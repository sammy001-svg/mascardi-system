<?php
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
canAccess('reports') || die('Access denied.');
$pageTitle = 'Vehicle P&L';
$db = getDB();

// ── Period ────────────────────────────────────────────────────────────────────
$period = $_GET['period'] ?? 'this_month';
switch ($period) {
    case 'last_month':
        $dateFrom = date('Y-m-01', strtotime('first day of last month'));
        $dateTo   = date('Y-m-t',  strtotime('last day of last month'));
        $label    = 'Last Month (' . date('M Y', strtotime('last month')) . ')';
        break;
    case 'last_3_months':
        $dateFrom = date('Y-m-01', strtotime('-2 months'));
        $dateTo   = date('Y-m-d');
        $label    = 'Last 3 Months';
        break;
    case 'this_year':
        $dateFrom = date('Y-01-01');
        $dateTo   = date('Y-12-31');
        $label    = 'This Year (' . date('Y') . ')';
        break;
    case 'custom':
        $dateFrom = $_GET['date_from'] ?? date('Y-m-01');
        $dateTo   = $_GET['date_to']   ?? date('Y-m-d');
        $label    = fmtDate($dateFrom) . ' – ' . fmtDate($dateTo);
        break;
    default:
        $dateFrom = date('Y-m-01');
        $dateTo   = date('Y-m-d');
        $label    = 'This Month (' . date('M Y') . ')';
}

// ── Vehicle P&L data ──────────────────────────────────────────────────────────
$vehicles = [];
$kpi = ['count' => 0, 'revenue' => 0, 'cogs' => 0, 'profit' => 0, 'avg_margin' => 0];
$cogsBreakdown = ['purchase_price' => 0, 'freight' => 0, 'marine_insurance' => 0,
                  'port_charges' => 0, 'duty_tax' => 0, 'clearing_fees' => 0,
                  'transport_to_yard' => 0, 'workshop_costs' => 0, 'other_costs' => 0];
$daysToSell = [];

try {
    $stmt = $db->prepare("
        SELECT
            cs.id AS sale_id,
            cs.sale_number,
            cs.sale_date,
            cs.buyer_name,
            cs.sale_price,
            c.id AS car_id,
            c.make,
            c.model,
            c.year,
            c.chassis_number,
            c.registration_number,
            c.color,
            cc.purchase_price,
            cc.freight,
            cc.marine_insurance,
            cc.port_charges,
            cc.duty_tax,
            cc.clearing_fees,
            cc.transport_to_yard,
            cc.workshop_costs,
            cc.other_costs,
            ROUND(
                cc.purchase_price + cc.freight + cc.marine_insurance + cc.port_charges
                + cc.duty_tax + cc.clearing_fees + cc.transport_to_yard
                + cc.workshop_costs + cc.other_costs, 2
            ) AS cogs,
            ROUND(
                cs.sale_price - (
                    cc.purchase_price + cc.freight + cc.marine_insurance + cc.port_charges
                    + cc.duty_tax + cc.clearing_fees + cc.transport_to_yard
                    + cc.workshop_costs + cc.other_costs
                ), 2
            ) AS gross_profit,
            CASE WHEN cs.sale_price > 0 THEN ROUND(
                (cs.sale_price - (
                    cc.purchase_price + cc.freight + cc.marine_insurance + cc.port_charges
                    + cc.duty_tax + cc.clearing_fees + cc.transport_to_yard
                    + cc.workshop_costs + cc.other_costs
                )) / cs.sale_price * 100, 1
            ) ELSE 0 END AS margin_pct,
            DATEDIFF(cs.sale_date, c.created_at) AS days_to_sell,
            u.name AS sold_by
        FROM car_sales cs
        JOIN cars c ON c.id = cs.car_id
        JOIN car_costs cc ON cc.car_id = cs.car_id
        LEFT JOIN users u ON u.id = cs.sold_by
        WHERE cs.status = 'active' AND DATE(cs.sale_date) BETWEEN ? AND ?
        ORDER BY gross_profit DESC
    ");
    $stmt->execute([$dateFrom, $dateTo]);
    $vehicles = $stmt->fetchAll();

    foreach ($vehicles as $v) {
        $kpi['count']++;
        $kpi['revenue'] += (float)$v['sale_price'];
        $kpi['cogs']    += (float)$v['cogs'];
        $kpi['profit']  += (float)$v['gross_profit'];
        foreach (array_keys($cogsBreakdown) as $col) {
            $cogsBreakdown[$col] += (float)$v[$col];
        }
        if ($v['days_to_sell'] !== null && $v['days_to_sell'] >= 0) {
            $daysToSell[] = (int)$v['days_to_sell'];
        }
    }
    if ($kpi['count'] > 0 && $kpi['revenue'] > 0) {
        $kpi['avg_margin'] = round($kpi['profit'] / $kpi['revenue'] * 100, 1);
    }
} catch (\Throwable $e) {
    $vehicles = [];
}

$avgDaysToSell = $daysToSell ? round(array_sum($daysToSell) / count($daysToSell)) : 0;

// Top 10 for chart
$top10 = array_slice($vehicles, 0, 10);

// COGS breakdown labels
$cogsLabels = [
    'purchase_price'   => 'Purchase Price',
    'freight'          => 'Freight',
    'marine_insurance' => 'Marine Insurance',
    'port_charges'     => 'Port Charges',
    'duty_tax'         => 'Duty & Tax',
    'clearing_fees'    => 'Clearing Fees',
    'transport_to_yard'=> 'Transport to Yard',
    'workshop_costs'   => 'Workshop Costs',
    'other_costs'      => 'Other Costs',
];

$exportQs = http_build_query(array_filter([
    'type'      => 'profit',
    'period'    => $period,
    'date_from' => $period === 'custom' ? $dateFrom : '',
    'date_to'   => $period === 'custom' ? $dateTo   : '',
]));

include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/_nav.php';
?>

<!-- ── KPI Cards ─────────────────────────────────────────────────────────── -->
<div class="row g-3 mb-4">
    <div class="col-6 col-lg-3">
        <div class="card border-0 shadow-sm h-100" style="border-radius:12px">
            <div class="card-body d-flex align-items-center gap-3 p-3">
                <div class="flex-shrink-0 d-flex align-items-center justify-content-center rounded-3"
                     style="width:46px;height:46px;background:#eff6ff">
                    <i class="fa fa-car text-primary"></i>
                </div>
                <div>
                    <div class="text-muted" style="font-size:11.5px;text-transform:uppercase;letter-spacing:.04em;font-weight:600">Vehicles Sold</div>
                    <div class="fw-bold" style="font-size:22px;line-height:1.2"><?= number_format($kpi['count']) ?></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card border-0 shadow-sm h-100" style="border-radius:12px">
            <div class="card-body d-flex align-items-center gap-3 p-3">
                <div class="flex-shrink-0 d-flex align-items-center justify-content-center rounded-3"
                     style="width:46px;height:46px;background:#f0fdf4">
                    <i class="fa fa-arrow-trend-up text-success"></i>
                </div>
                <div>
                    <div class="text-muted" style="font-size:11.5px;text-transform:uppercase;letter-spacing:.04em;font-weight:600">Total Revenue</div>
                    <div class="fw-bold" style="font-size:18px;line-height:1.2"><?= money($kpi['revenue']) ?></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card border-0 shadow-sm h-100" style="border-radius:12px">
            <div class="card-body d-flex align-items-center gap-3 p-3">
                <div class="flex-shrink-0 d-flex align-items-center justify-content-center rounded-3"
                     style="width:46px;height:46px;background:<?= $kpi['profit'] >= 0 ? '#f0fdf4' : '#fef2f2' ?>">
                    <i class="fa fa-sack-dollar <?= $kpi['profit'] >= 0 ? 'text-success' : 'text-danger' ?>"></i>
                </div>
                <div>
                    <div class="text-muted" style="font-size:11.5px;text-transform:uppercase;letter-spacing:.04em;font-weight:600">Gross Profit</div>
                    <div class="fw-bold <?= $kpi['profit'] >= 0 ? 'text-success' : 'text-danger' ?>" style="font-size:18px;line-height:1.2"><?= money($kpi['profit']) ?></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card border-0 shadow-sm h-100" style="border-radius:12px">
            <div class="card-body d-flex align-items-center gap-3 p-3">
                <div class="flex-shrink-0 d-flex align-items-center justify-content-center rounded-3"
                     style="width:46px;height:46px;background:#fefce8">
                    <i class="fa fa-percent text-warning"></i>
                </div>
                <div>
                    <div class="text-muted" style="font-size:11.5px;text-transform:uppercase;letter-spacing:.04em;font-weight:600">Avg Margin</div>
                    <div class="fw-bold" style="font-size:22px;line-height:1.2"><?= $kpi['avg_margin'] ?>%</div>
                    <div class="text-muted" style="font-size:11px">Avg <?= $avgDaysToSell ?> days to sell</div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php if (empty($vehicles)): ?>
<div class="card border-0 shadow-sm text-center py-5" style="border-radius:12px">
    <i class="fa fa-car-burst fa-2x mb-3 d-block" style="color:#cbd5e1"></i>
    <p class="fw-semibold mb-1">No vehicle sales recorded for this period</p>
    <p class="text-muted small">Vehicle P&L requires completed sales with associated cost data.</p>
</div>
<?php else: ?>

<!-- ── Charts Row ──────────────────────────────────────────────────────────── -->
<div class="row g-4 mb-4">

    <!-- Top 10 profit chart -->
    <div class="col-lg-7">
        <div class="card border-0 shadow-sm h-100" style="border-radius:12px">
            <div class="card-body p-4">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <div>
                        <h6 class="fw-bold mb-0">Top <?= count($top10) ?> by Gross Profit</h6>
                        <div class="text-muted small">Highest earning vehicles in period</div>
                    </div>
                </div>
                <canvas id="profitChart" height="260"></canvas>
            </div>
        </div>
    </div>

    <!-- COGS breakdown pie -->
    <div class="col-lg-5">
        <div class="card border-0 shadow-sm h-100" style="border-radius:12px">
            <div class="card-body p-4">
                <div class="mb-3">
                    <h6 class="fw-bold mb-0">COGS Breakdown</h6>
                    <div class="text-muted small">Aggregate cost composition for all sold vehicles</div>
                </div>
                <canvas id="cogsChart" height="200"></canvas>
                <div class="row g-2 mt-3" style="font-size:11.5px">
                    <?php
                    $cogsColors = ['#3b82f6','#f59e0b','#10b981','#ef4444','#8b5cf6','#06b6d4','#f97316','#84cc16','#6b7280'];
                    $ci = 0;
                    foreach ($cogsLabels as $key => $lbl):
                        $val = $cogsBreakdown[$key] ?? 0;
                        if ($val <= 0) { $ci++; continue; }
                    ?>
                    <div class="col-6 d-flex align-items-center gap-2">
                        <span class="rounded-1 flex-shrink-0" style="width:10px;height:10px;background:<?= $cogsColors[$ci % count($cogsColors)] ?>"></span>
                        <span class="text-muted"><?= $lbl ?>:</span>
                        <span class="fw-semibold"><?= money($val) ?></span>
                    </div>
                    <?php $ci++; endforeach; ?>
                </div>
            </div>
        </div>
    </div>

</div>

<!-- ── Vehicle P&L Table ──────────────────────────────────────────────────── -->
<div class="card border-0 shadow-sm mb-4" style="border-radius:12px">
    <div class="card-body p-0">
        <div class="d-flex justify-content-between align-items-center p-4 pb-3 border-bottom">
            <div>
                <h6 class="fw-bold mb-0"><i class="fa fa-table me-2 text-primary"></i>Vehicle Profitability Detail</h6>
                <div class="text-muted small"><?= count($vehicles) ?> vehicle<?= count($vehicles) !== 1 ? 's' : '' ?> sold · sorted by profit (highest first)</div>
            </div>
            <a href="<?= BASE_URL ?>/modules/reports/export.php?<?= $exportQs ?>"
               class="btn btn-sm btn-outline-success">
                <i class="fa fa-file-csv me-1"></i>Export CSV
            </a>
        </div>
        <div class="table-responsive">
            <table class="table table-hover mb-0 datatable" style="font-size:13px">
                <thead style="font-size:11.5px;color:#64748b;text-transform:uppercase;letter-spacing:.05em;background:#f8fafc">
                    <tr>
                        <th class="ps-4 py-3">Vehicle</th>
                        <th class="py-3">Sale #</th>
                        <th class="py-3">Sale Date</th>
                        <th class="py-3 text-end">Purchase</th>
                        <th class="py-3 text-end">Landed Cost</th>
                        <th class="py-3 text-end">Workshop</th>
                        <th class="py-3 text-end">Total COGS</th>
                        <th class="py-3 text-end">Sale Price</th>
                        <th class="py-3 text-end">Gross Profit</th>
                        <th class="py-3 text-end pe-4">Margin</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($vehicles as $v):
                    $profitClass = (float)$v['gross_profit'] >= 0 ? 'text-success' : 'text-danger';
                    $landedCost  = (float)$v['freight'] + (float)$v['marine_insurance'] + (float)$v['port_charges']
                                 + (float)$v['duty_tax'] + (float)$v['clearing_fees'] + (float)$v['transport_to_yard'];
                ?>
                <tr>
                    <td class="ps-4 py-3">
                        <div class="fw-semibold"><?= e($v['make'] . ' ' . $v['model']) ?></div>
                        <div class="text-muted" style="font-size:11.5px">
                            <?= e($v['year']) ?>
                            <?php if ($v['registration_number']): ?>
                            · <span class="badge bg-dark" style="font-size:10px"><?= e($v['registration_number']) ?></span>
                            <?php endif; ?>
                        </div>
                        <?php if ($v['sold_by']): ?>
                        <div class="text-muted" style="font-size:11px"><i class="fa fa-user me-1"></i><?= e($v['sold_by']) ?></div>
                        <?php endif; ?>
                    </td>
                    <td class="py-3 small text-muted"><?= e($v['sale_number']) ?></td>
                    <td class="py-3 small text-muted"><?= fmtDate($v['sale_date']) ?></td>
                    <td class="py-3 text-end small"><?= money($v['purchase_price']) ?></td>
                    <td class="py-3 text-end small"><?= money($landedCost) ?></td>
                    <td class="py-3 text-end small"><?= money($v['workshop_costs']) ?></td>
                    <td class="py-3 text-end fw-semibold small"><?= money($v['cogs']) ?></td>
                    <td class="py-3 text-end fw-bold"><?= money($v['sale_price']) ?></td>
                    <td class="py-3 text-end fw-bold <?= $profitClass ?>"><?= money($v['gross_profit']) ?></td>
                    <td class="py-3 text-end pe-4">
                        <?php
                        $m = (float)$v['margin_pct'];
                        $badge = $m >= 15 ? 'success' : ($m >= 5 ? 'warning' : 'danger');
                        ?>
                        <span class="badge bg-<?= $badge ?>"><?= $m ?>%</span>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot style="background:#f8fafc;font-size:12px;font-weight:700">
                    <tr>
                        <td class="ps-4 py-2 text-muted" colspan="3">Totals (<?= $kpi['count'] ?> vehicles)</td>
                        <td class="py-2 text-end"><?= money($cogsBreakdown['purchase_price']) ?></td>
                        <td class="py-2 text-end">
                            <?= money(
                                $cogsBreakdown['freight'] + $cogsBreakdown['marine_insurance'] +
                                $cogsBreakdown['port_charges'] + $cogsBreakdown['duty_tax'] +
                                $cogsBreakdown['clearing_fees'] + $cogsBreakdown['transport_to_yard']
                            ) ?>
                        </td>
                        <td class="py-2 text-end"><?= money($cogsBreakdown['workshop_costs']) ?></td>
                        <td class="py-2 text-end"><?= money($kpi['cogs']) ?></td>
                        <td class="py-2 text-end"><?= money($kpi['revenue']) ?></td>
                        <td class="py-2 text-end <?= $kpi['profit'] >= 0 ? 'text-success' : 'text-danger' ?>"><?= money($kpi['profit']) ?></td>
                        <td class="py-2 text-end pe-4">
                            <span class="badge bg-<?= $kpi['avg_margin'] >= 15 ? 'success' : ($kpi['avg_margin'] >= 5 ? 'warning' : 'danger') ?>">
                                <?= $kpi['avg_margin'] ?>%
                            </span>
                        </td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
</div>

<!-- ── COGS Composition Detail ────────────────────────────────────────────── -->
<div class="card border-0 shadow-sm mb-4" style="border-radius:12px">
    <div class="card-body p-4">
        <h6 class="fw-bold mb-3"><i class="fa fa-layer-group me-2 text-secondary"></i>COGS Composition — Period Total</h6>
        <div class="row g-3">
        <?php
        $totalCogs = $kpi['cogs'] ?: 1;
        $ci = 0;
        foreach ($cogsLabels as $key => $lbl):
            $val = $cogsBreakdown[$key] ?? 0;
            $pct = round($val / $totalCogs * 100, 1);
        ?>
        <div class="col-6 col-md-4 col-lg-3">
            <div class="p-2 rounded-2 border" style="font-size:12px">
                <div class="d-flex justify-content-between mb-1">
                    <span class="text-muted"><?= $lbl ?></span>
                    <span class="fw-semibold"><?= $pct ?>%</span>
                </div>
                <div class="progress" style="height:5px;border-radius:3px">
                    <div class="progress-bar" style="width:<?= $pct ?>%;background:<?= $cogsColors[$ci % count($cogsColors)] ?>"></div>
                </div>
                <div class="mt-1 fw-bold" style="font-size:12.5px"><?= money($val) ?></div>
            </div>
        </div>
        <?php $ci++; endforeach; ?>
        </div>
    </div>
</div>

<?php endif; ?>

<!-- ── Charts JS ─────────────────────────────────────────────────────────── -->
<?php if (!empty($vehicles)): ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
(function () {
    const isDark = document.documentElement.getAttribute('data-theme') === 'dark';
    const gridColor = isDark ? 'rgba(255,255,255,.07)' : 'rgba(0,0,0,.06)';
    const labelColor = isDark ? '#94a3b8' : '#64748b';

    // Top 10 profit bar chart
    const profitData = <?= json_encode(array_map(fn($v) => [
        'label'  => $v['make'] . ' ' . $v['model'] . ' ' . $v['year'],
        'profit' => (float)$v['gross_profit'],
        'margin' => (float)$v['margin_pct'],
    ], $top10)) ?>;

    new Chart(document.getElementById('profitChart'), {
        type: 'bar',
        data: {
            labels: profitData.map(v => v.label.length > 22 ? v.label.slice(0, 22) + '…' : v.label),
            datasets: [{
                label: 'Gross Profit (KES)',
                data: profitData.map(v => v.profit),
                backgroundColor: profitData.map(v => v.profit >= 0 ? 'rgba(34,197,94,.75)' : 'rgba(239,68,68,.75)'),
                borderColor:     profitData.map(v => v.profit >= 0 ? '#16a34a' : '#dc2626'),
                borderWidth: 1.5,
                borderRadius: 4,
            }],
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label: ctx => {
                            const v = profitData[ctx.dataIndex];
                            return [
                                'Profit: KES ' + ctx.parsed.y.toLocaleString('en-KE', {minimumFractionDigits: 2}),
                                'Margin: ' + v.margin + '%',
                            ];
                        }
                    }
                }
            },
            scales: {
                x: { ticks: { color: labelColor, font: { size: 11 } }, grid: { display: false } },
                y: {
                    ticks: {
                        color: labelColor,
                        font: { size: 11 },
                        callback: v => 'KES ' + (v >= 1000000 ? (v/1000000).toFixed(1)+'M' : v >= 1000 ? (v/1000).toFixed(0)+'K' : v)
                    },
                    grid: { color: gridColor }
                }
            }
        }
    });

    // COGS breakdown donut
    const cogsData = <?= json_encode(
        array_values(array_filter(
            array_map(fn($k, $l) => ['label' => $l, 'value' => (float)($cogsBreakdown[$k] ?? 0)],
                array_keys($cogsLabels), array_values($cogsLabels)
            ),
            fn($d) => $d['value'] > 0
        ))
    ) ?>;
    const cogsColors = ['#3b82f6','#f59e0b','#10b981','#ef4444','#8b5cf6','#06b6d4','#f97316','#84cc16','#6b7280'];

    new Chart(document.getElementById('cogsChart'), {
        type: 'doughnut',
        data: {
            labels: cogsData.map(d => d.label),
            datasets: [{
                data: cogsData.map(d => d.value),
                backgroundColor: cogsColors.slice(0, cogsData.length),
                borderWidth: 2,
                borderColor: isDark ? '#1e293b' : '#fff',
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            cutout: '65%',
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label: ctx => {
                            const total = ctx.dataset.data.reduce((a, b) => a + b, 0);
                            const pct   = total > 0 ? ((ctx.parsed / total) * 100).toFixed(1) : 0;
                            return ctx.label + ': KES ' + ctx.parsed.toLocaleString('en-KE', {minimumFractionDigits: 2}) + ' (' + pct + '%)';
                        }
                    }
                }
            }
        }
    });
}());
</script>
<?php endif; ?>

<?php include __DIR__ . '/../../includes/footer.php'; ?>

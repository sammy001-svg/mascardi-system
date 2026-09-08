<?php
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
canAccess('reports') || die('Access denied.');
$pageTitle = 'Sales Performance';
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
    case 'last_6_months':
        $dateFrom = date('Y-m-01', strtotime('-5 months'));
        $dateTo   = date('Y-m-d');
        $label    = 'Last 6 Months';
        break;
    case 'this_year':
        $dateFrom = date('Y-01-01'); $dateTo = date('Y-12-31');
        $label = 'This Year (' . date('Y') . ')';
        break;
    case 'custom':
        $dateFrom = $_GET['date_from'] ?? date('Y-m-01');
        $dateTo   = $_GET['date_to']   ?? date('Y-m-d');
        $label    = fmtDate($dateFrom) . ' – ' . fmtDate($dateTo);
        break;
    default:
        $dateFrom = date('Y-m-01'); $dateTo = date('Y-m-d');
        $label    = 'This Month (' . date('M Y') . ')';
}

// ── Sales KPIs ────────────────────────────────────────────────────────────────
try {
    $salesKpi = $db->prepare("
        SELECT COUNT(*) AS total_sales,
               COALESCE(SUM(cs.sale_price), 0) AS revenue,
               COALESCE(AVG(cs.sale_price), 0) AS avg_price,
               SUM(cs.payment_status='paid_full') AS full_paid,
               SUM(cs.payment_status='financed')  AS financed,
               SUM(cs.payment_status='partial')   AS partial
        FROM car_sales cs WHERE cs.status='active' AND DATE(cs.sale_date) BETWEEN ? AND ?
    ");
    $salesKpi->execute([$dateFrom, $dateTo]);
    $sk = $salesKpi->fetch();
    $hasSales = true;
} catch (\Throwable $e) { $hasSales = false; $sk = null; }

// ── Sales rep leaderboard ─────────────────────────────────────────────────────
try {
    $reps = $db->prepare("
        SELECT u.id, u.name, u.role,
               COUNT(cs.id)                               AS cars_sold,
               COALESCE(SUM(cs.sale_price), 0)            AS total_revenue,
               COALESCE(AVG(cs.sale_price), 0)            AS avg_price,
               COALESCE(AVG(CASE WHEN cc.car_id IS NOT NULL
                   THEN cs.sale_price - (cc.purchase_price + cc.freight + cc.marine_insurance
                       + cc.port_charges + cc.duty_tax + cc.clearing_fees
                       + cc.transport_to_yard + cc.workshop_costs + cc.other_costs)
               END), NULL)                                 AS avg_profit,
               COALESCE(AVG(CASE WHEN cc.car_id IS NOT NULL AND cs.sale_price > 0
                   THEN (cs.sale_price - (cc.purchase_price + cc.freight + cc.marine_insurance
                       + cc.port_charges + cc.duty_tax + cc.clearing_fees
                       + cc.transport_to_yard + cc.workshop_costs + cc.other_costs))
                       / cs.sale_price * 100
               END), NULL)                                 AS avg_margin
        FROM car_sales cs
        JOIN users u ON u.id = cs.sold_by
        LEFT JOIN car_costs cc ON cc.car_id = cs.car_id
        WHERE cs.status='active' AND DATE(cs.sale_date) BETWEEN ? AND ?
        GROUP BY u.id, u.name, u.role
        ORDER BY total_revenue DESC
    ");
    $reps->execute([$dateFrom, $dateTo]);
    $reps = $reps->fetchAll();
} catch (\Throwable $e) { $reps = []; }

// ── Sales by payment method ───────────────────────────────────────────────────
try {
    $byPayMethod = $db->prepare("
        SELECT payment_method, COUNT(*) AS cnt,
               COALESCE(SUM(sale_price),0) AS revenue
        FROM car_sales WHERE status='active' AND DATE(sale_date) BETWEEN ? AND ?
        GROUP BY payment_method ORDER BY revenue DESC
    ");
    $byPayMethod->execute([$dateFrom, $dateTo]);
    $byPayMethod = $byPayMethod->fetchAll();
} catch (\Throwable $e) { $byPayMethod = []; }

// ── Sales by make ─────────────────────────────────────────────────────────────
try {
    $byMake = $db->prepare("
        SELECT c.make, COUNT(*) AS cnt,
               COALESCE(SUM(cs.sale_price), 0) AS revenue
        FROM car_sales cs JOIN cars c ON c.id = cs.car_id
        WHERE cs.status='active' AND DATE(cs.sale_date) BETWEEN ? AND ?
        GROUP BY c.make ORDER BY cnt DESC LIMIT 8
    ");
    $byMake->execute([$dateFrom, $dateTo]);
    $byMake = $byMake->fetchAll();
} catch (\Throwable $e) { $byMake = []; }

// ── CRM funnel ────────────────────────────────────────────────────────────────
try {
    $crm = $db->query("
        SELECT
            COUNT(*)                              AS total,
            SUM(stage='new')                      AS new_leads,
            SUM(stage='contacted')                AS contacted,
            SUM(stage='viewing')                  AS viewing,
            SUM(stage='negotiation')              AS negotiating,
            SUM(stage='closed_won')               AS won,
            SUM(stage='closed_lost')              AS lost
        FROM crm_leads
    ")->fetch();
    $crmSources = $db->query("SELECT source, COUNT(*) AS cnt FROM crm_leads GROUP BY source ORDER BY cnt DESC LIMIT 6")->fetchAll();
    $hasCrm = true;
} catch (\Throwable $e) { $hasCrm = false; $crm = null; $crmSources = []; }

// ── Showroom conversion ───────────────────────────────────────────────────────
try {
    $showroomStats = $db->query("
        SELECT
            COUNT(*)                        AS inquiries,
            SUM(status='new')               AS new_inq,
            SUM(status='contacted')         AS contacted,
            SUM(status='closed')            AS closed
        FROM showroom_inquiries
    ")->fetch();
    $hasShowroom = true;
} catch (\Throwable $e) { $hasShowroom = false; $showroomStats = null; }

// ── Service bookings ──────────────────────────────────────────────────────────
$sbStats = $db->prepare("
    SELECT
        COUNT(*)                          AS total,
        SUM(status='pending')             AS pending,
        SUM(status='confirmed')           AS confirmed,
        SUM(status='completed')           AS completed,
        SUM(status='cancelled')           AS cancelled
    FROM service_bookings WHERE DATE(created_at) BETWEEN ? AND ?
");
$sbStats->execute([$dateFrom, $dateTo]);
$sb = $sbStats->fetch();

// ── Top 5 sold models ──────────────────────────────────────────────────────────────
try {
    $topModels = $db->prepare("
        SELECT c.make, c.model, COUNT(*) AS cnt,
               COALESCE(SUM(cs.sale_price), 0) AS revenue,
               COALESCE(AVG(cs.sale_price), 0) AS avg_price
        FROM car_sales cs JOIN cars c ON c.id = cs.car_id
        WHERE cs.status='active' AND DATE(cs.sale_date) BETWEEN ? AND ?
        GROUP BY c.make, c.model ORDER BY cnt DESC, revenue DESC LIMIT 5
    ");
    $topModels->execute([$dateFrom, $dateTo]);
    $topModels = $topModels->fetchAll();
} catch (\Throwable $e) { $topModels = []; }

// ── Deal size distribution (price bands) ─────────────────────────────────────────
try {
    $dealBands = $db->prepare("
        SELECT
            SUM(sale_price < 500000)  AS under_500k,
            SUM(sale_price BETWEEN 500000 AND 999999) AS band_500k_1m,
            SUM(sale_price BETWEEN 1000000 AND 1999999) AS band_1m_2m,
            SUM(sale_price BETWEEN 2000000 AND 4999999) AS band_2m_5m,
            SUM(sale_price >= 5000000) AS above_5m
        FROM car_sales WHERE status='active' AND DATE(sale_date) BETWEEN ? AND ?
    ");
    $dealBands->execute([$dateFrom, $dateTo]);
    $dealBands = $dealBands->fetch();
} catch (\Throwable $e) { $dealBands = null; }

// ── Daily sales trend in period ──────────────────────────────────────────────────
try {
    $dailySales = $db->prepare("
        SELECT DATE(sale_date) AS day, COUNT(*) AS cnt, COALESCE(SUM(sale_price),0) AS revenue
        FROM car_sales WHERE status='active' AND DATE(sale_date) BETWEEN ? AND ?
        GROUP BY DATE(sale_date) ORDER BY day ASC
    ");
    $dailySales->execute([$dateFrom, $dateTo]);
    $dailySales = $dailySales->fetchAll();
} catch (\Throwable $e) { $dailySales = []; }

// ── Chart data ────────────────────────────────────────────────────────────────
$makeLabels  = json_encode(array_column($byMake, 'make'));
$makeCounts  = json_encode(array_column($byMake, 'cnt'));
$methodLabels = json_encode(array_map(fn($r) => ucwords(str_replace('_',' ',$r['payment_method'])), $byPayMethod));
$methodRevs   = json_encode(array_map(fn($r) => round($r['revenue'], 2), $byPayMethod));
$dealBandLabels = json_encode(['< 500K','500K–1M','1M–2M','2M–5M','5M+']);
$dealBandCounts = json_encode($dealBands ? [(int)$dealBands['under_500k'],(int)$dealBands['band_500k_1m'],(int)$dealBands['band_1m_2m'],(int)$dealBands['band_2m_5m'],(int)$dealBands['above_5m']] : [0,0,0,0,0]);
$dailyLabels  = json_encode(array_column($dailySales, 'day'));
$dailyCounts  = json_encode(array_column($dailySales, 'cnt'));
$dailyRevs    = json_encode(array_map(fn($r) => round($r['revenue'], 2), $dailySales));

$extraJs = <<<JS
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
(function(){
    var makeEl = document.getElementById('makeChart');
    if (makeEl) new Chart(makeEl, {
        type:'bar',
        data:{ labels:{$makeLabels}, datasets:[{ data:{$makeCounts}, backgroundColor:'rgba(37,99,235,.75)', borderRadius:5, label:'Cars Sold' }] },
        options:{ indexAxis:'y', responsive:true, plugins:{legend:{display:false}}, scales:{x:{beginAtZero:true,ticks:{stepSize:1}}} }
    });
    var pmEl = document.getElementById('pmChart');
    if (pmEl) new Chart(pmEl, {
        type:'doughnut',
        data:{ labels:{$methodLabels}, datasets:[{ data:{$methodRevs}, backgroundColor:['#16a34a','#2563eb','#d97706','#7c3aed','#0891b2'], borderWidth:2, borderColor:'#fff' }] },
        options:{ cutout:'55%', plugins:{ legend:{ position:'bottom', labels:{font:{size:11},padding:8,boxWidth:10} } } }
    });
    // Deal size distribution
    var dbEl = document.getElementById('dealBandChart');
    if (dbEl) new Chart(dbEl, {
        type: 'bar',
        data: { labels: {$dealBandLabels}, datasets: [{ data: {$dealBandCounts}, backgroundColor: ['rgba(16,185,129,.75)','rgba(37,99,235,.75)','rgba(249,115,22,.75)','rgba(139,92,246,.75)','rgba(239,68,68,.75)'], borderRadius: 6, label: 'Vehicles' }] },
        options: { responsive: true, plugins: { legend: { display: false }, tooltip: { callbacks: { label: ctx => ctx.parsed.y + ' vehicle' + (ctx.parsed.y !== 1 ? 's' : '') } } }, scales: { y: { beginAtZero: true, ticks: { stepSize: 1 } } } }
    });
    // Daily sales trend
    var dsEl = document.getElementById('dailySalesChart');
    if (dsEl) new Chart(dsEl, {
        type: 'bar',
        data: {
            labels: {$dailyLabels},
            datasets: [
                { label: 'Vehicles Sold', data: {$dailyCounts}, backgroundColor: 'rgba(22,163,74,.7)', borderRadius: 4, yAxisID: 'y' },
                { label: 'Revenue (KES)', data: {$dailyRevs}, type: 'line', borderColor: '#2563eb', backgroundColor: 'rgba(37,99,235,.1)', borderWidth: 2, pointRadius: 3, tension: 0.3, fill: false, yAxisID: 'y1' }
            ]
        },
        options: {
            responsive: true,
            plugins: { legend: { position: 'top', labels: { font: { size: 11 }, padding: 10, boxWidth: 12 } }, tooltip: { mode: 'index', intersect: false } },
            scales: {
                y:  { beginAtZero: true, ticks: { stepSize: 1 }, title: { display: true, text: 'Vehicles' } },
                y1: { beginAtZero: true, position: 'right', grid: { drawOnChartArea: false }, ticks: { callback: v => v >= 1e6 ? (v/1e6).toFixed(1)+'M' : v >= 1e3 ? (v/1e3).toFixed(0)+'K' : v } }
            }
        }
    });
}());
</script>
JS;

include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/_nav.php';
?>

<!-- KPIs -->
<?php if ($hasSales && $sk): ?>
<div class="row g-3 mb-4">
    <?php
    $kpis = [
        ['Cars Sold',     $sk['total_sales'],  'primary', 'fa-car',              'dbeafe','2563eb'],
        ['Sales Revenue', money((float)$sk['revenue']), 'success','fa-tag',      'dcfce7','16a34a'],
        ['Avg Sale Price',money((float)$sk['avg_price']),'info','fa-circle-dollar-to-slot','e0f2fe','0284c7'],
        ['Fully Paid',    $sk['full_paid'],    'success', 'fa-check-circle',     'dcfce7','16a34a'],
    ];
    foreach ($kpis as [$lbl, $val, $col, $icon, $ibg, $icol]): ?>
    <div class="col-sm-6 col-xl-3">
        <div class="stat-card" style="border-left:4px solid #<?= $icol ?>">
            <div class="stat-icon" style="background:#<?= $ibg ?>;color:#<?= $icol ?>"><i class="fa <?= $icon ?>"></i></div>
            <div class="stat-info">
                <div class="stat-label"><?= $lbl ?></div>
                <div class="stat-value <?= strlen((string)$val) > 5 ? 'stat-value-sm' : '' ?>"><?= $val ?></div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- Sales rep leaderboard -->
<div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="fa fa-trophy me-2 text-warning"></i>Sales Rep Leaderboard — <?= e($label) ?></span>
        <a href="<?= BASE_URL ?>/modules/reports/export.php?type=sales_reps&period=<?= urlencode($period) ?>&date_from=<?= urlencode($dateFrom) ?>&date_to=<?= urlencode($dateTo) ?>" class="btn btn-xs btn-outline-secondary">
            <i class="fa fa-download me-1"></i>CSV
        </a>
    </div>
    <div class="card-body p-0">
        <table class="table table-hover mb-0" style="font-size:13px">
            <thead><tr>
                <th class="ps-3">#</th>
                <th>Sales Person</th>
                <th>Role</th>
                <th class="text-center">Cars Sold</th>
                <th class="text-end">Revenue</th>
                <th class="text-end">Avg Sale</th>
                <th class="text-end">Avg Margin</th>
            </tr></thead>
            <tbody>
            <?php if (!$reps): ?>
            <tr><td colspan="7" class="text-center text-muted py-4">No sales recorded in this period</td></tr>
            <?php endif; ?>
            <?php foreach ($reps as $i => $r):
                $marginCls = $r['avg_margin'] === null ? 'secondary' : ($r['avg_margin'] >= 20 ? 'success' : ($r['avg_margin'] >= 10 ? 'warning text-dark' : 'danger'));
            ?>
            <tr>
                <td class="ps-3 text-muted fw-semibold">
                    <?= $i === 0 ? '🥇' : ($i === 1 ? '🥈' : ($i === 2 ? '🥉' : $i+1)) ?>
                </td>
                <td class="fw-semibold"><?= e($r['name']) ?></td>
                <td class="text-muted small"><?= ucwords(str_replace('_',' ',$r['role'])) ?></td>
                <td class="text-center fw-bold text-primary"><?= $r['cars_sold'] ?></td>
                <td class="text-end fw-semibold text-success"><?= money((float)$r['total_revenue']) ?></td>
                <td class="text-end text-muted"><?= money((float)$r['avg_price']) ?></td>
                <td class="text-end">
                    <?php if ($r['avg_margin'] !== null): ?>
                    <span class="badge bg-<?= $marginCls ?>"><?= round((float)$r['avg_margin'], 1) ?>%</span>
                    <?php else: ?>
                    <span class="text-muted small">No cost data</span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Sales by Make + Payment Methods -->
<div class="row g-4 mb-4">
    <div class="col-lg-5">
        <div class="card h-100">
            <div class="card-header"><i class="fa fa-car-side me-2"></i>Sales by Make — <?= e($label) ?></div>
            <div class="card-body">
                <?php if ($byMake): ?>
                <canvas id="makeChart" height="160"></canvas>
                <?php else: ?>
                <div class="empty-state"><i class="fa fa-car"></i><p class="text-muted">No sales yet</p></div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-lg-7">
        <div class="row g-3 h-100">
            <div class="col-12">
                <div class="card">
                    <div class="card-header"><i class="fa fa-credit-card me-2"></i>Payment Methods</div>
                    <div class="card-body">
                        <?php if ($byPayMethod): ?>
                        <?php
                        $totalRev = array_sum(array_column($byPayMethod, 'revenue'));
                        $pmLabels = ['cash'=>'Cash','bank_transfer'=>'Bank Transfer','mpesa'=>'M-Pesa','financing'=>'Financing','cheque'=>'Cheque'];
                        foreach ($byPayMethod as $pm):
                            $pct = $totalRev > 0 ? round($pm['revenue'] / $totalRev * 100) : 0;
                        ?>
                        <div class="d-flex align-items-center gap-3 mb-2" style="font-size:13px">
                            <span style="min-width:120px;font-weight:500"><?= $pmLabels[$pm['payment_method']] ?? $pm['payment_method'] ?></span>
                            <div class="progress flex-grow-1" style="height:7px">
                                <div class="progress-bar bg-primary" style="width:<?= $pct ?>%"></div>
                            </div>
                            <span class="text-muted small" style="min-width:24px"><?= $pm['cnt'] ?> sales</span>
                            <span class="fw-semibold" style="min-width:90px;text-align:right"><?= money((float)$pm['revenue']) ?></span>
                        </div>
                        <?php endforeach; ?>
                        <?php else: ?>
                        <div class="text-center text-muted py-3">No sales data</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="col-12">
                <div class="card">
                    <div class="card-header"><i class="fa fa-calendar-check me-2"></i>Service Bookings — <?= e($label) ?></div>
                    <div class="card-body">
                        <div class="row g-2 text-center">
                            <?php foreach ([['Pending',$sb['pending']??0,'warning'],['Confirmed',$sb['confirmed']??0,'primary'],['Completed',$sb['completed']??0,'success'],['Cancelled',$sb['cancelled']??0,'secondary']] as [$lbl,$val,$col]): ?>
                            <div class="col-3">
                                <div class="fw-bold text-<?= $col ?>" style="font-size:20px"><?= $val ?></div>
                                <div class="text-muted" style="font-size:11px"><?= $lbl ?></div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- CRM Funnel + Showroom Conversion -->
<div class="row g-4 mb-4">
    <?php if ($hasCrm && $crm): ?>
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header fw-semibold"><i class="fa fa-filter me-2 text-primary"></i>CRM Sales Funnel</div>
            <div class="card-body">
                <?php
                $convRate = $crm['total'] > 0 ? round($crm['won'] / $crm['total'] * 100, 1) : 0;
                $stages = [
                    ['New Leads',   $crm['new_leads'],  'secondary', 100],
                    ['Contacted',   $crm['contacted'],  'info',      $crm['total'] > 0 ? round($crm['contacted']/$crm['total']*100) : 0],
                    ['Viewing',     $crm['viewing'],    'primary',   $crm['total'] > 0 ? round($crm['viewing']/$crm['total']*100) : 0],
                    ['Negotiating', $crm['negotiating'],'warning',   $crm['total'] > 0 ? round($crm['negotiating']/$crm['total']*100) : 0],
                    ['Won',         $crm['won'],        'success',   $crm['total'] > 0 ? round($crm['won']/$crm['total']*100) : 0],
                ];
                foreach ($stages as [$sl, $sv, $sc, $spct]): ?>
                <div class="d-flex align-items-center gap-3 mb-3">
                    <span class="badge bg-<?= $sc ?>" style="min-width:90px;font-size:12px;padding:5px 0"><?= $sl ?></span>
                    <div class="progress flex-grow-1" style="height:10px;border-radius:5px">
                        <div class="progress-bar bg-<?= $sc ?>" style="width:<?= $spct ?>%"></div>
                    </div>
                    <span class="fw-bold" style="min-width:24px"><?= $sv ?></span>
                </div>
                <?php endforeach; ?>
                <div class="d-flex justify-content-between align-items-center mt-3 pt-3 border-top">
                    <div>
                        <div class="text-muted small">Conversion Rate</div>
                        <div class="fw-bold text-<?= $convRate >= 20 ? 'success' : ($convRate >= 10 ? 'warning' : 'danger') ?>" style="font-size:22px"><?= $convRate ?>%</div>
                    </div>
                    <div class="text-end">
                        <div class="text-muted small">Lost</div>
                        <div class="fw-bold text-danger" style="font-size:22px"><?= $crm['lost'] ?></div>
                    </div>
                </div>
                <?php if (!empty($crmSources)): ?>
                <div class="mt-3 pt-3 border-top">
                    <div class="text-muted small fw-semibold mb-2">Leads by Source</div>
                    <?php
                    $srcLabels = ['walk_in'=>'Walk-in','referral'=>'Referral','facebook'=>'Facebook','instagram'=>'Instagram','website'=>'Website','phone_call'=>'Phone Call','whatsapp'=>'WhatsApp','other'=>'Other'];
                    $totSrc = array_sum(array_column($crmSources,'cnt'));
                    foreach ($crmSources as $src):
                        $spct = $totSrc > 0 ? round($src['cnt']/$totSrc*100) : 0;
                    ?>
                    <div class="d-flex align-items-center gap-2 mb-1" style="font-size:12px">
                        <span style="min-width:90px"><?= e($srcLabels[$src['source']] ?? $src['source']) ?></span>
                        <div class="progress flex-grow-1" style="height:5px">
                            <div class="progress-bar bg-primary" style="width:<?= $spct ?>%"></div>
                        </div>
                        <span class="text-muted"><?= $src['cnt'] ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($hasShowroom && $showroomStats): ?>
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header fw-semibold d-flex justify-content-between align-items-center">
                <span><i class="fa fa-store me-2 text-primary"></i>Showroom Inquiries</span>
                <a href="<?= BASE_URL ?>/modules/showroom/index.php" class="btn btn-xs btn-outline-secondary">Manage →</a>
            </div>
            <div class="card-body">
                <?php
                $total_inq = (int)$showroomStats['inquiries'];
                $stages = [
                    ['Total Inquiries', $total_inq,                        'secondary'],
                    ['New / Unread',    (int)$showroomStats['new_inq'],    'danger'],
                    ['Contacted',       (int)$showroomStats['contacted'],  'primary'],
                    ['Closed',          (int)$showroomStats['closed'],     'success'],
                ];
                ?>
                <div class="row g-3 text-center mb-4">
                    <?php foreach ($stages as [$sl, $sv, $sc]): ?>
                    <div class="col-3">
                        <div class="fw-bold text-<?= $sc ?>" style="font-size:26px"><?= $sv ?></div>
                        <div class="text-muted" style="font-size:11px"><?= $sl ?></div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php $closeRate = $total_inq > 0 ? round($showroomStats['closed'] / $total_inq * 100, 1) : 0; ?>
                <div class="mb-2 d-flex justify-content-between small">
                    <span class="text-muted">Close Rate</span>
                    <span class="fw-semibold"><?= $closeRate ?>%</span>
                </div>
                <div class="progress mb-3" style="height:8px">
                    <div class="progress-bar bg-success" style="width:<?= $closeRate ?>%"></div>
                </div>
                <?php if ($showroomStats['new_inq'] > 0): ?>
                <div class="alert alert-warning py-2 px-3 mb-0 small">
                    <i class="fa fa-bell me-1"></i>
                    <strong><?= (int)$showroomStats['new_inq'] ?></strong> new enquiry<?= $showroomStats['new_inq'] > 1 ? 'ies' : '' ?> need attention.
                    <a href="<?= BASE_URL ?>/modules/showroom/index.php?status=new" class="ms-1">View now →</a>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php if (!empty($dailySales)): ?>
<!-- ── Daily Sales Trend ──────────────────────────────────────────────────────────── -->
<div class="card mb-4">
    <div class="card-header fw-semibold"><i class="fa fa-chart-line me-2 text-success"></i>Daily Sales Trend — <?= e($label) ?></div>
    <div class="card-body"><canvas id="dailySalesChart" height="80"></canvas></div>
</div>
<?php endif; ?>

<?php if ($dealBands || !empty($topModels)): ?>
<!-- ── Deal Size + Top Models ───────────────────────────────────────────────────────── -->
<div class="row g-4 mb-4">
    <?php if ($dealBands): ?>
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header fw-semibold"><i class="fa fa-chart-bar me-2 text-primary"></i>Deal Size Distribution — <?= e($label) ?></div>
            <div class="card-body">
                <canvas id="dealBandChart" height="160"></canvas>
                <div class="row g-2 mt-2 text-center" style="font-size:11px;color:#64748b">
                    <?php
                    $__bands = [['< 500K',$dealBands['under_500k'],'10b981'],['500K–1M',$dealBands['band_500k_1m'],'2563eb'],['1M–2M',$dealBands['band_1m_2m'],'f97316'],['2M–5M',$dealBands['band_2m_5m'],'8b5cf6'],['5M+',$dealBands['above_5m'],'ef4444']];
                    foreach ($__bands as [$__bl, $__bv, $__bc]):
                    ?>
                    <div class="col">
                        <div class="fw-bold" style="color:#<?= $__bc ?>;font-size:1.3rem"><?= (int)$__bv ?></div>
                        <div><?= $__bl ?></div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
    <?php if (!empty($topModels)): ?>
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header fw-semibold"><i class="fa fa-star me-2 text-warning"></i>Top 5 Sold Models — <?= e($label) ?></div>
            <div class="card-body p-0">
                <table class="table table-hover mb-0" style="font-size:13px">
                    <thead style="background:#f8fafc;font-size:11px;text-transform:uppercase;letter-spacing:.05em;color:#64748b">
                        <tr>
                            <th class="ps-3 py-2">#</th>
                            <th class="py-2">Make &amp; Model</th>
                            <th class="py-2 text-center">Sold</th>
                            <th class="py-2 text-end">Total Revenue</th>
                            <th class="py-2 text-end pe-3">Avg Price</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($topModels as $__i => $__tm): ?>
                    <tr>
                        <td class="ps-3 text-muted fw-semibold"><?= $__i + 1 ?></td>
                        <td class="fw-semibold"><?= e($__tm['make'] . ' ' . $__tm['model']) ?></td>
                        <td class="text-center">
                            <span class="badge bg-primary"><?= $__tm['cnt'] ?></span>
                        </td>
                        <td class="text-end text-success fw-semibold"><?= money((float)$__tm['revenue']) ?></td>
                        <td class="text-end pe-3 text-muted"><?= money((float)$__tm['avg_price']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php include __DIR__ . '/../../includes/footer.php'; ?>

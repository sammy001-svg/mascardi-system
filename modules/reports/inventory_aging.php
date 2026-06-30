<?php
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
canAccess('reports') || die('Access denied.');
$pageTitle = 'Inventory Aging Report';
$db = getDB();

// Snapshot — no period filter; aging is always the current state of stock
$aging = $db->query("
    SELECT c.id, c.make, c.model, c.year, c.color, c.status, c.asking_price,
           l.name AS location_name,
           DATEDIFF(NOW(), c.created_at) AS days_in_stock,
           c.created_at AS stock_date
    FROM cars c
    LEFT JOIN locations l ON l.id = c.location_id
    WHERE c.car_type = 'inventory'
      AND c.status NOT IN ('sold','cancelled','delivered')
    ORDER BY days_in_stock DESC
")->fetchAll();

$buckets     = ['0-30' => 0, '31-60' => 0, '61-90' => 0, '90+' => 0];
$totalValue  = 0;
foreach ($aging as $car) {
    $d = (int)$car['days_in_stock'];
    if ($d <= 30)     $buckets['0-30']++;
    elseif ($d <= 60) $buckets['31-60']++;
    elseif ($d <= 90) $buckets['61-90']++;
    else              $buckets['90+']++;
    $totalValue += (float)$car['asking_price'];
}
$total      = count($aging);
$avgDays    = $total ? round(array_sum(array_column($aging, 'days_in_stock')) / $total) : 0;
$slowMovers = $buckets['90+'];

// _nav.php needs these vars (period selector is shown but ignored by this page)
$period   = 'this_month';
$dateFrom = date('Y-m-01');
$dateTo   = date('Y-m-d');
$label    = 'Snapshot — ' . date('d M Y');

include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/_nav.php';
?>

<!-- ── KPI cards ─────────────────────────────────────────────────────────── -->
<div class="row g-3 mb-4">
    <div class="col-6 col-lg-3">
        <div class="card border-0 shadow-sm text-center h-100" style="border-radius:12px">
            <div class="card-body py-4">
                <div style="font-size:34px;font-weight:800;color:#2563eb"><?= $total ?></div>
                <div class="text-muted small mt-1">Cars in Stock</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card border-0 shadow-sm text-center h-100" style="border-radius:12px">
            <div class="card-body py-4">
                <div style="font-size:34px;font-weight:800;color:#d97706"><?= $avgDays ?></div>
                <div class="text-muted small mt-1">Avg Days in Stock</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card border-0 shadow-sm text-center h-100 <?= $slowMovers ? 'border-danger' : '' ?>" style="border-radius:12px">
            <div class="card-body py-4">
                <div style="font-size:34px;font-weight:800;color:<?= $slowMovers ? '#dc2626' : '#16a34a' ?>"><?= $slowMovers ?></div>
                <div class="text-muted small mt-1">Slow Movers (&gt;90 days)</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card border-0 shadow-sm text-center h-100" style="border-radius:12px">
            <div class="card-body py-4">
                <div style="font-size:20px;font-weight:800;color:#16a34a;line-height:1.4"><?= money($totalValue) ?></div>
                <div class="text-muted small mt-1">Total Stock Value (Ask)</div>
            </div>
        </div>
    </div>
</div>

<!-- ── Age distribution ───────────────────────────────────────────────────── -->
<div class="row g-4 mb-4">
    <div class="col-lg-5">
        <div class="card border-0 shadow-sm h-100" style="border-radius:12px">
            <div class="card-body p-4">
                <h6 class="fw-bold mb-1">Age Distribution</h6>
                <div class="text-muted small mb-3">Inventory grouped by days in stock</div>
                <canvas id="ageChart" height="220"></canvas>
            </div>
        </div>
    </div>
    <div class="col-lg-7">
        <div class="card border-0 shadow-sm h-100" style="border-radius:12px">
            <div class="card-body p-4">
                <h6 class="fw-bold mb-1">Age Bucket Health</h6>
                <div class="text-muted small mb-4">Action guide by age range</div>
                <?php
                $bucketMeta = [
                    '0-30'  => ['Fresh Stock',     'success', 'fa-seedling',             'Recently added — looking good'],
                    '31-60' => ['Moving Well',     'primary', 'fa-arrow-trend-up',       'Within normal sell cycle'],
                    '61-90' => ['Needs Attention', 'warning', 'fa-triangle-exclamation', 'Consider a price review'],
                    '90+'   => ['Slow Movers',     'danger',  'fa-circle-exclamation',   'Reprice, reposition, or promote'],
                ];
                foreach ($bucketMeta as $range => [$label2, $cls, $icon, $tip]):
                    $cnt = $buckets[$range];
                    $pct = $total > 0 ? round($cnt / $total * 100) : 0;
                ?>
                <div class="d-flex align-items-center gap-3 mb-3">
                    <div class="flex-shrink-0 d-flex align-items-center justify-content-center rounded-3"
                         style="width:44px;height:44px;background:#<?= ['success'=>'f0fdf4','primary'=>'eff6ff','warning'=>'fffbeb','danger'=>'fef2f2'][$cls] ?>">
                        <i class="fa <?= $icon ?> text-<?= $cls ?>"></i>
                    </div>
                    <div class="flex-grow-1">
                        <div class="d-flex justify-content-between align-items-baseline mb-1">
                            <span class="fw-semibold" style="font-size:13px"><?= $range ?> days — <?= $label2 ?></span>
                            <span class="fw-bold"><?= $cnt ?> <span class="text-muted fw-normal small">(<?= $pct ?>%)</span></span>
                        </div>
                        <div class="progress mb-1" style="height:5px;border-radius:3px">
                            <div class="progress-bar bg-<?= $cls ?>" style="width:<?= $pct ?>%;border-radius:3px"></div>
                        </div>
                        <div class="text-muted" style="font-size:11px"><?= $tip ?></div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>

<!-- ── Full list ─────────────────────────────────────────────────────────── -->
<div class="card border-0 shadow-sm" style="border-radius:12px">
    <div class="card-body p-0">
        <div class="p-4 pb-3 border-bottom d-flex justify-content-between align-items-start flex-wrap gap-2">
            <div>
                <h6 class="fw-bold mb-0"><i class="fa fa-boxes-stacked me-2 text-primary"></i>Full Inventory Aging List</h6>
                <div class="text-muted small">All unsold inventory — oldest first. Rows in red need immediate action.</div>
            </div>
            <a href="<?= BASE_URL ?>/modules/reports/export.php?type=inventory_aging"
               class="btn btn-xs btn-outline-secondary">
                <i class="fa fa-file-csv me-1"></i>Export CSV
            </a>
        </div>
        <div class="table-responsive">
            <table class="table table-hover mb-0 datatable" style="font-size:13px">
                <thead style="font-size:11.5px;color:#64748b;text-transform:uppercase;letter-spacing:.05em;background:#f8fafc">
                    <tr>
                        <th class="ps-4 py-3">Vehicle</th>
                        <th class="py-3">Status</th>
                        <th class="py-3">Location</th>
                        <th class="py-3 text-end">Asking Price</th>
                        <th class="py-3 text-center">Days in Stock</th>
                        <th class="py-3 text-center">Date Added</th>
                        <th class="py-3 text-center pe-4">Age Band</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($aging as $car):
                    $d = (int)$car['days_in_stock'];
                    if ($d <= 30)     { $ageCls = 'success'; $ageLabel = '0–30 days'; }
                    elseif ($d <= 60) { $ageCls = 'primary'; $ageLabel = '31–60 days'; }
                    elseif ($d <= 90) { $ageCls = 'warning'; $ageLabel = '61–90 days'; }
                    else              { $ageCls = 'danger';  $ageLabel = '90+ days'; }
                ?>
                <tr class="<?= $d > 90 ? 'table-danger' : '' ?>">
                    <td class="ps-4 py-3">
                        <a href="<?= BASE_URL ?>/modules/cars/view.php?id=<?= $car['id'] ?>" class="fw-semibold text-decoration-none">
                            <?= e($car['year'] . ' ' . $car['make'] . ' ' . $car['model']) ?>
                        </a>
                        <?php if ($car['color']): ?>
                        <div class="text-muted" style="font-size:11px"><?= e($car['color']) ?></div>
                        <?php endif; ?>
                    </td>
                    <td class="py-3"><?= statusBadge($car['status']) ?></td>
                    <td class="py-3 text-muted small"><?= e($car['location_name'] ?? '—') ?></td>
                    <td class="py-3 text-end fw-semibold">
                        <?= $car['asking_price'] ? money((float)$car['asking_price']) : '<span class="text-muted">—</span>' ?>
                    </td>
                    <td class="py-3 text-center fw-bold <?= $d > 90 ? 'text-danger' : ($d > 60 ? 'text-warning' : 'text-success') ?>">
                        <?= $d ?>
                    </td>
                    <td class="py-3 text-center text-muted small"><?= fmtDate($car['stock_date'], 'd M Y') ?></td>
                    <td class="py-3 text-center pe-4">
                        <span class="badge bg-<?= $ageCls ?>-subtle text-<?= $ageCls ?> border border-<?= $ageCls ?>-subtle" style="font-size:11px">
                            <?= $ageLabel ?>
                        </span>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (!$aging): ?>
                <tr><td colspan="7" class="text-center text-muted py-5">
                    <i class="fa fa-box-open fa-2x mb-2 d-block opacity-25"></i>No inventory cars in stock.
                </td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
(function () {
    const isDark   = document.documentElement.getAttribute('data-theme') === 'dark';
    const grid     = isDark ? 'rgba(255,255,255,.07)' : 'rgba(0,0,0,.06)';
    const labelClr = isDark ? '#94a3b8' : '#64748b';
    const data     = <?= json_encode(array_values($buckets)) ?>;
    const labels   = ['0–30 days', '31–60 days', '61–90 days', '90+ days'];
    const colors   = ['#16a34a', '#2563eb', '#d97706', '#dc2626'];

    new Chart(document.getElementById('ageChart'), {
        type: 'bar',
        data: {
            labels,
            datasets: [{
                label: 'Cars',
                data,
                backgroundColor: colors.map(c => c + '28'),
                borderColor: colors,
                borderWidth: 2,
                borderRadius: 8,
            }]
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            plugins: { legend: { display: false } },
            scales: {
                x: { ticks: { color: labelClr, font: { size: 11 } }, grid: { color: grid } },
                y: { ticks: { color: labelClr, font: { size: 11 }, stepSize: 1 }, grid: { color: grid }, beginAtZero: true }
            }
        }
    });
}());
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>

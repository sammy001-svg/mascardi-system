<?php
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
$pageTitle = 'Reports';
$db = getDB();

// ── Date range filter ────────────────────────────────────────────────────────
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
    default: // this_month
        $dateFrom = date('Y-m-01');
        $dateTo   = date('Y-m-d');
        $label    = 'This Month (' . date('M Y') . ')';
        break;
}

// ── Revenue (monthly for last 6 months) ─────────────────────────────────────
$monthlyRevenue = $db->query("
    SELECT DATE_FORMAT(created_at,'%b %Y') AS month_label,
           DATE_FORMAT(created_at,'%Y-%m') AS month_key,
           SUM(total)      AS revenue,
           COUNT(*)        AS invoice_count
    FROM invoices
    WHERE status = 'paid'
      AND created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
    GROUP BY month_key, month_label
    ORDER BY month_key ASC
")->fetchAll();

// ── Period revenue & invoice totals ─────────────────────────────────────────
$periodRevenue = $db->prepare("
    SELECT COALESCE(SUM(total),0) AS paid,
           COUNT(*) AS total_invoices,
           SUM(CASE WHEN status='unpaid'  THEN 1 ELSE 0 END) AS unpaid,
           SUM(CASE WHEN status='partial' THEN 1 ELSE 0 END) AS partial,
           SUM(CASE WHEN status='paid'    THEN 1 ELSE 0 END) AS paid_count,
           COALESCE(SUM(CASE WHEN status='unpaid' THEN total END),0) AS unpaid_amount
    FROM invoices
    WHERE DATE(created_at) BETWEEN ? AND ?
");
$periodRevenue->execute([$dateFrom, $dateTo]);
$revSummary = $periodRevenue->fetch();

// ── Cars by status ────────────────────────────────────────────────────────────
$carsByStatus = $db->query("SELECT status, COUNT(*) AS cnt FROM cars GROUP BY status ORDER BY cnt DESC")->fetchAll();
$totalCars    = array_sum(array_column($carsByStatus, 'cnt'));

// ── Cars by make ──────────────────────────────────────────────────────────────
$carsByMake = $db->query("SELECT make, COUNT(*) AS cnt FROM cars GROUP BY make ORDER BY cnt DESC LIMIT 8")->fetchAll();

// ── Cars added in period ──────────────────────────────────────────────────────
$periodCarsStmt = $db->prepare("SELECT COUNT(*) FROM cars WHERE DATE(created_at) BETWEEN ? AND ?");
$periodCarsStmt->execute([$dateFrom, $dateTo]);
$periodCars = (int)$periodCarsStmt->fetchColumn();

// ── Jobs by status ────────────────────────────────────────────────────────────
$jobsByStatus = $db->query("SELECT status, COUNT(*) AS cnt FROM workshop_jobs GROUP BY status ORDER BY cnt DESC")->fetchAll();

// ── Jobs in period ────────────────────────────────────────────────────────────
$periodJobsStmt = $db->prepare("SELECT COUNT(*) FROM workshop_jobs WHERE DATE(created_at) BETWEEN ? AND ?");
$periodJobsStmt->execute([$dateFrom, $dateTo]);
$periodJobs = (int)$periodJobsStmt->fetchColumn();

// ── Top mechanics by completed jobs ───────────────────────────────────────────
$topMechanics = $db->query("
    SELECT m.name,
           COUNT(j.id)                                          AS total_jobs,
           SUM(j.status = 'completed')                          AS completed,
           SUM(j.status IN ('in_progress','pending'))           AS active
    FROM mechanics m
    JOIN workshop_jobs j ON j.mechanic_id = m.id
    GROUP BY m.id, m.name
    ORDER BY total_jobs DESC
    LIMIT 6
")->fetchAll();

// ── Inventory: low stock ──────────────────────────────────────────────────────
$lowStock = $db->query("
    SELECT part_name, part_number, category, quantity, reorder_level, unit
    FROM inventory
    WHERE quantity <= reorder_level
    ORDER BY (quantity / GREATEST(reorder_level,1)) ASC
    LIMIT 10
")->fetchAll();
$lowStockCount = count($lowStock);

// ── Inventory: total value ────────────────────────────────────────────────────
$invSummary = $db->query("
    SELECT COUNT(*) AS total_parts,
           SUM(quantity * unit_price) AS stock_value
    FROM inventory
")->fetch();

// ── Recent high-value invoices ────────────────────────────────────────────────
$topInvoices = $db->prepare("
    SELECT i.invoice_number, i.total, i.status, i.created_at,
           c.make, c.model, c.chassis_number
    FROM invoices i
    JOIN cars c ON c.id = i.car_id
    WHERE DATE(i.created_at) BETWEEN ? AND ?
    ORDER BY i.total DESC
    LIMIT 8
");
$topInvoices->execute([$dateFrom, $dateTo]);
$topInvoices = $topInvoices->fetchAll();

// ── Chart JSON ────────────────────────────────────────────────────────────────
$revenueLabels  = json_encode(array_column($monthlyRevenue, 'month_label'));
$revenueAmounts = json_encode(array_map(fn($r) => round($r['revenue'], 2), $monthlyRevenue));
$statusLabels   = json_encode(array_map(fn($r) => ucwords(str_replace('_', ' ', $r['status'])), $carsByStatus));
$statusCounts   = json_encode(array_column($carsByStatus, 'cnt'));
$makeLabels     = json_encode(array_column($carsByMake, 'make'));
$makeCounts     = json_encode(array_column($carsByMake, 'cnt'));

$extraJs = <<<SCRIPT
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
(function () {
    var blueShades = ['#2563eb','#3b82f6','#60a5fa','#93c5fd','#bfdbfe','#dbeafe'];
    var statusColorMap = {
        'In Transit':'#d97706','In Workshop':'#db2777','Completed':'#16a34a',
        'Arrived':'#0284c7','In Assessment':'#7c3aed','Delivered':'#0f172a','Pending':'#64748b'
    };

    // Revenue Bar Chart
    var revenueEl = document.getElementById('revenueChart');
    if (revenueEl) {
        new Chart(revenueEl, {
            type: 'bar',
            data: {
                labels: {$revenueLabels},
                datasets: [{
                    label: 'Revenue (KES)',
                    data: {$revenueAmounts},
                    backgroundColor: 'rgba(37,99,235,.75)',
                    borderColor: '#2563eb',
                    borderWidth: 1,
                    borderRadius: 6
                }]
            },
            options: {
                responsive: true,
                plugins: { legend: { display: false } },
                scales: {
                    y: { beginAtZero: true, ticks: { callback: function(v){ return 'KES '+v.toLocaleString(); } } }
                }
            }
        });
    }

    // Fleet Status Donut
    var statusEl = document.getElementById('statusChart');
    if (statusEl) {
        var sLabels = {$statusLabels};
        var sColors = sLabels.map(function(l){ return statusColorMap[l] || '#94a3b8'; });
        new Chart(statusEl, {
            type: 'doughnut',
            data: { labels: sLabels, datasets: [{ data: {$statusCounts}, backgroundColor: sColors, borderWidth: 2, borderColor: '#fff' }] },
            options: { cutout: '60%', plugins: { legend: { position: 'bottom', labels: { font:{size:11}, padding:10, boxWidth:12 } } } }
        });
    }

    // Cars by Make Bar
    var makeEl = document.getElementById('makeChart');
    if (makeEl) {
        new Chart(makeEl, {
            type: 'bar',
            data: {
                labels: {$makeLabels},
                datasets: [{ label: 'Cars', data: {$makeCounts}, backgroundColor: blueShades, borderRadius: 6 }]
            },
            options: {
                indexAxis: 'y',
                responsive: true,
                plugins: { legend: { display: false } },
                scales: { x: { beginAtZero: true, ticks: { stepSize: 1 } } }
            }
        });
    }
}());
</script>
SCRIPT;

include __DIR__ . '/../../includes/header.php';
?>

<!-- Period filter -->
<div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-2">
    <h5 class="mb-0"><i class="fa fa-chart-bar me-2 text-primary"></i>Reports &amp; Analytics</h5>
    <form class="d-flex align-items-center gap-2 flex-wrap" method="GET">
        <select name="period" class="form-select form-select-sm" style="width:auto" onchange="this.form.submit()">
            <option value="this_month"    <?= $period === 'this_month'    ? 'selected' : '' ?>>This Month</option>
            <option value="last_month"    <?= $period === 'last_month'    ? 'selected' : '' ?>>Last Month</option>
            <option value="last_3_months" <?= $period === 'last_3_months' ? 'selected' : '' ?>>Last 3 Months</option>
            <option value="this_year"     <?= $period === 'this_year'     ? 'selected' : '' ?>>This Year</option>
            <option value="custom"        <?= $period === 'custom'        ? 'selected' : '' ?>>Custom Range</option>
        </select>
        <?php if ($period === 'custom'): ?>
        <input type="date" name="date_from" class="form-control form-control-sm" value="<?= e($dateFrom) ?>">
        <span class="text-muted small">to</span>
        <input type="date" name="date_to" class="form-control form-control-sm" value="<?= e($dateTo) ?>">
        <button type="submit" class="btn btn-sm btn-primary">Apply</button>
        <?php endif; ?>
        <span class="badge bg-light text-dark border px-3 py-2"><?= e($label) ?></span>
    </form>
</div>

<!-- ── KPI Cards ────────────────────────────────────────────────────────────── -->
<div class="row g-3 mb-4">
    <div class="col-sm-6 col-xl-3">
        <div class="stat-card" style="border-left:4px solid #16a34a">
            <div class="stat-icon" style="background:#dcfce7;color:#16a34a"><i class="fa fa-money-bill-wave"></i></div>
            <div class="stat-info">
                <div class="stat-label">Revenue Collected</div>
                <div class="stat-value stat-value-sm"><?= money((float)$revSummary['paid']) ?></div>
                <div class="text-muted" style="font-size:11px"><?= $revSummary['paid_count'] ?> paid invoices</div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="stat-card" style="border-left:4px solid #dc2626">
            <div class="stat-icon" style="background:#fee2e2;color:#dc2626"><i class="fa fa-file-invoice-dollar"></i></div>
            <div class="stat-info">
                <div class="stat-label">Outstanding Balance</div>
                <div class="stat-value stat-value-sm"><?= money((float)$revSummary['unpaid_amount']) ?></div>
                <div class="text-muted" style="font-size:11px"><?= $revSummary['unpaid'] ?> unpaid invoices</div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="stat-card" style="border-left:4px solid #2563eb">
            <div class="stat-icon" style="background:#dbeafe;color:#2563eb"><i class="fa fa-car"></i></div>
            <div class="stat-info">
                <div class="stat-label">Cars Added</div>
                <div class="stat-value"><?= $periodCars ?></div>
                <div class="text-muted" style="font-size:11px"><?= $totalCars ?> total in fleet</div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="stat-card" style="border-left:4px solid #9333ea">
            <div class="stat-icon" style="background:#fdf4ff;color:#9333ea"><i class="fa fa-toolbox"></i></div>
            <div class="stat-info">
                <div class="stat-label">Jobs Created</div>
                <div class="stat-value"><?= $periodJobs ?></div>
                <div class="text-muted" style="font-size:11px"><?= $lowStockCount ?> low stock alerts</div>
            </div>
        </div>
    </div>
</div>

<!-- ── Revenue Chart + Fleet Status ─────────────────────────────────────────── -->
<div class="row g-4 mb-4">
    <div class="col-lg-8">
        <div class="card h-100">
            <div class="card-header"><i class="fa fa-chart-line me-2"></i>Monthly Revenue — Last 6 Months</div>
            <div class="card-body">
                <?php if (empty($monthlyRevenue)): ?>
                <div class="empty-state"><i class="fa fa-chart-bar fa-2x text-muted mb-2"></i><p class="text-muted mb-0">No revenue data yet</p></div>
                <?php else: ?>
                <canvas id="revenueChart" height="90"></canvas>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card h-100">
            <div class="card-header"><i class="fa fa-chart-pie me-2"></i>Fleet by Status</div>
            <div class="card-body d-flex flex-column align-items-center justify-content-center">
                <?php if (empty($carsByStatus)): ?>
                <div class="empty-state"><i class="fa fa-car fa-2x text-muted mb-2"></i><p class="text-muted mb-0">No cars yet</p></div>
                <?php else: ?>
                <canvas id="statusChart" height="220"></canvas>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- ── Cars by Make + Jobs by Status ────────────────────────────────────────── -->
<div class="row g-4 mb-4">
    <div class="col-lg-5">
        <div class="card h-100">
            <div class="card-header"><i class="fa fa-car-side me-2"></i>Fleet by Make</div>
            <div class="card-body">
                <?php if (empty($carsByMake)): ?>
                <div class="empty-state"><i class="fa fa-car fa-2x text-muted mb-2"></i><p class="text-muted mb-0">No cars yet</p></div>
                <?php else: ?>
                <canvas id="makeChart" height="180"></canvas>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-lg-7">
        <div class="card h-100">
            <div class="card-header"><i class="fa fa-toolbox me-2"></i>Workshop Jobs by Status</div>
            <div class="card-body p-0">
                <table class="table table-hover mb-0">
                    <thead><tr><th class="ps-3">Status</th><th class="text-end pe-3">Count</th><th class="pe-3">Share</th></tr></thead>
                    <tbody>
                        <?php
                        $totalJobs = array_sum(array_column($jobsByStatus, 'cnt'));
                        foreach ($jobsByStatus as $row):
                            $pct = $totalJobs ? round($row['cnt'] / $totalJobs * 100) : 0;
                        ?>
                        <tr>
                            <td class="ps-3"><?= statusBadge($row['status']) ?></td>
                            <td class="text-end pe-3 fw-semibold"><?= $row['cnt'] ?></td>
                            <td class="pe-3" style="width:40%">
                                <div class="d-flex align-items-center gap-2">
                                    <div class="progress flex-grow-1" style="height:6px">
                                        <div class="progress-bar bg-primary" style="width:<?= $pct ?>%"></div>
                                    </div>
                                    <span class="text-muted small" style="min-width:30px"><?= $pct ?>%</span>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($jobsByStatus)): ?>
                        <tr><td colspan="3" class="text-center text-muted py-4">No jobs yet</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- ── Top Mechanics + Low Stock ────────────────────────────────────────────── -->
<div class="row g-4 mb-4">
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header"><i class="fa fa-screwdriver-wrench me-2"></i>Mechanic Performance</div>
            <div class="card-body p-0">
                <table class="table table-hover mb-0">
                    <thead><tr><th class="ps-3">Mechanic</th><th class="text-center">Total</th><th class="text-center">Done</th><th class="text-center">Active</th></tr></thead>
                    <tbody>
                        <?php foreach ($topMechanics as $mech): ?>
                        <tr>
                            <td class="ps-3 fw-medium"><?= e($mech['name']) ?></td>
                            <td class="text-center"><?= $mech['total_jobs'] ?></td>
                            <td class="text-center"><span class="badge bg-success"><?= $mech['completed'] ?></span></td>
                            <td class="text-center"><span class="badge bg-primary"><?= $mech['active'] ?></span></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($topMechanics)): ?>
                        <tr><td colspan="4" class="text-center text-muted py-4">No job assignments yet</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="fa fa-boxes-stacked me-2"></i>Low Stock Items</span>
                <?php if ($lowStockCount): ?>
                <a href="<?= BASE_URL ?>/modules/inventory/index.php?filter=low_stock" class="btn btn-xs btn-outline-warning">View All</a>
                <?php endif; ?>
            </div>
            <div class="card-body p-0">
                <?php if (empty($lowStock)): ?>
                <div class="empty-state">
                    <i class="fa fa-circle-check fa-2x text-success mb-2"></i>
                    <p class="text-muted mb-0">All stock levels are healthy</p>
                </div>
                <?php else: ?>
                <table class="table table-hover mb-0">
                    <thead><tr><th class="ps-3">Part</th><th>Category</th><th class="text-end pe-3">Qty / Reorder</th></tr></thead>
                    <tbody>
                        <?php foreach ($lowStock as $part): ?>
                        <tr>
                            <td class="ps-3">
                                <div class="fw-medium small"><?= e($part['part_name']) ?></div>
                                <?php if ($part['part_number']): ?><div class="text-muted" style="font-size:11px"><?= e($part['part_number']) ?></div><?php endif; ?>
                            </td>
                            <td class="text-muted small"><?= e($part['category'] ?? '—') ?></td>
                            <td class="text-end pe-3">
                                <span class="<?= $part['quantity'] == 0 ? 'text-danger fw-bold' : 'text-warning fw-semibold' ?>">
                                    <?= number_format($part['quantity'], 0) ?>
                                </span>
                                <span class="text-muted small"> / <?= number_format($part['reorder_level'], 0) ?> <?= e($part['unit']) ?></span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- ── Top Invoices in Period ──────────────────────────────────────────────── -->
<div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="fa fa-file-invoice-dollar me-2"></i>Invoices — <?= e($label) ?></span>
        <div class="d-flex gap-3 text-muted small">
            <span><i class="fa fa-circle-check text-success me-1"></i><?= $revSummary['paid_count'] ?> paid</span>
            <span><i class="fa fa-clock text-warning me-1"></i><?= $revSummary['partial'] ?> partial</span>
            <span><i class="fa fa-circle-xmark text-danger me-1"></i><?= $revSummary['unpaid'] ?> unpaid</span>
            <span class="fw-semibold text-dark">
                Total: <?= money((float)$revSummary['paid'] + (float)$revSummary['unpaid_amount']) ?>
            </span>
        </div>
    </div>
    <div class="card-body p-0">
        <table class="table table-hover mb-0">
            <thead>
                <tr>
                    <th class="ps-3">Invoice #</th>
                    <th>Vehicle</th>
                    <th>Chassis</th>
                    <th>Date</th>
                    <th>Status</th>
                    <th class="text-end pe-3">Amount</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($topInvoices)): ?>
                <tr><td colspan="6" class="text-center text-muted py-4">No invoices in this period</td></tr>
                <?php else: ?>
                <?php foreach ($topInvoices as $inv): ?>
                <tr>
                    <td class="ps-3">
                        <a href="<?= BASE_URL ?>/modules/invoices/view.php?id=<?= $inv['id'] ?? '' ?>" class="fw-medium text-primary text-decoration-none">
                            <?= e($inv['invoice_number']) ?>
                        </a>
                    </td>
                    <td><?= e($inv['make'] . ' ' . $inv['model']) ?></td>
                    <td><code style="font-size:11px"><?= e($inv['chassis_number']) ?></code></td>
                    <td class="text-muted small"><?= fmtDate($inv['created_at']) ?></td>
                    <td><?= statusBadge($inv['status']) ?></td>
                    <td class="text-end pe-3 fw-semibold"><?= money((float)$inv['total']) ?></td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- ── Inventory Summary ─────────────────────────────────────────────────────── -->
<div class="card">
    <div class="card-header"><i class="fa fa-boxes-stacked me-2"></i>Inventory Summary</div>
    <div class="card-body">
        <div class="row g-4 text-center">
            <div class="col-sm-4">
                <div class="fs-3 fw-bold text-primary"><?= number_format((int)$invSummary['total_parts']) ?></div>
                <div class="text-muted small">Total Part Types</div>
            </div>
            <div class="col-sm-4">
                <div class="fs-3 fw-bold text-danger"><?= $lowStockCount ?></div>
                <div class="text-muted small">Items Below Reorder Level</div>
            </div>
            <div class="col-sm-4">
                <div class="fs-3 fw-bold text-success"><?= money((float)($invSummary['stock_value'] ?? 0)) ?></div>
                <div class="text-muted small">Estimated Stock Value</div>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>

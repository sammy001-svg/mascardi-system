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

// ── Payment aging (days overdue) ─────────────────────────────────────────────
$agingBuckets = $db->query("
    SELECT
        SUM(CASE WHEN DATEDIFF(NOW(), due_date) BETWEEN 1  AND 30  THEN total - amount_paid ELSE 0 END) AS bucket_30,
        SUM(CASE WHEN DATEDIFF(NOW(), due_date) BETWEEN 31 AND 60  THEN total - amount_paid ELSE 0 END) AS bucket_60,
        SUM(CASE WHEN DATEDIFF(NOW(), due_date) BETWEEN 61 AND 90  THEN total - amount_paid ELSE 0 END) AS bucket_90,
        SUM(CASE WHEN DATEDIFF(NOW(), due_date)  > 90              THEN total - amount_paid ELSE 0 END) AS bucket_over90
    FROM invoices
    WHERE status IN ('unpaid','partial') AND due_date < NOW()
")->fetch();

$overdueInvoices = $db->query("
    SELECT i.invoice_number, i.due_date, (i.total - i.amount_paid) AS balance,
           DATEDIFF(NOW(), i.due_date) AS days_overdue,
           c.make, c.model, i.customer_name
    FROM invoices i
    JOIN cars c ON c.id = i.car_id
    WHERE i.status IN ('unpaid','partial') AND i.due_date < NOW()
    ORDER BY days_overdue DESC
    LIMIT 10
")->fetchAll();

// ── Vehicle lifecycle (days from intake to delivery) ─────────────────────────
$lifecycleData = $db->prepare("
    SELECT c.id, c.make, c.model, c.chassis_number,
           ci.intake_date,
           c.created_at AS added_date,
           MAX(CASE WHEN c.status = 'delivered' THEN c.updated_at END) AS delivered_date,
           DATEDIFF(COALESCE(MAX(CASE WHEN c.status = 'delivered' THEN c.updated_at END), NOW()), ci.intake_date) AS days_in_system
    FROM cars c
    LEFT JOIN car_intake ci ON ci.car_id = c.id
    WHERE ci.intake_date IS NOT NULL
    GROUP BY c.id, c.make, c.model, c.chassis_number, ci.intake_date, c.created_at
    ORDER BY days_in_system DESC
    LIMIT 8
");
$lifecycleData->execute();
$lifecycleData = $lifecycleData->fetchAll();

$avgLifecycle = $db->query("
    SELECT AVG(days) AS avg_days
    FROM (
        SELECT DATEDIFF(
            COALESCE(MAX(CASE WHEN c.status = 'delivered' THEN c.updated_at END), NOW()),
            MIN(ci.intake_date)
        ) AS days
        FROM cars c
        JOIN car_intake ci ON ci.car_id = c.id
        GROUP BY c.id
    ) t
")->fetchColumn();

// ── Inventory turnover ────────────────────────────────────────────────────────
$inventoryTurnover = $db->query("
    SELECT i.part_name, i.category,
           i.quantity AS current_stock,
           COALESCE(SUM(CASE WHEN it.transaction_type = 'out' THEN it.quantity ELSE 0 END), 0) AS total_issued,
           i.unit_price
    FROM inventory i
    LEFT JOIN inventory_transactions it ON it.inventory_id = i.id
        AND it.created_at >= DATE_SUB(NOW(), INTERVAL 3 MONTH)
    GROUP BY i.id, i.part_name, i.category, i.quantity, i.unit_price
    ORDER BY total_issued DESC
    LIMIT 8
")->fetchAll();

// ── Chart JSON ────────────────────────────────────────────────────────────────
$revenueLabels  = json_encode(array_column($monthlyRevenue, 'month_label'));
$revenueAmounts = json_encode(array_map(fn($r) => round($r['revenue'], 2), $monthlyRevenue));
$statusLabels   = json_encode(array_map(fn($r) => ucwords(str_replace('_', ' ', $r['status'])), $carsByStatus));
$statusCounts   = json_encode(array_column($carsByStatus, 'cnt'));
$makeLabels     = json_encode(array_column($carsByMake, 'make'));
$makeCounts     = json_encode(array_column($carsByMake, 'cnt'));

$agingData    = json_encode([(float)($agingBuckets['bucket_30']??0),(float)($agingBuckets['bucket_60']??0),(float)($agingBuckets['bucket_90']??0),(float)($agingBuckets['bucket_over90']??0)]);

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
    // Payment Aging Donut
    var agingEl = document.getElementById('agingChart');
    if (agingEl) {
        new Chart(agingEl, {
            type: 'doughnut',
            data: {
                labels: ['1-30 days','31-60 days','61-90 days','90+ days'],
                datasets: [{
                    data: {$agingData},
                    backgroundColor: ['#f59e0b','#f97316','#ef4444','#991b1b'],
                    borderWidth: 2, borderColor: '#fff'
                }]
            },
            options: { cutout: '55%', plugins: { legend: { position: 'bottom', labels: { font:{size:10}, padding:8, boxWidth:10 } } } }
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
<div class="card mb-4">
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

<!-- ── Payment Aging ──────────────────────────────────────────────────────────── -->
<div class="row g-4 mb-4">
    <div class="col-lg-5">
        <div class="card h-100">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="fa fa-hourglass-half me-2 text-danger"></i>Overdue Payment Aging</span>
                <span class="badge bg-danger"><?= count($overdueInvoices) ?> overdue</span>
            </div>
            <div class="card-body">
                <div class="row g-3 text-center mb-3">
                    <div class="col-3">
                        <div class="fw-bold text-warning"><?= money((float)($agingBuckets['bucket_30']??0)) ?></div>
                        <div class="text-muted" style="font-size:11px">1–30 days</div>
                    </div>
                    <div class="col-3">
                        <div class="fw-bold text-orange"><?= money((float)($agingBuckets['bucket_60']??0)) ?></div>
                        <div class="text-muted" style="font-size:11px">31–60 days</div>
                    </div>
                    <div class="col-3">
                        <div class="fw-bold text-danger"><?= money((float)($agingBuckets['bucket_90']??0)) ?></div>
                        <div class="text-muted" style="font-size:11px">61–90 days</div>
                    </div>
                    <div class="col-3">
                        <div class="fw-bold text-danger"><?= money((float)($agingBuckets['bucket_over90']??0)) ?></div>
                        <div class="text-muted" style="font-size:11px">&gt;90 days</div>
                    </div>
                </div>
                <canvas id="agingChart" height="160"></canvas>
            </div>
        </div>
    </div>
    <div class="col-lg-7">
        <div class="card h-100">
            <div class="card-header"><i class="fa fa-exclamation-triangle me-2 text-warning"></i>Top Overdue Invoices</div>
            <div class="card-body p-0">
                <table class="table table-hover mb-0">
                    <thead><tr><th class="ps-3">Invoice #</th><th>Customer</th><th>Vehicle</th><th>Days Overdue</th><th class="text-end pe-3">Balance</th></tr></thead>
                    <tbody>
                        <?php if (empty($overdueInvoices)): ?>
                        <tr><td colspan="5" class="text-center text-muted py-4"><i class="fa fa-check-circle text-success me-1"></i>No overdue invoices</td></tr>
                        <?php else: ?>
                        <?php foreach ($overdueInvoices as $oi): ?>
                        <tr>
                            <td class="ps-3"><a href="<?= BASE_URL ?>/modules/invoices/view.php?id=<?= $oi['id'] ?? '' ?>" class="fw-medium text-primary text-decoration-none"><?= e($oi['invoice_number']) ?></a></td>
                            <td class="small"><?= e($oi['customer_name'] ?? '—') ?></td>
                            <td class="small"><?= e($oi['make'] . ' ' . $oi['model']) ?></td>
                            <td>
                                <span class="badge bg-<?= $oi['days_overdue'] > 90 ? 'danger' : ($oi['days_overdue'] > 30 ? 'warning text-dark' : 'secondary') ?>">
                                    <?= $oi['days_overdue'] ?> days
                                </span>
                            </td>
                            <td class="text-end pe-3 text-danger fw-semibold"><?= money((float)$oi['balance']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- ── Vehicle Lifecycle + Inventory Turnover ─────────────────────────────────── -->
<div class="row g-4 mb-4">
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="fa fa-route me-2 text-primary"></i>Vehicle Lifecycle (Intake → Delivery)</span>
                <?php if ($avgLifecycle): ?>
                <span class="badge bg-info">Avg: <?= round((float)$avgLifecycle) ?> days</span>
                <?php endif; ?>
            </div>
            <div class="card-body p-0">
                <table class="table table-hover mb-0">
                    <thead><tr><th class="ps-3">Vehicle</th><th>Chassis</th><th>Intake Date</th><th class="text-end pe-3">Days in System</th></tr></thead>
                    <tbody>
                        <?php if (empty($lifecycleData)): ?>
                        <tr><td colspan="4" class="text-center text-muted py-4">No intake records found</td></tr>
                        <?php else: ?>
                        <?php foreach ($lifecycleData as $lv): ?>
                        <?php $daysColor = $lv['days_in_system'] > 90 ? 'danger' : ($lv['days_in_system'] > 45 ? 'warning' : 'success'); ?>
                        <tr>
                            <td class="ps-3 fw-medium small"><?= e($lv['make'] . ' ' . $lv['model']) ?></td>
                            <td><code style="font-size:10px"><?= e($lv['chassis_number']) ?></code></td>
                            <td class="text-muted small"><?= fmtDate($lv['intake_date']) ?></td>
                            <td class="text-end pe-3">
                                <span class="badge bg-<?= $daysColor ?>"><?= $lv['days_in_system'] ?> days</span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header"><i class="fa fa-arrows-rotate me-2 text-success"></i>Inventory Turnover (Last 3 Months)</div>
            <div class="card-body p-0">
                <table class="table table-hover mb-0">
                    <thead><tr><th class="ps-3">Part Name</th><th>Category</th><th class="text-center">Current Stock</th><th class="text-end pe-3">Issued (3 mo)</th></tr></thead>
                    <tbody>
                        <?php if (empty($inventoryTurnover)): ?>
                        <tr><td colspan="4" class="text-center text-muted py-4">No inventory movement data</td></tr>
                        <?php else: ?>
                        <?php foreach ($inventoryTurnover as $part): ?>
                        <tr>
                            <td class="ps-3 small fw-medium"><?= e($part['part_name']) ?></td>
                            <td class="small text-muted"><?= e($part['category'] ?? '—') ?></td>
                            <td class="text-center">
                                <span class="<?= $part['current_stock'] == 0 ? 'text-danger fw-bold' : '' ?>"><?= number_format($part['current_stock']) ?></span>
                            </td>
                            <td class="text-end pe-3">
                                <span class="badge bg-<?= $part['total_issued'] > 0 ? 'success' : 'secondary' ?>"><?= number_format($part['total_issued']) ?></span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php
// ── Expenses for P&L ─────────────────────────────────────────────────────────
try {
    $expSummary = $db->prepare("
        SELECT
            COALESCE(SUM(amount),0)                                            AS total_expenses,
            COALESCE(SUM(CASE WHEN category='salaries'  THEN amount END), 0)  AS salaries,
            COALESCE(SUM(CASE WHEN category='rent'      THEN amount END), 0)  AS rent,
            COALESCE(SUM(CASE WHEN category='fuel'      THEN amount END), 0)  AS fuel,
            COALESCE(SUM(CASE WHEN category='utilities' THEN amount END), 0)  AS utilities,
            COALESCE(SUM(CASE WHEN category='marketing' THEN amount END), 0)  AS marketing,
            COALESCE(SUM(CASE WHEN category='maintenance' THEN amount END), 0) AS maintenance,
            COALESCE(SUM(CASE WHEN category='office'    THEN amount END), 0)  AS office,
            COALESCE(SUM(CASE WHEN category='insurance' THEN amount END), 0)  AS insurance,
            COALESCE(SUM(CASE WHEN category='taxes'     THEN amount END), 0)  AS taxes,
            COALESCE(SUM(CASE WHEN category='other'     THEN amount END), 0)  AS other
        FROM expenses
        WHERE DATE(expense_date) BETWEEN ? AND ?
    ");
    $expSummary->execute([$dateFrom, $dateTo]);
    $expSummary = $expSummary->fetch();
    $hasExpenses = true;
} catch (\Throwable $e) { $hasExpenses = false; $expSummary = null; }

// ── P&L / Profit section (requires car_costs table) ──────────────────────────
try {
    $profitRows = $db->prepare("
        SELECT c.make, c.model, c.year, c.chassis_number,
               cs.sale_number, cs.sale_date, cs.buyer_name,
               cs.sale_price,
               (cc.purchase_price + cc.freight + cc.marine_insurance + cc.port_charges
                + cc.duty_tax + cc.clearing_fees + cc.transport_to_yard
                + cc.workshop_costs + cc.other_costs) AS total_cost,
               cs.sale_price - (cc.purchase_price + cc.freight + cc.marine_insurance + cc.port_charges
                + cc.duty_tax + cc.clearing_fees + cc.transport_to_yard
                + cc.workshop_costs + cc.other_costs) AS gross_profit,
               cs.id AS sale_id
        FROM car_sales cs
        JOIN cars c ON c.id = cs.car_id
        JOIN car_costs cc ON cc.car_id = cs.car_id
        WHERE cs.status = 'active'
          AND DATE(cs.sale_date) BETWEEN ? AND ?
        ORDER BY gross_profit DESC
    ");
    $profitRows->execute([$dateFrom, $dateTo]);
    $profitRows = $profitRows->fetchAll();

    $plSummary = $db->prepare("
        SELECT
            COUNT(cs.id)                                                                AS total_sold,
            COALESCE(SUM(cs.sale_price), 0)                                             AS total_revenue,
            COALESCE(SUM(cc.purchase_price + cc.freight + cc.marine_insurance
                       + cc.port_charges + cc.duty_tax + cc.clearing_fees
                       + cc.transport_to_yard + cc.workshop_costs + cc.other_costs), 0) AS total_costs,
            COALESCE(SUM(cs.sale_price - (cc.purchase_price + cc.freight + cc.marine_insurance
                       + cc.port_charges + cc.duty_tax + cc.clearing_fees
                       + cc.transport_to_yard + cc.workshop_costs + cc.other_costs)), 0) AS gross_profit
        FROM car_sales cs
        JOIN car_costs cc ON cc.car_id = cs.car_id
        WHERE cs.status = 'active'
          AND DATE(cs.sale_date) BETWEEN ? AND ?
    ");
    $plSummary->execute([$dateFrom, $dateTo]);
    $plSummary = $plSummary->fetch();
    $hasProfit = true;
} catch (\Throwable $e) { $hasProfit = false; $profitRows = []; $plSummary = null; }

// ── CRM stats ────────────────────────────────────────────────────────────────
try {
    $crmStats = $db->query("
        SELECT
            COUNT(*)                              AS total_leads,
            SUM(stage NOT IN ('closed_won','closed_lost')) AS active,
            SUM(stage = 'closed_won')             AS won,
            SUM(stage = 'closed_lost')            AS lost,
            SUM(stage = 'new')                    AS new_leads,
            SUM(stage = 'negotiation')            AS negotiation
        FROM crm_leads
    ")->fetch();
    $crmSources = $db->query("
        SELECT source, COUNT(*) AS cnt FROM crm_leads GROUP BY source ORDER BY cnt DESC
    ")->fetchAll();
    $hasCrm = true;
} catch (\Throwable $e) { $hasCrm = false; $crmStats = null; $crmSources = []; }

// ── Installment performance ───────────────────────────────────────────────────
try {
    $instStats = $db->query("
        SELECT
            COUNT(DISTINCT p.id)                                                AS total_plans,
            SUM(p.status = 'active')                                            AS active_plans,
            SUM(p.status = 'completed')                                         AS completed_plans,
            COALESCE(SUM(i.amount_paid), 0)                                     AS total_collected,
            COALESCE(SUM(CASE WHEN i.status = 'overdue' THEN i.amount_due - i.amount_paid ELSE 0 END), 0) AS overdue_amount,
            SUM(i.status = 'overdue')                                           AS overdue_count
        FROM sale_payment_plans p
        LEFT JOIN sale_installments i ON i.plan_id = p.id
    ")->fetch();
    $hasInst = true;
} catch (\Throwable $e) { $hasInst = false; $instStats = null; }
?>

<?php if ($hasExpenses && $expSummary): ?>
<!-- ── Expenses Breakdown ─────────────────────────────────────────────────── -->
<div class="card mb-4">
    <div class="card-header fw-semibold d-flex justify-content-between align-items-center">
        <span><i class="fa fa-receipt me-2 text-danger"></i>Operational Expenses — <?= e($label) ?></span>
        <a href="<?= BASE_URL ?>/modules/expenses/index.php" class="btn btn-xs btn-outline-secondary">View All</a>
    </div>
    <div class="card-body">
        <div class="row g-3 mb-3">
            <div class="col-md-4 text-center">
                <div class="fs-4 fw-bold text-danger"><?= money((float)$expSummary['total_expenses']) ?></div>
                <div class="text-muted small">Total Expenses</div>
            </div>
            <?php if ($hasProfit && $plSummary):
                $grossProfit = (float)($plSummary['gross_profit'] ?? 0);
                $totalExp    = (float)$expSummary['total_expenses'];
                $netProfit   = $grossProfit - $totalExp;
                $netColor    = $netProfit >= 0 ? 'success' : 'danger';
            ?>
            <div class="col-md-4 text-center">
                <div class="fs-4 fw-bold text-success"><?= money($grossProfit) ?></div>
                <div class="text-muted small">Gross Profit (Sales − COGS)</div>
            </div>
            <div class="col-md-4 text-center">
                <div class="fs-3 fw-bold text-<?= $netColor ?>"><?= money($netProfit) ?></div>
                <div class="text-muted small fw-semibold">NET PROFIT</div>
                <?php if ($grossProfit > 0): ?>
                <div class="badge bg-<?= $netColor ?> mt-1"><?= round($netProfit/$grossProfit*100,1) ?>% of gross</div>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
        <!-- Expense breakdown bars -->
        <?php
        $expCats = [
            'salaries'=>'Salaries & Wages','rent'=>'Rent','fuel'=>'Fuel & Transport',
            'utilities'=>'Utilities','marketing'=>'Marketing','maintenance'=>'Maintenance',
            'office'=>'Office','insurance'=>'Insurance','taxes'=>'Taxes','other'=>'Other'
        ];
        $expColors = [
            'salaries'=>'#2563eb','rent'=>'#7c3aed','fuel'=>'#d97706','utilities'=>'#0891b2',
            'marketing'=>'#ec4899','maintenance'=>'#16a34a','office'=>'#64748b',
            'insurance'=>'#0284c7','taxes'=>'#dc2626','other'=>'#94a3b8'
        ];
        $totalExp = (float)$expSummary['total_expenses'];
        foreach ($expCats as $key => $lbl):
            $val = (float)($expSummary[$key] ?? 0);
            if ($val <= 0) continue;
            $pct = $totalExp > 0 ? round($val/$totalExp*100) : 0;
            $col = $expColors[$key] ?? '#64748b';
        ?>
        <div class="d-flex align-items-center gap-3 mb-2" style="font-size:13px">
            <span style="min-width:140px;color:<?= $col ?>;font-weight:500"><?= $lbl ?></span>
            <div class="progress flex-grow-1" style="height:8px">
                <div class="progress-bar" style="width:<?= $pct ?>%;background:<?= $col ?>"></div>
            </div>
            <span class="fw-semibold" style="min-width:100px;text-align:right"><?= money($val) ?></span>
            <span class="text-muted small" style="min-width:36px"><?= $pct ?>%</span>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<?php if ($hasProfit): ?>
<!-- ── P&L Summary ─────────────────────────────────────────────────────────── -->
<div class="card mb-4">
    <div class="card-header fw-semibold">
        <i class="fa fa-scale-balanced me-2 text-success"></i>Profit &amp; Loss Summary — <?= e($label) ?>
    </div>
    <div class="card-body">
        <div class="row g-3 text-center mb-4">
            <div class="col-sm-3">
                <div class="text-muted small">Cars Sold (with costs)</div>
                <div class="fs-4 fw-bold text-primary"><?= (int)($plSummary['total_sold'] ?? 0) ?></div>
            </div>
            <div class="col-sm-3">
                <div class="text-muted small">Total Revenue</div>
                <div class="fs-5 fw-bold text-success"><?= money((float)($plSummary['total_revenue'] ?? 0)) ?></div>
            </div>
            <div class="col-sm-3">
                <div class="text-muted small">Total Cost of Goods</div>
                <div class="fs-5 fw-bold text-danger"><?= money((float)($plSummary['total_costs'] ?? 0)) ?></div>
            </div>
            <div class="col-sm-3">
                <?php $gp = (float)($plSummary['gross_profit'] ?? 0); ?>
                <div class="text-muted small">Gross Profit</div>
                <div class="fs-5 fw-bold <?= $gp >= 0 ? 'text-success' : 'text-danger' ?>"><?= money($gp) ?></div>
                <?php if ($plSummary['total_revenue'] > 0): ?>
                <div class="text-muted small"><?= round($gp / $plSummary['total_revenue'] * 100, 1) ?>% margin</div>
                <?php endif; ?>
            </div>
        </div>

        <?php if (!empty($profitRows)): ?>
        <table class="table table-hover mb-0" style="font-size:13px">
            <thead class="table-light">
                <tr>
                    <th class="ps-3">Vehicle</th>
                    <th>Sale #</th>
                    <th>Buyer</th>
                    <th>Sale Date</th>
                    <th class="text-end">Cost</th>
                    <th class="text-end">Sale Price</th>
                    <th class="text-end">Profit</th>
                    <th class="text-end">Margin</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($profitRows as $pr):
                $margin = $pr['sale_price'] > 0 ? round($pr['gross_profit'] / $pr['sale_price'] * 100, 1) : 0;
                $mc = $margin >= 20 ? 'bg-success' : ($margin >= 10 ? 'bg-warning text-dark' : 'bg-danger');
            ?>
            <tr>
                <td class="ps-3 fw-medium"><?= e($pr['make'].' '.$pr['model'].' '.$pr['year']) ?></td>
                <td><a href="<?= BASE_URL ?>/modules/sales/view.php?id=<?= $pr['sale_id'] ?>" class="text-decoration-none"><?= e($pr['sale_number']) ?></a></td>
                <td class="small"><?= e($pr['buyer_name']) ?></td>
                <td class="text-muted small"><?= fmtDate($pr['sale_date']) ?></td>
                <td class="text-end text-danger"><?= money((float)$pr['total_cost']) ?></td>
                <td class="text-end text-success fw-semibold"><?= money((float)$pr['sale_price']) ?></td>
                <td class="text-end fw-bold <?= $pr['gross_profit'] >= 0 ? 'text-success' : 'text-danger' ?>"><?= money((float)$pr['gross_profit']) ?></td>
                <td class="text-end"><span class="badge <?= $mc ?>"><?= $margin ?>%</span></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php else: ?>
        <div class="text-center text-muted py-3">
            <i class="fa fa-circle-info me-1"></i>No sales with import costs recorded in this period.
            <a href="<?= BASE_URL ?>/modules/car_costs/index.php" class="ms-1">Add costs →</a>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<?php if ($hasCrm || $hasInst): ?>
<div class="row g-4 mb-4">

    <?php if ($hasCrm && $crmStats): ?>
    <!-- CRM Pipeline Stats -->
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header fw-semibold"><i class="fa fa-funnel-dollar me-2 text-primary"></i>CRM Lead Pipeline</div>
            <div class="card-body">
                <div class="row g-3 text-center mb-3">
                    <div class="col-3">
                        <div class="fs-4 fw-bold text-secondary"><?= (int)$crmStats['total_leads'] ?></div>
                        <div class="text-muted small">Total Leads</div>
                    </div>
                    <div class="col-3">
                        <div class="fs-4 fw-bold text-primary"><?= (int)$crmStats['active'] ?></div>
                        <div class="text-muted small">Active</div>
                    </div>
                    <div class="col-3">
                        <div class="fs-4 fw-bold text-success"><?= (int)$crmStats['won'] ?></div>
                        <div class="text-muted small">Won</div>
                    </div>
                    <div class="col-3">
                        <div class="fs-4 fw-bold text-danger"><?= (int)$crmStats['lost'] ?></div>
                        <div class="text-muted small">Lost</div>
                    </div>
                </div>
                <?php if ($crmStats['total_leads'] > 0):
                    $convRate = round($crmStats['won'] / $crmStats['total_leads'] * 100, 1);
                ?>
                <div class="mb-2 d-flex justify-content-between small text-muted">
                    <span>Conversion Rate</span><span class="fw-semibold text-success"><?= $convRate ?>%</span>
                </div>
                <div class="progress mb-3" style="height:8px">
                    <div class="progress-bar bg-success" style="width:<?= $convRate ?>%"></div>
                </div>
                <?php endif; ?>
                <?php if (!empty($crmSources)): ?>
                <div class="small fw-semibold text-muted mb-2">Leads by Source</div>
                <?php $srcLabels=['walk_in'=>'Walk-in','referral'=>'Referral','facebook'=>'Facebook','instagram'=>'Instagram','website'=>'Website','phone_call'=>'Phone Call','whatsapp'=>'WhatsApp','other'=>'Other'];
                $totalSrc = array_sum(array_column($crmSources,'cnt'));
                foreach ($crmSources as $src):
                    $pct = $totalSrc > 0 ? round($src['cnt']/$totalSrc*100) : 0;
                ?>
                <div class="d-flex align-items-center gap-2 mb-1" style="font-size:12px">
                    <span style="min-width:90px"><?= e($srcLabels[$src['source']] ?? $src['source']) ?></span>
                    <div class="progress flex-grow-1" style="height:5px">
                        <div class="progress-bar bg-primary" style="width:<?= $pct ?>%"></div>
                    </div>
                    <span class="text-muted" style="min-width:24px"><?= $src['cnt'] ?></span>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($hasInst && $instStats): ?>
    <!-- Installment Performance -->
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header fw-semibold"><i class="fa fa-calendar-check me-2 text-warning"></i>Instalment Plan Performance</div>
            <div class="card-body">
                <div class="row g-3 text-center mb-3">
                    <div class="col-3">
                        <div class="fs-4 fw-bold text-primary"><?= (int)$instStats['total_plans'] ?></div>
                        <div class="text-muted small">Plans</div>
                    </div>
                    <div class="col-3">
                        <div class="fs-4 fw-bold text-info"><?= (int)$instStats['active_plans'] ?></div>
                        <div class="text-muted small">Active</div>
                    </div>
                    <div class="col-3">
                        <div class="fs-4 fw-bold text-success"><?= (int)$instStats['completed_plans'] ?></div>
                        <div class="text-muted small">Completed</div>
                    </div>
                    <div class="col-3">
                        <div class="fs-4 fw-bold text-danger"><?= (int)$instStats['overdue_count'] ?></div>
                        <div class="text-muted small">Overdue Inst.</div>
                    </div>
                </div>
                <dl class="row mb-0" style="font-size:13.5px">
                    <dt class="col-7 text-muted">Total Collected</dt>
                    <dd class="col-5 text-end fw-semibold text-success"><?= money((float)$instStats['total_collected']) ?></dd>
                    <dt class="col-7 text-muted">Overdue Balance</dt>
                    <dd class="col-5 text-end fw-semibold <?= $instStats['overdue_amount'] > 0 ? 'text-danger' : 'text-success' ?>"><?= money((float)$instStats['overdue_amount']) ?></dd>
                </dl>
                <?php if ($instStats['overdue_amount'] > 0): ?>
                <div class="alert alert-warning py-2 px-3 mt-3 mb-0 small">
                    <i class="fa fa-triangle-exclamation me-1"></i>
                    <?= (int)$instStats['overdue_count'] ?> overdue instalment<?= $instStats['overdue_count'] > 1 ? 's' : '' ?> totalling
                    <strong><?= money((float)$instStats['overdue_amount']) ?></strong> need follow-up.
                    <a href="<?= BASE_URL ?>/modules/installments/index.php?status=active" class="ms-1">View plans →</a>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

</div>
<?php endif; ?>

<?php include __DIR__ . '/../../includes/footer.php'; ?>

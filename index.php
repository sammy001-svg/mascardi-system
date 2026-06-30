<?php
require_once __DIR__ . '/includes/functions.php';

// Guests land on the public showroom — staff use login.php directly
if (!isLoggedIn()) {
    header('Location: ' . BASE_URL . '/showroom/');
    exit;
}

requireLogin();
$pageTitle = 'Dashboard';
$db   = getDB();
$user = authUser();
$role = $user['role'] ?? 'mechanic';

// Admin role → simple focused portal (Workshop + Sales dashboards)
if ($role === 'admin') {
    header('Location: ' . BASE_URL . '/modules/admin/workshop_dashboard.php');
    exit;
}

// General Manager → dedicated executive dashboard
if ($role === 'general_manager') {
    header('Location: ' . BASE_URL . '/modules/admin/gm_dashboard.php');
    exit;
}

// Customer Relations Managers get their own pipeline-focused dashboard
if ($role === 'customer_relations') {
    header('Location: ' . BASE_URL . '/modules/crm/my_dashboard.php');
    exit;
}

// ── Role-specific stats ───────────────────────────────────────────────────
$stats = [];
if ($role === 'mechanic') {
    $mechId = (int)($user['linked_id'] ?? 0);
    $s = $db->prepare("SELECT COUNT(*) FROM workshop_jobs WHERE mechanic_id=? AND status NOT IN ('completed','cancelled')");
    $s->execute([$mechId]); $stats['assigned_jobs'] = (int)$s->fetchColumn();

    $s = $db->prepare("SELECT COUNT(*) FROM workshop_jobs WHERE mechanic_id=? AND status='completed' AND DATE(updated_at)=CURDATE()");
    $s->execute([$mechId]); $stats['completed_today'] = (int)$s->fetchColumn();

    $s = $db->prepare("SELECT COUNT(*) FROM car_assessments WHERE mechanic_id=? AND assessment_date=CURDATE()");
    $s->execute([$mechId]); $stats['pending_assess'] = (int)$s->fetchColumn();

    $s = $db->prepare("SELECT COUNT(*) FROM parts_requests WHERE requested_by=? AND status='pending'");
    $s->execute([$user['id']]); $stats['parts_requested'] = (int)$s->fetchColumn();

    $myJobs = $db->prepare("SELECT j.*, c.make, c.model, c.chassis_number FROM workshop_jobs j JOIN cars c ON c.id=j.car_id WHERE j.mechanic_id=? ORDER BY j.priority DESC, j.created_at DESC LIMIT 10");
    $myJobs->execute([$mechId]); $myJobs = $myJobs->fetchAll();

    $myAssessments = $db->prepare("SELECT ca.*, c.make, c.model FROM car_assessments ca JOIN cars c ON c.id=ca.car_id WHERE ca.mechanic_id=? ORDER BY ca.created_at DESC LIMIT 5");
    $myAssessments->execute([$mechId]); $myAssessments = $myAssessments->fetchAll();

} elseif (in_array($role, ['super_admin', 'workshop_manager', 'admin'])) {
    $stats['total_cars']       = (int)$db->query("SELECT COUNT(*) FROM cars")->fetchColumn();
    $stats['in_workshop']      = (int)$db->query("SELECT COUNT(*) FROM cars WHERE status='in_workshop'")->fetchColumn();
    $stats['open_jobs']        = (int)$db->query("SELECT COUNT(*) FROM workshop_jobs WHERE status NOT IN ('completed','cancelled')")->fetchColumn();
    $stats['low_stock']        = (int)$db->query("SELECT COUNT(*) FROM inventory WHERE quantity <= reorder_level")->fetchColumn();
    $stats['revenue_month']    = (float)$db->query("SELECT COALESCE(SUM(total),0) FROM invoices WHERE status='paid' AND MONTH(created_at)=MONTH(NOW()) AND YEAR(created_at)=YEAR(NOW())")->fetchColumn();
    $stats['pending_bookings'] = (int)$db->query("SELECT COUNT(*) FROM service_bookings WHERE status='pending'")->fetchColumn();
    $stats['overdue_jobs']     = (int)$db->query("SELECT COUNT(*) FROM workshop_jobs WHERE status NOT IN ('completed','cancelled') AND end_date < CURDATE() AND end_date IS NOT NULL")->fetchColumn();
    try { $stats['open_issues']     = (int)$db->query("SELECT COUNT(*) FROM car_issues WHERE status IN ('open','in_progress')")->fetchColumn(); }
    catch (\Throwable $e) { $stats['open_issues'] = 0; }
    try { $stats['cars_sold_month'] = (int)$db->query("SELECT COUNT(*) FROM car_sales WHERE status='active' AND MONTH(sale_date)=MONTH(NOW()) AND YEAR(sale_date)=YEAR(NOW())")->fetchColumn(); }
    catch (\Throwable $e) { $stats['cars_sold_month'] = 0; }

} elseif ($role === 'sales_person') {
    $stats['total_clients']    = (int)$db->query("SELECT COUNT(*) FROM clients")->fetchColumn();
    $stats['qa_today']         = (int)$db->query("SELECT COUNT(*) FROM quick_assessments WHERE assessment_date=CURDATE()")->fetchColumn();
    $stats['pending_bookings'] = (int)$db->query("SELECT COUNT(*) FROM service_bookings WHERE status='pending'")->fetchColumn();
    $stats['available_cars']   = (int)$db->query("SELECT COUNT(*) FROM cars WHERE status IN ('arrived','completed')")->fetchColumn();

} elseif ($role === 'sales_officer') {
    $stats['revenue_month']    = (float)$db->query("SELECT COALESCE(SUM(total),0) FROM invoices WHERE status='paid' AND MONTH(created_at)=MONTH(NOW()) AND YEAR(created_at)=YEAR(NOW())")->fetchColumn();
    $stats['unpaid_invoices']  = (int)$db->query("SELECT COUNT(*) FROM invoices WHERE status='unpaid'")->fetchColumn();
    $stats['active_quotes']    = (int)$db->query("SELECT COUNT(*) FROM quotations WHERE status='sent'")->fetchColumn();
    $stats['payments_today']   = (float)$db->query("SELECT COALESCE(SUM(amount),0) FROM payments WHERE status='confirmed' AND DATE(created_at)=CURDATE()")->fetchColumn();
}

// ── Shared non-mechanic data ──────────────────────────────────────────────
$recentCars = $recentActivity = $carStatusData = [];
$chartLabels = $chartCounts = $revLabels = $revData = '[]';
$upcomingBookings = $recentPayments = $overdueJobsList = $criticalIssuesList = [];

if ($role !== 'mechanic') {
    $recentCars    = $db->query("SELECT c.* FROM cars c ORDER BY c.created_at DESC LIMIT 6")->fetchAll();
    $recentActivity = $db->query("SELECT j.job_number, j.status, j.priority, j.updated_at, c.make, c.model FROM workshop_jobs j JOIN cars c ON c.id=j.car_id ORDER BY j.updated_at DESC LIMIT 8")->fetchAll();
    $carStatusData  = $db->query("SELECT status, COUNT(*) AS cnt FROM cars GROUP BY status")->fetchAll();
    $chartLabels    = json_encode(array_map(fn($r) => ucwords(str_replace('_', ' ', $r['status'])), $carStatusData));
    $chartCounts    = json_encode(array_column($carStatusData, 'cnt'));

    // Revenue trend — last 6 months
    if (in_array($role, ['super_admin','admin','workshop_manager','sales_officer'])) {
        $revM = []; $revV = [];
        for ($i = 5; $i >= 0; $i--) {
            $ym = date('Y-m', strtotime("-{$i} months"));
            $revM[] = date('M Y', strtotime($ym.'-01'));
            $revV[] = (float)$db->query("SELECT COALESCE(SUM(total),0) FROM invoices WHERE status='paid' AND DATE_FORMAT(created_at,'%Y-%m')='{$ym}'")->fetchColumn();
        }
        $revLabels = json_encode($revM);
        $revData   = json_encode($revV);
    }

    // Upcoming service bookings
    if (in_array($role, ['super_admin','admin','workshop_manager','sales_person','sales_officer'])) {
        $upcomingBookings = $db->query("
            SELECT booking_number, client_name, client_phone, preferred_date, service_type, status
            FROM service_bookings
            WHERE status IN ('pending','confirmed')
            ORDER BY preferred_date ASC, created_at ASC
            LIMIT 6
        ")->fetchAll();
    }

    // Recent confirmed payments
    if (in_array($role, ['super_admin','admin','workshop_manager','sales_officer'])) {
        $recentPayments = $db->query("
            SELECT id, amount, payment_method, reference_number, created_at, client_name
            FROM payments
            WHERE status = 'confirmed'
            ORDER BY created_at DESC
            LIMIT 6
        ")->fetchAll();
    }

    // Alerts: overdue jobs + critical/high issues
    if (in_array($role, ['super_admin','admin','workshop_manager'])) {
        $overdueJobsList = $db->query("
            SELECT j.id, j.job_number, j.end_date, j.priority, c.make, c.model
            FROM workshop_jobs j JOIN cars c ON c.id=j.car_id
            WHERE j.status NOT IN ('completed','cancelled')
              AND j.end_date < CURDATE() AND j.end_date IS NOT NULL
            ORDER BY j.end_date ASC LIMIT 5
        ")->fetchAll();

        try {
            $criticalIssuesList = $db->query("
                SELECT ci.id, ci.issue_number, ci.title, ci.severity, c.make, c.model
                FROM car_issues ci JOIN cars c ON c.id=ci.car_id
                WHERE ci.severity IN ('critical','high') AND ci.status IN ('open','in_progress')
                ORDER BY ci.severity DESC, ci.created_at DESC LIMIT 5
            ")->fetchAll();
        } catch (\Throwable $e) { $criticalIssuesList = []; }
    }
}

$extraJs = <<<SCRIPT
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
(function () {
    // Fleet Status Doughnut
    var el = document.getElementById('statusChart');
    if (el) {
        var labels = {$chartLabels};
        var counts = {$chartCounts};
        var colorMap = {
            'In Transit':    '#d97706',
            'In Workshop':   '#db2777',
            'Completed':     '#16a34a',
            'Arrived':       '#0284c7',
            'In Assessment': '#7c3aed',
            'Sold':          '#0f172a',
            'Delivered':     '#475569'
        };
        new Chart(el, {
            type: 'doughnut',
            data: {
                labels: labels,
                datasets: [{ data: counts, backgroundColor: labels.map(function(l){ return colorMap[l]||'#94a3b8'; }), borderWidth: 2, borderColor: '#ffffff' }]
            },
            options: { cutout: '62%', plugins: { legend: { position: 'bottom', labels: { font:{ size:11 }, padding:12, boxWidth:12 } } } }
        });
    }

    // Revenue Trend Bar Chart
    var rc = document.getElementById('revenueChart');
    if (rc) {
        var revLabels = {$revLabels};
        var revData   = {$revData};
        new Chart(rc, {
            type: 'bar',
            data: {
                labels: revLabels,
                datasets: [{
                    label: 'Revenue',
                    data: revData,
                    backgroundColor: 'rgba(37,99,235,0.75)',
                    borderColor: '#2563eb',
                    borderWidth: 1,
                    borderRadius: 4
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: function(ctx) {
                                var v = ctx.raw;
                                return ' KES ' + (v >= 1e6 ? (v/1e6).toFixed(2)+'M' : v >= 1e3 ? (v/1e3).toFixed(1)+'K' : v.toFixed(0));
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: function(v) {
                                return v >= 1e6 ? (v/1e6).toFixed(1)+'M' : v >= 1e3 ? (v/1e3).toFixed(0)+'K' : v;
                            }
                        }
                    }
                }
            }
        });
    }
}());
</script>
SCRIPT;

include __DIR__ . '/includes/header.php';
?>

<!-- Welcome Banner -->
<div class="welcome-banner mb-4">
    <div class="welcome-text">
        <h5 class="mb-1">Welcome back, <?= e($user['name']) ?></h5>
        <p class="mb-0"><?= date('l, d F Y') ?> &mdash;
            <span class="badge bg-white text-primary fw-semibold px-2 py-1" style="color:var(--brand)!important"><?= ucwords(str_replace('_', ' ', $role)) ?></span>
        </p>
    </div>
    <div class="welcome-stats d-none d-md-flex align-items-center gap-4">
        <?php if ($role === 'mechanic'): ?>
            <div class="text-center"><div class="welcome-stat-val"><?= $stats['assigned_jobs'] ?></div><div class="welcome-stat-lbl">Active Jobs</div></div>
            <div class="vr welcome-divider"></div>
            <div class="text-center"><div class="welcome-stat-val text-success"><?= $stats['completed_today'] ?></div><div class="welcome-stat-lbl">Done Today</div></div>
        <?php elseif (in_array($role, ['super_admin','admin','workshop_manager'])): ?>
            <div class="text-center"><div class="welcome-stat-val"><?= $stats['total_cars'] ?></div><div class="welcome-stat-lbl">Total Fleet</div></div>
            <div class="vr welcome-divider"></div>
            <div class="text-center"><div class="welcome-stat-val"><?= $stats['open_jobs'] ?></div><div class="welcome-stat-lbl">Open Jobs</div></div>
            <div class="vr welcome-divider"></div>
            <div class="text-center"><div class="welcome-stat-val text-success" style="font-size:1rem"><?= money($stats['revenue_month']) ?></div><div class="welcome-stat-lbl">Revenue (MTD)</div></div>
        <?php elseif ($role === 'sales_officer'): ?>
            <div class="text-center"><div class="welcome-stat-val text-success" style="font-size:1rem"><?= money($stats['revenue_month']) ?></div><div class="welcome-stat-lbl">Revenue (Month)</div></div>
        <?php else: ?>
            <div class="text-center"><div class="welcome-stat-val"><?= $stats['available_cars'] ?? 0 ?></div><div class="welcome-stat-lbl">Available Cars</div></div>
        <?php endif; ?>
    </div>
</div>

<!-- ── Stat Cards Row 1 ───────────────────────────────────────────────────── -->
<div class="row g-3 mb-3">
<?php if ($role === 'mechanic'): ?>
    <div class="col-sm-6 col-xl-3">
        <a href="<?= BASE_URL ?>/modules/jobs/index.php" class="stat-card stat-card-link" style="border-left:4px solid #f59e0b">
            <div class="stat-icon" style="background:#fef3c7;color:#f59e0b"><i class="fa fa-toolbox"></i></div>
            <div class="stat-info"><div class="stat-label">My Jobs</div><div class="stat-value"><?= $stats['assigned_jobs'] ?></div></div>
        </a>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="stat-card" style="border-left:4px solid #10b981">
            <div class="stat-icon" style="background:#d1fae5;color:#10b981"><i class="fa fa-circle-check"></i></div>
            <div class="stat-info"><div class="stat-label">Done Today</div><div class="stat-value"><?= $stats['completed_today'] ?></div></div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <a href="<?= BASE_URL ?>/modules/assessments/index.php" class="stat-card stat-card-link" style="border-left:4px solid #3b82f6">
            <div class="stat-icon" style="background:#dbeafe;color:#3b82f6"><i class="fa fa-clipboard-check"></i></div>
            <div class="stat-info"><div class="stat-label">My Assessments</div><div class="stat-value"><?= $stats['pending_assess'] ?></div></div>
        </a>
    </div>
    <div class="col-sm-6 col-xl-3">
        <a href="<?= BASE_URL ?>/modules/parts_requests/index.php" class="stat-card stat-card-link" style="border-left:4px solid #db2777">
            <div class="stat-icon" style="background:#fce7f3;color:#db2777"><i class="fa fa-hand-holding-box"></i></div>
            <div class="stat-info"><div class="stat-label">Quote Requests</div><div class="stat-value"><?= $stats['parts_requested'] ?></div></div>
        </a>
    </div>

<?php elseif (in_array($role, ['super_admin','admin','workshop_manager'])): ?>
    <div class="col-sm-6 col-xl-3">
        <a href="<?= BASE_URL ?>/modules/cars/index.php" class="stat-card stat-card-link" style="border-left:4px solid #2563eb">
            <div class="stat-icon" style="background:#dbeafe;color:#2563eb"><i class="fa fa-car"></i></div>
            <div class="stat-info"><div class="stat-label">Total Fleet</div><div class="stat-value"><?= $stats['total_cars'] ?></div></div>
        </a>
    </div>
    <div class="col-sm-6 col-xl-3">
        <a href="<?= BASE_URL ?>/modules/cars/index.php?status=in_workshop" class="stat-card stat-card-link" style="border-left:4px solid #db2777">
            <div class="stat-icon" style="background:#fce7f3;color:#db2777"><i class="fa fa-screwdriver-wrench"></i></div>
            <div class="stat-info"><div class="stat-label">In Workshop</div><div class="stat-value"><?= $stats['in_workshop'] ?></div></div>
        </a>
    </div>
    <div class="col-sm-6 col-xl-3">
        <a href="<?= BASE_URL ?>/modules/jobs/index.php" class="stat-card stat-card-link" style="border-left:4px solid #f59e0b">
            <div class="stat-icon" style="background:#fef3c7;color:#f59e0b"><i class="fa fa-clipboard-list"></i></div>
            <div class="stat-info"><div class="stat-label">Open Job Cards</div><div class="stat-value"><?= $stats['open_jobs'] ?></div></div>
        </a>
    </div>
    <div class="col-sm-6 col-xl-3">
        <a href="<?= BASE_URL ?>/modules/inventory/index.php" class="stat-card stat-card-link" style="border-left:4px solid #dc2626">
            <div class="stat-icon" style="background:#fee2e2;color:#dc2626"><i class="fa fa-boxes-stacked"></i></div>
            <div class="stat-info"><div class="stat-label">Low Stock Parts</div><div class="stat-value"><?= $stats['low_stock'] ?></div></div>
        </a>
    </div>

<?php elseif ($role === 'sales_person'): ?>
    <div class="col-sm-6 col-xl-3">
        <a href="<?= BASE_URL ?>/modules/clients/index.php" class="stat-card stat-card-link" style="border-left:4px solid #2563eb">
            <div class="stat-icon" style="background:#dbeafe;color:#2563eb"><i class="fa fa-users"></i></div>
            <div class="stat-info"><div class="stat-label">Total Clients</div><div class="stat-value"><?= $stats['total_clients'] ?></div></div>
        </a>
    </div>
    <div class="col-sm-6 col-xl-3">
        <a href="<?= BASE_URL ?>/modules/quick_assessments/index.php" class="stat-card stat-card-link" style="border-left:4px solid #8b5cf6">
            <div class="stat-icon" style="background:#f5f3ff;color:#8b5cf6"><i class="fa fa-magnifying-glass-chart"></i></div>
            <div class="stat-info"><div class="stat-label">Assessments Today</div><div class="stat-value"><?= $stats['qa_today'] ?></div></div>
        </a>
    </div>
    <div class="col-sm-6 col-xl-3">
        <a href="<?= BASE_URL ?>/modules/service_bookings/index.php" class="stat-card stat-card-link" style="border-left:4px solid #10b981">
            <div class="stat-icon" style="background:#d1fae5;color:#10b981"><i class="fa fa-calendar-check"></i></div>
            <div class="stat-info"><div class="stat-label">Pending Bookings</div><div class="stat-value"><?= $stats['pending_bookings'] ?></div></div>
        </a>
    </div>
    <div class="col-sm-6 col-xl-3">
        <a href="<?= BASE_URL ?>/modules/cars/index.php" class="stat-card stat-card-link" style="border-left:4px solid #0ea5e9">
            <div class="stat-icon" style="background:#e0f2fe;color:#0ea5e9"><i class="fa fa-car-side"></i></div>
            <div class="stat-info"><div class="stat-label">Available Cars</div><div class="stat-value"><?= $stats['available_cars'] ?></div></div>
        </a>
    </div>

<?php elseif ($role === 'sales_officer'): ?>
    <div class="col-sm-6 col-xl-3">
        <div class="stat-card" style="border-left:4px solid #16a34a">
            <div class="stat-icon" style="background:#dcfce7;color:#16a34a"><i class="fa fa-money-bill-wave"></i></div>
            <div class="stat-info"><div class="stat-label">Revenue (Month)</div><div class="stat-value stat-value-sm"><?= money($stats['revenue_month']) ?></div></div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <a href="<?= BASE_URL ?>/modules/invoices/index.php?status=unpaid" class="stat-card stat-card-link" style="border-left:4px solid #dc2626">
            <div class="stat-icon" style="background:#fee2e2;color:#dc2626"><i class="fa fa-file-invoice-dollar"></i></div>
            <div class="stat-info"><div class="stat-label">Unpaid Invoices</div><div class="stat-value"><?= $stats['unpaid_invoices'] ?></div></div>
        </a>
    </div>
    <div class="col-sm-6 col-xl-3">
        <a href="<?= BASE_URL ?>/modules/quotations/index.php" class="stat-card stat-card-link" style="border-left:4px solid #0284c7">
            <div class="stat-icon" style="background:#e0f2fe;color:#0284c7"><i class="fa fa-file-lines"></i></div>
            <div class="stat-info"><div class="stat-label">Active Quotations</div><div class="stat-value"><?= $stats['active_quotes'] ?></div></div>
        </a>
    </div>
    <div class="col-sm-6 col-xl-3">
        <a href="<?= BASE_URL ?>/modules/payments/index.php" class="stat-card stat-card-link" style="border-left:4px solid #d97706">
            <div class="stat-icon" style="background:#fef3c7;color:#d97706"><i class="fa fa-money-bill-transfer"></i></div>
            <div class="stat-info"><div class="stat-label">Payments Today</div><div class="stat-value stat-value-sm"><?= money($stats['payments_today']) ?></div></div>
        </a>
    </div>
<?php endif; ?>
</div>

<!-- ── Stat Cards Row 2 — Admin/Manager only ──────────────────────────────── -->
<?php if (in_array($role, ['super_admin','admin','workshop_manager'])): ?>
<div class="row g-3 mb-4">
    <div class="col-sm-6 col-xl-3">
        <a href="<?= BASE_URL ?>/modules/invoices/index.php?status=paid" class="stat-card stat-card-link" style="border-left:4px solid #16a34a">
            <div class="stat-icon" style="background:#dcfce7;color:#16a34a"><i class="fa fa-money-bill-wave"></i></div>
            <div class="stat-info"><div class="stat-label">Revenue (MTD)</div><div class="stat-value stat-value-sm"><?= money($stats['revenue_month']) ?></div></div>
        </a>
    </div>
    <div class="col-sm-6 col-xl-3">
        <a href="<?= BASE_URL ?>/modules/sales/index.php" class="stat-card stat-card-link" style="border-left:4px solid #0f172a">
            <div class="stat-icon" style="background:#f1f5f9;color:#0f172a"><i class="fa fa-tag"></i></div>
            <div class="stat-info"><div class="stat-label">Cars Sold (MTD)</div><div class="stat-value"><?= $stats['cars_sold_month'] ?></div></div>
        </a>
    </div>
    <div class="col-sm-6 col-xl-3">
        <a href="<?= BASE_URL ?>/modules/service_bookings/index.php" class="stat-card stat-card-link" style="border-left:4px solid #10b981">
            <div class="stat-icon" style="background:#d1fae5;color:#10b981"><i class="fa fa-calendar-check"></i></div>
            <div class="stat-info"><div class="stat-label">Pending Bookings</div><div class="stat-value"><?= $stats['pending_bookings'] ?></div></div>
        </a>
    </div>
    <div class="col-sm-6 col-xl-3">
        <a href="<?= BASE_URL ?>/modules/issues/index.php" class="stat-card stat-card-link" style="border-left:4px solid <?= ($stats['open_issues'] > 0) ? '#ea580c' : '#64748b' ?>">
            <div class="stat-icon" style="background:<?= ($stats['open_issues'] > 0) ? '#fff7ed' : '#f8fafc' ?>;color:<?= ($stats['open_issues'] > 0) ? '#ea580c' : '#64748b' ?>"><i class="fa fa-triangle-exclamation"></i></div>
            <div class="stat-info"><div class="stat-label">Open Issues</div><div class="stat-value"><?= $stats['open_issues'] ?></div></div>
        </a>
    </div>
</div>
<?php else: ?>
<div class="mb-4"></div>
<?php endif; ?>

<!-- ── Quick Actions ──────────────────────────────────────────────────────── -->
<div class="card mb-4">
    <div class="card-header"><i class="fa fa-bolt me-2"></i>Quick Actions</div>
    <div class="card-body">
        <div class="quick-actions-grid">
            <?php if ($role === 'mechanic'): ?>
                <a href="<?= BASE_URL ?>/modules/jobs/index.php" class="quick-action-card">
                    <div class="qa-icon" style="background:#fef3c7;color:#d97706"><i class="fa fa-toolbox fa-lg"></i></div>
                    <span>My Jobs</span>
                </a>
                <a href="<?= BASE_URL ?>/modules/assessments/add.php" class="quick-action-card">
                    <div class="qa-icon" style="background:#f0fdf4;color:#16a34a"><i class="fa fa-clipboard-check fa-lg"></i></div>
                    <span>New Assessment</span>
                </a>
                <a href="<?= BASE_URL ?>/modules/inventory/index.php" class="quick-action-card">
                    <div class="qa-icon" style="background:#dbeafe;color:#2563eb"><i class="fa fa-boxes-stacked fa-lg"></i></div>
                    <span>Check Parts</span>
                </a>
                <a href="<?= BASE_URL ?>/modules/issues/index.php" class="quick-action-card">
                    <div class="qa-icon" style="background:#fff7ed;color:#ea580c"><i class="fa fa-triangle-exclamation fa-lg"></i></div>
                    <span>Issues</span>
                </a>
            <?php else: ?>
                <?php if (canWrite('cars')): ?>
                <a href="<?= BASE_URL ?>/modules/cars/add.php" class="quick-action-card">
                    <div class="qa-icon" style="background:#dbeafe;color:#2563eb"><i class="fa fa-plus-circle fa-lg"></i></div>
                    <span>Add Car</span>
                </a>
                <?php endif; ?>
                <?php if (canAccess('intake')): ?>
                <a href="<?= BASE_URL ?>/modules/intake/add.php" class="quick-action-card">
                    <div class="qa-icon" style="background:#fef3c7;color:#d97706"><i class="fa fa-anchor fa-lg"></i></div>
                    <span>New Intake</span>
                </a>
                <?php endif; ?>
                <?php if (canAccess('assessments')): ?>
                <a href="<?= BASE_URL ?>/modules/assessments/add.php" class="quick-action-card">
                    <div class="qa-icon" style="background:#f0fdf4;color:#16a34a"><i class="fa fa-clipboard-check fa-lg"></i></div>
                    <span>Assessment</span>
                </a>
                <?php endif; ?>
                <?php if (canAccess('jobs')): ?>
                <a href="<?= BASE_URL ?>/modules/jobs/add.php" class="quick-action-card">
                    <div class="qa-icon" style="background:#fdf4ff;color:#9333ea"><i class="fa fa-toolbox fa-lg"></i></div>
                    <span>Job Card</span>
                </a>
                <?php endif; ?>
                <?php if (canWrite('clients')): ?>
                <a href="<?= BASE_URL ?>/modules/clients/add.php" class="quick-action-card">
                    <div class="qa-icon" style="background:#e0f2fe;color:#0369a1"><i class="fa fa-user-plus fa-lg"></i></div>
                    <span>New Client</span>
                </a>
                <?php endif; ?>
                <?php if (canAccess('service_bookings')): ?>
                <a href="<?= BASE_URL ?>/modules/service_bookings/index.php" class="quick-action-card">
                    <div class="qa-icon" style="background:#d1fae5;color:#059669"><i class="fa fa-calendar-check fa-lg"></i></div>
                    <span>Bookings</span>
                </a>
                <?php endif; ?>
                <?php if (canAccess('quotations')): ?>
                <a href="<?= BASE_URL ?>/modules/quotations/add.php" class="quick-action-card">
                    <div class="qa-icon" style="background:#f0f9ff;color:#0284c7"><i class="fa fa-file-lines fa-lg"></i></div>
                    <span>Quotation</span>
                </a>
                <?php endif; ?>
                <?php if (canAccess('invoices')): ?>
                <a href="<?= BASE_URL ?>/modules/invoices/index.php" class="quick-action-card">
                    <div class="qa-icon" style="background:#fff1f2;color:#dc2626"><i class="fa fa-file-invoice-dollar fa-lg"></i></div>
                    <span>Invoices</span>
                </a>
                <?php endif; ?>
                <?php if (canAccess('sales')): ?>
                <a href="<?= BASE_URL ?>/modules/sales/index.php" class="quick-action-card">
                    <div class="qa-icon" style="background:#f1f5f9;color:#0f172a"><i class="fa fa-tag fa-lg"></i></div>
                    <span>Sales</span>
                </a>
                <?php endif; ?>
                <?php if (canAccess('lpo')): ?>
                <a href="<?= BASE_URL ?>/modules/lpo/index.php" class="quick-action-card">
                    <div class="qa-icon" style="background:#fefce8;color:#ca8a04"><i class="fa fa-truck fa-lg"></i></div>
                    <span>LPO</span>
                </a>
                <?php endif; ?>
                <?php if (canAccess('reports')): ?>
                <a href="<?= BASE_URL ?>/modules/reports/index.php" class="quick-action-card">
                    <div class="qa-icon" style="background:#f8fafc;color:#64748b"><i class="fa fa-chart-bar fa-lg"></i></div>
                    <span>Reports</span>
                </a>
                <?php endif; ?>
                <?php if (canAccess('issues')): ?>
                <a href="<?= BASE_URL ?>/modules/issues/index.php" class="quick-action-card">
                    <div class="qa-icon" style="background:#fff7ed;color:#ea580c"><i class="fa fa-triangle-exclamation fa-lg"></i></div>
                    <span>Issues</span>
                </a>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- ── Main Content ───────────────────────────────────────────────────────── -->
<?php if ($role === 'mechanic'): ?>
<div class="row g-4">
    <div class="col-lg-8">
        <div class="card h-100">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="fa fa-toolbox me-2"></i>My Assigned Jobs</span>
                <a href="<?= BASE_URL ?>/modules/jobs/index.php" class="btn btn-xs btn-outline-primary">View All</a>
            </div>
            <div class="card-body p-0">
                <table class="table table-hover mb-0">
                    <thead>
                        <tr><th class="ps-3">Job #</th><th>Vehicle</th><th>Priority</th><th>Status</th><th class="text-end pe-3">Action</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($myJobs as $j): ?>
                        <tr>
                            <td class="ps-3 fw-bold small"><?= e($j['job_number']) ?></td>
                            <td>
                                <div class="fw-medium small"><?= e($j['make'] . ' ' . $j['model']) ?></div>
                                <code style="font-size:10px"><?= e($j['chassis_number']) ?></code>
                            </td>
                            <td><span class="badge bg-<?= in_array($j['priority'],['urgent','high']) ? 'danger' : 'secondary' ?>"><?= strtoupper($j['priority']) ?></span></td>
                            <td><?= statusBadge($j['status']) ?></td>
                            <td class="text-end pe-3"><a href="<?= BASE_URL ?>/modules/jobs/view.php?id=<?= $j['id'] ?>" class="btn btn-xs btn-primary">Open</a></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($myJobs)): ?>
                        <tr><td colspan="5" class="text-center py-4 text-muted small">No jobs assigned currently.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card h-100">
            <div class="card-header">Recent Assessments</div>
            <div class="list-group list-group-flush">
                <?php foreach ($myAssessments as $a): ?>
                <a href="<?= BASE_URL ?>/modules/assessments/view.php?id=<?= $a['id'] ?>" class="list-group-item list-group-item-action">
                    <div class="fw-semibold small"><?= e($a['make'] . ' ' . $a['model']) ?></div>
                    <div class="d-flex justify-content-between align-items-center mt-1">
                        <span class="text-muted small"><?= fmtDate($a['assessment_date']) ?></span>
                        <?= statusBadge($a['overall_status']) ?>
                    </div>
                </a>
                <?php endforeach; ?>
                <?php if (empty($myAssessments)): ?>
                <div class="list-group-item text-center text-muted small py-3">No recent assessments.</div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php else: /* ── Non-mechanic layout ─────────────────────────────────────── */ ?>

<!-- Charts Row -->
<?php if (in_array($role, ['super_admin','admin','workshop_manager','sales_officer'])): ?>
<div class="row g-4 mb-4">
    <div class="col-lg-8">
        <div class="card h-100">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="fa fa-chart-bar me-2"></i>Revenue Trend — Last 6 Months</span>
                <a href="<?= BASE_URL ?>/modules/invoices/index.php" class="btn btn-xs btn-outline-primary">All Invoices</a>
            </div>
            <div class="card-body"><canvas id="revenueChart" height="110"></canvas></div>
        </div>
    </div>
    <div class="col-lg-4">
        <?php if (!empty($carStatusData)): ?>
        <div class="card h-100">
            <div class="card-header"><i class="fa fa-circle-half-stroke me-2"></i>Fleet Status</div>
            <div class="card-body d-flex align-items-center justify-content-center pb-2">
                <canvas id="statusChart" height="220"></canvas>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<!-- Tables Row -->
<div class="row g-4 mb-4">
    <!-- Recent Cars -->
    <div class="col-lg-<?= in_array($role, ['super_admin','admin','workshop_manager']) ? '5' : '7' ?>">
        <div class="card h-100">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="fa fa-car me-2"></i>Recent Cars</span>
                <a href="<?= BASE_URL ?>/modules/cars/index.php" class="btn btn-xs btn-outline-primary">View All</a>
            </div>
            <div class="card-body p-0">
                <table class="table table-hover mb-0">
                    <thead><tr><th class="ps-3">Chassis</th><th>Vehicle</th><th>Status</th><th></th></tr></thead>
                    <tbody>
                        <?php foreach ($recentCars as $car): ?>
                        <tr>
                            <td class="ps-3 small"><code><?= e($car['chassis_number']) ?></code></td>
                            <td class="fw-medium small"><?= e($car['make'] . ' ' . $car['model']) ?></td>
                            <td><?= statusBadge($car['status']) ?></td>
                            <td class="text-end pe-3"><a href="<?= BASE_URL ?>/modules/cars/view.php?id=<?= $car['id'] ?>" class="btn btn-xs btn-outline-secondary">View</a></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($recentCars)): ?>
                        <tr><td colspan="4" class="text-center py-4 text-muted small">No cars yet.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Upcoming Bookings -->
    <?php if (!empty($upcomingBookings) || in_array($role, ['super_admin','admin','workshop_manager','sales_person','sales_officer'])): ?>
    <div class="col-lg-<?= in_array($role, ['super_admin','admin','workshop_manager']) ? '4' : '5' ?>">
        <div class="card h-100">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="fa fa-calendar-check me-2"></i>Upcoming Bookings</span>
                <a href="<?= BASE_URL ?>/modules/service_bookings/index.php" class="btn btn-xs btn-outline-primary">All Bookings</a>
            </div>
            <div class="list-group list-group-flush">
                <?php foreach ($upcomingBookings as $bk): ?>
                <a href="<?= BASE_URL ?>/modules/service_bookings/index.php" class="list-group-item list-group-item-action py-2">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="fw-semibold small"><?= e($bk['client_name']) ?></div>
                            <div class="text-muted" style="font-size:11px"><?= e(implode(', ', array_slice(explode(', ', $bk['service_type'] ?? ''), 0, 2))) ?></div>
                        </div>
                        <div class="text-end">
                            <?= statusBadge($bk['status']) ?>
                            <div class="text-muted mt-1" style="font-size:10px"><?= $bk['preferred_date'] ? fmtDate($bk['preferred_date'], 'd M') : '—' ?></div>
                        </div>
                    </div>
                </a>
                <?php endforeach; ?>
                <?php if (empty($upcomingBookings)): ?>
                <div class="list-group-item text-center text-muted small py-4">No upcoming bookings.</div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Alerts Panel — admin/manager only -->
    <?php if (in_array($role, ['super_admin','admin','workshop_manager'])): ?>
    <div class="col-lg-3">
        <div class="card h-100" style="border-top:3px solid #dc2626">
            <div class="card-header text-danger fw-semibold"><i class="fa fa-bell me-2"></i>Alerts</div>
            <div class="list-group list-group-flush" style="font-size:12.5px">
                <?php if (!empty($overdueJobsList)): ?>
                <div class="list-group-item bg-danger bg-opacity-10 py-1 px-3 text-danger fw-semibold" style="font-size:11px">OVERDUE JOBS (<?= count($overdueJobsList) ?>)</div>
                <?php foreach ($overdueJobsList as $oj): ?>
                <a href="<?= BASE_URL ?>/modules/jobs/view.php?id=<?= $oj['id'] ?>" class="list-group-item list-group-item-action py-2">
                    <div class="fw-medium"><?= e($oj['job_number']) ?></div>
                    <div class="text-muted" style="font-size:11px"><?= e($oj['make'].' '.$oj['model']) ?> &bull; due <?= fmtDate($oj['end_date'], 'd M') ?></div>
                </a>
                <?php endforeach; ?>
                <?php endif; ?>

                <?php if (!empty($criticalIssuesList)): ?>
                <div class="list-group-item bg-warning bg-opacity-25 py-1 px-3 text-warning-emphasis fw-semibold" style="font-size:11px">CRITICAL ISSUES (<?= count($criticalIssuesList) ?>)</div>
                <?php foreach ($criticalIssuesList as $ci): ?>
                <a href="<?= BASE_URL ?>/modules/issues/view.php?id=<?= $ci['id'] ?>" class="list-group-item list-group-item-action py-2">
                    <div class="fw-medium"><?= e($ci['title']) ?></div>
                    <div class="text-muted" style="font-size:11px"><?= e($ci['make'].' '.$ci['model']) ?> &bull; <span class="text-danger"><?= strtoupper($ci['severity']) ?></span></div>
                </a>
                <?php endforeach; ?>
                <?php endif; ?>

                <?php if ($stats['low_stock'] > 0): ?>
                <div class="list-group-item bg-warning bg-opacity-10 py-1 px-3" style="font-size:11px;color:#92400e;font-weight:600">LOW STOCK (<?= $stats['low_stock'] ?> items)</div>
                <a href="<?= BASE_URL ?>/modules/inventory/index.php" class="list-group-item list-group-item-action py-2">
                    <div class="text-muted"><?= $stats['low_stock'] ?> part(s) at or below reorder level</div>
                </a>
                <?php endif; ?>

                <?php if (empty($overdueJobsList) && empty($criticalIssuesList) && $stats['low_stock'] === 0): ?>
                <div class="list-group-item text-center text-success py-4 small">
                    <i class="fa fa-circle-check fa-lg mb-1 d-block"></i>No alerts right now
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- Recent Payments Row -->
<?php if (in_array($role, ['super_admin','admin','workshop_manager','sales_officer']) && !empty($recentPayments)): ?>
<div class="row g-4 mb-4">
    <div class="col-12">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="fa fa-money-bill-transfer me-2"></i>Recent Payments</span>
                <a href="<?= BASE_URL ?>/modules/payments/index.php" class="btn btn-xs btn-outline-primary">All Payments</a>
            </div>
            <div class="card-body p-0">
                <table class="table table-hover mb-0">
                    <thead>
                        <tr>
                            <th class="ps-3">Client</th>
                            <th>Reference</th>
                            <th>Method</th>
                            <th class="text-end">Amount</th>
                            <th class="text-end pe-3">Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recentPayments as $pay): ?>
                        <tr>
                            <td class="ps-3 fw-medium small"><?= e($pay['client_name'] ?: '—') ?></td>
                            <td class="small text-muted"><?= e($pay['reference_number'] ?: '—') ?></td>
                            <td><span class="badge bg-light text-dark border"><?= ucwords(str_replace('_',' ',$pay['payment_method'] ?? '')) ?></span></td>
                            <td class="text-end fw-semibold text-success"><?= money($pay['amount']) ?></td>
                            <td class="text-end pe-3 text-muted small"><?= fmtDate($pay['created_at'], 'd M H:i') ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Recent Activity — only if no revenue chart shown (sales_person) -->
<?php if (!in_array($role, ['super_admin','admin','workshop_manager','sales_officer'])): ?>
<div class="row g-4">
    <div class="col-12">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="fa fa-clock-rotate-left me-2"></i>Recent Job Activity</span>
                <a href="<?= BASE_URL ?>/modules/jobs/index.php" class="btn btn-xs btn-outline-secondary">All Jobs</a>
            </div>
            <div class="list-group list-group-flush">
                <?php foreach ($recentActivity as $log): ?>
                <div class="list-group-item">
                    <div class="d-flex justify-content-between align-items-center">
                        <div class="small">
                            <span class="fw-semibold"><?= e($log['make'].' '.$log['model']) ?></span>
                            <span class="text-muted ms-1">#<?= e($log['job_number']) ?></span>
                        </div>
                        <div class="d-flex align-items-center gap-2">
                            <?= statusBadge($log['status']) ?>
                            <span class="text-muted" style="font-size:10px"><?= fmtDate($log['updated_at'], 'd M') ?></span>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php if (empty($recentActivity)): ?>
                <div class="list-group-item text-center text-muted small py-3">No recent activity.</div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php endif; /* end mechanic/non-mechanic split */ ?>

<?php include __DIR__ . '/includes/footer.php'; ?>

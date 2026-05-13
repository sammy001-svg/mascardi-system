<?php
require_once __DIR__ . '/includes/functions.php';
requireLogin();
$pageTitle = 'Dashboard';
$db   = getDB();
$user = authUser();
$role = $user['role'] ?? 'mechanic';

// ── Fetch role-specific stats ──────────────────────────────────────────────
$stats = [];
if ($role === 'mechanic') {
    $mechId = (int)($user['linked_id'] ?? 0);
    $stats['assigned_jobs']     = $db->prepare("SELECT COUNT(*) FROM workshop_jobs WHERE mechanic_id=? AND status NOT IN ('completed','cancelled')");
    $stats['assigned_jobs']->execute([$mechId]); $stats['assigned_jobs'] = (int)$stats['assigned_jobs']->fetchColumn();
    
    $stats['completed_today']   = $db->prepare("SELECT COUNT(*) FROM workshop_jobs WHERE mechanic_id=? AND status='completed' AND DATE(updated_at)=CURDATE()");
    $stats['completed_today']->execute([$mechId]); $stats['completed_today'] = (int)$stats['completed_today']->fetchColumn();
    
    $stats['pending_assess']    = $db->prepare("SELECT COUNT(*) FROM car_assessments WHERE mechanic_id=? AND assessment_date=CURDATE()");
    $stats['pending_assess']->execute([$mechId]); $stats['pending_assess'] = (int)$stats['pending_assess']->fetchColumn();
    
    $stats['parts_requested']   = $db->prepare("SELECT COUNT(*) FROM parts_requests WHERE requested_by=? AND status='pending'");
    $stats['parts_requested']->execute([$user['id']]); $stats['parts_requested'] = (int)$stats['parts_requested']->fetchColumn();

    $myJobs = $db->prepare("SELECT j.*, c.make, c.model, c.chassis_number FROM workshop_jobs j JOIN cars c ON c.id=j.car_id WHERE j.mechanic_id=? ORDER BY j.priority DESC, j.created_at DESC LIMIT 10");
    $myJobs->execute([$mechId]); $myJobs = $myJobs->fetchAll();
    
    $myAssessments = $db->prepare("SELECT ca.*, c.make, c.model FROM car_assessments ca JOIN cars c ON c.id=ca.car_id WHERE ca.mechanic_id=? ORDER BY ca.created_at DESC LIMIT 5");
    $myAssessments->execute([$mechId]); $myAssessments = $myAssessments->fetchAll();

} elseif ($role === 'workshop_manager' || $role === 'admin') {
    $stats['total_cars']        = (int)$db->query("SELECT COUNT(*) FROM cars")->fetchColumn();
    $stats['in_workshop']       = (int)$db->query("SELECT COUNT(*) FROM cars WHERE status='in_workshop'")->fetchColumn();
    $stats['open_jobs']         = (int)$db->query("SELECT COUNT(*) FROM workshop_jobs WHERE status NOT IN ('completed','cancelled')")->fetchColumn();
    $stats['low_stock']         = (int)$db->query("SELECT COUNT(*) FROM inventory WHERE quantity <= reorder_level")->fetchColumn();
    
} elseif ($role === 'sales_person') {
    $stats['total_clients']     = (int)$db->query("SELECT COUNT(*) FROM clients")->fetchColumn();
    $stats['qa_today']          = (int)$db->query("SELECT COUNT(*) FROM quick_assessments WHERE assessment_date=CURDATE()")->fetchColumn();
    $stats['pending_bookings']  = (int)$db->query("SELECT COUNT(*) FROM service_bookings WHERE status='pending'")->fetchColumn();
    $stats['available_cars']    = (int)$db->query("SELECT COUNT(*) FROM cars WHERE status IN ('arrived','completed')")->fetchColumn();

} elseif ($role === 'sales_officer') {
    $stats['revenue_month']     = (float)$db->query("SELECT COALESCE(SUM(total),0) FROM invoices WHERE status='paid' AND MONTH(created_at)=MONTH(NOW()) AND YEAR(created_at)=YEAR(NOW())")->fetchColumn();
    $stats['unpaid_invoices']   = (int)$db->query("SELECT COUNT(*) FROM invoices WHERE status='unpaid'")->fetchColumn();
    $stats['active_quotes']     = (int)$db->query("SELECT COUNT(*) FROM quotations WHERE status='sent'")->fetchColumn();
    $stats['payments_today']    = (float)$db->query("SELECT COALESCE(SUM(amount),0) FROM payments WHERE status='confirmed' AND DATE(created_at)=CURDATE()")->fetchColumn();
}

if ($role !== 'mechanic') {
    $recentCars  = $db->query("SELECT c.*, ci.intake_date FROM cars c LEFT JOIN car_intake ci ON ci.car_id=c.id ORDER BY c.created_at DESC LIMIT 6")->fetchAll();
    $recentJobs  = $db->query("SELECT j.*, c.make, c.model, c.chassis_number, m.name AS mechanic_name FROM workshop_jobs j JOIN cars c ON c.id=j.car_id LEFT JOIN mechanics m ON m.id=j.mechanic_id ORDER BY j.created_at DESC LIMIT 5")->fetchAll();
    $carStatusData = $db->query("SELECT status, COUNT(*) AS cnt FROM cars GROUP BY status")->fetchAll();
    $chartLabels = json_encode(array_map(fn($r) => ucwords(str_replace('_', ' ', $r['status'])), $carStatusData));
    $chartCounts = json_encode(array_column($carStatusData, 'cnt'));
    $recentActivity = $db->query("SELECT j.job_number, j.status, j.updated_at, c.make, c.model FROM workshop_jobs j JOIN cars c ON c.id=j.car_id ORDER BY j.updated_at DESC LIMIT 6")->fetchAll();
}

$extraJs = <<<SCRIPT
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
(function () {
    var el = document.getElementById('statusChart');
    if (!el) return;
    var labels = {$chartLabels};
    var counts = {$chartCounts};
    var colorMap = {
        'In Transit':  '#d97706',
        'In Workshop': '#db2777',
        'Completed':   '#16a34a',
        'Arrived':     '#0284c7',
        'In Assessment': '#7c3aed',
        'Delivered':   '#0f172a',
        'Pending':     '#64748b'
    };
    var colors = labels.map(function (l) { return colorMap[l] || '#94a3b8'; });
    new Chart(el, {
        type: 'doughnut',
        data: {
            labels: labels,
            datasets: [{
                data: counts,
                backgroundColor: colors,
                borderWidth: 2,
                borderColor: '#ffffff'
            }]
        },
        options: {
            cutout: '62%',
            plugins: {
                legend: {
                    position: 'bottom',
                    labels: { font: { size: 11 }, padding: 12, boxWidth: 12 }
                }
            }
        }
    });
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
            <span class="badge bg-white bg-opacity-20 fw-normal"><?= ucwords(str_replace('_', ' ', $role)) ?></span>
        </p>
    </div>
    <div class="welcome-stats d-none d-md-flex align-items-center gap-4">
        <?php if ($role === 'mechanic'): ?>
            <div class="text-center">
                <div class="welcome-stat-val"><?= $stats['assigned_jobs'] ?></div>
                <div class="welcome-stat-lbl">Active Jobs</div>
            </div>
            <div class="vr welcome-divider"></div>
            <div class="text-center">
                <div class="welcome-stat-val text-success"><?= $stats['completed_today'] ?></div>
                <div class="welcome-stat-lbl">Done Today</div>
            </div>
        <?php elseif ($role === 'workshop_manager' || $role === 'admin'): ?>
            <div class="text-center">
                <div class="welcome-stat-val"><?= $stats['total_cars'] ?></div>
                <div class="welcome-stat-lbl">Total Fleet</div>
            </div>
            <div class="vr welcome-divider"></div>
            <div class="text-center">
                <div class="welcome-stat-val"><?= $stats['open_jobs'] ?></div>
                <div class="welcome-stat-lbl">Open Jobs</div>
            </div>
        <?php elseif ($role === 'sales_officer'): ?>
            <div class="text-center">
                <div class="welcome-stat-val"><?= money($stats['revenue_month'] ?? 0) ?></div>
                <div class="welcome-stat-lbl">Revenue (Month)</div>
            </div>
        <?php else: ?>
            <div class="text-center">
                <div class="welcome-stat-val"><?= $stats['available_cars'] ?? 0 ?></div>
                <div class="welcome-stat-lbl">Available Cars</div>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Stat Cards -->
<div class="row g-3 mb-4">
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
                <div class="stat-info"><div class="stat-label">Part Requests</div><div class="stat-value"><?= $stats['parts_requested'] ?></div></div>
            </a>
        </div>

    <?php elseif ($role === 'workshop_manager' || $role === 'admin'): ?>
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
                <div class="stat-info"><div class="stat-label">Assessments (Today)</div><div class="stat-value"><?= $stats['qa_today'] ?></div></div>
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
                <div class="stat-info"><div class="stat-label">Payments (Today)</div><div class="stat-value stat-value-sm"><?= money($stats['payments_today']) ?></div></div>
            </a>
        </div>
    <?php endif; ?>
</div>

<!-- Quick Actions -->
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
                <a href="<?= BASE_URL ?>/modules/cars/add.php" class="quick-action-card">
                    <div class="qa-icon" style="background:#dbeafe;color:#2563eb"><i class="fa fa-plus-circle fa-lg"></i></div>
                    <span>Add Car</span>
                </a>
                <a href="<?= BASE_URL ?>/modules/intake/add.php" class="quick-action-card">
                    <div class="qa-icon" style="background:#fef3c7;color:#d97706"><i class="fa fa-anchor fa-lg"></i></div>
                    <span>New Intake</span>
                </a>
                <a href="<?= BASE_URL ?>/modules/assessments/add.php" class="quick-action-card">
                    <div class="qa-icon" style="background:#f0fdf4;color:#16a34a"><i class="fa fa-clipboard-check fa-lg"></i></div>
                    <span>Assessment</span>
                </a>
                <a href="<?= BASE_URL ?>/modules/jobs/add.php" class="quick-action-card">
                    <div class="qa-icon" style="background:#fdf4ff;color:#9333ea"><i class="fa fa-toolbox fa-lg"></i></div>
                    <span>Job Card</span>
                </a>
                <a href="<?= BASE_URL ?>/modules/quotations/add.php" class="quick-action-card">
                    <div class="qa-icon" style="background:#f0f9ff;color:#0284c7"><i class="fa fa-file-lines fa-lg"></i></div>
                    <span>Quotation</span>
                </a>
                <a href="<?= BASE_URL ?>/modules/invoices/index.php" class="quick-action-card">
                    <div class="qa-icon" style="background:#fff1f2;color:#dc2626"><i class="fa fa-file-invoice-dollar fa-lg"></i></div>
                    <span>Invoices</span>
                </a>
                <a href="<?= BASE_URL ?>/modules/reports/index.php" class="quick-action-card">
                    <div class="qa-icon" style="background:#f8fafc;color:#64748b"><i class="fa fa-chart-bar fa-lg"></i></div>
                    <span>Reports</span>
                </a>
                <a href="<?= BASE_URL ?>/modules/issues/index.php" class="quick-action-card">
                    <div class="qa-icon" style="background:#fff7ed;color:#ea580c"><i class="fa fa-triangle-exclamation fa-lg"></i></div>
                    <span>Issues</span>
                </a>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="row g-4">
    <?php if ($role === 'mechanic'): ?>
        <!-- Mechanic's My Jobs -->
        <div class="col-lg-8">
            <div class="card h-100">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span><i class="fa fa-toolbox me-2"></i>My Assigned Jobs</span>
                    <a href="<?= BASE_URL ?>/modules/jobs/index.php" class="btn btn-xs btn-outline-primary">View All</a>
                </div>
                <div class="card-body p-0">
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th class="ps-3">Job #</th>
                                <th>Vehicle</th>
                                <th>Priority</th>
                                <th>Status</th>
                                <th class="text-end pe-3">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($myJobs as $j): ?>
                            <tr>
                                <td class="ps-3 fw-bold small"><?= e($j['job_number']) ?></td>
                                <td>
                                    <div class="fw-medium small"><?= e($j['make'] . ' ' . $j['model']) ?></div>
                                    <code style="font-size:10px"><?= e($j['chassis_number']) ?></code>
                                </td>
                                <td><span class="badge bg-<?= $j['priority']==='urgent'||$j['priority']==='high' ? 'danger' : 'secondary' ?>"><?= strtoupper($j['priority']) ?></span></td>
                                <td><?= statusBadge($j['status']) ?></td>
                                <td class="text-end pe-3">
                                    <a href="<?= BASE_URL ?>/modules/jobs/view.php?id=<?= $j['id'] ?>" class="btn btn-xs btn-primary">Open</a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if (empty($myJobs)): ?>
                            <tr><td colspan="5" class="text-center py-4 text-muted small">No jobs assigned to you currently.</td></tr>
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
                </div>
            </div>
        </div>
    <?php else: ?>
        <!-- Admin Activity Tables -->
        <div class="col-lg-7">
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
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <div class="col-lg-5 d-flex flex-column gap-4">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span><i class="fa fa-clock-rotate-left me-2"></i>Recent Activity</span>
                    <a href="<?= BASE_URL ?>/modules/jobs/index.php" class="btn btn-xs btn-outline-secondary">All Jobs</a>
                </div>
                <div class="list-group list-group-flush">
                    <?php foreach ($recentActivity as $log): ?>
                    <a href="<?= BASE_URL ?>/modules/jobs/index.php" class="list-group-item list-group-item-action">
                        <div class="d-flex justify-content-between align-items-center">
                            <div class="small">
                                <span class="fw-semibold"><?= e($log['make'] . ' ' . $log['model']) ?></span>
                                <span class="text-muted ms-1">#<?= e($log['job_number']) ?></span>
                            </div>
                            <div class="d-flex align-items-center gap-2">
                                <?= statusBadge($log['status']) ?>
                                <span class="text-muted" style="font-size:10px"><?= fmtDate($log['updated_at'], 'd M') ?></span>
                            </div>
                        </div>
                    </a>
                    <?php endforeach; ?>
                    <?php if (empty($recentActivity)): ?>
                    <div class="list-group-item text-center text-muted small py-3">No recent activity.</div>
                    <?php endif; ?>
                </div>
            </div>
            <?php if (!empty($carStatusData)): ?>
            <div class="card">
                <div class="card-header">Fleet Status</div>
                <div class="card-body pb-2"><canvas id="statusChart" height="200"></canvas></div>
            </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>

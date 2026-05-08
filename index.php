<?php
require_once __DIR__ . '/includes/functions.php';
$pageTitle = 'Dashboard';
$user = authUser();
$role = $user['role'] ?? 'mechanic';

if ($role === 'mechanic') {
    $mechId = (int)($user['linked_id'] ?? 0);
    $stats = [
        'assigned_jobs'   => $db->query("SELECT COUNT(*) FROM workshop_jobs WHERE mechanic_id = $mechId AND status NOT IN ('completed','cancelled')")->fetchColumn(),
        'pending_assess'  => $db->query("SELECT COUNT(*) FROM car_assessments WHERE mechanic_id = $mechId AND assessment_date = CURDATE()")->fetchColumn(),
        'completed_today' => $db->query("SELECT COUNT(*) FROM workshop_jobs WHERE mechanic_id = $mechId AND status = 'completed' AND DATE(updated_at) = CURDATE()")->fetchColumn(),
    ];
    $myJobs = $db->query("SELECT j.*, c.make, c.model, c.chassis_number FROM workshop_jobs j JOIN cars c ON c.id=j.car_id WHERE j.mechanic_id = $mechId ORDER BY j.priority DESC, j.created_at DESC LIMIT 10")->fetchAll();
    $myAssessments = $db->query("SELECT ca.*, c.make, c.model FROM car_assessments ca JOIN cars c ON c.id=ca.car_id WHERE ca.mechanic_id = $mechId ORDER BY ca.created_at DESC LIMIT 5")->fetchAll();
} else {
    $stats = getDashboardStats();
    $recentCars = $db->query("SELECT c.*, ci.intake_date FROM cars c LEFT JOIN car_intake ci ON ci.car_id=c.id ORDER BY c.created_at DESC LIMIT 6")->fetchAll();
    $recentJobs = $db->query("SELECT j.*, c.make, c.model, c.chassis_number, m.name AS mechanic_name FROM workshop_jobs j JOIN cars c ON c.id=j.car_id LEFT JOIN mechanics m ON m.id=j.mechanic_id ORDER BY j.created_at DESC LIMIT 5")->fetchAll();
    $recentAudit = $db->query("SELECT a.*, u.name AS user_name FROM audit_logs a LEFT JOIN users u ON u.id = a.user_id ORDER BY a.created_at DESC LIMIT 5")->fetchAll();
    $carStatusData = $db->query("SELECT status, COUNT(*) AS cnt FROM cars GROUP BY status")->fetchAll();

    $chartLabels = json_encode(array_map(fn($r) => ucwords(str_replace('_', ' ', $r['status'])), $carStatusData));
    $chartCounts = json_encode(array_column($carStatusData, 'cnt'));
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
        <p class="mb-0"><?= date('l, d F Y') ?> &mdash; <?= $role === 'mechanic' ? 'Here are your active assignments.' : 'Here&rsquo;s what&rsquo;s happening today.' ?></p>
    </div>
    <div class="welcome-stats d-none d-md-flex align-items-center gap-4">
        <?php if ($role === 'mechanic'): ?>
            <div class="text-center">
                <div class="welcome-stat-val text-warning"><?= $stats['assigned_jobs'] ?></div>
                <div class="welcome-stat-lbl">Active Jobs</div>
            </div>
            <div class="vr welcome-divider"></div>
            <div class="text-center">
                <div class="welcome-stat-val text-success"><?= $stats['completed_today'] ?></div>
                <div class="welcome-stat-lbl">Done Today</div>
            </div>
        <?php else: ?>
            <div class="text-center">
                <div class="welcome-stat-val"><?= $stats['total_cars'] ?></div>
                <div class="welcome-stat-lbl">Total Cars</div>
            </div>
            <div class="vr welcome-divider"></div>
            <div class="text-center">
                <div class="welcome-stat-val"><?= $stats['open_jobs'] ?></div>
                <div class="welcome-stat-lbl">Open Jobs</div>
            </div>
            <div class="vr welcome-divider"></div>
            <div class="text-center">
                <div class="welcome-stat-val"><?= $stats['unpaid_invoices'] ?></div>
                <div class="welcome-stat-lbl">Unpaid Invoices</div>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Stat Cards -->
<div class="row g-3 mb-4">
    <?php if ($role === 'mechanic'): ?>
        <div class="col-sm-6 col-xl-4">
            <a href="<?= BASE_URL ?>/modules/jobs/index.php" class="stat-card stat-card-link" style="border-left:4px solid #f59e0b">
                <div class="stat-icon" style="background:#fef3c7;color:#f59e0b"><i class="fa fa-toolbox"></i></div>
                <div class="stat-info">
                    <div class="stat-label">Assigned Jobs</div>
                    <div class="stat-value"><?= $stats['assigned_jobs'] ?></div>
                </div>
            </a>
        </div>
        <div class="col-sm-6 col-xl-4">
            <a href="<?= BASE_URL ?>/modules/assessments/index.php" class="stat-card stat-card-link" style="border-left:4px solid #3b82f6">
                <div class="stat-icon" style="background:#dbeafe;color:#3b82f6"><i class="fa fa-clipboard-check"></i></div>
                <div class="stat-info">
                    <div class="stat-label">Assessments Today</div>
                    <div class="stat-value"><?= $stats['pending_assess'] ?></div>
                </div>
            </a>
        </div>
        <div class="col-sm-6 col-xl-4">
            <div class="stat-card" style="border-left:4px solid #10b981">
                <div class="stat-icon" style="background:#d1fae5;color:#10b981"><i class="fa fa-circle-check"></i></div>
                <div class="stat-info">
                    <div class="stat-label">Completed Today</div>
                    <div class="stat-value"><?= $stats['completed_today'] ?></div>
                </div>
            </div>
        </div>
    <?php else: ?>
        <div class="col-sm-6 col-xl-3">
            <a href="<?= BASE_URL ?>/modules/cars/index.php" class="stat-card stat-card-link" style="border-left:4px solid #2563eb">
                <div class="stat-icon" style="background:#dbeafe;color:#2563eb"><i class="fa fa-car"></i></div>
                <div class="stat-info"><div class="stat-label">Total Cars</div><div class="stat-value"><?= $stats['total_cars'] ?></div></div>
            </a>
        </div>
        <div class="col-sm-6 col-xl-3">
            <a href="<?= BASE_URL ?>/modules/jobs/index.php" class="stat-card stat-card-link" style="border-left:4px solid #db2777">
                <div class="stat-icon" style="background:#fce7f3;color:#db2777"><i class="fa fa-toolbox"></i></div>
                <div class="stat-info"><div class="stat-label">In Workshop</div><div class="stat-value"><?= $stats['in_workshop'] ?></div></div>
            </a>
        </div>
        <div class="col-sm-6 col-xl-3">
            <a href="<?= BASE_URL ?>/modules/invoices/index.php" class="stat-card stat-card-link" style="border-left:4px solid #dc2626">
                <div class="stat-icon" style="background:#fee2e2;color:#dc2626"><i class="fa fa-file-invoice-dollar"></i></div>
                <div class="stat-info"><div class="stat-label">Unpaid Invoices</div><div class="stat-value"><?= $stats['unpaid_invoices'] ?></div></div>
            </a>
        </div>
        <div class="col-sm-6 col-xl-3">
            <div class="stat-card" style="border-left:4px solid #16a34a">
                <div class="stat-icon" style="background:#dcfce7;color:#16a34a"><i class="fa fa-money-bill-wave"></i></div>
                <div class="stat-info"><div class="stat-label">Revenue (Month)</div><div class="stat-value stat-value-sm"><?= money($stats['revenue_month']) ?></div></div>
            </div>
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
                    <span><i class="fa fa-history me-2"></i>Audit Activity</span>
                    <a href="<?= BASE_URL ?>/modules/audit/index.php" class="btn btn-xs btn-outline-secondary">History</a>
                </div>
                <div class="list-group list-group-flush">
                    <?php foreach ($recentAudit as $log): ?>
                    <div class="list-group-item">
                        <div class="d-flex justify-content-between align-items-start">
                            <div class="small">
                                <span class="fw-bold"><?= e($log['user_name']) ?></span>
                                <span class="text-muted">performed</span>
                                <span class="text-<?= $log['action']==='delete'?'danger':'primary' ?> fw-bold"><?= strtoupper($log['action']) ?></span>
                                <span class="text-muted">on</span>
                                <span class="fw-bold"><?= ucfirst($log['module']) ?></span>
                            </div>
                            <span class="text-muted" style="font-size:10px"><?= fmtDate($log['created_at'], 'H:i') ?></span>
                        </div>
                    </div>
                    <?php endforeach; ?>
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

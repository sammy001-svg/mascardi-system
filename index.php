<?php
require_once __DIR__ . '/includes/functions.php';
$pageTitle = 'Dashboard';
$stats = getDashboardStats();
$db = getDB();

$recentCars = $db->query("SELECT c.*, ci.intake_date FROM cars c LEFT JOIN car_intake ci ON ci.car_id=c.id ORDER BY c.created_at DESC LIMIT 6")->fetchAll();
$recentJobs = $db->query("SELECT j.*, c.make, c.model, c.chassis_number, m.name AS mechanic_name FROM workshop_jobs j JOIN cars c ON c.id=j.car_id LEFT JOIN mechanics m ON m.id=j.mechanic_id ORDER BY j.created_at DESC LIMIT 5")->fetchAll();
$carStatusData = $db->query("SELECT status, COUNT(*) AS cnt FROM cars GROUP BY status")->fetchAll();

$chartLabels = json_encode(array_map(fn($r) => ucwords(str_replace('_', ' ', $r['status'])), $carStatusData));
$chartCounts = json_encode(array_column($carStatusData, 'cnt'));

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
        <h5 class="mb-1">Welcome back</h5>
        <p class="mb-0"><?= date('l, d F Y') ?> &mdash; Here&rsquo;s what&rsquo;s happening today.</p>
    </div>
    <div class="welcome-stats d-none d-md-flex align-items-center gap-4">
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
    </div>
</div>

<!-- Stat Cards -->
<div class="row g-3 mb-4">
    <div class="col-sm-6 col-xl-3">
        <a href="<?= BASE_URL ?>/modules/cars/index.php" class="stat-card stat-card-link" style="border-left:4px solid #2563eb">
            <div class="stat-icon" style="background:#dbeafe;color:#2563eb"><i class="fa fa-car"></i></div>
            <div class="stat-info">
                <div class="stat-label">Total Cars</div>
                <div class="stat-value"><?= $stats['total_cars'] ?></div>
            </div>
        </a>
    </div>
    <div class="col-sm-6 col-xl-3">
        <a href="<?= BASE_URL ?>/modules/intake/index.php" class="stat-card stat-card-link" style="border-left:4px solid #d97706">
            <div class="stat-icon" style="background:#fef3c7;color:#d97706"><i class="fa fa-truck-moving"></i></div>
            <div class="stat-info">
                <div class="stat-label">In Transit</div>
                <div class="stat-value"><?= $stats['in_transit'] ?></div>
            </div>
        </a>
    </div>
    <div class="col-sm-6 col-xl-3">
        <a href="<?= BASE_URL ?>/modules/jobs/index.php" class="stat-card stat-card-link" style="border-left:4px solid #db2777">
            <div class="stat-icon" style="background:#fce7f3;color:#db2777"><i class="fa fa-toolbox"></i></div>
            <div class="stat-info">
                <div class="stat-label">In Workshop</div>
                <div class="stat-value"><?= $stats['in_workshop'] ?></div>
            </div>
        </a>
    </div>
    <div class="col-sm-6 col-xl-3">
        <a href="<?= BASE_URL ?>/modules/cars/index.php" class="stat-card stat-card-link" style="border-left:4px solid #16a34a">
            <div class="stat-icon" style="background:#dcfce7;color:#16a34a"><i class="fa fa-circle-check"></i></div>
            <div class="stat-info">
                <div class="stat-label">Completed</div>
                <div class="stat-value"><?= $stats['completed'] ?></div>
            </div>
        </a>
    </div>
    <div class="col-sm-6 col-xl-3">
        <a href="<?= BASE_URL ?>/modules/jobs/index.php" class="stat-card stat-card-link" style="border-left:4px solid #64748b">
            <div class="stat-icon" style="background:#f1f5f9;color:#64748b"><i class="fa fa-file-lines"></i></div>
            <div class="stat-info">
                <div class="stat-label">Open Jobs</div>
                <div class="stat-value"><?= $stats['open_jobs'] ?></div>
            </div>
        </a>
    </div>
    <div class="col-sm-6 col-xl-3">
        <a href="<?= BASE_URL ?>/modules/invoices/index.php" class="stat-card stat-card-link" style="border-left:4px solid #dc2626">
            <div class="stat-icon" style="background:#fee2e2;color:#dc2626"><i class="fa fa-file-invoice-dollar"></i></div>
            <div class="stat-info">
                <div class="stat-label">Unpaid Invoices</div>
                <div class="stat-value"><?= $stats['unpaid_invoices'] ?></div>
            </div>
        </a>
    </div>
    <div class="col-sm-6 col-xl-3">
        <a href="<?= BASE_URL ?>/modules/inventory/index.php?filter=low_stock" class="stat-card stat-card-link" style="border-left:4px solid #d97706">
            <div class="stat-icon" style="background:#fef3c7;color:#d97706"><i class="fa fa-boxes-stacked"></i></div>
            <div class="stat-info">
                <div class="stat-label">Low Stock Items</div>
                <div class="stat-value"><?= $stats['low_stock'] ?></div>
            </div>
        </a>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="stat-card" style="border-left:4px solid #16a34a">
            <div class="stat-icon" style="background:#dcfce7;color:#16a34a"><i class="fa fa-money-bill-wave"></i></div>
            <div class="stat-info">
                <div class="stat-label">Revenue (Month)</div>
                <div class="stat-value stat-value-sm"><?= money($stats['revenue_month']) ?></div>
            </div>
        </div>
    </div>
</div>

<!-- Quick Actions -->
<div class="card mb-4">
    <div class="card-header"><i class="fa fa-bolt me-2"></i>Quick Actions</div>
    <div class="card-body">
        <div class="quick-actions-grid">
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
            <a href="<?= BASE_URL ?>/modules/lpo/add.php" class="quick-action-card">
                <div class="qa-icon" style="background:#fffbeb;color:#b45309"><i class="fa fa-file-import fa-lg"></i></div>
                <span>Create LPO</span>
            </a>
            <a href="<?= BASE_URL ?>/modules/reports/index.php" class="quick-action-card">
                <div class="qa-icon" style="background:#f8fafc;color:#64748b"><i class="fa fa-chart-bar fa-lg"></i></div>
                <span>Reports</span>
            </a>
        </div>
    </div>
</div>

<div class="row g-4">
    <!-- Recent Cars -->
    <div class="col-lg-7">
        <div class="card h-100">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="fa fa-car me-2"></i>Recent Cars</span>
                <a href="<?= BASE_URL ?>/modules/cars/index.php" class="btn btn-xs btn-outline-primary">View All</a>
            </div>
            <div class="card-body p-0">
                <table class="table table-hover mb-0">
                    <thead>
                        <tr>
                            <th class="ps-3">Chassis</th>
                            <th>Vehicle</th>
                            <th>Year</th>
                            <th>Status</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($recentCars)): ?>
                        <tr>
                            <td colspan="5">
                                <div class="empty-state">
                                    <i class="fa fa-car-side fa-2x mb-3 text-muted"></i>
                                    <p class="mb-2 fw-medium text-muted">No cars added yet</p>
                                    <a href="<?= BASE_URL ?>/modules/cars/add.php" class="btn btn-sm btn-primary">
                                        <i class="fa fa-plus me-1"></i>Add First Car
                                    </a>
                                </div>
                            </td>
                        </tr>
                        <?php else: ?>
                        <?php foreach ($recentCars as $car): ?>
                        <tr>
                            <td class="ps-3"><code class="text-primary"><?= e($car['chassis_number']) ?></code></td>
                            <td class="fw-medium"><?= e($car['make'] . ' ' . $car['model']) ?></td>
                            <td class="text-muted"><?= e($car['year']) ?></td>
                            <td><?= statusBadge($car['status']) ?></td>
                            <td>
                                <a href="<?= BASE_URL ?>/modules/cars/view.php?id=<?= $car['id'] ?>" class="btn btn-xs btn-outline-secondary">
                                    <i class="fa fa-eye me-1"></i>View
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Right column: Jobs + Fleet Chart -->
    <div class="col-lg-5 d-flex flex-column gap-4">

        <!-- Active Jobs -->
        <div class="card flex-grow-1">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="fa fa-toolbox me-2"></i>Active Jobs</span>
                <a href="<?= BASE_URL ?>/modules/jobs/index.php" class="btn btn-xs btn-outline-primary">View All</a>
            </div>
            <div class="card-body p-0">
                <?php if (empty($recentJobs)): ?>
                <div class="empty-state">
                    <i class="fa fa-toolbox fa-2x mb-3 text-muted"></i>
                    <p class="mb-0 text-muted">No active jobs</p>
                </div>
                <?php else: ?>
                <div class="list-group list-group-flush">
                    <?php foreach ($recentJobs as $j): ?>
                    <a href="<?= BASE_URL ?>/modules/jobs/view.php?id=<?= $j['id'] ?>" class="list-group-item list-group-item-action job-list-item">
                        <div class="d-flex justify-content-between align-items-start gap-2">
                            <div class="min-w-0">
                                <div class="fw-semibold small"><?= e($j['job_number']) ?></div>
                                <div class="text-muted" style="font-size:12px">
                                    <?= e($j['make'] . ' ' . $j['model']) ?>
                                    &bull; <code style="font-size:10px"><?= e($j['chassis_number']) ?></code>
                                </div>
                                <?php if ($j['mechanic_name']): ?>
                                <div class="text-muted mt-1" style="font-size:11px">
                                    <i class="fa fa-user-gear me-1"></i><?= e($j['mechanic_name']) ?>
                                </div>
                                <?php endif; ?>
                            </div>
                            <div class="text-end flex-shrink-0">
                                <?= statusBadge($j['status']) ?>
                                <div class="text-muted mt-1" style="font-size:11px"><?= fmtDate($j['start_date']) ?></div>
                            </div>
                        </div>
                    </a>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Fleet Status Chart -->
        <?php if (!empty($carStatusData)): ?>
        <div class="card">
            <div class="card-header"><i class="fa fa-chart-pie me-2"></i>Fleet Status</div>
            <div class="card-body pb-2">
                <canvas id="statusChart" height="220"></canvas>
            </div>
        </div>
        <?php endif; ?>

    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>

<?php
require_once __DIR__ . '/includes/functions.php';
$pageTitle = 'Dashboard';
$stats = getDashboardStats();
$db = getDB();

// Recent cars
$recentCars = $db->query("SELECT c.*, ci.intake_date FROM cars c LEFT JOIN car_intake ci ON ci.car_id=c.id ORDER BY c.created_at DESC LIMIT 6")->fetchAll();

// Recent jobs
$recentJobs = $db->query("SELECT j.*, c.make, c.model, c.chassis_number, m.name AS mechanic_name FROM workshop_jobs j JOIN cars c ON c.id=j.car_id LEFT JOIN mechanics m ON m.id=j.mechanic_id ORDER BY j.created_at DESC LIMIT 5")->fetchAll();

// Cars by status for chart
$carStatusData = $db->query("SELECT status, COUNT(*) AS cnt FROM cars GROUP BY status")->fetchAll();

include __DIR__ . '/includes/header.php';
?>

<!-- Stat Cards -->
<div class="row g-3 mb-4">
    <div class="col-sm-6 col-xl-3">
        <div class="stat-card">
            <div class="stat-icon" style="background:#dbeafe;color:#2563eb"><i class="fa fa-car"></i></div>
            <div class="stat-info">
                <div class="stat-label">Total Cars</div>
                <div class="stat-value"><?= $stats['total_cars'] ?></div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="stat-card">
            <div class="stat-icon" style="background:#fef3c7;color:#d97706"><i class="fa fa-truck-moving"></i></div>
            <div class="stat-info">
                <div class="stat-label">In Transit</div>
                <div class="stat-value"><?= $stats['in_transit'] ?></div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="stat-card">
            <div class="stat-icon" style="background:#fce7f3;color:#db2777"><i class="fa fa-toolbox"></i></div>
            <div class="stat-info">
                <div class="stat-label">In Workshop</div>
                <div class="stat-value"><?= $stats['in_workshop'] ?></div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="stat-card">
            <div class="stat-icon" style="background:#dcfce7;color:#16a34a"><i class="fa fa-circle-check"></i></div>
            <div class="stat-info">
                <div class="stat-label">Completed</div>
                <div class="stat-value"><?= $stats['completed'] ?></div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="stat-card">
            <div class="stat-icon" style="background:#f1f5f9;color:#64748b"><i class="fa fa-file-lines"></i></div>
            <div class="stat-info">
                <div class="stat-label">Open Jobs</div>
                <div class="stat-value"><?= $stats['open_jobs'] ?></div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="stat-card">
            <div class="stat-icon" style="background:#fee2e2;color:#dc2626"><i class="fa fa-file-invoice-dollar"></i></div>
            <div class="stat-info">
                <div class="stat-label">Unpaid Invoices</div>
                <div class="stat-value"><?= $stats['unpaid_invoices'] ?></div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="stat-card">
            <div class="stat-icon" style="background:#fef3c7;color:#d97706"><i class="fa fa-boxes-stacked"></i></div>
            <div class="stat-info">
                <div class="stat-label">Low Stock Items</div>
                <div class="stat-value"><?= $stats['low_stock'] ?></div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="stat-card">
            <div class="stat-icon" style="background:#dcfce7;color:#16a34a"><i class="fa fa-money-bill-wave"></i></div>
            <div class="stat-info">
                <div class="stat-label">Revenue (Month)</div>
                <div class="stat-value" style="font-size:15px"><?= money($stats['revenue_month']) ?></div>
            </div>
        </div>
    </div>
</div>

<!-- Quick Actions -->
<div class="card mb-4">
    <div class="card-header"><i class="fa fa-bolt me-2"></i>Quick Actions</div>
    <div class="card-body d-flex flex-wrap gap-2">
        <a href="<?= BASE_URL ?>/modules/cars/add.php" class="btn btn-primary btn-sm"><i class="fa fa-plus me-1"></i>Add Car</a>
        <a href="<?= BASE_URL ?>/modules/intake/add.php" class="btn btn-outline-primary btn-sm"><i class="fa fa-anchor me-1"></i>New Intake</a>
        <a href="<?= BASE_URL ?>/modules/assessments/add.php" class="btn btn-outline-secondary btn-sm"><i class="fa fa-clipboard-check me-1"></i>New Assessment</a>
        <a href="<?= BASE_URL ?>/modules/jobs/add.php" class="btn btn-outline-dark btn-sm"><i class="fa fa-toolbox me-1"></i>Create Job Card</a>
        <a href="<?= BASE_URL ?>/modules/quotations/add.php" class="btn btn-outline-info btn-sm"><i class="fa fa-file-lines me-1"></i>New Quotation</a>
        <a href="<?= BASE_URL ?>/modules/invoices/index.php" class="btn btn-outline-success btn-sm"><i class="fa fa-file-invoice-dollar me-1"></i>Invoices</a>
        <a href="<?= BASE_URL ?>/modules/lpo/add.php" class="btn btn-outline-warning btn-sm"><i class="fa fa-file-import me-1"></i>Create LPO</a>
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
                        <tr><td colspan="5" class="text-center text-muted py-4">No cars yet. <a href="<?= BASE_URL ?>/modules/cars/add.php">Add first car</a></td></tr>
                        <?php else: ?>
                        <?php foreach ($recentCars as $car): ?>
                        <tr>
                            <td class="ps-3"><code><?= e($car['chassis_number']) ?></code></td>
                            <td><?= e($car['make'] . ' ' . $car['model']) ?></td>
                            <td><?= e($car['year']) ?></td>
                            <td><?= statusBadge($car['status']) ?></td>
                            <td><a href="<?= BASE_URL ?>/modules/cars/view.php?id=<?= $car['id'] ?>" class="btn btn-xs btn-outline-secondary">View</a></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Recent Jobs -->
    <div class="col-lg-5">
        <div class="card h-100">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="fa fa-toolbox me-2"></i>Active Jobs</span>
                <a href="<?= BASE_URL ?>/modules/jobs/index.php" class="btn btn-xs btn-outline-primary">View All</a>
            </div>
            <div class="card-body p-0">
                <?php if (empty($recentJobs)): ?>
                <p class="text-center text-muted py-4">No jobs yet.</p>
                <?php else: ?>
                <div class="list-group list-group-flush">
                    <?php foreach ($recentJobs as $j): ?>
                    <a href="<?= BASE_URL ?>/modules/jobs/view.php?id=<?= $j['id'] ?>" class="list-group-item list-group-item-action">
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <div class="fw-semibold small"><?= e($j['job_number']) ?></div>
                                <div class="text-muted" style="font-size:12px"><?= e($j['make'] . ' ' . $j['model']) ?></div>
                                <?php if ($j['mechanic_name']): ?>
                                <div class="text-muted" style="font-size:11px"><i class="fa fa-user me-1"></i><?= e($j['mechanic_name']) ?></div>
                                <?php endif; ?>
                            </div>
                            <div class="text-end">
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
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>

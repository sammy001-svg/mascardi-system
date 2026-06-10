<?php
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
canAccess('showroom_transfers') || die('Access denied.');
$db   = getDB();
$user = authUser();

// Inline migration (safe to repeat)
foreach ([
    "CREATE TABLE IF NOT EXISTS showroom_transfers (
        id INT AUTO_INCREMENT PRIMARY KEY,
        transfer_number VARCHAR(20) UNIQUE NOT NULL,
        car_id INT NOT NULL,
        driver_id INT NULL,
        from_location_id INT NOT NULL,
        to_location_id INT NOT NULL,
        transfer_type ENUM('transfer','stock_rotation','service_return','ad_hoc') DEFAULT 'transfer',
        status ENUM('pending','approved','in_transit','arrived','cancelled') DEFAULT 'pending',
        requested_date DATE NOT NULL,
        departure_at DATETIME NULL,
        arrival_at DATETIME NULL,
        departure_mileage INT NULL,
        arrival_mileage INT NULL,
        departure_condition TEXT NULL,
        arrival_condition TEXT NULL,
        raised_by VARCHAR(100) NOT NULL,
        approved_by VARCHAR(100) NULL,
        notes TEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (car_id) REFERENCES cars(id) ON DELETE CASCADE,
        FOREIGN KEY (driver_id) REFERENCES drivers(id) ON DELETE SET NULL,
        FOREIGN KEY (from_location_id) REFERENCES locations(id) ON DELETE RESTRICT,
        FOREIGN KEY (to_location_id) REFERENCES locations(id) ON DELETE RESTRICT
    )",
] as $_mig) {
    try { $db->exec($_mig); } catch (\Throwable $_e) {}
}

$transfers = $db->query("
    SELECT st.*,
           c.make, c.model, c.registration_number,
           fl.name AS from_name,
           tl.name AS to_name,
           d.name  AS driver_name_rel
    FROM showroom_transfers st
    JOIN cars c          ON c.id  = st.car_id
    JOIN locations fl    ON fl.id = st.from_location_id
    JOIN locations tl    ON tl.id = st.to_location_id
    LEFT JOIN drivers d  ON d.id  = st.driver_id
    ORDER BY st.created_at DESC
")->fetchAll();

$pending_count = count(array_filter($transfers, fn($t) => $t['status'] === 'pending'));

$statusColors = [
    'pending'    => 'warning',
    'approved'   => 'info',
    'in_transit' => 'primary',
    'arrived'    => 'success',
    'cancelled'  => 'secondary',
];
$typeLabels = [
    'transfer'       => 'Transfer',
    'stock_rotation' => 'Rotation',
    'service_return' => 'Svc Return',
    'ad_hoc'         => 'Ad-hoc',
];
$typeColors = [
    'transfer'       => 'primary',
    'stock_rotation' => 'success',
    'service_return' => 'warning',
    'ad_hoc'         => 'secondary',
];

$pageTitle = 'Showroom Transfers';
include __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h5 class="mb-1"><i class="fa fa-right-left me-2 text-primary"></i>Showroom Transfers</h5>
        <div class="text-muted small">
            <?= $pending_count ?> pending approval<?= $pending_count !== 1 ? 's' : '' ?>
        </div>
    </div>
    <?php if (canWrite('showroom_transfers')): ?>
    <a href="add.php" class="btn btn-primary btn-sm"><i class="fa fa-plus me-1"></i>New Transfer Order</a>
    <?php endif; ?>
</div>

<?php if ($pending_count && canWrite('showroom_transfers')): ?>
<div class="alert alert-warning d-flex align-items-center gap-2 mb-3">
    <i class="fa fa-bell"></i>
    <span><?= $pending_count ?> transfer order<?= $pending_count !== 1 ? 's' : '' ?> awaiting approval.</span>
</div>
<?php endif; ?>

<div class="card">
    <div class="card-body p-0">
        <div class="table-responsive">
        <table class="table table-hover datatable mb-0">
            <thead>
                <tr>
                    <th class="ps-3">Ref #</th>
                    <th>Date</th>
                    <th>Vehicle</th>
                    <th>From</th>
                    <th>To</th>
                    <th>Driver</th>
                    <th>Type</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($transfers as $t): ?>
                <tr>
                    <td class="ps-3 fw-bold">
                        <a href="view.php?id=<?= $t['id'] ?>"><?= e($t['transfer_number']) ?></a>
                    </td>
                    <td class="text-muted small"><?= fmtDate($t['requested_date'], 'd M Y') ?></td>
                    <td class="fw-medium small">
                        <?= e($t['make'] . ' ' . $t['model']) ?>
                        <?php if ($t['registration_number']): ?>
                        <div><span class="badge bg-dark bg-opacity-75 font-monospace"><?= e($t['registration_number']) ?></span></div>
                        <?php endif; ?>
                    </td>
                    <td class="small"><i class="fa fa-location-dot me-1 text-muted"></i><?= e($t['from_name']) ?></td>
                    <td class="small"><i class="fa fa-location-dot me-1 text-success"></i><?= e($t['to_name']) ?></td>
                    <td class="small text-muted"><?= e($t['driver_name_rel'] ?? $t['driver_name'] ?? '—') ?></td>
                    <td>
                        <span class="badge bg-<?= $typeColors[$t['transfer_type']] ?? 'secondary' ?> bg-opacity-75">
                            <?= $typeLabels[$t['transfer_type']] ?? ucfirst($t['transfer_type']) ?>
                        </span>
                    </td>
                    <td>
                        <span class="badge bg-<?= $statusColors[$t['status']] ?? 'secondary' ?>">
                            <?= ucfirst(str_replace('_', ' ', $t['status'])) ?>
                        </span>
                    </td>
                    <td>
                        <a href="view.php?id=<?= $t['id'] ?>" class="btn btn-xs btn-outline-primary" title="View">
                            <i class="fa fa-eye"></i>
                        </a>
                        <a href="print.php?id=<?= $t['id'] ?>" class="btn btn-xs btn-outline-dark" target="_blank" title="Print Slip">
                            <i class="fa fa-print"></i>
                        </a>
                        <?php if (hasRole('admin') && in_array($t['status'], ['pending','cancelled'])): ?>
                        <a href="delete.php?id=<?= $t['id'] ?>"
                           class="btn btn-xs btn-outline-danger"
                           onclick="return confirm('Delete transfer <?= e($t['transfer_number']) ?>?')"
                           title="Delete">
                            <i class="fa fa-trash"></i>
                        </a>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (!$transfers): ?>
                <tr><td colspan="9" class="text-center text-muted py-4">No transfer orders yet.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>

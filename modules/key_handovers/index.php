<?php
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
canAccess('key_handovers') || die('Access denied.');
$db   = getDB();
$user = authUser();

// Inline migration
foreach ([
    "CREATE TABLE IF NOT EXISTS car_keys (
        id INT AUTO_INCREMENT PRIMARY KEY,
        car_id INT NOT NULL,
        key_label VARCHAR(50) NOT NULL,
        current_location_id INT NULL,
        status ENUM('at_showroom','in_transit','with_driver','missing') DEFAULT 'at_showroom',
        notes TEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (car_id) REFERENCES cars(id) ON DELETE CASCADE,
        FOREIGN KEY (current_location_id) REFERENCES locations(id) ON DELETE SET NULL
    )",
    "CREATE TABLE IF NOT EXISTS key_handovers (
        id INT AUTO_INCREMENT PRIMARY KEY,
        handover_number VARCHAR(20) UNIQUE NOT NULL,
        handover_date DATE NOT NULL,
        run_type ENUM('morning_run','evening_run','ad_hoc') DEFAULT 'morning_run',
        driver_id INT NULL,
        driver_name VARCHAR(150) NULL,
        from_location_id INT NOT NULL,
        to_location_id INT NOT NULL,
        status ENUM('pending','checked_out','completed','cancelled') DEFAULT 'pending',
        checked_out_at DATETIME NULL,
        checked_out_by VARCHAR(100) NULL,
        checked_in_at DATETIME NULL,
        checked_in_by VARCHAR(100) NULL,
        notes TEXT NULL,
        created_by VARCHAR(100) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (driver_id) REFERENCES drivers(id) ON DELETE SET NULL,
        FOREIGN KEY (from_location_id) REFERENCES locations(id) ON DELETE RESTRICT,
        FOREIGN KEY (to_location_id) REFERENCES locations(id) ON DELETE RESTRICT
    )",
    "CREATE TABLE IF NOT EXISTS key_handover_items (
        id INT AUTO_INCREMENT PRIMARY KEY,
        handover_id INT NOT NULL,
        car_key_id INT NOT NULL,
        car_id INT NOT NULL,
        checked_out_at DATETIME NULL,
        checked_out_by VARCHAR(100) NULL,
        checked_in_at DATETIME NULL,
        checked_in_by VARCHAR(100) NULL,
        notes TEXT NULL,
        FOREIGN KEY (handover_id) REFERENCES key_handovers(id) ON DELETE CASCADE,
        FOREIGN KEY (car_key_id) REFERENCES car_keys(id) ON DELETE CASCADE,
        FOREIGN KEY (car_id) REFERENCES cars(id) ON DELETE CASCADE
    )",
] as $_mig) {
    try { $db->exec($_mig); } catch (\Throwable $_e) {}
}

// Tabs: handovers | key register
$tab = $_GET['tab'] ?? 'handovers';

// ── Key Register ─────────────────────────────────────────────────────────────
$keys = $db->query("
    SELECT ck.*, c.make, c.model, c.registration_number, l.name AS location_name
    FROM car_keys ck
    JOIN cars c ON c.id = ck.car_id
    LEFT JOIN locations l ON l.id = ck.current_location_id
    ORDER BY ck.status, c.make, c.model
")->fetchAll();

// ── Handover runs ─────────────────────────────────────────────────────────────
$handovers = $db->query("
    SELECT kh.*,
           fl.name AS from_name,
           tl.name AS to_name,
           d.name  AS driver_name_rel,
           COUNT(khi.id) AS key_count
    FROM key_handovers kh
    JOIN locations fl ON fl.id = kh.from_location_id
    JOIN locations tl ON tl.id = kh.to_location_id
    LEFT JOIN drivers d ON d.id = kh.driver_id
    LEFT JOIN key_handover_items khi ON khi.handover_id = kh.id
    GROUP BY kh.id
    ORDER BY kh.handover_date DESC, kh.id DESC
")->fetchAll();

$pending_count = count(array_filter($handovers, fn($h) => $h['status'] === 'pending'));

$statusColors = [
    'pending'     => 'warning',
    'checked_out' => 'primary',
    'completed'   => 'success',
    'cancelled'   => 'secondary',
];
$runLabels = [
    'morning_run' => ['fa-sun',  'Morning Run'],
    'evening_run' => ['fa-moon', 'Evening Run'],
    'ad_hoc'      => ['fa-bolt', 'Ad-hoc'],
];
$keyStatusColors = ['at_showroom'=>'success','in_transit'=>'primary','with_driver'=>'warning','missing'=>'danger'];

$pageTitle = 'Key Handovers';
include __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h5 class="mb-1"><i class="fa fa-key me-2 text-primary"></i>Key Management</h5>
        <div class="text-muted small"><?= $pending_count ?> run<?= $pending_count !== 1 ? 's' : '' ?> pending checkout</div>
    </div>
    <div class="d-flex gap-2">
        <?php if (canWrite('key_handovers')): ?>
        <?php if ($tab === 'keys'): ?>
        <a href="keys_add.php" class="btn btn-outline-primary btn-sm"><i class="fa fa-plus me-1"></i>Register Key</a>
        <?php else: ?>
        <a href="add.php" class="btn btn-primary btn-sm"><i class="fa fa-plus me-1"></i>New Key Run</a>
        <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<ul class="nav nav-tabs mb-3">
    <li class="nav-item">
        <a class="nav-link <?= $tab === 'handovers' ? 'active' : '' ?>" href="?tab=handovers">
            <i class="fa fa-route me-1"></i>Key Runs
            <?php if ($pending_count): ?>
            <span class="badge bg-warning text-dark ms-1"><?= $pending_count ?></span>
            <?php endif; ?>
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $tab === 'keys' ? 'active' : '' ?>" href="?tab=keys">
            <i class="fa fa-key me-1"></i>Key Register
            <span class="badge bg-light text-dark border ms-1"><?= count($keys) ?></span>
        </a>
    </li>
</ul>

<?php if ($tab === 'handovers'): ?>
<!-- ── Handover Runs ───────────────────────────────────────────── -->
<div class="card">
    <div class="card-body p-0">
        <div class="table-responsive">
        <table class="table table-hover datatable mb-0">
            <thead>
                <tr>
                    <th class="ps-3">Ref #</th>
                    <th>Date</th>
                    <th>Run</th>
                    <th>Driver</th>
                    <th>From</th>
                    <th>To</th>
                    <th>Keys</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($handovers as $h):
                    [$runIcon, $runLabel] = $runLabels[$h['run_type']] ?? ['fa-bolt','Ad-hoc'];
                ?>
                <tr>
                    <td class="ps-3 fw-bold">
                        <a href="view.php?id=<?= $h['id'] ?>"><?= e($h['handover_number']) ?></a>
                    </td>
                    <td class="text-muted small"><?= fmtDate($h['handover_date'], 'd M Y') ?></td>
                    <td>
                        <span class="small"><i class="fa <?= $runIcon ?> me-1 text-warning"></i><?= $runLabel ?></span>
                    </td>
                    <td class="small text-muted"><?= e($h['driver_name_rel'] ?? $h['driver_name'] ?? '—') ?></td>
                    <td class="small"><i class="fa fa-location-dot me-1 text-muted"></i><?= e($h['from_name']) ?></td>
                    <td class="small"><i class="fa fa-location-dot me-1 text-success"></i><?= e($h['to_name']) ?></td>
                    <td>
                        <span class="badge bg-light text-dark border"><?= $h['key_count'] ?> key<?= $h['key_count'] != 1 ? 's' : '' ?></span>
                    </td>
                    <td>
                        <span class="badge bg-<?= $statusColors[$h['status']] ?? 'secondary' ?>">
                            <?= ucfirst(str_replace('_', ' ', $h['status'])) ?>
                        </span>
                    </td>
                    <td>
                        <a href="view.php?id=<?= $h['id'] ?>" class="btn btn-xs btn-outline-primary" title="View">
                            <i class="fa fa-eye"></i>
                        </a>
                        <a href="print.php?id=<?= $h['id'] ?>" class="btn btn-xs btn-outline-dark" target="_blank" title="Print Sheet">
                            <i class="fa fa-print"></i>
                        </a>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (!$handovers): ?>
                <tr><td colspan="9" class="text-center text-muted py-4">No key runs yet.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
        </div>
    </div>
</div>

<?php else: ?>
<!-- ── Key Register ────────────────────────────────────────────── -->
<div class="card">
    <div class="card-body p-0">
        <div class="table-responsive">
        <table class="table table-hover datatable mb-0">
            <thead>
                <tr>
                    <th class="ps-3">Key Label</th>
                    <th>Vehicle</th>
                    <th>Current Location</th>
                    <th>Status</th>
                    <th>Notes</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($keys as $k): ?>
                <tr>
                    <td class="ps-3 fw-bold font-monospace"><?= e($k['key_label']) ?></td>
                    <td class="fw-medium small">
                        <?= e($k['make'] . ' ' . $k['model']) ?>
                        <?php if ($k['registration_number']): ?>
                        <span class="badge bg-dark bg-opacity-75 ms-1"><?= e($k['registration_number']) ?></span>
                        <?php endif; ?>
                    </td>
                    <td class="small text-muted"><?= e($k['location_name'] ?: '—') ?></td>
                    <td>
                        <span class="badge bg-<?= $keyStatusColors[$k['status']] ?? 'secondary' ?>">
                            <?= ucfirst(str_replace('_', ' ', $k['status'])) ?>
                        </span>
                    </td>
                    <td class="text-muted small"><?= $k['notes'] ? e($k['notes']) : '—' ?></td>
                    <td>
                        <?php if (canWrite('key_handovers')): ?>
                        <a href="keys_edit.php?id=<?= $k['id'] ?>" class="btn btn-xs btn-outline-warning" title="Edit">
                            <i class="fa fa-pen"></i>
                        </a>
                        <a href="keys_delete.php?id=<?= $k['id'] ?>"
                           class="btn btn-xs btn-outline-danger"
                           onclick="return confirm('Remove key <?= e($k['key_label']) ?> from register?')"
                           title="Delete">
                            <i class="fa fa-trash"></i>
                        </a>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (!$keys): ?>
                <tr><td colspan="6" class="text-center text-muted py-4">No keys registered yet. <a href="keys_add.php">Register the first key.</a></td></tr>
                <?php endif; ?>
            </tbody>
        </table>
        </div>
    </div>
</div>
<?php endif; ?>

<?php include __DIR__ . '/../../includes/footer.php'; ?>

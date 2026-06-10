<?php
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
canAccess('showroom_transfers') || die('Access denied.');
$db   = getDB();
$user = authUser();

$id = (int)($_GET['id'] ?? 0);
if (!$id) redirect(BASE_URL . '/modules/showroom_transfers/index.php');

$stmt = $db->prepare("
    SELECT st.*,
           c.make, c.model, c.registration_number, c.chassis_number, c.year, c.color,
           fl.name AS from_name,
           tl.name AS to_name,
           d.name  AS driver_name_rel, d.phone AS driver_phone
    FROM showroom_transfers st
    JOIN cars c          ON c.id  = st.car_id
    JOIN locations fl    ON fl.id = st.from_location_id
    JOIN locations tl    ON tl.id = st.to_location_id
    LEFT JOIN drivers d  ON d.id  = st.driver_id
    WHERE st.id = ?
");
$stmt->execute([$id]);
$t = $stmt->fetch();
if (!$t) { setFlash('error', 'Transfer not found.'); redirect(BASE_URL . '/modules/showroom_transfers/index.php'); }

$drivers   = $db->query("SELECT id, name FROM drivers WHERE status='active' ORDER BY name")->fetchAll();

// ── POST Actions ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && canWrite('showroom_transfers')) {
    $action = $_POST['action'] ?? '';

    if ($action === 'approve') {
        $driverId = (int)($_POST['driver_id'] ?? 0) ?: null;
        $db->prepare("UPDATE showroom_transfers SET status='approved', approved_by=?, driver_id=COALESCE(?,driver_id), updated_at=NOW() WHERE id=?")
           ->execute([$user['name'], $driverId, $id]);
        logActivity('update', 'showroom_transfers', $id, "Approved transfer {$t['transfer_number']}");
        setFlash('success', 'Transfer approved.');
        redirect(BASE_URL . '/modules/showroom_transfers/view.php?id=' . $id);
    }

    if ($action === 'cancel') {
        $db->prepare("UPDATE showroom_transfers SET status='cancelled', updated_at=NOW() WHERE id=?")
           ->execute([$id]);
        setFlash('success', 'Transfer cancelled.');
        redirect(BASE_URL . '/modules/showroom_transfers/view.php?id=' . $id);
    }

    if ($action === 'depart') {
        $mileage   = (int)($_POST['departure_mileage'] ?? 0) ?: null;
        $condition = trim($_POST['departure_condition'] ?? '');
        $db->prepare("UPDATE showroom_transfers SET status='in_transit', departure_at=NOW(), departure_mileage=?, departure_condition=?, updated_at=NOW() WHERE id=?")
           ->execute([$mileage, $condition ?: null, $id]);
        // Update car status to in_transit
        $db->prepare("UPDATE cars SET status='in_transit', updated_at=NOW() WHERE id=?")
           ->execute([$t['car_id']]);
        logActivity('update', 'showroom_transfers', $id, "Departed for {$t['to_name']}");
        setFlash('success', 'Departure confirmed. Car marked in-transit.');
        redirect(BASE_URL . '/modules/showroom_transfers/view.php?id=' . $id);
    }

    if ($action === 'arrive') {
        $mileage   = (int)($_POST['arrival_mileage'] ?? 0) ?: null;
        $condition = trim($_POST['arrival_condition'] ?? '');
        $db->beginTransaction();
        try {
            $db->prepare("UPDATE showroom_transfers SET status='arrived', arrival_at=NOW(), arrival_mileage=?, arrival_condition=?, updated_at=NOW() WHERE id=?")
               ->execute([$mileage, $condition ?: null, $id]);
            // Auto-update car location and status
            $db->prepare("UPDATE cars SET location_id=?, status='arrived', updated_at=NOW() WHERE id=?")
               ->execute([$t['to_location_id'], $t['car_id']]);
            // If stock rotation, update rotation date
            if ($t['transfer_type'] === 'stock_rotation') {
                $db->prepare("UPDATE cars SET last_rotated_at=CURDATE(), rotation_notes=? WHERE id=?")
                   ->execute([$t['notes'], $t['car_id']]);
            }
            $db->commit();
            logActivity('update', 'showroom_transfers', $id, "Arrived at {$t['to_name']} — car location updated");
            setFlash('success', 'Arrival confirmed. Car location updated to ' . $t['to_name'] . '.');
        } catch (\Throwable $e) {
            $db->rollBack();
            setFlash('error', 'Failed: ' . $e->getMessage());
        }
        redirect(BASE_URL . '/modules/showroom_transfers/view.php?id=' . $id);
    }
}

$statusMeta = [
    'pending'    => ['warning',   'fa-clock',        'Pending Approval'],
    'approved'   => ['info',      'fa-check-circle',  'Approved'],
    'in_transit' => ['primary',   'fa-truck-moving',  'In Transit'],
    'arrived'    => ['success',   'fa-flag-checkered','Arrived'],
    'cancelled'  => ['secondary', 'fa-ban',           'Cancelled'],
];
[$sColor, $sIcon, $sLabel] = $statusMeta[$t['status']] ?? ['secondary','fa-question','Unknown'];

$typeLabels = [
    'transfer'       => ['primary', 'Stock Transfer'],
    'stock_rotation' => ['success', 'Stock Rotation'],
    'service_return' => ['warning', 'Service Return'],
    'ad_hoc'         => ['secondary','Ad-hoc'],
];
[$tColor, $tLabel] = $typeLabels[$t['transfer_type']] ?? ['secondary', ucfirst($t['transfer_type'])];

$pageTitle = 'Transfer ' . $t['transfer_number'];
include __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <h5 class="mb-1"><i class="fa fa-right-left me-2 text-primary"></i><?= e($t['transfer_number']) ?></h5>
        <div class="text-muted small">Raised by <strong><?= e($t['raised_by']) ?></strong> on <?= fmtDate($t['created_at'], 'd M Y, H:i') ?></div>
    </div>
    <div class="d-flex align-items-center gap-2 flex-wrap">
        <span class="badge bg-<?= $sColor ?> fs-6 px-3 py-2"><i class="fa <?= $sIcon ?> me-1"></i><?= $sLabel ?></span>
        <span class="badge bg-<?= $tColor ?> bg-opacity-75"><?= $tLabel ?></span>
        <a href="print.php?id=<?= $id ?>" class="btn btn-sm btn-outline-dark" target="_blank">
            <i class="fa fa-print me-1"></i>Transfer Slip
        </a>
        <a href="index.php" class="btn btn-sm btn-outline-secondary"><i class="fa fa-arrow-left me-1"></i>Back</a>
        <?php if (hasRole('admin') && in_array($t['status'], ['pending','cancelled'])): ?>
        <a href="delete.php?id=<?= $id ?>"
           class="btn btn-sm btn-danger"
           onclick="return confirm('Delete transfer <?= e($t['transfer_number']) ?>?')">
            <i class="fa fa-trash me-1"></i>Delete
        </a>
        <?php endif; ?>
    </div>
</div>

<!-- Vehicle + Route -->
<div class="row g-4 mb-4">
    <div class="col-md-4">
        <div class="card h-100">
            <div class="card-header fw-semibold"><i class="fa fa-car me-2 text-primary"></i>Vehicle</div>
            <div class="card-body">
                <div class="fw-bold fs-6"><?= e($t['make'] . ' ' . $t['model']) ?> <span class="text-muted fw-normal"><?= $t['year'] ?></span></div>
                <?php if ($t['registration_number']): ?>
                <div class="mt-1"><span class="badge bg-dark font-monospace"><?= e($t['registration_number']) ?></span></div>
                <?php endif; ?>
                <?php if ($t['chassis_number']): ?>
                <div class="text-muted small mt-1"><i class="fa fa-hashtag me-1"></i><?= e($t['chassis_number']) ?></div>
                <?php endif; ?>
                <a href="<?= BASE_URL ?>/modules/cars/view.php?id=<?= $t['car_id'] ?>" class="btn btn-xs btn-outline-primary mt-2">
                    <i class="fa fa-external-link me-1"></i>View Car
                </a>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card h-100">
            <div class="card-header fw-semibold"><i class="fa fa-route me-2 text-primary"></i>Route</div>
            <div class="card-body">
                <div class="d-flex align-items-center gap-2 mb-2">
                    <span class="badge bg-danger bg-opacity-75 px-2 py-1">FROM</span>
                    <span class="fw-semibold"><?= e($t['from_name']) ?></span>
                </div>
                <div class="text-muted ps-1 mb-2"><i class="fa fa-arrow-down"></i></div>
                <div class="d-flex align-items-center gap-2">
                    <span class="badge bg-success bg-opacity-75 px-2 py-1">TO</span>
                    <span class="fw-semibold"><?= e($t['to_name']) ?></span>
                </div>
                <hr class="my-2">
                <div class="text-muted small">
                    <i class="fa fa-calendar me-1"></i>Requested: <?= fmtDate($t['requested_date'], 'd M Y') ?>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card h-100">
            <div class="card-header fw-semibold"><i class="fa fa-id-card me-2 text-primary"></i>Driver</div>
            <div class="card-body">
                <?php if ($t['driver_name_rel'] || $t['driver_name']): ?>
                <div class="fw-bold"><?= e($t['driver_name_rel'] ?? $t['driver_name']) ?></div>
                <?php if ($t['driver_phone']): ?>
                <div class="text-muted small mt-1"><i class="fa fa-phone me-1"></i><?= e($t['driver_phone']) ?></div>
                <?php endif; ?>
                <?php else: ?>
                <span class="text-muted">Not assigned yet.</span>
                <?php endif; ?>
                <?php if ($t['approved_by']): ?>
                <hr class="my-2">
                <div class="text-muted small"><i class="fa fa-check me-1 text-success"></i>Approved by <?= e($t['approved_by']) ?></div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Trip Log -->
<?php if ($t['departure_at'] || $t['arrival_at'] || $t['departure_mileage'] || $t['arrival_mileage']): ?>
<div class="card mb-4">
    <div class="card-header fw-semibold"><i class="fa fa-truck-moving me-2"></i>Trip Log</div>
    <div class="card-body">
        <div class="row g-3">
            <?php if ($t['departure_at']): ?>
            <div class="col-md-3">
                <div class="text-muted small">Departed</div>
                <div class="fw-semibold"><?= fmtDate($t['departure_at'], 'd M Y, H:i') ?></div>
            </div>
            <?php endif; ?>
            <?php if ($t['departure_mileage']): ?>
            <div class="col-md-3">
                <div class="text-muted small">Departure Mileage</div>
                <div class="fw-semibold"><?= number_format($t['departure_mileage']) ?> km</div>
            </div>
            <?php endif; ?>
            <?php if ($t['arrival_at']): ?>
            <div class="col-md-3">
                <div class="text-muted small">Arrived</div>
                <div class="fw-semibold"><?= fmtDate($t['arrival_at'], 'd M Y, H:i') ?></div>
            </div>
            <?php endif; ?>
            <?php if ($t['arrival_mileage']): ?>
            <div class="col-md-3">
                <div class="text-muted small">Arrival Mileage</div>
                <div class="fw-semibold"><?= number_format($t['arrival_mileage']) ?> km</div>
            </div>
            <?php endif; ?>
        </div>
        <?php if ($t['departure_condition']): ?>
        <div class="mt-3 pt-2 border-top">
            <span class="text-muted small">Condition at Departure:</span>
            <p class="mb-0 mt-1"><?= nl2br(e($t['departure_condition'])) ?></p>
        </div>
        <?php endif; ?>
        <?php if ($t['arrival_condition']): ?>
        <div class="mt-3 pt-2 border-top">
            <span class="text-muted small">Condition at Arrival:</span>
            <p class="mb-0 mt-1"><?= nl2br(e($t['arrival_condition'])) ?></p>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<?php if ($t['notes']): ?>
<div class="card mb-4">
    <div class="card-header fw-semibold">Notes</div>
    <div class="card-body"><p class="mb-0"><?= nl2br(e($t['notes'])) ?></p></div>
</div>
<?php endif; ?>

<?php if (canWrite('showroom_transfers')): ?>

<!-- ── APPROVE action (pending) ─────────────────────────────────── -->
<?php if ($t['status'] === 'pending'): ?>
<div class="card mb-3" style="border-top:3px solid #2563eb">
    <div class="card-header fw-semibold"><i class="fa fa-gavel me-2"></i>Approve Transfer</div>
    <div class="card-body">
        <form method="POST" class="row g-3 align-items-end">
            <input type="hidden" name="action" value="approve">
            <div class="col-md-5">
                <label class="form-label">Assign Driver <span class="text-muted small">(or leave for later)</span></label>
                <select name="driver_id" class="form-select select2">
                    <option value="">— No driver yet —</option>
                    <?php foreach ($drivers as $drv): ?>
                    <option value="<?= $drv['id'] ?>" <?= ($t['driver_id'] == $drv['id']) ? 'selected' : '' ?>>
                        <?= e($drv['name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4 d-flex gap-2">
                <button type="submit" class="btn btn-success flex-grow-1">
                    <i class="fa fa-check me-1"></i>Approve
                </button>
                <button type="submit" form="cancelForm" class="btn btn-outline-danger flex-grow-1">
                    <i class="fa fa-xmark me-1"></i>Cancel
                </button>
            </div>
        </form>
        <form method="POST" id="cancelForm"><input type="hidden" name="action" value="cancel"></form>
    </div>
</div>
<?php endif; ?>

<!-- ── CONFIRM DEPARTURE (approved) ─────────────────────────────── -->
<?php if ($t['status'] === 'approved'): ?>
<div class="card mb-3" style="border-top:3px solid #0ea5e9">
    <div class="card-header fw-semibold"><i class="fa fa-truck-moving me-2"></i>Confirm Departure</div>
    <div class="card-body">
        <form method="POST" class="row g-3 align-items-end">
            <input type="hidden" name="action" value="depart">
            <div class="col-md-4">
                <label class="form-label">Departure Mileage <span class="text-muted small">(km)</span></label>
                <input type="number" name="departure_mileage" class="form-control" placeholder="e.g. 45000" min="0">
            </div>
            <div class="col-md-5">
                <label class="form-label">Condition at Departure</label>
                <input type="text" name="departure_condition" class="form-control" placeholder="e.g. Clean, no damage">
            </div>
            <div class="col-md-3">
                <button type="submit" class="btn btn-primary w-100">
                    <i class="fa fa-truck-moving me-1"></i>Mark In Transit
                </button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- ── CONFIRM ARRIVAL (in_transit) ─────────────────────────────── -->
<?php if ($t['status'] === 'in_transit'): ?>
<div class="card mb-3" style="border-top:3px solid #22c55e">
    <div class="card-header fw-semibold"><i class="fa fa-flag-checkered me-2"></i>Confirm Arrival at <?= e($t['to_name']) ?></div>
    <div class="card-body">
        <form method="POST" class="row g-3 align-items-end">
            <input type="hidden" name="action" value="arrive">
            <div class="col-md-4">
                <label class="form-label">Arrival Mileage <span class="text-muted small">(km)</span></label>
                <input type="number" name="arrival_mileage" class="form-control" placeholder="e.g. 45085" min="0">
            </div>
            <div class="col-md-5">
                <label class="form-label">Condition on Arrival</label>
                <input type="text" name="arrival_condition" class="form-control" placeholder="e.g. Clean, minor dust">
            </div>
            <div class="col-md-3">
                <button type="submit" class="btn btn-success w-100">
                    <i class="fa fa-flag-checkered me-1"></i>Confirm Arrival
                </button>
            </div>
        </form>
        <div class="form-text mt-2">
            <i class="fa fa-circle-info me-1 text-success"></i>
            Confirming arrival will automatically update the car's location to <strong><?= e($t['to_name']) ?></strong>.
        </div>
    </div>
</div>
<?php endif; ?>

<?php endif; ?>

<?php include __DIR__ . '/../../includes/footer.php'; ?>

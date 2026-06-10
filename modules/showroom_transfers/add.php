<?php
require_once __DIR__ . '/../../includes/functions.php';
requireWrite('showroom_transfers');
$db   = getDB();
$user = authUser();

$locations = $db->query("SELECT id, name FROM locations WHERE status='active' ORDER BY name")->fetchAll();
$drivers   = $db->query("SELECT id, name FROM drivers WHERE status='active' ORDER BY name")->fetchAll();
$cars      = $db->query("
    SELECT c.id, c.make, c.model, c.registration_number, c.chassis_number,
           l.name AS location_name, l.id AS location_id
    FROM cars c
    LEFT JOIN locations l ON l.id = c.location_id
    WHERE c.status NOT IN ('delivered')
    ORDER BY c.make, c.model
")->fetchAll();

// Pre-select car from query string (e.g. from stock rotation report)
$preCarId = (int)($_GET['car_id'] ?? 0);
$preType  = $_GET['type'] ?? 'transfer';

$errors = [];
$d = [
    'car_id'          => $preCarId ?: '',
    'driver_id'       => '',
    'from_location_id'=> '',
    'to_location_id'  => '',
    'transfer_type'   => $preType,
    'requested_date'  => date('Y-m-d'),
    'notes'           => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $d['car_id']           = (int)($_POST['car_id'] ?? 0);
    $d['driver_id']        = (int)($_POST['driver_id'] ?? 0) ?: null;
    $d['from_location_id'] = (int)($_POST['from_location_id'] ?? 0);
    $d['to_location_id']   = (int)($_POST['to_location_id'] ?? 0);
    $d['transfer_type']    = $_POST['transfer_type'] ?? 'transfer';
    $d['requested_date']   = $_POST['requested_date'] ?? date('Y-m-d');
    $d['notes']            = trim($_POST['notes'] ?? '');

    if (!$d['car_id'])           $errors[] = 'Select a vehicle.';
    if (!$d['from_location_id']) $errors[] = 'Select the origin location.';
    if (!$d['to_location_id'])   $errors[] = 'Select the destination location.';
    if ($d['from_location_id'] && $d['from_location_id'] === $d['to_location_id']) $errors[] = 'Origin and destination cannot be the same.';

    if (empty($errors)) {
        try {
            $num = nextNumber('showroom_transfers', 'transfer_number', 'ST');
            $db->prepare("
                INSERT INTO showroom_transfers
                    (transfer_number, car_id, driver_id, from_location_id, to_location_id,
                     transfer_type, requested_date, notes, raised_by)
                VALUES (?,?,?,?,?, ?,?,?,?)
            ")->execute([
                $num, $d['car_id'], $d['driver_id'], $d['from_location_id'], $d['to_location_id'],
                $d['transfer_type'], $d['requested_date'], $d['notes'] ?: null, $user['name'],
            ]);
            $newId = (int)$db->lastInsertId();
            logActivity('create', 'showroom_transfers', $newId, "Raised transfer order {$num}");
            setFlash('success', "Transfer order {$num} raised successfully.");
            redirect(BASE_URL . '/modules/showroom_transfers/view.php?id=' . $newId);
        } catch (\Throwable $e) {
            $errors[] = 'Save failed: ' . $e->getMessage();
        }
    }
}

$typeOptions = [
    'transfer'       => 'Stock Transfer',
    'stock_rotation' => 'Stock Rotation',
    'service_return' => 'Service Return',
    'ad_hoc'         => 'Ad-hoc',
];

$pageTitle = 'New Transfer Order';
include __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="mb-0"><i class="fa fa-right-left me-2 text-primary"></i>New Transfer Order</h5>
    <a href="index.php" class="btn btn-sm btn-outline-secondary"><i class="fa fa-arrow-left me-1"></i>Back</a>
</div>

<?php if ($errors): ?>
<div class="alert alert-danger mb-3">
    <?php foreach ($errors as $err) echo '<div><i class="fa fa-circle-exclamation me-2"></i>' . e($err) . '</div>'; ?>
</div>
<?php endif; ?>

<form method="POST">
<div class="row g-4">

    <!-- ── Vehicle ────────────────────────────────────────────── -->
    <div class="col-12">
        <div class="card">
            <div class="card-header fw-semibold"><i class="fa fa-car me-2 text-primary"></i>Vehicle</div>
            <div class="card-body">
                <select name="car_id" class="form-select select2" required id="carSelect">
                    <option value="">— Select vehicle —</option>
                    <?php foreach ($cars as $c): ?>
                    <option value="<?= $c['id'] ?>"
                            data-location="<?= $c['location_id'] ?>"
                            <?= ($d['car_id'] == $c['id']) ? 'selected' : '' ?>>
                        <?= e($c['make'] . ' ' . $c['model']) ?>
                        <?= $c['registration_number'] ? ' — ' . e($c['registration_number']) : '' ?>
                        <?= $c['location_name'] ? ' [' . e($c['location_name']) . ']' : '' ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                <div class="form-text mt-1"><i class="fa fa-circle-info me-1 text-primary"></i>Current location is shown in brackets. Selecting a car will auto-fill the origin.</div>
            </div>
        </div>
    </div>

    <!-- ── Route ──────────────────────────────────────────────── -->
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header fw-semibold"><i class="fa fa-location-dot me-2 text-danger"></i>Origin</div>
            <div class="card-body">
                <select name="from_location_id" class="form-select select2" required id="fromLocation">
                    <option value="">— Select origin —</option>
                    <?php foreach ($locations as $loc): ?>
                    <option value="<?= $loc['id'] ?>" <?= ($d['from_location_id'] == $loc['id']) ? 'selected' : '' ?>>
                        <?= e($loc['name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header fw-semibold"><i class="fa fa-location-dot me-2 text-success"></i>Destination</div>
            <div class="card-body">
                <select name="to_location_id" class="form-select select2" required>
                    <option value="">— Select destination —</option>
                    <?php foreach ($locations as $loc): ?>
                    <option value="<?= $loc['id'] ?>" <?= ($d['to_location_id'] == $loc['id']) ? 'selected' : '' ?>>
                        <?= e($loc['name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
    </div>

    <!-- ── Details ────────────────────────────────────────────── -->
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header fw-semibold"><i class="fa fa-circle-info me-2 text-primary"></i>Transfer Details</div>
            <div class="card-body">
                <div class="mb-3">
                    <label class="form-label fw-semibold small">Transfer Type</label>
                    <select name="transfer_type" class="form-select">
                        <?php foreach ($typeOptions as $val => $label): ?>
                        <option value="<?= $val ?>" <?= $d['transfer_type'] === $val ? 'selected' : '' ?>><?= $label ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="form-label fw-semibold small">Date Required <span class="text-danger">*</span></label>
                    <input type="date" name="requested_date" class="form-control" value="<?= e($d['requested_date']) ?>" required>
                </div>
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header fw-semibold"><i class="fa fa-id-card me-2 text-primary"></i>Driver Assignment</div>
            <div class="card-body">
                <label class="form-label fw-semibold small">Assign Driver <span class="text-muted fw-normal">(optional — can assign later)</span></label>
                <select name="driver_id" class="form-select select2">
                    <option value="">— Assign later —</option>
                    <?php foreach ($drivers as $drv): ?>
                    <option value="<?= $drv['id'] ?>" <?= ($d['driver_id'] == $drv['id']) ? 'selected' : '' ?>>
                        <?= e($drv['name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
    </div>

    <!-- ── Notes ──────────────────────────────────────────────── -->
    <div class="col-12">
        <div class="card">
            <div class="card-body">
                <label class="form-label fw-semibold">Notes</label>
                <textarea name="notes" class="form-control" rows="3" placeholder="Any special instructions…"><?= e($d['notes']) ?></textarea>
            </div>
        </div>
    </div>

    <div class="col-12 d-flex justify-content-end gap-2">
        <a href="index.php" class="btn btn-outline-secondary">Cancel</a>
        <button type="submit" class="btn btn-primary px-4">
            <i class="fa fa-paper-plane me-2"></i>Raise Transfer Order
        </button>
    </div>

</div>
</form>

<?php include __DIR__ . '/../../includes/footer.php'; ?>

<script>
$(function () {
    $('#carSelect').on('select2:select', function () {
        const opt = this.options[this.selectedIndex];
        const locId = opt ? opt.dataset.location : '';
        if (locId) {
            const fromSel = document.querySelector('[name="from_location_id"]');
            if (fromSel && !fromSel.value) {
                $(fromSel).val(locId).trigger('change');
            }
        }
    });
});
</script>

<?php
require_once __DIR__ . '/../../includes/functions.php';
requireLogin(); requireRole('admin');
$pageTitle = 'Assign Driver for Delivery';
$db = getDB();

$carId = (int)($_GET['id'] ?? 0);
if (!$carId) redirect(BASE_URL . '/modules/cars/index.php');

$stmt = $db->prepare("SELECT * FROM cars WHERE id=?");
$stmt->execute([$carId]); $car = $stmt->fetch();
if (!$car) { setFlash('error', 'Car not found.'); redirect(BASE_URL . '/modules/cars/index.php'); }

$drivers = $db->query("SELECT id, name, phone, license_number FROM drivers WHERE status='active' ORDER BY name")->fetchAll();

// Existing pending/in_transit transfer for this car
$existing = $db->prepare("SELECT ct.*, d.name AS driver_name FROM car_transfers ct JOIN drivers d ON d.id=ct.driver_id WHERE ct.car_id=? AND ct.status IN ('pending','in_transit') ORDER BY ct.id DESC LIMIT 1");
$existing->execute([$carId]); $existing = $existing->fetch();

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $driverId       = (int)($_POST['driver_id'] ?? 0);
    $departureDate  = $_POST['departure_date'] ?? '';
    $estArrival     = $_POST['estimated_arrival'] ?? '';
    $fromLocation   = trim($_POST['from_location'] ?? 'Mombasa');
    $toLocation     = trim($_POST['to_location']   ?? 'Nairobi');
    $notes          = trim($_POST['notes'] ?? '');

    if (!$driverId)      $errors[] = 'Please select a driver.';
    if (!$departureDate) $errors[] = 'Departure date is required.';

    if (empty($errors)) {
        // Cancel any previous pending transfer for this car
        $db->prepare("UPDATE car_transfers SET status='pending' WHERE car_id=? AND status='pending'")->execute([$carId]);

        $db->prepare("INSERT INTO car_transfers (car_id, driver_id, departure_date, estimated_arrival, from_location, to_location, status, notes)
                      VALUES (?, ?, ?, ?, ?, ?, 'pending', ?)")
           ->execute([$carId, $driverId, $departureDate, $estArrival ?: null, $fromLocation, $toLocation, $notes]);

        // Mark car as in_transit
        $db->prepare("UPDATE cars SET status='in_transit' WHERE id=?")->execute([$carId]);

        setFlash('success', 'Driver assigned. Car marked as In Transit.');
        redirect(BASE_URL . '/modules/cars/view.php?id=' . $carId);
    }
}

include __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="mb-0"><i class="fa fa-id-card me-2 text-warning"></i>Assign Driver for Delivery</h5>
    <a href="view.php?id=<?= $carId ?>" class="btn btn-sm btn-outline-secondary"><i class="fa fa-arrow-left me-1"></i>Back to Car</a>
</div>

<!-- Car summary -->
<div class="card mb-4" style="border-left:4px solid #f59e0b">
    <div class="card-body py-3">
        <div class="row g-2">
            <div class="col-md-4">
                <div class="text-muted small">Vehicle</div>
                <div class="fw-bold"><?= e($car['make'] . ' ' . $car['model'] . ' ' . $car['year']) ?></div>
            </div>
            <div class="col-md-4">
                <div class="text-muted small">Chassis</div>
                <code><?= e($car['chassis_number']) ?></code>
            </div>
            <div class="col-md-4">
                <div class="text-muted small">Current Status</div>
                <?= statusBadge($car['status']) ?>
            </div>
        </div>
    </div>
</div>

<?php if ($existing): ?>
<div class="alert alert-warning mb-4">
    <i class="fa fa-triangle-exclamation me-2"></i>
    This car already has an active transfer assigned to <strong><?= e($existing['driver_name']) ?></strong>
    (Status: <?= statusBadge($existing['status']) ?>).
    Submitting this form will create a new assignment.
</div>
<?php endif; ?>

<?php if ($errors): ?>
<div class="alert alert-danger mb-3">
    <?php foreach ($errors as $err) echo '<div><i class="fa fa-circle-exclamation me-2"></i>'.e($err).'</div>'; ?>
</div>
<?php endif; ?>

<div class="card">
    <div class="card-header"><i class="fa fa-truck me-2"></i>Transfer Details</div>
    <div class="card-body">
        <form method="POST">
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">Driver <span class="text-danger">*</span></label>
                    <select name="driver_id" class="form-select select2" required>
                        <option value="">— Select active driver —</option>
                        <?php foreach ($drivers as $d): ?>
                        <option value="<?= $d['id'] ?>"><?= e($d['name']) ?> &mdash; <?= e($d['license_number']) ?> (<?= e($d['phone']) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Departure Date <span class="text-danger">*</span></label>
                    <input type="date" name="departure_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Est. Arrival Date</label>
                    <input type="date" name="estimated_arrival" class="form-control">
                </div>
                <div class="col-md-3">
                    <label class="form-label">From</label>
                    <input type="text" name="from_location" class="form-control" value="Mombasa">
                </div>
                <div class="col-md-3">
                    <label class="form-label">To</label>
                    <input type="text" name="to_location" class="form-control" value="Nairobi">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Notes</label>
                    <input type="text" name="notes" class="form-control" placeholder="Any special instructions for the driver…">
                </div>
                <div class="col-12 d-flex gap-2 justify-content-end border-top pt-3 mt-1">
                    <a href="view.php?id=<?= $carId ?>" class="btn btn-outline-secondary">Cancel</a>
                    <button type="submit" class="btn btn-warning px-4">
                        <i class="fa fa-id-card me-2"></i>Assign Driver &amp; Mark In Transit
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>

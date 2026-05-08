<?php
require_once __DIR__ . '/../../includes/functions.php';
$pageTitle = 'Add Car';
$db = getDB();
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $chassis   = trim($_POST['chassis_number'] ?? '');
    $reg       = trim($_POST['registration_number'] ?? '');
    $make      = trim($_POST['make'] ?? '');
    $model     = trim($_POST['model'] ?? '');
    $year      = (int)($_POST['year'] ?? 0);
    $color     = trim($_POST['color'] ?? '');
    $engine    = trim($_POST['engine_number'] ?? '');
    $trans     = $_POST['transmission'] ?? 'manual';
    $fuel      = $_POST['fuel_type'] ?? 'petrol';
    $body      = trim($_POST['body_type'] ?? '');
    $notes     = trim($_POST['notes'] ?? '');

    if (!$chassis) $errors[] = 'Chassis number is required.';
    if (!$make)    $errors[] = 'Make is required.';
    if (!$model)   $errors[] = 'Model is required.';
    if (!$year)    $errors[] = 'Year is required.';

    if (empty($errors)) {
        try {
            $stmt = $db->prepare("INSERT INTO cars (chassis_number,registration_number,make,model,year,color,engine_number,transmission,fuel_type,body_type,notes) VALUES (?,?,?,?,?,?,?,?,?,?,?)");
            $stmt->execute([$chassis,$reg,$make,$model,$year,$color,$engine,$trans,$fuel,$body,$notes]);
            setFlash('success', "Car {$make} {$model} ({$chassis}) added successfully.");
            redirect(BASE_URL . '/modules/cars/view.php?id=' . $db->lastInsertId());
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                $errors[] = 'Chassis number already exists.';
            } else {
                $errors[] = 'Database error: ' . $e->getMessage();
            }
        }
    }
}

include __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="mb-0">Add New Car</h5>
    <a href="index.php" class="btn btn-sm btn-outline-secondary"><i class="fa fa-arrow-left me-1"></i>Back</a>
</div>

<?php if ($errors): ?>
<div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $e) echo "<li>" . htmlspecialchars($e) . "</li>"; ?></ul></div>
<?php endif; ?>

<div class="card">
    <div class="card-body">
        <form method="POST">
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label">Chassis Number <span class="text-danger">*</span></label>
                    <input type="text" name="chassis_number" class="form-control" value="<?= e($_POST['chassis_number'] ?? '') ?>" placeholder="e.g. JTEBT9FJ60K056783" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Registration Number</label>
                    <input type="text" name="registration_number" class="form-control" value="<?= e($_POST['registration_number'] ?? '') ?>" placeholder="e.g. KCA 123A">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Engine Number</label>
                    <input type="text" name="engine_number" class="form-control" value="<?= e($_POST['engine_number'] ?? '') ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Make <span class="text-danger">*</span></label>
                    <input type="text" name="make" class="form-control" value="<?= e($_POST['make'] ?? '') ?>" placeholder="e.g. Toyota" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Model <span class="text-danger">*</span></label>
                    <input type="text" name="model" class="form-control" value="<?= e($_POST['model'] ?? '') ?>" placeholder="e.g. Land Cruiser" required>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Year <span class="text-danger">*</span></label>
                    <input type="number" name="year" class="form-control" value="<?= e($_POST['year'] ?? date('Y')) ?>" min="1980" max="<?= date('Y')+1 ?>" required>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Color</label>
                    <input type="text" name="color" class="form-control" value="<?= e($_POST['color'] ?? '') ?>" placeholder="e.g. White">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Body Type</label>
                    <select name="body_type" class="form-select">
                        <option value="">Select...</option>
                        <?php foreach (['Saloon','SUV','Pick-Up','Van','Truck','Hatchback','Coupe','Bus','Minibus','Other'] as $bt): ?>
                        <option value="<?= $bt ?>" <?= ($_POST['body_type'] ?? '') === $bt ? 'selected' : '' ?>><?= $bt ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Transmission</label>
                    <select name="transmission" class="form-select">
                        <option value="manual" <?= ($_POST['transmission'] ?? '') === 'manual' ? 'selected' : '' ?>>Manual</option>
                        <option value="automatic" <?= ($_POST['transmission'] ?? '') === 'automatic' ? 'selected' : '' ?>>Automatic</option>
                        <option value="cvt" <?= ($_POST['transmission'] ?? '') === 'cvt' ? 'selected' : '' ?>>CVT</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Fuel Type</label>
                    <select name="fuel_type" class="form-select">
                        <?php foreach (['petrol','diesel','hybrid','electric'] as $ft): ?>
                        <option value="<?= $ft ?>" <?= ($_POST['fuel_type'] ?? '') === $ft ? 'selected' : '' ?>><?= ucfirst($ft) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12">
                    <label class="form-label">Notes</label>
                    <textarea name="notes" class="form-control" rows="2"><?= e($_POST['notes'] ?? '') ?></textarea>
                </div>
            </div>
            <div class="mt-4 d-flex gap-2">
                <button type="submit" class="btn btn-primary"><i class="fa fa-save me-1"></i>Save Car</button>
                <a href="index.php" class="btn btn-outline-secondary">Cancel</a>
            </div>
        </form>
    </div>
</div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>

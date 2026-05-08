<?php
require_once __DIR__ . '/../../includes/functions.php';
$id = (int)($_GET['id'] ?? 0);
if (!$id) redirect(BASE_URL . '/modules/cars/index.php');
$db = getDB();
$stmt = $db->prepare("SELECT * FROM cars WHERE id=?");
$stmt->execute([$id]); $car = $stmt->fetch();
if (!$car) { setFlash('error','Car not found.'); redirect(BASE_URL.'/modules/cars/index.php'); }
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = [
        'chassis_number'      => trim($_POST['chassis_number'] ?? ''),
        'registration_number' => trim($_POST['registration_number'] ?? ''),
        'make'                => trim($_POST['make'] ?? ''),
        'model'               => trim($_POST['model'] ?? ''),
        'year'                => (int)($_POST['year'] ?? 0),
        'color'               => trim($_POST['color'] ?? ''),
        'engine_number'       => trim($_POST['engine_number'] ?? ''),
        'transmission'        => $_POST['transmission'] ?? 'manual',
        'fuel_type'           => $_POST['fuel_type'] ?? 'petrol',
        'body_type'           => trim($_POST['body_type'] ?? ''),
        'status'              => $_POST['status'] ?? 'in_transit',
        'notes'               => trim($_POST['notes'] ?? ''),
    ];
    if (!$data['chassis_number']) $errors[] = 'Chassis number is required.';
    if (!$data['make'])           $errors[] = 'Make is required.';
    if (!$data['model'])          $errors[] = 'Model is required.';

    if (empty($errors)) {
        $db->prepare("UPDATE cars SET chassis_number=?,registration_number=?,make=?,model=?,year=?,color=?,engine_number=?,transmission=?,fuel_type=?,body_type=?,status=?,notes=? WHERE id=?")
           ->execute([...array_values($data), $id]);
        setFlash('success','Car updated successfully.');
        redirect(BASE_URL.'/modules/cars/view.php?id='.$id);
    }
    $car = array_merge($car, $data);
}
$pageTitle = 'Edit Car';
include __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="mb-0">Edit Car: <?= e($car['make'].' '.$car['model']) ?></h5>
    <a href="view.php?id=<?= $id ?>" class="btn btn-sm btn-outline-secondary"><i class="fa fa-arrow-left me-1"></i>Back</a>
</div>
<?php if ($errors): ?><div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $err) echo "<li>".e($err)."</li>"; ?></ul></div><?php endif; ?>
<div class="card">
    <div class="card-body">
        <form method="POST">
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label">Chassis Number <span class="text-danger">*</span></label>
                    <input type="text" name="chassis_number" class="form-control" value="<?= e($car['chassis_number']) ?>" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Registration Number</label>
                    <input type="text" name="registration_number" class="form-control" value="<?= e($car['registration_number'] ?? '') ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Engine Number</label>
                    <input type="text" name="engine_number" class="form-control" value="<?= e($car['engine_number'] ?? '') ?>">
                </div>
                <div class="col-md-4"><label class="form-label">Make <span class="text-danger">*</span></label><input type="text" name="make" class="form-control" value="<?= e($car['make']) ?>" required></div>
                <div class="col-md-4"><label class="form-label">Model <span class="text-danger">*</span></label><input type="text" name="model" class="form-control" value="<?= e($car['model']) ?>" required></div>
                <div class="col-md-2"><label class="form-label">Year</label><input type="number" name="year" class="form-control" value="<?= e($car['year']) ?>" min="1980" max="<?= date('Y')+1 ?>"></div>
                <div class="col-md-2"><label class="form-label">Color</label><input type="text" name="color" class="form-control" value="<?= e($car['color'] ?? '') ?>"></div>
                <div class="col-md-3">
                    <label class="form-label">Body Type</label>
                    <select name="body_type" class="form-select">
                        <option value="">Select...</option>
                        <?php foreach (['Saloon','SUV','Pick-Up','Van','Truck','Hatchback','Coupe','Bus','Minibus','Other'] as $bt): ?>
                        <option value="<?= $bt ?>" <?= $car['body_type']===$bt?'selected':'' ?>><?= $bt ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Transmission</label>
                    <select name="transmission" class="form-select">
                        <?php foreach (['manual','automatic','cvt'] as $t): ?>
                        <option value="<?= $t ?>" <?= $car['transmission']===$t?'selected':'' ?>><?= ucfirst($t) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Fuel Type</label>
                    <select name="fuel_type" class="form-select">
                        <?php foreach (['petrol','diesel','hybrid','electric'] as $f): ?>
                        <option value="<?= $f ?>" <?= $car['fuel_type']===$f?'selected':'' ?>><?= ucfirst($f) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Status</label>
                    <select name="status" class="form-select">
                        <?php foreach (['in_transit','arrived','in_assessment','in_workshop','completed','delivered'] as $s): ?>
                        <option value="<?= $s ?>" <?= $car['status']===$s?'selected':'' ?>><?= ucwords(str_replace('_',' ',$s)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12"><label class="form-label">Notes</label><textarea name="notes" class="form-control" rows="2"><?= e($car['notes'] ?? '') ?></textarea></div>
            </div>
            <div class="mt-4 d-flex gap-2">
                <button type="submit" class="btn btn-primary"><i class="fa fa-save me-1"></i>Update Car</button>
                <a href="view.php?id=<?= $id ?>" class="btn btn-outline-secondary">Cancel</a>
            </div>
        </form>
    </div>
</div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>

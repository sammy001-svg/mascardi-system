<?php
require_once __DIR__ . '/../../includes/functions.php';
requireLogin(); requireRole('admin');
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
        'car_type'            => $_POST['car_type'] ?? 'inventory',
        'owner_name'          => trim($_POST['owner_name'] ?? ''),
        'owner_phone'         => trim($_POST['owner_phone'] ?? ''),
        'location_id'         => (int)($_POST['location_id'] ?? 1),
        'client_id'           => $_POST['client_id'] ? (int)$_POST['client_id'] : null,
        'body_type'           => trim($_POST['body_type'] ?? ''),
        'status'              => $_POST['status'] ?? 'in_transit',
        'notes'               => trim($_POST['notes'] ?? ''),
    ];
    if (!$data['chassis_number']) $errors[] = 'Chassis number is required.';
    if (!$data['make'])           $errors[] = 'Make is required.';
    if (!$data['model'])          $errors[] = 'Model is required.';

    if (empty($errors)) {
        $db->prepare("UPDATE cars SET chassis_number=?,registration_number=?,make=?,model=?,year=?,color=?,engine_number=?,transmission=?,fuel_type=?,car_type=?,owner_name=?,owner_phone=?,location_id=?,client_id=?,body_type=?,status=?,notes=? WHERE id=?")
           ->execute([...array_values($data), $id]);
        logActivity('update', 'cars', $id, "Updated car: {$data['make']} {$data['model']} ({$data['chassis_number']})");
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
                <div class="col-md-3">
                    <label class="form-label">Vehicle Type <span class="text-danger">*</span></label>
                    <select name="car_type" id="car_type" class="form-select" required>
                        <option value="inventory" <?= ($car['car_type'] ?? 'inventory') === 'inventory' ? 'selected' : '' ?>>Inventory (Imported)</option>
                        <option value="client" <?= ($car['car_type'] ?? '') === 'client' ? 'selected' : '' ?>>Client (Repair/Service)</option>
                    </select>
                </div>
                <div class="col-md-4 owner-fields" style="<?= ($car['car_type'] ?? '') === 'client' ? '' : 'display:none' ?>">
                    <label class="form-label">Owner Name <span class="text-danger">*</span></label>
                    <input type="text" name="owner_name" class="form-control" value="<?= e($car['owner_name'] ?? '') ?>" placeholder="Customer Name">
                </div>
                <div class="col-md-4 owner-fields" style="<?= ($car['car_type'] ?? '') === 'client' ? '' : 'display:none' ?>">
                    <label class="form-label">Owner Phone</label>
                    <input type="text" name="owner_phone" class="form-control" value="<?= e($car['owner_phone'] ?? '') ?>" placeholder="Customer Phone">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Client Account <small class="text-muted">(for portal access)</small></label>
                    <select name="client_id" id="client_id" class="form-select select2">
                        <option value="">— No account —</option>
                        <?php 
                        $clients = $db->query("SELECT id, name, phone, email FROM clients WHERE status='active' ORDER BY name ASC")->fetchAll();
                        foreach ($clients as $cl): ?>
                        <option value="<?= $cl['id'] ?>" data-name="<?= e($cl['name']) ?>" data-phone="<?= e($cl['phone']) ?>" <?= (int)($car['client_id'] ?? 0) === (int)$cl['id'] ? 'selected' : '' ?>>
                            <?= e($cl['name']) ?><?= $cl['phone'] ? ' (' . e($cl['phone']) . ')' : '' ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Current Location <span class="text-danger">*</span></label>
                    <select name="location_id" class="form-select" required>
                        <?php 
                        $locs = $db->query("SELECT id, name FROM locations WHERE status='active' OR id = " . (int)($car['location_id'] ?? 0) . " ORDER BY name ASC")->fetchAll();
                        foreach ($locs as $l): ?>
                        <option value="<?= $l['id'] ?>" <?= (int)($car['location_id'] ?? 0) === (int)$l['id'] ? 'selected' : '' ?>><?= e($l['name']) ?></option>
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

<script>
document.getElementById('car_type').addEventListener('change', function() {
    const isClient = this.value === 'client';
    document.querySelectorAll('.owner-fields').forEach(el => {
        el.style.display = isClient ? 'block' : 'none';
        const input = el.querySelector('input');
        if (input) input.required = isClient && input.name === 'owner_name';
    });
});
document.getElementById('client_id').addEventListener('change', function() {
    const opt = this.options[this.selectedIndex];
    if (opt.value && document.getElementById('car_type').value === 'client') {
        document.getElementsByName('owner_name')[0].value = opt.getAttribute('data-name');
        document.getElementsByName('owner_phone')[0].value = opt.getAttribute('data-phone');
    }
});
</script>
<?php include __DIR__ . '/../../includes/footer.php'; ?>

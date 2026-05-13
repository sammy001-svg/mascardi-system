<?php
require_once __DIR__ . '/../../includes/functions.php';
$pageTitle = 'New Mombasa Intake';
$db = getDB();
$errors = [];
$preCarId = (int)($_GET['car_id'] ?? 0);

$cars    = $db->query("SELECT id, chassis_number, make, model, year FROM cars ORDER BY make, model")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $carId       = (int)($_POST['car_id'] ?? 0);
    $intakeDate  = $_POST['intake_date'] ?? '';
    $port        = trim($_POST['port'] ?? 'Mombasa Port');
    $shipping    = trim($_POST['shipping_line'] ?? '');
    $bl          = trim($_POST['bill_of_lading'] ?? '');
    $container   = trim($_POST['container_number'] ?? '');
    $agent       = trim($_POST['clearing_agent'] ?? '');
    $condition   = $_POST['condition_on_arrival'] ?? 'good';
    $condNotes   = trim($_POST['condition_notes'] ?? '');

    // Transfer fields
    $transportedBy = trim($_POST['transported_by'] ?? '');
    $depDate       = $_POST['departure_date'] ?: null;
    $estArr        = $_POST['estimated_arrival'] ?: null;
    $depCond       = trim($_POST['departure_condition'] ?? '');
    $depMileage    = $_POST['departure_mileage'] ? (int)$_POST['departure_mileage'] : null;

    if (!$carId)     $errors[] = 'Please select a car.';
    if (!$intakeDate) $errors[] = 'Intake date is required.';

    if (empty($errors)) {
        $db->beginTransaction();
        try {
            // Save intake
            $db->prepare("INSERT INTO car_intake (car_id,intake_date,port,shipping_line,bill_of_lading,container_number,clearing_agent,condition_on_arrival,condition_notes) VALUES (?,?,?,?,?,?,?,?,?)")
               ->execute([$carId,$intakeDate,$port,$shipping,$bl,$container,$agent,$condition,$condNotes]);

            // Save transfer if departure date set
            if ($depDate) {
                $db->prepare("INSERT INTO car_transfers (car_id,transported_by,departure_date,estimated_arrival,from_location,to_location,departure_condition,departure_mileage,status) VALUES (?,?,?,?,'Mombasa','Nairobi',?,?,'in_transit')")
                   ->execute([$carId,$transportedBy,$depDate,$estArr,$depCond,$depMileage]);
                $db->prepare("UPDATE cars SET status='in_transit' WHERE id=?")->execute([$carId]);
            } else {
                $db->prepare("UPDATE cars SET status='arrived' WHERE id=?")->execute([$carId]);
            }

            $db->commit();
            setFlash('success','Intake record saved successfully.');
            redirect(BASE_URL.'/modules/intake/index.php');
        } catch (Exception $e) {
            $db->rollBack();
            $errors[] = 'Error: ' . $e->getMessage();
        }
    }
}

include __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="mb-0">New Mombasa Intake</h5>
    <a href="index.php" class="btn btn-sm btn-outline-secondary"><i class="fa fa-arrow-left me-1"></i>Back</a>
</div>
<?php if ($errors): ?><div class="alert alert-danger"><ul class="mb-0"><?php foreach($errors as $err) echo "<li>".e($err)."</li>"; ?></ul></div><?php endif; ?>

<form method="POST">
<div class="row g-4">
    <!-- Intake Details -->
    <div class="col-lg-6">
        <div class="card">
            <div class="card-header"><i class="fa fa-anchor me-2"></i>Port Arrival Details</div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-12">
                        <label class="form-label">Vehicle <span class="text-danger">*</span></label>
                        <select name="car_id" class="form-select select2" required>
                            <option value="">Select car...</option>
                            <?php foreach ($cars as $c): ?>
                            <option value="<?= $c['id'] ?>" <?= (($_POST['car_id']??$preCarId)==$c['id'])?'selected':'' ?>>
                                <?= e($c['make'].' '.$c['model'].' '.$c['year'].' — '.$c['chassis_number']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Intake Date <span class="text-danger">*</span></label>
                        <input type="date" name="intake_date" class="form-control" value="<?= e($_POST['intake_date']??date('Y-m-d')) ?>" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Port</label>
                        <input type="text" name="port" class="form-control" value="<?= e($_POST['port']??'Mombasa Port') ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Shipping Line</label>
                        <input type="text" name="shipping_line" class="form-control" value="<?= e($_POST['shipping_line']??'') ?>" placeholder="e.g. MSC, Maersk">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Bill of Lading No.</label>
                        <input type="text" name="bill_of_lading" class="form-control" value="<?= e($_POST['bill_of_lading']??'') ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Container Number</label>
                        <input type="text" name="container_number" class="form-control" value="<?= e($_POST['container_number']??'') ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Clearing Agent</label>
                        <input type="text" name="clearing_agent" class="form-control" value="<?= e($_POST['clearing_agent']??'') ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Condition on Arrival</label>
                        <select name="condition_on_arrival" class="form-select">
                            <?php foreach (['excellent','good','fair','poor','damaged'] as $c): ?>
                            <option value="<?= $c ?>" <?= ($_POST['condition_on_arrival']??'good')===$c?'selected':'' ?>><?= ucfirst($c) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12">
                        <label class="form-label">Condition Notes</label>
                        <textarea name="condition_notes" class="form-control" rows="3" placeholder="Describe any visible damage, marks, scratches..."><?= e($_POST['condition_notes']??'') ?></textarea>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Transfer to Nairobi -->
    <div class="col-lg-6">
        <div class="card">
            <div class="card-header"><i class="fa fa-truck-moving me-2"></i>Transfer to Nairobi (Optional)</div>
            <div class="card-body">
                <p class="text-muted small mb-3">Fill in transport details to record departure immediately. Leave blank if not dispatching yet.</p>
                <div class="row g-3">
                    <div class="col-12">
                        <label class="form-label">Transported By</label>
                        <input type="text" name="transported_by" class="form-control" placeholder="Transporter name" value="<?= e($_POST['transported_by']??'') ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Departure Date/Time</label>
                        <input type="datetime-local" name="departure_date" class="form-control" value="<?= e($_POST['departure_date']??'') ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Estimated Arrival</label>
                        <input type="datetime-local" name="estimated_arrival" class="form-control" value="<?= e($_POST['estimated_arrival']??'') ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Departure Mileage (km)</label>
                        <input type="number" name="departure_mileage" class="form-control" value="<?= e($_POST['departure_mileage']??'') ?>">
                    </div>
                    <div class="col-12">
                        <label class="form-label">Condition at Departure</label>
                        <textarea name="departure_condition" class="form-control" rows="3" placeholder="Record car condition before dispatch..."><?= e($_POST['departure_condition']??'') ?></textarea>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="mt-4 d-flex gap-2">
    <button type="submit" class="btn btn-primary"><i class="fa fa-save me-1"></i>Save Intake Record</button>
    <a href="index.php" class="btn btn-outline-secondary">Cancel</a>
</div>
</form>
<?php include __DIR__ . '/../../includes/footer.php'; ?>

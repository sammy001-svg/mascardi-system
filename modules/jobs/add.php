<?php
require_once __DIR__ . '/../../includes/functions.php';
$pageTitle = 'Create Job Card';
$db = getDB();
$errors = [];
$preCarId       = (int)($_GET['car_id'] ?? 0);
$preAssessId    = (int)($_GET['assessment_id'] ?? 0);

$cars      = $db->query("SELECT id, chassis_number, make, model, year FROM cars ORDER BY make,model")->fetchAll();
$mechanics = $db->query("SELECT id, name FROM mechanics WHERE status='active' ORDER BY name")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $carId    = (int)($_POST['car_id'] ?? 0);
    $mechId   = $_POST['mechanic_id'] ? (int)$_POST['mechanic_id'] : null;
    $assessId = $_POST['assessment_id'] ? (int)$_POST['assessment_id'] : null;
    $start    = $_POST['start_date'] ?: null;
    $end      = $_POST['end_date'] ?: null;
    $status   = $_POST['status'] ?? 'pending';
    $priority = $_POST['priority'] ?? 'normal';
    $desc     = trim($_POST['description'] ?? '');
    $notes    = trim($_POST['notes'] ?? '');

    if (!$carId) $errors[] = 'Please select a car.';

    if (empty($errors)) {
        $jobNumber = nextNumber('workshop_jobs', 'job_number', getSetting('job_prefix','JOB'));
        $db->prepare("INSERT INTO workshop_jobs (job_number,car_id,mechanic_id,assessment_id,start_date,end_date,status,priority,description,notes) VALUES (?,?,?,?,?,?,?,?,?,?)")
           ->execute([$jobNumber,$carId,$mechId,$assessId,$start,$end,$status,$priority,$desc,$notes]);
        $jobId = $db->lastInsertId();
        $db->prepare("UPDATE cars SET status='in_workshop' WHERE id=?")->execute([$carId]);
        setFlash('success',"Job card {$jobNumber} created.");
        redirect(BASE_URL.'/modules/jobs/view.php?id='.$jobId);
    }
}
include __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="mb-0">Create Job Card</h5>
    <a href="index.php" class="btn btn-sm btn-outline-secondary"><i class="fa fa-arrow-left me-1"></i>Back</a>
</div>
<?php if ($errors): ?><div class="alert alert-danger"><ul class="mb-0"><?php foreach($errors as $err) echo "<li>".e($err)."</li>"; ?></ul></div><?php endif; ?>
<div class="card"><div class="card-body">
    <form method="POST">
        <div class="row g-3">
            <div class="col-md-5">
                <label class="form-label">Vehicle <span class="text-danger">*</span></label>
                <select name="car_id" class="form-select select2" required>
                    <option value="">Select car...</option>
                    <?php foreach ($cars as $c): ?>
                    <option value="<?= $c['id'] ?>" <?= (($_POST['car_id']??$preCarId)==$c['id'])?'selected':'' ?>><?= e($c['make'].' '.$c['model'].' '.$c['year'].' — '.$c['chassis_number']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">Assign Mechanic</label>
                <select name="mechanic_id" class="form-select select2">
                    <option value="">Select mechanic...</option>
                    <?php foreach ($mechanics as $m): ?>
                    <option value="<?= $m['id'] ?>" <?= ($_POST['mechanic_id']??'')==$m['id']?'selected':'' ?>><?= e($m['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">Priority</label>
                <select name="priority" class="form-select">
                    <?php foreach (['low','normal','high','urgent'] as $p): ?>
                    <option value="<?= $p ?>" <?= ($_POST['priority']??'normal')===$p?'selected':'' ?>><?= ucfirst($p) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">Status</label>
                <select name="status" class="form-select">
                    <?php foreach (['pending','in_progress','waiting_parts','on_hold','completed','cancelled'] as $s): ?>
                    <option value="<?= $s ?>" <?= ($_POST['status']??'pending')===$s?'selected':'' ?>><?= ucwords(str_replace('_',' ',$s)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">Start Date</label>
                <input type="date" name="start_date" class="form-control" value="<?= e($_POST['start_date']??date('Y-m-d')) ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label">Expected End Date</label>
                <input type="date" name="end_date" class="form-control" value="<?= e($_POST['end_date']??'') ?>">
            </div>
            <?php if ($preAssessId): ?><input type="hidden" name="assessment_id" value="<?= $preAssessId ?>"><?php endif; ?>
            <div class="col-12">
                <label class="form-label">Work Description</label>
                <textarea name="description" class="form-control" rows="3" placeholder="Describe work to be done..."><?= e($_POST['description']??'') ?></textarea>
            </div>
            <div class="col-12">
                <label class="form-label">Internal Notes</label>
                <textarea name="notes" class="form-control" rows="2"><?= e($_POST['notes']??'') ?></textarea>
            </div>
        </div>
        <div class="mt-4 d-flex gap-2">
            <button type="submit" class="btn btn-primary"><i class="fa fa-save me-1"></i>Create Job Card</button>
            <a href="index.php" class="btn btn-outline-secondary">Cancel</a>
        </div>
    </form>
</div></div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>

<?php
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
canAccess('jobs') || die('Access denied.');
canWrite('jobs') || die('Permission denied.');
$pageTitle = 'Create Job Card';
$db = getDB();
$errors = [];
$preCarId       = (int)($_GET['car_id'] ?? 0);
$preAssessId    = (int)($_GET['assessment_id'] ?? 0);

$mechanics = $db->query("SELECT id, name FROM mechanics WHERE status='active' ORDER BY name")->fetchAll();

// ── Auto-populate from assessment when launched from assessment view ──────────
// If we arrive here with an assessment_id, pre-fill the form with data from
// the assessment so the workshop manager does not have to transcribe what was
// already documented in the checklist.
$preAssessData    = null;
$preDescription   = '';
$preMechanicId    = 0;
if ($preAssessId) {
    try {
        $astStmt = $db->prepare("
            SELECT ca.*, m.id AS mech_id
            FROM car_assessments ca
            LEFT JOIN mechanics m ON m.id = ca.mechanic_id
            WHERE ca.id = ?
        ");
        $astStmt->execute([$preAssessId]);
        $preAssessData = $astStmt->fetch(PDO::FETCH_ASSOC);

        if ($preAssessData) {
            $preMechanicId = (int)($preAssessData['mech_id'] ?? 0);

            // Build the description from all non-good assessment items
            $issStmt = $db->prepare("
                SELECT part_category, part_name, condition, notes
                FROM assessment_items
                WHERE assessment_id = ? AND condition != 'good'
                ORDER BY part_category, part_name
            ");
            $issStmt->execute([$preAssessId]);
            $issues = $issStmt->fetchAll(PDO::FETCH_ASSOC);

            if ($issues) {
                $condLabels = [
                    'minor_damage'  => 'Minor Damage',
                    'major_damage'  => 'Major Damage',
                    'missing'       => 'Missing',
                    'needs_service' => 'Needs Service',
                ];
                $lines = ['Issues identified during assessment:'];
                $currentCat = '';
                foreach ($issues as $iss) {
                    if ($iss['part_category'] !== $currentCat) {
                        $currentCat = $iss['part_category'];
                        $lines[] = '';
                        $lines[] = '[' . $currentCat . ']';
                    }
                    $condText = $condLabels[$iss['condition']] ?? ucfirst(str_replace('_', ' ', $iss['condition']));
                    $line = '- ' . $iss['part_name'] . ' (' . $condText . ')';
                    if (!empty($iss['notes'])) {
                        $line .= ': ' . $iss['notes'];
                    }
                    $lines[] = $line;
                }
                $preDescription = implode("\n", $lines);
            } elseif ($preAssessData['notes']) {
                $preDescription = $preAssessData['notes'];
            }
        }
    } catch (\Throwable $_) {}
}

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
        try {
            $jobNumber = nextNumber('workshop_jobs', 'job_number', getSetting('job_prefix','JOB'));
            $db->prepare("INSERT INTO workshop_jobs (job_number,car_id,mechanic_id,assessment_id,start_date,end_date,status,priority,description,notes) VALUES (?,?,?,?,?,?,?,?,?,?)")
               ->execute([$jobNumber,$carId,$mechId,$assessId,$start,$end,$status,$priority,$desc,$notes]);
            $jobId = $db->lastInsertId();
            $db->prepare("UPDATE cars SET status='in_workshop' WHERE id=?")->execute([$carId]);
            logActivity('create', 'jobs', $jobId, "Created job card {$jobNumber}");
            setFlash('success',"Job card {$jobNumber} created.");
            redirect(BASE_URL.'/modules/jobs/view.php?id='.$jobId);
        } catch (\PDOException $e) {
            $errors[] = "Database error: " . $e->getMessage();
        }
    }
}
include __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="mb-0">Create Job Card</h5>
    <a href="index.php" class="btn btn-sm btn-outline-secondary"><i class="fa fa-arrow-left me-1"></i>Back</a>
</div>
<?php if (!empty($errors)): ?><div class="alert alert-danger"><ul class="mb-0"><?php foreach($errors as $err) echo "<li>".e($err)."</li>"; ?></ul></div><?php endif; ?>
<div class="card"><div class="card-body">
    <form method="POST">
        <div class="row g-3">
            <div class="col-md-5">
                <label class="form-label">Vehicle <span class="text-danger">*</span></label>
                <select name="car_id" class="form-select select2" required>
                    <option value="">Select car...</option>
                    <?php 
                    $cars = $db->query("SELECT id, chassis_number, make, model, year, car_type, owner_name FROM cars ORDER BY make,model")->fetchAll();
                    foreach ($cars as $c): ?>
                    <option value="<?= $c['id'] ?>" <?= (($_POST['car_id']??$preCarId)==$c['id'])?'selected':'' ?>>
                        <?= e($c['make'].' '.$c['model'].' '.$c['year']) ?> 
                        <?= $c['car_type']==='client' ? ' — [CLIENT: '.e($c['owner_name']).']' : '' ?>
                        — <?= e($c['chassis_number']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">Assign Mechanic</label>
                <select name="mechanic_id" class="form-select select2">
                    <option value="">Select mechanic...</option>
                    <?php foreach ($mechanics as $m): ?>
                    <option value="<?= $m['id'] ?>" <?= ($_POST['mechanic_id'] ?? $preMechanicId) == $m['id'] ? 'selected' : '' ?>><?= e($m['name']) ?></option>
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
                <textarea name="description" class="form-control" rows="4" placeholder="Describe work to be done..."><?= e($_POST['description'] ?? $preDescription) ?></textarea>
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

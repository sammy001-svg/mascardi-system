<?php
require_once __DIR__ . '/../../includes/functions.php';
$pageTitle = 'New Assessment';
$db = getDB();
$errors = [];
$preCarId = (int)($_GET['car_id'] ?? 0);

$cars      = $db->query("SELECT id, chassis_number, make, model, year FROM cars ORDER BY make, model")->fetchAll();
$mechanics = $db->query("SELECT id, name FROM mechanics WHERE status='active' ORDER BY name")->fetchAll();
$partsList = getPartsList();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $carId      = (int)($_POST['car_id'] ?? 0);
    $mechId     = $_POST['mechanic_id'] ? (int)$_POST['mechanic_id'] : null;
    $date       = $_POST['assessment_date'] ?? '';
    $type       = $_POST['assessment_type'] ?? 'arrival';
    $status     = $_POST['overall_status'] ?? 'fair';
    $mileage    = $_POST['mileage'] ? (int)$_POST['mileage'] : null;
    $fuel       = $_POST['fuel_level'] ?? 'half';
    $notes      = trim($_POST['notes'] ?? '');

    if (!$carId) $errors[] = 'Please select a car.';
    if (!$date)  $errors[] = 'Assessment date is required.';

    if (empty($errors)) {
        $db->beginTransaction();
        try {
            $db->prepare("INSERT INTO car_assessments (car_id,mechanic_id,assessment_date,assessment_type,overall_status,mileage,fuel_level,notes) VALUES (?,?,?,?,?,?,?,?)")
               ->execute([$carId,$mechId,$date,$type,$status,$mileage,$fuel,$notes]);
            $assessmentId = $db->lastInsertId();

            // Save part conditions
            $parts   = $_POST['part_name'] ?? [];
            $conds   = $_POST['part_condition'] ?? [];
            $pnotes  = $_POST['part_notes'] ?? [];
            $cats    = $_POST['part_category'] ?? [];

            $insStmt = $db->prepare("INSERT INTO assessment_items (assessment_id,part_category,part_name,condition,notes) VALUES (?,?,?,?,?)");
            foreach ($parts as $idx => $pname) {
                if (!$pname) continue;
                $cond = $conds[$idx] ?? 'good';
                $pnote = $pnotes[$idx] ?? '';
                $cat   = $cats[$idx] ?? '';
                $insStmt->execute([$assessmentId, $cat, $pname, $cond, $pnote]);
            }

            // Update car status
            $db->prepare("UPDATE cars SET status='in_assessment' WHERE id=?")->execute([$carId]);
            $db->commit();
            setFlash('success', 'Assessment saved successfully.');
            redirect(BASE_URL.'/modules/assessments/view.php?id='.$assessmentId);
        } catch (Exception $e) {
            $db->rollBack();
            $errors[] = 'Error: ' . $e->getMessage();
        }
    }
}
include __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="mb-0">New Car Assessment</h5>
    <a href="index.php" class="btn btn-sm btn-outline-secondary"><i class="fa fa-arrow-left me-1"></i>Back</a>
</div>
<?php if ($errors): ?><div class="alert alert-danger"><ul class="mb-0"><?php foreach($errors as $err) echo "<li>".e($err)."</li>"; ?></ul></div><?php endif; ?>

<form method="POST">
<!-- Header info -->
<div class="card mb-4">
    <div class="card-header"><i class="fa fa-clipboard-check me-2"></i>Assessment Details</div>
    <div class="card-body">
        <div class="row g-3">
            <div class="col-md-4">
                <label class="form-label">Vehicle <span class="text-danger">*</span></label>
                <select name="car_id" class="form-select select2" required>
                    <option value="">Select car...</option>
                    <?php foreach ($cars as $c): ?>
                    <option value="<?= $c['id'] ?>" <?= (($_POST['car_id']??$preCarId)==$c['id'])?'selected':'' ?>><?= e($c['make'].' '.$c['model'].' '.$c['year'].' — '.$c['chassis_number']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">Mechanic</label>
                <select name="mechanic_id" class="form-select select2">
                    <option value="">Select mechanic...</option>
                    <?php foreach ($mechanics as $m): ?>
                    <option value="<?= $m['id'] ?>" <?= ($_POST['mechanic_id']??'')==$m['id']?'selected':'' ?>><?= e($m['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">Date <span class="text-danger">*</span></label>
                <input type="date" name="assessment_date" class="form-control" value="<?= e($_POST['assessment_date']??date('Y-m-d')) ?>" required>
            </div>
            <div class="col-md-3">
                <label class="form-label">Assessment Type</label>
                <select name="assessment_type" class="form-select">
                    <option value="arrival" <?= ($_POST['assessment_type']??'arrival')==='arrival'?'selected':'' ?>>Arrival Assessment</option>
                    <option value="workshop" <?= ($_POST['assessment_type']??'')==='workshop'?'selected':'' ?>>Workshop Assessment</option>
                    <option value="pre_delivery" <?= ($_POST['assessment_type']??'')==='pre_delivery'?'selected':'' ?>>Pre-Delivery Check</option>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">Overall Status</label>
                <select name="overall_status" class="form-select">
                    <?php foreach (['excellent','good','fair','poor','critical'] as $s): ?>
                    <option value="<?= $s ?>" <?= ($_POST['overall_status']??'fair')===$s?'selected':'' ?>><?= ucfirst($s) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">Mileage (km)</label>
                <input type="number" name="mileage" class="form-control" value="<?= e($_POST['mileage']??'') ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label">Fuel Level</label>
                <select name="fuel_level" class="form-select">
                    <?php foreach (['empty','quarter','half','three_quarter','full'] as $f): ?>
                    <option value="<?= $f ?>" <?= ($_POST['fuel_level']??'half')===$f?'selected':'' ?>><?= ucwords(str_replace('_',' ',$f)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label">General Notes</label>
                <textarea name="notes" class="form-control" rows="2"><?= e($_POST['notes']??'') ?></textarea>
            </div>
        </div>
    </div>
</div>

<!-- Parts checklist -->
<div class="card mb-4">
    <div class="card-header"><i class="fa fa-list-check me-2"></i>Parts Condition Checklist</div>
    <div class="card-body">
        <div class="parts-grid">
            <?php
            $rowIndex = 0;
            foreach ($partsList as $category => $parts):
            ?>
            <div class="parts-category">
                <div class="category-title"><?= e($category) ?></div>
                <?php foreach ($parts as $part): ?>
                <div class="part-row">
                    <input type="hidden" name="part_name[]" value="<?= e($part) ?>">
                    <input type="hidden" name="part_category[]" value="<?= e($category) ?>">
                    <span style="flex:1"><?= e($part) ?></span>
                    <div class="d-flex gap-1 align-items-center">
                        <select name="part_condition[]" class="form-select form-select-sm" style="width:140px">
                            <option value="good">Good</option>
                            <option value="minor_damage">Minor Damage</option>
                            <option value="major_damage">Major Damage</option>
                            <option value="missing">Missing</option>
                            <option value="needs_service">Needs Service</option>
                        </select>
                        <input type="text" name="part_notes[]" class="form-control form-control-sm" style="width:120px" placeholder="Notes...">
                    </div>
                </div>
                <?php $rowIndex++; endforeach; ?>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<div class="d-flex gap-2">
    <button type="submit" class="btn btn-primary"><i class="fa fa-save me-1"></i>Save Assessment</button>
    <a href="index.php" class="btn btn-outline-secondary">Cancel</a>
</div>
</form>
<?php include __DIR__ . '/../../includes/footer.php'; ?>

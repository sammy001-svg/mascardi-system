<?php
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
canAccess('assessments') || die('Access denied.');
canWrite('assessments') || die('Permission denied.');

$id = (int)($_GET['id'] ?? 0);
if (!$id) redirect(BASE_URL . '/modules/assessments/index.php');
$db   = getDB();
$user = authUser();

$assessment = $db->prepare("SELECT ca.*, c.make, c.model, c.year, c.chassis_number FROM car_assessments ca JOIN cars c ON c.id=ca.car_id WHERE ca.id=?");
$assessment->execute([$id]); $assessment = $assessment->fetch();
if (!$assessment) { setFlash('error', 'Assessment not found.'); redirect(BASE_URL . '/modules/assessments/index.php'); }

$existingItems = $db->prepare("SELECT * FROM assessment_items WHERE assessment_id=?");
$existingItems->execute([$id]); $existingItems = $existingItems->fetchAll();

// Build lookup: part_name → item row (preserves resolution data)
$itemMap = [];
foreach ($existingItems as $item) {
    $itemMap[$item['part_name']] = $item;
}

$cars      = $db->query("SELECT id, chassis_number, make, model, year, car_type, owner_name FROM cars WHERE status NOT IN ('delivered') ORDER BY make, model")->fetchAll();
$mechanics = $db->query("SELECT id, name FROM mechanics WHERE status='active' ORDER BY name")->fetchAll();
$partsList = getPartsList();

$allowedTypes = ['arrival', 'pre_delivery', 'client_service', 'yard'];
$typesMeta    = [
    'arrival'        => ['Vehicle Intake Protocol',   'fa-anchor',         '#0284c7'],
    'pre_delivery'   => ['Pre-Delivery',              'fa-flag-checkered', '#16a34a'],
    'client_service' => ['Client Service Assessment', 'fa-user-check',     '#7c3aed'],
    'yard'           => ['Yard Assessment',           'fa-warehouse',      '#d97706'],
];

$errors = [];

// Working data — start from DB
$d = [
    'car_id'          => $assessment['car_id'],
    'mechanic_id'     => $assessment['mechanic_id'],
    'assessment_date' => $assessment['assessment_date'],
    'assessment_type' => $assessment['assessment_type'],
    'overall_status'  => $assessment['overall_status'],
    'mileage'         => $assessment['mileage'],
    'fuel_level'      => $assessment['fuel_level'],
    'notes'           => $assessment['notes'],
];

// On POST, overwrite conditions
$postConditions = [];
$postNotes      = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $d['car_id']          = (int)($_POST['car_id'] ?? $assessment['car_id']);
    $d['mechanic_id']     = $_POST['mechanic_id'] ? (int)$_POST['mechanic_id'] : null;
    $d['assessment_date'] = $_POST['assessment_date'] ?? $assessment['assessment_date'];
    $d['assessment_type'] = in_array($_POST['assessment_type'] ?? '', $allowedTypes)
                            ? $_POST['assessment_type'] : $assessment['assessment_type'];
    $d['overall_status']  = $_POST['overall_status'] ?? $assessment['overall_status'];
    $d['mileage']         = $_POST['mileage'] ? (int)$_POST['mileage'] : null;
    $d['fuel_level']      = $_POST['fuel_level'] ?? $assessment['fuel_level'];
    $d['notes']           = trim($_POST['notes'] ?? '');

    $partNames      = $_POST['part_name']      ?? [];
    $postConditions = $_POST['part_condition'] ?? [];
    $partNotes      = $_POST['part_notes']     ?? [];
    $partCats       = $_POST['part_category']  ?? [];

    if (!$d['assessment_date']) $errors[] = 'Assessment date is required.';

    if (empty($errors)) {
        $db->beginTransaction();
        try {
            $db->prepare("UPDATE car_assessments SET
                mechanic_id=?, assessment_date=?, assessment_type=?,
                overall_status=?, mileage=?, fuel_level=?, notes=?
                WHERE id=?")
               ->execute([
                   $d['mechanic_id'], $d['assessment_date'], $d['assessment_type'],
                   $d['overall_status'], $d['mileage'], $d['fuel_level'], $d['notes'],
                   $id,
               ]);

            $updateStmt = $db->prepare("UPDATE assessment_items SET `condition`=?, notes=? WHERE id=?");
            $insertStmt = $db->prepare("INSERT INTO assessment_items (assessment_id, part_category, part_name, `condition`, notes) VALUES (?,?,?,?,?)");

            foreach ($partNames as $idx => $pname) {
                if (!$pname) continue;
                $cond  = $postConditions[$idx] ?? 'good';
                $pnote = $partNotes[$idx] ?? '';
                $cat   = $partCats[$idx] ?? '';

                if (isset($itemMap[$pname])) {
                    // Update existing — preserve resolution fields
                    $updateStmt->execute([$cond, $pnote, $itemMap[$pname]['id']]);
                } else {
                    // New part not in original assessment
                    $insertStmt->execute([$id, $cat, $pname, $cond, $pnote]);
                }
            }

            $db->commit();
            logActivity('update', 'assessments', $id, "Updated assessment for {$assessment['make']} {$assessment['model']}");
            setFlash('success', 'Assessment updated successfully.');
            redirect(BASE_URL . '/modules/assessments/view.php?id=' . $id);
        } catch (\Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            $errors[] = 'Save failed: ' . $e->getMessage();
        }
    }
}

// Build condition lookups for render (POST overrides DB)
$condMap = []; // part_name => condition
$noteMap = []; // part_name => note
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $partNames = $_POST['part_name'] ?? [];
    foreach ($partNames as $idx => $pname) {
        $condMap[$pname] = $_POST['part_condition'][$idx] ?? 'good';
        $noteMap[$pname] = $_POST['part_notes'][$idx] ?? '';
    }
} else {
    foreach ($existingItems as $item) {
        $condMap[$item['part_name']] = $item['condition'];
        $noteMap[$item['part_name']] = $item['notes'] ?? '';
    }
}

// Count parts for JS
$allPartsFlat = [];
foreach ($partsList as $parts) {
    foreach ($parts as $p) $allPartsFlat[] = $p;
}
$totalParts = count($allPartsFlat);

$catIcons = [
    'Exterior'            => 'fa-car-side',
    'Lighting'            => 'fa-lightbulb',
    'Wheels & Tyres'      => 'fa-circle-dot',
    'Interior'            => 'fa-couch',
    'Electronics'         => 'fa-microchip',
    'Engine & Mechanical' => 'fa-gears',
    'Documents'           => 'fa-file-lines',
];

$pageTitle = 'Edit Assessment — ' . $assessment['make'] . ' ' . $assessment['model'];
include __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h5 class="mb-1"><i class="fa fa-pen me-2 text-primary"></i>Edit Assessment</h5>
        <div class="text-muted small">
            <?= e($assessment['make'].' '.$assessment['model'].' '.$assessment['year']) ?>
            &mdash; <code><?= e($assessment['chassis_number']) ?></code>
        </div>
    </div>
    <a href="view.php?id=<?= $id ?>" class="btn btn-sm btn-outline-secondary"><i class="fa fa-arrow-left me-1"></i>Back</a>
</div>

<?php if ($errors): ?>
<div class="alert alert-danger mb-3">
    <?php foreach ($errors as $e) echo '<div><i class="fa fa-circle-exclamation me-1"></i>'.e($e).'</div>'; ?>
</div>
<?php endif; ?>

<form method="POST" id="assessmentForm">

<!-- Vehicle & Assessment Details -->
<div class="card mb-4">
    <div class="card-header"><i class="fa fa-car me-2"></i>Assessment Details</div>
    <div class="card-body">
        <div class="row g-3">
            <div class="col-md-5">
                <label class="form-label">Vehicle</label>
                <input type="hidden" name="car_id" value="<?= $assessment['car_id'] ?>">
                <?php
                $carLabel = $assessment['make'].' '.$assessment['model'].' '.$assessment['year'].' — '.$assessment['chassis_number'];
                ?>
                <input type="text" class="form-control" value="<?= e($carLabel) ?>" disabled>
                <div class="form-text text-muted small">Vehicle cannot be changed after assessment is created.</div>
            </div>

            <div class="col-md-4">
                <label class="form-label">Assessed By (Mechanic)</label>
                <select name="mechanic_id" class="form-select select2">
                    <option value="">— Select mechanic —</option>
                    <?php foreach ($mechanics as $m): ?>
                    <option value="<?= $m['id'] ?>" <?= $d['mechanic_id'] == $m['id'] ? 'selected' : '' ?>><?= e($m['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-3">
                <label class="form-label">Date <span class="text-danger">*</span></label>
                <input type="date" name="assessment_date" class="form-control" value="<?= e($d['assessment_date']) ?>" required>
            </div>

            <!-- Assessment Type -->
            <div class="col-md-6">
                <label class="form-label">Assessment Type</label>
                <div class="d-flex gap-2 flex-wrap">
                    <?php foreach ($allowedTypes as $val):
                        [$lbl, $icon, $color] = $typesMeta[$val];
                    ?>
                    <div class="type-option">
                        <input type="radio" name="assessment_type" id="type_<?= $val ?>" value="<?= $val ?>"
                               class="type-radio" <?= $d['assessment_type'] === $val ? 'checked' : '' ?>>
                        <label for="type_<?= $val ?>" class="type-btn" style="--type-color:<?= $color ?>">
                            <i class="fa <?= $icon ?>"></i> <?= $lbl ?>
                        </label>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Overall Status -->
            <div class="col-md-4">
                <label class="form-label">Overall Condition</label>
                <div class="d-flex gap-2 flex-wrap">
                    <?php
                    $statuses = ['excellent'=>['#16a34a','Excellent'],'good'=>['#2563eb','Good'],'fair'=>['#d97706','Fair'],'poor'=>['#ea580c','Poor'],'critical'=>['#dc2626','Critical']];
                    foreach ($statuses as $val => [$color, $lbl]):
                    ?>
                    <div>
                        <input type="radio" name="overall_status" id="os_<?= $val ?>" value="<?= $val ?>"
                               class="os-radio" <?= $d['overall_status'] === $val ? 'checked' : '' ?>>
                        <label for="os_<?= $val ?>" class="os-btn" style="--os-color:<?= $color ?>"><?= $lbl ?></label>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="col-md-2">
                <label class="form-label">Mileage (km)</label>
                <input type="number" name="mileage" class="form-control" min="0" value="<?= e($d['mileage'] ?? '') ?>" placeholder="e.g. 45000">
            </div>

            <div class="col-md-4">
                <label class="form-label">Fuel Level</label>
                <div class="fuel-gauge">
                    <?php $fuelOpts = ['empty'=>'E','quarter'=>'¼','half'=>'½','three_quarter'=>'¾','full'=>'F'];
                    foreach ($fuelOpts as $val => $lbl): ?>
                    <input type="radio" name="fuel_level" id="fuel_<?= $val ?>" value="<?= $val ?>"
                           class="fuel-radio" <?= ($d['fuel_level'] ?? 'half') === $val ? 'checked' : '' ?>>
                    <label for="fuel_<?= $val ?>" class="fuel-btn"><?= $lbl ?></label>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="col-12">
                <label class="form-label">General Notes</label>
                <textarea name="notes" class="form-control" rows="2"><?= e($d['notes'] ?? '') ?></textarea>
            </div>
        </div>
    </div>
</div>

<!-- Parts Checklist -->
<div class="d-flex align-items-center justify-content-between mb-3">
    <h6 class="mb-0 fw-semibold"><i class="fa fa-list-check me-2 text-primary"></i>Parts Condition Checklist</h6>
    <div class="d-flex align-items-center gap-3">
        <span class="text-muted small" id="progressText">0 / <?= $totalParts ?> assessed</span>
        <span class="badge bg-danger d-none" id="globalIssuesBadge">0 issues</span>
        <button type="button" class="btn btn-sm btn-outline-success" id="markAllGoodBtn">
            <i class="fa fa-check-double me-1"></i>Mark All Good
        </button>
    </div>
</div>

<?php
$idx = 0;
foreach ($partsList as $category => $parts):
    $icon = $catIcons[$category] ?? 'fa-box';
?>
<div class="assess-category mb-3" data-category="<?= e($category) ?>">
    <div class="assess-cat-header" onclick="toggleCat(this)">
        <div class="d-flex align-items-center gap-2">
            <i class="fa fa-chevron-down assess-chevron" style="font-size:12px;transition:transform .2s"></i>
            <i class="fa <?= $icon ?> text-primary"></i>
            <span class="fw-semibold"><?= e($category) ?></span>
            <span class="badge bg-light text-dark border ms-1"><?= count($parts) ?></span>
        </div>
        <div class="d-flex align-items-center gap-2">
            <span class="badge bg-danger cat-issues-badge d-none">0 issues</span>
            <button type="button" class="btn btn-xs btn-outline-success cat-mark-good" onclick="event.stopPropagation(); markCatGood(this)">
                <i class="fa fa-check me-1"></i>All Good
            </button>
        </div>
    </div>
    <div class="assess-cat-body">
        <?php foreach ($parts as $part):
            $savedCond = $condMap[$part] ?? 'good';
            $savedNote = $noteMap[$part] ?? '';
            $isResolved = isset($itemMap[$part]) && $itemMap[$part]['is_resolved'];
        ?>
        <div class="assess-part-row" data-idx="<?= $idx ?>">
            <input type="hidden" name="part_name[<?= $idx ?>]"     value="<?= e($part) ?>">
            <input type="hidden" name="part_category[<?= $idx ?>]" value="<?= e($category) ?>">
            <span class="assess-part-name">
                <?= e($part) ?>
                <?php if ($isResolved): ?>
                <span class="badge bg-success ms-1" style="font-size:10px" title="Issue resolved"><i class="fa fa-check"></i> Resolved</span>
                <?php endif; ?>
            </span>
            <div class="cond-group" role="group">
                <?php
                $conditions = [
                    'good'          => ['fa-check',              'Good',    'cond-good'],
                    'minor_damage'  => ['fa-triangle-exclamation','Minor',   'cond-minor'],
                    'major_damage'  => ['fa-circle-xmark',       'Major',   'cond-major'],
                    'missing'       => ['fa-ban',                'Missing', 'cond-missing'],
                    'needs_service' => ['fa-wrench',             'Service', 'cond-service'],
                ];
                foreach ($conditions as $cval => [$icon2, $clbl, $cls]):
                ?>
                <input type="radio" name="part_condition[<?= $idx ?>]" id="c<?= $idx ?>_<?= $cval ?>"
                       value="<?= $cval ?>" class="cond-radio"
                       <?= $savedCond === $cval ? 'checked' : '' ?>
                       onchange="onCondChange(this)">
                <label for="c<?= $idx ?>_<?= $cval ?>" class="cond-btn <?= $cls ?>">
                    <i class="fa <?= $icon2 ?>"></i><span><?= $clbl ?></span>
                </label>
                <?php endforeach; ?>
            </div>
            <div class="assess-part-notes" style="<?= $savedCond === 'good' ? 'display:none' : '' ?>">
                <input type="text" name="part_notes[<?= $idx ?>]" class="form-control form-control-sm"
                       placeholder="Describe the issue…" value="<?= e($savedNote) ?>">
            </div>
        </div>
        <?php $idx++; endforeach; ?>
    </div>
</div>
<?php endforeach; ?>

<div style="height:80px"></div>

<div class="assess-footer no-print">
    <div class="d-flex align-items-center gap-4">
        <div>
            <span class="fw-bold fs-5 text-danger" id="issueCount">0</span>
            <span class="text-muted small ms-1">issues found</span>
        </div>
        <div class="vr"></div>
        <div class="text-muted small" id="footerProgress">0 / <?= $totalParts ?> parts reviewed</div>
    </div>
    <div class="d-flex gap-2">
        <a href="view.php?id=<?= $id ?>" class="btn btn-outline-secondary">Cancel</a>
        <button type="submit" class="btn btn-primary px-4">
            <i class="fa fa-save me-2"></i>Save Changes
        </button>
    </div>
</div>

</form>

<?php
$extraJs = <<<SCRIPT
<script>
var totalParts = {$totalParts};

function onCondChange(radio) {
    var row = radio.closest('.assess-part-row');
    var notes = row.querySelector('.assess-part-notes');
    if (notes) notes.style.display = radio.value === 'good' ? 'none' : '';
    updateCounts();
}

function updateCounts() {
    var rows = document.querySelectorAll('.assess-part-row');
    var issues = 0, touched = 0;
    rows.forEach(function(row) {
        var checked = row.querySelector('input[type=radio]:checked');
        if (checked) { touched++; if (checked.value !== 'good') issues++; }
    });
    document.getElementById('issueCount').textContent     = issues;
    document.getElementById('progressText').textContent   = touched + ' / ' + totalParts + ' assessed';
    document.getElementById('footerProgress').textContent = touched + ' / ' + totalParts + ' parts reviewed';
    var gb = document.getElementById('globalIssuesBadge');
    if (issues > 0) { gb.textContent = issues + ' issue' + (issues > 1 ? 's' : ''); gb.classList.remove('d-none'); }
    else gb.classList.add('d-none');
    document.querySelectorAll('.assess-category').forEach(function(cat) {
        var catIssues = 0;
        cat.querySelectorAll('.assess-part-row').forEach(function(r) {
            var c = r.querySelector('input[type=radio]:checked');
            if (c && c.value !== 'good') catIssues++;
        });
        var badge = cat.querySelector('.cat-issues-badge');
        if (badge) {
            if (catIssues > 0) { badge.textContent = catIssues + ' issue' + (catIssues > 1 ? 's' : ''); badge.classList.remove('d-none'); }
            else badge.classList.add('d-none');
        }
    });
}

function toggleCat(header) {
    var body    = header.nextElementSibling;
    var chevron = header.querySelector('.assess-chevron');
    var open    = body.style.display !== 'none';
    body.style.display      = open ? 'none' : '';
    chevron.style.transform = open ? 'rotate(-90deg)' : 'rotate(0deg)';
}

function markCatGood(btn) {
    btn.closest('.assess-category').querySelectorAll('.assess-part-row').forEach(function(row) {
        var goodRadio = row.querySelector('input[value="good"]');
        if (goodRadio) { goodRadio.checked = true; var n = row.querySelector('.assess-part-notes'); if (n) n.style.display = 'none'; }
    });
    updateCounts();
}

document.getElementById('markAllGoodBtn') && document.getElementById('markAllGoodBtn').addEventListener('click', function() {
    document.querySelectorAll('input[type=radio][value="good"]').forEach(function(r) { if (r.name.startsWith('part_condition')) r.checked = true; });
    document.querySelectorAll('.assess-part-notes').forEach(function(n) { n.style.display = 'none'; });
    updateCounts();
});

updateCounts();
</script>
SCRIPT;
include __DIR__ . '/../../includes/footer.php';
?>

<?php
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();

$pageTitle = 'New Assessment';
$db   = getDB();
$user = authUser();
$role = $user['role'];
$linkedId = (int)($user['linked_id'] ?? 0);

// ── Allowed types and car scope per role ────────────────────────────────────
if ($role === 'driver') {
    $allowedTypes = ['pre_departure'];
    $defaultType  = 'pre_departure';
    // Only cars assigned to this driver via a pending/in_transit transfer
    $stmt = $db->prepare("
        SELECT c.id, c.chassis_number, c.make, c.model, c.year, c.car_type, c.owner_name
        FROM cars c
        JOIN car_transfers ct ON ct.car_id = c.id
        WHERE ct.driver_id = ? AND ct.status IN ('pending','in_transit')
        ORDER BY c.make, c.model
    ");
    $stmt->execute([$linkedId]);
    $cars = $stmt->fetchAll();
} elseif ($role === 'mechanic') {
    $allowedTypes = ['arrival', 'pre_sales', 'pre_delivery'];
    $defaultType  = 'arrival';
    $cars = $db->query("SELECT id, chassis_number, make, model, year, car_type, owner_name FROM cars WHERE status NOT IN ('delivered') ORDER BY make, model")->fetchAll();
} else {
    // admin / manager
    $allowedTypes = ['pre_departure', 'arrival', 'pre_sales', 'pre_delivery'];
    $defaultType  = 'arrival';
    $cars = $db->query("SELECT id, chassis_number, make, model, year, car_type, owner_name FROM cars WHERE status NOT IN ('delivered') ORDER BY make, model")->fetchAll();
}

$mechanics = $db->query("SELECT id, name FROM mechanics WHERE status='active' ORDER BY name")->fetchAll();
$drivers   = $db->query("SELECT id, name FROM drivers WHERE status='active' ORDER BY name")->fetchAll();
$partsList = getPartsList();

$allParts = [];
foreach ($partsList as $category => $parts) {
    foreach ($parts as $part) {
        $allParts[] = ['name' => $part, 'category' => $category];
    }
}

$preCarId = (int)($_GET['car_id'] ?? 0);
$errors   = [];

// ── POST handler ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $carId    = (int)($_POST['car_id'] ?? 0);
    $date     = $_POST['assessment_date'] ?? '';
    $type     = $_POST['assessment_type'] ?? $defaultType;
    $status   = $_POST['overall_status'] ?? 'fair';
    $mileage  = $_POST['mileage'] ? (int)$_POST['mileage'] : null;
    $fuel     = $_POST['fuel_level'] ?? 'half';
    $notes    = trim($_POST['notes'] ?? '');

    // Enforce allowed types
    if (!in_array($type, $allowedTypes)) $type = $defaultType;

    // Resolve who performed the assessment
    if ($role === 'driver') {
        $driverId  = $linkedId ?: null;
        $mechId    = null;
    } elseif ($role === 'mechanic') {
        $mechId   = $linkedId ?: ((int)($_POST['mechanic_id'] ?? 0) ?: null);
        $driverId = null;
    } else {
        $mechId   = $_POST['mechanic_id'] ? (int)$_POST['mechanic_id'] : null;
        $driverId = $type === 'pre_departure' && $_POST['driver_id']
                    ? (int)$_POST['driver_id'] : null;
    }

    if (!$carId) $errors[] = 'Please select a vehicle.';
    if (!$date)  $errors[] = 'Assessment date is required.';

    // Validate car is in driver's assigned list
    if ($role === 'driver' && $carId) {
        $check = $db->prepare("SELECT id FROM car_transfers WHERE car_id=? AND driver_id=? AND status IN ('pending','in_transit')");
        $check->execute([$carId, $linkedId]);
        if (!$check->fetch()) $errors[] = 'That vehicle is not assigned to you for delivery.';
    }

    if (empty($errors)) {
        $db->beginTransaction();
        try {
            $db->prepare("INSERT INTO car_assessments (car_id, mechanic_id, driver_id, assessment_date, assessment_type, overall_status, mileage, fuel_level, notes) VALUES (?,?,?,?,?,?,?,?,?)")
               ->execute([$carId, $mechId, $driverId, $date, $type, $status, $mileage, $fuel, $notes]);
            $assessmentId = (int)$db->lastInsertId();

            // Save part-level checklist
            $parts  = $_POST['part_name']      ?? [];
            $conds  = $_POST['part_condition'] ?? [];
            $pnotes = $_POST['part_notes']     ?? [];
            $cats   = $_POST['part_category']  ?? [];

            $ins = $db->prepare("INSERT INTO assessment_items (assessment_id, part_category, part_name, `condition`, notes) VALUES (?,?,?,?,?)");
            foreach ($parts as $idx => $pname) {
                if (!$pname) continue;
                $ins->execute([$assessmentId, $cats[$idx] ?? '', $pname, $conds[$idx] ?? 'good', $pnotes[$idx] ?? '']);
            }

            // Update car status based on assessment type
            $newCarStatus = match ($type) {
                'pre_departure' => 'in_transit',
                'arrival'       => 'arrived',
                default         => 'in_assessment',
            };
            $db->prepare("UPDATE cars SET status=? WHERE id=?")->execute([$newCarStatus, $carId]);

            // If driver did pre_departure, mark the transfer as in_transit
            if ($type === 'pre_departure' && $driverId) {
                $db->prepare("UPDATE car_transfers SET status='in_transit', departure_date=COALESCE(departure_date,?) WHERE car_id=? AND driver_id=? AND status='pending'")
                   ->execute([$date, $carId, $driverId]);
            }

            $db->commit();
            setFlash('success', 'Assessment saved successfully.');
            redirect(BASE_URL . '/modules/assessments/view.php?id=' . $assessmentId);
        } catch (Exception $e) {
            $db->rollBack();
            $errors[] = 'Save failed: ' . $e->getMessage();
        }
    }
}

// Re-populate on validation error
$postConditions = $_POST['part_condition'] ?? [];
$postNotes      = $_POST['part_notes']     ?? [];
$postCarId      = (int)($_POST['car_id']          ?? $preCarId);
$postMechId     = (int)($_POST['mechanic_id']     ?? ($role === 'mechanic' ? $linkedId : 0));
$postDriverId   = (int)($_POST['driver_id']       ?? ($role === 'driver'   ? $linkedId : 0));
$postDate       = $_POST['assessment_date']  ?? date('Y-m-d');
$postType       = in_array($_POST['assessment_type'] ?? '', $allowedTypes) ? $_POST['assessment_type'] : $defaultType;
$postStatus     = $_POST['overall_status']   ?? 'fair';
$postMileage    = $_POST['mileage']          ?? '';
$postFuel       = $_POST['fuel_level']       ?? 'half';
$postGeneralNotes = $_POST['notes']          ?? '';

// Type metadata
$typesMeta = [
    'pre_departure' => ['Pre-Departure (Driver)',    'fa-truck-fast',        '#d97706'],
    'arrival'       => ['Arrival Check (Mechanic)',  'fa-anchor',            '#0284c7'],
    'pre_sales'     => ['Pre-Sales (Mechanic)',      'fa-tag',               '#7c3aed'],
    'pre_delivery'  => ['Pre-Delivery (Mechanic)',   'fa-flag-checkered',    '#16a34a'],
];

include __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="mb-0"><i class="fa fa-clipboard-check me-2 text-primary"></i>New Assessment</h5>
    <a href="index.php" class="btn btn-sm btn-outline-secondary"><i class="fa fa-arrow-left me-1"></i>Back</a>
</div>

<?php if ($errors): ?>
<div class="alert alert-danger mb-3">
    <i class="fa fa-circle-exclamation me-2"></i>
    <?php foreach ($errors as $err) echo e($err) . ' '; ?>
</div>
<?php endif; ?>

<?php if ($role === 'driver' && empty($cars)): ?>
<div class="alert alert-warning">
    <i class="fa fa-triangle-exclamation me-2"></i>
    No cars are currently assigned to you for delivery. Ask your administrator to assign a car.
</div>
<?php else: ?>

<form method="POST" id="assessmentForm">

<!-- ── Vehicle & Assessment Details ─────────────────────────────────────── -->
<div class="card mb-4">
    <div class="card-header"><i class="fa fa-car me-2"></i>Vehicle &amp; Assessment Details</div>
    <div class="card-body">
        <div class="row g-3">

            <div class="col-md-5">
                <label class="form-label">Vehicle <span class="text-danger">*</span></label>
                <select name="car_id" class="form-select select2" required>
                    <option value="">— Select vehicle —</option>
                    <?php foreach ($cars as $c): ?>
                    <option value="<?= $c['id'] ?>" <?= $postCarId === (int)$c['id'] ? 'selected' : '' ?>>
                        <?= e($c['make'] . ' ' . $c['model'] . ' ' . $c['year']) ?>
                        <?= $c['car_type']==='client' ? ' — [CLIENT: '.e($c['owner_name']).']' : '' ?>
                        — <?= e($c['chassis_number']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <?php if ($role !== 'driver'): ?>
            <div class="col-md-4">
                <label class="form-label">Assessed By (Mechanic)</label>
                <select name="mechanic_id" class="form-select select2">
                    <option value="">— Select mechanic —</option>
                    <?php foreach ($mechanics as $m): ?>
                    <option value="<?= $m['id'] ?>" <?= $postMechId === (int)$m['id'] ? 'selected' : '' ?>>
                        <?= e($m['name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>

            <?php if (in_array('pre_departure', $allowedTypes) && $role !== 'driver'): ?>
            <div class="col-md-4" id="driverFieldWrapper">
                <label class="form-label">Assigned Driver (for Pre-Departure)</label>
                <select name="driver_id" class="form-select select2">
                    <option value="">— Select driver —</option>
                    <?php foreach ($drivers as $d): ?>
                    <option value="<?= $d['id'] ?>" <?= $postDriverId === (int)$d['id'] ? 'selected' : '' ?>>
                        <?= e($d['name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>

            <div class="col-md-3">
                <label class="form-label">Date <span class="text-danger">*</span></label>
                <input type="date" name="assessment_date" class="form-control" value="<?= e($postDate) ?>" required>
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
                            class="type-radio"
                            <?= $postType === $val ? 'checked' : '' ?>
                            <?= count($allowedTypes) === 1 ? 'required' : '' ?>>
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
                            class="os-radio" <?= $postStatus === $val ? 'checked' : '' ?>>
                        <label for="os_<?= $val ?>" class="os-btn" style="--os-color:<?= $color ?>"><?= $lbl ?></label>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="col-md-2">
                <label class="form-label">Mileage (km)</label>
                <input type="number" name="mileage" class="form-control" min="0" value="<?= e($postMileage) ?>" placeholder="e.g. 45000">
            </div>

            <div class="col-md-4">
                <label class="form-label">Fuel Level</label>
                <div class="fuel-gauge">
                    <?php $fuelOpts = ['empty'=>'E','quarter'=>'¼','half'=>'½','three_quarter'=>'¾','full'=>'F'];
                    foreach ($fuelOpts as $val => $lbl): ?>
                    <input type="radio" name="fuel_level" id="fuel_<?= $val ?>" value="<?= $val ?>"
                        class="fuel-radio" <?= $postFuel === $val ? 'checked' : '' ?>>
                    <label for="fuel_<?= $val ?>" class="fuel-btn"><?= $lbl ?></label>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="col-12">
                <label class="form-label">General Notes</label>
                <textarea name="notes" class="form-control" rows="2" placeholder="Overall observations about the vehicle..."><?= e($postGeneralNotes) ?></textarea>
            </div>
        </div>
    </div>
</div>

<!-- ── Parts Checklist ───────────────────────────────────────────────────── -->
<div class="d-flex align-items-center justify-content-between mb-3">
    <h6 class="mb-0 fw-semibold"><i class="fa fa-list-check me-2 text-primary"></i>Parts Condition Checklist</h6>
    <div class="d-flex align-items-center gap-3">
        <span class="text-muted small" id="progressText">0 / <?= count($allParts) ?> assessed</span>
        <span class="badge bg-danger d-none" id="globalIssuesBadge">0 issues</span>
        <button type="button" class="btn btn-sm btn-outline-success" id="markAllGoodBtn">
            <i class="fa fa-check-double me-1"></i>Mark All Good
        </button>
    </div>
</div>

<?php
$idx = 0;
$catIcons = ['Exterior'=>'fa-car-side','Lighting'=>'fa-lightbulb','Wheels & Tyres'=>'fa-circle-dot','Interior'=>'fa-couch','Electronics'=>'fa-microchip','Engine & Mechanical'=>'fa-gears','Documents'=>'fa-file-lines'];
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
            $savedCond = $postConditions[$idx] ?? 'good';
            $savedNote = $postNotes[$idx] ?? '';
        ?>
        <div class="assess-part-row" data-idx="<?= $idx ?>">
            <input type="hidden" name="part_name[<?= $idx ?>]"     value="<?= e($part) ?>">
            <input type="hidden" name="part_category[<?= $idx ?>]" value="<?= e($category) ?>">
            <span class="assess-part-name"><?= e($part) ?></span>
            <div class="cond-group" role="group">
                <?php
                $conditions = ['good'=>['fa-check','Good','cond-good'],'minor_damage'=>['fa-triangle-exclamation','Minor','cond-minor'],'major_damage'=>['fa-circle-xmark','Major','cond-major'],'missing'=>['fa-ban','Missing','cond-missing'],'needs_service'=>['fa-wrench','Service','cond-service']];
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
        <div class="text-muted small" id="footerProgress">0 / <?= count($allParts) ?> parts reviewed</div>
    </div>
    <div class="d-flex gap-2">
        <a href="index.php" class="btn btn-outline-secondary">Cancel</a>
        <button type="submit" class="btn btn-primary px-4">
            <i class="fa fa-save me-2"></i>Save Assessment
        </button>
    </div>
</div>

</form>
<?php endif; ?>

<?php
$totalParts = count($allParts);
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
    rows.forEach(function (row) {
        var checked = row.querySelector('input[type=radio]:checked');
        if (checked) { touched++; if (checked.value !== 'good') issues++; }
    });
    document.getElementById('issueCount').textContent    = issues;
    document.getElementById('progressText').textContent  = touched + ' / ' + totalParts + ' assessed';
    document.getElementById('footerProgress').textContent = touched + ' / ' + totalParts + ' parts reviewed';
    var gb = document.getElementById('globalIssuesBadge');
    if (issues > 0) { gb.textContent = issues + ' issue' + (issues > 1 ? 's' : ''); gb.classList.remove('d-none'); }
    else gb.classList.add('d-none');
    document.querySelectorAll('.assess-category').forEach(function (cat) {
        var catIssues = 0;
        cat.querySelectorAll('.assess-part-row').forEach(function (r) {
            var c = r.querySelector('input[type=radio]:checked');
            if (c && c.value !== 'good') catIssues++;
        });
        var badge = cat.querySelector('.cat-issues-badge');
        if (badge) {
            if (catIssues > 0) { badge.textContent = catIssues + ' issue' + (catIssues > 1 ? 's' : ''); badge.classList.remove('d-none'); }
            else badge.classList.add('d-none');
        }
    });
    var worstVal = 0;
    rows.forEach(function (row) {
        var c = row.querySelector('input[type=radio]:checked');
        if (!c) return;
        var rank = {good:0, needs_service:1, minor_damage:2, major_damage:3, missing:4};
        worstVal = Math.max(worstVal, rank[c.value] || 0);
    });
    var osMap = {0:'excellent',1:'good',2:'fair',3:'poor',4:'critical'};
    var sr = document.getElementById('os_' + (osMap[worstVal] || 'fair'));
    if (sr && !document.querySelector('.os-radio:checked[data-user-set]')) sr.checked = true;
}

function toggleCat(header) {
    var body = header.nextElementSibling;
    var chevron = header.querySelector('.assess-chevron');
    var open = body.style.display !== 'none';
    body.style.display = open ? 'none' : '';
    chevron.style.transform = open ? 'rotate(-90deg)' : 'rotate(0deg)';
}

function markCatGood(btn) {
    btn.closest('.assess-category').querySelectorAll('.assess-part-row').forEach(function (row) {
        var goodRadio = row.querySelector('input[value="good"]');
        if (goodRadio) { goodRadio.checked = true; var n = row.querySelector('.assess-part-notes'); if (n) n.style.display = 'none'; }
    });
    updateCounts();
}

document.getElementById('markAllGoodBtn') && document.getElementById('markAllGoodBtn').addEventListener('click', function () {
    document.querySelectorAll('input[type=radio][value="good"]').forEach(function (r) { if (r.name.startsWith('part_condition')) r.checked = true; });
    document.querySelectorAll('.assess-part-notes').forEach(function (n) { n.style.display = 'none'; });
    updateCounts();
});

document.querySelectorAll('.os-radio').forEach(function (r) {
    r.addEventListener('change', function () {
        document.querySelectorAll('.os-radio').forEach(function(x){ x.dataset.userSet = ''; });
        this.dataset.userSet = 'yes';
    });
});

updateCounts();
</script>
SCRIPT;
include __DIR__ . '/../../includes/footer.php';
?>

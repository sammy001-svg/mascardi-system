<?php
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/notifications.php';
requireLogin();
canAccess('issues') || die('Access denied.');
canWrite('issues') || die('Permission denied.');
$pageTitle = 'Report Issue';
$db   = getDB();
$user = authUser();

$preCarId = (int)($_GET['car_id'] ?? 0);

$cars      = $db->query("SELECT id, make, model, year, chassis_number, registration_number FROM cars ORDER BY make, model")->fetchAll();
$mechanics = $db->query("SELECT id, name FROM mechanics WHERE status='active' ORDER BY name")->fetchAll();

$categories = [
    'Exterior', 'Interior', 'Engine & Mechanical', 'Electrical', 'Wheels & Tyres',
    'Lighting', 'Body Work', 'Safety', 'Documents', 'Other',
];

$errors = [];
$d = [
    'car_id'      => $preCarId,
    'title'       => '',
    'category'    => '',
    'severity'    => 'medium',
    'description' => '',
    'assigned_to' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $d['car_id']      = (int)($_POST['car_id'] ?? 0);
    $d['title']       = trim($_POST['title'] ?? '');
    $d['category']    = trim($_POST['category'] ?? '');
    $d['severity']    = $_POST['severity'] ?? 'medium';
    $d['description'] = trim($_POST['description'] ?? '');
    $d['assigned_to'] = $_POST['assigned_to'] ?: null;

    if (!$d['car_id'])  $errors[] = 'Please select a vehicle.';
    if (!$d['title'])   $errors[] = 'Issue title is required.';
    if (!$d['category']) $errors[] = 'Please select a category.';

    if (empty($errors)) {
        try {
            $issNum = nextNumber('car_issues', 'issue_number', getSetting('issue_prefix', 'ISS'));
            $db->prepare("INSERT INTO car_issues
                (issue_number, car_id, title, description, category, severity, status, reported_by, assigned_to)
                VALUES (?,?,?,?,?,?,?,?,?)")
               ->execute([
                   $issNum, $d['car_id'], $d['title'], $d['description'],
                   $d['category'], $d['severity'], 'open',
                   $user['name'], $d['assigned_to'],
               ]);
            $newId = (int)$db->lastInsertId();
            logActivity('create', 'issues', $newId, "Reported issue {$issNum}: {$d['title']}");
            if (in_array($d['severity'], ['critical', 'high'])) {
                notifyRoles(['admin', 'workshop_manager'], 'issue',
                    strtoupper($d['severity']) . " Issue: {$d['title']}",
                    $d['category'] . ($d['description'] ? ' — ' . mb_substr($d['description'], 0, 80) : ''),
                    BASE_URL . '/modules/issues/view.php?id=' . $newId
                );
            }
            setFlash('success', "Issue {$issNum} reported.");
            redirect(BASE_URL . '/modules/issues/view.php?id=' . $newId);
        } catch (\Throwable $e) {
            $errors[] = 'Save failed: ' . $e->getMessage();
        }
    }
}

include __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <h5 class="mb-0"><i class="fa fa-triangle-exclamation me-2 text-warning"></i>Report Issue</h5>
    <a href="index.php" class="btn btn-sm btn-outline-secondary"><i class="fa fa-arrow-left me-1"></i>Back</a>
</div>

<?php if ($errors): ?>
<div class="alert alert-danger"><?php foreach ($errors as $e) echo '<div><i class="fa fa-circle-exclamation me-1"></i>'.e($e).'</div>'; ?></div>
<?php endif; ?>

<form method="POST">
<div class="row g-4">
    <div class="col-lg-8">
        <div class="card mb-4">
            <div class="card-header fw-semibold"><i class="fa fa-car me-2"></i>Vehicle</div>
            <div class="card-body">
                <select name="car_id" class="form-select select2" required>
                    <option value="">Select vehicle…</option>
                    <?php foreach ($cars as $c): ?>
                    <option value="<?= $c['id'] ?>" <?= $d['car_id'] == $c['id'] ? 'selected' : '' ?>>
                        <?= e($c['make'].' '.$c['model'].' '.$c['year']) ?>
                        <?= $c['registration_number'] ? ' — '.e($c['registration_number']) : '' ?>
                        — <code><?= e(substr($c['chassis_number'], 0, 12)) ?>…</code>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="card mb-4">
            <div class="card-header fw-semibold"><i class="fa fa-triangle-exclamation me-2 text-warning"></i>Issue Details</div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-12">
                        <label class="form-label">Issue Title <span class="text-danger">*</span></label>
                        <input type="text" name="title" class="form-control" value="<?= e($d['title']) ?>"
                               placeholder="e.g. Front bumper crack, AC not cooling, Oil leak" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Category <span class="text-danger">*</span></label>
                        <select name="category" class="form-select" required>
                            <option value="">Select category…</option>
                            <?php foreach ($categories as $cat): ?>
                            <option value="<?= $cat ?>" <?= $d['category'] === $cat ? 'selected' : '' ?>><?= $cat ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Severity</label>
                        <select name="severity" class="form-select" id="severitySelect" onchange="updateSeverityBadge()">
                            <?php foreach (['low'=>'Low','medium'=>'Medium','high'=>'High','critical'=>'Critical'] as $v=>$l): ?>
                            <option value="<?= $v ?>" <?= $d['severity']===$v?'selected':'' ?>><?= $l ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12">
                        <label class="form-label">Description</label>
                        <textarea name="description" class="form-control" rows="4"
                                  placeholder="Describe the issue in detail — location, symptoms, when it was first noticed…"><?= e($d['description']) ?></textarea>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="card mb-4">
            <div class="card-header fw-semibold"><i class="fa fa-user-gear me-2"></i>Assignment</div>
            <div class="card-body">
                <label class="form-label">Assign to Mechanic <small class="text-muted">(optional)</small></label>
                <select name="assigned_to" class="form-select select2">
                    <option value="">— Unassigned —</option>
                    <?php foreach ($mechanics as $m): ?>
                    <option value="<?= $m['id'] ?>" <?= $d['assigned_to'] == $m['id'] ? 'selected' : '' ?>><?= e($m['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <div class="text-muted small mt-2"><i class="fa fa-info-circle me-1"></i>You can assign or reassign later from the issue view.</div>
            </div>
        </div>

        <div class="card" id="severityCard">
            <div class="card-body text-center py-4">
                <div id="severityIcon" style="font-size:2.5rem;margin-bottom:.5rem"></div>
                <div id="severityLabel" class="fw-semibold" style="font-size:15px"></div>
                <div id="severityDesc" class="text-muted small mt-1"></div>
            </div>
        </div>

        <div class="d-grid gap-2 mt-4">
            <button type="submit" class="btn btn-warning py-2 fw-semibold">
                <i class="fa fa-triangle-exclamation me-2"></i>Report Issue
            </button>
            <a href="index.php" class="btn btn-outline-secondary">Cancel</a>
        </div>
    </div>
</div>
</form>

<?php
$extraJs = <<<'JS'
<script>
var severityMeta = {
    low:      { icon: '🔵', color: '#2563eb', label: 'Low Severity',      desc: 'Minor issue, can wait for next scheduled service.' },
    medium:   { icon: '🟡', color: '#d97706', label: 'Medium Severity',   desc: 'Should be addressed within the week.' },
    high:     { icon: '🟠', color: '#ea580c', label: 'High Severity',     desc: 'Needs prompt attention — affects usability or safety.' },
    critical: { icon: '🔴', color: '#dc2626', label: 'Critical',          desc: 'Vehicle must not be driven. Immediate action required.' },
};
function updateSeverityBadge() {
    var v    = document.getElementById('severitySelect').value;
    var meta = severityMeta[v] || severityMeta.medium;
    document.getElementById('severityIcon').textContent   = meta.icon;
    document.getElementById('severityLabel').textContent  = meta.label;
    document.getElementById('severityLabel').style.color  = meta.color;
    document.getElementById('severityDesc').textContent   = meta.desc;
    document.getElementById('severityCard').style.borderTop = '3px solid ' + meta.color;
}
updateSeverityBadge();
</script>
JS;
include __DIR__ . '/../../includes/footer.php';
?>

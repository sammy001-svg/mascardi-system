<?php
require_once __DIR__ . '/../../includes/functions.php';
requireWrite('key_handovers');
$db   = getDB();
$user = authUser();

$cars      = $db->query("SELECT c.id, c.make, c.model, c.registration_number, l.name AS location_name, l.id AS location_id FROM cars c LEFT JOIN locations l ON l.id = c.location_id ORDER BY c.make, c.model")->fetchAll();
$locations = $db->query("SELECT id, name FROM locations WHERE status='active' ORDER BY name")->fetchAll();

$errors = [];
$d = ['car_id' => '', 'key_label' => '', 'current_location_id' => '', 'notes' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $d['car_id']              = (int)($_POST['car_id'] ?? 0);
    $d['key_label']           = trim($_POST['key_label'] ?? '');
    $d['current_location_id'] = (int)($_POST['current_location_id'] ?? 0) ?: null;
    $d['notes']               = trim($_POST['notes'] ?? '');

    if (!$d['car_id'])    $errors[] = 'Select a vehicle.';
    if (!$d['key_label']) $errors[] = 'Key label is required.';

    if (empty($errors)) {
        try {
            $db->prepare("INSERT INTO car_keys (car_id, key_label, current_location_id, status, notes) VALUES (?,?,?,'at_showroom',?)")
               ->execute([$d['car_id'], $d['key_label'], $d['current_location_id'], $d['notes'] ?: null]);
            setFlash('success', "Key '{$d['key_label']}' registered.");
            redirect(BASE_URL . '/modules/key_handovers/index.php?tab=keys');
        } catch (\Throwable $e) {
            $errors[] = 'Save failed: ' . $e->getMessage();
        }
    }
}

$pageTitle = 'Register Key';
include __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="mb-0"><i class="fa fa-key me-2 text-primary"></i>Register Key</h5>
    <a href="index.php?tab=keys" class="btn btn-sm btn-outline-secondary"><i class="fa fa-arrow-left me-1"></i>Back</a>
</div>

<?php if ($errors): ?>
<div class="alert alert-danger mb-3">
    <?php foreach ($errors as $err) echo '<div>' . e($err) . '</div>'; ?>
</div>
<?php endif; ?>

<div class="card" style="max-width:600px">
    <div class="card-body">
        <form method="POST">
            <div class="mb-3">
                <label class="form-label fw-semibold">Vehicle <span class="text-danger">*</span></label>
                <select name="car_id" class="form-select select2" required id="carSel">
                    <option value="">— Select vehicle —</option>
                    <?php foreach ($cars as $c): ?>
                    <option value="<?= $c['id'] ?>" data-location="<?= $c['location_id'] ?>" <?= ($d['car_id'] == $c['id']) ? 'selected' : '' ?>>
                        <?= e($c['make'] . ' ' . $c['model']) ?>
                        <?= $c['registration_number'] ? ' — ' . e($c['registration_number']) : '' ?>
                        <?= $c['location_name'] ? ' [' . e($c['location_name']) . ']' : '' ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                <div class="form-text">If the car is linked to a location, current location will be auto-filled.</div>
            </div>
            <div class="mb-3">
                <label class="form-label fw-semibold">Key Label <span class="text-danger">*</span></label>
                <input type="text" name="key_label" class="form-control font-monospace" placeholder="e.g. KDA123Q-K1" value="<?= e($d['key_label']) ?>" required>
                <div class="form-text">Use a consistent format — e.g. registration + key number.</div>
            </div>
            <div class="mb-3">
                <label class="form-label fw-semibold">Current Location</label>
                <select name="current_location_id" class="form-select select2" id="locationSel">
                    <option value="">— Unknown —</option>
                    <?php foreach ($locations as $loc): ?>
                    <option value="<?= $loc['id'] ?>" <?= ($d['current_location_id'] == $loc['id']) ? 'selected' : '' ?>>
                        <?= e($loc['name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="mb-4">
                <label class="form-label fw-semibold">Notes</label>
                <textarea name="notes" class="form-control" rows="2" placeholder="Any relevant notes about this key…"><?= e($d['notes']) ?></textarea>
            </div>
            <div class="d-flex gap-2 justify-content-end">
                <a href="index.php?tab=keys" class="btn btn-outline-secondary">Cancel</a>
                <button type="submit" class="btn btn-primary px-4"><i class="fa fa-save me-2"></i>Register Key</button>
            </div>
        </form>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
<script>
$(function(){
    $('#carSel').on('select2:select', function(){
        const opt = this.options[this.selectedIndex];
        const locId = opt ? opt.dataset.location : '';
        if (locId) { $('#locationSel').val(locId).trigger('change'); }
    });
});
</script>

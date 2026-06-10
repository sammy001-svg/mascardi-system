<?php
require_once __DIR__ . '/../../includes/functions.php';
requireWrite('key_handovers');
$db = getDB();

$id = (int)($_GET['id'] ?? 0);
if (!$id) redirect(BASE_URL . '/modules/key_handovers/index.php?tab=keys');

$key = $db->prepare("SELECT * FROM car_keys WHERE id = ?");
$key->execute([$id]);
$key = $key->fetch();
if (!$key) { setFlash('error', 'Key not found.'); redirect(BASE_URL . '/modules/key_handovers/index.php?tab=keys'); }

$locations = $db->query("SELECT id, name FROM locations WHERE status='active' ORDER BY name")->fetchAll();
$statusOpts = ['at_showroom' => 'At Showroom', 'in_transit' => 'In Transit', 'with_driver' => 'With Driver', 'missing' => 'Missing'];

$errors = [];
$d = $key;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $d['key_label']           = trim($_POST['key_label'] ?? '');
    $d['current_location_id'] = (int)($_POST['current_location_id'] ?? 0) ?: null;
    $d['status']              = $_POST['status'] ?? 'at_showroom';
    $d['notes']               = trim($_POST['notes'] ?? '');

    if (!$d['key_label']) $errors[] = 'Key label is required.';

    if (empty($errors)) {
        try {
            $db->prepare("UPDATE car_keys SET key_label=?, current_location_id=?, status=?, notes=?, updated_at=NOW() WHERE id=?")
               ->execute([$d['key_label'], $d['current_location_id'], $d['status'], $d['notes'] ?: null, $id]);
            setFlash('success', "Key updated.");
            redirect(BASE_URL . '/modules/key_handovers/index.php?tab=keys');
        } catch (\Throwable $e) {
            $errors[] = 'Save failed: ' . $e->getMessage();
        }
    }
}

$pageTitle = 'Edit Key';
include __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="mb-0"><i class="fa fa-key me-2 text-primary"></i>Edit Key — <?= e($key['key_label']) ?></h5>
    <a href="index.php?tab=keys" class="btn btn-sm btn-outline-secondary"><i class="fa fa-arrow-left me-1"></i>Back</a>
</div>

<?php if ($errors): ?>
<div class="alert alert-danger mb-3"><?php foreach ($errors as $err) echo '<div>' . e($err) . '</div>'; ?></div>
<?php endif; ?>

<div class="card" style="max-width:600px">
    <div class="card-body">
        <form method="POST">
            <div class="mb-3">
                <label class="form-label fw-semibold">Key Label <span class="text-danger">*</span></label>
                <input type="text" name="key_label" class="form-control font-monospace" value="<?= e($d['key_label']) ?>" required>
            </div>
            <div class="mb-3">
                <label class="form-label fw-semibold">Current Location</label>
                <select name="current_location_id" class="form-select select2">
                    <option value="">— Unknown —</option>
                    <?php foreach ($locations as $loc): ?>
                    <option value="<?= $loc['id'] ?>" <?= ($d['current_location_id'] == $loc['id']) ? 'selected' : '' ?>><?= e($loc['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="mb-3">
                <label class="form-label fw-semibold">Status</label>
                <select name="status" class="form-select">
                    <?php foreach ($statusOpts as $val => $label): ?>
                    <option value="<?= $val ?>" <?= $d['status'] === $val ? 'selected' : '' ?>><?= $label ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="mb-4">
                <label class="form-label fw-semibold">Notes</label>
                <textarea name="notes" class="form-control" rows="2"><?= e($d['notes'] ?? '') ?></textarea>
            </div>
            <div class="d-flex gap-2 justify-content-end">
                <a href="index.php?tab=keys" class="btn btn-outline-secondary">Cancel</a>
                <button type="submit" class="btn btn-primary px-4"><i class="fa fa-save me-2"></i>Save Changes</button>
            </div>
        </form>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>

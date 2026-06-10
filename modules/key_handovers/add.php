<?php
require_once __DIR__ . '/../../includes/functions.php';
requireWrite('key_handovers');
$db   = getDB();
$user = authUser();

$locations = $db->query("SELECT id, name FROM locations WHERE status='active' ORDER BY name")->fetchAll();
$drivers   = $db->query("SELECT id, name, phone FROM drivers WHERE status='active' ORDER BY name")->fetchAll();

// Keys available at a location — loaded via JS fetch; pre-load all for fallback
$allKeys = $db->query("
    SELECT ck.id AS key_id, ck.key_label, ck.car_id, ck.current_location_id,
           c.make, c.model, c.registration_number
    FROM car_keys ck
    JOIN cars c ON c.id = ck.car_id
    WHERE ck.status IN ('at_showroom','with_driver')
    ORDER BY c.make, c.model
")->fetchAll();

$errors = [];
$d = [
    'handover_date'    => date('Y-m-d'),
    'run_type'         => 'morning_run',
    'driver_id'        => '',
    'from_location_id' => '',
    'to_location_id'   => '',
    'notes'            => '',
    'key_ids'          => [],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $d['handover_date']    = $_POST['handover_date'] ?? date('Y-m-d');
    $d['run_type']         = $_POST['run_type'] ?? 'morning_run';
    $d['driver_id']        = (int)($_POST['driver_id'] ?? 0) ?: null;
    $d['from_location_id'] = (int)($_POST['from_location_id'] ?? 0);
    $d['to_location_id']   = (int)($_POST['to_location_id'] ?? 0);
    $d['notes']            = trim($_POST['notes'] ?? '');
    $d['key_ids']          = array_map('intval', $_POST['key_ids'] ?? []);

    $driverName = null;
    if ($d['driver_id']) {
        $dr = $db->prepare("SELECT name FROM drivers WHERE id=?");
        $dr->execute([$d['driver_id']]);
        $driverName = $dr->fetchColumn() ?: null;
    }

    if (!$d['from_location_id']) $errors[] = 'Select the origin location.';
    if (!$d['to_location_id'])   $errors[] = 'Select the destination location.';
    if ($d['from_location_id'] === $d['to_location_id'] && $d['from_location_id']) $errors[] = 'Origin and destination cannot be the same.';
    if (empty($d['key_ids']))    $errors[] = 'Select at least one key.';

    if (empty($errors)) {
        $db->beginTransaction();
        try {
            $num = nextNumber('key_handovers', 'handover_number', 'KH');
            $db->prepare("
                INSERT INTO key_handovers
                    (handover_number, handover_date, run_type, driver_id, driver_name,
                     from_location_id, to_location_id, notes, created_by)
                VALUES (?,?,?,?,?, ?,?,?,?)
            ")->execute([
                $num, $d['handover_date'], $d['run_type'], $d['driver_id'], $driverName,
                $d['from_location_id'], $d['to_location_id'], $d['notes'] ?: null, $user['name'],
            ]);
            $hid = (int)$db->lastInsertId();

            $ins = $db->prepare("INSERT INTO key_handover_items (handover_id, car_key_id, car_id) VALUES (?,?,?)");
            foreach ($d['key_ids'] as $kid) {
                $carId = null;
                foreach ($allKeys as $k) { if ($k['key_id'] == $kid) { $carId = $k['car_id']; break; } }
                if ($carId) $ins->execute([$hid, $kid, $carId]);
            }

            $db->commit();
            logActivity('create', 'key_handovers', $hid, "Created key run {$num}");
            setFlash('success', "Key run {$num} created.");
            redirect(BASE_URL . '/modules/key_handovers/view.php?id=' . $hid);
        } catch (\Throwable $e) {
            $db->rollBack();
            $errors[] = 'Save failed: ' . $e->getMessage();
        }
    }
}

$pageTitle = 'New Key Run';
include __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="mb-0"><i class="fa fa-key me-2 text-primary"></i>New Key Run</h5>
    <a href="index.php" class="btn btn-sm btn-outline-secondary"><i class="fa fa-arrow-left me-1"></i>Back</a>
</div>

<?php if ($errors): ?>
<div class="alert alert-danger mb-3">
    <?php foreach ($errors as $err) echo '<div><i class="fa fa-circle-exclamation me-2"></i>' . e($err) . '</div>'; ?>
</div>
<?php endif; ?>

<form method="POST" id="keyRunForm">
<div class="row g-4">

    <!-- Run Details -->
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header fw-semibold"><i class="fa fa-circle-info me-2 text-primary"></i>Run Details</div>
            <div class="card-body">
                <div class="mb-3">
                    <label class="form-label fw-semibold small">Run Type <span class="text-danger">*</span></label>
                    <div class="d-flex gap-2">
                        <?php foreach (['morning_run'=>['fa-sun','Morning Run','warning'],'evening_run'=>['fa-moon','Evening Run','primary'],'ad_hoc'=>['fa-bolt','Ad-hoc','secondary']] as $val=>[$icon,$label,$color]): ?>
                        <div class="flex-fill">
                            <input type="radio" name="run_type" id="rt_<?= $val ?>" value="<?= $val ?>" class="btn-check"
                                   <?= $d['run_type'] === $val ? 'checked' : '' ?>>
                            <label for="rt_<?= $val ?>" class="btn btn-outline-<?= $color ?> w-100 fw-semibold">
                                <i class="fa <?= $icon ?> me-1"></i><?= $label ?>
                            </label>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold small">Date <span class="text-danger">*</span></label>
                    <input type="date" name="handover_date" class="form-control" value="<?= e($d['handover_date']) ?>" required>
                </div>
                <div>
                    <label class="form-label fw-semibold small">Notes</label>
                    <textarea name="notes" class="form-control" rows="2" placeholder="Any instructions…"><?= e($d['notes']) ?></textarea>
                </div>
            </div>
        </div>
    </div>

    <!-- Driver + Route -->
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header fw-semibold"><i class="fa fa-route me-2 text-primary"></i>Driver &amp; Route</div>
            <div class="card-body">
                <div class="mb-3">
                    <label class="form-label fw-semibold small">Driver <span class="text-danger">*</span></label>
                    <select name="driver_id" class="form-select select2" required>
                        <option value="">— Select driver —</option>
                        <?php foreach ($drivers as $drv): ?>
                        <option value="<?= $drv['id'] ?>" <?= ($d['driver_id'] == $drv['id']) ? 'selected' : '' ?>>
                            <?= e($drv['name']) ?><?= $drv['phone'] ? ' — '.e($drv['phone']) : '' ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold small">From <span class="text-danger">*</span></label>
                    <select name="from_location_id" class="form-select select2" required id="fromLoc">
                        <option value="">— Origin —</option>
                        <?php foreach ($locations as $loc): ?>
                        <option value="<?= $loc['id'] ?>" <?= ($d['from_location_id'] == $loc['id']) ? 'selected' : '' ?>><?= e($loc['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="form-label fw-semibold small">To <span class="text-danger">*</span></label>
                    <select name="to_location_id" class="form-select select2" required>
                        <option value="">— Destination —</option>
                        <?php foreach ($locations as $loc): ?>
                        <option value="<?= $loc['id'] ?>" <?= ($d['to_location_id'] == $loc['id']) ? 'selected' : '' ?>><?= e($loc['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </div>
    </div>

    <!-- Key Selection -->
    <div class="col-12">
        <div class="card">
            <div class="card-header fw-semibold d-flex justify-content-between align-items-center">
                <span><i class="fa fa-key me-2 text-primary"></i>Select Keys for this Run</span>
                <span class="badge bg-primary" id="keyCount">0 selected</span>
            </div>
            <div class="card-body">
                <?php if (!$allKeys): ?>
                <div class="text-muted text-center py-3">
                    No keys registered yet. <a href="keys_add.php">Register keys first.</a>
                </div>
                <?php else: ?>
                <div class="form-text mb-3">
                    <i class="fa fa-circle-info me-1 text-primary"></i>
                    Only keys with status "At Showroom" or "With Driver" are shown. Select all keys going on this run.
                    <a href="keys_add.php" class="ms-2">+ Register new key</a>
                </div>
                <div class="table-responsive">
                <table class="table table-sm table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th style="width:40px">
                                <input type="checkbox" id="checkAll" class="form-check-input">
                            </th>
                            <th>Key Label</th>
                            <th>Vehicle</th>
                            <th>Current Location</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($allKeys as $k): ?>
                        <tr>
                            <td>
                                <input type="checkbox" name="key_ids[]" value="<?= $k['key_id'] ?>"
                                       class="form-check-input key-cb"
                                       <?= in_array($k['key_id'], $d['key_ids']) ? 'checked' : '' ?>>
                            </td>
                            <td class="fw-semibold font-monospace small"><?= e($k['key_label']) ?></td>
                            <td class="small">
                                <?= e($k['make'] . ' ' . $k['model']) ?>
                                <?php if ($k['registration_number']): ?>
                                <span class="badge bg-dark bg-opacity-75 ms-1"><?= e($k['registration_number']) ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="small text-muted">
                                <?php
                                $locName = '—';
                                foreach ($locations as $loc) {
                                    if ($loc['id'] == $k['current_location_id']) { $locName = $loc['name']; break; }
                                }
                                echo e($locName);
                                ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-12 d-flex justify-content-end gap-2">
        <a href="index.php" class="btn btn-outline-secondary">Cancel</a>
        <button type="submit" class="btn btn-primary px-4">
            <i class="fa fa-paper-plane me-2"></i>Create Key Run
        </button>
    </div>

</div>
</form>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
<script>
$(function(){
    function updateCount(){
        var n = document.querySelectorAll('.key-cb:checked').length;
        document.getElementById('keyCount').textContent = n + ' selected';
    }
    document.querySelectorAll('.key-cb').forEach(function(cb){ cb.addEventListener('change', updateCount); });
    document.getElementById('checkAll') && document.getElementById('checkAll').addEventListener('change', function(){
        document.querySelectorAll('.key-cb').forEach(function(cb){ cb.checked = this.checked; }.bind(this));
        updateCount();
    });
    updateCount();
});
</script>

<?php
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
requireRole(['admin','manager','mechanic','workshop_manager']);
$pageTitle = 'New Part Request';
$db   = getDB();
$user = authUser();
$role = $user['role'];

// Resolve mechanic_id: mechanic uses their linked record; admin/manager picks
$linkedMechId = ($role === 'mechanic') ? (int)($user['linked_id'] ?? 0) : 0;

$errors  = [];
$preJobId = (int)($_GET['job_id'] ?? 0);

// Fetch data for dropdowns
$mechanics = $db->query("SELECT id, name FROM mechanics WHERE status='active' ORDER BY name")->fetchAll();
$jobs = $role === 'mechanic' && $linkedMechId
    ? $db->prepare("SELECT id, job_number FROM workshop_jobs WHERE mechanic_id=? AND status NOT IN ('completed','cancelled') ORDER BY created_at DESC")
    : $db->query("SELECT id, job_number FROM workshop_jobs WHERE status NOT IN ('completed','cancelled') ORDER BY created_at DESC");
if ($role === 'mechanic' && $linkedMechId) { $jobs->execute([$linkedMechId]); }
$jobs = $jobs->fetchAll();

// Inventory items for the part picker (no prices for mechanics)
$invItems = $db->query("SELECT id, part_name, part_number, unit, quantity FROM inventory WHERE quantity > 0 ORDER BY part_name")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $mechId  = $linkedMechId ?: (int)($_POST['mechanic_id'] ?? 0);
    $jobId   = (int)($_POST['job_id'] ?? 0) ?: null;
    $notes   = trim($_POST['notes'] ?? '');
    $invIds  = $_POST['inventory_id']  ?? [];
    $customs = $_POST['custom_part']   ?? [];
    $qtys    = $_POST['qty']           ?? [];
    $units   = $_POST['unit']          ?? [];
    $inotes  = $_POST['item_notes']    ?? [];

    if (!$mechId) $errors[] = 'Mechanic is required.';

    // Build items list
    $items = [];
    foreach ($qtys as $i => $qty) {
        $qty = (float)$qty;
        if ($qty <= 0) continue;
        $invId    = (int)($invIds[$i] ?? 0) ?: null;
        $partName = $invId
            ? ($invItems[array_search($invId, array_column($invItems,'id'))]['part_name'] ?? trim($customs[$i] ?? ''))
            : trim($customs[$i] ?? '');
        if ($invId) {
            foreach ($invItems as $inv) { if ($inv['id'] == $invId) { $partName = $inv['part_name']; break; } }
        }
        if (!$partName) continue;
        $items[] = [
            'inventory_id' => $invId,
            'part_name'    => $partName,
            'qty'          => $qty,
            'unit'         => $units[$i] ?? 'piece',
            'notes'        => trim($inotes[$i] ?? ''),
        ];
    }

    if (empty($items)) $errors[] = 'Add at least one part to request.';

    if (empty($errors)) {
        $db->beginTransaction();
        try {
            $reqNum = nextNumber('parts_requests', 'request_number', 'REQ');
            $db->prepare("INSERT INTO parts_requests (request_number, job_id, mechanic_id, requested_by, notes) VALUES (?,?,?,?,?)")
               ->execute([$reqNum, $jobId, $mechId, $user['id'], $notes]);
            $reqId = (int)$db->lastInsertId();

            $ins = $db->prepare("INSERT INTO parts_request_items (request_id, inventory_id, part_name, quantity_requested, unit, notes) VALUES (?,?,?,?,?,?)");
            foreach ($items as $it) {
                $ins->execute([$reqId, $it['inventory_id'], $it['part_name'], $it['qty'], $it['unit'], $it['notes']]);
            }

            $db->commit();
            setFlash('success', 'Part request ' . $reqNum . ' submitted. Waiting for approval.');
            redirect(BASE_URL . '/modules/parts_requests/view.php?id=' . $reqId);
        } catch (Exception $e) {
            $db->rollBack();
            $errors[] = 'Save failed: ' . $e->getMessage();
        }
    }
}

include __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="mb-0"><i class="fa fa-hand-holding-box me-2 text-primary"></i>New Part Request</h5>
    <a href="index.php" class="btn btn-sm btn-outline-secondary"><i class="fa fa-arrow-left me-1"></i>Back</a>
</div>

<?php if ($errors): ?>
<div class="alert alert-danger mb-3">
    <?php foreach ($errors as $e) echo '<div><i class="fa fa-circle-exclamation me-2"></i>'.htmlspecialchars($e).'</div>'; ?>
</div>
<?php endif; ?>

<form method="POST" id="reqForm">

<!-- Header -->
<div class="card mb-4">
    <div class="card-header"><i class="fa fa-info-circle me-2"></i>Request Details</div>
    <div class="card-body">
        <div class="row g-3">
            <?php if ($role !== 'mechanic'): ?>
            <div class="col-md-4">
                <label class="form-label">Mechanic <span class="text-danger">*</span></label>
                <select name="mechanic_id" class="form-select select2" required>
                    <option value="">— Select mechanic —</option>
                    <?php foreach ($mechanics as $m): ?>
                    <option value="<?= $m['id'] ?>"><?= e($m['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
            <div class="col-md-4">
                <label class="form-label">Linked Job <span class="text-muted small">(optional)</span></label>
                <select name="job_id" class="form-select select2">
                    <option value="">— No specific job —</option>
                    <?php foreach ($jobs as $j): ?>
                    <option value="<?= $j['id'] ?>" <?= (int)($_POST['job_id'] ?? $preJobId) === (int)$j['id'] ? 'selected' : '' ?>>
                        <?= e($j['job_number']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-<?= $role !== 'mechanic' ? '4' : '8' ?>">
                <label class="form-label">Request Notes</label>
                <input type="text" name="notes" class="form-control" placeholder="e.g. Urgently needed for brake repair on KAA 123X" value="<?= e($_POST['notes'] ?? '') ?>">
            </div>
        </div>
    </div>
</div>

<!-- Parts Line Items -->
<div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="fa fa-list me-2"></i>Parts Needed</span>
        <button type="button" class="btn btn-sm btn-outline-primary" id="addRowBtn">
            <i class="fa fa-plus me-1"></i>Add Part
        </button>
    </div>
    <div class="card-body p-0">
        <table class="table mb-0" id="itemsTable">
            <thead>
                <tr>
                    <th class="ps-4" style="width:35%">Part (from stock or custom)</th>
                    <th style="width:20%">Custom Name <span class="text-muted small">(if not in stock)</span></th>
                    <th style="width:12%">Qty <span class="text-danger">*</span></th>
                    <th style="width:12%">Unit</th>
                    <th>Notes / Purpose</th>
                    <th style="width:48px"></th>
                </tr>
            </thead>
            <tbody id="itemsBody">
                <!-- first row always present -->
                <tr class="item-row">
                    <td class="ps-4">
                        <select name="inventory_id[]" class="form-select form-select-sm select2-inv">
                            <option value="">— Pick from stock —</option>
                            <?php foreach ($invItems as $inv): ?>
                            <option value="<?= $inv['id'] ?>" data-unit="<?= e($inv['unit']) ?>">
                                <?= e($inv['part_name']) ?><?= $inv['part_number'] ? ' (' . e($inv['part_number']) . ')' : '' ?>
                                — <?= number_format((float)$inv['quantity'], 0) ?> in stock
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                    <td>
                        <input type="text" name="custom_part[]" class="form-control form-control-sm custom-part" placeholder="e.g. Oil filter">
                    </td>
                    <td><input type="number" name="qty[]" class="form-control form-control-sm" min="0.01" step="0.01" value="1" required></td>
                    <td><input type="text"   name="unit[]"  class="form-control form-control-sm unit-field" value="piece" placeholder="piece"></td>
                    <td><input type="text"   name="item_notes[]" class="form-control form-control-sm" placeholder="Why this part is needed…"></td>
                    <td class="pe-3"><button type="button" class="btn btn-xs btn-outline-danger remove-row" title="Remove"><i class="fa fa-trash"></i></button></td>
                </tr>
            </tbody>
        </table>
    </div>
</div>

<div class="d-flex justify-content-end gap-2">
    <a href="index.php" class="btn btn-outline-secondary">Cancel</a>
    <button type="submit" class="btn btn-primary px-4">
        <i class="fa fa-paper-plane me-2"></i>Submit Request
    </button>
</div>

</form>

<?php
$invJson = json_encode(array_map(fn($i) => ['id'=>$i['id'],'name'=>$i['part_name'],'pn'=>$i['part_number'],'unit'=>$i['unit'],'qty'=>(float)$i['quantity']], $invItems));
$extraJs = <<<JS
<script>
var invItems = {$invJson};

function initRow(row) {
    var sel = row.querySelector('.select2-inv');
    if (window.jQuery && \$.fn.select2) {
        \$(sel).select2({ theme: 'bootstrap-5', width: '100%' });
        \$(sel).on('change', function () { syncUnit(row, this.value); });
    } else {
        sel.addEventListener('change', function () { syncUnit(row, this.value); });
    }
    row.querySelector('.remove-row').addEventListener('click', function () {
        if (document.querySelectorAll('.item-row').length > 1) row.remove();
    });
}

function syncUnit(row, invId) {
    var item = invItems.find(function(i){ return i.id == invId; });
    if (item) row.querySelector('.unit-field').value = item.unit || 'piece';
}

document.getElementById('addRowBtn').addEventListener('click', function () {
    var first = document.querySelector('.item-row');
    var clone = first.cloneNode(true);
    clone.querySelectorAll('input').forEach(function(i){ i.value = i.name.includes('qty') ? '1' : ''; });
    var sel = clone.querySelector('.select2-inv');
    if (window.jQuery && \$(sel).data('select2')) \$(sel).select2('destroy');
    sel.value = '';
    document.getElementById('itemsBody').appendChild(clone);
    initRow(clone);
    if (window.jQuery && \$.fn.select2) \$(clone).find('.select2-inv').select2({ theme: 'bootstrap-5', width: '100%' });
});

document.querySelectorAll('.item-row').forEach(initRow);
</script>
JS;
include __DIR__ . '/../../includes/footer.php';
?>

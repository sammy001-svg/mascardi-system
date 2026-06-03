<?php
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
canAccess('lpo') || die('Access denied.');
canWrite('lpo') || die('Permission denied.');

$id = (int)($_GET['id'] ?? 0);
if (!$id) redirect(BASE_URL . '/modules/lpo/index.php');
$db = getDB();

$lpo = $db->prepare("SELECT l.*, s.name AS supplier_name FROM lpo l JOIN suppliers s ON s.id=l.supplier_id WHERE l.id=?");
$lpo->execute([$id]); $lpo = $lpo->fetch();
if (!$lpo) { setFlash('error', 'LPO not found.'); redirect(BASE_URL . '/modules/lpo/index.php'); }

if ($lpo['status'] !== 'draft') {
    setFlash('error', 'Only draft LPOs can be edited (current status: ' . $lpo['status'] . ').');
    redirect(BASE_URL . '/modules/lpo/view.php?id=' . $id);
}

$existingItems = $db->prepare("SELECT * FROM lpo_items WHERE lpo_id=? ORDER BY id");
$existingItems->execute([$id]); $existingItems = $existingItems->fetchAll();

// Inline migration: add parts_request_id column to lpo if not yet present
try { $db->exec("ALTER TABLE lpo ADD COLUMN parts_request_id INT NULL"); } catch (\Throwable $_e) {}

$suppliers     = $db->query("SELECT id, name FROM suppliers WHERE status='active' ORDER BY name")->fetchAll();
$partsRequests = $db->query("SELECT id, request_number, client_name, car_make, car_model, car_registration FROM parts_requests ORDER BY id DESC LIMIT 200")->fetchAll();
$inventory     = $db->query("SELECT id, part_number, part_name, unit, unit_price FROM inventory ORDER BY part_name")->fetchAll();

$errors = [];
$d = $lpo;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $d['supplier_id']        = (int)($_POST['supplier_id'] ?? 0);
    $d['parts_request_id']   = $_POST['parts_request_id'] ? (int)$_POST['parts_request_id'] : null;
    $d['date']               = $_POST['date'] ?? date('Y-m-d');
    $d['expected_delivery']  = $_POST['expected_delivery'] ?: null;
    $d['tax_rate']           = (float)($_POST['tax_rate'] ?? 16);
    $d['delivery_address']   = trim($_POST['delivery_address'] ?? '');
    $d['notes']              = trim($_POST['notes'] ?? '');
    $d['approved_by']        = trim($_POST['approved_by'] ?? '');

    $itemDescs  = $_POST['item_desc']   ?? [];
    $itemInvIds = $_POST['item_inv_id'] ?? [];
    $itemQtys   = $_POST['item_qty']    ?? [];
    $itemUnits  = $_POST['item_unit']   ?? [];
    $itemPrices = $_POST['item_price']  ?? [];

    if (!$d['supplier_id']) $errors[] = 'Please select a supplier.';
    if (empty(array_filter($itemDescs))) $errors[] = 'Add at least one item.';

    if (empty($errors)) {
        $db->beginTransaction();
        try {
            $subtotal = 0;
            foreach ($itemQtys as $i => $qty) {
                $subtotal += (float)$qty * (float)($itemPrices[$i] ?? 0);
            }
            $taxAmt = $subtotal * ($d['tax_rate'] / 100);
            $total  = $subtotal + $taxAmt;

            $db->prepare("UPDATE lpo SET
                supplier_id=?, parts_request_id=?, date=?, expected_delivery=?,
                tax_rate=?, subtotal=?, tax_amount=?, total=?,
                delivery_address=?, notes=?, approved_by=?
                WHERE id=?")
               ->execute([
                   $d['supplier_id'], $d['parts_request_id'], $d['date'], $d['expected_delivery'],
                   $d['tax_rate'], $subtotal, $taxAmt, $total,
                   $d['delivery_address'], $d['notes'], $d['approved_by'],
                   $id,
               ]);

            $db->prepare("DELETE FROM lpo_items WHERE lpo_id=?")->execute([$id]);

            $iStmt = $db->prepare("INSERT INTO lpo_items (lpo_id, inventory_id, description, quantity, unit, unit_price, total) VALUES (?,?,?,?,?,?,?)");
            foreach ($itemDescs as $i => $desc) {
                if (!trim($desc)) continue;
                $qty   = (float)($itemQtys[$i] ?? 1);
                $price = (float)($itemPrices[$i] ?? 0);
                $unit  = $itemUnits[$i] ?? 'piece';
                $invId = $itemInvIds[$i] ?: null;
                $iStmt->execute([$id, $invId, $desc, $qty, $unit, $price, $qty * $price]);
            }

            $db->commit();
            logActivity('update', 'lpo', $id, "Updated LPO {$lpo['lpo_number']}");
            setFlash('success', "LPO {$lpo['lpo_number']} updated.");
            redirect(BASE_URL . '/modules/lpo/view.php?id=' . $id);
        } catch (\Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            $errors[] = 'Save failed: ' . $e->getMessage();
        }
    }
}

// Build line rows from POST (on error) or DB
$lineRows = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $itemDescs  = $_POST['item_desc']   ?? [];
    $itemInvIds = $_POST['item_inv_id'] ?? [];
    $itemQtys   = $_POST['item_qty']    ?? [];
    $itemUnits  = $_POST['item_unit']   ?? [];
    $itemPrices = $_POST['item_price']  ?? [];
    foreach ($itemDescs as $i => $_) {
        $lineRows[] = [
            'description'  => $itemDescs[$i] ?? '',
            'inventory_id' => $itemInvIds[$i] ?? '',
            'quantity'     => $itemQtys[$i] ?? 1,
            'unit'         => $itemUnits[$i] ?? 'piece',
            'unit_price'   => $itemPrices[$i] ?? 0,
        ];
    }
} else {
    $lineRows = $existingItems;
}
if (empty($lineRows)) {
    $lineRows = [['description'=>'','inventory_id'=>'','quantity'=>1,'unit'=>'piece','unit_price'=>0]];
}

$vatRate = getSetting('vat_rate', '16');
$pageTitle = 'Edit LPO — ' . $lpo['lpo_number'];
include __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h5 class="mb-1"><i class="fa fa-pen me-2"></i>Edit LPO: <strong><?= e($lpo['lpo_number']) ?></strong></h5>
        <div class="text-muted small"><?= e($lpo['supplier_name']) ?> &mdash; <?= statusBadge($lpo['status']) ?></div>
    </div>
    <a href="view.php?id=<?= $id ?>" class="btn btn-sm btn-outline-secondary"><i class="fa fa-arrow-left me-1"></i>Back</a>
</div>

<?php if ($errors): ?>
<div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $e) echo '<li>'.e($e).'</li>'; ?></ul></div>
<?php endif; ?>

<form method="POST">
<div class="row g-4">
    <div class="col-lg-8">
        <div class="card">
            <div class="card-header"><i class="fa fa-list me-2"></i>Items to Order</div>
            <div class="card-body line-items-wrapper">
                <div class="table-responsive">
                    <table class="table table-sm mb-2">
                        <thead>
                            <tr>
                                <th>Description</th>
                                <th>Part (Stock)</th>
                                <th>Qty</th>
                                <th>Unit</th>
                                <th>Unit Price</th>
                                <th>Total</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody class="line-items-body">
                            <?php foreach ($lineRows as $row): ?>
                            <tr class="line-item-row">
                                <td>
                                    <input type="text" name="item_desc[]" class="form-control form-control-sm item-desc"
                                           value="<?= e($row['description'] ?? '') ?>" placeholder="Item description…" required>
                                </td>
                                <td>
                                    <select name="item_inv_id[]" class="form-select form-select-sm select2 inventory-select" style="min-width:150px">
                                        <option value="">Not in stock</option>
                                        <?php foreach ($inventory as $inv): ?>
                                        <option value="<?= $inv['id'] ?>"
                                                data-price="<?= $inv['unit_price'] ?>"
                                                data-desc="<?= e($inv['part_name']) ?>"
                                                <?= ($row['inventory_id'] ?? '') == $inv['id'] ? 'selected' : '' ?>>
                                            <?= e(($inv['part_number'] ? $inv['part_number'].' — ' : '').$inv['part_name']) ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                                <td><input type="number" name="item_qty[]"   class="form-control form-control-sm item-qty"   style="width:70px" value="<?= e($row['quantity']   ?? 1) ?>" min="0.01" step="0.01"></td>
                                <td><input type="text"   name="item_unit[]"  class="form-control form-control-sm"            style="width:80px" value="<?= e($row['unit']        ?? 'piece') ?>"></td>
                                <td><input type="number" name="item_price[]" class="form-control form-control-sm item-price" style="width:90px" value="<?= e($row['unit_price']  ?? 0) ?>" min="0" step="0.01"></td>
                                <td>
                                    <?php $lineTotal = (float)($row['quantity'] ?? 1) * (float)($row['unit_price'] ?? 0); ?>
                                    <strong class="item-total"><?= number_format($lineTotal, 2) ?></strong>
                                </td>
                                <td><button type="button" class="btn btn-xs btn-outline-danger remove-line-item"><i class="fa fa-times"></i></button></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <button type="button" class="btn btn-sm btn-outline-primary add-line-item"><i class="fa fa-plus me-1"></i>Add Item</button>
            </div>
        </div>

        <div class="card mt-3">
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">Delivery Address</label>
                        <textarea name="delivery_address" class="form-control" rows="2"><?= e($d['delivery_address'] ?? '') ?></textarea>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Notes / Special Instructions</label>
                        <textarea name="notes" class="form-control" rows="2"><?= e($d['notes'] ?? '') ?></textarea>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="card mb-3">
            <div class="card-header">LPO Details</div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-12">
                        <label class="form-label">Supplier <span class="text-danger">*</span></label>
                        <select name="supplier_id" class="form-select select2" required>
                            <option value="">Select supplier…</option>
                            <?php foreach ($suppliers as $s): ?>
                            <option value="<?= $s['id'] ?>" <?= $d['supplier_id'] == $s['id'] ? 'selected' : '' ?>><?= e($s['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12">
                        <label class="form-label">Linked Quote Request</label>
                        <select name="parts_request_id" class="form-select select2">
                            <option value="">None</option>
                            <?php foreach ($partsRequests as $pr): ?>
                            <option value="<?= (int)$pr['id'] ?>" <?= ($d['parts_request_id'] ?? '') == $pr['id'] ? 'selected' : '' ?>>
                                <?= e($pr['request_number']) ?>
                                <?= $pr['client_name'] ? ' — '.e($pr['client_name']) : '' ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-6">
                        <label class="form-label">LPO Date</label>
                        <input type="date" name="date" class="form-control" value="<?= e($d['date'] ?? date('Y-m-d')) ?>">
                    </div>
                    <div class="col-6">
                        <label class="form-label">Expected Delivery</label>
                        <input type="date" name="expected_delivery" class="form-control" value="<?= e($d['expected_delivery'] ?? '') ?>">
                    </div>
                    <div class="col-12">
                        <label class="form-label">Approved By</label>
                        <input type="text" name="approved_by" class="form-control" value="<?= e($d['approved_by'] ?? '') ?>">
                    </div>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header"><i class="fa fa-calculator me-2"></i>Totals</div>
            <div class="card-body">
                <table class="table table-sm mb-0">
                    <tr>
                        <td class="text-muted">Subtotal</td>
                        <td class="text-end fw-semibold">KES <span id="subtotal_display">0.00</span></td>
                    </tr>
                    <tr>
                        <td class="text-muted">VAT</td>
                        <td class="text-end">
                            <div class="input-group input-group-sm justify-content-end" style="max-width:110px;margin-left:auto">
                                <input type="number" id="tax_rate" name="tax_rate" class="form-control form-control-sm"
                                       value="<?= e($d['tax_rate'] ?? $vatRate) ?>" min="0" max="100" step="0.01">
                                <span class="input-group-text">%</span>
                            </div>
                            <div class="mt-1">KES <span id="tax_display">0.00</span></div>
                        </td>
                    </tr>
                    <tr class="table-warning">
                        <td><strong>Total</strong></td>
                        <td class="text-end"><strong>KES <span id="total_display">0.00</span></strong></td>
                    </tr>
                </table>
                <input type="hidden" id="overall_discount" value="0">
                <input type="hidden" id="hidden_subtotal" name="hidden_subtotal">
                <input type="hidden" id="hidden_discount" name="hidden_discount">
                <input type="hidden" id="hidden_tax"      name="hidden_tax">
                <input type="hidden" id="hidden_total"    name="hidden_total">
                <button type="submit" class="btn btn-warning w-100 mt-3 text-dark"><i class="fa fa-save me-1"></i>Save Changes</button>
            </div>
        </div>
    </div>
</div>
</form>

<?php
$extraJs = <<<'JS'
<script>
$(function() { recalcTotals && recalcTotals(); });
</script>
JS;
include __DIR__ . '/../../includes/footer.php';
?>

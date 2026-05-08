<?php
require_once __DIR__ . '/../../includes/functions.php';
$pageTitle = 'Create LPO';
$db = getDB();
$errors = [];
$preJobId = (int)($_GET['job_id'] ?? 0);

$suppliers = $db->query("SELECT id, name FROM suppliers WHERE status='active' ORDER BY name")->fetchAll();
$jobs      = $db->query("SELECT id, job_number FROM workshop_jobs WHERE status NOT IN ('completed','cancelled') ORDER BY job_number")->fetchAll();
$inventory = $db->query("SELECT id, part_number, part_name, unit, unit_price FROM inventory ORDER BY part_name")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $suppId   = (int)($_POST['supplier_id'] ?? 0);
    $jobId    = $_POST['job_id'] ? (int)$_POST['job_id'] : null;
    $date     = $_POST['date'] ?? date('Y-m-d');
    $expDel   = $_POST['expected_delivery'] ?: null;
    $taxRate  = (float)($_POST['tax_rate'] ?? 16);
    $delAddr  = trim($_POST['delivery_address'] ?? '');
    $notes    = trim($_POST['notes'] ?? '');
    $approvedBy = trim($_POST['approved_by'] ?? '');

    $itemDescs  = $_POST['item_desc'] ?? [];
    $itemInvIds = $_POST['item_inv_id'] ?? [];
    $itemQtys   = $_POST['item_qty'] ?? [];
    $itemUnits  = $_POST['item_unit'] ?? [];
    $itemPrices = $_POST['item_price'] ?? [];

    if (!$suppId) $errors[] = 'Please select a supplier.';
    if (empty(array_filter($itemDescs))) $errors[] = 'Add at least one item.';

    if (empty($errors)) {
        $db->beginTransaction();
        try {
            $subtotal = 0;
            foreach ($itemQtys as $i => $qty) { $subtotal += $qty * (float)($itemPrices[$i] ?? 0); }
            $taxAmt = $subtotal * ($taxRate / 100);
            $total  = $subtotal + $taxAmt;

            $lpoNum = nextNumber('lpo','lpo_number', getSetting('lpo_prefix','LPO'));
            $db->prepare("INSERT INTO lpo (lpo_number,supplier_id,job_id,date,expected_delivery,tax_rate,subtotal,tax_amount,total,delivery_address,notes,approved_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)")
               ->execute([$lpoNum,$suppId,$jobId,$date,$expDel,$taxRate,$subtotal,$taxAmt,$total,$delAddr,$notes,$approvedBy]);
            $lpoId = $db->lastInsertId();

            $iStmt = $db->prepare("INSERT INTO lpo_items (lpo_id,inventory_id,description,quantity,unit,unit_price,total) VALUES (?,?,?,?,?,?,?)");
            foreach ($itemDescs as $i => $desc) {
                if (!$desc) continue;
                $qty   = (float)($itemQtys[$i] ?? 1);
                $price = (float)($itemPrices[$i] ?? 0);
                $unit  = $itemUnits[$i] ?? 'piece';
                $invId = $itemInvIds[$i] ?: null;
                $iStmt->execute([$lpoId,$invId,$desc,$qty,$unit,$price,$qty*$price]);
            }
            $db->commit();
            setFlash('success',"LPO {$lpoNum} created.");
            redirect(BASE_URL.'/modules/lpo/view.php?id='.$lpoId);
        } catch(Exception $e){ $db->rollBack(); $errors[]=$e->getMessage(); }
    }
}
$vatRate = getSetting('vat_rate','16');
include __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="mb-0">Create Local Purchase Order</h5>
    <a href="index.php" class="btn btn-sm btn-outline-secondary"><i class="fa fa-arrow-left me-1"></i>Back</a>
</div>
<?php if($errors): ?><div class="alert alert-danger"><ul class="mb-0"><?php foreach($errors as $err) echo "<li>".e($err)."</li>"; ?></ul></div><?php endif; ?>

<form method="POST">
<div class="row g-4">
    <div class="col-lg-8">
        <div class="card">
            <div class="card-header"><i class="fa fa-list me-2"></i>Items to Order</div>
            <div class="card-body line-items-wrapper">
                <div class="table-responsive">
                    <table class="table table-sm mb-2">
                        <thead><tr><th>Description</th><th>Part (Stock)</th><th>Qty</th><th>Unit</th><th>Unit Price</th><th>Total</th><th></th></tr></thead>
                        <tbody class="line-items-body">
                            <?php $lineItems = isset($_POST['item_desc']) ? array_keys($_POST['item_desc']) : [0]; ?>
                            <?php foreach($lineItems as $i): ?>
                            <tr class="line-item-row">
                                <td><input type="text" name="item_desc[]" class="form-control form-control-sm item-desc" value="<?= e($_POST['item_desc'][$i]??'') ?>" placeholder="Item description..." required></td>
                                <td>
                                    <select name="item_inv_id[]" class="form-select form-select-sm select2 inventory-select" style="min-width:150px">
                                        <option value="">Not in stock</option>
                                        <?php foreach($inventory as $inv): ?>
                                        <option value="<?= $inv['id'] ?>" data-price="<?= $inv['unit_price'] ?>" data-desc="<?= e($inv['part_name']) ?>" <?= ($_POST['item_inv_id'][$i]??'')==$inv['id']?'selected':'' ?>>
                                            <?= e(($inv['part_number']?$inv['part_number'].' — ':'').$inv['part_name']) ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                                <td><input type="number" name="item_qty[]" class="form-control form-control-sm item-qty" style="width:70px" value="<?= e($_POST['item_qty'][$i]??1) ?>" min="0.01" step="0.01"></td>
                                <td><input type="text" name="item_unit[]" class="form-control form-control-sm" style="width:80px" value="<?= e($_POST['item_unit'][$i]??'piece') ?>"></td>
                                <td><input type="number" name="item_price[]" class="form-control form-control-sm item-price" style="width:90px" value="<?= e($_POST['item_price'][$i]??0) ?>" min="0" step="0.01"></td>
                                <td><strong class="item-total">0.00</strong></td>
                                <td><button type="button" class="btn btn-xs btn-outline-danger remove-line-item"><i class="fa fa-times"></i></button></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <button type="button" class="btn btn-sm btn-outline-primary add-line-item"><i class="fa fa-plus me-1"></i>Add Item</button>
            </div>
        </div>
        <div class="card mt-3"><div class="card-body">
            <div class="row g-3">
                <div class="col-md-6"><label class="form-label">Delivery Address</label><textarea name="delivery_address" class="form-control" rows="2" placeholder="Nairobi Workshop, ..."><?= e($_POST['delivery_address']??'') ?></textarea></div>
                <div class="col-md-6"><label class="form-label">Notes / Special Instructions</label><textarea name="notes" class="form-control" rows="2"><?= e($_POST['notes']??'') ?></textarea></div>
            </div>
        </div></div>
    </div>

    <div class="col-lg-4">
        <div class="card mb-3"><div class="card-header">LPO Details</div><div class="card-body">
            <div class="row g-3">
                <div class="col-12"><label class="form-label">Supplier <span class="text-danger">*</span></label>
                    <select name="supplier_id" class="form-select select2" required>
                        <option value="">Select supplier...</option>
                        <?php foreach($suppliers as $s): ?><option value="<?= $s['id'] ?>" <?= ($_POST['supplier_id']??'')==$s['id']?'selected':'' ?>><?= e($s['name']) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12"><label class="form-label">Linked Job Card</label>
                    <select name="job_id" class="form-select select2">
                        <option value="">None</option>
                        <?php foreach($jobs as $j): ?><option value="<?= $j['id'] ?>" <?= (($_POST['job_id']??$preJobId)==$j['id'])?'selected':'' ?>><?= e($j['job_number']) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="col-6"><label class="form-label">LPO Date</label><input type="date" name="date" class="form-control" value="<?= e($_POST['date']??date('Y-m-d')) ?>"></div>
                <div class="col-6"><label class="form-label">Expected Delivery</label><input type="date" name="expected_delivery" class="form-control" value="<?= e($_POST['expected_delivery']??'') ?>"></div>
                <div class="col-12"><label class="form-label">Approved By</label><input type="text" name="approved_by" class="form-control" value="<?= e($_POST['approved_by']??'') ?>"></div>
            </div>
        </div></div>

        <div class="card"><div class="card-header"><i class="fa fa-calculator me-2"></i>Totals</div><div class="card-body">
            <table class="table table-sm mb-0">
                <tr><td class="text-muted">Subtotal</td><td class="text-end fw-semibold">KES <span id="subtotal_display">0.00</span></td></tr>
                <tr><td class="text-muted">VAT</td>
                    <td class="text-end">
                        <div class="input-group input-group-sm justify-content-end" style="max-width:110px;margin-left:auto"><input type="number" id="tax_rate" name="tax_rate" class="form-control form-control-sm" value="<?= e($_POST['tax_rate']??$vatRate) ?>" min="0" max="100" step="0.01"><span class="input-group-text">%</span></div>
                        <div class="mt-1">KES <span id="tax_display">0.00</span></div>
                    </td>
                </tr>
                <tr class="table-warning"><td><strong>Total</strong></td><td class="text-end"><strong>KES <span id="total_display">0.00</span></strong></td></tr>
            </table>
            <input type="hidden" id="overall_discount" value="0">
            <input type="hidden" id="hidden_subtotal" name="hidden_subtotal"><input type="hidden" id="hidden_discount" name="hidden_discount"><input type="hidden" id="hidden_tax" name="hidden_tax"><input type="hidden" id="hidden_total" name="hidden_total">
            <button type="submit" class="btn btn-warning w-100 mt-3 text-dark"><i class="fa fa-save me-1"></i>Save LPO</button>
        </div></div>
    </div>
</div>
</form>
<?php include __DIR__ . '/../../includes/footer.php'; ?>

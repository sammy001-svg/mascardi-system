<?php
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
canAccess('quotations') || die('Access denied.');
canWrite('quotations') || die('Permission denied.');

$id = (int)($_GET['id'] ?? 0);
if (!$id) redirect(BASE_URL . '/modules/quotations/index.php');
$db = getDB();

$q = $db->prepare("SELECT q.*, c.make, c.model, c.chassis_number FROM quotations q JOIN cars c ON c.id=q.car_id WHERE q.id=?");
$q->execute([$id]); $q = $q->fetch();
if (!$q) { setFlash('error', 'Quotation not found.'); redirect(BASE_URL . '/modules/quotations/index.php'); }

if (in_array($q['status'], ['approved', 'rejected', 'converted'])) {
    setFlash('error', 'This quotation cannot be edited in its current status (' . $q['status'] . ').');
    redirect(BASE_URL . '/modules/quotations/view.php?id=' . $id);
}

$existingItems = $db->prepare("SELECT * FROM quotation_items WHERE quotation_id=? ORDER BY id");
$existingItems->execute([$id]); $existingItems = $existingItems->fetchAll();

$cars      = $db->query("SELECT id, chassis_number, registration_number, make, model, owner_name, owner_phone, car_type FROM cars ORDER BY make")->fetchAll();
$jobs      = $db->query("SELECT id, job_number, car_id FROM workshop_jobs WHERE status NOT IN ('completed','cancelled') ORDER BY job_number")->fetchAll();
$clients   = $db->query("SELECT id, name, phone, email FROM clients WHERE status='active' ORDER BY name")->fetchAll();
$inventory = $db->query("SELECT id, part_number, part_name, selling_price FROM inventory ORDER BY part_name")->fetchAll();

$errors = [];

// Working data — start from DB values, overwrite on POST
$d = $q;
// Reverse-calculate discount percentage from stored KES amount
$d['overall_discount'] = ($q['subtotal'] > 0)
    ? round(((float)$q['discount'] / (float)$q['subtotal']) * 100, 4)
    : 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $d['car_id']          = (int)($_POST['car_id'] ?? 0);
    $d['job_id']          = $_POST['job_id'] ? (int)$_POST['job_id'] : null;
    $d['client_id']       = $_POST['client_id'] ? (int)$_POST['client_id'] : null;
    $d['date']            = $_POST['date'] ?? date('Y-m-d');
    $d['valid_until']     = $_POST['valid_until'] ?: null;
    $d['customer_name']   = trim($_POST['customer_name'] ?? '');
    $d['customer_phone']  = trim($_POST['customer_phone'] ?? '');
    $d['customer_email']  = trim($_POST['customer_email'] ?? '');
    $d['tax_rate']        = (float)($_POST['tax_rate'] ?? 16);
    $d['overall_discount']= (float)($_POST['overall_discount'] ?? 0);
    $d['notes']           = trim($_POST['notes'] ?? '');
    $d['terms']           = trim($_POST['terms'] ?? '');

    $itemDescs   = $_POST['item_desc']   ?? [];
    $itemTypes   = $_POST['item_type']   ?? [];
    $itemInvIds  = $_POST['item_inv_id'] ?? [];
    $itemQtys    = $_POST['item_qty']    ?? [];
    $itemPrices  = $_POST['item_price']  ?? [];
    $itemDiscs   = $_POST['item_disc']   ?? [];

    if (!$d['car_id']) $errors[] = 'Please select a vehicle.';
    if (empty($itemDescs) || !array_filter($itemDescs)) $errors[] = 'Add at least one line item.';

    if (empty($errors)) {
        $db->beginTransaction();
        try {
            // Recompute totals
            $subtotal = 0;
            foreach ($itemQtys as $i => $qty) {
                $price = (float)($itemPrices[$i] ?? 0);
                $disc  = (float)($itemDiscs[$i] ?? 0);
                $subtotal += (float)$qty * $price * (1 - $disc / 100);
            }
            $discAmt = $subtotal * ($d['overall_discount'] / 100);
            $taxable = $subtotal - $discAmt;
            $taxAmt  = $taxable * ($d['tax_rate'] / 100);
            $total   = $taxable + $taxAmt;

            $db->prepare("UPDATE quotations SET
                car_id=?, job_id=?, client_id=?,
                date=?, valid_until=?,
                customer_name=?, customer_phone=?, customer_email=?,
                subtotal=?, discount=?, tax_rate=?, tax_amount=?, total=?,
                notes=?, terms=?
                WHERE id=?")
               ->execute([
                   $d['car_id'], $d['job_id'], $d['client_id'],
                   $d['date'], $d['valid_until'],
                   $d['customer_name'], $d['customer_phone'], $d['customer_email'],
                   $subtotal, $discAmt, $d['tax_rate'], $taxAmt, $total,
                   $d['notes'], $d['terms'],
                   $id,
               ]);

            // Replace all line items
            $db->prepare("DELETE FROM quotation_items WHERE quotation_id=?")->execute([$id]);

            $iStmt = $db->prepare("INSERT INTO quotation_items (quotation_id, item_type, inventory_id, description, quantity, unit_price, discount, total) VALUES (?,?,?,?,?,?,?,?)");
            foreach ($itemDescs as $i => $desc) {
                if (!trim($desc)) continue;
                $qty   = (float)($itemQtys[$i] ?? 1);
                $price = (float)($itemPrices[$i] ?? 0);
                $disc  = (float)($itemDiscs[$i] ?? 0);
                $tot   = $qty * $price * (1 - $disc / 100);
                $invId = $itemInvIds[$i] ?: null;
                $type  = $itemTypes[$i] ?? 'part';
                $iStmt->execute([$id, $type, $invId, $desc, $qty, $price, $disc, $tot]);
            }

            $db->commit();
            logActivity('update', 'quotations', $id, "Updated quotation {$q['quotation_number']}");
            setFlash('success', 'Quotation updated successfully.');
            redirect(BASE_URL . '/modules/quotations/view.php?id=' . $id);
        } catch (\Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            $errors[] = 'Save failed: ' . $e->getMessage();
        }
    }
}

// Build line item rows from POST (on error) or DB (first load)
$lineRows = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $itemDescs  = $_POST['item_desc']   ?? [];
    $itemTypes  = $_POST['item_type']   ?? [];
    $itemInvIds = $_POST['item_inv_id'] ?? [];
    $itemQtys   = $_POST['item_qty']    ?? [];
    $itemPrices = $_POST['item_price']  ?? [];
    $itemDiscs  = $_POST['item_disc']   ?? [];
    foreach ($itemDescs as $i => $_) {
        $lineRows[] = [
            'item_type'    => $itemTypes[$i] ?? 'part',
            'inventory_id' => $itemInvIds[$i] ?? '',
            'description'  => $itemDescs[$i] ?? '',
            'quantity'     => $itemQtys[$i] ?? 1,
            'unit_price'   => $itemPrices[$i] ?? 0,
            'discount'     => $itemDiscs[$i] ?? 0,
        ];
    }
} else {
    $lineRows = $existingItems;
}
if (empty($lineRows)) {
    $lineRows = [['item_type'=>'part','inventory_id'=>'','description'=>'','quantity'=>1,'unit_price'=>0,'discount'=>0]];
}

$vatRate = getSetting('vat_rate', '16');
$pageTitle = 'Edit Quotation — ' . $q['quotation_number'];
include __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h5 class="mb-1"><i class="fa fa-pen me-2"></i>Edit Quotation: <strong><?= e($q['quotation_number']) ?></strong></h5>
        <div class="text-muted small"><?= e($q['make'].' '.$q['model']) ?> &mdash; <?= statusBadge($q['status']) ?></div>
    </div>
    <a href="view.php?id=<?= $id ?>" class="btn btn-sm btn-outline-secondary"><i class="fa fa-arrow-left me-1"></i>Back</a>
</div>

<?php if ($errors): ?>
<div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $err) echo '<li>'.e($err).'</li>'; ?></ul></div>
<?php endif; ?>

<form method="POST">
<div class="row g-4">
    <!-- Left: line items + notes -->
    <div class="col-lg-8">
        <div class="card">
            <div class="card-header"><i class="fa fa-list me-2"></i>Line Items</div>
            <div class="card-body line-items-wrapper">
                <div class="table-responsive">
                    <table class="table table-sm mb-2" id="lineItemsTable">
                        <thead>
                            <tr>
                                <th>Type</th>
                                <th style="width:30%">Description</th>
                                <th>Part</th>
                                <th>Qty</th>
                                <th>Unit Price</th>
                                <th>Disc%</th>
                                <th>Total</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody class="line-items-body">
                            <?php foreach ($lineRows as $row): ?>
                            <tr class="line-item-row">
                                <td>
                                    <select name="item_type[]" class="form-select form-select-sm" style="width:100px">
                                        <option value="part"    <?= ($row['item_type']??'part')==='part'   ?'selected':'' ?>>Part</option>
                                        <option value="labour"  <?= ($row['item_type']??'')==='labour'     ?'selected':'' ?>>Labour</option>
                                        <option value="service" <?= ($row['item_type']??'')==='service'    ?'selected':'' ?>>Service</option>
                                    </select>
                                </td>
                                <td>
                                    <input type="text" name="item_desc[]" class="form-control form-control-sm item-desc"
                                           value="<?= e($row['description'] ?? '') ?>" placeholder="Description…" required>
                                </td>
                                <td>
                                    <select name="item_inv_id[]" class="form-select form-select-sm select2 inventory-select" style="min-width:150px">
                                        <option value="">From stock…</option>
                                        <?php foreach ($inventory as $inv): ?>
                                        <option value="<?= $inv['id'] ?>"
                                                data-price="<?= $inv['selling_price'] ?>"
                                                data-desc="<?= e($inv['part_name']) ?>"
                                                <?= ($row['inventory_id'] ?? '') == $inv['id'] ? 'selected' : '' ?>>
                                            <?= e(($inv['part_number'] ? $inv['part_number'].' — ' : '').$inv['part_name']) ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                                <td><input type="number" name="item_qty[]"   class="form-control form-control-sm item-qty"      style="width:65px"  value="<?= e($row['quantity']   ?? 1) ?>" min="0.01" step="0.01"></td>
                                <td><input type="number" name="item_price[]" class="form-control form-control-sm item-price"    style="width:90px"  value="<?= e($row['unit_price'] ?? 0) ?>" min="0"    step="0.01"></td>
                                <td><input type="number" name="item_disc[]"  class="form-control form-control-sm item-discount" style="width:65px"  value="<?= e($row['discount']   ?? 0) ?>" min="0" max="100" step="0.01"></td>
                                <td>
                                    <?php
                                    $qty   = (float)($row['quantity'] ?? 1);
                                    $price = (float)($row['unit_price'] ?? 0);
                                    $disc  = (float)($row['discount'] ?? 0);
                                    ?>
                                    <strong class="item-total"><?= number_format($qty * $price * (1 - $disc / 100), 2) ?></strong>
                                </td>
                                <td><button type="button" class="btn btn-xs btn-outline-danger remove-line-item"><i class="fa fa-times"></i></button></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <button type="button" class="btn btn-sm btn-outline-primary add-line-item"><i class="fa fa-plus me-1"></i>Add Line</button>
            </div>
        </div>

        <div class="card mt-3">
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">Notes</label>
                        <textarea name="notes" class="form-control" rows="3"><?= e($d['notes'] ?? '') ?></textarea>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Terms &amp; Conditions</label>
                        <textarea name="terms" class="form-control" rows="3"><?= e($d['terms'] ?? 'This quotation is valid for 30 days.') ?></textarea>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Right: info + totals -->
    <div class="col-lg-4">
        <div class="card mb-3">
            <div class="card-header"><i class="fa fa-car me-2"></i>Quotation Info</div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-12">
                        <label class="form-label">Vehicle <span class="text-danger">*</span></label>
                        <select name="car_id" id="car_select" class="form-select select2" required>
                            <option value="">Select car…</option>
                            <?php foreach ($cars as $c): ?>
                            <option value="<?= $c['id'] ?>"
                                    data-type="<?= $c['car_type'] ?>"
                                    data-owner="<?= e($c['owner_name'] ?? '') ?>"
                                    data-phone="<?= e($c['owner_phone'] ?? '') ?>"
                                    <?= ($d['car_id'] == $c['id']) ? 'selected' : '' ?>>
                                <?= e($c['make'].' '.$c['model'].' — '.$c['chassis_number']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12">
                        <label class="form-label">Job Card <small class="text-muted">(optional)</small></label>
                        <select name="job_id" class="form-select select2">
                            <option value="">Select job…</option>
                            <?php foreach ($jobs as $j): ?>
                            <option value="<?= $j['id'] ?>" <?= ($d['job_id'] == $j['id']) ? 'selected' : '' ?>><?= e($j['job_number']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-6">
                        <label class="form-label">Date</label>
                        <input type="date" name="date" class="form-control" value="<?= e($d['date'] ?? date('Y-m-d')) ?>">
                    </div>
                    <div class="col-6">
                        <label class="form-label">Valid Until</label>
                        <input type="date" name="valid_until" class="form-control" value="<?= e($d['valid_until'] ?? date('Y-m-d', strtotime('+30 days'))) ?>">
                    </div>
                    <div class="col-12">
                        <label class="form-label">Link to Client <small class="text-muted">(optional)</small></label>
                        <select name="client_id" id="client_select" class="form-select select2">
                            <option value="">— No client —</option>
                            <?php foreach ($clients as $cl): ?>
                            <option value="<?= $cl['id'] ?>"
                                    data-name="<?= e($cl['name']) ?>"
                                    data-email="<?= e($cl['email']) ?>"
                                    data-phone="<?= e($cl['phone']) ?>"
                                    <?= ($d['client_id'] == $cl['id']) ? 'selected' : '' ?>>
                                <?= e($cl['name']) ?><?= $cl['phone'] ? ' — '.$cl['phone'] : '' ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12">
                        <label class="form-label">Customer Name</label>
                        <input type="text" id="customer_name" name="customer_name" class="form-control" value="<?= e($d['customer_name'] ?? '') ?>">
                    </div>
                    <div class="col-6">
                        <label class="form-label">Phone</label>
                        <input type="text" id="customer_phone" name="customer_phone" class="form-control" value="<?= e($d['customer_phone'] ?? '') ?>">
                    </div>
                    <div class="col-6">
                        <label class="form-label">Email</label>
                        <input type="email" name="customer_email" class="form-control" value="<?= e($d['customer_email'] ?? '') ?>">
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
                        <td class="text-muted">Discount</td>
                        <td class="text-end">
                            <div class="input-group input-group-sm justify-content-end" style="max-width:120px;margin-left:auto">
                                <input type="number" id="overall_discount" name="overall_discount" class="form-control form-control-sm"
                                       value="<?= e($d['overall_discount'] ?? 0) ?>" min="0" max="100" step="0.01">
                                <span class="input-group-text">%</span>
                            </div>
                            <div class="mt-1 text-end">KES <span id="discount_display">0.00</span></div>
                        </td>
                    </tr>
                    <tr>
                        <td class="text-muted">VAT</td>
                        <td class="text-end">
                            <div class="input-group input-group-sm justify-content-end" style="max-width:120px;margin-left:auto">
                                <input type="number" id="tax_rate" name="tax_rate" class="form-control form-control-sm"
                                       value="<?= e($d['tax_rate'] ?? $vatRate) ?>" min="0" max="100" step="0.01">
                                <span class="input-group-text">%</span>
                            </div>
                            <div class="mt-1 text-end">KES <span id="tax_display">0.00</span></div>
                        </td>
                    </tr>
                    <tr class="table-primary">
                        <td><strong>Total</strong></td>
                        <td class="text-end"><strong>KES <span id="total_display">0.00</span></strong></td>
                    </tr>
                </table>
                <input type="hidden" id="hidden_subtotal" name="hidden_subtotal">
                <input type="hidden" id="hidden_discount" name="hidden_discount">
                <input type="hidden" id="hidden_tax" name="hidden_tax">
                <input type="hidden" id="hidden_total" name="hidden_total">
                <button type="submit" class="btn btn-primary w-100 mt-3"><i class="fa fa-save me-1"></i>Save Changes</button>
            </div>
        </div>
    </div>
</div>
</form>

<?php
$extraJs = <<<'JS'
<script>
$(document).on('change', '#client_select', function() {
    var opt = $(this).find('option:selected');
    if (opt.val()) {
        $('#customer_name').val(opt.data('name'));
        $('#customer_phone').val(opt.data('phone'));
        $('input[name="customer_email"]').val(opt.data('email'));
    }
});
$(document).on('change', '#car_select', function() {
    var opt = $(this).find('option:selected');
    if (opt.data('type') === 'client') {
        $('#customer_name').val(opt.data('owner'));
        $('#customer_phone').val(opt.data('phone'));
    }
});
$(function() {
    recalcTotals && recalcTotals();
});
</script>
JS;
include __DIR__ . '/../../includes/footer.php';
?>

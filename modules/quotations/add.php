<?php
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
canAccess('quotations') || die('Access denied.');
canWrite('quotations') || die('Permission denied.');
$pageTitle = 'New Quotation';
$db = getDB();
$errors = [];
$preCarId = (int)($_GET['car_id'] ?? 0);
$preJobId = (int)($_GET['job_id'] ?? 0);

$cars      = $db->query("SELECT id, chassis_number, registration_number, make, model, owner_name, owner_phone, owner_email FROM cars ORDER BY make ASC")->fetchAll();
$jobs      = $db->query("SELECT id, job_number, car_id FROM workshop_jobs WHERE status NOT IN ('completed','cancelled') ORDER BY job_number")->fetchAll();
$clients   = $db->query("SELECT id, name, phone, email FROM clients WHERE status='active' ORDER BY name ASC")->fetchAll();
$inventory = $db->query("SELECT id, part_number, part_name, selling_price FROM inventory ORDER BY part_name")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $carId    = (int)($_POST['car_id'] ?? 0);
    $jobId    = $_POST['job_id'] ? (int)$_POST['job_id'] : null;
    $clientId = $_POST['client_id'] ? (int)$_POST['client_id'] : null;
    $date     = $_POST['date'] ?? date('Y-m-d');
    $validUntil = $_POST['valid_until'] ?: null;
    $custName = trim($_POST['customer_name'] ?? '');
    $custPhone= trim($_POST['customer_phone'] ?? '');
    $custEmail= trim($_POST['customer_email'] ?? '');
    $taxRate  = (float)($_POST['tax_rate'] ?? 16);
    $discount = (float)($_POST['overall_discount'] ?? 0);
    $notes    = trim($_POST['notes'] ?? '');
    $terms    = trim($_POST['terms'] ?? '');

    $itemDescs   = $_POST['item_desc']  ?? [];
    $itemTypes   = $_POST['item_type']  ?? [];
    $itemInvIds  = $_POST['item_inv_id']?? [];
    $itemQtys    = $_POST['item_qty']   ?? [];
    $itemPrices  = $_POST['item_price'] ?? [];
    $itemDiscs   = $_POST['item_disc']  ?? [];

    if (!$carId) $errors[] = 'Please select a car.';
    if (empty($itemDescs) || !array_filter($itemDescs)) $errors[] = 'Add at least one line item.';

    if (empty($errors)) {
        $db->beginTransaction();
        try {
            // Compute totals
            $subtotal = 0;
            foreach ($itemQtys as $i => $qty) {
                $price = (float)($itemPrices[$i] ?? 0);
                $disc  = (float)($itemDiscs[$i] ?? 0);
                $subtotal += $qty * $price * (1 - $disc/100);
            }
            $discAmt  = $subtotal * ($discount / 100);
            $taxable  = $subtotal - $discAmt;
            $taxAmt   = $taxable * ($taxRate / 100);
            $total    = $taxable + $taxAmt;

            $qNum = nextNumber('quotations','quotation_number', getSetting('quotation_prefix','QT'));
            $db->prepare("INSERT INTO quotations (quotation_number,car_id,job_id,client_id,date,valid_until,customer_name,customer_phone,customer_email,subtotal,discount,tax_rate,tax_amount,total,notes,terms) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
               ->execute([$qNum,$carId,$jobId,$clientId,$date,$validUntil,$custName,$custPhone,$custEmail,$subtotal,$discAmt,$taxRate,$taxAmt,$total,$notes,$terms]);
            $qId = $db->lastInsertId();

            $iStmt = $db->prepare("INSERT INTO quotation_items (quotation_id,item_type,inventory_id,description,quantity,unit_price,discount,total) VALUES (?,?,?,?,?,?,?,?)");
            foreach ($itemDescs as $i => $desc) {
                if (!$desc) continue;
                $qty   = (float)($itemQtys[$i] ?? 1);
                $price = (float)($itemPrices[$i] ?? 0);
                $disc  = (float)($itemDiscs[$i] ?? 0);
                $tot   = $qty * $price * (1 - $disc/100);
                $invId = $itemInvIds[$i] ?: null;
                $type  = $itemTypes[$i] ?? 'part';
                $iStmt->execute([$qId,$type,$invId,$desc,$qty,$price,$disc,$tot]);
            }
            $db->commit();
            setFlash('success',"Quotation {$qNum} created.");
            redirect(BASE_URL.'/modules/quotations/view.php?id='.$qId);
        } catch (\Throwable $e) {
            if($db->inTransaction()) $db->rollBack(); $errors[] = $e->getMessage();
        }
    }
}
$vatRate = getSetting('vat_rate','16');
include __DIR__ . '/../../includes/header.php';
$extraJs = '<script>
$(function(){
    // Recalc on load
    recalcTotals && recalcTotals();
});
</script>';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="mb-0">New Quotation</h5>
    <a href="index.php" class="btn btn-sm btn-outline-secondary"><i class="fa fa-arrow-left me-1"></i>Back</a>
</div>
<?php if ($errors): ?><div class="alert alert-danger"><ul class="mb-0"><?php foreach($errors as $err) echo "<li>".e($err)."</li>"; ?></ul></div><?php endif; ?>

<form method="POST">
<div class="row g-4">
    <div class="col-lg-8">
        <!-- Line Items -->
        <div class="card">
            <div class="card-header"><i class="fa fa-list me-2"></i>Line Items</div>
            <div class="card-body line-items-wrapper">
                <div class="table-responsive">
                    <table class="table table-sm mb-2" id="lineItemsTable">
                        <thead><tr><th>Type</th><th style="width:30%">Description</th><th>Part</th><th>Qty</th><th>Unit Price</th><th>Disc%</th><th>Total</th><th></th></tr></thead>
                        <tbody class="line-items-body">
                            <?php $lineItems = isset($_POST['item_desc']) ? array_keys($_POST['item_desc']) : [0]; ?>
                            <?php foreach ($lineItems as $i): ?>
                            <tr class="line-item-row">
                                <td>
                                    <select name="item_type[]" class="form-select form-select-sm" style="width:100px">
                                        <option value="part" <?= ($_POST['item_type'][$i]??'part')==='part'?'selected':'' ?>>Part</option>
                                        <option value="labour" <?= ($_POST['item_type'][$i]??'')==='labour'?'selected':'' ?>>Labour</option>
                                        <option value="service" <?= ($_POST['item_type'][$i]??'')==='service'?'selected':'' ?>>Service</option>
                                    </select>
                                </td>
                                <td><input type="text" name="item_desc[]" class="form-control form-control-sm item-desc" value="<?= e($_POST['item_desc'][$i]??'') ?>" placeholder="Description..." required></td>
                                <td>
                                    <select name="item_inv_id[]" class="form-select form-select-sm select2 inventory-select" style="min-width:150px">
                                        <option value="">From stock...</option>
                                        <?php foreach ($inventory as $inv): ?>
                                        <option value="<?= $inv['id'] ?>" data-price="<?= $inv['selling_price'] ?>" data-desc="<?= e($inv['part_name']) ?>" <?= ($_POST['item_inv_id'][$i]??'')==$inv['id']?'selected':'' ?>>
                                            <?= e(($inv['part_number']?$inv['part_number'].' — ':'').$inv['part_name']) ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                                <td><input type="number" name="item_qty[]" class="form-control form-control-sm item-qty" style="width:65px" value="<?= e($_POST['item_qty'][$i]??1) ?>" min="0.01" step="0.01"></td>
                                <td><input type="number" name="item_price[]" class="form-control form-control-sm item-price" style="width:90px" value="<?= e($_POST['item_price'][$i]??0) ?>" min="0" step="0.01"></td>
                                <td><input type="number" name="item_disc[]" class="form-control form-control-sm item-discount" style="width:65px" value="<?= e($_POST['item_disc'][$i]??0) ?>" min="0" max="100" step="0.01"></td>
                                <td><strong class="item-total"><?= number_format(($_POST['item_qty'][$i]??1)*($_POST['item_price'][$i]??0),2) ?></strong></td>
                                <td><button type="button" class="btn btn-xs btn-outline-danger remove-line-item"><i class="fa fa-times"></i></button></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <button type="button" class="btn btn-sm btn-outline-primary add-line-item"><i class="fa fa-plus me-1"></i>Add Line</button>
            </div>
        </div>

        <!-- Notes & Terms -->
        <div class="card mt-3">
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-6"><label class="form-label">Notes</label><textarea name="notes" class="form-control" rows="3" placeholder="Special instructions, parts to source..."><?= e($_POST['notes']??'') ?></textarea></div>
                    <div class="col-md-6"><label class="form-label">Terms & Conditions</label><textarea name="terms" class="form-control" rows="3" placeholder="Payment terms, warranty..."><?= e($_POST['terms']??'This quotation is valid for 30 days.') ?></textarea></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Right panel -->
    <div class="col-lg-4">
        <div class="card mb-3">
            <div class="card-header"><i class="fa fa-car me-2"></i>Quotation Info</div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-12">
                        <label class="form-label">Vehicle <span class="text-danger">*</span></label>
                        <select name="car_id" id="car_select" class="form-select select2" required>
                            <option value="">Select car...</option>
                            <?php foreach ($cars as $c): ?>
                            <option value="<?= $c['id'] ?>" 
                                data-type="<?= $c['car_type'] ?>"
                                data-owner="<?= e($c['owner_name']) ?>"
                                data-phone="<?= e($c['owner_phone']) ?>"
                                <?= (($_POST['car_id']??$preCarId)==$c['id'])?'selected':'' ?>><?= e($c['make'].' '.$c['model'].' — '.$c['chassis_number']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12">
                        <label class="form-label">Job Card (optional)</label>
                        <select name="job_id" class="form-select select2">
                            <option value="">Select job...</option>
                            <?php foreach ($jobs as $j): ?>
                            <option value="<?= $j['id'] ?>" <?= (($_POST['job_id']??$preJobId)==$j['id'])?'selected':'' ?>><?= e($j['job_number']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-6"><label class="form-label">Date</label><input type="date" name="date" class="form-control" value="<?= e($_POST['date']??date('Y-m-d')) ?>"></div>
                    <div class="col-6"><label class="form-label">Valid Until</label><input type="date" name="valid_until" class="form-control" value="<?= e($_POST['valid_until']??date('Y-m-d', strtotime('+30 days'))) ?>"></div>
                    <div class="col-12">
                        <label class="form-label">Link to Client <small class="text-muted">(optional)</small></label>
                        <select name="client_id" id="client_select" class="form-select select2">
                            <option value="">— No client —</option>
                            <?php foreach ($clients as $cl): ?>
                            <option value="<?= $cl['id'] ?>" data-name="<?= e($cl['name']) ?>" data-email="<?= e($cl['email']) ?>" data-phone="<?= e($cl['phone']) ?>" <?= (($_POST['client_id']??'')==$cl['id'])?'selected':'' ?>>
                                <?= e($cl['name']) ?><?= $cl['phone']?' — '.$cl['phone']:'' ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12"><label class="form-label">Customer Name</label><input type="text" id="customer_name" name="customer_name" class="form-control" value="<?= e($_POST['customer_name']??'') ?>"></div>
                    <div class="col-6"><label class="form-label">Phone</label><input type="text" id="customer_phone" name="customer_phone" class="form-control" value="<?= e($_POST['customer_phone']??'') ?>"></div>
                    <div class="col-6"><label class="form-label">Email</label><input type="email" name="customer_email" class="form-control" value="<?= e($_POST['customer_email']??'') ?>"></div>
                </div>
            </div>
        </div>
        <script>
        function populateCustomer() {
            var opt = $('#car_select').find('option:selected');
            if(opt.val() && opt.data('type') === 'client'){
                $('#customer_name').val(opt.data('owner'));
                $('#customer_phone').val(opt.data('phone'));
            }
        }
        $(document).on('change', '#car_select', function(){
            var opt = $(this).find('option:selected');
            if(opt.data('type') === 'client'){
                $('#customer_name').val(opt.data('owner'));
                $('#customer_phone').val(opt.data('phone'));
            } else if(!$('#client_select').val()) {
                $('#customer_name').val('');
                $('#customer_phone').val('');
            }
        });
        $(document).on('change', '#client_select', function(){
            var opt = $(this).find('option:selected');
            if(opt.val()){
                $('#customer_name').val(opt.data('name'));
                $('#customer_phone').val(opt.data('phone'));
                $('input[name="customer_email"]').val(opt.data('email'));
            }
        });
        $(function(){
            populateCustomer();
        });
        </script>

        <!-- Totals -->
        <div class="card">
            <div class="card-header"><i class="fa fa-calculator me-2"></i>Totals</div>
            <div class="card-body">
                <table class="table table-sm mb-0">
                    <tr><td class="text-muted">Subtotal</td><td class="text-end fw-semibold">KES <span id="subtotal_display">0.00</span></td></tr>
                    <tr>
                        <td class="text-muted">Discount</td>
                        <td class="text-end">
                            <div class="input-group input-group-sm justify-content-end" style="max-width:120px;margin-left:auto">
                                <input type="number" id="overall_discount" name="overall_discount" class="form-control form-control-sm" value="<?= e($_POST['overall_discount']??0) ?>" min="0" max="100" step="0.01">
                                <span class="input-group-text">%</span>
                            </div>
                            <div class="mt-1">KES <span id="discount_display">0.00</span></div>
                        </td>
                    </tr>
                    <tr>
                        <td class="text-muted">VAT</td>
                        <td class="text-end">
                            <div class="input-group input-group-sm justify-content-end" style="max-width:120px;margin-left:auto">
                                <input type="number" id="tax_rate" name="tax_rate" class="form-control form-control-sm" value="<?= e($_POST['tax_rate']??$vatRate) ?>" min="0" max="100" step="0.01">
                                <span class="input-group-text">%</span>
                            </div>
                            <div class="mt-1">KES <span id="tax_display">0.00</span></div>
                        </td>
                    </tr>
                    <tr class="table-primary"><td><strong>Total</strong></td><td class="text-end"><strong>KES <span id="total_display">0.00</span></strong></td></tr>
                </table>
                <input type="hidden" id="hidden_subtotal" name="hidden_subtotal">
                <input type="hidden" id="hidden_discount" name="hidden_discount">
                <input type="hidden" id="hidden_tax" name="hidden_tax">
                <input type="hidden" id="hidden_total" name="hidden_total">
                <button type="submit" class="btn btn-primary w-100 mt-3"><i class="fa fa-save me-1"></i>Save Quotation</button>
            </div>
        </div>
    </div>
</div>
</form>
<?php include __DIR__ . '/../../includes/footer.php'; ?>

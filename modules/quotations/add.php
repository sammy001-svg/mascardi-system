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
$preBookingId = (int)($_GET['booking_id'] ?? 0);
$preClientId = null;

if ($preBookingId) {
    $sbCheck = $db->prepare("SELECT car_id, client_id, job_id FROM service_bookings WHERE id = ?");
    $sbCheck->execute([$preBookingId]);
    $sbRow = $sbCheck->fetch();
    if ($sbRow) {
        if (!$preCarId && $sbRow['car_id']) $preCarId = (int)$sbRow['car_id'];
        if (!$preJobId && $sbRow['job_id']) $preJobId = (int)$sbRow['job_id'];
        $preClientId = $sbRow['client_id'] ? (int)$sbRow['client_id'] : null;
    }
}

// ── Pre-fill from Quote Request ──────────────────────────────────────────
$fromQrId          = (int)($_GET['from_qr'] ?? 0);
$fromQr            = null;
$fromQrMatchedLines = [];
$fromQrCustomer    = [];
$fromQrUnmatched   = [];
$fromQrVehicleHint = '';

if ($fromQrId && $_SERVER['REQUEST_METHOD'] === 'GET') {
    try {
        $s = $db->prepare("SELECT * FROM parts_requests WHERE id = ?");
        $s->execute([$fromQrId]);
        $fromQr = $s->fetch() ?: null;
    } catch (\Throwable $e) {}

    if ($fromQr) {
        // Fallback: if QR has no client/vehicle data, pull from its linked quick assessment
        if ((!$fromQr['client_name'] && !$fromQr['client_phone']) && !empty($fromQr['quick_assessment_id'])) {
            try {
                $qa = $db->prepare("
                    SELECT qa.client_name, qa.client_phone, qa.client_email,
                           qa.car_make, qa.car_model, qa.car_registration,
                           COALESCE(c.chassis_number, c2.chassis_number) AS chassis_number
                    FROM quick_assessments qa
                    LEFT JOIN cars c  ON c.id  = qa.car_id
                    LEFT JOIN cars c2 ON c2.registration_number = qa.car_registration AND qa.car_id IS NULL
                    WHERE qa.id = ?
                ");
                $qa->execute([$fromQr['quick_assessment_id']]);
                $qaRow = $qa->fetch() ?: [];
                $fromQr['client_name']      = $fromQr['client_name']      ?: ($qaRow['client_name']       ?? '');
                $fromQr['client_phone']     = $fromQr['client_phone']     ?: ($qaRow['client_phone']      ?? '');
                $fromQr['client_email']     = $fromQr['client_email']     ?: ($qaRow['client_email']      ?? '');
                $fromQr['car_make']         = $fromQr['car_make']         ?: ($qaRow['car_make']          ?? '');
                $fromQr['car_model']        = $fromQr['car_model']        ?: ($qaRow['car_model']         ?? '');
                $fromQr['car_registration'] = $fromQr['car_registration'] ?: ($qaRow['car_registration']  ?? '');
                $fromQr['car_chassis']      = $fromQr['car_chassis']      ?: ($qaRow['chassis_number']    ?? '');
            } catch (\Throwable $e) {}
        }

        // Match car by chassis then registration
        if (!$preCarId) {
            if ($fromQr['car_chassis']) {
                $s = $db->prepare("SELECT id FROM cars WHERE chassis_number = ? LIMIT 1");
                $s->execute([$fromQr['car_chassis']]);
                $preCarId = (int)($s->fetchColumn() ?: 0);
            }
            if (!$preCarId && $fromQr['car_registration']) {
                $s = $db->prepare("SELECT id FROM cars WHERE registration_number = ? LIMIT 1");
                $s->execute([$fromQr['car_registration']]);
                $preCarId = (int)($s->fetchColumn() ?: 0);
            }
        }

        if (!$preCarId) {
            $v = trim(($fromQr['car_make'] ?? '') . ' ' . ($fromQr['car_model'] ?? ''));
            if ($fromQr['car_registration']) $v .= ' — ' . $fromQr['car_registration'];
            $fromQrVehicleHint = $v ?: 'Unknown vehicle';
        }

        $fromQrCustomer = [
            'name'  => $fromQr['client_name']  ?? '',
            'phone' => $fromQr['client_phone'] ?? '',
            'email' => $fromQr['client_email'] ?? '',
        ];

        // Match each requested part to inventory
        $s = $db->prepare("SELECT * FROM parts_request_items WHERE request_id = ? ORDER BY id");
        $s->execute([$fromQrId]);
        foreach ($s->fetchAll() as $item) {
            $inv = null;
            if (!empty($item['part_number'])) {
                $q = $db->prepare("SELECT id, part_name, selling_price, quantity FROM inventory WHERE part_number = ? LIMIT 1");
                $q->execute([$item['part_number']]);
                $inv = $q->fetch() ?: null;
            }
            if (!$inv) {
                $q = $db->prepare("SELECT id, part_name, selling_price, quantity FROM inventory WHERE LOWER(part_name) = LOWER(?) LIMIT 1");
                $q->execute([$item['part_name']]);
                $inv = $q->fetch() ?: null;
            }
            $inStock  = $inv ? (float)$inv['quantity'] : 0;
            $hasStock = $inv && $inStock > 0;
            $fromQrMatchedLines[] = [
                'type'    => 'part',
                'desc'    => $item['part_name'],
                'inv_id'  => $hasStock ? $inv['id'] : '',
                'qty'     => $item['quantity_requested'],
                'price'   => $hasStock ? $inv['selling_price'] : 0,
                'disc'    => 0,
                'in_stock'=> $inStock,
                'matched' => $inv !== null,
            ];
            if (!$hasStock) {
                $fromQrUnmatched[] = $item['part_name'] . ($item['part_number'] ? ' [' . $item['part_number'] . ']' : '');
            }
        }
        if (empty($fromQrMatchedLines)) {
            $fromQrMatchedLines[] = ['type'=>'part','desc'=>'','inv_id'=>'','qty'=>1,'price'=>0,'disc'=>0,'in_stock'=>null,'matched'=>false];
        }
    }
}
// shorthand for use inside form rendering
$usePreFill = !empty($fromQrMatchedLines) && !isset($_POST['item_desc']);

$cars      = $db->query("SELECT id, chassis_number, registration_number, make, model, owner_name, owner_phone, owner_email, car_type FROM cars ORDER BY make ASC")->fetchAll();
$jobs      = $db->query("SELECT id, job_number, car_id FROM workshop_jobs WHERE status NOT IN ('completed','cancelled') ORDER BY job_number")->fetchAll();
$clients   = $db->query("SELECT id, name, phone, email FROM clients WHERE status='active' ORDER BY name ASC")->fetchAll();
$inventory = $db->query("SELECT id, part_number, part_name, selling_price FROM inventory ORDER BY part_name")->fetchAll();
$serviceBookings = $db->query("
    SELECT sb.id, sb.booking_number, sb.client_id, sb.car_id,
           COALESCE(cl.name,  sb.client_name)  AS client_name,
           COALESCE(cl.phone, sb.client_phone) AS client_phone,
           COALESCE(cl.email, sb.client_email) AS client_email,
           sb.car_make, sb.car_model, sb.car_registration,
           ca.make AS car_make_db, ca.model AS car_model_db,
           ca.chassis_number, ca.registration_number,
           wj.id AS job_id
    FROM service_bookings sb
    LEFT JOIN clients cl ON cl.id = sb.client_id
    LEFT JOIN cars ca ON ca.id = sb.car_id
    LEFT JOIN quick_assessments qa ON qa.service_booking_id = sb.id
    LEFT JOIN workshop_jobs wj ON wj.assessment_id = qa.id
    ORDER BY sb.booking_number DESC
")->fetchAll();

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
$extraJs = null; // all quotation JS runs in the post-footer script below
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="mb-0">New Quotation</h5>
    <a href="index.php" class="btn btn-sm btn-outline-secondary"><i class="fa fa-arrow-left me-1"></i>Back</a>
</div>
<?php if ($errors): ?><div class="alert alert-danger"><ul class="mb-0"><?php foreach($errors as $err) echo "<li>".e($err)."</li>"; ?></ul></div><?php endif; ?>

<?php if ($fromQr): ?>
<div class="alert alert-info d-flex flex-wrap align-items-start gap-3 mb-3">
    <i class="fa fa-file-invoice-dollar fa-lg mt-1 text-primary flex-shrink-0"></i>
    <div class="flex-fill">
        <div class="fw-semibold mb-1">
            Converting Quote Request
            <a href="<?= BASE_URL ?>/modules/parts_requests/view.php?id=<?= $fromQrId ?>" class="text-decoration-none">
                <?= e($fromQr['request_number']) ?>
            </a> to Quotation
        </div>
        <?php if ($fromQrVehicleHint): ?>
        <div class="small text-warning fw-semibold mt-1">
            <i class="fa fa-triangle-exclamation me-1"></i>
            Vehicle <strong><?= e($fromQrVehicleHint) ?></strong> is not yet registered in the system — please select or create it in the Vehicle field below.
        </div>
        <?php endif; ?>
        <?php if ($fromQrUnmatched): ?>
        <div class="small text-muted mt-1">
            <i class="fa fa-box-open me-1"></i>
            Parts not found in stock (added without price — update manually):
            <strong><?= e(implode(', ', $fromQrUnmatched)) ?></strong>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

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
                            <?php
                            $lineItems = isset($_POST['item_desc'])
                                ? array_keys($_POST['item_desc'])
                                : ($usePreFill ? array_keys($fromQrMatchedLines) : [0]);
                            ?>
                            <?php foreach ($lineItems as $i):
                                $pre    = $usePreFill ? ($fromQrMatchedLines[$i] ?? []) : [];
                                $pType  = $_POST['item_type'][$i]   ?? $pre['type']   ?? 'part';
                                $pDesc  = $_POST['item_desc'][$i]   ?? $pre['desc']   ?? '';
                                $pInvId = $_POST['item_inv_id'][$i] ?? $pre['inv_id'] ?? '';
                                $pQty   = $_POST['item_qty'][$i]    ?? $pre['qty']    ?? 1;
                                $pPrice = $_POST['item_price'][$i]  ?? $pre['price']  ?? 0;
                                $pDisc  = $_POST['item_disc'][$i]   ?? $pre['disc']   ?? 0;
                            ?>
                            <tr class="line-item-row">
                                <td>
                                    <select name="item_type[]" class="form-select form-select-sm" style="width:100px">
                                        <option value="part"    <?= $pType==='part'   ?'selected':'' ?>>Part</option>
                                        <option value="labour"  <?= $pType==='labour' ?'selected':'' ?>>Labour</option>
                                        <option value="service" <?= $pType==='service'?'selected':'' ?>>Service</option>
                                    </select>
                                </td>
                                <td><input type="text" name="item_desc[]" class="form-control form-control-sm item-desc" value="<?= e($pDesc) ?>" placeholder="Description..." required></td>
                                <td>
                                    <select name="item_inv_id[]" class="form-select form-select-sm select2 inventory-select" style="min-width:150px">
                                        <option value="">From stock...</option>
                                        <?php foreach ($inventory as $inv): ?>
                                        <option value="<?= $inv['id'] ?>" data-price="<?= $inv['selling_price'] ?>" data-desc="<?= e($inv['part_name']) ?>" <?= $pInvId==$inv['id']?'selected':'' ?>>
                                            <?= e(($inv['part_number']?$inv['part_number'].' — ':'').$inv['part_name']) ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                                <td><input type="number" name="item_qty[]"   class="form-control form-control-sm item-qty"      style="width:65px" value="<?= e($pQty) ?>"   min="0.01" step="0.01"></td>
                                <td><input type="number" name="item_price[]" class="form-control form-control-sm item-price"    style="width:90px" value="<?= e($pPrice) ?>" min="0"    step="0.01"></td>
                                <td><input type="number" name="item_disc[]"  class="form-control form-control-sm item-discount" style="width:65px" value="<?= e($pDisc) ?>"  min="0"    max="100" step="0.01"></td>
                                <td><strong class="item-total"><?= number_format((float)$pQty * (float)$pPrice, 2) ?></strong></td>
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
                        <label class="form-label">Service Booking <small class="text-muted">(optional)</small></label>
                        <select name="booking_id" id="booking_select" class="form-select select2">
                            <option value="">— Select booking —</option>
                            <?php foreach ($serviceBookings as $sb):
                                $sbVehicleReg = $sb['car_registration'] ?: $sb['registration_number'] ?: '';
                                $sbCarMake    = $sb['car_make'] ?: $sb['car_make_db'] ?: '';
                                $sbCarModel   = $sb['car_model'] ?: $sb['car_model_db'] ?: '';
                            ?>
                            <option value="<?= $sb['id'] ?>"
                                    data-car-id="<?= $sb['car_id'] ?>"
                                    data-job-id="<?= $sb['job_id'] ?? '' ?>"
                                    data-client-id="<?= $sb['client_id'] ?>"
                                    data-client-name="<?= e($sb['client_name'] ?: '') ?>"
                                    data-client-phone="<?= e($sb['client_phone'] ?: '') ?>"
                                    data-client-email="<?= e($sb['client_email'] ?: '') ?>"
                                    data-car-make="<?= e($sbCarMake) ?>"
                                    data-car-model="<?= e($sbCarModel) ?>"
                                    data-car-reg="<?= e($sbVehicleReg) ?>"
                                    <?= $preBookingId == $sb['id'] ? 'selected' : '' ?>>
                                <?= e($sb['booking_number'] . ' — ' . ($sb['client_name'] ?: 'Walk-in') . ($sbVehicleReg ? ' (' . $sbVehicleReg . ')' : '')) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
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
                        <div id="booking-vehicle-hint" class="alert alert-warning py-2 px-3 mt-2 mb-0 small" <?= $fromQrVehicleHint ? '' : 'style="display:none"' ?>>
                            <?php if ($fromQrVehicleHint): ?>
                            <i class="fa fa-triangle-exclamation me-1"></i>Vehicle from quote request: <strong><?= e($fromQrVehicleHint) ?></strong> — not yet registered in the system.
                            <?php endif; ?>
                        </div>
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
                            <option value="<?= $cl['id'] ?>" data-name="<?= e($cl['name']) ?>" data-email="<?= e($cl['email']) ?>" data-phone="<?= e($cl['phone']) ?>" <?= (($_POST['client_id']??$preClientId??'')==$cl['id'])?'selected':'' ?>>
                                <?= e($cl['name']) ?><?= $cl['phone']?' — '.$cl['phone']:'' ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12"><label class="form-label">Customer Name</label><input type="text" id="customer_name" name="customer_name" class="form-control" value="<?= e($_POST['customer_name'] ?? $fromQrCustomer['name']  ?? '') ?>"></div>
                    <div class="col-6"><label class="form-label">Phone</label><input type="text" id="customer_phone" name="customer_phone" class="form-control" value="<?= e($_POST['customer_phone'] ?? $fromQrCustomer['phone'] ?? '') ?>"></div>
                    <div class="col-6"><label class="form-label">Email</label><input type="email" name="customer_email" class="form-control" value="<?= e($_POST['customer_email'] ?? $fromQrCustomer['email'] ?? '') ?>"></div>
                </div>
            </div>
        </div>

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
<script>
$(function () {

    /* ── QR pre-fill data from PHP ─────────────────────────────────────── */
    var _qr = <?= json_encode($fromQr ? [
        'name'  => $fromQrCustomer['name']  ?? '',
        'phone' => $fromQrCustomer['phone'] ?? '',
        'email' => $fromQrCustomer['email'] ?? '',
    ] : null) ?>;

    /* ── populateCustomer (car owner → customer fields) ─────────────────── */
    function populateCustomer() {
        var opt = $('#car_select').find('option:selected');
        if (opt.val() && opt.data('type') === 'client') {
            $('#customer_name').val(opt.data('owner') || '');
            $('#customer_phone').val(opt.data('phone') || '');
        }
    }

    /* ── Booking select → auto-fill car / job / client / customer ────────── */
    $(document).on('change', '#booking_select', function () {
        var opt  = $(this).find('option:selected');
        var hint = $('#booking-vehicle-hint');
        if (!opt.val()) { hint.hide(); return; }

        var carId    = opt.data('car-id');
        var jobId    = opt.data('job-id');
        var clientId = opt.data('client-id');

        if (carId) {
            $('#car_select').val(carId).trigger('change.select2');
            hint.hide();
        } else {
            var parts = [opt.data('car-make')||'', opt.data('car-model')||'', opt.data('car-reg')||''].filter(Boolean);
            if (parts.length) {
                hint.html('<i class="fa fa-car me-1"></i>Vehicle from booking: <strong>' +
                    $('<span>').text(parts.join(' ')).html() +
                    '</strong> <span class="text-muted">(not yet registered in system)</span>').show();
            } else { hint.hide(); }
        }

        if (jobId)    $('select[name="job_id"]').val(jobId).trigger('change.select2');
        if (clientId) $('#client_select').val(clientId).trigger('change.select2');

        if (opt.data('client-name'))  $('#customer_name').val(opt.data('client-name'));
        if (opt.data('client-phone')) $('#customer_phone').val(opt.data('client-phone'));
        if (opt.data('client-email')) $('input[name="customer_email"]').val(opt.data('client-email'));
    });

    /* ── Car select → fill owner or clear customer fields ───────────────── */
    $(document).on('change', '#car_select', function () {
        var opt = $(this).find('option:selected');
        if (opt.data('type') === 'client') {
            $('#customer_name').val(opt.data('owner') || '');
            $('#customer_phone').val(opt.data('phone') || '');
        } else if (!$('#client_select').val() && !$('#booking_select').val() && !_qr) {
            $('#customer_name').val('');
            $('#customer_phone').val('');
        }
    });

    /* ── Client select → fill name / phone / email ──────────────────────── */
    $(document).on('change', '#client_select', function () {
        var opt = $(this).find('option:selected');
        if (opt.val()) {
            $('#customer_name').val(opt.data('name') || '');
            $('#customer_phone').val(opt.data('phone') || '');
            $('input[name="customer_email"]').val(opt.data('email') || '');
        }
    });

    /* ── On-load init ───────────────────────────────────────────────────── */
    populateCustomer();
    if ($('#booking_select').val()) $('#booking_select').trigger('change');

    /* ── Re-apply QR customer data after select2 may have changed fields ── */
    if (_qr) {
        if (_qr.name)  $('#customer_name').val(_qr.name);
        if (_qr.phone) $('#customer_phone').val(_qr.phone);
        if (_qr.email) $('input[name="customer_email"]').val(_qr.email);
    }

    /* ── Recalculate totals ──────────────────────────────────────────────── */
    recalcTotals && recalcTotals();
});
</script>

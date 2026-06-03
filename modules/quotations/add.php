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
        // Always pull missing client/vehicle fields from linked quick assessment
        if (!empty($fromQr['quick_assessment_id'])) {
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
                $fromQr['client_name']      = ($fromQr['client_name']      ?: '') ?: ($qaRow['client_name']      ?? '');
                $fromQr['client_phone']     = ($fromQr['client_phone']     ?: '') ?: ($qaRow['client_phone']     ?? '');
                $fromQr['client_email']     = ($fromQr['client_email']     ?: '') ?: ($qaRow['client_email']     ?? '');
                $fromQr['car_make']         = ($fromQr['car_make']         ?: '') ?: ($qaRow['car_make']         ?? '');
                $fromQr['car_model']        = ($fromQr['car_model']        ?: '') ?: ($qaRow['car_model']        ?? '');
                $fromQr['car_registration'] = ($fromQr['car_registration'] ?: '') ?: ($qaRow['car_registration'] ?? '');
                $fromQr['car_chassis']      = ($fromQr['car_chassis']      ?: '') ?: ($qaRow['chassis_number']   ?? '');
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

// Quote Requests for QR-source selector
$quoteRequests = [];
try {
    $quoteRequests = $db->query("
        SELECT pr.id, pr.request_number, pr.created_at,
               pr.client_name, pr.client_phone, pr.client_email,
               pr.car_make, pr.car_model, pr.car_registration, pr.car_chassis,
               COALESCE(c.id, c2.id) AS matched_car_id
        FROM parts_requests pr
        LEFT JOIN cars c  ON pr.car_chassis IS NOT NULL AND pr.car_chassis != ''
                          AND c.chassis_number = pr.car_chassis
        LEFT JOIN cars c2 ON (pr.car_chassis IS NULL OR pr.car_chassis = '')
                          AND pr.car_registration IS NOT NULL AND pr.car_registration != ''
                          AND c2.registration_number = pr.car_registration
        ORDER BY pr.id DESC
        LIMIT 150
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (\Throwable $e) { $quoteRequests = []; }

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
            $afterSave = $_POST['_after_save'] ?? 'view';
            if ($afterSave === 'print') {
                redirect(BASE_URL.'/modules/quotations/print.php?id='.$qId);
            } elseif ($afterSave === 'send') {
                redirect(BASE_URL.'/modules/quotations/view.php?id='.$qId.'&open_email=1');
            } else {
                redirect(BASE_URL.'/modules/quotations/view.php?id='.$qId);
            }
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
                <button type="button" class="btn btn-sm btn-outline-primary" onclick="quotAddLine()"><i class="fa fa-plus me-1"></i>Add Line</button>
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
                        <?php if ($fromQrId): ?>
                        <!-- Quote Request selector (replaces Service Booking when coming from QR) -->
                        <label class="form-label">
                            <i class="fa fa-file-invoice me-1 text-primary"></i>Quote Request
                            <small class="text-muted fw-normal">— select to auto-fill client &amp; vehicle</small>
                        </label>
                        <select name="qr_id" id="qr_select" class="form-select select2">
                            <option value="">— Select quote request —</option>
                            <?php foreach ($quoteRequests as $qrOpt): ?>
                            <option value="<?= $qrOpt['id'] ?>"
                                    data-car-id="<?= (int)($qrOpt['matched_car_id'] ?? 0) ?>"
                                    data-client-name="<?= e($qrOpt['client_name']    ?? '') ?>"
                                    data-client-phone="<?= e($qrOpt['client_phone']  ?? '') ?>"
                                    data-client-email="<?= e($qrOpt['client_email']  ?? '') ?>"
                                    data-car-make="<?= e($qrOpt['car_make']        ?? '') ?>"
                                    data-car-model="<?= e($qrOpt['car_model']       ?? '') ?>"
                                    data-car-reg="<?= e($qrOpt['car_registration']  ?? '') ?>"
                                    data-chassis="<?= e($qrOpt['car_chassis']       ?? '') ?>"
                                    <?= $fromQrId == $qrOpt['id'] ? 'selected' : '' ?>>
                                <?= e($qrOpt['request_number']) ?>
                                — <?= e($qrOpt['client_name'] ?: 'Walk-in') ?>
                                <?php if ($qrOpt['car_registration'] || $qrOpt['car_make']): ?>
                                (<?= e(trim($qrOpt['car_make'].' '.$qrOpt['car_model'])) ?><?= $qrOpt['car_registration'] ? ' · '.$qrOpt['car_registration'] : '' ?>)
                                <?php endif; ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <!-- hidden so booking_select change handler still has its element -->
                        <select name="booking_id" id="booking_select" class="d-none"></select>
                        <?php else: ?>
                        <!-- Standard Service Booking selector -->
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
                        <?php endif; ?>
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
                        <?php if ($fromQrId && ($fromQr['car_make'] ?? '' || $fromQr['car_registration'] ?? '' || $fromQr['car_chassis'] ?? '')): ?>
                        <div class="alert alert-light border mt-2 py-2 px-3 small mb-0">
                            <div class="fw-semibold text-primary mb-1"><i class="fa fa-car me-1"></i>Vehicle from Quote Request</div>
                            <?php if ($fromQr['car_make'] ?? ''): ?>
                            <div><?= e(trim(($fromQr['car_make'] ?? '').' '.($fromQr['car_model'] ?? ''))) ?></div>
                            <?php endif; ?>
                            <?php if ($fromQr['car_registration'] ?? ''): ?>
                            <div>Reg: <strong class="text-uppercase"><?= e($fromQr['car_registration']) ?></strong></div>
                            <?php endif; ?>
                            <?php if ($fromQr['car_chassis'] ?? ''): ?>
                            <div>Chassis: <code><?= e($fromQr['car_chassis']) ?></code></div>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
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

    </div>
</div>

<!-- ── Quote Summary & Save — full width, always visible ───────────────── -->
<div class="card mt-4" style="border:1px solid #93c5fd">
    <div class="card-header fw-semibold" style="background:#eff6ff">
        <i class="fa fa-calculator me-2 text-primary"></i>Quote Summary &amp; Save
    </div>
    <div class="card-body">
        <div class="row g-4 align-items-start">

            <!-- Totals breakdown -->
            <div class="col-md-5">
                <table class="table table-sm mb-0">
                    <tr>
                        <td class="text-muted">Subtotal</td>
                        <td class="text-end fw-semibold">KES <span id="subtotal_display">0.00</span></td>
                    </tr>
                    <tr>
                        <td class="text-muted">Discount</td>
                        <td class="text-end">
                            <div class="input-group input-group-sm justify-content-end" style="max-width:130px;margin-left:auto">
                                <input type="number" id="overall_discount" name="overall_discount"
                                       class="form-control form-control-sm" value="<?= e($_POST['overall_discount']??0) ?>"
                                       min="0" max="100" step="0.01">
                                <span class="input-group-text">%</span>
                            </div>
                            <small class="text-muted">- KES <span id="discount_display">0.00</span></small>
                        </td>
                    </tr>
                    <tr>
                        <td class="text-muted">VAT</td>
                        <td class="text-end">
                            <div class="input-group input-group-sm justify-content-end" style="max-width:130px;margin-left:auto">
                                <input type="number" id="tax_rate" name="tax_rate"
                                       class="form-control form-control-sm" value="<?= e($_POST['tax_rate']??$vatRate) ?>"
                                       min="0" max="100" step="0.01">
                                <span class="input-group-text">%</span>
                            </div>
                            <small class="text-muted">KES <span id="tax_display">0.00</span></small>
                        </td>
                    </tr>
                    <tr class="table-primary">
                        <td><strong>Total</strong></td>
                        <td class="text-end"><strong>KES <span id="total_display">0.00</span></strong></td>
                    </tr>
                </table>
                <input type="hidden" id="hidden_subtotal" name="hidden_subtotal">
                <input type="hidden" id="hidden_discount" name="hidden_discount">
                <input type="hidden" id="hidden_tax"      name="hidden_tax">
                <input type="hidden" id="hidden_total"    name="hidden_total">
            </div>

            <!-- Notes & Terms -->
            <div class="col-md-4">
                <div class="mb-3">
                    <label class="form-label fw-semibold small">Notes</label>
                    <textarea name="notes" class="form-control form-control-sm" rows="3"
                              placeholder="Special instructions, parts to source..."><?= e($_POST['notes']??'') ?></textarea>
                </div>
                <div>
                    <label class="form-label fw-semibold small">Terms &amp; Conditions</label>
                    <textarea name="terms" class="form-control form-control-sm" rows="3"
                              placeholder="Payment terms, warranty..."><?= e($_POST['terms']??'This quotation is valid for 30 days.') ?></textarea>
                </div>
            </div>

            <!-- Save buttons -->
            <div class="col-md-3 d-flex flex-column gap-2">
                <input type="hidden" name="_after_save" id="_after_save" value="view">
                <button type="submit" class="btn btn-primary"
                        onclick="document.getElementById('_after_save').value='view'">
                    <i class="fa fa-save me-2"></i>Save Quotation
                </button>
                <button type="submit" class="btn btn-outline-secondary"
                        onclick="document.getElementById('_after_save').value='print'">
                    <i class="fa fa-print me-2"></i>Save &amp; Print
                </button>
                <button type="submit" class="btn btn-outline-info"
                        onclick="document.getElementById('_after_save').value='send'">
                    <i class="fa fa-envelope me-2"></i>Save &amp; Send
                </button>
            </div>

        </div>
    </div>
</div>

</form>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
<script>
/* ── All code here runs after jQuery + select2 + main.js are loaded ─────── */
(function () {
    'use strict';

    /* ── Add Line: capture inventory options then expose global function ──── */
    var _invOpts = (function () {
        var el = document.querySelector('#lineItemsTable .inventory-select');
        return el ? el.innerHTML : '<option value="">From stock...</option>';
    }());

    window.quotAddLine = function () {
        var tbody = document.querySelector('#lineItemsTable .line-items-body');
        if (!tbody) return;
        var tr = document.createElement('tr');
        tr.className = 'line-item-row';
        tr.innerHTML =
            '<td><select name="item_type[]" class="form-select form-select-sm" style="width:100px">' +
                '<option value="part">Part</option><option value="labour">Labour</option>' +
                '<option value="service">Service</option></select></td>' +
            '<td><input type="text" name="item_desc[]" class="form-control form-control-sm item-desc"' +
                ' placeholder="Description..." required></td>' +
            '<td><select name="item_inv_id[]" class="form-select form-select-sm inventory-select"' +
                ' style="min-width:150px">' + _invOpts + '</select></td>' +
            '<td><input type="number" name="item_qty[]"  class="form-control form-control-sm item-qty"' +
                ' style="width:65px" value="1" min="0.01" step="0.01"></td>' +
            '<td><input type="number" name="item_price[]" class="form-control form-control-sm item-price"' +
                ' style="width:90px" value="0" min="0" step="0.01"></td>' +
            '<td><input type="number" name="item_disc[]"  class="form-control form-control-sm item-discount"' +
                ' style="width:65px" value="0" min="0" max="100" step="0.01"></td>' +
            '<td><strong class="item-total">0.00</strong></td>' +
            '<td><button type="button" class="btn btn-xs btn-outline-danger remove-line-item">' +
                '<i class="fa fa-times"></i></button></td>';
        tbody.appendChild(tr);
        if ($.fn && $.fn.select2) {
            $(tr).find('.inventory-select').select2({ theme: 'bootstrap-5', width: '100%' });
        }
        $(tr).find('.item-qty').trigger('input');
        $(tr).find('.item-desc').focus();
    };

    /* ── Safe QR data from PHP ───────────────────────────────────────────── */
    var _qr = null;
    try {
        _qr = <?php
            if ($fromQr) {
                $arr = [
                    'name'  => $fromQrCustomer['name']  ?? '',
                    'phone' => $fromQrCustomer['phone'] ?? '',
                    'email' => $fromQrCustomer['email'] ?? '',
                    'carId' => (int)$preCarId,
                ];
                $enc = json_encode($arr, JSON_UNESCAPED_UNICODE);
                echo $enc !== false ? $enc : 'null';
            } else {
                echo 'null';
            }
        ?>;
    } catch (e) {}

    /* ── Set a field value safely ────────────────────────────────────────── */
    function setField(id, val) {
        var el = id.indexOf('[') >= 0
            ? document.querySelector('[name="' + id + '"]')
            : document.getElementById(id);
        if (el && val) el.value = val;
    }

    /* ── Update select2 dropdown display ────────────────────────────────── */
    function setSelect(id, val) {
        var el = document.getElementById(id);
        if (!el || !val) return;
        el.value = val;
        if ($.fn.select2) $(el).trigger('change');
    }

    /* ── QR select change: fill client + car ─────────────────────────────── */
    function applyQrOption(opt) {
        if (!opt) return;
        var carId = parseInt(opt.getAttribute('data-car-id')) || 0;
        setField('customer_name',  opt.getAttribute('data-client-name')  || '');
        setField('customer_phone', opt.getAttribute('data-client-phone') || '');
        setField('customer_email', opt.getAttribute('data-client-email') || '');
        if (carId) setSelect('car_select', String(carId));
    }

    var qrSel = document.getElementById('qr_select');
    if (qrSel) {
        qrSel.addEventListener('change', function () {
            var opt = this.options[this.selectedIndex];
            if (opt && opt.value) applyQrOption(opt);
        });
        /* Auto-apply on load for the pre-selected QR */
        if (qrSel.value) {
            var preOpt = qrSel.querySelector('option[value="' + qrSel.value + '"]');
            if (preOpt) applyQrOption(preOpt);
        }
    }

    /* ── Always re-apply PHP-injected QR customer data last ─────────────── */
    if (_qr) {
        setField('customer_name',  _qr.name);
        setField('customer_phone', _qr.phone);
        setField('customer_email', _qr.email);
        if (_qr.carId) setSelect('car_select', String(_qr.carId));
    }

    /* ── Client select: fill customer fields ─────────────────────────────── */
    var clientSel = document.getElementById('client_select');
    if (clientSel) {
        clientSel.addEventListener('change', function () {
            var opt = this.options[this.selectedIndex];
            if (!opt || !opt.value) return;
            setField('customer_name',  opt.getAttribute('data-name')  || '');
            setField('customer_phone', opt.getAttribute('data-phone') || '');
            setField('customer_email', opt.getAttribute('data-email') || '');
        });
    }

    /* ── Booking select: fill car / job / client / customer ──────────────── */
    var bookSel = document.getElementById('booking_select');
    if (bookSel) {
        bookSel.addEventListener('change', function () {
            var opt = this.options[this.selectedIndex];
            if (!opt || !opt.value) return;
            var carId    = opt.getAttribute('data-car-id')    || '';
            var jobId    = opt.getAttribute('data-job-id')    || '';
            var clientId = opt.getAttribute('data-client-id') || '';
            if (carId)    setSelect('car_select', carId);
            if (jobId)    setSelect('job_id',     jobId);
            if (clientId) setSelect('client_select', clientId);
            setField('customer_name',  opt.getAttribute('data-client-name')  || '');
            setField('customer_phone', opt.getAttribute('data-client-phone') || '');
            setField('customer_email', opt.getAttribute('data-client-email') || '');
        });
    }

}());
</script>

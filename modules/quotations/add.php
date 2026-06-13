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

        // Match car: QA direct link → chassis → exact reg → normalised reg
        if (!$preCarId) {
            // Direct car_id from linked quick assessment (most reliable)
            if (!empty($fromQr['quick_assessment_id'])) {
                try {
                    $s = $db->prepare("SELECT car_id FROM quick_assessments WHERE id = ? AND car_id IS NOT NULL LIMIT 1");
                    $s->execute([$fromQr['quick_assessment_id']]);
                    $preCarId = (int)($s->fetchColumn() ?: 0);
                } catch (\Throwable $e) {}
            }
            // Chassis exact match
            if (!$preCarId && $fromQr['car_chassis']) {
                $s = $db->prepare("SELECT id FROM cars WHERE chassis_number = ? LIMIT 1");
                $s->execute([$fromQr['car_chassis']]);
                $preCarId = (int)($s->fetchColumn() ?: 0);
            }
            // Registration exact match
            if (!$preCarId && $fromQr['car_registration']) {
                $s = $db->prepare("SELECT id FROM cars WHERE registration_number = ? LIMIT 1");
                $s->execute([$fromQr['car_registration']]);
                $preCarId = (int)($s->fetchColumn() ?: 0);
            }
            // Registration normalised: ignore spaces + case ("KDW 171A" == "KDW171A")
            if (!$preCarId && $fromQr['car_registration']) {
                $normReg = str_replace(' ', '', strtoupper($fromQr['car_registration']));
                $s = $db->prepare("SELECT id FROM cars WHERE REPLACE(UPPER(registration_number),' ','') = ? LIMIT 1");
                $s->execute([$normReg]);
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
<!-- Page Header -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <nav aria-label="breadcrumb" class="mb-1">
            <ol class="breadcrumb mb-0" style="font-size:12px">
                <li class="breadcrumb-item"><a href="index.php" class="text-decoration-none">Quotations</a></li>
                <li class="breadcrumb-item active">New</li>
            </ol>
        </nav>
        <h5 class="mb-0 d-flex align-items-center gap-2">
            <span style="width:34px;height:34px;background:#2563eb;color:#fff;border-radius:8px;display:inline-flex;align-items:center;justify-content:center;flex-shrink:0">
                <i class="fa fa-file-invoice-dollar" style="font-size:14px"></i>
            </span>
            New Quotation
        </h5>
    </div>
    <a href="index.php" class="btn btn-sm btn-outline-secondary"><i class="fa fa-arrow-left me-1"></i>Back</a>
</div>

<?php if ($errors): ?>
<div class="alert alert-danger d-flex gap-2 align-items-start mb-4" style="border-radius:10px;border:none">
    <i class="fa fa-circle-exclamation mt-1 flex-shrink-0"></i>
    <ul class="mb-0 ps-2"><?php foreach($errors as $err) echo "<li>".e($err)."</li>"; ?></ul>
</div>
<?php endif; ?>

<?php if ($fromQr): ?>
<div class="d-flex flex-wrap align-items-start gap-3 mb-4 p-3 rounded-3" style="background:#eff6ff;border:1px solid #bfdbfe">
    <span style="width:36px;height:36px;background:#2563eb;color:#fff;border-radius:8px;display:inline-flex;align-items:center;justify-content:center;flex-shrink:0;margin-top:2px">
        <i class="fa fa-bolt" style="font-size:14px"></i>
    </span>
    <div class="flex-fill">
        <div class="fw-semibold text-primary mb-1">
            Auto-filling from Quote Request
            <a href="<?= BASE_URL ?>/modules/parts_requests/view.php?id=<?= $fromQrId ?>" class="fw-bold text-decoration-none ms-1">
                <?= e($fromQr['request_number']) ?>
            </a>
        </div>
        <?php if ($fromQrVehicleHint): ?>
        <div class="small fw-semibold mt-1" style="color:#b45309">
            <i class="fa fa-triangle-exclamation me-1"></i>
            Vehicle <strong><?= e($fromQrVehicleHint) ?></strong> is not yet registered — please select or create it in the Vehicle field.
        </div>
        <?php endif; ?>
        <?php if ($fromQrUnmatched): ?>
        <div class="small text-muted mt-1">
            <i class="fa fa-box-open me-1"></i>
            Not in stock (added without price — update manually): <strong><?= e(implode(', ', $fromQrUnmatched)) ?></strong>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<form method="POST">
<input type="hidden" name="_after_save" id="_after_save" value="view">
<input type="hidden" id="hidden_subtotal" name="hidden_subtotal">
<input type="hidden" id="hidden_discount" name="hidden_discount">
<input type="hidden" id="hidden_tax"      name="hidden_tax">
<input type="hidden" id="hidden_total"    name="hidden_total">

<div class="row g-4">

<!-- ══ LEFT COLUMN ══════════════════════════════════════════════════════════ -->
<div class="col-xl-8 col-lg-7 order-2 order-lg-1">

    <!-- Source selector -->
    <?php if ($fromQrId): ?>
    <div class="card mb-3 border-0 shadow-sm" style="border-left:3px solid #2563eb !important">
        <div class="card-body py-3">
            <label class="form-label fw-semibold d-flex align-items-center gap-2 mb-2">
                <span class="badge" style="background:#eff6ff;color:#2563eb;font-size:11px;padding:4px 9px;border-radius:6px">
                    <i class="fa fa-file-invoice me-1"></i>Quote Request
                </span>
                <small class="text-muted fw-normal">Select to auto-fill client &amp; vehicle</small>
            </label>
            <select name="qr_id" id="qr_select" class="form-select select2">
                <option value="">— Select quote request —</option>
                <?php foreach ($quoteRequests as $qrOpt): ?>
                <option value="<?= (int)$qrOpt['id'] ?>"
                        data-car-id="<?= (int)($qrOpt['matched_car_id'] ?? 0) ?>"
                        data-client-name="<?= e($qrOpt['client_name']   ?? '') ?>"
                        data-client-phone="<?= e($qrOpt['client_phone'] ?? '') ?>"
                        data-client-email="<?= e($qrOpt['client_email'] ?? '') ?>"
                        data-car-make="<?= e($qrOpt['car_make']         ?? '') ?>"
                        data-car-model="<?= e($qrOpt['car_model']       ?? '') ?>"
                        data-car-reg="<?= e($qrOpt['car_registration']  ?? '') ?>"
                        data-chassis="<?= e($qrOpt['car_chassis']       ?? '') ?>"
                        <?= ((int)$fromQrId === (int)$qrOpt['id']) ? 'selected' : '' ?>>
                    <?= e($qrOpt['request_number'] ?? '') ?>
                    — <?= e($qrOpt['client_name'] ?: 'Walk-in') ?>
                    <?php if (!empty($qrOpt['car_registration']) || !empty($qrOpt['car_make'])): ?>
                    (<?= e(trim(($qrOpt['car_make'] ?? '').' '.($qrOpt['car_model'] ?? ''))) ?><?= !empty($qrOpt['car_registration']) ? ' · '.e($qrOpt['car_registration']) : '' ?>)
                    <?php endif; ?>
                </option>
                <?php endforeach; ?>
            </select>
            <select name="booking_id" id="booking_select" class="d-none"></select>
        </div>
    </div>
    <?php else: ?>
    <div class="card mb-3 border-0 shadow-sm" style="border-left:3px solid #64748b !important">
        <div class="card-body py-3">
            <label class="form-label fw-semibold d-flex align-items-center gap-2 mb-2">
                <span class="badge" style="background:#f8fafc;color:#475569;font-size:11px;padding:4px 9px;border-radius:6px;border:1px solid #e2e8f0">
                    <i class="fa fa-calendar-check me-1"></i>Service Booking
                </span>
                <small class="text-muted fw-normal">Optional — links quotation to a booking</small>
            </label>
            <select name="booking_id" id="booking_select" class="form-select select2">
                <option value="">— Select booking (optional) —</option>
                <?php foreach ($serviceBookings as $sb):
                    $sbReg   = $sb['car_registration'] ?: $sb['registration_number'] ?: '';
                    $sbMake  = $sb['car_make']  ?: $sb['car_make_db']  ?: '';
                    $sbModel = $sb['car_model'] ?: $sb['car_model_db'] ?: '';
                ?>
                <option value="<?= (int)$sb['id'] ?>"
                        data-car-id="<?= (int)($sb['car_id'] ?? 0) ?>"
                        data-job-id="<?= (int)($sb['job_id'] ?? 0) ?>"
                        data-client-id="<?= (int)($sb['client_id'] ?? 0) ?>"
                        data-client-name="<?= e($sb['client_name']  ?? '') ?>"
                        data-client-phone="<?= e($sb['client_phone'] ?? '') ?>"
                        data-client-email="<?= e($sb['client_email'] ?? '') ?>"
                        data-car-make="<?= e($sbMake) ?>"
                        data-car-model="<?= e($sbModel) ?>"
                        data-car-reg="<?= e($sbReg) ?>"
                        <?= (int)$preBookingId === (int)$sb['id'] ? 'selected' : '' ?>>
                    <?= e($sb['booking_number'].' — '.($sb['client_name'] ?: 'Walk-in').($sbReg ? ' ('.$sbReg.')' : '')) ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>
    <?php endif; ?>

    <!-- Line Items -->
    <div class="card mb-3 border-0 shadow-sm">
        <div class="card-header border-bottom py-3 d-flex justify-content-between align-items-center" style="background:#fff">
            <div class="fw-semibold d-flex align-items-center gap-2">
                <span style="width:28px;height:28px;background:#eff6ff;color:#2563eb;border-radius:6px;display:inline-flex;align-items:center;justify-content:center">
                    <i class="fa fa-list" style="font-size:12px"></i>
                </span>
                Line Items
            </div>
            <button type="button" class="btn btn-sm btn-primary d-flex align-items-center gap-1" onclick="quotAddLine()">
                <i class="fa fa-plus"></i><span class="d-none d-sm-inline ms-1">Add Line</span>
            </button>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table mb-0" id="lineItemsTable" style="min-width:680px">
                    <thead style="background:#f8fafc;border-bottom:2px solid #e2e8f0">
                        <tr style="font-size:11px;text-transform:uppercase;letter-spacing:.6px;color:#64748b;font-weight:600">
                            <th class="ps-3 py-2" style="width:95px">Type</th>
                            <th class="py-2">Description</th>
                            <th class="py-2" style="width:185px">From Stock</th>
                            <th class="py-2 text-center" style="width:68px">Qty</th>
                            <th class="py-2 text-end" style="width:105px">Unit Price</th>
                            <th class="py-2 text-center" style="width:62px">Disc%</th>
                            <th class="py-2 text-end pe-3" style="width:95px">Total</th>
                            <th style="width:38px"></th>
                        </tr>
                    </thead>
                    <tbody class="line-items-body">
                        <?php
                        $lineItems = isset($_POST['item_desc'])
                            ? array_keys($_POST['item_desc'])
                            : ($usePreFill ? array_keys($fromQrMatchedLines) : [0]);
                        ?>
                        <?php foreach ($lineItems as $i):
                            $pre       = $usePreFill ? ($fromQrMatchedLines[$i] ?? []) : [];
                            $pType     = $_POST['item_type'][$i]   ?? $pre['type']     ?? 'part';
                            $pDesc     = $_POST['item_desc'][$i]   ?? $pre['desc']     ?? '';
                            $pInvId    = $_POST['item_inv_id'][$i] ?? $pre['inv_id']   ?? '';
                            $pQty      = $_POST['item_qty'][$i]    ?? $pre['qty']      ?? 1;
                            $pPrice    = $_POST['item_price'][$i]  ?? $pre['price']    ?? 0;
                            $pDisc     = $_POST['item_disc'][$i]   ?? $pre['disc']     ?? 0;
                            $isMatched = $pre['matched'] ?? true;
                            $inStock   = $pre['in_stock'] ?? null;
                        ?>
                        <tr class="line-item-row" style="border-bottom:1px solid #f1f5f9">
                            <td class="ps-3 py-2">
                                <select name="item_type[]" class="form-select form-select-sm" style="border:none;background:#f1f5f9;border-radius:6px;font-size:12px">
                                    <option value="part"    <?= $pType==='part'   ?'selected':'' ?>>Part</option>
                                    <option value="labour"  <?= $pType==='labour' ?'selected':'' ?>>Labour</option>
                                    <option value="service" <?= $pType==='service'?'selected':'' ?>>Service</option>
                                </select>
                            </td>
                            <td class="py-2">
                                <input type="text" name="item_desc[]" class="form-control form-control-sm item-desc"
                                       style="border-color:#e2e8f0;border-radius:6px"
                                       value="<?= e($pDesc) ?>" placeholder="Item description..." required>
                                <?php if ($usePreFill && $inStock !== null && !$isMatched): ?>
                                <small class="text-danger d-block mt-1" style="font-size:11px"><i class="fa fa-circle-xmark me-1"></i>Not in stock</small>
                                <?php elseif ($usePreFill && $isMatched): ?>
                                <small class="text-success d-block mt-1" style="font-size:11px"><i class="fa fa-circle-check me-1"></i>Matched from inventory</small>
                                <?php endif; ?>
                            </td>
                            <td class="py-2">
                                <select name="item_inv_id[]" class="form-select form-select-sm select2 inventory-select" style="border-color:#e2e8f0;border-radius:6px">
                                    <option value="">From stock...</option>
                                    <?php foreach ($inventory as $inv): ?>
                                    <option value="<?= $inv['id'] ?>"
                                            data-price="<?= $inv['selling_price'] ?>"
                                            data-desc="<?= e($inv['part_name']) ?>"
                                            <?= $pInvId==$inv['id']?'selected':'' ?>>
                                        <?= e(($inv['part_number']?$inv['part_number'].' — ':'').$inv['part_name']) ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                            <td class="py-2 text-center">
                                <input type="number" name="item_qty[]" class="form-control form-control-sm item-qty text-center"
                                       style="border-color:#e2e8f0;border-radius:6px"
                                       value="<?= e($pQty) ?>" min="0.01" step="0.01">
                            </td>
                            <td class="py-2">
                                <input type="number" name="item_price[]" class="form-control form-control-sm item-price text-end"
                                       style="border-color:#e2e8f0;border-radius:6px"
                                       value="<?= e($pPrice) ?>" min="0" step="0.01">
                            </td>
                            <td class="py-2 text-center">
                                <input type="number" name="item_disc[]" class="form-control form-control-sm item-discount text-center"
                                       style="border-color:#e2e8f0;border-radius:6px"
                                       value="<?= e($pDisc) ?>" min="0" max="100" step="0.01">
                            </td>
                            <td class="py-2 text-end pe-3">
                                <span class="item-total fw-semibold" style="color:#0f172a"><?= number_format((float)$pQty * (float)$pPrice, 2) ?></span>
                            </td>
                            <td class="py-2 pe-2 text-center">
                                <button type="button" class="btn btn-sm remove-line-item"
                                        style="width:26px;height:26px;padding:0;border-radius:6px;border:1px solid #fecaca;background:#fff5f5;color:#ef4444;line-height:1"
                                        title="Remove row">
                                    <i class="fa fa-times" style="font-size:11px"></i>
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Notes & Terms -->
    <div class="card border-0 shadow-sm">
        <div class="card-header border-bottom py-3 d-flex align-items-center gap-2" style="background:#fff">
            <span style="width:28px;height:28px;background:#fefce8;color:#ca8a04;border-radius:6px;display:inline-flex;align-items:center;justify-content:center">
                <i class="fa fa-sticky-note" style="font-size:12px"></i>
            </span>
            <span class="fw-semibold">Notes &amp; Terms</span>
        </div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label mb-1" style="font-size:10.5px;font-weight:600;text-transform:uppercase;letter-spacing:.5px;color:#64748b">Notes</label>
                    <textarea name="notes" class="form-control" rows="3"
                              style="border-color:#e2e8f0;border-radius:8px;resize:none"
                              placeholder="Special instructions, parts to source..."><?= e($_POST['notes'] ?? '') ?></textarea>
                </div>
                <div class="col-md-6">
                    <label class="form-label mb-1" style="font-size:10.5px;font-weight:600;text-transform:uppercase;letter-spacing:.5px;color:#64748b">Terms &amp; Conditions</label>
                    <textarea name="terms" class="form-control" rows="3"
                              style="border-color:#e2e8f0;border-radius:8px;resize:none"
                              placeholder="Payment terms, warranty..."><?= e($_POST['terms'] ?? 'This quotation is valid for 30 days.') ?></textarea>
                </div>
            </div>
        </div>
    </div>

</div><!-- /col left -->

<!-- ══ RIGHT SIDEBAR ════════════════════════════════════════════════════════ -->
<div class="col-xl-4 col-lg-5 order-1 order-lg-2">
    <div style="position:sticky;top:16px">

        <!-- Client & Vehicle -->
        <div class="card mb-3 border-0 shadow-sm">
            <div class="card-header border-bottom py-3 d-flex align-items-center gap-2" style="background:#fff">
                <span style="width:28px;height:28px;background:#eff6ff;color:#2563eb;border-radius:6px;display:inline-flex;align-items:center;justify-content:center">
                    <i class="fa fa-user" style="font-size:12px"></i>
                </span>
                <span class="fw-semibold">Client &amp; Vehicle</span>
            </div>
            <div class="card-body">
                <!-- Customer -->
                <div class="mb-3 pb-3" style="border-bottom:1px solid #f1f5f9">
                    <div class="mb-2" style="font-size:10.5px;font-weight:600;text-transform:uppercase;letter-spacing:.5px;color:#64748b">
                        <i class="fa fa-user-tie me-1"></i>Customer
                    </div>
                    <select name="client_id" id="client_select" class="form-select form-select-sm select2 mb-2" style="border-color:#e2e8f0">
                        <option value="">— Link to client (optional) —</option>
                        <?php foreach ($clients as $cl): ?>
                        <option value="<?= (int)$cl['id'] ?>"
                                data-name="<?= e($cl['name']) ?>"
                                data-email="<?= e($cl['email'] ?? '') ?>"
                                data-phone="<?= e($cl['phone'] ?? '') ?>"
                                <?= (int)(($_POST['client_id'] ?? $preClientId) ?: 0) === (int)$cl['id'] ? 'selected' : '' ?>>
                            <?= e($cl['name']) ?><?= !empty($cl['phone']) ? ' — '.$cl['phone'] : '' ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <input type="text" id="customer_name" name="customer_name" class="form-control form-control-sm mb-1"
                           style="border-color:#e2e8f0" placeholder="Customer name"
                           value="<?= e($_POST['customer_name'] ?? $fromQrCustomer['name']  ?? '') ?>">
                    <div class="row g-1">
                        <div class="col-6">
                            <input type="text" id="customer_phone" name="customer_phone" class="form-control form-control-sm"
                                   style="border-color:#e2e8f0" placeholder="Phone"
                                   value="<?= e($_POST['customer_phone'] ?? $fromQrCustomer['phone'] ?? '') ?>">
                        </div>
                        <div class="col-6">
                            <input type="email" name="customer_email" class="form-control form-control-sm"
                                   style="border-color:#e2e8f0" placeholder="Email"
                                   value="<?= e($_POST['customer_email'] ?? $fromQrCustomer['email'] ?? '') ?>">
                        </div>
                    </div>
                </div>
                <!-- Vehicle -->
                <div>
                    <div class="mb-2" style="font-size:10.5px;font-weight:600;text-transform:uppercase;letter-spacing:.5px;color:#64748b">
                        <i class="fa fa-car me-1"></i>Vehicle <span class="text-danger">*</span>
                    </div>
                    <select name="car_id" id="car_select" class="form-select form-select-sm select2" style="border-color:#e2e8f0" required>
                        <option value="">Select car...</option>
                        <?php foreach ($cars as $c): ?>
                        <option value="<?= (int)$c['id'] ?>"
                                data-type="<?= e($c['car_type']) ?>"
                                data-owner="<?= e($c['owner_name'] ?? '') ?>"
                                data-phone="<?= e($c['owner_phone'] ?? '') ?>"
                                <?= (int)(($_POST['car_id'] ?? $preCarId) ?: 0) === (int)$c['id'] ? 'selected' : '' ?>>
                            <?= e($c['make'].' '.$c['model'].' — '.$c['chassis_number']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <?php
                    $qrMake = $fromQr['car_make']         ?? '';
                    $qrMod  = $fromQr['car_model']        ?? '';
                    $qrReg  = $fromQr['car_registration'] ?? '';
                    $qrChas = $fromQr['car_chassis']      ?? '';
                    if ($fromQrId && ($qrMake || $qrReg || $qrChas)): ?>
                    <div class="mt-2 p-2 rounded-2 small" style="background:#f0f9ff;border:1px solid #bae6fd">
                        <div class="fw-semibold text-primary mb-1" style="font-size:10.5px;text-transform:uppercase;letter-spacing:.4px">
                            <i class="fa fa-car me-1"></i>From Quote Request
                        </div>
                        <?php if ($qrMake): ?><div class="fw-medium text-dark"><?= e(trim($qrMake.' '.$qrMod)) ?></div><?php endif; ?>
                        <?php if ($qrReg): ?><div class="text-muted">Reg: <strong class="text-dark"><?= e(strtoupper($qrReg)) ?></strong></div><?php endif; ?>
                        <?php if ($qrChas): ?><div class="text-muted">Chassis: <code style="font-size:11px"><?= e($qrChas) ?></code></div><?php endif; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Quote Details -->
        <div class="card mb-3 border-0 shadow-sm">
            <div class="card-header border-bottom py-3 d-flex align-items-center gap-2" style="background:#fff">
                <span style="width:28px;height:28px;background:#f0fdf4;color:#16a34a;border-radius:6px;display:inline-flex;align-items:center;justify-content:center">
                    <i class="fa fa-calendar" style="font-size:12px"></i>
                </span>
                <span class="fw-semibold">Quote Details</span>
            </div>
            <div class="card-body">
                <div class="row g-2">
                    <div class="col-6">
                        <label class="form-label small fw-semibold mb-1" style="color:#475569">Date</label>
                        <input type="date" name="date" class="form-control form-control-sm" style="border-color:#e2e8f0"
                               value="<?= e($_POST['date'] ?? date('Y-m-d')) ?>">
                    </div>
                    <div class="col-6">
                        <label class="form-label small fw-semibold mb-1" style="color:#475569">Valid Until</label>
                        <input type="date" name="valid_until" class="form-control form-control-sm" style="border-color:#e2e8f0"
                               value="<?= e($_POST['valid_until'] ?? date('Y-m-d', strtotime('+30 days'))) ?>">
                    </div>
                    <div class="col-12">
                        <label class="form-label small fw-semibold mb-1" style="color:#475569">
                            Job Card <span class="fw-normal text-muted">(optional)</span>
                        </label>
                        <select name="job_id" id="job_select" class="form-select form-select-sm select2" style="border-color:#e2e8f0">
                            <option value="">No job card</option>
                            <?php foreach ($jobs as $j): ?>
                            <option value="<?= (int)$j['id'] ?>" <?= (int)(($_POST['job_id'] ?? $preJobId) ?: 0) === (int)$j['id'] ? 'selected' : '' ?>><?= e($j['job_number']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>
        </div>

        <!-- Summary & Save -->
        <div class="card border-0 shadow-sm">
            <div class="card-header border-bottom py-3 d-flex align-items-center gap-2" style="background:#fff">
                <span style="width:28px;height:28px;background:#fdf4ff;color:#9333ea;border-radius:6px;display:inline-flex;align-items:center;justify-content:center">
                    <i class="fa fa-calculator" style="font-size:12px"></i>
                </span>
                <span class="fw-semibold">Summary</span>
            </div>
            <div class="card-body">
                <!-- Discount & VAT -->
                <div class="row g-2 mb-3">
                    <div class="col-6">
                        <label class="form-label small fw-semibold mb-1" style="color:#475569">Discount %</label>
                        <div class="input-group input-group-sm">
                            <input type="number" id="overall_discount" name="overall_discount"
                                   class="form-control" style="border-color:#e2e8f0"
                                   value="<?= e($_POST['overall_discount']??0) ?>"
                                   min="0" max="100" step="0.01">
                            <span class="input-group-text" style="border-color:#e2e8f0;background:#f8fafc;color:#64748b;font-size:13px">%</span>
                        </div>
                    </div>
                    <div class="col-6">
                        <label class="form-label small fw-semibold mb-1" style="color:#475569">VAT %</label>
                        <div class="input-group input-group-sm">
                            <input type="number" id="tax_rate" name="tax_rate"
                                   class="form-control" style="border-color:#e2e8f0"
                                   value="<?= e($_POST['tax_rate']??$vatRate) ?>"
                                   min="0" max="100" step="0.01">
                            <span class="input-group-text" style="border-color:#e2e8f0;background:#f8fafc;color:#64748b;font-size:13px">%</span>
                        </div>
                    </div>
                </div>

                <!-- Totals breakdown -->
                <div class="rounded-3 overflow-hidden mb-3" style="border:1px solid #e2e8f0">
                    <div class="d-flex justify-content-between px-3 py-2" style="background:#f8fafc">
                        <span class="text-muted small">Subtotal</span>
                        <span class="fw-semibold small">KES <span id="subtotal_display">0.00</span></span>
                    </div>
                    <div class="d-flex justify-content-between px-3 py-2" style="border-top:1px solid #f1f5f9">
                        <span class="text-muted small">Discount</span>
                        <span class="small text-danger">− KES <span id="discount_display">0.00</span></span>
                    </div>
                    <div class="d-flex justify-content-between px-3 py-2" style="border-top:1px solid #f1f5f9">
                        <span class="text-muted small">VAT</span>
                        <span class="small">KES <span id="tax_display">0.00</span></span>
                    </div>
                    <div class="d-flex justify-content-between align-items-center px-3 py-3"
                         style="background:linear-gradient(135deg,#1e40af,#2563eb);border-top:2px solid #1d4ed8">
                        <span class="fw-bold text-white">Total</span>
                        <span class="fw-bold text-white" style="font-size:18px">KES <span id="total_display">0.00</span></span>
                    </div>
                </div>

                <!-- Save buttons -->
                <div class="d-grid gap-2">
                    <button type="submit" class="btn btn-primary fw-semibold"
                            style="border-radius:8px;padding:10px 16px"
                            onclick="document.getElementById('_after_save').value='view'">
                        <i class="fa fa-save me-2"></i>Save Quotation
                    </button>
                    <div class="row g-2">
                        <div class="col-6">
                            <button type="submit" class="btn btn-outline-dark w-100"
                                    style="border-radius:8px"
                                    onclick="document.getElementById('_after_save').value='print'">
                                <i class="fa fa-print me-1"></i>Save &amp; Print
                            </button>
                        </div>
                        <div class="col-6">
                            <button type="submit" class="btn btn-outline-info w-100"
                                    style="border-radius:8px"
                                    onclick="document.getElementById('_after_save').value='send'">
                                <i class="fa fa-envelope me-1"></i>Save &amp; Send
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

    </div><!-- /sticky -->
</div><!-- /col right -->

</div><!-- /row -->
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
        tr.style.borderBottom = '1px solid #f1f5f9';
        tr.innerHTML =
            '<td class="ps-3 py-2">' +
                '<select name="item_type[]" class="form-select form-select-sm"' +
                    ' style="border:none;background:#f1f5f9;border-radius:6px;font-size:12px">' +
                    '<option value="part">Part</option>' +
                    '<option value="labour">Labour</option>' +
                    '<option value="service">Service</option>' +
                '</select>' +
            '</td>' +
            '<td class="py-2">' +
                '<input type="text" name="item_desc[]"' +
                    ' class="form-control form-control-sm item-desc"' +
                    ' style="border-color:#e2e8f0;border-radius:6px"' +
                    ' placeholder="Item description..." required>' +
            '</td>' +
            '<td class="py-2">' +
                '<select name="item_inv_id[]"' +
                    ' class="form-select form-select-sm inventory-select"' +
                    ' style="border-color:#e2e8f0;border-radius:6px">' +
                    _invOpts +
                '</select>' +
            '</td>' +
            '<td class="py-2 text-center">' +
                '<input type="number" name="item_qty[]"' +
                    ' class="form-control form-control-sm item-qty text-center"' +
                    ' style="border-color:#e2e8f0;border-radius:6px"' +
                    ' value="1" min="0.01" step="0.01">' +
            '</td>' +
            '<td class="py-2">' +
                '<input type="number" name="item_price[]"' +
                    ' class="form-control form-control-sm item-price text-end"' +
                    ' style="border-color:#e2e8f0;border-radius:6px"' +
                    ' value="0" min="0" step="0.01">' +
            '</td>' +
            '<td class="py-2 text-center">' +
                '<input type="number" name="item_disc[]"' +
                    ' class="form-control form-control-sm item-discount text-center"' +
                    ' style="border-color:#e2e8f0;border-radius:6px"' +
                    ' value="0" min="0" max="100" step="0.01">' +
            '</td>' +
            '<td class="py-2 text-end pe-3">' +
                '<span class="item-total fw-semibold" style="color:#0f172a">0.00</span>' +
            '</td>' +
            '<td class="py-2 pe-2 text-center">' +
                '<button type="button" class="btn btn-sm remove-line-item"' +
                    ' style="width:26px;height:26px;padding:0;border-radius:6px;border:1px solid #fecaca;background:#fff5f5;color:#ef4444;line-height:1"' +
                    ' title="Remove row">' +
                    '<i class="fa fa-times" style="font-size:11px"></i>' +
                '</button>' +
            '</td>';
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

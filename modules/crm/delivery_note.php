<?php
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
canAccess('crm') || redirect(BASE_URL . '/index.php');

$db  = getDB();
$me  = authUser();
$uid = (int)$me['id'];

foreach ([
    "ALTER TABLE crm_leads ADD COLUMN pinned_car_id          INT           NULL DEFAULT NULL",
    "ALTER TABLE crm_leads ADD COLUMN agreed_sale_price      DECIMAL(15,2) NULL DEFAULT NULL",
    "ALTER TABLE crm_leads ADD COLUMN deposit_amount         DECIMAL(15,2) NULL DEFAULT NULL",
    "ALTER TABLE crm_leads ADD COLUMN import_vehicle_details TEXT          NULL DEFAULT NULL",
    "ALTER TABLE crm_leads ADD COLUMN delivered_at           DATETIME      NULL DEFAULT NULL",
    "ALTER TABLE clients   ADD COLUMN kra_pin                VARCHAR(20)   NULL",
    "ALTER TABLE clients   ADD COLUMN id_number              VARCHAR(30)   NULL",
] as $_sql) { try { $db->exec($_sql); } catch (\Throwable $_) {} }

$leadId = (int)($_GET['lead_id'] ?? 0);
if (!$leadId) { setFlash('error','No lead specified.'); redirect(BASE_URL.'/modules/crm/leads.php'); }

$stLead = $db->prepare("SELECT * FROM crm_leads WHERE id = ?");
$stLead->execute([$leadId]);
$lead = $stLead->fetch();
if (!$lead) { setFlash('error','Lead not found.'); redirect(BASE_URL.'/modules/crm/leads.php'); }

if ($me['role'] === 'customer_relations' && (int)$lead['assigned_to'] !== $uid) {
    setFlash('error','You can only view leads assigned to you.');
    redirect(BASE_URL.'/modules/crm/my_dashboard.php');
}

// Load car
$car = null;
if (!empty($lead['pinned_car_id'])) {
    try {
        $s = $db->prepare("SELECT * FROM cars WHERE id = ?");
        $s->execute([(int)$lead['pinned_car_id']]);
        $car = $s->fetch() ?: null;
    } catch (\Throwable $_) {}
}

// Load client
$client = null;
if (!empty($lead['client_id'])) {
    try {
        $s = $db->prepare("SELECT * FROM clients WHERE id = ?");
        $s->execute([(int)$lead['client_id']]);
        $client = $s->fetch() ?: null;
    } catch (\Throwable $_) {}
}

// Load assigning agent
$agent = null;
if (!empty($lead['assigned_to'])) {
    try {
        $s = $db->prepare("SELECT name, phone FROM users WHERE id = ?");
        $s->execute([(int)$lead['assigned_to']]);
        $agent = $s->fetch() ?: null;
    } catch (\Throwable $_) {}
}

$buyerName   = trim($client['name']      ?? $lead['name']  ?? '');
$buyerPhone  = trim($client['phone']     ?? $lead['phone'] ?? '');
$buyerIdNo   = trim($client['id_number'] ?? '');
$buyerKraPin = trim($client['kra_pin']   ?? '');

$agreedPrice = (float)($lead['agreed_sale_price'] ?? 0);
$depositAmt  = (float)($lead['deposit_amount']    ?? 0);
if (!$agreedPrice && $car) {
    $agreedPrice = (float)($car['offer_price'] ?? 0) ?: (float)($car['asking_price'] ?? 0);
}
$balance = max(0, $agreedPrice - $depositAmt);

$deliveryDate = $lead['delivered_at'] ?? ($lead['converted_at'] ?? date('Y-m-d'));
$deliveryDate = substr((string)$deliveryDate, 0, 10);

if ($lead['import_vehicle_details']) {
    $carDesc = $lead['import_vehicle_details'];
} elseif ($car) {
    $carDesc = trim(($car['year']??'').' '.($car['make']??'').' '.($car['model']??''));
} else {
    $carDesc = $lead['interested_in'] ?? '';
}

$noteNo = 'DN-' . str_pad($leadId, 4, '0', STR_PAD_LEFT) . '-' . date('ymd');

$pageTitle = 'Delivery Note — ' . $buyerName;
include __DIR__ . '/../../includes/header.php';
?>
<style>
@page { size: A4; margin: 0; }
@media print {
    .d-print-none { display:none !important; }
    .app-sidebar,.topbar,.sidebar-overlay,.app-topbar,
    header.app-topbar,#sidebarBackdrop,.fab-wa,.fab-chat,
    #pwaOverlay,#toastStack { display:none !important; }
    .main-wrap,.main-content,.page-body { margin:0 !important; padding:0 !important; }
    body { background:#fff !important; margin:0 !important; }
    #dnDoc {
        box-shadow:none !important; border:none !important; border-radius:0 !important;
        max-width:100% !important; padding:1.5cm 1.8cm !important;
    }
}
#dnDoc {
    max-width:700px; margin:0 auto;
    background:#fff; border:1px solid #ccc; border-radius:6px;
    font-family:Arial,Helvetica,sans-serif;
    font-size:12.5px; color:#000; line-height:1.5;
    box-shadow:0 4px 20px rgba(0,0,0,.1);
    padding:28px 32px;
}
.dn-table { width:100%; border-collapse:collapse; margin:8px 0; }
.dn-table td, .dn-table th {
    border:1px solid #333; padding:7px 11px;
    font-size:12.5px; vertical-align:top;
}
.dn-table th { background:#f5f5f5; font-weight:700; width:36%; white-space:nowrap; }
.sig-line { border-bottom:1.5px solid #333; min-height:44px; margin-bottom:5px; }
.dn-checklist { list-style:none; margin:0; padding:0; }
.dn-checklist li { padding:5px 0; border-bottom:1px solid #e5e7eb; display:flex; align-items:center; gap:10px; }
.dn-checklist li:last-child { border-bottom:none; }
.dn-box { width:14px; height:14px; border:1.5px solid #333; flex-shrink:0; display:inline-block; }
</style>

<!-- Action bar -->
<div class="d-print-none mb-4 d-flex align-items-center justify-content-between flex-wrap gap-2">
    <div class="d-flex align-items-center gap-2">
        <a href="view_lead.php?id=<?= $leadId ?>" class="btn btn-outline-secondary btn-sm">
            <i class="fa fa-arrow-left me-1"></i>Back to Lead
        </a>
        <span class="text-muted" style="font-size:12.5px">/ <?= e($lead['name']) ?></span>
    </div>
    <button class="btn btn-success btn-sm" onclick="window.print()">
        <i class="fa fa-print me-1"></i>Print / Save PDF
    </button>
</div>

<div id="dnDoc">

    <!-- ── Header ──────────────────────────────────────────────────────────── -->
    <div style="display:flex;justify-content:space-between;align-items:flex-start;
                padding-bottom:14px;border-bottom:2px solid #111;margin-bottom:18px">
        <div>
            <div style="font-family:'Times New Roman',Times,Georgia,serif;
                        font-style:italic;font-size:28px;font-weight:normal;
                        line-height:1.1;color:#000">
                MASCARDI<br>VENTURES LIMITED
            </div>
            <div style="font-size:11px;color:#444;margin-top:6px;line-height:1.7">
                291 Kabete Lane, Spring Valley<br>
                P.O.Box 1391-00606, Nairobi Kenya<br>
                Sales@mascardi.co
            </div>
        </div>
        <div style="text-align:right">
            <div style="font-size:22px;font-weight:900;letter-spacing:2px;
                        text-transform:uppercase;color:#111">DELIVERY NOTE</div>
            <div style="font-size:10px;color:#666;font-style:italic;margin-top:2px">Vehicle Handover Certificate</div>
            <div style="font-size:12px;color:#555;margin-top:8px;line-height:1.9">
                Note No: <strong><?= e($noteNo) ?></strong><br>
                Date: <strong><?= (new DateTime($deliveryDate))->format('d/m/Y') ?></strong>
            </div>
        </div>
    </div>

    <!-- ── Buyer & Delivery Details ───────────────────────────────────────── -->
    <table class="dn-table">
        <tr>
            <th>Buyer / Recipient</th>
            <td><strong><?= e($buyerName) ?></strong></td>
        </tr>
        <?php if ($buyerPhone): ?>
        <tr>
            <th>Phone</th>
            <td><?= e($buyerPhone) ?></td>
        </tr>
        <?php endif; ?>
        <?php if ($buyerIdNo): ?>
        <tr>
            <th>I.D. / Passport No.</th>
            <td><?= e($buyerIdNo) ?></td>
        </tr>
        <?php endif; ?>
        <?php if ($buyerKraPin): ?>
        <tr>
            <th>KRA PIN</th>
            <td><?= e($buyerKraPin) ?></td>
        </tr>
        <?php endif; ?>
        <tr>
            <th>Delivery Date</th>
            <td><strong><?= (new DateTime($deliveryDate))->format('d F Y') ?></strong></td>
        </tr>
        <?php if ($agent): ?>
        <tr>
            <th>Delivered By</th>
            <td><?= e($agent['name']) ?><?= $agent['phone'] ? ' — ' . e($agent['phone']) : '' ?></td>
        </tr>
        <?php endif; ?>
    </table>

    <!-- ── Vehicle Details ────────────────────────────────────────────────── -->
    <div style="margin-top:16px;margin-bottom:4px;font-weight:700;font-size:13px;
                border-bottom:1px solid #333;padding-bottom:4px">Vehicle Details</div>
    <?php if ($car): ?>
    <table class="dn-table">
        <tr>
            <th>Make / Model</th>
            <td><strong><?= e(trim(($car['make']??'').' '.($car['model']??''))) ?></strong>
                <?php if ($car['year']): ?>(<?= e($car['year']) ?>)<?php endif; ?>
            </td>
        </tr>
        <?php if ($car['registration_number']): ?>
        <tr>
            <th>Registration No.</th>
            <td><?= e($car['registration_number']) ?></td>
        </tr>
        <?php endif; ?>
        <?php if ($car['chassis_number']): ?>
        <tr>
            <th>Chassis / VIN No.</th>
            <td><?= e($car['chassis_number']) ?></td>
        </tr>
        <?php endif; ?>
        <?php if ($car['engine_number']): ?>
        <tr>
            <th>Engine No.</th>
            <td><?= e($car['engine_number']) ?></td>
        </tr>
        <?php endif; ?>
        <tr>
            <th>Colour</th>
            <td><?= e(ucfirst($car['color'] ?? '—')) ?></td>
        </tr>
        <?php if (!empty($car['mileage'])): ?>
        <tr>
            <th>Mileage at Delivery</th>
            <td><?= number_format((int)$car['mileage']) ?> km</td>
        </tr>
        <?php endif; ?>
    </table>
    <?php else: ?>
    <table class="dn-table">
        <tr>
            <th>Vehicle Description</th>
            <td><?= e($carDesc ?: '—') ?></td>
        </tr>
    </table>
    <?php endif; ?>

    <!-- ── Payment Summary ────────────────────────────────────────────────── -->
    <div style="margin-top:16px;margin-bottom:4px;font-weight:700;font-size:13px;
                border-bottom:1px solid #333;padding-bottom:4px">Payment Summary</div>
    <table class="dn-table">
        <tr>
            <th>Total Agreed Price</th>
            <td><?= $agreedPrice > 0 ? 'KES ' . number_format($agreedPrice, 0) . '/-' : '—' ?></td>
        </tr>
        <tr>
            <th>Deposit Paid</th>
            <td style="color:#15803d;font-weight:700">KES <?= number_format($depositAmt, 0) ?>/-</td>
        </tr>
        <tr>
            <th>Balance Settled</th>
            <td style="font-weight:700">
                <?= $balance <= 0 ? '<span style="color:#15803d">Fully Paid</span>' : 'KES ' . number_format($balance, 0) . '/-' ?>
            </td>
        </tr>
    </table>

    <!-- ── Handover Checklist ─────────────────────────────────────────────── -->
    <div style="margin-top:16px;margin-bottom:8px;font-weight:700;font-size:13px;
                border-bottom:1px solid #333;padding-bottom:4px">Handover Checklist</div>
    <ul class="dn-checklist" style="font-size:12px">
        <li><span class="dn-box"></span>All vehicle keys handed over</li>
        <li><span class="dn-box"></span>Logbook / title documents handed over</li>
        <li><span class="dn-box"></span>Vehicle in agreed condition</li>
        <li><span class="dn-box"></span>Insurance / road worthiness confirmed</li>
        <li><span class="dn-box"></span>Spare tyre and tools present</li>
        <li><span class="dn-box"></span>Full payment received / settlement confirmed</li>
    </ul>

    <!-- ── Declaration ────────────────────────────────────────────────────── -->
    <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:4px;
                padding:10px 14px;margin-top:14px;font-size:11.5px;color:#374151;line-height:1.7">
        I, <strong><?= e($buyerName) ?></strong>, hereby confirm that I have received the above-described vehicle
        from Mascardi Ventures Limited in satisfactory condition on
        <strong><?= (new DateTime($deliveryDate))->format('d F Y') ?></strong>, and that all items listed
        in the handover checklist above have been received and verified.
    </div>

    <!-- ── Signatures ─────────────────────────────────────────────────────── -->
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:32px;margin-top:32px">
        <div>
            <div class="sig-line"></div>
            <div style="font-size:11.5px;font-weight:600">Buyer Signature</div>
            <div style="font-size:11px;color:#555;margin-top:2px"><?= e($buyerName) ?></div>
            <div style="font-size:11px;color:#555">Date: ___________________</div>
        </div>
        <div>
            <div class="sig-line"></div>
            <div style="font-size:11.5px;font-weight:600">Authorized Signatory</div>
            <div style="font-size:11px;color:#555;margin-top:2px">For Mascardi Ventures Limited</div>
            <div style="font-size:11px;color:#555">Date: ___________________</div>
        </div>
    </div>

    <div style="text-align:center;margin-top:22px;font-size:10px;color:#9ca3af;border-top:1px solid #e5e7eb;padding-top:10px">
        Mascardi Ventures Limited &bull; 291 Kabete Lane, Spring Valley, Nairobi &bull; Sales@mascardi.co
    </div>

</div>

<div class="d-print-none mt-4 mb-4"></div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>

<?php
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
canAccess('crm') || redirect(BASE_URL . '/index.php');

$db  = getDB();
$me  = authUser();
$uid = (int)$me['id'];

foreach ([
    "ALTER TABLE crm_leads ADD COLUMN pinned_car_id     INT           NULL DEFAULT NULL",
    "ALTER TABLE crm_leads ADD COLUMN deposit_amount    DECIMAL(15,2) NULL DEFAULT NULL",
    "ALTER TABLE crm_leads ADD COLUMN deposit_date      DATE          NULL DEFAULT NULL",
    "ALTER TABLE crm_leads ADD COLUMN deposit_notes     TEXT          NULL DEFAULT NULL",
    "ALTER TABLE crm_leads ADD COLUMN agreed_sale_price DECIMAL(15,2) NULL DEFAULT NULL",
    "ALTER TABLE crm_leads ADD COLUMN due_date          DATE          NULL DEFAULT NULL",
    "ALTER TABLE clients   ADD COLUMN kra_pin           VARCHAR(20)   NULL",
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

// Company settings
$companyPhone = getSetting('company_phone', '254 722 200018');
$companyEmail = getSetting('company_email', 'mascardiventures@gmail.com');

// Buyer details
$buyerName   = trim($client['name']      ?? $lead['name']  ?? '');
$buyerIdNo   = trim($client['id_number'] ?? '');
$buyerKraPin = trim($client['kra_pin']   ?? '');

// Amounts
$deposit     = (float)($lead['deposit_amount']    ?? 0);
$agreedPrice = (float)($lead['agreed_sale_price'] ?? 0);
if (!$agreedPrice && $car) {
    $offer   = (float)($car['offer_price']  ?? 0);
    $asking  = (float)($car['asking_price'] ?? 0);
    $agreedPrice = $offer > 0 ? $offer : $asking;
}
$balance    = max(0, $agreedPrice - $deposit);
$depDate    = $lead['deposit_date'] ?? date('Y-m-d');
$dueDateRaw = $lead['due_date'] ?? '';
$dueDate    = $dueDateRaw ? (new DateTime($dueDateRaw))->format('d/m/Y') : '';

// Number to words
function _drHW(int $n): string {
    static $o = ['','One','Two','Three','Four','Five','Six','Seven','Eight','Nine',
                 'Ten','Eleven','Twelve','Thirteen','Fourteen','Fifteen','Sixteen',
                 'Seventeen','Eighteen','Nineteen'];
    static $t = ['','','Twenty','Thirty','Forty','Fifty','Sixty','Seventy','Eighty','Ninety'];
    $w = '';
    if ($n >= 100) { $w .= $o[(int)($n/100)].' Hundred '; $n %= 100; }
    $w .= ($n < 20) ? $o[$n] : ($t[(int)($n/10)].($n%10 ? ' '.$o[$n%10] : ''));
    return trim($w);
}
function drNumWords(float $amt): string {
    $n = (int)round($amt);
    if (!$n) return 'Zero Only';
    $parts = [];
    foreach ([['Billion',1_000_000_000],['Million',1_000_000],['Thousand',1_000]] as [$l,$d]) {
        if ($n >= $d) { $parts[] = _drHW((int)($n/$d)).' '.$l; $n %= $d; }
    }
    if ($n) $parts[] = _drHW($n);
    return 'Kenya Shillings ' . implode(' ', array_filter($parts)) . ' Only';
}

$receiptNo = 'DR-' . str_pad($leadId, 4, '0', STR_PAD_LEFT) . '-' . date('ymd');
$today     = date('d/m/Y');
$carDesc   = $car
    ? trim(($car['year'] ?? '') . ' ' . ($car['make'] ?? '') . ' ' . ($car['model'] ?? ''))
    : ($lead['interested_in'] ?? '');

$pageTitle = 'Deposit Receipt — ' . $buyerName;
include __DIR__ . '/../../includes/header.php';
?>
<style>
@media print {
    .d-print-none { display:none !important; }
    .app-sidebar,.topbar,.sidebar-overlay,.app-topbar,
    header.app-topbar,#sidebarBackdrop,.fab-wa,.fab-chat,
    #pwaOverlay,#toastStack { display:none !important; }
    .main-wrap,.main-content,.page-body { margin:0 !important; padding:0 !important; }
    body { background:#fff !important; margin:0 !important; }
    @page { size: A4; margin: 1.2cm; }
    #drDoc { box-shadow:none !important; border:none !important; border-radius:0 !important; }
}
#drDoc {
    max-width:700px; margin:0 auto;
    background:#fff; border:1px solid #ccc; border-radius:6px;
    font-family:Arial,Helvetica,sans-serif;
    font-size:12.5px; color:#000; line-height:1.5;
    box-shadow:0 4px 20px rgba(0,0,0,.1);
    padding:28px 32px;
}
.dr-table { width:100%; border-collapse:collapse; margin:8px 0; }
.dr-table td, .dr-table th {
    border:1px solid #333; padding:7px 11px;
    font-size:12.5px; vertical-align:top;
}
.dr-table th { background:#f5f5f5; font-weight:700; width:36%; white-space:nowrap; }
.sig-line { border-bottom:1.5px solid #333; min-height:44px; margin-bottom:5px; }
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

<div id="drDoc">

    <!-- ── Header ──────────────────────────────────────────────────────────── -->
    <div style="display:flex;justify-content:space-between;align-items:flex-start;
                padding-bottom:14px;border-bottom:2px solid #111;margin-bottom:18px">
        <!-- Company -->
        <div>
            <div style="font-family:'Times New Roman',Times,Georgia,serif;
                        font-style:italic;font-size:28px;font-weight:normal;
                        line-height:1.1;color:#000">
                MASCARDI<br>VENTURES LIMITED
            </div>
            <div style="font-size:11px;color:#444;margin-top:6px;line-height:1.7">
                P O Box 1391, Nairobi 00606<br>
                Tel: <?= e($companyPhone) ?><br>
                Email: <?= e($companyEmail) ?>
            </div>
        </div>
        <!-- Title + ref -->
        <div style="text-align:right">
            <div style="font-size:22px;font-weight:900;letter-spacing:2px;
                        text-transform:uppercase;color:#111">DEPOSIT RECEIPT</div>
            <div style="font-size:12px;color:#555;margin-top:8px;line-height:1.9">
                Receipt No: <strong><?= e($receiptNo) ?></strong><br>
                Date: <strong><?= e($today) ?></strong>
            </div>
        </div>
    </div>

    <!-- ── Received from ───────────────────────────────────────────────────── -->
    <table class="dr-table">
        <tr>
            <th>Received From</th>
            <td><strong><?= e($buyerName) ?></strong></td>
        </tr>
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
    </table>

    <!-- ── Amount ──────────────────────────────────────────────────────────── -->
    <table class="dr-table" style="margin-top:12px">
        <tr>
            <th>Amount (Figures)</th>
            <td style="font-size:16px;font-weight:700">
                KES <?= number_format($deposit, 0) ?>/-
            </td>
        </tr>
        <tr>
            <th>Amount (Words)</th>
            <td style="font-style:italic"><?= drNumWords($deposit) ?></td>
        </tr>
        <tr>
            <th>Being Payment For</th>
            <td>
                Reservation deposit for <strong><?= e($carDesc) ?></strong>
                <?php if ($car && !empty($car['chassis_number'])): ?>
                <br><span style="font-size:11.5px;color:#444">Chassis: <?= e($car['chassis_number']) ?></span>
                <?php endif; ?>
                <?php if (!empty($lead['deposit_notes'])): ?>
                <br><span style="font-size:11.5px;color:#444">Ref: <?= e($lead['deposit_notes']) ?></span>
                <?php endif; ?>
            </td>
        </tr>
        <tr>
            <th>Date of Payment</th>
            <td><?= $depDate ? (new DateTime($depDate))->format('d M Y') : e($today) ?></td>
        </tr>
    </table>

    <!-- ── Payment schedule ────────────────────────────────────────────────── -->
    <table class="dr-table" style="margin-top:12px">
        <tr>
            <th>Total Agreed Price</th>
            <td><?= $agreedPrice > 0 ? 'KES ' . number_format($agreedPrice, 0) . '/-' : '—' ?></td>
        </tr>
        <tr>
            <th>Deposit Paid</th>
            <td style="color:#15803d;font-weight:700">KES <?= number_format($deposit, 0) ?>/-</td>
        </tr>
        <tr>
            <th>Balance Due</th>
            <td style="color:#c2410c;font-weight:700">
                KES <?= number_format($balance, 0) ?>/-
                <?php if ($dueDate): ?>
                <span style="font-size:11px;color:#555;font-weight:normal">
                    — due by <?= e($dueDate) ?>
                </span>
                <?php endif; ?>
            </td>
        </tr>
    </table>

    <!-- ── Vehicle details ─────────────────────────────────────────────────── -->
    <?php if ($car): ?>
    <div style="background:#f8f8f8;border:1px solid #ddd;border-radius:4px;
                padding:10px 14px;margin-top:14px;font-size:12px">
        <div style="font-weight:700;margin-bottom:6px;font-size:12.5px">Vehicle Details</div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:2px 24px;line-height:1.9">
            <div><span style="font-weight:600">Make / Model:</span>
                <?= e(trim(($car['make']??'').' '.($car['model']??''))) ?></div>
            <div><span style="font-weight:600">Year:</span> <?= e($car['year'] ?? '—') ?></div>
            <div><span style="font-weight:600">Registration:</span>
                <?= e($car['registration_number'] ?: 'New') ?></div>
            <div><span style="font-weight:600">Color:</span>
                <?= e(ucfirst($car['color'] ?? '—')) ?></div>
            <?php if (!empty($car['chassis_number'])): ?>
            <div><span style="font-weight:600">Chassis No:</span>
                <?= e($car['chassis_number']) ?></div>
            <?php endif; ?>
            <?php if (!empty($car['engine_number'])): ?>
            <div><span style="font-weight:600">Engine No:</span>
                <?= e($car['engine_number']) ?></div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- ── Note ────────────────────────────────────────────────────────────── -->
    <div style="background:#fffbeb;border:1px solid #fde68a;border-radius:4px;
                padding:9px 14px;margin-top:14px;font-size:12px;color:#78350f">
        <strong>Note:</strong> This receipt is valid as proof of deposit payment.
        The vehicle remains the property of Mascardi Ventures Limited until full
        payment is received.
    </div>

    <!-- ── Signatures ──────────────────────────────────────────────────────── -->
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:32px;margin-top:28px">
        <div>
            <div class="sig-line"></div>
            <div style="font-size:11.5px;font-weight:600">Customer Signature</div>
            <div style="font-size:11px;color:#555;margin-top:2px"><?= e($buyerName) ?></div>
        </div>
        <div>
            <div class="sig-line"></div>
            <div style="font-size:11.5px;font-weight:600">Authorized Signatory</div>
            <div style="font-size:11px;color:#555;margin-top:2px">For Mascardi Ventures Limited</div>
        </div>
    </div>

</div>

<div class="d-print-none mt-4 mb-4"></div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>

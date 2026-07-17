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

// Load agent
$agentUser = null;
if (!empty($lead['assigned_to'])) {
    try {
        $s = $db->prepare("SELECT name, phone FROM users WHERE id = ?");
        $s->execute([(int)$lead['assigned_to']]);
        $agentUser = $s->fetch() ?: null;
    } catch (\Throwable $_) {}
}

// Company contact (fixed — same as sales agreement)

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
$balance = max(0, $agreedPrice - $deposit);
$depDate = $lead['deposit_date'] ?? '';

// Number to words
function _srHW(int $n): string {
    static $o = ['','One','Two','Three','Four','Five','Six','Seven','Eight','Nine',
                 'Ten','Eleven','Twelve','Thirteen','Fourteen','Fifteen','Sixteen',
                 'Seventeen','Eighteen','Nineteen'];
    static $t = ['','','Twenty','Thirty','Forty','Fifty','Sixty','Seventy','Eighty','Ninety'];
    $w = '';
    if ($n >= 100) { $w .= $o[(int)($n/100)].' Hundred '; $n %= 100; }
    $w .= ($n < 20) ? $o[$n] : ($t[(int)($n/10)].($n%10 ? ' '.$o[$n%10] : ''));
    return trim($w);
}
function srNumWords(float $amt): string {
    $n = (int)round($amt);
    if (!$n) return 'Zero Only';
    $parts = [];
    foreach ([['Billion',1_000_000_000],['Million',1_000_000],['Thousand',1_000]] as [$l,$d]) {
        if ($n >= $d) { $parts[] = _srHW((int)($n/$d)).' '.$l; $n %= $d; }
    }
    if ($n) $parts[] = _srHW($n);
    return 'Kenya Shillings ' . implode(' ', array_filter($parts)) . ' Only';
}

$receiptNo = 'SR-' . str_pad($leadId, 4, '0', STR_PAD_LEFT) . '-' . date('ymd');
$today     = date('d/m/Y');
$carDesc   = $car
    ? trim(($car['year'] ?? '') . ' ' . ($car['make'] ?? '') . ' ' . ($car['model'] ?? ''))
    : ($lead['interested_in'] ?? '');

$pageTitle = 'Sales Receipt — ' . $buyerName;
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
    #srDoc { box-shadow:none !important; border:none !important; border-radius:0 !important; }
}
#srDoc {
    max-width:700px; margin:0 auto;
    background:#fff; border:1px solid #ccc; border-radius:6px;
    font-family:Arial,Helvetica,sans-serif;
    font-size:12.5px; color:#000; line-height:1.5;
    box-shadow:0 4px 20px rgba(0,0,0,.1);
    padding:28px 32px;
}
.sr-table { width:100%; border-collapse:collapse; margin:8px 0; }
.sr-table td, .sr-table th {
    border:1px solid #333; padding:7px 11px;
    font-size:12.5px; vertical-align:top;
}
.sr-table th { background:#f5f5f5; font-weight:700; width:36%; white-space:nowrap; }
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

<div id="srDoc">

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
                291 Kabete Lane, Spring Valley<br>
                P.O.Box 1391-00606, Nairobi Kenya<br>
                Sales@mascardi.co
            </div>
        </div>
        <!-- Title + ref -->
        <div style="text-align:right">
            <div style="font-size:22px;font-weight:900;letter-spacing:2px;
                        text-transform:uppercase;color:#111">SALES RECEIPT</div>
            <div style="font-size:12px;color:#555;margin-top:8px;line-height:1.9">
                Receipt No: <strong><?= e($receiptNo) ?></strong><br>
                Date: <strong><?= e($today) ?></strong>
            </div>
        </div>
    </div>

    <!-- ── Buyer details ───────────────────────────────────────────────────── -->
    <table class="sr-table">
        <tr>
            <th>Sold To</th>
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

    <!-- ── Vehicle details ─────────────────────────────────────────────────── -->
    <table class="sr-table" style="margin-top:12px">
        <tr>
            <th>Vehicle</th>
            <td><strong><?= e($carDesc) ?></strong></td>
        </tr>
        <?php if ($car): ?>
        <tr>
            <th>Registration No.</th>
            <td><?= e($car['registration_number'] ?: 'New') ?></td>
        </tr>
        <tr>
            <th>Chassis No.</th>
            <td><?= e($car['chassis_number'] ?? '—') ?></td>
        </tr>
        <tr>
            <th>Engine No.</th>
            <td><?= e($car['engine_number'] ?? '—') ?></td>
        </tr>
        <tr>
            <th>Color</th>
            <td><?= e(ucfirst($car['color'] ?? '—')) ?></td>
        </tr>
        <tr>
            <th>Year</th>
            <td><?= e($car['year'] ?? '—') ?></td>
        </tr>
        <?php endif; ?>
    </table>

    <!-- ── Payment summary ─────────────────────────────────────────────────── -->
    <table class="sr-table" style="margin-top:12px">
        <tr>
            <th>Total Sale Price</th>
            <td style="font-size:15px;font-weight:700">
                <?= $agreedPrice > 0 ? 'KES ' . number_format($agreedPrice, 0) . '/-' : '—' ?>
            </td>
        </tr>
        <?php if ($deposit > 0): ?>
        <tr>
            <th>Less: Deposit Paid</th>
            <td style="color:#15803d">
                KES <?= number_format($deposit, 0) ?>/-
                <?php if ($depDate): ?>
                <span style="font-size:11px;color:#555">
                    (received <?= (new DateTime($depDate))->format('d M Y') ?>)
                </span>
                <?php endif; ?>
            </td>
        </tr>
        <tr>
            <th>Balance Received</th>
            <td style="font-size:15px;font-weight:700;color:#c2410c">
                KES <?= number_format($balance, 0) ?>/-
            </td>
        </tr>
        <?php endif; ?>
        <tr style="background:#f0fdf4">
            <th style="background:#f0fdf4">Total Amount Received</th>
            <td style="font-size:16px;font-weight:900;color:#15803d">
                KES <?= $agreedPrice > 0 ? number_format($agreedPrice, 0) . '/-' : '—' ?>
            </td>
        </tr>
        <tr>
            <th>Amount in Words</th>
            <td style="font-style:italic">
                <?= $agreedPrice > 0 ? srNumWords($agreedPrice) : '—' ?>
            </td>
        </tr>
    </table>

    <!-- ── Sales agent ─────────────────────────────────────────────────────── -->
    <?php if ($agentUser): ?>
    <table class="sr-table" style="margin-top:12px">
        <tr>
            <th>Sales Person</th>
            <td><?= e($agentUser['name']) ?></td>
        </tr>
        <?php if (!empty($agentUser['phone'])): ?>
        <tr>
            <th>Contact</th>
            <td><?= e($agentUser['phone']) ?></td>
        </tr>
        <?php endif; ?>
    </table>
    <?php endif; ?>

    <!-- ── Note ────────────────────────────────────────────────────────────── -->
    <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:4px;
                padding:9px 14px;margin-top:14px;font-size:12px;color:#14532d">
        <strong>Note:</strong> This receipt confirms receipt of the above payment for the
        vehicle described. Ownership of the vehicle transfers to the buyer upon receipt of
        full payment and completion of transfer formalities.
    </div>

    <!-- ── Signatures ──────────────────────────────────────────────────────── -->
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:32px;margin-top:28px">
        <div>
            <div class="sig-line"></div>
            <div style="font-size:11.5px;font-weight:600">Customer Signature</div>
            <div style="font-size:11px;color:#555;margin-top:2px"><?= e($buyerName) ?></div>
            <div style="font-size:11px;color:#555;margin-top:10px">Date: _______________</div>
        </div>
        <div>
            <div class="sig-line"></div>
            <div style="font-size:11.5px;font-weight:600">Authorized Signatory</div>
            <div style="font-size:11px;color:#555;margin-top:2px">For Mascardi Ventures Limited</div>
            <div style="font-size:11px;color:#555;margin-top:10px">Date: _______________</div>
        </div>
    </div>

</div>

<div class="d-print-none mt-4 mb-4"></div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>

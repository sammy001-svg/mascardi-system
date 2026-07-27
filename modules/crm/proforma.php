<?php
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/notifications.php';
requireLogin();
canAccess('crm') || redirect(BASE_URL . '/index.php');

$db  = getDB();
$me  = authUser();
$uid = (int)$me['id'];

// Column migrations
foreach ([
    "ALTER TABLE crm_leads ADD COLUMN pinned_car_id     INT           NULL DEFAULT NULL",
    "ALTER TABLE crm_leads ADD COLUMN agreed_sale_price DECIMAL(15,2) NULL DEFAULT NULL",
    "ALTER TABLE clients   ADD COLUMN kra_pin           VARCHAR(20)   NULL",
] as $_sql) { try { $db->exec($_sql); } catch (\Throwable $_) {} }

// Load lead
$leadId = (int)($_GET['lead_id'] ?? 0);
if (!$leadId) { setFlash('error', 'No lead specified.'); redirect(BASE_URL . '/modules/crm/leads.php'); }

$stLead = $db->prepare("SELECT * FROM crm_leads WHERE id = ?");
$stLead->execute([$leadId]);
$lead = $stLead->fetch();
if (!$lead) { setFlash('error', 'Lead not found.'); redirect(BASE_URL . '/modules/crm/leads.php'); }

if ($me['role'] === 'customer_relations' && (int)$lead['assigned_to'] !== $uid) {
    setFlash('error', 'You can only view leads assigned to you.');
    redirect(BASE_URL . '/modules/crm/my_dashboard.php');
}

// POST: notify sales team
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'send_notification') {
    $leadName = $lead['name'];
    $link     = BASE_URL . '/modules/crm/view_lead.php?id=' . $leadId;
    notifyRoles(['admin', 'sales_manager'], 'info',
        "CRM Proforma sent for {$leadName}",
        "Proforma quote prepared by {$me['name']}. Lead: {$leadName}.", $link);
    try {
        $db->prepare("INSERT INTO crm_activities (lead_id, type, summary, created_by, created_at) VALUES (?, 'note', ?, ?, NOW())")
           ->execute([$leadId, "Proforma quote sent to sales team for {$leadName}.", $uid]);
    } catch (\Throwable $_) {}
    logActivity('send_proforma', 'crm_leads', $leadId, "Proforma sent for lead: {$leadName}");
    setFlash('success', 'Sales team has been notified.');
    redirect(BASE_URL . '/modules/crm/proforma.php?lead_id=' . $leadId);
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

// Load pinned car
$car = null;
if (!empty($lead['pinned_car_id'])) {
    try {
        $s = $db->prepare("SELECT * FROM cars WHERE id = ?");
        $s->execute([(int)$lead['pinned_car_id']]);
        $car = $s->fetch() ?: null;
    } catch (\Throwable $_) {}
}

// Load agent (include phone for Contact line)
$agentUser = null;
if (!empty($lead['assigned_to'])) {
    try {
        $s = $db->prepare("SELECT name, email, phone FROM users WHERE id = ?");
        $s->execute([(int)$lead['assigned_to']]);
        $agentUser = $s->fetch() ?: null;
    } catch (\Throwable $_) {}
}

// Company settings
$companyPhone = getSetting('company_phone', '254 722 200018');
$companyEmail = getSetting('company_email', 'mascardiventures@gmail.com');

// Customer info
$customerName = trim($client['name']      ?? $lead['name']  ?? '');
$customerIdNo = trim($client['id_number'] ?? '');

// Purchase price: agreed_sale_price → offer_price → asking_price
$price = 0;
if ($car) {
    $agreedPrice = (float)($lead['agreed_sale_price'] ?? 0);
    $offerPrice  = (float)($car['offer_price']  ?? 0);
    $askingPrice = (float)($car['asking_price'] ?? 0);
    $price = $agreedPrice > 0 ? $agreedPrice : ($offerPrice > 0 ? $offerPrice : $askingPrice);
}

// Document meta
$today       = date('d/m/y');
$proformaNum = date('y/m/') . str_pad($leadId, 2, '0', STR_PAD_LEFT);

// Vehicle description: "TO SUPPLY 1 MAKE MODEL [notes]"
$carMakeModel = $car ? trim(($car['make'] ?? '') . ' ' . ($car['model'] ?? '')) : trim($lead['interested_in'] ?? '');
$carNotes     = $car ? trim($car['notes'] ?? '') : '';
$carFullDesc  = 'TO SUPPLY 1 ' . strtoupper($carMakeModel) . ($carNotes ? ' ' . $carNotes : '');

// Spec line: "1990cc, Petrol, Automatic Transmission"
$specParts = [];
if ($car && !empty($car['engine_cc']))    $specParts[] = $car['engine_cc'] . 'cc';
if ($car && !empty($car['fuel_type']))    $specParts[] = ucfirst($car['fuel_type']);
if ($car && !empty($car['transmission'])) $specParts[] = ucfirst($car['transmission']) . ' Transmission';
$carSpecLine = implode(', ', $specParts);

$pageTitle = 'Proforma Invoice — ' . ($customerName ?: 'Lead #' . $leadId);

include __DIR__ . '/../../includes/header.php';
?>
<style>
@page { size: A4; margin: 0; }
/* ── Print: force exactly one A4 page ───────────────────────────────────── */
@media print {
    /* Neutralize dark-mode's color-scheme:dark on <html>, which otherwise
       paints the print canvas with a dark UA fill — the "black margin"
       that shows around the page when printing from dark mode. */
    html { background:#fff !important; color-scheme:light !important; }
    .d-print-none { display:none !important; }
    .app-sidebar,.topbar,.sidebar-overlay,.app-topbar,
    header.app-topbar,#sidebarBackdrop,.fab-wa,.fab-chat,
    #pwaOverlay,#toastStack { display:none !important; }
    .main-wrap,.main-content,.page-body { margin:0 !important; padding:0 !important; }
    body { background:#fff !important; margin:0 !important; }

    #proformaDoc {
        max-width: none !important;
        width: 210mm !important;
        height: 297mm !important;
        margin: 0 !important;
        padding: 1cm !important;
        box-shadow: none !important;
        border: none !important;
        border-radius: 0 !important;
        overflow: hidden;
    }
    #pf-desc {
        flex: 1 !important;
        min-height: 0 !important;
        overflow: hidden;
    }
    .pf-spacer { min-height: 0 !important; }
}

/* ── Design tokens ────────────────────────────────────────────────────────── */
#proformaDoc {
    --ink: #0f172a; --ink-2: #475569; --ink-3: #94a3b8;
    --line: #dbe2ea; --surface: #f8fafc; --accent: #b45309;
}
/* ── Screen base ─────────────────────────────────────────────────────────── */
#proformaDoc {
    max-width: 760px;
    margin: 0 auto;
    background: #fff;
    font-family: 'Helvetica Neue', Arial, Helvetica, sans-serif;
    font-size: 12.5px;
    color: var(--ink);
    line-height: 1.4;
    box-shadow: 0 10px 40px rgba(15,23,42,.08);
    border: 1px solid var(--line);
    border-radius: 10px;
    overflow: hidden;
    /* Flex column — lets #pf-desc grow to fill A4 on print */
    display: flex;
    flex-direction: column;
}

/* ── Top header table ────────────────────────────────────────────────────── */
#pf-top { flex-shrink: 0; border-collapse: collapse; width: 100%; }
#pf-top td, #pf-top th { border: 1px solid var(--line); padding: 5px 9px; vertical-align: top; }

/* ── Description flex section ────────────────────────────────────────────── */
#pf-desc {
    flex: 1;
    display: flex;
    flex-direction: column;
    border: 1px solid var(--line);
    border-top: none;        /* top table's bottom border serves as the top */
    min-height: 320px;       /* screen minimum so it looks reasonable */
}

/* Shared row layout */
.pf-row {
    display: flex;
    flex-shrink: 0;
    border-bottom: 1px solid var(--line);
}
.pf-col-d {            /* Description column */
    flex: 1;
    padding: 5px 12px;
    border-right: 1px solid var(--line);
    font-size: 12.5px;
    color: var(--ink);
}
.pf-col-a {            /* Amount column */
    width: 130px;
    flex-shrink: 0;
    padding: 5px 12px;
    font-size: 12.5px;
    text-align: right;
    color: var(--ink);
}
/* Elastic spacer — absorbs remaining height, keeping TOTAL pinned to bottom */
.pf-spacer {
    flex: 1;
    display: flex;
    min-height: 50px;   /* screen: at least visible */
}
.pf-spacer-d { flex: 1; border-right: 1px solid var(--line); }
.pf-spacer-a { width: 130px; flex-shrink: 0; }
</style>

<!-- ── Action bar (screen only) ──────────────────────────────────────────── -->
<div class="d-print-none mb-4 d-flex align-items-center justify-content-between flex-wrap gap-2">
    <div class="d-flex align-items-center gap-2">
        <a href="view_lead.php?id=<?= $leadId ?>" class="btn btn-outline-secondary btn-sm">
            <i class="fa fa-arrow-left me-1"></i>Back to Lead
        </a>
        <span class="text-muted" style="font-size:12.5px">/ <?= e($lead['name']) ?></span>
    </div>
    <div class="d-flex gap-2 align-items-center flex-wrap">
        <span class="badge bg-light text-dark border" style="font-size:12px">
            Ref: <?= e($proformaNum) ?>
        </span>
        <form method="POST" class="d-inline">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="send_notification">
            <button type="submit" class="btn btn-outline-success btn-sm"
                    onclick="return confirm('Notify the sales team about this proforma?')">
                <i class="fa fa-bell me-1"></i>Notify Sales Team
            </button>
        </form>
        <a href="sales_agreement.php?lead_id=<?= $leadId ?>" class="btn btn-outline-primary btn-sm">
            <i class="fa fa-file-contract me-1"></i>Sales Agreement
        </a>
        <button class="btn btn-success btn-sm" onclick="window.print()">
            <i class="fa fa-print me-1"></i>Print / Save PDF
        </button>
    </div>
</div>

<div class="d-print-none alert alert-light border small mb-4" style="font-size:12px">
    <i class="fa fa-circle-info me-1 text-muted"></i>
    For a clean printout with no browser date/title line, open <strong>More settings</strong> in the print
    dialog and untick <strong>Headers and footers</strong>.
</div>

<!-- ═══════════════════════════════════════════════════════════════════════
     PRINTABLE PROFORMA INVOICE
════════════════════════════════════════════════════════════════════════ -->
<div id="proformaDoc">

    <!-- ══ TOP: MASCARDI wordmark (left) + client info & meta (right) ═════════ -->
    <table id="pf-top">
        <tr>
            <!-- LEFT: wordmark + company contact -->
            <td style="width:42%;padding:16px 18px;vertical-align:top;border-bottom:3px solid var(--ink)">
                <div style="font-size:22px;font-weight:800;letter-spacing:3px;color:var(--ink)">MASCARDI</div>
                <div style="font-size:9px;letter-spacing:.22em;color:var(--ink-3);
                            text-transform:uppercase;margin:2px 0 10px">Ventures Limited</div>
                <div style="font-size:11px;color:var(--ink-2);line-height:1.8">
                    P O Box 1391, Nairobi 00606<br>
                    Tel: <?= e($companyPhone) ?><br>
                    Email: <?= e($companyEmail) ?>
                </div>
            </td>

            <!-- RIGHT: document title + client + meta -->
            <td style="width:58%;padding:0;border-left:1px solid var(--line);border-bottom:3px solid var(--ink)">
                <div style="padding:10px 14px;display:flex;justify-content:space-between;align-items:center;border-bottom:1px solid var(--line)">
                    <span style="font-size:11px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:var(--ink)">Proforma Invoice</span>
                    <span style="font-size:11px;color:var(--ink-2)">Ref: <?= e($proformaNum) ?></span>
                </div>
                <div style="padding:8px 14px">
                    <strong style="font-size:12.5px;color:var(--ink)">Client: <?= e($customerName) ?></strong><br>
                    <span style="font-size:11.5px;color:var(--ink-2)">I.D No: <?= e($customerIdNo ?: '&nbsp;') ?></span><br>
                    <span style="font-size:11.5px;color:var(--ink-2)">P.O Box: _____, Nairobi</span>
                </div>

                <!-- Invoice meta -->
                <table style="border-collapse:collapse;width:100%;margin:0">
                    <tr>
                        <td style="border:1px solid var(--line);padding:4px 10px;font-weight:600;white-space:nowrap;font-size:11px;color:var(--ink-2)">
                            Vehicle Make
                        </td>
                        <td style="border:1px solid var(--line);padding:4px 10px;font-size:12px;color:var(--ink)"><?= e($carMakeModel ?: '—') ?></td>
                    </tr>
                    <tr>
                        <td style="border:1px solid var(--line);padding:4px 10px;font-weight:600;font-size:11px;color:var(--ink-2)">Registration No</td>
                        <td style="border:1px solid var(--line);padding:4px 10px;font-size:12px;color:var(--ink)">
                            <?= $car ? e($car['registration_number'] ?: 'New') : '—' ?>
                        </td>
                    </tr>
                    <tr>
                        <td style="border:1px solid var(--line);padding:4px 10px;font-weight:600;font-size:11px;color:var(--ink-2)">Date</td>
                        <td style="border:1px solid var(--line);padding:4px 10px;font-size:12px;color:var(--ink)"><?= e($today) ?></td>
                    </tr>
                    <tr>
                        <td style="border:1px solid var(--line);padding:4px 10px;font-weight:600;font-size:11px;color:var(--ink-2)">Order Number</td>
                        <td style="border:1px solid var(--line);padding:4px 10px;font-size:12px;color:var(--ink)"><?= $leadId ?></td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>

    <!-- ══ DESCRIPTION: flex column fills remaining page height ═══════════════ -->
    <div id="pf-desc">

        <!-- Column headers -->
        <div class="pf-row" style="background:var(--surface)">
            <div class="pf-col-d" style="font-weight:700;text-align:center;text-transform:uppercase;letter-spacing:.08em;font-size:10.5px;color:var(--ink-2)">Description</div>
            <div class="pf-col-a" style="font-weight:700;text-align:center;white-space:nowrap;text-transform:uppercase;letter-spacing:.08em;font-size:10.5px;color:var(--ink-2)">
                Kenya Shillings
            </div>
        </div>

        <!-- Two short gap rows before main description -->
        <div class="pf-row" style="min-height:14px">
            <div class="pf-col-d"></div><div class="pf-col-a"></div>
        </div>
        <div class="pf-row" style="min-height:14px">
            <div class="pf-col-d"></div><div class="pf-col-a"></div>
        </div>

        <!-- Vehicle description line -->
        <div class="pf-row">
            <div class="pf-col-d" style="padding-left:28px;font-weight:bold">
                <?= e($carFullDesc) ?>
            </div>
            <div class="pf-col-a" style="font-weight:bold">
                <?= $price > 0 ? number_format((int)$price, 0) . '/-' : '' ?>
            </div>
        </div>

        <!-- Spec line (cc / fuel / transmission) -->
        <?php if ($carSpecLine): ?>
        <div class="pf-row">
            <div class="pf-col-d" style="padding-left:28px;font-weight:bold"><?= e($carSpecLine) ?></div>
            <div class="pf-col-a"></div>
        </div>
        <?php endif; ?>

        <!-- Small gap before vehicle specifics -->
        <div class="pf-row" style="min-height:14px">
            <div class="pf-col-d"></div><div class="pf-col-a"></div>
        </div>

        <!-- Vehicle specifics -->
        <?php if ($car): ?>
        <div class="pf-row">
            <div class="pf-col-d" style="padding-left:28px;font-weight:bold">
                ENGINE NUMBER: <?= e($car['engine_number'] ?? 'TBC') ?>
            </div>
            <div class="pf-col-a"></div>
        </div>
        <div class="pf-row">
            <div class="pf-col-d" style="padding-left:28px;font-weight:bold">
                CHASSIS NUMBER: <?= e($car['chassis_number'] ?? '—') ?>
            </div>
            <div class="pf-col-a"></div>
        </div>
        <div class="pf-row">
            <div class="pf-col-d" style="padding-left:28px;font-weight:bold">
                COLOR: <?= e(strtoupper($car['color'] ?? '—')) ?>
            </div>
            <div class="pf-col-a"></div>
        </div>
        <div class="pf-row">
            <div class="pf-col-d" style="padding-left:28px;font-weight:bold">
                REGISTRATION: <?= e($car['registration_number'] ?: 'New') ?>
            </div>
            <div class="pf-col-a"></div>
        </div>
        <div class="pf-row">
            <div class="pf-col-d" style="padding-left:28px;font-weight:bold">
                YEAR: <?= e($car['year'] ?? '—') ?>
            </div>
            <div class="pf-col-a"></div>
        </div>
        <?php endif; ?>

        <!-- ── ELASTIC SPACER 1 — fills gap before payment terms ────────────── -->
        <div class="pf-spacer">
            <div class="pf-spacer-d"></div>
            <div class="pf-spacer-a"></div>
        </div>

        <!-- Payment terms -->
        <div class="pf-row">
            <div class="pf-col-d" style="padding-left:28px;font-weight:bold">PAYMENT TERMS</div>
            <div class="pf-col-a"></div>
        </div>
        <div class="pf-row">
            <div class="pf-col-d" style="padding-left:28px;font-weight:bold">Deposit 20%</div>
            <div class="pf-col-a"></div>
        </div>
        <div class="pf-row">
            <div class="pf-col-d" style="padding-left:28px;font-weight:bold">
                Balance to be paid before delivery
            </div>
            <div class="pf-col-a"></div>
        </div>

        <!-- ── ELASTIC SPACER 2 — fills gap before sales person ─────────────── -->
        <div class="pf-spacer">
            <div class="pf-spacer-d"></div>
            <div class="pf-spacer-a"></div>
        </div>

        <!-- Sales person -->
        <div class="pf-row">
            <div class="pf-col-d" style="padding-left:28px;font-weight:bold">
                Sales person-<?= e($agentUser['name'] ?? $me['name']) ?>
            </div>
            <div class="pf-col-a"></div>
        </div>
        <div class="pf-row">
            <div class="pf-col-d" style="padding-left:28px;font-weight:bold">
                Contact-<?= e($agentUser['phone'] ?? $me['phone'] ?? '') ?>
            </div>
            <div class="pf-col-a"></div>
        </div>

        <!-- TOTAL row — pinned at the bottom of #pf-desc -->
        <div style="display:flex;flex-shrink:0;border-top:2px solid var(--ink);background:var(--surface)">
            <div style="flex:1;padding:8px 12px;font-weight:700;text-align:right;
                        border-right:1px solid var(--line);text-transform:uppercase;letter-spacing:.06em;font-size:11px;color:var(--ink-2)">Total</div>
            <div style="width:130px;flex-shrink:0;padding:8px 12px;font-weight:800;text-align:right;font-size:13.5px;color:var(--ink)">
                <?= $price > 0 ? number_format((int)$price, 0) . '/-' : '' ?>
            </div>
        </div>

    </div><!-- /#pf-desc -->

    <!-- ══ FOOTER NOTE ═════════════════════════════════════════════════════════ -->
    <div id="pf-footer"
         style="flex-shrink:0;padding:9px 14px;font-weight:600;font-size:11.5px;color:#fff;background:var(--accent)">
        The vehicle belongs to Mascardi Ventures Limited until payment is received in full.
    </div>

</div><!-- /#proformaDoc -->

<div class="d-print-none mt-4 mb-4"></div>

<script>
// Browsers print their own date/title header line using document.title when
// "Headers and footers" is left on — shorten it to just the doc name so that
// fallback line reads clean instead of the full page title.
(function () {
    var original = document.title;
    var printTitle = 'Proforma Invoice — ' + <?= json_encode($proformaNum) ?>;
    window.addEventListener('beforeprint', function () { document.title = printTitle; });
    window.addEventListener('afterprint', function () { document.title = original; });
}());
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>

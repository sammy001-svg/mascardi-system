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
        overflow: hidden;
    }
    #pf-desc {
        flex: 1 !important;
        min-height: 0 !important;
        overflow: hidden;
    }
    .pf-spacer { min-height: 0 !important; }
}

/* ── Screen base ─────────────────────────────────────────────────────────── */
#proformaDoc {
    max-width: 760px;
    margin: 0 auto;
    background: #fff;
    font-family: Arial, Helvetica, sans-serif;
    font-size: 12.5px;
    color: #000;
    line-height: 1.4;
    box-shadow: 0 4px 20px rgba(0,0,0,.1);
    border: 1px solid #999;
    /* Flex column — lets #pf-desc grow to fill A4 on print */
    display: flex;
    flex-direction: column;
}

/* ── Top header table ────────────────────────────────────────────────────── */
#pf-top { flex-shrink: 0; border-collapse: collapse; width: 100%; }
#pf-top td, #pf-top th { border: 1px solid #000; padding: 4px 8px; vertical-align: top; }

/* ── Description flex section ────────────────────────────────────────────── */
#pf-desc {
    flex: 1;
    display: flex;
    flex-direction: column;
    border: 1px solid #000;
    border-top: none;        /* top table's bottom border serves as the top */
    min-height: 320px;       /* screen minimum so it looks reasonable */
}

/* Shared row layout */
.pf-row {
    display: flex;
    flex-shrink: 0;
    border-bottom: 1px solid #000;
}
.pf-col-d {            /* Description column */
    flex: 1;
    padding: 4px 10px;
    border-right: 1px solid #000;
    font-size: 12.5px;
}
.pf-col-a {            /* Amount column */
    width: 130px;
    flex-shrink: 0;
    padding: 4px 10px;
    font-size: 12.5px;
    text-align: right;
}
/* Elastic spacer — absorbs remaining height, keeping TOTAL pinned to bottom */
.pf-spacer {
    flex: 1;
    display: flex;
    min-height: 50px;   /* screen: at least visible */
}
.pf-spacer-d { flex: 1; border-right: 1px solid #000; }
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

<!-- ═══════════════════════════════════════════════════════════════════════
     PRINTABLE PROFORMA INVOICE
════════════════════════════════════════════════════════════════════════ -->
<div id="proformaDoc">

    <!-- ══ TOP: client info (left) + MASCARDI branding (right) ════════════════ -->
    <table id="pf-top">
        <tr>
            <!-- LEFT: client box + invoice meta -->
            <td style="width:42%;padding:0;vertical-align:top">

                <!-- Client info box -->
                <div style="border-bottom:1px solid #000;padding:8px 10px">
                    <strong>Client Name: <?= e($customerName) ?></strong><br>
                    I.D No: <?= e($customerIdNo ?: '&nbsp;') ?><br>
                    P.O Box: _____, Nairobi
                </div>

                <!-- Invoice meta table (shares cell-border grid) -->
                <table style="border-collapse:collapse;width:100%;margin:0">
                    <tr>
                        <td style="border:1px solid #000;padding:4px 8px;font-weight:bold;white-space:nowrap">
                            Proforma Invoice:
                        </td>
                        <td style="border:1px solid #000;padding:4px 8px"><?= e($proformaNum) ?></td>
                    </tr>
                    <tr>
                        <td style="border:1px solid #000;padding:4px 8px;font-weight:bold">Vehicle Make</td>
                        <td style="border:1px solid #000;padding:4px 8px"><?= e($carMakeModel ?: '—') ?></td>
                    </tr>
                    <tr>
                        <td style="border:1px solid #000;padding:4px 8px;font-weight:bold">Registration No</td>
                        <td style="border:1px solid #000;padding:4px 8px">
                            <?= $car ? e($car['registration_number'] ?: 'New') : '—' ?>
                        </td>
                    </tr>
                    <tr>
                        <td style="border:1px solid #000;padding:4px 8px;font-weight:bold">Date:</td>
                        <td style="border:1px solid #000;padding:4px 8px"><?= e($today) ?></td>
                    </tr>
                    <tr>
                        <td style="border:1px solid #000;padding:4px 8px;font-weight:bold">Order Number:</td>
                        <td style="border:1px solid #000;padding:4px 8px"><?= $leadId ?></td>
                    </tr>
                </table>
            </td>

            <!-- RIGHT: large italic serif company name + contact -->
            <td style="width:58%;padding:10px 16px;border-left:1px solid #000">
                <div style="font-family:'Times New Roman',Times,Georgia,serif;
                            font-style:italic;font-size:50px;font-weight:normal;
                            line-height:1.05;color:#000;letter-spacing:-1px">
                    MASCARDI<br>
                    VENTURES<br>
                    LIMITED
                </div>
                <div style="text-align:right;font-size:11.5px;margin-top:6px;line-height:1.7;color:#000">
                    P O Box 1391<br>
                    Nairobi<br>
                    00606<br>
                    Tel: <?= e($companyPhone) ?><br>
                    Email:<?= e($companyEmail) ?>
                </div>
            </td>
        </tr>
    </table>

    <!-- ══ DESCRIPTION: flex column fills remaining page height ═══════════════ -->
    <div id="pf-desc">

        <!-- Column headers -->
        <div class="pf-row">
            <div class="pf-col-d" style="font-weight:bold;text-align:center">Description</div>
            <div class="pf-col-a" style="font-weight:bold;text-align:center;white-space:nowrap">
                KENYA SHILLINGS
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
        <div style="display:flex;flex-shrink:0;border-top:1px solid #000">
            <div style="flex:1;padding:6px 10px;font-weight:bold;text-align:right;
                        border-right:1px solid #000">TOTAL</div>
            <div style="width:130px;flex-shrink:0;padding:6px 10px;font-weight:bold;text-align:right">
                <?= $price > 0 ? number_format((int)$price, 0) . '/-' : '' ?>
            </div>
        </div>

    </div><!-- /#pf-desc -->

    <!-- ══ FOOTER NOTE ═════════════════════════════════════════════════════════ -->
    <div id="pf-footer"
         style="flex-shrink:0;padding:7px 10px;font-weight:bold;font-size:12px;
                border:1px solid #000;border-top:none">
        The vehicle belongs to Mascardi Ventures Limited until payment is received in full.
    </div>

</div><!-- /#proformaDoc -->

<div class="d-print-none mt-4 mb-4"></div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>

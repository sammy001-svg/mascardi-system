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

// Vehicle description line: "TO SUPPLY 1 MAKE MODEL [notes]"
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
/* ── Print suppression ───────────────────────────────────────────────────── */
@media print {
    .d-print-none { display:none !important; }
    .app-sidebar,.topbar,.sidebar-overlay,.app-topbar,
    header.app-topbar,#sidebarBackdrop,.fab-wa,.fab-chat,
    #pwaOverlay,#toastStack { display:none !important; }
    .main-wrap,.main-content,.page-body { margin:0 !important; padding:0 !important; }
    body { background:#fff !important; }
    #proformaDoc { box-shadow:none !important; border:none !important; }
    @page { margin:1cm; size:A4; }
}
/* ── Document shell ──────────────────────────────────────────────────────── */
#proformaDoc {
    max-width:760px; margin:0 auto;
    background:#fff; border:1px solid #999;
    font-family:Arial,Helvetica,sans-serif;
    font-size:12.5px; color:#000; line-height:1.4;
    box-shadow:0 4px 20px rgba(0,0,0,.1);
}
/* ── All table cells with grid-line borders ──────────────────────────────── */
#proformaDoc table { border-collapse:collapse; width:100%; }
#proformaDoc td, #proformaDoc th {
    border:1px solid #000; padding:4px 8px; vertical-align:top;
}
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

    <!-- ══ TOP SECTION: client info (left) + company branding (right) ══════════ -->
    <table>
        <tr>
            <!-- LEFT: client box + invoice meta -->
            <td style="width:42%;border:1px solid #000;padding:0">

                <!-- Client info -->
                <div style="border-bottom:1px solid #000;padding:8px 10px">
                    <strong>Client Name: <?= e($customerName) ?></strong><br>
                    I.D No: <?= e($customerIdNo ?: '&nbsp;') ?><br>
                    P.O Box: _____, Nairobi
                </div>

                <!-- Invoice meta rows (no outer wrapper — cells form the grid) -->
                <table style="margin:0">
                    <tr>
                        <td style="font-weight:bold;white-space:nowrap">Proforma Invoice:</td>
                        <td><?= e($proformaNum) ?></td>
                    </tr>
                    <tr>
                        <td style="font-weight:bold">Vehicle Make</td>
                        <td><?= e($carMakeModel ?: '—') ?></td>
                    </tr>
                    <tr>
                        <td style="font-weight:bold">Registration No</td>
                        <td><?= $car ? e($car['registration_number'] ?: 'New') : '—' ?></td>
                    </tr>
                    <tr>
                        <td style="font-weight:bold">Date:</td>
                        <td><?= e($today) ?></td>
                    </tr>
                    <tr>
                        <td style="font-weight:bold">Order Number:</td>
                        <td><?= $leadId ?></td>
                    </tr>
                </table>

            </td>

            <!-- RIGHT: MASCARDI VENTURES LIMITED (large italic serif) + contact -->
            <td style="width:58%;border:1px solid #000;padding:10px 16px">
                <div style="font-family:'Times New Roman',Times,Georgia,serif;
                            font-style:italic; font-size:50px; font-weight:normal;
                            line-height:1.05; color:#000; letter-spacing:-1px">
                    MASCARDI<br>
                    VENTURES<br>
                    LIMITED
                </div>
                <div style="text-align:right; font-size:11.5px; margin-top:6px;
                            line-height:1.7; color:#000">
                    P O Box 1391<br>
                    Nairobi<br>
                    00606<br>
                    Tel: <?= e($companyPhone) ?><br>
                    Email:<?= e($companyEmail) ?>
                </div>
            </td>
        </tr>
    </table>

    <!-- ══ DESCRIPTION TABLE ═══════════════════════════════════════════════════ -->
    <table>
        <!-- Column header row -->
        <tr>
            <th style="text-align:center;font-weight:bold;padding:7px 10px">Description</th>
            <th style="text-align:center;font-weight:bold;padding:7px 10px;width:130px;
                       white-space:nowrap">KENYA SHILLINGS</th>
        </tr>

        <!-- Spacer rows -->
        <tr>
            <td style="padding:6px 10px">&nbsp;</td>
            <td>&nbsp;</td>
        </tr>
        <tr>
            <td style="padding:6px 10px">&nbsp;</td>
            <td>&nbsp;</td>
        </tr>

        <!-- Vehicle description line -->
        <tr>
            <td style="padding:4px 10px 4px 28px;font-weight:bold">
                <?= e($carFullDesc) ?>
            </td>
            <td style="text-align:right;font-weight:bold;padding:4px 10px">
                <?= $price > 0 ? number_format((int)$price, 0) . '/-' : '' ?>
            </td>
        </tr>

        <!-- Spec line (cc, fuel, transmission) -->
        <?php if ($carSpecLine): ?>
        <tr>
            <td style="padding:2px 10px 4px 28px;font-weight:bold"><?= e($carSpecLine) ?></td>
            <td>&nbsp;</td>
        </tr>
        <?php endif; ?>

        <!-- Gap before vehicle specifics -->
        <tr><td style="padding:6px 10px">&nbsp;</td><td>&nbsp;</td></tr>

        <!-- Vehicle specifics -->
        <?php if ($car): ?>
        <tr>
            <td style="padding:2px 10px 2px 28px;font-weight:bold">
                ENGINE NUMBER: <?= e($car['engine_number'] ?? 'TBC') ?>
            </td>
            <td>&nbsp;</td>
        </tr>
        <tr>
            <td style="padding:2px 10px 2px 28px;font-weight:bold">
                CHASSIS NUMBER: <?= e($car['chassis_number'] ?? '—') ?>
            </td>
            <td>&nbsp;</td>
        </tr>
        <tr>
            <td style="padding:2px 10px 2px 28px;font-weight:bold">
                COLOR: <?= e(strtoupper($car['color'] ?? '—')) ?>
            </td>
            <td>&nbsp;</td>
        </tr>
        <tr>
            <td style="padding:2px 10px 2px 28px;font-weight:bold">
                REGISTRATION: <?= e($car['registration_number'] ?: 'New') ?>
            </td>
            <td>&nbsp;</td>
        </tr>
        <tr>
            <td style="padding:2px 10px 4px 28px;font-weight:bold">
                YEAR: <?= e($car['year'] ?? '—') ?>
            </td>
            <td>&nbsp;</td>
        </tr>
        <?php endif; ?>

        <!-- Spacer rows before payment terms -->
        <?php for ($i = 0; $i < 6; $i++): ?>
        <tr><td style="padding:8px 10px">&nbsp;</td><td>&nbsp;</td></tr>
        <?php endfor; ?>

        <!-- Payment terms -->
        <tr>
            <td style="padding:2px 10px 2px 28px;font-weight:bold">PAYMENT TERMS</td>
            <td>&nbsp;</td>
        </tr>
        <tr>
            <td style="padding:2px 10px 2px 28px;font-weight:bold">Deposit 20%</td>
            <td>&nbsp;</td>
        </tr>
        <tr>
            <td style="padding:2px 10px 4px 28px;font-weight:bold">Balance to be paid before delivery</td>
            <td>&nbsp;</td>
        </tr>

        <!-- Spacer rows before sales person -->
        <?php for ($i = 0; $i < 8; $i++): ?>
        <tr><td style="padding:8px 10px">&nbsp;</td><td>&nbsp;</td></tr>
        <?php endfor; ?>

        <!-- Sales person -->
        <tr>
            <td style="padding:2px 10px 2px 28px;font-weight:bold">
                Sales person-<?= e($agentUser['name'] ?? $me['name']) ?>
            </td>
            <td>&nbsp;</td>
        </tr>
        <tr>
            <td style="padding:2px 10px 6px 28px;font-weight:bold">
                Contact-<?= e($agentUser['phone'] ?? $me['phone'] ?? '') ?>
            </td>
            <td>&nbsp;</td>
        </tr>

        <!-- TOTAL row -->
        <tr>
            <td style="text-align:right;font-weight:bold;padding:6px 10px">TOTAL</td>
            <td style="text-align:right;font-weight:bold;padding:6px 10px">
                <?= $price > 0 ? number_format((int)$price, 0) . '/-' : '' ?>
            </td>
        </tr>
    </table>

    <!-- ══ FOOTER NOTE ══════════════════════════════════════════════════════════ -->
    <div style="padding:8px 10px;font-weight:bold;font-size:12px;border-top:1px solid #000">
        The vehicle belongs to Mascardi Ventures Limited until payment is received in full.
    </div>

</div><!-- /#proformaDoc -->

<div class="d-print-none mt-4 mb-4"></div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>

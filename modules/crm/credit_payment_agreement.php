<?php
/**
 * Credit Payment Agreement.
 *
 * The wording below is reproduced verbatim from the signed template supplied by
 * the company. It is a contract, so nothing here paraphrases, tightens or
 * "improves" the language — only the figures, dates and party details are
 * substituted. If a clause needs to change, it changes on the legal side first.
 */
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/credit_bootstrap.php';
require_once __DIR__ . '/credit_clauses.php';
requireLogin();
canAccess('crm') || redirect(BASE_URL . '/index.php');

$db  = getDB();
$me  = authUser();
$uid = (int)$me['id'];
creditMigrate($db);

$leadId = (int)($_GET['lead_id'] ?? 0);
if (!$leadId) { setFlash('error', 'No lead specified.'); redirect(BASE_URL . '/modules/crm/leads.php'); }

$stLead = $db->prepare("SELECT * FROM crm_leads WHERE id = ?");
$stLead->execute([$leadId]);
$lead = $stLead->fetch(PDO::FETCH_ASSOC);
if (!$lead) { setFlash('error', 'Lead not found.'); redirect(BASE_URL . '/modules/crm/leads.php'); }

if ($me['role'] === 'customer_relations' && (int)$lead['assigned_to'] !== $uid) {
    setFlash('error', 'You can only view leads assigned to you.');
    redirect(BASE_URL . '/modules/crm/my_dashboard.php');
}

$agreement = creditForLead($db, $leadId);
if (!$agreement) {
    setFlash('error', 'No credit agreement has been set up for this lead yet.');
    redirect(BASE_URL . '/modules/crm/view_lead.php?id=' . $leadId);
}

$car = null;
if (!empty($lead['pinned_car_id'])) {
    $s = $db->prepare("SELECT * FROM cars WHERE id = ?");
    $s->execute([(int)$lead['pinned_car_id']]);
    $car = $s->fetch(PDO::FETCH_ASSOC) ?: null;
}
$client = null;
if (!empty($lead['client_id'])) {
    $s = $db->prepare("SELECT * FROM clients WHERE id = ?");
    $s->execute([(int)$lead['client_id']]);
    $client = $s->fetch(PDO::FETCH_ASSOC) ?: null;
}

$installments = creditInstallments($db, (int)$agreement['id']);

// ── Which of the three agreements is being produced ──────────────────────────
// The two early-payment variants are the base document plus their own clauses
// inserted after clause 3(d). An early-payment variant with no wording supplied
// yet is refused outright further down rather than silently printing the base
// document under an early-payment heading — that would be a contract saying
// something other than what was agreed.
[$variantKey, $variant] = creditVariant($_GET['variant'] ?? null);
$variantReady   = creditVariantReady($variantKey);
$variantClauses = $variant['clauses'];

// ── Party details ────────────────────────────────────────────────────────────
// Lead first, then the client record — the details are captured on the lead
// while the deal is being done.
$debtorName  = trim($lead['name'] ?? '') ?: trim($client['name'] ?? '');
$debtorId    = trim($lead['id_number'] ?? '') ?: trim($client['id_number'] ?? '');
$debtorBox   = trim($lead['po_box'] ?? '')  ?: trim($client['po_box'] ?? '');
$debtorEmail = trim($lead['email'] ?? '')   ?: trim($client['email'] ?? '');
$debtorPhone = trim($lead['phone'] ?? '')   ?: trim($client['phone'] ?? '');

$principal    = (float)$agreement['principal'];
$principalWords = creditWords($principal);

$vehMakeModel = $car ? trim(($car['make'] ?? '') . ' ' . ($car['model'] ?? '')) : '';
$vehYear      = $car['year'] ?? '';
$vehReg       = $car ? ($car['registration_number'] ?: 'New') : '';
$vehChassis   = $car['chassis_number'] ?? '';
$vehEngine    = $car['engine_number'] ?? '';
$vehRating    = $car && !empty($car['engine_cc']) ? ((int)$car['engine_cc'] . ' cc') : '';

// An early-payment version whose wording has not been supplied yet stops here
// with an explanation. Deliberately a page rather than a redirect: these open in
// a new tab, so redirecting would just show a second copy of the lead and leave
// the user guessing why the document never appeared.
if (!$variantReady) {
    $pageTitle = $variant['label'];
    include __DIR__ . '/../../includes/header.php';
    ?>
    <div class="container" style="max-width:640px">
        <div class="card mt-4 mb-4" style="border-color:#fcd34d;border-width:2px">
            <div class="card-header fw-semibold"
                 style="background:linear-gradient(135deg,#fffbeb,#fef3c7);border-bottom-color:#fde68a;color:#92400e">
                <i class="fa fa-triangle-exclamation me-2"></i><?= e($variant['label']) ?> — not ready yet
            </div>
            <div class="card-body">
                <p>This version of the Credit Payment Agreement cannot be generated, because the
                   <strong>clause wording for it has not been added to the system yet</strong>.</p>
                <p class="mb-3">Everything else is in place: it will produce the same agreement as the
                   standard one, with the <?= e($variant['label']) ?> clauses inserted after
                   clause&nbsp;3(d). Only the text of those clauses is missing, and it has to come from
                   the signed template rather than be written here.</p>
                <div class="alert alert-light border py-2 mb-3" style="font-size:13px">
                    <i class="fa fa-circle-info me-1"></i>
                    The <strong>standard Credit Agreement</strong> is unaffected and can be generated
                    as normal.
                </div>
                <a href="view_lead.php?id=<?= $leadId ?>#credit" class="btn btn-sm btn-outline-secondary">
                    <i class="fa fa-arrow-left me-1"></i>Back to Lead
                </a>
                <a href="credit_payment_agreement.php?lead_id=<?= $leadId ?>"
                   class="btn btn-sm" style="background:#7e22ce;color:#fff">
                    <i class="fa fa-file-signature me-1"></i>Generate standard Credit Agreement
                </a>
            </div>
        </div>
    </div>
    <?php
    include __DIR__ . '/../../includes/footer.php';
    exit;
}

$docName   = $variantKey === 'standard'
           ? 'Credit Payment Agreement'
           : 'Credit Payment Agreement (' . $variant['label'] . ')';
$pageTitle = $docName . ' — ' . ($debtorName ?: 'Lead #' . $leadId);
include __DIR__ . '/../../includes/header.php';
?>
<style>
@page { size: A4; margin: 18mm 16mm; }
@media print {
    html { background:#fff !important; color-scheme:light !important; }
    .d-print-none { display:none !important; }
    .app-sidebar,.topbar,.sidebar-overlay,.app-topbar,header.app-topbar,
    #sidebarBackdrop,.fab-wa,.fab-chat,#pwaOverlay,#toastStack { display:none !important; }
    .main-wrap,.main-content,.page-body { margin:0 !important; padding:0 !important; }
    body { background:#fff !important; margin:0 !important; color:#000 !important; }
    #cpaDoc { max-width:none !important; box-shadow:none !important; border:none !important;
              padding:0 !important; margin:0 !important; background:#fff !important; color:#000 !important; }
    /* A clause must never be split across a page break mid-sentence. */
    #cpaDoc h2, #cpaDoc h3 { page-break-after: avoid; }
    #cpaDoc p, #cpaDoc li, #cpaDoc table { page-break-inside: avoid; }
    #cpaSign { page-break-inside: avoid; }
}
#cpaDoc{
    --ink:#000000; --ink-2:#000000; --line:#000000;
    max-width:820px; margin:0 auto; background:#fff !important; color:#000000 !important; color-scheme:light !important;
    padding:34px 40px; border:1px solid #e5e7eb; border-radius:10px;
    font-family:"Cambria","Times New Roman",Georgia,serif; font-size:13.5px; line-height:1.62;
}
#cpaDoc .band{ background:#111827 !important; color:#ffffff !important; padding:14px 20px; margin:-34px -40px 26px;
    border-radius:10px 10px 0 0; font-size:24px; font-weight:800; letter-spacing:5px; }
#cpaDoc h1{ font-size:15px; font-weight:700; text-align:center; margin:0 0 16px; letter-spacing:.4px; color:#000000 !important; }
#cpaDoc h2{ font-size:13.5px; font-weight:700; margin:18px 0 6px; color:#000000 !important; }
#cpaDoc h3{ font-size:13px; font-weight:700; margin:14px 0 4px; color:#000000 !important; }
#cpaDoc b, #cpaDoc strong{ color:#000000 !important; font-weight:700; }
#cpaDoc p{ margin:0 0 9px; color:#000000 !important; }
#cpaDoc .kv{ margin:0 0 3px; color:#000000 !important; }
#cpaDoc ul{ margin:4px 0 10px 20px; padding:0; color:#000000 !important; }
#cpaDoc li{ margin-bottom:5px; color:#000000 !important; }
#cpaDoc table.sched{ width:100%; border-collapse:collapse; margin:8px 0 14px; }
#cpaDoc table.sched td{ border:1px solid #000000 !important; padding:5px 10px; font-size:13px; color:#000000 !important; }
#cpaSign{ margin-top:34px; color:#000000 !important; }
#cpaSign .line{ margin:26px 0 2px; letter-spacing:1px; color:#000000 !important; }
</style>

<div class="d-print-none mb-3 d-flex align-items-center justify-content-between flex-wrap gap-2">
    <a href="view_lead.php?id=<?= $leadId ?>" class="btn btn-outline-secondary btn-sm">
        <i class="fa fa-arrow-left me-1"></i>Back to Lead
    </a>
    <?php if ($variantKey !== 'standard'): ?>
    <span class="badge" style="background:#7e22ce;font-size:12px;padding:7px 12px">
        <i class="fa <?= e($variant['icon']) ?> me-1"></i><?= e($variant['label']) ?>
    </span>
    <?php endif; ?>
    <button class="btn btn-success btn-sm" onclick="window.print()">
        <i class="fa fa-print me-1"></i>Print / Save PDF
    </button>
</div>

<div id="cpaDoc">
    <div class="band">MASCARDI</div>

    <h1>CREDIT PAYMENT AGREEMENT</h1>

    <p>This Credit Payment Agreement (“Credit Agreement”) is made and entered into on
       <?= e(creditOrdinalDay($agreement['agreement_date'])) ?> day of
       <?= e(date('F, Y', strtotime($agreement['agreement_date']))) ?> by and between:</p>

    <p><b>Creditor:</b></p>
    <div class="kv">Mascardi Ventures Limited</div>
    <div class="kv">Reg. No. PVT-ZQUXL55</div>
    <div class="kv">Address: 291 Kabete Lane, Spring Valley, P.O.Box 1391-00606, Nairobi, Kenya</div>
    <div class="kv" style="margin-bottom:9px">Email: Sales@mascardi.co</div>

    <p><b>Debtor:</b></p>
    <div class="kv"><b>Name</b>: <?= e($debtorName) ?></div>
    <div class="kv"><b>ID No.:</b> <?= e($debtorId) ?></div>
    <div class="kv"><b>P.o Box:</b> <?= e($debtorBox) ?></div>
    <div class="kv"><b>Email:</b> <?= e($debtorEmail) ?></div>
    <div class="kv" style="margin-bottom:12px"><b>Phone Number:</b> <?= e($debtorPhone) ?></div>

    <h2>1. Vehicle Details:</h2>
    <div class="kv"><b>Make &amp; Model:</b> <?= e($vehMakeModel) ?></div>
    <div class="kv"><b>Year</b>: <?= e((string)$vehYear) ?></div>
    <div class="kv"><b>Registration No</b>.: <?= e($vehReg) ?></div>
    <div class="kv"><b>Chassis No.:</b> <?= e($vehChassis) ?></div>
    <div class="kv"><b>Engine No.:</b> <?= e($vehEngine) ?></div>
    <div class="kv" style="margin-bottom:12px"><b>Rating</b>: <?= e($vehRating) ?></div>

    <h2>2. Credit Facility</h2>
    <p>The Creditor has extended a credit facility in the sum of
       <b>Ksh <?= number_format($principal, 0) ?>/- (Kenya Shillings <?= e($principalWords) ?> Only)</b>
       (“Principal Amount”) to the Debtor.</p>
    <p>The Debtor acknowledges receipt of the Principal Amount and agrees that the credit facility is
       subject to facilitation fees, administrative charges, and other costs associated with the
       provision of credit (collectively, the “Charges”).</p>

    <h2>2A. Total Repayment Obligation</h2>
    <p>The Debtor expressly agrees that the total repayment obligation under this Agreement shall be
       strictly as per the installment schedule set out below<b>,</b> and that each installment amount
       is fully inclusive of all Charges<b>,</b> whether disclosed separately or not.</p>

    <h2>2B. Payment Terms</h2>
    <p>The Debtor shall pay the Creditor in the following installments inclusive of all facilitation
       charges on or before the due dates below.:</p>
    <table class="sched">
        <?php foreach ($installments as $i): ?>
        <tr>
            <td style="width:34%">Installment <?= (int)$i['seq'] ?></td>
            <td style="width:33%"><?= e(date('d/m/Y', strtotime($i['due_date']))) ?></td>
            <td style="width:33%">Ksh <?= number_format((float)$i['amount'], 0) ?></td>
        </tr>
        <?php endforeach; ?>
    </table>

    <h2>2C. No Reduction / Recharacterization</h2>
    <p>The Debtor agrees that:</p>
    <ul>
        <li>The repayment obligation shall <b>not be reduced, recharacterized, or recalculated</b>
            by separating the Principal Amount from the Charges</li>
        <li>All payments made shall be applied at the Creditor’s discretion between Principal and Charges</li>
        <li>The Debtor waives any right to claim that any portion of the Charges is excessive,
            undisclosed, or unenforceable</li>
    </ul>

    <h2>3. Late Payment and Default Clause</h2>
    <p><b>a) Late Payment Fee and Accrued Interest:</b><br>
       In the event of a delayed installment, the Debtor shall incur a late payment fee of
       <?= e(creditPenaltyPhrase($agreement)) ?> per occurrence. Additionally, interest on the
       outstanding balance shall accrue at an annual rate of
       <?= e(rtrim(rtrim(number_format((float)$agreement['interest_rate'], 2), '0'), '.')) ?>%,
       calculated from the original due date until full payment is received.</p>

    <p><b>b) Reassessment of Credit Facility:</b><br>
       If the Debtor accumulates more than three (3) instances of delayed payments within the agreement
       term, the Creditor reserves the right, at its sole discretion, to reassess the Debtor’s
       creditworthiness. This reassessment may lead to the immediate termination of the installment
       payment plan, with the entire outstanding balance declared immediately due and payable.</p>

    <p><b>c) Right of Repossession and Legal Recourse:</b><br>
       In the event of payment default or further delays, the Creditor retains the unequivocal right to
       repossess the vehicle without prior notice or legal proceedings. The Creditor may also pursue any
       and all legal remedies to secure the remaining balance. All costs, including but not limited to
       legal fees, repossession expenses, and any damages related to the recovery of the vehicle, shall
       be borne solely by the Debtor.</p>

    <p><b>d) Debt Recovery:</b><br>
       Should the ownership title of the vehicle not be held by the Creditor, or if the value of the
       repossessed vehicle is insufficient to cover the outstanding debt, the Debtor agrees to liquidate
       personal assets to clear the balance in full. The Creditor is entitled to initiate legal
       proceedings and employ auctioneers or other authorized agents to facilitate the recovery of the
       full debt amount, including any additional costs associated with these actions. The Debtor waives
       any claims against the Creditor’s methods of recovery and shall bear all related expenses,
       including legal fees, auctioneer costs, and other administrative fees incurred by the Creditor in
       the recovery process.</p>

    <?php /* Early-payment clauses, inserted after 3(d) and lettered on from it.
             A clause keeps its letter whether or not it carries a heading: with
             one it reads "e) Heading:" then the paragraph, without one the
             letter simply opens the paragraph. */ ?>
    <?php foreach ($variantClauses as $__i => $__c):
        $__letter  = creditClauseLetter($__i);
        $__heading = trim((string)($__c['heading'] ?? ''));
        $__body    = trim((string)($__c['body'] ?? ''));
    ?>
    <p><?php if ($__heading !== ''): ?><b><?= e($__letter) ?>) <?= e($__heading) ?></b><br>
       <?= nl2br(e($__body)) ?><?php else: ?><b><?= e($__letter) ?>)</b> <?= nl2br(e($__body)) ?><?php endif; ?></p>
    <?php endforeach; ?>

    <h2>4. Legal Enforceability:</h2>
    <p>This Agreement constitutes a legally binding contract between the Parties. The Parties agree to
       abide by the terms and conditions outlined herein and acknowledge that this Agreement can be
       enforced through legal means.</p>

    <h2>5. Relationship to Original Sale Agreement</h2>
    <p>This Credit Payment Agreement is supplementary to the original Car Sale Agreement dated
       (<?= e($agreement['sale_agreement_date']
              ? date('d/m/Y', strtotime($agreement['sale_agreement_date']))
              : '____________') ?>) between the Parties. All terms and conditions of the original Car
       Sale Agreement remain in full effect, except as modified by this Agreement. In case of any
       conflict between the two agreements, the terms of this Credit Payment Agreement shall take
       precedence solely with respect to payment terms and default provisions.</p>

    <p>IN WITNESS WHEREOF, the Parties have executed this Agreement as of the date first above written.</p>

    <div id="cpaSign">
        <div class="line">………………………………………..</div>
        <div>Mascardi Ventures Limited</div>
        <div class="line">……………………………………….</div>
        <div><?= e($debtorName) ?></div>
    </div>
</div>

<div class="d-print-none mt-4 mb-4"></div>

<script>
(function () {
    var original = document.title;
    var printTitle = <?= json_encode($docName . ' — ' . ($debtorName ?: 'Lead ' . $leadId)) ?>;
    window.addEventListener('beforeprint', function () { document.title = printTitle; });
    window.addEventListener('afterprint',  function () { document.title = original; });
}());
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>

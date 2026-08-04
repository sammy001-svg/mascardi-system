<?php
/**
 * Monthly deposit receipt for a credit-agreement payment.
 *
 * Issued against a single recorded payment, and states where that payment
 * leaves the schedule — a receipt that does not show the remaining balance
 * invites the next argument.
 */
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/credit_bootstrap.php';
requireLogin();
canAccess('crm') || redirect(BASE_URL . '/index.php');

$db = getDB(); $me = authUser(); $uid = (int)$me['id'];
creditMigrate($db);

$paymentId = (int)($_GET['payment_id'] ?? 0);
$leadId    = (int)($_GET['lead_id'] ?? 0);

$st = $db->prepare("SELECT p.*, a.lead_id, a.principal, a.reference AS agr_ref, u.name AS by_name
                    FROM credit_payments p
                    JOIN credit_agreements a ON a.id = p.agreement_id
                    LEFT JOIN users u ON u.id = p.recorded_by
                    WHERE " . ($paymentId ? "p.id = ?" : "a.lead_id = ?") . "
                    ORDER BY p.id DESC LIMIT 1");
$st->execute([$paymentId ?: $leadId]);
$pay = $st->fetch(PDO::FETCH_ASSOC);
if (!$pay) {
    setFlash('error', 'No payment has been recorded yet, so there is nothing to receipt.');
    redirect(BASE_URL . '/modules/crm/view_lead.php?id=' . $leadId);
}

$leadId = (int)$pay['lead_id'];
$s = $db->prepare("SELECT * FROM crm_leads WHERE id = ?"); $s->execute([$leadId]);
$lead = $s->fetch(PDO::FETCH_ASSOC);
if ($me['role'] === 'customer_relations' && (int)$lead['assigned_to'] !== $uid) {
    setFlash('error', 'You can only view leads assigned to you.');
    redirect(BASE_URL . '/modules/crm/my_dashboard.php');
}

$agreement = creditForLead($db, $leadId);
$summary   = creditSummary($db, (int)$agreement['id']);

$car = null;
if (!empty($lead['pinned_car_id'])) {
    $s = $db->prepare("SELECT * FROM cars WHERE id = ?"); $s->execute([(int)$lead['pinned_car_id']]);
    $car = $s->fetch(PDO::FETCH_ASSOC) ?: null;
}

$pageTitle = 'Payment Receipt';
include __DIR__ . '/../../includes/header.php';
?>
<style>
@page { size: A4; margin: 16mm; }
@media print {
    html { background:#fff !important; color-scheme:light !important; }
    .d-print-none { display:none !important; }
    .app-sidebar,.app-topbar,.fab-wa,.fab-chat,#pwaOverlay,#toastStack { display:none !important; }
    .main-wrap,.main-content,.page-body { margin:0 !important; padding:0 !important; }
    body { background:#fff !important; }
    #rcDoc { border:none !important; box-shadow:none !important; }
}
#rcDoc{ --ink:#0f172a; --ink-2:#475569; --line:#dbe2ea;
    max-width:700px; margin:0 auto; background:#fff; color:var(--ink);
    border:1px solid #e5e7eb; border-radius:10px; padding:0 0 26px;
    font-family:"Segoe UI",system-ui,sans-serif; }
#rcDoc .band{ background:#111827; color:#fff; padding:16px 24px; border-radius:10px 10px 0 0; }
#rcDoc .band .w{ font-size:22px; font-weight:800; letter-spacing:5px; }
#rcDoc .band .s{ font-size:10px; letter-spacing:.2em; opacity:.75; text-transform:uppercase; margin-top:2px; }
#rcDoc .body{ padding:24px 28px 0; }
#rcDoc h1{ font-size:15px; font-weight:800; letter-spacing:.08em; text-transform:uppercase;
    text-align:center; margin:0 0 4px; }
#rcDoc .ref{ text-align:center; font-size:12px; color:var(--ink-2); margin-bottom:20px; }
#rcDoc .amt{ background:#f0fdf4; border:1px solid #bbf7d0; border-radius:10px;
    padding:16px; text-align:center; margin-bottom:20px; }
#rcDoc .amt .v{ font-size:30px; font-weight:900; color:#15803d; letter-spacing:-1px; }
#rcDoc .amt .ww{ font-size:11.5px; color:var(--ink-2); margin-top:4px; font-style:italic; }
#rcDoc table{ width:100%; border-collapse:collapse; margin-bottom:16px; }
#rcDoc td{ padding:6px 0; border-bottom:1px solid var(--line); font-size:13px; vertical-align:top; }
#rcDoc td.k{ color:var(--ink-2); width:44%; }
#rcDoc td.v{ font-weight:600; text-align:right; }
#rcDoc .sign{ display:flex; justify-content:space-between; gap:24px; margin-top:30px; font-size:12px; }
#rcDoc .sign div{ flex:1; }
#rcDoc .sign .l{ border-top:1px solid var(--ink); padding-top:5px; color:var(--ink-2); }
#rcDoc .note{ background:#f8fafc; border:1px solid var(--line); border-radius:8px;
    padding:11px 13px; font-size:11.5px; color:var(--ink-2); margin-top:18px; }
</style>

<div class="d-print-none mb-3 d-flex justify-content-between flex-wrap gap-2">
    <a href="view_lead.php?id=<?= $leadId ?>" class="btn btn-outline-secondary btn-sm">
        <i class="fa fa-arrow-left me-1"></i>Back to Lead
    </a>
    <button class="btn btn-success btn-sm" onclick="window.print()">
        <i class="fa fa-print me-1"></i>Print / Save PDF
    </button>
</div>

<div id="rcDoc">
    <div class="band">
        <div class="w">MASCARDI</div>
        <div class="s">Ventures Limited &nbsp;&middot;&nbsp; P.O.Box 1391-00606, Nairobi</div>
    </div>
    <div class="body">
        <h1>Credit Payment Receipt</h1>
        <div class="ref">
            Receipt No. <strong><?= e($pay['receipt_number'] ?: '—') ?></strong>
            &nbsp;&middot;&nbsp; Agreement <?= e($pay['agr_ref'] ?: '—') ?>
        </div>

        <div class="amt">
            <div class="v">KSh <?= number_format((float)$pay['amount'], 2) ?></div>
            <div class="ww">Kenya Shillings <?= e(creditWords((float)$pay['amount'])) ?> Only</div>
        </div>

        <table>
            <tr><td class="k">Received from</td><td class="v"><?= e($lead['name']) ?></td></tr>
            <?php if (!empty($lead['id_number'])): ?>
            <tr><td class="k">ID No.</td><td class="v"><?= e($lead['id_number']) ?></td></tr>
            <?php endif; ?>
            <tr><td class="k">Date received</td><td class="v"><?= e(date('d M Y', strtotime($pay['paid_on']))) ?></td></tr>
            <?php if ($pay['method']): ?>
            <tr><td class="k">Paid by</td><td class="v"><?= e($pay['method']) ?></td></tr>
            <?php endif; ?>
            <?php if ($pay['reference']): ?>
            <tr><td class="k">Reference</td><td class="v"><?= e($pay['reference']) ?></td></tr>
            <?php endif; ?>
            <?php if ($car): ?>
            <tr><td class="k">Vehicle</td>
                <td class="v"><?= e(trim(($car['year'] ?? '') . ' ' . ($car['make'] ?? '') . ' ' . ($car['model'] ?? ''))) ?><br>
                    <span style="font-weight:400;font-size:11.5px;color:var(--ink-2)">
                        <?= e($car['chassis_number'] ?? '') ?></span></td></tr>
            <?php endif; ?>
            <tr><td class="k">Being payment towards</td><td class="v">Credit facility installments</td></tr>
        </table>

        <table>
            <tr><td class="k">Principal amount</td><td class="v">KSh <?= number_format((float)$agreement['principal'], 2) ?></td></tr>
            <tr><td class="k">Total paid to date</td><td class="v" style="color:#15803d">KSh <?= number_format($summary['paid'], 2) ?></td></tr>
            <tr><td class="k">Balance outstanding</td>
                <td class="v" style="color:<?= $summary['balance'] > 0 ? '#c2410c' : '#15803d' ?>">
                    KSh <?= number_format($summary['balance'], 2) ?></td></tr>
            <?php if ($summary['next_due'] && $summary['balance'] > 0): ?>
            <tr><td class="k">Next installment due</td>
                <td class="v">KSh <?= number_format($summary['next_amount'], 2) ?>
                    on <?= e(date('d M Y', strtotime($summary['next_due']))) ?></td></tr>
            <?php endif; ?>
            <tr><td class="k">Installments settled</td>
                <td class="v"><?= (int)$summary['paid_count'] ?> of <?= (int)$summary['count'] ?></td></tr>
        </table>

        <?php if ($summary['balance'] <= 0): ?>
        <div class="note" style="background:#f0fdf4;border-color:#bbf7d0;color:#15803d">
            <strong>This credit facility is now settled in full.</strong>
        </div>
        <?php elseif ($summary['overdue_count'] > 0): ?>
        <div class="note" style="background:#fef2f2;border-color:#fecaca;color:#b91c1c">
            <?= (int)$summary['overdue_count'] ?> installment<?= $summary['overdue_count'] === 1 ? ' is' : 's are' ?>
            overdue, totalling KSh <?= number_format($summary['overdue_amount'], 2) ?>.
            Late payment charges apply as set out in the Credit Payment Agreement.
        </div>
        <?php endif; ?>

        <div class="sign">
            <div><div class="l">Received by — <?= e($pay['by_name'] ?: $me['name']) ?></div></div>
            <div><div class="l">Client signature</div></div>
        </div>

        <div class="note">
            This receipt acknowledges the payment shown above against the Credit Payment Agreement.
            The vehicle remains the property of Mascardi Ventures Limited until the credit facility is
            paid in full.
        </div>
    </div>
</div>

<script>
(function () {
    var o = document.title;
    var t = 'Credit Payment Receipt — ' + <?= json_encode($pay['receipt_number'] ?: (string)$leadId) ?>;
    window.addEventListener('beforeprint', function () { document.title = t; });
    window.addEventListener('afterprint',  function () { document.title = o; });
}());
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>

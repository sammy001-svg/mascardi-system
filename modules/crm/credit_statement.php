<?php
/**
 * Client statement for a credit agreement.
 *
 * The whole schedule with what has been paid against each installment, so the
 * client can see exactly where they stand and what is still owed.
 */
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/credit_bootstrap.php';
requireLogin();
canAccess('crm') || redirect(BASE_URL . '/index.php');

$db = getDB(); $me = authUser(); $uid = (int)$me['id'];
creditMigrate($db);

$leadId = (int)($_GET['lead_id'] ?? 0);
if (!$leadId) { setFlash('error', 'No lead specified.'); redirect(BASE_URL . '/modules/crm/leads.php'); }

$s = $db->prepare("SELECT * FROM crm_leads WHERE id = ?"); $s->execute([$leadId]);
$lead = $s->fetch(PDO::FETCH_ASSOC);
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

$installments = creditInstallments($db, (int)$agreement['id']);
$payments     = creditPayments($db, (int)$agreement['id']);
$summary      = creditSummary($db, (int)$agreement['id']);

$car = null;
if (!empty($lead['pinned_car_id'])) {
    $s = $db->prepare("SELECT * FROM cars WHERE id = ?"); $s->execute([(int)$lead['pinned_car_id']]);
    $car = $s->fetch(PDO::FETCH_ASSOC) ?: null;
}

$pageTitle = 'Client Statement';
include __DIR__ . '/../../includes/header.php';
?>
<style>
@page { size: A4; margin: 15mm; }
@media print {
    html { background:#fff !important; color-scheme:light !important; }
    .d-print-none { display:none !important; }
    .app-sidebar,.app-topbar,.fab-wa,.fab-chat,#pwaOverlay,#toastStack { display:none !important; }
    .main-wrap,.main-content,.page-body { margin:0 !important; padding:0 !important; }
    body { background:#fff !important; }
    #stDoc { border:none !important; box-shadow:none !important; }
    #stDoc table { page-break-inside: auto; }
    #stDoc tr { page-break-inside: avoid; }
    #stDoc thead { display: table-header-group; }   /* repeat the header on page 2 */
}
#stDoc{ --ink:#0f172a; --ink-2:#475569; --line:#dbe2ea;
    max-width:820px; margin:0 auto; background:#fff; color:var(--ink);
    border:1px solid #e5e7eb; border-radius:10px; padding:0 0 26px;
    font-family:"Segoe UI",system-ui,sans-serif; }
#stDoc .band{ background:#111827; color:#fff; padding:16px 24px; border-radius:10px 10px 0 0;
    display:flex; justify-content:space-between; align-items:flex-end; gap:16px; flex-wrap:wrap; }
#stDoc .band .w{ font-size:22px; font-weight:800; letter-spacing:5px; }
#stDoc .band .s{ font-size:10px; letter-spacing:.2em; opacity:.75; text-transform:uppercase; margin-top:2px; }
#stDoc .band .r{ text-align:right; font-size:11px; opacity:.85; }
#stDoc .body{ padding:22px 26px 0; }
#stDoc h1{ font-size:15px; font-weight:800; letter-spacing:.08em; text-transform:uppercase; margin:0 0 14px; }
#stDoc .who{ display:flex; justify-content:space-between; gap:20px; flex-wrap:wrap; margin-bottom:18px; font-size:12.5px; }
#stDoc .who .k{ color:var(--ink-2); }
#stDoc .kpis{ display:grid; grid-template-columns:repeat(4,1fr); gap:10px; margin-bottom:18px; }
#stDoc .kpi{ border:1px solid var(--line); border-radius:8px; padding:10px 12px; }
#stDoc .kpi .l{ font-size:9.5px; text-transform:uppercase; letter-spacing:.6px; color:var(--ink-2); }
#stDoc .kpi .v{ font-size:16px; font-weight:800; margin-top:3px; }
#stDoc table{ width:100%; border-collapse:collapse; margin-bottom:18px; font-size:12.5px; }
#stDoc th{ background:#f1f5f9; border:1px solid var(--line); padding:6px 9px; text-align:left;
    font-size:10px; text-transform:uppercase; letter-spacing:.5px; color:var(--ink-2); }
#stDoc td{ border:1px solid var(--line); padding:6px 9px; }
#stDoc .num{ text-align:right; white-space:nowrap; }
#stDoc tfoot td{ font-weight:800; background:#f8fafc; }
#stDoc .pill{ font-size:10px; font-weight:700; padding:1px 7px; border-radius:20px; white-space:nowrap; }
#stDoc .note{ background:#f8fafc; border:1px solid var(--line); border-radius:8px;
    padding:11px 13px; font-size:11.5px; color:var(--ink-2); }
</style>

<div class="d-print-none mb-3 d-flex justify-content-between flex-wrap gap-2">
    <a href="view_lead.php?id=<?= $leadId ?>" class="btn btn-outline-secondary btn-sm">
        <i class="fa fa-arrow-left me-1"></i>Back to Lead
    </a>
    <button class="btn btn-success btn-sm" onclick="window.print()">
        <i class="fa fa-print me-1"></i>Print / Save PDF
    </button>
</div>

<div id="stDoc">
    <div class="band">
        <div>
            <div class="w">MASCARDI</div>
            <div class="s">Ventures Limited</div>
        </div>
        <div class="r">
            291 Kabete Lane, Spring Valley<br>
            P.O.Box 1391-00606, Nairobi<br>
            Sales@mascardi.co
        </div>
    </div>

    <div class="body">
        <h1>Credit Account Statement</h1>

        <div class="who">
            <div>
                <div class="k">Client</div>
                <div style="font-weight:700;font-size:14px"><?= e($lead['name']) ?></div>
                <?php if (!empty($lead['id_number'])): ?>
                <div class="k">ID No. <?= e($lead['id_number']) ?></div>
                <?php endif; ?>
                <?php if (!empty($lead['phone'])): ?>
                <div class="k"><?= e($lead['phone']) ?></div>
                <?php endif; ?>
            </div>
            <div style="text-align:right">
                <div class="k">Agreement</div>
                <div style="font-weight:700"><?= e($agreement['reference'] ?: '—') ?></div>
                <div class="k">Dated <?= e(date('d M Y', strtotime($agreement['agreement_date']))) ?></div>
                <div class="k">Statement as at <?= e(date('d M Y')) ?></div>
            </div>
        </div>

        <?php if ($car): ?>
        <div class="who" style="border-top:1px solid var(--line);padding-top:12px">
            <div>
                <div class="k">Vehicle</div>
                <div style="font-weight:600">
                    <?= e(trim(($car['year'] ?? '') . ' ' . ($car['make'] ?? '') . ' ' . ($car['model'] ?? ''))) ?>
                </div>
            </div>
            <div style="text-align:right">
                <div class="k">Chassis <?= e($car['chassis_number'] ?? '—') ?></div>
                <div class="k">Reg. <?= e($car['registration_number'] ?: 'New') ?></div>
            </div>
        </div>
        <?php endif; ?>

        <div class="kpis">
            <div class="kpi"><div class="l">Principal</div>
                <div class="v">KSh <?= number_format((float)$agreement['principal'], 0) ?></div></div>
            <div class="kpi"><div class="l">Paid to date</div>
                <div class="v" style="color:#15803d">KSh <?= number_format($summary['paid'], 0) ?></div></div>
            <div class="kpi"><div class="l">Balance</div>
                <div class="v" style="color:<?= $summary['balance'] > 0 ? '#c2410c' : '#15803d' ?>">
                    KSh <?= number_format($summary['balance'], 0) ?></div></div>
            <div class="kpi"><div class="l">Installments</div>
                <div class="v"><?= (int)$summary['paid_count'] ?>/<?= (int)$summary['count'] ?></div></div>
        </div>

        <table>
            <thead>
                <tr>
                    <th style="width:44px">#</th>
                    <th>Due date</th>
                    <th class="num">Installment</th>
                    <th class="num">Paid</th>
                    <th class="num">Outstanding</th>
                    <th style="width:88px">Status</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($installments as $i):
                $out = round((float)$i['amount'] - (float)$i['amount_paid'], 2);
                [$sl, $sc] = creditInstallmentStatuses()[$i['status']] ?? ['Pending', '#64748b'];
            ?>
                <tr>
                    <td><?= (int)$i['seq'] ?></td>
                    <td><?= e(date('d/m/Y', strtotime($i['due_date']))) ?></td>
                    <td class="num"><?= number_format((float)$i['amount'], 2) ?></td>
                    <td class="num"><?= number_format((float)$i['amount_paid'], 2) ?></td>
                    <td class="num"><?= $out > 0 ? number_format($out, 2) : '—' ?></td>
                    <td><span class="pill" style="background:<?= $sc ?>1f;color:<?= $sc ?>"><?= $sl ?></span></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="2">Total</td>
                    <td class="num"><?= number_format($summary['due'], 2) ?></td>
                    <td class="num"><?= number_format($summary['paid'], 2) ?></td>
                    <td class="num"><?= number_format($summary['balance'], 2) ?></td>
                    <td></td>
                </tr>
            </tfoot>
        </table>

        <?php if ($payments): ?>
        <h1 style="font-size:12.5px;margin:0 0 8px">Payments Received</h1>
        <table>
            <thead>
                <tr><th>Date</th><th>Receipt</th><th>Method</th><th>Reference</th><th class="num">Amount</th></tr>
            </thead>
            <tbody>
            <?php foreach ($payments as $p): ?>
                <tr>
                    <td><?= e(date('d/m/Y', strtotime($p['paid_on']))) ?></td>
                    <td><?= e($p['receipt_number'] ?: '—') ?></td>
                    <td><?= e($p['method'] ?: '—') ?></td>
                    <td><?= e($p['reference'] ?: '—') ?></td>
                    <td class="num"><?= number_format((float)$p['amount'], 2) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr><td colspan="4">Total received</td>
                    <td class="num"><?= number_format($summary['paid'], 2) ?></td></tr>
            </tfoot>
        </table>
        <?php endif; ?>

        <?php if ($summary['balance'] <= 0): ?>
        <div class="note" style="background:#f0fdf4;border-color:#bbf7d0;color:#15803d">
            <strong>Settled in full.</strong> No further payments are due under this agreement.
        </div>
        <?php elseif ($summary['overdue_count'] > 0): ?>
        <div class="note" style="background:#fef2f2;border-color:#fecaca;color:#b91c1c">
            <strong><?= (int)$summary['overdue_count'] ?> installment<?= $summary['overdue_count'] === 1 ? '' : 's' ?>
            overdue — KSh <?= number_format($summary['overdue_amount'], 2) ?>.</strong>
            A late payment fee of <?= e(creditPenaltyPhrase($agreement)) ?> applies per occurrence, and
            interest accrues at <?= e(rtrim(rtrim(number_format((float)$agreement['interest_rate'], 2), '0'), '.')) ?>%
            per annum from the original due date, as set out in the Credit Payment Agreement.
        </div>
        <?php else: ?>
        <div class="note">
            Next installment: <strong>KSh <?= number_format($summary['next_amount'], 2) ?></strong>
            due <strong><?= e($summary['next_due'] ? date('d M Y', strtotime($summary['next_due'])) : '—') ?></strong>.
            The vehicle remains the property of Mascardi Ventures Limited until the credit facility is
            paid in full.
        </div>
        <?php endif; ?>
    </div>
</div>

<script>
(function () {
    var o = document.title;
    var t = 'Credit Statement — ' + <?= json_encode($lead['name']) ?>;
    window.addEventListener('beforeprint', function () { document.title = t; });
    window.addEventListener('afterprint',  function () { document.title = o; });
}());
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>

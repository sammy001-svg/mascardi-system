<?php
/**
 * Receipt for a payment against the ancillary charges.
 *
 * Its own document rather than a line on the deposit receipt: a deposit is
 * money towards the car and these are the plates, the registration and the
 * transfer. Putting them on one sheet would have the customer reading a single
 * total that is two different debts.
 *
 * The charges are itemised on it, because "KES 45,000 received" tells a
 * customer nothing a month later, and the account the money came into is
 * named — that is what a bank statement is reconciled against.
 *
 * A reversed payment prints as VOID rather than refusing. The customer may
 * well be holding the original, and a receipt that simply will not open is
 * no help to whoever is explaining why it no longer counts.
 */

require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/_charges.php';
requireLogin();
canAccess('crm') || canAccess('installments') || redirect(BASE_URL . '/index.php');

$db  = getDB();
$me  = authUser();
$uid = (int)$me['id'];

$payId = (int)($_GET['id'] ?? 0);
$pay   = $payId ? chargePayment($db, $payId) : null;

if (!$pay) {
    setFlash('error', 'That receipt could not be found.');
    redirect(BASE_URL . '/modules/crm/leads.php');
}

$leadId = (int)$pay['lead_id'];

// A customer relations officer sees only their own leads, here as everywhere.
if ($me['role'] === 'customer_relations') {
    $st = $db->prepare("SELECT assigned_to FROM crm_leads WHERE id = ?");
    $st->execute([$leadId]);
    if ((int)$st->fetchColumn() !== $uid) {
        setFlash('error', 'You can only view leads assigned to you.');
        redirect(BASE_URL . '/modules/crm/my_dashboard.php');
    }
}

$charges = chargesFor($db, $leadId);
$sum     = chargesSummary($db, $leadId);
$bank    = leadBank((string)($pay['bank_key'] ?? ''));
$methods = chargeMethods();
$voided  = !empty($pay['voided_at']);

// What had been paid up to and including this receipt, so the balance printed
// is the balance as it stood at the time rather than as it stands now — a
// receipt is a record of a moment.
$st = $db->prepare("SELECT COALESCE(SUM(amount),0) FROM crm_lead_charge_payments
                     WHERE lead_id = ? AND voided_at IS NULL
                       AND (paid_on < ? OR (paid_on = ? AND id <= ?))");
$st->execute([$leadId, $pay['paid_on'], $pay['paid_on'], $payId]);
$paidToHere = (float)$st->fetchColumn();
$balanceThen = max(0.0, round((float)$sum['total'] - $paidToHere, 2));

$customerName = trim((string)($pay['client_name'] ?? '')) ?: trim((string)($pay['lead_name'] ?? ''));
$vehicle = trim(implode(' ', array_filter([
    (string)($pay['year'] ?? ''), (string)($pay['make'] ?? ''), (string)($pay['model'] ?? ''),
])));

$pageTitle = 'Charges receipt';
include __DIR__ . '/../../includes/header.php';
?>

<style>
@page { size: A4; margin: 0; }
@media print {
    /* Dark mode paints the print canvas with a dark UA fill otherwise — the
       black margin that shows around the page. */
    html { background:#fff !important; color-scheme:light !important; }
    .d-print-none { display:none !important; }
    .app-sidebar,.topbar,.sidebar-overlay,.app-topbar,
    header.app-topbar,#sidebarBackdrop,.fab-wa,.fab-chat,
    #pwaOverlay,#toastStack { display:none !important; }
    .main-wrap,.main-content,.page-body { margin:0 !important; padding:0 !important; }
    body { background:#fff !important; margin:0 !important; }
    #acDoc {
        box-shadow:none !important; border:none !important; border-radius:0 !important;
        max-width:100% !important; padding:1.5cm 1.8cm !important;
    }
}
#acDoc {
    --ink:#0f172a; --ink-2:#475569; --ink-3:#94a3b8;
    --line:#e2e8f0; --surface:#f8fafc; --accent:#0b7a4f;
    max-width:700px; margin:0 auto;
    background:#fff; border:1px solid var(--line); border-radius:10px;
    font-family:'Helvetica Neue',Arial,Helvetica,sans-serif;
    font-size:12.5px; color:var(--ink); line-height:1.5;
    box-shadow:0 10px 40px rgba(15,23,42,.08);
    padding:30px 34px;
}
.ac-table{width:100%;border-collapse:collapse;margin:8px 0}
.ac-table td,.ac-table th{border:none;border-bottom:1px solid var(--line);
    padding:8px 4px;font-size:12.5px;vertical-align:top}
.ac-table th{background:none;font-weight:600;color:var(--ink-2);
    text-transform:uppercase;font-size:9.5px;letter-spacing:.06em;white-space:nowrap}
.ac-table td{color:var(--ink);font-weight:500}
.ac-table .num{text-align:right;white-space:nowrap;font-variant-numeric:tabular-nums}
.ac-items th{width:auto}
.ac-items tr:last-child td{border-bottom:none}
.ac-sum td{border-bottom:none;padding:5px 4px}
.ac-sum .lbl{color:var(--ink-2)}
.ac-sum .tot td{border-top:2px solid var(--ink);padding-top:8px;font-weight:700}
.sig-line{border-bottom:1.5px solid var(--ink);min-height:44px;margin-bottom:5px}
/* A reversed receipt is stamped rather than withheld: the customer may be
   holding the original, and somebody has to explain it. */
.ac-void{position:relative}
.ac-void::after{content:'REVERSED';position:absolute;top:38%;left:50%;
    transform:translate(-50%,-50%) rotate(-18deg);
    font-size:84px;font-weight:800;letter-spacing:.06em;color:rgba(208,59,59,.14);
    pointer-events:none;white-space:nowrap}
</style>

<div class="d-print-none mb-4 d-flex align-items-center justify-content-between flex-wrap gap-2">
    <div class="d-flex align-items-center gap-2">
        <a href="view_lead.php?id=<?= $leadId ?>" class="btn btn-outline-secondary btn-sm">
            <i class="fa fa-arrow-left me-1"></i>Back to Lead
        </a>
        <span class="text-muted" style="font-size:12.5px">/ <?= e((string)$pay['lead_name']) ?></span>
    </div>
    <button class="btn btn-success btn-sm" onclick="window.print()">
        <i class="fa fa-print me-1"></i>Print / Save PDF
    </button>
</div>

<?php if ($voided): ?>
<div class="d-print-none alert alert-danger small mb-4" style="font-size:12.5px">
    <i class="fa fa-triangle-exclamation me-1"></i>
    This payment was reversed on <?= e(fmtDate((string)$pay['voided_at'], 'j M Y')) ?><?php
        if (!empty($pay['voided_by_name'])): ?> by <?= e((string)$pay['voided_by_name']) ?><?php endif; ?>.
    <?php if (!empty($pay['void_reason'])): ?>Reason: <?= e((string)$pay['void_reason']) ?>.<?php endif; ?>
    It no longer counts towards the charges.
</div>
<?php else: ?>
<div class="d-print-none alert alert-light border small mb-4" style="font-size:12px">
    <i class="fa fa-circle-info me-1 text-muted"></i>
    For a clean printout with no browser date/title line, open <strong>More settings</strong> in the print
    dialog and untick <strong>Headers and footers</strong>.
</div>
<?php endif; ?>

<div id="acDoc" class="<?= $voided ? 'ac-void' : '' ?>">

    <!-- ── Header ──────────────────────────────────────────────────────────── -->
    <div style="display:flex;justify-content:space-between;align-items:flex-start;
                padding-bottom:16px;border-bottom:3px solid var(--ink);margin-bottom:20px">
        <div>
            <div style="font-size:24px;font-weight:800;letter-spacing:3px;color:var(--ink)">MASCARDI</div>
            <div style="font-size:9.5px;letter-spacing:.22em;color:var(--ink-3);
                        text-transform:uppercase;margin:2px 0 8px">Ventures Limited</div>
            <div style="font-size:11px;color:var(--ink-2);line-height:1.7">
                291 Kabete Lane, Spring Valley<br>
                P.O.Box 1391-00606, Nairobi Kenya<br>
                Sales@mascardi.co
            </div>
        </div>
        <div style="text-align:right">
            <div style="display:inline-block;background:var(--ink);color:#fff;font-size:11px;
                        font-weight:700;letter-spacing:.1em;text-transform:uppercase;
                        padding:7px 16px;border-radius:3px;white-space:nowrap">
                Payment Receipt
            </div>
            <div style="font-size:12px;color:var(--ink-2);margin-top:10px;line-height:1.9">
                Receipt No: <strong style="color:var(--ink)"><?= e((string)$pay['receipt_number']) ?></strong><br>
                Date: <strong style="color:var(--ink)"><?= e(fmtDate((string)$pay['paid_on'], 'j F Y')) ?></strong>
            </div>
        </div>
    </div>

    <!-- ── Who, and against what ───────────────────────────────────────────── -->
    <table class="ac-table">
        <tr><th>Received from</th><td><?= e($customerName ?: '—') ?></td></tr>
        <?php if (!empty($pay['id_number'])): ?>
        <tr><th>ID Number</th><td><?= e((string)$pay['id_number']) ?></td></tr>
        <?php endif; ?>
        <?php if (!empty($pay['lead_phone'])): ?>
        <tr><th>Telephone</th><td><?= e((string)$pay['lead_phone']) ?></td></tr>
        <?php endif; ?>
        <?php if ($vehicle !== '' || !empty($pay['registration_number'])): ?>
        <tr>
            <th>Vehicle</th>
            <td>
                <?= e($vehicle ?: 'Not recorded') ?>
                <?php if (!empty($pay['registration_number'])): ?>
                &nbsp;&middot;&nbsp;<?= e((string)$pay['registration_number']) ?>
                <?php endif; ?>
            </td>
        </tr>
        <?php endif; ?>
        <tr><th>In respect of</th><td>Ancillary charges</td></tr>
    </table>

    <!-- ── The amount, said once and plainly ───────────────────────────────── -->
    <div style="background:var(--surface);border:1px solid var(--line);border-radius:8px;
                padding:16px 18px;margin:18px 0;display:flex;justify-content:space-between;
                align-items:center;gap:16px;flex-wrap:wrap">
        <div>
            <div style="font-size:9.5px;letter-spacing:.06em;text-transform:uppercase;
                        color:var(--ink-2);font-weight:600">Amount received</div>
            <div style="font-size:26px;font-weight:800;color:var(--accent);line-height:1.2;margin-top:3px">
                KES <?= number_format((float)$pay['amount'], 0) ?>/-
            </div>
        </div>
        <div style="text-align:right;font-size:11.5px;color:var(--ink-2);line-height:1.85">
            <?php if (!empty($pay['method'])): ?>
            <div>Paid by <strong style="color:var(--ink)"><?= e($methods[$pay['method']] ?? ucfirst((string)$pay['method'])) ?></strong></div>
            <?php endif; ?>
            <?php if ($bank): ?>
            <div>Into <strong style="color:var(--ink)"><?= e((string)$bank['label']) ?></strong></div>
            <?php endif; ?>
            <?php if (!empty($pay['reference'])): ?>
            <div>Ref <strong style="color:var(--ink)"><?= e((string)$pay['reference']) ?></strong></div>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($bank): ?>
    <!-- The account it came into, in full. This is what a bank statement is
         reconciled against, and a label alone is not enough to do it with. -->
    <div style="font-size:11px;color:var(--ink-2);border:1px dashed var(--line);
                border-radius:7px;padding:10px 13px;margin-bottom:18px;line-height:1.8">
        <?php $bits = [];
              foreach ($bank['lines'] as $k => $v) $bits[] = e($k) . ': <strong>' . e($v) . '</strong>';
              echo implode(' &nbsp;&middot;&nbsp; ', $bits); ?>
    </div>
    <?php endif; ?>

    <!-- ── What was charged ────────────────────────────────────────────────── -->
    <div style="font-size:9.5px;letter-spacing:.06em;text-transform:uppercase;
                color:var(--ink-2);font-weight:600;margin-bottom:4px">The charges</div>
    <?php if (!$charges): ?>
    <div style="font-size:12px;color:var(--ink-3);padding:8px 0">No items recorded.</div>
    <?php else: ?>
    <table class="ac-table ac-items">
        <thead>
            <tr><th style="width:auto">Item</th><th class="num" style="width:120px">Amount</th></tr>
        </thead>
        <tbody>
        <?php foreach ($charges as $c): ?>
            <tr>
                <td>
                    <?= e((string)$c['item']) ?>
                    <?php if (!empty($c['notes'])): ?>
                    <div style="font-size:11px;color:var(--ink-3)"><?= e((string)$c['notes']) ?></div>
                    <?php endif; ?>
                </td>
                <td class="num">KES <?= number_format((float)$c['amount'], 0) ?>/-</td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>

    <!-- ── Where that leaves it ────────────────────────────────────────────── -->
    <table class="ac-table ac-sum" style="margin-top:10px">
        <tr>
            <td class="lbl">Total charged</td>
            <td class="num">KES <?= number_format((float)$sum['total'], 0) ?>/-</td>
        </tr>
        <tr>
            <td class="lbl">Paid to date, including this receipt</td>
            <td class="num">KES <?= number_format($paidToHere, 0) ?>/-</td>
        </tr>
        <tr class="tot">
            <td>Balance outstanding</td>
            <td class="num" style="color:<?= $balanceThen > 0 ? '#b45309' : 'var(--accent)' ?>">
                KES <?= number_format($balanceThen, 0) ?>/-
            </td>
        </tr>
    </table>

    <?php if ($balanceThen <= 0.009): ?>
    <div style="font-size:11.5px;color:var(--accent);font-weight:600;margin-top:10px">
        These charges are settled in full.
    </div>
    <?php endif; ?>

    <?php if (!empty($pay['notes'])): ?>
    <div style="font-size:11.5px;color:var(--ink-2);margin-top:14px;padding:9px 12px;
                background:var(--surface);border-radius:7px">
        <?= e((string)$pay['notes']) ?>
    </div>
    <?php endif; ?>

    <!-- ── Signatures ──────────────────────────────────────────────────────── -->
    <div style="display:flex;gap:30px;margin-top:34px">
        <div style="flex:1">
            <div class="sig-line"></div>
            <div style="font-size:10.5px;color:var(--ink-2)">
                Received by<?php if (!empty($pay['by_name'])): ?> &mdash; <?= e((string)$pay['by_name']) ?><?php endif; ?>
            </div>
        </div>
        <div style="flex:1">
            <div class="sig-line"></div>
            <div style="font-size:10.5px;color:var(--ink-2)">Customer</div>
        </div>
    </div>

    <div style="font-size:10px;color:var(--ink-3);margin-top:22px;padding-top:12px;
                border-top:1px solid var(--line);line-height:1.7">
        This receipt covers ancillary charges only &mdash; registration, plates and related
        items. It is not a receipt for the vehicle purchase or its deposit, which are
        receipted separately.
        <?php if ($voided): ?>
        <br><strong style="color:#b91c1c">This payment has since been reversed and does not count
        towards the charges.</strong>
        <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>

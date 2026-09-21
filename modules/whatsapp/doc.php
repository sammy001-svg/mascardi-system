<?php
/**
 * The document a customer opens from the link Karl sent them.
 *
 * Unauthenticated by necessity — a customer has no login — so the token does
 * all the work. It is signed, it names the document AND the client it was
 * issued for, and it expires. Editing the id inside it invalidates the
 * signature, so a link to one invoice cannot be turned into a link to another.
 *
 * What it shows is deliberately a summary rather than the internal print view.
 * Those pages are laid out for staff and carry costs, margins and internal
 * notes; a customer is entitled to their own invoice, not to the yard's
 * commercial position on it.
 */

require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/_tools.php';

$claim = waVerifyDocToken((string)($_GET['t'] ?? ''));

if ($claim === null) {
    http_response_code(404);
    $why = 'This link is not valid, or it has expired. Links last seven days — '
         . 'ask us to send a fresh one.';
} else {
    $db = getDB();

    // Ownership re-checked against the record, not taken from the token. A
    // document reassigned to another client since the link was issued must stop
    // opening, and the signature alone would not notice that.
    $doc = null;
    try {
        if ($claim['kind'] === 'invoice' || $claim['kind'] === 'quotation') {
            $table  = $claim['kind'] === 'invoice' ? 'invoices' : 'quotations';
            $numCol = $claim['kind'] === 'invoice' ? 'invoice_number' : 'quotation_number';
            $st = $db->prepare("
                SELECT d.*, d.{$numCol} AS doc_number,
                       cl.name AS client_name,
                       c.make, c.model, c.year, c.registration_number
                  FROM {$table} d
             LEFT JOIN clients cl ON cl.id = d.client_id
             LEFT JOIN cars    c  ON c.id  = d.car_id
                 WHERE d.id = ? AND d.client_id = ? LIMIT 1");
            $st->execute([$claim['id'], $claim['client_id']]);
            $doc = $st->fetch(PDO::FETCH_ASSOC) ?: null;

        } elseif ($claim['kind'] === 'deposit') {
            // The deposit receipt belongs to a lead, and the total is the sum of
            // everything paid — the figure on the lead alone is only the first
            // instalment, which is exactly the bug the receipts had before.
            require_once __DIR__ . '/../crm/_deposits.php';
            $st = $db->prepare("
                SELECT l.id, l.name AS client_name, l.deposit_amount, l.deposit_date,
                       l.agreed_sale_price, l.due_date,
                       c.make, c.model, c.year, c.registration_number
                  FROM crm_leads l
             LEFT JOIN cars c ON c.id = l.pinned_car_id
                 WHERE l.id = ? LIMIT 1");
            $st->execute([$claim['id']]);
            $lead = $st->fetch(PDO::FETCH_ASSOC);
            if ($lead) {
                $sum = leadDepositSummary($db, $lead);
                $doc = [
                    'doc_number'  => 'Deposit receipt',
                    'client_name' => $lead['client_name'],
                    'date'        => $sum['date'],
                    'make'        => $lead['make'], 'model' => $lead['model'],
                    'year'        => $lead['year'],
                    'registration_number' => $lead['registration_number'],
                    'total'       => $sum['total'],
                    'amount_paid' => $sum['total'],
                    'agreed'      => (float)($lead['agreed_sale_price'] ?? 0),
                    'due_date'    => $lead['due_date'],
                    'rows'        => $sum['rows'],
                    'initial'     => $sum['initial'],
                ];
            }

        } elseif ($claim['kind'] === 'booking') {
            $st = $db->prepare("
                SELECT b.id, b.booking_number AS doc_number, b.client_name,
                       b.booking_date AS date, b.preferred_date, b.preferred_time,
                       b.service_type, b.status, b.car_make AS make, b.car_model AS model,
                       b.car_registration AS registration_number
                  FROM service_bookings b WHERE b.id = ? LIMIT 1");
            $st->execute([$claim['id']]);
            $doc = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        }
    } catch (\Throwable $e) {
        error_log('wa doc: ' . $e->getMessage());
        $doc = null;
    }

    if (!$doc) {
        http_response_code(404);
        $why = 'This document is no longer available. Please get in touch and we will help.';
        $claim = null;
    }
}

$company = getSetting('company_name', 'Mascardi');
$logo    = function_exists('companyLogo') ? companyLogo() : ['exists' => false, 'url' => ''];
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= $claim ? e($doc['doc_number']) . ' — ' : '' ?><?= e($company) ?></title>
<style>
:root{--ink:#0f172a;--ink2:#475569;--ink3:#94a3b8;--line:#e2e8f0;--bg:#f8fafc;--brand:#0f6b5c}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:var(--ink);
     font:15px/1.6 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;padding:18px}
.sheet{max-width:640px;margin:0 auto;background:#fff;border:1px solid var(--line);
       border-radius:14px;overflow:hidden;box-shadow:0 6px 24px rgba(0,0,0,.06)}
.head{background:var(--brand);color:#fff;padding:20px 24px}
.head h1{margin:0;font-size:19px;font-weight:700}
.head p{margin:3px 0 0;font-size:13px;opacity:.85}
.body{padding:22px 24px}
table{width:100%;border-collapse:collapse;font-size:14px}
th{text-align:left;color:var(--ink2);font-weight:600;padding:9px 0;width:45%;vertical-align:top}
td{text-align:right;padding:9px 0;border-bottom:1px solid var(--line)}
tr:last-child td{border-bottom:0}
.total{font-size:20px;font-weight:800;color:var(--brand)}
.note{margin-top:18px;padding:13px 15px;background:var(--bg);border-radius:10px;
      font-size:12.5px;color:var(--ink2)}
.err{max-width:460px;margin:60px auto;background:#fff;border:1px solid var(--line);
     border-radius:14px;padding:34px;text-align:center}
.err h1{font-size:18px;margin:0 0 10px}
.err p{color:var(--ink2);font-size:14px;margin:0}
.foot{text-align:center;color:var(--ink3);font-size:11.5px;margin-top:16px}
@media print{body{background:#fff;padding:0}.sheet{border:0;box-shadow:none}}
</style>
</head>
<body>
<?php if (!$claim): ?>
    <div class="err">
        <h1>We could not open that</h1>
        <p><?= e($why) ?></p>
    </div>
<?php else: ?>
    <div class="sheet">
        <div class="head">
            <h1><?= e($company) ?></h1>
            <p><?= e(match ($claim['kind']) {
                   'invoice'   => 'Invoice',
                   'quotation' => 'Quotation',
                   'deposit'   => 'Reservation & deposit receipt',
                   'booking'   => 'Service booking',
                   default     => 'Document',
               }) ?>
               <?= $claim['kind'] === 'deposit' ? '' : e($doc['doc_number']) ?></p>
        </div>
        <div class="body">
            <table>
                <tr><th>Prepared for</th><td><?= e($doc['client_name'] ?? '') ?></td></tr>
                <tr><th>Date</th><td><?= e(fmtDate($doc['date'], 'j F Y')) ?></td></tr>
                <?php if (!empty($doc['make']) || !empty($doc['model'])): ?>
                <tr><th>Vehicle</th><td><?= e(trim(($doc['year'] ?? '') . ' '
                    . ($doc['make'] ?? '') . ' ' . ($doc['model'] ?? ''))) ?></td></tr>
                <?php endif; ?>
                <?php if (!empty($doc['registration_number'])): ?>
                <tr><th>Registration</th><td><?= e($doc['registration_number']) ?></td></tr>
                <?php endif; ?>
                <?php if (isset($doc['subtotal']) && (float)$doc['subtotal'] > 0): ?>
                <tr><th>Subtotal</th><td>KES <?= number_format((float)$doc['subtotal']) ?></td></tr>
                <?php endif; ?>
                <?php if (isset($doc['discount']) && (float)$doc['discount'] > 0): ?>
                <tr><th>Discount</th><td>− KES <?= number_format((float)$doc['discount']) ?></td></tr>
                <?php endif; ?>
                <?php if (isset($doc['tax_amount']) && (float)$doc['tax_amount'] > 0): ?>
                <tr><th>VAT</th><td>KES <?= number_format((float)$doc['tax_amount']) ?></td></tr>
                <?php endif; ?>
                <?php if ($claim['kind'] !== 'booking'): ?>
                <tr><th><?= $claim['kind'] === 'deposit' ? 'Total paid' : 'Total' ?></th>
                    <td class="total">KES <?= number_format((float)($doc['total'] ?? 0)) ?></td></tr>
                <?php endif; ?>
                <?php if ($claim['kind'] === 'invoice' && isset($doc['amount_paid'])): ?>
                <tr><th>Paid</th><td>KES <?= number_format((float)$doc['amount_paid']) ?></td></tr>
                <tr><th>Balance</th><td><strong>KES <?=
                    number_format(max(0, (float)$doc['total'] - (float)$doc['amount_paid'])) ?></strong></td></tr>
                <?php endif; ?>
                <?php if ($claim['kind'] === 'quotation' && !empty($doc['valid_until'])): ?>
                <tr><th>Valid until</th><td><?= e(fmtDate($doc['valid_until'], 'j F Y')) ?></td></tr>
                <?php endif; ?>
                <?php if ($claim['kind'] === 'deposit'): ?>
                    <?php if (!empty($doc['rows'])): ?>
                    <?php // Every payment, not just the first. A customer who topped up
                          // twice should see all three lines and their own total. ?>
                    <tr><th>Initial deposit</th><td>KES <?= number_format((float)$doc['initial']) ?></td></tr>
                    <?php foreach ($doc['rows'] as $xd): ?>
                    <tr><th>Further payment<br><span style="font-weight:400;font-size:12px"><?=
                        e(fmtDate($xd['deposit_date'], 'j M Y')) ?></span></th>
                        <td>KES <?= number_format((float)$xd['amount']) ?></td></tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                    <?php if (!empty($doc['agreed'])): ?>
                    <tr><th>Agreed price</th><td>KES <?= number_format((float)$doc['agreed']) ?></td></tr>
                    <tr><th>Balance</th><td><strong>KES <?=
                        number_format(max(0, (float)$doc['agreed'] - (float)$doc['total'])) ?></strong></td></tr>
                    <?php endif; ?>
                    <?php if (!empty($doc['due_date'])): ?>
                    <tr><th>Balance due by</th><td><?= e(fmtDate($doc['due_date'], 'j F Y')) ?></td></tr>
                    <?php endif; ?>
                <?php endif; ?>
                <?php if ($claim['kind'] === 'booking'): ?>
                <tr><th>Service</th><td><?= e($doc['service_type'] ?? '') ?></td></tr>
                <?php if (!empty($doc['preferred_date'])): ?>
                <tr><th>Booked for</th><td><?= e(fmtDate($doc['preferred_date'], 'D j F Y'))
                    . (!empty($doc['preferred_time']) ? ' at ' . e($doc['preferred_time']) : '') ?></td></tr>
                <?php endif; ?>
                <tr><th>Status</th><td><?= e(ucfirst(str_replace('_',' ', $doc['status'] ?? ''))) ?></td></tr>
                <?php endif; ?>
            </table>

            <div class="note">
                This is a summary of your <?= e(match ($claim['kind']) {
                    'deposit' => 'reservation', 'booking' => 'booking',
                    default   => $claim['kind'] }) ?> for your records. For the stamped
                original, or anything that does not look right, reply on WhatsApp and a colleague
                will help.
            </div>
        </div>
    </div>
    <div class="foot">Private link · expires seven days after it was sent</div>
<?php endif; ?>
</body>
</html>

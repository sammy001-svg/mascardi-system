<?php
/**
 * Ancillary charges on a lead — the things billed on top of the car.
 *
 * Number plates, registration, transfer, insurance, tracking, a valuation.
 * They are not part of the agreed sale price and they are not the deposit, so
 * putting them in either would misstate both: the sale price is what the
 * vehicle was sold for and the deposit is what has been paid towards it. These
 * sit beside the deal with their own total and their own payments.
 *
 * Two tables, because they answer two questions. What was charged is a list
 * somebody edits until it is right. What was paid against it is a trail that
 * must never be edited, only added to — so a payment is voided rather than
 * deleted, the way a deposit and a credit payment already are, and every total
 * reads voided_at IS NULL.
 */

require_once __DIR__ . '/_banks.php';

if (!function_exists('chargesSchema')) {

/** The usual items, offered as suggestions rather than enforced as a list:
 *  a yard bills things this list will not have thought of. */
function chargesCommonItems(): array
{
    return [
        'Number plates',
        'Registration',
        'Transfer of ownership',
        'Insurance',
        'Tracking device',
        'Valuation',
        'Inspection',
        'Logbook transfer',
        'Comprehensive cover',
        'Delivery / transport',
        'Detailing',
        'NTSA fees',
    ];
}

function chargesSchema(PDO $db): void
{
    static $done = false;
    if ($done) return;
    $done = true;

    foreach ([
        "CREATE TABLE IF NOT EXISTS crm_lead_charges (
            id INT AUTO_INCREMENT PRIMARY KEY,
            lead_id INT NOT NULL,
            item VARCHAR(120) NOT NULL,
            amount DECIMAL(15,2) NOT NULL DEFAULT 0,
            notes VARCHAR(255) NULL,
            created_by INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            KEY idx_clc_lead (lead_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        // A payment against the charges. bank_key is one of the company's own
        // accounts from _banks.php — which account the money came into, so the
        // receipt can say so and finance can reconcile it.
        "CREATE TABLE IF NOT EXISTS crm_lead_charge_payments (
            id INT AUTO_INCREMENT PRIMARY KEY,
            lead_id INT NOT NULL,
            receipt_number VARCHAR(40) NULL,
            amount DECIMAL(15,2) NOT NULL DEFAULT 0,
            paid_on DATE NOT NULL,
            method VARCHAR(30) NULL,
            bank_key VARCHAR(40) NULL,
            reference VARCHAR(120) NULL,
            notes VARCHAR(255) NULL,
            account_id INT NULL,
            recorded_by INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            voided_at DATETIME NULL,
            voided_by INT NULL,
            void_reason VARCHAR(255) NULL,
            UNIQUE KEY uq_clcp_receipt (receipt_number),
            KEY idx_clcp_lead (lead_id, voided_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    ] as $sql) {
        try { $db->exec($sql); } catch (\Throwable $e) { error_log('chargesSchema: ' . $e->getMessage()); }
    }
}

/** May this user record and edit charges? The same permission as the rest of
 *  the deal on the lead page. */
function chargesCanWrite(): bool
{
    return canWrite('crm');
}

/** Who may take a payment back out again — a manager, not whoever took it,
 *  the same rule the receivables book uses for a reversal. */
function chargesCanVoid(?array $user = null): bool
{
    $user = $user ?: authUser();
    return $user && in_array($user['role'] ?? '', [
        'super_admin', 'admin', 'general_manager', 'finance_manager',
    ], true);
}

/** The charged items, in the order they were added. */
function chargesFor(PDO $db, int $leadId): array
{
    chargesSchema($db);
    if ($leadId <= 0) return [];
    try {
        $st = $db->prepare("SELECT * FROM crm_lead_charges WHERE lead_id = ? ORDER BY id");
        $st->execute([$leadId]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {
        error_log('chargesFor: ' . $e->getMessage());
        return [];
    }
}

/** Payments against the charges, newest last. Voided ones only when asked
 *  for — the lead page shows them, so the trail is visible. */
function chargePayments(PDO $db, int $leadId, bool $includeVoided = false): array
{
    chargesSchema($db);
    if ($leadId <= 0) return [];
    try {
        $st = $db->prepare("SELECT p.*, u.name AS by_name, v.name AS voided_by_name
                              FROM crm_lead_charge_payments p
                         LEFT JOIN users u ON u.id = p.recorded_by
                         LEFT JOIN users v ON v.id = p.voided_by
                             WHERE p.lead_id = ?"
                           . ($includeVoided ? '' : ' AND p.voided_at IS NULL')
                           . " ORDER BY p.paid_on, p.id");
        $st->execute([$leadId]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {
        error_log('chargePayments: ' . $e->getMessage());
        return [];
    }
}

/**
 * What the charges come to, what has been paid, and what is left.
 *
 * One call, because every screen that shows one of these three wants the other
 * two beside it, and working them out separately is how two of them come to
 * disagree.
 */
function chargesSummary(PDO $db, int $leadId): array
{
    chargesSchema($db);
    $out = ['count' => 0, 'total' => 0.0, 'paid' => 0.0, 'balance' => 0.0,
            'payments' => 0, 'voided' => 0];
    if ($leadId <= 0) return $out;

    try {
        $st = $db->prepare("SELECT COUNT(*) n, COALESCE(SUM(amount),0) t
                              FROM crm_lead_charges WHERE lead_id = ?");
        $st->execute([$leadId]);
        $c = $st->fetch(PDO::FETCH_ASSOC) ?: ['n' => 0, 't' => 0];
        $out['count'] = (int)$c['n'];
        $out['total'] = (float)$c['t'];

        $st = $db->prepare("SELECT COUNT(*) n, COALESCE(SUM(amount),0) t
                              FROM crm_lead_charge_payments
                             WHERE lead_id = ? AND voided_at IS NULL");
        $st->execute([$leadId]);
        $p = $st->fetch(PDO::FETCH_ASSOC) ?: ['n' => 0, 't' => 0];
        $out['payments'] = (int)$p['n'];
        $out['paid']     = (float)$p['t'];

        $st = $db->prepare("SELECT COUNT(*) FROM crm_lead_charge_payments
                             WHERE lead_id = ? AND voided_at IS NOT NULL");
        $st->execute([$leadId]);
        $out['voided'] = (int)$st->fetchColumn();
    } catch (\Throwable $e) {
        error_log('chargesSummary: ' . $e->getMessage());
    }

    // Floored, because paying more than was charged is an overpayment to sort
    // out, not a negative amount owing that quietly offsets another lead.
    $out['balance'] = max(0.0, round($out['total'] - $out['paid'], 2));
    return $out;
}

/**
 * Replace the charged items with what the form submitted.
 *
 * The form is a list the operator edits as a whole — add a line, correct an
 * amount, delete one — so it posts the whole list and this writes the whole
 * list. Replacing rather than diffing keeps the two in step; the payments are
 * a separate table and are untouched by it.
 *
 * Returns ['ok', 'error', 'count', 'total'].
 */
function chargesSave(PDO $db, int $leadId, array $items, array $amounts, array $notes, int $userId): array
{
    chargesSchema($db);
    $out = ['ok' => false, 'error' => '', 'count' => 0, 'total' => 0.0];
    if ($leadId <= 0) { $out['error'] = 'No lead to charge this to.'; return $out; }

    $rows = [];
    foreach ($items as $i => $raw) {
        $item = trim((string)$raw);
        if ($item === '') continue;                  // a blank line is a line nobody filled in
        $amt = (float)preg_replace('/[^0-9.\-]/', '', (string)($amounts[$i] ?? '0'));
        if ($amt < 0) { $out['error'] = 'An amount cannot be negative.'; return $out; }
        $rows[] = [
            'item'   => mb_substr($item, 0, 120),
            'amount' => round($amt, 2),
            'notes'  => mb_substr(trim((string)($notes[$i] ?? '')), 0, 255) ?: null,
        ];
    }

    try {
        $db->beginTransaction();
        $db->prepare("DELETE FROM crm_lead_charges WHERE lead_id = ?")->execute([$leadId]);
        $ins = $db->prepare("INSERT INTO crm_lead_charges (lead_id, item, amount, notes, created_by)
                             VALUES (?,?,?,?,?)");
        foreach ($rows as $r) {
            $ins->execute([$leadId, $r['item'], $r['amount'], $r['notes'], $userId ?: null]);
            $out['total'] += $r['amount'];
        }
        $db->commit();
    } catch (\Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log('chargesSave: ' . $e->getMessage());
        $out['error'] = 'The charges could not be saved: ' . $e->getMessage();
        return $out;
    }

    $out['count'] = count($rows);
    $out['total'] = round($out['total'], 2);
    $out['ok']    = true;

    try {
        logActivity('update', 'crm_leads', $leadId,
            'Ancillary charges saved: ' . $out['count'] . ' item'
            . ($out['count'] === 1 ? '' : 's') . ' totalling ' . money($out['total']));
    } catch (\Throwable $e) {}

    return $out;
}

/** The next receipt number for a charge payment. Its own series, so it is
 *  never confused with a deposit receipt or a credit instalment receipt. */
function chargeNextReceipt(PDO $db): string
{
    chargesSchema($db);
    try {
        $n = (int)$db->query("SELECT COUNT(*) FROM crm_lead_charge_payments")->fetchColumn() + 1;
    } catch (\Throwable $e) { $n = 1; }
    return 'AC-' . date('Y') . '-' . str_pad((string)$n, 4, '0', STR_PAD_LEFT);
}

/**
 * Take a payment against the charges.
 *
 * Returns ['ok', 'error', 'id', 'receipt', 'balance'].
 */
function chargeAddPayment(PDO $db, int $leadId, float $amount, string $paidOn,
                          string $method, string $bankKey, string $reference,
                          string $notes, int $userId, ?int $accountId = null): array
{
    chargesSchema($db);
    $out = ['ok' => false, 'error' => '', 'id' => 0, 'receipt' => '', 'balance' => 0.0];

    if ($leadId <= 0)  { $out['error'] = 'No lead to record this against.'; return $out; }
    if ($amount <= 0)  { $out['error'] = 'Enter the amount received.';      return $out; }

    $sum = chargesSummary($db, $leadId);
    if ($sum['total'] <= 0) {
        $out['error'] = 'There are no charges to pay yet. Add the items first.';
        return $out;
    }
    // Taking more than is owed is nearly always a typing error — an extra
    // nought is the easiest mistake to make here and the hardest to unwind.
    if ($amount > $sum['balance'] + 0.009) {
        $out['error'] = 'That is more than the ' . money($sum['balance'])
                      . ' still owing on these charges. Check the amount.';
        return $out;
    }

    $paidOn = trim($paidOn);
    if ($paidOn === '' || !strtotime($paidOn)) {
        // MySQL's today, not PHP's: this host runs PHP on UTC and MySQL on EAT,
        // and the two disagree by three hours about when today started.
        $paidOn = (string)$db->query('SELECT CURDATE()')->fetchColumn();
    } else {
        $paidOn = date('Y-m-d', strtotime($paidOn));
    }

    // Only one of our own accounts, so a receipt cannot name a bank we do not
    // bank with.
    if ($bankKey !== '' && leadBank($bankKey) === null) $bankKey = '';

    $receipt = chargeNextReceipt($db);
    try {
        $db->prepare("INSERT INTO crm_lead_charge_payments
                          (lead_id, receipt_number, amount, paid_on, method, bank_key,
                           reference, notes, account_id, recorded_by)
                      VALUES (?,?,?,?,?,?,?,?,?,?)")
           ->execute([$leadId, $receipt, round($amount, 2), $paidOn,
                      trim($method) ?: null, $bankKey ?: null,
                      mb_substr(trim($reference), 0, 120) ?: null,
                      mb_substr(trim($notes), 0, 255) ?: null,
                      $accountId, $userId ?: null]);
        $out['id'] = (int)$db->lastInsertId();
    } catch (\Throwable $e) {
        error_log('chargeAddPayment: ' . $e->getMessage());
        $out['error'] = 'The payment could not be recorded: ' . $e->getMessage();
        return $out;
    }

    $after = chargesSummary($db, $leadId);
    $out['ok']      = true;
    $out['receipt'] = $receipt;
    $out['balance'] = $after['balance'];

    try {
        logActivity('create', 'crm_lead_charge_payments', $out['id'],
            'Ancillary charge payment ' . $receipt . ' of ' . money($amount)
            . ($bankKey !== '' ? ' into ' . leadBankLabel($bankKey) : '')
            . '. Balance ' . money($after['balance']) . '.');
    } catch (\Throwable $e) {}

    return $out;
}

/**
 * Take a payment back out.
 *
 * Kept and marked rather than deleted, so the trail still shows the entry was
 * made and who took it back — the same convention as a voided deposit and a
 * reversed credit payment.
 */
function chargeVoidPayment(PDO $db, int $paymentId, string $reason, int $userId): array
{
    chargesSchema($db);
    $out = ['ok' => false, 'error' => '', 'receipt' => '', 'amount' => 0.0, 'lead_id' => 0];

    $reason = trim($reason);
    if ($reason === '') { $out['error'] = 'Say why it is being reversed.'; return $out; }

    try {
        $st = $db->prepare("SELECT * FROM crm_lead_charge_payments WHERE id = ?");
        $st->execute([$paymentId]);
        $p = $st->fetch(PDO::FETCH_ASSOC);
    } catch (\Throwable $e) { $out['error'] = 'That payment could not be read.'; return $out; }

    if (!$p)                     { $out['error'] = 'That payment does not exist.';          return $out; }
    if (!empty($p['voided_at'])) { $out['error'] = 'That payment has already been reversed.'; return $out; }

    try {
        $db->prepare("UPDATE crm_lead_charge_payments
                         SET voided_at = NOW(), voided_by = ?, void_reason = ?
                       WHERE id = ? AND voided_at IS NULL")
           ->execute([$userId ?: null, mb_substr($reason, 0, 255), $paymentId]);
    } catch (\Throwable $e) {
        error_log('chargeVoidPayment: ' . $e->getMessage());
        $out['error'] = 'The reversal could not be completed.';
        return $out;
    }

    $out['ok']      = true;
    $out['receipt'] = (string)($p['receipt_number'] ?? '');
    $out['amount']  = (float)$p['amount'];
    $out['lead_id'] = (int)$p['lead_id'];

    try {
        logActivity('update', 'crm_lead_charge_payments', $paymentId,
            'Reversed ancillary charge payment ' . $out['receipt'] . ' of '
            . money($out['amount']) . '. Reason: ' . $reason);
    } catch (\Throwable $e) {}

    return $out;
}

/** One payment, with the lead and car it belongs to — what the receipt needs. */
function chargePayment(PDO $db, int $paymentId): ?array
{
    chargesSchema($db);
    try {
        $st = $db->prepare("SELECT p.*, u.name AS by_name,
                                   l.name AS lead_name, l.phone AS lead_phone,
                                   l.email AS lead_email, l.id_number, l.po_box,
                                   l.pinned_car_id,
                                   c.make, c.model, c.year, c.registration_number,
                                   c.chassis_number,
                                   cl.name AS client_name
                              FROM crm_lead_charge_payments p
                         LEFT JOIN users   u  ON u.id  = p.recorded_by
                         LEFT JOIN crm_leads l ON l.id = p.lead_id
                         LEFT JOIN cars    c  ON c.id  = l.pinned_car_id
                         LEFT JOIN clients cl ON cl.id = l.client_id
                             WHERE p.id = ?");
        $st->execute([$paymentId]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (\Throwable $e) {
        error_log('chargePayment: ' . $e->getMessage());
        return null;
    }
}

/** How a payment was made, for the dropdown and the receipt. */
function chargeMethods(): array
{
    return ['bank' => 'Bank transfer', 'mpesa' => 'M-Pesa', 'cash' => 'Cash',
            'cheque' => 'Cheque', 'card' => 'Card'];
}

} // function_exists('chargesSchema')

<?php
/**
 * The receivables book — cars sold on credit, what is owed, and chasing it.
 *
 * Built on the credit agreements that already existed on the lead page rather
 * than beside them. Those agreements carried the schedule, the interest, the
 * receipts and the statement, and they were right; what they lacked was a book.
 * Each one could only be reached from inside its own lead, so the finance team
 * kept the real book in a spreadsheet — year by year, one row per buyer, with
 * the account manager, the last payment, the balance and a trail of notes.
 * This is that spreadsheet, kept by the system:
 *
 *   creditBook()           every account, with what it owes and when next
 *   creditMonth()          one month: what fell due, what came in, what is short
 *   creditRecordPayment()  one way to take a payment, from here or the lead page,
 *                          and it emails the buyer a confirmation
 *   creditReminderSweep()  reminders before the due date, on it, and while late
 *
 * Every date is compared in SQL, because PHP runs UTC on this host and MySQL
 * runs EAT: "is this instalment due today" answered in PHP is three hours out,
 * which is the difference between a reminder that arrives on the due date and
 * one that arrives the evening before calling it late.
 */

require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../crm/credit_bootstrap.php';

if (!function_exists('creditBook')) {

// ── Settings ─────────────────────────────────────────────────────────────────

/** How reminders behave. All of it switchable by the finance manager. */
function creditReminderConfig(): array
{
    return [
        'enabled'      => getSetting('credit_remind_enabled', '1') === '1',
        // Days before the due date the first reminder goes.
        'before'       => max(1, min(30, (int)getSetting('credit_remind_days_before', '3'))),
        'on_due'       => getSetting('credit_remind_on_due', '1') === '1',
        // While late: a reminder every N days, at most M of them. After that a
        // person has to pick the phone up — an automated email a month into a
        // missed payment is not collection, it is noise.
        'every'        => max(1, min(30, (int)getSetting('credit_remind_overdue_every', '7'))),
        'max_overdue'  => max(0, min(12, (int)getSetting('credit_remind_overdue_max', '4'))),
        'receipts'     => getSetting('credit_receipt_email', '1') === '1',
        // Where to pay, printed on every reminder. Blank leaves the line out.
        'how_to_pay'   => trim((string)getSetting('credit_payment_instructions', '')),
        // A copy to the finance inbox, so the team sees what the customer saw.
        'cc'           => trim((string)getSetting('credit_remind_cc', '')),
    ];
}

/**
 * Who may use the book, and who may change it.
 *
 * The same permission that already guards modules/installments, deliberately.
 * My first version also let anyone with 'payments' in — which is the whole
 * sales floor and the cashier — and the receivables book is every customer's
 * debt on one page, not a consequence of being able to take a deposit.
 */
function creditCanView(): bool   { return canAccess('installments'); }
function creditCanRecord(): bool { return canWrite('installments'); }

// ── Reading ──────────────────────────────────────────────────────────────────

/**
 * Every credit account, with what it owes and when it is next due.
 *
 * One row per agreement. The figures are worked out from the schedule itself
 * rather than from the status column, which is only refreshed when somebody
 * opens the account — reading it here would show last week's picture of who is
 * late.
 */
function creditBook(PDO $db, array $f = []): array
{
    creditMigrate($db);

    $where = ["a.status <> 'cancelled'"];
    $args  = [];

    if (!empty($f['manager']))  { $where[] = 'a.account_manager_id = ?'; $args[] = (int)$f['manager']; }
    if (!empty($f['q'])) {
        $q = '%' . $f['q'] . '%';
        $where[] = '(l.name LIKE ? OR cl.name LIKE ? OR c.registration_number LIKE ?
                     OR c.make LIKE ? OR c.model LIKE ? OR a.reference LIKE ?)';
        array_push($args, $q, $q, $q, $q, $q, $q);
    }

    $sql = "
        SELECT a.*,
               COALESCE(cl.name, l.name)            AS buyer,
               COALESCE(NULLIF(cl.phone,''), l.phone) AS phone,
               COALESCE(NULLIF(cl.email,''), l.email) AS email,
               c.make, c.model, c.year, c.registration_number,
               c.id AS car_id, c.color,
               /* The photograph of the car this credit was taken out on, picked
                  here rather than per row on the page: the receivables list is
                  one card per agreement and a query inside that loop is a
                  hundred round trips on a hundred accounts. Primary image if one
                  is flagged, otherwise the first that was uploaded. */
               (SELECT ci2.file_path FROM car_images ci2
                 WHERE ci2.car_id = c.id
              ORDER BY ci2.is_primary DESC, ci2.id ASC LIMIT 1) AS car_photo,
               u.name                               AS manager_name,
               x.due_total, x.paid_total, x.next_due, x.overdue_amount, x.oldest_overdue,
               (SELECT ci.amount - ci.amount_paid FROM credit_installments ci
                 WHERE ci.agreement_id = a.id AND ci.amount_paid < ci.amount
              ORDER BY ci.seq LIMIT 1)              AS next_amount,
               p.last_paid_on,
               (SELECT cp.amount FROM credit_payments cp WHERE cp.agreement_id = a.id
              ORDER BY cp.paid_on DESC, cp.id DESC LIMIT 1) AS last_paid_amount,
               (SELECT COUNT(*) FROM credit_notes cn WHERE cn.agreement_id = a.id) AS note_count
          FROM credit_agreements a
     LEFT JOIN crm_leads l  ON l.id  = a.lead_id
     LEFT JOIN clients  cl ON cl.id = COALESCE(a.client_id, l.client_id)
     LEFT JOIN cars     c  ON c.id  = COALESCE(a.car_id, l.pinned_car_id)
     LEFT JOIN users    u  ON u.id  = a.account_manager_id
     LEFT JOIN (SELECT agreement_id,
                       SUM(amount)      AS due_total,
                       SUM(amount_paid) AS paid_total,
                       MIN(CASE WHEN amount_paid < amount THEN due_date END) AS next_due,
                       SUM(CASE WHEN due_date < CURDATE() AND amount_paid < amount
                                THEN amount - amount_paid ELSE 0 END)        AS overdue_amount,
                       MIN(CASE WHEN due_date < CURDATE() AND amount_paid < amount
                                THEN due_date END)                            AS oldest_overdue
                  FROM credit_installments GROUP BY agreement_id) x ON x.agreement_id = a.id
     LEFT JOIN (SELECT agreement_id, MAX(paid_on) AS last_paid_on
                  FROM credit_payments GROUP BY agreement_id) p ON p.agreement_id = a.id
         WHERE " . implode(' AND ', $where) . "
      ORDER BY (a.status = 'completed'), x.oldest_overdue IS NULL, x.oldest_overdue, x.next_due";

    try {
        $st = $db->prepare($sql);
        $st->execute($args);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {
        error_log('creditBook: ' . $e->getMessage());
        return [];
    }

    $today  = (string)$db->query('SELECT CURDATE()')->fetchColumn();
    $cfg    = creditReminderConfig();

    foreach ($rows as &$r) {
        $r['balance']   = max(0, round((float)$r['due_total'] - (float)$r['paid_total'], 2));
        $r['days_over'] = $r['oldest_overdue']
            ? (int)((strtotime($today) - strtotime((string)$r['oldest_overdue'])) / 86400) : 0;
        $r['standing']  = creditStanding($r, $today, $cfg['before']);
        $r['car']       = trim(($r['year'] ? $r['year'] . ' ' : '') . ($r['make'] ?? '') . ' ' . ($r['model'] ?? ''));
    }
    unset($r);

    // Filters on the worked-out standing, which SQL does not know about.
    if (!empty($f['standing'])) {
        $want = (string)$f['standing'];
        $rows = array_values(array_filter($rows, static fn ($r) => $r['standing']['key'] === $want));
    }

    return $rows;
}

/**
 * Where an account stands, in the words the spreadsheet already uses.
 *
 * @return array{key:string, label:string, tone:string}
 */
function creditStanding(array $r, string $today, int $soonDays = 3): array
{
    $status = (string)($r['status'] ?? 'active');

    if ($status === 'legal')     return ['key' => 'legal',     'label' => 'With lawyers',  'tone' => 'legal'];
    if ($status === 'completed' || (float)($r['balance'] ?? 0) <= 0.009)
                                 return ['key' => 'cleared',   'label' => 'Cleared',       'tone' => 'good'];
    if ((float)($r['overdue_amount'] ?? 0) > 0.009)
                                 return ['key' => 'overdue',   'label' => 'Overdue',       'tone' => 'critical'];
    if ($status === 'defaulted') return ['key' => 'defaulted', 'label' => 'In default',    'tone' => 'critical'];

    $next = (string)($r['next_due'] ?? '');
    if ($next !== '' && strtotime($next) <= strtotime($today . " +{$soonDays} days")) {
        return ['key' => 'due_soon', 'label' => $next === $today ? 'Due today' : 'Due soon', 'tone' => 'warning'];
    }

    return ['key' => 'on_schedule', 'label' => 'On schedule', 'tone' => 'neutral'];
}

/** The totals across the book, for the tiles at the top of it. */
function creditBookTotals(array $rows): array
{
    $t = ['accounts' => 0, 'principal' => 0.0, 'outstanding' => 0.0, 'overdue' => 0.0,
          'overdue_accounts' => 0, 'cleared' => 0, 'legal' => 0.0, 'legal_accounts' => 0];

    foreach ($rows as $r) {
        if ($r['standing']['key'] === 'cleared') { $t['cleared']++; continue; }
        $t['accounts']++;
        $t['principal']   += (float)$r['principal'];
        $t['outstanding'] += (float)$r['balance'];
        if ($r['standing']['key'] === 'legal') {
            $t['legal'] += (float)$r['balance'];
            $t['legal_accounts']++;
        }
        if ((float)$r['overdue_amount'] > 0.009) {
            $t['overdue'] += (float)$r['overdue_amount'];
            $t['overdue_accounts']++;
        }
    }

    return $t;
}

/**
 * One month of the book: what fell due in it, what came in during it, and what
 * of that month's instalments is still unpaid.
 *
 * "Collected" is money received in the month, whatever instalment it paid, and
 * "still owed" is what remains unpaid on the instalments that fell due in the
 * month. They are different questions and both are asked at month end — the
 * first is the cash, the second is the arrears the month has added.
 */
function creditMonth(PDO $db, string $ym): array
{
    creditMigrate($db);
    if (!preg_match('/^\d{4}-\d{2}$/', $ym)) $ym = (string)$db->query("SELECT DATE_FORMAT(CURDATE(),'%Y-%m')")->fetchColumn();

    $from = $ym . '-01';
    $to   = (string)$db->query("SELECT LAST_DAY(" . $db->quote($from) . ")")->fetchColumn();

    $due = finRowsSafe($db, "
        SELECT ci.id, ci.seq, ci.due_date, ci.amount, ci.amount_paid, ci.agreement_id,
               a.reference, a.status, COALESCE(cl.name, l.name) AS buyer,
               COALESCE(NULLIF(cl.phone,''), l.phone) AS phone,
               c.registration_number, u.name AS manager_name
          FROM credit_installments ci
          JOIN credit_agreements a ON a.id = ci.agreement_id AND a.status <> 'cancelled'
     LEFT JOIN crm_leads l  ON l.id  = a.lead_id
     LEFT JOIN clients  cl ON cl.id = COALESCE(a.client_id, l.client_id)
     LEFT JOIN cars     c  ON c.id  = COALESCE(a.car_id, l.pinned_car_id)
     LEFT JOIN users    u  ON u.id  = a.account_manager_id
         WHERE ci.due_date BETWEEN ? AND ?
      ORDER BY ci.due_date, buyer", [$from, $to]);

    $expected = $owed = 0.0;
    foreach ($due as $d) {
        $expected += (float)$d['amount'];
        $owed     += max(0, (float)$d['amount'] - (float)$d['amount_paid']);
    }

    $collected = (float)(finRowsSafe($db, "SELECT COALESCE(SUM(p.amount),0) AS v
                                             FROM credit_payments p
                                             JOIN credit_agreements a ON a.id = p.agreement_id
                                            WHERE p.paid_on BETWEEN ? AND ?", [$from, $to])[0]['v'] ?? 0);

    $payments = finRowsSafe($db, "
        SELECT p.id, p.receipt_number, p.amount, p.paid_on, p.method, p.reference,
               a.id AS agreement_id, COALESCE(cl.name, l.name) AS buyer, c.registration_number,
               u.name AS by_name
          FROM credit_payments p
          JOIN credit_agreements a ON a.id = p.agreement_id
     LEFT JOIN crm_leads l  ON l.id  = a.lead_id
     LEFT JOIN clients  cl ON cl.id = COALESCE(a.client_id, l.client_id)
     LEFT JOIN cars     c  ON c.id  = COALESCE(a.car_id, l.pinned_car_id)
     LEFT JOIN users    u  ON u.id  = p.recorded_by
         WHERE p.paid_on BETWEEN ? AND ?
      ORDER BY p.paid_on DESC, p.id DESC", [$from, $to]);

    return [
        'ym'        => $ym,
        'from'      => $from,
        'to'        => $to,
        'label'     => date('F Y', strtotime($from)),
        'expected'  => $expected,
        'owed'      => $owed,
        'met'       => $expected - $owed,
        'rate'      => $expected > 0 ? ($expected - $owed) / $expected * 100 : null,
        'collected' => $collected,
        'due'       => $due,
        'payments'  => $payments,
    ];
}

/** A list, or an empty one — the book must not die on a missing table. */
function finRowsSafe(PDO $db, string $sql, array $args = []): array
{
    try {
        $st = $db->prepare($sql);
        $st->execute($args);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (\Throwable $e) {
        error_log('credit query: ' . $e->getMessage());
        return [];
    }
}

/** One account in full, or null. */
function creditAccount(PDO $db, int $agreementId): ?array
{
    foreach (creditBook($db) as $r) {
        if ((int)$r['id'] === $agreementId) return $r;
    }
    // A cancelled one is not in the book but can still be opened.
    try {
        $st = $db->prepare('SELECT * FROM credit_agreements WHERE id = ?');
        $st->execute([$agreementId]);
        $a = $st->fetch(PDO::FETCH_ASSOC);
        return $a ?: null;
    } catch (\Throwable $e) { return null; }
}

/** The dated notes trail, newest first. */
function creditNotes(PDO $db, int $agreementId): array
{
    return finRowsSafe($db, "SELECT n.*, u.name AS by_name FROM credit_notes n
                          LEFT JOIN users u ON u.id = n.created_by
                              WHERE n.agreement_id = ?
                           ORDER BY n.created_at DESC, n.id DESC", [$agreementId]);
}

function creditNoteKinds(): array
{
    return [
        'note'       => 'Follow-up',
        'contact'    => 'Customer said',
        'management' => 'Management',
        'legal'      => 'Legal',
    ];
}

function creditAddNote(PDO $db, int $agreementId, string $kind, string $body, int $userId): bool
{
    $body = trim($body);
    if ($body === '') return false;
    if (!array_key_exists($kind, creditNoteKinds())) $kind = 'note';

    try {
        $db->prepare('INSERT INTO credit_notes (agreement_id, kind, body, created_by) VALUES (?,?,?,?)')
           ->execute([$agreementId, $kind, mb_substr($body, 0, 4000), $userId ?: null]);
        // Writing a note is reviewing the account; the spreadsheet tracks the
        // date of last review by hand, and this keeps it without anyone trying.
        $db->prepare('UPDATE credit_agreements SET last_reviewed_at = NOW(), last_reviewed_by = ? WHERE id = ?')
           ->execute([$userId ?: null, $agreementId]);
        return true;
    } catch (\Throwable $e) {
        error_log('creditAddNote: ' . $e->getMessage());
        return false;
    }
}

/** The reminders and receipts sent on an account, newest first. */
function creditSentLog(PDO $db, int $agreementId, int $limit = 30): array
{
    return finRowsSafe($db, "SELECT r.*, ci.seq, ci.due_date FROM credit_reminders r
                          LEFT JOIN credit_installments ci ON ci.id = r.installment_id
                              WHERE r.agreement_id = ?
                           ORDER BY r.sent_at DESC, r.id DESC
                              LIMIT " . max(1, min(200, $limit)), [$agreementId]);
}

// ── Who to write to ──────────────────────────────────────────────────────────

/** @return array{email:string, name:string, phone:string} */
function creditRecipient(PDO $db, int $agreementId): array
{
    $r = finRowsSafe($db, "SELECT COALESCE(NULLIF(cl.email,''), l.email) AS email,
                                  COALESCE(cl.name, l.name) AS name,
                                  COALESCE(NULLIF(cl.phone,''), l.phone) AS phone
                             FROM credit_agreements a
                        LEFT JOIN crm_leads l  ON l.id  = a.lead_id
                        LEFT JOIN clients  cl ON cl.id = COALESCE(a.client_id, l.client_id)
                            WHERE a.id = ?", [$agreementId]);
    $r = $r[0] ?? [];

    $email = trim((string)($r['email'] ?? ''));

    return [
        'email' => filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '',
        'name'  => trim((string)($r['name'] ?? '')),
        'phone' => trim((string)($r['phone'] ?? '')),
    ];
}

// ── Taking a payment ─────────────────────────────────────────────────────────

/**
 * Record money received on a credit account.
 *
 * The one way it is done, whether from the book or from the lead page, so the
 * two cannot drift: the payment is written, spread across the schedule oldest
 * instalment first, the account's standing refreshed, and the buyer emailed a
 * confirmation with the new balance.
 *
 * The email is sent after the payment is safely written and never undoes it. A
 * receipt that could not be emailed is a thing to mention on screen; a payment
 * lost because the mail server was down is a thing nobody would forgive.
 *
 * @return array{ok:bool, error:string, receipt:string, payment_id:int,
 *               balance:float, emailed:bool, email_note:string}
 */
function creditRecordPayment(PDO $db, int $agreementId, float $amount, string $paidOn,
                             string $method, string $reference, string $notes, int $userId,
                             ?int $accountId = null): array
{
    require_once __DIR__ . '/_accounts.php';
    acctMigrate($db);

    $fail = static fn (string $why) => ['ok' => false, 'error' => $why, 'receipt' => '', 'payment_id' => 0,
                                         'balance' => 0.0, 'emailed' => false, 'email_note' => ''];
    creditMigrate($db);

    if ($amount <= 0) return $fail('Enter the amount received.');

    $paidOn = creditReadDate($paidOn) ?? (strtotime($paidOn) ? date('Y-m-d', strtotime($paidOn)) : '');
    if ($paidOn === '') $paidOn = (string)$db->query('SELECT CURDATE()')->fetchColumn();

    try {
        $st = $db->prepare('SELECT * FROM credit_agreements WHERE id = ?');
        $st->execute([$agreementId]);
        $agr = $st->fetch(PDO::FETCH_ASSOC);
    } catch (\Throwable $e) { $agr = null; }

    if (!$agr)                         return $fail('That credit account does not exist.');
    if ($agr['status'] === 'cancelled') return $fail('That credit account has been cancelled.');

    $before = creditSummary($db, $agreementId)['balance'];

    // Taking more than is owed is almost always a typing error — an extra zero
    // on a payment is the easiest mistake to make and the hardest to unwind.
    if ($before > 0 && $amount > $before + 0.009) {
        return $fail('That is more than the ' . money($before) . ' still owed. Check the amount — '
                   . 'if the buyer really has overpaid, record the balance here and the rest as a refund.');
    }

    try {
        $receipt = creditNextReceipt($db);
        $db->prepare("INSERT INTO credit_payments
                         (agreement_id, receipt_number, amount, paid_on, method, reference, notes,
                          recorded_by, account_id)
                      VALUES (?,?,?,?,?,?,?,?,?)")
           ->execute([$agreementId, $receipt, round($amount, 2), $paidOn,
                      trim($method) ?: null, trim($reference) ?: null, trim($notes) ?: null,
                      $userId ?: null, $accountId]);
        $payId = (int)$db->lastInsertId();
        creditApplyPayment($db, $agreementId, $amount, $payId);
    } catch (\Throwable $e) {
        error_log('creditRecordPayment: ' . $e->getMessage());
        return $fail('The payment could not be recorded: ' . $e->getMessage());
    }

    $sum = creditSummary($db, $agreementId);

    try {
        logActivity('create', 'credit_payments', $payId,
            "Credit payment {$receipt} of " . money($amount) . ' recorded on ' . ($agr['reference'] ?? '#' . $agreementId)
            . '. Balance ' . money($sum['balance']) . '.');
    } catch (\Throwable $e) {}

    // Then, and only then, tell the buyer.
    $mail = ['sent' => false, 'note' => ''];
    if (creditReminderConfig()['receipts']) {
        $mail = creditEmailReceipt($db, $agreementId, $payId, $receipt, $amount, $paidOn, $sum);
    } else {
        $mail['note'] = 'Receipt emails are switched off.';
    }

    return ['ok' => true, 'error' => '', 'receipt' => $receipt, 'payment_id' => $payId,
            'balance' => (float)$sum['balance'], 'emailed' => $mail['sent'], 'email_note' => $mail['note']];
}

// ── Emails ───────────────────────────────────────────────────────────────────

/** A figure the way a customer reads it. */
function creditKes(float $v): string
{
    return 'KES ' . number_format($v, 2);
}

/** The account lines every email carries, so a buyer always knows where they stand. */
function creditEmailFacts(PDO $db, int $agreementId, array $rows): string
{
    $acct = finRowsSafe($db, "SELECT a.reference, c.make, c.model, c.year, c.registration_number
                                FROM credit_agreements a
                           LEFT JOIN crm_leads l ON l.id = a.lead_id
                           LEFT JOIN cars c ON c.id = COALESCE(a.car_id, l.pinned_car_id)
                               WHERE a.id = ?", [$agreementId])[0] ?? [];

    $vehicle = trim(($acct['year'] ?? '') . ' ' . ($acct['make'] ?? '') . ' ' . ($acct['model'] ?? ''));
    if (!empty($acct['registration_number'])) $vehicle .= ' (' . $acct['registration_number'] . ')';

    $all = array_merge(
        $vehicle !== '' ? [['Vehicle', $vehicle]] : [],
        !empty($acct['reference']) ? [['Account', (string)$acct['reference']]] : [],
        $rows
    );

    $html = '<table class="data" style="width:100%;border-collapse:collapse;margin:14px 0">';
    foreach ($all as [$k, $v]) {
        $html .= '<tr><th style="text-align:left;padding:7px 10px;border-bottom:1px solid #e2e8f0;'
               . 'color:#64748b;font-weight:600;width:42%">' . e($k) . '</th>'
               . '<td style="padding:7px 10px;border-bottom:1px solid #e2e8f0">' . $v . '</td></tr>';
    }
    return $html . '</table>';
}

function creditEmailFooter(): string
{
    $cfg = creditReminderConfig();
    $co  = getSetting('company_name', 'Mascardi');
    $ph  = trim((string)getSetting('company_phone', ''));

    $out = '';
    if ($cfg['how_to_pay'] !== '') {
        $out .= '<p style="margin:14px 0 6px"><strong>How to pay</strong></p>'
              . '<p style="margin:0;white-space:pre-line">' . e($cfg['how_to_pay']) . '</p>';
    }
    $out .= '<p style="margin-top:16px;color:#64748b;font-size:13px">If you have already paid, thank you — '
          . 'please ignore this message. For anything about your account, reply to this email'
          . ($ph !== '' ? ' or call ' . e($ph) : '') . '.</p>'
          . '<p style="margin-top:6px;color:#64748b;font-size:13px">' . e($co) . ' — Accounts</p>';
    return $out;
}

/**
 * Send one email and write down that it went — or why it did not.
 *
 * The log row is what makes the reminder sweep idempotent: the unique key on
 * (instalment, stage, channel) means a reminder that has been attempted cannot
 * be attempted again, however many times the sweep runs.
 */
function creditSendAndLog(PDO $db, int $agreementId, ?int $installmentId, ?int $paymentId,
                          string $stage, string $to, string $toName, string $subject, string $html): array
{
    $cfg = creditReminderConfig();

    if ($to === '') {
        creditLogSend($db, $agreementId, $installmentId, $paymentId, $stage, '', 'no_email',
                      'No email address on file for this buyer.');
        return ['sent' => false, 'note' => 'There is no email address on file for this buyer.'];
    }

    try {
        require_once __DIR__ . '/../../includes/mailer.php';
        $r = sendMail($to, $toName, $subject, mailTemplate($subject, $html), 'credit_' . $stage, $agreementId);
    } catch (\Throwable $e) {
        $r = ['ok' => false, 'error' => $e->getMessage()];
    }

    creditLogSend($db, $agreementId, $installmentId, $paymentId, $stage, $to,
                  $r['ok'] ? 'sent' : 'failed', $r['ok'] ? null : mb_substr((string)($r['error'] ?? ''), 0, 250));

    // The finance inbox gets its own copy, so the team sees what the customer saw.
    if ($r['ok'] && $cfg['cc'] !== '' && filter_var($cfg['cc'], FILTER_VALIDATE_EMAIL)) {
        try { sendMail($cfg['cc'], 'Accounts', '[Copy] ' . $subject,
                       mailTemplate($subject, '<p style="color:#64748b">Sent to ' . e($to) . ':</p>' . $html),
                       'credit_' . $stage . '_copy', $agreementId); } catch (\Throwable $e) {}
    }

    return $r['ok']
        ? ['sent' => true,  'note' => 'Emailed to ' . $to . '.']
        : ['sent' => false, 'note' => 'The email to ' . $to . ' could not be sent: ' . ($r['error'] ?? 'unknown error')];
}

function creditLogSend(PDO $db, int $agreementId, ?int $installmentId, ?int $paymentId,
                       string $stage, string $to, string $status, ?string $error): void
{
    try {
        $db->prepare("INSERT INTO credit_reminders
                         (agreement_id, installment_id, payment_id, stage, channel, sent_to, status, error)
                      VALUES (?,?,?,?, 'email', ?,?,?)
                      ON DUPLICATE KEY UPDATE status = VALUES(status), error = VALUES(error),
                                              sent_to = VALUES(sent_to), sent_at = NOW()")
           ->execute([$agreementId, $installmentId, $paymentId, $stage, $to ?: null, $status, $error]);
    } catch (\Throwable $e) { error_log('creditLogSend: ' . $e->getMessage()); }
}

/** A payment confirmation, sent the moment a payment is recorded. */
function creditEmailReceipt(PDO $db, int $agreementId, int $paymentId, string $receipt,
                            float $amount, string $paidOn, array $sum): array
{
    $to  = creditRecipient($db, $agreementId);
    $co  = getSetting('company_name', 'Mascardi');
    $first = $to['name'] !== '' ? explode(' ', $to['name'])[0] : 'there';

    $rows = [
        ['Amount received', '<strong>' . e(creditKes($amount)) . '</strong>'],
        ['Date received',   e(date('j F Y', strtotime($paidOn)))],
        ['Receipt number',  e($receipt)],
        ['Balance remaining', '<strong>' . e(creditKes((float)$sum['balance'])) . '</strong>'],
    ];
    if ((float)$sum['balance'] > 0.009 && $sum['next_due']) {
        $rows[] = ['Next instalment', e(creditKes((float)$sum['next_amount'])) . ' due '
                  . e(date('j F Y', strtotime((string)$sum['next_due'])))];
    }

    $settled = (float)$sum['balance'] <= 0.009;
    $html = '<p>Dear ' . e($first) . ',</p>'
          . '<p>Thank you — we have received your payment. '
          . ($settled ? '<strong>Your account is now paid in full.</strong> Our team will be in touch about '
                        . 'releasing the logbook and anything else held against the vehicle.'
                      : 'Here is where your account now stands.') . '</p>'
          . creditEmailFacts($db, $agreementId, $rows)
          . '<p style="margin-top:16px;color:#64748b;font-size:13px">Please keep this email as your record. '
          . 'If any figure looks wrong, reply to this email and we will put it right.</p>'
          . '<p style="margin-top:6px;color:#64748b;font-size:13px">' . e($co) . ' — Accounts</p>';

    $subject = ($settled ? 'Paid in full — ' : 'Payment received — ') . $receipt;

    return creditSendAndLog($db, $agreementId, null, $paymentId, 'receipt',
                            $to['email'], $to['name'], $subject, $html);
}

/**
 * A reminder about the earliest unpaid instalment on an account.
 *
 * @param string $stage 'before' | 'due' | 'overdue_N' | 'manual'
 */
function creditEmailReminder(PDO $db, array $inst, string $stage): array
{
    $agreementId = (int)$inst['agreement_id'];
    $to    = creditRecipient($db, $agreementId);
    $sum   = creditSummary($db, $agreementId);
    $first = $to['name'] !== '' ? explode(' ', $to['name'])[0] : 'there';

    $owedNow = max(0, (float)$inst['amount'] - (float)$inst['amount_paid']);
    $dueWord = date('j F Y', strtotime((string)$inst['due_date']));
    $daysOver = (int)($inst['days_over'] ?? 0);

    if (str_starts_with($stage, 'overdue')) {
        $subject = 'Payment overdue — instalment due ' . $dueWord;
        $lead    = '<p>Our records show that your instalment due on <strong>' . e($dueWord) . '</strong> has not '
                 . 'yet been received in full. It is now ' . $daysOver . ' day' . ($daysOver === 1 ? '' : 's')
                 . ' overdue.</p><p>Please make the payment as soon as you can, or reply to let us know when '
                 . 'to expect it — we would much rather hear from you than chase.</p>';
        $rows = [
            ['Overdue now', '<strong>' . e(creditKes((float)$sum['overdue_amount'])) . '</strong>'],
            ['Earliest missed', e($dueWord)],
            ['Total balance', e(creditKes((float)$sum['balance']))],
        ];
    } elseif ($stage === 'due') {
        $subject = 'Your instalment is due today';
        $lead    = '<p>This is a reminder that your instalment is due <strong>today, ' . e($dueWord) . '</strong>.</p>';
        $rows = [
            ['Due today',     '<strong>' . e(creditKes($owedNow)) . '</strong>'],
            ['Total balance', e(creditKes((float)$sum['balance']))],
        ];
    } else {
        $subject = 'Upcoming instalment due ' . $dueWord;
        $lead    = '<p>A friendly reminder that your next instalment is due on <strong>' . e($dueWord) . '</strong>.</p>';
        $rows = [
            ['Amount due',    '<strong>' . e(creditKes($owedNow)) . '</strong>'],
            ['Due date',      e($dueWord)],
            ['Total balance', e(creditKes((float)$sum['balance']))],
        ];
    }

    $html = '<p>Dear ' . e($first) . ',</p>' . $lead
          . creditEmailFacts($db, $agreementId, $rows)
          . creditEmailFooter();

    return creditSendAndLog($db, $agreementId, (int)$inst['id'], null, $stage,
                            $to['email'], $to['name'], $subject, $html);
}

// ── The sweep ────────────────────────────────────────────────────────────────

/**
 * Which reminder, if any, an instalment is due for today.
 *
 * Self-healing on purpose. If the sweep did not run for three days, the
 * "before" window is still open until the due date, and an overdue stage is
 * worked out from how late the instalment is rather than from whether
 * yesterday's run happened. A missed "due today" is simply skipped — by then
 * the overdue reminder says the same thing more usefully.
 */
function creditStageFor(int $daysUntil, array $cfg): ?string
{
    if ($daysUntil >= 1 && $daysUntil <= $cfg['before']) return 'before';
    if ($daysUntil === 0)                                return $cfg['on_due'] ? 'due' : null;
    if ($daysUntil < 0 && $cfg['max_overdue'] > 0) {
        $late = -$daysUntil;
        $k = intdiv($late - 1, $cfg['every']) + 1;
        return $k <= $cfg['max_overdue'] ? 'overdue_' . $k : null;
    }
    return null;
}

/**
 * Send whatever reminders are due now, a few at a time.
 *
 * One reminder per account per run, about the earliest unpaid instalment — a
 * buyer who has missed two payments gets one email saying so, not two emails
 * a second apart. Accounts with the lawyers are never emailed: nobody sends a
 * friendly payment reminder to somebody the company is suing.
 *
 * Safe to run as often as anything likes: the log's unique key means a
 * reminder that has been attempted is never attempted again.
 *
 * @return array{considered:int, sent:int, failed:int, no_email:int}
 */
function creditReminderSweep(PDO $db, int $limit = 5): array
{
    $out = ['considered' => 0, 'sent' => 0, 'failed' => 0, 'no_email' => 0];
    $cfg = creditReminderConfig();
    if (!$cfg['enabled']) return $out;

    creditMigrate($db);

    // The earliest unpaid instalment of every live account, and how far off it is.
    $rows = finRowsSafe($db, "
        SELECT ci.*, DATEDIFF(ci.due_date, CURDATE()) AS days_until,
               GREATEST(DATEDIFF(CURDATE(), ci.due_date), 0) AS days_over
          FROM credit_installments ci
          JOIN credit_agreements a ON a.id = ci.agreement_id
         WHERE a.status IN ('active','defaulted')
           AND a.reminders_enabled = 1
           AND ci.amount_paid < ci.amount
           AND ci.id = (SELECT c2.id FROM credit_installments c2
                         WHERE c2.agreement_id = ci.agreement_id AND c2.amount_paid < c2.amount
                      ORDER BY c2.seq LIMIT 1)
           AND ci.due_date <= DATE_ADD(CURDATE(), INTERVAL ? DAY)
      ORDER BY ci.due_date ASC", [$cfg['before']]);

    foreach ($rows as $inst) {
        if ($out['sent'] + $out['failed'] >= $limit) break;

        $stage = creditStageFor((int)$inst['days_until'], $cfg);
        if ($stage === null) continue;

        // Already done — the log says so.
        $st = $db->prepare("SELECT 1 FROM credit_reminders
                             WHERE installment_id = ? AND stage = ? AND channel = 'email'");
        $st->execute([(int)$inst['id'], $stage]);
        if ($st->fetchColumn()) continue;

        $out['considered']++;
        $r = creditEmailReminder($db, $inst, $stage);

        if ($r['sent'])                                   $out['sent']++;
        elseif (str_contains($r['note'], 'no email'))     $out['no_email']++;
        else                                              $out['failed']++;
    }

    return $out;
}

/**
 * The heartbeat: run the sweep from whatever already ticks.
 *
 * Every signed-in browser polls the notification bell, which makes it the one
 * thing in the system that runs on its own without a cron job. Locked with a
 * conditional UPDATE so twenty browsers polling at once sweep once between
 * them — checking the time and then writing it would let two pass together,
 * which is the whole problem the lock exists to prevent.
 */
function creditHeartbeat(PDO $db, int $everySeconds = 120): void
{
    if (!creditReminderConfig()['enabled']) return;

    try {
        $st = $db->prepare("UPDATE settings SET setting_value = UNIX_TIMESTAMP()
                             WHERE setting_key = 'credit_last_sweep'
                               AND setting_value < (UNIX_TIMESTAMP() - ?)");
        $st->execute([max(30, $everySeconds)]);

        if ($st->rowCount() === 0) {
            $has = $db->query("SELECT 1 FROM settings WHERE setting_key = 'credit_last_sweep'")->fetchColumn();
            if ($has) return;
            $db->exec("INSERT IGNORE INTO settings (setting_key, setting_value)
                       VALUES ('credit_last_sweep', UNIX_TIMESTAMP())");
        }
    } catch (\Throwable $e) {
        error_log('creditHeartbeat lock: ' . $e->getMessage());
        return;
    }

    try { creditReminderSweep($db, 3); }
    catch (\Throwable $e) { error_log('creditHeartbeat: ' . $e->getMessage()); }
}

// ── Sold, and sold on credit ─────────────────────────────────────────────────

/**
 * Cars delivered from leads, split into sold outright and sold on credit.
 *
 * A delivered car with no credit agreement attached is a completed sale: the
 * money is in. One with a credit agreement is a sale too, but the money is not
 * in — it is a receivable, and counting it with the cash sales overstates what
 * the business has actually been paid. So they are counted apart.
 *
 * Dated by delivery, falling back to conversion for leads delivered before the
 * delivery date was recorded.
 *
 * @return array{sold:array{count:int,value:float}, credit:array{count:int,value:float,outstanding:float}}
 */
function creditSalesSplit(PDO $db, string $from, string $to): array
{
    creditMigrate($db);

    $rows = finRowsSafe($db, "
        SELECT l.id,
               COALESCE(NULLIF(l.agreed_sale_price,0), c.offer_price, c.asking_price, 0) AS value,
               a.id AS agreement_id
          FROM crm_leads l
     LEFT JOIN cars c ON c.id = l.pinned_car_id
     LEFT JOIN credit_agreements a ON a.lead_id = l.id AND a.status <> 'cancelled'
         WHERE l.stage = 'delivered'
           AND DATE(COALESCE(l.delivered_at, l.converted_at, l.updated_at)) BETWEEN ? AND ?",
        [$from, $to]);

    $out = ['sold' => ['count' => 0, 'value' => 0.0],
            'credit' => ['count' => 0, 'value' => 0.0, 'outstanding' => 0.0]];

    foreach ($rows as $r) {
        $k = $r['agreement_id'] ? 'credit' : 'sold';
        $out[$k]['count']++;
        $out[$k]['value'] += (float)$r['value'];
        if ($k === 'credit') {
            $out['credit']['outstanding'] += (float)creditSummary($db, (int)$r['agreement_id'])['balance'];
        }
    }

    return $out;
}

} // function_exists('creditBook')

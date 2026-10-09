<?php
/**
 * Credit Payment Agreements — schema and helpers.
 *
 * A credit agreement sits on top of a reserved lead: the balance left after
 * deposits becomes the Principal Amount, and the buyer pays it off in monthly
 * installments. The wording of the agreement itself is fixed (credit_payment_agreement.php)
 * — only the figures, dates and party details come from here.
 *
 * On the schedule
 * ---------------
 * The operator gives a monthly figure and a first due date; the number of
 * installments and the completion date follow from the principal. The final
 * installment carries the remainder rather than rounding every payment, so the
 * schedule always adds up to exactly the principal — a schedule that does not
 * total the debt is not something to put a signature on.
 */

// The agreement's three variants and their clause wording. Pulled in here so
// every consumer of the credit helpers has creditVariant() available.
require_once __DIR__ . '/credit_clauses.php';

if (!function_exists('creditMigrate')) {

// 2 — added concession_date / concession_amount for the Early Payment Concession.
// 3 — the receivables book: account manager, logbook held, reminders on/off,
//     irregular schedules, a "with lawyers" status, the follow-up notes trail
//     and a log of every reminder and receipt emailed.
// 5 — accounts imported from the finance team's Excel book for buyers who were
//     never in the system: no lead, so the buyer and vehicle live on the row.
if (!defined('CREDIT_SCHEMA_VERSION')) define('CREDIT_SCHEMA_VERSION', '5');

function creditStatuses(): array {
    return [
        'active'    => ['Active',    '#2563eb'],
        'completed' => ['Settled',   '#16a34a'],
        'defaulted' => ['In Default','#dc2626'],
        // A debt handed to the lawyers. Kept distinct from "in default" because
        // it changes what the yard does: nobody sends a friendly payment
        // reminder to somebody the company is suing.
        'legal'     => ['With lawyers', '#7c3aed'],
        'cancelled' => ['Cancelled', '#64748b'],
    ];
}

function creditInstallmentStatuses(): array {
    return [
        'pending' => ['Pending', '#64748b'],
        'partial' => ['Partial', '#f59e0b'],
        'paid'    => ['Paid',    '#16a34a'],
        'overdue' => ['Overdue', '#dc2626'],
    ];
}

function creditMigrate(PDO $db, bool $force = false): void
{
    static $done = false;
    if ($done && !$force) return;
    $done = true;

    if (!$force) {
        try {
            $st = $db->prepare("SELECT setting_value FROM settings WHERE setting_key = 'credit_schema_version'");
            $st->execute();
            if ((string)$st->fetchColumn() === CREDIT_SCHEMA_VERSION) return;
        } catch (\Throwable $_) {}
    }

    $tables = [
        "CREATE TABLE IF NOT EXISTS credit_agreements (
            id INT AUTO_INCREMENT PRIMARY KEY,
            lead_id INT NOT NULL,
            car_id INT NULL,
            client_id INT NULL,
            reference VARCHAR(40) NULL,
            agreement_date DATE NOT NULL,
            sale_agreement_date DATE NULL,
            principal DECIMAL(15,2) NOT NULL DEFAULT 0,
            monthly_payment DECIMAL(15,2) NOT NULL DEFAULT 0,
            first_due_date DATE NOT NULL,
            installments INT NOT NULL DEFAULT 0,
            completion_date DATE NULL,
            total_repayable DECIMAL(15,2) NOT NULL DEFAULT 0,
            penalty_type ENUM('fixed','percent') NOT NULL DEFAULT 'fixed',
            penalty_value DECIMAL(15,2) NOT NULL DEFAULT 0,
            interest_rate DECIMAL(6,2) NOT NULL DEFAULT 25.00,
            concession_date DATE NULL,
            concession_amount DECIMAL(15,2) NULL,
            status ENUM('active','completed','defaulted','cancelled') NOT NULL DEFAULT 'active',
            notes TEXT NULL,
            created_by INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_lead (lead_id),
            KEY idx_ca_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS credit_installments (
            id INT AUTO_INCREMENT PRIMARY KEY,
            agreement_id INT NOT NULL,
            seq INT NOT NULL,
            due_date DATE NOT NULL,
            amount DECIMAL(15,2) NOT NULL,
            amount_paid DECIMAL(15,2) NOT NULL DEFAULT 0,
            penalty_charged DECIMAL(15,2) NOT NULL DEFAULT 0,
            status ENUM('pending','partial','paid','overdue') NOT NULL DEFAULT 'pending',
            paid_at DATETIME NULL,
            UNIQUE KEY uq_seq (agreement_id, seq),
            KEY idx_ci_due (due_date, status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        "CREATE TABLE IF NOT EXISTS credit_payments (
            id INT AUTO_INCREMENT PRIMARY KEY,
            agreement_id INT NOT NULL,
            installment_id INT NULL,
            receipt_number VARCHAR(40) NULL,
            amount DECIMAL(15,2) NOT NULL,
            paid_on DATE NOT NULL,
            method VARCHAR(60) NULL,
            reference VARCHAR(120) NULL,
            notes TEXT NULL,
            recorded_by INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            KEY idx_cp_agr (agreement_id, paid_on),
            UNIQUE KEY uq_receipt (receipt_number)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    ];
    foreach ($tables as $sql) { try { $db->exec($sql); } catch (\Throwable $_) {} }

    // Columns added after the table shipped. CREATE TABLE IF NOT EXISTS above is a
    // no-op on an existing install, so anything added later has to come through
    // here as well — the two definitions must stay in step.
    $columns = [
        "ALTER TABLE credit_agreements ADD COLUMN concession_date DATE NULL AFTER interest_rate",
        "ALTER TABLE credit_agreements ADD COLUMN concession_amount DECIMAL(15,2) NULL AFTER concession_date",

        // v3 — the receivables book. Every row of the finance team's spreadsheet
        // names the person chasing that account; the system never recorded it.
        "ALTER TABLE credit_agreements ADD COLUMN account_manager_id INT NULL AFTER client_id",
        // The logbook held back as security until the car is paid for.
        "ALTER TABLE credit_agreements ADD COLUMN logbook_held TINYINT(1) NOT NULL DEFAULT 0",
        // Off for a buyer who has asked not to be emailed, or whose account is
        // being handled some other way.
        "ALTER TABLE credit_agreements ADD COLUMN reminders_enabled TINYINT(1) NOT NULL DEFAULT 1",
        // 'custom' where the instalments are not equal — a good third of the
        // current book is written as "1m, then 200k five times, then …".
        "ALTER TABLE credit_agreements ADD COLUMN schedule_type VARCHAR(10) NOT NULL DEFAULT 'equal'",
        "ALTER TABLE credit_agreements ADD COLUMN last_reviewed_at DATETIME NULL",
        "ALTER TABLE credit_agreements ADD COLUMN last_reviewed_by INT NULL",
        "ALTER TABLE credit_agreements MODIFY COLUMN status
             ENUM('active','completed','defaulted','legal','cancelled') NOT NULL DEFAULT 'active'",

        // v4 — reversing a payment entered by mistake. The row is kept and
        // marked rather than deleted, the same way a deposit is voided, so the
        // trail still shows that the entry was made and who took it back.
        // Everything that counts money must therefore read voided_at IS NULL.
        "ALTER TABLE credit_payments ADD COLUMN voided_at DATETIME NULL",
        "ALTER TABLE credit_payments ADD COLUMN voided_by INT NULL",
        "ALTER TABLE credit_payments ADD COLUMN void_reason VARCHAR(255) NULL",
        // Every sum of this table filters on voided_at, so it leads the index.
        "ALTER TABLE credit_payments ADD KEY idx_cp_live (voided_at, paid_on)",

        // v5 — imported accounts. A buyer from the old spreadsheet has no lead
        // and often no client or car record either, so lead_id may be NULL (the
        // unique key still holds: MySQL allows any number of NULLs in it) and
        // the details the sheet carried are kept on the agreement.
        "ALTER TABLE credit_agreements MODIFY COLUMN lead_id INT NULL",
        "ALTER TABLE credit_agreements ADD COLUMN source VARCHAR(12) NOT NULL DEFAULT 'system'",
        "ALTER TABLE credit_agreements ADD COLUMN ext_name VARCHAR(150) NULL",
        "ALTER TABLE credit_agreements ADD COLUMN ext_phone VARCHAR(40) NULL",
        "ALTER TABLE credit_agreements ADD COLUMN ext_email VARCHAR(150) NULL",
        "ALTER TABLE credit_agreements ADD COLUMN ext_vehicle VARCHAR(150) NULL",
        "ALTER TABLE credit_agreements ADD COLUMN ext_registration VARCHAR(30) NULL",
    ];
    foreach ($columns as $sql) { try { $db->exec($sql); } catch (\Throwable $_) {} }

    $more = [
        // The notes columns of the spreadsheet — Solomon's, management's, the MFS
        // team's — as one dated trail rather than three cells that get
        // overwritten, so "what did we last agree with him" has an answer.
        "CREATE TABLE IF NOT EXISTS credit_notes (
            id INT AUTO_INCREMENT PRIMARY KEY,
            agreement_id INT NOT NULL,
            kind VARCHAR(20) NOT NULL DEFAULT 'note',
            body TEXT NOT NULL,
            created_by INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            KEY idx_cn_agr (agreement_id, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        // Every reminder and receipt sent, one row per instalment per stage.
        // The unique key is what makes the sweep safe to run as often as it
        // likes: a reminder that has gone cannot go twice.
        "CREATE TABLE IF NOT EXISTS credit_reminders (
            id INT AUTO_INCREMENT PRIMARY KEY,
            agreement_id INT NOT NULL,
            installment_id INT NULL,
            payment_id INT NULL,
            stage VARCHAR(20) NOT NULL,
            channel VARCHAR(12) NOT NULL DEFAULT 'email',
            sent_to VARCHAR(190) NULL,
            status VARCHAR(12) NOT NULL DEFAULT 'sent',
            error VARCHAR(255) NULL,
            sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_cr_once (installment_id, stage, channel),
            KEY idx_cr_agr (agreement_id, sent_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    ];
    foreach ($more as $sql) { try { $db->exec($sql); } catch (\Throwable $_) {} }

    try {
        $db->prepare("INSERT INTO settings (setting_key, setting_value)
                      VALUES ('credit_schema_version', ?)
                      ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")
           ->execute([CREDIT_SCHEMA_VERSION]);
    } catch (\Throwable $_) {}
}

/**
 * Calculates Reducing Balance EMI and schedule metrics.
 */
function creditCalculateReducingBalance(float $principal, float $monthlyInterestPct, int $months): array
{
    $months = max(1, $months);
    if ($principal <= 0) {
        return ['monthly_payment' => 0.0, 'total_interest' => 0.0, 'total_repayable' => 0.0];
    }
    
    $r = ($monthlyInterestPct / 100);
    if ($r <= 0) {
        $monthly = round($principal / $months, 2);
        return [
            'monthly_payment' => $monthly,
            'total_interest'  => 0.0,
            'total_repayable' => $principal,
        ];
    }

    // Reducing balance EMI formula: M = P * [r(1+r)^n] / [(1+r)^n - 1]
    $pow = pow(1 + $r, $months);
    $monthly = round($principal * ($r * $pow) / ($pow - 1), 2);
    
    // Simulate month by month for exact total repayable and interest sum
    $rem = $principal;
    $totalPaid = 0.0;
    for ($i = 1; $i <= $months; $i++) {
        $interestForMonth = round($rem * $r, 2);
        $pmt = ($i === $months) ? round($rem + $interestForMonth, 2) : $monthly;
        $principalPortion = $pmt - $interestForMonth;
        $rem = max(0, $rem - $principalPortion);
        $totalPaid += $pmt;
    }
    
    $totalRepayable = round($totalPaid, 2);
    $totalInterest  = round($totalRepayable - $principal, 2);

    return [
        'monthly_payment' => $monthly,
        'total_interest'  => $totalInterest,
        'total_repayable' => $totalRepayable,
    ];
}

/**
 * Builds the installment schedule.
 *
 * Returns ['count','completion_date','total','rows'=>[['seq','due_date','amount'],…]].
 * The last row absorbs the rounding so the schedule totals the principal exactly.
 */
function creditBuildSchedule(float $principal, float $monthly, string $firstDue): array
{
    $out = ['count' => 0, 'completion_date' => null, 'total' => 0.0, 'rows' => []];
    if ($principal <= 0 || $monthly <= 0 || !strtotime($firstDue)) return $out;

    $n = (int)ceil(round($principal / $monthly, 6));
    $n = max(1, min($n, 600));   // a 50-year plan is a data-entry error, not a deal

    $start = new DateTimeImmutable(date('Y-m-d', strtotime($firstDue)));
    $remaining = $principal;

    for ($i = 1; $i <= $n; $i++) {
        // Month arithmetic on a 29th–31st start would skip short months
        // ("31 Jan +1 month" lands in March), so the day is clamped instead.
        $due = creditAddMonths($start, $i - 1);
        $amt = ($i === $n) ? round($remaining, 2) : round($monthly, 2);
        if ($amt <= 0) { $n = $i - 1; break; }
        $remaining = round($remaining - $amt, 2);
        $out['rows'][] = ['seq' => $i, 'due_date' => $due->format('Y-m-d'), 'amount' => $amt];
        $out['total'] += $amt;
    }

    $out['count'] = count($out['rows']);
    $out['total'] = round($out['total'], 2);
    $out['completion_date'] = $out['rows'] ? end($out['rows'])['due_date'] : null;
    return $out;
}

/**
 * A schedule typed out by hand, for the agreements that are not equal
 * instalments.
 *
 * Accepts one instalment per line as "date, amount" in any of the ways people
 * write a date here — 30/09/2026, 2026-09-30, 30.9.26 — because a form that
 * rejects the way the finance team already writes dates is a form they stop
 * using. Amounts may carry commas. Returns the same shape as
 * creditBuildSchedule(), plus the lines it could not read, so nothing is
 * silently dropped from a legal document.
 */
function creditParseCustomSchedule(string $text): array
{
    $out = ['count' => 0, 'completion_date' => null, 'total' => 0.0, 'rows' => [], 'bad' => []];
    $rows = [];

    foreach (preg_split('/\r\n|\r|\n/', trim($text)) as $n => $line) {
        $line = trim($line);
        if ($line === '') continue;

        if (!preg_match('#^\s*([0-9]{1,4}[./-][0-9]{1,2}[./-][0-9]{1,4})\s*[,;\t ]\s*(?:KES|Ksh|KSH)?\s*([0-9][0-9,]*(?:\.[0-9]{1,2})?)\s*$#i', $line, $m)) {
            $out['bad'][] = 'line ' . ($n + 1) . ': "' . $line . '"';
            continue;
        }

        $date = creditReadDate($m[1]);
        $amt  = (float)str_replace(',', '', $m[2]);

        if ($date === null || $amt <= 0) {
            $out['bad'][] = 'line ' . ($n + 1) . ': "' . $line . '"';
            continue;
        }
        $rows[] = ['due_date' => $date, 'amount' => round($amt, 2)];
    }

    usort($rows, static fn ($a, $b) => strcmp($a['due_date'], $b['due_date']));

    foreach ($rows as $i => $r) {
        $out['rows'][] = ['seq' => $i + 1, 'due_date' => $r['due_date'], 'amount' => $r['amount']];
        $out['total'] += $r['amount'];
    }
    $out['count']           = count($out['rows']);
    $out['total']           = round($out['total'], 2);
    $out['completion_date'] = $out['rows'] ? end($out['rows'])['due_date'] : null;

    return $out;
}

/** 30/09/2026, 30.9.26, 2026-09-30 → 2026-09-30. Day first, as Kenya writes it. */
function creditReadDate(string $s): ?string
{
    $s = trim($s);

    if (preg_match('#^(\d{4})[./-](\d{1,2})[./-](\d{1,2})$#', $s, $m)) {
        [$y, $mo, $d] = [(int)$m[1], (int)$m[2], (int)$m[3]];
    } elseif (preg_match('#^(\d{1,2})[./-](\d{1,2})[./-](\d{2,4})$#', $s, $m)) {
        [$d, $mo, $y] = [(int)$m[1], (int)$m[2], (int)$m[3]];
        if ($y < 100) $y += 2000;
    } else {
        return null;
    }

    return checkdate($mo, $d, $y) ? sprintf('%04d-%02d-%02d', $y, $mo, $d) : null;
}

/** Adds whole months, clamping the day so it never rolls into the next month. */
function creditAddMonths(DateTimeImmutable $from, int $months): DateTimeImmutable
{
    if ($months === 0) return $from;
    $day   = (int)$from->format('j');
    $first = $from->modify('first day of this month')->modify("+{$months} months");
    $last  = (int)$first->format('t');
    return $first->setDate((int)$first->format('Y'), (int)$first->format('n'), min($day, $last));
}

/** Writes the schedule rows for an agreement, replacing any existing ones. */
function creditWriteSchedule(PDO $db, int $agreementId, array $schedule): void
{
    try {
        $db->prepare("DELETE FROM credit_installments WHERE agreement_id = ?")->execute([$agreementId]);
        $ins = $db->prepare("INSERT INTO credit_installments (agreement_id, seq, due_date, amount) VALUES (?,?,?,?)");
        foreach ($schedule['rows'] as $r) {
            $ins->execute([$agreementId, $r['seq'], $r['due_date'], $r['amount']]);
        }
    } catch (\Throwable $e) {
        error_log('creditWriteSchedule: ' . $e->getMessage());
    }
}

/** The agreement for a lead, or null. */
function creditForLead(PDO $db, int $leadId): ?array
{
    try {
        $st = $db->prepare("SELECT * FROM credit_agreements WHERE lead_id = ?");
        $st->execute([$leadId]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (\Throwable $_) { return null; }
}

function creditInstallments(PDO $db, int $agreementId): array
{
    try {
        $st = $db->prepare("SELECT * FROM credit_installments WHERE agreement_id = ? ORDER BY seq");
        $st->execute([$agreementId]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (\Throwable $_) { return []; }
}

/**
 * What has been paid on an account, oldest first.
 *
 * Reversed entries are left out unless asked for. That is the safe default:
 * the callers are a customer-facing statement and the lead page's "last
 * payment", and on both a reversed entry would be a wrong figure shown to
 * somebody. Only the finance account page asks to see them, because that is
 * where the reversal has to be visible and auditable.
 */
function creditPayments(PDO $db, int $agreementId, bool $includeVoided = false): array
{
    try {
        $st = $db->prepare("SELECT p.*, u.name AS by_name, v.name AS voided_by_name
                              FROM credit_payments p
                         LEFT JOIN users u ON u.id = p.recorded_by
                         LEFT JOIN users v ON v.id = p.voided_by
                             WHERE p.agreement_id = ?"
                           . ($includeVoided ? '' : ' AND p.voided_at IS NULL')
                           . " ORDER BY p.paid_on, p.id");
        $st->execute([$agreementId]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (\Throwable $_) { return []; }
}

/**
 * Applies a payment across the schedule, oldest installment first.
 *
 * Returns the ids touched. Deliberately allocated rather than just recorded as
 * a lump sum: the agreement is written in terms of installments, so "which
 * installment is still outstanding" has to be answerable.
 */
function creditApplyPayment(PDO $db, int $agreementId, float $amount, int $paymentId = 0): array
{
    $touched = [];
    $left = round($amount, 2);
    if ($left <= 0) return $touched;

    foreach (creditInstallments($db, $agreementId) as $inst) {
        if ($left <= 0) break;
        $owed = round((float)$inst['amount'] - (float)$inst['amount_paid'], 2);
        if ($owed <= 0) continue;

        $put = min($owed, $left);
        $newPaid = round((float)$inst['amount_paid'] + $put, 2);
        $status  = $newPaid + 0.009 >= (float)$inst['amount'] ? 'paid' : 'partial';

        try {
            $db->prepare("UPDATE credit_installments
                          SET amount_paid = ?, status = ?, paid_at = ?
                          WHERE id = ?")
               ->execute([$newPaid, $status, $status === 'paid' ? date('Y-m-d H:i:s') : null, (int)$inst['id']]);
            if ($paymentId && !$touched) {
                // Attribute the payment to the first installment it lands on,
                // which is what a receipt is issued against.
                $db->prepare("UPDATE credit_payments SET installment_id = ? WHERE id = ?")
                   ->execute([(int)$inst['id'], $paymentId]);
            }
        } catch (\Throwable $e) { error_log('creditApplyPayment: ' . $e->getMessage()); }

        $touched[] = (int)$inst['id'];
        $left = round($left - $put, 2);
    }

    creditRefreshStatus($db, $agreementId);
    return $touched;
}

/**
 * Lay every live payment back over the schedule from scratch.
 *
 * Used when a payment is reversed. Unwinding one payment's own share is not
 * possible after the fact: a payment spills across instalments, later payments
 * fill in behind it, and nothing records which payment paid which part beyond
 * the first one a receipt was issued against. Zeroing the schedule and
 * re-applying what is left, oldest first, always lands where it would have if
 * the reversed entry had never been typed.
 *
 * Ordered by paid_on then id, which is the order creditApplyPayment() would
 * have seen them in. Returns the number of payments re-applied.
 */
function creditRebuildAllocation(PDO $db, int $agreementId): int
{
    $db->prepare("UPDATE credit_installments
                     SET amount_paid = 0, status = 'pending', paid_at = NULL
                   WHERE agreement_id = ?")->execute([$agreementId]);
    $db->prepare("UPDATE credit_payments SET installment_id = NULL WHERE agreement_id = ?")
       ->execute([$agreementId]);

    $st = $db->prepare("SELECT id, amount FROM credit_payments
                         WHERE agreement_id = ? AND voided_at IS NULL
                      ORDER BY paid_on ASC, id ASC");
    $st->execute([$agreementId]);
    $live = $st->fetchAll(PDO::FETCH_ASSOC);

    foreach ($live as $p) {
        creditApplyPayment($db, $agreementId, (float)$p['amount'], (int)$p['id']);
    }

    // creditApplyPayment() ends by refreshing the status, but it only ever
    // closes an account. One that was completed and now owes again has to be
    // put back by hand, or a reversed final payment leaves a settled account
    // with a balance nobody is chasing.
    $st = $db->prepare("SELECT COALESCE(SUM(amount),0) due, COALESCE(SUM(amount_paid),0) paid
                          FROM credit_installments WHERE agreement_id = ?");
    $st->execute([$agreementId]);
    $r = $st->fetch(PDO::FETCH_ASSOC) ?: ['due' => 0, 'paid' => 0];
    if ((float)$r['paid'] + 0.009 < (float)$r['due']) {
        $db->prepare("UPDATE credit_agreements SET status = 'active'
                       WHERE id = ? AND status = 'completed'")->execute([$agreementId]);
    }
    creditRefreshStatus($db, $agreementId);

    return count($live);
}

/** Marks overdue installments and closes the agreement once it is paid off. */
function creditRefreshStatus(PDO $db, int $agreementId): void
{
    try {
        $db->prepare("UPDATE credit_installments
                      SET status = 'overdue'
                      WHERE agreement_id = ? AND due_date < CURDATE()
                        AND status IN ('pending','partial')
                        AND amount_paid < amount")->execute([$agreementId]);

        $st = $db->prepare("SELECT COALESCE(SUM(amount),0) due, COALESCE(SUM(amount_paid),0) paid
                            FROM credit_installments WHERE agreement_id = ?");
        $st->execute([$agreementId]);
        $r = $st->fetch(PDO::FETCH_ASSOC) ?: ['due' => 0, 'paid' => 0];

        if ((float)$r['paid'] + 0.009 >= (float)$r['due'] && (float)$r['due'] > 0) {
            // Only ever closes an agreement that is genuinely settled; it never
            // reopens one someone marked cancelled or defaulted by hand.
            $db->prepare("UPDATE credit_agreements SET status='completed'
                          WHERE id = ? AND status = 'active'")->execute([$agreementId]);
        }
    } catch (\Throwable $e) { error_log('creditRefreshStatus: ' . $e->getMessage()); }
}

/** Totals for the summary card and the statement. */
function creditSummary(PDO $db, int $agreementId): array
{
    $out = ['due' => 0.0, 'paid' => 0.0, 'balance' => 0.0, 'overdue_count' => 0,
            'overdue_amount' => 0.0, 'next_due' => null, 'next_amount' => 0.0, 'paid_count' => 0, 'count' => 0];
    try {
        creditRefreshStatus($db, $agreementId);
        $rows = creditInstallments($db, $agreementId);
        $out['count'] = count($rows);
        foreach ($rows as $r) {
            $out['due']  += (float)$r['amount'];
            $out['paid'] += (float)$r['amount_paid'];
            if ($r['status'] === 'paid') $out['paid_count']++;
            if ($r['status'] === 'overdue') {
                $out['overdue_count']++;
                $out['overdue_amount'] += (float)$r['amount'] - (float)$r['amount_paid'];
            }
            if ($out['next_due'] === null && $r['status'] !== 'paid') {
                $out['next_due']    = $r['due_date'];
                $out['next_amount'] = (float)$r['amount'] - (float)$r['amount_paid'];
            }
        }
        $out['balance'] = round($out['due'] - $out['paid'], 2);
    } catch (\Throwable $_) {}
    return $out;
}

/**
 * The amount in words, bare.
 *
 * The shared numberToWords() appends " Shillings Only", which is right for a
 * cheque but wrong here — the agreement already writes "(Kenya Shillings …
 * Only)" around it, so the suffix would read "…Shillings Only Only".
 */
function creditWords(float $amount): string
{
    $w = numberToWords((int)round($amount));
    $w = preg_replace('/\s*shillings\s*only\s*$/i', '', $w);
    $w = preg_replace('/\s*only\s*$/i', '', (string)$w);
    return ucwords(strtolower(trim((string)$w)));
}

/** Penalty wording for the agreement, matching whichever basis was chosen. */
function creditPenaltyPhrase(array $agreement): string
{
    if ($agreement['penalty_type'] === 'percent') {
        return rtrim(rtrim(number_format((float)$agreement['penalty_value'], 2), '0'), '.')
             . '% of the overdue installment';
    }
    $v = (float)$agreement['penalty_value'];
    return 'Ksh ' . number_format($v, 0) . ' (Kenya Shillings ' . creditWords($v) . ')';
}

/** What a late installment would cost, on the agreed basis. */
function creditPenaltyFor(array $agreement, float $installmentAmount): float
{
    return $agreement['penalty_type'] === 'percent'
        ? round($installmentAmount * ((float)$agreement['penalty_value'] / 100), 2)
        : round((float)$agreement['penalty_value'], 2);
}

function creditNextReference(PDO $db): string
{
    try {
        $n = (int)$db->query("SELECT COUNT(*) FROM credit_agreements")->fetchColumn() + 1;
        return 'CPA-' . date('Y') . '-' . str_pad((string)$n, 4, '0', STR_PAD_LEFT);
    } catch (\Throwable $_) { return 'CPA-' . date('Y') . '-0001'; }
}

function creditNextReceipt(PDO $db): string
{
    try {
        $n = (int)$db->query("SELECT COUNT(*) FROM credit_payments")->fetchColumn() + 1;
        return 'CRP-' . date('Y') . '-' . str_pad((string)$n, 4, '0', STR_PAD_LEFT);
    } catch (\Throwable $_) { return 'CRP-' . date('Y') . '-0001'; }
}

/** Ordinal day, as the agreement's opening line is written ("10th day of June"). */
function creditOrdinalDay(string $date): string
{
    $d = (int)date('j', strtotime($date));
    $suffix = ($d % 100 >= 11 && $d % 100 <= 13) ? 'th'
            : ([1 => 'st', 2 => 'nd', 3 => 'rd'][$d % 10] ?? 'th');
    return $d . $suffix;
}

} // function_exists guard

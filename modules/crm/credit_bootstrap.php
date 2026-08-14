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

if (!defined('CREDIT_SCHEMA_VERSION')) define('CREDIT_SCHEMA_VERSION', '1');

function creditStatuses(): array {
    return [
        'active'    => ['Active',    '#2563eb'],
        'completed' => ['Settled',   '#16a34a'],
        'defaulted' => ['In Default','#dc2626'],
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

function creditPayments(PDO $db, int $agreementId): array
{
    try {
        $st = $db->prepare("SELECT p.*, u.name AS by_name FROM credit_payments p
                            LEFT JOIN users u ON u.id = p.recorded_by
                            WHERE p.agreement_id = ? ORDER BY p.paid_on, p.id");
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

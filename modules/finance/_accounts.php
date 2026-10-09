<?php
/**
 * The company's own accounts — where the money actually sits.
 *
 * The system has always recorded how money arrived (M-Pesa, bank, cheque, cash)
 * but never which account it arrived in. A yard with two bank accounts, a
 * paybill and a petty cash tin could add up its takings and still not answer
 * the only question the owner asks, which is how much is in each one.
 *
 * So: accounts are records, every movement of money names one, and the balance
 * of an account is its opening balance plus everything in, less everything out.
 *
 * ON WHERE THE MOVEMENTS COME FROM
 *
 * There is no single ledger table, and inventing one would mean the money on a
 * statement and the money on the payments page could disagree — two tables, two
 * answers, and no way to tell which is right. Instead the statement is read
 * from the tables that already hold the money:
 *
 *   payments          money in — the main receipts book
 *   credit_payments   money in — instalments on cars sold on credit
 *   crm_lead_deposits money in — top-ups on a reservation
 *   expenses          money out
 *
 * Each gains one column, account_id, and the ledger is their union. That way a
 * statement cannot drift from the records it describes, because it IS the
 * records.
 *
 * Unconfirmed payments are deliberately left out of a balance. A payment
 * somebody has keyed in but not confirmed is not money in the account yet, and
 * a balance that counts it is a balance that will not match the bank.
 */

require_once __DIR__ . '/../../includes/functions.php';

if (!function_exists('acctMigrate')) {

define('ACCOUNT_SCHEMA_VERSION', '1');

/** The kinds of account a yard actually has. */
function acctTypes(): array
{
    return [
        'bank'   => ['Bank account', 'fa-building-columns'],
        'mpesa'  => ['M-Pesa / mobile', 'fa-mobile-screen'],
        'cash'   => ['Cash on hand', 'fa-money-bill-wave'],
        'other'  => ['Other', 'fa-wallet'],
    ];
}

/**
 * The table, and the account_id column on every table that holds money.
 *
 * DDL commits implicitly in MySQL, so this must never run inside a transaction
 * — a lesson this codebase has already paid for once.
 */
function acctMigrate(PDO $db): void
{
    static $done = false;
    if ($done) return;
    $done = true;

    if (getSetting('ACCOUNT_SCHEMA_VERSION', '') === ACCOUNT_SCHEMA_VERSION) return;

    try {
        $db->exec("
            CREATE TABLE IF NOT EXISTS cash_accounts (
              id              INT AUTO_INCREMENT PRIMARY KEY,
              name            VARCHAR(120) NOT NULL,
              type            VARCHAR(20)  NOT NULL DEFAULT 'bank',
              bank_name       VARCHAR(120) NULL,
              account_number  VARCHAR(60)  NULL,
              paybill         VARCHAR(40)  NULL,
              currency        VARCHAR(3)   NOT NULL DEFAULT 'KES',
              -- What was in the account on the day the yard started using this
              -- system. Without it every balance here is short by whatever was
              -- already in the bank.
              opening_balance DECIMAL(15,2) NOT NULL DEFAULT 0,
              opening_date    DATE NULL,
              is_default      TINYINT(1) NOT NULL DEFAULT 0,
              status          VARCHAR(12) NOT NULL DEFAULT 'active',
              notes           TEXT NULL,
              sort_order      INT NOT NULL DEFAULT 0,
              created_by      INT NULL,
              created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
              updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              KEY idx_ca_status (status, sort_order)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (\Throwable $e) { error_log('acctMigrate: ' . $e->getMessage()); }

    // One column on each table that already holds money. Nullable on purpose:
    // everything recorded before today has no account, and guessing one would
    // put money into an account it never went into.
    foreach (['payments', 'expenses', 'credit_payments', 'crm_lead_deposits'] as $t) {
        try { $db->exec("ALTER TABLE `$t` ADD COLUMN account_id INT NULL"); }
        catch (\Throwable $e) { /* already there */ }
        try { $db->exec("ALTER TABLE `$t` ADD INDEX idx_{$t}_acct (account_id)"); }
        catch (\Throwable $e) { /* already there */ }
    }

    try {
        $db->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('ACCOUNT_SCHEMA_VERSION', ?)
                      ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")
           ->execute([ACCOUNT_SCHEMA_VERSION]);
    } catch (\Throwable $e) {}
}

// ── Who may do what ──────────────────────────────────────────────────────────

/** Reading balances and statements is ordinary finance work. */
function acctCanView(): bool { return canAccess('payments') || canAccess('expenses'); }

/**
 * Opening and closing accounts is not. An account is where the company's money
 * is said to be, and somebody adding one quietly is how money goes missing on
 * paper.
 */
function acctCanManage(): bool { return hasRole('finance_manager'); }

// ── Reading ──────────────────────────────────────────────────────────────────

/** A list, or an empty one — a missing table must not take a page down. */
function acctRows(PDO $db, string $sql, array $args = []): array
{
    try {
        $st = $db->prepare($sql);
        $st->execute($args);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (\Throwable $e) {
        error_log('accounts: ' . $e->getMessage());
        return [];
    }
}

/** Every account, with what is in it. */
function acctAll(PDO $db, bool $includeClosed = false): array
{
    acctMigrate($db);

    $where = $includeClosed ? '1=1' : "a.status = 'active'";

    $rows = acctRows($db, "
        SELECT a.*,
               COALESCE(pin.total,0)  AS in_payments,
               COALESCE(cin.total,0)  AS in_credit,
               COALESCE(din.total,0)  AS in_deposits,
               COALESCE(eout.total,0) AS out_expenses,
               COALESCE(pend.total,0) AS pending_in
          FROM cash_accounts a
     LEFT JOIN (SELECT account_id, SUM(amount) total FROM payments
                 WHERE status = 'confirmed' GROUP BY account_id) pin  ON pin.account_id  = a.id
     LEFT JOIN (SELECT account_id, SUM(amount) total FROM payments
                 WHERE status = 'pending'   GROUP BY account_id) pend ON pend.account_id = a.id
     LEFT JOIN (SELECT account_id, SUM(amount) total FROM credit_payments
                 WHERE voided_at IS NULL GROUP BY account_id) cin  ON cin.account_id  = a.id
     LEFT JOIN (SELECT account_id, SUM(amount) total FROM crm_lead_deposits
                 WHERE voided_at IS NULL GROUP BY account_id) din ON din.account_id = a.id
     LEFT JOIN (SELECT account_id, SUM(amount) total FROM expenses
                GROUP BY account_id) eout ON eout.account_id = a.id
         WHERE $where
      ORDER BY a.status, a.sort_order, a.name");

    foreach ($rows as &$r) {
        $r['in']      = (float)$r['in_payments'] + (float)$r['in_credit'] + (float)$r['in_deposits'];
        $r['out']     = (float)$r['out_expenses'];
        $r['balance'] = (float)$r['opening_balance'] + $r['in'] - $r['out'];
        $r['label']   = acctLabel($r);
    }
    unset($r);

    return $rows;
}

/** One account, or null. */
function acctOne(PDO $db, int $id): ?array
{
    foreach (acctAll($db, true) as $a) {
        if ((int)$a['id'] === $id) return $a;
    }
    return null;
}

/** "Equity — 0100123456" — the name plus whatever identifies it. */
function acctLabel(array $a): string
{
    $bits = [trim((string)$a['name'])];

    if (!empty($a['bank_name']) && stripos((string)$a['name'], (string)$a['bank_name']) === false) {
        $bits[] = (string)$a['bank_name'];
    }
    if (!empty($a['account_number'])) $bits[] = (string)$a['account_number'];
    elseif (!empty($a['paybill']))    $bits[] = 'Paybill ' . $a['paybill'];

    return implode(' — ', $bits);
}

/** For the dropdowns. Active accounts only, default first. */
function acctChoices(PDO $db): array
{
    $out = [];
    foreach (acctAll($db) as $a) $out[(int)$a['id']] = $a['label'];
    return $out;
}

/** The account a form should pre-select. */
function acctDefaultId(PDO $db): int
{
    foreach (acctAll($db) as $a) {
        if ((int)$a['is_default']) return (int)$a['id'];
    }
    $all = acctAll($db);
    return $all ? (int)$all[0]['id'] : 0;
}

/**
 * A <select> for any form that records money.
 *
 * One helper rather than the markup copied into six pages, so every screen
 * offers the same accounts in the same order and calls the field the same thing.
 */
function acctSelect(PDO $db, string $name = 'account_id', int $selected = 0,
                    string $class = 'form-select', bool $required = false): string
{
    $choices = acctChoices($db);
    if (!$choices) return '';

    if ($selected <= 0) $selected = acctDefaultId($db);

    $html = '<select name="' . e($name) . '" class="' . e($class) . '"' . ($required ? ' required' : '') . '>';
    if (!$required) $html .= '<option value="">— not recorded —</option>';

    foreach ($choices as $id => $label) {
        $html .= '<option value="' . (int)$id . '"' . ((int)$id === $selected ? ' selected' : '') . '>'
               . e($label) . '</option>';
    }

    return $html . '</select>';
}

// ── The statement ────────────────────────────────────────────────────────────

/**
 * Every movement on an account over a period, oldest first, with a running
 * balance — which is what makes it a statement rather than a list.
 *
 * The opening balance is the account's own opening figure plus everything that
 * moved before the period started, so the statement reconciles: open, move,
 * close.
 *
 * @return array{account:array, opening:float, closing:float, in:float, out:float, rows:array}
 */
function acctStatement(PDO $db, int $accountId, string $from, string $to): array
{
    acctMigrate($db);

    $a = acctOne($db, $accountId);
    if (!$a) return ['account' => [], 'opening' => 0.0, 'closing' => 0.0, 'in' => 0.0, 'out' => 0.0, 'rows' => []];

    // The four sources, as one list. Written out rather than looped so each
    // keeps the description that makes sense for it — "Payment PMT-0012 from
    // Jane" reads; "movement 12" does not.
    $union = "
        SELECT p.payment_date AS moved_on, 'in' AS direction, p.amount,
               'Payment' AS kind, p.payment_number AS ref,
               COALESCE(NULLIF(p.client_name,''), cl.name, '') AS party,
               COALESCE(p.description, '') AS detail, p.payment_method AS method,
               p.id AS src_id, 'payment' AS src
          FROM payments p
     LEFT JOIN clients cl ON cl.id = p.client_id
         WHERE p.account_id = :a_p AND p.status = 'confirmed'
           AND DATE(p.payment_date) BETWEEN :f_p AND :t_p

        UNION ALL
        SELECT cp.paid_on, 'in', cp.amount,
               'Credit instalment', cp.receipt_number,
               COALESCE(cl.name, l.name, ''),
               COALESCE(ag.reference, ''), cp.method,
               cp.id, 'credit'
          FROM credit_payments cp
     LEFT JOIN credit_agreements ag ON ag.id = cp.agreement_id
     LEFT JOIN crm_leads l  ON l.id  = ag.lead_id
     LEFT JOIN clients  cl ON cl.id = COALESCE(ag.client_id, l.client_id)
         WHERE cp.voided_at IS NULL
           AND cp.account_id = :a_cp AND cp.paid_on BETWEEN :f_cp AND :t_cp

        UNION ALL
        SELECT d.deposit_date, 'in', d.amount,
               'Deposit', '', COALESCE(cl.name, l.name, ''),
               COALESCE(d.notes, ''), '',
               d.id, 'deposit'
          FROM crm_lead_deposits d
     LEFT JOIN crm_leads l  ON l.id  = d.lead_id
     LEFT JOIN clients  cl ON cl.id = l.client_id
         WHERE d.account_id = :a_d AND d.voided_at IS NULL
           AND d.deposit_date BETWEEN :f_d AND :t_d

        UNION ALL
        SELECT x.expense_date, 'out', x.amount,
               'Expense', COALESCE(x.expense_number,''), COALESCE(x.vendor,''),
               x.description, x.payment_method,
               x.id, 'expense'
          FROM expenses x
         WHERE x.account_id = :a_x AND x.expense_date BETWEEN :f_x AND :t_x
    ";

    $args = [];
    foreach (['p', 'cp', 'd', 'x'] as $k) {
        $args['a_' . $k] = $accountId;
        $args['f_' . $k] = $from;
        $args['t_' . $k] = $to;
    }
    $rows = acctRows($db, $union . ' ORDER BY moved_on ASC, src_id ASC', $args);

    // What was in it before the period opened.
    $prior = (float)(acctRows($db, "
        SELECT
          COALESCE((SELECT SUM(amount) FROM payments
                     WHERE account_id = ? AND status='confirmed' AND DATE(payment_date) < ?),0)
        + COALESCE((SELECT SUM(amount) FROM credit_payments
                     WHERE account_id = ? AND voided_at IS NULL AND paid_on < ?),0)
        + COALESCE((SELECT SUM(amount) FROM crm_lead_deposits
                     WHERE account_id = ? AND voided_at IS NULL AND deposit_date < ?),0)
        - COALESCE((SELECT SUM(amount) FROM expenses WHERE account_id = ? AND expense_date < ?),0)
          AS v", [$accountId, $from, $accountId, $from, $accountId, $from, $accountId, $from])[0]['v'] ?? 0);

    $opening = (float)$a['opening_balance'] + $prior;
    $running = $opening;
    $in = $out = 0.0;

    foreach ($rows as &$r) {
        $amt = (float)$r['amount'];
        if ($r['direction'] === 'in') { $running += $amt; $in += $amt; }
        else                          { $running -= $amt; $out += $amt; }
        $r['balance'] = round($running, 2);
    }
    unset($r);

    return ['account' => $a, 'opening' => round($opening, 2), 'closing' => round($running, 2),
            'in' => round($in, 2), 'out' => round($out, 2), 'rows' => $rows];
}

/**
 * Money recorded without an account named.
 *
 * Everything entered before accounts existed has none, which is honest — but it
 * means the accounts will not add up to the yard's total takings, and somebody
 * should be told that rather than left to wonder.
 */
function acctUnassigned(PDO $db, string $from, string $to): array
{
    acctMigrate($db);

    $n = fn (string $sql, array $args) => (float)(acctRows($db, $sql, $args)[0]['v'] ?? 0);

    $in = $n("SELECT COALESCE(SUM(amount),0) v FROM payments
               WHERE account_id IS NULL AND status='confirmed' AND DATE(payment_date) BETWEEN ? AND ?", [$from, $to])
        + $n("SELECT COALESCE(SUM(amount),0) v FROM credit_payments
               WHERE account_id IS NULL AND voided_at IS NULL AND paid_on BETWEEN ? AND ?", [$from, $to])
        + $n("SELECT COALESCE(SUM(amount),0) v FROM crm_lead_deposits
               WHERE account_id IS NULL AND voided_at IS NULL AND deposit_date BETWEEN ? AND ?", [$from, $to]);

    $out = $n("SELECT COALESCE(SUM(amount),0) v FROM expenses
                WHERE account_id IS NULL AND expense_date BETWEEN ? AND ?", [$from, $to]);

    return ['in' => $in, 'out' => $out, 'any' => ($in + $out) > 0.009];
}

/** What the company holds, across every account. */
function acctTotals(PDO $db): array
{
    $t = ['accounts' => 0, 'balance' => 0.0, 'pending' => 0.0, 'by_type' => []];

    foreach (acctAll($db) as $a) {
        $t['accounts']++;
        $t['balance'] += (float)$a['balance'];
        $t['pending'] += (float)$a['pending_in'];
        $k = (string)$a['type'];
        $t['by_type'][$k] = ($t['by_type'][$k] ?? 0) + (float)$a['balance'];
    }

    return $t;
}

/**
 * Read an account id off a form, keeping only one that exists and is open.
 *
 * Every entry point calls this rather than trusting the posted value, so a
 * closed account cannot be posted into and a stray id cannot create a phantom
 * balance nothing will ever reconcile.
 */
function acctFromRequest(PDO $db, string $field = 'account_id'): ?int
{
    $id = (int)($_POST[$field] ?? 0);
    if ($id <= 0) return null;

    foreach (acctAll($db) as $a) {
        if ((int)$a['id'] === $id) return $id;
    }
    return null;
}

} // function_exists('acctMigrate')

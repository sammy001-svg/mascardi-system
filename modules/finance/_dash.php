<?php
/**
 * The figures behind the finance dashboard.
 *
 * The old dashboard answered everything: cash flow, expense categories,
 * deposits held, supplier commitments, payment methods. Finance did not read
 * most of it, because the question finance actually arrives with every morning
 * is narrower and more urgent — who owes us money on credit, how much came in
 * this month, and who has stopped paying. This answers that and stops.
 *
 * Every date comparison is done by MySQL. PHP here runs on UTC and MySQL on
 * EAT, so "overdue" worked out in PHP is overdue three hours early or late
 * depending on which way you cross, and an instalment due today reads as
 * yesterday's problem.
 */

require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/_credit.php';

/** A single number, or zero — a dashboard must not die on a missing table. */
function dashNum(PDO $db, string $sql, array $args = []): float
{
    try {
        $st = $db->prepare($sql);
        $st->execute($args);
        return (float)($st->fetchColumn() ?: 0);
    } catch (\Throwable $e) {
        error_log('dashNum: ' . $e->getMessage());
        return 0.0;
    }
}

/** A list, or an empty one. */
function dashRows(PDO $db, string $sql, array $args = []): array
{
    try {
        $st = $db->prepare($sql);
        $st->execute($args);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (\Throwable $e) {
        error_log('dashRows: ' . $e->getMessage());
        return [];
    }
}

/** Agreements that are still being paid. Cancelled ones are not a receivable. */
const DASH_LIVE = "ca.status NOT IN ('cancelled','completed')";

/**
 * What is coming due but is not late yet.
 *
 * Kept apart from the overdue figure on purpose: one is a plan and the other is
 * a problem, and a dashboard that adds them together tells finance it is in
 * more trouble than it is.
 */
function dashDueSoon(PDO $db, int $days = 7): array
{
    $sql = "SELECT COALESCE(SUM(ci.amount - ci.amount_paid),0) AS amount,
                   COUNT(*) AS n,
                   COUNT(DISTINCT ci.agreement_id) AS accounts
              FROM credit_installments ci
              JOIN credit_agreements ca ON ca.id = ci.agreement_id
             WHERE " . DASH_LIVE . "
               AND ci.amount_paid < ci.amount
               AND ci.due_date >= CURDATE()
               AND ci.due_date <= DATE_ADD(CURDATE(), INTERVAL ? DAY)";
    $r = dashRows($db, $sql, [$days]);
    $r = $r[0] ?? [];
    return ['amount'   => (float)($r['amount'] ?? 0),
            'count'    => (int)($r['n'] ?? 0),
            'accounts' => (int)($r['accounts'] ?? 0),
            'days'     => $days];
}

/** What is late. */
function dashOverdue(PDO $db): array
{
    $sql = "SELECT COALESCE(SUM(ci.amount - ci.amount_paid),0) AS amount,
                   COUNT(*) AS n,
                   COUNT(DISTINCT ci.agreement_id) AS accounts,
                   COALESCE(MAX(DATEDIFF(CURDATE(), ci.due_date)),0) AS worst
              FROM credit_installments ci
              JOIN credit_agreements ca ON ca.id = ci.agreement_id
             WHERE " . DASH_LIVE . "
               AND ci.amount_paid < ci.amount
               AND ci.due_date < CURDATE()";
    $r = dashRows($db, $sql);
    $r = $r[0] ?? [];
    return ['amount'   => (float)($r['amount'] ?? 0),
            'count'    => (int)($r['n'] ?? 0),
            'accounts' => (int)($r['accounts'] ?? 0),
            'worst'    => (int)($r['worst'] ?? 0)];
}

/** Money actually received this month, and the month before it to compare. */
function dashCollected(PDO $db): array
{
    $now = dashNum($db, "SELECT COALESCE(SUM(amount),0) FROM credit_payments
                          WHERE voided_at IS NULL
                            AND DATE_FORMAT(paid_on,'%Y-%m') = DATE_FORMAT(CURDATE(),'%Y-%m')");
    $was = dashNum($db, "SELECT COALESCE(SUM(amount),0) FROM credit_payments
                          WHERE voided_at IS NULL
                            AND DATE_FORMAT(paid_on,'%Y-%m')
                                = DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 1 MONTH),'%Y-%m')");
    $n = (int)dashNum($db, "SELECT COUNT(*) FROM credit_payments
                             WHERE voided_at IS NULL
                               AND DATE_FORMAT(paid_on,'%Y-%m') = DATE_FORMAT(CURDATE(),'%Y-%m')");
    return ['amount' => $now, 'last_month' => $was, 'payments' => $n,
            'delta'  => $was > 0 ? ($now - $was) / $was * 100 : null];
}

/** The whole book: what is still owed across every live agreement. */
function dashOutstanding(PDO $db): array
{
    $sql = "SELECT COALESCE(SUM(ci.amount - ci.amount_paid),0) AS owed,
                   COUNT(DISTINCT ca.id) AS accounts
              FROM credit_agreements ca
              LEFT JOIN credit_installments ci
                     ON ci.agreement_id = ca.id AND ci.amount_paid < ci.amount
             WHERE " . DASH_LIVE;
    $r = dashRows($db, $sql);
    $r = $r[0] ?? [];
    return ['amount' => (float)($r['owed'] ?? 0), 'accounts' => (int)($r['accounts'] ?? 0)];
}

/**
 * Twelve months of the book: what came in, and what was due in that month and
 * still has not been paid.
 *
 * Two measures of the same unit, so one axis. "Still owed" is deliberately the
 * shortfall on that month's own instalments rather than the whole arrears
 * balance, because the question the chart answers is "how did each month do",
 * not "how bad is it now" — the tiles above already say that.
 */
function dashMonths(PDO $db, int $months = 12): array
{
    $out = [];
    $rows = dashRows($db, "
        SELECT DATE_FORMAT(d.m,'%Y-%m') AS ym,
               DATE_FORMAT(d.m,'%b') AS label,
               COALESCE((SELECT SUM(p.amount) FROM credit_payments p
                          WHERE p.voided_at IS NULL
                            AND DATE_FORMAT(p.paid_on,'%Y-%m') = DATE_FORMAT(d.m,'%Y-%m')),0) AS collected,
               COALESCE((SELECT SUM(ci.amount - ci.amount_paid)
                           FROM credit_installments ci
                           JOIN credit_agreements ca ON ca.id = ci.agreement_id
                          WHERE " . DASH_LIVE . "
                            AND ci.amount_paid < ci.amount
                            AND DATE_FORMAT(ci.due_date,'%Y-%m') = DATE_FORMAT(d.m,'%Y-%m')),0) AS owed
          FROM (
            SELECT DATE_SUB(DATE_FORMAT(CURDATE(),'%Y-%m-01'), INTERVAL seq MONTH) AS m
              FROM (SELECT 0 AS seq UNION SELECT 1 UNION SELECT 2 UNION SELECT 3
                    UNION SELECT 4 UNION SELECT 5 UNION SELECT 6 UNION SELECT 7
                    UNION SELECT 8 UNION SELECT 9 UNION SELECT 10 UNION SELECT 11) s
          ) d
         WHERE d.m >= DATE_SUB(DATE_FORMAT(CURDATE(),'%Y-%m-01'), INTERVAL ? MONTH)
      ORDER BY d.m ASC", [max(1, $months - 1)]);

    foreach ($rows as $r) {
        $out[] = ['ym'        => (string)$r['ym'],
                  'label'     => (string)$r['label'],
                  'collected' => (float)$r['collected'],
                  'owed'      => (float)$r['owed']];
    }
    return $out;
}

/**
 * How late the arrears are, in bands.
 *
 * Ordered magnitude, so the chart draws it on one hue getting darker. The bands
 * are the ones a yard chases by: this week's slip, a month behind, a quarter
 * behind, and gone.
 */
function dashAgeing(PDO $db): array
{
    $r = dashRows($db, "
        SELECT
          COALESCE(SUM(CASE WHEN late BETWEEN 1 AND 30   THEN owed END),0) AS b1,
          COALESCE(SUM(CASE WHEN late BETWEEN 31 AND 60  THEN owed END),0) AS b2,
          COALESCE(SUM(CASE WHEN late BETWEEN 61 AND 90  THEN owed END),0) AS b3,
          COALESCE(SUM(CASE WHEN late > 90               THEN owed END),0) AS b4,
          COUNT(CASE WHEN late BETWEEN 1 AND 30  THEN 1 END) AS n1,
          COUNT(CASE WHEN late BETWEEN 31 AND 60 THEN 1 END) AS n2,
          COUNT(CASE WHEN late BETWEEN 61 AND 90 THEN 1 END) AS n3,
          COUNT(CASE WHEN late > 90              THEN 1 END) AS n4
        FROM (
          SELECT DATEDIFF(CURDATE(), ci.due_date) AS late,
                 (ci.amount - ci.amount_paid)     AS owed
            FROM credit_installments ci
            JOIN credit_agreements ca ON ca.id = ci.agreement_id
           WHERE " . DASH_LIVE . "
             AND ci.amount_paid < ci.amount
             AND ci.due_date < CURDATE()
        ) x");
    $r = $r[0] ?? [];
    return [
        ['label' => '1–30 days',  'amount' => (float)($r['b1'] ?? 0), 'count' => (int)($r['n1'] ?? 0)],
        ['label' => '31–60 days', 'amount' => (float)($r['b2'] ?? 0), 'count' => (int)($r['n2'] ?? 0)],
        ['label' => '61–90 days', 'amount' => (float)($r['b3'] ?? 0), 'count' => (int)($r['n3'] ?? 0)],
        ['label' => 'Over 90',    'amount' => (float)($r['b4'] ?? 0), 'count' => (int)($r['n4'] ?? 0)],
    ];
}

/**
 * Where every credit account stands.
 *
 * State, not magnitude, so the chart uses the reserved status colours and every
 * bar carries its own label — a reader must never have to work out which
 * colour meant "with lawyers".
 */
function dashStanding(PDO $db): array
{
    $rows = dashRows($db, "
        SELECT ca.id, ca.status,
               COALESCE(SUM(ci.amount - ci.amount_paid),0) AS balance,
               COALESCE(SUM(CASE WHEN ci.due_date < CURDATE() AND ci.amount_paid < ci.amount
                                 THEN ci.amount - ci.amount_paid END),0) AS overdue_amount,
               MIN(CASE WHEN ci.amount_paid < ci.amount THEN ci.due_date END) AS next_due
          FROM credit_agreements ca
          LEFT JOIN credit_installments ci ON ci.agreement_id = ca.id
         WHERE ca.status <> 'cancelled'
      GROUP BY ca.id, ca.status");

    $today = (string)($db->query("SELECT CURDATE()")->fetchColumn() ?: date('Y-m-d'));
    $buckets = [
        'on_schedule' => ['label' => 'On schedule',  'n' => 0, 'tone' => 'neutral'],
        'due_soon'    => ['label' => 'Due soon',     'n' => 0, 'tone' => 'warning'],
        'overdue'     => ['label' => 'Overdue',      'n' => 0, 'tone' => 'critical'],
        'legal'       => ['label' => 'With lawyers', 'n' => 0, 'tone' => 'legal'],
        'cleared'     => ['label' => 'Cleared',      'n' => 0, 'tone' => 'good'],
    ];
    foreach ($rows as $r) {
        $k = creditStanding($r, $today)['key'];
        if ($k === 'defaulted') $k = 'overdue';
        if (isset($buckets[$k])) $buckets[$k]['n']++;
    }
    return $buckets;
}

/**
 * The credit clients who owe the most, and whether they are behind.
 *
 * Magnitude across entities, so one hue and no legend — the title names the
 * single thing being measured. Capped, because a dashboard is a glance and the
 * full list is what Receivables is for.
 */
function dashTopClients(PDO $db, int $limit = 8): array
{
    return dashRows($db, "
        SELECT ca.id,
               COALESCE(NULLIF(TRIM(cl.name),''), NULLIF(TRIM(l.name),''), ca.reference) AS name,
               COALESCE(SUM(ci.amount - ci.amount_paid),0) AS owed,
               COALESCE(SUM(CASE WHEN ci.due_date < CURDATE() AND ci.amount_paid < ci.amount
                                 THEN ci.amount - ci.amount_paid END),0) AS overdue
          FROM credit_agreements ca
          LEFT JOIN credit_installments ci ON ci.agreement_id = ca.id AND ci.amount_paid < ci.amount
          LEFT JOIN crm_leads l ON l.id = ca.lead_id
          LEFT JOIN clients  cl ON cl.id = ca.client_id
         WHERE " . DASH_LIVE . "
      GROUP BY ca.id, name
        HAVING owed > 0.009
      ORDER BY owed DESC
         LIMIT " . max(1, min(20, $limit)));
}

/** Short money, for a tile. */
function dashShort(float $v): string
{
    $a = abs($v);
    if ($a >= 1000000) return 'KES ' . rtrim(rtrim(number_format($v / 1000000, 1), '0'), '.') . 'M';
    if ($a >= 1000)    return 'KES ' . number_format($v / 1000) . 'K';
    return 'KES ' . number_format($v);
}

<?php
/**
 * The numbers the finance portal runs on, in one place.
 *
 * The dashboard, the sidebar badges and anything added later all read from
 * here, so that a figure means the same thing wherever it appears. A receivable
 * counted one way on the dashboard and another on a badge is how people stop
 * believing the screen.
 *
 * Every figure is defined once, in SQL, against the tables that actually hold
 * the money:
 *
 *   payments   what came in, and only where status = 'confirmed' — a payment
 *              somebody has keyed in but not yet confirmed is not cash.
 *   expenses   what went out.
 *   invoices   what is owed to us: total - amount_paid, while unpaid or partial.
 *   crm_leads  deposits held against cars not yet delivered. That is somebody
 *              else's money sitting in our account, and it belongs on the
 *              screen even though nothing else in the system calls it a
 *              liability.
 *   lpo        what we have committed to buy and not yet received.
 *
 * Dates are compared in SQL rather than in PHP throughout, because PHP runs UTC
 * on this host and MySQL runs EAT — "today" answered in PHP is three hours out,
 * which on a day's takings is the difference between right and wrong.
 *
 * Nothing here throws. A yard that has never recorded an expense has no
 * expenses table until the migration is run, and a dashboard that dies on that
 * is worse than one showing a zero.
 */

require_once __DIR__ . '/../../includes/functions.php';

if (!function_exists('finNum')) {

/** One number, or zero if the table is not there yet. */
function finNum(PDO $db, string $sql, array $args = []): float
{
    try {
        $st = $db->prepare($sql);
        $st->execute($args);

        return (float)($st->fetchColumn() ?: 0);
    } catch (\Throwable $e) {
        return 0.0;
    }
}

/** One list, or an empty one. */
function finRows(PDO $db, string $sql, array $args = []): array
{
    try {
        $st = $db->prepare($sql);
        $st->execute($args);

        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (\Throwable $e) {
        return [];
    }
}

/**
 * Money in and money out over a period, and what is left.
 *
 * @return array{in:float, out:float, net:float}
 */
function finCashFlow(PDO $db, string $from, string $to): array
{
    $in = finNum($db, "SELECT COALESCE(SUM(amount),0) FROM payments
                        WHERE status = 'confirmed'
                          AND DATE(payment_date) BETWEEN ? AND ?", [$from, $to]);

    $out = finNum($db, "SELECT COALESCE(SUM(amount),0) FROM expenses
                         WHERE DATE(expense_date) BETWEEN ? AND ?", [$from, $to]);

    return ['in' => $in, 'out' => $out, 'net' => $in - $out];
}

/** What came in, split by how it arrived. */
function finByMethod(PDO $db, string $from, string $to): array
{
    $rows = finRows($db, "SELECT payment_method, COALESCE(SUM(amount),0) AS total, COUNT(*) AS n
                            FROM payments
                           WHERE status = 'confirmed'
                             AND DATE(payment_date) BETWEEN ? AND ?
                        GROUP BY payment_method
                        ORDER BY total DESC", [$from, $to]);

    $out = [];
    foreach ($rows as $r) {
        $out[(string)$r['payment_method']] = ['total' => (float)$r['total'], 'n' => (int)$r['n']];
    }

    return $out;
}

/**
 * What is owed to us, by how long it has been owed.
 *
 * Aged from the due date where there is one and the invoice date where there is
 * not, because an invoice with no due date is due now — treating it as never
 * due would quietly keep the worst debts out of the oldest bucket.
 */
function finReceivables(PDO $db): array
{
    $sql = "SELECT
              COALESCE(SUM(GREATEST(total - COALESCE(amount_paid,0), 0)), 0) AS owed,
              COUNT(*) AS n,
              COALESCE(SUM(CASE WHEN COALESCE(due_date, date) >= CURDATE()
                                THEN GREATEST(total - COALESCE(amount_paid,0), 0) ELSE 0 END), 0) AS current,
              COALESCE(SUM(CASE WHEN COALESCE(due_date, date) <  CURDATE()
                            AND COALESCE(due_date, date) >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                                THEN GREATEST(total - COALESCE(amount_paid,0), 0) ELSE 0 END), 0) AS d30,
              COALESCE(SUM(CASE WHEN COALESCE(due_date, date) <  DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                            AND COALESCE(due_date, date) >= DATE_SUB(CURDATE(), INTERVAL 60 DAY)
                                THEN GREATEST(total - COALESCE(amount_paid,0), 0) ELSE 0 END), 0) AS d60,
              COALESCE(SUM(CASE WHEN COALESCE(due_date, date) <  DATE_SUB(CURDATE(), INTERVAL 60 DAY)
                                THEN GREATEST(total - COALESCE(amount_paid,0), 0) ELSE 0 END), 0) AS d90
            FROM invoices
           WHERE status IN ('unpaid','partial')";

    $r = finRows($db, $sql);
    $r = $r[0] ?? [];

    return [
        'owed'    => (float)($r['owed']    ?? 0),
        'count'   => (int)  ($r['n']       ?? 0),
        'current' => (float)($r['current'] ?? 0),
        'd30'     => (float)($r['d30']     ?? 0),
        'd60'     => (float)($r['d60']     ?? 0),
        'd90'     => (float)($r['d90']     ?? 0),
    ];
}

/** The invoices behind that, worst first. */
function finOverdueInvoices(PDO $db, int $limit = 12): array
{
    return finRows($db, "SELECT i.id, i.invoice_number, i.customer_name, i.date, i.due_date,
                                i.total, COALESCE(i.amount_paid,0) AS amount_paid,
                                GREATEST(i.total - COALESCE(i.amount_paid,0), 0) AS owed,
                                DATEDIFF(CURDATE(), COALESCE(i.due_date, i.date)) AS days_over
                           FROM invoices i
                          WHERE i.status IN ('unpaid','partial')
                            AND COALESCE(i.due_date, i.date) < CURDATE()
                       ORDER BY days_over DESC, owed DESC
                          LIMIT " . max(1, min(50, $limit)));
}

/** Instalments that should have been paid and have not been. */
function finArrears(PDO $db, int $limit = 12): array
{
    return finRows($db, "SELECT si.id, si.due_date, si.amount_due,
                                COALESCE(si.amount_paid,0) AS amount_paid,
                                GREATEST(si.amount_due - COALESCE(si.amount_paid,0), 0) AS owed,
                                DATEDIFF(CURDATE(), si.due_date) AS days_over,
                                si.plan_id
                           FROM sale_installments si
                          WHERE si.due_date < CURDATE()
                            AND COALESCE(si.amount_paid,0) < si.amount_due
                            AND COALESCE(si.status,'') <> 'cancelled'
                       ORDER BY si.due_date ASC
                          LIMIT " . max(1, min(50, $limit)));
}

/**
 * Deposits taken against cars that have not been handed over.
 *
 * Not revenue. It is the customer's money until the car is theirs, and if a
 * reservation falls through it goes back. Finance ought to be able to see how
 * much of the bank balance is spoken for.
 */
function finDepositsHeld(PDO $db): array
{
    $initial = finNum($db, "SELECT COALESCE(SUM(deposit_amount),0) FROM crm_leads
                             WHERE stage = 'reserved'");

    // Voided top-ups are not money we hold. A deposit that was reversed and
    // still counted here would overstate the liability by exactly the amount
    // somebody has already been given back.
    $extra = finNum($db, "SELECT COALESCE(SUM(d.amount),0)
                            FROM crm_lead_deposits d
                            JOIN crm_leads l ON l.id = d.lead_id
                           WHERE l.stage = 'reserved'
                             AND d.voided_at IS NULL");

    $n = (int)finNum($db, "SELECT COUNT(*) FROM crm_leads WHERE stage = 'reserved'");

    return ['total' => $initial + $extra, 'count' => $n];
}

/** Ordered and not yet received — money already committed. */
function finCommitments(PDO $db): array
{
    return [
        'total' => finNum($db, "SELECT COALESCE(SUM(total),0) FROM lpo
                                 WHERE status IN ('sent','acknowledged','partial')"),
        'count' => (int)finNum($db, "SELECT COUNT(*) FROM lpo
                                      WHERE status IN ('sent','acknowledged','partial')"),
    ];
}

/**
 * Payments keyed in but not confirmed.
 *
 * This is the one number on the dashboard that is a job rather than a fact:
 * until somebody confirms it, it is not counted as cash anywhere, so a pile of
 * these means the day's takings on screen are wrong and nobody knows it.
 */
function finPendingPayments(PDO $db): array
{
    return [
        'total' => finNum($db, "SELECT COALESCE(SUM(amount),0) FROM payments WHERE status = 'pending'"),
        'count' => (int)finNum($db, "SELECT COUNT(*) FROM payments WHERE status = 'pending'"),
    ];
}

/** Money in and out, month by month, for the chart. */
function finMonthly(PDO $db, int $months = 12): array
{
    $in = finRows($db, "SELECT DATE_FORMAT(payment_date,'%Y-%m') AS k, COALESCE(SUM(amount),0) AS v
                          FROM payments
                         WHERE status = 'confirmed'
                           AND payment_date >= DATE_SUB(DATE_FORMAT(CURDATE(),'%Y-%m-01'), INTERVAL ? MONTH)
                      GROUP BY k", [$months - 1]);

    $out = finRows($db, "SELECT DATE_FORMAT(expense_date,'%Y-%m') AS k, COALESCE(SUM(amount),0) AS v
                           FROM expenses
                          WHERE expense_date >= DATE_SUB(DATE_FORMAT(CURDATE(),'%Y-%m-01'), INTERVAL ? MONTH)
                       GROUP BY k", [$months - 1]);

    $inBy  = array_column($in,  'v', 'k');
    $outBy = array_column($out, 'v', 'k');

    // The months themselves come from MySQL too, so the series cannot drift
    // from the figures by a timezone.
    $keys = finRows($db, "SELECT DATE_FORMAT(DATE_SUB(DATE_FORMAT(CURDATE(),'%Y-%m-01'),
                                 INTERVAL n MONTH), '%Y-%m') AS k,
                                 DATE_FORMAT(DATE_SUB(DATE_FORMAT(CURDATE(),'%Y-%m-01'),
                                 INTERVAL n MONTH), '%b %y') AS label
                            FROM (SELECT 0 n UNION SELECT 1 UNION SELECT 2 UNION SELECT 3
                                  UNION SELECT 4 UNION SELECT 5 UNION SELECT 6 UNION SELECT 7
                                  UNION SELECT 8 UNION SELECT 9 UNION SELECT 10 UNION SELECT 11) m
                           WHERE n < ?
                        ORDER BY k ASC", [$months]);

    $series = [];
    foreach ($keys as $k) {
        $series[] = [
            'label' => $k['label'],
            'in'    => (float)($inBy[$k['k']]  ?? 0),
            'out'   => (float)($outBy[$k['k']] ?? 0),
        ];
    }

    return $series;
}

/** Where the money went, this period. */
function finExpenseCategories(PDO $db, string $from, string $to, int $limit = 8): array
{
    return finRows($db, "SELECT COALESCE(NULLIF(category,''),'other') AS category,
                                COALESCE(SUM(amount),0) AS total, COUNT(*) AS n
                           FROM expenses
                          WHERE DATE(expense_date) BETWEEN ? AND ?
                       GROUP BY category
                       ORDER BY total DESC
                          LIMIT " . max(1, min(20, $limit)), [$from, $to]);
}

// ── Comparison ───────────────────────────────────────────────────────────────

/**
 * The window immediately before this one, of the same length.
 *
 * A figure on its own says almost nothing in finance: 625,000 in is good or bad
 * entirely depending on what the month before did. Every tile therefore carries
 * a delta, and this is what it is measured against — the same number of days,
 * ending the day before the current window starts, so a part-month compares
 * against the same part of the month before rather than against a whole one.
 *
 * @return array{from:string, to:string, label:string}
 */
function finPrevPeriod(PDO $db, string $from, string $to): array
{
    $r = finRows($db, "SELECT DATE_SUB(?, INTERVAL DATEDIFF(?, ?) + 1 DAY) AS f,
                              DATE_SUB(?, INTERVAL 1 DAY) AS t",
                 [$from, $to, $from, $from]);

    return [
        'from'  => (string)($r[0]['f'] ?? $from),
        'to'    => (string)($r[0]['t'] ?? $from),
        'label' => 'the previous period',
    ];
}

/**
 * How a figure moved, as a proportion.
 *
 * Returns null where there is nothing to compare against, because "up 100%"
 * from zero is not information — it is a division dressed up as a trend, and
 * showing it makes every first month look like a triumph.
 *
 * @return array{pct:?float, dir:string, prev:float}
 */
function finDelta(float $now, float $before): array
{
    if (abs($before) < 0.01) {
        return ['pct' => null, 'dir' => $now > 0 ? 'up' : 'flat', 'prev' => $before];
    }

    $pct = ($now - $before) / abs($before) * 100;

    return [
        'pct'  => $pct,
        'dir'  => abs($pct) < 0.5 ? 'flat' : ($pct > 0 ? 'up' : 'down'),
        'prev' => $before,
    ];
}

/**
 * A short series for a tile's sparkline — one point per day across the window,
 * or per month where the window is long enough that days would be noise.
 */
function finSpark(PDO $db, string $what, string $from, string $to): array
{
    $days = (int)finNum($db, 'SELECT DATEDIFF(?, ?) + 1', [$to, $from]);
    $byMonth = $days > 62;

    [$table, $dateCol, $where] = $what === 'out'
        ? ['expenses', 'expense_date', '1=1']
        : ['payments', 'payment_date', "status = 'confirmed'"];

    $fmt = $byMonth ? '%Y-%m' : '%Y-%m-%d';

    $rows = finRows($db, "SELECT DATE_FORMAT($dateCol, '$fmt') AS k, COALESCE(SUM(amount),0) AS v
                            FROM $table
                           WHERE $where AND DATE($dateCol) BETWEEN ? AND ?
                        GROUP BY k ORDER BY k ASC", [$from, $to]);

    return array_map(static fn ($r) => (float)$r['v'], $rows);
}

/**
 * A date range in words, for the sentence under the hero figure.
 *
 * "against 500,000 over the 28 days before" reads; "against 500,000 over
 * 2026-08-04 to 2026-08-31" does not, and the exact dates are on the period
 * buttons anyway.
 */
function finRangeWords(PDO $db, string $from, string $to): string
{
    $days = (int)finNum($db, 'SELECT DATEDIFF(?, ?) + 1', [$to, $from]);

    if ($days <= 1)  return 'day';
    if ($days === 7) return 'week';
    if ($days >= 28 && $days <= 31) return 'month';
    if ($days >= 365) return 'year';

    return $days . ' days';
}

/**
 * KES in the space a tile has.
 *
 * 1,080,000.00 is unreadable at tile size and pushes the delta off the end, so
 * big numbers become 1.08M. The full figure is never lost — it is on the tile's
 * own title attribute and in the table views underneath the charts.
 */
function finShort(float $v): string
{
    $sign = $v < 0 ? '-' : '';
    $a    = abs($v);

    if ($a >= 1000000) return $sign . rtrim(rtrim(number_format($a / 1000000, 2, '.', ''), '0'), '.') . 'M';
    if ($a >= 1000)    return $sign . rtrim(rtrim(number_format($a / 1000, 1, '.', ''), '0'), '.') . 'K';

    return $sign . number_format($a);
}

/**
 * Who may see the company-wide money.
 *
 * The same gate the existing financial report uses, deliberately. This screen
 * shows the cash position, everything owed to us and everything we are holding
 * — that is a management view, not a consequence of being able to raise an
 * invoice. Gating it on 'payments' or 'invoices' would have handed the whole
 * picture to anyone who takes a deposit, which is most of the sales floor and
 * the workshop manager besides.
 *
 * A cashier therefore does not land here; their menu starts at Payments, which
 * is the work they actually do.
 */
function finCanUse(): bool
{
    return canAccess('reports');
}

} // function_exists('finNum')

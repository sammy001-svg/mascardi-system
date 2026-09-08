<?php
/**
 * The monthly delivery report — the sheet the sales meeting actually runs on.
 *
 * This mirrors the spreadsheet the yard already keeps by hand: one row per
 * delivered vehicle, the money on the left, who sold it and to whom on the
 * right, and a commentary column for the deals that need explaining. The point
 * of building it here is that the hand-kept version goes stale the moment a
 * deal changes, and nobody ever remembers to update both.
 *
 * The page and the Excel download share this file on purpose. A report that
 * disagrees with its own export is worse than having no export, because the
 * disagreement is only discovered in a meeting.
 */

if (!function_exists('deliveredPaymentTypes')) {

/** How the yard gets paid. Taken from the sheet as it is actually filled in. */
function deliveredPaymentTypes(): array
{
    return ['Cash', 'Credit', 'Small Credit', 'Bank Finance', 'Hire Purchase', 'Trade-In'];
}

/**
 * Two columns the sheet has and the system did not.
 *
 * Payment type and the commentary were only ever in the spreadsheet, which is
 * why the spreadsheet kept being maintained by hand. They live on the lead now
 * and are edited straight from the report, because reviewing the month is when
 * somebody actually knows what to write.
 */
function deliveredReportEnsure(PDO $db): bool
{
    static $done = null;
    if ($done !== null) return $done;
    foreach ([
        "ALTER TABLE crm_leads ADD COLUMN payment_type    VARCHAR(30)  NULL DEFAULT NULL",
        "ALTER TABLE crm_leads ADD COLUMN sale_commentary VARCHAR(255) NULL DEFAULT NULL",
    ] as $sql) { try { $db->exec($sql); } catch (\Throwable $_) {} }
    return $done = true;
}

/** The months that actually have deliveries, newest first — not a blind last-12. */
function deliveredReportMonths(PDO $db, string $scopeWhere = ''): array
{
    try {
        $rows = $db->query("
            SELECT DATE_FORMAT(COALESCE(l.delivered_at, l.converted_at, l.updated_at), '%Y-%m') AS ym,
                   COUNT(*) AS n
              FROM crm_leads l
             WHERE l.stage = 'delivered' $scopeWhere
          GROUP BY ym
          ORDER BY ym DESC
        ")->fetchAll(PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {
        error_log('deliveredReportMonths: ' . $e->getMessage());
        return [];
    }
    $out = [];
    foreach ($rows as $r) {
        if (!$r['ym']) continue;
        $out[$r['ym']] = [
            'label' => (new DateTime($r['ym'] . '-01'))->format('F Y'),
            'count' => (int)$r['n'],
        ];
    }
    return $out;
}

/**
 * Which month the report is showing, decided once for the page and the export.
 *
 * They disagreed before this existed: asked for a month with nothing in it, the
 * page quietly showed every delivery ever made while the download showed an empty
 * sheet. "Nothing was delivered in February" is an answer, and a report that
 * substitutes a different question when it does not like yours cannot be trusted
 * on the months it does answer.
 *
 * @param array $months  from deliveredReportMonths(), newest first
 * @return string        'YYYY-MM', or 'all', or '' when there is nothing at all
 */
function deliveredReportMonth(array $get, array $months): string
{
    // No month named at all: open on the most recent one that has deliveries.
    if (!isset($get['month'])) return (string)(array_key_first($months) ?? '');

    $m = trim((string)$get['month']);
    if ($m === '' || $m === 'all') return 'all';
    // Only a malformed value falls back. A well-formed month with no rows stands.
    return preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $m) ? $m : 'all';
}

/** A month key as a person reads it. */
function deliveredReportMonthLabel(string $ym): string
{
    if ($ym === '' || $ym === 'all') return 'All months';
    try { return (new DateTime($ym . '-01'))->format('F Y'); }
    catch (\Throwable $e) { return $ym; }
}

/**
 * One row per delivered vehicle, in the sheet's own column order.
 *
 * @param array $f  month (YYYY-MM), make, agent, q — all optional
 */
function deliveredReportRows(PDO $db, array $f = [], string $scopeWhere = ''): array
{
    deliveredReportEnsure($db);

    $where  = ["l.stage = 'delivered'"];
    $params = [];
    if (!empty($f['month'])) {
        // Compared in SQL: PHP runs UTC here and the database runs EAT, so a
        // month worked out in PHP puts the first three hours of the 1st in the
        // previous month's report.
        $where[]  = "DATE_FORMAT(COALESCE(l.delivered_at, l.converted_at, l.updated_at), '%Y-%m') = ?";
        $params[] = $f['month'];
    }
    if (!empty($f['make']))  { $where[] = 'c.make = ?';        $params[] = $f['make']; }
    if (!empty($f['agent'])) { $where[] = 'l.assigned_to = ?'; $params[] = (int)$f['agent']; }
    if (!empty($f['q'])) {
        $s = '%' . $f['q'] . '%';
        $where[] = '(l.name LIKE ? OR cl.name LIKE ? OR c.make LIKE ? OR c.model LIKE ?'
                 . ' OR c.registration_number LIKE ?)';
        array_push($params, $s, $s, $s, $s, $s);
    }
    $sql = implode(' AND ', $where) . ' ' . $scopeWhere;

    try {
        $st = $db->prepare("
            SELECT l.id AS lead_id,
                   l.name AS lead_name,
                   l.agreed_sale_price,
                   l.payment_type,
                   l.sale_commentary,
                   l.import_vehicle_details,
                   COALESCE(l.delivered_at, l.converted_at, l.updated_at) AS sold_at,
                   c.id AS car_id, c.make, c.model, c.year,
                   c.registration_number, c.mileage,
                   c.asking_price, c.offer_price,
                   cl.name AS client_name,
                   u.name  AS agent_name
              FROM crm_leads l
         LEFT JOIN cars    c  ON c.id  = l.pinned_car_id
         LEFT JOIN clients cl ON cl.id = l.client_id
         LEFT JOIN users   u  ON u.id  = l.assigned_to
             WHERE $sql
          -- Newest day first, but within a day in the order they were entered, which
          -- is how the sheet reads. Reversing the tie-break shuffles same-day sales.
          ORDER BY COALESCE(l.delivered_at, l.converted_at, l.updated_at) DESC, l.id ASC
        ");
        $st->execute($params);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {
        error_log('deliveredReportRows: ' . $e->getMessage());
        return [];
    }
}

/**
 * The vehicle as the sheet names it.
 *
 * An imported car that never made it into the fleet has no cars row, only the
 * free text typed on the lead — so it still gets a line rather than a blank.
 */
function deliveredReportVehicle(array $r): array
{
    if (!empty($r['make']) || !empty($r['model'])) {
        return [
            'make'  => (string)($r['make'] ?? ''),
            'model' => (string)($r['model'] ?? ''),
            'year'  => $r['year'] ? (string)(int)$r['year'] : '',
            'reg'   => (string)($r['registration_number'] ?? ''),
        ];
    }
    $txt = trim((string)($r['import_vehicle_details'] ?? ''));
    if ($txt === '') return ['make' => '', 'model' => '', 'year' => '', 'reg' => ''];
    $bits = preg_split('/\s+/', $txt, 3);
    $year = (isset($bits[0]) && preg_match('/^(19|20)\d{2}$/', $bits[0])) ? array_shift($bits) : '';
    return [
        'make'  => $bits[0] ?? '',
        'model' => trim(implode(' ', array_slice($bits, 1))),
        'year'  => $year,
        'reg'   => '',
    ];
}

/** The final figure, falling back the way the yard does when nothing was agreed. */
function deliveredReportFinal(array $r): float
{
    $v = (float)($r['agreed_sale_price'] ?? 0);
    if ($v > 0) return $v;
    $v = (float)($r['offer_price'] ?? 0);
    return $v > 0 ? $v : 0.0;
}

/** The list price the car was advertised at. */
function deliveredReportList(array $r): float
{
    return (float)($r['asking_price'] ?? 0);
}

/**
 * Why a row is coloured, in the sheet's own conventions.
 *
 * Pink for a record with a hole in it — no mileage, no list price or no final
 * figure — because those are the rows somebody has to go and chase. Red text
 * where nobody recorded how the customer paid. Both are stated in words in the
 * legend as well as in colour, so the report survives being photocopied.
 *
 * @return array{incomplete:bool, no_payment:bool, reasons:string[]}
 */
function deliveredReportFlags(array $r): array
{
    $v = deliveredReportVehicle($r);
    $reasons = [];
    if (!(float)($r['mileage'] ?? 0))   $reasons[] = 'no mileage';
    if (!deliveredReportList($r))       $reasons[] = 'no list price';
    if (!deliveredReportFinal($r))      $reasons[] = 'no final price';
    if ($v['reg'] === '')               $reasons[] = 'no registration';

    return [
        'incomplete' => (bool)$reasons,
        'no_payment' => trim((string)($r['payment_type'] ?? '')) === '',
        'reasons'    => $reasons,
    ];
}

/**
 * Where a value sits between the smallest and largest in the set, 0..1.
 *
 * Used for the green shading on mileage and on the final price. Returned as one
 * of four steps rather than a continuous gradient: a printed report cannot show
 * a hundred shades apart, and four is as many as anyone reads.
 */
function deliveredReportShade(float $value, float $min, float $max, bool $invert = false): int
{
    if ($value <= 0 || $max <= $min) return 0;
    $t = ($value - $min) / ($max - $min);
    if ($invert) $t = 1 - $t;
    return (int)max(1, min(4, 1 + floor($t * 3.999)));
}

/** The smallest and largest of a column, ignoring the blanks. */
function deliveredReportRange(array $rows, callable $get): array
{
    $vals = [];
    foreach ($rows as $r) { $v = (float)$get($r); if ($v > 0) $vals[] = $v; }
    return $vals ? [min($vals), max($vals)] : [0.0, 0.0];
}

} // function_exists('deliveredPaymentTypes')

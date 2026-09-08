<?php
/**
 * Monthly delivery report — the download.
 *
 * A real .xlsx, written by includes/xlsx.php. It used to be SpreadsheetML with a
 * .xls extension, which carried the formatting but made every modern Excel ask
 * "the file format and extension don't match — the file could be corrupted or
 * unsafe", and which Google Sheets and most phone viewers refuse outright.
 * People were being taught to click through a security warning to read their own
 * sales report.
 *
 * Everything about which rows appear and how they are marked comes from
 * _report.php, the same file the page uses. A download that disagrees with the
 * screen is only ever discovered in a meeting.
 *
 * Figures are written as numbers, never as pre-formatted text: the first thing
 * anyone does with this file is put a total under a column.
 */

require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/xlsx.php';
require_once __DIR__ . '/_report.php';
requireLogin();

if (!canAccess('crm')) { http_response_code(403); exit('Access denied'); }

$db  = getDB();
$me  = authUser();
$uid = (int)$me['id'];
$isCrmAgent = ($me['role'] === 'customer_relations');
$canFilter  = in_array($me['role'], ['admin', 'super_admin', 'general_manager'], true);
$scopeWhere = $isCrmAgent ? "AND l.assigned_to = $uid" : '';

deliveredReportEnsure($db);

$month   = deliveredReportMonth($_GET, deliveredReportMonths($db, $scopeWhere));
$filters = [
    'month' => $month === 'all' ? '' : $month,
    'make'  => trim($_GET['make'] ?? ''),
    'agent' => $canFilter ? (int)($_GET['agent'] ?? 0) : 0,
    'q'     => trim($_GET['q'] ?? ''),
];
$rows = deliveredReportRows($db, $filters, $scopeWhere);

[$milMin, $milMax]   = deliveredReportRange($rows, fn($r) => (float)($r['mileage'] ?? 0));
[$sellMin, $sellMax] = deliveredReportRange($rows, 'deliveredReportFinal');

$periodLabel = deliveredReportMonthLabel($month);

// ── The look, declared once ─────────────────────────────────────────────────
$green = ['E8F6EE', 'C9EAD6', 'A3DCBE', '79CBA4'];
$PINK  = 'F7E4F5';
$defs  = [
    'hdr'  => ['fill' => '0F6B5C', 'color' => 'FFFFFF', 'bold' => true,
               'align' => 'center', 'wrap' => true],
    'idx'  => ['align' => 'center', 'color' => '6B7280'],
    'txt'  => [],
    'na'   => ['italic' => true, 'color' => '9CA3AF', 'align' => 'center'],
    'num'  => ['fmt' => '#,##0', 'align' => 'right'],
    'date' => ['align' => 'center'],
    // Pink wins over the green scale: a hole in the record is the more urgent
    // thing to see, and two fills on one cell is not a choice you get.
    'hole'    => ['fill' => $PINK],
    'holeNum' => ['fill' => $PINK, 'fmt' => '#,##0', 'align' => 'right'],
    'holeNa'  => ['fill' => $PINK, 'italic' => true, 'color' => '9CA3AF', 'align' => 'center'],
    'red'     => ['color' => 'C0392B', 'bold' => true],
    'redHole' => ['color' => 'C0392B', 'bold' => true, 'fill' => $PINK],
    'ttl'  => ['bold' => true, 'fill' => 'EEF2F7', 'fmt' => '#,##0',
               'align' => 'right', 'top' => '0F6B5C'],
    'ttlL' => ['bold' => true, 'fill' => 'EEF2F7', 'align' => 'right', 'top' => '0F6B5C'],
    'key'  => ['color' => '6B7280', 'italic' => true],
];
// One hue, four steps, light to dark — the same scale the page uses.
foreach ($green as $i => $c) {
    $defs['g'  . ($i + 1)] = ['fill' => $c, 'fmt' => '#,##0', 'align' => 'right'];
    $defs['gb' . ($i + 1)] = ['fill' => $c, 'fmt' => '#,##0', 'align' => 'right', 'bold' => true];
}

$cols   = ['#', 'Vehicle Make', 'Model', 'Model Year', 'Registration', 'Mileage (Kms)',
           'List Price (KES)', 'Final Sale Price (KES)', 'Sales PI / Salesperson',
           'Date Sold/Delivered', 'Payment Type', 'Customer Name', 'Commentary'];
$widths = [5, 15, 22, 10, 13, 13, 15, 18, 20, 15, 13, 28, 40];

$sheet = [array_map(fn($c) => xlsxText($c, 'hdr'), $cols)];

$total = 0.0;
foreach ($rows as $i => $r) {
    $v     = deliveredReportVehicle($r);
    $fl    = deliveredReportFlags($r);
    $final = deliveredReportFinal($r);
    $list  = deliveredReportList($r);
    $mil   = (float)($r['mileage'] ?? 0);
    $total += $final;
    $hole  = $fl['incomplete'];

    $sTxt = $hole ? 'hole'    : 'txt';
    $sNum = $hole ? 'holeNum' : 'num';
    $sNa  = $hole ? 'holeNa'  : 'na';
    $sMil = $mil   > 0 ? ($hole ? 'holeNum' : 'g'  . deliveredReportShade($mil, $milMin, $milMax, true)) : $sNa;
    $sSel = $final > 0 ? ($hole ? 'holeNum' : 'gb' . deliveredReportShade($final, $sellMin, $sellMax)) : $sNa;
    $sPay = $fl['no_payment'] ? ($hole ? 'redHole' : 'red') : $sTxt;
    $cust = trim((string)($r['client_name'] ?? '')) ?: trim((string)($r['lead_name'] ?? ''));

    $sheet[] = [
        xlsxText((string)($i + 1), $hole ? 'hole' : 'idx'),
        $v['make']  !== '' ? xlsxText($v['make'],  $sTxt) : xlsxText('N/A', $sNa),
        $v['model'] !== '' ? xlsxText($v['model'], $sTxt) : xlsxText('N/A', $sNa),
        $v['year']  !== '' ? xlsxNumber((float)$v['year'], $hole ? 'hole' : 'date')
                           : xlsxText('N/A', $sNa),
        $v['reg']   !== '' ? xlsxText($v['reg'],   $sTxt) : xlsxText('N/A', $sNa),
        $mil   > 0 ? xlsxNumber($mil,   $sMil) : xlsxText('N/A', $sNa),
        $list  > 0 ? xlsxNumber($list,  $sNum) : xlsxText('N/A', $sNa),
        $final > 0 ? xlsxNumber($final, $sSel) : xlsxText('N/A', $sNa),
        xlsxText((string)($r['agent_name'] ?? ''), $sTxt),
        xlsxText($r['sold_at'] ? (new DateTime($r['sold_at']))->format('j-M-y') : '',
                 $hole ? 'hole' : 'date'),
        xlsxText((string)($r['payment_type'] ?? '') ?: 'N/A', $sPay),
        xlsxText($cust, $sTxt),
        xlsxText((string)($r['sale_commentary'] ?? ''), $sTxt),
    ];
}

if ($rows) {
    $sheet[] = [
        xlsxMerge('Total for ' . $periodLabel, 6, 'ttlL'),
        xlsxNumber($total, 'ttl'),
        xlsxMerge(count($rows) . ' ' . (count($rows) === 1 ? 'vehicle' : 'vehicles'), 4, 'ttlL'),
    ];
}

$sheet[] = [xlsxText('', 'txt')];
$sheet[] = [xlsxText('Greener mileage = lower. Greener sale price = higher. '
    . 'A pink row is missing something on the record. Red payment type = not recorded.', 'key')];
$sheet[] = [xlsxText('Generated ' . date('j M Y, H:i') . ' by ' . ($me['name'] ?? ''), 'key')];

$path = xlsxWrite($periodLabel, $sheet, $widths, $defs);
if ($path === null) {
    http_response_code(500);
    error_log('delivery export: the workbook could not be written');
    exit('The report could not be built, so nothing was downloaded. Please try again.');
}

// ASCII only. A non-ASCII Content-Disposition filename is handled differently by
// every browser, and the one that gets it wrong saves the header text as the name.
xlsxSend($path, 'Mascardi Deliveries - ' . $periodLabel . '.xlsx');

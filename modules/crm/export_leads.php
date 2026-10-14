<?php
/**
 * The leads list as an .xlsx.
 *
 * Whatever is on screen is what comes down: the filters, the scope rules and
 * the ordering all come from _leads_query.php, the same file the page uses. A
 * download that disagrees with the list it was taken from is only ever
 * discovered in a meeting.
 *
 * The header is row one and nothing is put above it, because that is what
 * xlsxWrite() attaches the filter dropdowns to — the whole point of handing
 * somebody a spreadsheet rather than a PDF is that they can slice it again
 * themselves. The run of filters that produced the file is written underneath
 * the data instead, where it cannot break the filter range.
 *
 * Figures go down as numbers, never as pre-formatted text: the first thing
 * anybody does with this file is put a total under a column.
 */

require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/xlsx.php';
require_once __DIR__ . '/_leads_query.php';
requireLogin();
canAccess('crm') || exit('Access denied');

$db  = getDB();
$me  = authUser();

$supLocId = (function_exists('supervisorLocationId') && ($me['role'] ?? '') === 'supervisor')
    ? supervisorLocationId() : null;
$scope = leadsScope($me, $supLocId);

// The same filters the page is showing, remembered set included, so the
// Export button does not need to carry them in its href to stay honest.
$f    = leadsStickyFilters($_GET, $scope);
$rows = leadsRows($db, $f, $scope, true);

$stages  = leadsStages();
$sources = leadsSources();

// ── The look, declared once ─────────────────────────────────────────────────
$defs = [
    'hdr'  => ['fill' => '0F6B5C', 'color' => 'FFFFFF', 'bold' => true,
               'align' => 'center', 'wrap' => true],
    'txt'  => [],
    'ctr'  => ['align' => 'center'],
    'na'   => ['italic' => true, 'color' => '9CA3AF', 'align' => 'center'],
    'num'  => ['fmt' => '#,##0', 'align' => 'right'],
    // An overdue follow-up is the one thing in this file somebody is meant to
    // act on today, so it is the one thing that is coloured.
    'late' => ['color' => 'C0392B', 'bold' => true, 'align' => 'center'],
    'key'  => ['italic' => true, 'color' => '6B7280'],
    'ttlL' => ['bold' => true, 'fill' => 'EEF2F7', 'align' => 'right', 'top' => '0F6B5C'],
    'ttl'  => ['bold' => true, 'fill' => 'EEF2F7', 'fmt' => '#,##0',
               'align' => 'right', 'top' => '0F6B5C'],
];

$cols = [
    'Name'            => 26,
    'Phone'           => 15,
    'Email'           => 24,
    'Stage'           => 12,
    'Source'          => 13,
    'Campaign'        => 18,
    'Interested in'   => 26,
    'Budget'          => 14,
    'Score'           => 8,
    'Assigned to'     => 20,
    'Follow-up'       => 13,
    'Vehicle'         => 26,
    'Registration'    => 14,
    'Agreed price'    => 15,
    'Deposit'         => 14,
    'Client'          => 22,
    'Entered'         => 13,
    'Last touched'    => 13,
    'Notes'           => 40,
];

$sheet  = [];
$header = [];
foreach (array_keys($cols) as $label) $header[] = xlsxText($label, 'hdr');
$sheet[] = $header;

$today      = (string)$db->query('SELECT CURDATE()')->fetchColumn();
$todayTs    = strtotime($today);
$sumBudget  = 0.0;
$sumAgreed  = 0.0;
$sumDeposit = 0.0;

/** A date as people read it, or a dash. */
$d = static function (?string $v, string $style = 'ctr') {
    if (!$v || $v === '0000-00-00' || strtotime($v) === false) return xlsxText('—', 'na');
    return xlsxText(date('j M Y', strtotime($v)), $style);
};
/** A figure, or a dash — never a nought standing in for "not recorded". */
$n = static function ($v) {
    $f = (float)$v;
    return $f > 0 ? xlsxNumber(round($f, 2), 'num') : xlsxText('—', 'na');
};
$t = static function (?string $v) {
    $v = trim((string)$v);
    return $v === '' ? xlsxText('—', 'na') : xlsxText($v, 'txt');
};

foreach ($rows as $r) {
    $sumBudget  += (float)($r['budget'] ?? 0);
    $sumAgreed  += (float)($r['agreed_sale_price'] ?? 0);
    $sumDeposit += (float)($r['deposit_amount'] ?? 0);

    // Overdue in the same sense the list means it: a date gone by, on a lead
    // that is still live.
    $fu      = (string)($r['follow_up_date'] ?? '');
    $overdue = $fu !== '' && strtotime($fu) !== false && strtotime($fu) < $todayTs
             && !in_array((string)$r['stage'], ['lost', 'delivered'], true);

    $vehicle = trim(implode(' ', array_filter([
        (string)($r['year'] ?? ''), (string)($r['make'] ?? ''), (string)($r['model'] ?? ''),
    ])));
    if ($vehicle === '') $vehicle = (string)($r['interested_in'] ?? '');

    $sheet[] = [
        $t($r['name'] ?? ''),
        $t($r['phone'] ?? ''),
        $t($r['email'] ?? ''),
        xlsxText($stages[$r['stage']][0] ?? ucfirst((string)$r['stage']), 'ctr'),
        xlsxText($sources[$r['source']] ?? ucfirst(str_replace('_', ' ', (string)$r['source'])), 'ctr'),
        $t($r['campaign'] ?? ''),
        $t($r['interested_in'] ?? ''),
        $n($r['budget'] ?? 0),
        xlsxNumber((float)($r['lead_score'] ?? 0), 'ctr'),
        $t($r['assigned_name'] ?? ''),
        $d($fu, $overdue ? 'late' : 'ctr'),
        $t($vehicle),
        $t($r['registration_number'] ?? ''),
        $n($r['agreed_sale_price'] ?? 0),
        $n($r['deposit_amount'] ?? 0),
        $t($r['client_name'] ?? ''),
        $d($r['created_at'] ?? null),
        $d($r['updated_at'] ?? null),
        $t($r['notes'] ?? ''),
    ];
}

if ($rows) {
    // Totals under the columns people total. Left of them, a count, so the
    // figure is never read without knowing how many leads it came from.
    // The merge spans the columns to its right, so 6 covers Name..Interested in
    // and Budget lands in its own column. Getting this off by one puts every
    // total under the wrong heading, which is worse than having no totals.
    $sheet[] = [
        xlsxMerge(count($rows) . ' ' . (count($rows) === 1 ? 'lead' : 'leads'), 6, 'ttlL'),
        $sumBudget  > 0 ? xlsxNumber(round($sumBudget, 2), 'ttl')  : xlsxText('', 'ttlL'),
        // Score, Assigned to, Follow-up, Vehicle, Registration — nothing to total.
        xlsxText('', 'ttlL'), xlsxText('', 'ttlL'), xlsxText('', 'ttlL'),
        xlsxText('', 'ttlL'), xlsxText('', 'ttlL'),
        $sumAgreed  > 0 ? xlsxNumber(round($sumAgreed, 2), 'ttl')  : xlsxText('', 'ttlL'),
        $sumDeposit > 0 ? xlsxNumber(round($sumDeposit, 2), 'ttl') : xlsxText('', 'ttlL'),
    ];
}

// ── What produced this file ─────────────────────────────────────────────────
// Below the data, not above it: the filter dropdowns are attached to row one,
// and anything put on top of the header takes them with it.
$said = [];
if ($f['q'] !== '')         $said[] = 'search "' . $f['q'] . '"';
if ($f['stage'] !== '')     $said[] = 'stage ' . ($stages[$f['stage']][0] ?? $f['stage']);
if ($f['source'] !== '')    $said[] = 'source ' . ($sources[$f['source']] ?? $f['source']);
if ($f['campaign'] !== '')  $said[] = 'campaign ' . $f['campaign'];
if ($f['date_from'] !== '') $said[] = 'entered from ' . date('j M Y', strtotime($f['date_from']));
if ($f['date_to'] !== '')   $said[] = 'entered to ' . date('j M Y', strtotime($f['date_to']));
if (!$scope['is_agent'] && (int)$f['assigned'] > 0) {
    $st = $db->prepare('SELECT name FROM users WHERE id = ?');
    $st->execute([(int)$f['assigned']]);
    $said[] = 'assigned to ' . ((string)$st->fetchColumn() ?: '#' . (int)$f['assigned']);
}

$sheet[] = [xlsxText('', 'txt')];
$sheet[] = [xlsxText($said ? 'Filtered by ' . implode(', ', $said) : 'No filters — every lead in view.', 'key')];
$sheet[] = [xlsxText('A red follow-up date is overdue. A dash means nothing was recorded.', 'key')];
$sheet[] = [xlsxText('Generated ' . date('j M Y, H:i') . ' by ' . ($me['name'] ?? ''), 'key')];

$path = xlsxWrite('Leads', $sheet, array_values($cols), $defs);
if ($path === null) {
    http_response_code(500);
    error_log('leads export: the workbook could not be written');
    exit('The export could not be built, so nothing was downloaded. Please try again.');
}

// ASCII only in the filename: a non-ASCII Content-Disposition name is handled
// differently by every browser, and the one that gets it wrong saves the
// header text as the name.
xlsxSend($path, 'Mascardi Leads - ' . date('j M Y') . '.xlsx');

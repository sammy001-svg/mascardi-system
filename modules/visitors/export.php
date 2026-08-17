<?php
/**
 * Visitors — CSV export of the current filter.
 *
 * Takes the same purpose/range/search parameters as the log, so what downloads is
 * what is on screen.
 */
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/_bootstrap.php';
requireLogin();
canAccess('visitors') || redirect(BASE_URL . '/index.php');

$db = getDB();
visitorsMigrate($db);

$purpose = (string)($_GET['purpose'] ?? '');
$range   = in_array($_GET['range'] ?? '', ['today','week','month','all'], true) ? $_GET['range'] : 'month';
$search  = trim($_GET['q'] ?? '');

$where = ['1'];
$args  = [];
if (isset(visitorPurposes()[$purpose])) { $where[] = 'v.purpose = ?'; $args[] = $purpose; }
if ($range === 'today')     $where[] = 'DATE(v.created_at) = CURDATE()';
elseif ($range === 'week')  $where[] = 'YEARWEEK(v.created_at, 1) = YEARWEEK(CURDATE(), 1)';
elseif ($range === 'month') $where[] = "v.created_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')";
if ($search !== '') {
    $where[] = '(CONCAT_WS(" ", v.first_name, v.middle_name, v.last_name) LIKE ?
                 OR v.phone LIKE ? OR v.id_number LIKE ? OR v.email LIKE ?)';
    $s = "%{$search}%";
    array_push($args, $s, $s, $s, $s);
}

$rows = [];
try {
    $st = $db->prepare("
        SELECT v.*, u.name AS staff_name, a.name AS officer_name, r.name AS recorded_by_name,
               TRIM(CONCAT_WS(' ', c.year, c.make, c.model)) AS car_label
        FROM visitors v
        LEFT JOIN users u ON u.id = v.staff_id
        LEFT JOIN users a ON a.id = v.assigned_to
        LEFT JOIN users r ON r.id = v.recorded_by
        LEFT JOIN cars  c ON c.id = v.car_id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY v.created_at DESC");
    $st->execute($args);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (\Throwable $_) {}

$name = 'visitors-' . $range . ($purpose ? '-' . $purpose : '') . '-' . date('Y-m-d') . '.csv';

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $name . '"');
header('Cache-Control: no-store');

$out = fopen('php://output', 'w');
// BOM so Excel opens UTF-8 names correctly instead of mangling them.
fwrite($out, "\xEF\xBB\xBF");

fputcsv($out, [
    'Date', 'Time in', 'Time out', 'Minutes on site',
    'First name', 'Second name', 'Last name', 'Phone', 'ID number', 'Email',
    'Heard about us', 'Purpose',
    'Vehicle of interest', 'Comment',
    'Service vehicle', 'Registration', 'Year', 'Mileage', 'Service notes',
    'Came to see', 'Reason',
    'Lead ref', 'Assigned to', 'Client ref', 'Signed in by',
]);

foreach ($rows as $v) {
    $in  = strtotime($v['created_at']);
    $out2 = $v['checked_out_at'] ? strtotime($v['checked_out_at']) : null;
    fputcsv($out, [
        date('Y-m-d', $in),
        date('H:i', $in),
        $out2 ? date('H:i', $out2) : '',
        $out2 ? (int)round(($out2 - $in) / 60) : '',
        $v['first_name'], $v['middle_name'], $v['last_name'],
        $v['phone'], $v['id_number'], $v['email'],
        $v['heard_from'],
        visitorPurposes()[$v['purpose']][0] ?? $v['purpose'],
        $v['car_label'], $v['buy_comment'],
        trim(($v['svc_make'] ?? '') . ' ' . ($v['svc_model'] ?? '')),
        $v['svc_reg'], $v['svc_year'], $v['svc_mileage'], $v['svc_notes'],
        $v['staff_name'], $v['visit_reason'],
        $v['lead_id'] ? 'Lead #' . $v['lead_id'] : '',
        $v['officer_name'],
        $v['client_id'] ? 'Client #' . $v['client_id'] : '',
        $v['recorded_by_name'],
    ]);
}
fclose($out);

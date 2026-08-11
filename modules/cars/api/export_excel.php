<?php
/**
 * Cars Inventory — Excel/CSV Export
 * Exports all Mascardi Inventory cars to an Excel-compatible CSV.
 * Respects the same make / location filters as the inventory DataTable.
 */
require_once __DIR__ . '/../../../includes/functions.php';
requireLogin();

if (!canAccess('cars')) {
    http_response_code(403);
    exit('Access denied');
}

$db = getDB();

// ── Filters (same as list.php) ───────────────────────────────────────────────
$filterMake     = trim($_GET['filter_make']     ?? '');
$filterLocation = (int)($_GET['filter_location'] ?? 0);

// Supervisor location scope
$supLocId = supervisorLocationId();
if ($supLocId) $filterLocation = $supLocId;

// ── Base WHERE: inventory only (not delivered/sold) ──────────────────────────
$baseWhere = "c.car_type = 'inventory' AND (c.status IS NULL OR c.status NOT IN ('delivered','sold'))";

$filterWhere  = '';
$filterParams = [];
if ($filterMake !== '') {
    $filterWhere   .= ' AND c.make = ?';
    $filterParams[] = $filterMake;
}
if ($filterLocation > 0) {
    $filterWhere   .= ' AND c.location_id = ?';
    $filterParams[] = $filterLocation;
}

$fullWhere = $baseWhere . $filterWhere;

// ── Query — all columns useful for export ────────────────────────────────────
$sql = "
    SELECT
        c.id,
        c.make,
        c.model,
        c.year,
        c.color,
        c.registration_number,
        c.chassis_number,
        c.engine_number,
        c.engine_cc,
        c.mileage,
        c.asking_price,
        c.offer_price,
        c.status,
        IFNULL(l.name, '') AS location_name,
        c.car_type,
        c.show_on_website,
        c.created_at
    FROM cars c
    LEFT JOIN locations l ON l.id = c.location_id
    WHERE {$fullWhere}
    ORDER BY c.make ASC, c.model ASC
";

$stmt = $db->prepare($sql);
$stmt->execute($filterParams);
$rows = $stmt->fetchAll();

// ── Status label map ─────────────────────────────────────────────────────────
$statusLabels = [
    'in_transit'     => 'In Transit',
    'arrived'        => 'Arrived',
    'in_assessment'  => 'In Assessment',
    'in_workshop'    => 'In Workshop',
    'completed'      => 'Completed',
    'reserved'       => 'Reserved',
    'sold'           => 'Sold',
    'delivered'      => 'Delivered',
];

// ── Output as UTF-8 CSV (Excel opens correctly with BOM) ─────────────────────
$filename = 'Mascardi_Inventory_' . date('Y-m-d') . '.csv';

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');

$out = fopen('php://output', 'w');

// UTF-8 BOM so Excel recognises the encoding immediately
fwrite($out, "\xEF\xBB\xBF");

// ── Header row ───────────────────────────────────────────────────────────────
fputcsv($out, [
    '#',
    'Make',
    'Model',
    'Year',
    'Color',
    'Registration No.',
    'Chassis No.',
    'Engine No.',
    'Engine CC',
    'Mileage (km)',
    'Asking Price (KES)',
    'Offer / Sale Price (KES)',
    'Status',
    'Location',
    'On Website',
    'Date Added',
]);

// ── Data rows ────────────────────────────────────────────────────────────────
$i = 1;
foreach ($rows as $car) {
    fputcsv($out, [
        $i++,
        $car['make'],
        $car['model'],
        $car['year'],
        $car['color'],
        $car['registration_number'],
        $car['chassis_number'],
        $car['engine_number'],
        $car['engine_cc'] ? $car['engine_cc'] . ' cc' : '',
        $car['mileage']   ? number_format((int)$car['mileage']) : '',
        $car['asking_price'] > 0 ? number_format((float)$car['asking_price'], 2) : '',
        ($car['offer_price'] !== null && (float)$car['offer_price'] > 0)
            ? number_format((float)$car['offer_price'], 2) : '',
        $statusLabels[$car['status']] ?? ucfirst(str_replace('_', ' ', (string)$car['status'])),
        $car['location_name'],
        $car['show_on_website'] ? 'Yes' : 'No',
        $car['created_at'] ? date('d/m/Y', strtotime($car['created_at'])) : '',
    ]);
}

fclose($out);
exit;

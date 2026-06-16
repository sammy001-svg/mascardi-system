<?php
require_once __DIR__ . '/../../../includes/functions.php';
header('Content-Type: application/json');

if (!isLoggedIn()) { echo json_encode(['cars' => []]); exit; }
if (!canAccess('crm')) { http_response_code(403); echo json_encode(['cars' => []]); exit; }

$q = trim($_GET['q'] ?? '');
if (strlen($q) < 2) { echo json_encode(['cars' => []]); exit; }

try {
    $db = getDB();
    // Try common column names — cars table may use different status values
    $like = "%{$q}%";
    $cars = $db->prepare("
        SELECT id,
               COALESCE(registration_number, reg_number, plate, '') AS reg,
               COALESCE(make, brand, '') AS make,
               COALESCE(model, '') AS model,
               COALESCE(year, '') AS year,
               COALESCE(selling_price, price, 0) AS price,
               COALESCE(status, '') AS status
        FROM cars
        WHERE (make LIKE ? OR model LIKE ? OR registration_number LIKE ?
               OR reg_number LIKE ? OR plate LIKE ?)
          AND status IN ('available','in_stock','for_sale','active','new','used')
        LIMIT 10
    ");
    $cars->execute([$like,$like,$like,$like,$like]);
    $rows = $cars->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['cars' => $rows]);
} catch (\Throwable $e) {
    // Fallback query without status filter if column names differ
    try {
        $cars = $db->prepare("SELECT id, registration_number AS reg, make, model, year, selling_price AS price FROM cars WHERE make LIKE ? OR model LIKE ? OR registration_number LIKE ? LIMIT 10");
        $cars->execute([$like,$like,$like]);
        echo json_encode(['cars' => $cars->fetchAll(PDO::FETCH_ASSOC)]);
    } catch (\Throwable $e2) {
        echo json_encode(['cars' => [], 'error' => 'Car search unavailable']);
    }
}

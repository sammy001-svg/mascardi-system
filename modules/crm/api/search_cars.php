<?php
require_once __DIR__ . '/../../../includes/functions.php';
header('Content-Type: application/json');

if (!isLoggedIn()) { echo json_encode(['cars' => []]); exit; }
if (!canAccess('crm')) { http_response_code(403); echo json_encode(['cars' => []]); exit; }

$q = trim($_GET['q'] ?? '');
if (strlen($q) < 2) { echo json_encode(['cars' => []]); exit; }

try {
    $db   = getDB();
    $like = "%{$q}%";

    $cars = $db->prepare("
        SELECT
            c.id,
            COALESCE(c.registration_number, c.reg_number, c.plate, '') AS reg,
            COALESCE(c.make, c.brand, '')                              AS make,
            COALESCE(c.model, '')                                      AS model,
            COALESCE(c.year, '')                                       AS year,
            COALESCE(c.color, '')                                      AS color,
            COALESCE(c.selling_price, c.price, 0)                     AS price,
            COALESCE(c.status, '')                                     AS status,
            COALESCE(c.transmission, '')                               AS transmission,
            COALESCE(c.fuel_type, '')                                  AS fuel_type,
            COALESCE(c.mileage, 0)                                     AS mileage,
            COALESCE(c.body_type, '')                                  AS body_type,
            COALESCE(c.engine_capacity, c.engine_size, '')             AS engine,
            ci.file_path                                               AS primary_image
        FROM cars c
        LEFT JOIN car_images ci ON ci.car_id = c.id AND ci.is_primary = 1
        WHERE (
                c.make             LIKE ? OR c.model LIKE ?
             OR c.registration_number LIKE ? OR c.reg_number LIKE ? OR c.plate LIKE ?
             OR CONCAT(COALESCE(c.year,''), ' ', COALESCE(c.make,''), ' ', COALESCE(c.model,'')) LIKE ?
             )
          AND c.status IN ('available','in_stock','for_sale','active','new','used','completed','arrived')
        GROUP BY c.id
        ORDER BY c.make, c.model
        LIMIT 12
    ");
    $cars->execute([$like, $like, $like, $like, $like, $like]);
    echo json_encode(['cars' => $cars->fetchAll(PDO::FETCH_ASSOC)]);

} catch (\Throwable $e) {
    // Fallback: simpler query without status filter or image join
    try {
        $like = "%{$q}%";
        $stmt = $db->prepare("
            SELECT c.id,
                   c.registration_number AS reg,
                   c.make, c.model, c.year, c.color,
                   c.selling_price AS price, c.status,
                   c.transmission, c.fuel_type, c.mileage, c.body_type,
                   NULL AS primary_image
            FROM cars c
            WHERE c.make LIKE ? OR c.model LIKE ? OR c.registration_number LIKE ?
            LIMIT 12
        ");
        $stmt->execute([$like, $like, $like]);
        echo json_encode(['cars' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    } catch (\Throwable $e2) {
        echo json_encode(['cars' => [], 'error' => 'Car search unavailable']);
    }
}

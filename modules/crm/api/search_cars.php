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

    $stmt = $db->prepare("
        SELECT
            c.id,
            c.registration_number                               AS reg,
            c.make,
            c.model,
            c.year,
            c.color,
            c.asking_price                                      AS price,
            c.status,
            c.transmission,
            c.fuel_type,
            c.mileage,
            c.body_type,
            c.engine_cc                                         AS engine,
            c.car_type,
            (SELECT ci.file_path
               FROM car_images ci
              WHERE ci.car_id = c.id AND ci.is_primary = 1
              LIMIT 1)                                          AS primary_image
        FROM cars c
        -- Consignment stock (trade-in / sale on behalf) is sellable too, but was
        -- excluded here, so those vehicles could not be linked to a lead at all.
        -- Delivered/sold units stay out: they are no longer available to sell.
        WHERE c.car_type IN ('inventory','trade_in','sale_on_behalf')
          AND (c.status IS NULL OR c.status NOT IN ('delivered','sold'))
          AND (
                c.make                LIKE ?
             OR c.model               LIKE ?
             OR c.registration_number LIKE ?
             OR c.chassis_number      LIKE ?
             OR CONCAT(COALESCE(c.year,''), ' ', c.make, ' ', c.model) LIKE ?
              )
        ORDER BY c.make, c.model
        LIMIT 15
    ");
    $stmt->execute([$like, $like, $like, $like, $like]);
    echo json_encode(['cars' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);

} catch (\Throwable $e) {
    echo json_encode(['cars' => [], 'error' => $e->getMessage()]);
}

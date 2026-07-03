<?php
/**
 * Stock Take API
 * GET  ?location_id=X  — cars at location + total count
 * POST {location_id, date, time, confirmed_ids[], notes} — save stock take record
 */
require_once __DIR__ . '/../../../includes/functions.php';
requireLogin();

if (!canAccess('cars')) {
    http_response_code(403);
    echo json_encode(['error' => 'Access denied']);
    exit;
}

header('Content-Type: application/json; charset=utf-8');
$db = getDB();

// Auto-create tables on first use
try {
    $db->exec("CREATE TABLE IF NOT EXISTS stock_takes (
        id              INT AUTO_INCREMENT PRIMARY KEY,
        location_id     INT NULL,
        location_name   VARCHAR(150) NULL,
        take_date       DATE NOT NULL,
        take_time       TIME NOT NULL,
        conducted_by    INT NOT NULL,
        total_in_system INT NOT NULL DEFAULT 0,
        total_confirmed INT NOT NULL DEFAULT 0,
        notes           TEXT NULL,
        created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_st_location (location_id),
        INDEX idx_st_date (take_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $db->exec("CREATE TABLE IF NOT EXISTS stock_take_items (
        id            INT AUTO_INCREMENT PRIMARY KEY,
        stock_take_id INT NOT NULL,
        car_id        INT NOT NULL,
        confirmed     TINYINT(1) NOT NULL DEFAULT 0,
        INDEX idx_sti_take (stock_take_id),
        INDEX idx_sti_car  (car_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (\Throwable $_) {}

$method = $_SERVER['REQUEST_METHOD'];

// ── GET: fetch all cars at a given location ───────────────────────────────────
if ($method === 'GET') {
    $locationId = (int)($_GET['location_id'] ?? 0);

    if (!$locationId) {
        echo json_encode(['cars' => [], 'location' => null, 'total' => 0]);
        exit;
    }

    $loc = $db->prepare("SELECT id, name FROM locations WHERE id = ?");
    $loc->execute([$locationId]);
    $location = $loc->fetch(PDO::FETCH_ASSOC);

    if (!$location) {
        echo json_encode(['cars' => [], 'location' => null, 'total' => 0]);
        exit;
    }

    $cars = $db->prepare("
        SELECT c.id, c.make, c.model, c.year, c.registration_number,
               c.chassis_number, c.color, c.status, c.car_type
        FROM   cars c
        WHERE  c.location_id = ?
        ORDER  BY c.make ASC, c.model ASC, c.year DESC
    ");
    $cars->execute([$locationId]);
    $carList = $cars->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['cars' => $carList, 'location' => $location, 'total' => count($carList)]);
    exit;
}

// ── POST: save stock take record ──────────────────────────────────────────────
if ($method === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true) ?? [];

    $locationId   = (int)($body['location_id']   ?? 0);
    $date         = trim($body['date']            ?? '');
    $time         = trim($body['time']            ?? '');
    $confirmedIds = array_filter(array_map('intval', (array)($body['confirmed_ids'] ?? [])));
    $notes        = trim($body['notes']           ?? '');
    $me           = authUser();

    if (!$locationId || !$date || !$time) {
        http_response_code(400);
        echo json_encode(['error' => 'Location, date and time are required']);
        exit;
    }

    $loc = $db->prepare("SELECT id, name FROM locations WHERE id = ?");
    $loc->execute([$locationId]);
    $location = $loc->fetch(PDO::FETCH_ASSOC);

    if (!$location) {
        http_response_code(404);
        echo json_encode(['error' => 'Location not found']);
        exit;
    }

    $totalStmt = $db->prepare("SELECT COUNT(*) FROM cars WHERE location_id = ?");
    $totalStmt->execute([$locationId]);
    $totalInSystem = (int)$totalStmt->fetchColumn();

    try {
        $db->beginTransaction();

        $db->prepare("
            INSERT INTO stock_takes
                (location_id, location_name, take_date, take_time,
                 conducted_by, total_in_system, total_confirmed, notes)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ")->execute([
            $locationId, $location['name'], $date, $time,
            $me['id'], $totalInSystem, count($confirmedIds), $notes
        ]);
        $stId = (int)$db->lastInsertId();

        if ($stId && $confirmedIds) {
            $ins = $db->prepare(
                "INSERT INTO stock_take_items (stock_take_id, car_id, confirmed) VALUES (?, ?, 1)"
            );
            foreach ($confirmedIds as $cid) {
                if ($cid > 0) $ins->execute([$stId, $cid]);
            }
        }

        $db->commit();
        echo json_encode(['success' => true, 'id' => $stId]);
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);

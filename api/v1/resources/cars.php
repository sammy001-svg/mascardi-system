<?php
/** GET /api/v1/cars  or  GET /api/v1/cars/{id} */

$db = getDB();

if ($id) {
    $stmt = $db->prepare("SELECT c.*, IFNULL(pl.name, l.name) AS location_name FROM cars c LEFT JOIN locations l ON l.id = c.location_id LEFT JOIN locations pl ON pl.id = l.parent_id WHERE c.id = ?");
    $stmt->execute([$id]);
    $car = $stmt->fetch();
    if (!$car) apiError(404, "Car #{$id} not found.");

    // Include images
    $imgs = $db->prepare("SELECT file_path, is_primary FROM car_images WHERE car_id = ? ORDER BY is_primary DESC");
    $imgs->execute([$id]);
    $car['images'] = $imgs->fetchAll();

    apiResponse($car);
}

// List with filters
$where  = ['1=1'];
$params = [];

$status = $_GET['status'] ?? '';
$make   = $_GET['make']   ?? '';
$search = $_GET['search'] ?? '';
$page   = max(1, (int)($_GET['page'] ?? 1));
$limit  = min(100, max(1, (int)($_GET['per_page'] ?? 20)));
$offset = ($page - 1) * $limit;

if ($status) { $where[] = 'c.status = ?';  $params[] = $status; }
if ($make)   { $where[] = 'c.make LIKE ?'; $params[] = "%{$make}%"; }
if ($search) {
    $where[]  = '(c.chassis_number LIKE ? OR c.make LIKE ? OR c.model LIKE ? OR c.registration_number LIKE ?)';
    $s = "%{$search}%";
    $params = array_merge($params, [$s, $s, $s, $s]);
}

$whereStr = implode(' AND ', $where);

$totalStmt = $db->prepare("SELECT COUNT(*) FROM cars c WHERE {$whereStr}");
$totalStmt->execute($params);
$total = (int)$totalStmt->fetchColumn();

$stmt = $db->prepare("SELECT c.id, c.chassis_number, c.registration_number, c.make, c.model, c.year, c.color,
                             c.transmission, c.fuel_type, c.car_type, c.status, c.created_at,
                             IFNULL(pl.name, l.name) AS location
                      FROM cars c
                      LEFT JOIN locations l  ON l.id  = c.location_id
                      LEFT JOIN locations pl ON pl.id = l.parent_id
                      WHERE {$whereStr} ORDER BY c.created_at DESC LIMIT {$limit} OFFSET {$offset}");
$stmt->execute($params);
$cars = $stmt->fetchAll();

apiPaginate($cars, $total, $page, $limit);

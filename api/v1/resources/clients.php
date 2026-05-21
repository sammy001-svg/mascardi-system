<?php
/** GET /api/v1/clients  or  GET /api/v1/clients/{id} */

$db = getDB();

if ($id) {
    $stmt = $db->prepare("SELECT id, name, email, phone, id_number, portal_enabled, status, created_at FROM clients WHERE id = ?");
    $stmt->execute([$id]);
    $client = $stmt->fetch();
    if (!$client) apiError(404, "Client #{$id} not found.");

    $vehicles = $db->prepare("SELECT id, chassis_number, make, model, year, status FROM cars WHERE client_id = ?");
    $vehicles->execute([$id]);
    $client['vehicles'] = $vehicles->fetchAll();

    apiResponse($client);
}

$page   = max(1, (int)($_GET['page'] ?? 1));
$limit  = min(100, max(1, (int)($_GET['per_page'] ?? 20)));
$offset = ($page - 1) * $limit;
$search = $_GET['search'] ?? '';

$where  = ['1=1'];
$params = [];
if ($search) {
    $where[]  = '(name LIKE ? OR email LIKE ? OR phone LIKE ?)';
    $s = "%{$search}%";
    $params = array_merge($params, [$s, $s, $s]);
}
$whereStr = implode(' AND ', $where);

$total = (int)$db->prepare("SELECT COUNT(*) FROM clients WHERE {$whereStr}")->execute($params) ? $db->query("SELECT FOUND_ROWS()")->fetchColumn() : 0;

$totalStmt = $db->prepare("SELECT COUNT(*) FROM clients WHERE {$whereStr}");
$totalStmt->execute($params);
$total = (int)$totalStmt->fetchColumn();

$stmt = $db->prepare("SELECT id, name, email, phone, status, portal_enabled, created_at FROM clients WHERE {$whereStr} ORDER BY name ASC LIMIT {$limit} OFFSET {$offset}");
$stmt->execute($params);
$clients = $stmt->fetchAll();

apiPaginate($clients, $total, $page, $limit);

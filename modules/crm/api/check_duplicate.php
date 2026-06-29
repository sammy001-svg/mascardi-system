<?php
/**
 * AJAX endpoint — checks for existing leads that match a given phone, email, or name.
 * Returns JSON: { duplicates: [ {id, name, phone, email, stage} ] }
 */
require_once __DIR__ . '/../../../includes/functions.php';

header('Content-Type: application/json');

if (!isLoggedIn() || !canAccess('crm')) {
    echo json_encode(['duplicates' => []]);
    exit;
}

$db    = getDB();
$phone = trim($_GET['phone'] ?? '');
$email = trim($_GET['email'] ?? '');
$name  = trim($_GET['name']  ?? '');

$conds  = [];
$params = [];

if ($phone !== '') {
    $suffix = substr(preg_replace('/[^0-9]/', '', $phone), -9);
    if (strlen($suffix) >= 7) {
        $conds[]  = "phone LIKE ?";
        $params[] = '%' . $suffix;
    }
}
if ($email !== '') {
    $conds[]  = "LOWER(email) = LOWER(?)";
    $params[] = $email;
}
if ($name !== '') {
    $conds[]  = "LOWER(name) = LOWER(?)";
    $params[] = strtolower($name);
}

$dupes = [];
if ($conds) {
    try {
        $stmt = $db->prepare("
            SELECT l.id, l.name, l.phone, l.email, l.stage
            FROM crm_leads l
            WHERE (" . implode(' OR ', $conds) . ")
            LIMIT 10
        ");
        $stmt->execute($params);
        $dupes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {}
}

echo json_encode(['duplicates' => $dupes]);

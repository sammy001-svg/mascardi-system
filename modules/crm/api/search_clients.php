<?php
/**
 * CRM — Client search API
 * GET ?q=SEARCH_TERM
 * Returns JSON: {"clients": [...]}
 * Used by convert_lead.php "Link Existing Client" tab.
 */
require_once __DIR__ . '/../../../includes/functions.php';

header('Content-Type: application/json');

// Require a logged-in session; return 401 instead of a redirect
requireLogin();

// Only CRM access is needed to search clients from this endpoint
if (!canAccess('crm')) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit;
}

$q = trim($_GET['q'] ?? '');

if ($q === '' || mb_strlen($q) < 1) {
    echo json_encode(['clients' => []]);
    exit;
}

try {
    $db     = getDB();
    $like   = '%' . $q . '%';
    $stmt   = $db->prepare("
        SELECT id, name, phone, email
        FROM clients
        WHERE status = 'active'
          AND (name LIKE ? OR phone LIKE ? OR email LIKE ?)
        ORDER BY name ASC
        LIMIT 10
    ");
    $stmt->execute([$like, $like, $like]);
    $clients = $stmt->fetchAll(\PDO::FETCH_ASSOC);

    // Sanitise output — null fields become empty strings
    $result = array_map(function ($c) {
        return [
            'id'    => (int)$c['id'],
            'name'  => $c['name']  ?? '',
            'phone' => $c['phone'] ?? '',
            'email' => $c['email'] ?? '',
        ];
    }, $clients);

    echo json_encode(['clients' => $result]);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Query failed', 'clients' => []]);
}

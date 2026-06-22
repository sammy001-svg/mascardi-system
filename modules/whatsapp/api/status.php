<?php
require_once __DIR__ . '/../../../includes/functions.php';
header('Content-Type: application/json');

if (!isLoggedIn()) { echo json_encode(['connected' => false, 'unread' => 0]); exit; }

try {
    $db     = getDB();
    $config = $db->query("SELECT * FROM wa_config LIMIT 1")->fetch() ?: [];
    $unread = (int)$db->query("SELECT COALESCE(SUM(unread_count),0) FROM wa_conversations")->fetchColumn();
    echo json_encode([
        'connected' => (bool)($config['is_connected'] ?? false),
        'unread'    => $unread,
        'phone'     => $config['phone_number'] ?? null,
    ]);
} catch (\Throwable $e) {
    echo json_encode(['connected' => false, 'unread' => 0]);
}

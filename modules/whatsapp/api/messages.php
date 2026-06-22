<?php
require_once __DIR__ . '/../../../includes/functions.php';
header('Content-Type: application/json');
if (!isLoggedIn()) { http_response_code(401); echo json_encode(['messages' => []]); exit; }

$convId  = (int)($_GET['conversation_id'] ?? 0);
if (!$convId) { echo json_encode(['messages' => []]); exit; }

$db = getDB();

try {
    $db->prepare("UPDATE wa_conversations SET unread_count = 0 WHERE id = ?")->execute([$convId]);
    $db->prepare("UPDATE wa_messages SET is_read = 1 WHERE conversation_id = ? AND direction = 'in'")->execute([$convId]);

    $stmt = $db->prepare("
        SELECT m.*, u.name AS agent_name
        FROM wa_messages m
        LEFT JOIN users u ON u.id = m.sent_by
        WHERE m.conversation_id = ?
        ORDER BY m.sent_at ASC
        LIMIT 150
    ");
    $stmt->execute([$convId]);
    echo json_encode(['messages' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
} catch (\Throwable $e) {
    echo json_encode(['messages' => [], 'error' => $e->getMessage()]);
}

<?php
/**
 * Returns new messages from the local DB for an open conversation.
 * NO external API calls — responds in under 10ms.
 * Green API draining is handled by receive.php (called separately).
 */
require_once __DIR__ . '/../../../includes/functions.php';
header('Content-Type: application/json');

if (!isLoggedIn()) { echo json_encode(['messages' => []]); exit; }

$convId  = (int)($_GET['conversation_id'] ?? 0);
$sinceId = (int)($_GET['since_id']        ?? 0);

if (!$convId) { echo json_encode(['messages' => []]); exit; }

try {
    $db   = getDB();
    $stmt = $db->prepare("
        SELECT m.*, u.name AS agent_name
        FROM   wa_messages m
        LEFT JOIN users u ON u.id = m.sent_by
        WHERE  m.conversation_id = ?
          AND  m.id > ?
        ORDER BY m.sent_at ASC
        LIMIT 50
    ");
    $stmt->execute([$convId, $sinceId]);
    $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Mark conversation as read whenever new incoming messages arrive
    if ($messages) {
        foreach ($messages as $m) {
            if ($m['direction'] === 'in') {
                $db->prepare("UPDATE wa_conversations SET unread_count = 0 WHERE id = ?")->execute([$convId]);
                $db->prepare("UPDATE wa_messages SET is_read = 1 WHERE conversation_id = ? AND direction = 'in'")->execute([$convId]);
                break;
            }
        }
    }

    echo json_encode(['messages' => $messages]);

} catch (\Throwable $e) {
    echo json_encode(['messages' => [], 'error' => $e->getMessage()]);
}

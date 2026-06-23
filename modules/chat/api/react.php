<?php
// Chat API — Toggle a reaction on a message
require_once __DIR__ . '/../../../includes/functions.php';

header('Content-Type: application/json');

if (!isLoggedIn()) { http_response_code(401); echo json_encode(['error'=>'Unauthenticated']); exit; }

$me  = authUser();
$db  = getDB();
$raw = json_decode(file_get_contents('php://input'), true) ?? [];

$msgId = (int)($raw['message_id'] ?? 0);
$emoji = mb_substr(trim($raw['emoji'] ?? ''), 0, 8);

if (!$msgId || !$emoji) { http_response_code(400); echo json_encode(['error'=>'Invalid params']); exit; }

try {
    // Ensure reactions table exists
    $db->exec("CREATE TABLE IF NOT EXISTS chat_reactions (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        message_id  INT NOT NULL,
        user_id     INT NOT NULL,
        emoji       VARCHAR(20) NOT NULL,
        created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY  uk_react (message_id, user_id),
        KEY         idx_msg  (message_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Verify the user is a participant of the conversation that contains this message
    $access = $db->prepare("
        SELECT cm.id FROM chat_messages cm
        JOIN chat_participants cp
            ON cp.conversation_id = cm.conversation_id
           AND cp.user_id = ?
        WHERE cm.id = ? AND cm.is_deleted = 0
        LIMIT 1
    ");
    $access->execute([$me['id'], $msgId]);
    if (!$access->fetch()) {
        http_response_code(403); echo json_encode(['error'=>'Access denied']); exit;
    }

    // Fetch existing reaction (one per user per message)
    $existing = $db->prepare("SELECT emoji FROM chat_reactions WHERE message_id = ? AND user_id = ?");
    $existing->execute([$msgId, $me['id']]);
    $cur = $existing->fetchColumn();

    if ($cur === false) {
        // No existing — insert
        $db->prepare("INSERT INTO chat_reactions (message_id, user_id, emoji) VALUES (?,?,?)")
           ->execute([$msgId, $me['id'], $emoji]);
        echo json_encode(['ok' => true, 'action' => 'added']);
    } elseif ($cur === $emoji) {
        // Same emoji — toggle off
        $db->prepare("DELETE FROM chat_reactions WHERE message_id = ? AND user_id = ?")
           ->execute([$msgId, $me['id']]);
        echo json_encode(['ok' => true, 'action' => 'removed']);
    } else {
        // Different emoji — replace
        $db->prepare("UPDATE chat_reactions SET emoji = ?, created_at = NOW() WHERE message_id = ? AND user_id = ?")
           ->execute([$emoji, $msgId, $me['id']]);
        echo json_encode(['ok' => true, 'action' => 'changed']);
    }

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}

<?php
// Chat API – Total unread message count across all conversations
require_once __DIR__ . '/../../../includes/functions.php';

header('Content-Type: application/json');

if (!isLoggedIn()) { echo json_encode(['count' => 0]); exit; }

$me = authUser();
$db = getDB();

try {
    $stmt = $db->prepare("
        SELECT COALESCE(SUM(
            (SELECT COUNT(*) FROM chat_messages cm
             WHERE cm.conversation_id = cp.conversation_id
               AND cm.id > cp.last_read_msg_id
               AND cm.sender_id <> ?
               AND cm.is_deleted = 0)
        ), 0) AS total_unread
        FROM chat_participants cp
        WHERE cp.user_id = ?
    ");
    $stmt->execute([$me['id'], $me['id']]);
    $count = (int)$stmt->fetchColumn();
    echo json_encode(['count' => $count]);
} catch (Exception $e) {
    echo json_encode(['count' => 0]);
}

<?php
// Chat API – Fetch messages for a conversation (polling)
require_once __DIR__ . '/../../../includes/functions.php';

header('Content-Type: application/json');

if (!isLoggedIn()) { http_response_code(401); echo json_encode(['error'=>'Unauthenticated']); exit; }

$me   = authUser();
$db   = getDB();
$convId = (int)($_GET['conversation_id'] ?? 0);
$after  = (int)($_GET['after'] ?? 0);   // last known message ID — return messages with id > after

if (!$convId) { http_response_code(400); echo json_encode(['error'=>'conversation_id required']); exit; }

try {
    // Verify current user is a participant
    $check = $db->prepare("SELECT last_read_msg_id FROM chat_participants WHERE conversation_id=? AND user_id=?");
    $check->execute([$convId, $me['id']]);
    $participant = $check->fetch();
    if (!$participant) { http_response_code(403); echo json_encode(['error'=>'Access denied']); exit; }

    // Fetch messages after the given id (with reply-to preview)
    $stmt = $db->prepare("
        SELECT
            cm.id, cm.conversation_id, cm.sender_id, cm.type,
            cm.content, cm.file_path, cm.file_name, cm.file_size, cm.mime_type,
            cm.duration, cm.is_deleted, cm.created_at, cm.reply_to_id,
            u.name AS sender_name, u.role AS sender_role,
            rm.type    AS reply_to_type,
            rm.content AS reply_to_content,
            rm.file_name AS reply_to_file_name,
            ru.name    AS reply_to_sender_name
        FROM chat_messages cm
        JOIN users u ON u.id = cm.sender_id
        LEFT JOIN chat_messages rm ON rm.id = cm.reply_to_id
        LEFT JOIN users ru ON ru.id = rm.sender_id
        WHERE cm.conversation_id = ?
          AND cm.id > ?
          AND cm.is_deleted = 0
        ORDER BY cm.id ASC
        LIMIT 100
    ");
    $stmt->execute([$convId, $after]);
    $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Mark messages as read
    if (!empty($messages)) {
        $maxId = max(array_column($messages, 'id'));
        if ($maxId > (int)$participant['last_read_msg_id']) {
            $db->prepare("UPDATE chat_participants SET last_read_msg_id=? WHERE conversation_id=? AND user_id=?")
               ->execute([$maxId, $convId, $me['id']]);
        }
    }

    // Add public URL for files/images/voice
    $baseUrl = BASE_URL . '/uploads/chat/';
    foreach ($messages as &$msg) {
        $msg['id']         = (int)$msg['id'];
        $msg['sender_id']  = (int)$msg['sender_id'];
        $msg['is_deleted'] = (bool)$msg['is_deleted'];
        $msg['is_mine']    = ($msg['sender_id'] === (int)$me['id']);
        $msg['file_url']   = $msg['file_path'] ? $baseUrl . basename($msg['file_path']) : null;
    }
    unset($msg);

    echo json_encode(['messages' => $messages, 'my_id' => (int)$me['id']]);
} catch (Exception $e) {
    echo json_encode(['messages' => [], 'my_id' => (int)$me['id']]);
}

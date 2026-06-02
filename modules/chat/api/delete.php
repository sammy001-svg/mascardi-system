<?php
// Chat API – Soft-delete a message (sender only, or admin)
require_once __DIR__ . '/../../../includes/functions.php';

header('Content-Type: application/json');

if (!isLoggedIn()) { http_response_code(401); echo json_encode(['error'=>'Unauthenticated']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['error'=>'POST only']); exit; }

$me  = authUser();
$db  = getDB();

$body   = json_decode(file_get_contents('php://input'), true) ?? [];
$msgId  = (int)($body['message_id']  ?? 0);
$convId = (int)($body['conversation_id'] ?? 0);

if (!$msgId || !$convId) {
    http_response_code(400);
    echo json_encode(['error' => 'message_id and conversation_id required']);
    exit;
}

// Verify current user is a participant
$check = $db->prepare("SELECT 1 FROM chat_participants WHERE conversation_id=? AND user_id=?");
$check->execute([$convId, $me['id']]);
if (!$check->fetch()) {
    http_response_code(403); echo json_encode(['error' => 'Access denied']); exit;
}

// Fetch the message
$stmt = $db->prepare("SELECT sender_id FROM chat_messages WHERE id=? AND conversation_id=? AND is_deleted=0");
$stmt->execute([$msgId, $convId]);
$msg = $stmt->fetch();
if (!$msg) {
    http_response_code(404); echo json_encode(['error' => 'Message not found']); exit;
}

// Only the sender or an admin can delete
if ((int)$msg['sender_id'] !== (int)$me['id'] && $me['role'] !== 'admin') {
    http_response_code(403); echo json_encode(['error' => 'You can only delete your own messages']); exit;
}

$db->prepare("UPDATE chat_messages SET is_deleted=1 WHERE id=?")->execute([$msgId]);
echo json_encode(['ok' => true]);

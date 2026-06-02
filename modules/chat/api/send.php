<?php
// Chat API – Send a text message
require_once __DIR__ . '/../../../includes/functions.php';

header('Content-Type: application/json');

if (!isLoggedIn()) { http_response_code(401); echo json_encode(['error'=>'Unauthenticated']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['error'=>'POST only']); exit; }

$me  = authUser();
$db  = getDB();

$body      = json_decode(file_get_contents('php://input'), true) ?? [];
$convId    = (int)($body['conversation_id'] ?? 0);
$text      = trim($body['content'] ?? '');
$replyToId = isset($body['reply_to_id']) ? (int)$body['reply_to_id'] : null;

if (!$convId || $text === '') {
    http_response_code(400); echo json_encode(['error'=>'conversation_id and content required']); exit;
}
if (mb_strlen($text) > 10000) {
    http_response_code(400); echo json_encode(['error'=>'Message too long']); exit;
}

// Verify participant
$check = $db->prepare("SELECT 1 FROM chat_participants WHERE conversation_id=? AND user_id=?");
$check->execute([$convId, $me['id']]);
if (!$check->fetch()) { http_response_code(403); echo json_encode(['error'=>'Access denied']); exit; }

// Validate reply_to_id belongs to this conversation (if provided)
if ($replyToId) {
    $rCheck = $db->prepare("SELECT 1 FROM chat_messages WHERE id=? AND conversation_id=? AND is_deleted=0");
    $rCheck->execute([$replyToId, $convId]);
    if (!$rCheck->fetch()) $replyToId = null;
}

// Insert message
$stmt = $db->prepare("
    INSERT INTO chat_messages (conversation_id, sender_id, type, content, reply_to_id)
    VALUES (?, ?, 'text', ?, ?)
");
$stmt->execute([$convId, $me['id'], $text, $replyToId]);
$msgId = (int)$db->lastInsertId();

// Update sender's last_read_msg_id to this message (they've "seen" it)
$db->prepare("UPDATE chat_participants SET last_read_msg_id=? WHERE conversation_id=? AND user_id=?")
   ->execute([$msgId, $convId, $me['id']]);

echo json_encode(['ok' => true, 'message_id' => $msgId]);

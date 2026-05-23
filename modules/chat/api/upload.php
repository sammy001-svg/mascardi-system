<?php
// Chat API – Upload file, image, or voice note and save as message
require_once __DIR__ . '/../../../includes/functions.php';

header('Content-Type: application/json');

if (!isLoggedIn()) { http_response_code(401); echo json_encode(['error'=>'Unauthenticated']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['error'=>'POST only']); exit; }

$me     = authUser();
$db     = getDB();
$convId = (int)($_POST['conversation_id'] ?? 0);

if (!$convId) { http_response_code(400); echo json_encode(['error'=>'conversation_id required']); exit; }

// Verify participant
$check = $db->prepare("SELECT 1 FROM chat_participants WHERE conversation_id=? AND user_id=?");
$check->execute([$convId, $me['id']]);
if (!$check->fetch()) { http_response_code(403); echo json_encode(['error'=>'Access denied']); exit; }

if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400); echo json_encode(['error'=>'No file uploaded or upload error']); exit;
}

$file     = $_FILES['file'];
$origName = basename($file['name']);
$tmpPath  = $file['tmp_name'];
$size     = $file['size'];
$mime     = mime_content_type($tmpPath) ?: ($file['type'] ?? 'application/octet-stream');

// 50 MB limit
if ($size > 52428800) {
    http_response_code(400); echo json_encode(['error'=>'File too large (max 50 MB)']); exit;
}

// Determine message type
$isVoice = (bool)($_POST['voice'] ?? false);
$ext     = strtolower(pathinfo($origName, PATHINFO_EXTENSION));

if ($isVoice || in_array($ext, ['webm','ogg','mp3','m4a','wav','opus'])) {
    $msgType = 'voice';
} elseif (in_array($ext, ['jpg','jpeg','png','gif','webp','bmp','svg'])) {
    $msgType = 'image';
} else {
    $msgType = 'file';
}

// Save to uploads/chat/
$uploadDir = BASE_PATH . '/uploads/chat/';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

$safeName  = date('Ymd_His') . '_' . $me['id'] . '_' . preg_replace('/[^a-zA-Z0-9._\-]/', '_', $origName);
$destPath  = $uploadDir . $safeName;

if (!move_uploaded_file($tmpPath, $destPath)) {
    http_response_code(500); echo json_encode(['error'=>'Could not save file']); exit;
}

// Duration for voice notes (sent from JS as POST field)
$duration = ($msgType === 'voice') ? (int)($_POST['duration'] ?? 0) : null;

// Insert message
$stmt = $db->prepare("
    INSERT INTO chat_messages
        (conversation_id, sender_id, type, file_path, file_name, file_size, mime_type, duration)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
");
$stmt->execute([
    $convId,
    $me['id'],
    $msgType,
    'uploads/chat/' . $safeName,
    $origName,
    $size,
    $mime,
    $duration,
]);
$msgId = (int)$db->lastInsertId();

// Update sender's read pointer
$db->prepare("UPDATE chat_participants SET last_read_msg_id=? WHERE conversation_id=? AND user_id=?")
   ->execute([$msgId, $convId, $me['id']]);

echo json_encode([
    'ok'         => true,
    'message_id' => $msgId,
    'type'       => $msgType,
    'file_url'   => BASE_URL . '/uploads/chat/' . $safeName,
    'file_name'  => $origName,
    'file_size'  => $size,
    'mime_type'  => $mime,
]);

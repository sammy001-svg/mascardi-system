<?php
require_once __DIR__ . '/../../../includes/functions.php';
header('Content-Type: application/json');
set_time_limit(30);

if (!isLoggedIn()) { http_response_code(401); echo json_encode(['success' => false, 'error' => 'Unauthenticated']); exit; }

$convId = (int)($_GET['conversation_id'] ?? 0);
if (!$convId) { echo json_encode(['success' => false, 'error' => 'conversation_id required']); exit; }

$db = getDB();

$s = $db->prepare("SELECT * FROM wa_conversations WHERE id = ?");
$s->execute([$convId]);
$conv = $s->fetch();
if (!$conv) { echo json_encode(['success' => false, 'error' => 'Conversation not found']); exit; }

$config = $db->query("SELECT * FROM wa_config LIMIT 1")->fetch();
if (!$config || !$config['is_connected'] || !$config['instance_id']) {
    echo json_encode(['success' => false, 'error' => 'WhatsApp is not connected']); exit;
}

$iid   = $config['instance_id'];
$token = $config['api_token'];

// Fetch last 100 messages for this chat from Green API
$ch = curl_init("https://api.greenapi.com/waInstance{$iid}/getChatHistory/{$token}");
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => json_encode(['chatId' => $conv['chat_id'], 'count' => 100]),
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
    CURLOPT_TIMEOUT        => 20,
    CURLOPT_SSL_VERIFYPEER => false,
]);
$body = curl_exec($ch);
curl_close($ch);

$msgs = $body ? (json_decode($body, true) ?: []) : [];
if (!is_array($msgs)) $msgs = [];

$imported = 0;

foreach ($msgs as $m) {
    $msgId    = $m['idMessage'] ?? null;
    $ts       = $m['timestamp'] ?? time();
    $mtype    = trim($m['typeMessage'] ?? 'textMessage');
    $dir      = (($m['type'] ?? 'incoming') === 'outgoing') ? 'out' : 'in';
    $mediaUrl = $m['downloadUrl'] ?? null;

    // getChatHistory puts text directly on the message root — try all known fields
    $body = $m['textMessage']
         ?? $m['text']
         ?? $m['caption']
         ?? null;

    // For media and unknown types, show a friendly placeholder
    if (!$body || $body === '') {
        if (str_contains($mtype, 'image'))       $body = '🖼 Image';
        elseif (str_contains($mtype, 'audio') || $mtype === 'pttMessage') $body = '🎵 Voice message';
        elseif (str_contains($mtype, 'video'))   $body = '🎥 Video';
        elseif (str_contains($mtype, 'doc'))     $body = '📄 Document';
        elseif (str_contains($mtype, 'sticker')) $body = '🏷 Sticker';
        elseif (str_contains($mtype, 'location'))$body = '📍 Location';
        elseif (str_contains($mtype, 'contact')) $body = '👤 Contact';
        else                                     $body = "📎 Message ({$mtype})";
    }

    $waType = 'text';
    if (str_contains($mtype, 'image'))       $waType = 'image';
    elseif (str_contains($mtype, 'audio') || $mtype === 'pttMessage') $waType = 'audio';
    elseif (str_contains($mtype, 'video'))   $waType = 'video';
    elseif (str_contains($mtype, 'doc'))     $waType = 'document';

    try {
        $db->prepare(
            "INSERT IGNORE INTO wa_messages
                 (conversation_id, message_id, direction, type, body, media_url, sent_at, is_read)
             VALUES (?, ?, ?, ?, ?, ?, FROM_UNIXTIME(?), 1)"
        )->execute([$convId, $msgId, $dir, $waType, mb_substr($body ?? '', 0, 5000), $mediaUrl, $ts]);
        if ((int)$db->lastInsertId() > 0) $imported++;
    } catch (\Throwable $_) {}
}

// Return all messages for this conversation so the UI can refresh in place
$stmt = $db->prepare("
    SELECT m.*, u.name AS agent_name
    FROM wa_messages m
    LEFT JOIN users u ON u.id = m.sent_by
    WHERE m.conversation_id = ?
    ORDER BY m.sent_at ASC
    LIMIT 150
");
$stmt->execute([$convId]);
$allMessages = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode([
    'success'    => true,
    'imported'   => $imported,
    'total_api'  => count($msgs),
    'messages'   => $allMessages,
]);

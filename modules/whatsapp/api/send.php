<?php
require_once __DIR__ . '/../../../includes/functions.php';
header('Content-Type: application/json');

if (!isLoggedIn()) { http_response_code(401); echo json_encode(['success' => false]); exit; }

$conversationId = (int)($_POST['conversation_id'] ?? 0);
$message        = trim($_POST['message'] ?? '');

if (!$conversationId || !$message) {
    echo json_encode(['success' => false, 'error' => 'Missing required fields']); exit;
}

try {
    $db  = getDB();
    $me  = authUser();
    $uid = (int)$me['id'];

    $conv = $db->prepare("SELECT * FROM wa_conversations WHERE id = ?");
    $conv->execute([$conversationId]);
    $conv = $conv->fetch();
    if (!$conv) { echo json_encode(['success' => false, 'error' => 'Conversation not found']); exit; }

    $config = $db->query("SELECT * FROM wa_config LIMIT 1")->fetch();
    if (!$config || !$config['instance_id'] || !$config['is_connected']) {
        echo json_encode(['success' => false, 'error' => 'WhatsApp not connected']); exit;
    }

    // Green API requires chatId in phone@c.us / group@g.us / id@lid format.
    // Old imported rows may lack the suffix — add @c.us when there is no @ at all.
    $chatId = $conv['chat_id'];
    if ($chatId && !str_contains($chatId, '@')) {
        $chatId .= '@c.us';
    }

    $url     = "https://api.greenapi.com/waInstance{$config['instance_id']}/sendMessage/{$config['api_token']}";
    $payload = json_encode(['chatId' => $chatId, 'message' => $message]);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $resp  = json_decode($body ?: '', true) ?: [];
    $msgId = $resp['idMessage'] ?? null;

    if ($code !== 200 || !$msgId) {
        echo json_encode(['success' => false, 'error' => 'Send failed: ' . ($resp['message'] ?? "HTTP $code")]); exit;
    }

    $db->prepare("INSERT INTO wa_messages (conversation_id, message_id, direction, type, body, sent_by, sent_at)
                  VALUES (?, ?, 'out', 'text', ?, ?, NOW())")
       ->execute([$conversationId, $msgId, $message, $uid]);

    $insertedId = (int)$db->lastInsertId();

    $db->prepare("UPDATE wa_conversations SET last_message=?, last_message_at=NOW(), updated_at=NOW() WHERE id=?")
       ->execute([$message, $conversationId]);

    echo json_encode([
        'success'    => true,
        'message_id' => $insertedId,
        'id_message' => $msgId,
        'sent_at'    => date('Y-m-d H:i:s'),
        'body'       => $message,
        'agent_name' => $me['name'],
    ]);

} catch (\Throwable $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

<?php
require_once __DIR__ . '/../../../includes/functions.php';
header('Content-Type: application/json');

if (!isLoggedIn()) { echo json_encode(['new' => 0, 'messages' => []]); exit; }

$convId  = (int)($_GET['conversation_id'] ?? 0);
$sinceId = (int)($_GET['since_id'] ?? 0);

function gaDelete(string $instanceId, string $apiToken, int $receiptId): void {
    $ch = curl_init("https://api.greenapi.com/waInstance{$instanceId}/deleteNotification/{$apiToken}/{$receiptId}");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => 'DELETE', CURLOPT_TIMEOUT => 5, CURLOPT_SSL_VERIFYPEER => false]);
    curl_exec($ch);
    curl_close($ch);
}

try {
    $db     = getDB();
    $config = $db->query("SELECT * FROM wa_config LIMIT 1")->fetch();
    $new    = 0;

    if ($config && $config['is_connected'] && $config['instance_id']) {
        $iid   = $config['instance_id'];
        $token = $config['api_token'];

        for ($i = 0; $i < 5; $i++) {
            $ch = curl_init("https://api.greenapi.com/waInstance{$iid}/receiveNotification/{$token}");
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8, CURLOPT_SSL_VERIFYPEER => false]);
            $body = curl_exec($ch);
            curl_close($ch);

            if (!$body || $body === 'null') break;

            $notif = json_decode($body, true);
            if (!$notif || !isset($notif['receiptId'])) break;

            $rid       = (int)$notif['receiptId'];
            $nb        = $notif['body'] ?? [];
            $wtype     = $nb['typeWebhook'] ?? '';

            if ($wtype === 'incomingMessageReceived') {
                $sender   = $nb['senderData']  ?? [];
                $msgData  = $nb['messageData'] ?? [];
                $chatId   = $sender['chatId']    ?? '';
                $sName    = $sender['senderName'] ?? '';
                $msgType  = $msgData['typeMessage'] ?? 'textMessage';
                $msgBody  = '';
                $mediaUrl = null;

                if ($msgType === 'textMessage') {
                    $msgBody = $msgData['textMessageData']['textMessage'] ?? '';
                } elseif (in_array($msgType, ['imageMessage','documentMessage','audioMessage','videoMessage'])) {
                    $msgBody  = $msgData['fileMessageData']['caption'] ?? "[{$msgType}]";
                    $mediaUrl = $msgData['fileMessageData']['downloadUrl'] ?? null;
                } else {
                    $msgBody = "[{$msgType}]";
                }

                $phone = preg_replace('/@.*/', '', $chatId);

                $db->prepare(
                    "INSERT INTO wa_conversations (chat_id, contact_name, contact_phone, last_message, last_message_at, unread_count)
                     VALUES (?, ?, ?, ?, NOW(), 1)
                     ON DUPLICATE KEY UPDATE
                       contact_name    = IF(contact_name IS NULL OR contact_name = '', VALUES(contact_name), contact_name),
                       last_message    = VALUES(last_message),
                       last_message_at = NOW(),
                       unread_count    = unread_count + 1,
                       updated_at      = NOW()"
                )->execute([$chatId, $sName ?: $phone, $phone, mb_substr($msgBody, 0, 500)]);

                $convRow = $db->prepare("SELECT id FROM wa_conversations WHERE chat_id = ?");
                $convRow->execute([$chatId]);
                $cid = (int)($convRow->fetchColumn() ?: 0);

                if ($cid) {
                    $waType = 'text';
                    if (str_contains($msgType, 'image'))    $waType = 'image';
                    elseif (str_contains($msgType, 'audio'))    $waType = 'audio';
                    elseif (str_contains($msgType, 'video'))    $waType = 'video';
                    elseif (str_contains($msgType, 'document')) $waType = 'document';

                    $extMsgId = $nb['idMessage'] ?? null;
                    $ts       = $nb['timestamp'] ?? time();

                    try {
                        $db->prepare(
                            "INSERT IGNORE INTO wa_messages (conversation_id, message_id, direction, type, body, media_url, sent_at)
                             VALUES (?, ?, 'in', ?, ?, ?, FROM_UNIXTIME(?))"
                        )->execute([$cid, $extMsgId, $waType, $msgBody, $mediaUrl, $ts]);
                        $new++;
                    } catch (\Throwable $_) {}
                }
            }

            gaDelete($iid, $token, $rid);
        }
    }

    // Return messages for the open chat
    $messages = [];
    if ($convId) {
        $db->prepare("UPDATE wa_conversations SET unread_count = 0 WHERE id = ?")->execute([$convId]);
        $db->prepare("UPDATE wa_messages SET is_read = 1 WHERE conversation_id = ? AND direction = 'in'")->execute([$convId]);

        $sql = "SELECT m.*, u.name AS agent_name
                FROM wa_messages m
                LEFT JOIN users u ON u.id = m.sent_by
                WHERE m.conversation_id = ?";
        if ($sinceId) $sql .= " AND m.id > " . (int)$sinceId;
        $sql .= " ORDER BY m.sent_at ASC LIMIT 50";

        $stmt = $db->prepare($sql);
        $stmt->execute([$convId]);
        $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    echo json_encode(['new' => $new, 'messages' => $messages]);

} catch (\Throwable $e) {
    echo json_encode(['new' => 0, 'messages' => [], 'error' => $e->getMessage()]);
}

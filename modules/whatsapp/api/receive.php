<?php
/**
 * Drains the Green API notification queue and saves incoming messages to DB.
 * Uses a MySQL advisory lock so only ONE process drains at a time — multiple
 * browser tabs / users all call this endpoint, but only the first one through
 * actually hits Green API; the rest return {new:0, locked:true} immediately.
 */
require_once __DIR__ . '/../../../includes/functions.php';
header('Content-Type: application/json');

if (!isLoggedIn()) { echo json_encode(['new' => 0]); exit; }

$db  = getDB();
$new = 0;
$notifications = [];

try {
    $config = $db->query("SELECT * FROM wa_config LIMIT 1")->fetch();
    if (!$config || !$config['is_connected'] || !$config['instance_id']) {
        echo json_encode(['new' => 0, 'notifications' => []]); exit;
    }

    // Non-blocking advisory lock — first caller wins, rest skip immediately
    $locked = (int)$db->query("SELECT GET_LOCK('wa_recv_lock', 0)")->fetchColumn();
    if (!$locked) {
        echo json_encode(['new' => 0, 'locked' => true, 'notifications' => []]); exit;
    }

    $iid   = $config['instance_id'];
    $token = $config['api_token'];

    try {
        // Drain up to 10 queued notifications per call
        for ($i = 0; $i < 10; $i++) {
            $ch = curl_init("https://api.greenapi.com/waInstance{$iid}/receiveNotification/{$token}");
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 5,
                CURLOPT_CONNECTTIMEOUT => 4,
                CURLOPT_SSL_VERIFYPEER => false,
            ]);
            $body = curl_exec($ch);
            $cerr = curl_errno($ch);
            curl_close($ch);

            // Empty queue or network error
            if ($cerr || !$body || trim($body) === 'null') break;

            $notif = json_decode($body, true);
            if (!is_array($notif) || !isset($notif['receiptId'])) break;

            $rid   = (int)$notif['receiptId'];
            $nb    = $notif['body'] ?? [];
            $wtype = $nb['typeWebhook'] ?? '';

            if ($wtype === 'incomingMessageReceived') {
                $sender   = $nb['senderData']  ?? [];
                $msgData  = $nb['messageData'] ?? [];
                $chatId   = $sender['chatId']   ?? ($nb['chatId'] ?? '');
                // Try all known name fields — Green API version varies
                $sName    = $sender['senderName']
                         ?? $sender['pushName']
                         ?? $sender['chatName']
                         ?? $nb['senderName']
                         ?? '';
                // Trim whitespace — some API versions include trailing \r\n
                $msgType  = trim($msgData['typeMessage'] ?? '');
                if ($msgType === '') $msgType = 'textMessage'; // safe default
                $msgBody  = '';
                $mediaUrl = null;

                switch ($msgType) {
                    case 'textMessage':
                        // Standard nested format (most Green API versions)
                        $msgBody = $msgData['textMessageData']['textMessage'] ?? '';
                        // Flat format fallback (some Green API versions / self-hosted)
                        if ($msgBody === '') $msgBody = $msgData['textMessage'] ?? '';
                        break;

                    case 'extendedTextMessage':
                        // Extended text = plain text + URL preview
                        $ext     = $msgData['extendedTextMessageData'] ?? [];
                        $msgBody = $ext['text'] ?? $ext['description'] ?? '';
                        if ($msgBody === '') $msgBody = $msgData['textMessage'] ?? '';
                        break;

                    case 'imageMessage':
                        $fmd      = $msgData['fileMessageData'] ?? $msgData['imageMessageData'] ?? [];
                        $msgBody  = $fmd['caption'] ?? '';
                        if ($msgBody === '') $msgBody = '🖼 Image';
                        $mediaUrl = $fmd['downloadUrl'] ?? null;
                        break;

                    case 'videoMessage':
                        $fmd      = $msgData['fileMessageData'] ?? $msgData['videoMessageData'] ?? [];
                        $msgBody  = $fmd['caption'] ?? '';
                        if ($msgBody === '') $msgBody = '🎥 Video';
                        $mediaUrl = $fmd['downloadUrl'] ?? null;
                        break;

                    case 'audioMessage':
                    case 'pttMessage':
                        $fmd      = $msgData['fileMessageData'] ?? $msgData['audioMessageData'] ?? [];
                        $msgBody  = '🎵 Voice message';
                        $mediaUrl = $fmd['downloadUrl'] ?? null;
                        break;

                    case 'documentMessage':
                        $fmd      = $msgData['fileMessageData'] ?? $msgData['documentMessageData'] ?? [];
                        $fileName = $fmd['fileName'] ?? 'Document';
                        $msgBody  = $fmd['caption'] ?? "📄 {$fileName}";
                        $mediaUrl = $fmd['downloadUrl'] ?? null;
                        break;

                    case 'stickerMessage':
                        $msgBody = '🏷 Sticker';
                        break;

                    case 'locationMessage':
                        $loc     = $msgData['locationMessageData'] ?? [];
                        $msgBody = '📍 ' . ($loc['nameLocation'] ?? $loc['address'] ?? 'Location');
                        break;

                    case 'contactMessage':
                        $msgBody = '👤 ' . ($msgData['contactMessageData']['displayName'] ?? 'Contact');
                        break;

                    case 'templateMessage':
                        $tpl     = $msgData['templateMessage'] ?? $msgData['templateButtonReplyMessage'] ?? [];
                        $msgBody = $tpl['contentText'] ?? $tpl['selectedId'] ?? 'Template message';
                        break;

                    case 'buttonsResponseMessage':
                        $msgBody = $msgData['buttonsResponseMessage']['selectedDisplayText'] ?? 'Button response';
                        break;

                    case 'listResponseMessage':
                        $msgBody = $msgData['listResponseMessage']['title'] ?? 'List response';
                        break;

                    case 'reactionMessage':
                        $msgBody = '👍 Reaction';
                        break;

                    default:
                        // Universal fallback: scan common text fields before giving up
                        $msgBody = $msgData['textMessageData']['textMessage']
                                ?? $msgData['extendedTextMessageData']['text']
                                ?? $msgData['textMessage']
                                ?? $msgData['caption']
                                ?? '';
                        if ($msgBody === '') {
                            // Unknown type — log for debugging, store readable placeholder
                            error_log("WA receive.php — unhandled typeMessage: {$msgType} | data: " . json_encode($msgData));
                            $msgBody = "📎 Message ({$msgType})";
                        }
                }

                if ($chatId) {
                    $phone       = preg_replace('/@.*/', '', $chatId);
                    $displayName = $sName ?: $phone;

                    // Upsert conversation — preserve existing name, increment unread
                    $db->prepare(
                        "INSERT INTO wa_conversations
                             (chat_id, contact_name, contact_phone, last_message, last_message_at, unread_count)
                         VALUES (?, ?, ?, ?, NOW(), 1)
                         ON DUPLICATE KEY UPDATE
                           contact_name    = IF(contact_name IS NULL OR contact_name = '', VALUES(contact_name), contact_name),
                           last_message    = VALUES(last_message),
                           last_message_at = NOW(),
                           unread_count    = unread_count + 1,
                           updated_at      = NOW()"
                    )->execute([$chatId, $displayName, $phone, mb_substr($msgBody, 0, 500)]);

                    $stmtCid = $db->prepare("SELECT id FROM wa_conversations WHERE chat_id = ?");
                    $stmtCid->execute([$chatId]);
                    $cid = (int)($stmtCid->fetchColumn() ?: 0);

                    if ($cid) {
                        $waType = 'text';
                        if (str_contains($msgType, 'image'))    $waType = 'image';
                        elseif (str_contains($msgType, 'audio'))    $waType = 'audio';
                        elseif (str_contains($msgType, 'video'))    $waType = 'video';
                        elseif (str_contains($msgType, 'document')) $waType = 'document';

                        $extMsgId = $nb['idMessage'] ?? null;
                        $ts       = $nb['timestamp'] ?? time();

                        try {
                            $ins = $db->prepare(
                                "INSERT IGNORE INTO wa_messages
                                     (conversation_id, message_id, direction, type, body, media_url, sent_at)
                                 VALUES (?, ?, 'in', ?, ?, ?, FROM_UNIXTIME(?))"
                            );
                            $ins->execute([$cid, $extMsgId, $waType, $msgBody, $mediaUrl, $ts]);

                            if ($ins->rowCount() > 0) {
                                $new++;
                                $notifications[] = [
                                    'conv_id' => $cid,
                                    'name'    => $displayName,
                                    'preview' => mb_substr($msgBody, 0, 60),
                                ];
                            }
                        } catch (\Throwable $_) {}
                    }
                }
            }

            // Always delete — clears state-change notifications, read receipts, etc.
            $del = curl_init("https://api.greenapi.com/waInstance{$iid}/deleteNotification/{$token}/{$rid}");
            curl_setopt_array($del, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CUSTOMREQUEST  => 'DELETE',
                CURLOPT_TIMEOUT        => 4,
                CURLOPT_SSL_VERIFYPEER => false,
            ]);
            curl_exec($del);
            curl_close($del);
        }
    } finally {
        $db->query("SELECT RELEASE_LOCK('wa_recv_lock')");
    }

    echo json_encode(['new' => $new, 'notifications' => $notifications]);

} catch (\Throwable $e) {
    echo json_encode(['new' => 0, 'notifications' => [], 'error' => $e->getMessage()]);
}

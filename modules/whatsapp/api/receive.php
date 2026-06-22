<?php
/**
 * Drains the Green API notification queue and saves incoming messages to DB.
 * Uses a MySQL advisory lock so only ONE process drains at a time — multiple
 * browser tabs / users all call this endpoint, but only the first one through
 * actually hits Green API; the rest return {new:0,locked:true} immediately.
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
        for ($i = 0; $i < 10; $i++) {
            $ch = curl_init("https://api.greenapi.com/waInstance{$iid}/receiveNotification/{$token}");
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 5,
                CURLOPT_CONNECTTIMEOUT => 4,
                CURLOPT_SSL_VERIFYPEER => false,
            ]);
            $rawBody = curl_exec($ch);
            $cerr    = curl_errno($ch);
            curl_close($ch);

            if ($cerr || !$rawBody || trim($rawBody) === 'null') break;

            $notif = json_decode($rawBody, true);
            if (!is_array($notif) || !isset($notif['receiptId'])) break;

            $rid   = (int)$notif['receiptId'];
            $nb    = $notif['body'] ?? [];
            $wtype = $nb['typeWebhook'] ?? '';

            if ($wtype === 'incomingMessageReceived') {
                $sender  = $nb['senderData']  ?? [];
                $msgData = $nb['messageData'] ?? [];
                $chatId  = $sender['chatId']   ?? $nb['chatId'] ?? '';
                $sName   = $sender['senderName']
                        ?? $sender['pushName']
                        ?? $sender['chatName']
                        ?? $nb['senderName']
                        ?? '';
                $msgType = trim($msgData['typeMessage'] ?? 'textMessage');

                // ── Extract message body ─────────────────────────────────────
                // STRATEGY: try every known text field path FIRST (waterfall),
                // regardless of typeMessage. Only fall back to media labels when
                // no text is found at all.
                //
                // This is intentional — Green API returns the text in different
                // locations depending on version, WhatsApp client, and message
                // origin (Business, personal, group, etc.).
                $msgBody  = '';
                $mediaUrl = null;

                // Waterfall: ordered by how commonly text appears in each field
                $textCandidates = [
                    // Standard format (most common)
                    $msgData['textMessageData']['textMessage'] ?? null,
                    // extendedTextMessage (links, rich text)
                    $msgData['extendedTextMessageData']['text'] ?? null,
                    // Some Green API instances return text at the root of messageData
                    $msgData['textMessage'] ?? null,
                    $msgData['text']        ?? null,
                    // Template / interactive messages
                    $msgData['templateMessage']['contentText']                 ?? null,
                    $msgData['buttonsResponseMessage']['selectedDisplayText']  ?? null,
                    $msgData['listResponseMessage']['title']                   ?? null,
                    $msgData['interactiveResponseMessage']['body']             ?? null,
                    // Caption on media (user typed text alongside a photo/video)
                    $msgData['fileMessageData']['caption']                     ?? null,
                    // Extended text fallback fields
                    $msgData['extendedTextMessageData']['description']         ?? null,
                    $msgData['extendedTextMessageData']['title']               ?? null,
                    // Contact / location / reaction
                    $msgData['contactMessageData']['displayName']              ?? null,
                ];

                foreach ($textCandidates as $c) {
                    if (is_string($c) && $c !== '') { $msgBody = $c; break; }
                }

                // Determine DB media type
                $waType = 'text';
                if (str_contains($msgType, 'image'))    $waType = 'image';
                elseif (str_contains($msgType, 'video'))    $waType = 'video';
                elseif (str_contains($msgType, 'audio') || $msgType === 'pttMessage') $waType = 'audio';
                elseif (str_contains($msgType, 'doc'))      $waType = 'document';

                // Media fallback labels (only when text waterfall found nothing)
                if ($msgBody === '') {
                    switch ($msgType) {
                        case 'imageMessage':
                            $msgBody  = 'Image';
                            $mediaUrl = $msgData['fileMessageData']['downloadUrl'] ?? null;
                            break;
                        case 'videoMessage':
                            $msgBody  = 'Video';
                            $mediaUrl = $msgData['fileMessageData']['downloadUrl'] ?? null;
                            break;
                        case 'audioMessage':
                        case 'pttMessage':
                            $msgBody  = 'Voice message';
                            $mediaUrl = $msgData['fileMessageData']['downloadUrl'] ?? null;
                            break;
                        case 'documentMessage':
                            $fn       = $msgData['fileMessageData']['fileName'] ?? 'Document';
                            $msgBody  = "Document: {$fn}";
                            $mediaUrl = $msgData['fileMessageData']['downloadUrl'] ?? null;
                            break;
                        case 'stickerMessage':    $msgBody = 'Sticker'; break;
                        case 'locationMessage':
                            $loc     = $msgData['locationMessageData'] ?? [];
                            $msgBody = 'Location: ' . ($loc['nameLocation'] ?? $loc['address'] ?? 'shared location');
                            break;
                        case 'reactionMessage':   $msgBody = 'Reaction'; break;
                        default:
                            // Log full notification body so we can inspect it on the server
                            error_log('WA receive.php — no text extracted. type=' . $msgType
                                . ' chatId=' . $chatId
                                . ' keys=' . implode(',', array_keys($msgData))
                                . ' nb_snippet=' . substr(json_encode($nb), 0, 500));
                            $msgBody = 'Message';
                    }
                }

                if ($chatId) {
                    $phone       = preg_replace('/@.*/', '', $chatId);
                    $displayName = $sName ?: $phone;

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

            // Always delete — clears state/typing/receipt notifications too
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

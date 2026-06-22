<?php
require_once __DIR__ . '/../../../includes/functions.php';
header('Content-Type: application/json');
set_time_limit(120);

if (!isLoggedIn()) { http_response_code(401); echo json_encode(['success' => false, 'error' => 'Unauthenticated']); exit; }
if (!hasRole(['admin', 'general_manager'])) { http_response_code(403); echo json_encode(['success' => false, 'error' => 'Access denied']); exit; }

$db = getDB();
$config = $db->query("SELECT * FROM wa_config LIMIT 1")->fetch();
if (!$config || !$config['is_connected'] || !$config['instance_id']) {
    echo json_encode(['success' => false, 'error' => 'WhatsApp is not connected']); exit;
}

$iid         = $config['instance_id'];
$token       = $config['api_token'];
$withHistory = !empty($_GET['history']);

function waImportGet(string $iid, string $token, string $method): ?array {
    $ch = curl_init("https://api.greenapi.com/waInstance{$iid}/{$method}/{$token}");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_SSL_VERIFYPEER => false]);
    $body = curl_exec($ch);
    curl_close($ch);
    return $body ? (json_decode($body, true) ?: null) : null;
}

function waImportPost(string $iid, string $token, string $method, array $data): ?array {
    $ch = curl_init("https://api.greenapi.com/waInstance{$iid}/{$method}/{$token}");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($data),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $body = curl_exec($ch);
    curl_close($ch);
    return $body ? (json_decode($body, true) ?: null) : null;
}

// ── 1. Fetch all chats ────────────────────────────────────────────────────────
$chats = waImportGet($iid, $token, 'getChats');
if (!is_array($chats)) {
    echo json_encode(['success' => false, 'error' => 'No chats returned from WhatsApp. Make sure WhatsApp is connected and try again.']);
    exit;
}

// Sort newest-first so top-100 for history fetch are the most active ones
usort($chats, function ($a, $b) {
    return ($b['lastMessage']['timestamp'] ?? 0) - ($a['lastMessage']['timestamp'] ?? 0);
});

$newConvs     = 0;
$updatedConvs = 0;
$importedMsgs = 0;
$chatDbIds    = []; // chatId => db row id

// ── 2. Upsert all conversations ───────────────────────────────────────────────
foreach ($chats as $chat) {
    $chatId = $chat['id'] ?? ($chat['chatId'] ?? null);
    if (!$chatId) continue;

    $phone = preg_replace('/@.*/', '', $chatId);
    $name  = $chat['name'] ?? ($chat['title'] ?? $phone);

    $lastMsg   = null;
    $lastMsgAt = null;
    $lm = $chat['lastMessage'] ?? null;
    if ($lm && isset($lm['timestamp'])) {
        $lastMsgAt = date('Y-m-d H:i:s', $lm['timestamp']);
        $lastMsg   = $lm['textMessage'] ?? ($lm['caption'] ?? null);
        if (!$lastMsg && isset($lm['typeMessage'])) {
            $t = $lm['typeMessage'];
            if (str_contains($t, 'image'))  $lastMsg = '[Image]';
            elseif (str_contains($t, 'audio'))   $lastMsg = '[Voice]';
            elseif (str_contains($t, 'video'))   $lastMsg = '[Video]';
            elseif (str_contains($t, 'doc'))     $lastMsg = '[Document]';
        }
    }

    try {
        $s = $db->prepare("SELECT id FROM wa_conversations WHERE chat_id = ?");
        $s->execute([$chatId]);
        $existId = (int)($s->fetchColumn() ?: 0);

        if ($existId) {
            $db->prepare(
                "UPDATE wa_conversations SET
                   contact_name    = IF(contact_name IS NULL OR contact_name = '', ?, contact_name),
                   last_message    = COALESCE(?, last_message),
                   last_message_at = COALESCE(?, last_message_at),
                   updated_at      = NOW()
                 WHERE id = ?"
            )->execute([mb_substr($name, 0, 150), $lastMsg ? mb_substr($lastMsg, 0, 500) : null, $lastMsgAt, $existId]);
            $chatDbIds[$chatId] = $existId;
            $updatedConvs++;
        } else {
            $db->prepare(
                "INSERT INTO wa_conversations (chat_id, contact_name, contact_phone, last_message, last_message_at, unread_count)
                 VALUES (?, ?, ?, ?, ?, 0)"
            )->execute([$chatId, mb_substr($name, 0, 150), $phone, $lastMsg ? mb_substr($lastMsg, 0, 500) : null, $lastMsgAt]);
            $chatDbIds[$chatId] = (int)$db->lastInsertId();
            $newConvs++;
        }
    } catch (\Throwable $_) {}
}

// ── 3. Optionally import last 30 messages for the 100 most recent chats ───────
if ($withHistory && !empty($chatDbIds)) {
    $batchCount = 0;
    foreach ($chatDbIds as $chatId => $dbId) {
        if ($batchCount++ >= 100) break;

        $msgs = waImportPost($iid, $token, 'getChatHistory', ['chatId' => $chatId, 'count' => 30]);
        if (!is_array($msgs)) continue;

        foreach ($msgs as $m) {
            $msgId    = $m['idMessage'] ?? null;
            $ts       = $m['timestamp'] ?? time();
            $mtype    = $m['typeMessage'] ?? 'textMessage';
            $dir      = (($m['type'] ?? 'incoming') === 'outgoing') ? 'out' : 'in';
            $body     = $m['textMessage'] ?? ($m['caption'] ?? null);
            $mediaUrl = $m['downloadUrl'] ?? null;

            if (!$body) {
                if (str_contains($mtype, 'image'))       $body = '[Image]';
                elseif (str_contains($mtype, 'audio'))   $body = '[Voice]';
                elseif (str_contains($mtype, 'video'))   $body = '[Video]';
                elseif (str_contains($mtype, 'doc'))     $body = '[Document]';
                else                                     $body = "[{$mtype}]";
            }

            $waType = 'text';
            if (str_contains($mtype, 'image'))       $waType = 'image';
            elseif (str_contains($mtype, 'audio'))   $waType = 'audio';
            elseif (str_contains($mtype, 'video'))   $waType = 'video';
            elseif (str_contains($mtype, 'doc'))     $waType = 'document';

            try {
                $db->prepare(
                    "INSERT IGNORE INTO wa_messages
                         (conversation_id, message_id, direction, type, body, media_url, sent_at, is_read)
                     VALUES (?, ?, ?, ?, ?, ?, FROM_UNIXTIME(?), 1)"
                )->execute([$dbId, $msgId, $dir, $waType, mb_substr($body ?? '', 0, 5000), $mediaUrl, $ts]);
                if ((int)$db->lastInsertId() > 0) $importedMsgs++;
            } catch (\Throwable $_) {}
        }
    }
}

echo json_encode([
    'success'       => true,
    'new_convs'     => $newConvs,
    'updated_convs' => $updatedConvs,
    'total_chats'   => count($chats),
    'imported_msgs' => $importedMsgs,
    'with_history'  => $withHistory,
]);

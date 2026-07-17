<?php
/**
 * SMS helper — Africa's Talking gateway.
 * All functions silently swallow exceptions so they never break the main request.
 */

function sendSms(string $phone, string $message, string $refType = '', int $refId = 0): array {
    $phone = preg_replace('/[^\d+]/', '', $phone);
    if (!$phone) return ['ok' => false, 'error' => 'No phone number'];

    $apiKey   = getSetting('at_api_key', '');
    $username = getSetting('at_username', '');
    $senderId = getSetting('at_sender_id', '');

    if (!$apiKey || !$username) return ['ok' => false, 'error' => 'SMS not configured'];

    // Normalize to E.164 (Kenya)
    if (preg_match('/^0[17]/', $phone)) {
        $phone = '+254' . substr($phone, 1);
    } elseif (preg_match('/^254/', $phone) && !str_starts_with($phone, '+')) {
        $phone = '+' . $phone;
    }

    $fields = ['username' => $username, 'to' => $phone, 'message' => $message];
    if ($senderId) $fields['from'] = $senderId;

    try {
        $ch = curl_init('https://api.africastalking.com/version1/messaging');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($fields),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HTTPHEADER     => ['apiKey: ' . $apiKey, 'Accept: application/json'],
        ]);
        $raw = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);

        if ($err) {
            _logSms('sms', $phone, $message, $refType, $refId, 'failed', "cURL: $err");
            return ['ok' => false, 'error' => "cURL: $err"];
        }

        $data      = json_decode($raw, true);
        $recipient = $data['SMSMessageData']['Recipients'][0] ?? null;
        $ok        = $recipient && in_array($recipient['status'] ?? '', ['Success', 'MessageSent']);
        $status    = $ok ? 'sent' : 'failed';
        $errMsg    = $ok ? '' : ($recipient['status'] ?? 'Unknown error');

        _logSms('sms', $phone, $message, $refType, $refId, $status, substr($raw, 0, 500));

        return ['ok' => $ok, 'error' => $errMsg];
    } catch (\Throwable $e) {
        _logSms('sms', $phone, $message, $refType, $refId, 'failed', $e->getMessage());
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

function smsEnabled(): bool {
    return getSetting('at_api_key', '') !== '' && getSetting('at_username', '') !== '';
}

function _logSms(string $channel, string $phone, string $message, string $refType, int $refId, string $status, string $response): void {
    try {
        $db = getDB();
        $db->exec("CREATE TABLE IF NOT EXISTS sms_log (
            id         INT AUTO_INCREMENT PRIMARY KEY,
            channel    VARCHAR(20) DEFAULT 'sms',
            phone      VARCHAR(30) NOT NULL,
            message    TEXT,
            ref_type   VARCHAR(50),
            ref_id     INT DEFAULT 0,
            status     VARCHAR(20) DEFAULT 'sent',
            response   TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_ref (ref_type, ref_id),
            INDEX idx_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $db->prepare(
            "INSERT INTO sms_log (channel, phone, message, ref_type, ref_id, status, response) VALUES (?,?,?,?,?,?,?)"
        )->execute([$channel, $phone, $message, $refType, $refId, $status, $response]);
    } catch (\Throwable $_) {}
}

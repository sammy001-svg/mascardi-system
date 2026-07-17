<?php
/**
 * WhatsApp helper — Twilio WhatsApp gateway.
 * All functions silently swallow exceptions so they never break the main request.
 */

function sendWhatsApp(string $to, string $message, string $refType = '', int $refId = 0): array {
    $to = preg_replace('/[^\d+]/', '', $to);
    if (!$to) return ['ok' => false, 'error' => 'No phone number'];

    $sid   = getSetting('twilio_sid', '');
    $token = getSetting('twilio_token', '');
    $from  = getSetting('twilio_wa_from', '');

    if (!$sid || !$token || !$from) return ['ok' => false, 'error' => 'WhatsApp not configured'];

    // Normalize to E.164 (Kenya)
    if (preg_match('/^0[17]/', $to)) {
        $to = '+254' . substr($to, 1);
    } elseif (preg_match('/^254/', $to) && !str_starts_with($to, '+')) {
        $to = '+' . $to;
    }

    $waTo   = str_starts_with($to, 'whatsapp:')   ? $to   : 'whatsapp:' . $to;
    $waFrom = str_starts_with($from, 'whatsapp:') ? $from : 'whatsapp:' . $from;

    try {
        $url = "https://api.twilio.com/2010-04-01/Accounts/{$sid}/Messages.json";
        $ch  = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query(['From' => $waFrom, 'To' => $waTo, 'Body' => $message]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_USERPWD        => "$sid:$token",
        ]);
        $raw  = curl_exec($ch);
        $err  = curl_error($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($err) {
            require_once __DIR__ . '/sms.php';
            _logSms('whatsapp', $to, $message, $refType, $refId, 'failed', "cURL: $err");
            return ['ok' => false, 'error' => "cURL: $err"];
        }

        $data   = json_decode($raw, true);
        $ok     = $code >= 200 && $code < 300 && isset($data['sid']);
        $status = $ok ? 'sent' : 'failed';
        $errMsg = $ok ? '' : ($data['message'] ?? "HTTP $code");

        require_once __DIR__ . '/sms.php';
        _logSms('whatsapp', $to, $message, $refType, $refId, $status, substr($raw, 0, 500));

        return ['ok' => $ok, 'error' => $errMsg, 'sid' => $data['sid'] ?? ''];
    } catch (\Throwable $e) {
        require_once __DIR__ . '/sms.php';
        _logSms('whatsapp', $to, $message, $refType, $refId, 'failed', $e->getMessage());
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

function whatsappEnabled(): bool {
    return getSetting('twilio_sid', '') !== ''
        && getSetting('twilio_token', '') !== ''
        && getSetting('twilio_wa_from', '') !== '';
}

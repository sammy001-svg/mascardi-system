<?php
/**
 * Safaricom Daraja API — M-Pesa Integration
 *
 * Settings required (set in Admin → Settings):
 *   mpesa_consumer_key, mpesa_consumer_secret
 *   mpesa_shortcode, mpesa_passkey
 *   mpesa_env  (sandbox | production)
 *   mpesa_callback_url  (your public HTTPS URL, e.g. https://yourdomain.com/modules/payments/mpesa_callback.php)
 */

function mpesaBaseUrl(): string {
    return getSetting('mpesa_env', 'sandbox') === 'production'
        ? 'https://api.safaricom.co.ke'
        : 'https://sandbox.safaricom.co.ke';
}

/**
 * Get OAuth access token from Daraja API
 */
function mpesaAccessToken(): string {
    $key    = getSetting('mpesa_consumer_key', '');
    $secret = getSetting('mpesa_consumer_secret', '');

    if (!$key || !$secret) {
        throw new RuntimeException('M-Pesa consumer key/secret not configured in Settings.');
    }

    $url = mpesaBaseUrl() . '/oauth/v1/generate?grant_type=client_credentials';
    $ch  = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Authorization: Basic ' . base64_encode("{$key}:{$secret}")],
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => getSetting('mpesa_env', 'sandbox') === 'production',
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err      = curl_error($ch);
    curl_close($ch);

    if ($err) throw new RuntimeException("M-Pesa cURL error: {$err}");
    if ($httpCode !== 200) throw new RuntimeException("M-Pesa token request failed (HTTP {$httpCode}): {$response}");

    $data = json_decode($response, true);
    if (empty($data['access_token'])) {
        throw new RuntimeException('M-Pesa: no access_token in response.');
    }

    return $data['access_token'];
}

/**
 * Initiate STK Push (Lipa Na M-Pesa Online)
 *
 * @param  string $phone      Customer phone in format 254XXXXXXXXX
 * @param  float  $amount     Amount to charge (must be whole number KES)
 * @param  string $accountRef Short reference (invoice number, booking number, etc.)
 * @param  string $description Transaction description shown on phone
 * @return array  ['success' => bool, 'checkout_request_id' => string, 'error' => string]
 */
function mpesaStkPush(string $phone, float $amount, string $accountRef, string $description = 'Payment'): array {
    $result = ['success' => false, 'checkout_request_id' => '', 'error' => ''];

    try {
        $token       = mpesaAccessToken();
        $shortcode   = getSetting('mpesa_shortcode', '');
        $passkey     = getSetting('mpesa_passkey', '');
        $callbackUrl = getSetting('mpesa_callback_url', '');

        if (!$shortcode || !$passkey || !$callbackUrl) {
            throw new RuntimeException('M-Pesa shortcode, passkey, or callback URL not configured.');
        }

        $timestamp = date('YmdHis');
        $password  = base64_encode($shortcode . $passkey . $timestamp);
        $amount    = (int)ceil($amount); // M-Pesa only accepts whole numbers

        // Normalize phone: strip leading 0, +, spaces; ensure starts with 254
        $phone = preg_replace('/\D/', '', $phone);
        if (str_starts_with($phone, '0')) $phone = '254' . substr($phone, 1);
        if (str_starts_with($phone, '+')) $phone = ltrim($phone, '+');
        if (!str_starts_with($phone, '254')) $phone = '254' . $phone;

        $payload = [
            'BusinessShortCode' => $shortcode,
            'Password'          => $password,
            'Timestamp'         => $timestamp,
            'TransactionType'   => 'CustomerPayBillOnline',
            'Amount'            => $amount,
            'PartyA'            => $phone,
            'PartyB'            => $shortcode,
            'PhoneNumber'       => $phone,
            'CallBackURL'       => $callbackUrl,
            'AccountReference'  => substr($accountRef, 0, 12),
            'TransactionDesc'   => substr($description, 0, 13),
        ];

        $url = mpesaBaseUrl() . '/mpesa/stkpush/v1/processrequest';
        $ch  = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $token,
                'Content-Type: application/json',
            ],
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => getSetting('mpesa_env', 'sandbox') === 'production',
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err      = curl_error($ch);
        curl_close($ch);

        if ($err) throw new RuntimeException("cURL error: {$err}");

        $data = json_decode($response, true);

        if ($httpCode === 200 && isset($data['CheckoutRequestID'])) {
            $result['success']              = true;
            $result['checkout_request_id'] = $data['CheckoutRequestID'];
        } else {
            $errorMsg = $data['errorMessage'] ?? $data['ResponseDescription'] ?? "HTTP {$httpCode}: {$response}";
            throw new RuntimeException($errorMsg);
        }
    } catch (RuntimeException $e) {
        $result['error'] = $e->getMessage();
        error_log('M-Pesa STK Push Error: ' . $e->getMessage());
    }

    return $result;
}

/**
 * Query STK Push transaction status
 */
function mpesaStkQuery(string $checkoutRequestId): array {
    $result = ['success' => false, 'result_code' => null, 'result_desc' => '', 'error' => ''];

    try {
        $token     = mpesaAccessToken();
        $shortcode = getSetting('mpesa_shortcode', '');
        $passkey   = getSetting('mpesa_passkey', '');
        $timestamp = date('YmdHis');
        $password  = base64_encode($shortcode . $passkey . $timestamp);

        $payload = [
            'BusinessShortCode' => $shortcode,
            'Password'          => $password,
            'Timestamp'         => $timestamp,
            'CheckoutRequestID' => $checkoutRequestId,
        ];

        $url = mpesaBaseUrl() . '/mpesa/stkpushquery/v1/query';
        $ch  = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $token,
                'Content-Type: application/json',
            ],
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => getSetting('mpesa_env', 'sandbox') === 'production',
        ]);
        $response = curl_exec($ch);
        curl_close($ch);

        $data = json_decode($response, true);
        $result['success']     = true;
        $result['result_code'] = $data['ResultCode'] ?? null;
        $result['result_desc'] = $data['ResultDesc'] ?? '';
    } catch (RuntimeException $e) {
        $result['error'] = $e->getMessage();
    }

    return $result;
}

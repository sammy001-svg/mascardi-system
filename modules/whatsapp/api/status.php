<?php
/**
 * Where the connection stands — and the QR code when one is being waited for.
 *
 * Polled from the connect page every few seconds while an admin is scanning,
 * so it stays cheap and never throws: a failed poll should leave the last good
 * state on screen rather than replacing it with an error.
 */

require_once __DIR__ . '/../../../includes/functions.php';
require_once __DIR__ . '/../_wa.php';
requireLogin();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

// The QR is the key to the company's WhatsApp account. Only an administrator
// may ask for one, even though any permitted user may read the state.
$wantQr = !empty($_GET['qr']) && waCanAdmin();

if (!waCanUse() && !waCanAdmin()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'forbidden']);
    exit;
}

try {
    $s = waConfigured()
        ? waDriverStatus($wantQr)
        : ['state' => 'unconfigured', 'label' => 'Not set up', 'qr' => null,
           'phone' => '', 'detail' => 'No WhatsApp connection has been set up yet.'];
} catch (\Throwable $e) {
    error_log('wa status: ' . $e->getMessage());
    $s = ['state' => 'error', 'label' => 'Not connected', 'qr' => null,
          'phone' => '', 'detail' => 'The connection could not be checked just now.'];
}

echo json_encode([
    'ok'       => true,
    'state'    => $s['state'],
    'label'    => $s['label'],
    'detail'   => $s['detail'],
    'phone'    => $s['phone'],
    'qr'       => $wantQr ? $s['qr'] : null,
    'provider' => waProvider(),
], JSON_UNESCAPED_UNICODE);

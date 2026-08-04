<?php
/**
 * Call Centre — WebRTC capability token.
 *
 * The browser cannot hold the provider's API key, so it asks for a short-lived
 * token instead. The token is scoped to this one agent's client name, which is
 * what makes an inbound call ring in their browser and nobody else's.
 *
 * Requesting a token is also what registers the agent as on duty: it writes the
 * call_agents row the inbound router reads when deciding who to ring.
 */
require_once __DIR__ . '/../../../includes/functions.php';
require_once __DIR__ . '/../_bootstrap.php';
requireLogin();
canAccess('callcenter') || (function () {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'No call-centre access']);
    exit;
})();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$db = getDB();
ccMigrate($db);

$me   = authUser();
$meId = (int)$me['id'];
$cfg  = ccConfig();

$ready = ccReady($cfg);
if (!$ready['ok']) {
    echo json_encode(['ok' => false, 'error' => 'Call centre is not configured: ' . implode(', ', $ready['missing'])]);
    exit;
}

$clientName = ccClientName($meId);

// Register / refresh this agent. Done before the token request so the agent is
// visible to the router even if the provider is slow to answer.
try {
    $db->prepare("INSERT INTO call_agents (user_id, client_name, state, last_seen)
                  VALUES (?,?, 'available', NOW())
                  ON DUPLICATE KEY UPDATE
                      client_name = VALUES(client_name),
                      last_seen   = NOW(),
                      state       = IF(state = 'offline', 'available', state)")
       ->execute([$meId, $clientName]);
} catch (\Throwable $e) {
    error_log('cc/token agent register: ' . $e->getMessage());
}

// ── Ask the provider for a token ─────────────────────────────────────────────
$res = ccHttp($cfg['token_url'], [
    'username'    => $cfg['username'],
    'clientName'  => $clientName,
    'phoneNumber' => $cfg['caller_id'],
    'incoming'    => 'true',
    'outgoing'    => 'true',
    'lifeTimeSec' => '86400',
], ['apiKey: ' . $cfg['api_key']]);

if (!$res['ok']) {
    error_log('cc/token: provider ' . json_encode([$res['http'] ?? null, $res['error'] ?? null, substr($res['raw'] ?? '', 0, 300)]));
    echo json_encode([
        'ok'    => false,
        // Surfaced verbatim: a wrong key or an unprovisioned number both fail
        // here, and the provider's own wording is the fastest route to a fix.
        'error' => 'The provider would not issue a calling token. '
                 . ($res['error'] ?? ('HTTP ' . ($res['http'] ?? '?')))
                 . '. Check the API key, and that WebRTC calling is enabled on the account.',
        'detail' => substr((string)($res['raw'] ?? ''), 0, 400),
    ]);
    exit;
}

$token = $res['json']['token'] ?? ($res['json']['capabilityToken'] ?? null);
if (!$token) {
    echo json_encode([
        'ok'     => false,
        'error'  => 'The provider replied without a token.',
        'detail' => substr((string)($res['raw'] ?? ''), 0, 400),
    ]);
    exit;
}

echo json_encode([
    'ok'          => true,
    'token'       => $token,
    'client_name' => $clientName,
    'caller_id'   => $cfg['caller_id'],
    'agent'       => ['id' => $meId, 'name' => $me['name']],
]);

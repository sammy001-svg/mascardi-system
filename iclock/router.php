<?php
/**
 * ZKTeco ADMS / Push SDK endpoint.
 *
 * The device is configured with this server as its "Cloud Server" / "ADMS
 * server" and then opens the connection itself, which is what makes biometric
 * data reachable from a hosted server when the terminal sits on the yard LAN
 * behind NAT. Nothing here can be initiated from our side.
 *
 * The firmware calls a fixed set of paths under /iclock/ :
 *
 *   GET  /iclock/cdata?SN=..&options=all   handshake — we answer with config
 *   POST /iclock/cdata?SN=..&table=ATTLOG  attendance punches (tab separated)
 *   POST /iclock/cdata?SN=..&table=OPERLOG device/operator events
 *   GET  /iclock/getrequest?SN=..          device asks for queued commands
 *   POST /iclock/devicecmd?SN=..           results of those commands
 *
 * Replies must be plain text. The device expects the literal "OK" for accepted
 * POSTs; anything else — an HTML error page, a redirect, a stray warning — is
 * read as a failure and the terminal will retry the same batch indefinitely.
 * That is why this file is deliberately self-contained and never renders a
 * layout, and why output buffering is discarded before the reply is written.
 */

// The device is not a browser: no session, no CSRF, no redirect to login.
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../modules/hr/zk_bootstrap.php';

// Any notice printed above the response body would corrupt the reply.
while (ob_get_level() > 0) { ob_end_clean(); }
ob_start();

header('Content-Type: text/plain; charset=utf-8');

$db = getDB();
try { zkMigrate($db); } catch (\Throwable $_) {}

$cfg      = zkConfig();
$endpoint = strtolower(trim($_GET['__ep'] ?? basename(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: ''), '/'));
$sn       = trim($_GET['SN'] ?? $_GET['sn'] ?? '');
$method   = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$remoteIp = $_SERVER['REMOTE_ADDR'] ?? '';
$table    = strtoupper(trim($_GET['table'] ?? ''));

/** Reply and stop. Body must be exactly what the firmware expects. */
function zkReply(string $body, int $code = 200): void {
    while (ob_get_level() > 0) { ob_end_clean(); }
    http_response_code($code);
    header('Content-Type: text/plain; charset=utf-8');
    echo $body;
    exit;
}

function zkLog(PDO $db, ?string $sn, string $ep, string $method, string $table,
               int $recv, int $stored, string $note, string $body, string $ip): void {
    try {
        $db->prepare("INSERT INTO zk_push_log
                (device_sn, endpoint, method, table_name, rows_received, rows_stored, remote_ip, note, body)
             VALUES (?,?,?,?,?,?,?,?,?)")
           ->execute([$sn ?: null, $ep, $method, $table ?: null, $recv, $stored, $ip,
                      mb_substr($note, 0, 255), mb_substr($body, 0, 60000)]);
        // Keep the log useful rather than unbounded — devices poll constantly.
        if (random_int(1, 50) === 1) {
            $db->exec("DELETE FROM zk_push_log WHERE created_at < DATE_SUB(NOW(), INTERVAL 14 DAY)");
        }
    } catch (\Throwable $_) {}
}

// ── Optional shared secret ───────────────────────────────────────────────────
// The endpoint is public by necessity. When a key is configured the device URL
// must carry ?key=..., which stops anyone who merely guesses a serial number
// from posting attendance. Left blank, the serial whitelist below is the gate.
if ($cfg['push_key'] !== '' && !hash_equals($cfg['push_key'], (string)($_GET['key'] ?? ''))) {
    zkLog($db, $sn, $endpoint, $method, $table, 0, 0, 'Rejected: bad or missing key', '', $remoteIp);
    zkReply('Unauthorized', 401);
}

if ($sn === '') {
    zkLog($db, null, $endpoint, $method, $table, 0, 0, 'Rejected: no SN in query', '', $remoteIp);
    zkReply('Unauthorized', 401);
}

$device = zkDeviceFor($db, $sn, $remoteIp);
if (!$device) zkReply('Unauthorized', 401);

try {
    $db->prepare("UPDATE zk_devices SET last_seen_at = NOW(), ip_address = ? WHERE serial_number = ?")
       ->execute([$remoteIp ?: null, $sn]);
} catch (\Throwable $_) {}

// A device that has not been approved is answered politely so it keeps
// checking in — that is how it appears in the UI to be approved — but its
// data is not accepted.
$approved = ($device['status'] === 'active');

// ── GET /iclock/cdata — handshake ────────────────────────────────────────────
if ($endpoint === 'cdata' && $method === 'GET') {
    $fw = trim($_GET['pushver'] ?? '');
    $dt = trim($_GET['DeviceTime'] ?? '');
    if ($fw !== '' || $dt !== '') {
        try {
            $db->prepare("UPDATE zk_devices SET firmware = COALESCE(NULLIF(?,''), firmware),
                                                device_time = COALESCE(NULLIF(?,''), device_time)
                          WHERE serial_number = ?")->execute([$fw, $dt, $sn]);
        } catch (\Throwable $_) {}
    }

    zkLog($db, $sn, $endpoint, $method, $table, 0, 0,
          $approved ? 'Handshake' : 'Handshake from device awaiting approval', '', $remoteIp);

    // The registry block tells the terminal what to send and how often. Field
    // names are fixed by the firmware.
    $reply = "GET OPTION FROM: {$sn}\r\n"
           . "ATTLOGStamp=None\r\n"
           . "OPERLOGStamp=9999\r\n"
           . "ATTPHOTOStamp=None\r\n"
           . "ErrorDelay=30\r\n"
           . "Delay=30\r\n"
           . "TransTimes=00:00;14:00\r\n"
           . "TransInterval=1\r\n"
           . "TransFlag=1000000000\r\n"
           . "TimeZone=" . (int)getSetting('zk_timezone_offset', '3') . "\r\n"
           . "Realtime=1\r\n"
           . "Encrypt=0\r\n";
    zkReply($reply);
}

// ── POST /iclock/cdata — the device is sending data ──────────────────────────
if ($endpoint === 'cdata' && $method === 'POST') {
    $body = file_get_contents('php://input') ?: '';

    // ATTLOG is the only table that carries attendance. OPERLOG and the photo
    // tables are acknowledged so the device clears them and does not retry.
    if ($table !== '' && $table !== 'ATTLOG') {
        zkLog($db, $sn, $endpoint, $method, $table, 0, 0, "Acknowledged {$table} (not attendance)", $body, $remoteIp);
        zkReply('OK');
    }

    $parsed = zkParseAttlog($body);
    $recv   = count($parsed['rows']);

    if (!$approved) {
        zkLog($db, $sn, $endpoint, $method, 'ATTLOG', $recv, 0,
              'Device not approved — ' . $recv . ' punch(es) discarded', $body, $remoteIp);
        // Still "OK": telling the device it failed makes it retry the same
        // batch forever. Approving the device is the operator's action.
        zkReply('OK');
    }

    $res = zkStorePunches($db, $sn, $parsed['rows'], 'push');

    // Roll straight into attendance so the register is live, not overnight.
    $rolled = ['written' => 0];
    if ($cfg['auto_rollup'] && $res['days']) {
        $rolled = zkRollupRange($db, min($res['days']), max($res['days']), $cfg);
    }

    zkLog($db, $sn, $endpoint, $method, 'ATTLOG', $recv, $res['stored'],
          sprintf('stored %d, duplicate %d, unmapped %d, skipped %d, attendance rows %d',
                  $res['stored'], $res['duplicates'], $res['unmapped'],
                  $parsed['skipped'], $rolled['written'] ?? 0),
          $body, $remoteIp);

    zkReply('OK');
}

// ── GET /iclock/getrequest — device polling for commands ─────────────────────
if ($endpoint === 'getrequest') {
    zkLog($db, $sn, $endpoint, $method, '', 0, 0, 'Command poll', '', $remoteIp);
    // No command queue: the integration only reads attendance. "OK" means
    // nothing to do.
    zkReply('OK');
}

// ── POST /iclock/devicecmd — command results ─────────────────────────────────
if ($endpoint === 'devicecmd') {
    zkLog($db, $sn, $endpoint, $method, '', 0, 0, 'Command result',
          file_get_contents('php://input') ?: '', $remoteIp);
    zkReply('OK');
}

// ── ping / fdata / edata and anything else ───────────────────────────────────
zkLog($db, $sn, $endpoint ?: 'unknown', $method, $table, 0, 0, 'Unhandled endpoint', '', $remoteIp);
zkReply('OK');

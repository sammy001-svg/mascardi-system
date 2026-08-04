<?php
/**
 * Call Centre — inbound call handler (provider webhook).
 *
 * When a client rings the shared number, Africa's Talking POSTs here and reads
 * the XML we return as instructions. This is the whole of "clients can call
 * back": the number they see on their phone is the call centre's, and dialling
 * it lands on whichever agent is signed in.
 *
 * Point the provider's Voice callback URL at this file. It must be publicly
 * reachable and answer quickly — the caller is holding the line while it runs.
 *
 * Notes on responses
 * ------------------
 * The reply must be XML and nothing else. A PHP notice, a redirect, or an HTML
 * error page all read as a malformed response and the caller hears silence, so
 * output buffering is discarded before anything is written and every failure
 * path still returns valid XML.
 */
require_once __DIR__ . '/../../../includes/functions.php';
require_once __DIR__ . '/../_bootstrap.php';

while (ob_get_level() > 0) { ob_end_clean(); }
header('Content-Type: application/xml; charset=utf-8');

$db = getDB();
try { ccMigrate($db); } catch (\Throwable $_) {}
$cfg = ccConfig();

/** Reply and stop. */
function ccSay(string $xml): void {
    while (ob_get_level() > 0) { ob_end_clean(); }
    header('Content-Type: application/xml; charset=utf-8');
    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<Response>' . $xml . '</Response>';
    exit;
}

/** Spoken fallback — used whenever no agent can take the call. */
function ccVoicemail(string $message): void {
    ccSay(
        '<Say voice="woman">' . htmlspecialchars($message, ENT_XML1) . '</Say>'
        . '<Record finishOnKey="#" maxLength="90" trimSilence="true" playBeep="true">'
        . '<Say voice="woman">Please leave your name and message after the tone, then press hash.</Say>'
        . '</Record>'
    );
}

// ── Optional shared secret ───────────────────────────────────────────────────
// This endpoint has to be public for the provider to reach it, and the provider
// sends no credentials of its own. When a key is set in settings the callback
// URL must carry ?key=…, which stops anyone who finds the address from posting
// invented call records into the log. Left blank, the endpoint is open.
$expectedKey = getSetting('cc_callback_key', '');
if ($expectedKey !== '' && !hash_equals($expectedKey, (string)($_GET['key'] ?? ''))) {
    error_log('cc/voice_callback: rejected a request with a bad or missing key from '
              . ($_SERVER['REMOTE_ADDR'] ?? '?'));
    http_response_code(403);
    ccSay('<Say voice="woman">Configuration error. Goodbye.</Say>');
}

// ── Provider parameters ──────────────────────────────────────────────────────
$p            = $_POST ?: $_GET;
$sessionId    = (string)($p['sessionId']       ?? '');
$direction    = strtolower((string)($p['direction'] ?? 'inbound'));
$callerNumber = ccNormalizeNumber((string)($p['callerNumber'] ?? ''));
$destination  = (string)($p['destinationNumber'] ?? $cfg['caller_id']);
$isActive     = (string)($p['isActive'] ?? '1');
$status       = strtolower((string)($p['status'] ?? ''));
$durationSec  = (int)($p['durationInSeconds'] ?? $p['callSessionDuration'] ?? 0);
$amount       = $p['amount']   ?? null;
$currency     = $p['currencyCode'] ?? null;
$recording    = (string)($p['recordingUrl'] ?? '');
$hangupCause  = (string)($p['hangupCause'] ?? $p['callSessionState'] ?? '');

// ── Event notification (isActive = 0): the call has finished ─────────────────
// The provider posts a final summary. Nothing is spoken; just record it.
if ($isActive === '0' || $status === 'completed' || $hangupCause !== '') {
    try {
        $st = $db->prepare("SELECT id, answered_at, status FROM call_logs WHERE session_id = ?");
        $st->execute([$sessionId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);

        $final = 'completed';
        if (in_array($status, ['busy','no_answer','failed'], true))       $final = $status;
        elseif ($durationSec === 0 && ($row['answered_at'] ?? null) === null) $final = 'missed';

        $fields = [
            'status'        => $final,
            'duration_sec'  => $durationSec,
            'ended_at'      => date('Y-m-d H:i:s'),
            'hangup_cause'  => $hangupCause !== '' ? mb_substr($hangupCause, 0, 80) : null,
            'cost_amount'   => $amount !== null ? (float)preg_replace('/[^\d.]/', '', (string)$amount) : null,
            'cost_currency' => $currency ? mb_substr((string)$currency, 0, 8) : null,
            'recording_url' => $recording !== '' ? mb_substr($recording, 0, 500) : null,
        ];
        if ($row) {
            $sets = []; $args = [];
            foreach ($fields as $k => $v) { $sets[] = "`{$k}`=?"; $args[] = $v; }
            $args[] = (int)$row['id'];
            $db->prepare("UPDATE call_logs SET " . implode(',', $sets) . " WHERE id=?")->execute($args);

            // Free the agent and count the call. Matched on the agent the call
            // was assigned to as well as on current_call_id: an inbound call is
            // attributed by the router without ever setting current_call_id, so
            // matching on that alone missed every incoming call in the tally.
            $st2 = $db->prepare("SELECT agent_id FROM call_logs WHERE id = ?");
            $st2->execute([(int)$row['id']]);
            $agentId = (int)$st2->fetchColumn();
            if ($agentId) {
                $db->prepare("UPDATE call_agents
                              SET state = IF(state = 'busy', 'available', state),
                                  current_call_id = NULL,
                                  calls_today = calls_today + 1
                              WHERE user_id = ?")->execute([$agentId]);
            } else {
                $db->prepare("UPDATE call_agents SET state='available', current_call_id=NULL,
                              calls_today = calls_today + 1 WHERE current_call_id = ?")->execute([(int)$row['id']]);
            }
        } else {
            ccUpsertCall($db, array_merge($fields, [
                'session_id'  => $sessionId,
                'direction'   => $direction === 'outbound' ? 'outbound' : 'inbound',
                'from_number' => $callerNumber,
                'to_number'   => $destination,
                'caller_id'   => $cfg['caller_id'],
                'started_at'  => date('Y-m-d H:i:s'),
            ]));
        }
    } catch (\Throwable $e) {
        error_log('cc/voice_callback event: ' . $e->getMessage());
    }
    ccSay('');   // acknowledged, nothing to speak
}

// ── A live inbound call is waiting for instructions ──────────────────────────
if (!$cfg['enabled']) {
    ccSay('<Say voice="woman">Thank you for calling. Our phone service is currently unavailable. Please try again later.</Say>');
}

$who = ccIdentifyCaller($db, $callerNumber);

// Record the call before ringing anyone, so a caller who hangs up while it is
// ringing still shows as a missed call to follow up rather than vanishing.
$callId = ccUpsertCall($db, [
    'session_id'  => $sessionId ?: ('inb-' . time() . '-' . bin2hex(random_bytes(3))),
    'direction'   => 'inbound',
    'client_id'   => $who['client_id'],
    'lead_id'     => $who['lead_id'],
    'from_number' => $callerNumber,
    'to_number'   => $destination,
    'caller_id'   => $cfg['caller_id'],
    'status'      => 'ringing',
    'started_at'  => date('Y-m-d H:i:s'),
]);

$agents = ccAvailableAgents($db);

if (!$agents) {
    try {
        $db->prepare("UPDATE call_logs SET status='missed', ended_at=NOW() WHERE id=?")->execute([$callId]);
        // Tell the team, so a missed call is chased rather than merely logged.
        require_once __DIR__ . '/../../../includes/notifications.php';
        notifyRoles(['admin','super_admin','general_manager','sales_manager','customer_relations'],
            'call', 'Missed call — nobody was available',
            ($who['name'] ? $who['name'] . ' (' . ccPrettyNumber($callerNumber) . ')' : ccPrettyNumber($callerNumber))
            . ' rang the call centre and left a message.',
            BASE_URL . '/modules/callcenter/logs.php');
    } catch (\Throwable $_) {}

    ccVoicemail('Thank you for calling Mascardi Ventures. All our agents are busy at the moment.');
}

// Ring every free agent at once and connect the first to answer. Sequential
// hunting would make each caller wait out a full ring cycle per agent.
$targets = [];
foreach ($agents as $a) {
    $targets[] = $a['client_name'] . '.' . $cfg['username'];
}

try {
    // Attribute the call to the first agent now; the completion event corrects
    // it if someone else actually picks up.
    $db->prepare("UPDATE call_logs SET agent_id = ? WHERE id = ?")
       ->execute([(int)$agents[0]['user_id'], $callId]);
} catch (\Throwable $_) {}

$record = $cfg['record_calls'] ? ' record="true"' : '';
$greeting = $who['name']
    ? 'Connecting you now.'
    : 'Thank you for calling Mascardi Ventures. Connecting you to an agent.';

ccSay(
    '<Say voice="woman">' . htmlspecialchars($greeting, ENT_XML1) . '</Say>'
    . '<Dial phoneNumbers="' . htmlspecialchars(implode(',', $targets), ENT_XML1) . '"'
    . ' ringbackTone="" maxDuration="3600" sequential="false"' . $record . '/>'
);

<?php
/**
 * Call Centre — call actions from the dialer.
 *
 *   start    record an outbound call the browser is placing
 *   state    agent presence heartbeat (available / paused / busy / offline)
 *   update   progress a call the browser reports (ringing, answered, ended)
 *   note     attach a note to a call afterwards
 *   status   poll: my current call, agent roster, balance warning
 *
 * The audio path is browser-to-provider; this endpoint only keeps the record
 * straight and lets the rest of the system see what is happening.
 */
require_once __DIR__ . '/../../../includes/functions.php';
require_once __DIR__ . '/../_bootstrap.php';
requireLogin();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (!canAccess('callcenter')) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'No call-centre access']);
    exit;
}

$db = getDB();
ccMigrate($db);

$me   = authUser();
$meId = (int)$me['id'];
$cfg  = ccConfig();

$body   = json_decode(file_get_contents('php://input'), true) ?: [];
$action = $_GET['action'] ?? ($body['action'] ?? '');

try {
switch ($action) {

// ── Placing an outbound call ─────────────────────────────────────────────────
case 'start': {
    $to = ccNormalizeNumber((string)($body['to'] ?? ''));
    if ($to === '') {
        echo json_encode(['ok' => false, 'error' => 'That does not look like a valid phone number.']);
        exit;
    }

    $ready = ccReady($cfg);
    if (!$ready['ok']) {
        echo json_encode(['ok' => false, 'error' => 'Call centre is not configured: ' . implode(', ', $ready['missing'])]);
        exit;
    }

    // Refuse rather than dial when there is no airtime — the call would fail
    // at the provider anyway, and this gives a message that explains why.
    $bal = ccBalance();
    if (!empty($bal['ok']) && $bal['amount'] <= 0) {
        echo json_encode(['ok' => false, 'error' => 'No airtime left. Top up before making calls.', 'no_credit' => true]);
        exit;
    }

    $who = ccIdentifyCaller($db, $to);
    $callId = ccUpsertCall($db, [
        'session_id' => (string)($body['session_id'] ?? ('local-' . $meId . '-' . time() . '-' . bin2hex(random_bytes(3)))),
        'direction'  => 'outbound',
        'agent_id'   => $meId,
        'client_id'  => $who['client_id'],
        'lead_id'    => (int)($body['lead_id'] ?? 0) ?: $who['lead_id'],
        'from_number'=> $cfg['caller_id'],
        'to_number'  => $to,
        'caller_id'  => $cfg['caller_id'],
        'status'     => 'ringing',
        'started_at' => date('Y-m-d H:i:s'),
    ]);

    $db->prepare("UPDATE call_agents SET state='busy', current_call_id=?, last_seen=NOW() WHERE user_id=?")
       ->execute([$callId, $meId]);

    logActivity('create', 'callcenter', $callId, "Outbound call to {$to}");

    echo json_encode([
        'ok'        => true,
        'call_id'   => $callId,
        'to'        => $to,
        'display'   => ccPrettyNumber($to),
        'contact'   => $who['name'],
        'caller_id' => $cfg['caller_id'],
    ]);
    exit;
}

// ── Agent presence ───────────────────────────────────────────────────────────
case 'state': {
    $state = $body['state'] ?? 'available';
    if (!isset(ccAgentStates()[$state])) $state = 'available';
    $db->prepare("INSERT INTO call_agents (user_id, client_name, state, last_seen)
                  VALUES (?,?,?,NOW())
                  ON DUPLICATE KEY UPDATE state=VALUES(state), last_seen=NOW()")
       ->execute([$meId, ccClientName($meId), $state]);
    echo json_encode(['ok' => true, 'state' => $state]);
    exit;
}

// ── Call progress reported by the browser ────────────────────────────────────
case 'update': {
    $id = (int)($body['call_id'] ?? 0);
    if (!$id) { echo json_encode(['ok' => false, 'error' => 'call_id required']); exit; }

    $st = $db->prepare("SELECT * FROM call_logs WHERE id = ?");
    $st->execute([$id]);
    $call = $st->fetch(PDO::FETCH_ASSOC);
    if (!$call) { echo json_encode(['ok' => false, 'error' => 'No such call']); exit; }
    // An agent may only touch their own call; anyone with write access to the
    // module can correct any of them.
    if ((int)$call['agent_id'] !== $meId && !canWrite('callcenter')) {
        http_response_code(403); echo json_encode(['ok' => false, 'error' => 'Not your call']); exit;
    }

    $status = $body['status'] ?? '';
    if (!isset(ccCallStatuses()[$status])) { echo json_encode(['ok' => false, 'error' => 'Unknown status']); exit; }

    $fields = ['status' => $status];
    if ($status === 'active' && !$call['answered_at']) $fields['answered_at'] = date('Y-m-d H:i:s');
    if (in_array($status, ['completed','missed','failed','busy','no_answer'], true)) {
        $fields['ended_at'] = date('Y-m-d H:i:s');
        // Duration is measured from answer, not from dial — ring time is not
        // talk time and is not billed as such.
        $from = $call['answered_at'] ?? ($fields['answered_at'] ?? null);
        $fields['duration_sec'] = $from ? max(0, time() - strtotime($from)) : 0;
        if (!empty($body['hangup_cause'])) $fields['hangup_cause'] = mb_substr((string)$body['hangup_cause'], 0, 80);

        $db->prepare("UPDATE call_agents SET state='available', current_call_id=NULL,
                      calls_today = calls_today + 1, last_seen=NOW() WHERE user_id=?")->execute([$meId]);
    }

    $sets = []; $args = [];
    foreach ($fields as $k => $v) { $sets[] = "`{$k}` = ?"; $args[] = $v; }
    $args[] = $id;
    $db->prepare("UPDATE call_logs SET " . implode(', ', $sets) . " WHERE id = ?")->execute($args);

    echo json_encode(['ok' => true, 'status' => $status, 'duration' => $fields['duration_sec'] ?? null]);
    exit;
}

case 'note': {
    $id = (int)($body['call_id'] ?? 0);
    $db->prepare("UPDATE call_logs SET notes = ? WHERE id = ? AND (agent_id = ? OR ? = 1)")
       ->execute([trim((string)($body['notes'] ?? '')) ?: null, $id, $meId, canWrite('callcenter') ? 1 : 0]);
    echo json_encode(['ok' => true]);
    exit;
}

// ── Dialer poll ──────────────────────────────────────────────────────────────
case 'status': {
    $db->prepare("UPDATE call_agents SET last_seen = NOW() WHERE user_id = ?")->execute([$meId]);

    $out = ['ok' => true];

    // An inbound call the router has assigned to me but the browser has not
    // picked up yet — this is how a call-back reaches the right person.
    $st = $db->prepare("SELECT id, from_number, status, started_at, client_id, lead_id
                        FROM call_logs
                        WHERE direction = 'inbound' AND agent_id = ? AND status IN ('ringing','active')
                        ORDER BY id DESC LIMIT 1");
    $st->execute([$meId]);
    if ($inb = $st->fetch(PDO::FETCH_ASSOC)) {
        $who = ccIdentifyCaller($db, (string)$inb['from_number']);
        $inb['display'] = ccPrettyNumber($inb['from_number']);
        $inb['contact'] = $who['name'];
        $out['incoming'] = $inb;
    }

    $roster = $db->query("SELECT a.user_id, a.state, a.calls_today, u.name
                          FROM call_agents a JOIN users u ON u.id = a.user_id
                          WHERE a.last_seen > DATE_SUB(NOW(), INTERVAL 90 SECOND)
                          ORDER BY u.name")->fetchAll(PDO::FETCH_ASSOC);
    $out['agents'] = $roster;

    $bal = ccBalance();
    if (!empty($bal['ok'])) {
        $out['balance']  = ['amount' => $bal['amount'], 'currency' => $bal['currency']];
        $out['low_credit'] = $bal['amount'] <= $cfg['low_balance'];
        $out['minutes']  = ccEstimatedMinutes($bal, $cfg);
    }
    echo json_encode($out);
    exit;
}

default:
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Unknown action']);
}
} catch (\Throwable $e) {
    error_log('cc/call ' . $action . ': ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Call action failed']);
}

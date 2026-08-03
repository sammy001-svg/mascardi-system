<?php
/**
 * Meetings — WebRTC signalling for the virtual room.
 *
 * The media itself never touches this server: browsers connect directly to each
 * other. All this endpoint does is carry the handshake — session descriptions
 * and ICE candidates — between them, the same polled-database approach the 1:1
 * chat calls already use.
 *
 * Topology is a full mesh: every participant holds a peer connection to every
 * other. That needs no media server, which is the point, but the number of
 * connections grows with the square of the room size, so it suits the small
 * internal meetings this module is for rather than an all-hands.
 *
 * Actions:
 *   join    register in the room, return who else is here
 *   poll    heartbeat + collect signals addressed to me + current roster
 *   send    queue a signal for one other participant
 *   state   publish mic/camera/screen-share state
 *   leave   remove myself and tell the others
 */
require_once __DIR__ . '/../../../includes/functions.php';
require_once __DIR__ . '/../_bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (!isLoggedIn()) { http_response_code(401); echo json_encode(['error' => 'Unauthenticated']); exit; }

$me   = authUser();
$meId = (int)$me['id'];
$db   = getDB();
meetingsMigrate($db);

// GET is used for polling (no state change beyond the heartbeat); everything
// else is POST and goes through the normal CSRF check in requireLogin().
$body   = json_decode(file_get_contents('php://input'), true) ?: [];
$action = $_GET['action'] ?? ($body['action'] ?? '');
$meetingId = (int)($_GET['meeting_id'] ?? ($body['meeting_id'] ?? 0));

if (!$meetingId) { http_response_code(400); echo json_encode(['error' => 'meeting_id required']); exit; }

$st = $db->prepare("SELECT * FROM meetings WHERE id = ?");
$st->execute([$meetingId]);
$meeting = $st->fetch(PDO::FETCH_ASSOC);
if (!$meeting)                                    { http_response_code(404); echo json_encode(['error' => 'No such meeting']); exit; }
if (!meetingCanView($db, $meeting, $meId))        { http_response_code(403); echo json_encode(['error' => 'Not invited']); exit; }
if ($meeting['meeting_type'] === 'physical')      { http_response_code(400); echo json_encode(['error' => 'This meeting has no video room']); exit; }

/** Peers seen within the last 25s are considered present. */
const MEETING_PEER_TTL = 25;

function meetingRoster(PDO $db, int $meetingId, int $excludeUser = 0): array
{
    $sql = "SELECT p.user_id, p.mic_on, p.cam_on, p.sharing, p.joined_at, u.name
            FROM meeting_peers p JOIN users u ON u.id = p.user_id
            WHERE p.meeting_id = ?
              AND p.last_seen IS NOT NULL
              AND p.last_seen > DATE_SUB(NOW(), INTERVAL " . MEETING_PEER_TTL . " SECOND)";
    $args = [$meetingId];
    if ($excludeUser) { $sql .= " AND p.user_id <> ?"; $args[] = $excludeUser; }
    $sql .= " ORDER BY p.joined_at";
    $s = $db->prepare($sql);
    $s->execute($args);
    return array_map(static fn($r) => [
        'user_id' => (int)$r['user_id'],
        'name'    => $r['name'],
        'mic_on'  => (int)$r['mic_on'] === 1,
        'cam_on'  => (int)$r['cam_on'] === 1,
        'sharing' => (int)$r['sharing'] === 1,
    ], $s->fetchAll(PDO::FETCH_ASSOC));
}

try {
    switch ($action) {

    case 'join': {
        $token = bin2hex(random_bytes(8));
        $db->prepare("INSERT INTO meeting_peers (meeting_id,user_id,peer_token,last_seen)
                      VALUES (?,?,?,NOW())
                      ON DUPLICATE KEY UPDATE peer_token=VALUES(peer_token), last_seen=NOW(),
                                              joined_at=CURRENT_TIMESTAMP")
           ->execute([$meetingId, $meId, $token]);

        // A rejoin must not be met with stale signals from the previous session.
        $db->prepare("DELETE FROM meeting_signals WHERE meeting_id=? AND (to_user=? OR from_user=?)")
           ->execute([$meetingId, $meId, $meId]);

        // Mark attendance — the point of a virtual room is that this is known
        // without anyone taking a register.
        $db->prepare("UPDATE meeting_participants SET attended=1, joined_at=COALESCE(joined_at,NOW())
                      WHERE meeting_id=? AND user_id=?")->execute([$meetingId, $meId]);

        // Opening the room starts the meeting, so the status reflects reality.
        if ($meeting['status'] === 'scheduled') {
            $db->prepare("UPDATE meetings SET status='in_progress', actual_start=COALESCE(actual_start,NOW())
                          WHERE id=? AND status='scheduled'")->execute([$meetingId]);
        }

        echo json_encode([
            'ok'      => true,
            'me'      => ['user_id' => $meId, 'name' => $me['name']],
            'token'   => $token,
            // The newcomer offers to everyone already present. Making the
            // joiner always the initiator gives a deterministic direction and
            // avoids both sides offering at once ("glare").
            'peers'   => meetingRoster($db, $meetingId, $meId),
            'ttl'     => MEETING_PEER_TTL,
        ]);
        exit;
    }

    case 'poll': {
        $db->prepare("UPDATE meeting_peers SET last_seen=NOW() WHERE meeting_id=? AND user_id=?")
           ->execute([$meetingId, $meId]);

        // Read then delete: each signal is consumed exactly once, which keeps
        // the table tiny without a separate cleanup job.
        $s = $db->prepare("SELECT id, from_user, kind, payload FROM meeting_signals
                           WHERE meeting_id=? AND to_user=? ORDER BY id LIMIT 100");
        $s->execute([$meetingId, $meId]);
        $signals = $s->fetchAll(PDO::FETCH_ASSOC);

        if ($signals) {
            $ids = array_column($signals, 'id');
            $in  = implode(',', array_fill(0, count($ids), '?'));
            $db->prepare("DELETE FROM meeting_signals WHERE id IN ({$in})")->execute($ids);
        }

        // Abandoned rows from browsers that closed without saying goodbye.
        if (random_int(1, 20) === 1) {
            $db->prepare("DELETE FROM meeting_peers WHERE meeting_id=? AND
                          (last_seen IS NULL OR last_seen < DATE_SUB(NOW(), INTERVAL 120 SECOND))")
               ->execute([$meetingId]);
        }

        echo json_encode([
            'ok'      => true,
            'signals' => array_map(static fn($r) => [
                'from'    => (int)$r['from_user'],
                'kind'    => $r['kind'],
                'payload' => $r['payload'] !== null ? json_decode($r['payload'], true) : null,
            ], $signals),
            'peers'   => meetingRoster($db, $meetingId, $meId),
        ]);
        exit;
    }

    case 'send': {
        $to   = (int)($body['to'] ?? 0);
        $kind = $body['kind'] ?? '';
        if (!$to || !in_array($kind, ['offer','answer','ice','bye'], true)) {
            http_response_code(400); echo json_encode(['error' => 'to and kind required']); exit;
        }
        // The recipient must be in this meeting — otherwise this endpoint would
        // relay arbitrary payloads to any user id.
        $chk = $db->prepare("SELECT 1 FROM meeting_peers WHERE meeting_id=? AND user_id=? LIMIT 1");
        $chk->execute([$meetingId, $to]);
        if (!$chk->fetchColumn()) { echo json_encode(['ok' => true, 'skipped' => 'peer not in room']); exit; }

        $db->prepare("INSERT INTO meeting_signals (meeting_id,from_user,to_user,kind,payload)
                      VALUES (?,?,?,?,?)")
           ->execute([$meetingId, $meId, $to, $kind,
                      isset($body['payload']) ? json_encode($body['payload']) : null]);
        echo json_encode(['ok' => true]);
        exit;
    }

    case 'state': {
        $db->prepare("UPDATE meeting_peers SET mic_on=?, cam_on=?, sharing=?, last_seen=NOW()
                      WHERE meeting_id=? AND user_id=?")
           ->execute([
               !empty($body['mic_on'])  ? 1 : 0,
               !empty($body['cam_on'])  ? 1 : 0,
               !empty($body['sharing']) ? 1 : 0,
               $meetingId, $meId,
           ]);
        echo json_encode(['ok' => true]);
        exit;
    }

    case 'leave': {
        $peers = meetingRoster($db, $meetingId, $meId);
        $ins = $db->prepare("INSERT INTO meeting_signals (meeting_id,from_user,to_user,kind) VALUES (?,?,?,'bye')");
        foreach ($peers as $p) $ins->execute([$meetingId, $meId, $p['user_id']]);

        $db->prepare("DELETE FROM meeting_peers WHERE meeting_id=? AND user_id=?")->execute([$meetingId, $meId]);
        echo json_encode(['ok' => true]);
        exit;
    }

    default:
        http_response_code(400);
        echo json_encode(['error' => 'Unknown action']);
    }
} catch (\Throwable $e) {
    error_log('meetings/signal: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Signalling failed']);
}

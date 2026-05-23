<?php
// Chat API – WebRTC call signaling
require_once __DIR__ . '/../../../includes/functions.php';

header('Content-Type: application/json');

if (!isLoggedIn()) { http_response_code(401); echo json_encode(['error'=>'Unauthenticated']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['error'=>'POST only']); exit; }

$me   = authUser();
$db   = getDB();
$body = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $body['action'] ?? '';

// ── Helper: fetch a call by id ──────────────────────────────────────────────
function getCall(PDO $db, int $id): ?array {
    $s = $db->prepare("SELECT * FROM chat_calls WHERE id=?");
    $s->execute([$id]);
    return $s->fetch(PDO::FETCH_ASSOC) ?: null;
}

// ── Helper: verify user is caller or callee ─────────────────────────────────
function verifyCallAccess(array $call, int $userId): bool {
    return (int)$call['caller_id'] === $userId || (int)$call['callee_id'] === $userId;
}

switch ($action) {

    // ── INITIATE: caller starts a call ─────────────────────────────────────
    case 'initiate': {
        $convId   = (int)($body['conversation_id'] ?? 0);
        $calleeId = (int)($body['callee_id'] ?? 0);
        $callType = in_array($body['call_type'] ?? '', ['audio','video']) ? $body['call_type'] : 'audio';
        $offerSdp = $body['offer_sdp'] ?? '';

        if (!$convId || !$calleeId || !$offerSdp) {
            http_response_code(400); echo json_encode(['error'=>'conversation_id, callee_id, offer_sdp required']); exit;
        }

        // Verify caller is a participant
        $p = $db->prepare("SELECT 1 FROM chat_participants WHERE conversation_id=? AND user_id=?");
        $p->execute([$convId, $me['id']]);
        if (!$p->fetch()) { http_response_code(403); echo json_encode(['error'=>'Access denied']); exit; }

        // End any existing ringing calls from this user in this conversation
        $db->prepare("UPDATE chat_calls SET status='ended', ended_at=NOW()
                      WHERE conversation_id=? AND caller_id=? AND status='ringing'")
           ->execute([$convId, $me['id']]);

        $stmt = $db->prepare("
            INSERT INTO chat_calls (conversation_id, caller_id, callee_id, call_type, status, offer_sdp)
            VALUES (?, ?, ?, ?, 'ringing', ?)
        ");
        $stmt->execute([$convId, $me['id'], $calleeId, $callType, $offerSdp]);
        $callId = (int)$db->lastInsertId();

        // Insert a system message into conversation so callee sees it in polling
        $db->prepare("
            INSERT INTO chat_messages (conversation_id, sender_id, type, content)
            VALUES (?, ?, 'call', ?)
        ")->execute([$convId, $me['id'], "📞 Incoming {$callType} call"]);

        echo json_encode(['ok' => true, 'call_id' => $callId]);
        break;
    }

    // ── ANSWER: callee accepts, provides answer SDP ─────────────────────────
    case 'answer': {
        $callId    = (int)($body['call_id'] ?? 0);
        $answerSdp = $body['answer_sdp'] ?? '';

        if (!$callId || !$answerSdp) {
            http_response_code(400); echo json_encode(['error'=>'call_id and answer_sdp required']); exit;
        }

        $call = getCall($db, $callId);
        if (!$call || (int)$call['callee_id'] !== (int)$me['id']) {
            http_response_code(403); echo json_encode(['error'=>'Access denied']); exit;
        }
        if ($call['status'] !== 'ringing') {
            http_response_code(409); echo json_encode(['error'=>'Call not in ringing state']); exit;
        }

        $db->prepare("
            UPDATE chat_calls SET status='active', answer_sdp=?, answered_at=NOW()
            WHERE id=?
        ")->execute([$answerSdp, $callId]);

        echo json_encode(['ok' => true]);
        break;
    }

    // ── REJECT: callee rejects the call ────────────────────────────────────
    case 'reject': {
        $callId = (int)($body['call_id'] ?? 0);
        if (!$callId) { http_response_code(400); echo json_encode(['error'=>'call_id required']); exit; }

        $call = getCall($db, $callId);
        if (!$call || (int)$call['callee_id'] !== (int)$me['id']) {
            http_response_code(403); echo json_encode(['error'=>'Access denied']); exit;
        }

        $db->prepare("UPDATE chat_calls SET status='rejected', ended_at=NOW() WHERE id=?")
           ->execute([$callId]);

        echo json_encode(['ok' => true]);
        break;
    }

    // ── ICE: store ICE candidates ───────────────────────────────────────────
    case 'ice': {
        $callId     = (int)($body['call_id'] ?? 0);
        $candidates = $body['candidates'] ?? null;   // JSON array

        if (!$callId || !is_array($candidates)) {
            http_response_code(400); echo json_encode(['error'=>'call_id and candidates[] required']); exit;
        }

        $call = getCall($db, $callId);
        if (!$call || !verifyCallAccess($call, (int)$me['id'])) {
            http_response_code(403); echo json_encode(['error'=>'Access denied']); exit;
        }

        $isCaller = ((int)$call['caller_id'] === (int)$me['id']);
        $col      = $isCaller ? 'caller_ice' : 'callee_ice';

        // Merge with existing candidates
        $existing  = json_decode($call[$col] ?? '[]', true) ?: [];
        $merged    = array_merge($existing, $candidates);

        $db->prepare("UPDATE chat_calls SET {$col}=? WHERE id=?")
           ->execute([json_encode($merged), $callId]);

        echo json_encode(['ok' => true]);
        break;
    }

    // ── STATUS: poll call record (caller polls for answer SDP + callee ICE) ─
    case 'status': {
        $callId = (int)($body['call_id'] ?? 0);
        if (!$callId) { http_response_code(400); echo json_encode(['error'=>'call_id required']); exit; }

        $call = getCall($db, $callId);
        if (!$call || !verifyCallAccess($call, (int)$me['id'])) {
            http_response_code(403); echo json_encode(['error'=>'Access denied']); exit;
        }

        echo json_encode([
            'call_id'    => (int)$call['id'],
            'status'     => $call['status'],
            'call_type'  => $call['call_type'],
            'caller_id'  => (int)$call['caller_id'],
            'callee_id'  => (int)$call['callee_id'],
            'offer_sdp'  => $call['offer_sdp'],
            'answer_sdp' => $call['answer_sdp'],
            'caller_ice' => json_decode($call['caller_ice'] ?? '[]'),
            'callee_ice' => json_decode($call['callee_ice'] ?? '[]'),
        ]);
        break;
    }

    // ── INCOMING: callee polls for incoming ringing call directed at them ───
    case 'incoming': {
        $stmt = $db->prepare("
            SELECT cc.id, cc.caller_id, cc.call_type, cc.offer_sdp, cc.conversation_id,
                   u.name AS caller_name
            FROM chat_calls cc
            JOIN users u ON u.id = cc.caller_id
            WHERE cc.callee_id = ?
              AND cc.status = 'ringing'
            ORDER BY cc.started_at DESC
            LIMIT 1
        ");
        $stmt->execute([$me['id']]);
        $incoming = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($incoming) {
            echo json_encode(['incoming' => true, 'call' => $incoming]);
        } else {
            echo json_encode(['incoming' => false]);
        }
        break;
    }

    // ── END: either party hangs up ─────────────────────────────────────────
    case 'end': {
        $callId = (int)($body['call_id'] ?? 0);
        if (!$callId) { http_response_code(400); echo json_encode(['error'=>'call_id required']); exit; }

        $call = getCall($db, $callId);
        if (!$call || !verifyCallAccess($call, (int)$me['id'])) {
            http_response_code(403); echo json_encode(['error'=>'Access denied']); exit;
        }

        $finalStatus = ($call['status'] === 'ringing') ? 'missed' : 'ended';
        $db->prepare("UPDATE chat_calls SET status=?, ended_at=NOW() WHERE id=?")
           ->execute([$finalStatus, $callId]);

        echo json_encode(['ok' => true, 'final_status' => $finalStatus]);
        break;
    }

    default:
        http_response_code(400); echo json_encode(['error'=>'Unknown action']);
}

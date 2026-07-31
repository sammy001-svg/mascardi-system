<?php
/**
 * Team chat — unified poll.
 *
 * The client used to run three independent timers: messages every 2s, typing
 * every 2s, and an incoming-call check every 5s. That is 72 HTTP requests a
 * minute per open chat, each paying its own connection, session load, auth
 * check and (previously) schema DDL.
 *
 * This returns all of it in one response, so the same information costs one
 * request instead of three. It is also cheap when nothing has happened, which
 * is the overwhelmingly common case: the client sends the cursors it already
 * holds and anything unchanged is simply omitted from the reply.
 */
require_once __DIR__ . '/../../../includes/functions.php';
require_once __DIR__ . '/../chat_bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (!isLoggedIn()) { http_response_code(401); echo json_encode(['error' => 'Unauthenticated']); exit; }

$me = authUser();
$db = getDB();
chatMigrate($db);

$meId    = (int)$me['id'];
$convId  = (int)($_GET['conversation_id'] ?? 0);
$after   = (int)($_GET['after'] ?? 0);
// Cheap change-detector for the conversation list: the client sends back the
// value we gave it, and the list is only rebuilt when it actually moves.
$listSig = (string)($_GET['list_sig'] ?? '');

$out = [
    'ok'       => true,
    'server_t' => time(),
];

try {
    chatTouchPresence($db, $meId);

    // ── Messages in the open conversation ────────────────────────────────────
    if ($convId > 0 && chatIsParticipant($db, $convId, $meId)) {

        $st = $db->prepare("
            SELECT cm.id, cm.conversation_id, cm.sender_id, cm.type,
                   cm.content, cm.file_path, cm.file_name, cm.file_size, cm.mime_type,
                   cm.duration, cm.is_deleted, cm.created_at, cm.reply_to_id, cm.edited_at,
                   u.name AS sender_name, u.role AS sender_role,
                   rm.type      AS reply_to_type,
                   rm.content   AS reply_to_content,
                   rm.file_name AS reply_to_file_name,
                   ru.name      AS reply_to_sender_name
            FROM chat_messages cm
            JOIN users u ON u.id = cm.sender_id
            LEFT JOIN chat_messages rm ON rm.id = cm.reply_to_id
            LEFT JOIN users ru ON ru.id = rm.sender_id
            WHERE cm.conversation_id = ? AND cm.id > ? AND cm.is_deleted = 0
            ORDER BY cm.id ASC
            LIMIT 100
        ");
        $st->execute([$convId, $after]);
        $msgs = $st->fetchAll(PDO::FETCH_ASSOC);
        if ($msgs) $out['messages'] = $msgs;

        // Mark everything delivered as read for this reader.
        $maxId = $msgs ? (int)end($msgs)['id'] : 0;
        if ($maxId > 0) {
            $db->prepare("UPDATE chat_participants SET last_read_msg_id = ?
                          WHERE conversation_id = ? AND user_id = ? AND last_read_msg_id < ?")
               ->execute([$maxId, $convId, $meId, $maxId]);
        }

        // Lowest read position among everyone else — drives the read ticks.
        $rm = $db->prepare("SELECT MIN(last_read_msg_id) FROM chat_participants
                            WHERE conversation_id = ? AND user_id <> ?");
        $rm->execute([$convId, $meId]);
        $out['read_min'] = (int)$rm->fetchColumn();

        // Who is typing (entries go stale after 6 seconds).
        $tp = $db->prepare("SELECT u.id, u.name FROM chat_typing ct
                            JOIN users u ON u.id = ct.user_id
                            WHERE ct.conversation_id = ? AND ct.user_id <> ?
                              AND ct.updated_at > DATE_SUB(NOW(), INTERVAL 6 SECOND)");
        $tp->execute([$convId, $meId]);
        $out['typing'] = $tp->fetchAll(PDO::FETCH_ASSOC);

        // Reactions on the visible window, so the client need not ask separately.
        if ($msgs) {
            $ids = array_column($msgs, 'id');
            $in  = implode(',', array_fill(0, count($ids), '?'));
            $rs  = $db->prepare("SELECT message_id, emoji, COUNT(*) n,
                                        SUM(user_id = ?) AS mine
                                 FROM chat_reactions WHERE message_id IN ({$in})
                                 GROUP BY message_id, emoji");
            $rs->execute(array_merge([$meId], $ids));
            $out['reactions'] = $rs->fetchAll(PDO::FETCH_ASSOC);
        }
    }

    // ── Conversation list signature + unread total ───────────────────────────
    // A change-detector, so the sidebar is only rebuilt when something actually
    // moved. Deliberately a PHP loop over one indexed MAX per conversation
    // rather than a single aggregate: MariaDB applies the MIN/MAX index
    // optimisation to a standalone MAX (~0.1 ms) but not to the same MAX inside
    // a JOIN or a correlated subquery, where it walks every message row instead.
    // Measured over 10 conversations / 21k messages: 1.3 ms here vs 8.7 ms for
    // the aggregate, and the gap widens as history grows.
    $parts = $db->prepare("SELECT conversation_id, last_read_msg_id FROM chat_participants WHERE user_id = ?");
    $parts->execute([$meId]);
    $myConvs = $parts->fetchAll(PDO::FETCH_ASSOC);

    $maxStmt = $db->prepare("SELECT MAX(id) FROM chat_messages WHERE conversation_id = ? AND is_deleted = 0");
    $sigParts = [];
    foreach ($myConvs as $p) {
        $maxStmt->execute([(int)$p['conversation_id']]);
        $sigParts[] = $p['conversation_id'] . ':' . (int)$maxStmt->fetchColumn() . ':' . (int)$p['last_read_msg_id'];
    }
    // Hashed, not sent verbatim: the raw form grows with every conversation and
    // the client echoes it back on each poll, so it would add a few hundred
    // bytes to every request forever. The client treats it as opaque.
    $newSig = substr(md5(implode(';', $sigParts)), 0, 16);

    $out['list_sig']     = $newSig;
    $out['list_changed'] = ($listSig !== '' && $listSig !== $newSig);

    // Counting unread is the expensive half, so it only runs when the signature
    // moved (or on the client's first poll, which sends no signature).
    if ($listSig === '' || $out['list_changed']) {
        $unreadStmt = $db->prepare("SELECT COUNT(*) FROM chat_messages
                                    WHERE conversation_id = ? AND is_deleted = 0
                                      AND id > ? AND sender_id <> ?");
        $unread = 0;
        foreach ($myConvs as $p) {
            $unreadStmt->execute([(int)$p['conversation_id'], (int)$p['last_read_msg_id'], $meId]);
            $unread += (int)$unreadStmt->fetchColumn();
        }
        $out['unread'] = $unread;
    }

    // ── Incoming call ────────────────────────────────────────────────────────
    // Ringing entries older than 45s are abandoned attempts; treating them as
    // live would make a phone ring for a call nobody is on.
    $cl = $db->prepare("SELECT c.id, c.conversation_id, c.caller_id, c.call_type, c.offer_sdp,
                               u.name AS caller_name
                        FROM chat_calls c
                        JOIN users u ON u.id = c.caller_id
                        WHERE c.callee_id = ? AND c.status = 'ringing'
                          AND c.started_at > DATE_SUB(NOW(), INTERVAL 45 SECOND)
                        ORDER BY c.id DESC LIMIT 1");
    $cl->execute([$meId]);
    if ($call = $cl->fetch(PDO::FETCH_ASSOC)) $out['incoming_call'] = $call;

} catch (\Throwable $e) {
    error_log('chat/poll: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Poll failed']);
    exit;
}

echo json_encode($out);

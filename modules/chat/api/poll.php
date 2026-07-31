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
    // One row: the newest message id anywhere I participate, how many rows are
    // unread, and the newest activity timestamp. Cheap, and enough to know
    // whether the sidebar needs rebuilding at all.
    $sig = $db->prepare("
        SELECT COALESCE(MAX(cm.id), 0) AS max_id,
               COALESCE(SUM(cm.id > cp.last_read_msg_id AND cm.sender_id <> ?), 0) AS unread
        FROM chat_participants cp
        JOIN chat_messages cm ON cm.conversation_id = cp.conversation_id AND cm.is_deleted = 0
        WHERE cp.user_id = ?
    ");
    $sig->execute([$meId, $meId]);
    $row = $sig->fetch(PDO::FETCH_ASSOC) ?: ['max_id' => 0, 'unread' => 0];

    $newSig = $row['max_id'] . ':' . $row['unread'];
    $out['unread']       = (int)$row['unread'];
    $out['list_sig']     = $newSig;
    $out['list_changed'] = ($listSig !== '' && $listSig !== $newSig);

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

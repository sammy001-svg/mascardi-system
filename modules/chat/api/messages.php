<?php
// Chat API – Fetch messages for a conversation
require_once __DIR__ . '/../../../includes/functions.php';

header('Content-Type: application/json');

if (!isLoggedIn()) { http_response_code(401); echo json_encode(['error'=>'Unauthenticated']); exit; }

$me     = authUser();
$db     = getDB();
$convId  = (int)($_GET['conversation_id'] ?? 0);
$after   = (int)($_GET['after']  ?? 0);
$before  = isset($_GET['before'])  ? (int)$_GET['before']  : null;
$initial = !empty($_GET['initial']);

if (!$convId) { http_response_code(400); echo json_encode(['error'=>'conversation_id required']); exit; }

const PAGE = 50;

try {
    // Verify participant
    $check = $db->prepare("SELECT last_read_msg_id FROM chat_participants WHERE conversation_id=? AND user_id=?");
    $check->execute([$convId, $me['id']]);
    $participant = $check->fetch();
    if (!$participant) { http_response_code(403); echo json_encode(['error'=>'Access denied']); exit; }

    // ── Schema upgrades (safe, idempotent) ────────────────────────────────
    try { $db->exec("CREATE TABLE IF NOT EXISTS chat_reactions (
        id         INT AUTO_INCREMENT PRIMARY KEY,
        message_id INT NOT NULL,
        user_id    INT NOT NULL,
        emoji      VARCHAR(20) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uk_react (message_id, user_id),
        KEY        idx_msg  (message_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); } catch (\Throwable $t) {}

    try { $db->exec("ALTER TABLE users ADD COLUMN last_seen TIMESTAMP NULL DEFAULT NULL"); } catch (\Throwable $t) {}

    // Update caller's last_seen
    try { $db->prepare("UPDATE users SET last_seen = NOW() WHERE id = ?")->execute([$me['id']]); } catch (\Throwable $t) {}

    // ── Build message query ────────────────────────────────────────────────
    $cols = "
        cm.id, cm.conversation_id, cm.sender_id, cm.type,
        cm.content, cm.file_path, cm.file_name, cm.file_size, cm.mime_type,
        cm.duration, cm.is_deleted, cm.created_at, cm.reply_to_id,
        u.name  AS sender_name,  u.role AS sender_role,
        rm.type      AS reply_to_type,
        rm.content   AS reply_to_content,
        rm.file_name AS reply_to_file_name,
        ru.name      AS reply_to_sender_name
    ";
    $joins = "
        JOIN users u ON u.id = cm.sender_id
        LEFT JOIN chat_messages rm ON rm.id = cm.reply_to_id
        LEFT JOIN users ru ON ru.id = rm.sender_id
    ";

    $hasMore  = false;
    $messages = [];

    if ($initial || $before !== null) {
        $wherePart = $initial
            ? "cm.conversation_id = ? AND cm.is_deleted = 0"
            : "cm.conversation_id = ? AND cm.id < ? AND cm.is_deleted = 0";
        $params = $initial ? [$convId] : [$convId, $before];

        $stmt = $db->prepare("
            SELECT {$cols}
            FROM chat_messages cm {$joins}
            WHERE {$wherePart}
            ORDER BY cm.id DESC
            LIMIT " . (PAGE + 1) . "
        ");
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $hasMore  = count($rows) > PAGE;
        if ($hasMore) array_pop($rows);
        $messages = array_reverse($rows);

    } else {
        $stmt = $db->prepare("
            SELECT {$cols}
            FROM chat_messages cm {$joins}
            WHERE cm.conversation_id = ?
              AND cm.id > ?
              AND cm.is_deleted = 0
            ORDER BY cm.id ASC
            LIMIT 100
        ");
        $stmt->execute([$convId, $after]);
        $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // ── Mark as read ──────────────────────────────────────────────────────
    if (!empty($messages)) {
        $maxId = max(array_column($messages, 'id'));
        if ($maxId > (int)$participant['last_read_msg_id']) {
            $db->prepare("UPDATE chat_participants SET last_read_msg_id=? WHERE conversation_id=? AND user_id=?")
               ->execute([$maxId, $convId, $me['id']]);
        }
    }

    // ── Minimum read_msg_id from other participants (for read receipts) ───
    $readMin = 0;
    try {
        $rmStmt = $db->prepare("
            SELECT COALESCE(MIN(last_read_msg_id), 0)
            FROM chat_participants
            WHERE conversation_id = ? AND user_id != ?
        ");
        $rmStmt->execute([$convId, $me['id']]);
        $readMin = (int)$rmStmt->fetchColumn();
    } catch (\Throwable $t) {}

    // ── Reactions ─────────────────────────────────────────────────────────
    $reactMap = [];
    if (!empty($messages)) {
        try {
            $msgIds = implode(',', array_map('intval', array_column($messages, 'id')));
            $rStmt  = $db->query("
                SELECT cr.message_id, cr.emoji, cr.user_id, u.name AS uname
                FROM chat_reactions cr
                JOIN users u ON u.id = cr.user_id
                WHERE cr.message_id IN ($msgIds)
                ORDER BY cr.created_at ASC
            ");
            foreach ($rStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $mid = (int)$r['message_id'];
                $em  = $r['emoji'];
                if (!isset($reactMap[$mid][$em])) {
                    $reactMap[$mid][$em] = ['n' => 0, 'm' => false, 'u' => []];
                }
                $reactMap[$mid][$em]['n']++;
                $reactMap[$mid][$em]['u'][] = $r['uname'];
                if ((int)$r['user_id'] === (int)$me['id']) $reactMap[$mid][$em]['m'] = true;
            }
        } catch (\Throwable $t) {}
    }

    // ── Normalise messages ────────────────────────────────────────────────
    $baseUrl = BASE_URL . '/uploads/chat/';
    foreach ($messages as &$msg) {
        $mid = (int)$msg['id'];
        $msg['id']         = $mid;
        $msg['sender_id']  = (int)$msg['sender_id'];
        $msg['is_deleted'] = (bool)$msg['is_deleted'];
        $msg['is_mine']    = ($msg['sender_id'] === (int)$me['id']);
        $msg['file_url']   = $msg['file_path'] ? $baseUrl . basename($msg['file_path']) : null;
        if ($msg['reply_to_id']) $msg['reply_to_id'] = (int)$msg['reply_to_id'];

        // Reactions array: [{e, n, m, u}]
        $reactions = [];
        foreach ($reactMap[$mid] ?? [] as $emoji => $data) {
            $reactions[] = [
                'e' => $emoji,
                'n' => $data['n'],
                'm' => $data['m'],
                'u' => implode(', ', $data['u']),
            ];
        }
        $msg['reactions'] = $reactions;
    }
    unset($msg);

    echo json_encode([
        'messages' => $messages,
        'has_more' => $hasMore,
        'my_id'    => (int)$me['id'],
        'read_min' => $readMin,
    ]);

} catch (Exception $e) {
    echo json_encode(['messages' => [], 'has_more' => false, 'my_id' => (int)$me['id'], 'read_min' => 0]);
}

<?php
// Chat API – Conversation list & create direct chat
require_once __DIR__ . '/../../../includes/functions.php';

header('Content-Type: application/json');

if (!isLoggedIn()) { http_response_code(401); echo json_encode(['error'=>'Unauthenticated']); exit; }

$me  = authUser();
$db  = getDB();
$method = $_SERVER['REQUEST_METHOD'];

// ── GET: list my conversations ─────────────────────────────────────────────
if ($method === 'GET') {
    try {
        $rows = $db->prepare("
            SELECT
                cc.id, cc.type, cc.name,
                cm_last.id        AS last_msg_id,
                cm_last.type      AS last_msg_type,
                cm_last.content   AS last_msg_content,
                cm_last.file_name AS last_msg_file,
                cm_last.created_at AS last_msg_at,
                u_last.name       AS last_sender_name,
                u_last.id         AS last_sender_id,
                cp.last_read_msg_id,
                (SELECT COUNT(*) FROM chat_messages cm2
                 WHERE cm2.conversation_id = cc.id
                   AND cm2.id > cp.last_read_msg_id
                   AND cm2.sender_id <> ?
                   AND cm2.is_deleted = 0) AS unread_count,
                u_other.id        AS other_user_id,
                u_other.name      AS other_user_name,
                u_other.role      AS other_user_role
            FROM chat_participants cp
            JOIN chat_conversations cc ON cc.id = cp.conversation_id
            LEFT JOIN chat_messages cm_last ON cm_last.id = (
                SELECT MAX(id) FROM chat_messages
                WHERE conversation_id = cc.id AND is_deleted = 0
            )
            LEFT JOIN users u_last ON u_last.id = cm_last.sender_id
            LEFT JOIN chat_participants cp2 ON cp2.conversation_id = cc.id AND cp2.user_id <> ?
            LEFT JOIN users u_other ON u_other.id = cp2.user_id AND cc.type = 'direct'
            WHERE cp.user_id = ?
            ORDER BY COALESCE(cm_last.created_at, cc.created_at) DESC
        ");
        $rows->execute([$me['id'], $me['id'], $me['id']]);
        $convs = $rows->fetchAll(PDO::FETCH_ASSOC);

        foreach ($convs as &$c) {
            $c['display_name'] = ($c['type'] === 'direct')
                ? ($c['other_user_name'] ?? 'Unknown')
                : ($c['name'] ?? 'Group');
            if ($c['last_msg_type'] === 'voice')       { $c['last_preview'] = '🎤 Voice note'; }
            elseif ($c['last_msg_type'] === 'image')   { $c['last_preview'] = '📷 Photo'; }
            elseif ($c['last_msg_type'] === 'file')    { $c['last_preview'] = '📎 ' . ($c['last_msg_file'] ?? 'File'); }
            elseif ($c['last_msg_type'] === 'call')    { $c['last_preview'] = '📞 ' . ($c['last_msg_content'] ?? 'Call'); }
            elseif ($c['last_msg_type'] === 'system')  { $c['last_preview'] = $c['last_msg_content'] ?? ''; }
            else                                        { $c['last_preview'] = $c['last_msg_content'] ?? ''; }
        }
        unset($c);

        echo json_encode(['conversations' => $convs]);
    } catch (Exception $e) {
        // Tables may not exist yet — return empty list so UI stays functional
        echo json_encode(['conversations' => [], 'db_error' => true]);
    }
    exit;
}

// ── POST: start or find a direct conversation ──────────────────────────────
if ($method === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    $targetId = (int)($body['user_id'] ?? 0);

    if (!$targetId || $targetId === (int)$me['id']) {
        http_response_code(400); echo json_encode(['error'=>'Invalid user']); exit;
    }

    try {
        // Check target exists
        $target = $db->prepare("SELECT id FROM users WHERE id=?");
        $target->execute([$targetId]);
        if (!$target->fetch()) {
            http_response_code(404); echo json_encode(['error'=>'User not found']); exit;
        }

        // Find existing direct conversation between the two
        $existing = $db->prepare("
            SELECT cc.id FROM chat_conversations cc
            JOIN chat_participants cp1 ON cp1.conversation_id = cc.id AND cp1.user_id = ?
            JOIN chat_participants cp2 ON cp2.conversation_id = cc.id AND cp2.user_id = ?
            WHERE cc.type = 'direct'
            LIMIT 1
        ");
        $existing->execute([$me['id'], $targetId]);
        $conv = $existing->fetch();

        if ($conv) {
            echo json_encode(['conversation_id' => (int)$conv['id'], 'existing' => true]);
            exit;
        }

        // Create new direct conversation
        $db->beginTransaction();
        $db->prepare("INSERT INTO chat_conversations (type, created_by) VALUES ('direct', ?)")
           ->execute([$me['id']]);
        $convId = (int)$db->lastInsertId();

        $ins = $db->prepare("INSERT INTO chat_participants (conversation_id, user_id) VALUES (?,?)");
        $ins->execute([$convId, $me['id']]);
        $ins->execute([$convId, $targetId]);

        $db->commit();
        echo json_encode(['conversation_id' => $convId, 'existing' => false]);

    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        http_response_code(500);
        echo json_encode(['error' => 'Chat tables not set up. Please visit /modules/chat/setup.php first.']);
    }
    exit;
}

http_response_code(405); echo json_encode(['error'=>'Method not allowed']);

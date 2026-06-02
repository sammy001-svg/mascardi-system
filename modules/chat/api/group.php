<?php
// Chat API – Group conversation management
require_once __DIR__ . '/../../../includes/functions.php';

header('Content-Type: application/json');

if (!isLoggedIn()) { http_response_code(401); echo json_encode(['error'=>'Unauthenticated']); exit; }

$me     = authUser();
$db     = getDB();
$method = $_SERVER['REQUEST_METHOD'];

// ── GET: group info + members ───────────────────────────────────────────────
if ($method === 'GET') {
    $convId = (int)($_GET['conversation_id'] ?? 0);
    if (!$convId) { http_response_code(400); echo json_encode(['error'=>'conversation_id required']); exit; }

    // Verify participant
    $check = $db->prepare("SELECT 1 FROM chat_participants WHERE conversation_id=? AND user_id=?");
    $check->execute([$convId, $me['id']]);
    if (!$check->fetch()) { http_response_code(403); echo json_encode(['error'=>'Access denied']); exit; }

    // Get group record
    $grpStmt = $db->prepare("SELECT id, name, type, created_by FROM chat_conversations WHERE id=? AND type='group'");
    $grpStmt->execute([$convId]);
    $group = $grpStmt->fetch(PDO::FETCH_ASSOC);
    if (!$group) { http_response_code(404); echo json_encode(['error'=>'Group not found']); exit; }

    // Get members with role
    $mStmt = $db->prepare("
        SELECT u.id, u.name, u.role, cp.joined_at
        FROM chat_participants cp
        JOIN users u ON u.id = cp.user_id
        WHERE cp.conversation_id = ?
        ORDER BY cp.joined_at ASC
    ");
    $mStmt->execute([$convId]);
    $members = $mStmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'group'      => $group,
        'members'    => $members,
        'is_creator' => (int)$group['created_by'] === (int)$me['id'],
        'my_id'      => (int)$me['id'],
    ]);
    exit;
}

// ── POST: group actions ─────────────────────────────────────────────────────
if ($method === 'POST') {
    $body   = json_decode(file_get_contents('php://input'), true) ?? [];
    $action = trim($body['action'] ?? '');
    $convId = (int)($body['conversation_id'] ?? 0);

    if (!$convId) { http_response_code(400); echo json_encode(['error'=>'conversation_id required']); exit; }

    // Verify participant
    $check = $db->prepare("SELECT 1 FROM chat_participants WHERE conversation_id=? AND user_id=?");
    $check->execute([$convId, $me['id']]);
    if (!$check->fetch()) { http_response_code(403); echo json_encode(['error'=>'Access denied']); exit; }

    // Verify it's a group
    $grpStmt = $db->prepare("SELECT id, name, created_by FROM chat_conversations WHERE id=? AND type='group'");
    $grpStmt->execute([$convId]);
    $group = $grpStmt->fetch(PDO::FETCH_ASSOC);
    if (!$group) { http_response_code(404); echo json_encode(['error'=>'Group not found']); exit; }

    $isCreator = (int)$group['created_by'] === (int)$me['id'];
    $isAdmin   = $me['role'] === 'admin';

    switch ($action) {

        case 'add_member':
            $userId = (int)($body['user_id'] ?? 0);
            if (!$userId) { http_response_code(400); echo json_encode(['error'=>'user_id required']); exit; }

            // Already a member?
            $ex = $db->prepare("SELECT 1 FROM chat_participants WHERE conversation_id=? AND user_id=?");
            $ex->execute([$convId, $userId]);
            if ($ex->fetch()) { echo json_encode(['ok'=>true, 'already_member'=>true]); exit; }

            $uStmt = $db->prepare("SELECT name FROM users WHERE id=? AND status='active'");
            $uStmt->execute([$userId]);
            $user = $uStmt->fetch();
            if (!$user) { http_response_code(404); echo json_encode(['error'=>'User not found']); exit; }

            $db->prepare("INSERT INTO chat_participants (conversation_id, user_id) VALUES (?,?)")->execute([$convId, $userId]);
            $db->prepare("INSERT INTO chat_messages (conversation_id, sender_id, type, content) VALUES (?,?,'system',?)")
               ->execute([$convId, $me['id'], $me['name'] . ' added ' . $user['name']]);
            echo json_encode(['ok' => true]);
            break;

        case 'remove_member':
            $userId = (int)($body['user_id'] ?? 0);
            if (!$userId) { http_response_code(400); echo json_encode(['error'=>'user_id required']); exit; }

            // Only creator/admin can remove others; everyone can remove themselves
            if ($userId !== (int)$me['id'] && !$isCreator && !$isAdmin) {
                http_response_code(403); echo json_encode(['error'=>'Only the group creator can remove members']); exit;
            }

            $uStmt = $db->prepare("SELECT name FROM users WHERE id=?");
            $uStmt->execute([$userId]);
            $user = $uStmt->fetch();

            $db->prepare("DELETE FROM chat_participants WHERE conversation_id=? AND user_id=?")->execute([$convId, $userId]);
            $msg = ((int)$userId === (int)$me['id'])
                ? $me['name'] . ' left the group'
                : $me['name'] . ' removed ' . ($user['name'] ?? 'a member');
            $db->prepare("INSERT INTO chat_messages (conversation_id, sender_id, type, content) VALUES (?,?,'system',?)")
               ->execute([$convId, $me['id'], $msg]);
            echo json_encode(['ok' => true]);
            break;

        case 'rename':
            if (!$isCreator && !$isAdmin) {
                http_response_code(403); echo json_encode(['error'=>'Only the group creator can rename the group']); exit;
            }
            $newName = trim($body['name'] ?? '');
            if (!$newName) { http_response_code(400); echo json_encode(['error'=>'Name required']); exit; }
            $db->prepare("UPDATE chat_conversations SET name=? WHERE id=?")->execute([$newName, $convId]);
            $db->prepare("INSERT INTO chat_messages (conversation_id, sender_id, type, content) VALUES (?,?,'system',?)")
               ->execute([$convId, $me['id'], $me['name'] . ' renamed the group to "' . $newName . '"']);
            echo json_encode(['ok' => true]);
            break;

        case 'leave':
            $db->prepare("DELETE FROM chat_participants WHERE conversation_id=? AND user_id=?")->execute([$convId, $me['id']]);
            $db->prepare("INSERT INTO chat_messages (conversation_id, sender_id, type, content) VALUES (?,?,'system',?)")
               ->execute([$convId, $me['id'], $me['name'] . ' left the group']);
            echo json_encode(['ok' => true, 'left' => true]);
            break;

        default:
            http_response_code(400); echo json_encode(['error'=>'Unknown action']);
    }
    exit;
}

http_response_code(405); echo json_encode(['error' => 'Method not allowed']);

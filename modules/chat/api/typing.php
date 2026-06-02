<?php
// Chat API – Typing indicators
require_once __DIR__ . '/../../../includes/functions.php';

header('Content-Type: application/json');

if (!isLoggedIn()) { http_response_code(401); echo json_encode(['error'=>'Unauthenticated']); exit; }

$me     = authUser();
$db     = getDB();
$method = $_SERVER['REQUEST_METHOD'];

// ── POST: record that the current user is typing ───────────────────────────
if ($method === 'POST') {
    $body   = json_decode(file_get_contents('php://input'), true) ?? [];
    $convId = (int)($body['conversation_id'] ?? 0);
    if (!$convId) { http_response_code(400); echo json_encode(['error'=>'conversation_id required']); exit; }

    // Verify participant
    $check = $db->prepare("SELECT 1 FROM chat_participants WHERE conversation_id=? AND user_id=?");
    $check->execute([$convId, $me['id']]);
    if (!$check->fetch()) { http_response_code(403); echo json_encode(['error'=>'Access denied']); exit; }

    try {
        $db->prepare("
            INSERT INTO chat_typing (conversation_id, user_id, updated_at) VALUES (?, ?, NOW())
            ON DUPLICATE KEY UPDATE updated_at = NOW()
        ")->execute([$convId, $me['id']]);
        echo json_encode(['ok' => true]);
    } catch (Exception $e) {
        // Table may not exist yet — not fatal
        echo json_encode(['ok' => false]);
    }
    exit;
}

// ── GET: who's currently typing in a conversation ─────────────────────────
if ($method === 'GET') {
    $convId = (int)($_GET['conversation_id'] ?? 0);
    if (!$convId) { http_response_code(400); echo json_encode(['error'=>'conversation_id required']); exit; }

    // Verify participant
    $check = $db->prepare("SELECT 1 FROM chat_participants WHERE conversation_id=? AND user_id=?");
    $check->execute([$convId, $me['id']]);
    if (!$check->fetch()) { http_response_code(403); echo json_encode(['error'=>'Access denied']); exit; }

    try {
        $stmt = $db->prepare("
            SELECT u.id, u.name
            FROM chat_typing ct
            JOIN users u ON u.id = ct.user_id
            WHERE ct.conversation_id = ?
              AND ct.user_id != ?
              AND ct.updated_at > DATE_SUB(NOW(), INTERVAL 5 SECOND)
        ");
        $stmt->execute([$convId, $me['id']]);
        $typers = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['typing' => $typers]);
    } catch (Exception $e) {
        echo json_encode(['typing' => []]);
    }
    exit;
}

http_response_code(405); echo json_encode(['error' => 'Method not allowed']);

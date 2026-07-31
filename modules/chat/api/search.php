<?php
/**
 * Team chat — message search.
 *
 * Scoped to conversations the caller actually belongs to. Without that a user
 * could read any message in the company by guessing a conversation id.
 */
require_once __DIR__ . '/../../../includes/functions.php';
require_once __DIR__ . '/../chat_bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

if (!isLoggedIn()) { http_response_code(401); echo json_encode(['error' => 'Unauthenticated']); exit; }

$me = authUser();
$db = getDB();
chatMigrate($db);

$meId   = (int)$me['id'];
$q      = trim($_GET['q'] ?? '');
$convId = (int)($_GET['conversation_id'] ?? 0);

// Two characters matches almost everything and makes the LIKE scan the whole
// table for no useful result.
if (mb_strlen($q) < 2) { echo json_encode(['ok' => true, 'results' => [], 'note' => 'Type at least 2 characters.']); exit; }

try {
    $params = [$meId];
    $scope  = '';
    if ($convId > 0) {
        if (!chatIsParticipant($db, $convId, $meId)) {
            http_response_code(403); echo json_encode(['error' => 'Access denied']); exit;
        }
        $scope    = ' AND cm.conversation_id = ?';
        $params[] = $convId;
    }

    // Escape the LIKE wildcards so searching for "50%" or "a_b" means those
    // characters rather than a pattern.
    $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $q) . '%';
    $params[] = $like;

    $st = $db->prepare("
        SELECT cm.id, cm.conversation_id, cm.sender_id, cm.type, cm.content,
               cm.file_name, cm.created_at,
               u.name AS sender_name,
               cc.type AS conv_type, cc.name AS conv_name,
               other.name AS other_name
        FROM chat_messages cm
        JOIN chat_participants cp ON cp.conversation_id = cm.conversation_id AND cp.user_id = ?
        JOIN chat_conversations cc ON cc.id = cm.conversation_id
        JOIN users u ON u.id = cm.sender_id
        LEFT JOIN chat_participants cp2
               ON cp2.conversation_id = cc.id AND cp2.user_id <> cp.user_id AND cc.type = 'direct'
        LEFT JOIN users other ON other.id = cp2.user_id
        WHERE cm.is_deleted = 0
          AND cm.type IN ('text','file','image')
          {$scope}
          AND (cm.content LIKE ? OR cm.file_name LIKE ?)
        ORDER BY cm.id DESC
        LIMIT 60
    ");
    $params[] = $like;   // second placeholder, for file_name
    $st->execute($params);

    $results = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $r['conv_label'] = $r['conv_type'] === 'direct'
            ? ($r['other_name'] ?: 'Direct message')
            : ($r['conv_name'] ?: 'Group');
        $r['preview'] = chatPreview($r['type'], $r['content'], $r['file_name']);
        unset($r['content']);
        $results[] = $r;
    }

    echo json_encode(['ok' => true, 'results' => $results, 'query' => $q]);
} catch (\Throwable $e) {
    error_log('chat/search: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Search failed']);
}

<?php
require_once __DIR__ . '/../../../includes/functions.php';
header('Content-Type: application/json');
if (!isLoggedIn()) { http_response_code(401); echo json_encode(['conversations' => []]); exit; }

$db = getDB();
try {
    $rows = $db->query("
        SELECT wc.*,
               cl.name AS client_full_name,
               (SELECT body      FROM wa_messages WHERE conversation_id = wc.id ORDER BY sent_at DESC LIMIT 1) AS last_msg,
               (SELECT sent_at   FROM wa_messages WHERE conversation_id = wc.id ORDER BY sent_at DESC LIMIT 1) AS last_msg_at,
               (SELECT direction FROM wa_messages WHERE conversation_id = wc.id ORDER BY sent_at DESC LIMIT 1) AS last_msg_dir
        FROM wa_conversations wc
        LEFT JOIN clients cl ON cl.id = wc.client_id
        ORDER BY COALESCE(
            (SELECT sent_at FROM wa_messages WHERE conversation_id = wc.id ORDER BY sent_at DESC LIMIT 1),
            wc.last_message_at,
            wc.created_at
        ) DESC
        LIMIT 300
    ")->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['conversations' => $rows]);
} catch (\Throwable $e) {
    echo json_encode(['conversations' => [], 'error' => $e->getMessage()]);
}

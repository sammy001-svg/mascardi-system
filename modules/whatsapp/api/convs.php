<?php
/**
 * The conversation list for the inbox, and the thread when one is asked for.
 *
 * One endpoint for both because the inbox always wants them together: opening a
 * thread also re-reads the list so the unread count it just cleared disappears,
 * and two requests to do that is two chances for them to disagree.
 */

require_once __DIR__ . '/../../../includes/functions.php';
require_once __DIR__ . '/../_wa.php';
requireLogin();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (!waCanUse()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'You do not have access to the WhatsApp inbox.']);
    exit;
}

$db = getDB();
waMigrate($db);
$me = authUser();

$convId  = (int)($_GET['id'] ?? 0);
$afterId = (int)($_GET['after'] ?? 0);

try {
    $convs = waConversations($db, [
        'status'   => ($_GET['status'] ?? 'open') === 'all' ? '' : (($_GET['status'] ?? 'open') === 'closed' ? 'closed' : 'open'),
        'assigned' => !empty($_GET['mine']) ? (int)$me['id'] : 0,
        'unread'   => !empty($_GET['unread']),
        'q'        => trim($_GET['q'] ?? ''),
        'limit'    => 200,
    ]);

    $out = ['ok' => true, 'unread' => waUnreadTotal($db), 'conversations' => [], 'messages' => null];

    foreach ($convs as $c) {
        $out['conversations'][] = [
            'id'      => (int)$c['id'],
            'name'    => (string)($c['client_name'] ?: $c['contact_name'] ?: $c['contact_phone']),
            'phone'   => (string)$c['contact_phone'],
            'preview' => (string)($c['last_message'] ?? ''),
            'at'      => $c['last_message_at'],
            'unread'  => (int)$c['unread_count'],
            'status'  => (string)$c['status'],
            'agent'   => (string)($c['agent_name'] ?? ''),
            'client_id' => (int)($c['client_id'] ?? 0),
            'lead_id'   => (int)($c['lead_id'] ?? 0),
            'stage'     => (string)($c['lead_stage'] ?? ''),
        ];
    }

    if ($convId > 0) {
        $conv = waConversationById($db, $convId);
        if ($conv) {
            // Opening a thread is what marks it read — but only on a first load.
            // A poll for new messages must not clear the badge for a thread
            // nobody is looking at any more.
            if ($afterId === 0) waMarkRead($db, $convId);

            $rows = waMessages($db, $convId, $afterId);
            $msgs = [];
            foreach ($rows as $m) {
                $msgs[] = [
                    'id'        => (int)$m['id'],
                    'direction' => (string)$m['direction'],
                    'type'      => (string)$m['type'],
                    'body'      => (string)($m['body'] ?? ''),
                    'file_name' => (string)($m['file_name'] ?? ''),
                    'file_url'  => !empty($m['file_path'])
                                    ? rtrim(BASE_URL, '/') . '/' . ltrim((string)$m['file_path'], '/')
                                    : (string)($m['media_url'] ?? ''),
                    'status'    => (string)$m['status'],
                    'error'     => (string)($m['error'] ?? ''),
                    'sender'    => (string)($m['sender_name'] ?? ''),
                    'at'        => $m['sent_at'],
                ];
            }
            $out['messages'] = $msgs;
            $out['conversation'] = [
                'id'     => (int)$conv['id'],
                'name'   => (string)($conv['contact_name'] ?: $conv['contact_phone']),
                'phone'  => (string)$conv['contact_phone'],
                'status' => (string)$conv['status'],
                'client_id'   => (int)($conv['client_id'] ?? 0),
                'lead_id'     => (int)($conv['lead_id'] ?? 0),
                'assigned_to' => (int)($conv['assigned_to'] ?? 0),
            ];
        }
    }

    echo json_encode($out, JSON_UNESCAPED_UNICODE);
} catch (\Throwable $e) {
    error_log('wa convs: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'The inbox could not be read just now.']);
}

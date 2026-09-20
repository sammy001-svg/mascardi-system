<?php
/**
 * Pull the phone's existing conversations into the inbox.
 *
 * Done a few chats at a time, driven by the browser, rather than in one long
 * request. Two hundred chats each needing a history call is minutes of work,
 * and a shared host will cut a request off long before that — leaving a job
 * that is half done and no way to tell how far it got.
 *
 * Batched means it is also resumable: every message is filed against the
 * provider's own id, so running it again picks up what was missed and adds
 * nothing twice.
 */

require_once __DIR__ . '/../../../includes/functions.php';
require_once __DIR__ . '/../_wa.php';
requireLogin();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (!waCanAdmin()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Importing history is an administrator job.']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'POST only']);
    exit;
}
verifyCsrf();

if (!waConfigured()) {
    echo json_encode(['ok' => false, 'error' => 'WhatsApp is not connected yet.']);
    exit;
}
if (waProvider() === 'cloud') {
    echo json_encode(['ok' => false, 'error' => 'The official API does not hand over past '
                                              . 'conversations — Meta only delivers messages '
                                              . 'from the moment the number was connected.']);
    exit;
}

$db = getDB();
waMigrate($db);
$me = authUser();

$offset  = max(0, (int)($_POST['offset'] ?? 0));
$batch   = max(1, min(5, (int)($_POST['batch'] ?? 3)));
$perChat = max(10, min(500, (int)($_POST['per_chat'] ?? 100)));

try {
    // The chat list is fetched fresh each batch rather than cached in the
    // session: it is one cheap call, and a stale list silently skips whoever
    // wrote in while the import was running.
    $chats = waDriverChats();
    $total = count($chats);

    if ($total === 0) {
        echo json_encode(['ok' => false,
            'error' => 'The provider returned no chats. If the phone was linked only moments '
                     . 'ago, give it a minute to finish syncing and try again.']);
        exit;
    }

    $slice    = array_slice($chats, $offset, $batch);
    $messages = 0;
    $done     = [];

    foreach ($slice as $c) {
        $r = waImportChat($db, $c['chat_id'], $c['name'], $perChat);
        $messages += $r['messages'];
        $done[] = ['name' => $c['name'] ?: waChatPhone($c['chat_id']), 'messages' => $r['messages']];
    }

    $next = $offset + count($slice);
    $finished = $next >= $total;

    if ($finished) {
        logActivity('update', 'wa_conversations', 0,
            'WhatsApp history imported by ' . $me['name'] . ' — ' . $total . ' chats scanned.');
    }

    echo json_encode([
        'ok'       => true,
        'total'    => $total,
        'next'     => $next,
        'finished' => $finished,
        'messages' => $messages,
        'chats'    => $done,
    ], JSON_UNESCAPED_UNICODE);
} catch (\Throwable $e) {
    error_log('wa import: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'The import stopped unexpectedly. '
                                              . 'Run it again — it carries on where it left off.']);
}

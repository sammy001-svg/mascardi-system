<?php
/**
 * Open a conversation with a number, creating it if there is not one yet.
 *
 * This exists because the first attempt did not. Starting a new chat went
 * through send.php with an empty message, on the theory that the refusal would
 * still carry the conversation id back. It does not: the failure path returns
 * {ok, error} and nothing else, so the thread was created, the page never
 * opened it, and the person was shown "There is nothing to send" for a
 * conversation that existed perfectly well.
 *
 * Opening a conversation and sending a message are two different things, and
 * pretending otherwise cost a working feature.
 */

require_once __DIR__ . '/../../../includes/functions.php';
require_once __DIR__ . '/../_wa.php';
requireLogin();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'POST only']);
    exit;
}
if (!waCanSend()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'You do not have permission to start conversations.']);
    exit;
}
verifyCsrf();

$raw = trim($_POST['phone'] ?? '');
if ($raw === '') {
    echo json_encode(['ok' => false, 'error' => 'Enter a phone number.']);
    exit;
}

$chatId = waChatId($raw);
if ($chatId === null) {
    // Say what was actually wrong with it. "Not usable" sends people to check a
    // number that is fine — which is exactly what happened when a bad country
    // code was making every local number come out twenty-one digits long.
    $digits = preg_replace('/\D+/', '', $raw) ?? '';
    $why = strlen($digits) < 9
        ? 'that is only ' . strlen($digits) . ' digits'
        : 'that comes to ' . strlen($digits) . ' digits once the country code is added';
    echo json_encode(['ok' => false,
        'error' => 'That number cannot be used — ' . $why . '. Enter it as 0712345678 '
                 . 'or with the country code, like 254712345678.']);
    exit;
}

try {
    $db = getDB();
    waMigrate($db);

    $conv = waConversation($db, $chatId, trim($_POST['name'] ?? ''), waChatPhone($chatId));
    if (!$conv) {
        echo json_encode(['ok' => false, 'error' => 'That conversation could not be opened.']);
        exit;
    }

    echo json_encode([
        'ok'              => true,
        'conversation_id' => (int)$conv['id'],
        'name'            => (string)($conv['contact_name'] ?: $conv['contact_phone']),
        'phone'           => (string)$conv['contact_phone'],
        // A thread that already existed is not a new one, and saying so saves
        // somebody wondering why their "new" chat is full of old messages.
        'existing'        => !empty($conv['last_message_at']),
    ], JSON_UNESCAPED_UNICODE);
} catch (\Throwable $e) {
    error_log('wa open: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'That conversation could not be opened.']);
}

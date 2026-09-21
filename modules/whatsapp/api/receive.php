<?php
/**
 * The webhook — where customers' messages arrive.
 *
 * This is the only file in the module that answers without a login, because the
 * provider is not a person and has no session. That makes it the one piece worth
 * being careful about:
 *
 *   · A secret in the address proves the caller is the provider. Without it
 *     anyone who guessed this URL could write messages into the inbox that look
 *     like they came from a customer, which is a more interesting attack than it
 *     first sounds — invent a message from a buyer agreeing a price.
 *   · It answers 200 quickly and does the work after. Providers retry anything
 *     slow, and a retry that arrives while the first is still writing is how the
 *     same sentence lands in a thread twice.
 *   · It never echoes anything back. An error page here would be a free oracle
 *     telling an attacker whether they had guessed the secret.
 */

require_once __DIR__ . '/../../../includes/functions.php';
require_once __DIR__ . '/../_wa.php';
require_once __DIR__ . '/../_auto.php';

// No session, no cookies, nothing to leak.
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

/** Answer and hang up, so the provider stops waiting; then keep working. */
function waAck(): void
{
    echo '{"ok":true}';
    if (function_exists('fastcgi_finish_request')) { fastcgi_finish_request(); return; }
    // Without FPM the best available is to flush and let the rest run.
    if (ob_get_level() > 0) @ob_end_flush();
    @flush();
}

$secret = trim((string)getSetting('wa_webhook_secret', ''));

$given = (string)($_GET['k'] ?? '');
if ($given === '') {
    $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    if (stripos($auth, 'bearer ') === 0) $given = trim(substr($auth, 7));
}

// A configured secret is required. hash_equals so a wrong guess takes the same
// time as any other wrong guess.
if ($secret === '' || !hash_equals($secret, $given)) {
    http_response_code(404);          // 404, not 403: do not confirm this exists
    echo '{"ok":false}';
    error_log('wa receive: rejected a call with a bad or missing secret from '
              . ($_SERVER['REMOTE_ADDR'] ?? '?'));
    exit;
}

$raw = file_get_contents('php://input') ?: '';
waAck();

if ($raw === '') exit;
$payload = json_decode($raw, true);
if (!is_array($payload)) {
    error_log('wa receive: payload was not JSON');
    exit;
}

try {
    $db = getDB();
    waMigrate($db);

    $msg = waDriverInbound($payload);
    // null is the ordinary case, not a failure: delivery receipts, typing
    // indicators and group traffic all land here and none of them are messages.
    if ($msg === null) exit;

    $rowId = waRecordInbound($db, $msg);
    if ($rowId <= 0) exit;   // already filed, or could not be filed

    // Tell whoever is meant to be answering. The thread carries an agent once it
    // has been matched to a lead, so the notification goes to a person rather
    // than to a room.
    $conv = waConversation($db, (string)$msg['chat_id']);
    if ($conv) {
        $who  = trim((string)($conv['contact_name'] ?? '')) ?: (string)($conv['contact_phone'] ?? 'A customer');
        $body = trim((string)$msg['body']);
        if ($body === '') $body = ucfirst((string)$msg['type']) . ' received';

        require_once __DIR__ . '/../../../includes/notifications.php';
        $link = BASE_URL . '/modules/whatsapp/index.php?id=' . (int)$conv['id'];

        if (!empty($conv['assigned_to'])) {
            createNotification((int)$conv['assigned_to'], 'chat',
                'WhatsApp from ' . $who, mb_substr($body, 0, 140), $link);
        } else {
            notifyRoles(['customer_relations', 'sales_manager', 'sales_officer', 'admin'],
                'chat', 'WhatsApp from ' . $who, mb_substr($body, 0, 140), $link);
        }

        // Should Karl say something? Almost always the answer is no, because
        // during working hours a colleague gets first refusal and the grace
        // period has only just started. Out of hours it answers at once:
        // nobody is coming, and a customer who writes at ten at night is
        // better served by "we have you, we open at eight" than by silence
        // until morning. The in-hours case is picked up by cron_auto.php,
        // because a grace period expiring is not an event anything sends.
        try {
            if (waAutoEnabled()) waAutoRespond($db, (int)$conv['id']);
        } catch (\Throwable $e) {
            // The customer's message is already filed and the team already
            // told. A failure to add a courtesy reply must not undo either.
            error_log('wa auto-reply: ' . $e->getMessage());
        }
    }
} catch (\Throwable $e) {
    // The provider has already been told 200, so there is nobody left to tell.
    error_log('wa receive: ' . $e->getMessage());
}

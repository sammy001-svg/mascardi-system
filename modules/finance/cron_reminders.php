<?php
/**
 * The reminder sweep, for cron.
 *
 * The sweep also rides on the notification poll, so a yard with somebody signed
 * in never needs this. It matters on the day nobody is: a payment due on a
 * Sunday, or over the holidays, would otherwise be reminded about whenever the
 * office next opened.
 *
 * On the command line:
 *     php modules/finance/cron_reminders.php
 *
 * Or over HTTP, with the same secret the WhatsApp webhook uses:
 *     curl "https://…/modules/finance/cron_reminders.php?k=SECRET"
 *
 * Suggested cron, once an hour — the sweep is idempotent, so running it more
 * often than needed costs nothing and misses nothing:
 *     0 * * * * php /home/USER/public_html/modules/finance/cron_reminders.php
 *
 * (The schedule line is in a plain comment rather than a docblock: a star
 * followed by a slash would close the comment early, which this codebase has
 * been caught by once already.)
 */

require_once __DIR__ . '/_credit.php';

$cli = PHP_SAPI === 'cli';

if (!$cli) {
    // Over the web this is unauthenticated by necessity — cron has no session —
    // so the secret does all the work.
    $secret = trim((string)getSetting('wa_webhook_secret', ''));
    $given  = (string)($_GET['k'] ?? '');

    if ($secret === '' || !hash_equals($secret, $given)) {
        http_response_code(404);          // 404, not 403: do not confirm this exists
        error_log('credit cron: rejected a call with a bad or missing key from '
                . ($_SERVER['REMOTE_ADDR'] ?? '?'));
        exit;
    }
    header('Content-Type: application/json; charset=utf-8');
}

$db = getDB();

try {
    // A bigger batch than the browser heartbeat takes: nobody is waiting on this
    // one, and a morning's worth of reminders should go in a single run.
    $r = creditReminderSweep($db, 50);
} catch (\Throwable $e) {
    error_log('credit cron: ' . $e->getMessage());
    $r = ['considered' => 0, 'sent' => 0, 'failed' => 0, 'no_email' => 0, 'error' => $e->getMessage()];
}

try {
    $db->prepare("INSERT INTO settings (setting_key, setting_value)
                  VALUES ('credit_last_sweep', UNIX_TIMESTAMP())
                  ON DUPLICATE KEY UPDATE setting_value = UNIX_TIMESTAMP()")->execute();
} catch (\Throwable $e) {}

if ($cli) {
    printf("considered %d · sent %d · failed %d · no email %d\n",
        $r['considered'], $r['sent'], $r['failed'], $r['no_email']);
} else {
    echo json_encode(['ok' => true] + $r);
}

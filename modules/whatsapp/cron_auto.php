<?php
/**
 * Cron-callable sweep for automatic WhatsApp replies.
 *
 * Run every few minutes:
 *   every five minutes:  "5-star" cron, i.e. 0,5,10,... — see below
 *
 * The webhook already answers immediately when the yard is closed, because
 * nobody is coming and there is nothing to wait for. This exists for the other
 * case: a customer writes during working hours, the grace period passes, and no
 * colleague has picked it up. Nothing arrives to trigger that moment — it is
 * defined by silence — so something has to come looking.
 *
 * Safe to run as often as you like. Every guard lives in waAutoDecide(), so a
 * sweep that finds nothing due simply does nothing.
 */

// Cron line (kept out of the docblock above, because the slash-star in a
// crontab expression closes a PHP comment and takes the file with it):
//
//     */5 * * * * php /path/to/modules/whatsapp/cron_auto.php
//
define('RUNNING_CRON', true);
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/_auto.php';

$cli = (PHP_SAPI === 'cli');

// Over HTTP this needs the same secret the webhook uses, or a scheduled URL
// somebody found would become a way to make the yard message its customers.
if (!$cli) {
    header('Content-Type: text/plain; charset=utf-8');
    $secret = trim((string)getSetting('wa_webhook_secret', ''));
    $given  = (string)($_GET['k'] ?? '');
    if ($secret === '' || !hash_equals($secret, $given)) {
        http_response_code(404);
        echo "not found\n";
        exit;
    }
}

$db = getDB();

try {
    waMigrate($db);

    if (!waAutoEnabled()) {
        echo "Automatic replies are switched off.\n";
        exit;
    }

    $r = waAutoSweep($db, 15);
    echo 'Considered ' . $r['considered'] . ' conversation(s), replied to ' . $r['sent'] . ".\n";

    if ($r['sent'] > 0) {
        logActivity('update', 'wa_messages', 0,
            'Karl answered ' . $r['sent'] . ' unattended WhatsApp conversation(s) on a scheduled sweep.');
    }
} catch (\Throwable $e) {
    error_log('wa cron_auto: ' . $e->getMessage());
    echo "The sweep failed: " . $e->getMessage() . "\n";
    exit(1);
}

<?php
/**
 * The unread count for the menu badge.
 *
 * Asked on every page by every signed-in browser, and answering it means a
 * round trip to the mail server — so it is cached in the session for a minute.
 * Without that, opening any page in the system would wait on IMAP.
 *
 * Somebody with no mailbox connected gets a zero rather than an error: the
 * sidebar polls this for everyone, and an error in the console on every page
 * load for half the staff is noise that teaches people to ignore the console.
 */

require_once __DIR__ . '/../_mailbox.php';
requireLogin();

use Mascardi\Mail\Mailbox;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$cached = $_SESSION['mail_unread'] ?? null;

if (is_array($cached) && ($cached['at'] ?? 0) > time() - 60) {
    echo json_encode(['ok' => true, 'unread' => (int)$cached['n'], 'count' => (int)$cached['n']]);
    exit;
}

try {
    $box = mailServerReady() ? Mailbox::for((int)authUser()['id']) : null;

    if (!$box) {
        echo json_encode(['ok' => true, 'unread' => 0, 'count' => 0]);
        exit;
    }

    $n = $box->unreadInInbox();
    $box->close();
} catch (\Throwable $e) {
    // A mail server that is down must not put a red mark on every page.
    echo json_encode(['ok' => false, 'unread' => 0, 'count' => 0]);
    exit;
}

$_SESSION['mail_unread'] = ['n' => $n, 'at' => time()];

echo json_encode(['ok' => true, 'unread' => $n, 'count' => $n]);

<?php
/**
 * The badge on the menu item.
 *
 * Already called by all three sidebars, so the path and the shape of the answer
 * are fixed by what is already out there — this keeps both.
 *
 * Someone without WhatsApp rights gets a zero rather than a refusal. The sidebar
 * polls this on every page for everybody, and an error in the console on every
 * page load for half the staff is noise that teaches people to ignore the
 * console.
 */

require_once __DIR__ . '/../../../includes/functions.php';
require_once __DIR__ . '/../_wa.php';
require_once __DIR__ . '/../_auto.php';
requireLogin();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (!waCanUse()) { echo json_encode(['ok' => true, 'count' => 0]); exit; }

try {
    $db = getDB();
    waMigrate($db);

    // Every signed-in browser polls this every twenty seconds, which makes it
    // the one thing in the system that ticks. During opening hours the trigger
    // for an automatic reply is a minute passing with nobody answering — and
    // nothing fires when nothing happens — so the tick has to come from here.
    // Internally locked, so twenty staff polling at once sweep once between
    // them; and it never delays the badge, which is what was actually asked for.
    try { waAutoHeartbeat($db); } catch (\Throwable $e) {
        error_log('wa heartbeat: ' . $e->getMessage());
    }

    $count = waUnreadTotal($db);
} catch (\Throwable $e) {
    error_log('wa unread: ' . $e->getMessage());
    $count = 0;
}

echo json_encode(['ok' => true, 'count' => $count, 'unread' => $count]);

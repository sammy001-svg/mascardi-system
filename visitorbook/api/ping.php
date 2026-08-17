<?php
/**
 * Visitors Book — desk heartbeat.
 *
 * A kiosk spends most of its day sitting on the form with nobody touching it. If
 * the claim on its location expired during those quiet stretches, another tablet
 * could take the branch out from under it and two desks would end up recording
 * against one location — the exact thing the claim exists to prevent.
 *
 * So the page pings this while it is open. The reply also tells the page when its
 * claim has genuinely gone (the device slept past the TTL and someone else took
 * over), which is the signal to send the operator back to choose again rather
 * than carry on recording against a branch it no longer holds.
 */
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../modules/visitors/_bootstrap.php';
requireLogin();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (authRole() !== 'visitor_book' && !canAccess('visitors')) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'forbidden']);
    exit;
}

$db  = getDB();
$loc = visitorSessionLocation();

// No location claimed at all — nothing to keep alive, and nothing wrong either
// (an install with no locations configured runs this way).
if (!$loc) {
    echo json_encode(['ok' => true, 'held' => true, 'location_id' => null]);
    exit;
}

$held = visitorDeskTouch($db, (int)$loc);

$out = ['ok' => true, 'held' => $held, 'location_id' => (int)$loc];
if (!$held) {
    // Say who has it now, so the page can be specific about what happened.
    $holder = visitorDeskHolder($db, (int)$loc);
    $out['taken_by'] = $holder ? ($holder['device_label'] ?: 'another device') : null;
    $out['location']  = visitorLocationName($db, (int)$loc);
}
echo json_encode($out, JSON_UNESCAPED_UNICODE);

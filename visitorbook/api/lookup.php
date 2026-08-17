<?php
/**
 * Returning-visitor lookup for the kiosk.
 *
 * Given a full phone number, returns the details that visitor gave last time so
 * reception does not have to retype them. Nothing else: no visit dates, no
 * purposes, no history. The kiosk is a public screen, so the most this endpoint
 * can confirm about a number typed into it is that it has been here before —
 * see visitorLookupByPhone() for why that is the whole surface on purpose.
 */
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../modules/visitors/_bootstrap.php';
requireLogin();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

// The kiosk account, or a super admin looking at the same screen.
if (authRole() !== 'visitor_book' && !canAccess('visitors')) {
    http_response_code(403);
    echo json_encode(['error' => 'forbidden']);
    exit;
}

$db = getDB();
$phone = (string)($_GET['phone'] ?? '');

$found = visitorLookupByPhone($db, $phone);
if (!$found) {
    echo json_encode(['found' => false]);
    exit;
}

echo json_encode([
    'found'  => true,
    'name'   => visitorFullName($found),
    'fields' => [
        'first_name'  => (string)($found['first_name']  ?? ''),
        'middle_name' => (string)($found['middle_name'] ?? ''),
        'last_name'   => (string)($found['last_name']   ?? ''),
        'id_number'   => (string)($found['id_number']   ?? ''),
        'email'       => (string)($found['email']       ?? ''),
        'heard_from'  => (string)($found['heard_from']  ?? ''),
    ],
], JSON_UNESCAPED_UNICODE);

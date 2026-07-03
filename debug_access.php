<?php
require_once __DIR__ . '/includes/functions.php';
requireLogin();

$user  = authUser();
$perms = getUserPermissions();

header('Content-Type: text/plain; charset=utf-8');
echo "=== ACCESS DIAGNOSTIC ===\n\n";
echo "Session user ID   : " . ($user['id']   ?? 'null') . "\n";
echo "Session user name : " . ($user['name'] ?? 'null') . "\n";
echo "Session role      : " . ($user['role'] ?? 'null') . "\n\n";

echo "canAccess('cars') returns : " . (canAccess('cars') ? 'TRUE' : 'FALSE') . "\n\n";

echo "user_permissions rows for this user:\n";
if (empty($perms)) {
    echo "  (none — role map applies directly)\n";
} else {
    foreach ($perms as $mod => [$ac, $aw]) {
        echo "  module=$mod  can_access=" . ($ac ? '1' : '0') . "  can_write=" . ($aw ? '1' : '0') . "\n";
    }
}

echo "\nRole map entry for this role:\n";
$map = [
    'customer_relations' => ['clients','crm','chat','cars'],
];
$role = $user['role'] ?? '';
if (isset($map[$role])) {
    echo "  " . implode(', ', $map[$role]) . "\n";
} else {
    echo "  (role '$role' not found in map snippet — check auth.php full map)\n";
}

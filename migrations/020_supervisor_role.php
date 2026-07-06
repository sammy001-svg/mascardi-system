<?php
/**
 * Migration 020: Add supervisor role support
 * - Adds location_id to users table for supervisor assignment
 */
require_once __DIR__ . '/../includes/functions.php';
requireRole('admin');

$db = getDB();
$results = [];

// Add location_id to users table
try {
    $db->exec("ALTER TABLE users ADD COLUMN location_id INT NULL DEFAULT NULL COMMENT 'Assigned location for supervisor role'");
    $results[] = ['ok', 'Added location_id column to users table'];
} catch (\Throwable $e) {
    $results[] = str_contains($e->getMessage(), 'Duplicate') || str_contains($e->getMessage(), 'already exists')
        ? ['skip', 'users.location_id already exists — skipped']
        : ['err', 'users.location_id: ' . $e->getMessage()];
}

// Add index for performance
try {
    $db->exec("ALTER TABLE users ADD INDEX idx_users_location (location_id)");
    $results[] = ['ok', 'Added index idx_users_location'];
} catch (\Throwable $e) {
    $results[] = ['skip', 'Index idx_users_location already exists — skipped'];
}

echo '<!DOCTYPE html><html><head><title>Migration 020</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head><body class="p-4"><div class="container" style="max-width:600px"><h4 class="mb-3">Migration 020 — Supervisor Role</h4><ul class="list-group">';
foreach ($results as [$type, $msg]) {
    $cls = $type === 'ok' ? 'success' : ($type === 'skip' ? 'secondary' : 'danger');
    $ico = $type === 'ok' ? '✅' : ($type === 'skip' ? '⏭️' : '❌');
    echo "<li class='list-group-item list-group-item-{$cls}'>{$ico} {$msg}</li>";
}
echo '</ul><div class="mt-3"><a href="' . BASE_URL . '/modules/users/index.php" class="btn btn-primary">→ Go to Users</a></div></div></body></html>';

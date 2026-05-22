<?php
/**
 * Migration 016 — Notifications table
 * Run once as admin, then delete this file.
 */
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/app.php';

session_start();
if (empty($_SESSION['auth_user']) || ($_SESSION['auth_user']['role'] ?? '') !== 'admin') {
    die('<p style="font-family:sans-serif;color:red;padding:20px">Access denied. Log in as admin first.</p>');
}

$db = getDB();
$results = [];

try {
    $db->exec("
        CREATE TABLE IF NOT EXISTS notifications (
            id         INT AUTO_INCREMENT PRIMARY KEY,
            user_id    INT NOT NULL,
            type       VARCHAR(50)  NOT NULL DEFAULT 'info',
            title      VARCHAR(200) NOT NULL,
            message    TEXT,
            link       VARCHAR(500),
            is_read    TINYINT(1)   NOT NULL DEFAULT 0,
            created_at TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_user_read (user_id, is_read),
            CONSTRAINT fk_notif_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $results[] = ['ok', 'notifications table created (or already exists).'];
} catch (PDOException $e) {
    $results[] = ['err', 'notifications table: ' . $e->getMessage()];
}
?>
<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><title>Migration 016</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container py-5" style="max-width:600px">
    <h4 class="mb-4">Migration 016 — Notifications</h4>
    <div class="card"><div class="card-body">
        <?php foreach ($results as [$t, $m]): ?>
        <?php $cls = $t === 'ok' ? 'success' : 'danger'; ?>
        <div class="alert alert-<?= $cls ?> py-2 mb-2 small">
            <?= $t === 'ok' ? '✔' : '✖' ?> <?= htmlspecialchars($m) ?>
        </div>
        <?php endforeach; ?>
        <?php if (!in_array('err', array_column($results, 0))): ?>
        <div class="alert alert-success mt-3"><strong>Done.</strong> Delete <code>run_migration_016.php</code> now.</div>
        <a href="/" class="btn btn-primary">Go to Dashboard</a>
        <?php else: ?>
        <div class="alert alert-danger mt-3"><strong>Failed.</strong> Check errors above.</div>
        <?php endif; ?>
    </div></div>
</div>
</body>
</html>

<?php
/**
 * Generate or revoke API token for a user (admin only).
 * Called via POST with ?action=generate or ?action=revoke.
 */
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
requireRole('admin');
verifyCsrf();

$userId = (int)($_POST['user_id'] ?? 0);
$action = $_POST['action'] ?? 'generate';

if (!$userId) {
    setFlash('error', 'User ID required.');
    redirect(BASE_URL . '/modules/users/index.php');
}

$db = getDB();

if ($action === 'revoke') {
    $db->prepare("UPDATE users SET api_token = NULL WHERE id = ?")->execute([$userId]);
    logActivity('revoke_api_token', 'users', $userId, 'API token revoked');
    setFlash('success', 'API token revoked.');
} else {
    $token = bin2hex(random_bytes(32)); // 64-char hex token
    $db->prepare("UPDATE users SET api_token = ? WHERE id = ?")->execute([$token, $userId]);
    logActivity('generate_api_token', 'users', $userId, 'API token generated');
    setFlash('success', 'New API token generated. Copy it now — it will not be shown again.');
    $_SESSION['show_token_' . $userId] = $token;
}

redirect(BASE_URL . '/modules/users/edit.php?id=' . $userId);

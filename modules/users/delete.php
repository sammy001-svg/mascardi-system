<?php
require_once __DIR__ . '/../../includes/functions.php';
requireRole('admin');
$id = (int)($_GET['id'] ?? 0);
if (!$id) redirect(BASE_URL . '/modules/users/index.php');

// Prevent deleting own account
if ($id === authUser()['id']) {
    setFlash('error', 'You cannot delete your own account.');
    redirect(BASE_URL . '/modules/users/index.php');
}

$db = getDB();
$stmt = $db->prepare("SELECT name FROM users WHERE id=?"); $stmt->execute([$id]); $user = $stmt->fetch();
if (!$user) { setFlash('error', 'User not found.'); redirect(BASE_URL . '/modules/users/index.php'); }

$db->prepare("DELETE FROM users WHERE id=?")->execute([$id]);
setFlash('success', "User {$user['name']} deleted.");
redirect(BASE_URL . '/modules/users/index.php');

<?php
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
requireRole('admin');
$id = (int)($_GET['id'] ?? 0);
if ($id) {
    $db   = getDB();
    $stmt = $db->prepare("SELECT name FROM drivers WHERE id=?");
    $stmt->execute([$id]);
    $name = $stmt->fetchColumn() ?: 'Driver';
    $db->prepare("DELETE FROM drivers WHERE id=?")->execute([$id]);
    logActivity('delete', 'drivers', $id, "Deleted driver: {$name}");
    setFlash('success', 'Driver deleted.');
}
redirect(BASE_URL . '/modules/drivers/index.php');

<?php
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
requireRole('admin');
$db = getDB();

$id = (int)($_GET['id'] ?? 0);
if (!$id) redirect(BASE_URL . '/modules/inventory/index.php');

try {
    $db->prepare("DELETE FROM inventory WHERE id=?")->execute([$id]);
    setFlash('success', 'Part deleted from inventory.');
} catch (PDOException $e) {
    setFlash('error', 'Cannot delete: this part is referenced in other records.');
}

redirect(BASE_URL . '/modules/inventory/index.php');

<?php
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
requireRole('admin');
$db = getDB();

$id = (int)($_GET['id'] ?? 0);
if (!$id) redirect(BASE_URL . '/modules/suppliers/index.php');

try {
    $db->prepare("DELETE FROM suppliers WHERE id=?")->execute([$id]);
    setFlash('success', 'Supplier deleted.');
} catch (PDOException $e) {
    setFlash('error', 'Cannot delete: this supplier is linked to LPOs or inventory items.');
}

redirect(BASE_URL . '/modules/suppliers/index.php');

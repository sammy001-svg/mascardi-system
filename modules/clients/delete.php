<?php
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
requireRole('admin');
$db = getDB();

$id = (int)($_GET['id'] ?? 0);
if (!$id) redirect(BASE_URL . '/modules/clients/index.php');

$stmt = $db->prepare("SELECT name FROM clients WHERE id = ?");
$stmt->execute([$id]);
$client = $stmt->fetch();
if (!$client) {
    setFlash('error', 'Client not found.');
    redirect(BASE_URL . '/modules/clients/index.php');
}

try {
    // All FK references to clients(id) use ON DELETE SET NULL or ON DELETE CASCADE
    // — MySQL nullifies/removes child records automatically.
    $db->prepare("DELETE FROM clients WHERE id = ?")->execute([$id]);
    setFlash('success', 'Client "' . $client['name'] . '" deleted.');
} catch (\Throwable $e) {
    setFlash('error', 'Cannot delete: ' . $e->getMessage());
}

redirect(BASE_URL . '/modules/clients/index.php');

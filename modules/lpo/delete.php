<?php
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
requireRole('admin');
$db = getDB();

$id = (int)($_GET['id'] ?? 0);
if (!$id) redirect(BASE_URL . '/modules/lpo/index.php');

$stmt = $db->prepare("SELECT lpo_number FROM lpo WHERE id = ?");
$stmt->execute([$id]);
$lpo = $stmt->fetch();
if (!$lpo) {
    setFlash('error', 'LPO not found.');
    redirect(BASE_URL . '/modules/lpo/index.php');
}

try {
    $db->beginTransaction();
    $db->prepare("DELETE FROM lpo_items WHERE lpo_id = ?")->execute([$id]);
    $db->prepare("DELETE FROM lpo WHERE id = ?")->execute([$id]);
    $db->commit();
    setFlash('success', 'LPO ' . $lpo['lpo_number'] . ' deleted.');
} catch (\Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    setFlash('error', 'Cannot delete: ' . $e->getMessage());
}

redirect(BASE_URL . '/modules/lpo/index.php');

<?php
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
requireRole('admin');
$db = getDB();

$id = (int)($_GET['id'] ?? 0);
if (!$id) redirect(BASE_URL . '/modules/parts_requests/index.php');

$stmt = $db->prepare("SELECT request_number FROM parts_requests WHERE id = ?");
$stmt->execute([$id]);
$req = $stmt->fetch();
if (!$req) {
    setFlash('error', 'Quote request not found.');
    redirect(BASE_URL . '/modules/parts_requests/index.php');
}

try {
    $db->beginTransaction();
    $db->prepare("DELETE FROM parts_request_items WHERE request_id = ?")->execute([$id]);
    $db->prepare("DELETE FROM parts_requests WHERE id = ?")->execute([$id]);
    $db->commit();
    setFlash('success', 'Quote request ' . $req['request_number'] . ' deleted.');
} catch (\Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    setFlash('error', 'Cannot delete: ' . $e->getMessage());
}

redirect(BASE_URL . '/modules/parts_requests/index.php');

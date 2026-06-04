<?php
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
requireRole('admin');
$db = getDB();

$id = (int)($_GET['id'] ?? 0);
if (!$id) redirect(BASE_URL . '/modules/quotations/index.php');

$stmt = $db->prepare("SELECT quotation_number FROM quotations WHERE id = ?");
$stmt->execute([$id]);
$q = $stmt->fetch();
if (!$q) {
    setFlash('error', 'Quotation not found.');
    redirect(BASE_URL . '/modules/quotations/index.php');
}

try {
    $db->beginTransaction();
    // Detach any invoices that were converted from this quotation
    $db->prepare("UPDATE invoices SET quotation_id = NULL WHERE quotation_id = ?")->execute([$id]);
    $db->prepare("DELETE FROM quotation_items WHERE quotation_id = ?")->execute([$id]);
    $db->prepare("DELETE FROM quotations WHERE id = ?")->execute([$id]);
    $db->commit();
    setFlash('success', 'Quotation ' . $q['quotation_number'] . ' deleted.');
} catch (\Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    setFlash('error', 'Cannot delete: ' . $e->getMessage());
}

redirect(BASE_URL . '/modules/quotations/index.php');

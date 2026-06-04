<?php
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
requireRole('admin');
$db = getDB();

$id = (int)($_GET['id'] ?? 0);
if (!$id) redirect(BASE_URL . '/modules/invoices/index.php');

$stmt = $db->prepare("SELECT invoice_number, status FROM invoices WHERE id = ?");
$stmt->execute([$id]);
$inv = $stmt->fetch();
if (!$inv) {
    setFlash('error', 'Invoice not found.');
    redirect(BASE_URL . '/modules/invoices/index.php');
}

// Block deletion if confirmed payments exist — those funds are real records
$confirmedPayments = (int)$db->prepare("SELECT COUNT(*) FROM payments WHERE invoice_id = ? AND status = 'confirmed'")
    ->execute([$id]) ? $db->query("SELECT COUNT(*) FROM payments WHERE invoice_id = {$id} AND status = 'confirmed'")->fetchColumn() : 0;

$stmt2 = $db->prepare("SELECT COUNT(*) FROM payments WHERE invoice_id = ? AND status = 'confirmed'");
$stmt2->execute([$id]);
$confirmedPayments = (int)$stmt2->fetchColumn();

if ($confirmedPayments > 0) {
    setFlash('error', "Cannot delete invoice {$inv['invoice_number']}: it has {$confirmedPayments} confirmed payment(s). Please reverse or delete those payments first.");
    redirect(BASE_URL . '/modules/invoices/view.php?id=' . $id);
}

try {
    $db->beginTransaction();
    // Detach any pending/reversed payments so they are not orphaned
    $db->prepare("UPDATE payments SET invoice_id = NULL WHERE invoice_id = ?")->execute([$id]);
    // Delete line items then the invoice
    $db->prepare("DELETE FROM invoice_items WHERE invoice_id = ?")->execute([$id]);
    $db->prepare("DELETE FROM invoices WHERE id = ?")->execute([$id]);
    $db->commit();
    setFlash('success', 'Invoice ' . $inv['invoice_number'] . ' deleted.');
} catch (\Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    setFlash('error', 'Cannot delete: ' . $e->getMessage());
}

redirect(BASE_URL . '/modules/invoices/index.php');

<?php
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
requireRole('admin');
$db = getDB();

$id = (int)($_GET['id'] ?? 0);
if (!$id) redirect(BASE_URL . '/modules/payments/index.php');

$stmt = $db->prepare("SELECT payment_number, status, invoice_id, amount FROM payments WHERE id = ?");
$stmt->execute([$id]);
$pay = $stmt->fetch();
if (!$pay) {
    setFlash('error', 'Payment not found.');
    redirect(BASE_URL . '/modules/payments/index.php');
}

try {
    $db->beginTransaction();

    // If this confirmed payment was applied to an invoice, recalculate invoice balance
    if ($pay['status'] === 'confirmed' && $pay['invoice_id']) {
        $remaining = $db->prepare("SELECT COALESCE(SUM(amount),0) FROM payments WHERE invoice_id=? AND status='confirmed' AND id != ?");
        $remaining->execute([$pay['invoice_id'], $id]);
        $newPaid = (float)$remaining->fetchColumn();

        $invRow = $db->prepare("SELECT total FROM invoices WHERE id=?");
        $invRow->execute([$pay['invoice_id']]);
        $invRow = $invRow->fetch();
        if ($invRow) {
            $newInvStatus = $newPaid <= 0 ? 'unpaid' : ($newPaid >= (float)$invRow['total'] ? 'paid' : 'partial');
            $db->prepare("UPDATE invoices SET status=?, amount_paid=? WHERE id=?")->execute([$newInvStatus, $newPaid, $pay['invoice_id']]);
        }
    }

    $db->prepare("DELETE FROM payments WHERE id = ?")->execute([$id]);
    $db->commit();
    setFlash('success', 'Payment ' . $pay['payment_number'] . ' deleted.');
} catch (\Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    setFlash('error', 'Cannot delete: ' . $e->getMessage());
}

redirect(BASE_URL . '/modules/payments/index.php');

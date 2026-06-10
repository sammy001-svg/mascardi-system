<?php
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
hasRole('admin') || die('Admin only.');

$db = getDB();
$id = (int)($_GET['id'] ?? 0);
if (!$id) redirect(BASE_URL . '/modules/showroom_transfers/index.php');

$t = $db->prepare("SELECT id, transfer_number, status FROM showroom_transfers WHERE id = ?");
$t->execute([$id]);
$t = $t->fetch();

if (!$t) {
    setFlash('error', 'Transfer not found.');
    redirect(BASE_URL . '/modules/showroom_transfers/index.php');
}

if (in_array($t['status'], ['in_transit', 'arrived'])) {
    setFlash('error', 'Cannot delete a transfer that is in-transit or has arrived.');
    redirect(BASE_URL . '/modules/showroom_transfers/view.php?id=' . $id);
}

$db->prepare("DELETE FROM showroom_transfers WHERE id = ?")->execute([$id]);
logActivity('delete', 'showroom_transfers', $id, "Deleted transfer {$t['transfer_number']}");
setFlash('success', "Transfer {$t['transfer_number']} deleted.");
redirect(BASE_URL . '/modules/showroom_transfers/index.php');

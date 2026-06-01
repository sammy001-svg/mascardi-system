<?php
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
canEditDelete() || redirect(BASE_URL . '/index.php');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect(BASE_URL . '/modules/expenses/index.php');

$id  = (int)($_POST['id'] ?? 0);
$db  = getDB();

if ($id) {
    $exp = $db->prepare("SELECT * FROM expenses WHERE id=?");
    $exp->execute([$id]); $exp = $exp->fetch();
    if ($exp) {
        if ($exp['receipt_file']) @unlink(BASE_PATH . '/uploads/receipts/' . $exp['receipt_file']);
        $db->prepare("DELETE FROM expenses WHERE id=?")->execute([$id]);
        logActivity('delete','expenses',$id,"Deleted expense: {$exp['description']}");
        setFlash('success','Expense deleted.');
    }
}
redirect(BASE_URL . '/modules/expenses/index.php');

<?php
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
canAccess('lpo') || die('Access denied.');
$pageTitle = 'Local Purchase Orders';
$db = getDB();
$lpos = $db->query("SELECT l.*, s.name AS supplier_name, pr.request_number AS linked_qr FROM lpo l JOIN suppliers s ON s.id=l.supplier_id LEFT JOIN parts_requests pr ON pr.id=l.parts_request_id ORDER BY l.created_at DESC")->fetchAll();
include __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="mb-0">Local Purchase Orders <span class="badge bg-secondary ms-2"><?= count($lpos) ?></span></h5>
    <a href="add.php" class="btn btn-primary btn-sm"><i class="fa fa-plus me-1"></i>Create LPO</a>
</div>
<div class="card">
    <div class="card-body p-0">
        <table class="table table-hover datatable mb-0">
            <thead><tr><th class="ps-3">LPO No.</th><th>Date</th><th>Supplier</th><th>Quote Request</th><th>Expected Del.</th><th>Subtotal</th><th>Tax</th><th>Total</th><th>Status</th><th>Actions</th></tr></thead>
            <tbody>
                <?php foreach ($lpos as $l): ?>
                <tr>
                    <td class="ps-3"><strong><?= e($l['lpo_number']) ?></strong></td>
                    <td><?= fmtDate($l['date']) ?></td>
                    <td><?= e($l['supplier_name']) ?></td>
                    <td><?= $l['linked_qr'] ? e($l['linked_qr']) : '—' ?></td>
                    <td><?= fmtDate($l['expected_delivery']) ?></td>
                    <td><?= money($l['subtotal']) ?></td>
                    <td><?= money($l['tax_amount']) ?></td>
                    <td><strong><?= money($l['total']) ?></strong></td>
                    <td><?= statusBadge($l['status']) ?></td>
                    <td>
                        <a href="view.php?id=<?= $l['id'] ?>" class="btn btn-xs btn-outline-primary"><i class="fa fa-eye"></i></a>
                        <a href="print.php?id=<?= $l['id'] ?>" class="btn btn-xs btn-outline-dark" target="_blank"><i class="fa fa-print"></i></a>
                        <?php if (hasRole('admin')): ?>
                        <a href="delete.php?id=<?= $l['id'] ?>" class="btn btn-xs btn-outline-danger"
                           onclick="return confirm('Delete LPO <?= e($l['lpo_number']) ?>? This cannot be undone.')">
                            <i class="fa fa-trash"></i>
                        </a>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>

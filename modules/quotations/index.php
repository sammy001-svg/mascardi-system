<?php
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
canAccess('quotations') || die('Access denied.');
$pageTitle = 'Quotations';
$db = getDB();
$quotations = $db->query("SELECT q.*, c.chassis_number, c.make, c.model FROM quotations q JOIN cars c ON c.id=q.car_id ORDER BY q.created_at DESC")->fetchAll();
include __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="mb-0">Quotations <span class="badge bg-secondary ms-2"><?= count($quotations) ?></span></h5>
    <?php if (canWrite('quotations')): ?>
    <a href="add.php" class="btn btn-primary btn-sm"><i class="fa fa-plus me-1"></i>New Quotation</a>
    <?php endif; ?>
</div>
<div class="card">
    <div class="card-body p-0">
        <table class="table table-hover datatable mb-0">
            <thead><tr><th class="ps-3">No.</th><th>Date</th><th>Vehicle</th><th>Customer</th><th>Valid Until</th><th>Subtotal</th><th>Tax</th><th>Total</th><th>Status</th><th>Actions</th></tr></thead>
            <tbody>
                <?php foreach ($quotations as $q): ?>
                <tr>
                    <td class="ps-3"><strong><?= e($q['quotation_number']) ?></strong></td>
                    <td><?= fmtDate($q['date']) ?></td>
                    <td><?= e($q['make'].' '.$q['model']) ?><br><small class="text-muted"><?= e($q['chassis_number']) ?></small></td>
                    <td><?= e($q['customer_name']??'—') ?></td>
                    <td><?= fmtDate($q['valid_until']) ?></td>
                    <td><?= money($q['subtotal']) ?></td>
                    <td><?= money($q['tax_amount']) ?></td>
                    <td><strong><?= money($q['total']) ?></strong></td>
                    <td><?= statusBadge($q['status']) ?></td>
                    <td>
                        <a href="view.php?id=<?= $q['id'] ?>" class="btn btn-xs btn-outline-primary"><i class="fa fa-eye"></i></a>
                        <a href="print.php?id=<?= $q['id'] ?>" class="btn btn-xs btn-outline-dark" target="_blank"><i class="fa fa-print"></i></a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>

<?php
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
canAccess('invoices') || die('Access denied.');
$pageTitle = 'Invoices';
$db = getDB();
$status = $_GET['status'] ?? '';
$allowed_statuses = ['unpaid', 'partial', 'paid', 'cancelled'];
$params = [];
$where  = '';
if ($status && in_array($status, $allowed_statuses)) {
    $where    = 'WHERE i.status = ?';
    $params[] = $status;
}
$stmt = $db->prepare("SELECT i.*, c.chassis_number, c.make, c.model FROM invoices i JOIN cars c ON c.id=i.car_id $where ORDER BY i.created_at DESC");
$stmt->execute($params);
$invoices = $stmt->fetchAll();
include __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="mb-0">Invoices <span class="badge bg-secondary ms-2"><?= count($invoices) ?></span></h5>
</div>
<div class="mb-3 d-flex gap-2 flex-wrap">
    <a href="?" class="btn btn-sm <?= !$status?'btn-primary':'btn-outline-secondary' ?>">All</a>
    <?php foreach(['unpaid','partial','paid','cancelled'] as $s): ?>
    <a href="?status=<?= $s ?>" class="btn btn-sm <?= $status===$s?'btn-primary':'btn-outline-secondary' ?>"><?= ucfirst($s) ?></a>
    <?php endforeach; ?>
</div>
<div class="card">
    <div class="card-body p-0">
        <table class="table table-hover datatable mb-0">
            <thead><tr><th class="ps-3">Invoice No.</th><th>Date</th><th>Vehicle</th><th>Customer</th><th>Total</th><th>Paid</th><th>Balance</th><th>Status</th><th>Actions</th></tr></thead>
            <tbody>
                <?php foreach ($invoices as $inv): ?>
                <tr>
                    <td class="ps-3"><strong><?= e($inv['invoice_number']) ?></strong></td>
                    <td><?= fmtDate($inv['date']) ?></td>
                    <td><?= e($inv['make'].' '.$inv['model']) ?><br><small class="text-muted"><?= e($inv['chassis_number']) ?></small></td>
                    <td><?= e($inv['customer_name']??'—') ?></td>
                    <td><?= money($inv['total']) ?></td>
                    <td class="text-success"><?= money($inv['amount_paid']) ?></td>
                    <td class="<?= ($inv['total']-$inv['amount_paid'])>0?'text-danger':'' ?>"><?= money($inv['total']-$inv['amount_paid']) ?></td>
                    <td><?= statusBadge($inv['status']) ?></td>
                    <td>
                        <a href="view.php?id=<?= $inv['id'] ?>" class="btn btn-xs btn-outline-primary"><i class="fa fa-eye"></i></a>
                        <a href="print.php?id=<?= $inv['id'] ?>" class="btn btn-xs btn-outline-dark" target="_blank"><i class="fa fa-print"></i></a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>

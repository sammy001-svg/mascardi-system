<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
$pageTitle = 'My Invoices';
requireClientLogin();
$cl  = clientAuth();
$db  = getDB();

$invoices = $db->prepare("
    SELECT i.*, ca.make, ca.model, ca.year, ca.chassis_number
    FROM invoices i
    LEFT JOIN cars ca ON ca.id = i.car_id
    WHERE i.client_id = ?
    ORDER BY i.created_at DESC
");
$invoices->execute([$cl['id']]); $invoices = $invoices->fetchAll();

include __DIR__ . '/includes/header.php';
?>
<h5 class="fw-700 mb-4"><i class="fa fa-file-invoice-dollar me-2 text-primary"></i>My Invoices</h5>

<div class="card">
    <div class="card-body p-0">
        <?php if ($invoices): ?>
        <table style="width:100%;border-collapse:collapse">
            <thead>
                <tr style="background:#f8fafc;font-size:12px;color:#64748b">
                    <th style="padding:12px 20px;border-bottom:1px solid #f1f5f9">Invoice #</th>
                    <th style="padding:12px;border-bottom:1px solid #f1f5f9">Vehicle</th>
                    <th style="padding:12px;border-bottom:1px solid #f1f5f9">Date</th>
                    <th style="padding:12px;border-bottom:1px solid #f1f5f9">Total</th>
                    <th style="padding:12px;border-bottom:1px solid #f1f5f9">Paid</th>
                    <th style="padding:12px;border-bottom:1px solid #f1f5f9">Balance</th>
                    <th style="padding:12px;border-bottom:1px solid #f1f5f9">Status</th>
                    <th style="padding:12px;border-bottom:1px solid #f1f5f9;text-align:right">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($invoices as $inv):
                    $balance = (float)$inv['total'] - (float)$inv['amount_paid'];
                ?>
                <tr style="border-bottom:1px solid #f8fafc;font-size:13.5px">
                    <td style="padding:14px 20px;font-weight:600"><?= e($inv['invoice_number']) ?></td>
                    <td style="padding:14px 12px;color:#475569"><?= e(($inv['make']??'') . ' ' . ($inv['model']??'')) ?></td>
                    <td style="padding:14px 12px;color:#64748b"><?= fmtDate($inv['date']) ?></td>
                    <td style="padding:14px 12px;font-weight:600"><?= money((float)$inv['total']) ?></td>
                    <td style="padding:14px 12px;color:#16a34a"><?= money((float)$inv['amount_paid']) ?></td>
                    <td style="padding:14px 12px;color:<?= $balance>0?'#dc2626':'#16a34a' ?>;font-weight:600"><?= money($balance) ?></td>
                    <td style="padding:14px 12px"><?= statusBadge($inv['status']) ?></td>
                    <td style="padding:14px 20px;text-align:right">
                        <a href="<?= BASE_URL ?>/modules/invoices/print.php?id=<?= $inv['id'] ?>" class="btn btn-xs btn-outline-primary" target="_blank">
                            <i class="fa fa-eye me-1"></i>View
                        </a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php else: ?>
        <div class="text-center py-5 text-muted">
            <i class="fa fa-file-invoice fa-2x mb-3 d-block" style="color:#cbd5e1"></i>
            <p class="mb-0">No invoices yet.</p>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>

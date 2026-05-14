<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
$pageTitle = 'My Quotations';
requireClientLogin();
$cl = clientAuth();
$db = getDB();

$quotes = $db->prepare("
    SELECT q.*, ca.make, ca.model, ca.year, ca.chassis_number
    FROM quotations q
    LEFT JOIN cars ca ON ca.id = q.car_id
    WHERE q.client_id = ?
    ORDER BY q.created_at DESC
");
$quotes->execute([$cl['id']]); $quotes = $quotes->fetchAll();

include __DIR__ . '/includes/header.php';
?>
<h5 class="fw-700 mb-4"><i class="fa fa-file-lines me-2 text-primary"></i>My Quotations</h5>

<div class="card">
    <div class="card-body p-0">
        <?php if ($quotes): ?>
        <table style="width:100%;border-collapse:collapse">
            <thead>
                <tr style="background:#f8fafc;font-size:12px;color:#64748b">
                    <th style="padding:12px 20px;border-bottom:1px solid #f1f5f9">Quote #</th>
                    <th style="padding:12px;border-bottom:1px solid #f1f5f9">Vehicle</th>
                    <th style="padding:12px;border-bottom:1px solid #f1f5f9">Date</th>
                    <th style="padding:12px;border-bottom:1px solid #f1f5f9">Valid Until</th>
                    <th style="padding:12px;border-bottom:1px solid #f1f5f9">Total</th>
                    <th style="padding:12px;border-bottom:1px solid #f1f5f9">Status</th>
                    <th style="padding:12px;border-bottom:1px solid #f1f5f9;text-align:right">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($quotes as $q): ?>
                <tr style="border-bottom:1px solid #f8fafc;font-size:13.5px">
                    <td style="padding:14px 20px;font-weight:600"><?= e($q['quotation_number']) ?></td>
                    <td style="padding:14px 12px;color:#475569"><?= e(($q['make']??'') . ' ' . ($q['model']??'')) ?></td>
                    <td style="padding:14px 12px;color:#64748b"><?= fmtDate($q['date']) ?></td>
                    <td style="padding:14px 12px;color:#64748b"><?= $q['valid_until'] ? fmtDate($q['valid_until']) : '—' ?></td>
                    <td style="padding:14px 12px;font-weight:600"><?= money((float)$q['total']) ?></td>
                    <td style="padding:14px 12px"><?= statusBadge($q['status']) ?></td>
                    <td style="padding:14px 20px;text-align:right">
                        <a href="<?= BASE_URL ?>/modules/quotations/print.php?id=<?= $q['id'] ?>" class="btn btn-xs btn-outline-primary" target="_blank">
                            <i class="fa fa-eye me-1"></i>View
                        </a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php else: ?>
        <div class="text-center py-5 text-muted">
            <i class="fa fa-file-lines fa-2x mb-3 d-block" style="color:#cbd5e1"></i>
            <p class="mb-0">No quotations yet.</p>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>

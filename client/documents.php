<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
$pageTitle = 'My Documents';
requireClientLogin();
$cl  = clientAuth();
$db  = getDB();
$cid = $cl['id'];

// Invoices
$invoices = $db->prepare("
    SELECT i.*, c.make, c.model, c.year
    FROM invoices i
    LEFT JOIN cars c ON c.id = i.car_id
    WHERE i.client_id = ? AND i.status != 'cancelled'
    ORDER BY i.created_at DESC
");
$invoices->execute([$cid]); $invoices = $invoices->fetchAll();

// Quotations
$quotes = $db->prepare("
    SELECT q.*, c.make, c.model, c.year
    FROM quotations q
    LEFT JOIN cars c ON c.id = q.car_id
    WHERE q.client_id = ?
    ORDER BY q.created_at DESC
");
$quotes->execute([$cid]); $quotes = $quotes->fetchAll();

// Sale contracts — car purchased from yard
$sales = [];
try {
    $salesStmt = $db->prepare("
        SELECT cs.*, c.make, c.model, c.year, c.registration_number
        FROM car_sales cs
        JOIN cars c ON c.id = cs.car_id
        WHERE c.client_id = ?
        ORDER BY cs.sale_date DESC
    ");
    $salesStmt->execute([$cid]); $sales = $salesStmt->fetchAll();
} catch (\Throwable $e) {}

// Quick assessments reports
$assessments = $db->prepare("
    SELECT qa.*, c.make, c.model, c.year
    FROM quick_assessments qa
    JOIN cars c ON c.id = qa.car_id
    WHERE c.client_id = ?
    ORDER BY qa.created_at DESC
");
$assessments->execute([$cid]); $assessments = $assessments->fetchAll();

$totalDocs = count($invoices) + count($quotes) + count($sales) + count($assessments);

include __DIR__ . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <h5 class="fw-700 mb-1"><i class="fa fa-folder-open me-2 text-primary"></i>My Documents</h5>
        <p class="text-muted mb-0" style="font-size:13px"><?= $totalDocs ?> document<?= $totalDocs !== 1 ? 's' : '' ?> available to download</p>
    </div>
</div>

<?php if ($totalDocs === 0): ?>
<div class="card text-center py-5">
    <i class="fa fa-folder-open fa-2x mb-3 d-block" style="color:#cbd5e1"></i>
    <p class="text-muted mb-0">No documents available yet.</p>
</div>
<?php else: ?>

<!-- Sale Contracts -->
<?php if ($sales): ?>
<div class="card mb-4">
    <div class="card-header"><i class="fa fa-file-contract me-2 text-success"></i>Purchase Agreements (<?= count($sales) ?>)</div>
    <div class="card-body p-0">
        <?php foreach ($sales as $s): ?>
        <div class="px-4 py-3 border-bottom d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
                <div class="fw-semibold" style="font-size:13px">
                    <?= e($s['make'] . ' ' . $s['model'] . ' ' . $s['year']) ?>
                    <?php if ($s['registration_number']): ?><span class="badge bg-dark ms-2"><?= e($s['registration_number']) ?></span><?php endif; ?>
                </div>
                <div class="text-muted" style="font-size:12px">
                    Sale Ref: <?= e($s['sale_number']) ?> &middot; <?= fmtDate($s['sale_date']) ?> &middot; <?= money((float)$s['sale_price']) ?>
                </div>
            </div>
            <div class="d-flex gap-2">
                <a href="<?= BASE_URL ?>/modules/sales/contract.php?id=<?= $s['id'] ?>" target="_blank"
                   class="btn btn-sm btn-outline-success">
                    <i class="fa fa-file-contract me-1"></i>Purchase Agreement
                </a>
                <?php if ($s['delivered_at']): ?>
                <a href="<?= BASE_URL ?>/modules/sales/handover.php?id=<?= $s['id'] ?>" target="_blank"
                   class="btn btn-sm btn-outline-info">
                    <i class="fa fa-clipboard-check me-1"></i>Handover Certificate
                </a>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<!-- Invoices -->
<?php if ($invoices): ?>
<div class="card mb-4">
    <div class="card-header"><i class="fa fa-file-invoice-dollar me-2 text-primary"></i>Invoices (<?= count($invoices) ?>)</div>
    <div class="card-body p-0">
        <?php foreach ($invoices as $inv): ?>
        <div class="px-4 py-3 border-bottom d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
                <div class="fw-semibold" style="font-size:13px"><?= e($inv['invoice_number']) ?></div>
                <div class="text-muted" style="font-size:12px">
                    <?php if ($inv['make']): ?>
                    <?= e($inv['make'] . ' ' . $inv['model'] . ' ' . $inv['year']) ?> &middot;
                    <?php endif; ?>
                    <?= fmtDate($inv['date'] ?? $inv['created_at']) ?> &middot; <strong><?= money((float)$inv['total']) ?></strong>
                </div>
            </div>
            <div class="d-flex gap-2 align-items-center">
                <?= statusBadge($inv['status']) ?>
                <a href="<?= BASE_URL ?>/modules/invoices/print.php?id=<?= $inv['id'] ?>" target="_blank"
                   class="btn btn-sm btn-outline-primary">
                    <i class="fa fa-download me-1"></i>Download PDF
                </a>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<!-- Quotations -->
<?php if ($quotes): ?>
<div class="card mb-4">
    <div class="card-header"><i class="fa fa-file-lines me-2 text-secondary"></i>Quotations (<?= count($quotes) ?>)</div>
    <div class="card-body p-0">
        <?php foreach ($quotes as $q): ?>
        <div class="px-4 py-3 border-bottom d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
                <div class="fw-semibold" style="font-size:13px"><?= e($q['quotation_number']) ?></div>
                <div class="text-muted" style="font-size:12px">
                    <?php if ($q['make']): ?>
                    <?= e($q['make'] . ' ' . $q['model'] . ' ' . $q['year']) ?> &middot;
                    <?php endif; ?>
                    <?= fmtDate($q['date'] ?? $q['created_at']) ?> &middot; <strong><?= money((float)$q['total']) ?></strong>
                </div>
            </div>
            <div class="d-flex gap-2 align-items-center">
                <?= statusBadge($q['status']) ?>
                <a href="<?= BASE_URL ?>/modules/quotations/print.php?id=<?= $q['id'] ?>" target="_blank"
                   class="btn btn-sm btn-outline-secondary">
                    <i class="fa fa-download me-1"></i>Download PDF
                </a>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<!-- Assessment Reports -->
<?php if ($assessments): ?>
<div class="card mb-4">
    <div class="card-header"><i class="fa fa-list-check me-2 text-info"></i>Assessment Reports (<?= count($assessments) ?>)</div>
    <div class="card-body p-0">
        <?php foreach ($assessments as $qa): ?>
        <div class="px-4 py-3 border-bottom d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
                <div class="fw-semibold" style="font-size:13px"><?= e($qa['reference_number'] ?? ('Assessment #' . $qa['id'])) ?></div>
                <div class="text-muted" style="font-size:12px">
                    <?php if ($qa['make']): ?>
                    <?= e($qa['make'] . ' ' . $qa['model'] . ' ' . $qa['year']) ?> &middot;
                    <?php endif; ?>
                    <?= fmtDate($qa['created_at']) ?>
                </div>
            </div>
            <div class="d-flex gap-2 align-items-center">
                <?= statusBadge($qa['status'] ?? 'pending') ?>
                <a href="<?= BASE_URL ?>/modules/quick_assessments/print.php?id=<?= $qa['id'] ?>" target="_blank"
                   class="btn btn-sm btn-outline-info">
                    <i class="fa fa-download me-1"></i>View Report
                </a>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<?php endif; // totalDocs ?>

<?php include __DIR__ . '/includes/footer.php'; ?>

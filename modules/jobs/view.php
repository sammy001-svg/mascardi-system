<?php
require_once __DIR__ . '/../../includes/functions.php';
$id = (int)($_GET['id']??0); if(!$id) redirect(BASE_URL.'/modules/jobs/index.php');
$db = getDB();
$stmt = $db->prepare("SELECT j.*, c.chassis_number, c.make, c.model, c.year, c.color, m.name AS mechanic_name, m.phone AS mechanic_phone FROM workshop_jobs j JOIN cars c ON c.id=j.car_id LEFT JOIN mechanics m ON m.id=j.mechanic_id WHERE j.id=?");
$stmt->execute([$id]); $job = $stmt->fetch();
if(!$job){setFlash('error','Job not found.');redirect(BASE_URL.'/modules/jobs/index.php');}

$user = authUser();
if ($user['role'] === 'mechanic' && $job['mechanic_id'] != $user['linked_id']) {
    setFlash('error', 'Access denied. This job is not assigned to you.');
    redirect(BASE_URL . '/modules/jobs/index.php');
}

// Mark Complete quick-action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'mark_complete') {
    canWrite('jobs') || die('Permission denied.');
    $db->prepare("UPDATE workshop_jobs SET status='completed', end_date=COALESCE(end_date,CURDATE()) WHERE id=?")->execute([$id]);
    $db->prepare("UPDATE cars SET status='completed' WHERE id=?")->execute([$job['car_id']]);
    logActivity('update', 'jobs', $id, "Marked job {$job['job_number']} as completed");
    setFlash('success', "Job {$job['job_number']} marked as completed.");
    redirect(BASE_URL . '/modules/jobs/view.php?id=' . $id);
}

$quotations   = $db->prepare("SELECT * FROM quotations WHERE job_id=? ORDER BY id DESC"); $quotations->execute([$id]); $quotations=$quotations->fetchAll();
$invoices     = $db->prepare("SELECT * FROM invoices WHERE job_id=? ORDER BY id DESC");   $invoices->execute([$id]);   $invoices=$invoices->fetchAll();
$lpos         = $db->prepare("SELECT l.*, s.name AS supplier_name FROM lpo l JOIN suppliers s ON s.id=l.supplier_id WHERE l.job_id=? ORDER BY l.id DESC"); $lpos->execute([$id]); $lpos=$lpos->fetchAll();
$partsReqs    = $db->prepare("SELECT pr.*, m.name AS mechanic_name FROM parts_requests pr LEFT JOIN mechanics m ON m.id=pr.mechanic_id WHERE pr.job_id=? ORDER BY pr.id DESC"); $partsReqs->execute([$id]); $partsReqs=$partsReqs->fetchAll();

$pageTitle = $job['job_number'];
include __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="mb-0">Job Card: <strong><?= e($job['job_number']) ?></strong></h5>
    <div class="d-flex gap-2">
        <?php if (canWrite('jobs')): ?>
        <?php if (!in_array($job['status'], ['completed','cancelled'])): ?>
        <form method="POST" class="d-inline" onsubmit="return confirm('Mark this job as completed?')">
            <input type="hidden" name="action" value="mark_complete">
            <button type="submit" class="btn btn-sm btn-success"><i class="fa fa-circle-check me-1"></i>Mark Complete</button>
        </form>
        <?php endif; ?>
        <a href="edit.php?id=<?= $id ?>" class="btn btn-sm btn-outline-secondary"><i class="fa fa-pen me-1"></i>Edit</a>
        <a href="<?= BASE_URL ?>/modules/quotations/add.php?car_id=<?= $job['car_id'] ?>&job_id=<?= $id ?>" class="btn btn-sm btn-outline-info"><i class="fa fa-file-lines me-1"></i>New Quotation</a>
        <a href="<?= BASE_URL ?>/modules/lpo/add.php?job_id=<?= $id ?>" class="btn btn-sm btn-outline-warning"><i class="fa fa-file-import me-1"></i>New LPO</a>
        <?php endif; ?>
        <a href="index.php" class="btn btn-sm btn-outline-secondary"><i class="fa fa-arrow-left me-1"></i>Back</a>
    </div>
</div>

<div class="row g-4">
    <div class="col-lg-4">
        <div class="card mb-3">
            <div class="card-header"><i class="fa fa-toolbox me-2"></i>Job Details</div>
            <div class="card-body">
                <dl class="row mb-0" style="font-size:13.5px">
                    <dt class="col-5 text-muted">Job Number</dt><dd class="col-7 fw-bold"><?= e($job['job_number']) ?></dd>
                    <dt class="col-5 text-muted">Status</dt><dd class="col-7"><?= statusBadge($job['status']) ?></dd>
                    <dt class="col-5 text-muted">Priority</dt><dd class="col-7"><?= statusBadge($job['priority']) ?></dd>
                    <dt class="col-5 text-muted">Start Date</dt><dd class="col-7"><?= fmtDate($job['start_date']) ?></dd>
                    <dt class="col-5 text-muted">End Date</dt><dd class="col-7"><?= fmtDate($job['end_date']) ?></dd>
                    <dt class="col-5 text-muted">Mechanic</dt><dd class="col-7"><?= e($job['mechanic_name']??'—') ?></dd>
                    <?php if($job['mechanic_phone']): ?><dt class="col-5 text-muted">Mech. Phone</dt><dd class="col-7"><?= e($job['mechanic_phone']) ?></dd><?php endif; ?>
                </dl>
                <?php if($job['description']): ?><hr><p class="small mb-0"><strong>Work Description:</strong><br><?= e($job['description']) ?></p><?php endif; ?>
                <?php if($job['notes']): ?><hr><p class="small mb-0 text-muted"><?= e($job['notes']) ?></p><?php endif; ?>
            </div>
        </div>
        <div class="card">
            <div class="card-header"><i class="fa fa-car me-2"></i>Vehicle</div>
            <div class="card-body">
                <dl class="row mb-0" style="font-size:13.5px">
                    <dt class="col-5 text-muted">Vehicle</dt><dd class="col-7 fw-semibold"><?= e($job['make'].' '.$job['model']) ?></dd>
                    <dt class="col-5 text-muted">Year</dt><dd class="col-7"><?= e($job['year']) ?></dd>
                    <dt class="col-5 text-muted">Color</dt><dd class="col-7"><?= e($job['color']??'—') ?></dd>
                    <dt class="col-5 text-muted">Chassis</dt><dd class="col-7"><code><?= e($job['chassis_number']) ?></code></dd>
                </dl>
                <div class="mt-2"><a href="<?= BASE_URL ?>/modules/cars/view.php?id=<?= $job['car_id'] ?>" class="btn btn-sm btn-outline-primary w-100"><i class="fa fa-car me-1"></i>View Full Car History</a></div>
            </div>
        </div>
    </div>

    <div class="col-lg-8">
        <!-- Quotations -->
        <div class="card mb-3">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="fa fa-file-lines me-2"></i>Quotations</span>
                <?php if (canWrite('quotations')): ?>
                <a href="<?= BASE_URL ?>/modules/quotations/add.php?car_id=<?= $job['car_id'] ?>&job_id=<?= $id ?>" class="btn btn-xs btn-outline-primary">+ New</a>
                <?php endif; ?>
            </div>
            <?php if($quotations): ?>
            <table class="table mb-0">
                <thead><tr><th class="ps-3">No.</th><th>Date</th><th>Customer</th><th>Total</th><th>Status</th><th>Actions</th></tr></thead>
                <tbody>
                    <?php foreach($quotations as $q): ?>
                    <tr>
                        <td class="ps-3"><?= e($q['quotation_number']) ?></td>
                        <td><?= fmtDate($q['date']) ?></td>
                        <td><?= e($q['customer_name']??'—') ?></td>
                        <td><strong><?= money($q['total']) ?></strong></td>
                        <td><?= statusBadge($q['status']) ?></td>
                        <td><a href="<?= BASE_URL ?>/modules/quotations/view.php?id=<?= $q['id'] ?>" class="btn btn-xs btn-outline-primary">View</a></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php else: ?><div class="card-body text-muted small">No quotations yet.</div><?php endif; ?>
        </div>

        <!-- Invoices -->
        <div class="card mb-3">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="fa fa-file-invoice-dollar me-2"></i>Invoices</span>
                <?php if (canWrite('invoices')): ?>
                <a href="<?= BASE_URL ?>/modules/invoices/add.php?job_id=<?= $id ?>" class="btn btn-xs btn-outline-success"><i class="fa fa-plus me-1"></i>New Invoice</a>
                <?php endif; ?>
            </div>
            <?php if($invoices): ?>
            <table class="table mb-0">
                <thead><tr><th class="ps-3">No.</th><th>Date</th><th>Total</th><th>Paid</th><th>Status</th><th>Actions</th></tr></thead>
                <tbody>
                    <?php foreach($invoices as $inv): ?>
                    <tr>
                        <td class="ps-3"><?= e($inv['invoice_number']) ?></td>
                        <td><?= fmtDate($inv['date']) ?></td>
                        <td><?= money($inv['total']) ?></td>
                        <td><?= money($inv['amount_paid']) ?></td>
                        <td><?= statusBadge($inv['status']) ?></td>
                        <td><a href="<?= BASE_URL ?>/modules/invoices/view.php?id=<?= $inv['id'] ?>" class="btn btn-xs btn-outline-primary">View</a></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php else: ?><div class="card-body text-muted small">No invoices yet. <a href="<?= BASE_URL ?>/modules/invoices/add.php?job_id=<?= $id ?>">Create an invoice</a> or <a href="<?= BASE_URL ?>/modules/quotations/add.php?car_id=<?= $job['car_id'] ?>&job_id=<?= $id ?>">create a quotation</a> and convert it.</div><?php endif; ?>
        </div>

        <!-- Parts Requests -->
        <div class="card mb-3">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="fa fa-hand-holding-box me-2"></i>Parts Requests</span>
                <?php if (canWrite('parts_requests')): ?>
                <a href="<?= BASE_URL ?>/modules/parts_requests/add.php?job_id=<?= $id ?>" class="btn btn-xs btn-outline-primary">+ New</a>
                <?php endif; ?>
            </div>
            <?php if($partsReqs): ?>
            <table class="table mb-0">
                <thead><tr><th class="ps-3">No.</th><th>Date</th><th>Mechanic</th><th>Status</th><th>Actions</th></tr></thead>
                <tbody>
                    <?php foreach($partsReqs as $pr): ?>
                    <tr>
                        <td class="ps-3"><?= e($pr['request_number']) ?></td>
                        <td><?= fmtDate($pr['created_at'] ?? '') ?></td>
                        <td><?= e($pr['mechanic_name'] ?? '—') ?></td>
                        <td><?= statusBadge($pr['status']) ?></td>
                        <td><a href="<?= BASE_URL ?>/modules/parts_requests/view.php?id=<?= $pr['id'] ?>" class="btn btn-xs btn-outline-primary">View</a></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php else: ?><div class="card-body text-muted small">No parts requests for this job.</div><?php endif; ?>
        </div>

        <!-- LPOs -->
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="fa fa-file-import me-2"></i>Local Purchase Orders</span>
                <?php if (canWrite('lpo')): ?>
                <a href="<?= BASE_URL ?>/modules/lpo/add.php?job_id=<?= $id ?>" class="btn btn-xs btn-outline-warning">+ New LPO</a>
                <?php endif; ?>
            </div>
            <?php if($lpos): ?>
            <table class="table mb-0">
                <thead><tr><th class="ps-3">LPO No.</th><th>Supplier</th><th>Date</th><th>Total</th><th>Status</th><th>Actions</th></tr></thead>
                <tbody>
                    <?php foreach($lpos as $l): ?>
                    <tr>
                        <td class="ps-3"><?= e($l['lpo_number']) ?></td>
                        <td><?= e($l['supplier_name']) ?></td>
                        <td><?= fmtDate($l['date']) ?></td>
                        <td><?= money($l['total']) ?></td>
                        <td><?= statusBadge($l['status']) ?></td>
                        <td><a href="<?= BASE_URL ?>/modules/lpo/view.php?id=<?= $l['id'] ?>" class="btn btn-xs btn-outline-primary">View</a></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php else: ?><div class="card-body text-muted small">No LPOs yet.</div><?php endif; ?>
        </div>
    </div>
</div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>

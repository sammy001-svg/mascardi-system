<?php
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
canAccess('drivers') || die('Access denied.');
$id = (int)($_GET['id'] ?? 0);
if (!$id) redirect(BASE_URL . '/modules/drivers/index.php');
$db = getDB();
$stmt = $db->prepare("SELECT * FROM drivers WHERE id=?");
$stmt->execute([$id]);
$driver = $stmt->fetch();
if (!$driver) { setFlash('error', 'Driver not found.'); redirect(BASE_URL . '/modules/drivers/index.php'); }

$pageTitle = $driver['name'];
$expired = $driver['license_expiry'] && $driver['license_expiry'] < date('Y-m-d');
$expiringSoon = !$expired && $driver['license_expiry'] && $driver['license_expiry'] <= date('Y-m-d', strtotime('+30 days'));

include __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="mb-0"><i class="fa fa-id-card me-2"></i><?= e($driver['name']) ?> <?= statusBadge($driver['status']) ?></h5>
    <div class="d-flex gap-2">
        <?php if (canWrite('drivers')): ?>
        <a href="edit.php?id=<?= $id ?>" class="btn btn-sm btn-outline-secondary"><i class="fa fa-pen me-1"></i>Edit</a>
        <?php endif; ?>
        <?php if (canEditDelete()): ?>
        <a href="delete.php?id=<?= $id ?>" class="btn btn-sm btn-outline-danger confirm-delete"><i class="fa fa-trash me-1"></i>Delete</a>
        <?php endif; ?>
        <a href="index.php" class="btn btn-sm btn-outline-secondary"><i class="fa fa-arrow-left me-1"></i>Back</a>
    </div>
</div>

<?php if ($expired): ?>
<div class="alert alert-danger"><i class="fa fa-triangle-exclamation me-2"></i><strong>License Expired:</strong> This driver's license expired on <?= fmtDate($driver['license_expiry']) ?>. They should not be assigned to active duties.</div>
<?php elseif ($expiringSoon): ?>
<div class="alert alert-warning"><i class="fa fa-clock me-2"></i><strong>License Expiring Soon:</strong> License expires on <?= fmtDate($driver['license_expiry']) ?>. Please arrange renewal.</div>
<?php endif; ?>

<div class="row g-4">
    <div class="col-lg-5">
        <div class="card mb-3">
            <div class="card-header"><i class="fa fa-user me-2"></i>Personal Details</div>
            <div class="card-body">
                <dl class="row mb-0" style="font-size:13.5px">
                    <dt class="col-5 text-muted">Full Name</dt>
                    <dd class="col-7 fw-semibold"><?= e($driver['name']) ?></dd>

                    <dt class="col-5 text-muted">National ID</dt>
                    <dd class="col-7"><?= e($driver['id_number'] ?? '—') ?></dd>

                    <dt class="col-5 text-muted">Phone</dt>
                    <dd class="col-7">
                        <?php if ($driver['phone']): ?>
                        <a href="tel:<?= e($driver['phone']) ?>"><?= e($driver['phone']) ?></a>
                        <?php else: ?>—<?php endif; ?>
                    </dd>

                    <dt class="col-5 text-muted">Email</dt>
                    <dd class="col-7">
                        <?php if ($driver['email']): ?>
                        <a href="mailto:<?= e($driver['email']) ?>"><?= e($driver['email']) ?></a>
                        <?php else: ?>—<?php endif; ?>
                    </dd>

                    <dt class="col-5 text-muted">Address</dt>
                    <dd class="col-7"><?= e($driver['address'] ?? '—') ?></dd>

                    <dt class="col-5 text-muted">Status</dt>
                    <dd class="col-7"><?= statusBadge($driver['status']) ?></dd>

                    <dt class="col-5 text-muted">Added</dt>
                    <dd class="col-7 text-muted small"><?= fmtDate($driver['created_at']) ?></dd>
                </dl>
            </div>
        </div>
    </div>

    <div class="col-lg-7">
        <div class="card mb-3">
            <div class="card-header"><i class="fa fa-id-card me-2"></i>License Information</div>
            <div class="card-body">
                <dl class="row mb-0" style="font-size:13.5px">
                    <dt class="col-5 text-muted">License Number</dt>
                    <dd class="col-7 fw-semibold font-monospace"><?= e($driver['license_number'] ?? '—') ?></dd>

                    <dt class="col-5 text-muted">License Class</dt>
                    <dd class="col-7"><span class="badge bg-secondary"><?= e($driver['license_class'] ?? '—') ?></span></dd>

                    <dt class="col-5 text-muted">Expiry Date</dt>
                    <dd class="col-7">
                        <?php if ($driver['license_expiry']): ?>
                        <span class="<?= $expired ? 'text-danger fw-bold' : ($expiringSoon ? 'text-warning fw-semibold' : '') ?>">
                            <?= fmtDate($driver['license_expiry']) ?>
                            <?php if ($expired): ?>
                            <span class="badge bg-danger ms-1">EXPIRED</span>
                            <?php elseif ($expiringSoon): ?>
                            <span class="badge bg-warning text-dark ms-1">EXPIRING SOON</span>
                            <?php endif; ?>
                        </span>
                        <?php else: ?><span class="text-muted">—</span><?php endif; ?>
                    </dd>
                </dl>
            </div>
        </div>

        <div class="card">
            <div class="card-header">Quick Actions</div>
            <div class="card-body d-flex gap-2 flex-wrap">
                <?php if (canWrite('drivers')): ?>
                <a href="edit.php?id=<?= $id ?>" class="btn btn-outline-secondary btn-sm">
                    <i class="fa fa-pen me-1"></i>Edit Details
                </a>
                <?php if ($driver['status'] === 'active'): ?>
                <a href="edit.php?id=<?= $id ?>" class="btn btn-outline-warning btn-sm">
                    <i class="fa fa-ban me-1"></i>Deactivate
                </a>
                <?php else: ?>
                <a href="edit.php?id=<?= $id ?>" class="btn btn-outline-success btn-sm">
                    <i class="fa fa-check me-1"></i>Activate
                </a>
                <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>

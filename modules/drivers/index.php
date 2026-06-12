<?php
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
canAccess('drivers') || die('Access denied.');
$pageTitle = 'Drivers';
$db = getDB();
$drivers = $db->query("SELECT * FROM drivers ORDER BY created_at DESC")->fetchAll();
include __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="mb-0">Drivers <span class="badge bg-secondary ms-2"><?= count($drivers) ?></span></h5>
    <?php if (canWrite('drivers')): ?>
    <a href="add.php" class="btn btn-primary btn-sm"><i class="fa fa-plus me-1"></i>Add Driver</a>
    <?php endif; ?>
</div>
<div class="card">
    <div class="card-body p-0">
        <table class="table table-hover datatable mb-0">
            <thead>
                <tr>
                    <th class="ps-3">#</th>
                    <th>Name</th>
                    <th>ID Number</th>
                    <th>License No.</th>
                    <th>Class</th>
                    <th>License Expiry</th>
                    <th>Phone</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($drivers as $i => $d): ?>
                <?php $expired = $d['license_expiry'] && $d['license_expiry'] < date('Y-m-d'); ?>
                <tr>
                    <td class="ps-3"><?= $i + 1 ?></td>
                    <td class="fw-semibold">
                        <a href="view.php?id=<?= $d['id'] ?>" class="text-decoration-none"><?= e($d['name']) ?></a>
                    </td>
                    <td><?= e($d['id_number'] ?? '—') ?></td>
                    <td><?= e($d['license_number'] ?? '—') ?></td>
                    <td><span class="badge bg-secondary"><?= e($d['license_class'] ?? '—') ?></span></td>
                    <td>
                        <?php if ($d['license_expiry']): ?>
                        <span class="<?= $expired ? 'text-danger fw-semibold' : '' ?>">
                            <?= fmtDate($d['license_expiry']) ?>
                            <?= $expired ? ' <i class="fa fa-triangle-exclamation ms-1"></i>' : '' ?>
                        </span>
                        <?php else: ?>—<?php endif; ?>
                    </td>
                    <td><?= e($d['phone'] ?? '—') ?></td>
                    <td><?= statusBadge($d['status']) ?></td>
                    <td>
                        <a href="view.php?id=<?= $d['id'] ?>" class="btn btn-xs btn-outline-primary"><i class="fa fa-eye"></i></a>
                        <?php if (canWrite('drivers')): ?>
                        <a href="edit.php?id=<?= $d['id'] ?>" class="btn btn-xs btn-outline-secondary"><i class="fa fa-pen"></i></a>
                        <?php endif; ?>
                        <?php if (canEditDelete()): ?>
                        <a href="delete.php?id=<?= $d['id'] ?>" class="btn btn-xs btn-outline-danger confirm-delete"><i class="fa fa-trash"></i></a>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (!$drivers): ?>
                <tr><td colspan="9" class="text-center text-muted py-4">No drivers registered yet.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>

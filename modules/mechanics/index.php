<?php
require_once __DIR__ . '/../../includes/functions.php';
$pageTitle = 'Mechanics';
$db = getDB();
$mechanics = $db->query("SELECT m.*, COUNT(j.id) AS jobs FROM mechanics m LEFT JOIN workshop_jobs j ON j.mechanic_id=m.id GROUP BY m.id ORDER BY m.name")->fetchAll();
include __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="mb-0">Mechanics <span class="badge bg-secondary ms-2"><?= count($mechanics) ?></span></h5>
    <?php if (canWrite('mechanics')): ?>
    <a href="add.php" class="btn btn-primary btn-sm"><i class="fa fa-plus me-1"></i>Add Mechanic</a>
    <?php endif; ?>
</div>
<div class="card">
    <div class="card-body p-0">
        <table class="table table-hover datatable mb-0">
            <thead><tr><th class="ps-3">#</th><th>Name</th><th>ID Number</th><th>Phone</th><th>Specialization</th><th>Jobs</th><th>Status</th><th>Actions</th></tr></thead>
            <tbody>
                <?php foreach ($mechanics as $i => $m): ?>
                <tr>
                    <td class="ps-3"><?= $i+1 ?></td>
                    <td class="fw-semibold"><?= e($m['name']) ?></td>
                    <td><?= e($m['id_number']??'—') ?></td>
                    <td><?= e($m['phone']??'—') ?></td>
                    <td><?= e($m['specialization']??'—') ?></td>
                    <td><span class="badge bg-primary"><?= $m['jobs'] ?></span></td>
                    <td><?= statusBadge($m['status']) ?></td>
                    <td>
                        <?php if (canWrite('mechanics')): ?>
                        <a href="edit.php?id=<?= $m['id'] ?>" class="btn btn-xs btn-outline-secondary"><i class="fa fa-pen"></i></a>
                        <?php endif; ?>
                        <?php if (canEditDelete()): ?>
                        <a href="delete.php?id=<?= $m['id'] ?>" class="btn btn-xs btn-outline-danger confirm-delete"><i class="fa fa-trash"></i></a>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>

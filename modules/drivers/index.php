<?php
require_once __DIR__ . '/../../includes/functions.php';
$pageTitle = 'Drivers';
$db = getDB();
$drivers = $db->query("SELECT d.*, COUNT(ct.id) AS trips FROM drivers d LEFT JOIN car_transfers ct ON ct.driver_id=d.id GROUP BY d.id ORDER BY d.name")->fetchAll();
include __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="mb-0">Drivers <span class="badge bg-secondary ms-2"><?= count($drivers) ?></span></h5>
    <a href="add.php" class="btn btn-primary btn-sm"><i class="fa fa-plus me-1"></i>Add Driver</a>
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
                    <th>License Class</th>
                    <th>License Expiry</th>
                    <th>Phone</th>
                    <th>Trips</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($drivers as $i => $d): ?>
                <tr>
                    <td class="ps-3"><?= $i+1 ?></td>
                    <td class="fw-semibold"><?= e($d['name']) ?></td>
                    <td><?= e($d['id_number']) ?></td>
                    <td><?= e($d['license_number']) ?></td>
                    <td><?= e($d['license_class'] ?: '—') ?></td>
                    <td><?php
                        $exp = $d['license_expiry'];
                        $expired = $exp && strtotime($exp) < time();
                        echo $exp ? '<span class="'.($expired?'text-danger fw-semibold':'').'">'.fmtDate($exp).'</span>' : '—';
                    ?></td>
                    <td><?= e($d['phone']) ?></td>
                    <td><span class="badge bg-info"><?= $d['trips'] ?></span></td>
                    <td><?= statusBadge($d['status']) ?></td>
                    <td>
                        <?php if (canEditDelete()): ?>
                        <a href="edit.php?id=<?= $d['id'] ?>" class="btn btn-xs btn-outline-secondary"><i class="fa fa-pen"></i></a>
                        <a href="delete.php?id=<?= $d['id'] ?>" class="btn btn-xs btn-outline-danger confirm-delete"><i class="fa fa-trash"></i></a>
                        <?php else: ?>
                        <span class="text-muted small">—</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>

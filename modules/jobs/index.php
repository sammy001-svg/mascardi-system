<?php
require_once __DIR__ . '/../../includes/functions.php';
$pageTitle = 'Workshop Job Cards';
$db = getDB();
$allowed = ['pending','in_progress','waiting_parts','on_hold','completed','cancelled'];
$status  = in_array($_GET['status'] ?? '', $allowed) ? $_GET['status'] : '';
if ($status) {
    $stmt = $db->prepare("SELECT j.*, c.chassis_number, c.make, c.model, m.name AS mechanic_name FROM workshop_jobs j JOIN cars c ON c.id=j.car_id LEFT JOIN mechanics m ON m.id=j.mechanic_id WHERE j.status=? ORDER BY j.created_at DESC");
    $stmt->execute([$status]);
    $jobs = $stmt->fetchAll();
} else {
    $jobs = $db->query("SELECT j.*, c.chassis_number, c.make, c.model, m.name AS mechanic_name FROM workshop_jobs j JOIN cars c ON c.id=j.car_id LEFT JOIN mechanics m ON m.id=j.mechanic_id ORDER BY j.created_at DESC")->fetchAll();
}
include __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="mb-0">Job Cards <span class="badge bg-secondary ms-2"><?= count($jobs) ?></span></h5>
    <a href="add.php" class="btn btn-primary btn-sm"><i class="fa fa-plus me-1"></i>Create Job Card</a>
</div>

<!-- Status filter pills -->
<div class="mb-3 d-flex gap-2 flex-wrap">
    <a href="?" class="btn btn-sm <?= !$status?'btn-primary':'btn-outline-secondary' ?>">All</a>
    <?php foreach (['pending','in_progress','waiting_parts','on_hold','completed','cancelled'] as $s): ?>
    <a href="?status=<?= $s ?>" class="btn btn-sm <?= $status===$s?'btn-primary':'btn-outline-secondary' ?>"><?= ucwords(str_replace('_',' ',$s)) ?></a>
    <?php endforeach; ?>
</div>

<div class="card">
    <div class="card-body p-0">
        <table class="table table-hover datatable mb-0">
            <thead><tr><th class="ps-3">Job No.</th><th>Vehicle</th><th>Chassis</th><th>Mechanic</th><th>Start Date</th><th>End Date</th><th>Priority</th><th>Status</th><th>Actions</th></tr></thead>
            <tbody>
                <?php foreach ($jobs as $j): ?>
                <tr>
                    <td class="ps-3"><strong><?= e($j['job_number']) ?></strong></td>
                    <td><?= e($j['make'].' '.$j['model']) ?></td>
                    <td><code><?= e($j['chassis_number']) ?></code></td>
                    <td><?= e($j['mechanic_name']??'—') ?></td>
                    <td><?= fmtDate($j['start_date']) ?></td>
                    <td><?= fmtDate($j['end_date']) ?></td>
                    <td><?= statusBadge($j['priority']) ?></td>
                    <td><?= statusBadge($j['status']) ?></td>
                    <td>
                        <a href="view.php?id=<?= $j['id'] ?>" class="btn btn-xs btn-outline-primary"><i class="fa fa-eye"></i></a>
                        <a href="edit.php?id=<?= $j['id'] ?>" class="btn btn-xs btn-outline-secondary"><i class="fa fa-pen"></i></a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>

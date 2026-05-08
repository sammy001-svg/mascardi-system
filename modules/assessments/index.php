<?php
require_once __DIR__ . '/../../includes/functions.php';
$pageTitle = 'Arrival Assessments';
$db = getDB();
$assessments = $db->query("SELECT ca.*, c.chassis_number, c.make, c.model, m.name AS mechanic_name FROM car_assessments ca JOIN cars c ON c.id=ca.car_id LEFT JOIN mechanics m ON m.id=ca.mechanic_id ORDER BY ca.assessment_date DESC")->fetchAll();
include __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="mb-0">Car Assessments <span class="badge bg-secondary ms-2"><?= count($assessments) ?></span></h5>
    <a href="add.php" class="btn btn-primary btn-sm"><i class="fa fa-plus me-1"></i>New Assessment</a>
</div>
<div class="card">
    <div class="card-body p-0">
        <table class="table table-hover datatable mb-0">
            <thead><tr><th class="ps-3">#</th><th>Date</th><th>Chassis</th><th>Vehicle</th><th>Type</th><th>Overall Status</th><th>Mileage</th><th>Mechanic</th><th>Actions</th></tr></thead>
            <tbody>
                <?php foreach ($assessments as $i => $a): ?>
                <tr>
                    <td class="ps-3"><?= $i+1 ?></td>
                    <td><?= fmtDate($a['assessment_date']) ?></td>
                    <td><code><?= e($a['chassis_number']) ?></code></td>
                    <td><?= e($a['make'].' '.$a['model']) ?></td>
                    <td><span class="badge bg-light text-dark border"><?= ucwords(str_replace('_',' ',$a['assessment_type'])) ?></span></td>
                    <td><?= statusBadge($a['overall_status']) ?></td>
                    <td><?= $a['mileage'] ? number_format($a['mileage']).' km' : '—' ?></td>
                    <td><?= e($a['mechanic_name']??'—') ?></td>
                    <td>
                        <a href="view.php?id=<?= $a['id'] ?>" class="btn btn-xs btn-outline-primary"><i class="fa fa-eye"></i></a>
                        <a href="delete.php?id=<?= $a['id'] ?>" class="btn btn-xs btn-outline-danger confirm-delete"><i class="fa fa-trash"></i></a>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if(empty($assessments)): ?><tr><td colspan="9" class="text-center text-muted py-4">No assessments yet.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>

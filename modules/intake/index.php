<?php
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
canAccess('intake') || die('Access denied.');
$pageTitle = 'Mombasa Intake';
$db = getDB();
$records = $db->query("
    SELECT ci.*, c.chassis_number, c.make, c.model, c.year, c.status AS car_status,
           ct.status AS transfer_status, ct.transported_by, ct.departure_date, ct.arrival_date,
           d.name AS driver_name
    FROM car_intake ci
    JOIN cars c ON c.id = ci.car_id
    LEFT JOIN car_transfers ct ON ct.car_id = ci.car_id
    LEFT JOIN drivers d ON d.id = ct.driver_id
    ORDER BY ci.intake_date DESC
")->fetchAll();
include __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="mb-0">Mombasa Intake & Dispatch <span class="badge bg-secondary ms-2"><?= count($records) ?></span></h5>
    <a href="add.php" class="btn btn-primary btn-sm"><i class="fa fa-plus me-1"></i>New Intake</a>
</div>
<div class="card">
    <div class="card-body p-0">
        <table class="table table-hover datatable mb-0">
            <thead>
                <tr>
                    <th class="ps-3">#</th>
                    <th>Chassis No.</th>
                    <th>Vehicle</th>
                    <th>Intake Date</th>
                    <th>Port</th>
                    <th>Condition</th>
                    <th>Transporter</th>
                    <th>Departure</th>
                    <th>Transfer Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($records as $i => $r): ?>
                <tr>
                    <td class="ps-3"><?= $i+1 ?></td>
                    <td><code><?= e($r['chassis_number']) ?></code></td>
                    <td><?= e($r['make'].' '.$r['model'].' ('.$r['year'].')') ?></td>
                    <td><?= fmtDate($r['intake_date']) ?></td>
                    <td><?= e($r['port']) ?></td>
                    <td><?= $r['condition_on_arrival'] ? statusBadge($r['condition_on_arrival']) : '—' ?></td>
                    <td>
                        <?php 
                        if ($r['driver_name']) echo '<i class="fa fa-user-shield me-1 text-primary"></i>'.e($r['driver_name']);
                        elseif ($r['transported_by']) echo '<i class="fa fa-truck me-1 text-muted"></i>'.e($r['transported_by']);
                        else echo '—';
                        ?>
                    </td>
                    <td><?= fmtDate($r['departure_date']) ?></td>
                    <td><?= $r['transfer_status'] ? statusBadge($r['transfer_status']) : '<span class="badge bg-light text-dark">No transfer</span>' ?></td>
                    <td><a href="view.php?id=<?= $r['id'] ?>" class="btn btn-xs btn-outline-primary"><i class="fa fa-eye"></i></a></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>

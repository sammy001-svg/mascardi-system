<?php
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
$pageTitle = 'Assessments';
$db   = getDB();
$user = authUser();
$role = $user['role'];
$linkedId = (int)($user['linked_id'] ?? 0);

// ── Build query based on role ───────────────────────────────────────────────
if ($role === 'driver') {
    // Driver only sees their own pre-departure assessments
    $stmt = $db->prepare("
        SELECT ca.*, c.chassis_number, c.make, c.model,
               NULL AS mechanic_name, d.name AS driver_name
        FROM car_assessments ca
        JOIN cars c ON c.id = ca.car_id
        LEFT JOIN drivers d ON d.id = ca.driver_id
        WHERE ca.driver_id = ?
        ORDER BY ca.assessment_date DESC, ca.id DESC
    ");
    $stmt->execute([$linkedId]);
} elseif ($role === 'mechanic') {
    $stmt = $db->prepare("
        SELECT ca.*, c.chassis_number, c.make, c.model,
               m.name AS mechanic_name, d.name AS driver_name
        FROM car_assessments ca
        JOIN cars c ON c.id = ca.car_id
        LEFT JOIN mechanics m ON m.id = ca.mechanic_id
        LEFT JOIN drivers d ON d.id = ca.driver_id
        WHERE ca.assessment_type != 'pre_departure'
        ORDER BY ca.assessment_date DESC, ca.id DESC
    ");
    $stmt->execute();
} else {
    $stmt = $db->query("
        SELECT ca.*, c.chassis_number, c.make, c.model,
               m.name AS mechanic_name, d.name AS driver_name
        FROM car_assessments ca
        JOIN cars c ON c.id = ca.car_id
        LEFT JOIN mechanics m ON m.id = ca.mechanic_id
        LEFT JOIN drivers d ON d.id = ca.driver_id
        ORDER BY ca.assessment_date DESC, ca.id DESC
    ");
}

$assessments = $stmt->fetchAll();

$typeMeta = [
    'pre_departure' => ['Pre-Departure',  'bg-warning text-dark'],
    'arrival'       => ['Arrival',        'bg-info text-dark'],
    'pre_sales'     => ['Pre-Sales',      'bg-purple'],
    'pre_delivery'  => ['Pre-Delivery',   'bg-success'],
    'workshop'      => ['Workshop',       'bg-secondary'],
];

include __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="mb-0">Assessments <span class="badge bg-secondary ms-2"><?= count($assessments) ?></span></h5>
    <a href="add.php" class="btn btn-primary btn-sm"><i class="fa fa-plus me-1"></i>New Assessment</a>
</div>

<?php if ($role === 'driver'): ?>
<div class="alert alert-info mb-3">
    <i class="fa fa-info-circle me-2"></i>
    You can only record <strong>Pre-Departure</strong> assessments for cars assigned to you. Contact your administrator to be assigned a vehicle.
</div>
<?php endif; ?>

<div class="card">
    <div class="card-body p-0">
        <table class="table table-hover datatable mb-0">
            <thead>
                <tr>
                    <th class="ps-3">Date</th>
                    <th>Chassis</th>
                    <th>Vehicle</th>
                    <th>Type</th>
                    <th>Condition</th>
                    <th>Mileage</th>
                    <th>Assessed By</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($assessments as $a):
                    [$typeLabel, $typeCls] = $typeMeta[$a['assessment_type']] ?? [ucwords(str_replace('_',' ',$a['assessment_type'])), 'bg-light text-dark border'];
                    $assessedBy = $a['driver_name'] ?? $a['mechanic_name'] ?? '—';
                ?>
                <tr>
                    <td class="ps-3"><?= fmtDate($a['assessment_date']) ?></td>
                    <td><code><?= e($a['chassis_number']) ?></code></td>
                    <td><?= e($a['make'] . ' ' . $a['model']) ?></td>
                    <td><span class="badge <?= $typeCls ?>"><?= $typeLabel ?></span></td>
                    <td><?= statusBadge($a['overall_status']) ?></td>
                    <td><?= $a['mileage'] ? number_format($a['mileage']) . ' km' : '—' ?></td>
                    <td class="text-muted small"><?= e($assessedBy) ?></td>
                    <td>
                        <a href="view.php?id=<?= $a['id'] ?>" class="btn btn-xs btn-outline-primary"><i class="fa fa-eye"></i></a>
                        <?php if (canEditDelete()): ?>
                        <a href="delete.php?id=<?= $a['id'] ?>" class="btn btn-xs btn-outline-danger confirm-delete"><i class="fa fa-trash"></i></a>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>

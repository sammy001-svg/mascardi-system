<?php
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
canAccess('service_bookings') || die('Access denied.');
$pageTitle = 'Service Bookings';
$db   = getDB();
$user = authUser();

$bookings = $db->query("
    SELECT sb.*, c.name AS client_name_link, ca.make, ca.model, ca.chassis_number
    FROM service_bookings sb
    LEFT JOIN clients c  ON c.id  = sb.client_id
    LEFT JOIN cars ca    ON ca.id = sb.car_id
    ORDER BY sb.created_at DESC
")->fetchAll();

$statusColors = ['pending'=>'warning','confirmed'=>'info','in_progress'=>'primary','completed'=>'success','cancelled'=>'danger'];
$pending = array_filter($bookings, fn($b) => $b['status'] === 'pending');

include __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <h5 class="mb-1"><i class="fa fa-calendar-check me-2 text-primary"></i>Service Bookings</h5>
        <div class="text-muted small"><?= count($pending) ?> pending booking<?= count($pending) !== 1 ? 's' : '' ?></div>
    </div>
    <a href="add.php" class="btn btn-primary btn-sm"><i class="fa fa-plus me-1"></i>New Booking</a>
</div>

<?php if (count($pending)): ?>
<div class="alert alert-warning d-flex align-items-center gap-2 mb-3">
    <i class="fa fa-bell"></i>
    <span><?= count($pending) ?> service booking<?= count($pending)!==1?'s':'' ?> awaiting confirmation.</span>
</div>
<?php endif; ?>

<div class="card">
    <div class="card-body p-0">
        <table class="table table-hover datatable mb-0">
            <thead>
                <tr>
                    <th class="ps-3">Booking #</th>
                    <th>Client</th>
                    <th>Phone</th>
                    <th>Vehicle</th>
                    <th>Service</th>
                    <th>Preferred</th>
                    <th>Status</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($bookings as $b): ?>
                <tr>
                    <td class="ps-3 fw-bold">
                        <a href="view.php?id=<?= $b['id'] ?>"><?= e($b['booking_number']) ?></a>
                        <div class="text-muted" style="font-size:11px"><?= fmtDate($b['booking_date']) ?></div>
                    </td>
                    <td class="fw-medium small"><?= e($b['client_name']) ?></td>
                    <td class="text-muted small">
                        <?php if ($b['client_phone']): ?>
                        <i class="fa-brands fa-whatsapp text-success me-1"></i><?= e($b['client_phone']) ?>
                        <?php else: ?>—<?php endif; ?>
                    </td>
                    <td class="text-muted small">
                        <?php
                        $vLabel = trim(($b['car_make'] ?: $b['make']).' '.($b['car_model'] ?: $b['model']));
                        $vReg   = $b['car_registration'] ?: $b['registration_number'] ?? '';
                        echo e($vLabel ?: $b['car_description'] ?: '—');
                        if ($vReg) echo ' <span class="badge bg-dark ms-1">'.e($vReg).'</span>';
                        ?>
                    </td>
                    <td class="text-muted small"><?= e($b['service_type'] ?? '—') ?></td>
                    <td class="text-muted small"><?= $b['preferred_date'] ? fmtDate($b['preferred_date']) : '—' ?></td>
                    <td><span class="badge bg-<?= $statusColors[$b['status']] ?? 'secondary' ?>"><?= ucwords(str_replace('_',' ',$b['status'])) ?></span></td>
                    <td>
                        <a href="view.php?id=<?= $b['id'] ?>" class="btn btn-xs btn-outline-primary"><i class="fa fa-eye"></i></a>
                        <?php if (hasRole('admin')): ?>
                        <a href="delete.php?id=<?= $b['id'] ?>" class="btn btn-xs btn-outline-danger"
                           onclick="return confirm('Delete booking <?= e($b['booking_number']) ?>? This cannot be undone.')">
                            <i class="fa fa-trash"></i>
                        </a>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>

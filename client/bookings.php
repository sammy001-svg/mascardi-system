<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
$pageTitle = 'My Bookings';
requireClientLogin();
$cl = clientAuth();
$db = getDB();

$bookings = $db->prepare("
    SELECT sb.*, ca.make, ca.model, ca.year
    FROM service_bookings sb
    LEFT JOIN cars ca ON ca.id = sb.car_id
    WHERE sb.client_id = ?
    ORDER BY sb.created_at DESC
");
$bookings->execute([$cl['id']]); $bookings = $bookings->fetchAll();

$statusColors = ['pending'=>'warning','confirmed'=>'info','in_progress'=>'primary','completed'=>'success','cancelled'=>'danger'];
$statusLabels = ['pending'=>'Pending','confirmed'=>'Confirmed','in_progress'=>'In Progress','completed'=>'Completed','cancelled'=>'Cancelled'];

$statusSteps = ['pending', 'confirmed', 'in_progress', 'completed'];
$stepLabels  = ['Pending', 'Confirmed', 'In Progress', 'Completed'];

include __DIR__ . '/includes/header.php';
?>
<h5 class="fw-700 mb-4"><i class="fa fa-calendar-check me-2 text-primary"></i>My Service Bookings</h5>

<?php if ($bookings): ?>
<div class="row g-3">
    <?php foreach ($bookings as $b): ?>
    <div class="col-md-6">
        <div class="card p-4">
            <div class="d-flex justify-content-between align-items-start mb-3">
                <div>
                    <div class="fw-700 mb-1" style="font-size:15px"><?= e($b['booking_number']) ?></div>
                    <div class="text-muted" style="font-size:12px">Booked <?= fmtDate($b['booking_date']) ?></div>
                </div>
                <span class="badge bg-<?= $statusColors[$b['status']] ?? 'secondary' ?> px-3 py-2">
                    <?= $statusLabels[$b['status']] ?? ucfirst($b['status']) ?>
                </span>
            </div>
            <!-- Progress tracker (not for cancelled) -->
            <?php if ($b['status'] !== 'cancelled'): ?>
            <?php $currentStep = array_search($b['status'], $statusSteps); $currentStep = $currentStep === false ? 0 : $currentStep; ?>
            <div class="d-flex align-items-center mb-3" style="gap:0">
                <?php foreach ($statusSteps as $i => $step): ?>
                <?php $done = $i <= $currentStep; $active = $i === $currentStep; ?>
                <div class="d-flex align-items-center" style="flex:1;min-width:0">
                    <div class="d-flex flex-column align-items-center" style="flex-shrink:0">
                        <div style="width:28px;height:28px;border-radius:50%;background:<?= $done ? '#2563eb' : '#e2e8f0' ?>;color:<?= $done ? '#fff' : '#94a3b8' ?>;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:700;border:2px solid <?= $active ? '#2563eb' : ($done ? '#2563eb' : '#e2e8f0') ?>">
                            <?= $done && !$active ? '<i class="fa fa-check" style="font-size:10px"></i>' : ($i + 1) ?>
                        </div>
                        <div style="font-size:9px;color:<?= $done ? '#2563eb' : '#94a3b8' ?>;margin-top:3px;font-weight:<?= $active ? '700' : '400' ?>;white-space:nowrap"><?= $stepLabels[$i] ?></div>
                    </div>
                    <?php if ($i < count($statusSteps) - 1): ?>
                    <div style="flex:1;height:2px;background:<?= $i < $currentStep ? '#2563eb' : '#e2e8f0' ?>;margin:0 4px;margin-bottom:14px"></div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <div style="font-size:13px;line-height:1.8">
                <?php if ($b['make']): ?>
                <div><i class="fa fa-car me-2 text-muted"></i><?= e($b['make'].' '.$b['model'].' '.$b['year']) ?></div>
                <?php elseif ($b['car_description']): ?>
                <div><i class="fa fa-car me-2 text-muted"></i><?= e($b['car_description']) ?></div>
                <?php endif; ?>
                <?php if ($b['service_type']): ?>
                <div><i class="fa fa-wrench me-2 text-muted"></i><?= e($b['service_type']) ?></div>
                <?php endif; ?>
                <?php if ($b['preferred_date']): ?>
                <div><i class="fa fa-calendar me-2 text-muted"></i>Preferred: <?= fmtDate($b['preferred_date']) ?></div>
                <?php endif; ?>
            </div>
            <?php if ($b['description']): ?>
            <div class="mt-3 p-3 rounded" style="background:#f8fafc;font-size:12.5px;color:#475569">
                <?= e($b['description']) ?>
            </div>
            <?php endif; ?>
            <?php if ($b['admin_notes']): ?>
            <div class="mt-2 p-3 rounded border-start border-primary border-3" style="background:#eff6ff;font-size:12.5px;color:#1e40af">
                <strong>From <?= e(getSetting('company_name','the workshop')) ?>:</strong><br>
                <?= nl2br(e($b['admin_notes'])) ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php else: ?>
<div class="card text-center py-5">
    <i class="fa fa-calendar-xmark fa-2x mb-3 d-block" style="color:#cbd5e1"></i>
    <p class="text-muted mb-0">No bookings yet.</p>
</div>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>

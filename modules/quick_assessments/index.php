<?php
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
canAccess('quick_assessments') || die('Access denied.');
$pageTitle = 'Quick Assessments';
$db = getDB();

$assessments = $db->query("
    SELECT qa.*,
           sb.booking_number
    FROM quick_assessments qa
    LEFT JOIN service_bookings sb ON sb.id = qa.service_booking_id
    ORDER BY qa.assessment_date DESC, qa.id DESC
")->fetchAll(PDO::FETCH_ASSOC);

$conditionMeta = [
    'good'           => ['success', 'Good'],
    'fair'           => ['warning', 'Fair'],
    'needs_attention'=> ['primary', 'Needs Attention'],
    'critical'       => ['danger',  'Critical'],
];

include __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="mb-0"><i class="fa fa-magnifying-glass-chart me-2 text-primary"></i>Quick Assessments
        <span class="badge bg-secondary ms-2"><?= count($assessments) ?></span>
    </h5>
    <?php if (canWrite('quick_assessments')): ?>
    <a href="add.php" class="btn btn-primary btn-sm"><i class="fa fa-plus me-1"></i>New Assessment</a>
    <?php endif; ?>
</div>

<div class="card">
    <div class="card-body p-0">
        <table class="table table-hover datatable mb-0" style="font-size:13px">
            <thead style="background:#f8fafc;font-size:11px;color:#64748b;text-transform:uppercase;letter-spacing:.04em">
                <tr>
                    <th class="ps-3 py-2">#</th>
                    <th class="py-2">Date</th>
                    <th class="py-2">Vehicle</th>
                    <th class="py-2">Client</th>
                    <th class="py-2">Condition</th>
                    <th class="py-2">Assessed By</th>
                    <th class="py-2">Booking</th>
                    <th class="py-2 pe-3">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($assessments as $a):
                    [$cc, $cl] = $conditionMeta[$a['overall_condition']] ?? ['secondary', ucfirst($a['overall_condition'])];
                    $vehicle = implode(' ', array_filter([$a['car_make'], $a['car_model'], $a['car_year'] ? "({$a['car_year']})" : '']));
                ?>
                <tr>
                    <td class="ps-3 py-2 fw-semibold"><?= e($a['assessment_number']) ?></td>
                    <td class="py-2"><?= fmtDate($a['assessment_date']) ?></td>
                    <td class="py-2">
                        <div class="fw-medium"><?= $vehicle ? e($vehicle) : '<span class="text-muted">—</span>' ?></div>
                        <?php if ($a['car_registration']): ?>
                        <span class="badge bg-dark" style="font-size:10px"><?= e($a['car_registration']) ?></span>
                        <?php endif; ?>
                    </td>
                    <td class="py-2">
                        <div><?= $a['client_name'] ? e($a['client_name']) : '<span class="text-muted">—</span>' ?></div>
                        <?php if ($a['client_phone']): ?><div class="text-muted" style="font-size:11px"><?= e($a['client_phone']) ?></div><?php endif; ?>
                    </td>
                    <td class="py-2"><span class="badge bg-<?= $cc ?>"><?= $cl ?></span></td>
                    <td class="py-2 text-muted small"><?= $a['assessed_by'] ? e($a['assessed_by']) : '—' ?></td>
                    <td class="py-2" style="font-size:11px">
                        <?php if ($a['booking_number']): ?>
                        <a href="<?= BASE_URL ?>/modules/service_bookings/view.php?id=<?= $a['service_booking_id'] ?>" class="text-decoration-none">
                            <i class="fa fa-calendar-check me-1 text-muted"></i><?= e($a['booking_number']) ?>
                        </a>
                        <?php else: ?><span class="text-muted">—</span><?php endif; ?>
                    </td>
                    <td class="py-2 pe-3">
                        <a href="view.php?id=<?= $a['id'] ?>" class="btn btn-xs btn-outline-primary">
                            <i class="fa fa-eye"></i>
                        </a>
                        <?php if (canEditDelete()): ?>
                        <a href="delete.php?id=<?= $a['id'] ?>" class="btn btn-xs btn-outline-danger confirm-delete ms-1">
                            <i class="fa fa-trash"></i>
                        </a>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($assessments)): ?>
                <tr><td colspan="8" class="text-center py-5 text-muted">
                    <i class="fa fa-magnifying-glass fa-2x mb-2 d-block"></i>No quick assessments yet.
                </td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>

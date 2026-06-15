<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/portal_auth.php';
$pageTitle = 'Service History';
$db     = getDB();
$client = portalClient();
$cid    = $client['id'];

// Client's cars for filter
$cars = $db->prepare("SELECT id, make, model, year, registration_number FROM cars WHERE client_id=? ORDER BY make,model");
$cars->execute([$cid]); $cars = $cars->fetchAll();

$filterCar = (int)($_GET['car_id'] ?? 0);
$carWhere  = $filterCar ? " AND sb.car_id = ?" : "";
$params    = $filterCar ? [$cid, $filterCar] : [$cid];

// Service bookings with linked job + invoice
$bookings = $db->prepare("
    SELECT sb.*,
           ca.make, ca.model, ca.year, ca.registration_number,
           qa.id AS qa_id, qa.assessment_number,
           wj.id AS job_id, wj.job_number, wj.status AS job_status,
           i.id AS invoice_id, i.invoice_number, i.total AS invoice_total, i.status AS invoice_status,
           i.amount_paid
    FROM service_bookings sb
    LEFT JOIN cars ca              ON ca.id = sb.car_id
    LEFT JOIN quick_assessments qa ON qa.service_booking_id = sb.id
    LEFT JOIN workshop_jobs wj     ON wj.assessment_id = qa.id
    LEFT JOIN invoices i           ON i.car_id = sb.car_id
                                   AND i.status != 'cancelled'
                                   AND DATE(i.created_at) BETWEEN DATE(sb.created_at)
                                   AND DATE_ADD(sb.created_at, INTERVAL 60 DAY)
    WHERE sb.client_id = ? {$carWhere}
    GROUP BY sb.id
    ORDER BY sb.booking_date DESC, sb.created_at DESC
");
$bookings->execute($params); $bookings = $bookings->fetchAll();

// Quick assessments not linked to a booking (walk-ins via client's cars)
$assessments = [];
try {
    $aParams = $filterCar ? [$cid, $filterCar] : [$cid];
    $aWhere  = $filterCar ? "AND c.id = ?" : "";
    $aStmt = $db->prepare("
        SELECT qa.*, c.make, c.model, c.year, c.registration_number
        FROM quick_assessments qa
        JOIN cars c ON c.id = qa.car_id
        WHERE c.client_id = ? {$aWhere}
        AND qa.service_booking_id IS NULL
        ORDER BY qa.assessment_date DESC LIMIT 50
    ");
    $aStmt->execute($aParams); $assessments = $aStmt->fetchAll();
} catch (\Throwable $e) {}

$bkColors = [
    'pending'     => 'warning',
    'confirmed'   => 'info',
    'in_progress' => 'primary',
    'completed'   => 'success',
    'cancelled'   => 'danger',
];
$jobColors = [
    'pending'     => 'warning',
    'in_progress' => 'primary',
    'completed'   => 'success',
    'cancelled'   => 'danger',
];

include __DIR__ . '/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <h5 class="fw-bold mb-1"><i class="fa fa-wrench me-2 text-primary"></i>Service History</h5>
        <div class="text-muted small"><?= count($bookings) ?> service visit<?= count($bookings) !== 1 ? 's' : '' ?> recorded</div>
    </div>
    <a href="<?= BASE_URL ?>/portal/bookings.php?action=new" class="btn btn-sm btn-primary no-print">
        <i class="fa fa-calendar-plus me-1"></i>Book a Service
    </a>
</div>

<?php if (count($cars) > 1): ?>
<div class="p-card mb-4 no-print">
    <div class="p-card-body py-2">
        <form method="GET" class="d-flex gap-3 align-items-center flex-wrap">
            <label class="text-muted small fw-semibold mb-0">Filter by vehicle:</label>
            <select name="car_id" class="form-select form-select-sm" style="max-width:280px" onchange="this.form.submit()">
                <option value="">All my vehicles</option>
                <?php foreach ($cars as $car): ?>
                <option value="<?= $car['id'] ?>" <?= $filterCar == $car['id'] ? 'selected' : '' ?>>
                    <?= e($car['make'].' '.$car['model'].' '.$car['year']) ?>
                    <?= $car['registration_number'] ? ' — '.e($car['registration_number']) : '' ?>
                </option>
                <?php endforeach; ?>
            </select>
            <?php if ($filterCar): ?>
            <a href="service_history.php" class="btn btn-sm btn-outline-secondary">Show all</a>
            <?php endif; ?>
        </form>
    </div>
</div>
<?php endif; ?>

<?php if (empty($bookings) && empty($assessments)): ?>
<div class="p-card text-center py-5">
    <i class="fa fa-wrench fa-2x mb-3 d-block" style="color:#cbd5e1"></i>
    <p class="fw-semibold mb-1">No service history<?= $filterCar ? ' for this vehicle' : '' ?></p>
    <p class="text-muted small mb-3">Your service visits and workshop records will appear here.</p>
    <a href="<?= BASE_URL ?>/portal/bookings.php?action=new" class="btn btn-sm btn-primary">Book a Service</a>
</div>
<?php else: ?>

<!-- Bookings / Service Visits -->
<?php if ($bookings): ?>
<div class="p-card mb-4">
    <div class="p-card-header">
        <span><i class="fa fa-calendar-check me-2 text-success"></i>Service Visits (<?= count($bookings) ?>)</span>
    </div>
    <div class="table-responsive">
        <table class="table table-hover mb-0" style="font-size:13px">
            <thead style="font-size:11.5px;color:#64748b;text-transform:uppercase;letter-spacing:.05em;background:#f8fafc">
                <tr>
                    <th class="ps-4 py-3">Booking #</th>
                    <th class="py-3">Vehicle</th>
                    <th class="py-3">Service</th>
                    <th class="py-3">Date</th>
                    <th class="py-3">Status</th>
                    <th class="py-3">Job Card</th>
                    <th class="py-3 pe-4">Invoice</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($bookings as $b): ?>
            <tr>
                <td class="ps-4 py-3 fw-semibold"><?= e($b['booking_number']) ?></td>
                <td class="py-3">
                    <?php if ($b['make']): ?>
                    <div class="fw-medium"><?= e($b['make'].' '.$b['model'].' '.$b['year']) ?></div>
                    <?php if ($b['registration_number']): ?>
                    <span class="badge bg-dark" style="font-size:10px"><?= e($b['registration_number']) ?></span>
                    <?php endif; ?>
                    <?php else: ?>
                    <span class="text-muted small"><?= e($b['car_description'] ?? '—') ?></span>
                    <?php endif; ?>
                </td>
                <td class="py-3 small">
                    <?php
                    $types = array_filter(explode(',', $b['service_type'] ?? ''));
                    foreach ($types as $t): ?>
                    <span class="badge bg-light text-dark border me-1" style="font-size:11px"><?= e(trim($t)) ?></span>
                    <?php endforeach; ?>
                    <?php if (!$types): ?><span class="text-muted">—</span><?php endif; ?>
                </td>
                <td class="py-3 text-muted small"><?= fmtDate($b['preferred_date'] ?: $b['booking_date']) ?></td>
                <td class="py-3">
                    <span class="badge bg-<?= $bkColors[$b['status']] ?? 'secondary' ?>">
                        <?= ucwords(str_replace('_', ' ', $b['status'])) ?>
                    </span>
                </td>
                <td class="py-3">
                    <?php if ($b['job_number']): ?>
                    <div>
                        <span class="badge bg-light text-dark border"><?= e($b['job_number']) ?></span>
                        <?php if ($b['job_status']): ?>
                        <br><span class="badge bg-<?= $jobColors[$b['job_status']] ?? 'secondary' ?>" style="font-size:10px;margin-top:2px">
                            <?= ucwords(str_replace('_',' ',$b['job_status'])) ?>
                        </span>
                        <?php endif; ?>
                    </div>
                    <?php else: ?>
                    <span class="text-muted small">—</span>
                    <?php endif; ?>
                </td>
                <td class="py-3 pe-4">
                    <?php if ($b['invoice_id']): ?>
                    <div>
                        <a href="<?= BASE_URL ?>/modules/invoices/print.php?id=<?= $b['invoice_id'] ?>"
                           target="_blank" class="btn btn-xs btn-outline-primary">
                            <i class="fa fa-file-invoice me-1"></i><?= e($b['invoice_number']) ?>
                        </a>
                        <div class="mt-1">
                            <?php $bal = (float)$b['invoice_total'] - (float)$b['amount_paid']; ?>
                            <?php if ($bal > 0): ?>
                            <span class="text-danger" style="font-size:11px"><i class="fa fa-circle-exclamation me-1"></i><?= money($bal) ?> due</span>
                            <?php else: ?>
                            <span class="text-success" style="font-size:11px"><i class="fa fa-circle-check me-1"></i>Paid</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php else: ?>
                    <span class="text-muted small">—</span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php if ($b['admin_notes']): ?>
            <tr>
                <td colspan="7" class="ps-4 pb-3 pt-0">
                    <div class="p-2 rounded-2 border-start border-primary border-3" style="background:#eff6ff;font-size:12px;color:#1e40af">
                        <i class="fa fa-comment-dots me-1"></i><?= nl2br(e($b['admin_notes'])) ?>
                    </div>
                </td>
            </tr>
            <?php endif; ?>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<!-- Walk-in assessments (no booking) -->
<?php if ($assessments): ?>
<div class="p-card">
    <div class="p-card-header">
        <span><i class="fa fa-clipboard-check me-2 text-info"></i>Inspection Records (<?= count($assessments) ?>)</span>
    </div>
    <div class="table-responsive">
        <table class="table table-hover mb-0" style="font-size:13px">
            <thead style="font-size:11.5px;color:#64748b;text-transform:uppercase;letter-spacing:.05em;background:#f8fafc">
                <tr>
                    <th class="ps-4 py-3">Ref</th>
                    <th class="py-3">Vehicle</th>
                    <th class="py-3">Date</th>
                    <th class="py-3">Mileage</th>
                    <th class="py-3 pe-4">Fuel</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($assessments as $a): ?>
            <tr>
                <td class="ps-4 py-3 fw-semibold small"><?= e($a['assessment_number']) ?></td>
                <td class="py-3">
                    <div class="fw-medium small"><?= e($a['make'].' '.$a['model'].' '.$a['year']) ?></div>
                    <?php if ($a['registration_number']): ?>
                    <span class="badge bg-dark" style="font-size:10px"><?= e($a['registration_number']) ?></span>
                    <?php endif; ?>
                </td>
                <td class="py-3 text-muted small"><?= fmtDate($a['assessment_date']) ?></td>
                <td class="py-3 small"><?= $a['check_mileage'] ? e($a['check_mileage']).' km' : '—' ?></td>
                <td class="py-3 pe-4 small"><?= $a['check_fuel_level'] ? e($a['check_fuel_level']) : '—' ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php endif; ?>

<?php include __DIR__ . '/footer.php'; ?>

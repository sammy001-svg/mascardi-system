<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
$pageTitle = 'Service History';
requireClientLogin();
$cl  = clientAuth();
$db  = getDB();
$cid = $cl['id'];

// Client cars for filter
$cars = $db->prepare("SELECT id, make, model, year, registration_number FROM cars WHERE client_id=? ORDER BY make,model");
$cars->execute([$cid]); $cars = $cars->fetchAll();

$filterCar = (int)($_GET['car_id'] ?? 0);

// Service bookings (workshop jobs)
$where  = "sb.client_id = ?";
$params = [$cid];
if ($filterCar) {
    $where  .= " AND sb.car_id = ?";
    $params[] = $filterCar;
}

$bookings = $db->prepare("
    SELECT sb.*,
           ca.make, ca.model, ca.year, ca.registration_number,
           wj.id AS job_id, wj.job_number, wj.status AS job_status,
           i.id AS invoice_id, i.invoice_number, i.total AS invoice_total, i.status AS invoice_status
    FROM service_bookings sb
    LEFT JOIN cars ca         ON ca.id = sb.car_id
    LEFT JOIN quick_assessments qa ON qa.service_booking_id = sb.id
    LEFT JOIN workshop_jobs wj     ON wj.assessment_id = qa.id
    LEFT JOIN invoices i           ON i.car_id = sb.car_id AND i.status != 'cancelled'
                                   AND DATE(i.created_at) BETWEEN DATE(sb.created_at) AND DATE_ADD(sb.created_at, INTERVAL 30 DAY)
    WHERE {$where}
    ORDER BY sb.created_at DESC
");
$bookings->execute($params); $bookings = $bookings->fetchAll();

$statusColors = [
    'pending'     => 'warning',
    'confirmed'   => 'info',
    'in_progress' => 'primary',
    'completed'   => 'success',
    'cancelled'   => 'danger',
];

include __DIR__ . '/includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <h5 class="fw-700 mb-0"><i class="fa fa-wrench me-2 text-primary"></i>Service History</h5>
    <a href="<?= BASE_URL ?>/client/bookings.php?new=1" class="btn btn-primary btn-sm"><i class="fa fa-calendar-plus me-1"></i>New Booking</a>
</div>

<!-- Filter -->
<?php if (count($cars) > 1): ?>
<div class="card mb-4">
    <div class="card-body py-2">
        <form method="GET" class="d-flex gap-3 align-items-center flex-wrap">
            <label class="form-label mb-0 text-muted small fw-semibold">Filter by vehicle:</label>
            <select name="car_id" class="form-select form-select-sm" style="max-width:280px" onchange="this.form.submit()">
                <option value="">All my vehicles</option>
                <?php foreach ($cars as $car): ?>
                <option value="<?= $car['id'] ?>" <?= $filterCar == $car['id'] ? 'selected' : '' ?>>
                    <?= e($car['make'] . ' ' . $car['model'] . ' ' . $car['year']) ?>
                    <?= $car['registration_number'] ? ' — ' . $car['registration_number'] : '' ?>
                </option>
                <?php endforeach; ?>
            </select>
            <?php if ($filterCar): ?><a href="service_history.php" class="btn btn-sm btn-outline-secondary">Clear</a><?php endif; ?>
        </form>
    </div>
</div>
<?php endif; ?>

<?php if ($bookings): ?>
<div class="card">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0" style="font-size:13px">
                <thead style="background:#f8fafc">
                    <tr>
                        <th class="ps-4">Booking #</th>
                        <th>Vehicle</th>
                        <th>Service Type</th>
                        <th>Date</th>
                        <th>Status</th>
                        <th>Job Card</th>
                        <th class="pe-4">Invoice</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($bookings as $b): ?>
                <tr>
                    <td class="ps-4 fw-semibold"><?= e($b['booking_number']) ?></td>
                    <td>
                        <?php if ($b['make']): ?>
                        <div class="fw-semibold"><?= e($b['make'] . ' ' . $b['model'] . ' ' . $b['year']) ?></div>
                        <?php if ($b['registration_number']): ?>
                        <div class="text-muted" style="font-size:11px"><?= e($b['registration_number']) ?></div>
                        <?php endif; ?>
                        <?php else: ?>
                        <span class="text-muted small"><?= e($b['car_description'] ?? '—') ?></span>
                        <?php endif; ?>
                    </td>
                    <td class="small">
                        <?php
                        $types = explode(', ', $b['service_type'] ?? '');
                        foreach ($types as $t): ?>
                        <span class="badge bg-light text-dark border me-1" style="font-size:11px"><?= e(trim($t)) ?></span>
                        <?php endforeach; ?>
                    </td>
                    <td class="text-muted small"><?= fmtDate($b['booking_date']) ?></td>
                    <td>
                        <span class="badge bg-<?= $statusColors[$b['status']] ?? 'secondary' ?>">
                            <?= ucwords(str_replace('_', ' ', $b['status'])) ?>
                        </span>
                    </td>
                    <td>
                        <?php if ($b['job_number']): ?>
                        <span class="badge bg-light text-dark border"><?= e($b['job_number']) ?></span>
                        <?php else: ?>
                        <span class="text-muted small">—</span>
                        <?php endif; ?>
                    </td>
                    <td class="pe-4">
                        <?php if ($b['invoice_id']): ?>
                        <a href="<?= BASE_URL ?>/modules/invoices/print.php?id=<?= $b['invoice_id'] ?>" target="_blank"
                           class="btn btn-xs btn-outline-primary" title="View Invoice">
                            <i class="fa fa-file-invoice me-1"></i><?= e($b['invoice_number']) ?>
                        </a>
                        <?php else: ?>
                        <span class="text-muted small">—</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php if ($b['admin_notes']): ?>
                <tr>
                    <td colspan="7" class="ps-4 pb-2 pt-0">
                        <div class="p-2 rounded border-start border-primary border-2" style="background:#eff6ff;font-size:12px;color:#1e40af">
                            <i class="fa fa-message me-1"></i><?= nl2br(e($b['admin_notes'])) ?>
                        </div>
                    </td>
                </tr>
                <?php endif; ?>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php else: ?>
<div class="card text-center py-5">
    <i class="fa fa-wrench fa-2x mb-3 d-block" style="color:#cbd5e1"></i>
    <p class="text-muted mb-2">No service history found<?= $filterCar ? ' for this vehicle' : '' ?>.</p>
    <a href="<?= BASE_URL ?>/client/bookings.php?new=1" class="btn btn-primary btn-sm">Book a Service</a>
</div>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>

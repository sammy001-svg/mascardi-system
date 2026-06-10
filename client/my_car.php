<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
requireClientLogin();
$cl  = clientAuth();
$db  = getDB();
$cid = $cl['id'];

$carId = (int)($_GET['id'] ?? 0);
if (!$carId) { setFlash('error', 'Vehicle not found.'); header('Location: ' . BASE_URL . '/client/index.php'); exit; }

$car = $db->prepare("SELECT * FROM cars WHERE id=? AND client_id=?");
$car->execute([$carId, $cid]); $car = $car->fetch();
if (!$car) { setFlash('error', 'Vehicle not found.'); header('Location: ' . BASE_URL . '/client/index.php'); exit; }

$pageTitle = $car['make'] . ' ' . $car['model'];

// Service bookings for this car
$bookings = $db->prepare("
    SELECT sb.*, wj.job_number
    FROM service_bookings sb
    LEFT JOIN quick_assessments qa ON qa.service_booking_id = sb.id
    LEFT JOIN workshop_jobs wj     ON wj.assessment_id = qa.id
    WHERE sb.car_id = ? AND sb.client_id = ?
    ORDER BY sb.created_at DESC
");
$bookings->execute([$carId, $cid]); $bookings = $bookings->fetchAll();

// Assessments for this car
$assessments = $db->prepare("
    SELECT qa.*
    FROM quick_assessments qa
    WHERE qa.car_id = ?
    ORDER BY qa.created_at DESC
");
$assessments->execute([$carId]); $assessments = $assessments->fetchAll();

// Invoices for this car
$invoices = $db->prepare("
    SELECT * FROM invoices
    WHERE car_id = ? AND client_id = ? AND status != 'cancelled'
    ORDER BY created_at DESC
");
$invoices->execute([$carId, $cid]); $invoices = $invoices->fetchAll();

// Sale record for this car
$sale = null;
try {
    $saleStmt = $db->prepare("SELECT * FROM car_sales WHERE car_id = ? ORDER BY id DESC LIMIT 1");
    $saleStmt->execute([$carId]); $sale = $saleStmt->fetch();
} catch (\Throwable $e) {}

$statusColors = [
    'pending'     => 'warning',
    'confirmed'   => 'info',
    'in_progress' => 'primary',
    'completed'   => 'success',
    'cancelled'   => 'danger',
];

include __DIR__ . '/includes/header.php';
?>

<!-- Vehicle Header -->
<div class="cp-welcome mb-4" style="padding:24px 28px">
    <div class="d-flex justify-content-between align-items-start flex-wrap gap-3">
        <div>
            <div style="font-size:11px;opacity:.7;text-transform:uppercase;letter-spacing:1px;margin-bottom:4px">Vehicle Detail</div>
            <h4 class="fw-700 mb-1"><?= e($car['make'] . ' ' . $car['model'] . ' ' . $car['year']) ?></h4>
            <div style="opacity:.8;font-size:13px">
                <?php if ($car['chassis_number']): ?><span>VIN: <?= e($car['chassis_number']) ?></span><?php endif; ?>
                <?php if ($car['registration_number']): ?><span class="ms-3">Reg: <strong><?= e($car['registration_number']) ?></strong></span><?php endif; ?>
            </div>
        </div>
        <a href="<?= BASE_URL ?>/client/index.php" class="btn btn-light btn-sm"><i class="fa fa-arrow-left me-1"></i>Dashboard</a>
    </div>
</div>

<div class="row g-4">
    <!-- Left: Car details -->
    <div class="col-md-4">
        <div class="card mb-4">
            <div class="card-header"><i class="fa fa-car me-2"></i>Vehicle Info</div>
            <div class="card-body" style="font-size:13px">
                <?php
                $fields = [
                    'Make'         => $car['make'],
                    'Model'        => $car['model'],
                    'Year'         => $car['year'],
                    'Color'        => $car['color'],
                    'Body Type'    => ucfirst($car['body_type'] ?? ''),
                    'Fuel Type'    => ucfirst($car['fuel_type'] ?? ''),
                    'Transmission' => ucfirst($car['transmission'] ?? ''),
                    'Chassis/VIN'  => $car['chassis_number'],
                    'Engine No.'   => $car['engine_number'] ?? null,
                    'Reg. No.'     => $car['registration_number'],
                ];
                foreach ($fields as $label => $value):
                    if (!$value) continue;
                ?>
                <div class="d-flex mb-1">
                    <span class="text-muted me-2" style="width:110px;flex-shrink:0"><?= $label ?></span>
                    <span class="fw-semibold"><?= e($value) ?></span>
                </div>
                <?php endforeach; ?>
                <?php if ($car['status']): ?>
                <div class="d-flex mb-1">
                    <span class="text-muted me-2" style="width:110px;flex-shrink:0">Status</span>
                    <span><?= statusBadge($car['status']) ?></span>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($sale): ?>
        <div class="card mb-4">
            <div class="card-header"><i class="fa fa-tag me-2 text-success"></i>Purchase</div>
            <div class="card-body" style="font-size:13px">
                <div class="d-flex mb-1">
                    <span class="text-muted me-2" style="width:110px;flex-shrink:0">Purchase Date</span>
                    <span class="fw-semibold"><?= fmtDate($sale['sale_date']) ?></span>
                </div>
                <div class="d-flex mb-1">
                    <span class="text-muted me-2" style="width:110px;flex-shrink:0">Sale No.</span>
                    <span class="fw-semibold"><?= e($sale['sale_number']) ?></span>
                </div>
                <?php if ($sale['delivered_at']): ?>
                <div class="d-flex mb-1">
                    <span class="text-muted me-2" style="width:110px;flex-shrink:0">Delivered</span>
                    <span class="fw-semibold"><?= fmtDate($sale['delivered_at']) ?></span>
                </div>
                <?php endif; ?>
                <div class="mt-3 d-flex flex-wrap gap-2">
                    <a href="<?= BASE_URL ?>/modules/sales/contract.php?id=<?= $sale['id'] ?>" target="_blank"
                       class="btn btn-sm btn-outline-success">
                        <i class="fa fa-file-contract me-1"></i>Purchase Agreement
                    </a>
                    <?php if ($sale['delivered_at']): ?>
                    <a href="<?= BASE_URL ?>/modules/sales/handover.php?id=<?= $sale['id'] ?>" target="_blank"
                       class="btn btn-sm btn-outline-info">
                        <i class="fa fa-clipboard-check me-1"></i>Handover Cert
                    </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Right: History -->
    <div class="col-md-8">
        <!-- Service Bookings -->
        <div class="card mb-4">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="fa fa-wrench me-2"></i>Service History (<?= count($bookings) ?>)</span>
                <a href="<?= BASE_URL ?>/client/bookings.php?new=1" class="btn btn-xs btn-outline-primary">+ New Booking</a>
            </div>
            <div class="card-body p-0">
                <?php if ($bookings): ?>
                <?php foreach ($bookings as $b): ?>
                <div class="px-4 py-3 border-bottom">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="fw-semibold" style="font-size:13px"><?= e($b['booking_number']) ?></div>
                            <div class="text-muted" style="font-size:12px">
                                <?= e($b['service_type'] ?? 'Service') ?>
                                <?= $b['preferred_date'] ? ' · ' . fmtDate($b['preferred_date']) : '' ?>
                            </div>
                            <?php if ($b['job_number']): ?>
                            <div class="text-muted" style="font-size:11px"><i class="fa fa-toolbox me-1"></i>Job: <?= e($b['job_number']) ?></div>
                            <?php endif; ?>
                        </div>
                        <span class="badge bg-<?= $statusColors[$b['status']] ?? 'secondary' ?>">
                            <?= ucwords(str_replace('_', ' ', $b['status'])) ?>
                        </span>
                    </div>
                    <?php if ($b['admin_notes']): ?>
                    <div class="mt-2 p-2 rounded border-start border-primary border-2" style="background:#eff6ff;font-size:12px;color:#1e40af">
                        <?= nl2br(e($b['admin_notes'])) ?>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
                <?php else: ?>
                <p class="text-muted p-4 mb-0 small">No service bookings for this vehicle.</p>
                <?php endif; ?>
            </div>
        </div>

        <!-- Assessments -->
        <?php if ($assessments): ?>
        <div class="card mb-4">
            <div class="card-header"><i class="fa fa-list-check me-2"></i>Assessments (<?= count($assessments) ?>)</div>
            <div class="card-body p-0">
                <?php foreach ($assessments as $qa): ?>
                <div class="px-4 py-3 border-bottom d-flex justify-content-between align-items-center">
                    <div>
                        <div class="fw-semibold" style="font-size:13px"><?= e($qa['reference_number'] ?? 'Assessment') ?></div>
                        <div class="text-muted" style="font-size:12px"><?= fmtDate($qa['created_at']) ?></div>
                    </div>
                    <?= statusBadge($qa['status'] ?? 'pending') ?>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Invoices -->
        <?php if ($invoices): ?>
        <div class="card">
            <div class="card-header"><i class="fa fa-file-invoice me-2"></i>Invoices (<?= count($invoices) ?>)</div>
            <div class="card-body p-0">
                <?php foreach ($invoices as $inv): ?>
                <div class="px-4 py-3 border-bottom d-flex justify-content-between align-items-center">
                    <div>
                        <div class="fw-semibold" style="font-size:13px"><?= e($inv['invoice_number']) ?></div>
                        <div class="text-muted" style="font-size:12px"><?= fmtDate($inv['date'] ?? $inv['created_at']) ?></div>
                    </div>
                    <div class="text-end">
                        <div class="fw-bold" style="font-size:13px"><?= money((float)$inv['total']) ?></div>
                        <div class="d-flex gap-1 justify-content-end align-items-center mt-1">
                            <?= statusBadge($inv['status']) ?>
                            <a href="<?= BASE_URL ?>/modules/invoices/print.php?id=<?= $inv['id'] ?>" target="_blank"
                               class="btn btn-xs btn-outline-primary"><i class="fa fa-download"></i></a>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>

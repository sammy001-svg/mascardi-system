<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/portal_auth.php';
$db     = getDB();
$client = portalClient();
$cid    = $client['id'];
$id     = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location: bookings.php'); exit; }

$stmt = $db->prepare("
    SELECT sb.*, ca.make, ca.model, ca.year, ca.chassis_number, ca.registration_number,
           ca.color
    FROM service_bookings sb
    LEFT JOIN cars ca ON ca.id = sb.car_id
    WHERE sb.id = ? AND sb.client_id = ?
");
$stmt->execute([$id, $cid]); $booking = $stmt->fetch();
if (!$booking) { header('Location: bookings.php'); exit; }

// Linked invoice
$invoice = null;
if ($booking['invoice_id'] ?? null) {
    $inv = $db->prepare("SELECT * FROM invoices WHERE id=? LIMIT 1");
    $inv->execute([$booking['invoice_id']]); $invoice = $inv->fetch();
}

$pageTitle = $booking['booking_number'];

// Status timeline
$statusFlow = ['pending', 'confirmed', 'in_progress', 'completed'];
$statusLabels = ['pending' => 'Pending', 'confirmed' => 'Confirmed', 'in_progress' => 'In Progress', 'completed' => 'Completed', 'cancelled' => 'Cancelled'];
$currentStatus = $booking['status'];
$currentIdx = array_search($currentStatus, $statusFlow);

include __DIR__ . '/header.php';
?>

<div class="mb-4">
    <a href="bookings.php" class="text-muted text-decoration-none small"><i class="fa fa-arrow-left me-1"></i>Back to Bookings</a>
    <h5 class="fw-bold mt-2 mb-0">Booking: <?= e($booking['booking_number']) ?></h5>
</div>

<?php if ($currentStatus !== 'cancelled'): ?>
<!-- Status Progress -->
<div class="p-card mb-4">
    <div class="p-card-body py-3">
        <div class="d-flex align-items-center justify-content-between" style="overflow-x:auto">
        <?php foreach ($statusFlow as $si => $step):
            $isDone   = $si < $currentIdx;
            $isActive = $si === $currentIdx;
        ?>
            <div class="text-center flex-fill" style="min-width:90px">
                <div class="mx-auto rounded-circle d-flex align-items-center justify-content-center mb-1
                    <?= $isDone ? 'bg-success text-white' : ($isActive ? 'bg-primary text-white' : 'border bg-light text-muted') ?>"
                    style="width:38px;height:38px">
                    <i class="fa <?= $isDone ? 'fa-check' : ['fa-clock','fa-thumbs-up','fa-wrench','fa-circle-check'][$si] ?> fa-sm"></i>
                </div>
                <div class="small fw-<?= $isActive ? 'bold' : 'normal' ?> <?= $isDone ? 'text-success' : ($isActive ? 'text-primary' : 'text-muted') ?>" style="font-size:11.5px">
                    <?= $statusLabels[$step] ?>
                </div>
            </div>
            <?php if ($si < count($statusFlow) - 1): ?>
            <div style="height:2px;background:<?= $isDone ? '#198754' : '#dee2e6' ?>;flex:1;min-width:16px;max-width:60px;margin-bottom:18px"></div>
            <?php endif; ?>
        <?php endforeach; ?>
        </div>
    </div>
</div>
<?php else: ?>
<div class="alert alert-danger"><i class="fa fa-ban me-2"></i>This booking has been cancelled.</div>
<?php endif; ?>

<div class="row g-4">
    <div class="col-lg-6">
        <div class="p-card">
            <div class="p-card-header"><i class="fa fa-calendar me-2 text-primary"></i>Booking Details</div>
            <div class="p-card-body">
                <dl class="row mb-0" style="font-size:13.5px">
                    <dt class="col-5 text-muted">Booking #</dt><dd class="col-7 fw-semibold"><?= e($booking['booking_number']) ?></dd>
                    <dt class="col-5 text-muted">Status</dt><dd class="col-7"><?= statusBadge($booking['status']) ?></dd>
                    <dt class="col-5 text-muted">Booked On</dt><dd class="col-7"><?= fmtDate($booking['booking_date']) ?></dd>
                    <dt class="col-5 text-muted">Preferred Date</dt><dd class="col-7"><?= $booking['preferred_date'] ? fmtDate($booking['preferred_date']) : '—' ?></dd>
                    <dt class="col-5 text-muted">Preferred Time</dt><dd class="col-7"><?= $booking['preferred_time'] ? date('g:i A', strtotime($booking['preferred_time'])) : '—' ?></dd>
                    <?php if ($booking['service_type']): ?>
                    <dt class="col-5 text-muted">Service</dt><dd class="col-7"><?= e($booking['service_type']) ?></dd>
                    <?php endif; ?>
                </dl>
                <?php if ($booking['description']): ?>
                <hr class="my-2">
                <div class="small text-muted"><?= nl2br(e($booking['description'])) ?></div>
                <?php endif; ?>
                <?php if ($booking['admin_notes']): ?>
                <div class="alert alert-info py-2 small mt-3 mb-0">
                    <i class="fa fa-info-circle me-1"></i><strong>Note from us:</strong> <?= nl2br(e($booking['admin_notes'])) ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-lg-6">
        <?php if ($booking['make'] || $booking['car_make']): ?>
        <div class="p-card mb-4">
            <div class="p-card-header"><i class="fa fa-car me-2 text-success"></i>Vehicle</div>
            <div class="p-card-body">
                <dl class="row mb-0" style="font-size:13.5px">
                    <?php if ($booking['make']): ?>
                    <dt class="col-5 text-muted">Vehicle</dt><dd class="col-7 fw-semibold"><?= e($booking['make'].' '.$booking['model'].' '.$booking['year']) ?></dd>
                    <?php if ($booking['registration_number']): ?>
                    <dt class="col-5 text-muted">Reg. No.</dt><dd class="col-7"><span class="badge bg-dark"><?= e($booking['registration_number']) ?></span></dd>
                    <?php endif; ?>
                    <?php if ($booking['chassis_number']): ?>
                    <dt class="col-5 text-muted">Chassis</dt><dd class="col-7"><code style="font-size:11px"><?= e($booking['chassis_number']) ?></code></dd>
                    <?php endif; ?>
                    <?php else: ?>
                    <dt class="col-5 text-muted">Vehicle</dt><dd class="col-7"><?= e($booking['car_make'].' '.$booking['car_model']) ?></dd>
                    <?php if ($booking['car_registration']): ?>
                    <dt class="col-5 text-muted">Reg. No.</dt><dd class="col-7"><span class="badge bg-dark"><?= e($booking['car_registration']) ?></span></dd>
                    <?php endif; ?>
                    <?php endif; ?>
                </dl>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($invoice): ?>
        <div class="p-card" style="border-left:4px solid #16a34a">
            <div class="p-card-header"><i class="fa fa-file-invoice-dollar me-2 text-success"></i>Invoice</div>
            <div class="p-card-body">
                <dl class="row mb-0" style="font-size:13.5px">
                    <dt class="col-5 text-muted">Invoice #</dt><dd class="col-7 fw-semibold"><?= e($invoice['invoice_number']) ?></dd>
                    <dt class="col-5 text-muted">Total</dt><dd class="col-7 fw-bold"><?= money($invoice['total']) ?></dd>
                    <dt class="col-5 text-muted">Paid</dt><dd class="col-7 text-success fw-semibold"><?= money($invoice['amount_paid']) ?></dd>
                    <dt class="col-5 text-muted">Status</dt><dd class="col-7"><?= statusBadge($invoice['status']) ?></dd>
                </dl>
                <a href="<?= BASE_URL ?>/portal/invoices.php" class="btn btn-sm btn-outline-success mt-3 w-100">
                    <i class="fa fa-file-invoice me-1"></i>View All Invoices
                </a>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . '/footer.php'; ?>

<?php
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/mailer.php';
requireLogin();
canAccess('service_bookings') || die('Access denied.');
$db   = getDB();
$user = authUser();
$id   = (int)($_GET['id'] ?? 0);
if (!$id) redirect('index.php');

$booking = $db->prepare("
    SELECT sb.*, cl.name AS client_link_name, cl.email AS client_link_email,
           ca.make, ca.model, ca.year, ca.chassis_number, ca.registration_number,
           wj.id AS job_id
    FROM service_bookings sb
    LEFT JOIN clients cl ON cl.id = sb.client_id
    LEFT JOIN cars ca    ON ca.id = sb.car_id
    LEFT JOIN quick_assessments qa ON qa.service_booking_id = sb.id
    LEFT JOIN workshop_jobs wj     ON wj.assessment_id = qa.id
    WHERE sb.id = ?
");
$booking->execute([$id]); $booking = $booking->fetch();
if (!$booking) { setFlash('error','Not found.'); redirect('index.php'); }

// Handle status update + notes
if ($_SERVER['REQUEST_METHOD'] === 'POST' && canWrite('service_bookings')) {
    $newStatus   = $_POST['status']      ?? $booking['status'];
    $adminNotes  = trim($_POST['admin_notes'] ?? '');
    $db->prepare("UPDATE service_bookings SET status=?,admin_notes=?,updated_at=NOW() WHERE id=?")
       ->execute([$newStatus, $adminNotes ?: $booking['admin_notes'], $id]);

    // Notify client by email if status changed
    if ($newStatus !== $booking['status'] && $booking['client_email']) {
        $labels = ['pending'=>'Pending','confirmed'=>'Confirmed','in_progress'=>'In Progress','completed'=>'Completed','cancelled'=>'Cancelled'];
        $subj = 'Service Booking ' . $booking['booking_number'] . ' — ' . ($labels[$newStatus] ?? $newStatus);
        $body = '<p>Dear ' . htmlspecialchars($booking['client_name']) . ',</p>
                 <p>Your service booking status has been updated to <strong>' . ($labels[$newStatus] ?? $newStatus) . '</strong>.</p>
                 <table class="data"><tr><th>Booking #</th><td>' . e($booking['booking_number']) . '</td></tr>
                 <tr><th>Service</th><td>' . e($booking['service_type'] ?? 'General Service') . '</td></tr></table>'
                 . ($adminNotes ? '<p>' . nl2br(e($adminNotes)) . '</p>' : '');
        sendMail($booking['client_email'], $booking['client_name'], $subj, mailTemplate($subj, $body), 'service_booking', $id);
    }

    setFlash('success', 'Booking updated.');
    redirect('view.php?id=' . $id);
}

$statusColors = ['pending'=>['warning','fa-clock'],'confirmed'=>['info','fa-check'],'in_progress'=>['primary','fa-spinner'],'completed'=>['success','fa-flag-checkered'],'cancelled'=>['danger','fa-ban']];
[$sc,$si] = $statusColors[$booking['status']] ?? ['secondary','fa-question'];

$pageTitle = $booking['booking_number'];
include __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <h5 class="mb-1"><i class="fa fa-calendar-check me-2 text-primary"></i><?= e($booking['booking_number']) ?></h5>
        <div class="text-muted small">Booked by <strong><?= e($booking['client_name']) ?></strong> on <?= fmtDate($booking['booking_date']) ?></div>
    </div>
    <div class="d-flex align-items-center gap-2">
        <span class="badge bg-<?= $sc ?> fs-6 px-3 py-2"><i class="fa <?= $si ?> me-1"></i><?= ucwords(str_replace('_',' ',$booking['status'])) ?></span>
        <?php if (canWrite('service_bookings') && !in_array($booking['status'], ['completed', 'cancelled'])): ?>
        <a href="edit.php?id=<?= $id ?>" class="btn btn-sm btn-outline-secondary"><i class="fa fa-pen me-1"></i>Edit</a>
        <?php endif; ?>
        <a href="index.php" class="btn btn-sm btn-outline-secondary"><i class="fa fa-arrow-left me-1"></i>Back</a>
    </div>
</div>

<div class="row g-4">
    <div class="col-md-5">
        <!-- Client -->
        <div class="card mb-4">
            <div class="card-header"><i class="fa fa-user me-2"></i>Client</div>
            <div class="card-body">
                <dl class="row mb-0" style="font-size:13.5px">
                    <dt class="col-5 text-muted">Name</dt>
                    <dd class="col-7 fw-medium">
                        <?= e($booking['client_name']) ?>
                        <?php if ($booking['client_id']): ?>
                        <a href="<?= BASE_URL ?>/modules/clients/view.php?id=<?= $booking['client_id'] ?>" class="ms-1 small"><i class="fa fa-external-link-alt"></i></a>
                        <?php endif; ?>
                    </dd>
                    <dt class="col-5 text-muted">Phone</dt>
                    <dd class="col-7">
                        <?php if ($booking['client_phone']): ?>
                        <i class="fa-brands fa-whatsapp text-success me-1"></i><?= e($booking['client_phone']) ?>
                        <?php else: ?>—<?php endif; ?>
                    </dd>
                    <dt class="col-5 text-muted">Email</dt>
                    <dd class="col-7 small"><?= e($booking['client_email'] ?: '—') ?></dd>
                </dl>
            </div>
        </div>
        <!-- Vehicle -->
        <div class="card mb-4">
            <div class="card-header"><i class="fa fa-car me-2"></i>Vehicle</div>
            <div class="card-body">
                <?php
                $vMake = $booking['car_make'] ?: $booking['make'];
                $vModel= $booking['car_model'] ?: $booking['model'];
                $vReg  = $booking['car_registration'] ?: $booking['registration_number'];
                ?>
                <dl class="row mb-0" style="font-size:13.5px">
                    <dt class="col-5 text-muted">Make</dt><dd class="col-7 fw-medium"><?= e($vMake ?: '—') ?></dd>
                    <dt class="col-5 text-muted">Model</dt><dd class="col-7"><?= e($vModel ?: '—') ?></dd>
                    <dt class="col-5 text-muted">Registration</dt><dd class="col-7"><span class="badge bg-dark"><?= e($vReg ?: '—') ?></span></dd>
                    <?php if ($booking['car_id']): ?>
                    <dt class="col-5 text-muted">System Car</dt>
                    <dd class="col-7"><a href="<?= BASE_URL ?>/modules/cars/view.php?id=<?= $booking['car_id'] ?>" class="btn btn-xs btn-outline-primary"><i class="fa fa-eye me-1"></i>View</a></dd>
                    <?php endif; ?>
                </dl>
            </div>
        </div>
        <!-- Service -->
        <div class="card">
            <div class="card-header"><i class="fa fa-wrench me-2"></i>Service Details</div>
            <div class="card-body">
                <dl class="row mb-0" style="font-size:13.5px">
                    <dt class="col-5 text-muted">Type</dt>
                    <dd class="col-7 fw-semibold">
                        <?php 
                        $types = explode(', ', $booking['service_type'] ?? '');
                        foreach($types as $t): ?>
                            <span class="badge bg-light text-dark border me-1 mb-1"><?= e($t) ?></span>
                        <?php endforeach; ?>
                    </dd>
                    <dt class="col-5 text-muted">Preferred Date</dt><dd class="col-7"><?= $booking['preferred_date'] ? fmtDate($booking['preferred_date']) : '—' ?></dd>
                    <dt class="col-5 text-muted">Start Time</dt><dd class="col-7"><?= e($booking['preferred_time'] ?? '—') ?></dd>
                    <dt class="col-5 text-muted">Sales Person</dt><dd class="col-7"><?= e($booking['sales_person'] ?? '—') ?></dd>
                    <?php if ($booking['description']): ?>
                    <dt class="col-12 text-muted mt-2">Issues / Symptoms</dt>
                    <dd class="col-12 text-muted small" style="white-space:pre-wrap"><?= e($booking['description']) ?></dd>
                    <?php endif; ?>
                </dl>
            </div>
        </div>
    </div>

    <div class="col-md-7">
        <!-- Update Status -->
        <?php if (canWrite('service_bookings')): ?>
        <div class="card mb-4" style="border-top:3px solid #2563eb">
            <div class="card-header"><i class="fa fa-gavel me-2"></i>Update Booking</div>
            <div class="card-body">
                <form method="POST" class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">Status</label>
                        <select name="status" class="form-select">
                            <?php foreach (['pending','confirmed','in_progress','completed','cancelled'] as $s): ?>
                            <option value="<?= $s ?>" <?= $booking['status']===$s?'selected':'' ?>><?= ucwords(str_replace('_',' ',$s)) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6 d-flex align-items-end">
                        <button type="submit" class="btn btn-primary w-100"><i class="fa fa-save me-1"></i>Update Status</button>
                    </div>
                    <div class="col-12">
                        <label class="form-label">Admin Notes / Message to Client</label>
                        <textarea name="admin_notes" class="form-control" rows="3" placeholder="Notes visible to the client when emailed…"><?= e($booking['admin_notes'] ?? '') ?></textarea>
                        <?php if ($booking['client_email']): ?>
                        <div class="text-muted small mt-1"><i class="fa fa-info-circle me-1"></i>Status change email will be sent to <?= e($booking['client_email']) ?></div>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>
        <?php endif; ?>

        <!-- Admin Notes display -->
        <?php if ($booking['admin_notes']): ?>
        <div class="card mb-4">
            <div class="card-header">Admin Notes</div>
            <div class="card-body"><p class="mb-0"><?= e($booking['admin_notes']) ?></p></div>
        </div>
        <?php endif; ?>

        <!-- Create Quotation -->
        <?php if (canAccess('quotations') && canWrite('quotations')): ?>
        <div class="card mb-4">
            <div class="card-header"><i class="fa fa-file-invoice-dollar me-2"></i>Quotation</div>
            <div class="card-body">
                <p class="text-muted small mb-2">Generate a quotation for this service booking.</p>
                <a href="<?= BASE_URL ?>/modules/quotations/add.php?booking_id=<?= $id ?>" class="btn btn-outline-primary"><i class="fa fa-plus me-1"></i>Create Quotation</a>
            </div>
        </div>
        <?php endif; ?>

        <!-- Create Job Card -->
        <?php if (canAccess('jobs') && canWrite('jobs') && in_array($booking['status'], ['confirmed','in_progress'])): ?>
        <div class="card">
            <div class="card-header"><i class="fa fa-toolbox me-2"></i>Linked Job Card</div>
            <div class="card-body">
                <?php if (!empty($booking['job_id'])): ?>
                <a href="<?= BASE_URL ?>/modules/jobs/view.php?id=<?= $booking['job_id'] ?>" class="btn btn-outline-primary"><i class="fa fa-external-link-alt me-1"></i>View Job Card</a>
                <?php elseif ($booking['car_id']): ?>
                <p class="text-muted small mb-2">Create a workshop job card for this booking.</p>
                <a href="<?= BASE_URL ?>/modules/jobs/add.php?car_id=<?= $booking['car_id'] ?>&booking_id=<?= $id ?>" class="btn btn-outline-success"><i class="fa fa-plus me-1"></i>Create Job Card</a>
                <?php else: ?>
                <p class="text-muted small mb-0">Link a vehicle from the system first to create a job card.</p>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>

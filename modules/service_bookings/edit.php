<?php
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
canAccess('service_bookings') || die('Access denied.');
canWrite('service_bookings') || die('Permission denied.');

$id = (int)($_GET['id'] ?? 0);
if (!$id) redirect(BASE_URL . '/modules/service_bookings/index.php');
$db = getDB();

$booking = $db->prepare("SELECT * FROM service_bookings WHERE id=?");
$booking->execute([$id]); $booking = $booking->fetch();
if (!$booking) { setFlash('error', 'Booking not found.'); redirect(BASE_URL . '/modules/service_bookings/index.php'); }

if (in_array($booking['status'], ['completed', 'cancelled'])) {
    setFlash('error', 'Completed or cancelled bookings cannot be edited.');
    redirect(BASE_URL . '/modules/service_bookings/view.php?id=' . $id);
}

$serviceTypes = ['Engine Service', 'Major Service', 'Diagnostics', 'Paint Job', 'Body Work', 'Buffing'];
$serviceIcons = [
    'Engine Service' => 'fa-engine',
    'Major Service'  => 'fa-screwdriver-wrench',
    'Diagnostics'    => 'fa-stethoscope',
    'Paint Job'      => 'fa-brush',
    'Body Work'      => 'fa-car-burst',
    'Buffing'        => 'fa-circle-dot',
];
$timeSlots = ['08:00','09:00','10:00','11:00','12:00','13:00','14:00','15:00'];

$errors = [];
$d = $booking;
$d['service_type'] = array_map('trim', explode(', ', $booking['service_type'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $d['client_name']      = trim($_POST['client_name'] ?? '');
    $d['client_email']     = trim($_POST['client_email'] ?? '');
    $d['client_phone']     = trim($_POST['client_phone'] ?? '');
    $d['car_make']         = trim($_POST['car_make'] ?? '');
    $d['car_model']        = trim($_POST['car_model'] ?? '');
    $d['car_registration'] = strtoupper(trim($_POST['car_registration'] ?? ''));
    $d['service_type']     = $_POST['service_type'] ?? [];
    $d['description']      = trim($_POST['description'] ?? '');
    $d['preferred_date']   = $_POST['preferred_date'] ?? '';
    $d['preferred_time']   = $_POST['preferred_time'] ?? '';
    $d['admin_notes']      = trim($_POST['admin_notes'] ?? '');
    $d['sales_person']     = trim($_POST['sales_person'] ?? '');

    if (!$d['client_name'])  $errors[] = 'Client name is required.';
    if (!$d['client_phone']) $errors[] = 'Phone number is required.';
    if (empty($d['service_type'])) $errors[] = 'Please select at least one service type.';

    if (empty($errors)) {
        try {
            $serviceStr = implode(', ', $d['service_type']);
            $db->prepare("UPDATE service_bookings SET
                client_name=?, client_email=?, client_phone=?,
                car_make=?, car_model=?, car_registration=?,
                car_description=?,
                service_type=?, description=?,
                preferred_date=?, preferred_time=?,
                admin_notes=?, sales_person=?,
                updated_at=NOW()
                WHERE id=?")
               ->execute([
                   $d['client_name'], $d['client_email'], $d['client_phone'],
                   $d['car_make'], $d['car_model'], $d['car_registration'],
                   trim($d['car_make'].' '.$d['car_model'].' '.$d['car_registration']),
                   $serviceStr, $d['description'],
                   $d['preferred_date'] ?: null, $d['preferred_time'] ?: null,
                   $d['admin_notes'], $d['sales_person'],
                   $id,
               ]);
            logActivity('update', 'service_bookings', $id, "Updated booking {$booking['booking_number']} — {$d['client_name']}");
            setFlash('success', 'Booking updated successfully.');
            redirect(BASE_URL . '/modules/service_bookings/view.php?id=' . $id);
        } catch (\Throwable $e) {
            $errors[] = 'Save failed: ' . $e->getMessage();
        }
    }
}

$pageTitle = 'Edit Booking — ' . $booking['booking_number'];
include __DIR__ . '/../../includes/header.php';
?>
<style>
.service-card input[type=checkbox] { display:none; }
.service-card label {
    display:flex; flex-direction:column; align-items:center; justify-content:center;
    gap:8px; border:2px solid #e2e8f0; border-radius:12px; padding:18px 12px;
    cursor:pointer; transition:.15s; background:#fff; text-align:center;
    font-size:13px; font-weight:600; color:#475569; height:100%; position:relative;
}
.service-card label i.fa { font-size:22px; color:#94a3b8; transition:.15s; }
.service-card input[type=checkbox]:checked + label {
    border-color:#2563eb; background:#eff6ff; color:#1d4ed8;
}
.service-card input[type=checkbox]:checked + label i.fa { color:#2563eb; }
.service-card input[type=checkbox]:checked + label::after {
    content:'\f058'; font-family:'Font Awesome 6 Free'; font-weight:900;
    position:absolute; top:8px; right:8px; color:#2563eb; font-size:16px;
}
.service-card label:hover { border-color:#93c5fd; background:#f8fafc; }
</style>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h5 class="mb-1"><i class="fa fa-pen me-2 text-primary"></i>Edit Booking: <strong><?= e($booking['booking_number']) ?></strong></h5>
        <div class="text-muted small">Client: <?= e($booking['client_name']) ?> &mdash; Booked <?= fmtDate($booking['booking_date']) ?></div>
    </div>
    <a href="view.php?id=<?= $id ?>" class="btn btn-sm btn-outline-secondary"><i class="fa fa-arrow-left me-1"></i>Back</a>
</div>

<?php if ($errors): ?>
<div class="alert alert-danger mb-4"><?php foreach ($errors as $err) echo '<div><i class="fa fa-circle-exclamation me-1"></i>'.e($err).'</div>'; ?></div>
<?php endif; ?>

<form method="POST">
<div class="row g-4">
    <div class="col-lg-8">

        <!-- Client Info -->
        <div class="card mb-4">
            <div class="card-header fw-semibold"><i class="fa fa-user me-2 text-primary"></i>Client Information</div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label">Client Name <span class="text-danger">*</span></label>
                        <input type="text" name="client_name" class="form-control" value="<?= e($d['client_name']) ?>" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Email</label>
                        <input type="email" name="client_email" class="form-control" value="<?= e($d['client_email'] ?? '') ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Phone <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="fa-brands fa-whatsapp text-success"></i></span>
                            <input type="text" name="client_phone" class="form-control" value="<?= e($d['client_phone'] ?? '') ?>" required>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Vehicle Info -->
        <div class="card mb-4">
            <div class="card-header fw-semibold"><i class="fa fa-car me-2 text-primary"></i>Vehicle Details</div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label">Car Make</label>
                        <input type="text" name="car_make" class="form-control" value="<?= e($d['car_make'] ?? '') ?>" placeholder="e.g. BMW, Toyota">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Car Model</label>
                        <input type="text" name="car_model" class="form-control" value="<?= e($d['car_model'] ?? '') ?>" placeholder="e.g. 320i, GLE">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Registration Number</label>
                        <input type="text" name="car_registration" class="form-control" value="<?= e($d['car_registration'] ?? '') ?>"
                               style="text-transform:uppercase" oninput="this.value=this.value.toUpperCase()" placeholder="e.g. KDA 000Q">
                    </div>
                </div>
            </div>
        </div>

        <!-- Service Type -->
        <div class="card mb-4">
            <div class="card-header fw-semibold"><i class="fa fa-wrench me-2 text-primary"></i>Service Type <span class="text-danger">*</span></div>
            <div class="card-body">
                <div class="row g-3">
                    <?php foreach ($serviceTypes as $st): ?>
                    <div class="col-6 col-md-4 service-card">
                        <input type="checkbox" name="service_type[]" id="st_<?= md5($st) ?>" value="<?= e($st) ?>" <?= in_array($st, (array)$d['service_type']) ? 'checked' : '' ?>>
                        <label for="st_<?= md5($st) ?>">
                            <i class="fa <?= $serviceIcons[$st] ?? 'fa-gear' ?>"></i>
                            <?= e($st) ?>
                        </label>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <!-- Issues / Description -->
        <div class="card">
            <div class="card-header fw-semibold"><i class="fa fa-comment-dots me-2 text-primary"></i>Vehicle Issues / Symptoms</div>
            <div class="card-body">
                <textarea name="description" class="form-control" rows="4" placeholder="Describe any issues, warning lights, noises…"><?= e($d['description'] ?? '') ?></textarea>
            </div>
        </div>
    </div>

    <!-- Right column -->
    <div class="col-lg-4">
        <div class="card mb-4">
            <div class="card-header fw-semibold"><i class="fa fa-calendar-days me-2 text-primary"></i>Desired Slot</div>
            <div class="card-body">
                <div class="mb-3">
                    <label class="form-label">Preferred Date</label>
                    <input type="date" name="preferred_date" class="form-control" value="<?= e($d['preferred_date'] ?? '') ?>">
                </div>
                <div class="mb-1">
                    <label class="form-label">Preferred Start Time</label>
                    <select name="preferred_time" class="form-select">
                        <option value="">— Any time —</option>
                        <?php foreach ($timeSlots as $ts): ?>
                        <option value="<?= $ts ?>" <?= ($d['preferred_time'] ?? '') === $ts ? 'selected' : '' ?>><?= $ts ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </div>

        <div class="card mb-4">
            <div class="card-header fw-semibold"><i class="fa fa-pen-to-square me-2 text-primary"></i>Additional Info</div>
            <div class="card-body">
                <div class="mb-3">
                    <label class="form-label">Admin Notes / Comment</label>
                    <textarea name="admin_notes" class="form-control" rows="3"><?= e($d['admin_notes'] ?? '') ?></textarea>
                </div>
                <div class="mb-1">
                    <label class="form-label">Sales Person In Charge</label>
                    <input type="text" name="sales_person" class="form-control" value="<?= e($d['sales_person'] ?? '') ?>">
                </div>
            </div>
        </div>

        <div class="d-grid gap-2">
            <button type="submit" class="btn btn-primary py-2 fw-semibold"><i class="fa fa-save me-2"></i>Save Changes</button>
            <a href="view.php?id=<?= $id ?>" class="btn btn-outline-secondary">Cancel</a>
        </div>
    </div>
</div>
</form>
<?php include __DIR__ . '/../../includes/footer.php'; ?>

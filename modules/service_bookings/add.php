<?php
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
requireRole(['admin', 'manager']);
$pageTitle = 'New Service Booking';
$db   = getDB();
$user = authUser();

$serviceTypes = [
    'Engine Service',
    'Major Service',
    'Diagnostics',
    'Paint Job',
    'Body Work',
    'Buffing',
];

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
$d = [
    'client_name'      => '',
    'client_phone'     => '',
    'car_make'         => '',
    'car_model'        => '',
    'car_registration' => '',
    'service_type'     => '',
    'description'      => '',
    'preferred_date'   => '',
    'preferred_time'   => '',
    'admin_notes'      => '',
    'sales_person'     => '',
    'booking_date'     => date('Y-m-d'),
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($d as $k => $_) $d[$k] = trim($_POST[$k] ?? '');
    $d['booking_date'] = date('Y-m-d');

    if (!$d['client_name'])  $errors[] = 'Client name is required.';
    if (!$d['client_phone']) $errors[] = 'Phone number is required.';
    if (!$d['service_type']) $errors[] = 'Please select a service type.';

    if (empty($errors)) {
        try {
            $bNum = nextNumber('service_bookings', 'booking_number', 'BK');
            $db->prepare("
                INSERT INTO service_bookings
                    (booking_number, client_name, client_phone,
                     car_make, car_model, car_registration, car_description,
                     service_type, description,
                     booking_date, preferred_date, preferred_time,
                     admin_notes, sales_person, created_by)
                VALUES (?,?,?, ?,?,?,?, ?,?, ?,?,?, ?,?,?)
            ")->execute([
                $bNum, $d['client_name'], $d['client_phone'],
                $d['car_make'], $d['car_model'], $d['car_registration'],
                trim($d['car_make'].' '.$d['car_model'].' '.$d['car_registration']),
                $d['service_type'], $d['description'],
                $d['booking_date'],
                $d['preferred_date'] ?: null,
                $d['preferred_time'] ?: null,
                $d['admin_notes'], $d['sales_person'],
                $user['name'],
            ]);
            $newId = $db->lastInsertId();
            setFlash('success', "Booking {$bNum} created.");
            redirect(BASE_URL . '/modules/service_bookings/view.php?id=' . $newId);
        } catch (\Throwable $e) {
            $errors[] = 'Save failed: ' . $e->getMessage();
        }
    }
}

include __DIR__ . '/../../includes/header.php';
?>
<style>
.service-card input[type=radio] { display:none; }
.service-card label {
    display:flex; flex-direction:column; align-items:center; justify-content:center;
    gap:8px; border:2px solid #e2e8f0; border-radius:12px; padding:18px 12px;
    cursor:pointer; transition:.15s; background:#fff; text-align:center;
    font-size:13px; font-weight:600; color:#475569; height:100%;
}
.service-card label i { font-size:22px; color:#94a3b8; transition:.15s; }
.service-card input[type=radio]:checked + label {
    border-color:#2563eb; background:#eff6ff; color:#1d4ed8;
}
.service-card input[type=radio]:checked + label i { color:#2563eb; }
.service-card label:hover { border-color:#93c5fd; background:#f8fafc; }
</style>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h5 class="mb-0"><i class="fa fa-calendar-plus me-2 text-primary"></i>New Service Booking</h5>
    <a href="index.php" class="btn btn-sm btn-outline-secondary"><i class="fa fa-arrow-left me-1"></i>Back</a>
</div>

<?php if ($errors): ?>
<div class="alert alert-danger mb-4"><?php foreach ($errors as $err) echo '<div><i class="fa fa-circle-exclamation me-1"></i>'.e($err).'</div>'; ?></div>
<?php endif; ?>

<form method="POST">
<div class="row g-4">

    <!-- ── Left column ── -->
    <div class="col-lg-8">

        <!-- Client Info -->
        <div class="card mb-4">
            <div class="card-header fw-semibold"><i class="fa fa-user me-2 text-primary"></i>Client Information</div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">Client Name <span class="text-danger">*</span></label>
                        <input type="text" name="client_name" class="form-control" placeholder="Full name" value="<?= e($d['client_name']) ?>" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Phone <span class="text-danger">*</span> <small class="text-muted fw-normal">(WhatsApp preferred)</small></label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="fa-brands fa-whatsapp text-success"></i></span>
                            <input type="text" name="client_phone" class="form-control" placeholder="e.g. 0712 345 678" value="<?= e($d['client_phone']) ?>" required>
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
                        <input type="text" name="car_make" class="form-control" placeholder="e.g. BMW, Audi, Toyota" value="<?= e($d['car_make']) ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Car Model</label>
                        <input type="text" name="car_model" class="form-control" placeholder="e.g. 320i, SQ5, GLE" value="<?= e($d['car_model']) ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Registration Number</label>
                        <input type="text" name="car_registration" class="form-control" placeholder="e.g. KDA 000Q" value="<?= e($d['car_registration']) ?>" style="text-transform:uppercase" oninput="this.value=this.value.toUpperCase()">
                    </div>
                </div>
            </div>
        </div>

        <!-- Service Type -->
        <div class="card mb-4">
            <div class="card-header fw-semibold"><i class="fa fa-wrench me-2 text-primary"></i>Service Type Requested <span class="text-danger">*</span></div>
            <div class="card-body">
                <div class="row g-3">
                    <?php foreach ($serviceTypes as $st): ?>
                    <div class="col-6 col-md-4 service-card">
                        <input type="radio" name="service_type" id="st_<?= md5($st) ?>" value="<?= e($st) ?>" <?= $d['service_type']===$st?'checked':'' ?>>
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
                <textarea name="description" class="form-control" rows="4" placeholder="Describe any issues, warning lights, noises, or symptoms the client has reported…"><?= e($d['description']) ?></textarea>
            </div>
        </div>
    </div>

    <!-- ── Right column ── -->
    <div class="col-lg-4">

        <!-- Schedule -->
        <div class="card mb-4">
            <div class="card-header fw-semibold"><i class="fa fa-calendar-days me-2 text-primary"></i>Desired Slot</div>
            <div class="card-body">
                <div class="mb-3">
                    <label class="form-label">Preferred Date <small class="text-muted fw-normal">(Mon – Sat)</small></label>
                    <input type="date" name="preferred_date" class="form-control" value="<?= e($d['preferred_date']) ?>">
                    <div class="form-text"><i class="fa fa-circle-info me-1 text-primary"></i>We will check availability and confirm.</div>
                </div>
                <div class="mb-1">
                    <label class="form-label">Preferred Start Time <small class="text-muted fw-normal">(last start 15:00)</small></label>
                    <select name="preferred_time" class="form-select">
                        <option value="">— Any time —</option>
                        <?php foreach ($timeSlots as $ts): ?>
                        <option value="<?= $ts ?>" <?= $d['preferred_time']===$ts?'selected':'' ?>><?= $ts ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </div>

        <!-- Comment & Sales Person -->
        <div class="card mb-4">
            <div class="card-header fw-semibold"><i class="fa fa-pen-to-square me-2 text-primary"></i>Additional Info</div>
            <div class="card-body">
                <div class="mb-3">
                    <label class="form-label">Comment</label>
                    <textarea name="admin_notes" class="form-control" rows="3" placeholder="Any other notes or special requests…"><?= e($d['admin_notes']) ?></textarea>
                </div>
                <div class="mb-1">
                    <label class="form-label">Sales Person In Charge</label>
                    <input type="text" name="sales_person" class="form-control" placeholder="Staff name" value="<?= e($d['sales_person']) ?>">
                </div>
            </div>
        </div>

        <button type="submit" class="btn btn-primary w-100 py-2 fw-semibold">
            <i class="fa fa-calendar-check me-2"></i>Create Booking
        </button>
    </div>

</div>
</form>

<?php include __DIR__ . '/../../includes/footer.php'; ?>

<?php
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
canAccess('cars') || die('Access denied.');

$db  = getDB();
$me  = authUser();
$id  = (int)($_GET['id'] ?? 0);
if (!$id) redirect(BASE_URL . '/modules/cars/index.php');

// ── Vehicle ──────────────────────────────────────────────────
$car = $db->prepare("
    SELECT c.*, l.name AS location_name,
           cl.name AS owner_name, cl.phone AS owner_phone, cl.email AS owner_email
    FROM cars c
    LEFT JOIN locations l ON l.id = c.location_id
    LEFT JOIN clients cl  ON cl.id = c.client_id
    WHERE c.id = ?
");
$car->execute([$id]);
$car = $car->fetch();
if (!$car) { setFlash('error','Vehicle not found.'); redirect(BASE_URL . '/modules/cars/index.php'); }

// ── Latest non-cancelled service booking + quick assessment ──
$booking = $db->prepare("
    SELECT sb.*,
           qa.id AS qa_id, qa.overall_condition, qa.observations,
           qa.recommended_services, qa.assessed_by, qa.assessment_date,
           qa.check_tyres, qa.check_lights, qa.check_exterior, qa.check_engine,
           qa.check_interior, qa.check_brakes, qa.check_fluids, qa.check_electrical
    FROM service_bookings sb
    LEFT JOIN quick_assessments qa ON qa.service_booking_id = sb.id
    WHERE sb.car_id = ? AND sb.status NOT IN ('cancelled')
    ORDER BY sb.id DESC
    LIMIT 1
");
$booking->execute([$id]);
$booking = $booking->fetch();

// ── Workshop jobs ─────────────────────────────────────────────
$stmtJobs = $db->prepare("
    SELECT j.*, m.name AS mechanic_name
    FROM workshop_jobs j
    LEFT JOIN mechanics m ON m.id = j.mechanic_id
    WHERE j.car_id = ?
    ORDER BY j.id DESC
");
$stmtJobs->execute([$id]);
$jobs = $stmtJobs->fetchAll();

$activeJob = null;
foreach ($jobs as $j) {
    if (!in_array($j['status'], ['completed', 'cancelled'])) { $activeJob = $j; break; }
}

// ── Parts requests for active job ────────────────────────────
$partsReqs = [];
if ($activeJob) {
    $prStmt = $db->prepare("
        SELECT pr.*, m.name AS mech FROM parts_requests pr
        LEFT JOIN mechanics m ON m.id = pr.mechanic_id
        WHERE pr.job_id = ? ORDER BY pr.id DESC
    ");
    $prStmt->execute([$activeJob['id']]);
    $partsReqs = $prStmt->fetchAll();
}

// ── POST handlers ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $canAct = canWrite('jobs') || canWrite('service_bookings');
    if (!$canAct) { setFlash('error','Permission denied.'); redirect('workshop.php?id='.$id); }

    if ($action === 'complete_service') {
        $db->prepare("UPDATE workshop_jobs SET status='completed', end_date=COALESCE(end_date,CURDATE()), updated_at=NOW() WHERE car_id=? AND status NOT IN ('completed','cancelled')")
           ->execute([$id]);
        if ($booking) {
            $db->prepare("UPDATE service_bookings SET status='completed', updated_at=NOW() WHERE id=?")->execute([$booking['id']]);
        }
        $db->prepare("UPDATE cars SET status='completed', updated_at=NOW() WHERE id=?")->execute([$id]);
        logActivity('update','cars',$id,"Service completed: {$car['make']} {$car['model']}");
        require_once __DIR__ . '/../../includes/notifications.php';
        notifyRoles(
            ['admin','general_manager','sales_manager','sales_officer','workshop_manager'],
            'cars',
            "Service Complete: {$car['make']} {$car['model']} {$car['year']}",
            ($car['registration_number'] ?: $car['chassis_number']) . " is ready for collection",
            BASE_URL . '/modules/cars/workshop.php?id=' . $id
        );
        setFlash('success','Service marked as complete. Vehicle is ready for collection.');
        redirect('workshop.php?id='.$id);
    }

    if ($action === 'checkout') {
        $db->prepare("UPDATE workshop_jobs SET status='completed', end_date=COALESCE(end_date,CURDATE()), updated_at=NOW() WHERE car_id=? AND status NOT IN ('completed','cancelled')")
           ->execute([$id]);
        if ($booking && !in_array($booking['status'], ['completed','cancelled'])) {
            $db->prepare("UPDATE service_bookings SET status='completed', updated_at=NOW() WHERE id=?")->execute([$booking['id']]);
        }
        $db->prepare("UPDATE cars SET status='completed', updated_at=NOW() WHERE id=?")->execute([$id]);
        logActivity('update','cars',$id,"Vehicle checked out of workshop: {$car['make']} {$car['model']}");
        setFlash('success','Vehicle checked out from the workshop successfully.');
        redirect(BASE_URL . '/modules/cars/index.php');
    }

    if ($action === 'update_job') {
        $jid   = (int)($_POST['job_id'] ?? 0);
        $jstat = $_POST['job_status'] ?? '';
        $valid = ['pending','in_progress','waiting_parts','on_hold','completed','cancelled'];
        if ($jid && in_array($jstat, $valid)) {
            $extra = $jstat === 'completed' ? ', end_date=COALESCE(end_date,CURDATE())' : '';
            $db->prepare("UPDATE workshop_jobs SET status=?{$extra}, updated_at=NOW() WHERE id=? AND car_id=?")
               ->execute([$jstat, $jid, $id]);
            setFlash('success','Job status updated.');
        }
        redirect('workshop.php?id='.$id);
    }

    if ($action === 'add_note' && !empty($_POST['note'])) {
        $note = trim($_POST['note']);
        if ($activeJob) {
            $stamp    = '['.date('d M Y H:i').' — '.$me['name'].'] ';
            $newNotes = ($activeJob['notes'] ? rtrim($activeJob['notes'])."\n" : '') . $stamp . $note;
            $db->prepare("UPDATE workshop_jobs SET notes=?, updated_at=NOW() WHERE id=?")->execute([$newNotes, $activeJob['id']]);
            setFlash('success','Note added to job card.');
        }
        redirect('workshop.php?id='.$id);
    }
}

// ── Determine progress steps ──────────────────────────────────
$isCheckedOut  = in_array($car['status'], ['completed','delivered','sold']);
$isComplete    = $isCheckedOut || ($booking && $booking['status'] === 'completed');
$hasJobRunning = $activeJob || ($booking && in_array($booking['status'], ['in_progress']));
$isCheckedIn   = $car['status'] === 'in_workshop' || $hasJobRunning;
$hasAssessment = !empty($booking['qa_id']);
$hasBooking    = !empty($booking);

$steps = [
    ['label'=>'Booking',      'icon'=>'fa-calendar-check', 'done'=>$hasBooking,    'active'=>$hasBooking && !$isCheckedIn],
    ['label'=>'Checked In',   'icon'=>'fa-right-to-bracket','done'=>$isCheckedIn,  'active'=>$isCheckedIn && !$hasJobRunning],
    ['label'=>'Assessed',     'icon'=>'fa-clipboard-check', 'done'=>$hasAssessment,'active'=>$hasAssessment && !$hasJobRunning],
    ['label'=>'In Progress',  'icon'=>'fa-screwdriver-wrench','done'=>$hasJobRunning,'active'=>$hasJobRunning && !$isComplete],
    ['label'=>'Complete',     'icon'=>'fa-circle-check',   'done'=>$isComplete,   'active'=>$isComplete && !$isCheckedOut],
    ['label'=>'Checked Out',  'icon'=>'fa-flag-checkered', 'done'=>$isCheckedOut, 'active'=>$isCheckedOut],
];
$currentStep = 0;
foreach ($steps as $i => $s) { if ($s['done'] || $s['active']) $currentStep = $i; }

$canAct   = canWrite('jobs') || canWrite('service_bookings');
$isInShop = $car['status'] === 'in_workshop';

$pageTitle = 'Workshop — '.$car['make'].' '.$car['model'];
include __DIR__ . '/../../includes/header.php';

$condIcon = fn($v) => match($v) {
    'ok'    => '<span class="badge bg-success-subtle text-success border border-success-subtle"><i class="fa fa-check me-1"></i>OK</span>',
    'issue' => '<span class="badge bg-danger-subtle text-danger border border-danger-subtle"><i class="fa fa-triangle-exclamation me-1"></i>Issue</span>',
    'na'    => '<span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle">N/A</span>',
    default => '<span class="text-muted">—</span>',
};

$condColor = fn($v) => match($v) {
    'good'            => 'success',
    'fair'            => 'warning',
    'needs_attention' => 'orange',
    'critical'        => 'danger',
    default           => 'secondary',
};
?>

<style>
.ws-stepper { display:flex; align-items:center; gap:0; overflow-x:auto; padding:4px 0; }
.ws-step    { display:flex; flex-direction:column; align-items:center; flex:1; min-width:80px; position:relative; }
.ws-step-dot {
    width:42px; height:42px; border-radius:50%;
    display:flex; align-items:center; justify-content:center;
    font-size:15px; border:2px solid #dee2e6;
    background:#f8f9fa; color:#adb5bd;
    transition: all .2s;
}
.ws-step.done  .ws-step-dot { background:#198754; border-color:#198754; color:#fff; }
.ws-step.active .ws-step-dot { background:#2563eb; border-color:#2563eb; color:#fff; box-shadow:0 0 0 4px rgba(37,99,235,.18); }
.ws-step-label { font-size:11.5px; margin-top:6px; font-weight:600; color:#adb5bd; white-space:nowrap; }
.ws-step.done  .ws-step-label { color:#198754; }
.ws-step.active .ws-step-label { color:#2563eb; }
.ws-line  { flex:1; height:2px; background:#dee2e6; min-width:16px; max-width:60px; margin-bottom:22px; flex-shrink:0; }
.ws-line.done { background:#198754; }

.cond-grid { display:grid; grid-template-columns:1fr 1fr; gap:8px; }
.cond-item { display:flex; justify-content:space-between; align-items:center;
             background:#f8f9fa; border-radius:8px; padding:8px 12px; font-size:13px; }
.cond-item-label { font-weight:600; color:#374151; }

.job-card { border-left:4px solid #dee2e6; border-radius:0 8px 8px 0; padding:14px 16px; background:#fff; margin-bottom:10px; box-shadow:0 1px 4px rgba(0,0,0,.05); }
.job-card.pending       { border-left-color:#fbbf24; }
.job-card.in_progress   { border-left-color:#2563eb; }
.job-card.waiting_parts { border-left-color:#f97316; }
.job-card.on_hold       { border-left-color:#8b5cf6; }
.job-card.completed     { border-left-color:#22c55e; }
.job-card.cancelled     { border-left-color:#ef4444; opacity:.65; }

.notes-pre { white-space:pre-wrap; font-size:12.5px; color:#374151; background:#f8f9fa;
             border-radius:8px; padding:12px 14px; max-height:160px; overflow-y:auto; }
</style>

<!-- ── Page header ─────────────────────────────────────────── -->
<div class="d-flex justify-content-between align-items-start mb-3 flex-wrap gap-2">
    <div>
        <h5 class="mb-1">
            <i class="fa fa-toolbox me-2 text-primary"></i>
            <?= e($car['make'].' '.$car['model'].' '.$car['year']) ?>
            <?php if ($car['registration_number']): ?>
            <span class="badge bg-dark ms-2" style="font-size:13px;letter-spacing:1px"><?= e($car['registration_number']) ?></span>
            <?php endif; ?>
        </h5>
        <div class="d-flex gap-2 align-items-center flex-wrap">
            <?= statusBadge($car['status']) ?>
            <?php if ($car['car_type'] === 'client'): ?>
            <span class="badge bg-info text-dark"><i class="fa fa-user me-1"></i>Client Vehicle</span>
            <?php else: ?>
            <span class="badge bg-primary"><i class="fa fa-warehouse me-1"></i>Inventory</span>
            <?php endif; ?>
            <?php if ($car['location_name']): ?>
            <span class="text-muted small"><i class="fa fa-location-dot me-1"></i><?= e($car['location_name']) ?></span>
            <?php endif; ?>
        </div>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <?php if ($canAct && $isInShop && !$isComplete): ?>
        <form method="POST" class="d-inline" onsubmit="return confirm('Mark this vehicle\'s service as complete?')">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="complete_service">
            <button class="btn btn-success"><i class="fa fa-circle-check me-1"></i>Mark Service Complete</button>
        </form>
        <?php endif; ?>
        <?php if ($canAct && $isComplete && $isInShop): ?>
        <form method="POST" class="d-inline" onsubmit="return confirm('Check this vehicle out of the workshop?')">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="checkout">
            <button class="btn btn-warning"><i class="fa fa-right-from-bracket me-1"></i>Check Out from Workshop</button>
        </form>
        <?php endif; ?>
        <a href="view.php?id=<?= $id ?>" class="btn btn-sm btn-outline-secondary"><i class="fa fa-eye me-1"></i>Full History</a>
        <a href="index.php" class="btn btn-sm btn-outline-secondary"><i class="fa fa-arrow-left me-1"></i>Back</a>
    </div>
</div>

<!-- ── Already checked out banner ───────────────────────────── -->
<?php if ($isCheckedOut && !$isInShop): ?>
<div class="alert alert-success d-flex align-items-center gap-2 mb-3">
    <i class="fa fa-flag-checkered fa-lg"></i>
    <div><strong>Vehicle checked out.</strong> This vehicle has been released from the workshop.</div>
</div>
<?php endif; ?>

<!-- ── Progress stepper ──────────────────────────────────────── -->
<div class="card mb-4">
    <div class="card-body py-3">
        <div class="ws-stepper">
            <?php foreach ($steps as $i => $step):
                $cls = $step['done'] ? 'done' : ($step['active'] ? 'active' : '');
            ?>
            <div class="ws-step <?= $cls ?>">
                <div class="ws-step-dot"><i class="fa <?= $step['icon'] ?>"></i></div>
                <div class="ws-step-label"><?= $step['label'] ?></div>
            </div>
            <?php if ($i < count($steps) - 1): ?>
            <div class="ws-line <?= $step['done'] ? 'done' : '' ?>"></div>
            <?php endif; ?>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<!-- ── Main layout ───────────────────────────────────────────── -->
<div class="row g-4">

<!-- LEFT: Vehicle + Assessment ─────────────────────────────── -->
<div class="col-lg-4">

    <!-- Vehicle card -->
    <div class="card mb-3">
        <div class="card-header fw-semibold"><i class="fa fa-car me-2 text-primary"></i>Vehicle</div>
        <div class="card-body">
            <dl class="row mb-0" style="font-size:13.5px">
                <dt class="col-5 text-muted">Make / Model</dt>
                <dd class="col-7 fw-bold"><?= e($car['make'].' '.$car['model']) ?></dd>
                <dt class="col-5 text-muted">Year</dt>
                <dd class="col-7"><?= e($car['year']) ?></dd>
                <dt class="col-5 text-muted">Color</dt>
                <dd class="col-7"><?= e($car['color'] ?: '—') ?></dd>
                <dt class="col-5 text-muted">Reg. No.</dt>
                <dd class="col-7"><span class="badge bg-dark"><?= e($car['registration_number'] ?: '—') ?></span></dd>
                <dt class="col-5 text-muted">Chassis</dt>
                <dd class="col-7"><code style="font-size:11px"><?= e($car['chassis_number']) ?></code></dd>
                <dt class="col-5 text-muted">Transmission</dt>
                <dd class="col-7"><?= ucfirst($car['transmission'] ?: '—') ?></dd>
                <dt class="col-5 text-muted">Fuel</dt>
                <dd class="col-7"><?= ucfirst($car['fuel_type'] ?: '—') ?></dd>
            </dl>
        </div>
    </div>

    <!-- Client / Owner -->
    <?php if ($car['car_type'] === 'client' && $car['owner_name']): ?>
    <div class="card mb-3">
        <div class="card-header fw-semibold"><i class="fa fa-user me-2"></i>Owner / Client</div>
        <div class="card-body">
            <dl class="row mb-0" style="font-size:13.5px">
                <dt class="col-5 text-muted">Name</dt>
                <dd class="col-7 fw-medium">
                    <?= e($car['owner_name']) ?>
                    <?php if ($car['client_id']): ?>
                    <a href="<?= BASE_URL ?>/modules/clients/view.php?id=<?= $car['client_id'] ?>" class="ms-1 small"><i class="fa fa-external-link-alt"></i></a>
                    <?php endif; ?>
                </dd>
                <?php if ($car['owner_phone']): ?>
                <dt class="col-5 text-muted">Phone</dt>
                <dd class="col-7"><i class="fa-brands fa-whatsapp text-success me-1"></i><?= e($car['owner_phone']) ?></dd>
                <?php endif; ?>
                <?php if ($car['owner_email']): ?>
                <dt class="col-5 text-muted">Email</dt>
                <dd class="col-7 small"><?= e($car['owner_email']) ?></dd>
                <?php endif; ?>
            </dl>
        </div>
    </div>
    <?php endif; ?>

    <!-- Quick Assessment -->
    <?php if ($booking && $booking['qa_id']): ?>
    <div class="card mb-3">
        <div class="card-header fw-semibold d-flex justify-content-between align-items-center">
            <span><i class="fa fa-clipboard-check me-2"></i>Vehicle Condition (on arrival)</span>
            <?php
            $oc = $booking['overall_condition'] ?? 'good';
            $ocColor = match($oc) {
                'good' => 'success', 'fair' => 'warning',
                'needs_attention' => 'danger', 'critical' => 'danger',
                default => 'secondary'
            };
            ?>
            <span class="badge bg-<?= $ocColor ?>"><?= ucwords(str_replace('_',' ',$oc)) ?></span>
        </div>
        <div class="card-body pb-2">
            <div class="cond-grid mb-3">
                <?php
                $checks = [
                    'check_tyres'    => 'Tyres',
                    'check_lights'   => 'Lights',
                    'check_exterior' => 'Exterior',
                    'check_engine'   => 'Engine',
                    'check_interior' => 'Interior',
                    'check_brakes'   => 'Brakes',
                    'check_fluids'   => 'Fluids',
                    'check_electrical' => 'Electrical',
                ];
                foreach ($checks as $field => $label): ?>
                <div class="cond-item">
                    <span class="cond-item-label"><?= $label ?></span>
                    <?= ($condIcon)($booking[$field] ?? 'na') ?>
                </div>
                <?php endforeach; ?>
            </div>
            <?php if ($booking['observations']): ?>
            <div class="mb-2">
                <div class="text-muted small fw-semibold mb-1">Observations</div>
                <div class="notes-pre"><?= e($booking['observations']) ?></div>
            </div>
            <?php endif; ?>
            <?php if ($booking['recommended_services']): ?>
            <div class="mb-2">
                <div class="text-muted small fw-semibold mb-1">Recommended Services</div>
                <div class="notes-pre"><?= e($booking['recommended_services']) ?></div>
            </div>
            <?php endif; ?>
            <?php if ($booking['assessed_by']): ?>
            <div class="text-muted small mt-1"><i class="fa fa-user-pen me-1"></i>Assessed by <?= e($booking['assessed_by']) ?>
                <?php if ($booking['assessment_date']): ?> &mdash; <?= fmtDate($booking['assessment_date']) ?><?php endif; ?>
            </div>
            <?php endif; ?>
            <a href="<?= BASE_URL ?>/modules/quick_assessments/view.php?id=<?= $booking['qa_id'] ?>" class="btn btn-sm btn-outline-secondary mt-2 w-100">
                <i class="fa fa-external-link-alt me-1"></i>Full Assessment Report
            </a>
        </div>
    </div>
    <?php endif; ?>

</div><!-- /left -->

<!-- RIGHT: Booking + Jobs ──────────────────────────────────── -->
<div class="col-lg-8">

    <!-- Service Booking -->
    <?php if ($booking): ?>
    <div class="card mb-4" style="border-top:3px solid #2563eb">
        <div class="card-header fw-semibold d-flex justify-content-between align-items-center">
            <span><i class="fa fa-calendar-check me-2 text-primary"></i>Service Booking — <?= e($booking['booking_number']) ?></span>
            <?= statusBadge($booking['status']) ?>
        </div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-sm-6">
                    <div class="text-muted small fw-semibold mb-1">Services Requested</div>
                    <?php foreach (array_filter(array_map('trim', explode(',', $booking['service_type'] ?? ''))) as $svc): ?>
                    <span class="badge bg-primary-subtle text-primary border border-primary-subtle me-1 mb-1"><?= e($svc) ?></span>
                    <?php endforeach; ?>
                </div>
                <div class="col-sm-6">
                    <div class="text-muted small fw-semibold mb-1">Client / Booking Info</div>
                    <div class="small">
                        <strong><?= e($booking['client_name'] ?: '—') ?></strong><br>
                        <?php if ($booking['client_phone']): ?>
                        <i class="fa-brands fa-whatsapp text-success me-1"></i><?= e($booking['client_phone']) ?><br>
                        <?php endif; ?>
                        <?php if ($booking['preferred_date']): ?>
                        <i class="fa fa-calendar me-1 text-muted"></i>Pref. <?= fmtDate($booking['preferred_date']) ?>
                        <?php if ($booking['preferred_time']): ?> at <?= e($booking['preferred_time']) ?><?php endif; ?><br>
                        <?php endif; ?>
                    </div>
                </div>
                <?php if ($booking['description']): ?>
                <div class="col-12">
                    <div class="text-muted small fw-semibold mb-1">Issues / Symptoms Reported</div>
                    <div class="notes-pre"><?= e($booking['description']) ?></div>
                </div>
                <?php endif; ?>
                <?php if ($booking['admin_notes']): ?>
                <div class="col-12">
                    <div class="text-muted small fw-semibold mb-1">Notes</div>
                    <div class="notes-pre"><?= e($booking['admin_notes']) ?></div>
                </div>
                <?php endif; ?>
            </div>
            <div class="mt-3 d-flex gap-2 flex-wrap">
                <a href="<?= BASE_URL ?>/modules/service_bookings/view.php?id=<?= $booking['id'] ?>" class="btn btn-sm btn-outline-primary">
                    <i class="fa fa-external-link-alt me-1"></i>Full Booking Details
                </a>
                <?php if (canAccess('jobs') && canWrite('jobs') && !$activeJob): ?>
                <a href="<?= BASE_URL ?>/modules/jobs/add.php?car_id=<?= $id ?>&booking_id=<?= $booking['id'] ?>" class="btn btn-sm btn-outline-success">
                    <i class="fa fa-plus me-1"></i>Create Job Card
                </a>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php else: ?>
    <div class="alert alert-warning mb-4">
        <i class="fa fa-triangle-exclamation me-2"></i>No active service booking found for this vehicle.
        <?php if (canAccess('service_bookings') && canWrite('service_bookings')): ?>
        <a href="<?= BASE_URL ?>/modules/service_bookings/add.php?car_id=<?= $id ?>" class="btn btn-sm btn-warning ms-2">
            <i class="fa fa-plus me-1"></i>Create Booking
        </a>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- Workshop Jobs ──────────────────────────────────────── -->
    <div class="card mb-4">
        <div class="card-header fw-semibold d-flex justify-content-between align-items-center">
            <span><i class="fa fa-screwdriver-wrench me-2"></i>Workshop Jobs</span>
            <div class="d-flex gap-2">
                <?php if (count($jobs) > 0): ?>
                <span class="badge bg-secondary"><?= count($jobs) ?></span>
                <?php endif; ?>
                <?php if (canAccess('jobs') && canWrite('jobs')): ?>
                <a href="<?= BASE_URL ?>/modules/jobs/add.php?car_id=<?= $id ?><?= $booking ? '&booking_id='.$booking['id'] : '' ?>"
                   class="btn btn-xs btn-outline-success">
                    <i class="fa fa-plus me-1"></i>New Job
                </a>
                <?php endif; ?>
            </div>
        </div>
        <div class="card-body">
            <?php if (empty($jobs)): ?>
            <p class="text-muted small mb-0">No workshop jobs yet.
                <?php if (canWrite('jobs')): ?>
                <a href="<?= BASE_URL ?>/modules/jobs/add.php?car_id=<?= $id ?><?= $booking ? '&booking_id='.$booking['id'] : '' ?>">Create the first job card.</a>
                <?php endif; ?>
            </p>
            <?php else: foreach ($jobs as $j):
                $jStatusColors = ['pending'=>'warning','in_progress'=>'primary','waiting_parts'=>'orange','on_hold'=>'purple','completed'=>'success','cancelled'=>'danger'];
            ?>
            <div class="job-card <?= $j['status'] ?>">
                <div class="d-flex justify-content-between align-items-start gap-2 flex-wrap">
                    <div>
                        <div class="fw-semibold"><?= e($j['job_number']) ?>
                            <?php if ($j['priority'] === 'urgent' || $j['priority'] === 'high'): ?>
                            <span class="badge bg-danger ms-1" style="font-size:10px"><?= strtoupper($j['priority']) ?></span>
                            <?php endif; ?>
                        </div>
                        <div class="small text-muted">
                            <i class="fa fa-user-wrench me-1"></i><?= e($j['mechanic_name'] ?: 'Unassigned') ?>
                            <span class="mx-2">·</span>
                            <i class="fa fa-calendar me-1"></i><?= fmtDate($j['start_date']) ?>
                            <?php if ($j['end_date']): ?> → <?= fmtDate($j['end_date']) ?><?php endif; ?>
                        </div>
                        <?php if ($j['description']): ?>
                        <div class="small mt-1 text-secondary"><?= e(mb_substr($j['description'],0,100)).(mb_strlen($j['description'])>100?'…':'') ?></div>
                        <?php endif; ?>
                    </div>
                    <div class="d-flex gap-2 align-items-center flex-wrap">
                        <?= statusBadge($j['status']) ?>
                        <?php if ($canAct && !in_array($j['status'],['completed','cancelled'])): ?>
                        <form method="POST" class="d-flex gap-1 align-items-center" onsubmit="return true">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="update_job">
                            <input type="hidden" name="job_id" value="<?= $j['id'] ?>">
                            <select name="job_status" class="form-select form-select-sm" style="width:auto"
                                    onchange="this.form.submit()">
                                <?php foreach (['pending'=>'Pending','in_progress'=>'In Progress','waiting_parts'=>'Waiting Parts','on_hold'=>'On Hold','completed'=>'Completed','cancelled'=>'Cancelled'] as $sv=>$sl): ?>
                                <option value="<?= $sv ?>" <?= $j['status']===$sv?'selected':'' ?>><?= $sl ?></option>
                                <?php endforeach; ?>
                            </select>
                        </form>
                        <?php endif; ?>
                        <a href="<?= BASE_URL ?>/modules/jobs/view.php?id=<?= $j['id'] ?>" class="btn btn-xs btn-outline-secondary">
                            <i class="fa fa-eye"></i>
                        </a>
                    </div>
                </div>
                <?php if ($j['notes']): ?>
                <div class="notes-pre mt-2"><?= e($j['notes']) ?></div>
                <?php endif; ?>
            </div>
            <?php endforeach; endif; ?>
        </div>
    </div>

    <!-- Add Note to active job ──────────────────────────────── -->
    <?php if ($canAct && $activeJob): ?>
    <div class="card mb-4">
        <div class="card-header fw-semibold"><i class="fa fa-pen-to-square me-2"></i>Add Note to <?= e($activeJob['job_number']) ?></div>
        <div class="card-body">
            <form method="POST">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="add_note">
                <div class="input-group">
                    <textarea name="note" class="form-control" rows="2" placeholder="Type a progress note, observation or update…" required></textarea>
                    <button class="btn btn-primary align-self-stretch"><i class="fa fa-plus me-1"></i>Add</button>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <!-- Parts Requests ─────────────────────────────────────── -->
    <?php if ($partsReqs): ?>
    <div class="card mb-4">
        <div class="card-header fw-semibold d-flex justify-content-between align-items-center">
            <span><i class="fa fa-hand-holding-box me-2"></i>Parts Requests</span>
            <span class="badge bg-secondary"><?= count($partsReqs) ?></span>
        </div>
        <table class="table table-sm mb-0" style="font-size:13px">
            <thead class="table-light">
                <tr><th class="ps-3">Ref</th><th>Date</th><th>Mechanic</th><th>Status</th><th></th></tr>
            </thead>
            <tbody>
                <?php foreach ($partsReqs as $pr): ?>
                <tr>
                    <td class="ps-3 fw-medium"><?= e($pr['request_number'] ?? '#'.$pr['id']) ?></td>
                    <td><?= fmtDate($pr['created_at'] ?? '') ?></td>
                    <td><?= e($pr['mech'] ?? '—') ?></td>
                    <td><?= statusBadge($pr['status']) ?></td>
                    <td><a href="<?= BASE_URL ?>/modules/parts_requests/view.php?id=<?= $pr['id'] ?>" class="btn btn-xs btn-outline-secondary">View</a></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

</div><!-- /right -->
</div><!-- /row -->

<?php include __DIR__ . '/../../includes/footer.php'; ?>

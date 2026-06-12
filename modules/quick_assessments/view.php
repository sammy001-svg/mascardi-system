<?php
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
canAccess('quick_assessments') || die('Access denied.');

$id = (int)($_GET['id'] ?? 0);
$db = getDB();

// Handle Check In to Workshop
if (isset($_GET['action']) && $_GET['action'] === 'check_in') {
    $tmp = $db->prepare("SELECT car_id FROM quick_assessments WHERE id = ?");
    $tmp->execute([$id]);
    $carId = $tmp->fetchColumn();
    if ($carId) {
        $db->prepare("UPDATE cars SET status = 'in_workshop' WHERE id = ?")->execute([$carId]);
        logActivity('update', 'cars', $carId, 'Checked in to workshop via quick assessment #' . $id);
        setFlash('success', 'Vehicle checked in to Workshop.');
    }
    redirect(BASE_URL . '/modules/quick_assessments/view.php?id=' . $id);
}

$stmt = $db->prepare("
    SELECT qa.*,
           sb.booking_number,
           c.chassis_number,
           c.status AS car_status,
           u.name AS creator_name
    FROM quick_assessments qa
    LEFT JOIN service_bookings sb ON sb.id = qa.service_booking_id
    LEFT JOIN cars c ON c.id = qa.car_id
    LEFT JOIN users u ON u.id = qa.created_by
    WHERE qa.id = ?
");
$stmt->execute([$id]);
$a = $stmt->fetch();

if (!$a) {
    setFlash('danger', 'Assessment not found.');
    redirect('index.php');
}

$pageTitle = "QA — {$a['assessment_number']}";
include __DIR__ . '/../../includes/header.php';

$conditionMeta = [
    'good'            => ['success', 'fa-circle-check',         'Good'],
    'fair'            => ['warning', 'fa-circle-minus',         'Fair'],
    'needs_attention' => ['primary', 'fa-triangle-exclamation', 'Needs Attention'],
    'critical'        => ['danger',  'fa-circle-xmark',         'Critical'],
];
[$cc, $ci, $cl] = $conditionMeta[$a['overall_condition']] ?? ['secondary','fa-circle-question', ucfirst((string)$a['overall_condition'])];

// Check items (standard text fields)
$checkItems = [
    'tyres'      => ['Tyres',           'fa-circle-dot'],
    'lights'     => ['Lights',          'fa-lightbulb'],
    'exterior'   => ['Exterior Body',   'fa-car-side'],
    'engine'     => ['Engine Bay',      'fa-gears'],
    'interior'   => ['Interior',        'fa-couch'],
    'brakes'     => ['Brakes',          'fa-circle-stop'],
    'fluids'     => ['Fluid Levels',    'fa-droplet'],
    'electrical' => ['Electrical',      'fa-microchip'],
    'jack'       => ['Jack & Tools',    'fa-wrench'],
    'radio'      => ['Radio / Audio',   'fa-radio'],
];
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h5 class="mb-0">Quick Assessment: <?= e($a['assessment_number']) ?></h5>
        <div class="text-muted small">Recorded on <?= fmtDate($a['assessment_date']) ?></div>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <?php if ($a['car_id']): ?>
            <?php if ($a['car_status'] === 'in_workshop'): ?>
            <span class="btn btn-sm btn-warning text-dark pe-none">
                <i class="fa fa-screwdriver-wrench me-1"></i>In Workshop
            </span>
            <?php else: ?>
            <a href="view.php?id=<?= $id ?>&action=check_in"
               class="btn btn-sm btn-warning text-dark"
               onclick="return confirm('Check this vehicle into the Workshop?')">
                <i class="fa fa-screwdriver-wrench me-1"></i>Check In to Workshop
            </a>
            <?php endif; ?>
        <?php endif; ?>
        <a href="index.php" class="btn btn-sm btn-outline-secondary"><i class="fa fa-arrow-left me-1"></i>Back</a>
        <a href="print.php?id=<?= $id ?>" target="_blank" class="btn btn-sm btn-primary"><i class="fa fa-print me-1"></i>Print</a>
        <?php if (canEditDelete()): ?>
        <a href="delete.php?id=<?= $a['id'] ?>" class="btn btn-sm btn-outline-danger confirm-delete"><i class="fa fa-trash"></i></a>
        <?php endif; ?>
    </div>
</div>

<div class="row g-4">

    <!-- ── Left: Checklist + Observations ─────────────────────────────────── -->
    <div class="col-lg-8">

        <!-- Checklist -->
        <div class="card mb-4">
            <div class="card-header bg-white fw-bold d-flex align-items-center justify-content-between">
                <span><i class="fa fa-list-check me-2 text-primary"></i>Checklist Results</span>
                <span class="badge bg-<?= $cc ?>">
                    <i class="fa <?= $ci ?> me-1"></i><?= $cl ?>
                </span>
            </div>
            <div class="card-body">

                <!-- Standard check items -->
                <div class="row g-2 mb-4">
                    <?php foreach ($checkItems as $key => [$label, $icon]):
                        $val = $a["check_{$key}"] ?? null;
                    ?>
                    <div class="col-md-6">
                        <div class="d-flex align-items-start gap-2 p-2 border rounded bg-light bg-opacity-50">
                            <i class="fa <?= $icon ?> text-muted mt-1" style="width:18px;text-align:center;flex-shrink:0"></i>
                            <div style="min-width:0;flex:1">
                                <div class="fw-semibold" style="font-size:12px;color:#6c757d;text-transform:uppercase;letter-spacing:.4px"><?= $label ?></div>
                                <?php if ($val): ?>
                                <div style="font-size:13px;color:#212529;word-break:break-word"><?= nl2br(e($val)) ?></div>
                                <?php else: ?>
                                <div style="font-size:12px;color:#adb5bd;font-style:italic">Not checked</div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>

                <!-- Readings row -->
                <div class="row g-3 mb-4">
                    <div class="col-md-4">
                        <div class="border rounded p-3 text-center bg-light bg-opacity-50">
                            <div class="text-muted mb-1" style="font-size:11px;text-transform:uppercase;letter-spacing:.5px"><i class="fa fa-gauge-high me-1"></i>Mileage</div>
                            <div class="fw-bold fs-5">
                                <?= $a['check_mileage'] ? number_format((float)$a['check_mileage']) . ' km' : '<span class="text-muted" style="font-size:14px">—</span>' ?>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="border rounded p-3 text-center bg-light bg-opacity-50">
                            <div class="text-muted mb-1" style="font-size:11px;text-transform:uppercase;letter-spacing:.5px"><i class="fa fa-gas-pump me-1"></i>Fuel Level</div>
                            <div class="fw-bold fs-6"><?= $a['check_fuel_level'] ? e($a['check_fuel_level']) : '<span class="text-muted" style="font-size:14px">—</span>' ?></div>
                        </div>
                    </div>
                </div>

                <!-- Dents / Scratches -->
                <?php if ($a['check_dents'] || true): ?>
                <div class="mb-3">
                    <h6 class="fw-bold mb-2 small text-uppercase text-muted" style="letter-spacing:.5px">
                        <i class="fa fa-car-burst me-2"></i>Dents / Scratches
                    </h6>
                    <div class="p-3 bg-light rounded small border">
                        <?= $a['check_dents'] ? nl2br(e($a['check_dents'])) : '<span class="text-muted fst-italic">None noted.</span>' ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Items Left in Car -->
                <div class="mb-4">
                    <h6 class="fw-bold mb-2 small text-uppercase text-muted" style="letter-spacing:.5px">
                        <i class="fa fa-box-open me-2"></i>Items Left in Car
                    </h6>
                    <div class="p-3 bg-light rounded small border">
                        <?= $a['check_items_left'] ? nl2br(e($a['check_items_left'])) : '<span class="text-muted fst-italic">None recorded.</span>' ?>
                    </div>
                </div>

                <hr class="my-3">

                <!-- Observations -->
                <div class="mb-3">
                    <h6 class="fw-bold mb-2"><i class="fa fa-note-sticky me-2 text-warning"></i>Observations</h6>
                    <div class="p-3 bg-light rounded small border">
                        <?= $a['observations'] ? nl2br(e($a['observations'])) : '<span class="text-muted fst-italic">No specific observations noted.</span>' ?>
                    </div>
                </div>

                <!-- Recommendations -->
                <div>
                    <h6 class="fw-bold mb-2"><i class="fa fa-wrench me-2 text-primary"></i>Recommended Services</h6>
                    <div class="p-3 bg-light rounded small border">
                        <?= $a['recommended_services'] ? nl2br(e($a['recommended_services'])) : '<span class="text-muted fst-italic">No specific recommendations.</span>' ?>
                    </div>
                </div>

            </div>
        </div>

    </div>

    <!-- ── Right: Vehicle + Client + Meta ─────────────────────────────────── -->
    <div class="col-lg-4">

        <!-- Overall Condition -->
        <div class="card mb-4 border-<?= $cc ?>">
            <div class="card-body d-flex align-items-center gap-3 py-3">
                <div class="bg-<?= $cc ?> bg-opacity-15 text-<?= $cc ?> rounded-circle d-flex align-items-center justify-content-center" style="width:48px;height:48px;flex-shrink:0">
                    <i class="fa <?= $ci ?> fa-lg"></i>
                </div>
                <div>
                    <div class="text-muted" style="font-size:11px;text-transform:uppercase;letter-spacing:.5px">Overall Condition</div>
                    <div class="fw-bold fs-5 text-<?= $cc ?>"><?= $cl ?></div>
                </div>
            </div>
        </div>

        <!-- Vehicle Card -->
        <div class="card mb-4 shadow-sm border-0">
            <div class="card-body">
                <h6 class="card-title fw-bold mb-3"><i class="fa fa-car me-2 text-primary"></i>Vehicle</h6>
                <div class="d-flex align-items-center gap-3 mb-3">
                    <div class="bg-primary bg-opacity-10 text-primary p-3 rounded-circle">
                        <i class="fa fa-car-side fa-lg"></i>
                    </div>
                    <div>
                        <div class="fw-bold fs-5"><?= e(trim($a['car_make'] . ' ' . $a['car_model'])) ?></div>
                        <div class="text-muted small"><?= $a['car_year'] ? e($a['car_year']) : 'Year N/A' ?></div>
                    </div>
                </div>
                <div>
                    <?php if ($a['car_registration']): ?>
                    <div class="d-flex justify-content-between mb-2">
                        <span class="text-muted small">Registration:</span>
                        <span class="badge bg-dark"><?= e($a['car_registration']) ?></span>
                    </div>
                    <?php endif; ?>
                    <?php if ($a['chassis_number']): ?>
                    <div class="d-flex justify-content-between mb-2">
                        <span class="text-muted small">Chassis No:</span>
                        <code class="small"><?= e($a['chassis_number']) ?></code>
                    </div>
                    <?php endif; ?>
                    <?php if ($a['car_id']): ?>
                    <a href="<?= BASE_URL ?>/modules/cars/view.php?id=<?= $a['car_id'] ?>" class="btn btn-xs btn-outline-primary w-100 mt-2">
                        View Vehicle File
                    </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Client Card -->
        <div class="card mb-4 shadow-sm border-0">
            <div class="card-body">
                <h6 class="card-title fw-bold mb-3"><i class="fa fa-user me-2 text-primary"></i>Client</h6>
                <div class="fw-bold mb-1"><?= e($a['client_name'] ?: 'Walk-in Client') ?></div>
                <?php if ($a['client_phone']): ?>
                <div class="text-muted small mb-1"><i class="fa fa-phone me-1"></i><?= e($a['client_phone']) ?></div>
                <?php endif; ?>
                <?php if ($a['client_email']): ?>
                <div class="text-muted small mb-2">
                    <i class="fa fa-envelope me-1"></i>
                    <a href="mailto:<?= e($a['client_email']) ?>" class="text-muted"><?= e($a['client_email']) ?></a>
                </div>
                <?php endif; ?>
                <?php if ($a['booking_number']): ?>
                <div class="border-top pt-2 mt-2">
                    <div class="text-muted small mb-1">Linked Booking:</div>
                    <a href="<?= BASE_URL ?>/modules/service_bookings/view.php?id=<?= $a['service_booking_id'] ?>" class="text-decoration-none fw-medium">
                        <i class="fa fa-calendar-check me-1 text-success"></i><?= e($a['booking_number']) ?>
                    </a>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Metadata -->
        <div class="card bg-light border-0">
            <div class="card-body small">
                <div class="d-flex justify-content-between mb-2">
                    <span class="text-muted">Assessed By:</span>
                    <span class="fw-medium"><?= e($a['assessed_by'] ?: '—') ?></span>
                </div>
                <div class="d-flex justify-content-between mb-2">
                    <span class="text-muted">Created By:</span>
                    <span class="fw-medium"><?= e($a['creator_name'] ?: '—') ?></span>
                </div>
                <div class="d-flex justify-content-between">
                    <span class="text-muted">Recorded:</span>
                    <span class="fw-medium"><?= fmtDate($a['created_at'], 'd M Y, H:i') ?></span>
                </div>
            </div>
        </div>

    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>

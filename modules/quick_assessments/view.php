<?php
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
canAccess('quick_assessments') || die('Access denied.');

$id = (int)($_GET['id'] ?? 0);
$db = getDB();

$stmt = $db->prepare("
    SELECT qa.*, 
           sb.booking_number,
           c.chassis_number,
           u.name as creator_name
    FROM quick_assessments qa
    LEFT JOIN service_bookings sb ON sb.id = qa.service_booking_id
    LEFT JOIN cars c ON c.id = qa.car_id
    LEFT JOIN users u ON u.id = qa.created_by
    WHERE qa.id = ?
");
$stmt->execute([$id]);
$a = $stmt->fetch();

if (!$a) {
    setFlash('danger', "Assessment not found.");
    redirect('index.php');
}

$pageTitle = "QA-{$a['assessment_number']}";
include __DIR__ . '/../../includes/header.php';

$conditionMeta = [
    'good'           => ['success', 'fa-circle-check', 'Good'],
    'fair'           => ['warning', 'fa-circle-minus', 'Fair'],
    'needs_attention'=> ['primary', 'fa-triangle-exclamation', 'Needs Attention'],
    'critical'       => ['danger',  'fa-circle-xmark', 'Critical'],
];
[$cc, $ci, $cl] = $conditionMeta[$a['overall_condition']] ?? ['secondary', 'fa-circle-question', ucfirst($a['overall_condition'])];

$checks = [
    'tyres'     => ['Tyres',            'fa-circle-dot'],
    'lights'    => ['Lights',           'fa-lightbulb'],
    'exterior'  => ['Exterior Body',    'fa-car-side'],
    'engine'    => ['Engine Bay',       'fa-gears'],
    'interior'  => ['Interior',         'fa-couch'],
    'brakes'    => ['Brakes',           'fa-circle-stop'],
    'fluids'    => ['Fluid Levels',     'fa-droplet'],
    'electrical'=> ['Electrical',       'fa-microchip'],
];
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h5 class="mb-0">Quick Assessment: <?= e($a['assessment_number']) ?></h5>
        <div class="text-muted small">Recorded on <?= fmtDate($a['assessment_date']) ?></div>
    </div>
    <div class="d-flex gap-2">
        <a href="index.php" class="btn btn-sm btn-outline-secondary"><i class="fa fa-arrow-left me-1"></i>Back</a>
        <a href="print.php?id=<?= $id ?>" target="_blank" class="btn btn-sm btn-primary"><i class="fa fa-print me-1"></i>Print for Client</a>
        <?php if (canEditDelete()): ?>
        <a href="delete.php?id=<?= $a['id'] ?>" class="btn btn-sm btn-outline-danger confirm-delete"><i class="fa fa-trash"></i></a>
        <?php endif; ?>
    </div>
</div>

<div class="row g-4">
    <!-- Main Info -->
    <div class="col-lg-8">
        <div class="card mb-4">
            <div class="card-header bg-white fw-bold d-flex align-items-center justify-content-between">
                <span><i class="fa fa-list-check me-2 text-primary"></i>Checklist Results</span>
                <span class="badge bg-<?= $cc ?>"><i class="fa <?= $ci ?> me-1"></i><?= $cl ?></span>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <?php foreach ($checks as $key => [$label, $icon]): 
                        $val = $a["check_{$key}"];
                        $valColor = $val === 'ok' ? 'success' : ($val === 'issue' ? 'danger' : 'secondary');
                        $valIcon = $val === 'ok' ? 'check-circle' : ($val === 'issue' ? 'triangle-exclamation' : 'minus');
                        $valText = $val === 'ok' ? 'OK' : ($val === 'issue' ? 'Issue Found' : 'N/A');
                    ?>
                    <div class="col-md-6">
                        <div class="d-flex align-items-center justify-content-between p-2 border rounded bg-light bg-opacity-50">
                            <div class="d-flex align-items-center gap-2">
                                <i class="fa <?= $icon ?> text-muted" style="width:20px;text-align:center"></i>
                                <span class="fw-medium small"><?= $label ?></span>
                            </div>
                            <span class="badge bg-<?= $valColor ?>-subtle text-<?= $valColor ?> border border-<?= $valColor ?>-subtle px-2">
                                <i class="fa fa-<?= $valIcon ?> me-1" style="font-size:10px"></i><?= $valText ?>
                            </span>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>

                <hr class="my-4">

                <div class="mb-4">
                    <h6 class="fw-bold mb-2"><i class="fa fa-note-sticky me-2 text-warning"></i>Observations</h6>
                    <div class="p-3 bg-light rounded small border">
                        <?= $a['observations'] ? nl2br(e($a['observations'])) : '<span class="text-muted italic">No specific observations noted.</span>' ?>
                    </div>
                </div>

                <div>
                    <h6 class="fw-bold mb-2"><i class="fa fa-wrench me-2 text-primary"></i>Recommended Services</h6>
                    <div class="p-3 bg-light rounded small border">
                        <?= $a['recommended_services'] ? nl2br(e($a['recommended_services'])) : '<span class="text-muted italic">No specific recommendations.</span>' ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Sidebar Info -->
    <div class="col-lg-4">
        <!-- Vehicle Card -->
        <div class="card mb-4 shadow-sm border-0">
            <div class="card-body">
                <h6 class="card-title fw-bold mb-3"><i class="fa fa-car me-2 text-primary"></i>Vehicle Info</h6>
                <div class="d-flex align-items-center gap-3 mb-3">
                    <div class="bg-primary bg-opacity-10 text-primary p-3 rounded-circle">
                        <i class="fa fa-car-side fa-lg"></i>
                    </div>
                    <div>
                        <div class="fw-bold fs-5"><?= e($a['car_make'] . ' ' . $a['car_model']) ?></div>
                        <div class="text-muted small"><?= e($a['car_year'] ?? 'Year N/A') ?></div>
                    </div>
                </div>
                <div class="p-2 border-top">
                    <div class="d-flex justify-content-between mb-2">
                        <span class="text-muted small">Registration:</span>
                        <span class="badge bg-dark"><?= e($a['car_registration'] ?: 'N/A') ?></span>
                    </div>
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
                <h6 class="card-title fw-bold mb-3"><i class="fa fa-user me-2 text-primary"></i>Client Info</h6>
                <div class="mb-3">
                    <div class="fw-bold"><?= e($a['client_name'] ?: 'Walk-in Client') ?></div>
                    <?php if ($a['client_phone']): ?>
                    <div class="text-muted small"><i class="fa fa-phone me-1"></i><?= e($a['client_phone']) ?></div>
                    <?php endif; ?>
                </div>
                <?php if ($a['booking_number']): ?>
                <div class="p-2 border-top">
                    <div class="text-muted small mb-1">Linked Booking:</div>
                    <a href="<?= BASE_URL ?>/modules/service_bookings/view.php?id=<?= $a['service_booking_id'] ?>" class="text-decoration-none fw-medium">
                        <i class="fa fa-calendar-check me-2 text-success"></i><?= e($a['booking_number']) ?>
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
                    <span class="fw-medium"><?= e($a['assessed_by'] ?: 'System') ?></span>
                </div>
                <div class="d-flex justify-content-between mb-2">
                    <span class="text-muted">Created By:</span>
                    <span class="fw-medium"><?= e($a['creator_name'] ?: 'Unknown') ?></span>
                </div>
                <div class="d-flex justify-content-between">
                    <span class="text-muted">System Log:</span>
                    <span class="fw-medium"><?= fmtDate($a['created_at'], 'd M Y, H:i') ?></span>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>

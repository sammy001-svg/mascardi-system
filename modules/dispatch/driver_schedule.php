<?php
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
canAccess('dispatch') || die('Access denied.');
$db   = getDB();

// Week range (Mon–Sun)
$weekStart = $_GET['week'] ?? date('Y-m-d', strtotime('monday this week'));
$weekStart = date('Y-m-d', strtotime($weekStart . ' monday this week'));
$weekEnd   = date('Y-m-d', strtotime($weekStart . ' +6 days'));
$prevWeek  = date('Y-m-d', strtotime($weekStart . ' -7 days'));
$nextWeek  = date('Y-m-d', strtotime($weekStart . ' +7 days'));

// Build week days array
$days = [];
for ($i = 0; $i < 7; $i++) {
    $days[] = date('Y-m-d', strtotime($weekStart . " +$i days"));
}

// All active drivers
$drivers = $db->query("SELECT * FROM drivers WHERE status='active' ORDER BY name")->fetchAll();

// All jobs for this week
$jobs = $db->prepare("
    SELECT dj.*, c.make, c.model, c.registration_number,
           fl.name AS from_loc, tl.name AS to_loc
    FROM dispatch_jobs dj
    LEFT JOIN cars c       ON c.id  = dj.car_id
    LEFT JOIN locations fl ON fl.id = dj.from_location_id
    LEFT JOIN locations tl ON tl.id = dj.to_location_id
    WHERE dj.scheduled_date BETWEEN ? AND ?
      AND dj.status != 'cancelled'
    ORDER BY dj.scheduled_time
");
$jobs->execute([$weekStart, $weekEnd]); $jobs = $jobs->fetchAll();

// Index jobs by driver_id + date
$schedule = [];
foreach ($jobs as $j) {
    $key = ($j['driver_id'] ?? 0) . '|' . $j['scheduled_date'];
    $schedule[$key][] = $j;
}
// Unassigned jobs (no driver)
$unassigned = [];
foreach ($jobs as $j) {
    if (!$j['driver_id']) { $unassigned[$j['scheduled_date']][] = $j; }
}

$typeColors = ['client_pickup'=>'info','client_return'=>'success','test_drive'=>'warning','delivery'=>'primary','transfer'=>'secondary','ad_hoc'=>'dark'];
$typeShort  = ['client_pickup'=>'Pickup','client_return'=>'Return','test_drive'=>'Test Drive','delivery'=>'Delivery','transfer'=>'Transfer','ad_hoc'=>'Ad Hoc'];

$pageTitle = 'Driver Schedule';
include __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div>
        <h5 class="mb-1"><i class="fa fa-calendar-week me-2 text-primary"></i>Driver Schedule</h5>
        <div class="text-muted small">Week of <?= date('d M', strtotime($weekStart)) ?> – <?= date('d M Y', strtotime($weekEnd)) ?></div>
    </div>
    <?php if (canWrite('dispatch')): ?>
    <a href="add.php" class="btn btn-primary btn-sm"><i class="fa fa-plus me-1"></i>New Job</a>
    <?php endif; ?>
</div>

<!-- Week navigation -->
<div class="d-flex align-items-center gap-2 mb-4">
    <a href="?week=<?= $prevWeek ?>" class="btn btn-sm btn-outline-secondary"><i class="fa fa-chevron-left"></i></a>
    <span class="fw-semibold"><?= date('d M', strtotime($weekStart)) ?> – <?= date('d M Y', strtotime($weekEnd)) ?></span>
    <a href="?week=<?= $nextWeek ?>" class="btn btn-sm btn-outline-secondary"><i class="fa fa-chevron-right"></i></a>
    <a href="?" class="btn btn-sm btn-outline-primary ms-2">This Week</a>
    <a href="index.php" class="btn btn-sm btn-outline-secondary ms-auto"><i class="fa fa-arrow-left me-1"></i>Board</a>
</div>

<div class="table-responsive">
<table class="table table-bordered" style="font-size:12px;min-width:900px">
    <thead style="background:#1e3a5f;color:#fff">
        <tr>
            <th style="width:130px;background:#1e3a5f" class="ps-3">Driver</th>
            <?php foreach ($days as $day): ?>
            <th class="text-center <?= $day===date('Y-m-d')?'bg-primary':'' ?>">
                <div><?= date('D', strtotime($day)) ?></div>
                <div style="font-size:11px;opacity:.8"><?= date('d M', strtotime($day)) ?></div>
            </th>
            <?php endforeach; ?>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($drivers as $dr): ?>
    <tr>
        <td class="ps-3 align-top fw-semibold" style="background:#f8fafc;vertical-align:top">
            <div><?= e($dr['name']) ?></div>
            <a href="tel:<?= e($dr['phone']) ?>" class="text-muted" style="font-size:10px"><?= e($dr['phone']) ?></a>
        </td>
        <?php foreach ($days as $day): ?>
        <?php $dayJobs = $schedule[$dr['id'] . '|' . $day] ?? []; ?>
        <td class="align-top p-1 <?= $day===date('Y-m-d')?'bg-primary bg-opacity-10':'' ?>" style="vertical-align:top;min-height:60px">
            <?php foreach ($dayJobs as $j): ?>
            <a href="view.php?id=<?= $j['id'] ?>" class="d-block text-decoration-none mb-1">
                <div class="badge bg-<?= $typeColors[$j['job_type']] ?? 'secondary' ?> w-100 text-start text-wrap" style="white-space:normal;font-size:10px;line-height:1.4;padding:3px 6px">
                    <?php if ($j['scheduled_time']): ?><span style="opacity:.8"><?= date('H:i',strtotime($j['scheduled_time'])) ?></span> <?php endif; ?>
                    <?= $typeShort[$j['job_type']] ?? $j['job_type'] ?>
                    <?php if ($j['make']): ?><br><span style="opacity:.8"><?= e($j['make'].' '.$j['model']) ?></span><?php endif; ?>
                </div>
            </a>
            <?php endforeach; ?>
            <?php if (!$dayJobs): ?><span class="text-muted" style="font-size:10px">—</span><?php endif; ?>
        </td>
        <?php endforeach; ?>
    </tr>
    <?php endforeach; ?>

    <!-- Unassigned row -->
    <?php $hasUnassigned = array_filter($unassigned); if ($hasUnassigned): ?>
    <tr style="background:#fff7ed">
        <td class="ps-3 fw-semibold align-top" style="color:#d97706;font-size:12px">
            <i class="fa fa-triangle-exclamation me-1"></i>Unassigned
        </td>
        <?php foreach ($days as $day): ?>
        <td class="align-top p-1">
            <?php foreach ($unassigned[$day] ?? [] as $j): ?>
            <a href="view.php?id=<?= $j['id'] ?>" class="d-block text-decoration-none mb-1">
                <div class="badge bg-warning text-dark w-100 text-start text-wrap" style="white-space:normal;font-size:10px;line-height:1.4;padding:3px 6px">
                    <?= $typeShort[$j['job_type']] ?? $j['job_type'] ?>
                    <?php if ($j['make']): ?><br><span style="opacity:.7"><?= e($j['make'].' '.$j['model']) ?></span><?php endif; ?>
                </div>
            </a>
            <?php endforeach; ?>
        </td>
        <?php endforeach; ?>
    </tr>
    <?php endif; ?>
    </tbody>
</table>
</div>

<div class="d-flex gap-3 mt-2 flex-wrap">
    <?php foreach ($typeColors as $type => $color): ?>
    <span class="badge bg-<?= $color ?>"><?= $typeShort[$type] ?></span>
    <?php endforeach; ?>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>

<?php
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
canAccess('dispatch') || die('Access denied.');
$db   = getDB();
$user = authUser();

// ── Inline migrations ─────────────────────────────────────────────────────────
try { $db->exec("CREATE TABLE IF NOT EXISTS dispatch_jobs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    job_number VARCHAR(20) UNIQUE NOT NULL,
    job_type ENUM('client_pickup','client_return','test_drive','delivery','transfer','ad_hoc') NOT NULL,
    status ENUM('scheduled','assigned','en_route','completed','cancelled') DEFAULT 'scheduled',
    car_id INT NULL, driver_id INT NULL,
    scheduled_date DATE NOT NULL, scheduled_time TIME NULL,
    from_type ENUM('location','address') DEFAULT 'location',
    from_location_id INT NULL, from_address VARCHAR(255) NULL,
    to_type ENUM('location','address') DEFAULT 'location',
    to_location_id INT NULL, to_address VARCHAR(255) NULL,
    client_id INT NULL, service_booking_id INT NULL,
    sale_id INT NULL, showroom_transfer_id INT NULL,
    started_at DATETIME NULL, completed_at DATETIME NULL,
    departure_mileage INT NULL, arrival_mileage INT NULL,
    notes TEXT NULL, raised_by VARCHAR(100) NOT NULL DEFAULT '',
    assigned_by VARCHAR(100) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
)"); } catch (\Throwable $e) {}

// ── Date navigation ────────────────────────────────────────────────────────────
$date     = $_GET['date'] ?? date('Y-m-d');
$isToday  = ($date === date('Y-m-d'));
$prevDay  = date('Y-m-d', strtotime($date . ' -1 day'));
$nextDay  = date('Y-m-d', strtotime($date . ' +1 day'));

// ── Jobs for this date ────────────────────────────────────────────────────────
$jobs = $db->prepare("
    SELECT dj.*,
           c.make, c.model, c.year, c.registration_number,
           dr.name AS driver_name, dr.phone AS driver_phone,
           cl.name AS client_name, cl.phone AS client_phone,
           fl.name AS from_location_name,
           tl.name AS to_location_name
    FROM dispatch_jobs dj
    LEFT JOIN cars c       ON c.id  = dj.car_id
    LEFT JOIN drivers dr   ON dr.id = dj.driver_id
    LEFT JOIN clients cl   ON cl.id = dj.client_id
    LEFT JOIN locations fl ON fl.id = dj.from_location_id
    LEFT JOIN locations tl ON tl.id = dj.to_location_id
    WHERE dj.scheduled_date = ?
    ORDER BY (dj.scheduled_time IS NULL), dj.scheduled_time, dj.id
");
$jobs->execute([$date]); $jobs = $jobs->fetchAll();

// ── Stats ─────────────────────────────────────────────────────────────────────
$total      = count($jobs);
$unassigned = count(array_filter($jobs, fn($j) => $j['status'] === 'scheduled'));
$enRoute    = count(array_filter($jobs, fn($j) => $j['status'] === 'en_route'));
$completed  = count(array_filter($jobs, fn($j) => $j['status'] === 'completed'));

// ── Active drivers with today's load ─────────────────────────────────────────
$drivers = $db->query("SELECT d.* FROM drivers d WHERE d.status='active' ORDER BY d.name")->fetchAll();
$driverLoad = [];
foreach ($jobs as $j) {
    if ($j['driver_id'] && in_array($j['status'], ['assigned','en_route'])) {
        $driverLoad[$j['driver_id']] = ($driverLoad[$j['driver_id']] ?? 0) + 1;
    }
}

// ── Driver checkin status (graceful — team table may not exist yet) ────────────
$driverCheckins = [];
try {
    $tcRows = $db->query("SELECT tc.*, u.linked_id FROM team_checkins tc JOIN users u ON u.id=tc.user_id WHERE u.linked_type='driver'")->fetchAll();
    foreach ($tcRows as $r) { $driverCheckins[$r['linked_id']] = $r; }
} catch (\Throwable $e) {}

// ── Display helpers ───────────────────────────────────────────────────────────
$typeLabels = ['client_pickup'=>'Client Pickup','client_return'=>'Client Return','test_drive'=>'Test Drive','delivery'=>'Delivery','transfer'=>'Transfer','ad_hoc'=>'Ad Hoc'];
$typeColors = ['client_pickup'=>'info','client_return'=>'success','test_drive'=>'warning','delivery'=>'primary','transfer'=>'secondary','ad_hoc'=>'dark'];
$typeIcons  = ['client_pickup'=>'fa-person-walking-arrow-right','client_return'=>'fa-person-walking-arrow-loop-left','test_drive'=>'fa-road','delivery'=>'fa-truck','transfer'=>'fa-right-left','ad_hoc'=>'fa-bolt'];
$stColors   = ['scheduled'=>'warning','assigned'=>'info','en_route'=>'primary','completed'=>'success','cancelled'=>'danger'];
$stIcons    = ['scheduled'=>'fa-clock','assigned'=>'fa-user-check','en_route'=>'fa-truck-moving','completed'=>'fa-circle-check','cancelled'=>'fa-ban'];

$pageTitle = 'Dispatch Board';
include __DIR__ . '/../../includes/header.php';
?>

<!-- Header + date nav -->
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div>
        <h5 class="mb-1"><i class="fa fa-dispatch me-2 text-primary fa-map-location-dot"></i>Dispatch Board</h5>
        <div class="text-muted small">Car &amp; driver movement operations</div>
    </div>
    <?php if (canWrite('dispatch')): ?>
    <a href="add.php" class="btn btn-primary btn-sm"><i class="fa fa-plus me-1"></i>New Job</a>
    <?php endif; ?>
</div>

<!-- Date navigation -->
<div class="d-flex align-items-center gap-2 mb-4 flex-wrap">
    <a href="?date=<?= $prevDay ?>" class="btn btn-sm btn-outline-secondary"><i class="fa fa-chevron-left"></i></a>
    <input type="date" class="form-control form-control-sm" style="width:160px" value="<?= $date ?>"
           onchange="location='?date='+this.value">
    <a href="?date=<?= $nextDay ?>" class="btn btn-sm btn-outline-secondary"><i class="fa fa-chevron-right"></i></a>
    <?php if (!$isToday): ?>
    <a href="?" class="btn btn-sm btn-outline-primary">Today</a>
    <?php endif; ?>
    <span class="text-muted small ms-1"><?= date('l, d F Y', strtotime($date)) ?></span>
</div>

<!-- Stats -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="card text-center py-3">
            <div style="font-size:28px;font-weight:800;color:#1e3a5f"><?= $total ?></div>
            <div class="text-muted small">Total Jobs</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card text-center py-3 <?= $unassigned ? 'border-warning' : '' ?>">
            <div style="font-size:28px;font-weight:800;color:<?= $unassigned ? '#d97706' : '#94a3b8' ?>"><?= $unassigned ?></div>
            <div class="text-muted small">Unassigned</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card text-center py-3 <?= $enRoute ? 'border-primary' : '' ?>">
            <div style="font-size:28px;font-weight:800;color:<?= $enRoute ? '#2563eb' : '#94a3b8' ?>"><?= $enRoute ?></div>
            <div class="text-muted small">En Route</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card text-center py-3">
            <div style="font-size:28px;font-weight:800;color:#16a34a"><?= $completed ?></div>
            <div class="text-muted small">Completed</div>
        </div>
    </div>
</div>

<!-- Driver availability rail -->
<div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="fa fa-id-badge me-2"></i>Driver Availability</span>
        <a href="driver_schedule.php" class="btn btn-xs btn-outline-secondary">Week Schedule</a>
    </div>
    <div class="card-body py-3">
        <?php if ($drivers): ?>
        <div class="d-flex gap-3 flex-wrap">
        <?php foreach ($drivers as $dr):
            $load     = $driverLoad[$dr['id']] ?? 0;
            $tcRow    = $driverCheckins[$dr['id']] ?? null;
            $tcStatus = $tcRow['status'] ?? null;
            $tcLoc    = $tcRow['custom_location'] ?? null;
            $busy     = $load > 0;
            $onLeave  = in_array($tcStatus, ['on_leave','absent']);
            $dotColor = $onLeave ? '#ef4444' : ($busy ? '#f59e0b' : '#22c55e');
            $cardBorder = $onLeave ? '#fee2e2' : ($busy ? '#fef3c7' : '#dcfce7');
        ?>
        <div class="text-center p-3 rounded border" style="min-width:110px;border-color:<?= $cardBorder ?>!important;background:<?= $cardBorder ?>22">
            <div style="position:relative;display:inline-block">
                <div style="width:40px;height:40px;border-radius:50%;background:#1e3a5f;color:#fff;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:15px;margin:0 auto">
                    <?= strtoupper(substr($dr['name'], 0, 1)) ?>
                </div>
                <div style="position:absolute;bottom:0;right:0;width:12px;height:12px;border-radius:50%;background:<?= $dotColor ?>;border:2px solid #fff"></div>
            </div>
            <div class="fw-semibold mt-1" style="font-size:11.5px;line-height:1.3"><?= e($dr['name']) ?></div>
            <?php if ($onLeave): ?>
            <div style="font-size:10px;color:#ef4444"><?= ucfirst(str_replace('_',' ',$tcStatus)) ?></div>
            <?php elseif ($busy): ?>
            <div style="font-size:10px;color:#d97706"><?= $load ?> active job<?= $load > 1 ? 's' : '' ?></div>
            <?php else: ?>
            <div style="font-size:10px;color:#16a34a">Available</div>
            <?php endif; ?>
            <?php if ($tcLoc): ?><div style="font-size:9px;color:#64748b;margin-top:1px"><?= e($tcLoc) ?></div><?php endif; ?>
            <a href="tel:<?= e($dr['phone']) ?>" style="font-size:10px;color:#2563eb;text-decoration:none">
                <i class="fa fa-phone" style="font-size:9px"></i> <?= e($dr['phone']) ?>
            </a>
        </div>
        <?php endforeach; ?>
        </div>
        <?php else: ?>
        <p class="text-muted mb-0 small">No active drivers found. <a href="<?= BASE_URL ?>/modules/drivers/add.php">Add a driver</a>.</p>
        <?php endif; ?>
    </div>
</div>

<!-- Jobs list -->
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="fa fa-list-check me-2"></i>Jobs — <?= date('d M Y', strtotime($date)) ?></span>
        <?php if (canWrite('dispatch')): ?>
        <a href="add.php?date=<?= $date ?>" class="btn btn-xs btn-outline-primary"><i class="fa fa-plus me-1"></i>Add</a>
        <?php endif; ?>
    </div>
    <div class="card-body p-0">
        <?php if (!$jobs): ?>
        <div class="text-center py-5 text-muted">
            <i class="fa fa-calendar-day fa-2x mb-2 d-block opacity-25"></i>
            No dispatch jobs for this date.
            <?php if (canWrite('dispatch')): ?>
            <div class="mt-2"><a href="add.php?date=<?= $date ?>" class="btn btn-sm btn-primary">Schedule a Job</a></div>
            <?php endif; ?>
        </div>
        <?php else: ?>
        <div class="table-responsive">
        <table class="table table-hover mb-0" style="font-size:13px">
            <thead style="background:#f8fafc">
                <tr>
                    <th class="ps-3" style="width:90px">Time</th>
                    <th>Type</th>
                    <th>Vehicle</th>
                    <th>Route</th>
                    <th>Driver</th>
                    <th>Status</th>
                    <th class="pe-3">Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($jobs as $j):
                $fromLabel = $j['from_type'] === 'address' ? $j['from_address'] : ($j['from_location_name'] ?? '—');
                $toLabel   = $j['to_type']   === 'address' ? $j['to_address']   : ($j['to_location_name']   ?? '—');
            ?>
            <tr>
                <td class="ps-3 text-muted small">
                    <?= $j['scheduled_time'] ? date('H:i', strtotime($j['scheduled_time'])) : '<span class="text-muted">TBC</span>' ?>
                </td>
                <td>
                    <span class="badge bg-<?= $typeColors[$j['job_type']] ?? 'secondary' ?>">
                        <i class="fa <?= $typeIcons[$j['job_type']] ?? 'fa-circle' ?> me-1"></i><?= $typeLabels[$j['job_type']] ?? $j['job_type'] ?>
                    </span>
                    <div class="text-muted" style="font-size:10px"><?= e($j['job_number']) ?></div>
                </td>
                <td>
                    <?php if ($j['make']): ?>
                    <div class="fw-semibold"><?= e($j['make'] . ' ' . $j['model']) ?></div>
                    <?php if ($j['registration_number']): ?>
                    <span class="badge bg-dark" style="font-size:10px"><?= e($j['registration_number']) ?></span>
                    <?php endif; ?>
                    <?php elseif ($j['client_name']): ?>
                    <span class="text-muted small"><i class="fa fa-user me-1"></i><?= e($j['client_name']) ?>'s car</span>
                    <?php else: ?>
                    <span class="text-muted small">—</span>
                    <?php endif; ?>
                </td>
                <td style="max-width:200px">
                    <div class="small"><i class="fa fa-location-dot text-danger me-1"></i><?= e($fromLabel) ?></div>
                    <div class="small text-muted"><i class="fa fa-arrow-down me-1" style="font-size:9px"></i></div>
                    <div class="small"><i class="fa fa-location-dot text-success me-1"></i><?= e($toLabel) ?></div>
                </td>
                <td>
                    <?php if ($j['driver_name']): ?>
                    <div class="fw-semibold small"><?= e($j['driver_name']) ?></div>
                    <a href="tel:<?= e($j['driver_phone']) ?>" class="text-muted" style="font-size:11px"><i class="fa fa-phone me-1"></i><?= e($j['driver_phone']) ?></a>
                    <?php else: ?>
                    <span class="badge bg-warning text-dark"><i class="fa fa-user-xmark me-1"></i>Unassigned</span>
                    <?php endif; ?>
                </td>
                <td>
                    <span class="badge bg-<?= $stColors[$j['status']] ?? 'secondary' ?>">
                        <i class="fa <?= $stIcons[$j['status']] ?? 'fa-circle' ?> me-1"></i><?= ucwords(str_replace('_',' ',$j['status'])) ?>
                    </span>
                </td>
                <td class="pe-3">
                    <a href="view.php?id=<?= $j['id'] ?>" class="btn btn-xs btn-outline-primary">
                        <i class="fa fa-arrow-right me-1"></i>Manage
                    </a>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>

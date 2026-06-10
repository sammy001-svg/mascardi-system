<?php
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
canAccess('dispatch') || die('Access denied.');
$db   = getDB();
$user = authUser();
$id   = (int)($_GET['id'] ?? 0);
if (!$id) redirect('index.php');

function loadJob($db, $id) {
    $s = $db->prepare("
        SELECT dj.*,
               c.make, c.model, c.year, c.registration_number, c.chassis_number, c.status AS car_status,
               dr.name AS driver_name, dr.phone AS driver_phone, dr.license_number,
               cl.name AS client_name, cl.phone AS client_phone,
               fl.name AS from_location_name,
               tl.name AS to_location_name
        FROM dispatch_jobs dj
        LEFT JOIN cars c       ON c.id  = dj.car_id
        LEFT JOIN drivers dr   ON dr.id = dj.driver_id
        LEFT JOIN clients cl   ON cl.id = dj.client_id
        LEFT JOIN locations fl ON fl.id = dj.from_location_id
        LEFT JOIN locations tl ON tl.id = dj.to_location_id
        WHERE dj.id = ?
    ");
    $s->execute([$id]); return $s->fetch();
}
$job = loadJob($db, $id);
if (!$job) { setFlash('error','Job not found.'); redirect('index.php'); }

// ── POST: workflow actions ────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && canWrite('dispatch')) {
    $action = $_POST['action'] ?? '';

    if ($action === 'assign') {
        $driverId = (int)($_POST['driver_id'] ?? 0);
        $db->prepare("UPDATE dispatch_jobs SET driver_id=?,status='assigned',assigned_by=?,updated_at=NOW() WHERE id=?")
           ->execute([$driverId ?: null, $user['name'], $id]);
        $newStatus = $driverId ? 'assigned' : 'scheduled';
        $db->prepare("UPDATE dispatch_jobs SET status=? WHERE id=?")->execute([$newStatus, $id]);
        setFlash('success', $driverId ? 'Driver assigned.' : 'Driver removed — job set to Scheduled.');

    } elseif ($action === 'depart') {
        $mileage = (int)($_POST['departure_mileage'] ?? 0);
        $db->prepare("UPDATE dispatch_jobs SET status='en_route',started_at=NOW(),departure_mileage=?,updated_at=NOW() WHERE id=?")
           ->execute([$mileage ?: null, $id]);
        // Update car status to in_transit
        if ($job['car_id']) $db->prepare("UPDATE cars SET status='in_transit' WHERE id=?")->execute([$job['car_id']]);
        // Update driver team_checkin
        if ($job['driver_id']) {
            try {
                $fromLabel = $job['from_type']==='address' ? $job['from_address'] : $job['from_location_name'];
                $db->prepare("INSERT INTO team_checkins (user_id,location_id,custom_location,status,notes,checked_in_at)
                    SELECT u.id, ?, ?, 'in_transit', 'On dispatch job {$job['job_number']}', NOW()
                    FROM users u WHERE u.linked_id=? AND u.linked_type='driver'
                    ON DUPLICATE KEY UPDATE location_id=VALUES(location_id),custom_location=VALUES(custom_location),status=VALUES(status),notes=VALUES(notes),checked_in_at=VALUES(checked_in_at)")
                   ->execute([$job['from_location_id'], $fromLabel, $job['driver_id']]);
            } catch (\Throwable $e) {}
        }
        setFlash('success', 'Job marked en route. Car status updated.');

    } elseif ($action === 'complete') {
        $mileage = (int)($_POST['arrival_mileage'] ?? 0);
        $notes   = trim($_POST['completion_notes'] ?? '');
        $db->prepare("UPDATE dispatch_jobs SET status='completed',completed_at=NOW(),arrival_mileage=?,notes=CONCAT(COALESCE(notes,''),' ',?),updated_at=NOW() WHERE id=?")
           ->execute([$mileage ?: null, $notes ? "\n[Completion] $notes" : '', $id]);
        // Update car location to destination
        if ($job['car_id'] && $job['to_location_id']) {
            $db->prepare("UPDATE cars SET location_id=?,status='arrived',updated_at=NOW() WHERE id=?")->execute([$job['to_location_id'], $job['car_id']]);
        }
        // Update driver team_checkin to destination
        if ($job['driver_id']) {
            try {
                $toLoc   = $job['to_location_id'];
                $toLabel = $job['to_type']==='address' ? $job['to_address'] : $job['to_location_name'];
                $db->prepare("INSERT INTO team_checkins (user_id,location_id,custom_location,status,notes,checked_in_at)
                    SELECT u.id, ?, ?, 'at_location', 'Completed dispatch job {$job['job_number']}', NOW()
                    FROM users u WHERE u.linked_id=? AND u.linked_type='driver'
                    ON DUPLICATE KEY UPDATE location_id=VALUES(location_id),custom_location=VALUES(custom_location),status=VALUES(status),notes=VALUES(notes),checked_in_at=VALUES(checked_in_at)")
                   ->execute([$toLoc, $toLabel, $job['driver_id']]);
            } catch (\Throwable $e) {}
        }
        setFlash('success', 'Job completed. Car location updated.');

    } elseif ($action === 'cancel') {
        $db->prepare("UPDATE dispatch_jobs SET status='cancelled',updated_at=NOW() WHERE id=?")->execute([$id]);
        setFlash('success', 'Job cancelled.');
    }

    logActivity('update','dispatch',$id,"Job {$job['job_number']} action: $action");
    redirect('view.php?id=' . $id);
}

$job = loadJob($db, $id); // reload after potential update

$drivers = $db->query("SELECT * FROM drivers WHERE status='active' ORDER BY name")->fetchAll();

$typeLabels = ['client_pickup'=>'Client Pickup','client_return'=>'Client Return','test_drive'=>'Test Drive','delivery'=>'Delivery','transfer'=>'Transfer','ad_hoc'=>'Ad Hoc'];
$typeColors = ['client_pickup'=>'info','client_return'=>'success','test_drive'=>'warning','delivery'=>'primary','transfer'=>'secondary','ad_hoc'=>'dark'];
$typeIcons  = ['client_pickup'=>'fa-person-walking-arrow-right','client_return'=>'fa-person-walking-arrow-loop-left','test_drive'=>'fa-road','delivery'=>'fa-truck','transfer'=>'fa-right-left','ad_hoc'=>'fa-bolt'];
$stColors   = ['scheduled'=>'warning','assigned'=>'info','en_route'=>'primary','completed'=>'success','cancelled'=>'danger'];

$fromLabel = $job['from_type'] === 'address' ? $job['from_address'] : ($job['from_location_name'] ?? '—');
$toLabel   = $job['to_type']   === 'address' ? $job['to_address']   : ($job['to_location_name']   ?? '—');

$pageTitle = $job['job_number'];
include __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <h5 class="mb-1"><i class="fa fa-map-location-dot me-2 text-primary"></i><?= e($job['job_number']) ?></h5>
        <div class="text-muted small">Raised by <?= e($job['raised_by']) ?> · <?= fmtDate($job['created_at']) ?></div>
    </div>
    <div class="d-flex gap-2">
        <span class="badge bg-<?= $stColors[$job['status']] ?? 'secondary' ?> fs-6 px-3 py-2"><?= ucwords(str_replace('_',' ',$job['status'])) ?></span>
        <a href="print.php?id=<?= $id ?>" target="_blank" class="btn btn-sm btn-outline-secondary"><i class="fa fa-print me-1"></i>Print</a>
        <a href="index.php?date=<?= $job['scheduled_date'] ?>" class="btn btn-sm btn-outline-secondary"><i class="fa fa-arrow-left me-1"></i>Board</a>
        <?php if (canEditDelete()): ?>
        <a href="delete.php?id=<?= $id ?>" class="btn btn-sm btn-danger"
           onclick="return confirm('Delete this dispatch job?')"><i class="fa fa-trash"></i></a>
        <?php endif; ?>
    </div>
</div>

<div class="row g-4">
    <div class="col-md-5">

        <!-- Job summary -->
        <div class="card mb-4" style="border-top:3px solid #2563eb">
            <div class="card-header"><i class="fa <?= $typeIcons[$job['job_type']] ?? 'fa-circle' ?> me-2 text-<?= $typeColors[$job['job_type']] ?? 'secondary' ?>"></i><?= $typeLabels[$job['job_type']] ?? $job['job_type'] ?></div>
            <div class="card-body">
                <dl class="row mb-0" style="font-size:13.5px">
                    <dt class="col-5 text-muted">Job #</dt><dd class="col-7 fw-bold"><?= e($job['job_number']) ?></dd>
                    <dt class="col-5 text-muted">Date</dt><dd class="col-7"><?= fmtDate($job['scheduled_date']) ?><?= $job['scheduled_time'] ? ' at ' . date('H:i', strtotime($job['scheduled_time'])) : '' ?></dd>
                    <dt class="col-5 text-muted">From</dt><dd class="col-7"><i class="fa fa-location-dot text-danger me-1"></i><?= e($fromLabel) ?></dd>
                    <dt class="col-5 text-muted">To</dt><dd class="col-7"><i class="fa fa-location-dot text-success me-1"></i><?= e($toLabel) ?></dd>
                    <?php if ($job['started_at']): ?>
                    <dt class="col-5 text-muted">Departed</dt><dd class="col-7"><?= fmtDate($job['started_at'], 'd M Y H:i') ?><?= $job['departure_mileage'] ? ' — ' . number_format($job['departure_mileage']) . ' km' : '' ?></dd>
                    <?php endif; ?>
                    <?php if ($job['completed_at']): ?>
                    <dt class="col-5 text-muted">Arrived</dt><dd class="col-7"><?= fmtDate($job['completed_at'], 'd M Y H:i') ?><?= $job['arrival_mileage'] ? ' — ' . number_format($job['arrival_mileage']) . ' km' : '' ?></dd>
                    <?php endif; ?>
                    <?php if ($job['notes']): ?>
                    <dt class="col-12 text-muted mt-2">Notes</dt><dd class="col-12 small text-muted"><?= nl2br(e($job['notes'])) ?></dd>
                    <?php endif; ?>
                </dl>
            </div>
        </div>

        <!-- Vehicle -->
        <div class="card mb-4">
            <div class="card-header"><i class="fa fa-car me-2"></i>Vehicle</div>
            <div class="card-body">
                <?php if ($job['make']): ?>
                <div class="fw-semibold mb-1"><?= e($job['make'] . ' ' . $job['model'] . ' ' . $job['year']) ?></div>
                <?php if ($job['registration_number']): ?><span class="badge bg-dark me-1"><?= e($job['registration_number']) ?></span><?php endif; ?>
                <?php if ($job['chassis_number']): ?><code style="font-size:11px"><?= e($job['chassis_number']) ?></code><?php endif; ?>
                <div class="mt-2"><?= statusBadge($job['car_status']) ?></div>
                <a href="<?= BASE_URL ?>/modules/cars/view.php?id=<?= $job['car_id'] ?>" class="btn btn-xs btn-outline-primary mt-2"><i class="fa fa-eye me-1"></i>View Car</a>
                <?php elseif ($job['client_name']): ?>
                <div class="text-muted small"><i class="fa fa-user me-1"></i><?= e($job['client_name']) ?>'s vehicle — not linked to system car</div>
                <?php else: ?>
                <div class="text-muted small">No vehicle specified.</div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Client -->
        <?php if ($job['client_name']): ?>
        <div class="card mb-4">
            <div class="card-header"><i class="fa fa-user me-2"></i>Client</div>
            <div class="card-body" style="font-size:13.5px">
                <div class="fw-semibold"><?= e($job['client_name']) ?></div>
                <?php if ($job['client_phone']): ?>
                <a href="tel:<?= e($job['client_phone']) ?>" class="text-muted small"><i class="fa-brands fa-whatsapp text-success me-1"></i><?= e($job['client_phone']) ?></a>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

    </div>

    <div class="col-md-7">

        <!-- Driver card -->
        <div class="card mb-4">
            <div class="card-header"><i class="fa fa-id-badge me-2"></i>Driver</div>
            <div class="card-body">
                <?php if ($job['driver_name']): ?>
                <div class="d-flex align-items-center gap-3 mb-3">
                    <div style="width:48px;height:48px;border-radius:50%;background:#1e3a5f;color:#fff;display:flex;align-items:center;justify-content:center;font-size:18px;font-weight:700">
                        <?= strtoupper(substr($job['driver_name'],0,1)) ?>
                    </div>
                    <div>
                        <div class="fw-semibold"><?= e($job['driver_name']) ?></div>
                        <a href="tel:<?= e($job['driver_phone']) ?>" class="text-muted small"><i class="fa fa-phone me-1"></i><?= e($job['driver_phone']) ?></a>
                        <?php if ($job['license_number']): ?><div class="text-muted" style="font-size:11px">Lic: <?= e($job['license_number']) ?></div><?php endif; ?>
                    </div>
                </div>
                <?php if ($job['assigned_by']): ?>
                <div class="text-muted small">Assigned by <?= e($job['assigned_by']) ?></div>
                <?php endif; ?>
                <?php else: ?>
                <div class="alert alert-warning py-2 small mb-3"><i class="fa fa-triangle-exclamation me-1"></i>No driver assigned yet.</div>
                <?php endif; ?>

                <?php if (!in_array($job['status'], ['completed','cancelled']) && canWrite('dispatch')): ?>
                <form method="POST" class="d-flex gap-2 align-items-center mt-2">
                    <input type="hidden" name="action" value="assign">
                    <select name="driver_id" class="form-select form-select-sm select2" style="flex:1">
                        <option value="">— Remove / clear driver —</option>
                        <?php foreach ($drivers as $dr): ?>
                        <option value="<?= $dr['id'] ?>" <?= $job['driver_id'] == $dr['id'] ? 'selected' : '' ?>>
                            <?= e($dr['name']) ?> — <?= e($dr['phone']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <button class="btn btn-sm btn-outline-primary"><i class="fa fa-user-check me-1"></i>Assign</button>
                </form>
                <?php endif; ?>
            </div>
        </div>

        <!-- Workflow actions -->
        <?php if (!in_array($job['status'], ['completed','cancelled']) && canWrite('dispatch')): ?>
        <div class="card mb-4" style="border-top:3px solid #2563eb">
            <div class="card-header fw-semibold"><i class="fa fa-gavel me-2"></i>Progress Job</div>
            <div class="card-body">
                <?php if ($job['status'] === 'assigned'): ?>
                <p class="text-muted small mb-3">Driver is assigned. Mark as departed when the car leaves.</p>
                <form method="POST" class="row g-2">
                    <input type="hidden" name="action" value="depart">
                    <div class="col-6">
                        <label class="form-label small">Departure Mileage (km)</label>
                        <input type="number" name="departure_mileage" class="form-control form-control-sm" placeholder="Odometer reading">
                    </div>
                    <div class="col-6 d-flex align-items-end">
                        <button class="btn btn-primary w-100"><i class="fa fa-truck-moving me-1"></i>Mark En Route</button>
                    </div>
                </form>

                <?php elseif ($job['status'] === 'en_route'): ?>
                <div class="alert alert-primary py-2 small mb-3"><i class="fa fa-truck-moving me-1"></i>Car is currently en route from <?= e($fromLabel) ?> → <?= e($toLabel) ?>.</div>
                <form method="POST" class="row g-2">
                    <input type="hidden" name="action" value="complete">
                    <div class="col-6">
                        <label class="form-label small">Arrival Mileage (km)</label>
                        <input type="number" name="arrival_mileage" class="form-control form-control-sm" placeholder="Odometer reading">
                    </div>
                    <div class="col-6">
                        <label class="form-label small">Condition / Notes on Arrival</label>
                        <input type="text" name="completion_notes" class="form-control form-control-sm" placeholder="Any issues noted on arrival?">
                    </div>
                    <div class="col-12">
                        <button class="btn btn-success"><i class="fa fa-circle-check me-1"></i>Mark Completed &amp; Update Car Location</button>
                    </div>
                </form>

                <?php elseif ($job['status'] === 'scheduled'): ?>
                <p class="text-muted small mb-2">Assign a driver above first, then mark the job en route.</p>
                <?php endif; ?>

                <hr class="my-3">
                <form method="POST" onsubmit="return confirm('Cancel this dispatch job?')">
                    <input type="hidden" name="action" value="cancel">
                    <button class="btn btn-sm btn-outline-danger"><i class="fa fa-ban me-1"></i>Cancel Job</button>
                </form>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($job['status'] === 'completed'): ?>
        <div class="card border-success mb-4">
            <div class="card-body text-center py-3">
                <i class="fa fa-circle-check fa-2x text-success mb-2 d-block"></i>
                <div class="fw-semibold text-success">Job Completed</div>
                <div class="text-muted small"><?= fmtDate($job['completed_at'], 'd M Y H:i') ?></div>
                <?php if ($job['arrival_mileage'] && $job['departure_mileage']): ?>
                <div class="text-muted small mt-1">Distance: <?= number_format($job['arrival_mileage'] - $job['departure_mileage']) ?> km</div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>

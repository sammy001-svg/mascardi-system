<?php
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
canAccess('intake') || die('Access denied.');
$id = (int)($_GET['id']??0); if(!$id) redirect(BASE_URL.'/modules/intake/index.php');
$db = getDB();
$stmt = $db->prepare("SELECT ci.*, c.chassis_number, c.make, c.model, c.year, c.color, c.status AS car_status FROM car_intake ci JOIN cars c ON c.id=ci.car_id WHERE ci.id=?");
$stmt->execute([$id]); $intake = $stmt->fetch();
if(!$intake){setFlash('error','Not found.');redirect(BASE_URL.'/modules/intake/index.php');}

$transfers = $db->prepare("SELECT ct.*, d.name AS driver_name, d.phone AS driver_phone, d.id_number AS driver_id_num, d.license_number, d.license_class FROM car_transfers ct LEFT JOIN drivers d ON d.id=ct.driver_id WHERE ct.car_id=? ORDER BY ct.id DESC");
$transfers->execute([$intake['car_id']]); $transfers = $transfers->fetchAll();

$pageTitle = 'Intake Record';
include __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="mb-0">Intake: <?= e($intake['make'].' '.$intake['model'].' ('.$intake['chassis_number'].')') ?></h5>
    <div class="d-flex gap-2">
        <a href="<?= BASE_URL ?>/modules/cars/view.php?id=<?= $intake['car_id'] ?>" class="btn btn-sm btn-outline-primary"><i class="fa fa-car me-1"></i>View Car</a>
        <a href="index.php" class="btn btn-sm btn-outline-secondary"><i class="fa fa-arrow-left me-1"></i>Back</a>
    </div>
</div>

<div class="row g-4">
    <div class="col-lg-5">
        <div class="card mb-3">
            <div class="card-header"><i class="fa fa-anchor me-2"></i>Port Arrival</div>
            <div class="card-body">
                <dl class="row mb-0" style="font-size:13.5px">
                    <dt class="col-5 text-muted">Intake Date</dt><dd class="col-7"><?= fmtDate($intake['intake_date']) ?></dd>
                    <dt class="col-5 text-muted">Port</dt><dd class="col-7"><?= e($intake['port']) ?></dd>
                    <dt class="col-5 text-muted">Shipping Line</dt><dd class="col-7"><?= e($intake['shipping_line']??'—') ?></dd>
                    <dt class="col-5 text-muted">Bill of Lading</dt><dd class="col-7"><?= e($intake['bill_of_lading']??'—') ?></dd>
                    <dt class="col-5 text-muted">Container No.</dt><dd class="col-7"><?= e($intake['container_number']??'—') ?></dd>
                    <dt class="col-5 text-muted">Clearing Agent</dt><dd class="col-7"><?= e($intake['clearing_agent']??'—') ?></dd>
                    <dt class="col-5 text-muted">Arrival Condition</dt><dd class="col-7"><?= $intake['condition_on_arrival'] ? statusBadge($intake['condition_on_arrival']) : '—' ?></dd>
                </dl>
                <?php if($intake['condition_notes']): ?><hr><p class="small"><?= e($intake['condition_notes']) ?></p><?php endif; ?>
            </div>
        </div>

        <div class="card">
            <div class="card-header"><i class="fa fa-car me-2"></i>Vehicle</div>
            <div class="card-body">
                <dl class="row mb-0" style="font-size:13.5px">
                    <dt class="col-5 text-muted">Chassis</dt><dd class="col-7"><code><?= e($intake['chassis_number']) ?></code></dd>
                    <dt class="col-5 text-muted">Vehicle</dt><dd class="col-7"><?= e($intake['make'].' '.$intake['model']) ?></dd>
                    <dt class="col-5 text-muted">Year</dt><dd class="col-7"><?= e($intake['year']) ?></dd>
                    <dt class="col-5 text-muted">Color</dt><dd class="col-7"><?= e($intake['color']??'—') ?></dd>
                    <dt class="col-5 text-muted">Status</dt><dd class="col-7"><?= statusBadge($intake['car_status']) ?></dd>
                </dl>
            </div>
        </div>
    </div>

    <div class="col-lg-7">
        <?php foreach ($transfers as $t): ?>
        <div class="card mb-3">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="fa fa-truck-moving me-2"></i>Transfer: <?= e($t['from_location']) ?> → <?= e($t['to_location']) ?></span>
                <?= statusBadge($t['status']) ?>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-6">
                        <h6 class="text-muted small fw-bold text-uppercase mb-2">Transporter Details</h6>
                        <?php if ($t['driver_name']): ?>
                        <dl class="row mb-0" style="font-size:13.5px">
                            <dt class="col-5 text-muted">Name</dt><dd class="col-7 fw-semibold"><?= e($t['driver_name']) ?></dd>
                            <dt class="col-5 text-muted">ID Number</dt><dd class="col-7"><?= e($t['driver_id_num']??'—') ?></dd>
                            <dt class="col-5 text-muted">License No.</dt><dd class="col-7"><?= e($t['license_number']??'—') ?></dd>
                            <dt class="col-5 text-muted">License Class</dt><dd class="col-7"><?= e($t['license_class']??'—') ?></dd>
                            <dt class="col-5 text-muted">Phone</dt><dd class="col-7"><?= e($t['driver_phone']??'—') ?></dd>
                        </dl>
                        <?php else: ?>
                        <dl class="row mb-0" style="font-size:13.5px">
                            <dt class="col-5 text-muted">Transporter</dt><dd class="col-7 fw-semibold"><?= e($t['transported_by']??'—') ?></dd>
                            <dt class="col-7 text-muted small mt-2">External Party</dt>
                        </dl>
                        <?php endif; ?>
                    </div>
                    <div class="col-md-6">
                        <h6 class="text-muted small fw-bold text-uppercase mb-2">Trip Details</h6>
                        <dl class="row mb-0" style="font-size:13.5px">
                            <dt class="col-5 text-muted">Departure</dt><dd class="col-7"><?= fmtDate($t['departure_date'],'d M Y H:i') ?></dd>
                            <dt class="col-5 text-muted">Est. Arrival</dt><dd class="col-7"><?= fmtDate($t['estimated_arrival'],'d M Y H:i') ?></dd>
                            <dt class="col-5 text-muted">Actual Arrival</dt><dd class="col-7"><?= $t['arrival_date'] ? fmtDate($t['arrival_date'],'d M Y H:i') : '<span class="text-warning">Pending</span>' ?></dd>
                            <dt class="col-5 text-muted">Dep. Mileage</dt><dd class="col-7"><?= $t['departure_mileage'] ? number_format($t['departure_mileage']).' km' : '—' ?></dd>
                            <dt class="col-5 text-muted">Arr. Mileage</dt><dd class="col-7"><?= $t['arrival_mileage'] ? number_format($t['arrival_mileage']).' km' : '—' ?></dd>
                        </dl>
                    </div>
                </div>
                <?php if ($t['status'] === 'in_transit'): ?>
                <div class="mt-3 pt-3 border-top">
                    <form method="POST" action="<?= BASE_URL ?>/modules/intake/confirm_arrival.php" class="row g-2 align-items-end">
                        <input type="hidden" name="transfer_id" value="<?= $t['id'] ?>">
                        <input type="hidden" name="car_id" value="<?= $intake['car_id'] ?>">
                        <div class="col-md-4"><label class="form-label small">Arrival Date/Time</label><input type="datetime-local" name="arrival_date" class="form-control form-control-sm" value="<?= date('Y-m-d\TH:i') ?>" required></div>
                        <div class="col-md-3"><label class="form-label small">Arrival Mileage</label><input type="number" name="arrival_mileage" class="form-control form-control-sm"></div>
                        <div class="col-md-4"><label class="form-label small">Arrival Condition Notes</label><input type="text" name="arrival_condition" class="form-control form-control-sm" placeholder="Any damage, issues..."></div>
                        <div class="col-md-1"><button type="submit" class="btn btn-success btn-sm w-100">Confirm</button></div>
                    </form>
                </div>
                <?php endif; ?>
                <?php if($t['departure_condition']): ?><div class="mt-2 small text-muted">Departure note: <?= e($t['departure_condition']) ?></div><?php endif; ?>
                <?php if($t['arrival_condition']): ?><div class="mt-1 small text-muted">Arrival note: <?= e($t['arrival_condition']) ?></div><?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>

        <?php if (empty($transfers)): ?>
        <div class="card">
            <div class="card-body text-center text-muted py-4">
                <i class="fa fa-truck-moving fa-2x mb-2 d-block"></i>
                No transfer assigned yet.
                <div class="mt-2">
                    <a href="<?= BASE_URL ?>/modules/intake/assign_driver.php?intake_id=<?= $id ?>" class="btn btn-sm btn-primary">Assign Driver</a>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>

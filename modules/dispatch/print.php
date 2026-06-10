<?php
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
canAccess('dispatch') || die('Access denied.');
$id = (int)($_GET['id'] ?? 0); if (!$id) die('Invalid.');
$db = getDB();
$stmt = $db->prepare("SELECT dj.*,c.make,c.model,c.year,c.registration_number,c.chassis_number,dr.name AS driver_name,dr.phone AS driver_phone,dr.license_number,dr.license_class,cl.name AS client_name,cl.phone AS client_phone,fl.name AS from_location_name,tl.name AS to_location_name FROM dispatch_jobs dj LEFT JOIN cars c ON c.id=dj.car_id LEFT JOIN drivers dr ON dr.id=dj.driver_id LEFT JOIN clients cl ON cl.id=dj.client_id LEFT JOIN locations fl ON fl.id=dj.from_location_id LEFT JOIN locations tl ON tl.id=dj.to_location_id WHERE dj.id=?");
$stmt->execute([$id]); $job = $stmt->fetch();
if (!$job) die('Not found.');

$co = ['name'=>getSetting('company_name','Mascardi Car Yard'),'address'=>getSetting('company_address','Nairobi, Kenya'),'phone'=>getSetting('company_phone',''),'logo'=>getSetting('company_logo','')];
$typeLabels = ['client_pickup'=>'Client Pickup','client_return'=>'Client Return','test_drive'=>'Test Drive','delivery'=>'Delivery','transfer'=>'Transfer','ad_hoc'=>'Ad Hoc'];
$fromLabel  = $job['from_type']==='address' ? $job['from_address'] : ($job['from_location_name'] ?? '—');
$toLabel    = $job['to_type']  ==='address' ? $job['to_address']   : ($job['to_location_name']   ?? '—');
?>
<!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"><title>Dispatch Slip — <?= e($job['job_number']) ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
body{background:#f1f5f9;font-size:13px;font-family:'Segoe UI',sans-serif}
.pw{max-width:700px;margin:24px auto;background:#fff;padding:36px;box-shadow:0 2px 10px rgba(0,0,0,.1)}
.section-title{font-weight:700;text-transform:uppercase;font-size:10px;letter-spacing:1.5px;color:#1e3a5f;border-bottom:2px solid #1e3a5f;padding-bottom:3px;margin:16px 0 8px}
.info-row{display:flex;margin-bottom:4px}.info-label{width:140px;flex-shrink:0;color:#64748b}.info-value{flex:1;font-weight:600}
.route-box{background:#f0f9ff;border:2px solid #0891b2;border-radius:8px;padding:14px 18px;margin:10px 0}
.sig-block{border-top:1px solid #334155;padding-top:4px;min-height:56px}
.sig-label{font-size:11px;color:#475569;margin-top:2px}
@media print{body{background:#fff}.no-print{display:none}.pw{box-shadow:none;margin:0;padding:20px}}
</style></head><body>
<div class="no-print text-center py-2">
    <button onclick="window.print()" class="btn btn-primary btn-sm"><i class="fa fa-print me-1"></i>Print</button>
    <a href="view.php?id=<?= $id ?>" class="btn btn-outline-secondary btn-sm ms-2">Back</a>
</div>
<div class="pw">
    <div class="d-flex justify-content-between align-items-start mb-2">
        <div>
            <?php if ($co['logo'] && file_exists(BASE_PATH.'/assets/images/'.$co['logo'])): ?>
            <img src="<?= BASE_URL ?>/assets/images/<?= e($co['logo']) ?>" style="height:40px;object-fit:contain;margin-bottom:4px;display:block">
            <?php else: ?><div style="font-size:20px;font-weight:800;color:#1e3a5f"><?= e($co['name']) ?></div><?php endif; ?>
            <div style="font-size:11px;color:#64748b"><?= e($co['address']) ?><?= $co['phone'] ? ' · '.e($co['phone']) : '' ?></div>
        </div>
        <div class="text-end">
            <div style="font-size:18px;font-weight:800;color:#2563eb;text-transform:uppercase">Dispatch Slip</div>
            <div class="fw-bold"><?= e($job['job_number']) ?></div>
            <span class="badge bg-<?= ['client_pickup'=>'info','client_return'=>'success','test_drive'=>'warning','delivery'=>'primary','transfer'=>'secondary','ad_hoc'=>'dark'][$job['job_type']] ?? 'secondary' ?>">
                <?= $typeLabels[$job['job_type']] ?? $job['job_type'] ?>
            </span>
        </div>
    </div>
    <hr>

    <div class="section-title">Route</div>
    <div class="route-box">
        <div class="d-flex align-items-center gap-3">
            <div style="flex:1;text-align:center">
                <div style="font-size:10px;color:#64748b;text-transform:uppercase">FROM</div>
                <div class="fw-bold"><?= e($fromLabel) ?></div>
            </div>
            <div style="font-size:20px;color:#0891b2">&#8594;</div>
            <div style="flex:1;text-align:center">
                <div style="font-size:10px;color:#64748b;text-transform:uppercase">TO</div>
                <div class="fw-bold"><?= e($toLabel) ?></div>
            </div>
        </div>
        <div class="text-center text-muted small mt-2">
            Scheduled: <?= fmtDate($job['scheduled_date'], 'd F Y') ?><?= $job['scheduled_time'] ? ' at '.date('H:i',strtotime($job['scheduled_time'])) : '' ?>
        </div>
    </div>

    <div class="section-title">Vehicle</div>
    <?php if ($job['make']): ?>
    <div class="info-row"><span class="info-label">Vehicle</span><span class="info-value"><?= e($job['make'].' '.$job['model'].' '.$job['year']) ?></span></div>
    <?php if ($job['registration_number']): ?><div class="info-row"><span class="info-label">Registration</span><span class="info-value"><?= e($job['registration_number']) ?></span></div><?php endif; ?>
    <?php if ($job['chassis_number']): ?><div class="info-row"><span class="info-label">Chassis/VIN</span><span class="info-value" style="font-family:monospace"><?= e($job['chassis_number']) ?></span></div><?php endif; ?>
    <?php else: ?><div class="text-muted small">No vehicle specified.</div><?php endif; ?>

    <div class="section-title">Driver</div>
    <?php if ($job['driver_name']): ?>
    <div class="info-row"><span class="info-label">Driver Name</span><span class="info-value"><?= e($job['driver_name']) ?></span></div>
    <div class="info-row"><span class="info-label">Phone</span><span class="info-value"><?= e($job['driver_phone']) ?></span></div>
    <?php if ($job['license_number']): ?><div class="info-row"><span class="info-label">License No.</span><span class="info-value"><?= e($job['license_number']) ?> (<?= e($job['license_class']) ?>)</span></div><?php endif; ?>
    <?php else: ?><div class="text-muted small">Driver not yet assigned.</div><?php endif; ?>

    <?php if ($job['client_name']): ?>
    <div class="section-title">Client</div>
    <div class="info-row"><span class="info-label">Name</span><span class="info-value"><?= e($job['client_name']) ?></span></div>
    <?php if ($job['client_phone']): ?><div class="info-row"><span class="info-label">Phone</span><span class="info-value"><?= e($job['client_phone']) ?></span></div><?php endif; ?>
    <?php endif; ?>

    <div class="section-title">Mileage &amp; Condition</div>
    <div class="info-row"><span class="info-label">Departure Mileage</span><span class="info-value"><?= $job['departure_mileage'] ? number_format($job['departure_mileage']).' km' : '_______________ km' ?></span></div>
    <div class="info-row"><span class="info-label">Arrival Mileage</span><span class="info-value"><?= $job['arrival_mileage'] ? number_format($job['arrival_mileage']).' km' : '_______________ km' ?></span></div>
    <div class="info-row"><span class="info-label">Condition</span><span class="info-value">____________________________________________</span></div>

    <div class="section-title">Notes</div>
    <div style="border:1px solid #e2e8f0;border-radius:4px;padding:8px;min-height:40px;font-size:12px"><?= $job['notes'] ? nl2br(e($job['notes'])) : '&nbsp;' ?></div>

    <div class="row mt-4 g-3">
        <div class="col-4"><div style="margin-bottom:32px">&nbsp;</div><div class="sig-block"><div class="sig-label"><strong>ISSUED BY</strong></div><div class="sig-label">Name: <?= e($job['raised_by']) ?></div><div class="sig-label">Date: ________________</div></div></div>
        <div class="col-4"><div style="margin-bottom:32px">&nbsp;</div><div class="sig-block"><div class="sig-label"><strong>DRIVER ACKNOWLEDGED</strong></div><div class="sig-label">Name: <?= e($job['driver_name'] ?? '________________') ?></div><div class="sig-label">Signature &amp; Date: ________</div></div></div>
        <div class="col-4"><div style="margin-bottom:32px">&nbsp;</div><div class="sig-block"><div class="sig-label"><strong>RECEIVED AT DESTINATION</strong></div><div class="sig-label">Name: ________________</div><div class="sig-label">Signature &amp; Date: ________</div></div></div>
    </div>

    <div style="margin-top:20px;text-align:center;font-size:10px;color:#94a3b8;border-top:1px solid #f1f5f9;padding-top:8px">
        <?= e($co['name']) ?> &mdash; Dispatch Slip &mdash; Ref: <?= e($job['job_number']) ?> &mdash; Generated <?= date('d M Y H:i') ?>
    </div>
</div>
</body></html>

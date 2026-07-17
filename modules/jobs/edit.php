<?php
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
canAccess('jobs') || die('Access denied.');
canWrite('jobs') || die('Permission denied.');
$id=(int)($_GET['id']??0); if(!$id) redirect(BASE_URL.'/modules/jobs/index.php');
$db=getDB(); $stmt=$db->prepare("SELECT * FROM workshop_jobs WHERE id=?"); $stmt->execute([$id]); $job=$stmt->fetch();
if(!$job){setFlash('error','Not found.');redirect(BASE_URL.'/modules/jobs/index.php');}
$mechanics=$db->query("SELECT id,name FROM mechanics WHERE status='active' ORDER BY name")->fetchAll();
$errors=[];
if($_SERVER['REQUEST_METHOD']==='POST'){
    $data=['mechanic_id'=>$_POST['mechanic_id']?:(null),'start_date'=>$_POST['start_date']?:null,'end_date'=>$_POST['end_date']?:null,'status'=>$_POST['status']??'pending','priority'=>$_POST['priority']??'normal','description'=>trim($_POST['description']??''),'notes'=>trim($_POST['notes']??'')];
    $db->prepare("UPDATE workshop_jobs SET mechanic_id=?,start_date=?,end_date=?,status=?,priority=?,description=?,notes=? WHERE id=?")->execute([...array_values($data),$id]);
    if($data['status']==='completed') $db->prepare("UPDATE cars SET status='completed' WHERE id=?")->execute([$job['car_id']]);
    logActivity('update', 'jobs', $id, "Updated job {$job['job_number']} — status: {$data['status']}");
    if (in_array($data['status'], ['completed','in_progress'])) {
        require_once __DIR__ . '/../../includes/notifications.php';
        $statusLabel = $data['status'] === 'completed' ? 'Job Completed' : 'Job In Progress';
        notifyRoles(['admin','general_manager','sales_officer','sales_manager'], 'job',
            "{$statusLabel}: {$job['job_number']}",
            "{$job['make']} {$job['model']} {$job['year']} — " . ucwords(str_replace('_',' ',$data['status'])),
            BASE_URL . '/modules/jobs/view.php?id=' . $id
        );
    }
    // ── SMS / WhatsApp owner notification on job complete ─────────────────────
    if ($data['status'] === 'completed') {
        try {
            $ownerQ = $db->prepare("SELECT buyer_name, buyer_phone, buyer_email FROM car_sales WHERE car_id=? ORDER BY created_at DESC LIMIT 1");
            $ownerQ->execute([$job['car_id']]);
            $owner = $ownerQ->fetch();
            if ($owner) {
                $co      = getSetting('company_name', 'Mascardi');
                $vehicle = "{$job['make']} {$job['model']} {$job['year']}";
                if (!empty($owner['buyer_phone'])) {
                    if (getSetting('alert_sms_job_complete', '0') === '1') {
                        require_once __DIR__ . '/../../includes/sms.php';
                        sendSms($owner['buyer_phone'],
                            "Hi {$owner['buyer_name']}, your {$vehicle} workshop job {$job['job_number']} is complete. Contact {$co} to arrange collection.",
                            'job', $id);
                    }
                    if (getSetting('alert_whatsapp_job_complete', '0') === '1') {
                        require_once __DIR__ . '/../../includes/whatsapp.php';
                        sendWhatsApp($owner['buyer_phone'],
                            "*Job Complete — {$co}*\n\nHi {$owner['buyer_name']},\n\nYour *{$vehicle}* (Job: {$job['job_number']}) has been completed and is ready for collection.\n\nContact us to arrange pickup.",
                            'job', $id);
                    }
                }
                if (!empty($owner['buyer_email']) && filter_var($owner['buyer_email'], FILTER_VALIDATE_EMAIL)) {
                    if (getSetting('alert_email_job_complete', '0') === '1') {
                        require_once __DIR__ . '/../../includes/mailer.php';
                        $subj = "Workshop Job Complete — {$job['job_number']}";
                        $body = "<p>Dear " . htmlspecialchars($owner['buyer_name']) . ",</p>
                                 <p>Great news — your vehicle is ready for collection. Here are the details:</p>
                                 <table class='data'>
                                   <tr><th>Job No.</th><td><strong>" . htmlspecialchars($job['job_number']) . "</strong></td></tr>
                                   <tr><th>Vehicle</th><td>" . htmlspecialchars($vehicle) . "</td></tr>
                                   <tr><th>Status</th><td><strong style='color:#16a34a'>Completed</strong></td></tr>
                                 </table>
                                 <p>Please contact us to arrange collection at your convenience. Thank you for choosing <strong>" . htmlspecialchars($co) . "</strong>.</p>";
                        sendMail($owner['buyer_email'], $owner['buyer_name'], $subj, mailTemplate($subj, $body), 'job', $id);
                    }
                }
            }
        } catch (\Throwable $_) {}
    }
    setFlash('success','Job updated.'); redirect(BASE_URL.'/modules/jobs/view.php?id='.$id);
}
$pageTitle='Edit Job';
include __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3"><h5 class="mb-0">Edit Job: <?= e($job['job_number']) ?></h5><a href="view.php?id=<?= $id ?>" class="btn btn-sm btn-outline-secondary"><i class="fa fa-arrow-left me-1"></i>Back</a></div>
<div class="card"><div class="card-body"><form method="POST"><div class="row g-3">
    <div class="col-md-4"><label class="form-label">Mechanic</label><select name="mechanic_id" class="form-select select2"><option value="">Select...</option><?php foreach($mechanics as $m): ?><option value="<?= $m['id'] ?>" <?= $job['mechanic_id']==$m['id']?'selected':'' ?>><?= e($m['name']) ?></option><?php endforeach; ?></select></div>
    <div class="col-md-2"><label class="form-label">Priority</label><select name="priority" class="form-select"><?php foreach(['low','normal','high','urgent'] as $p): ?><option value="<?= $p ?>" <?= $job['priority']===$p?'selected':'' ?>><?= ucfirst($p) ?></option><?php endforeach; ?></select></div>
    <div class="col-md-2"><label class="form-label">Status</label><select name="status" class="form-select"><?php foreach(['pending','in_progress','waiting_parts','on_hold','completed','cancelled'] as $s): ?><option value="<?= $s ?>" <?= $job['status']===$s?'selected':'' ?>><?= ucwords(str_replace('_',' ',$s)) ?></option><?php endforeach; ?></select></div>
    <div class="col-md-2"><label class="form-label">Start Date</label><input type="date" name="start_date" class="form-control" value="<?= e($job['start_date']??'') ?>"></div>
    <div class="col-md-2"><label class="form-label">End Date</label><input type="date" name="end_date" class="form-control" value="<?= e($job['end_date']??'') ?>"></div>
    <div class="col-12"><label class="form-label">Description</label><textarea name="description" class="form-control" rows="3"><?= e($job['description']??'') ?></textarea></div>
    <div class="col-12"><label class="form-label">Notes</label><textarea name="notes" class="form-control" rows="2"><?= e($job['notes']??'') ?></textarea></div>
</div><div class="mt-4 d-flex gap-2"><button type="submit" class="btn btn-primary"><i class="fa fa-save me-1"></i>Update</button><a href="view.php?id=<?= $id ?>" class="btn btn-outline-secondary">Cancel</a></div></form></div></div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>

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

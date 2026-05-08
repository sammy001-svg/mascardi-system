<?php
require_once __DIR__ . '/../../includes/functions.php';
$id = (int)($_GET['id']??0); if(!$id) redirect(BASE_URL.'/modules/mechanics/index.php');
$db = getDB(); $stmt=$db->prepare("SELECT * FROM mechanics WHERE id=?"); $stmt->execute([$id]); $m=$stmt->fetch();
if(!$m){setFlash('error','Not found.');redirect(BASE_URL.'/modules/mechanics/index.php');}
$errors=[];
if($_SERVER['REQUEST_METHOD']==='POST'){
    $data=['name'=>trim($_POST['name']??''),'id_number'=>trim($_POST['id_number']??''),'phone'=>trim($_POST['phone']??''),'email'=>trim($_POST['email']??''),'specialization'=>trim($_POST['specialization']??''),'status'=>$_POST['status']??'active'];
    if(!$data['name']) $errors[]='Name required.';
    if(empty($errors)){$db->prepare("UPDATE mechanics SET name=?,id_number=?,phone=?,email=?,specialization=?,status=? WHERE id=?")->execute([...array_values($data),$id]);setFlash('success','Updated.');redirect(BASE_URL.'/modules/mechanics/index.php');}
    $m=array_merge($m,$data);
}
$pageTitle='Edit Mechanic';
include __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3"><h5 class="mb-0">Edit Mechanic: <?= e($m['name']) ?></h5><a href="index.php" class="btn btn-sm btn-outline-secondary"><i class="fa fa-arrow-left me-1"></i>Back</a></div>
<?php if($errors): ?><div class="alert alert-danger"><ul class="mb-0"><?php foreach($errors as $err) echo "<li>".e($err)."</li>"; ?></ul></div><?php endif; ?>
<div class="card"><div class="card-body"><form method="POST"><div class="row g-3">
    <div class="col-md-6"><label class="form-label">Full Name *</label><input type="text" name="name" class="form-control" value="<?= e($m['name']) ?>" required></div>
    <div class="col-md-6"><label class="form-label">Phone</label><input type="text" name="phone" class="form-control" value="<?= e($m['phone']??'') ?>"></div>
    <div class="col-md-4"><label class="form-label">National ID</label><input type="text" name="id_number" class="form-control" value="<?= e($m['id_number']??'') ?>"></div>
    <div class="col-md-4"><label class="form-label">Email</label><input type="email" name="email" class="form-control" value="<?= e($m['email']??'') ?>"></div>
    <div class="col-md-3"><label class="form-label">Specialization</label><input type="text" name="specialization" class="form-control" value="<?= e($m['specialization']??'') ?>"></div>
    <div class="col-md-1"><label class="form-label">Status</label><select name="status" class="form-select"><option value="active" <?= $m['status']==='active'?'selected':'' ?>>Active</option><option value="inactive" <?= $m['status']==='inactive'?'selected':'' ?>>Inactive</option></select></div>
</div><div class="mt-4 d-flex gap-2"><button type="submit" class="btn btn-primary"><i class="fa fa-save me-1"></i>Update</button><a href="index.php" class="btn btn-outline-secondary">Cancel</a></div></form></div></div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>

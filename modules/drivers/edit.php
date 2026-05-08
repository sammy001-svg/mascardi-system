<?php
require_once __DIR__ . '/../../includes/functions.php';
$id = (int)($_GET['id'] ?? 0);
if (!$id) redirect(BASE_URL.'/modules/drivers/index.php');
$db = getDB();
$stmt = $db->prepare("SELECT * FROM drivers WHERE id=?"); $stmt->execute([$id]); $drv = $stmt->fetch();
if (!$drv) { setFlash('error','Driver not found.'); redirect(BASE_URL.'/modules/drivers/index.php'); }
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = ['name'=>trim($_POST['name']??''),'id_number'=>trim($_POST['id_number']??''),'license_number'=>trim($_POST['license_number']??''),'license_class'=>trim($_POST['license_class']??''),'license_expiry'=>$_POST['license_expiry']?:null,'phone'=>trim($_POST['phone']??''),'email'=>trim($_POST['email']??''),'address'=>trim($_POST['address']??''),'status'=>$_POST['status']??'active'];
    if (!$data['name']) $errors[] = 'Name required.';
    if (empty($errors)) {
        $db->prepare("UPDATE drivers SET name=?,id_number=?,license_number=?,license_class=?,license_expiry=?,phone=?,email=?,address=?,status=? WHERE id=?")->execute([...array_values($data),$id]);
        setFlash('success','Driver updated.'); redirect(BASE_URL.'/modules/drivers/index.php');
    }
    $drv = array_merge($drv,$data);
}
$pageTitle = 'Edit Driver';
include __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3"><h5 class="mb-0">Edit Driver: <?= e($drv['name']) ?></h5><a href="index.php" class="btn btn-sm btn-outline-secondary"><i class="fa fa-arrow-left me-1"></i>Back</a></div>
<?php if ($errors): ?><div class="alert alert-danger"><ul class="mb-0"><?php foreach($errors as $err) echo "<li>".e($err)."</li>"; ?></ul></div><?php endif; ?>
<div class="card"><div class="card-body">
    <form method="POST">
        <div class="row g-3">
            <div class="col-md-6"><label class="form-label">Full Name *</label><input type="text" name="name" class="form-control" value="<?= e($drv['name']) ?>" required></div>
            <div class="col-md-6"><label class="form-label">Phone *</label><input type="text" name="phone" class="form-control" value="<?= e($drv['phone']) ?>"></div>
            <div class="col-md-4"><label class="form-label">National ID *</label><input type="text" name="id_number" class="form-control" value="<?= e($drv['id_number']) ?>"></div>
            <div class="col-md-4"><label class="form-label">License Number *</label><input type="text" name="license_number" class="form-control" value="<?= e($drv['license_number']) ?>"></div>
            <div class="col-md-2"><label class="form-label">License Class</label><input type="text" name="license_class" class="form-control" value="<?= e($drv['license_class']??'') ?>"></div>
            <div class="col-md-2"><label class="form-label">Expiry</label><input type="date" name="license_expiry" class="form-control" value="<?= e($drv['license_expiry']??'') ?>"></div>
            <div class="col-md-4"><label class="form-label">Email</label><input type="email" name="email" class="form-control" value="<?= e($drv['email']??'') ?>"></div>
            <div class="col-md-2"><label class="form-label">Status</label><select name="status" class="form-select"><option value="active" <?= $drv['status']==='active'?'selected':'' ?>>Active</option><option value="inactive" <?= $drv['status']==='inactive'?'selected':'' ?>>Inactive</option></select></div>
            <div class="col-12"><label class="form-label">Address</label><textarea name="address" class="form-control" rows="2"><?= e($drv['address']??'') ?></textarea></div>
        </div>
        <div class="mt-4 d-flex gap-2"><button type="submit" class="btn btn-primary"><i class="fa fa-save me-1"></i>Update</button><a href="index.php" class="btn btn-outline-secondary">Cancel</a></div>
    </form>
</div></div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>

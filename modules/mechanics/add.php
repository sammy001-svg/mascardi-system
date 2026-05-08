<?php
require_once __DIR__ . '/../../includes/functions.php';
$pageTitle = 'Add Mechanic';
$db = getDB(); $errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name   = trim($_POST['name']??'');
    $idNum  = trim($_POST['id_number']??'');
    $phone  = trim($_POST['phone']??'');
    $email  = trim($_POST['email']??'');
    $spec   = trim($_POST['specialization']??'');
    if (!$name) $errors[] = 'Name is required.';
    if (empty($errors)) {
        $db->prepare("INSERT INTO mechanics (name,id_number,phone,email,specialization) VALUES (?,?,?,?,?)")->execute([$name,$idNum,$phone,$email,$spec]);
        setFlash('success',"Mechanic {$name} added."); redirect(BASE_URL.'/modules/mechanics/index.php');
    }
}
include __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3"><h5 class="mb-0">Add Mechanic</h5><a href="index.php" class="btn btn-sm btn-outline-secondary"><i class="fa fa-arrow-left me-1"></i>Back</a></div>
<?php if ($errors): ?><div class="alert alert-danger"><ul class="mb-0"><?php foreach($errors as $err) echo "<li>".e($err)."</li>"; ?></ul></div><?php endif; ?>
<div class="card"><div class="card-body">
    <form method="POST">
        <div class="row g-3">
            <div class="col-md-6"><label class="form-label">Full Name *</label><input type="text" name="name" class="form-control" value="<?= e($_POST['name']??'') ?>" required></div>
            <div class="col-md-6"><label class="form-label">Phone</label><input type="text" name="phone" class="form-control" value="<?= e($_POST['phone']??'') ?>"></div>
            <div class="col-md-4"><label class="form-label">National ID</label><input type="text" name="id_number" class="form-control" value="<?= e($_POST['id_number']??'') ?>"></div>
            <div class="col-md-4"><label class="form-label">Email</label><input type="email" name="email" class="form-control" value="<?= e($_POST['email']??'') ?>"></div>
            <div class="col-md-4"><label class="form-label">Specialization</label><input type="text" name="specialization" class="form-control" value="<?= e($_POST['specialization']??'') ?>" placeholder="e.g. Engine, Auto-Electrical"></div>
        </div>
        <div class="mt-4 d-flex gap-2"><button type="submit" class="btn btn-primary"><i class="fa fa-save me-1"></i>Save Mechanic</button><a href="index.php" class="btn btn-outline-secondary">Cancel</a></div>
    </form>
</div></div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>

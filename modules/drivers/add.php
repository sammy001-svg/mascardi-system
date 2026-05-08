<?php
require_once __DIR__ . '/../../includes/functions.php';
$pageTitle = 'Add Driver';
$db = getDB();
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name    = trim($_POST['name'] ?? '');
    $idNum   = trim($_POST['id_number'] ?? '');
    $lic     = trim($_POST['license_number'] ?? '');
    $licCls  = trim($_POST['license_class'] ?? 'BCE');
    $licExp  = $_POST['license_expiry'] ?: null;
    $phone   = trim($_POST['phone'] ?? '');
    $email   = trim($_POST['email'] ?? '');
    $address = trim($_POST['address'] ?? '');

    if (!$name)  $errors[] = 'Name is required.';
    if (!$idNum) $errors[] = 'ID number is required.';
    if (!$lic)   $errors[] = 'License number is required.';
    if (!$phone) $errors[] = 'Phone is required.';

    if (empty($errors)) {
        try {
            $db->prepare("INSERT INTO drivers (name,id_number,license_number,license_class,license_expiry,phone,email,address) VALUES (?,?,?,?,?,?,?,?)")
               ->execute([$name,$idNum,$lic,$licCls,$licExp,$phone,$email,$address]);
            setFlash('success',"Driver {$name} added successfully.");
            redirect(BASE_URL.'/modules/drivers/index.php');
        } catch (PDOException $e) {
            $errors[] = $e->getCode()==='23000' ? 'ID Number or License already exists.' : $e->getMessage();
        }
    }
}
include __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="mb-0">Add Driver</h5>
    <a href="index.php" class="btn btn-sm btn-outline-secondary"><i class="fa fa-arrow-left me-1"></i>Back</a>
</div>
<?php if ($errors): ?><div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $err) echo "<li>".e($err)."</li>"; ?></ul></div><?php endif; ?>
<div class="card">
    <div class="card-body">
        <form method="POST">
            <div class="row g-3">
                <div class="col-md-6"><label class="form-label">Full Name <span class="text-danger">*</span></label><input type="text" name="name" class="form-control" value="<?= e($_POST['name']??'') ?>" required></div>
                <div class="col-md-6"><label class="form-label">Phone <span class="text-danger">*</span></label><input type="text" name="phone" class="form-control" value="<?= e($_POST['phone']??'') ?>" required></div>
                <div class="col-md-4"><label class="form-label">National ID Number <span class="text-danger">*</span></label><input type="text" name="id_number" class="form-control" value="<?= e($_POST['id_number']??'') ?>" required></div>
                <div class="col-md-4"><label class="form-label">License Number <span class="text-danger">*</span></label><input type="text" name="license_number" class="form-control" value="<?= e($_POST['license_number']??'') ?>" required></div>
                <div class="col-md-2"><label class="form-label">License Class</label><input type="text" name="license_class" class="form-control" value="<?= e($_POST['license_class']??'BCE') ?>" placeholder="BCE"></div>
                <div class="col-md-2"><label class="form-label">License Expiry</label><input type="date" name="license_expiry" class="form-control" value="<?= e($_POST['license_expiry']??'') ?>"></div>
                <div class="col-md-6"><label class="form-label">Email</label><input type="email" name="email" class="form-control" value="<?= e($_POST['email']??'') ?>"></div>
                <div class="col-12"><label class="form-label">Address</label><textarea name="address" class="form-control" rows="2"><?= e($_POST['address']??'') ?></textarea></div>
            </div>
            <div class="mt-4 d-flex gap-2">
                <button type="submit" class="btn btn-primary"><i class="fa fa-save me-1"></i>Save Driver</button>
                <a href="index.php" class="btn btn-outline-secondary">Cancel</a>
            </div>
        </form>
    </div>
</div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>

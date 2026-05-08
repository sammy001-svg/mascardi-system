<?php
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
requireRole('admin', 'manager');
$pageTitle = 'Edit Supplier';
$db = getDB();

$id = (int)($_GET['id'] ?? 0);
if (!$id) redirect(BASE_URL . '/modules/suppliers/index.php');

$sup = $db->prepare("SELECT * FROM suppliers WHERE id=?");
$sup->execute([$id]);
$sup = $sup->fetch();
if (!$sup) { setFlash('error', 'Supplier not found.'); redirect(BASE_URL . '/modules/suppliers/index.php'); }

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name    = trim($_POST['name'] ?? '');
    $contact = trim($_POST['contact_person'] ?? '');
    $phone   = trim($_POST['phone'] ?? '');
    $email   = trim($_POST['email'] ?? '');
    $addr    = trim($_POST['address'] ?? '');
    $pin     = trim($_POST['pin_number'] ?? '');
    $terms   = trim($_POST['payment_terms'] ?? 'Cash on Delivery');
    $status  = $_POST['status'] ?? 'active';

    if (!$name) {
        $error = 'Supplier name is required.';
    } else {
        try {
            $db->prepare("UPDATE suppliers SET name=?,contact_person=?,phone=?,email=?,address=?,pin_number=?,payment_terms=?,status=? WHERE id=?")
               ->execute([$name,$contact,$phone,$email,$addr,$pin,$terms,$status,$id]);
            setFlash('success', 'Supplier updated successfully.');
            redirect(BASE_URL . '/modules/suppliers/index.php');
        } catch (PDOException $e) {
            $error = $e->getMessage();
        }
    }
}

$f = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $sup;
include __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h5 class="mb-0">Edit Supplier — <?= e($sup['name']) ?></h5>
    <a href="index.php" class="btn btn-sm btn-outline-secondary"><i class="fa fa-arrow-left me-1"></i>Back</a>
</div>

<?php if ($error): ?>
<div class="alert alert-danger"><i class="fa fa-exclamation-circle me-2"></i><?= e($error) ?></div>
<?php endif; ?>

<div class="card">
    <div class="card-body">
        <form method="POST">
            <div class="row g-3">
                <div class="col-md-8">
                    <label class="form-label">Supplier Name <span class="text-danger">*</span></label>
                    <input type="text" name="name" class="form-control" required value="<?= e($f['name']) ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Status</label>
                    <select name="status" class="form-select">
                        <option value="active"   <?= $f['status']==='active'?'selected':''   ?>>Active</option>
                        <option value="inactive" <?= $f['status']==='inactive'?'selected':'' ?>>Inactive</option>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Contact Person</label>
                    <input type="text" name="contact_person" class="form-control" value="<?= e($f['contact_person'] ?? '') ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Phone</label>
                    <input type="text" name="phone" class="form-control" value="<?= e($f['phone'] ?? '') ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Email</label>
                    <input type="email" name="email" class="form-control" value="<?= e($f['email'] ?? '') ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label">PIN Number</label>
                    <input type="text" name="pin_number" class="form-control" value="<?= e($f['pin_number'] ?? '') ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Payment Terms</label>
                    <select name="payment_terms" class="form-select">
                        <?php foreach (['Cash on Delivery','Net 7','Net 14','Net 30','Net 60','Prepaid','Credit Account'] as $t): ?>
                        <option value="<?= $t ?>" <?= ($f['payment_terms'] ?? '')===$t?'selected':'' ?>><?= $t ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12">
                    <label class="form-label">Address</label>
                    <textarea name="address" class="form-control" rows="2"><?= e($f['address'] ?? '') ?></textarea>
                </div>
            </div>

            <div class="mt-4 d-flex gap-2">
                <button type="submit" class="btn btn-primary"><i class="fa fa-check me-1"></i>Save Changes</button>
                <a href="index.php" class="btn btn-outline-secondary">Cancel</a>
            </div>
        </form>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>

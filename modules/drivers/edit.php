<?php
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
canAccess('drivers') || die('Access denied.');
canWrite('drivers') || die('Permission denied.');
$id = (int)($_GET['id'] ?? 0);
if (!$id) redirect(BASE_URL . '/modules/drivers/index.php');
$db = getDB();
$stmt = $db->prepare("SELECT * FROM drivers WHERE id=?");
$stmt->execute([$id]);
$driver = $stmt->fetch();
if (!$driver) { setFlash('error', 'Driver not found.'); redirect(BASE_URL . '/modules/drivers/index.php'); }

$pageTitle = 'Edit Driver';
$errors = [];
$d = $driver;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $d['name']           = trim($_POST['name'] ?? '');
    $d['id_number']      = trim($_POST['id_number'] ?? '');
    $d['license_number'] = trim($_POST['license_number'] ?? '');
    $d['license_class']  = trim($_POST['license_class'] ?? 'BCE');
    $d['license_expiry'] = trim($_POST['license_expiry'] ?? '') ?: null;
    $d['phone']          = trim($_POST['phone'] ?? '');
    $d['email']          = trim($_POST['email'] ?? '') ?: null;
    $d['address']        = trim($_POST['address'] ?? '') ?: null;
    $d['status']         = $_POST['status'] ?? 'active';

    if (!$d['name'])           $errors[] = 'Full name is required.';
    if (!$d['id_number'])      $errors[] = 'National ID number is required.';
    if (!$d['license_number']) $errors[] = 'License number is required.';
    if (!$d['phone'])          $errors[] = 'Phone number is required.';

    if (empty($errors)) {
        try {
            $db->prepare("UPDATE drivers SET name=?,id_number=?,license_number=?,license_class=?,license_expiry=?,phone=?,email=?,address=?,status=? WHERE id=?")
               ->execute([$d['name'],$d['id_number'],$d['license_number'],$d['license_class'],
                          $d['license_expiry'],$d['phone'],$d['email'],$d['address'],$d['status'],$id]);
            logActivity('update', 'drivers', $id, "Updated driver: {$d['name']}");
            setFlash('success', 'Driver updated.');
            redirect(BASE_URL . '/modules/drivers/view.php?id=' . $id);
        } catch (\PDOException $e) {
            if ($e->getCode() === '23000') {
                $errors[] = 'A driver with that ID number or license number already exists.';
            } else {
                $errors[] = $e->getMessage();
            }
        }
    }
}

include __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <h5 class="mb-0"><i class="fa fa-pen me-2"></i>Edit Driver — <?= e($driver['name']) ?></h5>
    <div class="d-flex gap-2">
        <a href="view.php?id=<?= $id ?>" class="btn btn-sm btn-outline-secondary"><i class="fa fa-eye me-1"></i>View</a>
        <a href="index.php" class="btn btn-sm btn-outline-secondary"><i class="fa fa-arrow-left me-1"></i>Back</a>
    </div>
</div>

<?php if ($errors): ?>
<div class="alert alert-danger">
    <?php foreach ($errors as $err): ?><div><i class="fa fa-circle-exclamation me-1"></i><?= e($err) ?></div><?php endforeach; ?>
</div>
<?php endif; ?>

<form method="POST">
<div class="row g-4">
    <div class="col-lg-8">
        <div class="card mb-4">
            <div class="card-header fw-semibold"><i class="fa fa-user me-2"></i>Personal Information</div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">Full Name <span class="text-danger">*</span></label>
                        <input type="text" name="name" class="form-control" value="<?= e($d['name']) ?>" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Phone <span class="text-danger">*</span></label>
                        <input type="text" name="phone" class="form-control" value="<?= e($d['phone']) ?>" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">National ID No. <span class="text-danger">*</span></label>
                        <input type="text" name="id_number" class="form-control" value="<?= e($d['id_number']) ?>" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Email</label>
                        <input type="email" name="email" class="form-control" value="<?= e($d['email'] ?? '') ?>">
                    </div>
                    <div class="col-12">
                        <label class="form-label">Address</label>
                        <textarea name="address" class="form-control" rows="2"><?= e($d['address'] ?? '') ?></textarea>
                    </div>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header fw-semibold"><i class="fa fa-id-card me-2"></i>License Details</div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-5">
                        <label class="form-label">License Number <span class="text-danger">*</span></label>
                        <input type="text" name="license_number" class="form-control" value="<?= e($d['license_number']) ?>" required>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">License Class</label>
                        <select name="license_class" class="form-select">
                            <?php foreach (['A','B','C','D','E','BCE','ABCE'] as $cls): ?>
                            <option value="<?= $cls ?>" <?= $d['license_class'] === $cls ? 'selected' : '' ?>><?= $cls ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">License Expiry Date</label>
                        <input type="date" name="license_expiry" class="form-control"
                               value="<?= e($d['license_expiry'] ?? '') ?>">
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="card mb-4">
            <div class="card-header fw-semibold">Status</div>
            <div class="card-body">
                <div class="form-check mb-2">
                    <input class="form-check-input" type="radio" name="status" value="active" id="stActive"
                        <?= $d['status'] === 'active' ? 'checked' : '' ?>>
                    <label class="form-check-label" for="stActive"><span class="badge bg-success">Active</span></label>
                </div>
                <div class="form-check">
                    <input class="form-check-input" type="radio" name="status" value="inactive" id="stInactive"
                        <?= $d['status'] === 'inactive' ? 'checked' : '' ?>>
                    <label class="form-check-label" for="stInactive"><span class="badge bg-secondary">Inactive</span></label>
                </div>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-body small text-muted">
                <i class="fa fa-clock me-1"></i>Added <?= fmtDate($driver['created_at']) ?>
            </div>
        </div>

        <div class="d-grid gap-2">
            <button type="submit" class="btn btn-primary py-2">
                <i class="fa fa-save me-2"></i>Save Changes
            </button>
            <a href="view.php?id=<?= $id ?>" class="btn btn-outline-secondary">Cancel</a>
        </div>
    </div>
</div>
</form>
<?php include __DIR__ . '/../../includes/footer.php'; ?>

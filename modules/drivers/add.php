<?php
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
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

    // Login account fields
    $createLogin = !empty($_POST['create_login']);
    $username    = trim($_POST['username'] ?? '');
    $pass        = $_POST['login_password'] ?? '';
    $pass2       = $_POST['login_password_confirm'] ?? '';

    if (!$name)  $errors[] = 'Name is required.';
    if (!$idNum) $errors[] = 'ID number is required.';
    if (!$lic)   $errors[] = 'License number is required.';
    if (!$phone) $errors[] = 'Phone is required.';

    if ($createLogin) {
        if (!$username)          $errors[] = 'Username is required when creating a login.';
        if (!$pass)              $errors[] = 'Password is required when creating a login.';
        elseif (strlen($pass) < 6) $errors[] = 'Password must be at least 6 characters.';
        elseif ($pass !== $pass2)  $errors[] = 'Passwords do not match.';
    }

    if (empty($errors)) {
        try {
            $db->prepare("INSERT INTO drivers (name,id_number,license_number,license_class,license_expiry,phone,email,address) VALUES (?,?,?,?,?,?,?,?)")
               ->execute([$name,$idNum,$lic,$licCls,$licExp,$phone,$email,$address]);
            $driverId = (int)$db->lastInsertId();

            if ($createLogin && $username && $pass) {
                $db->prepare("INSERT INTO users (name,username,email,password,role,linked_id,linked_type) VALUES (?,?,?,?,'driver',?,'driver')")
                   ->execute([$name, $username, $email, password_hash($pass, PASSWORD_DEFAULT), $driverId]);
            }

            setFlash('success', "Driver {$name} added successfully." . ($createLogin ? ' Login account created.' : ''));
            redirect(BASE_URL . '/modules/drivers/index.php');
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                $msg = stripos($e->getMessage(), 'username') !== false
                    ? 'Username already taken by another user.'
                    : 'ID Number or License Number already exists.';
                $errors[] = $msg;
            } else {
                $errors[] = $e->getMessage();
            }
        }
    }
}
include __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="mb-0">Add Driver</h5>
    <a href="index.php" class="btn btn-sm btn-outline-secondary"><i class="fa fa-arrow-left me-1"></i>Back</a>
</div>
<?php if ($errors): ?>
<div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $err) echo '<li>' . e($err) . '</li>'; ?></ul></div>
<?php endif; ?>

<div class="card">
    <div class="card-body">
        <form method="POST">
            <!-- Driver Details -->
            <div class="row g-3">
                <div class="col-md-6"><label class="form-label">Full Name <span class="text-danger">*</span></label><input type="text" name="name" class="form-control" value="<?= e($_POST['name'] ?? '') ?>" required></div>
                <div class="col-md-6"><label class="form-label">Phone <span class="text-danger">*</span></label><input type="text" name="phone" class="form-control" value="<?= e($_POST['phone'] ?? '') ?>" required></div>
                <div class="col-md-4"><label class="form-label">National ID Number <span class="text-danger">*</span></label><input type="text" name="id_number" class="form-control" value="<?= e($_POST['id_number'] ?? '') ?>" required></div>
                <div class="col-md-4"><label class="form-label">License Number <span class="text-danger">*</span></label><input type="text" name="license_number" class="form-control" value="<?= e($_POST['license_number'] ?? '') ?>" required></div>
                <div class="col-md-2"><label class="form-label">License Class</label><input type="text" name="license_class" class="form-control" value="<?= e($_POST['license_class'] ?? 'BCE') ?>" placeholder="BCE"></div>
                <div class="col-md-2"><label class="form-label">License Expiry</label><input type="date" name="license_expiry" class="form-control" value="<?= e($_POST['license_expiry'] ?? '') ?>"></div>
                <div class="col-md-6"><label class="form-label">Email</label><input type="email" name="email" class="form-control" value="<?= e($_POST['email'] ?? '') ?>" id="driverEmail"></div>
                <div class="col-12"><label class="form-label">Address</label><textarea name="address" class="form-control" rows="2"><?= e($_POST['address'] ?? '') ?></textarea></div>
            </div>

            <!-- Login Account Section -->
            <div class="form-section mt-4">
                <div class="d-flex align-items-center justify-content-between mb-3">
                    <div class="form-section-title mb-0">Login Account</div>
                    <div class="form-check form-switch mb-0">
                        <input class="form-check-input" type="checkbox" id="createLogin" name="create_login" value="1"
                            <?= !empty($_POST['create_login']) ? 'checked' : '' ?>>
                        <label class="form-check-label small fw-medium" for="createLogin">Create login account for this driver</label>
                    </div>
                </div>
                <div id="loginFields" style="display:none">
                    <div class="alert alert-info py-2 small mb-3">
                        <i class="fa fa-info-circle me-1"></i>This driver will be able to sign in using these credentials.
                    </div>
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label">Username <span class="text-danger">*</span></label>
                            <input type="text" name="username" class="form-control" id="loginUsername"
                                value="<?= e($_POST['username'] ?? '') ?>" autocomplete="off" placeholder="e.g. jdoe">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Password <span class="text-danger">*</span></label>
                            <input type="password" name="login_password" class="form-control" autocomplete="new-password">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Confirm Password <span class="text-danger">*</span></label>
                            <input type="password" name="login_password_confirm" class="form-control" autocomplete="new-password">
                        </div>
                    </div>
                </div>
            </div>

            <div class="mt-4 d-flex gap-2">
                <button type="submit" class="btn btn-primary"><i class="fa fa-save me-1"></i>Save Driver</button>
                <a href="index.php" class="btn btn-outline-secondary">Cancel</a>
            </div>
        </form>
    </div>
</div>

<?php
$extraJs = <<<'JS'
<script>
(function () {
    var toggle = document.getElementById('createLogin');
    var fields = document.getElementById('loginFields');
    var nameInput = document.querySelector('input[name="name"]');
    var usernameInput = document.getElementById('loginUsername');

    function updateVisibility() {
        if (fields) fields.style.display = toggle && toggle.checked ? '' : 'none';
    }
    if (toggle) { toggle.addEventListener('change', updateVisibility); updateVisibility(); }

    // Auto-suggest username from name
    if (nameInput && usernameInput) {
        nameInput.addEventListener('blur', function () {
            if (usernameInput.value === '' && this.value) {
                usernameInput.value = this.value.toLowerCase().replace(/\s+/g, '.').replace(/[^a-z0-9.]/g, '');
            }
        });
    }
}());
</script>
JS;
include __DIR__ . '/../../includes/footer.php';
?>

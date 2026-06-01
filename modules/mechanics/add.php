<?php
require_once __DIR__ . '/../../includes/functions.php';
requireWrite('mechanics');
$pageTitle = 'Add Mechanic';
$db = getDB();
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name  = trim($_POST['name'] ?? '');
    $idNum = trim($_POST['id_number'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $spec  = trim($_POST['specialization'] ?? '');

    // Login account fields
    $createLogin = !empty($_POST['create_login']);
    $username    = trim($_POST['username'] ?? '');
    $pass        = $_POST['login_password'] ?? '';
    $pass2       = $_POST['login_password_confirm'] ?? '';

    if (!$name) $errors[] = 'Name is required.';

    if ($createLogin) {
        if (!$username)            $errors[] = 'Username is required when creating a login.';
        if (!$pass)                $errors[] = 'Password is required when creating a login.';
        elseif (strlen($pass) < 6) $errors[] = 'Password must be at least 6 characters.';
        elseif ($pass !== $pass2)  $errors[] = 'Passwords do not match.';
    }

    if (empty($errors)) {
        try {
            $db->prepare("INSERT INTO mechanics (name,id_number,phone,email,specialization) VALUES (?,?,?,?,?)")
               ->execute([$name, $idNum, $phone, $email, $spec]);
            $mechanicId = (int)$db->lastInsertId();

            if ($createLogin && $username && $pass) {
                $db->prepare("INSERT INTO users (name,username,email,password,role,linked_id,linked_type) VALUES (?,?,?,?,'mechanic',?,'mechanic')")
                   ->execute([$name, $username, $email, password_hash($pass, PASSWORD_DEFAULT), $mechanicId]);
            }

            logActivity('create', 'mechanics', $mechanicId, "Added mechanic: {$name}");
            setFlash('success', "Mechanic {$name} added." . ($createLogin ? ' Login account created.' : ''));
            redirect(BASE_URL . '/modules/mechanics/index.php');
        } catch (PDOException $e) {
            $errors[] = $e->getCode() === '23000' ? 'Username already taken by another user.' : $e->getMessage();
        }
    }
}
include __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="mb-0">Add Mechanic</h5>
    <a href="index.php" class="btn btn-sm btn-outline-secondary"><i class="fa fa-arrow-left me-1"></i>Back</a>
</div>
<?php if ($errors): ?>
<div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $err) echo '<li>' . e($err) . '</li>'; ?></ul></div>
<?php endif; ?>

<div class="card"><div class="card-body">
    <form method="POST">
        <div class="row g-3">
            <div class="col-md-6"><label class="form-label">Full Name <span class="text-danger">*</span></label><input type="text" name="name" class="form-control" value="<?= e($_POST['name'] ?? '') ?>" required></div>
            <div class="col-md-6"><label class="form-label">Phone</label><input type="text" name="phone" class="form-control" value="<?= e($_POST['phone'] ?? '') ?>"></div>
            <div class="col-md-4"><label class="form-label">National ID</label><input type="text" name="id_number" class="form-control" value="<?= e($_POST['id_number'] ?? '') ?>"></div>
            <div class="col-md-4"><label class="form-label">Email</label><input type="email" name="email" class="form-control" value="<?= e($_POST['email'] ?? '') ?>"></div>
            <div class="col-md-4"><label class="form-label">Specialization</label><input type="text" name="specialization" class="form-control" value="<?= e($_POST['specialization'] ?? '') ?>" placeholder="e.g. Engine, Auto-Electrical"></div>
        </div>

        <!-- Login Account Section -->
        <div class="form-section mt-4">
            <div class="d-flex align-items-center justify-content-between mb-3">
                <div class="form-section-title mb-0">Login Account</div>
                <div class="form-check form-switch mb-0">
                    <input class="form-check-input" type="checkbox" id="createLogin" name="create_login" value="1"
                        <?= !empty($_POST['create_login']) ? 'checked' : '' ?>>
                    <label class="form-check-label small fw-medium" for="createLogin">Create login account for this mechanic</label>
                </div>
            </div>
            <div id="loginFields" style="display:none">
                <div class="alert alert-info py-2 small mb-3">
                    <i class="fa fa-info-circle me-1"></i>This mechanic will be able to sign in and manage their assigned job cards.
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
            <button type="submit" class="btn btn-primary"><i class="fa fa-save me-1"></i>Save Mechanic</button>
            <a href="index.php" class="btn btn-outline-secondary">Cancel</a>
        </div>
    </form>
</div></div>

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

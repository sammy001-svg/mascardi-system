<?php
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
$id = (int)($_GET['id'] ?? 0);
if (!$id) redirect(BASE_URL . '/modules/mechanics/index.php');
$db = getDB();
$stmt = $db->prepare("SELECT * FROM mechanics WHERE id=?"); $stmt->execute([$id]); $m = $stmt->fetch();
if (!$m) { setFlash('error', 'Not found.'); redirect(BASE_URL . '/modules/mechanics/index.php'); }

// Linked user account
$stmt2 = $db->prepare("SELECT * FROM users WHERE linked_type='mechanic' AND linked_id=? LIMIT 1");
$stmt2->execute([$id]); $linkedUser = $stmt2->fetch();

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = [
        'name'           => trim($_POST['name'] ?? ''),
        'id_number'      => trim($_POST['id_number'] ?? ''),
        'phone'          => trim($_POST['phone'] ?? ''),
        'email'          => trim($_POST['email'] ?? ''),
        'specialization' => trim($_POST['specialization'] ?? ''),
        'status'         => $_POST['status'] ?? 'active',
    ];
    if (!$data['name']) $errors[] = 'Name required.';

    $action   = $_POST['account_action'] ?? '';
    $username = trim($_POST['username'] ?? '');
    $pass     = $_POST['login_password'] ?? '';
    $pass2    = $_POST['login_password_confirm'] ?? '';

    if ($action === 'create') {
        if (!$username)            $errors[] = 'Username is required.';
        if (!$pass)                $errors[] = 'Password is required.';
        elseif (strlen($pass) < 6) $errors[] = 'Password must be at least 6 characters.';
        elseif ($pass !== $pass2)  $errors[] = 'Passwords do not match.';
    }
    if ($action === 'update' && $pass !== '' && $pass !== $pass2) {
        $errors[] = 'New passwords do not match.';
    }

    if (empty($errors)) {
        try {
            $db->prepare("UPDATE mechanics SET name=?,id_number=?,phone=?,email=?,specialization=?,status=? WHERE id=?")
               ->execute([...array_values($data), $id]);

            if ($action === 'create' && !$linkedUser) {
                $db->prepare("INSERT INTO users (name,username,email,password,role,linked_id,linked_type) VALUES (?,?,?,?,'mechanic',?,'mechanic')")
                   ->execute([$data['name'], $username, $data['email'], password_hash($pass, PASSWORD_DEFAULT), $id]);
            } elseif ($action === 'update' && $linkedUser) {
                if ($pass !== '') {
                    $db->prepare("UPDATE users SET name=?,email=?,password=? WHERE id=?")
                       ->execute([$data['name'], $data['email'], password_hash($pass, PASSWORD_DEFAULT), $linkedUser['id']]);
                } else {
                    $db->prepare("UPDATE users SET name=?,email=? WHERE id=?")
                       ->execute([$data['name'], $data['email'], $linkedUser['id']]);
                }
            } elseif ($action === 'disable' && $linkedUser) {
                $db->prepare("UPDATE users SET status='inactive' WHERE id=?")->execute([$linkedUser['id']]);
            } elseif ($action === 'enable' && $linkedUser) {
                $db->prepare("UPDATE users SET status='active' WHERE id=?")->execute([$linkedUser['id']]);
            } elseif ($action === 'delete_account' && $linkedUser) {
                $db->prepare("DELETE FROM users WHERE id=?")->execute([$linkedUser['id']]);
            }

            setFlash('success', 'Mechanic updated.');
            redirect(BASE_URL . '/modules/mechanics/index.php');
        } catch (PDOException $e) {
            $errors[] = $e->getCode() === '23000' ? 'Username already taken by another user.' : $e->getMessage();
        }
    }
    $m = array_merge($m, $data);
}

$pageTitle = 'Edit Mechanic';
include __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="mb-0">Edit Mechanic: <?= e($m['name']) ?></h5>
    <a href="index.php" class="btn btn-sm btn-outline-secondary"><i class="fa fa-arrow-left me-1"></i>Back</a>
</div>
<?php if ($errors): ?>
<div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $err) echo '<li>' . e($err) . '</li>'; ?></ul></div>
<?php endif; ?>

<div class="card"><div class="card-body">
    <form method="POST">
        <div class="row g-3">
            <div class="col-md-6"><label class="form-label">Full Name <span class="text-danger">*</span></label><input type="text" name="name" class="form-control" value="<?= e($m['name']) ?>" required></div>
            <div class="col-md-6"><label class="form-label">Phone</label><input type="text" name="phone" class="form-control" value="<?= e($m['phone'] ?? '') ?>"></div>
            <div class="col-md-4"><label class="form-label">National ID</label><input type="text" name="id_number" class="form-control" value="<?= e($m['id_number'] ?? '') ?>"></div>
            <div class="col-md-4"><label class="form-label">Email</label><input type="email" name="email" class="form-control" value="<?= e($m['email'] ?? '') ?>"></div>
            <div class="col-md-3"><label class="form-label">Specialization</label><input type="text" name="specialization" class="form-control" value="<?= e($m['specialization'] ?? '') ?>"></div>
            <div class="col-md-1"><label class="form-label">Status</label>
                <select name="status" class="form-select">
                    <option value="active"   <?= $m['status'] === 'active'   ? 'selected' : '' ?>>Active</option>
                    <option value="inactive" <?= $m['status'] === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                </select>
            </div>
        </div>

        <!-- Login Account Section -->
        <div class="form-section mt-4">
            <div class="form-section-title">Login Account</div>

            <?php if ($linkedUser): ?>
            <div class="d-flex align-items-center gap-3 mb-3 p-3 bg-light rounded-3">
                <div class="user-avatar bg-info text-white"><?= strtoupper(substr($linkedUser['username'], 0, 1)) ?></div>
                <div class="flex-grow-1">
                    <div class="fw-semibold"><?= e($linkedUser['username']) ?></div>
                    <div class="small text-muted">Mechanic account &mdash; <?= statusBadge($linkedUser['status']) ?></div>
                </div>
                <div class="d-flex gap-2">
                    <?php if ($linkedUser['status'] === 'active'): ?>
                    <button type="submit" name="account_action" value="disable" class="btn btn-sm btn-outline-warning">
                        <i class="fa fa-ban me-1"></i>Disable
                    </button>
                    <?php else: ?>
                    <button type="submit" name="account_action" value="enable" class="btn btn-sm btn-outline-success">
                        <i class="fa fa-check me-1"></i>Enable
                    </button>
                    <?php endif; ?>
                    <button type="button" class="btn btn-sm btn-outline-primary" id="toggleChangePass">
                        <i class="fa fa-key me-1"></i>Change Password
                    </button>
                    <button type="submit" name="account_action" value="delete_account" class="btn btn-sm btn-outline-danger confirm-delete">
                        <i class="fa fa-trash me-1"></i>Remove
                    </button>
                </div>
            </div>
            <div id="changePassFields" style="display:none">
                <input type="hidden" name="account_action" value="update">
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label">New Password</label>
                        <input type="password" name="login_password" class="form-control" placeholder="Leave blank to keep current" autocomplete="new-password">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Confirm New Password</label>
                        <input type="password" name="login_password_confirm" class="form-control" autocomplete="new-password">
                    </div>
                </div>
            </div>

            <?php else: ?>
            <div class="alert alert-secondary py-2 small mb-3">
                <i class="fa fa-circle-info me-2"></i>This mechanic does not have a login account yet.
            </div>
            <div class="form-check form-switch mb-3">
                <input class="form-check-input" type="checkbox" id="createLogin" value="1">
                <label class="form-check-label small fw-medium" for="createLogin">Create login account for this mechanic</label>
            </div>
            <div id="loginFields" style="display:none">
                <input type="hidden" name="account_action" value="create">
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label">Username <span class="text-danger">*</span></label>
                        <input type="text" name="username" class="form-control" autocomplete="off" placeholder="e.g. jdoe">
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
            <?php endif; ?>
        </div>

        <div class="mt-4 d-flex gap-2">
            <button type="submit" class="btn btn-primary" <?= $linkedUser ? '' : 'name="account_action" value=""' ?>>
                <i class="fa fa-save me-1"></i>Update Mechanic
            </button>
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
    if (toggle && fields) {
        toggle.addEventListener('change', function () { fields.style.display = this.checked ? '' : 'none'; });
    }
    var togglePass = document.getElementById('toggleChangePass');
    var passFields = document.getElementById('changePassFields');
    if (togglePass && passFields) {
        togglePass.addEventListener('click', function () {
            var show = passFields.style.display === 'none';
            passFields.style.display = show ? '' : 'none';
            togglePass.innerHTML = show
                ? '<i class="fa fa-xmark me-1"></i>Cancel'
                : '<i class="fa fa-key me-1"></i>Change Password';
        });
    }
}());
</script>
JS;
include __DIR__ . '/../../includes/footer.php';
?>

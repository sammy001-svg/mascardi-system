<?php
require_once __DIR__ . '/../../includes/functions.php';
requireRole('admin');
$id = (int)($_GET['id'] ?? 0);
if (!$id) redirect(BASE_URL . '/modules/users/index.php');
$db = getDB();
$stmt = $db->prepare("SELECT * FROM users WHERE id=?"); $stmt->execute([$id]); $user = $stmt->fetch();
if (!$user) { setFlash('error', 'User not found.'); redirect(BASE_URL . '/modules/users/index.php'); }

$pageTitle = 'Edit User';
$errors = [];

$freeMechanics = $db->query("SELECT m.id, m.name FROM mechanics m WHERE m.status='active' AND (NOT EXISTS (SELECT 1 FROM users u WHERE u.linked_type='mechanic' AND u.linked_id=m.id) OR m.id=" . (int)($user['linked_id'] ?? 0) . ") ORDER BY m.name")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name       = trim($_POST['name'] ?? '');
    $username   = trim($_POST['username'] ?? '');
    $email      = trim($_POST['email'] ?? '');
    $role       = $_POST['role'] ?? $user['role'];
    $pass       = $_POST['password'] ?? '';
    $pass2      = $_POST['password_confirm'] ?? '';
    $linkedType = $_POST['linked_type'] ?? '';
    $linkedId   = (int)($_POST['linked_id'] ?? 0);
    $status     = $_POST['status'] ?? 'active';

    if (!$name)     $errors[] = 'Full name is required.';
    if (!$username) $errors[] = 'Username is required.';
    if ($pass !== '' && strlen($pass) < 6) $errors[] = 'Password must be at least 6 characters.';
    if ($pass !== '' && $pass !== $pass2)  $errors[] = 'Passwords do not match.';

    // Prevent admin from downgrading their own role
    if ($id === authUser()['id'] && $role !== 'admin') {
        $errors[] = 'You cannot change your own role.';
    }

    if (empty($errors)) {
        try {
            $lt = ($linkedType && $linkedId) ? $linkedType : null;
            $li = ($linkedType && $linkedId) ? $linkedId   : null;

            if ($pass !== '') {
                $db->prepare("UPDATE users SET name=?,username=?,email=?,password=?,role=?,linked_id=?,linked_type=?,status=? WHERE id=?")
                   ->execute([$name, $username, $email, password_hash($pass, PASSWORD_DEFAULT), $role, $li, $lt, $status, $id]);
            } else {
                $db->prepare("UPDATE users SET name=?,username=?,email=?,role=?,linked_id=?,linked_type=?,status=? WHERE id=?")
                   ->execute([$name, $username, $email, $role, $li, $lt, $status, $id]);
            }
            setFlash('success', 'User updated successfully.');
            redirect(BASE_URL . '/modules/users/index.php');
        } catch (PDOException $e) {
            $errors[] = $e->getCode() === '23000' ? 'Username already taken by another user.' : $e->getMessage();
        }
    }
    $user = array_merge($user, compact('name','username','email','role','linkedType','linkedId','status'));
}

include __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="mb-0">Edit User: <?= e($user['name']) ?></h5>
    <a href="index.php" class="btn btn-sm btn-outline-secondary"><i class="fa fa-arrow-left me-1"></i>Back</a>
</div>
<?php if ($errors): ?>
<div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $err) echo '<li>' . e($err) . '</li>'; ?></ul></div>
<?php endif; ?>

<div class="card">
    <div class="card-body">
        <form method="POST" autocomplete="off">
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">Full Name <span class="text-danger">*</span></label>
                    <input type="text" name="name" class="form-control" value="<?= e($user['name']) ?>" required>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Username <span class="text-danger">*</span></label>
                    <input type="text" name="username" class="form-control" value="<?= e($user['username']) ?>" required>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Status</label>
                    <select name="status" class="form-select" <?= $id === authUser()['id'] ? 'disabled' : '' ?>>
                        <option value="active"   <?= $user['status'] === 'active'   ? 'selected' : '' ?>>Active</option>
                        <option value="inactive" <?= $user['status'] === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                    </select>
                    <?php if ($id === authUser()['id']): ?>
                    <input type="hidden" name="status" value="active">
                    <?php endif; ?>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Email</label>
                    <input type="email" name="email" class="form-control" value="<?= e($user['email'] ?? '') ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Role <span class="text-danger">*</span></label>
                    <select name="role" class="form-select" <?= $id === authUser()['id'] ? 'disabled' : '' ?>>
                        <option value="admin"            <?= $user['role'] === 'admin'            ? 'selected' : '' ?>>Admin — Full system access</option>
                        <option value="workshop_manager" <?= $user['role'] === 'workshop_manager' ? 'selected' : '' ?>>Workshop Manager — Workshop operations</option>
                        <option value="sales_person"     <?= $user['role'] === 'sales_person'     ? 'selected' : '' ?>>Sales Person — Bookings &amp; quick assessments</option>
                        <option value="sales_officer"    <?= $user['role'] === 'sales_officer'    ? 'selected' : '' ?>>Sales Officer — Payments, quotations &amp; invoices</option>
                    </select>
                    <?php if ($id === authUser()['id']): ?>
                    <input type="hidden" name="role" value="admin">
                    <?php endif; ?>
                </div>
            </div>

            <!-- Password change (optional) -->
            <div class="form-section">
                <div class="form-section-title">Change Password <span class="text-muted fw-normal">(leave blank to keep current)</span></div>
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label">New Password</label>
                        <input type="password" name="password" class="form-control" placeholder="Min 6 characters" autocomplete="new-password">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Confirm New Password</label>
                        <input type="password" name="password_confirm" class="form-control" autocomplete="new-password">
                    </div>
                </div>
            </div>

            <!-- Link to mechanic profile -->
            <div class="form-section">
                <div class="form-section-title">Mechanic Profile Link</div>
                <div class="row g-3">
                    <div class="col-md-5">
                        <label class="form-label">Select Mechanic</label>
                        <select name="linked_id" class="form-select">
                            <option value="">— None —</option>
                            <?php foreach ($freeMechanics as $m): ?>
                            <option value="<?= $m['id'] ?>" <?= $user['linked_id'] == $m['id'] ? 'selected' : '' ?>><?= e($m['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <input type="hidden" name="linked_type" value="mechanic">
                    </div>
                </div>
            </div>

            <div class="mt-4 d-flex gap-2">
                <button type="submit" class="btn btn-primary"><i class="fa fa-save me-1"></i>Save Changes</button>
                <a href="index.php" class="btn btn-outline-secondary">Cancel</a>
            </div>
        </form>
    </div>
</div>

<!-- API Token Management Card -->
<div class="card mt-4">
    <div class="card-header d-flex align-items-center gap-2">
        <i class="fa fa-key text-warning"></i>
        <span>REST API Token</span>
        <?php if ($user['api_token'] ?? null): ?>
        <span class="badge bg-success ms-auto">Token Active</span>
        <?php else: ?>
        <span class="badge bg-secondary ms-auto">No Token</span>
        <?php endif; ?>
    </div>
    <div class="card-body">
        <?php $showToken = $_SESSION['show_token_' . $id] ?? null; unset($_SESSION['show_token_' . $id]); ?>
        <?php if ($showToken): ?>
        <div class="alert alert-warning">
            <i class="fa fa-exclamation-triangle me-2"></i>
            <strong>Copy this token now — it will not be shown again:</strong>
            <div class="mt-2 font-monospace bg-dark text-white p-2 rounded" style="word-break:break-all;font-size:13px"><?= e($showToken) ?></div>
        </div>
        <?php endif; ?>
        <p class="text-muted small mb-3">
            API tokens allow this user to authenticate to the REST API at <code><?= BASE_URL ?>/api/v1/</code>.
            Send the token in the <code>Authorization: Bearer &lt;token&gt;</code> header.
        </p>
        <div class="d-flex gap-2">
            <form method="POST" action="generate_token.php">
                <input type="hidden" name="user_id" value="<?= $id ?>">
                <input type="hidden" name="action" value="generate">
                <button type="submit" class="btn btn-warning" onclick="return confirm('Generate a new API token? The old token will be invalidated immediately.')">
                    <i class="fa fa-rotate me-1"></i><?= ($user['api_token'] ?? null) ? 'Regenerate Token' : 'Generate Token' ?>
                </button>
            </form>
            <?php if ($user['api_token'] ?? null): ?>
            <form method="POST" action="generate_token.php">
                <input type="hidden" name="user_id" value="<?= $id ?>">
                <input type="hidden" name="action" value="revoke">
                <button type="submit" class="btn btn-outline-danger" onclick="return confirm('Revoke this API token? API calls using it will stop working immediately.')">
                    <i class="fa fa-ban me-1"></i>Revoke Token
                </button>
            </form>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php
include __DIR__ . '/../../includes/footer.php';
?>

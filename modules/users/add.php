<?php
require_once __DIR__ . '/../../includes/functions.php';
requireRole('admin');
$pageTitle = 'Add User';
$db = getDB();
$errors = [];

$freeMechanics = $db->query("SELECT m.id, m.name FROM mechanics m WHERE m.status='active' AND NOT EXISTS (SELECT 1 FROM users u WHERE u.linked_type='mechanic' AND u.linked_id=m.id) ORDER BY m.name")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name       = trim($_POST['name'] ?? '');
    $username   = trim($_POST['username'] ?? '');
    $email      = trim($_POST['email'] ?? '');
    $role       = $_POST['role'] ?? 'mechanic';
    $pass       = $_POST['password'] ?? '';
    $pass2      = $_POST['password_confirm'] ?? '';
    $linkedType = $_POST['linked_type'] ?? '';
    $linkedId   = (int)($_POST['linked_id'] ?? 0);
    $status     = $_POST['status'] ?? 'active';

    if (!$name)     $errors[] = 'Full name is required.';
    if (!$username) $errors[] = 'Username is required.';
    if (!$pass)     $errors[] = 'Password is required.';
    elseif (strlen($pass) < 6) $errors[] = 'Password must be at least 6 characters.';
    elseif ($pass !== $pass2)  $errors[] = 'Passwords do not match.';
    if (!in_array($role, ['admin','workshop_manager','sales_person','sales_officer'])) $errors[] = 'Invalid role.';

    if (empty($errors)) {
        try {
            $lt = ($linkedType && $linkedId) ? $linkedType : null;
            $li = ($linkedType && $linkedId) ? $linkedId   : null;
            $db->prepare("INSERT INTO users (name,username,email,password,role,linked_id,linked_type,status) VALUES (?,?,?,?,?,?,?,?)")
               ->execute([$name, $username, $email, password_hash($pass, PASSWORD_DEFAULT), $role, $li, $lt, $status]);
            setFlash('success', "User {$name} created successfully.");
            redirect(BASE_URL . '/modules/users/index.php');
        } catch (PDOException $e) {
            $errors[] = $e->getCode() === '23000' ? 'Username already exists.' : $e->getMessage();
        }
    }
}

include __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="mb-0">Add User</h5>
    <a href="index.php" class="btn btn-sm btn-outline-secondary"><i class="fa fa-arrow-left me-1"></i>Back</a>
</div>
<?php if ($errors): ?>
<div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $err) echo '<li>' . e($err) . '</li>'; ?></ul></div>
<?php endif; ?>

<div class="card">
    <div class="card-body">
        <form method="POST">
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">Full Name <span class="text-danger">*</span></label>
                    <input type="text" name="name" class="form-control" value="<?= e($_POST['name'] ?? '') ?>" required>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Username <span class="text-danger">*</span></label>
                    <input type="text" name="username" class="form-control" value="<?= e($_POST['username'] ?? '') ?>" required autocomplete="off">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Status</label>
                    <select name="status" class="form-select">
                        <option value="active" <?= ($_POST['status'] ?? 'active') === 'active' ? 'selected' : '' ?>>Active</option>
                        <option value="inactive" <?= ($_POST['status'] ?? '') === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Email</label>
                    <input type="email" name="email" class="form-control" value="<?= e($_POST['email'] ?? '') ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Role <span class="text-danger">*</span></label>
                    <select name="role" class="form-select">
                        <option value="admin"            <?= ($_POST['role'] ?? '') === 'admin'            ? 'selected' : '' ?>>Admin — Full system access</option>
                        <option value="workshop_manager" <?= ($_POST['role'] ?? 'workshop_manager') === 'workshop_manager' ? 'selected' : '' ?>>Workshop Manager — Workshop operations</option>
                        <option value="sales_person"     <?= ($_POST['role'] ?? '') === 'sales_person'     ? 'selected' : '' ?>>Sales Person — Bookings &amp; quick assessments</option>
                        <option value="sales_officer"    <?= ($_POST['role'] ?? '') === 'sales_officer'    ? 'selected' : '' ?>>Sales Officer — Payments, quotations &amp; invoices</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Password <span class="text-danger">*</span></label>
                    <input type="password" name="password" class="form-control" autocomplete="new-password">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Confirm Password <span class="text-danger">*</span></label>
                    <input type="password" name="password_confirm" class="form-control" autocomplete="new-password">
                </div>
            </div>

            <!-- Workshop Manager: optionally link to mechanic profile -->
            <div class="form-section">
                <div class="form-section-title">Link to Mechanic Profile <span class="text-muted fw-normal">(optional — Workshop Manager only)</span></div>
                <div class="row g-3">
                    <div class="col-md-6">
                        <select name="linked_id" class="form-select">
                            <option value="">— None —</option>
                            <?php foreach ($freeMechanics as $m): ?>
                            <option value="<?= $m['id'] ?>" <?= ($_POST['linked_id'] ?? '') == $m['id'] ? 'selected' : '' ?>><?= e($m['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <input type="hidden" name="linked_type" value="mechanic">
                    </div>
                </div>
            </div>

            <div class="mt-4 d-flex gap-2">
                <button type="submit" class="btn btn-primary"><i class="fa fa-save me-1"></i>Create User</button>
                <a href="index.php" class="btn btn-outline-secondary">Cancel</a>
            </div>
        </form>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php';
?>

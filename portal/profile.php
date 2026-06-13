<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/portal_auth.php';
$pageTitle = 'My Profile';
$db     = getDB();
$client = portalClient();
$cid    = $client['id'];

$errors   = [];
$success  = '';
$action   = $_POST['action'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if ($action === 'update_contact') {
        $name  = trim($_POST['name']  ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $email = trim($_POST['email'] ?? '');

        if (!$name)  $errors[] = 'Name is required.';
        if (!$email) $errors[] = 'Email address is required.';
        if ($email && !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Enter a valid email address.';

        // Ensure email not taken by another client
        if (empty($errors) && $email) {
            $chk = $db->prepare("SELECT id FROM clients WHERE email=? AND id!=? LIMIT 1");
            $chk->execute([$email, $cid]);
            if ($chk->fetch()) $errors[] = 'That email address is already in use by another account.';
        }

        if (empty($errors)) {
            $db->prepare("UPDATE clients SET name=?, phone=?, email=?, updated_at=NOW() WHERE id=?")
               ->execute([$name, $phone, $email, $cid]);
            // Refresh session name
            $_SESSION['portal_client']['name']  = $name;
            $_SESSION['portal_client']['email'] = $email;
            setFlash('success', 'Contact details updated.');
            redirect(BASE_URL . '/portal/profile.php');
        }

    } elseif ($action === 'change_password') {
        $current = $_POST['current_password'] ?? '';
        $new     = $_POST['new_password']     ?? '';
        $confirm = $_POST['confirm_password'] ?? '';

        if (!$current) $errors[] = 'Enter your current password.';
        if (strlen($new) < 6) $errors[] = 'New password must be at least 6 characters.';
        if ($new !== $confirm) $errors[] = 'New password and confirmation do not match.';

        if (empty($errors)) {
            $row = $db->prepare("SELECT portal_password FROM clients WHERE id=?");
            $row->execute([$cid]); $row = $row->fetch();
            if (!$row || !$row['portal_password'] || !password_verify($current, $row['portal_password'])) {
                $errors[] = 'Current password is incorrect.';
            } else {
                $db->prepare("UPDATE clients SET portal_password=?, updated_at=NOW() WHERE id=?")
                   ->execute([password_hash($new, PASSWORD_DEFAULT), $cid]);
                setFlash('success', 'Password changed successfully.');
                redirect(BASE_URL . '/portal/profile.php');
            }
        }
    }
}

// Fresh client row
$row = $db->prepare("SELECT name, email, phone, portal_last_login, created_at FROM clients WHERE id=?");
$row->execute([$cid]); $row = $row->fetch();

include __DIR__ . '/header.php';
?>

<div class="mb-4">
    <h5 class="fw-bold mb-1"><i class="fa fa-user-circle me-2 text-primary"></i>My Profile</h5>
    <div class="text-muted small">Manage your contact details and portal password</div>
</div>

<?php if ($errors): ?>
<div class="alert alert-danger d-flex gap-2 align-items-start mb-4" style="border-radius:10px">
    <i class="fa fa-circle-exclamation mt-1 flex-shrink-0"></i>
    <ul class="mb-0 ps-2"><?php foreach ($errors as $err) echo '<li>'.e($err).'</li>'; ?></ul>
</div>
<?php endif; ?>

<div class="row g-4">

    <!-- Contact Details -->
    <div class="col-lg-6">
        <div class="p-card">
            <div class="p-card-header">
                <span><i class="fa fa-address-card me-2 text-primary"></i>Contact Details</span>
            </div>
            <div class="p-card-body">
                <form method="POST">
                    <input type="hidden" name="action" value="update_contact">
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Full Name <span class="text-danger">*</span></label>
                        <input type="text" name="name" class="form-control"
                               value="<?= e($_POST['name'] ?? $row['name']) ?>" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Email Address <span class="text-danger">*</span></label>
                        <input type="email" name="email" class="form-control"
                               value="<?= e($_POST['email'] ?? $row['email']) ?>" required>
                        <div class="form-text">This is also your login email.</div>
                    </div>
                    <div class="mb-4">
                        <label class="form-label small fw-semibold">Phone Number</label>
                        <input type="tel" name="phone" class="form-control"
                               value="<?= e($_POST['phone'] ?? $row['phone']) ?>">
                    </div>
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="fa fa-save me-2"></i>Save Changes
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- Change Password + Account Info -->
    <div class="col-lg-6">
        <div class="p-card mb-4">
            <div class="p-card-header">
                <span><i class="fa fa-key me-2 text-warning"></i>Change Password</span>
            </div>
            <div class="p-card-body">
                <form method="POST">
                    <input type="hidden" name="action" value="change_password">
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Current Password <span class="text-danger">*</span></label>
                        <input type="password" name="current_password" class="form-control"
                               placeholder="Your current password" required autocomplete="current-password">
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">New Password <span class="text-danger">*</span></label>
                        <input type="password" name="new_password" class="form-control"
                               placeholder="At least 6 characters" required minlength="6" autocomplete="new-password">
                    </div>
                    <div class="mb-4">
                        <label class="form-label small fw-semibold">Confirm New Password <span class="text-danger">*</span></label>
                        <input type="password" name="confirm_password" class="form-control"
                               placeholder="Repeat new password" required minlength="6" autocomplete="new-password">
                    </div>
                    <button type="submit" class="btn btn-warning w-100">
                        <i class="fa fa-lock me-2"></i>Change Password
                    </button>
                </form>
            </div>
        </div>

        <!-- Account info -->
        <div class="p-card">
            <div class="p-card-header">
                <span><i class="fa fa-circle-info me-2 text-secondary"></i>Account Info</span>
            </div>
            <div class="p-card-body">
                <dl class="row g-2 mb-0" style="font-size:13px">
                    <dt class="col-5 text-muted">Client Since</dt>
                    <dd class="col-7"><?= fmtDate($row['created_at'], 'd M Y') ?></dd>
                    <dt class="col-5 text-muted">Last Login</dt>
                    <dd class="col-7"><?= $row['portal_last_login'] ? fmtDate($row['portal_last_login'], 'd M Y H:i') : '—' ?></dd>
                    <dt class="col-5 text-muted">Login Email</dt>
                    <dd class="col-7 small text-break"><?= e($row['email']) ?></dd>
                </dl>
                <hr class="my-3">
                <a href="<?= BASE_URL ?>/portal/logout.php"
                   class="btn btn-outline-danger btn-sm w-100"
                   onclick="return confirm('Sign out of your portal?')">
                    <i class="fa fa-right-from-bracket me-2"></i>Sign Out
                </a>
            </div>
        </div>
    </div>

</div>

<?php include __DIR__ . '/footer.php'; ?>

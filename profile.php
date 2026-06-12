<?php
require_once __DIR__ . '/includes/functions.php';
requireLogin();
$db   = getDB();
$me   = authUser();
$id   = (int)$me['id'];

// Add profile_image column if it doesn't exist (safe to run on every load)
try { $db->exec("ALTER TABLE users ADD COLUMN profile_image VARCHAR(255) NULL DEFAULT NULL"); } catch (\Throwable $_) {}

// Re-fetch full user row (session may not have profile_image yet)
$user = $db->prepare("SELECT * FROM users WHERE id = ?");
$user->execute([$id]);
$user = $user->fetch();

$errors = [];

// ── Handle photo upload ───────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['profile_photo'])) {
    $file = $_FILES['profile_photo'];
    if ($file['error'] === UPLOAD_ERR_NO_FILE) {
        $errors[] = 'No file selected.';
    } else {
        try {
            $uploadDir = __DIR__ . '/uploads/profiles';
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

            // Delete old image if present
            if ($user['profile_image']) {
                $old = $uploadDir . '/' . $user['profile_image'];
                if (file_exists($old)) unlink($old);
            }

            $filename = handleUpload($file, $uploadDir, ['jpg','jpeg','png','webp'], 20971520);
            $db->prepare("UPDATE users SET profile_image = ? WHERE id = ?")->execute([$filename, $id]);
            logActivity('update', 'users', $id, 'Updated profile photo');
            setFlash('success', 'Profile photo updated.');
            redirect(BASE_URL . '/profile.php');
        } catch (Exception $e) {
            $errors[] = $e->getMessage();
        }
    }
}

// ── Handle remove photo ───────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['remove_photo'])) {
    if ($user['profile_image']) {
        $old = __DIR__ . '/uploads/profiles/' . $user['profile_image'];
        if (file_exists($old)) unlink($old);
        $db->prepare("UPDATE users SET profile_image = NULL WHERE id = ?")->execute([$id]);
    }
    setFlash('success', 'Profile photo removed.');
    redirect(BASE_URL . '/profile.php');
}

// ── Handle password change ────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    $current = $_POST['current_password'] ?? '';
    $new     = $_POST['new_password']     ?? '';
    $confirm = $_POST['confirm_password'] ?? '';

    if (!password_verify($current, $user['password'])) {
        $errors[] = 'Current password is incorrect.';
    } elseif (strlen($new) < 6) {
        $errors[] = 'New password must be at least 6 characters.';
    } elseif ($new !== $confirm) {
        $errors[] = 'Passwords do not match.';
    } else {
        $db->prepare("UPDATE users SET password = ? WHERE id = ?")->execute([password_hash($new, PASSWORD_DEFAULT), $id]);
        logActivity('update', 'users', $id, 'Changed own password');
        setFlash('success', 'Password changed successfully.');
        redirect(BASE_URL . '/profile.php');
    }
}

$pageTitle = 'My Profile';
include __DIR__ . '/includes/header.php';

$initials = strtoupper(implode('', array_map(fn($w) => $w[0], explode(' ', trim($user['name'])))));
$initials = substr($initials, 0, 2);
$avatarColors = ['#2563eb','#7c3aed','#db2777','#059669','#d97706','#dc2626','#0891b2'];
$avatarColor  = $avatarColors[crc32($user['name']) % count($avatarColors)];
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <h5 class="mb-1"><i class="fa fa-user-circle me-2 text-primary"></i>My Profile</h5>
        <div class="text-muted small">Update your photo and password</div>
    </div>
</div>

<?php if ($errors): ?>
<div class="alert alert-danger">
    <ul class="mb-0"><?php foreach ($errors as $e) echo '<li>' . e($e) . '</li>'; ?></ul>
</div>
<?php endif; ?>

<div class="row g-4">

    <!-- ── Left: Avatar + info ─────────────────────────────────────────────── -->
    <div class="col-lg-4">

        <!-- Current photo card -->
        <div class="card text-center mb-4">
            <div class="card-body py-4">
                <?php if ($user['profile_image']): ?>
                <img src="<?= BASE_URL ?>/uploads/profiles/<?= e($user['profile_image']) ?>"
                     class="rounded-circle border shadow mb-3"
                     style="width:110px;height:110px;object-fit:cover">
                <?php else: ?>
                <div class="rounded-circle d-inline-flex align-items-center justify-content-center border shadow mb-3"
                     style="width:110px;height:110px;background:<?= $avatarColor ?>;font-size:36px;font-weight:700;color:#fff">
                    <?= e($initials) ?>
                </div>
                <?php endif; ?>

                <div class="fw-bold fs-6 mb-1"><?= e($user['name']) ?></div>
                <div class="text-muted small mb-3"><?= ucwords(str_replace('_', ' ', $user['role'])) ?></div>

                <dl class="row text-start mb-0" style="font-size:13px">
                    <dt class="col-5 text-muted">Username</dt>
                    <dd class="col-7"><code><?= e($user['username']) ?></code></dd>
                    <?php if ($user['email']): ?>
                    <dt class="col-5 text-muted">Email</dt>
                    <dd class="col-7"><?= e($user['email']) ?></dd>
                    <?php endif; ?>
                    <dt class="col-5 text-muted">Status</dt>
                    <dd class="col-7"><?= statusBadge($user['status']) ?></dd>
                </dl>
            </div>
        </div>

        <!-- Upload form -->
        <div class="card mb-3">
            <div class="card-header fw-semibold"><i class="fa fa-camera me-2 text-primary"></i>Change Photo</div>
            <div class="card-body">
                <form method="POST" enctype="multipart/form-data">
                    <div class="mb-3">
                        <input type="file" name="profile_photo" id="profilePhotoInput"
                               class="form-control" accept="image/*" required>
                        <div class="form-text text-muted">Max 20MB. JPG, PNG or WEBP.</div>
                    </div>
                    <!-- Live preview -->
                    <div id="photoPreview" class="text-center mb-3" style="display:none">
                        <img id="previewImg" src="" class="rounded-circle border"
                             style="width:80px;height:80px;object-fit:cover">
                    </div>
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="fa fa-upload me-1"></i>Upload Photo
                    </button>
                </form>
                <?php if ($user['profile_image']): ?>
                <form method="POST" class="mt-2">
                    <button type="submit" name="remove_photo" value="1"
                            class="btn btn-outline-danger w-100 btn-sm"
                            onclick="return confirm('Remove your profile photo?')">
                        <i class="fa fa-trash me-1"></i>Remove Photo
                    </button>
                </form>
                <?php endif; ?>
            </div>
        </div>

    </div>

    <!-- ── Right: Password change ──────────────────────────────────────────── -->
    <div class="col-lg-8">
        <div class="card">
            <div class="card-header fw-semibold"><i class="fa fa-lock me-2 text-primary"></i>Change Password</div>
            <div class="card-body">
                <form method="POST" autocomplete="off">
                    <input type="hidden" name="change_password" value="1">
                    <div class="mb-3" style="max-width:480px">
                        <label class="form-label">Current Password <span class="text-danger">*</span></label>
                        <input type="password" name="current_password" class="form-control"
                               placeholder="Enter your current password" required autocomplete="current-password">
                    </div>
                    <div class="mb-3" style="max-width:480px">
                        <label class="form-label">New Password <span class="text-danger">*</span></label>
                        <input type="password" name="new_password" class="form-control"
                               placeholder="Minimum 6 characters" required autocomplete="new-password">
                    </div>
                    <div class="mb-4" style="max-width:480px">
                        <label class="form-label">Confirm New Password <span class="text-danger">*</span></label>
                        <input type="password" name="confirm_password" class="form-control"
                               placeholder="Repeat new password" required autocomplete="new-password">
                    </div>
                    <button type="submit" class="btn btn-primary">
                        <i class="fa fa-key me-1"></i>Update Password
                    </button>
                </form>
            </div>
        </div>
    </div>

</div>

<script>
document.getElementById('profilePhotoInput').addEventListener('change', function () {
    var file = this.files[0];
    if (!file) return;
    var reader = new FileReader();
    reader.onload = function (e) {
        document.getElementById('previewImg').src = e.target.result;
        document.getElementById('photoPreview').style.display = '';
    };
    reader.readAsDataURL(file);
});
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>

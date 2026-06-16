<?php
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
canAccess('crm') || redirect(BASE_URL . '/index.php');

$db  = getDB();
$me  = authUser();
$id  = (int)$me['id'];

// Ensure profile_image column exists
try { $db->exec("ALTER TABLE users ADD COLUMN profile_image VARCHAR(255) NULL DEFAULT NULL"); } catch (\Throwable $_) {}

// Re-fetch full row (session may lag behind DB)
$stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$id]);
$user = $stmt->fetch();

$errors  = [];
$success = '';

// ── Update profile info ───────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    $name  = trim($_POST['name']  ?? '');
    $email = trim($_POST['email'] ?? '');

    if (!$name)  $errors[] = 'Full name is required.';
    if (!$email) $errors[] = 'Email is required.';
    elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Invalid email address.';

    if (empty($errors)) {
        $db->prepare("UPDATE users SET name = ?, email = ? WHERE id = ?")
           ->execute([$name, $email, $id]);
        // Refresh session name so topbar updates immediately
        $_SESSION['auth_user']['name']  = $name;
        $_SESSION['auth_user']['email'] = $email;
        logActivity('update', 'users', $id, 'Updated profile info');
        setFlash('success', 'Profile updated.');
        redirect(BASE_URL . '/modules/crm/settings.php');
    }
}

// ── Upload profile photo ──────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['profile_photo'])) {
    $file = $_FILES['profile_photo'];
    if ($file['error'] === UPLOAD_ERR_NO_FILE) {
        $errors[] = 'No file selected.';
    } else {
        try {
            $uploadDir = BASE_PATH . '/uploads/profiles';
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

            if ($user['profile_image']) {
                $old = $uploadDir . '/' . $user['profile_image'];
                if (file_exists($old)) unlink($old);
            }

            $filename = handleUpload($file, $uploadDir, ['jpg','jpeg','png','webp'], 20971520);
            $db->prepare("UPDATE users SET profile_image = ? WHERE id = ?")->execute([$filename, $id]);
            logActivity('update', 'users', $id, 'Updated profile photo');
            setFlash('success', 'Profile photo updated.');
            redirect(BASE_URL . '/modules/crm/settings.php');
        } catch (Exception $e) {
            $errors[] = $e->getMessage();
        }
    }
}

// ── Remove photo ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['remove_photo'])) {
    if ($user['profile_image']) {
        $old = BASE_PATH . '/uploads/profiles/' . $user['profile_image'];
        if (file_exists($old)) unlink($old);
        $db->prepare("UPDATE users SET profile_image = NULL WHERE id = ?")->execute([$id]);
    }
    setFlash('success', 'Profile photo removed.');
    redirect(BASE_URL . '/modules/crm/settings.php');
}

// ── Change password ───────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    $current = $_POST['current_password'] ?? '';
    $new     = $_POST['new_password']     ?? '';
    $confirm = $_POST['confirm_password'] ?? '';

    if (!password_verify($current, $user['password'])) {
        $errors[] = 'Current password is incorrect.';
    } elseif (strlen($new) < 6) {
        $errors[] = 'New password must be at least 6 characters.';
    } elseif ($new !== $confirm) {
        $errors[] = 'New passwords do not match.';
    } else {
        $db->prepare("UPDATE users SET password = ? WHERE id = ?")
           ->execute([password_hash($new, PASSWORD_DEFAULT), $id]);
        logActivity('update', 'users', $id, 'Changed own password');
        setFlash('success', 'Password changed successfully.');
        redirect(BASE_URL . '/modules/crm/settings.php');
    }
}

$pageTitle = 'My Settings';

// Avatar
$initials     = strtoupper(substr($user['name'], 0, 1));
$words        = explode(' ', trim($user['name']));
if (count($words) > 1) $initials = strtoupper($words[0][0] . end($words)[0]);
$avatarColors = ['#2563eb','#7c3aed','#db2777','#059669','#d97706','#dc2626','#0891b2'];
$avatarColor  = $avatarColors[crc32($user['name']) % count($avatarColors)];

include __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <h5 class="mb-1"><i class="fa fa-user-gear me-2 text-primary"></i>My Settings</h5>
        <div class="text-muted small">Manage your profile, photo and password</div>
    </div>
    <a href="<?= BASE_URL ?>/modules/crm/my_dashboard.php" class="btn btn-sm btn-outline-secondary">
        <i class="fa fa-arrow-left me-1"></i>Back to Dashboard
    </a>
</div>

<?php if ($errors): ?>
<div class="alert alert-danger alert-dismissible fade show">
    <ul class="mb-0"><?php foreach ($errors as $err) echo '<li>' . e($err) . '</li>'; ?></ul>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<div class="row g-4">

    <!-- ══ LEFT COLUMN: Avatar + Photo ════════════════════════════════════════ -->
    <div class="col-lg-4">

        <!-- Profile card -->
        <div class="card mb-4 text-center" style="border-top:3px solid #2563eb">
            <div class="card-body py-4">
                <?php if ($user['profile_image']): ?>
                <img src="<?= BASE_URL ?>/uploads/profiles/<?= e($user['profile_image']) ?>"
                     class="rounded-circle border shadow mb-3"
                     style="width:110px;height:110px;object-fit:cover">
                <?php else: ?>
                <div class="rounded-circle d-inline-flex align-items-center justify-content-center border shadow mb-3"
                     style="width:110px;height:110px;background:<?= $avatarColor ?>;font-size:38px;font-weight:800;color:#fff">
                    <?= e($initials) ?>
                </div>
                <?php endif; ?>

                <div class="fw-bold fs-6 mb-0"><?= e($user['name']) ?></div>
                <div class="text-muted small mb-2"><?= ucwords(str_replace('_', ' ', $user['role'])) ?></div>

                <dl class="row text-start mb-0 mt-3" style="font-size:13px">
                    <dt class="col-5 text-muted">Username</dt>
                    <dd class="col-7"><code><?= e($user['username']) ?></code></dd>
                    <?php if ($user['email']): ?>
                    <dt class="col-5 text-muted">Email</dt>
                    <dd class="col-7 text-break"><?= e($user['email']) ?></dd>
                    <?php endif; ?>
                    <dt class="col-5 text-muted">Status</dt>
                    <dd class="col-7"><?= statusBadge($user['status']) ?></dd>
                </dl>
            </div>
        </div>

        <!-- Upload photo -->
        <div class="card mb-3">
            <div class="card-header fw-semibold"><i class="fa fa-camera me-2 text-primary"></i>Profile Photo</div>
            <div class="card-body">
                <form method="POST" enctype="multipart/form-data">
                    <div class="mb-3">
                        <input type="file" name="profile_photo" id="profilePhotoInput"
                               class="form-control" accept="image/*" required>
                        <div class="form-text">Max 20 MB &mdash; JPG, PNG, WEBP</div>
                    </div>
                    <!-- Live preview -->
                    <div id="photoPreview" class="text-center mb-3" style="display:none">
                        <img id="previewImg" src="" alt="Preview"
                             class="rounded-circle border" style="width:80px;height:80px;object-fit:cover">
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

    <!-- ══ RIGHT COLUMN: Profile info + Password ══════════════════════════════ -->
    <div class="col-lg-8">

        <!-- Edit profile info -->
        <div class="card mb-4">
            <div class="card-header fw-semibold"><i class="fa fa-id-card me-2 text-primary"></i>Profile Information</div>
            <div class="card-body">
                <form method="POST" autocomplete="off">
                    <input type="hidden" name="update_profile" value="1">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Full Name <span class="text-danger">*</span></label>
                            <input type="text" name="name" class="form-control"
                                   value="<?= e($user['name']) ?>" required placeholder="Your full name">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Email Address <span class="text-danger">*</span></label>
                            <input type="email" name="email" class="form-control"
                                   value="<?= e($user['email'] ?? '') ?>" required placeholder="you@example.com">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label text-muted">Username</label>
                            <input type="text" class="form-control bg-light" value="<?= e($user['username']) ?>" disabled>
                            <div class="form-text">Username cannot be changed here.</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label text-muted">Role</label>
                            <input type="text" class="form-control bg-light"
                                   value="<?= ucwords(str_replace('_', ' ', $user['role'])) ?>" disabled>
                        </div>
                    </div>
                    <div class="mt-4">
                        <button type="submit" class="btn btn-primary px-4">
                            <i class="fa fa-save me-1"></i>Save Profile
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Change password -->
        <div class="card" style="border-top:3px solid #7c3aed">
            <div class="card-header fw-semibold"><i class="fa fa-lock me-2 text-purple" style="color:#7c3aed"></i>Change Password</div>
            <div class="card-body">
                <form method="POST" autocomplete="off" id="pwForm">
                    <input type="hidden" name="change_password" value="1">
                    <div class="row g-3">
                        <div class="col-md-12" style="max-width:480px">
                            <label class="form-label">Current Password <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <input type="password" name="current_password" id="currentPw"
                                       class="form-control" placeholder="Enter current password"
                                       required autocomplete="current-password">
                                <button class="btn btn-outline-secondary" type="button"
                                        onclick="togglePw('currentPw',this)" tabindex="-1">
                                    <i class="fa fa-eye"></i>
                                </button>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">New Password <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <input type="password" name="new_password" id="newPw"
                                       class="form-control" placeholder="Min. 6 characters"
                                       required autocomplete="new-password" oninput="checkStrength(this.value)">
                                <button class="btn btn-outline-secondary" type="button"
                                        onclick="togglePw('newPw',this)" tabindex="-1">
                                    <i class="fa fa-eye"></i>
                                </button>
                            </div>
                            <!-- Strength bar -->
                            <div class="mt-2" id="strengthBar" style="display:none">
                                <div class="progress" style="height:5px">
                                    <div id="strengthFill" class="progress-bar" style="width:0%"></div>
                                </div>
                                <div id="strengthLabel" class="form-text mt-1"></div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Confirm New Password <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <input type="password" name="confirm_password" id="confirmPw"
                                       class="form-control" placeholder="Repeat new password"
                                       required autocomplete="new-password">
                                <button class="btn btn-outline-secondary" type="button"
                                        onclick="togglePw('confirmPw',this)" tabindex="-1">
                                    <i class="fa fa-eye"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                    <div class="mt-4">
                        <button type="submit" class="btn px-4" style="background:#7c3aed;color:#fff">
                            <i class="fa fa-key me-1"></i>Update Password
                        </button>
                    </div>
                </form>
            </div>
        </div>

    </div>
</div>

<script>
// Live photo preview
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

// Toggle password visibility
function togglePw(id, btn) {
    var inp = document.getElementById(id);
    var icon = btn.querySelector('i');
    if (inp.type === 'password') {
        inp.type = 'text';
        icon.classList.replace('fa-eye', 'fa-eye-slash');
    } else {
        inp.type = 'password';
        icon.classList.replace('fa-eye-slash', 'fa-eye');
    }
}

// Password strength meter
function checkStrength(val) {
    var bar   = document.getElementById('strengthBar');
    var fill  = document.getElementById('strengthFill');
    var label = document.getElementById('strengthLabel');
    if (!val) { bar.style.display = 'none'; return; }
    bar.style.display = '';
    var score = 0;
    if (val.length >= 6)  score++;
    if (val.length >= 10) score++;
    if (/[A-Z]/.test(val)) score++;
    if (/[0-9]/.test(val)) score++;
    if (/[^A-Za-z0-9]/.test(val)) score++;
    var levels = [
        { pct:'20%', cls:'bg-danger',  text:'Very weak'  },
        { pct:'40%', cls:'bg-warning', text:'Weak'       },
        { pct:'60%', cls:'bg-info',    text:'Fair'       },
        { pct:'80%', cls:'bg-primary', text:'Strong'     },
        { pct:'100%',cls:'bg-success', text:'Very strong'},
    ];
    var lv = levels[Math.min(score - 1, 4)] || levels[0];
    fill.style.width = lv.pct;
    fill.className   = 'progress-bar ' + lv.cls;
    label.textContent = lv.text;
    label.style.color = '';
}
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>

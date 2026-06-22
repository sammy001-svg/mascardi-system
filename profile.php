<?php
require_once __DIR__ . '/includes/functions.php';
requireLogin();
$db   = getDB();
$me   = authUser();
$id   = (int)$me['id'];

// Safe schema upgrades on every load
try { $db->exec("ALTER TABLE users ADD COLUMN profile_image VARCHAR(255) NULL DEFAULT NULL"); } catch (\Throwable $_) {}
try { $db->exec("ALTER TABLE users ADD COLUMN phone VARCHAR(50) NULL DEFAULT NULL"); } catch (\Throwable $_) {}

// Re-fetch full row
$stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$id]);
$user = $stmt->fetch();

$errors  = [];
$success = '';

// ── Update personal info ─────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_info'])) {
    $name  = trim($_POST['name']  ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');

    if ($name === '') {
        $errors[] = 'Name is required.';
    } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Email address is not valid.';
    } else {
        $db->prepare("UPDATE users SET name = ?, email = ?, phone = ? WHERE id = ?")
           ->execute([$name, $email ?: null, $phone ?: null, $id]);
        logActivity('update', 'users', $id, 'Updated profile info');
        setFlash('success', 'Profile updated successfully.');
        redirect(BASE_URL . '/profile.php');
    }
}

// ── Upload photo ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['profile_photo']) && !isset($_POST['update_info'])) {
    $file = $_FILES['profile_photo'];
    if ($file['error'] === UPLOAD_ERR_NO_FILE) {
        $errors[] = 'No file selected.';
    } else {
        try {
            $uploadDir = __DIR__ . '/uploads/profiles';
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
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

// ── Remove photo ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['remove_photo'])) {
    if ($user['profile_image']) {
        $old = __DIR__ . '/uploads/profiles/' . $user['profile_image'];
        if (file_exists($old)) unlink($old);
        $db->prepare("UPDATE users SET profile_image = NULL WHERE id = ?")->execute([$id]);
    }
    setFlash('success', 'Profile photo removed.');
    redirect(BASE_URL . '/profile.php');
}

// ── Change password ───────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    $current = $_POST['current_password'] ?? '';
    $new     = $_POST['new_password']     ?? '';
    $confirm = $_POST['confirm_password'] ?? '';

    if (!password_verify($current, $user['password'])) {
        $errors[] = 'Current password is incorrect.';
    } elseif (strlen($new) < 8) {
        $errors[] = 'New password must be at least 8 characters.';
    } elseif ($new !== $confirm) {
        $errors[] = 'Passwords do not match.';
    } else {
        $db->prepare("UPDATE users SET password = ? WHERE id = ?")
           ->execute([password_hash($new, PASSWORD_DEFAULT), $id]);
        logActivity('update', 'users', $id, 'Changed own password');
        setFlash('success', 'Password changed successfully.');
        redirect(BASE_URL . '/profile.php');
    }
}

// ── Recent activity ───────────────────────────────────────────────────────────
$activity = $db->prepare(
    "SELECT action, module, details, ip_address, created_at
     FROM audit_logs WHERE user_id = ? ORDER BY created_at DESC LIMIT 8"
);
$activity->execute([$id]);
$logs = $activity->fetchAll();

$pageTitle = 'My Profile';
include __DIR__ . '/includes/header.php';

$initials     = strtoupper(implode('', array_map(fn($w) => $w[0], explode(' ', trim($user['name'])))));
$initials     = substr($initials, 0, 2);
$avatarColors = ['#2563eb','#7c3aed','#db2777','#059669','#d97706','#dc2626','#0891b2'];
$avatarColor  = $avatarColors[crc32($user['name']) % count($avatarColors)];

$flash = getFlash();
?>

<style>
.profile-avatar-wrap { position:relative; display:inline-block; cursor:pointer; }
.profile-avatar-wrap .avatar-overlay {
    position:absolute; inset:0; border-radius:50%;
    background:rgba(0,0,0,.45); display:flex; align-items:center; justify-content:center;
    opacity:0; transition:.2s; color:#fff; font-size:20px;
}
.profile-avatar-wrap:hover .avatar-overlay { opacity:1; }
.strength-bar { height:4px; border-radius:2px; transition:width .3s,background .3s; }
.pw-toggle { cursor:pointer; user-select:none; }
.activity-dot {
    width:8px; height:8px; border-radius:50%; flex-shrink:0; margin-top:5px;
}
</style>

<!-- Page header -->
<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <h5 class="mb-1"><i class="fa fa-user-circle me-2 text-primary"></i>My Profile</h5>
        <div class="text-muted small">Manage your account information and security settings</div>
    </div>
</div>

<?php if ($flash && $flash['type'] === 'success'): ?>
<div class="alert alert-success alert-dismissible fade show" role="alert">
    <i class="fa fa-check-circle me-2"></i><?= e($flash['message']) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<?php if ($errors): ?>
<div class="alert alert-danger alert-dismissible fade show" role="alert">
    <ul class="mb-0"><?php foreach ($errors as $err) echo '<li>' . e($err) . '</li>'; ?></ul>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<div class="row g-4">

    <!-- ════════════════════════════════════════════════════════════════
         LEFT COLUMN — Avatar card + photo change
    ═════════════════════════════════════════════════════════════════ -->
    <div class="col-lg-4">

        <!-- Avatar card -->
        <div class="card text-center mb-4">
            <div class="card-body py-4">

                <!-- Clickable avatar — triggers file input -->
                <label for="profilePhotoInput" class="profile-avatar-wrap mb-3 d-inline-block">
                    <?php if ($user['profile_image']): ?>
                    <img src="<?= BASE_URL ?>/uploads/profiles/<?= e($user['profile_image']) ?>"
                         id="avatarPreviewImg"
                         class="rounded-circle border shadow"
                         style="width:110px;height:110px;object-fit:cover"
                         decoding="async" fetchpriority="high" width="110" height="110">
                    <?php else: ?>
                    <div id="avatarInitials"
                         class="rounded-circle d-inline-flex align-items-center justify-content-center border shadow"
                         style="width:110px;height:110px;background:<?= $avatarColor ?>;font-size:36px;font-weight:700;color:#fff">
                        <?= e($initials) ?>
                    </div>
                    <?php endif; ?>
                    <div class="avatar-overlay"><i class="fa fa-camera"></i></div>
                </label>

                <div class="fw-bold fs-6 mb-0"><?= e($user['name']) ?></div>
                <div class="badge rounded-pill mb-3"
                     style="background:var(--bs-primary-bg-subtle,#dbeafe);color:var(--bs-primary,#2563eb);font-weight:600;font-size:11px">
                    <?= ucwords(str_replace('_', ' ', $user['role'])) ?>
                </div>

                <dl class="row text-start mb-0" style="font-size:13px">
                    <dt class="col-5 text-muted fw-normal">Username</dt>
                    <dd class="col-7 fw-semibold"><code><?= e($user['username']) ?></code></dd>
                    <?php if ($user['email']): ?>
                    <dt class="col-5 text-muted fw-normal">Email</dt>
                    <dd class="col-7 text-truncate"><?= e($user['email']) ?></dd>
                    <?php endif; ?>
                    <?php if (!empty($user['phone'])): ?>
                    <dt class="col-5 text-muted fw-normal">Phone</dt>
                    <dd class="col-7"><?= e($user['phone']) ?></dd>
                    <?php endif; ?>
                    <?php if ($user['last_login']): ?>
                    <dt class="col-5 text-muted fw-normal">Last Login</dt>
                    <dd class="col-7"><?= date('d M, H:i', strtotime($user['last_login'])) ?></dd>
                    <?php endif; ?>
                    <dt class="col-5 text-muted fw-normal">Status</dt>
                    <dd class="col-7"><?= statusBadge($user['status']) ?></dd>
                </dl>
            </div>
        </div>

        <!-- Photo upload form (hidden input, avatar click triggers it) -->
        <form method="POST" enctype="multipart/form-data" id="photoForm">
            <input type="file" name="profile_photo" id="profilePhotoInput"
                   accept="image/*" style="display:none">
            <!-- Preview before submit -->
            <div id="photoPreviewBar" class="card mb-3" style="display:none">
                <div class="card-body text-center py-3">
                    <img id="previewImg" src="" class="rounded-circle border mb-2"
                         style="width:72px;height:72px;object-fit:cover">
                    <div class="d-flex gap-2 justify-content-center">
                        <button type="submit" class="btn btn-primary btn-sm">
                            <i class="fa fa-upload me-1"></i>Save Photo
                        </button>
                        <button type="button" class="btn btn-outline-secondary btn-sm" id="cancelPhoto">
                            Cancel
                        </button>
                    </div>
                </div>
            </div>
        </form>

        <?php if ($user['profile_image']): ?>
        <form method="POST">
            <button type="submit" name="remove_photo" value="1"
                    class="btn btn-outline-danger w-100 btn-sm"
                    onclick="return confirm('Remove your profile photo?')">
                <i class="fa fa-trash me-1"></i>Remove Photo
            </button>
        </form>
        <?php endif; ?>

    </div>

    <!-- ════════════════════════════════════════════════════════════════
         RIGHT COLUMN — Personal info + Password + Activity
    ═════════════════════════════════════════════════════════════════ -->
    <div class="col-lg-8">

        <!-- Personal Information -->
        <div class="card mb-4">
            <div class="card-header fw-semibold">
                <i class="fa fa-id-card me-2 text-primary"></i>Personal Information
            </div>
            <div class="card-body">
                <form method="POST" autocomplete="off">
                    <input type="hidden" name="update_info" value="1">
                    <div class="row g-3">
                        <div class="col-sm-6">
                            <label class="form-label">Full Name <span class="text-danger">*</span></label>
                            <input type="text" name="name" class="form-control"
                                   value="<?= e($user['name']) ?>" required maxlength="150">
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label">Username</label>
                            <input type="text" class="form-control"
                                   value="<?= e($user['username']) ?>" disabled
                                   title="Username cannot be changed">
                            <div class="form-text text-muted">Username cannot be changed.</div>
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label">Email Address</label>
                            <input type="email" name="email" class="form-control"
                                   value="<?= e($user['email'] ?? '') ?>" maxlength="150"
                                   placeholder="your@email.com">
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label">Phone Number</label>
                            <input type="tel" name="phone" class="form-control"
                                   value="<?= e($user['phone'] ?? '') ?>" maxlength="50"
                                   placeholder="+254 700 000 000">
                        </div>
                    </div>
                    <div class="mt-3">
                        <button type="submit" class="btn btn-primary">
                            <i class="fa fa-save me-1"></i>Save Changes
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Change Password -->
        <div class="card mb-4">
            <div class="card-header fw-semibold">
                <i class="fa fa-lock me-2 text-primary"></i>Change Password
            </div>
            <div class="card-body">
                <form method="POST" autocomplete="off" id="pwForm">
                    <input type="hidden" name="change_password" value="1">
                    <div class="row g-3">
                        <div class="col-12 col-sm-6 col-lg-12 col-xl-6">
                            <label class="form-label">Current Password <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <input type="password" name="current_password" id="curPw"
                                       class="form-control" required autocomplete="current-password"
                                       placeholder="Your current password">
                                <span class="input-group-text pw-toggle" data-target="curPw">
                                    <i class="fa fa-eye" id="curPwIcon"></i>
                                </span>
                            </div>
                        </div>
                        <div class="col-12 col-sm-6 col-lg-12 col-xl-6">
                            <label class="form-label">New Password <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <input type="password" name="new_password" id="newPw"
                                       class="form-control" required autocomplete="new-password"
                                       placeholder="Min. 8 characters" oninput="checkStrength(this.value)">
                                <span class="input-group-text pw-toggle" data-target="newPw">
                                    <i class="fa fa-eye" id="newPwIcon"></i>
                                </span>
                            </div>
                            <!-- Strength bar -->
                            <div class="mt-2">
                                <div style="height:4px;background:#e5e7eb;border-radius:2px;overflow:hidden">
                                    <div class="strength-bar" id="strengthBar" style="width:0;background:#ef4444"></div>
                                </div>
                                <div class="form-text" id="strengthLabel" style="font-size:11px"></div>
                            </div>
                        </div>
                        <div class="col-12 col-sm-6 col-lg-12 col-xl-6">
                            <label class="form-label">Confirm New Password <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <input type="password" name="confirm_password" id="confPw"
                                       class="form-control" required autocomplete="new-password"
                                       placeholder="Repeat new password" oninput="checkMatch()">
                                <span class="input-group-text pw-toggle" data-target="confPw">
                                    <i class="fa fa-eye" id="confPwIcon"></i>
                                </span>
                            </div>
                            <div class="form-text" id="matchLabel" style="font-size:11px"></div>
                        </div>
                    </div>
                    <div class="mt-3">
                        <button type="submit" class="btn btn-primary">
                            <i class="fa fa-key me-1"></i>Update Password
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Recent Activity -->
        <?php if ($logs): ?>
        <div class="card">
            <div class="card-header fw-semibold d-flex align-items-center justify-content-between">
                <span><i class="fa fa-clock-rotate-left me-2 text-primary"></i>Recent Activity</span>
                <span class="badge bg-secondary rounded-pill"><?= count($logs) ?></span>
            </div>
            <div class="card-body p-0">
                <ul class="list-group list-group-flush">
                    <?php foreach ($logs as $log):
                        $actionColors = [
                            'create' => '#10b981', 'insert' => '#10b981',
                            'update' => '#2563eb',
                            'delete' => '#ef4444',
                            'login'  => '#7c3aed',
                        ];
                        $dotColor = $actionColors[strtolower($log['action'])] ?? '#6b7280';
                        $module   = $log['module'] ? ucwords(str_replace(['_','modules/'], [' ',''], $log['module'])) : '—';
                    ?>
                    <li class="list-group-item">
                        <div class="d-flex gap-3 align-items-start">
                            <div class="activity-dot mt-1" style="background:<?= $dotColor ?>;margin-top:6px"></div>
                            <div style="flex:1;min-width:0">
                                <div class="d-flex justify-content-between align-items-center gap-2 flex-wrap">
                                    <span class="fw-semibold text-capitalize" style="font-size:13px">
                                        <?= e($log['action']) ?>
                                        <span class="text-muted fw-normal">· <?= e($module) ?></span>
                                    </span>
                                    <span class="text-muted" style="font-size:11px;white-space:nowrap">
                                        <?= date('d M, H:i', strtotime($log['created_at'])) ?>
                                    </span>
                                </div>
                                <?php if ($log['details']): ?>
                                <div class="text-muted" style="font-size:12px;margin-top:1px">
                                    <?= e($log['details']) ?>
                                </div>
                                <?php endif; ?>
                                <?php if ($log['ip_address']): ?>
                                <div class="text-muted" style="font-size:11px;margin-top:2px">
                                    <i class="fa fa-location-dot me-1" style="font-size:10px"></i><?= e($log['ip_address']) ?>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
        <?php endif; ?>

    </div>
</div>

<script>
// ── Photo preview ────────────────────────────────────────────────────────────
var photoInput  = document.getElementById('profilePhotoInput');
var previewBar  = document.getElementById('photoPreviewBar');
var previewImg  = document.getElementById('previewImg');
var cancelBtn   = document.getElementById('cancelPhoto');

photoInput.addEventListener('change', function () {
    if (!this.files[0]) return;
    var reader = new FileReader();
    reader.onload = function (e) {
        previewImg.src = e.target.result;
        // Also update the avatar on the card live
        var live = document.getElementById('avatarPreviewImg') || document.getElementById('avatarInitials');
        if (live && live.tagName === 'IMG') live.src = e.target.result;
        previewBar.style.display = '';
    };
    reader.readAsDataURL(this.files[0]);
});

if (cancelBtn) cancelBtn.addEventListener('click', function () {
    photoInput.value = '';
    previewBar.style.display = 'none';
});

// ── Show / hide password ─────────────────────────────────────────────────────
document.querySelectorAll('.pw-toggle').forEach(function (el) {
    el.addEventListener('click', function () {
        var target = document.getElementById(this.dataset.target);
        var icon   = this.querySelector('i');
        if (target.type === 'password') {
            target.type = 'text';
            icon.className = 'fa fa-eye-slash';
        } else {
            target.type = 'password';
            icon.className = 'fa fa-eye';
        }
    });
});

// ── Password strength ────────────────────────────────────────────────────────
function checkStrength(val) {
    var bar   = document.getElementById('strengthBar');
    var label = document.getElementById('strengthLabel');
    if (!val) { bar.style.width = '0'; label.textContent = ''; return; }
    var score = 0;
    if (val.length >= 8)  score++;
    if (val.length >= 12) score++;
    if (/[A-Z]/.test(val)) score++;
    if (/[0-9]/.test(val)) score++;
    if (/[^A-Za-z0-9]/.test(val)) score++;
    var levels = [
        { pct:'20%', color:'#ef4444', text:'Very weak' },
        { pct:'40%', color:'#f97316', text:'Weak'      },
        { pct:'60%', color:'#eab308', text:'Fair'      },
        { pct:'80%', color:'#3b82f6', text:'Good'      },
        { pct:'100%',color:'#10b981', text:'Strong'    },
    ];
    var lvl = levels[Math.min(score, 4)];
    bar.style.width     = lvl.pct;
    bar.style.background = lvl.color;
    label.textContent   = lvl.text;
    label.style.color   = lvl.color;
}

// ── Confirm match indicator ──────────────────────────────────────────────────
function checkMatch() {
    var newPw  = document.getElementById('newPw').value;
    var confPw = document.getElementById('confPw').value;
    var label  = document.getElementById('matchLabel');
    if (!confPw) { label.textContent = ''; return; }
    if (newPw === confPw) {
        label.textContent = 'Passwords match';
        label.style.color = '#10b981';
    } else {
        label.textContent = 'Passwords do not match';
        label.style.color = '#ef4444';
    }
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>

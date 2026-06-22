  <?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/app.php';

// Already logged in?
if (!empty($_SESSION['auth_user'])) {
    header('Location: ' . BASE_URL . '/index.php');
    exit;
}

function ensureUsersTable(): void {
    getDB()->exec("CREATE TABLE IF NOT EXISTS users (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(150) NOT NULL,
        username VARCHAR(50) UNIQUE NOT NULL,
        email VARCHAR(150),
        password VARCHAR(255) NOT NULL,
        role ENUM('admin','workshop_manager','sales_person','sales_officer','manager','mechanic','driver') NOT NULL DEFAULT 'mechanic',
        linked_id INT NULL,
        linked_type ENUM('driver','mechanic') NULL,
        status ENUM('active','inactive') NOT NULL DEFAULT 'active',
        last_login TIMESTAMP NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function hasAdminUser(): bool {
    try {
        return (int) getDB()->query("SELECT COUNT(*) FROM users WHERE role IN ('admin','super_admin')")->fetchColumn() > 0;
    } catch (PDOException $e) {
        return false;
    }
}

$isFirstRun = !hasAdminUser();
$error = '';
$setupDone = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if ($isFirstRun && isset($_POST['setup_admin'])) {
        $name   = trim($_POST['name'] ?? '');
        $uname  = trim($_POST['username'] ?? '');
        $email  = trim($_POST['email'] ?? '');
        $pass   = $_POST['password'] ?? '';
        $pass2  = $_POST['password_confirm'] ?? '';

        if (!$name || !$uname || !$pass) {
            $error = 'Name, username, and password are required.';
        } elseif (strlen($pass) < 6) {
            $error = 'Password must be at least 6 characters.';
        } elseif ($pass !== $pass2) {
            $error = 'Passwords do not match.';
        } else {
            ensureUsersTable();
            $db = getDB();
            try {
                $db->prepare("INSERT INTO users (name,username,email,password,role) VALUES (?,?,?,?,'admin')")
                   ->execute([$name, $uname, $email, password_hash($pass, PASSWORD_DEFAULT)]);
                $setupDone = true;
                $isFirstRun = false;
            } catch (PDOException $e) {
                $error = $e->getCode() === '23000' ? 'Username already taken.' : $e->getMessage();
            }
        }

    } else {
        $uname = trim($_POST['username'] ?? '');
        $pass  = $_POST['password'] ?? '';
        $ip    = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

        if (!$uname || !$pass) {
            $error = 'Username and password are required.';
        } else {
            $db = getDB();

            // Brute-force protection: max 5 failures per username per 15 minutes
            try {
                $db->exec("CREATE TABLE IF NOT EXISTS login_attempts (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    username VARCHAR(100) NOT NULL,
                    ip_address VARCHAR(45) NOT NULL,
                    attempted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_username_time (username, attempted_at),
                    INDEX idx_ip_time (ip_address, attempted_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

                $failCount = (int)$db->prepare("SELECT COUNT(*) FROM login_attempts WHERE (username=? OR ip_address=?) AND attempted_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)")
                    ->execute([$uname, $ip]) ? $db->query("SELECT COUNT(*) FROM login_attempts WHERE (username=? OR ip_address=?) AND attempted_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)")->fetchColumn() : 0;

                $failStmt = $db->prepare("SELECT COUNT(*) FROM login_attempts WHERE (username=? OR ip_address=?) AND attempted_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)");
                $failStmt->execute([$uname, $ip]);
                $failCount = (int)$failStmt->fetchColumn();

                if ($failCount >= 5) {
                    $error = 'Too many failed attempts. Please wait 15 minutes and try again.';
                } else {
                    $stmt = $db->prepare("SELECT * FROM users WHERE username=? AND status='active' LIMIT 1");
                    $stmt->execute([$uname]);
                    $user = $stmt->fetch();

                    if ($user && password_verify($pass, $user['password'])) {
                        // Clear failed attempts on success
                        $db->prepare("DELETE FROM login_attempts WHERE username=? OR ip_address=?")->execute([$uname, $ip]);

                        session_regenerate_id(true);
                        $_SESSION['auth_user'] = [
                            'id'          => $user['id'],
                            'name'        => $user['name'],
                            'username'    => $user['username'],
                            'role'        => $user['role'],
                            'linked_id'   => $user['linked_id'],
                            'linked_type' => $user['linked_type'],
                        ];
                        $_SESSION['last_activity']    = time();
                        $_SESSION['sess_regenerated'] = time();

                        $db->prepare("UPDATE users SET last_login=NOW() WHERE id=?")->execute([$user['id']]);

                        $next = $_GET['next'] ?? '';
                        if ($next && str_starts_with(urldecode($next), '/')) {
                            header('Location: ' . urldecode($next));
                        } else {
                            header('Location: ' . BASE_URL . '/index.php');
                        }
                        exit;
                    } else {
                        // Log failed attempt
                        $db->prepare("INSERT INTO login_attempts (username, ip_address) VALUES (?, ?)")->execute([$uname, $ip]);
                        $remaining = max(0, 5 - $failCount - 1);
                        $error = 'Invalid username or password.' . ($remaining > 0 ? " ({$remaining} attempts remaining)" : ' Account temporarily locked.');
                    }
                }
            } catch (PDOException $e) {
                // If login_attempts table doesn't exist yet, fall back to simple auth
                $stmt = $db->prepare("SELECT * FROM users WHERE username=? AND status='active' LIMIT 1");
                $stmt->execute([$uname]);
                $user = $stmt->fetch();
                if ($user && password_verify($pass, $user['password'])) {
                    session_regenerate_id(true);
                    $_SESSION['auth_user'] = ['id' => $user['id'], 'name' => $user['name'], 'username' => $user['username'], 'role' => $user['role'], 'linked_id' => $user['linked_id'], 'linked_type' => $user['linked_type']];
                    $_SESSION['last_activity'] = time();
                    $db->prepare("UPDATE users SET last_login=NOW() WHERE id=?")->execute([$user['id']]);
                    header('Location: ' . BASE_URL . '/index.php'); exit;
                } else {
                    $error = 'Invalid username or password.';
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= $isFirstRun ? 'Setup — ' : 'Staff Login — ' ?><?= htmlspecialchars(APP_NAME) ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
<style>
*, *::before, *::after { box-sizing: border-box; }
body {
    background: linear-gradient(135deg, #0f172a 0%, #1e3a8a 55%, #2563eb 100%);
    min-height: 100vh;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    font-family: 'Inter', 'Segoe UI', system-ui, sans-serif;
    padding: 24px;
    position: relative;
}
body::before {
    content: '';
    position: fixed;
    inset: 0;
    background-image: linear-gradient(rgba(255,255,255,.03) 1px, transparent 1px),
                      linear-gradient(90deg, rgba(255,255,255,.03) 1px, transparent 1px);
    background-size: 50px 50px;
    pointer-events: none;
}
.back-link {
    position: fixed;
    top: 20px;
    left: 24px;
    color: rgba(255,255,255,.6);
    font-size: 13px;
    font-weight: 600;
    text-decoration: none;
    display: flex;
    align-items: center;
    gap: 7px;
    padding: 8px 14px;
    border: 1px solid rgba(255,255,255,.15);
    border-radius: 8px;
    background: rgba(255,255,255,.05);
    transition: all .15s;
    z-index: 10;
}
.back-link:hover { color: #fff; border-color: rgba(255,255,255,.3); background: rgba(255,255,255,.1); text-decoration: none; }
.login-wrap { width: 100%; max-width: 420px; position: relative; z-index: 1; }
.login-card {
    background: rgba(255,255,255,.97);
    backdrop-filter: blur(20px);
    border-radius: 24px;
    padding: 42px 38px;
    box-shadow: 0 32px 80px rgba(0,0,0,.4), 0 4px 16px rgba(0,0,0,.2);
    border: 1px solid rgba(255,255,255,.4);
}
.brand-icon { width: 58px; height: 58px; background: linear-gradient(135deg,#3b82f6,#1d4ed8); border-radius: 16px; display: flex; align-items: center; justify-content: center; color: #fff; font-size: 26px; margin: 0 auto 16px; box-shadow: 0 8px 24px rgba(37,99,235,.4); }
.login-title { font-size: 23px; font-weight: 800; color: #0f172a; text-align: center; margin-bottom: 4px; letter-spacing: -.4px; }
.login-sub { color: #64748b; font-size: 13px; text-align: center; margin-bottom: 28px; }
.form-label { font-size: 13px; font-weight: 600; color: #374151; margin-bottom: 5px; }
.form-control { font-size: 14px; border-color: #e2e8f0; padding: 10px 40px; border-radius: 10px; transition: border-color .15s, box-shadow .15s; }
.form-control:focus { border-color: #2563eb; box-shadow: 0 0 0 3px rgba(37,99,235,.15); outline: none; }
.field-wrap { position: relative; }
.field-wrap > i:first-child { position: absolute; left: 13px; top: 50%; transform: translateY(-50%); color: #94a3b8; font-size: 14px; pointer-events: none; }
.password-toggle { position: absolute; right: 13px; top: 50%; transform: translateY(-50%); color: #94a3b8; cursor: pointer; z-index: 10; transition: color .15s; background: none; border: none; padding: 0; font-size: 14px; display: flex; align-items: center; }
.password-toggle:hover { color: #2563eb; }
.btn-login { background: linear-gradient(135deg,#2563eb,#1d4ed8); border: none; padding: 13px; font-size: 15px; font-weight: 700; border-radius: 12px; letter-spacing: .3px; transition: box-shadow .15s, transform .1s; }
.btn-login:hover { box-shadow: 0 6px 20px rgba(37,99,235,.45); transform: translateY(-1px); }
.first-run-badge { background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 10px; padding: 10px 14px; font-size: 13px; color: #1d4ed8; margin-bottom: 18px; }
</style>
</head>
<body>

<!-- Back to showroom -->
<a href="<?= BASE_URL ?>/showroom/" class="back-link">
    <i class="fa fa-arrow-left"></i> Back to Showroom
</a>

<div class="login-wrap">
    <div class="login-card">
        <div class="brand-icon"><i class="fa fa-car-side"></i></div>
        <div class="login-title"><?= $isFirstRun ? 'System Setup' : htmlspecialchars(APP_NAME) ?></div>
        <div class="login-sub"><?= $isFirstRun ? 'Create your administrator account to get started.' : 'Staff portal — sign in to continue' ?></div>

        <?php if ($isFirstRun && !$setupDone): ?>
        <div class="first-run-badge"><i class="fa fa-star me-2"></i><strong>First-time setup:</strong> No admin account exists yet. Create one below.</div>
        <?php endif; ?>

        <?php if (isset($_GET['timeout'])): ?>
        <div class="alert alert-warning py-2"><i class="fa fa-clock me-2"></i>Your session expired due to inactivity. Please sign in again.</div>
        <?php endif; ?>

        <?php if ($error): ?>
        <div class="alert alert-danger py-2"><i class="fa fa-circle-exclamation me-2"></i><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <?php if ($setupDone): ?>
        <div class="alert alert-success py-2"><i class="fa fa-check-circle me-2"></i>Admin account created! You can now sign in.</div>
        <?php endif; ?>

        <?php if ($isFirstRun && !$setupDone): ?>
        <!-- First-run admin setup -->
        <form method="POST">
            <input type="hidden" name="setup_admin" value="1">
            <div class="mb-3">
                <label class="form-label">Full Name <span class="text-danger">*</span></label>
                <div class="field-wrap"><i class="fa fa-user"></i>
                <input type="text" name="name" class="form-control" placeholder="e.g. Mascardi Admin" required value="<?= htmlspecialchars($_POST['name'] ?? '') ?>"></div>
            </div>
            <div class="mb-3">
                <label class="form-label">Username <span class="text-danger">*</span></label>
                <div class="field-wrap"><i class="fa fa-at"></i>
                <input type="text" name="username" class="form-control" placeholder="admin" required autocomplete="off" value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"></div>
            </div>
            <div class="mb-3">
                <label class="form-label">Email <span class="text-muted fw-normal">(optional)</span></label>
                <div class="field-wrap"><i class="fa fa-envelope"></i>
                <input type="email" name="email" class="form-control" placeholder="admin@mascardi.co.ke" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"></div>
            </div>
            <div class="mb-3">
                <label class="form-label">Password <span class="text-danger">*</span></label>
                <div class="field-wrap">
                    <i class="fa fa-lock"></i>
                    <input type="password" name="password" class="form-control password-input" placeholder="At least 6 characters" required>
                    <button type="button" class="password-toggle"><i class="fa fa-eye"></i></button>
                </div>
            </div>
            <div class="mb-4">
                <label class="form-label">Confirm Password <span class="text-danger">*</span></label>
                <div class="field-wrap">
                    <i class="fa fa-lock"></i>
                    <input type="password" name="password_confirm" class="form-control password-input" placeholder="Repeat password" required>
                    <button type="button" class="password-toggle"><i class="fa fa-eye"></i></button>
                </div>
            </div>
            <button type="submit" class="btn btn-login btn-primary w-100 text-white">
                <i class="fa fa-check-circle me-2"></i>Create Admin Account
            </button>
        </form>

        <?php else: ?>
        <!-- Normal login -->
        <form method="POST">
            <div class="mb-3">
                <label class="form-label">Username</label>
                <div class="field-wrap"><i class="fa fa-user"></i>
                <input type="text" name="username" class="form-control" placeholder="Enter your username" required autocomplete="username" value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"></div>
            </div>
            <div class="mb-4">
                <label class="form-label">Password</label>
                <div class="field-wrap">
                    <i class="fa fa-lock"></i>
                    <input type="password" name="password" class="form-control password-input" placeholder="Enter your password" required autocomplete="current-password">
                    <button type="button" class="password-toggle"><i class="fa fa-eye"></i></button>
                </div>
            </div>
            <button type="submit" class="btn btn-login btn-primary w-100 text-white">
                <i class="fa fa-right-to-bracket me-2"></i>Sign In
            </button>
        </form>
        <?php endif; ?>
    </div>

    <div class="text-center mt-3">
        <p style="font-size:12px;color:rgba(255,255,255,.35);margin:0 0 8px">
            <?= htmlspecialchars(APP_NAME) ?> &mdash; Staff Portal
        </p>
        <a href="<?= BASE_URL ?>/showroom/" style="font-size:12.5px;color:rgba(255,255,255,.5);text-decoration:none;transition:color .15s"
           onmouseover="this.style.color='rgba(255,255,255,.9)'" onmouseout="this.style.color='rgba(255,255,255,.5)'">
            <i class="fa fa-store me-1"></i> Browse public showroom →
        </a>
    </div>
</div>

<script>
document.querySelectorAll('.password-toggle').forEach(btn => {
    btn.addEventListener('click', () => {
        const input = btn.closest('.field-wrap').querySelector('.password-input');
        const icon = btn.querySelector('i');
        if (input.type === 'password') {
            input.type = 'text';
            icon.classList.replace('fa-eye', 'fa-eye-slash');
        } else {
            input.type = 'password';
            icon.classList.replace('fa-eye-slash', 'fa-eye');
        }
    });
});
</script>
</body>
</html>

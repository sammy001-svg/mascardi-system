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
        role ENUM('admin','manager','mechanic','driver') NOT NULL DEFAULT 'mechanic',
        linked_id INT NULL,
        linked_type ENUM('driver','mechanic') NULL,
        status ENUM('active','inactive') NOT NULL DEFAULT 'active',
        last_login TIMESTAMP NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function hasAdminUser(): bool {
    try {
        return (int) getDB()->query("SELECT COUNT(*) FROM users WHERE role='admin'")->fetchColumn() > 0;
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

        if (!$uname || !$pass) {
            $error = 'Username and password are required.';
        } else {
            $db = getDB();
            $stmt = $db->prepare("SELECT * FROM users WHERE username=? AND status='active' LIMIT 1");
            $stmt->execute([$uname]);
            $user = $stmt->fetch();

            if ($user && password_verify($pass, $user['password'])) {
                session_regenerate_id(true);
                $_SESSION['auth_user'] = [
                    'id'          => $user['id'],
                    'name'        => $user['name'],
                    'username'    => $user['username'],
                    'role'        => $user['role'],
                    'linked_id'   => $user['linked_id'],
                    'linked_type' => $user['linked_type'],
                ];
                $db->prepare("UPDATE users SET last_login=NOW() WHERE id=?")->execute([$user['id']]);

                $next = $_GET['next'] ?? '';
                if ($next && str_starts_with(urldecode($next), '/')) {
                    header('Location: ' . BASE_URL . urldecode($next));
                } else {
                    header('Location: ' . BASE_URL . '/index.php');
                }
                exit;
            } else {
                $error = 'Invalid username or password.';
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
<title><?= $isFirstRun ? 'Setup — ' : 'Login — ' ?><?= htmlspecialchars(APP_NAME) ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
<style>
*{box-sizing:border-box}
body{background:linear-gradient(135deg,#0f172a 0%,#1e3a5f 100%);min-height:100vh;display:flex;align-items:center;justify-content:center;font-family:'Segoe UI',system-ui,sans-serif;padding:20px}
.login-wrap{width:100%;max-width:420px}
.login-card{background:#fff;border-radius:18px;padding:42px 38px;box-shadow:0 25px 60px rgba(0,0,0,.45)}
.brand-icon{width:58px;height:58px;background:#2563eb;border-radius:16px;display:flex;align-items:center;justify-content:center;color:#fff;font-size:26px;margin:0 auto 16px}
.login-title{font-size:23px;font-weight:800;color:#0f172a;text-align:center;margin-bottom:4px}
.login-sub{color:#64748b;font-size:13px;text-align:center;margin-bottom:28px}
.form-label{font-size:13px;font-weight:600;color:#374151;margin-bottom:5px}
.form-control{font-size:14px;border-color:#e2e8f0;padding:10px 14px 10px 40px;border-radius:8px}
.form-control:focus{border-color:#2563eb;box-shadow:0 0 0 3px rgba(37,99,235,.15)}
.field-wrap{position:relative}
.field-wrap i{position:absolute;left:13px;top:50%;transform:translateY(-50%);color:#94a3b8;font-size:14px;pointer-events:none}
.btn-login{background:#2563eb;border:none;padding:12px;font-size:15px;font-weight:700;border-radius:10px;letter-spacing:.3px}
.btn-login:hover{background:#1d4ed8}
.alert{border-radius:10px;font-size:13px}
.first-run-badge{background:#fef3c7;color:#92400e;border-radius:8px;padding:10px 14px;font-size:12px;margin-bottom:20px;border:1px solid #fcd34d}
</style>
</head>
<body>
<div class="login-wrap">
    <div class="login-card">
        <div class="brand-icon"><i class="fa fa-car-side"></i></div>
        <div class="login-title"><?= $isFirstRun ? 'System Setup' : htmlspecialchars(APP_NAME) ?></div>
        <div class="login-sub"><?= $isFirstRun ? 'Create your administrator account to get started.' : 'Sign in to continue' ?></div>

        <?php if ($isFirstRun && !$setupDone): ?>
        <div class="first-run-badge"><i class="fa fa-star me-2"></i><strong>First-time setup:</strong> No admin account exists yet. Create one below.</div>
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
                <div class="field-wrap"><i class="fa fa-lock"></i>
                <input type="password" name="password" class="form-control" placeholder="At least 6 characters" required></div>
            </div>
            <div class="mb-4">
                <label class="form-label">Confirm Password <span class="text-danger">*</span></label>
                <div class="field-wrap"><i class="fa fa-lock"></i>
                <input type="password" name="password_confirm" class="form-control" placeholder="Repeat password" required></div>
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
                <div class="field-wrap"><i class="fa fa-lock"></i>
                <input type="password" name="password" class="form-control" placeholder="Enter your password" required autocomplete="current-password"></div>
            </div>
            <button type="submit" class="btn btn-login btn-primary w-100 text-white">
                <i class="fa fa-right-to-bracket me-2"></i>Sign In
            </button>
        </form>
        <?php endif; ?>
    </div>

    <p class="text-center text-white-50 mt-3" style="font-size:12px">
        <?= htmlspecialchars(APP_NAME) ?> &mdash; Car Yard Management System
    </p>
</div>
</body>
</html>

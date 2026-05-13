<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

if (isset($_SESSION['_client'])) {
    header('Location: ' . BASE_URL . '/client/index.php'); exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $pass  = $_POST['password'] ?? '';
    try {
        $db   = getDB();
        $stmt = $db->prepare("SELECT * FROM clients WHERE email=? AND portal_enabled=1 AND status='active' LIMIT 1");
        $stmt->execute([$email]);
        $client = $stmt->fetch();
        if ($client && $client['portal_password'] && password_verify($pass, $client['portal_password'])) {
            $_SESSION['_client'] = ['id'=>$client['id'],'name'=>$client['name'],'email'=>$client['email']];
            header('Location: ' . BASE_URL . '/client/index.php'); exit;
        }
        $error = 'Invalid email or password, or portal access is not enabled for your account.';
    } catch (\Throwable $e) {
        $error = 'Login error. Please try again.';
    }
}
$company = getSetting('company_name', 'Mascardi System');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Client Login — <?= e($company) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
<style>
body { font-family: 'Inter',sans-serif; background: linear-gradient(135deg,#1e3a5f 0%,#2563eb 100%); min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 20px; }
.login-card { background: #fff; border-radius: 20px; padding: 40px 36px; width: 100%; max-width: 420px; box-shadow: 0 20px 60px rgba(0,0,0,.25); }
.login-logo { width: 60px; height: 60px; background: linear-gradient(135deg,#2563eb,#1e3a5f); border-radius: 16px; display: flex; align-items: center; justify-content: center; color: #fff; font-size: 24px; margin: 0 auto 20px; }
.login-title { text-align: center; margin-bottom: 28px; }
.login-title h4 { font-weight: 700; color: #1e293b; margin-bottom: 4px; }
.login-title p { color: #64748b; font-size: 13px; margin: 0; }
.btn-login { background: linear-gradient(135deg,#2563eb,#1d4ed8); border: none; font-weight: 600; padding: 12px; border-radius: 10px; }
.btn-login:hover { background: linear-gradient(135deg,#1d4ed8,#1e40af); }
.form-control { border-radius: 10px; padding: 11px 14px; font-size: 14px; }
.form-control:focus { border-color: #2563eb; box-shadow: 0 0 0 3px rgba(37,99,235,.12); }
</style>
</head>
<body>
<div class="login-card">
    <div class="login-logo"><i class="fa fa-car-side"></i></div>
    <div class="login-title">
        <h4><?= e($company) ?></h4>
        <p>Client Portal &mdash; sign in to view your vehicles &amp; service history</p>
    </div>
    <?php if ($error): ?>
    <div class="alert alert-danger py-2 small"><i class="fa fa-circle-exclamation me-1"></i><?= e($error) ?></div>
    <?php endif; ?>
    <form method="POST">
        <div class="mb-3">
            <label class="form-label fw-medium small">Email Address</label>
            <input type="email" name="email" class="form-control" value="<?= e($_POST['email'] ?? '') ?>" required autofocus placeholder="your@email.com">
        </div>
        <div class="mb-4">
            <label class="form-label fw-medium small">Password</label>
            <input type="password" name="password" class="form-control" required placeholder="••••••••">
        </div>
        <button type="submit" class="btn btn-primary btn-login w-100">
            <i class="fa fa-right-to-bracket me-2"></i>Sign In
        </button>
    </form>
    <div class="text-center mt-4 text-muted small">
        Don't have access? Contact <strong><?= e(getSetting('company_name','us')) ?></strong> to enable your portal.
    </div>
</div>
</body>
</html>

<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/portal_auth.php';

// Already logged in → go to dashboard
if (!empty($_SESSION['portal_client']['id'])) {
    header('Location: ' . BASE_URL . '/portal/index.php');
    exit;
}

$error    = '';
$redirect = $_GET['redirect'] ?? BASE_URL . '/portal/index.php';
$timeout  = isset($_GET['timeout']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $pass  = $_POST['password'] ?? '';

    if (!$email || !$pass) {
        $error = 'Please enter your email and password.';
    } else {
        $db   = getDB();
        $stmt = $db->prepare("SELECT * FROM clients WHERE email = ? AND portal_enabled = 1 AND status = 'active' LIMIT 1");
        $stmt->execute([$email]);
        $client = $stmt->fetch();

        if ($client && $client['portal_password'] && password_verify($pass, $client['portal_password'])) {
            portalLogin($client);
            header('Location: ' . (filter_var($redirect, FILTER_VALIDATE_URL) ? $redirect : BASE_URL . '/portal/index.php'));
            exit;
        } else {
            $error = 'Invalid email or password. Contact us if you need portal access.';
        }
    }
}

$company = getSetting('company_name', APP_NAME);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Client Portal — <?= e($company) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
<style>
* { font-family: 'Inter', sans-serif; }
body { background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%); min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 1rem; }
.login-card { background: #fff; border-radius: 16px; box-shadow: 0 20px 60px rgba(0,0,0,.35); max-width: 420px; width: 100%; padding: 2.5rem; }
.login-logo { width: 56px; height: 56px; border-radius: 14px; background: #1e293b; color: #fff; display: flex; align-items: center; justify-content: center; font-size: 22px; margin: 0 auto 1rem; }
.login-title { font-size: 22px; font-weight: 700; text-align: center; margin-bottom: 4px; }
.login-sub { text-align: center; color: #64748b; font-size: 13px; margin-bottom: 1.5rem; }
.form-label { font-size: 13px; font-weight: 500; }
.form-control { border-radius: 8px; }
.btn-login { border-radius: 8px; font-weight: 600; padding: .65rem; }
.login-footer { text-align: center; margin-top: 1.25rem; font-size: 12px; color: #94a3b8; }
</style>
</head>
<body>
<div class="login-card">
    <div class="login-logo"><i class="fa fa-car-side"></i></div>
    <div class="login-title"><?= e($company) ?></div>
    <div class="login-sub">Client Portal — Sign in to view your vehicles, bookings &amp; invoices</div>

    <?php if ($timeout): ?>
    <div class="alert alert-warning py-2 small"><i class="fa fa-clock me-1"></i>Your session timed out. Please sign in again.</div>
    <?php endif; ?>

    <?php if ($error): ?>
    <div class="alert alert-danger py-2 small"><i class="fa fa-circle-exclamation me-1"></i><?= e($error) ?></div>
    <?php endif; ?>

    <form method="POST">
        <input type="hidden" name="redirect" value="<?= e($redirect) ?>">
        <div class="mb-3">
            <label class="form-label">Email Address</label>
            <input type="email" name="email" class="form-control" placeholder="your@email.com"
                   value="<?= e($_POST['email'] ?? '') ?>" required autofocus>
        </div>
        <div class="mb-4">
            <label class="form-label">Password</label>
            <input type="password" name="password" class="form-control" placeholder="Your portal password" required>
        </div>
        <button type="submit" class="btn btn-dark w-100 btn-login">
            <i class="fa fa-right-to-bracket me-2"></i>Sign In
        </button>
    </form>

    <div class="login-footer">
        Don't have access? Contact us at <?= e(getSetting('company_phone', '')) ?>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

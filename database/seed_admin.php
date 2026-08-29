<?php
/**
 * Admin seed — creates the super-admin account for the admin portal.
 *
 * Run once:
 *   CLI  : php database/seed_admin.php
 *   Browser: https://your-domain/database/seed_admin.php
 *
 * DELETE THIS FILE after running. It contains credentials in plain text.
 */

// ── Config ─────────────────────────────────────────────────────────────────
$credentials = [
    'name'     => 'Mascardi Admin',
    'username' => 'Mascardiadmin',
    'email'    => 'admin@mascardicaryard.com',
    'password' => 'Mas@123@1s',
    'role'     => 'admin',
    'status'   => 'active',
];

// ── Bootstrap ──────────────────────────────────────────────────────────────
define('SEED_RUN', true);
$root = dirname(__DIR__);
require_once $root . '/includes/functions.php';

$isCli = PHP_SAPI === 'cli';

try {
    $db = getDB();
} catch (\Throwable $e) {
    die('DB connection failed: ' . $e->getMessage() . PHP_EOL);
}

// ── Who may run this ─────────────────────────────────────────────────────────
//
// This script rewrites the super-admin password to the value hard-coded above,
// so left open to the web it is an unauthenticated account takeover: anyone who
// loads the URL owns the admin account. It still has to work for genuine
// first-time setup, though, when nobody can possibly be signed in yet — so the
// rule is: the console always; the browser only while no admin exists, or when a
// super admin is already signed in and is deliberately resetting.
if (!$isCli) {
    try {
        $admins = (int)$db->query(
            "SELECT COUNT(*) FROM users WHERE role IN ('admin','super_admin') AND status = 'active'"
        )->fetchColumn();
    } catch (\Throwable $e) {
        $admins = 1;   // cannot tell — assume set up, and refuse
    }

    if ($admins > 0) {
        requireLogin();
        if (!isSuperAdmin()) {
            http_response_code(403);
            exit('An admin account already exists. Only a signed-in super admin may reset it, '
               . 'or run it from the cPanel terminal: php database/seed_admin.php');
        }

        // Being signed in is not the same as meaning to do this. A bare GET that
        // rewrites the password can be fired by anything that makes the browser
        // fetch a URL, so require a posted confirmation carrying the CSRF token.
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            seedAdminConfirmPage($credentials['username']);
        }
        verifyCsrf();
    }
}

function seedAdminConfirmPage(string $username): void
{
    $t = htmlspecialchars(csrfToken(), ENT_QUOTES);
    $u = htmlspecialchars($username, ENT_QUOTES);
    http_response_code(200);
    echo <<<HTML
<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow"><title>Reset the admin account</title>
<style>body{font-family:"Segoe UI",system-ui,sans-serif;background:#0d1219;color:#e8eaed;
margin:0;display:grid;place-items:center;min-height:100vh;padding:24px}
.c{max-width:520px;background:#151c26;border:1px solid #2a3442;border-radius:14px;padding:26px}
h1{font-size:19px;margin:0 0 12px}p{font-size:14px;line-height:1.7;color:#cbd5e1;margin:0 0 14px}
b{color:#fff}button{background:#b91c1c;color:#fff;border:0;border-radius:9px;padding:11px 20px;
font-size:14px;font-weight:600;cursor:pointer}a{color:#9aa4b2;font-size:13px;margin-left:14px}
</style></head><body><div class="c">
<h1>Reset the admin account?</h1>
<p>This overwrites the password for <b>$u</b> with the value hard-coded in
<b>database/seed_admin.php</b>, and that value is committed to the repository.
If the password has since been changed, this throws that change away.</p>
<p>Only continue if you are deliberately recovering the account.</p>
<form method="post"><input type="hidden" name="csrf_token" value="$t">
<button type="submit">Yes, reset it</button>
<a href="../index.php">Cancel</a></form>
</div></body></html>
HTML;
    exit;
}

// ── Seed ───────────────────────────────────────────────────────────────────
$hash = password_hash($credentials['password'], PASSWORD_DEFAULT);

try {
    $existing = $db->prepare("SELECT id, username FROM users WHERE username = ? OR role = 'admin' LIMIT 1");
    $existing->execute([$credentials['username']]);
    $row = $existing->fetch(PDO::FETCH_ASSOC);

    if ($row) {
        // Update existing admin record
        $db->prepare(
            "UPDATE users
             SET name=?, username=?, email=?, password=?, role='admin', status='active'
             WHERE id=?"
        )->execute([
            $credentials['name'],
            $credentials['username'],
            $credentials['email'],
            $hash,
            $row['id'],
        ]);
        $action = "UPDATED (ID #{$row['id']}, was username: {$row['username']})";
    } else {
        // Insert fresh admin
        $db->prepare(
            "INSERT INTO users (name, username, email, password, role, status)
             VALUES (?, ?, ?, ?, 'admin', 'active')"
        )->execute([
            $credentials['name'],
            $credentials['username'],
            $credentials['email'],
            $hash,
        ]);
        $action = 'CREATED (ID #' . $db->lastInsertId() . ')';
    }
} catch (\Throwable $e) {
    die('Seed failed: ' . $e->getMessage() . PHP_EOL);
}

// ── Report ─────────────────────────────────────────────────────────────────
$isCli = PHP_SAPI === 'cli';

if ($isCli) {
    echo PHP_EOL;
    echo "===========================================\n";
    echo "  Admin seed completed successfully\n";
    echo "===========================================\n";
    echo "  Action   : {$action}\n";
    echo "  Username : {$credentials['username']}\n";
    echo "  Password : {$credentials['password']}\n";
    echo "  Role     : admin\n";
    echo "===========================================\n";
    echo "  !! DELETE this file now: database/seed_admin.php !!\n";
    echo PHP_EOL;
} else {
    ?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Admin Seed</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;background:#f1f5f9;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px}
.card{background:#fff;border-radius:16px;box-shadow:0 4px 24px rgba(0,0,0,.08);padding:40px;max-width:480px;width:100%}
.icon{width:64px;height:64px;border-radius:50%;background:#dcfce7;display:flex;align-items:center;justify-content:center;margin:0 auto 20px;font-size:28px}
h1{text-align:center;font-size:20px;font-weight:700;color:#0f172a;margin-bottom:6px}
.sub{text-align:center;font-size:13.5px;color:#64748b;margin-bottom:28px}
.row{display:flex;justify-content:space-between;align-items:center;padding:10px 0;border-bottom:1px solid #f1f5f9}
.row:last-child{border-bottom:none}
.label{font-size:12.5px;color:#64748b;font-weight:500}
.val{font-size:13px;color:#0f172a;font-weight:600;font-family:monospace}
.warn{margin-top:24px;background:#fef3c7;border:1px solid #fcd34d;border-radius:10px;padding:14px 16px;font-size:13px;color:#92400e;line-height:1.5}
.warn strong{display:block;margin-bottom:4px}
.action-badge{display:inline-block;background:#dcfce7;color:#15803d;border-radius:6px;padding:3px 10px;font-size:12px;font-weight:700;margin-bottom:20px}
</style>
</head>
<body>
<div class="card">
    <div class="icon">✓</div>
    <h1>Admin Seed Complete</h1>
    <p class="sub"><span class="action-badge"><?= htmlspecialchars($action) ?></span></p>
    <div class="row"><span class="label">Name</span><span class="val"><?= htmlspecialchars($credentials['name']) ?></span></div>
    <div class="row"><span class="label">Username</span><span class="val"><?= htmlspecialchars($credentials['username']) ?></span></div>
    <div class="row"><span class="label">Password</span><span class="val"><?= htmlspecialchars($credentials['password']) ?></span></div>
    <div class="row"><span class="label">Role</span><span class="val">admin</span></div>
    <div class="row"><span class="label">Status</span><span class="val">active</span></div>
    <div class="warn">
        <strong>⚠ Security: Delete this file immediately</strong>
        Remove <code>database/seed_admin.php</code> from the server now that seeding is complete.
        It exposes the admin password in plain text.
    </div>
</div>
</body>
</html>
<?php
}

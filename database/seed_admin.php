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
require_once $root . '/config/database.php';

try {
    $db = getDB();
} catch (\Throwable $e) {
    die('DB connection failed: ' . $e->getMessage() . PHP_EOL);
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

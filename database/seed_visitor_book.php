<?php
/**
 * Visitors Book seed — creates (or resets) the reception kiosk login.
 *
 * Staff sign in with this account once, then hand the screen to visitors. The
 * account is confined to the visitors book and can reach nothing else in the
 * system (see _confineVisitorBook in includes/auth.php), which is what makes it
 * safe to leave signed in on an unattended screen in reception.
 *
 * Run it:
 *   php database/seed_visitor_book.php
 *   php database/seed_visitor_book.php --username=reception --password='YourPass123'
 *   php database/seed_visitor_book.php --reset          (new password, same username)
 *
 * In a browser, open /database/seed_visitor_book.php while signed in as a super
 * admin. Unlike database/seed_admin.php this refuses to run for an anonymous
 * visitor — an unauthenticated URL that resets an account password is an open
 * door, and this one is reachable for as long as the file exists.
 *
 * Safe to run more than once. With no --password it generates a fresh one and
 * prints it; the password is shown only at that moment, so write it down.
 */

$root = dirname(__DIR__);
require_once $root . '/includes/functions.php';
require_once $root . '/modules/visitors/_bootstrap.php';

$isCli = PHP_SAPI === 'cli';

// ── Who may run this ─────────────────────────────────────────────────────────
if (!$isCli) {
    requireLogin();
    if (!isSuperAdmin()) {
        http_response_code(403);
        exit('Only a super admin can create the visitors book login.');
    }
}

// ── Arguments ────────────────────────────────────────────────────────────────
$args = [];
if ($isCli) {
    foreach (array_slice($argv, 1) as $a) {
        if (preg_match('/^--([a-z_]+)(?:=(.*))?$/i', $a, $m)) $args[strtolower($m[1])] = $m[2] ?? '1';
    }
} else {
    $args = array_change_key_case($_GET, CASE_LOWER);
}

$username = trim((string)($args['username'] ?? 'visitorbook'));
$display  = trim((string)($args['name']     ?? 'Visitors Book — Reception'));
$password = (string)($args['password'] ?? '');
$reset    = !empty($args['reset']);

if (!preg_match('/^[A-Za-z0-9._-]{3,50}$/', $username)) {
    exit('Username must be 3–50 characters, letters/numbers/dot/underscore/hyphen only.' . PHP_EOL);
}

/**
 * A password that can be read aloud and typed at a counter without ambiguity.
 * No 0/O or 1/l/I, because this gets written on a card and handed to whoever is
 * opening up in the morning.
 */
function vbGeneratePassword(): string
{
    $words = ['Desk','Front','Guest','Lobby','Visit','Entry','Foyer','Badge','Sign','Book'];
    $chars = 'ABCDEFGHJKMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789';
    $w = $words[random_int(0, count($words) - 1)] . $words[random_int(0, count($words) - 1)];
    $tail = '';
    for ($i = 0; $i < 4; $i++) $tail .= $chars[random_int(0, strlen($chars) - 1)];
    return $w . '-' . $tail;
}

$generated = false;
if ($password === '') {
    $password  = vbGeneratePassword();
    $generated = true;
} elseif (strlen($password) < 8) {
    exit('Password must be at least 8 characters.' . PHP_EOL);
}

// ── Seed ─────────────────────────────────────────────────────────────────────
try {
    $db = getDB();
} catch (\Throwable $e) {
    exit('Database connection failed: ' . $e->getMessage() . PHP_EOL);
}

// The tables and the role itself have to exist before an account can use them.
visitorsMigrate($db);

// Attempt the widening, but do not abort on it. Whether the role is usable is
// decided further down by reading back what the database actually stored, which
// is the real test — bailing out on this check alone previously stopped the seed
// on installations where the widening was unnecessary in the first place.
$roleDetail = '';
$roleWidened = ensureUserRole('visitor_book', $roleDetail);

$colType = '(unknown)';
try {
    $c = $db->query("SHOW COLUMNS FROM users LIKE 'role'")->fetch(PDO::FETCH_ASSOC);
    if ($c) $colType = trim((string)$c['Type']);
} catch (\Throwable $_) {}

$hash   = password_hash($password, PASSWORD_DEFAULT);
$action = '';
$userId = 0;

try {
    // Match on username first, then on the role, so re-running finds the account
    // even if it was created under a different name.
    $st = $db->prepare("SELECT id, username FROM users WHERE username = ? LIMIT 1");
    $st->execute([$username]);
    $row = $st->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        $st = $db->prepare("SELECT id, username FROM users WHERE role = 'visitor_book' ORDER BY id LIMIT 1");
        $st->execute();
        $row = $st->fetch(PDO::FETCH_ASSOC);
    }

    if ($row) {
        $db->prepare("UPDATE users SET name = ?, username = ?, password = ?,
                      role = 'visitor_book', status = 'active' WHERE id = ?")
           ->execute([$display, $username, $hash, (int)$row['id']]);
        $userId = (int)$row['id'];
        $action = $row['username'] === $username
                ? "password reset for existing account #{$userId}"
                : "existing account #{$userId} renamed from '{$row['username']}' and password reset";
    } else {
        $db->prepare("INSERT INTO users (name, username, email, password, role, status, created_at)
                      VALUES (?,?,?,?, 'visitor_book', 'active', NOW())")
           ->execute([$display, $username, $username . '@reception.local', $hash]);
        $userId = (int)$db->lastInsertId();
        $action = "created account #{$userId}";
    }

    // Prove the role survived the write. MySQL strict mode is off here, so an
    // ENUM that did not accept the value would have silently stored '' — an
    // account with a blank role can reach nothing at all, and the failure would
    // otherwise only show up when someone tried to sign in.
    $stored = (string)$db->query("SELECT role FROM users WHERE id = {$userId}")->fetchColumn();
    if ($stored !== 'visitor_book') {
        $msg = "The account was created, but the database would not store its role.\n\n"
             . "  Stored role : '" . $stored . "' (expected 'visitor_book')\n"
             . "  Column type : " . $colType . "\n"
             . "  Widening    : " . ($roleWidened ? 'reported success' : 'FAILED') . "\n"
             . "  Detail      : " . ($roleDetail !== '' ? $roleDetail : '(none)') . "\n\n"
             . "The usual cause is that the database user cannot run ALTER TABLE, which is\n"
             . "common on shared hosting. Run this once in phpMyAdmin (SQL tab), then reload\n"
             . "this page — it appends the role without disturbing the existing ones:\n\n"
             . "  ALTER TABLE users MODIFY COLUMN role\n"
             . "    ENUM('admin','general_manager','manager','supervisor','finance_manager',\n"
             . "         'accountant','cashier','sales_manager','sales_officer','sales_person',\n"
             . "         'customer_relations','receptionist','workshop_manager','mechanic',\n"
             . "         'driver','inventory_manager','procurement_officer','hr_manager',\n"
             . "         'super_admin','visitor_book')\n"
             . "    NOT NULL DEFAULT 'sales_person';\n\n"
             . "Check that list against your own column first (SHOW COLUMNS FROM users LIKE\n"
             . "'role') and keep every value it already has — dropping one blanks the role of\n"
             . "every account holding it.\n";
        if ($isCli) exit(PHP_EOL . $msg . PHP_EOL);
        http_response_code(500);
        exit('<pre style="font:13px/1.6 ui-monospace,Consolas,monospace;background:#151c26;'
           . 'color:#e8eaed;padding:22px;margin:0;min-height:100vh;white-space:pre-wrap">'
           . htmlspecialchars($msg) . '</pre>');
    }
} catch (\Throwable $e) {
    exit('Seed failed: ' . $e->getMessage() . PHP_EOL);
}

// On the command line there is no request to derive a host from, so BASE_URL
// falls back to localhost. Printing that as though it were the address to visit
// would just send someone to the wrong place, so the CLI report shows the path
// and lets the reader supply their own domain.
$loginUrl = $isCli ? '/login.php' : rtrim(BASE_URL, '/') . '/login.php';

// ── Report ───────────────────────────────────────────────────────────────────
if ($isCli) {
    $line = str_repeat('=', 56);
    echo PHP_EOL, $line, PHP_EOL;
    echo "  Visitors Book login ready", PHP_EOL;
    echo $line, PHP_EOL;
    echo "  Action    : {$action}", PHP_EOL;
    echo "  Username  : {$username}", PHP_EOL;
    echo "  Password  : {$password}", PHP_EOL;
    echo "  Sign in at: https://your-domain{$loginUrl}", PHP_EOL;
    echo "  Role       : visitor_book — " . ($roleDetail !== '' ? $roleDetail : 'ok'), PHP_EOL;
    echo $line, PHP_EOL;
    if ($generated) {
        echo "  This password is shown once. Write it down now.", PHP_EOL;
    }
    echo "  Sign in with it at reception, then leave the visitors book open.", PHP_EOL;
    echo "  The account cannot reach any other part of the system.", PHP_EOL;
    echo PHP_EOL;
    exit;
}
?><!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Visitors Book login</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:"Segoe UI",system-ui,-apple-system,sans-serif;background:#0d1219;color:#e8eaed;
     min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px}
.card{background:#151c26;border:1px solid #2a3442;border-radius:16px;padding:38px;max-width:520px;width:100%}
.icon{width:62px;height:62px;border-radius:50%;background:#132a1c;color:#4ade80;display:flex;
      align-items:center;justify-content:center;margin:0 auto 18px;font-size:26px}
h1{text-align:center;font-size:20px;font-weight:800;margin-bottom:6px;letter-spacing:-.3px}
.sub{text-align:center;font-size:13px;color:#9aa4b2;margin-bottom:24px}
.badge{display:inline-block;background:#1e1430;color:#c084fc;border-radius:6px;padding:4px 11px;
       font-size:11.5px;font-weight:700}
.row{display:flex;justify-content:space-between;align-items:center;gap:14px;padding:11px 0;
     border-bottom:1px solid #222b38}
.row:last-of-type{border-bottom:0}
.label{font-size:12.5px;color:#9aa4b2}
.val{font-size:14px;font-weight:700;font-family:ui-monospace,Consolas,monospace;
     word-break:break-all;text-align:right}
.pw{color:#4ade80;font-size:17px}
.note{margin-top:22px;background:#2a2210;border:1px solid #5a4a18;border-radius:10px;
      padding:13px 15px;font-size:12.5px;color:#fcd34d;line-height:1.55}
.note strong{display:block;margin-bottom:3px}
.go{display:block;margin-top:20px;text-align:center;background:#7e22ce;color:#fff;
    text-decoration:none;padding:13px;border-radius:10px;font-weight:700;font-size:14.5px}
.go:hover{background:#6b21a8}
.small{margin-top:14px;font-size:11.5px;color:#6b7688;text-align:center;line-height:1.6}
</style>
</head>
<body>
<div class="card">
    <div class="icon">&#10003;</div>
    <h1>Visitors Book login ready</h1>
    <p class="sub"><span class="badge"><?= htmlspecialchars($action) ?></span></p>

    <div class="row"><span class="label">Username</span>
        <span class="val"><?= htmlspecialchars($username) ?></span></div>
    <div class="row"><span class="label">Password</span>
        <span class="val pw"><?= htmlspecialchars($password) ?></span></div>
    <div class="row"><span class="label">Role</span>
        <span class="val">visitor_book &#10003;</span></div>
    <div class="row"><span class="label">Role column</span>
        <span class="val" style="font-size:11.5px;font-weight:400;color:#9aa4b2">
            <?= htmlspecialchars($roleDetail !== '' ? $roleDetail : 'ok') ?></span></div>

    <?php if ($generated): ?>
    <div class="note">
        <strong>&#9888; This password is shown once</strong>
        Write it down before leaving this page. Re-running this seed issues a new one;
        it cannot show you this password again.
    </div>
    <?php endif; ?>

    <a class="go" href="<?= htmlspecialchars($loginUrl) ?>">Go to the sign-in page</a>

    <p class="small">
        Sign in with these details at reception, then leave the visitors book open on the screen.
        The account is confined to the visitors book and cannot open any other part of the system,
        so it is safe to leave signed in.
    </p>
</div>
</body>
</html>

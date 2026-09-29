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

function _issueRememberToken(PDO $db, int $userId, string $username = ''): void {
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS remember_tokens (
            id         INT AUTO_INCREMENT PRIMARY KEY,
            user_id    INT NOT NULL,
            token_hash VARCHAR(64) NOT NULL,
            expires_at TIMESTAMP NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uk_token (token_hash),
            KEY        idx_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        // Purge only EXPIRED tokens — never other live tokens for this user,
        // so remember-me keeps working on their other devices/browsers too.
        $db->exec("DELETE FROM remember_tokens WHERE expires_at < NOW()");
        $token = bin2hex(random_bytes(32));
        $hash  = hash('sha256', $token);
        $db->prepare("INSERT INTO remember_tokens (user_id, token_hash, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 10 YEAR))")
           ->execute([$userId, $hash]);
        $cookieOpts = [
            'expires'  => time() + 10 * 365 * 86400,
            'path'     => '/',
            'httponly' => true,
            'samesite' => 'Lax',
            'secure'   => isset($_SERVER['HTTPS']),
        ];
        setcookie('rm_tok', $token, $cookieOpts);
        // Remember the username for form prefill (read server-side only; the
        // password itself is never stored — the browser's own password manager
        // handles that via the autocomplete attributes on the form).
        if ($username !== '') {
            setcookie('rm_user', $username, $cookieOpts);
        }
    } catch (Exception $e) {
        // Non-fatal — user just won't be remembered
    }
}

$isFirstRun = !hasAdminUser();
$error = '';
$setupDone = false;

/* The customer's side of the same door. Staff sign in with a username against
 * `users`; a buyer signs in with the email we invoice them at, against
 * `clients`. The engine is in includes/client_auth.php — everything here is
 * the handful of lines that turn a posted form into one of its calls. */
require_once __DIR__ . '/includes/client_auth.php';

// ?door=client opens on the customer side. The old client/login.php forwards
// here with it, so links already out in the world land on the right panel.
$clientSide  = ($_GET['door'] ?? '') === 'client';
$clientPost  = false;                    // was THIS request a customer form?
$clientPane  = 'signin';                 // signin | register | verify
$clientError = '';
$clientNote  = '';
$clientEmail = '';
$portalOn    = clientPortalEnabled();

// A customer already signed in has no business on the sign-in page.
if (!empty($_SESSION['_client'])) {
    header('Location: ' . BASE_URL . '/client/index.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $portalOn
    && in_array(($_POST['client_do'] ?? ''), ['signin', 'register', 'verify', 'resend'], true)) {

    $clientSide = true;
    $clientPost = true;
    $do         = (string)$_POST['client_do'];
    $clientEmail = trim((string)($_POST['email'] ?? ''));
    $db         = getDB();

    if ($do === 'signin') {
        $r = clientPortalLogin($db, $clientEmail, (string)($_POST['password'] ?? ''));
        if ($r['ok']) {
            clientPortalSignIn($r['client']);
            header('Location: ' . BASE_URL . '/client/index.php');
            exit;
        }
        $clientError = $r['error'];

    } elseif ($do === 'register' || $do === 'resend') {
        $pass  = (string)($_POST['password'] ?? '');
        $pass2 = (string)($_POST['password_confirm'] ?? '');

        if ($do === 'register' && $pass !== $pass2) {
            $clientPane  = 'register';
            $clientError = 'The two passwords do not match.';
        } else {
            $r = clientPortalStart($db, (string)($_POST['name'] ?? ''), $clientEmail,
                                   (string)($_POST['phone'] ?? ''), $pass);
            if (!$r['ok']) {
                $clientPane  = 'register';
                $clientError = $r['error'];
            } else {
                $clientPane  = 'verify';
                $clientEmail = $r['email'];
                // Said the same way whether or not that address is on file: a
                // stranger must not be able to find out who banks here by
                // watching which addresses get a different answer.
                $clientNote  = 'If we can reach that address, a 6-digit code is on its way. '
                             . 'Enter it below to finish.';
                if (!$r['sent'] && $r['error'] !== '') {
                    // The yard's own mail is broken. Say so plainly rather than
                    // leaving them waiting for a code that cannot arrive.
                    $clientError = 'We could not send the code just now. Please contact us and we will '
                                 . 'set your access up by hand.';
                }
            }
        }

    } elseif ($do === 'verify') {
        $r = clientPortalVerify($db, $clientEmail, (string)($_POST['code'] ?? ''));
        if ($r['ok']) {
            clientPortalSignIn($r['client']);
            header('Location: ' . BASE_URL . '/client/index.php');
            exit;
        }
        $clientPane  = 'verify';
        $clientError = $r['error'];
    }
}

// Which pane is SHOWING ($clientSide) and which form was SUBMITTED are two
// different questions. Reading the staff branch off the visible pane meant
// that arriving at ?door=client and then switching to Staff posted a form
// nothing would process.
/* Staff who have forgotten their password. Same shape as the customer door:
 * a code to the address on file, and nothing changes until it comes back.
 * The engine is in includes/staff_auth.php. */
require_once __DIR__ . '/includes/staff_auth.php';

$staffPane   = 'signin';   // signin | forgot | reset
$resetSignIn = '';         // username to prefill after a successful reset
$staffWho   = '';          // the username or address they typed
$resetError = '';
$resetNote  = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && in_array(($_POST['staff_do'] ?? ''), ['forgot', 'reset'], true)) {

    $clientPost = true;            // not a sign-in; keep the sign-in branch off
    $clientSide = false;           // but this is the staff side of the door
    $staffWho   = trim((string)($_POST['who'] ?? ''));
    $db         = getDB();

    if ($_POST['staff_do'] === 'forgot') {
        $r = staffResetStart($db, $staffWho);
        if (!$r['ok']) {
            $staffPane  = 'forgot';
            $resetError = $r['error'];
        } else {
            $staffPane = 'reset';
            // Said the same way whether the account exists, has no address on
            // file, or the mail itself failed. Anything more specific is a way
            // to find out who works here, or which accounts cannot be
            // recovered and are therefore worth attacking directly.
            $resetNote = 'If that account exists and has an email address on file, a 6-digit '
                       . 'code is on its way. Enter it below with your new password. '
                       . 'Nothing arriving? Ask an administrator — they can set one for you.';
        }
    } else {
        $r = staffResetComplete($db, $staffWho, (string)($_POST['code'] ?? ''),
                                (string)($_POST['password'] ?? ''),
                                (string)($_POST['password_confirm'] ?? ''));
        if ($r['ok']) {
            // Straight back to a clean sign-in form, with the username filled
            // in — they have just proved it is theirs.
            $staffPane   = 'signin';
            $resetSignIn = $r['username'];
            $resetNote   = 'Password changed. Sign in with it below.';
        } else {
            $staffPane  = 'reset';
            $resetError = $r['error'];
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$clientPost) {

    // Whatever ?door said, a staff form was posted, so show the staff pane —
    // otherwise its error message renders behind a hidden panel.
    $clientSide = false;

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
                            'location_id' => $user['location_id'] ?? null,
                        ];
                        $_SESSION['last_activity']    = time();
                        $_SESSION['sess_regenerated'] = time();

                        $db->prepare("UPDATE users SET last_login=NOW() WHERE id=?")->execute([$user['id']]);

                        // Remember-me cookie
                        if (!empty($_POST['remember_me'])) {
                            _issueRememberToken($db, (int)$user['id'], $user['username']);
                        } else {
                            // Box unchecked — forget this browser
                            setcookie('rm_tok',  '', time() - 3600, '/', '', isset($_SERVER['HTTPS']), true);
                            setcookie('rm_user', '', time() - 3600, '/', '', isset($_SERVER['HTTPS']), true);
                        }

                        // The visitors-book account has no dashboard and no menu
                        // to reach one from, so it goes straight to the kiosk —
                        // ignoring ?next, which would only land it on a page it
                        // has no access to.
                        if ($user['role'] === 'visitor_book') {
                            header('Location: ' . BASE_URL . '/visitorbook/index.php');
                            exit;
                        }

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
                    $_SESSION['auth_user'] = ['id' => $user['id'], 'name' => $user['name'], 'username' => $user['username'], 'role' => $user['role'], 'linked_id' => $user['linked_id'], 'linked_type' => $user['linked_type'], 'location_id' => $user['location_id'] ?? null];
                    $_SESSION['last_activity'] = time();
                    $db->prepare("UPDATE users SET last_login=NOW() WHERE id=?")->execute([$user['id']]);
                    if (!empty($_POST['remember_me'])) {
                        _issueRememberToken($db, (int)$user['id'], $user['username']);
                    }
                    header('Location: ' . BASE_URL . ($user['role'] === 'visitor_book'
                        ? '/visitorbook/index.php' : '/index.php')); exit;
                } else {
                    $error = 'Invalid username or password.';
                }
            }
        }
    }
}

// Show the animated welcome intro only on a fresh visit (not after a
// failed login POST, not during first-run setup, not on session timeout).
$showIntro = $_SERVER['REQUEST_METHOD'] === 'GET' && !$isFirstRun && !$setupDone && !$error && !isset($_GET['timeout']);

// Username remembered from a previous "Remember me" login (never the password —
// that stays with the browser's own password manager via autocomplete). A reset
// that has just finished wins over the cookie: they proved that username is
// theirs a moment ago, which the cookie cannot say.
$rememberedUser = $resetSignIn !== '' ? $resetSignIn : trim($_COOKIE['rm_user'] ?? '');

/* The photograph for the picture side.
 *
 * The page used to be one card floating in the middle of a gradient. It is now
 * split: the photograph holds one side and the form the other, so every word
 * sits on solid colour instead of on a picture that had to be dimmed until it
 * was barely worth showing.
 *
 * Preference order, first hit wins: a photo uploaded in Settings, a file
 * dropped into assets/images/login-bg.*, and then the showroom hero that ships
 * with the system. If none of them is there the panel is plain navy and the
 * page still reads properly — a missing photo should degrade, not break.
 */
$loginBg = '';
$__bgSet = trim((string)getSetting('login_background', ''));
if ($__bgSet !== '' && is_file(BASE_PATH . '/assets/images/' . basename($__bgSet))) {
    $loginBg = BASE_URL . '/assets/images/' . rawurlencode(basename($__bgSet))
             . '?v=' . filemtime(BASE_PATH . '/assets/images/' . basename($__bgSet));
} else {
    foreach (['login-bg.jpg', 'login-bg.jpeg', 'login-bg.png', 'login-bg.webp', 'hero.webp'] as $__try) {
        if (is_file(BASE_PATH . '/assets/images/' . $__try)) {
            $loginBg = BASE_URL . '/assets/images/' . $__try
                     . '?v=' . filemtime(BASE_PATH . '/assets/images/' . $__try);
            break;
        }
    }
}

$companyName    = getSetting('company_name', 'Mascardi');
$companyPhone   = trim((string)getSetting('company_phone', ''));
$companyEmail   = trim((string)getSetting('company_email', ''));

// Mokoto nameplate font — self-hosted. Drop the file at assets/fonts/mokoto.woff2
// (or .woff/.ttf/.otf) and it's picked up automatically, no code change needed.
// Until then the nameplate falls back to Orbitron.
$mokotoFile = null;
foreach (['woff2', 'woff', 'ttf', 'otf'] as $__ext) {
    if (is_file(BASE_PATH . '/assets/fonts/mokoto.' . $__ext)) { $mokotoFile = 'mokoto.' . $__ext; break; }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= $isFirstRun ? 'Setup — ' : 'Sign In — ' ?><?= htmlspecialchars(APP_NAME) ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<!-- Orbitron approximates the Mokoto display look; Inter for body -->
<link href="https://fonts.googleapis.com/css2?family=Orbitron:wght@600;700;800;900&family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<?php if ($mokotoFile): ?>
<style>
@font-face {
    font-family: 'Mokoto';
    src: url('<?= BASE_URL ?>/assets/fonts/<?= htmlspecialchars($mokotoFile) ?>') format('<?= str_ends_with($mokotoFile, '.woff2') ? 'woff2' : (str_ends_with($mokotoFile, '.woff') ? 'woff' : (str_ends_with($mokotoFile, '.ttf') ? 'truetype' : 'opentype')) ?>');
    font-weight: 400 900;
    font-display: swap;
}
</style>
<?php endif; ?>
<meta name="color-scheme" content="dark">
<meta name="theme-color" content="#0a0f1e">
<link rel="preload" as="image" href="<?= BASE_URL ?>/IMG_4604.webp">
<style>
*, *::before, *::after { box-sizing: border-box; }
body {
    background: #0b1220;
    min-height: 100vh;
    font-family: 'Inter', 'Segoe UI', system-ui, sans-serif;
    margin: 0;
}

/* ── The split ───────────────────────────────────────────────────
   One column on a phone, with the picture reduced to a band across the top;
   two side by side from tablet width up. The form column has a floor of 430px
   so it never squeezes into something unusable on the way there. */
.auth{ min-height:100vh; min-height:100dvh; display:grid; grid-template-columns:1fr; }
@media (min-width:900px){
    .auth{ grid-template-columns:minmax(0,1.05fr) minmax(430px,.95fr); }
}
@media (min-width:1500px){
    .auth{ grid-template-columns:minmax(0,1.2fr) 560px; }
}

/* ── The picture side ── */
.auth-stage{
    position:relative; display:flex; flex-direction:column; justify-content:space-between;
    gap:22px; padding:26px; min-height:190px; overflow:hidden;
    background:#0b1220 center/cover no-repeat;
}
@media (min-width:900px){ .auth-stage{ padding:46px; min-height:100vh; min-height:100dvh; } }

/* Dark enough for white text to sit on it, and no darker — the point of
   splitting the page was to stop dimming the photograph into a blur. */
.auth-stage::after{
    content:''; position:absolute; inset:0;
    background:linear-gradient(115deg, rgba(8,16,34,.86) 0%, rgba(8,16,34,.58) 55%, rgba(8,16,34,.38) 100%);
}
.auth-stage > *{ position:relative; z-index:1; }

.auth-inner{ max-width:480px; }
.auth-lockup{ display:flex; align-items:center; gap:13px; margin-bottom:18px; }
/* Standing on its own, the name carries the panel, so it is set as a
   nameplate rather than as a label beside a mark. */
.auth-company{
    font-size:28px; font-weight:800; color:#fff; letter-spacing:.14em;
    text-transform:uppercase; line-height:1.15;
    font-family:'Orbitron','Inter',system-ui,sans-serif;
    text-shadow:0 0 22px rgba(59,130,246,.45);
}
@media (min-width:900px){ .auth-company{ font-size:34px; } }
.auth-tagline{
    color:rgba(255,255,255,.82); font-size:15px; line-height:1.6; margin:0 0 22px; max-width:30em;
}
.auth-points{ list-style:none; padding:0; margin:0; display:grid; gap:11px; }
.auth-points li{
    display:flex; align-items:flex-start; gap:11px;
    color:rgba(255,255,255,.78); font-size:13.5px; line-height:1.5;
}
.auth-points i{ color:#60a5fa; margin-top:2px; width:16px; text-align:center; flex:none; }
.auth-back{
    align-self:flex-start; color:rgba(255,255,255,.72); font-size:13px; font-weight:600;
    text-decoration:none; display:inline-flex; align-items:center; gap:8px;
    padding:8px 14px; border:1px solid rgba(255,255,255,.2);
    border-radius:9px; background:rgba(255,255,255,.07); transition:all .15s;
}
.auth-back:hover{ color:#fff; border-color:rgba(255,255,255,.4); background:rgba(255,255,255,.14); }
@media (max-width:899px){ .auth-back, .auth-points{ display:none; } }

/* ── The form side ── */
.auth-panel{
    display:flex; align-items:center; justify-content:center;
    padding:30px 20px 38px; background:#111c33;
}
@media (min-width:900px){ .auth-panel{ padding:48px 44px; } }
.auth-form{ width:100%; }

/* Repeated for the phone, where the picture side is only a band and the
   lockup on it has been pushed off the top. */
.auth-smallbrand{
    display:flex; align-items:center; gap:10px; margin-bottom:22px;
    color:#e6edf7; font-weight:800; font-size:16px;
}
.auth-smallbrand span{ letter-spacing:.1em; text-transform:uppercase; }
@media (min-width:900px){ .auth-smallbrand{ display:none; } }

.auth-foot{
    margin-top:26px; padding-top:18px; border-top:1px solid rgba(148,163,184,.16);
    text-align:center;
}
.auth-contact{ display:flex; flex-wrap:wrap; justify-content:center; gap:14px; margin-bottom:9px; }
.auth-contact a{
    color:#94a3b8; font-size:12.5px; text-decoration:none;
    display:inline-flex; align-items:center; gap:6px;
}
.auth-contact a:hover{ color:#e6edf7; }
.auth-legal{ color:rgba(148,163,184,.65); font-size:11.5px; }
.auth-backsm{
    display:inline-flex; align-items:center; gap:7px; margin-top:12px;
    color:#60a5fa; font-size:12.5px; text-decoration:none; font-weight:600;
}
@media (min-width:900px){ .auth-backsm{ display:none; } }

.login-card{ background:transparent; border:0; padding:0; box-shadow:none; }
.login-title { font-size: 23px; font-weight: 800; color: #0f172a; text-align: center; margin-bottom: 4px; letter-spacing: -.4px; }
.login-sub { color: #64748b; font-size: 13px; text-align: center; margin-bottom: 28px; }
.form-label { font-size: 13px; font-weight: 600; color: #374151; margin-bottom: 5px; }
.form-control { font-size: 14px; border-color: #e2e8f0; padding: 10px 40px; border-radius: 10px; transition: border-color .15s, box-shadow .15s; }
.form-control:focus { border-color: #2563eb; box-shadow: 0 0 0 3px rgba(37,99,235,.15); outline: none; }
.field-wrap { position: relative; }
.field-wrap > i:first-child { position: absolute; left: 13px; top: 50%; transform: translateY(-50%); color: #94a3b8; font-size: 14px; pointer-events: none; }
.password-toggle { position: absolute; right: 13px; top: 50%; transform: translateY(-50%); color: #94a3b8; cursor: pointer; z-index: 10; transition: color .15s; background: none; border: none; padding: 0; font-size: 14px; display: flex; align-items: center; }
.password-toggle:hover { color: #2563eb; }
.form-check-input { width: 16px; height: 16px; margin-top: 2px; cursor: pointer; accent-color: #2563eb; border-color: #cbd5e1; }
.form-check-input:checked { background-color: #2563eb; border-color: #2563eb; }
.form-check-label { font-size: 13px; color: #64748b; cursor: pointer; user-select: none; }
.btn-login { background: linear-gradient(135deg,#2563eb,#1d4ed8); border: none; padding: 13px; font-size: 15px; font-weight: 700; border-radius: 12px; letter-spacing: .3px; transition: box-shadow .15s, transform .1s; }
.btn-login:hover { box-shadow: 0 6px 20px rgba(37,99,235,.45); transform: translateY(-1px); }
.first-run-badge { background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 10px; padding: 10px 14px; font-size: 13px; color: #1d4ed8; margin-bottom: 18px; }

/* ── The two doors ──────────────────────────────────────────────────────────
   Staff sign in with a username, customers with the email we invoice them at.
   They are different accounts in different tables, so the choice is made
   before anything is typed rather than guessed from what was. */
.door-switch{
    display:grid; grid-template-columns:1fr 1fr; gap:4px; padding:4px;
    background:rgba(255,255,255,.05); border:1px solid rgba(148,163,184,.18);
    border-radius:12px; margin-bottom:20px;
}
.door-switch button{
    border:0; background:transparent; color:#94a3b8; cursor:pointer;
    padding:9px 6px; border-radius:9px; font-size:13.5px; font-weight:600;
    letter-spacing:.2px; transition:background .16s, color .16s;
    display:flex; align-items:center; justify-content:center; gap:7px;
}
.door-switch button:hover{ color:#e6edf7; }
.door-switch button.on{
    background:linear-gradient(135deg,#2563eb,#1d4ed8); color:#fff;
    box-shadow:0 4px 14px rgba(37,99,235,.35);
}
.door-pane[hidden]{ display:none; }

/* The code boxes on the verify step. Big and monospaced, because a code read
   off a phone is transcribed one character at a time. */
.code-input{
    letter-spacing:.7em; text-align:center; font-size:22px; font-weight:700;
    font-family:'Orbitron',ui-monospace,monospace; padding-left:.7em;
}
.door-alt{
    margin-top:18px; padding-top:16px; border-top:1px solid rgba(148,163,184,.16);
    text-align:center; font-size:13px; color:#94a3b8;
}
.door-alt a{ color:#60a5fa; text-decoration:none; font-weight:600; }
.door-alt a:hover{ text-decoration:underline; }
.sent-to{
    background:rgba(37,99,235,.10); border:1px solid rgba(59,130,246,.28);
    border-radius:10px; padding:10px 13px; font-size:13px; color:#cbd5e1;
    margin-bottom:16px;
}
.sent-to strong{ color:#e6edf7; }
</style>

<!-- ═══════════════════ DARK THEME + WELCOME INTRO ═══════════════════ -->
<style>
:root{
    --neon-g:#22c55e; --neon-b:#3b82f6; --neon-r:#ef4444; --neon-y:#f59e0b;
    --brand-blue-light:#60a5fa;
    --ink:#0a0f1e;
}
body{
    background:
        radial-gradient(1000px 560px at 12% -8%, rgba(59,130,246,.20), transparent 60%),
        radial-gradient(900px 600px at 96% 108%, rgba(34,197,94,.12), transparent 60%),
        linear-gradient(120deg, rgba(5,7,15,.88) 0%, rgba(8,12,24,.62) 42%, rgba(11,18,38,.86) 100%),
        url('<?= BASE_URL ?>/IMG_4604.webp') center center / cover no-repeat fixed,
        #05070f !important;
    color:#e6edf7;
}
body::before{
    background-image:
        linear-gradient(rgba(255,255,255,.028) 1px, transparent 1px),
        linear-gradient(90deg, rgba(255,255,255,.028) 1px, transparent 1px) !important;
    background-size:54px 54px !important;
    mask-image:radial-gradient(circle at 50% 45%, #000 55%, transparent 100%);
    -webkit-mask-image:radial-gradient(circle at 50% 45%, #000 55%, transparent 100%);
}

/* ── Login card → dark glass with neon rim ─────────────────────────── */
/* The card used to float in the middle of the page as dark glass with a neon
   rim, tilted in 3D. With the page split, the form has a side of its own and
   the panel IS the surface, so a second bordered box inside it would only be a
   box inside a box. What is kept is the arrival: the form still eases in as
   the welcome animation steps aside, because that join should not be abrupt. */
/* The form sits on its own lit panel inside the picture/form split: a dark
   surface with a neon rim turning slowly around it, and a soft aura behind.
   The rim is a rotating conic gradient masked down to the border itself, so it
   is a line of moving colour rather than a glowing box — a sharp edge reads as
   deliberate where a halo reads as a mistake. */
.login-card{
    position:relative; overflow:hidden;
    background:rgba(13,22,42,.86) !important;
    -webkit-backdrop-filter:blur(18px); backdrop-filter:blur(18px);
    border:1px solid rgba(59,130,246,.22) !important;
    border-radius:22px;
    padding:34px 30px;
    box-shadow:0 26px 70px rgba(0,0,0,.55), inset 0 1px 0 rgba(255,255,255,.05) !important;
}
@media (min-width:900px){ .login-card{ padding:38px 34px; } }
/* ── 3D tilt + animated neon glow ──────────────────────────────────── */
.login-wrap{ width:100%; max-width:410px; position:relative; }
.card-3d{ position:relative; }
/* Above the aura, which sits behind the panel and bleeds out past its edge. */
.login-card > *{ position:relative; z-index:1; }
.auth-form{ opacity:0; }
body.no-intro .auth-form,
body.has-intro .login-stage.show .auth-form{
    animation:cardIn .8s cubic-bezier(.17,.75,.28,1) .1s backwards;
    opacity:1;
}
@keyframes cardIn{
    0%{ opacity:0; transform:translateY(42px) rotateX(16deg) scale(.955); }
    100%{ opacity:1; transform:translateY(0) rotateX(0) scale(1); }
}
/* Shake after a failed sign-in, once the card has settled */
.auth-form.err{
    animation:cardIn .8s cubic-bezier(.17,.75,.28,1) .05s backwards,
              shakeX .5s ease .9s;
}
@keyframes shakeX{
    0%,100%{ transform:translateX(0); }
    20%,60%{ transform:translateX(-9px); }
    40%,80%{ transform:translateX(9px); }
}

/* Soft neon aura breathing around the card (sits behind it in 3D space) */
.neon-aura{
    position:absolute; inset:-18px; border-radius:40px; z-index:0;
    overflow:hidden; filter:blur(34px); opacity:.42;
    pointer-events:none; transition:opacity .5s ease;
}
.neon-aura::before{
    content:''; position:absolute; left:50%; top:50%;
    width:240%; height:240%; margin:-120% 0 0 -120%;
    background:conic-gradient(#22d3ee, #3b82f6, #8b5cf6, #d946ef, #3b82f6, #22d3ee);
    animation:neonSpin 7s linear infinite;
}
/* Brighter while somebody is actually filling the form in. */
.login-wrap:hover .neon-aura,
.login-wrap:focus-within .neon-aura{ opacity:.7; }

/* Crisp neon ring tracing the card edge — same rotating gradient */
.neon-ring{
    position:absolute; inset:0; border-radius:22px; padding:1.4px;
    z-index:2; pointer-events:none; overflow:hidden;
    -webkit-mask:linear-gradient(#fff 0 0) content-box, linear-gradient(#fff 0 0);
    -webkit-mask-composite:xor; mask-composite:exclude;
}
.neon-ring::before{
    content:''; position:absolute; left:50%; top:50%;
    width:240%; height:240%; margin:-120% 0 0 -120%;
    background:conic-gradient(#22d3ee, #3b82f6, #8b5cf6, #d946ef, #3b82f6, #22d3ee);
    animation:neonSpin 7s linear infinite;
}
@keyframes neonSpin{ to{ transform:rotate(360deg); } }

@media (prefers-reduced-motion: reduce){
    body.no-intro .auth-form,
    body.has-intro .login-stage.show .auth-form,
    .auth-form.err{ animation:none; opacity:1; }
}
.login-title{ color:#f8fafc !important; }
.login-sub{ color:#93a3bb !important; }
.form-label{ color:#cbd5e1 !important; }
.form-control{
    background:rgba(255,255,255,.045) !important;
    border-color:rgba(148,163,184,.28) !important;
    color:#e6edf7 !important;
}
.form-control::placeholder{ color:#5b6b85 !important; }
.form-control:focus{
    border-color:var(--neon-b) !important;
    background:rgba(255,255,255,.07) !important;
    box-shadow:0 0 0 3px rgba(59,130,246,.22), 0 0 16px rgba(59,130,246,.18) !important;
}
.field-wrap > i:first-child{ color:#5b6b85; }
.password-toggle{ color:#5b6b85; }
.password-toggle:hover{ color:var(--neon-b); }
.form-check-label{ color:#93a3bb; }
.first-run-badge{
    background:rgba(59,130,246,.12) !important;
    border-color:rgba(59,130,246,.3) !important;
    color:#93c5fd !important;
}
.alert-danger{ background:rgba(239,68,68,.14); border-color:rgba(239,68,68,.35); color:#fca5a5; }
.alert-warning{ background:rgba(245,158,11,.14); border-color:rgba(245,158,11,.35); color:#fcd34d; }
.alert-success{ background:rgba(34,197,94,.14); border-color:rgba(34,197,94,.35); color:#86efac; }

/* ── Login stage slide-in ──────────────────────────────────────────── */
.login-stage{ opacity:1; transform:none; }
body.has-intro .login-stage{
    opacity:0;
    transition:opacity .9s ease;
}
body.has-intro .login-stage.show{ opacity:1; transform:none; }

/* ═══════════════════ INTRO OVERLAY — silent luxury reveal ═══════════════════ */
#introOverlay{
    position:fixed; inset:0; z-index:9999;
    display:flex; flex-direction:column; align-items:center; justify-content:center;
    gap:30px; padding:24px; overflow:hidden;
    background:#060606;
    transition:opacity 1s ease, visibility 1s;
}
#introOverlay.done{ opacity:0; visibility:hidden; pointer-events:none; }

/* Faint centre glow + hairline grid, fading out at the edges */
#introOverlay .intro-grid{
    position:absolute; inset:0; pointer-events:none;
    background:radial-gradient(720px 440px at 50% 42%, rgba(255,255,255,.055), transparent 70%);
}
#introOverlay .intro-grid::after{
    content:''; position:absolute; inset:0;
    background-image:
        linear-gradient(rgba(255,255,255,.024) 1px, transparent 1px),
        linear-gradient(90deg, rgba(255,255,255,.024) 1px, transparent 1px);
    background-size:64px 64px;
    mask-image:radial-gradient(circle at 50% 45%, #000 35%, transparent 82%);
    -webkit-mask-image:radial-gradient(circle at 50% 45%, #000 35%, transparent 82%);
}

/* Wordmark — letters rise, unblur and settle with a calm stagger.
   Plain spans (single text node each), centered flex row, capped width:
   this layout is bulletproof across browsers and never spills the viewport. */
.brand-name{
    position:relative; z-index:2; margin:0 auto;
    font-family:<?= $mokotoFile ? "'Mokoto'," : '' ?>'Orbitron','Inter',sans-serif; font-weight:900;
    font-size:clamp(34px, 8.5vw, 96px);
    letter-spacing:.3em; text-indent:.3em; /* indent balances the trailing tracking */
    line-height:1;
    display:flex; flex-wrap:wrap; justify-content:center; align-items:baseline;
    width:100%; max-width:94vw;
    text-align:center;
    color:#ffffff;
}
.brand-name span{
    display:inline-block; opacity:0;
    transform:translateY(26px);
    filter:blur(12px);
    text-shadow:0 0 34px rgba(255,255,255,.18);
    will-change:transform,opacity,filter;
}
.brand-name span.in{ animation:letterIn 1.15s cubic-bezier(.16,.68,.24,1) forwards; }
@keyframes letterIn{
    0%{ opacity:0; transform:translateY(26px); filter:blur(12px); }
    55%{ opacity:1; }
    100%{ opacity:1; transform:translateY(0); filter:blur(0); }
}
/* Light glint that sweeps letter-by-letter once the name has settled */
.brand-name span.glint{
    text-shadow:0 0 42px rgba(255,255,255,.85), 0 0 14px rgba(255,255,255,.6);
    transition:text-shadow .3s ease;
}

.brand-underline{
    position:relative; z-index:2; height:1px; width:0;
    margin-top:-2px;
    background:linear-gradient(90deg, transparent, rgba(255,255,255,.8), transparent);
    transition:width 1.3s cubic-bezier(.16,.7,.2,1) .15s;
}
#introOverlay.reveal .brand-underline{ width:min(520px,78vw); }

/* Tagline — fades up while its tracking settles into place */
.tagline{
    position:relative; z-index:2; min-height:1.6em;
    font-family:'Inter',sans-serif; font-weight:500;
    font-size:clamp(11px, 2vw, 15px); letter-spacing:.42em;
    text-transform:uppercase; text-align:center;
    color:rgba(255,255,255,.62);
    padding:0 12px;
    opacity:0; transform:translateY(14px);
    transition:opacity 1.2s ease .25s, transform 1.2s ease .25s, letter-spacing 1.6s ease .25s;
}
#introOverlay.reveal .tagline{ opacity:1; transform:none; letter-spacing:.24em; }

/* Skip */
.intro-skip{
    position:fixed; top:22px; right:24px; z-index:10000;
    font-size:10.5px; font-weight:600; letter-spacing:.16em; text-transform:uppercase;
    color:rgba(255,255,255,.55);
    background:none; border:1px solid rgba(255,255,255,.22);
    border-radius:2px; padding:9px 18px; cursor:pointer;
    display:flex; align-items:center; gap:8px; transition:all .25s ease;
}
.intro-skip:hover{ color:#fff; border-color:rgba(255,255,255,.6); }

@media (prefers-reduced-motion: reduce){
    .brand-name span, .brand-name span.in{ animation:none; opacity:1; transform:none; filter:none; }
    .tagline{ transition:opacity .3s ease; }
    #introOverlay, .login-stage{ transition:opacity .3s ease; }
}
/* Fixed backgrounds are janky on mobile — pin to scroll and reframe */
@media (max-width:768px){
    body{ background-attachment:scroll, scroll, scroll, scroll, scroll !important;
          background-position:12% -8%, 96% 108%, center, 60% center, center !important; }
}
@media (max-width:560px){
    .intro-skip{ top:16px; right:16px; }
}
</style>
</head>
<body class="<?= $showIntro ? 'has-intro' : 'no-intro' ?>">

<?php if ($showIntro): ?>
<!-- ═══════════════════ WELCOME INTRO OVERLAY ═══════════════════ -->
<div id="introOverlay">
    <div class="intro-grid"></div>

    <h1 id="brandName" class="brand-name" data-name="MASCARDI" aria-label="MASCARDI"></h1>
    <div class="brand-underline"></div>
    <div id="tagline" class="tagline">Welcome to Mascardi &mdash; Home of Luxury Cars</div>

    <button type="button" id="skipIntro" class="intro-skip">
        Skip <i class="fa fa-forward" style="font-size:9px"></i>
    </button>
</div>
<?php endif; ?>

<!-- ═══════════════════ LOGIN STAGE ═══════════════════ -->
<div class="login-stage" id="loginStage">

<div class="auth">

  <!-- The photograph, and the company over it. Hidden from screen readers:
       it is decoration plus a repeat of what the form side already says. -->
  <section class="auth-stage"<?= $loginBg !== '' ? ' style="background-image:url(\'' . htmlspecialchars($loginBg) . '\')"' : '' ?> aria-hidden="true">
    <div class="auth-inner">
      <?php // The name on its own. A logo here competed with the photograph
            // behind it and with the nameplate the welcome animation has just
            // finished drawing; set as a wordmark it reads as the company
            // rather than as a badge stuck on a picture. ?>
      <div class="auth-lockup">
        <span class="auth-company"><?= htmlspecialchars($companyName) ?></span>
      </div>

      <?php // What is behind the door, for somebody deciding whether they are
            // at the right one. The two sides keep different promises, and the
            // switch on the form side changes which is showing — so both are
            // rendered and one is hidden, rather than fetching the page again
            // just to reword a list. ?>
      <div class="stage-copy" data-door="staff"<?= $clientSide ? ' hidden' : '' ?>>
        <p class="auth-tagline">
          The Showroom, its stock and its money &mdash; one system, from the showroom
          floor to the books.
        </p>
        <ul class="auth-points">
          <li><i class="fa fa-warehouse"></i><span>Stock, reservations and deliveries</span></li>
          <li><i class="fa fa-screwdriver-wrench"></i><span>The workshop floor and its jobs</span></li>
          <li><i class="fa fa-coins"></i><span>Payments, expenses and the accounts</span></li>
          <li><i class="fa fa-chart-line"></i><span>What was sold, by whom, and for how much</span></li>
        </ul>
      </div>

      <div class="stage-copy" data-door="client"<?= $clientSide ? '' : ' hidden' ?>>
        <p class="auth-tagline">
          Your vehicles, your paperwork and what you still owe &mdash; in one place,
          whenever you want to look.
        </p>
        <ul class="auth-points">
          <li><i class="fa fa-car"></i><span>The vehicles you have bought from us</span></li>
          <li><i class="fa fa-file-invoice"></i><span>Your invoices, receipts and statement</span></li>
          <li><i class="fa fa-calendar-check"></i><span>Service bookings and what was done</span></li>
          <li><i class="fa fa-folder-open"></i><span>Logbooks and documents we hold for you</span></li>
        </ul>
      </div>
    </div>

    <?php // The way out, kept on the picture side where it cannot be mistaken
          // for part of the form. ?>
    <a class="auth-back" href="<?= BASE_URL ?>/showroom/" aria-hidden="true" tabindex="-1">
      <i class="fa fa-arrow-left"></i> <span>Back to the showroom</span>
    </a>
  </section>

  <!-- The form side -->
  <main class="auth-panel" id="main">
    <div class="login-wrap">
    <div class="card-3d" id="card3d">
    <div class="neon-aura" aria-hidden="true"></div>
    <div class="login-card">
      <span class="neon-ring" aria-hidden="true"></span>
      <div class="auth-form<?= $error || $clientError ? ' err' : '' ?>">

        <?php // On a phone the picture side is only a band, so the brand is
              // repeated here where there is room to read it. ?>
        <div class="auth-smallbrand">
          <span><?= htmlspecialchars($companyName) ?></span>
        </div>
        <div class="login-title"><?= $isFirstRun ? 'System Setup' : htmlspecialchars(APP_NAME) ?></div>
        <div class="login-sub" id="doorSub"><?= $isFirstRun
            ? 'Create your administrator account to get started.'
            : ($clientSide ? 'Customer portal — your vehicles, invoices and documents'
                           : 'Staff portal — sign in to continue') ?></div>

        <?php // First-run setup is the only thing that matters until an admin
              // exists, so the choice of door is not offered yet. ?>
        <?php if (!$isFirstRun && $portalOn): ?>
        <div class="door-switch" role="tablist" aria-label="Who is signing in">
            <button type="button" id="doorStaffBtn"  class="<?= $clientSide ? '' : 'on' ?>"
                    role="tab" aria-selected="<?= $clientSide ? 'false' : 'true' ?>">
                <i class="fa fa-id-badge"></i> Staff
            </button>
            <button type="button" id="doorClientBtn" class="<?= $clientSide ? 'on' : '' ?>"
                    role="tab" aria-selected="<?= $clientSide ? 'true' : 'false' ?>">
                <i class="fa fa-user"></i> Customer
            </button>
        </div>
        <?php endif; ?>

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
        <!-- ── Staff: a username against `users` ── -->
        <div class="door-pane" id="paneStaff"<?= $clientSide ? ' hidden' : '' ?>>

        <?php if ($resetError): ?>
        <div class="alert alert-danger py-2"><i class="fa fa-circle-exclamation me-2"></i><?= e($resetError) ?></div>
        <?php endif; ?>
        <?php if ($resetNote): ?>
        <div class="alert alert-success py-2"><i class="fa fa-envelope-circle-check me-2"></i><?= e($resetNote) ?></div>
        <?php endif; ?>

        <div class="door-pane" id="stfSignin"<?= $staffPane === 'signin' ? '' : ' hidden' ?>>
        <form method="POST">
            <div class="mb-3">
                <label class="form-label">Username</label>
                <div class="field-wrap"><i class="fa fa-user"></i>
                <input type="text" name="username" class="form-control" placeholder="Enter your username" required autocomplete="username" value="<?= htmlspecialchars($_POST['username'] ?? $rememberedUser) ?>"></div>
            </div>
            <div class="mb-3">
                <label class="form-label">Password</label>
                <div class="field-wrap">
                    <i class="fa fa-lock"></i>
                    <input type="password" name="password" class="form-control password-input" placeholder="Enter your password" required autocomplete="current-password">
                    <button type="button" class="password-toggle"><i class="fa fa-eye"></i></button>
                </div>
            </div>
            <div class="mb-4">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="remember_me" id="rememberMe" value="1"<?= (!empty($_POST['remember_me']) || ($_SERVER['REQUEST_METHOD'] !== 'POST' && $rememberedUser)) ? ' checked' : '' ?>>
                    <label class="form-check-label" for="rememberMe">Remember me on this browser</label>
                </div>
            </div>
            <button type="submit" class="btn btn-login btn-primary w-100 text-white">
                <i class="fa fa-right-to-bracket me-2"></i>Sign In
            </button>
        </form>
            <div class="door-alt">
                <a href="#" id="toForgot">Forgotten your password? →</a>
            </div>
        </div><!-- /stfSignin -->

        <!-- Ask for a code -->
        <div class="door-pane" id="stfForgot"<?= $staffPane === 'forgot' ? '' : ' hidden' ?>>
            <form method="POST">
                <input type="hidden" name="staff_do" value="forgot">
                <div class="mb-3">
                    <label class="form-label">Your username or email</label>
                    <div class="field-wrap"><i class="fa fa-user"></i>
                    <input type="text" name="who" class="form-control" required autocomplete="username"
                           placeholder="Either one will do"
                           value="<?= e($staffPane === 'forgot' ? $staffWho : '') ?>"></div>
                    <div class="form-text">
                        We will send a code to the address we hold for that account.
                    </div>
                </div>
                <button type="submit" class="btn btn-login btn-primary w-100 text-white">
                    <i class="fa fa-paper-plane me-2"></i>Send me a code
                </button>
            </form>
            <div class="door-alt">
                Remembered it? <a href="#" id="toStaffSignin">Sign in instead →</a>
            </div>
        </div>

        <!-- Code plus the new password, in one step -->
        <div class="door-pane" id="stfReset"<?= $staffPane === 'reset' ? '' : ' hidden' ?>>
            <form method="POST">
                <input type="hidden" name="staff_do" value="reset">
                <input type="hidden" name="who" value="<?= e($staffWho) ?>">
                <div class="mb-3">
                    <label class="form-label">The 6-digit code</label>
                    <input type="text" name="code" class="form-control code-input" required
                           inputmode="numeric" pattern="[0-9]{6}" maxlength="6"
                           autocomplete="one-time-code" placeholder="000000">
                </div>
                <div class="mb-3">
                    <label class="form-label">New password</label>
                    <div class="field-wrap">
                        <i class="fa fa-lock"></i>
                        <input type="password" name="password" class="form-control password-input"
                               required minlength="8" autocomplete="new-password"
                               placeholder="At least 8 characters">
                        <button type="button" class="password-toggle"><i class="fa fa-eye"></i></button>
                    </div>
                </div>
                <div class="mb-4">
                    <label class="form-label">Confirm new password</label>
                    <div class="field-wrap">
                        <i class="fa fa-lock"></i>
                        <input type="password" name="password_confirm" class="form-control password-input"
                               required minlength="8" autocomplete="new-password" placeholder="Repeat it">
                        <button type="button" class="password-toggle"><i class="fa fa-eye"></i></button>
                    </div>
                </div>
                <button type="submit" class="btn btn-login btn-primary w-100 text-white">
                    <i class="fa fa-circle-check me-2"></i>Set my new password
                </button>
            </form>
            <div class="door-alt">
                Nothing arrived? <a href="#" id="toForgot2">Try again →</a>
            </div>
        </div>

        </div><!-- /paneStaff -->

        <?php if ($portalOn): ?>
        <!-- ── Customer: the email we invoice them at, against `clients` ── -->
        <div class="door-pane" id="paneClient"<?= $clientSide ? '' : ' hidden' ?>>

            <?php if ($clientError): ?>
            <div class="alert alert-danger py-2"><i class="fa fa-circle-exclamation me-2"></i><?= e($clientError) ?></div>
            <?php endif; ?>
            <?php if ($clientNote): ?>
            <div class="alert alert-success py-2"><i class="fa fa-envelope-circle-check me-2"></i><?= e($clientNote) ?></div>
            <?php endif; ?>

            <!-- Sign in -->
            <div class="door-pane" id="cliSignin"<?= $clientPane === 'signin' ? '' : ' hidden' ?>>
                <form method="POST">
                    <input type="hidden" name="client_do" value="signin">
                    <div class="mb-3">
                        <label class="form-label">Email address</label>
                        <div class="field-wrap"><i class="fa fa-envelope"></i>
                        <input type="email" name="email" class="form-control" required autocomplete="email"
                               placeholder="The address we invoice you at"
                               value="<?= e($clientPane === 'signin' ? $clientEmail : '') ?>"></div>
                    </div>
                    <div class="mb-4">
                        <label class="form-label">Password</label>
                        <div class="field-wrap">
                            <i class="fa fa-lock"></i>
                            <input type="password" name="password" class="form-control password-input"
                                   required autocomplete="current-password" placeholder="Enter your password">
                            <button type="button" class="password-toggle"><i class="fa fa-eye"></i></button>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-login btn-primary w-100 text-white">
                        <i class="fa fa-right-to-bracket me-2"></i>Sign In
                    </button>
                </form>
                <div class="door-alt">
                    <?php // One link for three things, because from here they are
                          // the same act: proving you can read the address. ?>
                    New here, or forgotten your password?<br>
                    <a href="#" id="toRegister">Create an account or reset your access →</a>
                </div>
            </div>

            <!-- Register / claim / reset -->
            <div class="door-pane" id="cliRegister"<?= $clientPane === 'register' ? '' : ' hidden' ?>>
                <form method="POST">
                    <input type="hidden" name="client_do" value="register">
                    <div class="mb-3">
                        <label class="form-label">Your name</label>
                        <div class="field-wrap"><i class="fa fa-user"></i>
                        <input type="text" name="name" class="form-control" required
                               placeholder="As it should appear on your invoices"
                               value="<?= e($clientPane === 'register' ? (string)($_POST['name'] ?? '') : '') ?>"></div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Email address</label>
                        <div class="field-wrap"><i class="fa fa-envelope"></i>
                        <input type="email" name="email" class="form-control" required autocomplete="email"
                               placeholder="We will send a code here"
                               value="<?= e($clientPane === 'register' ? $clientEmail : '') ?>"></div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Phone <span class="text-muted fw-normal">(optional)</span></label>
                        <div class="field-wrap"><i class="fa fa-phone"></i>
                        <input type="text" name="phone" class="form-control" placeholder="07xx xxx xxx"
                               value="<?= e($clientPane === 'register' ? (string)($_POST['phone'] ?? '') : '') ?>"></div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Choose a password</label>
                        <div class="field-wrap">
                            <i class="fa fa-lock"></i>
                            <input type="password" name="password" class="form-control password-input"
                                   required minlength="8" autocomplete="new-password"
                                   placeholder="At least 8 characters">
                            <button type="button" class="password-toggle"><i class="fa fa-eye"></i></button>
                        </div>
                    </div>
                    <div class="mb-4">
                        <label class="form-label">Confirm password</label>
                        <div class="field-wrap">
                            <i class="fa fa-lock"></i>
                            <input type="password" name="password_confirm" class="form-control password-input"
                                   required minlength="8" autocomplete="new-password" placeholder="Repeat it">
                            <button type="button" class="password-toggle"><i class="fa fa-eye"></i></button>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-login btn-primary w-100 text-white">
                        <i class="fa fa-paper-plane me-2"></i>Send me a code
                    </button>
                </form>
                <div class="door-alt">
                    Already set up? <a href="#" id="toSignin">Sign in instead →</a>
                </div>
            </div>

            <!-- Verify -->
            <div class="door-pane" id="cliVerify"<?= $clientPane === 'verify' ? '' : ' hidden' ?>>
                <div class="sent-to">
                    <i class="fa fa-envelope me-1"></i>
                    Code sent to <strong><?= e($clientEmail) ?></strong>.
                    It is good for <?= CLIENT_CODE_TTL_MINUTES ?> minutes.
                </div>
                <form method="POST">
                    <input type="hidden" name="client_do" value="verify">
                    <input type="hidden" name="email" value="<?= e($clientEmail) ?>">
                    <div class="mb-4">
                        <label class="form-label">Enter the 6-digit code</label>
                        <input type="text" name="code" class="form-control code-input" required
                               inputmode="numeric" pattern="[0-9]{6}" maxlength="6"
                               autocomplete="one-time-code" placeholder="000000" autofocus>
                    </div>
                    <button type="submit" class="btn btn-login btn-primary w-100 text-white">
                        <i class="fa fa-circle-check me-2"></i>Confirm and sign in
                    </button>
                </form>
                <div class="door-alt">
                    Nothing arrived? <a href="#" id="toRegister2">Start again →</a>
                </div>
            </div>

        </div><!-- /paneClient -->
        <?php endif; ?>
        <?php endif; ?>
        <footer class="auth-foot">
          <?php // Somebody who cannot get in needs a way to reach a person,
                // and on the customer side "ask your administrator" means
                // nothing. These are the details they actually need. ?>
          <?php if ($companyPhone !== '' || $companyEmail !== ''): ?>
          <div class="auth-contact">
            <?php if ($companyPhone !== ''): ?>
            <a href="tel:<?= htmlspecialchars(preg_replace('/[^0-9+]/', '', $companyPhone)) ?>">
              <i class="fa fa-phone"></i><?= htmlspecialchars($companyPhone) ?>
            </a>
            <?php endif; ?>
            <?php if ($companyEmail !== ''): ?>
            <a href="mailto:<?= htmlspecialchars($companyEmail) ?>">
              <i class="fa fa-envelope"></i><?= htmlspecialchars($companyEmail) ?>
            </a>
            <?php endif; ?>
          </div>
          <?php endif; ?>

          <div class="auth-legal">
            &copy; <?= date('Y') ?> <?= htmlspecialchars($companyName) ?>
            <span aria-hidden="true">&middot;</span>
            <?= $clientSide ? 'Customer portal' : 'Showroom management System' ?>
          </div>

          <?php // Repeated for the phone, where the picture side is a band and
                // the link on it is not shown. ?>
          <a class="auth-backsm" href="<?= BASE_URL ?>/showroom/">
            <i class="fa fa-store"></i> Browse the showroom
          </a>
        </footer>

      </div><!-- /auth-form -->
    </div><!-- /login-card -->
    </div><!-- /card-3d -->
    </div><!-- /login-wrap -->
  </main>

</div><!-- /auth -->
</div><!-- /login-stage -->

<script>
/* Which door is showing, and which of the customer's three steps.
   The server has already picked the right one on a POST, so this only has to
   handle the clicking — reload with a wrong password and you are still on the
   customer side, looking at the message about it. */
(function () {
    var staffBtn = document.getElementById('doorStaffBtn'),
        cliBtn   = document.getElementById('doorClientBtn'),
        staff    = document.getElementById('paneStaff'),
        client   = document.getElementById('paneClient'),
        sub      = document.getElementById('doorSub');
    if (!staffBtn || !cliBtn || !staff || !client) return;

    function door(toClient) {
        staff.hidden  = toClient;
        client.hidden = !toClient;
        staffBtn.classList.toggle('on', !toClient);
        cliBtn.classList.toggle('on', toClient);
        staffBtn.setAttribute('aria-selected', String(!toClient));
        cliBtn.setAttribute('aria-selected', String(toClient));
        if (sub) {
            sub.textContent = toClient
                ? 'Customer portal — your vehicles, invoices and documents'
                : 'Staff portal — sign in to continue';
        }
        // The picture side promises different things to the two of them, so it
        // moves with the switch. Leaving it behind would sit a customer's form
        // next to a list of what staff can do.
        document.querySelectorAll('.stage-copy').forEach(function (c) {
            c.hidden = (c.getAttribute('data-door') !== (toClient ? 'client' : 'staff'));
        });
        var legal = document.querySelector('.auth-legal');
        if (legal) {
            legal.lastChild.textContent = toClient ? ' Customer portal' : ' Showroom management System';
        }
        var first = (toClient ? client : staff).querySelector('input:not([type=hidden])');
        if (first && !first.hasAttribute('autofocus')) { try { first.focus(); } catch (e) {} }
    }
    staffBtn.addEventListener('click', function () { door(false); });
    cliBtn.addEventListener('click',   function () { door(true);  });

    // The customer's three steps: sign in, ask for a code, type the code.
    var panes = {
        signin:   document.getElementById('cliSignin'),
        register: document.getElementById('cliRegister'),
        verify:   document.getElementById('cliVerify')
    };
    function step(which) {
        Object.keys(panes).forEach(function (k) {
            if (panes[k]) panes[k].hidden = (k !== which);
        });
        var first = panes[which] && panes[which].querySelector('input:not([type=hidden])');
        if (first) { try { first.focus(); } catch (e) {} }
    }
    ['toRegister', 'toRegister2'].forEach(function (id) {
        var a = document.getElementById(id);
        if (a) a.addEventListener('click', function (ev) { ev.preventDefault(); step('register'); });
    });
    var back = document.getElementById('toSignin');
    if (back) back.addEventListener('click', function (ev) { ev.preventDefault(); step('signin'); });

    // The staff side has the same three steps: sign in, ask for a code, set a
    // new password. The server has already chosen the right one on a POST, so
    // this only handles the clicking.
    var stf = {
        signin: document.getElementById('stfSignin'),
        forgot: document.getElementById('stfForgot'),
        reset:  document.getElementById('stfReset')
    };
    function staffStep(which) {
        Object.keys(stf).forEach(function (k) {
            if (stf[k]) stf[k].hidden = (k !== which);
        });
        var first = stf[which] && stf[which].querySelector('input:not([type=hidden])');
        if (first) { try { first.focus(); } catch (e) {} }
    }
    ['toForgot', 'toForgot2'].forEach(function (id) {
        var a = document.getElementById(id);
        if (a) a.addEventListener('click', function (ev) { ev.preventDefault(); staffStep('forgot'); });
    });
    var stfBack = document.getElementById('toStaffSignin');
    if (stfBack) stfBack.addEventListener('click', function (ev) { ev.preventDefault(); staffStep('signin'); });

    // A pasted code arrives with spaces or dashes in it more often than not.
    document.querySelectorAll('.code-input').forEach(function (code) {
        code.addEventListener('input', function () {
            var v = code.value.replace(/\D/g, '').slice(0, 6);
            if (v !== code.value) code.value = v;
        });
    });
}());

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

/* ── 3D tilt — the card leans gently toward the cursor ─────────────
   Skipped on touch devices and for users preferring reduced motion.
   Values ease toward the target each frame, so movement stays smooth. */
(function () {
    var wrap = document.getElementById('card3d');
    if (!wrap) return;
    if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
    if (window.matchMedia('(pointer: coarse)').matches) return;

    var tx = 0, ty = 0, cx = 0, cy = 0, raf = null;
    function frame() {
        cx += (tx - cx) * 0.14;
        cy += (ty - cy) * 0.14;
        wrap.style.transform = 'rotateX(' + cy.toFixed(2) + 'deg) rotateY(' + cx.toFixed(2) + 'deg)';
        if (Math.abs(tx - cx) > 0.05 || Math.abs(ty - cy) > 0.05) raf = requestAnimationFrame(frame);
        else raf = null;
    }
    function kick() { if (!raf) raf = requestAnimationFrame(frame); }

    wrap.addEventListener('mousemove', function (e) {
        var r = wrap.getBoundingClientRect();
        tx = ((e.clientX - r.left) / r.width  - 0.5) * 12;  // rotateY: ±6°
        ty = -((e.clientY - r.top) / r.height - 0.5) * 10;  // rotateX: ±5°
        kick();
    });
    wrap.addEventListener('mouseleave', function () { tx = 0; ty = 0; kick(); });
}());
</script>

<?php if ($showIntro): ?>
<!-- ═══════════════════ INTRO ORCHESTRATION — silent ═══════════════════ -->
<script>
(function () {
    'use strict';

    var overlay = document.getElementById('introOverlay');
    var brandEl = document.getElementById('brandName');
    var stage   = document.getElementById('loginStage');
    var skipBtn = document.getElementById('skipIntro');
    if (!overlay || !stage) return;

    var reduced  = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    var NAME     = brandEl.getAttribute('data-name') || 'MASCARDI';
    var finished = false;

    /* ── Build the nameplate letters — plain spans, nothing nested ── */
    var spans = [];
    NAME.split('').forEach(function (ch) {
        var s = document.createElement('span');
        s.textContent = ch;
        brandEl.appendChild(s);
        spans.push(s);
    });

    /* ── Reveal the login form ───────────────────────────────── */
    function revealLogin() {
        if (finished) return;
        finished = true;
        overlay.classList.add('done');
        document.body.classList.add('intro-done');
        stage.classList.add('show');
        setTimeout(function () {
            var u = stage.querySelector('input[name="username"]');
            if (u) { try { u.focus(); } catch (e) {} }
        }, 500);
        setTimeout(function () { if (overlay && overlay.parentNode) overlay.style.display = 'none'; }, 1100);
    }

    /* ── Skip ────────────────────────────────────────────────── */
    if (skipBtn) skipBtn.addEventListener('click', function (e) { e.stopPropagation(); revealLogin(); });
    window.addEventListener('keydown', function (e) { if (e.key === 'Escape') revealLogin(); });

    /* ── Timeline ────────────────────────────────────────────── */
    if (reduced) {
        spans.forEach(function (s) { s.classList.add('in'); });
        overlay.classList.add('reveal');
        setTimeout(revealLogin, 1400);
        return;
    }

    // 1. Letters rise + unblur, one after another
    var step = 95;
    spans.forEach(function (s, i) {
        setTimeout(function () { s.classList.add('in'); }, 250 + i * step);
    });

    var settled = 250 + spans.length * step + 900; // name fully in place

    // 2. Underline draws + tagline settles in
    setTimeout(function () { overlay.classList.add('reveal'); }, settled - 350);

    // 3. A light glint sweeps across the wordmark
    setTimeout(function () {
        spans.forEach(function (s, i) {
            setTimeout(function () {
                s.classList.add('glint');
                setTimeout(function () { s.classList.remove('glint'); }, 340);
            }, i * 55);
        });
    }, settled + 250);

    // 4. Hold, then hand over to the login card
    setTimeout(revealLogin, settled + 2500);
}());
</script>
<?php endif; ?>
</body>
</html>

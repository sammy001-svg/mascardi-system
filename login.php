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
                    header('Location: ' . BASE_URL . '/index.php'); exit;
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
// that stays with the browser's own password manager via autocomplete).
$rememberedUser = trim($_COOKIE['rm_user'] ?? '');

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
<title><?= $isFirstRun ? 'Setup — ' : 'Staff Login — ' ?><?= htmlspecialchars(APP_NAME) ?></title>
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
.form-check-input { width: 16px; height: 16px; margin-top: 2px; cursor: pointer; accent-color: #2563eb; border-color: #cbd5e1; }
.form-check-input:checked { background-color: #2563eb; border-color: #2563eb; }
.form-check-label { font-size: 13px; color: #64748b; cursor: pointer; user-select: none; }
.btn-login { background: linear-gradient(135deg,#2563eb,#1d4ed8); border: none; padding: 13px; font-size: 15px; font-weight: 700; border-radius: 12px; letter-spacing: .3px; transition: box-shadow .15s, transform .1s; }
.btn-login:hover { box-shadow: 0 6px 20px rgba(37,99,235,.45); transform: translateY(-1px); }
.first-run-badge { background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 10px; padding: 10px 14px; font-size: 13px; color: #1d4ed8; margin-bottom: 18px; }
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
.login-card{
    background:rgba(16,26,48,.82) !important;
    -webkit-backdrop-filter:blur(22px); backdrop-filter:blur(22px);
    border:1px solid rgba(59,130,246,.28) !important;
    box-shadow:0 34px 90px rgba(0,0,0,.65), 0 0 40px rgba(59,130,246,.10),
               inset 0 1px 0 rgba(255,255,255,.05) !important;
    position:relative; overflow:hidden;
}
/* ── 3D tilt + animated neon glow ──────────────────────────────────── */
.login-wrap{ perspective:1400px; }
.card-3d{ position:relative; transform-style:preserve-3d; will-change:transform; }
body .card-3d{ opacity:0; }
body.no-intro .card-3d,
body.has-intro .login-stage.show .card-3d{
    animation:cardIn .9s cubic-bezier(.17,.75,.28,1) .1s backwards;
    opacity:1;
}
@keyframes cardIn{
    0%{ opacity:0; transform:translateY(42px) rotateX(16deg) scale(.955); }
    100%{ opacity:1; transform:translateY(0) rotateX(0) scale(1); }
}
/* Shake after a failed sign-in, once the card has settled */
.card-3d.err{
    animation:cardIn .9s cubic-bezier(.17,.75,.28,1) .05s backwards,
              shakeX .5s ease 1s;
}
@keyframes shakeX{
    0%,100%{ transform:translateX(0); }
    20%,60%{ transform:translateX(-9px); }
    40%,80%{ transform:translateX(9px); }
}

/* Soft neon aura breathing around the card (sits behind it in 3D space) */
.neon-aura{
    position:absolute; inset:-14px; border-radius:36px; z-index:0;
    overflow:hidden; filter:blur(28px); opacity:.45;
    pointer-events:none; transition:opacity .5s ease;
    transform:translateZ(-40px);
}
.neon-aura::before{
    content:''; position:absolute; left:50%; top:50%;
    width:240%; height:240%; margin:-120% 0 0 -120%;
    background:conic-gradient(#22d3ee, #3b82f6, #8b5cf6, #d946ef, #3b82f6, #22d3ee);
    animation:neonSpin 7s linear infinite;
}
.card-3d:hover .neon-aura{ opacity:.72; }

/* Crisp neon ring tracing the card edge — same rotating gradient */
.neon-ring{
    position:absolute; inset:0; border-radius:24px; padding:1.5px;
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
    .neon-aura::before, .neon-ring::before{ animation:none; }
    body.no-intro .card-3d,
    body.has-intro .login-stage.show .card-3d,
    .card-3d.err{ animation:none; opacity:1; }
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
.brand-icon{ box-shadow:0 8px 26px rgba(37,99,235,.5), 0 0 24px rgba(59,130,246,.35); }
.brand-icon.has-logo{
    background:#ffffff !important; padding:8px;
    box-shadow:0 8px 26px rgba(0,0,0,.4), 0 0 22px rgba(59,130,246,.28);
}
.brand-icon.has-logo img{ width:100%; height:100%; object-fit:contain; display:block; border-radius:8px; }
.first-run-badge{
    background:rgba(59,130,246,.12) !important;
    border-color:rgba(59,130,246,.3) !important;
    color:#93c5fd !important;
}
.alert-danger{ background:rgba(239,68,68,.14); border-color:rgba(239,68,68,.35); color:#fca5a5; }
.alert-warning{ background:rgba(245,158,11,.14); border-color:rgba(245,158,11,.35); color:#fcd34d; }
.alert-success{ background:rgba(34,197,94,.14); border-color:rgba(34,197,94,.35); color:#86efac; }

/* ── Login stage slide-in ──────────────────────────────────────────── */
.login-stage{ opacity:1; transform:none; width:100%; display:flex; flex-direction:column; align-items:center; }
body.has-intro .login-stage{
    opacity:0; transform:translateY(46px) scale(.97);
    transition:opacity .9s ease, transform .9s cubic-bezier(.2,.9,.25,1.15);
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

<!-- Back to showroom -->
<a href="<?= BASE_URL ?>/showroom/" class="back-link">
    <i class="fa fa-arrow-left"></i> Back to Showroom
</a>

<div class="login-wrap">
    <div class="card-3d<?= $error ? ' err' : '' ?>" id="card3d">
    <div class="neon-aura" aria-hidden="true"></div>
    <div class="login-card">
        <span class="neon-ring" aria-hidden="true"></span>
        <?php $__logo = companyLogo(); ?>
        <?php if ($__logo['exists']): ?>
        <div class="brand-icon has-logo"><img src="<?= htmlspecialchars($__logo['url']) ?>" alt="<?= htmlspecialchars(getSetting('company_name', 'Mascardi')) ?> logo"></div>
        <?php else: ?>
        <div class="brand-icon"><i class="fa fa-car-side"></i></div>
        <?php endif; ?>
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
        <?php endif; ?>
    </div><!-- /login-card -->
    </div><!-- /card-3d -->

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
</div><!-- /login-stage -->

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

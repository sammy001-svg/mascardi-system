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

function _issueRememberToken(PDO $db, int $userId): void {
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
        // Remove old tokens for this user and clean up global expired ones
        $db->prepare("DELETE FROM remember_tokens WHERE user_id = ? OR expires_at < NOW()")->execute([$userId]);
        $token = bin2hex(random_bytes(32));
        $hash  = hash('sha256', $token);
        $db->prepare("INSERT INTO remember_tokens (user_id, token_hash, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 10 YEAR))")
           ->execute([$userId, $hash]);
        setcookie('rm_tok', $token, [
            'expires'  => time() + 10 * 365 * 86400,
            'path'     => '/',
            'httponly' => true,
            'samesite' => 'Lax',
            'secure'   => isset($_SERVER['HTTPS']),
        ]);
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
                            _issueRememberToken($db, (int)$user['id']);
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
                        _issueRememberToken($db, (int)$user['id']);
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
.login-card::after{
    content:''; position:absolute; inset:0; border-radius:inherit; padding:1px;
    background:linear-gradient(130deg, rgba(34,197,94,.5), rgba(59,130,246,.5) 45%, rgba(239,68,68,.45));
    -webkit-mask:linear-gradient(#fff 0 0) content-box, linear-gradient(#fff 0 0);
    -webkit-mask-composite:xor; mask-composite:exclude;
    opacity:.4; pointer-events:none;
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

/* ═══════════════════ INTRO OVERLAY ═══════════════════ */
#introOverlay{
    position:fixed; inset:0; z-index:9999;
    display:flex; flex-direction:column; align-items:center; justify-content:center;
    gap:26px; padding:24px; overflow:hidden;
    background:#0a1a4d;   /* solid navy blue */
    transition:opacity .9s ease, transform .9s ease, visibility .9s;
}
.intro-orbs{ display:none; }   /* keep the navy clean */
#introOverlay.done{ opacity:0; transform:scale(1.08); visibility:hidden; pointer-events:none; }

.intro-orbs{ position:absolute; inset:0; overflow:hidden; pointer-events:none; }
.intro-orbs .orb{ position:absolute; border-radius:50%; filter:blur(70px); opacity:.5; animation:orbFloat 16s ease-in-out infinite; }
.orb.g{ width:340px;height:340px; background:var(--neon-g); top:-60px; left:-40px; }
.orb.b{ width:420px;height:420px; background:var(--neon-b); bottom:-120px; right:-60px; animation-delay:-4s; }
.orb.r{ width:260px;height:260px; background:var(--neon-r); top:20%; right:14%; opacity:.35; animation-delay:-8s; }
.orb.y{ width:220px;height:220px; background:var(--neon-y); bottom:16%; left:12%; opacity:.32; animation-delay:-11s; }
@keyframes orbFloat{
    0%,100%{ transform:translate(0,0) scale(1); }
    33%{ transform:translate(40px,-30px) scale(1.08); }
    66%{ transform:translate(-30px,26px) scale(.95); }
}
#introOverlay .intro-grid{
    position:absolute; inset:0; pointer-events:none;
    background-image:
        linear-gradient(rgba(255,255,255,.03) 1px, transparent 1px),
        linear-gradient(90deg, rgba(255,255,255,.03) 1px, transparent 1px);
    background-size:58px 58px;
    mask-image:radial-gradient(circle at 50% 45%, #000 40%, transparent 85%);
    -webkit-mask-image:radial-gradient(circle at 50% 45%, #000 40%, transparent 85%);
}

/* Brand name — Mokoto-style nameplate */
.brand-name{
    position:relative; z-index:2; margin:0;
    font-family:'Orbitron','Inter',sans-serif; font-weight:900;
    font-size:clamp(46px, 11.5vw, 132px);
    letter-spacing:.06em; line-height:1;
    display:flex; gap:.02em; perspective:800px;
}
.brand-name span{
    position:relative; display:inline-block; opacity:0;
    transform:translateY(-160%) rotateX(-90deg); filter:blur(8px);
    color:#ffffff;
    text-shadow:0 0 26px rgba(255,255,255,.35), 0 6px 20px rgba(0,0,0,.45);
    will-change:transform,opacity;
}
.brand-name span.in{ animation:dropIn 1s cubic-bezier(.18,.9,.24,1.25) forwards; }
/* Splash flash as a letter lands */
.brand-name span.pulse{ text-shadow:0 0 40px rgba(180,220,255,.95), 0 0 18px rgba(255,255,255,.9); }
@keyframes dropIn{
    0%{ opacity:0; transform:translateY(-160%) rotateX(-90deg); filter:blur(8px); }
    58%{ opacity:1; transform:translateY(12%) rotateX(0); filter:blur(0); }
    74%{ transform:translateY(-5%); }
    88%{ transform:translateY(3%); }
    100%{ opacity:1; transform:translateY(0) rotateX(0); filter:blur(0); }
}

/* ── Water ripple + droplets — fires the instant a letter lands ───── */
.letter-splash{
    position:absolute; left:50%; bottom:-4px; width:0; height:0;
    pointer-events:none; z-index:1;
}
.splash-ring{
    position:absolute; left:0; top:0;
    width:16px; height:16px; margin:-8px 0 0 -8px;
    border:2px solid rgba(255,255,255,.85);
    border-radius:50%;
    opacity:0; transform:scale(.15);
    box-sizing:border-box;
}
.splash-ring.ring2{ border-color:rgba(200,225,255,.6); }
.splash-ring.ring3{ border-color:rgba(160,205,255,.4); }
.letter-splash.go .splash-ring{ animation:splashRing .7s cubic-bezier(.15,.6,.3,1) forwards; }
.letter-splash.go .splash-ring.ring2{ animation-delay:.07s; }
.letter-splash.go .splash-ring.ring3{ animation-delay:.15s; }
@keyframes splashRing{
    0%{ opacity:.9; transform:scale(.15) scaleY(.5); }
    100%{ opacity:0; transform:scale(3.1) scaleY(.7); }
}
.splash-drop{
    position:absolute; left:0; top:0;
    width:4px; height:4px; margin:-2px 0 0 -2px;
    background:#eaf4ff; border-radius:50%;
    opacity:0;
    box-shadow:0 0 6px rgba(255,255,255,.85);
}
.letter-splash.go .splash-drop{ animation:splashDrop .55s cubic-bezier(.1,.7,.25,1) forwards; }
@keyframes splashDrop{
    0%{ opacity:1; transform:translate(0,0) scale(1); }
    100%{ opacity:0; transform:translate(var(--dx,0), var(--dy,-14px)) scale(.25); }
}
@media (prefers-reduced-motion: reduce){ .letter-splash{ display:none !important; } }

.brand-underline{
    position:relative; z-index:2; height:2px; width:0;
    border-radius:3px; margin-top:-6px;
    background:linear-gradient(90deg, transparent, rgba(255,255,255,.85), transparent);
    box-shadow:0 0 16px rgba(255,255,255,.4);
    transition:width 1.1s ease .2s;
}
#introOverlay.reveal .brand-underline{ width:min(560px,80vw); }

/* Typed tagline */
.tagline{
    position:relative; z-index:2; min-height:1.6em;
    font-family:'Inter',sans-serif; font-weight:600;
    font-size:clamp(13px, 2.4vw, 21px); letter-spacing:.16em;
    text-transform:uppercase; text-align:center;
    color:#eaf1ff; text-shadow:0 0 18px rgba(255,255,255,.28);
    padding:0 12px;
}
.tagline.typing::after{
    content:'|'; margin-left:2px; color:var(--neon-b);
    animation:caret .7s steps(1) infinite; font-weight:400;
}
@keyframes caret{ 50%{ opacity:0; } }

/* Skip + sound hint */
.intro-skip{
    position:fixed; top:22px; right:24px; z-index:10000;
    font-size:12.5px; font-weight:600; color:rgba(255,255,255,.7);
    background:rgba(255,255,255,.06); border:1px solid rgba(255,255,255,.16);
    border-radius:9px; padding:8px 15px; cursor:pointer;
    display:flex; align-items:center; gap:7px; transition:all .15s;
}
.intro-skip:hover{ color:#fff; background:rgba(255,255,255,.12); border-color:rgba(255,255,255,.3); }
.sound-hint{
    position:fixed; bottom:26px; left:50%; transform:translateX(-50%); z-index:10000;
    font-size:12px; font-weight:500; color:rgba(255,255,255,.72);
    background:rgba(15,23,42,.6); border:1px solid rgba(148,163,184,.22);
    border-radius:30px; padding:8px 16px; display:flex; align-items:center; gap:8px;
    -webkit-backdrop-filter:blur(6px); backdrop-filter:blur(6px);
    animation:hintPulse 1.8s ease-in-out infinite; transition:opacity .4s;
}
.sound-hint.hide{ opacity:0; pointer-events:none; }
@keyframes hintPulse{ 0%,100%{ box-shadow:0 0 0 0 rgba(59,130,246,.35); } 50%{ box-shadow:0 0 0 8px rgba(59,130,246,0); } }

@media (prefers-reduced-motion: reduce){
    .brand-name span, .brand-name span.in{ animation:none; opacity:1; transform:none; filter:none;
        color:#fff; -webkit-text-fill-color:#fff; }
    .orb{ animation:none; }
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
    <div class="intro-orbs">
        <span class="orb g"></span><span class="orb b"></span>
        <span class="orb r"></span><span class="orb y"></span>
    </div>
    <div class="intro-grid"></div>

    <h1 id="brandName" class="brand-name" data-name="MASCARDI" aria-label="MASCARDI"></h1>
    <div class="brand-underline"></div>
    <div id="tagline" class="tagline" aria-live="polite"></div>

    <button type="button" id="skipIntro" class="intro-skip">
        <i class="fa fa-forward"></i> Skip
    </button>
    <div id="soundHint" class="sound-hint">
        <i class="fa fa-volume-high"></i> Click anywhere to hear the welcome
    </div>
</div>
<?php endif; ?>

<!-- ═══════════════════ LOGIN STAGE ═══════════════════ -->
<div class="login-stage" id="loginStage">

<!-- Back to showroom -->
<a href="<?= BASE_URL ?>/showroom/" class="back-link">
    <i class="fa fa-arrow-left"></i> Back to Showroom
</a>

<div class="login-wrap">
    <div class="login-card">
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
                <input type="text" name="username" class="form-control" placeholder="Enter your username" required autocomplete="username" value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"></div>
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
                    <input class="form-check-input" type="checkbox" name="remember_me" id="rememberMe" value="1"<?= !empty($_POST['remember_me']) ? ' checked' : '' ?>>
                    <label class="form-check-label" for="rememberMe">Remember me on this browser</label>
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
</script>

<?php if ($showIntro): ?>
<!-- ═══════════════════ INTRO ORCHESTRATION ═══════════════════ -->
<script>
(function () {
    'use strict';

    var overlay  = document.getElementById('introOverlay');
    var brandEl  = document.getElementById('brandName');
    var taglineEl= document.getElementById('tagline');
    var stage    = document.getElementById('loginStage');
    var skipBtn  = document.getElementById('skipIntro');
    var soundHint= document.getElementById('soundHint');
    if (!overlay || !stage) return;

    var reduced = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    var NAME  = (brandEl.getAttribute('data-name') || 'MASCARDI');
    var LINE1 = 'WELCOME TO MASCARDI, HOME OF LUXURY CARS';
    var LINE2 = 'Use your username and password to log in to your account. Have a great day at work today.';

    var finished = false;

    /* ── Web Audio: synthesized water-wave blips ─────────────── */
    var actx = null, audioUnlocked = false;
    function audioCtx() {
        if (!actx) {
            var AC = window.AudioContext || window.webkitAudioContext;
            if (AC) { try { actx = new AC(); } catch (e) { actx = null; } }
        }
        if (actx && actx.state === 'suspended') { try { actx.resume(); } catch (e) {} }
        return actx;
    }
    // A water SPLASH for each falling letter: bright splish + wash + droplet plonk
    function playWave(idx) {
        var ctx = audioCtx();
        if (!ctx || ctx.state !== 'running') return;
        try {
            var now = ctx.currentTime;

            // 1) Bright splish — short high-passed noise burst (the impact)
            var sDur = 0.26;
            var sBuf = ctx.createBuffer(1, Math.floor(ctx.sampleRate * sDur), ctx.sampleRate);
            var sd = sBuf.getChannelData(0);
            for (var i = 0; i < sd.length; i++) sd[i] = (Math.random() * 2 - 1) * Math.pow(1 - i / sd.length, 2.3);
            var sSrc = ctx.createBufferSource(); sSrc.buffer = sBuf;
            var hp = ctx.createBiquadFilter(); hp.type = 'highpass'; hp.frequency.setValueAtTime(1900, now);
            var sg = ctx.createGain();
            sg.gain.setValueAtTime(0.0001, now);
            sg.gain.exponentialRampToValueAtTime(0.24, now + 0.008);
            sg.gain.exponentialRampToValueAtTime(0.0001, now + sDur);
            sSrc.connect(hp); hp.connect(sg); sg.connect(ctx.destination);
            sSrc.start(now); sSrc.stop(now + sDur);

            // 2) Water wash — low-passed noise decay (the "sploosh")
            var wDur = 0.5;
            var wBuf = ctx.createBuffer(1, Math.floor(ctx.sampleRate * wDur), ctx.sampleRate);
            var wd = wBuf.getChannelData(0);
            for (var j = 0; j < wd.length; j++) wd[j] = (Math.random() * 2 - 1) * Math.pow(1 - j / wd.length, 1.5);
            var wSrc = ctx.createBufferSource(); wSrc.buffer = wBuf;
            var lp = ctx.createBiquadFilter(); lp.type = 'lowpass';
            lp.frequency.setValueAtTime(1300, now);
            lp.frequency.exponentialRampToValueAtTime(300, now + wDur);
            var wg = ctx.createGain();
            wg.gain.setValueAtTime(0.0001, now);
            wg.gain.exponentialRampToValueAtTime(0.28, now + 0.04);
            wg.gain.exponentialRampToValueAtTime(0.0001, now + wDur);
            wSrc.connect(lp); lp.connect(wg); wg.connect(ctx.destination);
            wSrc.start(now); wSrc.stop(now + wDur);

            // 3) Droplet plonk — quick descending sine
            var o = ctx.createOscillator(); o.type = 'sine';
            var f0 = 540 - idx * 16;
            o.frequency.setValueAtTime(f0, now);
            o.frequency.exponentialRampToValueAtTime(f0 * 0.42, now + 0.19);
            var og = ctx.createGain();
            og.gain.setValueAtTime(0.0001, now);
            og.gain.exponentialRampToValueAtTime(0.17, now + 0.02);
            og.gain.exponentialRampToValueAtTime(0.0001, now + 0.3);
            o.connect(og); og.connect(ctx.destination);
            o.start(now); o.stop(now + 0.32);
        } catch (e) {}
    }

    /* ── Speech: pick the warmest female / African-English voice ── */
    function pickVoice() {
        var vs = (window.speechSynthesis && speechSynthesis.getVoices()) || [];
        if (!vs.length) return null;
        var byLang = function (p) {
            for (var i = 0; i < vs.length; i++) {
                if (vs[i].lang && vs[i].lang.toLowerCase().indexOf(p) === 0) return vs[i];
            }
            return null;
        };
        // African English locales first
        var v = byLang('en-ke') || byLang('en-ng') || byLang('en-za') || byLang('en-gh') || byLang('sw');
        if (v) return v;
        // Female-sounding English voices by name
        var femHint = /(female|woman|zira|aria|jenny|tessa|ayanda|imani|amara|libby|sonia|hazel|susan|linda|nadia|joanna|salli|kendra)/i;
        for (var j = 0; j < vs.length; j++) {
            if (/^en/i.test(vs[j].lang) && femHint.test(vs[j].name)) return vs[j];
        }
        // Google English (often female-ish) then any English
        for (var k = 0; k < vs.length; k++) {
            if (/google.*english/i.test(vs[k].name)) return vs[k];
        }
        for (var m = 0; m < vs.length; m++) {
            if (/^en/i.test(vs[m].lang)) return vs[m];
        }
        return vs[0];
    }

    var currentLine = '';
    // Chrome pauses/cuts speech after a while — nudge it while it's talking.
    var resumeTimer = null;
    function keepAlive() {
        if (resumeTimer) clearInterval(resumeTimer);
        resumeTimer = setInterval(function () {
            if (!window.speechSynthesis || !speechSynthesis.speaking) {
                clearInterval(resumeTimer); resumeTimer = null; return;
            }
            try { speechSynthesis.resume(); } catch (e) {}
        }, 3000);
    }
    function makeUtterance(text) {
        var u = new SpeechSynthesisUtterance(text);
        var v = pickVoice();
        if (v) { u.voice = v; u.lang = v.lang; } else { u.lang = 'en-GB'; }
        u.rate = 0.9; u.pitch = 1.05; u.volume = 1;   // slower, calmer delivery
        return u;
    }
    function speak(text) {
        if (!('speechSynthesis' in window)) return;
        try {
            currentLine = text;
            var u = makeUtterance(text);
            try { speechSynthesis.cancel(); } catch (e) {}
            try { speechSynthesis.resume(); } catch (e) {}
            speechSynthesis.speak(u);
            keepAlive();
        } catch (e) {}
    }
    // Speak + guaranteed callback (fallback timer if onend never fires / audio blocked)
    function speakThen(text, cb) {
        var done = false, fire = function () { if (!done) { done = true; cb && cb(); } };
        // Wait generously so the voice has a real chance to finish before we move on.
        var fallback = setTimeout(fire, Math.max(4200, text.length * 95));
        if (!('speechSynthesis' in window)) return;
        try {
            currentLine = text;
            var u = makeUtterance(text);
            u.onend = function () { clearTimeout(fallback); fire(); };
            u.onerror = function () { clearTimeout(fallback); fire(); };
            try { speechSynthesis.cancel(); } catch (e) {}
            try { speechSynthesis.resume(); } catch (e) {}
            speechSynthesis.speak(u);
            keepAlive();
        } catch (e) { clearTimeout(fallback); fire(); }
    }

    /* ── Unlock audio on first user gesture (autoplay policy) ─── */
    function unlockAudio() {
        if (audioUnlocked) return;
        audioUnlocked = true;
        audioCtx();
        if (soundHint) soundHint.classList.add('hide');
        // If speech didn't start (was blocked), speak whatever line is current
        if ('speechSynthesis' in window && !speechSynthesis.speaking && currentLine && !finished) {
            speak(currentLine);
        }
    }
    // Only real activation gestures unlock audio (a mousemove does not
    // satisfy the browser's autoplay policy).
    ['pointerdown', 'touchstart', 'keydown'].forEach(function (ev) {
        window.addEventListener(ev, unlockAudio, { passive: true });
    });

    /* ── Typing effect ───────────────────────────────────────── */
    function typeText(el, text, speed, done) {
        el.textContent = ''; el.classList.add('typing');
        var i = 0;
        (function tick() {
            if (finished) { el.textContent = text; el.classList.remove('typing'); return; }
            if (i <= text.length) { el.textContent = text.slice(0, i); i++; setTimeout(tick, speed); }
            else { el.classList.remove('typing'); done && done(); }
        })();
    }

    /* ── Build the nameplate letters, each with its own splash rig ── */
    var spans = [];
    NAME.split('').forEach(function (ch) {
        var s = document.createElement('span');
        s.textContent = ch;

        // Water-splash rig: 3 expanding rings + a burst of flying droplets,
        // dormant until the '.go' class fires the instant the letter lands.
        var splash = document.createElement('span');
        splash.className = 'letter-splash';
        ['ring1', 'ring2', 'ring3'].forEach(function (cls) {
            var ring = document.createElement('span');
            ring.className = 'splash-ring ' + cls;
            splash.appendChild(ring);
        });
        var dropCount = 6;
        for (var d = 0; d < dropCount; d++) {
            var drop = document.createElement('span');
            drop.className = 'splash-drop';
            var angle = (Math.PI * 2 / dropCount) * d + (Math.random() * 0.5 - 0.25);
            var dist  = 9 + Math.random() * 9;
            var dx    = Math.cos(angle) * dist;
            var dy    = Math.sin(angle) * dist - 7; // bias upward, like water kicking up
            drop.style.setProperty('--dx', dx.toFixed(1) + 'px');
            drop.style.setProperty('--dy', dy.toFixed(1) + 'px');
            drop.style.animationDelay = (Math.random() * 0.05).toFixed(2) + 's';
            splash.appendChild(drop);
        }
        s.appendChild(splash);

        brandEl.appendChild(s);
        spans.push(s);
    });

    // Fire the ripple + droplets for letter i (restarts the CSS animation
    // cleanly even if called more than once).
    function splashLetter(s) {
        var splash = s.querySelector('.letter-splash');
        if (!splash) return;
        splash.classList.remove('go');
        void splash.offsetWidth; // force reflow so the animation restarts
        splash.classList.add('go');
    }

    /* ── Reveal the login form + speak the closing line ──────── */
    function revealLogin() {
        if (finished) return;
        finished = true;
        overlay.classList.add('done');
        document.body.classList.add('intro-done');
        stage.classList.add('show');
        // focus username once it's in view
        setTimeout(function () {
            stage.classList.add('show');
            var u = stage.querySelector('input[name="username"]');
            if (u) { try { u.focus(); } catch (e) {} }
        }, 450);
        speak(LINE2);
        setTimeout(function () { if (overlay && overlay.parentNode) overlay.style.display = 'none'; }, 1000);
    }

    /* ── Skip ────────────────────────────────────────────────── */
    function skip() {
        if (finished) return;
        try { if ('speechSynthesis' in window) speechSynthesis.cancel(); } catch (e) {}
        finished = true;
        overlay.classList.add('done');
        document.body.classList.add('intro-done');
        stage.classList.add('show');
        setTimeout(function () { if (overlay) overlay.style.display = 'none'; }, 700);
    }
    if (skipBtn) skipBtn.addEventListener('click', function (e) { e.stopPropagation(); skip(); });
    window.addEventListener('keydown', function (e) { if (e.key === 'Escape') skip(); });

    /* ── Timeline ────────────────────────────────────────────── */
    function run() {
        audioCtx(); // best-effort start (may stay suspended until gesture)

        if (reduced) {
            spans.forEach(function (s) { s.classList.add('in'); });
            overlay.classList.add('reveal');
            taglineEl.textContent = LINE1;
            speakThen(LINE1, function () { setTimeout(revealLogin, 400); });
            return;
        }

        var step = 340;      // slower — each letter gets room to "splash"
        var landAt = 580;    // ms into the 1s dropIn keyframes where the letter actually touches down (~58%)
        spans.forEach(function (s, i) {
            setTimeout(function () {
                s.classList.add('in');
                // Ripple + droplets + sound fire together, right as the letter lands —
                // not at the start of the fall.
                setTimeout(function () {
                    s.classList.add('pulse');
                    playWave(i);
                    splashLetter(s);
                    setTimeout(function () { s.classList.remove('pulse'); }, 420);
                }, landAt);
            }, i * step);
        });

        var afterName = spans.length * step + 800;   // hold on the finished name
        setTimeout(function () { overlay.classList.add('reveal'); }, afterName - 400);

        setTimeout(function () {
            typeText(taglineEl, LINE1, 72);           // slower typing
            speakThen(LINE1, function () { setTimeout(revealLogin, 1100); });
        }, afterName);
    }

    // Kick off once voices are (likely) ready so the first line uses a good voice
    if ('speechSynthesis' in window && speechSynthesis.getVoices().length === 0) {
        var started = false;
        var go = function () { if (!started) { started = true; run(); } };
        speechSynthesis.onvoiceschanged = go;
        setTimeout(go, 550); // fallback if the event never fires
    } else {
        run();
    }
}());
</script>
<?php endif; ?>
</body>
</html>

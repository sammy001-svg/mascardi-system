<?php
define('APP_NAME', 'Mascardi Luxury Cars');
define('APP_VERSION', '1.0.0');
// Robust BASE_URL detection
if (!defined('BASE_URL')) {
    $protocol = (isset($_SERVER['HTTPS']) && ($_SERVER['HTTPS'] === 'on' || $_SERVER['HTTPS'] === 1) || isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    
    // Detect script path to handle subdirectory deployments
    $script = $_SERVER['SCRIPT_NAME'] ?? '';
    $dir = str_replace('\\', '/', dirname($script));
    
    // Strip trailing /modules/... or /client/... from the directory
    // This allows BASE_URL to point to the project root even when called from subfolders
    $basePath = preg_replace('/(\/(modules|portal|client|config|includes|assets|showroom))($|\/.*)/', '', $dir);
    $basePath = rtrim($basePath, '/');

    define('BASE_URL', $protocol . '://' . $host . $basePath);
}
define('BASE_PATH', dirname(__DIR__));

// Start session with hardened cookie settings
if (session_status() === PHP_SESSION_NONE) {
    $sessionPath = BASE_PATH . '/sessions';
    if (!is_dir($sessionPath)) {
        @mkdir($sessionPath, 0777, true);
    }
    if (is_writable($sessionPath)) {
        session_save_path($sessionPath);
    }
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => isset($_SERVER['HTTPS']),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

// ── Remember-me auto-login ───────────────────────────────────
if (!isset($_SESSION['auth_user']) && !empty($_COOKIE['rm_tok']) && function_exists('getDB')) {
    try {
        $db   = getDB();
        $hash = hash('sha256', $_COOKIE['rm_tok']);
        $stmt = $db->prepare("
            SELECT rt.id AS token_id, u.id, u.name, u.username, u.role,
                   u.linked_id, u.linked_type
            FROM remember_tokens rt
            JOIN users u ON u.id = rt.user_id
            WHERE rt.token_hash = ? AND rt.expires_at > NOW() AND u.status = 'active'
            LIMIT 1
        ");
        $stmt->execute([$hash]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            // Rotate the token to prevent replay attacks
            $newToken = bin2hex(random_bytes(32));
            $newHash  = hash('sha256', $newToken);
            $db->prepare("DELETE FROM remember_tokens WHERE id = ?")->execute([$row['token_id']]);
            $db->prepare("INSERT INTO remember_tokens (user_id, token_hash, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 10 YEAR))")
               ->execute([$row['id'], $newHash]);
            setcookie('rm_tok', $newToken, [
                'expires'  => time() + 10 * 365 * 86400,
                'path'     => '/',
                'httponly' => true,
                'samesite' => 'Lax',
                'secure'   => isset($_SERVER['HTTPS']),
            ]);
            session_regenerate_id(true);
            $_SESSION['auth_user'] = [
                'id'          => (int)$row['id'],
                'name'        => $row['name'],
                'username'    => $row['username'],
                'role'        => $row['role'],
                'linked_id'   => $row['linked_id'],
                'linked_type' => $row['linked_type'],
            ];
            $_SESSION['last_activity']    = time();
            $_SESSION['sess_regenerated'] = time();
            $db->prepare("UPDATE users SET last_login = NOW() WHERE id = ?")->execute([$row['id']]);
        } else {
            // Token expired or invalid — clear the stale cookie
            setcookie('rm_tok', '', time() - 3600, '/', '', isset($_SERVER['HTTPS']), true);
        }
        unset($db, $stmt, $row, $hash, $newToken, $newHash);
    } catch (Exception $e) {
        // Silently fail — user signs in manually
    }
}

// ── Session timeout (30 min inactivity) ─────────────────────
if (isset($_SESSION['auth_user'])) {
    $timeout = 1800;
    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > $timeout) {
        session_unset();
        session_destroy();
        session_start();
        setcookie(session_name(), '', time() - 3600, '/');
        header('Location: ' . (defined('BASE_URL') ? BASE_URL : '') . '/login.php?timeout=1');
        exit;
    }
    $_SESSION['last_activity'] = time();

    // Regenerate session ID every 15 minutes to prevent fixation
    if (!isset($_SESSION['sess_regenerated'])) {
        $_SESSION['sess_regenerated'] = time();
    } elseif ((time() - $_SESSION['sess_regenerated']) > 900) {
        session_regenerate_id(true);
        $_SESSION['sess_regenerated'] = time();
    }
}

// ── CSRF token ───────────────────────────────────────────────
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// ── Global error & exception handling ───────────────────────────────────────
if (!defined('APP_DEBUG')) define('APP_DEBUG', false);

set_error_handler(function(int $errno, string $errstr, string $errfile, int $errline): bool {
    if (!(error_reporting() & $errno)) return false;
    $msg = "PHP Error [{$errno}]: {$errstr} in {$errfile}:{$errline}";
    error_log($msg);
    if (defined('APP_DEBUG') && APP_DEBUG) {
        echo '<pre style="background:#fee;padding:10px;border:1px solid #f00;font-size:12px">' . htmlspecialchars($msg) . '</pre>';
    }
    return true;
});

set_exception_handler(function(Throwable $e): void {
    $msg = get_class($e) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine();
    error_log($msg);
    // Discard any partial HTML buffered by header.php's ob_start() so the error
    // page renders cleanly instead of being appended after a broken page layout.
    while (ob_get_level() > 0) { ob_end_clean(); }
    if (headers_sent()) { echo '<p style="color:red">An error occurred.</p>'; return; }
    http_response_code(500);
    if (defined('APP_DEBUG') && APP_DEBUG) {
        echo '<pre style="background:#fee;padding:20px;border:1px solid #f00;font-size:12px">';
        echo htmlspecialchars($msg . "\n\nStack Trace:\n" . $e->getTraceAsString());
        echo '</pre>';
    } else {
        include dirname(__DIR__) . '/includes/error500.php';
    }
    exit;
});

// Flash message helpers
function setFlash(string $type, string $message): void {
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function getFlash(): ?array {
    if (isset($_SESSION['flash'])) {
        $flash = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $flash;
    }
    return null;
}

// Get app setting from DB
function getSetting(string $key, string $default = ''): string {
    static $settings = null;
    if ($settings === null) {
        try {
            $db = getDB();
            $rows = $db->query("SELECT setting_key, setting_value FROM settings")->fetchAll();
            $settings = array_column($rows, 'setting_value', 'setting_key');
        } catch (Exception $e) {
            $settings = [];
        }
    }
    return $settings[$key] ?? $default;
}

/**
 * Resolve the uploaded company logo to a usable URL + filesystem path.
 *
 * The logo always lives at /assets/images/<file>. Historically the setting
 * value was stored as the full path ('/assets/images/company_logo.png'), but
 * the many templates across the system build '/assets/images/' . value and so
 * expect a bare filename ('company_logo.png'). To make every template render
 * correctly, this normalizes to a bare filename and self-heals a legacy value
 * once (a single UPDATE on the first page load after deploy).
 *
 * Returns ['url' => cache-busted URL or '', 'file' => abs path or '', 'exists' => bool].
 */
function companyLogo(): array {
    $raw = trim(getSetting('company_logo', ''));
    if ($raw === '') return ['url' => '', 'file' => '', 'exists' => false];

    $base = basename(str_replace('\\', '/', $raw));   // -> company_logo.png
    if ($base !== $raw) {
        // Legacy full-path value: rewrite it to the bare filename so the
        // templates that don't use this helper also start working.
        try {
            getDB()->prepare("UPDATE settings SET setting_value=? WHERE setting_key='company_logo'")
                   ->execute([$base]);
        } catch (\Throwable $_) {}
    }

    $rel    = '/assets/images/' . $base;
    $file   = BASE_PATH . $rel;
    $exists = is_file($file);
    return [
        'url'    => $exists ? BASE_URL . $rel . '?v=' . @filemtime($file) : '',
        'file'   => $file,
        'exists' => $exists,
    ];
}

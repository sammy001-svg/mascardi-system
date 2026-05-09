<?php
define('APP_NAME', 'Mascardi System');
define('APP_VERSION', '1.0.0');
// Robust BASE_URL detection
if (!defined('BASE_URL')) {
    $protocol = (isset($_SERVER['HTTPS']) && ($_SERVER['HTTPS'] === 'on' || $_SERVER['HTTPS'] === 1) || isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    // Clean host (remove any accidental protocol or path)
    $host = preg_replace('/^https?:\/\//i', '', $host);
    $host = explode('/', $host)[0];
    
    // Define BASE_URL (assuming project is in root of domain/subdomain)
    define('BASE_URL', $protocol . '://' . $host);
}
define('BASE_PATH', dirname(__DIR__));

// Start session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

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

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
    $basePath = preg_replace('/(\/(modules|client|config|includes|assets))($|\/.*)/', '', $dir);
    $basePath = rtrim($basePath, '/');

    define('BASE_URL', $protocol . '://' . $host . $basePath);
}
define('BASE_PATH', dirname(__DIR__));

// Start session
if (session_status() === PHP_SESSION_NONE) {
    $sessionPath = BASE_PATH . '/sessions';
    if (!is_dir($sessionPath)) {
        @mkdir($sessionPath, 0777, true);
    }
    if (is_writable($sessionPath)) {
        session_save_path($sessionPath);
    }
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

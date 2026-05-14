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
    $baseDir = ($dir === '/') ? '' : $dir;
    
    // If we are deep in a module, we need to go up to the root
    // But since this is in config/app.php, we can't easily use dirname($script) 
    // instead let's try a simpler approach: detect if 'modules' is in the path
    $basePath = preg_replace('/\/modules\/.*$/', '', $baseDir);
    $basePath = preg_replace('/\/client\/.*$/', '', $basePath);
    $basePath = rtrim($basePath, '/');

    define('BASE_URL', $protocol . '://' . $host . $basePath);
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

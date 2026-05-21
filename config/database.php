<?php
// Load .env file if it exists (keeps credentials out of source control)
$envFile = dirname(__DIR__) . '/.env';
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) continue;
        if (str_contains($line, '=')) {
            [$key, $val] = explode('=', $line, 2);
            $key = trim($key);
            $val = trim($val, " \t\"'");
            if (!array_key_exists($key, $_ENV)) {
                $_ENV[$key] = $val;
                putenv("{$key}={$val}");
            }
        }
    }
}

define('DB_HOST',    $_ENV['DB_HOST']    ?? 'localhost');
define('DB_NAME',    $_ENV['DB_NAME']    ?? 'mascardi_db');
define('DB_USER',    $_ENV['DB_USER']    ?? 'root');
define('DB_PASS',    $_ENV['DB_PASS']    ?? '');
define('DB_CHARSET', 'utf8mb4');
define('APP_DEBUG',  filter_var($_ENV['APP_DEBUG'] ?? false, FILTER_VALIDATE_BOOLEAN));

function getDB(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            $msg = APP_DEBUG ? htmlspecialchars($e->getMessage()) : 'Database connection failed. Please contact support.';
            die('<div style="font-family:sans-serif;padding:20px;color:#c00;background:#fff0f0;border:1px solid #fcc;border-radius:6px;margin:20px;">
                <strong>Database Connection Failed:</strong> ' . $msg . '
                <p style="margin-top:10px;font-size:13px;">Please check your <code>.env</code> file credentials.</p>
            </div>');
        }
    }
    return $pdo;
}

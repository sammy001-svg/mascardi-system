<?php
define('DB_HOST', 'localhost');
define('DB_NAME', 'mascardi_db');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_CHARSET', 'utf8mb4');

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
            die('<div style="font-family:sans-serif;padding:20px;color:#c00;background:#fff0f0;border:1px solid #fcc;border-radius:6px;margin:20px;">
                <strong>Database Connection Failed:</strong> ' . htmlspecialchars($e->getMessage()) . '
                <p style="margin-top:10px;font-size:13px;">Please check your database credentials in <code>config/database.php</code></p>
            </div>');
        }
    }
    return $pdo;
}

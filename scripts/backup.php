<?php
/**
 * Database Backup Utility
 *
 * Usage (command line):
 *   php scripts/backup.php
 *
 * Or via browser (admin only):
 *   http://your-site/scripts/backup.php
 *
 * Schedule via Windows Task Scheduler or cron:
 *   0 2 * * * php /path/to/mascardi/scripts/backup.php
 *
 * Backups are stored in /backups/ as timestamped .sql files.
 * Files older than 30 days are automatically deleted.
 */

$isCli = (PHP_SAPI === 'cli');

// Auth check when running via web
if (!$isCli) {
    require_once dirname(__DIR__) . '/includes/functions.php';
    requireLogin();
    requireRole('admin');
}

// Load DB config
$envFile = dirname(__DIR__) . '/.env';
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) continue;
        if (str_contains($line, '=')) {
            [$k, $v] = explode('=', $line, 2);
            putenv(trim($k) . '=' . trim($v, " \t\"'"));
        }
    }
}

$host   = getenv('DB_HOST') ?: 'localhost';
$dbName = getenv('DB_NAME') ?: 'mascardi_db';
$dbUser = getenv('DB_USER') ?: 'root';
$dbPass = getenv('DB_PASS') ?: '';

$backupDir = dirname(__DIR__) . '/backups';
if (!is_dir($backupDir)) mkdir($backupDir, 0750, true);

// Protect backups directory from web access
$htaccess = $backupDir . '/.htaccess';
if (!file_exists($htaccess)) {
    file_put_contents($htaccess, "Order deny,allow\nDeny from all\n");
}

$timestamp  = date('Y-m-d_H-i-s');
$filename   = "backup_{$dbName}_{$timestamp}.sql";
$outputPath = $backupDir . '/' . $filename;

// Use mysqldump if available
$mysqldump = trim(shell_exec('where mysqldump 2>nul') ?: shell_exec('which mysqldump 2>/dev/null') ?: '');

if ($mysqldump) {
    // Build the command — use --password= with empty string if no password
    $passArg = $dbPass ? "--password=" . escapeshellarg($dbPass) : '--password=';
    $cmd = sprintf(
        '"%s" --host=%s --user=%s %s --single-transaction --routines --events --triggers %s > %s 2>&1',
        escapeshellarg(trim($mysqldump)),
        escapeshellarg($host),
        escapeshellarg($dbUser),
        $passArg,
        escapeshellarg($dbName),
        escapeshellarg($outputPath)
    );
    exec($cmd, $output, $returnCode);

    if ($returnCode !== 0 || !file_exists($outputPath) || filesize($outputPath) < 100) {
        $error = "mysqldump failed (exit {$returnCode}). " . implode(' ', $output);
        if ($isCli) { echo "ERROR: {$error}\n"; exit(1); }
        die('<div style="color:red;padding:20px">' . htmlspecialchars($error) . '</div>');
    }
} else {
    // PHP fallback: export using PDO (tables only, no stored procedures)
    try {
        $pdo = new PDO("mysql:host={$host};dbname={$dbName};charset=utf8mb4", $dbUser, $dbPass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);

        $sql  = "-- Mascardi System Database Backup\n";
        $sql .= "-- Generated: " . date('Y-m-d H:i:s') . "\n";
        $sql .= "-- Database: {$dbName}\n\n";
        $sql .= "SET FOREIGN_KEY_CHECKS=0;\n\n";

        $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($tables as $table) {
            // Structure
            $createRow = $pdo->query("SHOW CREATE TABLE `{$table}`")->fetch(PDO::FETCH_NUM);
            $sql .= "DROP TABLE IF EXISTS `{$table}`;\n";
            $sql .= $createRow[1] . ";\n\n";

            // Data
            $rows = $pdo->query("SELECT * FROM `{$table}`")->fetchAll(PDO::FETCH_ASSOC);
            if (!empty($rows)) {
                $cols = '`' . implode('`, `', array_keys($rows[0])) . '`';
                $sql .= "INSERT INTO `{$table}` ({$cols}) VALUES\n";
                $vals = [];
                foreach ($rows as $row) {
                    $vals[] = '(' . implode(', ', array_map(fn($v) => $v === null ? 'NULL' : $pdo->quote((string)$v), $row)) . ')';
                }
                $sql .= implode(",\n", $vals) . ";\n\n";
            }
        }

        $sql .= "SET FOREIGN_KEY_CHECKS=1;\n";
        file_put_contents($outputPath, $sql);
    } catch (PDOException $e) {
        $error = 'Backup failed: ' . $e->getMessage();
        if ($isCli) { echo "ERROR: {$error}\n"; exit(1); }
        die('<div style="color:red;padding:20px">' . htmlspecialchars($error) . '</div>');
    }
}

// Delete backups older than 30 days
$deleted = 0;
foreach (glob($backupDir . '/backup_*.sql') as $oldFile) {
    if (filemtime($oldFile) < strtotime('-30 days')) {
        unlink($oldFile);
        $deleted++;
    }
}

$size = number_format(filesize($outputPath) / 1024, 1) . ' KB';
$msg  = "Backup created: {$filename} ({$size}). Deleted {$deleted} old backup(s).";

if ($isCli) {
    echo $msg . "\n";
    exit(0);
}

// Web response
setFlash('success', $msg);

// Offer download
if (isset($_GET['download'])) {
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . filesize($outputPath));
    readfile($outputPath);
    exit;
}

redirect(BASE_URL . '/modules/settings/index.php');

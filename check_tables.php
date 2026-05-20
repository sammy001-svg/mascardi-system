<?php
require 'config/app.php';
require 'config/database.php';
require 'includes/functions.php';

$db = getDB();

// Get all tables currently in DB
$existing = $db->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);

// Scan all PHP files for FROM table references
$phpFiles = new RecursiveIteratorIterator(new RecursiveDirectoryIterator('.'));
$tables = [];
foreach ($phpFiles as $file) {
    if ($file->getExtension() !== 'php') continue;
    $path = $file->getPathname();
    if (strpos($path, './vendor') !== false) continue;
    $content = file_get_contents($path);
    preg_match_all('/\bFROM\s+`?([a-z][a-z_0-9]+)`?/i', $content, $m);
    preg_match_all('/\bJOIN\s+`?([a-z][a-z_0-9]+)`?/i', $content, $m2);
    preg_match_all('/\bINSERT\s+INTO\s+`?([a-z][a-z_0-9]+)`?/i', $content, $m3);
    preg_match_all('/\bUPDATE\s+`?([a-z][a-z_0-9]+)`?\s+SET/i', $content, $m4);
    foreach (array_merge($m[1], $m2[1], $m3[1], $m4[1]) as $t) {
        $tables[strtolower($t)] = true;
    }
}

$tables = array_keys($tables);
sort($tables);

echo "=== TABLES USED IN CODE ===\n";
$missing = [];
foreach ($tables as $t) {
    $exists = in_array($t, $existing);
    echo ($exists ? "  [OK]" : "  [MISSING]") . " $t\n";
    if (!$exists) $missing[] = $t;
}

echo "\n=== MISSING TABLES ===\n";
if (empty($missing)) {
    echo "None! All tables exist.\n";
} else {
    foreach ($missing as $t) echo "  - $t\n";
}

<?php
require_once __DIR__ . '/../config/database.php';
$db = getDB();
$sql = file_get_contents(__DIR__ . '/../migrations/fix_service_bookings_schema.sql');
try {
    $db->exec($sql);
    echo "SQL Applied Successfully locally.\n";
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

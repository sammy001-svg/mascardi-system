<?php
require_once __DIR__ . '/../config/database.php';

try {
    $db = getDB();
    
    echo "Altering users table role column...\n";
    $sql = "ALTER TABLE users MODIFY COLUMN role ENUM('admin','workshop_manager','sales_person','sales_officer','manager','mechanic','driver') NOT NULL DEFAULT 'mechanic'";
    $db->exec($sql);
    echo "Altered table successfully!\n";
    
    // Let's verify
    $stmt = $db->query("DESCRIBE users");
    $cols = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($cols as $col) {
        if ($col['Field'] === 'role') {
            echo "New Column definition: " . json_encode($col, JSON_PRETTY_PRINT) . "\n";
        }
    }
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}

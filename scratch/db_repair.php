<?php
require_once __DIR__ . '/../config/database.php';
$db = getDB();

function columnExists($db, $table, $column) {
    try {
        $rs = $db->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
        return $rs->rowCount() > 0;
    } catch (PDOException $e) {
        return false;
    }
}

try {
    echo "Starting database schema repairs...\n";

    // 1. Repair invoices table
    if (!columnExists($db, 'invoices', 'client_id')) {
        echo "Adding client_id column to invoices table...\n";
        $db->exec("ALTER TABLE `invoices` ADD COLUMN `client_id` INT NULL AFTER `id`");
        echo "client_id column added to invoices.\n";
        
        try {
            echo "Adding foreign key constraint fk_inv_client to invoices...\n";
            $db->exec("ALTER TABLE `invoices` ADD CONSTRAINT `fk_inv_client` FOREIGN KEY (`client_id`) REFERENCES `clients`(`id`) ON DELETE SET NULL");
            echo "Foreign key constraint added to invoices.\n";
        } catch (PDOException $e) {
            echo "Warning adding foreign key constraint: " . $e->getMessage() . "\n";
        }
    } else {
        echo "client_id column already exists in invoices table.\n";
    }

    // 2. Repair quotations table
    if (!columnExists($db, 'quotations', 'client_id')) {
        echo "Adding client_id column to quotations table...\n";
        $db->exec("ALTER TABLE `quotations` ADD COLUMN `client_id` INT NULL AFTER `id`");
        echo "client_id column added to quotations.\n";
        
        try {
            echo "Adding foreign key constraint fk_quot_client to quotations...\n";
            $db->exec("ALTER TABLE `quotations` ADD CONSTRAINT `fk_quot_client` FOREIGN KEY (`client_id`) REFERENCES `clients`(`id`) ON DELETE SET NULL");
            echo "Foreign key constraint added to quotations.\n";
        } catch (PDOException $e) {
            echo "Warning adding foreign key constraint: " . $e->getMessage() . "\n";
        }
    } else {
        echo "client_id column already exists in quotations table.\n";
    }

    echo "Database schema repairs completed successfully.\n";
} catch (PDOException $e) {
    echo "Fatal Error running repairs: " . $e->getMessage() . "\n";
}

<?php
require 'config/app.php';
require 'config/database.php';
require 'includes/functions.php';

$db = getDB();

echo "=== DESCRIBE car_transfers ===\n";
try {
    foreach($db->query('DESCRIBE car_transfers')->fetchAll(PDO::FETCH_ASSOC) as $row) {
        echo $row['Field'] . ' (' . $row['Type'] . ")\n";
    }
} catch (Exception $e) {
    echo "Error describing car_transfers: " . $e->getMessage() . "\n";
}

echo "\n=== DESCRIBE car_intake ===\n";
try {
    foreach($db->query('DESCRIBE car_intake')->fetchAll(PDO::FETCH_ASSOC) as $row) {
        echo $row['Field'] . ' (' . $row['Type'] . ")\n";
    }
} catch (Exception $e) {
    echo "Error describing car_intake: " . $e->getMessage() . "\n";
}

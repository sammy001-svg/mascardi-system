<?php
require 'config/app.php';
require 'config/database.php';
require 'includes/functions.php';

$db = getDB();
try {
    $cars = $db->query("SELECT id, chassis_number, make, model, year, car_type, owner_name FROM cars ORDER BY make,model")->fetchAll();
    echo "Cars found: " . count($cars) . "\n";
    foreach ($cars as $c) {
        echo "  - {$c['make']} {$c['model']} {$c['year']} | {$c['chassis_number']}\n";
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

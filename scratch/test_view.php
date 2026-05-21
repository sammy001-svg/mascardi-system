<?php
require 'config/app.php';
require 'config/database.php';
require 'includes/functions.php';

$db = getDB();

echo "=== VERIFYING VEHICLE DETAILS QUERIES ===\n";

try {
    // 1. Get first car ID
    $carRow = $db->query("SELECT id, make, model FROM cars LIMIT 1")->fetch();
    if (!$carRow) {
        echo "No cars in database to test.\n";
        exit(0);
    }
    
    $id = $carRow['id'];
    echo "Testing queries for vehicle: {$carRow['make']} {$carRow['model']} (ID: $id)\n";

    // Query 1: Car Details
    $car = $db->prepare("SELECT c.*, l.name AS location_name, cl.phone AS owner_phone FROM cars c LEFT JOIN locations l ON l.id = c.location_id LEFT JOIN clients cl ON cl.id = c.client_id WHERE c.id=?");
    $car->execute([$id]);
    $car = $car->fetch();
    echo "[OK] Car details loaded successfully. Owner Phone: " . ($car['owner_phone'] ?? 'None') . "\n";

    // Query 2: Intake Details
    $intake   = $db->prepare("SELECT * FROM car_intake WHERE car_id=? ORDER BY id DESC LIMIT 1");
    $intake->execute([$id]); 
    $intake = $intake->fetch();
    echo "[OK] Intake details loaded successfully.\n";

    // Query 3: Transfers
    $transfers = $db->prepare("SELECT ct.*, d.name AS transported_by FROM car_transfers ct LEFT JOIN drivers d ON d.id = ct.driver_id WHERE ct.car_id=? ORDER BY ct.id DESC");
    $transfers->execute([$id]); 
    $transfers = $transfers->fetchAll();
    echo "[OK] Transfer details (" . count($transfers) . ") loaded successfully.\n";

    // Query 4: Assessments
    $assessments = $db->prepare("SELECT ca.*, m.name AS mechanic_name FROM car_assessments ca LEFT JOIN mechanics m ON m.id=ca.mechanic_id WHERE ca.car_id=? ORDER BY ca.id DESC");
    $assessments->execute([$id]); 
    $assessments = $assessments->fetchAll();
    echo "[OK] Assessments (" . count($assessments) . ") loaded successfully.\n";

    // Query 5: Jobs
    $jobs = $db->prepare("SELECT j.*, m.name AS mechanic_name FROM workshop_jobs j LEFT JOIN mechanics m ON m.id=j.mechanic_id WHERE j.car_id=? ORDER BY j.id DESC");
    $jobs->execute([$id]); 
    $jobs = $jobs->fetchAll();
    echo "[OK] Jobs (" . count($jobs) . ") loaded successfully.\n";

    echo "\nSUCCESS: All vehicle details page queries executed perfectly with no PDOException!\n";
} catch (\Throwable $e) {
    echo "\nFAILURE: " . $e->getMessage() . "\n";
    exit(1);
}

<?php
require 'config/app.php';
require 'config/database.php';
require 'includes/functions.php';

try {
    $db = getDB();
    $car = $db->query("SELECT id FROM cars LIMIT 1")->fetch();
    if (!$car) {
        // Create a dummy car
        $db->query("INSERT INTO cars (chassis_number, make, model, year) VALUES ('TEST1234', 'Toyota', 'Corolla', 2020)");
        $carId = $db->lastInsertId();
    } else {
        $carId = $car['id'];
    }

    $jobNumber = nextNumber('workshop_jobs', 'job_number', getSetting('job_prefix','JOB'));
    
    $mechId = null;
    $assessId = null;
    $start = null;
    $end = null;
    $status = 'pending';
    $priority = 'normal';
    $desc = 'test';
    $notes = 'test';
    
    $stmt = $db->prepare("INSERT INTO workshop_jobs (job_number,car_id,mechanic_id,assessment_id,start_date,end_date,status,priority,description,notes) VALUES (?,?,?,?,?,?,?,?,?,?)");
    $stmt->execute([$jobNumber,$carId,$mechId,$assessId,$start,$end,$status,$priority,$desc,$notes]);
    echo "Inserted Job ID: " . $db->lastInsertId() . "\n";

    $db->prepare("UPDATE cars SET status='in_workshop' WHERE id=?")->execute([$carId]);
    echo "Updated Car ID: " . $carId . "\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

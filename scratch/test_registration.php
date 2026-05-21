<?php
require 'config/app.php';
require 'config/database.php';
require 'includes/functions.php';

$db = getDB();

echo "=== STARTING CLIENT + VEHICLE REGISTRATION TEST ===\n";

$d = [
    'name' => 'Jane Smith',
    'email' => 'jane.smith@example.com',
    'phone' => '0789123456',
    'id_number' => 'ID-999888777',
    'portal_enabled' => 0,
    'notes' => 'Test client with vehicle details',
    'status' => 'active',
    'car_make' => 'Toyota',
    'car_model' => 'Harrier',
    'car_year' => '2021',
    'car_registration' => 'KDM 567Z',
    'car_chassis' => 'CHASSIS-JANE-SMITH-777'
];

// 1. Check if chassis number exists to avoid duplicate constraint if run multiple times
$checkChassis = $db->prepare("SELECT COUNT(*) FROM cars WHERE chassis_number = ?");
$checkChassis->execute([$d['car_chassis']]);
if ($checkChassis->fetchColumn() > 0) {
    echo "Test client already registered. Deleting to perform a clean insert...\n";
    // Delete car and client
    $db->prepare("DELETE FROM cars WHERE chassis_number = ?")->execute([$d['car_chassis']]);
    $db->prepare("DELETE FROM clients WHERE email = ?")->execute([$d['email']]);
}

try {
    $db->beginTransaction();
    
    // Insert client
    $db->prepare("INSERT INTO clients (name,email,phone,id_number,portal_password,portal_enabled,status,notes) VALUES (?,?,?,?,?,?,?,?)")
       ->execute([$d['name'],$d['email'],$d['phone'],$d['id_number'],null,$d['portal_enabled'],$d['status'],$d['notes']]);
    $newId = $db->lastInsertId();
    echo "Inserted client with ID: $newId\n";

    // Insert linked vehicle
    $db->prepare("INSERT INTO cars (chassis_number,registration_number,make,model,year,car_type,owner_name,client_id,location_id,status) VALUES (?,?,?,?,?,?,?,?,?,?)")
       ->execute([$d['car_chassis'],$d['car_registration'],$d['car_make'],$d['car_model'],(int)$d['car_year'],'client',$d['name'],$newId,null,'completed']);
    
    $db->commit();
    echo "Successfully saved client AND vehicle atomically in a single transaction!\n";
} catch (\Throwable $e) {
    $db->rollBack();
    echo "Save failed: " . $e->getMessage() . "\n";
    exit(1);
}

echo "\n=== VERIFYING COHESIVE SERVICE BOOKING LOOKUP ===\n";
try {
    $clients = $db->query("
        SELECT c.id, c.name, c.email, c.phone,
               ca.id AS car_id, ca.make AS car_make, ca.model AS car_model, ca.registration_number AS car_reg
        FROM clients c
        LEFT JOIN cars ca ON ca.client_id = c.id AND ca.id = (
            SELECT MIN(id) FROM cars WHERE client_id = c.id
        )
        WHERE c.status='active' AND c.id = $newId
    ")->fetchAll(PDO::FETCH_ASSOC);

    if (count($clients) > 0) {
        $c = $clients[0];
        echo "Successfully retrieved client from service booking lookup query:\n";
        echo "  - Client Name: {$c['name']}\n";
        echo "  - Client Email: {$c['email']}\n";
        echo "  - Client Phone: {$c['phone']}\n";
        echo "  - Linked Car ID: {$c['car_id']}\n";
        echo "  - Linked Car Make: {$c['car_make']}\n";
        echo "  - Linked Car Model: {$c['car_model']}\n";
        echo "  - Linked Car Reg: {$c['car_reg']}\n";
        if ($c['car_make'] === 'Toyota' && $c['car_model'] === 'Harrier' && $c['car_reg'] === 'KDM 567Z') {
            echo "SUCCESS: Client and vehicle data matched perfectly!\n";
        } else {
            echo "ERROR: Data mismatch!\n";
        }
    } else {
        echo "ERROR: Client not found in active list!\n";
    }
} catch (Exception $e) {
    echo "Query Error: " . $e->getMessage() . "\n";
}

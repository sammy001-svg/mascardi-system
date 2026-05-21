<?php
require 'config/app.php';
require 'config/database.php';
require 'includes/functions.php';

$db = getDB();
try {
    $clients = $db->query("
        SELECT c.id, c.name, c.email, c.phone,
               ca.id AS car_id, ca.make AS car_make, ca.model AS car_model, ca.registration_number AS car_reg
        FROM clients c
        LEFT JOIN cars ca ON ca.client_id = c.id AND ca.id = (
            SELECT MIN(id) FROM cars WHERE client_id = c.id
        )
        ORDER BY c.name
    ")->fetchAll(PDO::FETCH_ASSOC);

    echo "Clients found: " . count($clients) . "\n";
    foreach ($clients as $c) {
        echo "  - Client ID: {$c['id']} | Name: {$c['name']} | Email: {$c['email']}\n";
        if ($c['car_id']) {
            echo "    * Car: {$c['car_make']} {$c['car_model']} [Reg: {$c['car_reg']}, ID: {$c['car_id']}]\n";
        } else {
            echo "    * No registered car\n";
        }
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

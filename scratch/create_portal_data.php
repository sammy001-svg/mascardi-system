<?php
require_once __DIR__ . '/../config/database.php';
$db = getDB();

// 1. Get test client
$client = $db->query("SELECT id FROM clients WHERE email='test@example.com' LIMIT 1")->fetch();
$cid = $client['id'];

// 2. Get a car or create one
$car = $db->query("SELECT id FROM cars WHERE chassis_number='TEST-PORTAL-001' LIMIT 1")->fetch();
if (!$car) {
    $db->prepare("INSERT INTO cars (chassis_number, make, model, year, car_type, owner_name, client_id, location_id, status) VALUES (?,?,?,?,?,?,?,?,?)")
       ->execute(['TEST-PORTAL-001', 'Toyota', 'Camry', 2022, 'client', 'Test Client', $cid, 1, 'in_workshop']);
    $carId = $db->lastInsertId();
} else {
    $carId = $car['id'];
    $db->prepare("UPDATE cars SET client_id=? WHERE id=?")->execute([$cid, $carId]);
}

// 3. Create Quotation
$qNum = 'QT-TEST-001';
$q = $db->prepare("SELECT id FROM quotations WHERE quotation_number=? LIMIT 1");
$q->execute([$qNum]);
$quote = $q->fetch();
if (!$quote) {
    $db->prepare("INSERT INTO quotations (quotation_number, car_id, client_id, date, customer_name, subtotal, tax_rate, tax_amount, total, status) VALUES (?,?,?,?,?,?,?,?,?,?)")
       ->execute([$qNum, $carId, $cid, date('Y-m-d'), 'Test Client', 1000, 16, 160, 1160, 'sent']);
    $qId = $db->lastInsertId();
    $db->prepare("INSERT INTO quotation_items (quotation_id, item_type, description, quantity, unit_price, total) VALUES (?,?,?,?,?,?)")
       ->execute([$qId, 'service', 'Test Service', 1, 1000, 1000]);
}

// 4. Create Invoice
$iNum = 'INV-TEST-001';
$i = $db->prepare("SELECT id FROM invoices WHERE invoice_number=? LIMIT 1");
$i->execute([$iNum]);
$invoice = $i->fetch();
if (!$invoice) {
    $db->prepare("INSERT INTO invoices (invoice_number, car_id, client_id, date, customer_name, subtotal, tax_rate, tax_amount, total, status) VALUES (?,?,?,?,?,?,?,?,?,?)")
       ->execute([$iNum, $carId, $cid, date('Y-m-d'), 'Test Client', 2000, 16, 320, 2320, 'unpaid']);
    $invId = $db->lastInsertId();
    $db->prepare("INSERT INTO invoice_items (invoice_id, item_type, description, quantity, unit_price, total) VALUES (?,?,?,?,?,?)")
       ->execute([$invId, 'part', 'Test Part', 2, 1000, 2000]);
}

echo "Test data created for $cid\n";

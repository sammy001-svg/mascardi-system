<?php
require_once __DIR__ . '/../config/database.php';
$db = getDB();
$pass = password_hash('password', PASSWORD_DEFAULT);
$db->query("UPDATE clients SET portal_enabled=1, portal_password='$pass', status='active' WHERE email IS NOT NULL AND email != '' LIMIT 1");
$client = $db->query("SELECT email FROM clients WHERE portal_enabled=1 LIMIT 1")->fetch();
echo "Client Email: " . $client['email'] . "\nPassword: password\n";

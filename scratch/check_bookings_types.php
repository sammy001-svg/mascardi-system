<?php
require_once __DIR__ . '/../config/database.php';
$db = getDB();
$cols = $db->query("DESCRIBE service_bookings")->fetchAll(PDO::FETCH_ASSOC);
foreach($cols as $c) {
    echo $c['Field'] . " | " . $c['Type'] . "\n";
}

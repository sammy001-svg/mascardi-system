<?php
require 'config/app.php';
require 'config/database.php';
require 'includes/functions.php';

$db = getDB();
foreach($db->query('SELECT id, name FROM locations')->fetchAll(PDO::FETCH_ASSOC) as $row) {
    echo $row['id'] . ': ' . $row['name'] . "\n";
}

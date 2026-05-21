<?php
require 'config/app.php';
require 'config/database.php';
require 'includes/functions.php';

$db = getDB();
foreach($db->query('DESCRIBE cars')->fetchAll(PDO::FETCH_ASSOC) as $row) {
    echo $row['Field'] . ' (' . $row['Type'] . ')\n';
}

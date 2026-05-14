<?php
require_once __DIR__ . '/../config/database.php';
$db = getDB();
$cols = $db->query("DESCRIBE cars")->fetchAll(PDO::FETCH_COLUMN);
echo implode("\n", $cols);

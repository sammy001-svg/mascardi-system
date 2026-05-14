<?php
require_once __DIR__ . '/../config/database.php';
$db = getDB();
$users = $db->query("SELECT username, role FROM users")->fetchAll(PDO::FETCH_ASSOC);
print_r($users);

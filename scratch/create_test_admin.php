<?php
require_once __DIR__ . '/../config/database.php';
$db = getDB();
$pass = password_hash('admin123', PASSWORD_DEFAULT);
$db->prepare("REPLACE INTO users (id, name, username, password, role, status) VALUES (999, 'Test Admin', 'testadmin', ?, 'admin', 'active')")
   ->execute([$pass]);
echo "Test admin created: testadmin / admin123\n";

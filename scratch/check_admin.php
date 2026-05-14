<?php
require_once __DIR__ . '/../config/database.php';

try {
    $db = getDB();
    $stmt = $db->query("SELECT username, role FROM users WHERE role='admin'");
    $admins = $stmt->fetchAll();
    
    if (empty($admins)) {
        echo "No admin users found.";
    } else {
        foreach ($admins as $admin) {
            echo "Username: " . $admin['username'] . " (Role: " . $admin['role'] . ")\n";
        }
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}

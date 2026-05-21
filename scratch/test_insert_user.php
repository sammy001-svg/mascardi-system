<?php
require_once __DIR__ . '/../config/database.php';

try {
    $db = getDB();
    $name = "Test Workshop Manager";
    $username = "test_wm";
    $role = "workshop_manager";
    $pass = password_hash("password123", PASSWORD_DEFAULT);
    
    // Delete if exists first
    $db->prepare("DELETE FROM users WHERE username = ?")->execute([$username]);
    
    // Insert new
    $stmt = $db->prepare("INSERT INTO users (name, username, password, role, status) VALUES (?, ?, ?, ?, 'active')");
    $stmt->execute([$name, $username, $pass, $role]);
    
    echo "Successfully inserted user with role '$role'!\n";
    
    // Select it back to verify
    $stmt = $db->prepare("SELECT id, username, name, role FROM users WHERE username = ?");
    $stmt->execute([$username]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "Fetched from DB: " . json_encode($user, JSON_PRETTY_PRINT) . "\n";
    
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}

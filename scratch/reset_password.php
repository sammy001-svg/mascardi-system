<?php
require_once __DIR__ . '/../config/database.php';

try {
    $db = getDB();
    $username = 'Mascardi';
    $newPassword = 'password';
    $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
    
    $stmt = $db->prepare("UPDATE users SET password = ? WHERE username = ?");
    $stmt->execute([$hashedPassword, $username]);
    
    if ($stmt->rowCount() > 0) {
        echo "Password for user '$username' has been reset to '$newPassword'.";
    } else {
        // Maybe the user doesn't exist? Let's try to create it if it doesn't.
        $stmt = $db->prepare("SELECT id FROM users WHERE username = ?");
        $stmt->execute([$username]);
        if (!$stmt->fetch()) {
             $db->prepare("INSERT INTO users (name, username, password, role) VALUES (?, ?, ?, 'admin')")
                ->execute(['Mascardi Admin', $username, $hashedPassword]);
             echo "User '$username' not found, so it was created with password '$newPassword'.";
        } else {
             echo "User '$username' found but password was already set (or something went wrong).";
        }
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}

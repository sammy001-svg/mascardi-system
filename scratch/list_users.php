<?php
require_once __DIR__ . '/../config/database.php';

try {
    $db = getDB();
    $stmt = $db->query("SELECT id, username, name, role, status FROM users");
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (empty($users)) {
        echo "NO_USERS";
    } else {
        echo json_encode($users, JSON_PRETTY_PRINT);
    }
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage();
}

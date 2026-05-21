<?php
require_once __DIR__ . '/../config/database.php';

try {
    $db = getDB();
    $stmt = $db->query("DESCRIBE users");
    $cols = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($cols as $col) {
        if ($col['Field'] === 'role') {
            echo json_encode($col, JSON_PRETTY_PRINT);
        }
    }
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage();
}

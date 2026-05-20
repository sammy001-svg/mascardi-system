<?php
require 'config/app.php';
require 'config/database.php';
require 'includes/functions.php';

$db = getDB();
try {
    $stmt = $db->prepare("DELETE FROM cars WHERE id=?");
    $stmt->execute([7]); // Let's try to delete a car (Audi Q5)
    echo "Deleted successfully. Rows affected: " . $stmt->rowCount() . "\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}

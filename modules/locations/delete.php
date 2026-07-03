<?php
require_once __DIR__ . '/../../includes/functions.php';
requireRole(['admin']);
$db = getDB();

$id = (int)($_GET['id'] ?? 0);
if (!$id) {
    setFlash('error', 'Invalid location.');
    redirect('index.php');
}

// Block if location has vehicles
$carCount = (int)$db->prepare("SELECT COUNT(*) FROM cars WHERE location_id=?")->execute([$id]) ?
            (int)$db->query("SELECT COUNT(*) FROM cars WHERE location_id={$id}")->fetchColumn() : 0;

$carStmt = $db->prepare("SELECT COUNT(*) FROM cars WHERE location_id = ?");
$carStmt->execute([$id]);
$carCount = (int)$carStmt->fetchColumn();

if ($carCount > 0) {
    setFlash('error', 'Cannot delete: this location still has ' . $carCount . ' vehicle(s) assigned to it.');
    redirect('index.php');
}

// Block if location has sub-locations
$subStmt = $db->prepare("SELECT COUNT(*) FROM locations WHERE parent_id = ?");
$subStmt->execute([$id]);
$subCount = (int)$subStmt->fetchColumn();

if ($subCount > 0) {
    setFlash('error', 'Cannot delete: this location has ' . $subCount . ' sub-location(s). Delete or reassign them first.');
    redirect('index.php');
}

$stmt = $db->prepare("DELETE FROM locations WHERE id = ?");
if ($stmt->execute([$id])) {
    logActivity('delete', 'locations', $id, 'Deleted location');
    setFlash('success', 'Location deleted.');
} else {
    setFlash('error', 'Failed to delete location.');
}

redirect('index.php');

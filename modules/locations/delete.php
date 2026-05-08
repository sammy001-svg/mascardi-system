<?php
require_once __DIR__ . '/../../includes/functions.php';
requireRole(['admin']);
$db = getDB();

$id = (int)($_GET['id'] ?? 0);

// Check if location has cars
$carCount = $db->prepare("SELECT COUNT(*) FROM cars WHERE location_id=?");
$carCount->execute([$id]);
if ($carCount->fetchColumn() > 0) {
    setFlash('error', 'Cannot delete location: It still contains vehicles.');
    redirect('index.php');
}

$stmt = $db->prepare("DELETE FROM locations WHERE id=?");
if ($stmt->execute([$id])) {
    logActivity('delete', 'locations', $id, 'Deleted location');
    setFlash('success', 'Location deleted.');
} else {
    setFlash('error', 'Failed to delete location.');
}

redirect('index.php');

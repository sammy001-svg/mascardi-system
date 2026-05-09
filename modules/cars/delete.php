<?php
require_once __DIR__ . '/../../includes/functions.php';
requireLogin(); requireRole('admin');
$id = (int)($_GET['id'] ?? 0);
if ($id) {
    $db = getDB();
    $car = $db->prepare("SELECT make,model FROM cars WHERE id=?")->execute([$id]) ? $db->prepare("SELECT make,model FROM cars WHERE id=?"): null;
    $db->prepare("DELETE FROM cars WHERE id=?")->execute([$id]);
    setFlash('success','Car deleted successfully.');
}
redirect(BASE_URL . '/modules/cars/index.php');

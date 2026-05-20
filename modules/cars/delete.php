<?php
require_once __DIR__ . '/../../includes/functions.php';
requireLogin(); requireRole('admin');
$id = (int)($_GET['id'] ?? 0);
if ($id) {
    $db = getDB();
    try {
        $stmt = $db->prepare("SELECT make, model FROM cars WHERE id=?");
        $stmt->execute([$id]);
        $car = $stmt->fetch();
        
        $db->prepare("DELETE FROM cars WHERE id=?")->execute([$id]);
        $carName = $car ? "{$car['make']} {$car['model']}" : "Car";
        logActivity('delete', 'cars', $id, "Deleted car: $carName");
        setFlash('success', 'Car deleted successfully.');
    } catch (\PDOException $e) {
        if ($e->getCode() == '23000') {
            setFlash('danger', 'Cannot delete this car because it has associated records (e.g. jobs, assessments, or invoices).');
        } else {
            setFlash('danger', 'Database error: ' . $e->getMessage());
        }
    }
}
redirect(BASE_URL . '/modules/cars/index.php');

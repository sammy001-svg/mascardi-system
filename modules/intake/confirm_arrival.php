<?php
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
canWrite('intake') || die('Permission denied.');
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $db = getDB();
    $transferId     = (int)($_POST['transfer_id'] ?? 0);
    $carId          = (int)($_POST['car_id'] ?? 0);
    $arrivalDate    = $_POST['arrival_date'] ?? '';
    $arrivalMileage = $_POST['arrival_mileage'] ? (int)$_POST['arrival_mileage'] : null;
    $arrivalCond    = trim($_POST['arrival_condition'] ?? '');

    if ($transferId) {
        $db->prepare("UPDATE car_transfers SET status='arrived', arrival_date=?, arrival_mileage=?, arrival_condition=? WHERE id=?")->execute([$arrivalDate, $arrivalMileage, $arrivalCond, $transferId]);
        if ($carId) {
            $db->prepare("UPDATE cars SET status='arrived' WHERE id=?")->execute([$carId]);
        }
        logActivity('update', 'intake', $carId, "Arrival confirmed for car #{$carId} — transfer #{$transferId}");
        setFlash('success', 'Arrival confirmed. Car is now in Nairobi.');
    }
    // Get the intake id to redirect back
    $intake = $db->prepare("SELECT id FROM car_intake WHERE car_id=? ORDER BY id DESC LIMIT 1");
    $intake->execute([$carId]);
    $intake = $intake->fetch();
    redirect(BASE_URL . '/modules/intake/view.php?id=' . ($intake['id'] ?? 1));
}
redirect(BASE_URL . '/modules/intake/index.php');

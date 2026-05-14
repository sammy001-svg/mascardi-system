<?php
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
canAccess('service_bookings') || die('Access denied.');
canEditDelete() || die('Permission denied.');

$id = (int)($_GET['id'] ?? 0);
if (!$id) redirect('index.php');

$db = getDB();
$sb = $db->prepare("SELECT * FROM service_bookings WHERE id = ?");
$sb->execute([$id]);
$booking = $sb->fetch();

if (!$booking) {
    setFlash('error', "Booking not found.");
    redirect('index.php');
}

try {
    $db->prepare("DELETE FROM service_bookings WHERE id = ?")->execute([$id]);
    setFlash('success', "Booking {$booking['booking_number']} deleted.");
} catch (\Throwable $e) {
    setFlash('error', "Delete failed: " . $e->getMessage());
}

redirect('index.php');

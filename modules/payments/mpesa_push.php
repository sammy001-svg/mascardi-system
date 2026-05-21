<?php
/**
 * Initiate M-Pesa STK Push for an invoice or service booking.
 * Called via AJAX from invoice/booking view pages.
 */
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/mpesa.php';
requireLogin();
verifyCsrf();

header('Content-Type: application/json');

$invoiceId  = (int)($_POST['invoice_id']  ?? 0);
$bookingId  = (int)($_POST['booking_id']  ?? 0);
$phone      = trim($_POST['phone']        ?? '');
$amount     = (float)($_POST['amount']    ?? 0);

if (!$phone || $amount <= 0) {
    echo json_encode(['success' => false, 'error' => 'Phone number and amount are required.']);
    exit;
}

if (!$invoiceId && !$bookingId) {
    echo json_encode(['success' => false, 'error' => 'No invoice or booking specified.']);
    exit;
}

$db  = getDB();
$ref = 'PAYMENT';
$desc = 'Mascardi Payment';

if ($invoiceId) {
    $inv = $db->prepare("SELECT invoice_number, total, amount_paid FROM invoices WHERE id=?");
    $inv->execute([$invoiceId]);
    $inv = $inv->fetch();
    if (!$inv) { echo json_encode(['success' => false, 'error' => 'Invoice not found.']); exit; }
    $ref  = $inv['invoice_number'];
    $desc = 'Inv ' . $inv['invoice_number'];
}

if ($bookingId) {
    $bk = $db->prepare("SELECT booking_number FROM service_bookings WHERE id=?");
    $bk->execute([$bookingId]);
    $bk = $bk->fetch();
    if ($bk) { $ref = $bk['booking_number']; $desc = 'Booking ' . $bk['booking_number']; }
}

// Initiate STK Push
$push = mpesaStkPush($phone, $amount, $ref, $desc);

if (!$push['success']) {
    echo json_encode(['success' => false, 'error' => $push['error']]);
    exit;
}

// Record the payment as pending
$payNumber = nextNumber('payments', 'payment_number', getSetting('payment_prefix', 'PAY'));
$db->prepare("INSERT INTO payments (payment_number, payment_date, invoice_id, service_booking_id, amount, payment_method, status, mpesa_checkout_id, mpesa_phone, created_at)
              VALUES (?, NOW(), ?, ?, ?, 'mpesa', 'pending', ?, ?, NOW())")
   ->execute([$payNumber, $invoiceId ?: null, $bookingId ?: null, $amount, $push['checkout_request_id'], $phone]);

logActivity('mpesa_stk_push', 'payments', $db->lastInsertId(), "STK Push initiated: {$ref} KES {$amount} to {$phone}");

echo json_encode([
    'success'              => true,
    'checkout_request_id' => $push['checkout_request_id'],
    'message'             => 'Payment prompt sent to ' . $phone . '. Ask the customer to enter their M-Pesa PIN.',
]);

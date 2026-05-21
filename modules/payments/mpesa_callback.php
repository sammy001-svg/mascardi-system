<?php
/**
 * M-Pesa Daraja STK Push callback endpoint.
 * Safaricom posts JSON to this URL after the customer completes (or cancels) payment.
 *
 * This file must be publicly accessible via HTTPS.
 * Register the URL in Admin → Settings → mpesa_callback_url.
 */
require_once __DIR__ . '/../../includes/functions.php';

// Allow only POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

// Acknowledge immediately — Safaricom requires a quick 200 response
header('Content-Type: application/json');
echo json_encode(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);

// Read and parse the callback payload
$raw  = file_get_contents('php://input');
$data = json_decode($raw, true);

if (empty($data)) {
    error_log('M-Pesa callback: empty or invalid payload');
    exit;
}

try {
    $stkCallback = $data['Body']['stkCallback'] ?? [];
    $resultCode  = (int)($stkCallback['ResultCode'] ?? -1);
    $checkoutId  = $stkCallback['CheckoutRequestID'] ?? '';
    $merchantId  = $stkCallback['MerchantRequestID'] ?? '';

    // Log raw callback for auditing
    $db = getDB();
    $db->prepare("INSERT INTO mpesa_callbacks (checkout_request_id, merchant_request_id, result_code, payload, created_at)
                  VALUES (?, ?, ?, ?, NOW())")
       ->execute([$checkoutId, $merchantId, $resultCode, $raw]);

    if ($resultCode !== 0) {
        // Payment failed or was cancelled — update any pending STK push records
        $db->prepare("UPDATE payments SET status='failed', mpesa_result_desc=? WHERE mpesa_checkout_id=? AND status='pending'")
           ->execute([$stkCallback['ResultDesc'] ?? 'Failed', $checkoutId]);
        exit;
    }

    // Extract metadata items
    $items = $stkCallback['CallbackMetadata']['Item'] ?? [];
    $meta  = [];
    foreach ($items as $item) {
        $meta[$item['Name']] = $item['Value'] ?? null;
    }

    $amount    = (float)($meta['Amount']         ?? 0);
    $mpesaCode = (string)($meta['MpesaReceiptNumber'] ?? '');
    $phone     = (string)($meta['PhoneNumber']   ?? '');

    if (!$checkoutId || !$mpesaCode) {
        error_log('M-Pesa callback: missing CheckoutRequestID or MpesaReceiptNumber');
        exit;
    }

    // Find and update the pending payment
    $stmt = $db->prepare("SELECT * FROM payments WHERE mpesa_checkout_id = ? AND status = 'pending' LIMIT 1");
    $stmt->execute([$checkoutId]);
    $payment = $stmt->fetch();

    if (!$payment) {
        error_log("M-Pesa callback: no pending payment found for CheckoutRequestID {$checkoutId}");
        exit;
    }

    $db->beginTransaction();

    // Confirm the payment
    $db->prepare("UPDATE payments SET status='confirmed', mpesa_code=?, mpesa_phone=?, amount=?, updated_at=NOW()
                  WHERE id=?")
       ->execute([$mpesaCode, $phone, $amount, $payment['id']]);

    // Update invoice balance if linked
    if ($payment['invoice_id']) {
        $inv = $db->prepare("SELECT total, amount_paid FROM invoices WHERE id=?");
        $inv->execute([$payment['invoice_id']]);
        $inv = $inv->fetch();

        if ($inv) {
            $newPaid   = (float)$inv['amount_paid'] + $amount;
            $newStatus = $newPaid >= (float)$inv['total'] ? 'paid' : ($newPaid > 0 ? 'partial' : 'unpaid');
            $db->prepare("UPDATE invoices SET amount_paid=?, status=?, updated_at=NOW() WHERE id=?")
               ->execute([$newPaid, $newStatus, $payment['invoice_id']]);
        }
    }

    // Audit log
    $db->prepare("INSERT INTO audit_logs (user_id, action, module, record_id, details, ip_address)
                  VALUES (NULL, 'mpesa_payment', 'payments', ?, ?, ?)")
       ->execute([$payment['id'], "M-Pesa {$mpesaCode}: KES {$amount} from {$phone}", $_SERVER['REMOTE_ADDR'] ?? '']);

    $db->commit();

} catch (Throwable $e) {
    if (isset($db) && $db->inTransaction()) $db->rollBack();
    error_log('M-Pesa callback exception: ' . $e->getMessage());
}

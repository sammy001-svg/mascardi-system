<?php
/**
 * Showroom inquiry endpoint — public, no auth required.
 * Accepts POST, saves to showroom_inquiries, optionally sends email.
 */
header('Content-Type: application/json');

require_once __DIR__ . '/../config/app.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$carId   = (int)($_POST['car_id'] ?? 0);
$name    = trim($_POST['name']    ?? '');
$phone   = trim($_POST['phone']   ?? '');
$email   = trim($_POST['email']   ?? '');
$message = trim($_POST['message'] ?? '');

// Validate
if (!$carId)  { echo json_encode(['success' => false, 'error' => 'Invalid request.']);         exit; }
if (!$name)   { echo json_encode(['success' => false, 'error' => 'Your name is required.']);   exit; }
if (!$phone && !$email) {
    echo json_encode(['success' => false, 'error' => 'Please provide a phone number or email.']);
    exit;
}
if ($email && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['success' => false, 'error' => 'Invalid email address.']);
    exit;
}
// Basic rate-limit: max 3 inquiries per IP per hour
$ip = $_SERVER['REMOTE_ADDR'] ?? '';

try {
    $db = getDB();

    // Verify car exists and is in showroom
    $car = $db->prepare("SELECT id, make, model, year FROM cars WHERE id=? AND car_type='inventory' AND asking_price>0");
    $car->execute([$carId]);
    $car = $car->fetch(PDO::FETCH_ASSOC);
    if (!$car) { echo json_encode(['success' => false, 'error' => 'Vehicle not found.']); exit; }

    // Rate limit
    $recent = $db->prepare("SELECT COUNT(*) FROM showroom_inquiries WHERE inquiry_email=? AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)");
    $recent->execute([$email ?: $ip]);
    if ((int)$recent->fetchColumn() >= 5) {
        echo json_encode(['success' => false, 'error' => 'Too many requests. Please try again later.']);
        exit;
    }

    // Insert inquiry
    $db->prepare("INSERT INTO showroom_inquiries (car_id, inquiry_name, inquiry_phone, inquiry_email, message) VALUES (?,?,?,?,?)")
       ->execute([$carId, $name, $phone ?: null, $email ?: null, $message ?: null]);

    $inquiryId = (int)$db->lastInsertId();

    // Fire notification to admin users if notifications table exists
    try {
        $admins = $db->query("SELECT id FROM users WHERE role='admin' AND status='active'")->fetchAll(PDO::FETCH_COLUMN);
        $carLabel = "{$car['year']} {$car['make']} {$car['model']}";
        $notifStmt = $db->prepare("INSERT INTO notifications (user_id, type, title, message, link) VALUES (?,?,?,?,?)");
        foreach ($admins as $uid) {
            $notifStmt->execute([
                $uid,
                'info',
                'New Showroom Inquiry',
                "{$name} enquired about the {$carLabel}",
                BASE_URL . '/modules/showroom/index.php?id=' . $inquiryId,
            ]);
        }
    } catch (Exception $e) {
        // Notifications table may not exist yet — not fatal
    }

    // Send email notification if mailer is configured
    try {
        require_once __DIR__ . '/../includes/mailer.php';
        $adminEmail = getSetting('admin_email', getSetting('company_email', ''));
        if ($adminEmail) {
            $carLabel   = "{$car['year']} {$car['make']} {$car['model']}";
            $viewUrl    = BASE_URL . '/modules/showroom/index.php';
            $body  = "<p>A new inquiry has been received for the <strong>{$carLabel}</strong>.</p>";
            $body .= "<table style='font-family:sans-serif;font-size:14px;border-collapse:collapse'>";
            $body .= "<tr><td style='padding:6px 16px 6px 0;color:#64748b;font-weight:600'>Name</td><td>" . htmlspecialchars($name) . "</td></tr>";
            if ($phone) $body .= "<tr><td style='padding:6px 16px 6px 0;color:#64748b;font-weight:600'>Phone</td><td><a href='tel:{$phone}'>{$phone}</a></td></tr>";
            if ($email) $body .= "<tr><td style='padding:6px 16px 6px 0;color:#64748b;font-weight:600'>Email</td><td><a href='mailto:{$email}'>{$email}</a></td></tr>";
            if ($message) $body .= "<tr><td style='padding:6px 16px 6px 0;color:#64748b;font-weight:600;vertical-align:top'>Message</td><td>" . nl2br(htmlspecialchars($message)) . "</td></tr>";
            $body .= "</table>";
            $body .= "<p style='margin-top:20px'><a href='{$viewUrl}' style='background:#2563eb;color:#fff;padding:10px 20px;border-radius:8px;text-decoration:none;font-weight:700'>View All Inquiries</a></p>";
            sendMail($adminEmail, "New Inquiry: {$carLabel}", $body);
        }
    } catch (Exception $e) {
        // Email failure is non-fatal
    }

    echo json_encode(['success' => true]);

} catch (Exception $e) {
    error_log('Showroom inquiry error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error. Please try again.']);
}

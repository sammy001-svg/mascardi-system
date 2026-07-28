<?php
/**
 * Showroom inquiry endpoint — public, no auth required.
 * Accepts POST, saves to showroom_inquiries, optionally sends email.
 */
header('Content-Type: application/json');

require_once __DIR__ . '/../includes/functions.php';

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
    require_once __DIR__ . '/_leads_bootstrap.php';
    showroomLeadsMigrate($db);

    // Verify car exists and is on the showroom.
    // Note: no asking_price filter — "Price on request" vehicles still show an
    // enquiry form, and requiring a price made those submissions fail with a
    // misleading "Vehicle not found", silently killing the highest-intent leads.
    $car = $db->prepare("SELECT id, make, model, year FROM cars
                         WHERE id=? AND car_type IN ('inventory','sale_on_behalf')
                           AND show_on_website = 1");
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

    // Insert inquiry — NOT wrapped in a silent catch: if we cannot record the
    // enquiry we must not tell the visitor it was sent.
    $db->prepare("INSERT INTO showroom_inquiries (car_id, inquiry_name, inquiry_phone, inquiry_email, message) VALUES (?,?,?,?,?)")
       ->execute([$carId, $name, $phone ?: null, $email ?: null, $message ?: null]);

    $inquiryId = (int)$db->lastInsertId();
    $carLabel  = "{$car['year']} {$car['make']} {$car['model']}";

    // Push into the CRM so the enquiry enters the normal lead pipeline
    // instead of sitting in a side table nobody works from.
    $leadId = showroomCreateLead(
        $db, $name, $phone ?: null, $email ?: null,
        $carLabel,
        "Website enquiry for {$carLabel}" . ($message ? ' — ' . $message : '')
    );
    if ($leadId) {
        try { $db->prepare("UPDATE showroom_inquiries SET lead_id=? WHERE id=?")->execute([$leadId, $inquiryId]); }
        catch (\Throwable $_) {}
    }

    // Notify the roles that actually work inbound leads (was admin-only before).
    showroomNotifyNewLead(
        'New Website Enquiry',
        "{$name} enquired about the {$carLabel}"
            . ($phone ? " · {$phone}" : '')
            . ($leadId ? '' : ' (CRM lead not created — review manually)'),
        $leadId
            ? BASE_URL . '/modules/crm/view_lead.php?id=' . $leadId
            : BASE_URL . '/modules/showroom/index.php?id=' . $inquiryId
    );

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
            sendMail($adminEmail, 'Admin', "New Inquiry: {$carLabel}", $body);
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

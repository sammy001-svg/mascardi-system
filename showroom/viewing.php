<?php
/**
 * Vehicle viewing request — public, no auth required.
 *
 * A customer on a car's page asking to come and see it. Deliberately built on
 * the same path as inquiry.php: the request lands in showroom_inquiries, becomes
 * a CRM lead, and notifies staff. What makes it different is that somebody has
 * named a day, so it goes to the people who can confirm an appointment and have
 * the car standing ready rather than to everyone in sales.
 *
 * Accepts POST, answers JSON.
 */
header('Content-Type: application/json');

require_once __DIR__ . '/../includes/functions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$carId = (int)($_POST['car_id'] ?? 0);
$name  = trim($_POST['name']    ?? '');
$phone = trim($_POST['phone']   ?? '');
$email = trim($_POST['email']   ?? '');
$date  = trim($_POST['preferred_date'] ?? '');
$slot  = trim($_POST['preferred_time'] ?? '');
$notes = trim($_POST['message'] ?? '');

$fail = static function (string $msg): void {
    echo json_encode(['success' => false, 'error' => $msg]);
    exit;
};

if (!$carId) $fail('Invalid request.');
if (!$name)  $fail('Your name is required.');
// A viewing has to be arranged with somebody, and an email alone is no use to
// a yard confirming an appointment for tomorrow.
if (!$phone) $fail('A phone number is required so we can confirm the appointment.');
if ($email && !filter_var($email, FILTER_VALIDATE_EMAIL)) $fail('Invalid email address.');

try {
    $db = getDB();
    require_once __DIR__ . '/_leads_bootstrap.php';
    showroomLeadsMigrate($db);

    // The car has to be one the public can actually see, and standing somewhere
    // they can come to. A unit still on the water cannot be viewed, which is the
    // same rule the page itself applies when it hides the yard location.
    $car = $db->prepare("SELECT c.id, c.make, c.model, c.year, c.status, c.location_id,
                                l.name AS location_name
                           FROM cars c
                      LEFT JOIN locations l ON l.id = c.location_id
                          WHERE c.id = ? AND c.car_type IN ('inventory','sale_on_behalf')
                            AND c.show_on_website = 1
                            AND (c.status IS NULL OR c.status NOT IN ('delivered','sold'))");
    $car->execute([$carId]);
    $car = $car->fetch(PDO::FETCH_ASSOC);
    if (!$car) $fail('Vehicle not found.');
    if (($car['status'] ?? '') === 'in_transit') {
        $fail('This vehicle is still on its way to us and cannot be viewed yet. Send an enquiry and we will tell you the moment it lands.');
    }

    // The date is checked against the database's clock, not PHP's: this
    // application runs on UTC while MySQL runs on EAT, so asking PHP whether a
    // date is in the past gets the wrong answer for several hours each day.
    $slots = showroomViewingSlots();
    if ($slot !== '' && !isset($slots[$slot])) $slot = '';
    if ($date !== '') {
        $d = DateTime::createFromFormat('Y-m-d', $date);
        if (!$d || $d->format('Y-m-d') !== $date) $fail('That is not a valid date.');
        $chk = $db->prepare('SELECT ? < CURDATE(), ? > DATE_ADD(CURDATE(), INTERVAL 120 DAY)');
        $chk->execute([$date, $date]);
        [$past, $tooFar] = array_values($chk->fetch(PDO::FETCH_NUM));
        if ((int)$past)   $fail('Please choose a date that has not already passed.');
        if ((int)$tooFar) $fail('Please choose a date within the next few months.');
    }

    // Rate limit, on the phone number that will be called back.
    $recent = $db->prepare("SELECT COUNT(*) FROM showroom_inquiries
                             WHERE request_type = 'viewing' AND inquiry_phone = ?
                               AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)");
    $recent->execute([$phone]);
    if ((int)$recent->fetchColumn() >= 5) {
        $fail('We already have your request. Someone will call you shortly.');
    }

    $carLabel = trim(($car['year'] ? $car['year'] . ' ' : '') . $car['make'] . ' ' . $car['model']);
    $when     = $date !== ''
        ? date('D j M Y', strtotime($date)) . ($slot !== '' ? ', ' . $slots[$slot] : '')
        : ($slot !== '' ? $slots[$slot] : 'No preference given');

    // Not wrapped in a silent catch: if the request cannot be recorded the
    // visitor must not be told it was sent.
    $db->prepare("INSERT INTO showroom_inquiries
                     (car_id, inquiry_name, inquiry_phone, inquiry_email, message,
                      request_type, preferred_date, preferred_time, location_id)
                  VALUES (?,?,?,?,?,'viewing',?,?,?)")
       ->execute([$carId, $name, $phone, $email ?: null, $notes ?: null,
                  $date ?: null, $slot ?: null, $car['location_id'] ?: null]);
    $requestId = (int)$db->lastInsertId();

    // Into the CRM, so the request is worked from the pipeline like any lead
    // rather than sitting in a side table.
    $summary = "Website viewing request for {$carLabel} — {$when}"
             . ($car['location_name'] ? ' at ' . $car['location_name'] : '')
             . ($notes !== '' ? ' — ' . $notes : '');
    $leadId  = showroomCreateLead($db, $name, $phone, $email ?: null, $carLabel, $summary);
    if ($leadId) {
        try { $db->prepare("UPDATE showroom_inquiries SET lead_id=? WHERE id=?")
                 ->execute([$leadId, $requestId]); }
        catch (\Throwable $_) {}
    }

    // The link opens the request straight onto the client's details, which is
    // the only thing the person reading the notification needs in that moment.
    showroomNotifyViewingRequest(
        'Viewing Request: ' . $carLabel,
        "{$name} · {$phone} — asked to view on {$when}"
            . ($car['location_name'] ? ' at ' . $car['location_name'] : ''),
        BASE_URL . '/modules/showroom/index.php?id=' . $requestId . '&popup=1'
    );

    // Email as well, for whoever is not at a screen.
    try {
        require_once __DIR__ . '/../includes/mailer.php';
        $to = getSetting('admin_email', getSetting('company_email', ''));
        if ($to) {
            $rows = [
                'Name'    => $name,
                'Phone'   => $phone,
                'Email'   => $email,
                'Vehicle' => $carLabel,
                'Wants to view' => $when,
                'Location' => (string)($car['location_name'] ?? ''),
                'Notes'   => $notes,
            ];
            $body = "<p><strong>{$name}</strong> has asked to view the <strong>"
                  . htmlspecialchars($carLabel) . "</strong>.</p>"
                  . "<table style='font-family:sans-serif;font-size:14px;border-collapse:collapse'>";
            foreach ($rows as $k => $v) {
                if (trim((string)$v) === '') continue;
                $body .= "<tr><td style='padding:6px 16px 6px 0;color:#64748b;font-weight:600;vertical-align:top'>"
                       . $k . "</td><td>" . nl2br(htmlspecialchars((string)$v)) . "</td></tr>";
            }
            $body .= "</table><p style='margin-top:20px'><a href='"
                   . BASE_URL . "/modules/showroom/index.php?id={$requestId}&popup=1'"
                   . " style='background:#2563eb;color:#fff;padding:10px 20px;border-radius:8px;text-decoration:none;font-weight:700'>Open the request</a></p>";
            sendMail($to, 'Admin', "Viewing request: {$carLabel}", $body);
        }
    } catch (\Throwable $_) { /* email failure is not the customer's problem */ }

    echo json_encode(['success' => true]);

} catch (\Throwable $e) {
    error_log('Showroom viewing request error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error. Please try again.']);
}

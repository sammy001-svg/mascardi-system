<?php
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/mailer.php';
requireLogin();
hasRole('admin') || http_response_code(403) && exit(json_encode(['ok' => false, 'error' => 'Access denied.']));

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    exit(json_encode(['ok' => false, 'error' => 'POST required.']));
}

$to = trim($_POST['to'] ?? '');
if (!$to || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
    exit(json_encode(['ok' => false, 'error' => 'Please enter a valid email address.']));
}

$company = getSetting('company_name', 'Mascardi System');
$subject = "Test Email from {$company}";
$body    = "<p>Hi,</p>
<p>This is a test email sent from the <strong>{$company}</strong> system to confirm that your SMTP email settings are configured correctly.</p>
<p>If you received this message, your email configuration is working.</p>
<p style='color:#64748b;font-size:12px;margin-top:24px'>Sent: " . date('d M Y, H:i:s') . "</p>";

$result = sendMail($to, $to, $subject, mailTemplate('Email Configuration Test', $body), 'settings', 0);

if ($result['ok']) {
    echo json_encode(['ok' => true, 'message' => "Test email sent successfully to {$to}."]);
} else {
    echo json_encode(['ok' => false, 'error' => $result['error']]);
}

<?php
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/pdf.php';
requireLogin();
canAccess('invoices') || die('Access denied.');

$id = (int)($_GET['id'] ?? 0);
if (!$id) redirect(BASE_URL . '/modules/invoices/index.php');

$db   = getDB();
$stmt = $db->prepare("SELECT i.*, c.chassis_number, c.make, c.model, c.year, c.color, c.registration_number
                      FROM invoices i JOIN cars c ON c.id = i.car_id WHERE i.id = ?");
$stmt->execute([$id]);
$inv = $stmt->fetch();
if (!$inv) die('Invoice not found.');

$items = $db->prepare("SELECT * FROM invoice_items WHERE invoice_id = ? ORDER BY id");
$items->execute([$id]);
$items = $items->fetchAll();

$company = [
    'name'    => getSetting('company_name',    'Mascardi Car Yard'),
    'address' => getSetting('company_address', 'Nairobi, Kenya'),
    'phone'   => getSetting('company_phone',   ''),
    'email'   => getSetting('company_email',   ''),
    'pin'     => getSetting('company_pin',     ''),
];

$filename = 'Invoice-' . $inv['invoice_number'] . '.pdf';
$html     = buildInvoiceHtml($inv, $items, $company);

logActivity('download_pdf', 'invoices', $id, 'Downloaded PDF: ' . $inv['invoice_number']);

$inline = ($_GET['view'] ?? '0') === '1';
renderPdf($html, $filename, $inline);

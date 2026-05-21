<?php
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/pdf.php';
requireLogin();
canAccess('quotations') || die('Access denied.');

$id = (int)($_GET['id'] ?? 0);
if (!$id) redirect(BASE_URL . '/modules/quotations/index.php');

$db   = getDB();
$stmt = $db->prepare("SELECT q.*, c.chassis_number, c.make, c.model, c.year, c.registration_number
                      FROM quotations q JOIN cars c ON c.id = q.car_id WHERE q.id = ?");
$stmt->execute([$id]);
$qt = $stmt->fetch();
if (!$qt) die('Quotation not found.');

$items = $db->prepare("SELECT * FROM quotation_items WHERE quotation_id = ? ORDER BY id");
$items->execute([$id]);
$items = $items->fetchAll();

$company = [
    'name'    => getSetting('company_name',    'Mascardi Car Yard'),
    'address' => getSetting('company_address', 'Nairobi, Kenya'),
    'phone'   => getSetting('company_phone',   ''),
    'email'   => getSetting('company_email',   ''),
    'pin'     => getSetting('company_pin',     ''),
];

$filename = 'Quotation-' . $qt['quotation_number'] . '.pdf';
$html     = buildQuotationHtml($qt, $items, $company);

logActivity('download_pdf', 'quotations', $id, 'Downloaded PDF: ' . $qt['quotation_number']);

$inline = ($_GET['view'] ?? '0') === '1';
renderPdf($html, $filename, $inline);

<?php
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
canAccess('sales') || die('Access denied.');
$id = (int)($_GET['id'] ?? 0);
if (!$id) die('Invalid request.');
$db = getDB();

$stmt = $db->prepare("
    SELECT cs.*, c.make, c.model, c.year, c.chassis_number, c.registration_number,
           c.color, c.engine_number, c.fuel_type, c.transmission, c.body_type,
           u.name AS sold_by_name
    FROM car_sales cs
    JOIN cars c ON c.id = cs.car_id
    LEFT JOIN users u ON u.id = cs.sold_by
    WHERE cs.id = ?
");
$stmt->execute([$id]); $sale = $stmt->fetch();
if (!$sale) die('Sale not found.');

$co = [
    'name'    => getSetting('company_name', 'Mascardi Car Yard'),
    'address' => getSetting('company_address', 'Nairobi, Kenya'),
    'phone'   => getSetting('company_phone', ''),
    'email'   => getSetting('company_email', ''),
    'pin'     => getSetting('company_pin', ''),
    'logo'    => getSetting('company_logo', ''),
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Purchase Agreement — <?= e($sale['sale_number']) ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
* { font-family: 'Times New Roman', serif; }
body { background: #f1f5f9; font-size: 13px; }
.print-wrapper { max-width: 820px; margin: 30px auto; background: #fff; padding: 48px; box-shadow: 0 2px 12px rgba(0,0,0,.1); }
h1 { font-family: 'Times New Roman', serif; }
.doc-title { font-size: 22px; font-weight: 800; text-align: center; letter-spacing: 2px; text-transform: uppercase; margin: 20px 0 4px; }
.doc-subtitle { text-align: center; color: #64748b; font-size: 12px; margin-bottom: 24px; }
.section-heading { font-weight: 700; text-transform: uppercase; letter-spacing: 1px; font-size: 11px; color: #1e3a5f; border-bottom: 2px solid #1e3a5f; padding-bottom: 4px; margin: 20px 0 12px; }
.data-row { display: flex; margin-bottom: 6px; font-size: 12.5px; }
.data-label { width: 180px; flex-shrink: 0; color: #64748b; }
.data-value { flex: 1; font-weight: 600; }
.sig-block { border-top: 1px solid #334155; padding-top: 6px; min-height: 70px; }
.sig-label { font-size: 11px; color: #475569; margin-top: 4px; }
.clause { margin-bottom: 10px; font-size: 12px; line-height: 1.7; }
.clause-num { font-weight: 700; color: #1e3a5f; }
.amount-box { background: #f0fdf4; border: 2px solid #16a34a; border-radius: 6px; padding: 12px 20px; text-align: center; display: inline-block; min-width: 200px; }
.amount-box .amt { font-size: 22px; font-weight: 800; color: #16a34a; }
.amount-box .lbl { font-size: 11px; color: #64748b; text-transform: uppercase; letter-spacing: 1px; }
.letterhead-divider { border-top: 3px solid #1e3a5f; border-bottom: 1px solid #93c5fd; padding: 6px 0; margin-bottom: 24px; text-align: center; color: #1e3a5f; font-size: 11px; letter-spacing: 1px; }
@media print {
    body { background: #fff; }
    .no-print { display: none !important; }
    .print-wrapper { box-shadow: none; margin: 0; padding: 28px; }
    .page-break { page-break-before: always; }
}
</style>
</head>
<body>

<div class="no-print text-center py-3">
    <button onclick="window.print()" class="btn btn-primary btn-sm"><i class="fa fa-print me-1"></i>Print / Save as PDF</button>
    <a href="view.php?id=<?= $id ?>" class="btn btn-outline-secondary btn-sm ms-2"><i class="fa fa-arrow-left me-1"></i>Back to Sale</a>
</div>

<div class="print-wrapper">

    <!-- Letterhead -->
    <div class="row align-items-center mb-2">
        <div class="col-8">
            <?php if ($co['logo'] && file_exists(BASE_PATH . '/assets/images/' . $co['logo'])): ?>
            <img src="<?= BASE_URL ?>/assets/images/<?= e($co['logo']) ?>" alt="<?= e($co['name']) ?>" style="height:52px;max-width:180px;object-fit:contain;display:block;margin-bottom:4px">
            <?php else: ?>
            <div style="font-size:24px;font-weight:800;color:#1e3a5f;font-family:sans-serif"><?= e($co['name']) ?></div>
            <?php endif; ?>
            <div style="font-size:11px;color:#64748b"><?= e($co['address']) ?></div>
            <?php if ($co['phone']): ?><div style="font-size:11px;color:#64748b">Tel: <?= e($co['phone']) ?></div><?php endif; ?>
            <?php if ($co['email']): ?><div style="font-size:11px;color:#64748b">Email: <?= e($co['email']) ?></div><?php endif; ?>
            <?php if ($co['pin']): ?><div style="font-size:11px;color:#64748b">KRA PIN: <?= e($co['pin']) ?></div><?php endif; ?>
        </div>
        <div class="col-4 text-end">
            <div style="font-size:11px;color:#64748b">Ref: <strong><?= e($sale['sale_number']) ?></strong></div>
            <div style="font-size:11px;color:#64748b">Date: <strong><?= fmtDate($sale['sale_date'], 'd F Y') ?></strong></div>
        </div>
    </div>
    <div class="letterhead-divider">MOTOR VEHICLE DEALER — LICENSED &amp; REGISTERED</div>

    <div class="doc-title">Motor Vehicle Purchase Agreement</div>
    <div class="doc-subtitle">This Agreement is entered into on the date stated below between the parties identified herein.</div>

    <!-- Parties -->
    <div class="section-heading">1. Parties to the Agreement</div>
    <div class="row">
        <div class="col-6">
            <div style="font-size:11px;font-weight:700;color:#475569;margin-bottom:6px">SELLER (Vendor)</div>
            <div class="data-row"><span class="data-label">Company Name</span><span class="data-value"><?= e($co['name']) ?></span></div>
            <div class="data-row"><span class="data-label">Address</span><span class="data-value"><?= e($co['address']) ?></span></div>
            <?php if ($co['phone']): ?><div class="data-row"><span class="data-label">Phone</span><span class="data-value"><?= e($co['phone']) ?></span></div><?php endif; ?>
            <?php if ($co['pin']): ?><div class="data-row"><span class="data-label">KRA PIN</span><span class="data-value"><?= e($co['pin']) ?></span></div><?php endif; ?>
            <div class="data-row"><span class="data-label">Representative</span><span class="data-value"><?= e($sale['sold_by_name'] ?? '—') ?></span></div>
        </div>
        <div class="col-6">
            <div style="font-size:11px;font-weight:700;color:#475569;margin-bottom:6px">BUYER (Purchaser)</div>
            <div class="data-row"><span class="data-label">Full Name</span><span class="data-value"><?= e($sale['buyer_name']) ?></span></div>
            <?php if ($sale['buyer_id_number']): ?><div class="data-row"><span class="data-label">ID / KRA PIN</span><span class="data-value"><?= e($sale['buyer_id_number']) ?></span></div><?php endif; ?>
            <?php if ($sale['buyer_phone']): ?><div class="data-row"><span class="data-label">Phone</span><span class="data-value"><?= e($sale['buyer_phone']) ?></span></div><?php endif; ?>
            <?php if ($sale['buyer_email']): ?><div class="data-row"><span class="data-label">Email</span><span class="data-value"><?= e($sale['buyer_email']) ?></span></div><?php endif; ?>
        </div>
    </div>

    <!-- Vehicle -->
    <div class="section-heading">2. Description of Motor Vehicle</div>
    <div class="row">
        <div class="col-6">
            <div class="data-row"><span class="data-label">Make &amp; Model</span><span class="data-value"><?= e($sale['make'] . ' ' . $sale['model']) ?></span></div>
            <div class="data-row"><span class="data-label">Year of Manufacture</span><span class="data-value"><?= e($sale['year']) ?></span></div>
            <div class="data-row"><span class="data-label">Color</span><span class="data-value"><?= e($sale['color'] ?? '—') ?></span></div>
            <div class="data-row"><span class="data-label">Body Type</span><span class="data-value"><?= e(ucfirst($sale['body_type'] ?? '—')) ?></span></div>
        </div>
        <div class="col-6">
            <div class="data-row"><span class="data-label">Chassis / VIN</span><span class="data-value" style="font-family:monospace;font-size:12px"><?= e($sale['chassis_number']) ?></span></div>
            <?php if ($sale['engine_number']): ?><div class="data-row"><span class="data-label">Engine Number</span><span class="data-value" style="font-family:monospace;font-size:12px"><?= e($sale['engine_number']) ?></span></div><?php endif; ?>
            <?php if ($sale['registration_number']): ?><div class="data-row"><span class="data-label">Registration No.</span><span class="data-value"><?= e($sale['registration_number']) ?></span></div><?php endif; ?>
            <div class="data-row"><span class="data-label">Fuel Type</span><span class="data-value"><?= e(ucfirst($sale['fuel_type'] ?? '—')) ?></span></div>
            <div class="data-row"><span class="data-label">Transmission</span><span class="data-value"><?= e(ucfirst($sale['transmission'] ?? '—')) ?></span></div>
        </div>
    </div>

    <!-- Financial Terms -->
    <div class="section-heading">3. Purchase Price &amp; Payment Terms</div>
    <div class="text-center mb-3">
        <div class="amount-box d-inline-block">
            <div class="lbl">Agreed Purchase Price</div>
            <div class="amt"><?= money((float)$sale['sale_price']) ?></div>
        </div>
    </div>
    <div class="row">
        <div class="col-6">
            <div class="data-row"><span class="data-label">Sale Price</span><span class="data-value"><?= money((float)$sale['sale_price']) ?></span></div>
            <?php if ($sale['deposit_amount'] > 0): ?>
            <div class="data-row"><span class="data-label">Deposit Paid</span><span class="data-value"><?= money((float)$sale['deposit_amount']) ?></span></div>
            <div class="data-row"><span class="data-label">Balance Due</span><span class="data-value <?= $sale['balance_amount'] > 0 ? 'text-danger' : '' ?>"><?= money((float)$sale['balance_amount']) ?></span></div>
            <?php endif; ?>
        </div>
        <div class="col-6">
            <div class="data-row"><span class="data-label">Payment Method</span><span class="data-value"><?= e(ucwords(str_replace('_', ' ', $sale['payment_method']))) ?></span></div>
            <div class="data-row"><span class="data-label">Payment Status</span><span class="data-value"><?= e(ucwords(str_replace('_', ' ', $sale['payment_status']))) ?></span></div>
            <?php if ($sale['finance_company']): ?>
            <div class="data-row"><span class="data-label">Financed By</span><span class="data-value"><?= e($sale['finance_company']) ?></span></div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Terms and Conditions -->
    <div class="section-heading">4. Terms &amp; Conditions</div>
    <div class="clause"><span class="clause-num">4.1 Sale As Is.</span> The Buyer acknowledges that the vehicle is sold in its present condition. The Seller warrants that it has clear title to the vehicle and that the vehicle is free of all encumbrances, liens, or charges not disclosed herein.</div>
    <div class="clause"><span class="clause-num">4.2 Payment.</span> Full payment of the agreed purchase price is a condition precedent to the transfer of ownership and delivery of the vehicle. Where payment is made in instalments, ownership shall vest in the Buyer only upon receipt of the final instalment.</div>
    <div class="clause"><span class="clause-num">4.3 Risk &amp; Ownership Transfer.</span> Risk in the vehicle passes to the Buyer upon delivery of the vehicle. Legal ownership passes to the Buyer only after full and final payment of the purchase price.</div>
    <div class="clause"><span class="clause-num">4.4 Transfer of Documents.</span> The Seller undertakes to provide the Buyer with all necessary documentation for the transfer of registration within thirty (30) days of full payment, subject to compliance by the Buyer with applicable government procedures.</div>
    <div class="clause"><span class="clause-num">4.5 No Returns.</span> Except as required by applicable law, this sale is final and no returns or exchanges will be accepted once the vehicle has been delivered and signed for by the Buyer.</div>
    <div class="clause"><span class="clause-num">4.6 Buyer Representations.</span> The Buyer confirms that they have inspected the vehicle, are satisfied with its condition, and enter into this Agreement freely and without undue influence.</div>
    <div class="clause"><span class="clause-num">4.7 Governing Law.</span> This Agreement shall be governed by and construed in accordance with the laws of the Republic of Kenya. Any dispute arising out of this Agreement shall be submitted to the courts of competent jurisdiction in Nairobi.</div>
    <div class="clause"><span class="clause-num">4.8 Entire Agreement.</span> This Agreement constitutes the entire agreement between the parties with respect to the subject matter hereof and supersedes all prior negotiations, representations, warranties, and understandings of the parties.</div>
    <?php if ($sale['notes']): ?>
    <div class="clause"><span class="clause-num">4.9 Special Conditions.</span> <?= nl2br(e($sale['notes'])) ?></div>
    <?php endif; ?>

    <!-- Signatures -->
    <div class="section-heading">5. Execution</div>
    <div style="font-size:12px;margin-bottom:20px;">The parties hereto have executed this Agreement on the date and year first written above.</div>

    <div class="row mt-4 g-4">
        <div class="col-6">
            <div style="margin-bottom:40px">&nbsp;</div>
            <div class="sig-block">
                <div class="sig-label"><strong>SELLER'S REPRESENTATIVE</strong></div>
                <div class="sig-label">Name: <?= e($sale['sold_by_name'] ?? '__________________________') ?></div>
                <div class="sig-label">Designation: ______________________________</div>
                <div class="sig-label">Date: ______________________________</div>
                <div class="sig-label">Official Stamp:</div>
            </div>
        </div>
        <div class="col-6">
            <div style="margin-bottom:40px">&nbsp;</div>
            <div class="sig-block">
                <div class="sig-label"><strong>BUYER</strong></div>
                <div class="sig-label">Name: <?= e($sale['buyer_name']) ?></div>
                <div class="sig-label">ID / KRA PIN: <?= e($sale['buyer_id_number'] ?? '__________________________') ?></div>
                <div class="sig-label">Date: ______________________________</div>
            </div>
        </div>
        <div class="col-6">
            <div style="margin-bottom:40px">&nbsp;</div>
            <div class="sig-block">
                <div class="sig-label"><strong>WITNESS 1</strong></div>
                <div class="sig-label">Name: ______________________________</div>
                <div class="sig-label">ID Number: ______________________________</div>
                <div class="sig-label">Date: ______________________________</div>
            </div>
        </div>
        <div class="col-6">
            <div style="margin-bottom:40px">&nbsp;</div>
            <div class="sig-block">
                <div class="sig-label"><strong>WITNESS 2</strong></div>
                <div class="sig-label">Name: ______________________________</div>
                <div class="sig-label">ID Number: ______________________________</div>
                <div class="sig-label">Date: ______________________________</div>
            </div>
        </div>
    </div>

    <div style="margin-top:32px;padding-top:16px;border-top:1px solid #e2e8f0;font-size:10px;color:#94a3b8;text-align:center">
        This document was generated by <?= e($co['name']) ?> Management System on <?= date('d F Y, H:i') ?> &mdash; Ref: <?= e($sale['sale_number']) ?>
    </div>
</div>
</body>
</html>

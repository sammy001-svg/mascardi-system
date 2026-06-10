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

$accessories = [
    'Spare Tyre'              => true,
    'Service / Log Book'      => true,
    'Extra Ignition Key'      => false,
    'Wheel Jack'              => true,
    'Wheel Spanner'           => true,
    'Warning / Reflector Triangles' => false,
    'Fire Extinguisher'       => false,
    'First Aid Kit'           => false,
    'Vehicle Manual'          => false,
    'Number Plates'           => true,
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Handover Certificate — <?= e($sale['sale_number']) ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
* { font-family: 'Times New Roman', serif; }
body { background: #f1f5f9; font-size: 13px; }
.print-wrapper { max-width: 820px; margin: 30px auto; background: #fff; padding: 48px; box-shadow: 0 2px 12px rgba(0,0,0,.1); }
.doc-title { font-size: 22px; font-weight: 800; text-align: center; letter-spacing: 2px; text-transform: uppercase; margin: 18px 0 4px; }
.doc-subtitle { text-align: center; color: #64748b; font-size: 12px; margin-bottom: 20px; }
.section-heading { font-weight: 700; text-transform: uppercase; letter-spacing: 1px; font-size: 11px; color: #1e3a5f; border-bottom: 2px solid #1e3a5f; padding-bottom: 4px; margin: 18px 0 10px; }
.data-row { display: flex; margin-bottom: 5px; font-size: 12.5px; }
.data-label { width: 170px; flex-shrink: 0; color: #64748b; }
.data-value { flex: 1; font-weight: 600; }
.acc-table td, .acc-table th { padding: 6px 10px; font-size: 12px; }
.check-box { display: inline-block; width: 16px; height: 16px; border: 1.5px solid #334155; border-radius: 3px; text-align: center; line-height: 14px; font-size: 11px; font-weight: 700; }
.fuel-gauge { display: flex; align-items: center; gap: 8px; margin: 8px 0; }
.fuel-seg { width: 32px; height: 18px; border: 1.5px solid #334155; border-radius: 2px; display: inline-block; }
.fuel-seg.filled { background: #16a34a; }
.sig-block { border-top: 1px solid #334155; padding-top: 6px; min-height: 64px; }
.sig-label { font-size: 11px; color: #475569; margin-top: 3px; }
.letterhead-divider { border-top: 3px solid #1e3a5f; border-bottom: 1px solid #93c5fd; padding: 5px 0; margin-bottom: 20px; text-align: center; color: #1e3a5f; font-size: 11px; letter-spacing: 1px; }
.condition-box { border: 1.5px solid #cbd5e1; border-radius: 6px; min-height: 56px; padding: 8px; font-size: 12px; color: #334155; }
@media print {
    body { background: #fff; }
    .no-print { display: none !important; }
    .print-wrapper { box-shadow: none; margin: 0; padding: 28px; }
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
        </div>
        <div class="col-4 text-end">
            <div style="font-size:11px;color:#64748b">Ref: <strong><?= e($sale['sale_number']) ?></strong></div>
            <div style="font-size:11px;color:#64748b">Date: <strong><?= $sale['delivered_at'] ? fmtDate($sale['delivered_at'], 'd F Y') : fmtDate($sale['sale_date'], 'd F Y') ?></strong></div>
        </div>
    </div>
    <div class="letterhead-divider">MOTOR VEHICLE DEALER — LICENSED &amp; REGISTERED</div>

    <div class="doc-title">Vehicle Handover Certificate</div>
    <div class="doc-subtitle">This certificate confirms the formal handover of the motor vehicle described below.</div>

    <!-- Parties -->
    <div class="section-heading">1. Parties</div>
    <div class="row">
        <div class="col-6">
            <div style="font-size:11px;font-weight:700;color:#475569;margin-bottom:5px">HANDED OVER BY (Seller)</div>
            <div class="data-row"><span class="data-label">Company</span><span class="data-value"><?= e($co['name']) ?></span></div>
            <div class="data-row"><span class="data-label">Representative</span><span class="data-value"><?= e($sale['sold_by_name'] ?? '—') ?></span></div>
        </div>
        <div class="col-6">
            <div style="font-size:11px;font-weight:700;color:#475569;margin-bottom:5px">RECEIVED BY (Buyer)</div>
            <div class="data-row"><span class="data-label">Full Name</span><span class="data-value"><?= e($sale['buyer_name']) ?></span></div>
            <?php if ($sale['buyer_id_number']): ?>
            <div class="data-row"><span class="data-label">ID / KRA PIN</span><span class="data-value"><?= e($sale['buyer_id_number']) ?></span></div>
            <?php endif; ?>
            <?php if ($sale['buyer_phone']): ?>
            <div class="data-row"><span class="data-label">Phone</span><span class="data-value"><?= e($sale['buyer_phone']) ?></span></div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Vehicle -->
    <div class="section-heading">2. Vehicle Description</div>
    <div class="row">
        <div class="col-6">
            <div class="data-row"><span class="data-label">Make &amp; Model</span><span class="data-value"><?= e($sale['make'] . ' ' . $sale['model']) ?></span></div>
            <div class="data-row"><span class="data-label">Year</span><span class="data-value"><?= e($sale['year']) ?></span></div>
            <div class="data-row"><span class="data-label">Color</span><span class="data-value"><?= e($sale['color'] ?? '—') ?></span></div>
            <div class="data-row"><span class="data-label">Body Type</span><span class="data-value"><?= e(ucfirst($sale['body_type'] ?? '—')) ?></span></div>
        </div>
        <div class="col-6">
            <div class="data-row"><span class="data-label">Chassis / VIN</span><span class="data-value" style="font-family:monospace;font-size:12px"><?= e($sale['chassis_number']) ?></span></div>
            <?php if ($sale['engine_number']): ?><div class="data-row"><span class="data-label">Engine No.</span><span class="data-value" style="font-family:monospace;font-size:12px"><?= e($sale['engine_number']) ?></span></div><?php endif; ?>
            <?php if ($sale['registration_number']): ?><div class="data-row"><span class="data-label">Registration</span><span class="data-value"><?= e($sale['registration_number']) ?></span></div><?php endif; ?>
            <div class="data-row"><span class="data-label">Transmission</span><span class="data-value"><?= e(ucfirst($sale['transmission'] ?? '—')) ?></span></div>
        </div>
    </div>

    <!-- Condition at Handover -->
    <div class="section-heading">3. Condition at Handover</div>
    <div class="row mb-2">
        <div class="col-6">
            <div class="data-row">
                <span class="data-label">Mileage (Odometer)</span>
                <span class="data-value">_________________________ km</span>
            </div>
        </div>
        <div class="col-6">
            <div class="data-label mb-1">Fuel Level at Handover</div>
            <div class="fuel-gauge">
                <span style="font-size:11px;color:#64748b">Empty</span>
                <span class="fuel-seg"></span>
                <span class="fuel-seg"></span>
                <span class="fuel-seg"></span>
                <span class="fuel-seg"></span>
                <span style="font-size:11px;color:#64748b">Full</span>
                <span style="font-size:11px;color:#64748b;margin-left:8px">(Circle appropriate segment)</span>
            </div>
        </div>
    </div>
    <div class="mb-2">
        <div class="data-label mb-1" style="font-weight:600">General Vehicle Condition (describe any pre-existing damage, dents, scratches):</div>
        <div class="condition-box"><?= $sale['delivery_notes'] ? nl2br(e($sale['delivery_notes'])) : '&nbsp;' ?></div>
    </div>

    <!-- Accessories Checklist -->
    <div class="section-heading">4. Accessories Handover Checklist</div>
    <table class="table table-bordered acc-table mb-0">
        <thead style="background:#f8fafc">
            <tr>
                <th style="width:50%">Item</th>
                <th style="width:15%;text-align:center">Included</th>
                <th style="width:15%;text-align:center">Not Included</th>
                <th style="width:20%">Remarks</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($accessories as $item => $default): ?>
        <tr>
            <td><?= e($item) ?></td>
            <td style="text-align:center"><span class="check-box"><?= $default ? '✓' : '' ?></span></td>
            <td style="text-align:center"><span class="check-box"><?= !$default ? '✓' : '' ?></span></td>
            <td></td>
        </tr>
        <?php endforeach; ?>
        <tr>
            <td><em>Other: _________________________</em></td>
            <td style="text-align:center"><span class="check-box"></span></td>
            <td style="text-align:center"><span class="check-box"></span></td>
            <td></td>
        </tr>
        </tbody>
    </table>
    <div style="font-size:10px;color:#94a3b8;margin-top:4px">Staff should verify each item physically before signing. Check marks indicate item is present at time of handover.</div>

    <!-- Declaration -->
    <div class="section-heading">5. Declaration</div>
    <div style="font-size:12px;line-height:1.8;margin-bottom:16px">
        I, <strong><?= e($sale['buyer_name']) ?></strong>, hereby confirm that I have received the above-described motor vehicle from <strong><?= e($co['name']) ?></strong> in the condition described above, together with the accessories listed. I am satisfied with the condition of the vehicle and the completeness of the accessories delivered.
    </div>

    <!-- Signatures -->
    <div class="row mt-3 g-4">
        <div class="col-4">
            <div style="margin-bottom:36px">&nbsp;</div>
            <div class="sig-block">
                <div class="sig-label"><strong>HANDED OVER BY</strong></div>
                <div class="sig-label">Name: <?= e($sale['sold_by_name'] ?? '________________________') ?></div>
                <div class="sig-label">Designation: ____________________</div>
                <div class="sig-label">Date: ____________________</div>
            </div>
        </div>
        <div class="col-4">
            <div style="margin-bottom:36px">&nbsp;</div>
            <div class="sig-block">
                <div class="sig-label"><strong>RECEIVED BY (BUYER)</strong></div>
                <div class="sig-label">Name: <?= e($sale['buyer_name']) ?></div>
                <div class="sig-label">ID: <?= e($sale['buyer_id_number'] ?? '____________________') ?></div>
                <div class="sig-label">Date: ____________________</div>
            </div>
        </div>
        <div class="col-4">
            <div style="margin-bottom:36px">&nbsp;</div>
            <div class="sig-block">
                <div class="sig-label"><strong>WITNESS</strong></div>
                <div class="sig-label">Name: ____________________</div>
                <div class="sig-label">ID: ____________________</div>
                <div class="sig-label">Date: ____________________</div>
            </div>
        </div>
    </div>

    <div style="margin-top:28px;padding-top:14px;border-top:1px solid #e2e8f0;font-size:10px;color:#94a3b8;text-align:center">
        This certificate was generated by <?= e($co['name']) ?> Management System on <?= date('d F Y, H:i') ?> &mdash; Ref: <?= e($sale['sale_number']) ?>
    </div>
</div>
</body>
</html>

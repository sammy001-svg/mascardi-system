<?php
require_once __DIR__ . '/../../includes/functions.php';

$id = (int)($_GET['id'] ?? 0);
$db = getDB();

$stmt = $db->prepare("
    SELECT qa.*, 
           sb.booking_number,
           c.chassis_number,
           c.make, c.model, c.year, c.registration_number,
           u.name as creator_name
    FROM quick_assessments qa
    LEFT JOIN service_bookings sb ON sb.id = qa.service_booking_id
    LEFT JOIN cars c ON c.id = qa.car_id
    LEFT JOIN users u ON u.id = qa.created_by
    WHERE qa.id = ?
");
$stmt->execute([$id]);
$a = $stmt->fetch();

if (!$a) die('Assessment not found');

$company = [
    'name'    => getSetting('company_name', 'Mascardi Car Yard'),
    'address' => getSetting('company_address', 'Nairobi, Kenya'),
    'phone'   => getSetting('company_phone', ''),
    'email'   => getSetting('company_email', ''),
    'pin'     => getSetting('company_pin', '')
];

$checks = [
    'tyres'     => 'Tyres',
    'lights'    => 'Lights',
    'exterior'  => 'Exterior Body',
    'engine'    => 'Engine Bay',
    'interior'  => 'Interior',
    'brakes'    => 'Brakes',
    'fluids'    => 'Fluid Levels',
    'electrical'=> 'Electrical',
];
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>QA-<?= e($a['assessment_number']) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background: #f1f5f9; font-size: 13px; font-family: 'Inter', system-ui, sans-serif; }
        .print-wrapper { max-width: 850px; margin: 30px auto; background: #fff; padding: 50px; border-radius: 8px; box-shadow: 0 4px 6px -1px rgb(0 0 0 / 0.1); }
        .header-logo { font-size: 24px; font-weight: 800; color: #0f172a; letter-spacing: -0.5px; }
        .doc-title { font-size: 28px; font-weight: 700; color: #2563eb; text-transform: uppercase; }
        .info-label { color: #64748b; font-size: 11px; font-weight: 700; text-transform: uppercase; margin-bottom: 2px; }
        .info-value { font-weight: 600; color: #1e293b; }
        .check-item { border-bottom: 1px solid #f1f5f9; padding: 8px 0; }
        .check-status { font-weight: 700; text-transform: uppercase; font-size: 11px; }
        .status-ok { color: #16a34a; }
        .status-issue { color: #dc2626; }
        .status-na { color: #94a3b8; }
        @media print {
            body { background: #fff; margin: 0; }
            .print-wrapper { box-shadow: none; margin: 0; padding: 20px; max-width: 100%; }
            .no-print { display: none !important; }
        }
    </style>
</head>
<body>

<div class="no-print text-center py-4">
    <button onclick="window.print()" class="btn btn-primary px-4"><i class="fa fa-print me-2"></i>Print Assessment</button>
    <a href="view.php?id=<?= $id ?>" class="btn btn-outline-secondary ms-2">Back to System</a>
</div>

<div class="print-wrapper">
    <!-- Header -->
    <div class="row mb-5">
        <div class="col-7">
            <div class="header-logo mb-2"><?= e($company['name']) ?></div>
            <div class="text-muted small">
                <?= e($company['address']) ?><br>
                Tel: <?= e($company['phone']) ?> | Email: <?= e($company['email']) ?><br>
                <?php if($company['pin']): ?>KRA PIN: <?= e($company['pin']) ?><?php endif; ?>
            </div>
        </div>
        <div class="col-5 text-end">
            <div class="doc-title mb-1">Quick Assessment</div>
            <div class="h5 fw-bold text-dark">#<?= e($a['assessment_number']) ?></div>
            <div class="text-muted small">Date: <?= fmtDate($a['assessment_date'], 'd F Y') ?></div>
        </div>
    </div>

    <div class="row mb-5 g-4">
        <!-- Client Info -->
        <div class="col-6">
            <div class="p-3 border rounded-3 bg-light bg-opacity-25 h-100">
                <div class="info-label">Client Details</div>
                <div class="info-value fs-6"><?= e($a['client_name'] ?: 'Walk-in Client') ?></div>
                <div class="text-muted small"><?= e($a['client_phone']) ?></div>
            </div>
        </div>
        <!-- Vehicle Info -->
        <div class="col-6">
            <div class="p-3 border rounded-3 bg-light bg-opacity-25 h-100">
                <div class="info-label">Vehicle Details</div>
                <div class="info-value fs-6"><?= e($a['car_make'] . ' ' . $a['car_model'] . ' (' . $a['car_year'] . ')') ?></div>
                <div class="small">
                    <span class="text-muted">Reg:</span> <?= e($a['car_registration'] ?: 'N/A') ?> | 
                    <span class="text-muted">Chassis:</span> <?= e($a['chassis_number'] ?: 'N/A') ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Checklist -->
    <div class="mb-5">
        <h6 class="fw-bold mb-3 border-bottom pb-2">VEHICLE CONDITION CHECKLIST</h6>
        <div class="row g-x-5">
            <?php foreach($checks as $key => $label): 
                $val = $a["check_{$key}"];
                $statusClass = $val === 'ok' ? 'status-ok' : ($val === 'issue' ? 'status-issue' : 'status-na');
                $statusText = $val === 'ok' ? 'PASSED' : ($val === 'issue' ? 'ISSUE DETECTED' : 'NOT APPLICABLE');
            ?>
            <div class="col-6">
                <div class="check-item d-flex justify-content-between align-items-center">
                    <span class="fw-medium"><?= $label ?></span>
                    <span class="check-status <?= $statusClass ?>"><?= $statusText ?></span>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Observations -->
    <div class="row mb-5">
        <div class="col-12">
            <h6 class="fw-bold mb-2 border-bottom pb-2 text-uppercase" style="font-size:11px">Observations & Findings</h6>
            <div class="p-3 border rounded-3 bg-light bg-opacity-10 min-vh-10" style="min-height: 80px">
                <?= nl2br(e($a['observations'])) ?: '<span class="text-muted italic">No major issues observed.</span>' ?>
            </div>
        </div>
    </div>

    <!-- Recommendations -->
    <div class="row mb-5">
        <div class="col-12">
            <h6 class="fw-bold mb-2 border-bottom pb-2 text-uppercase" style="font-size:11px">Recommended Services / Actions</h6>
            <div class="p-3 border rounded-3 bg-light bg-opacity-10" style="min-height: 60px">
                <?= nl2br(e($a['recommended_services'])) ?: '<span class="text-muted italic">Routine maintenance suggested.</span>' ?>
            </div>
        </div>
    </div>

    <!-- Overall Condition -->
    <div class="mb-5 p-3 border rounded-3 d-flex justify-content-between align-items-center">
        <div>
            <div class="info-label">Overall Assessment Result</div>
            <div class="info-value text-uppercase"><?= str_replace('_', ' ', $a['overall_condition']) ?></div>
        </div>
        <div class="text-end">
            <div class="info-label">Inspector</div>
            <div class="info-value"><?= e($a['assessed_by'] ?: $a['creator_name']) ?></div>
        </div>
    </div>

    <!-- Authorization -->
    <div class="mt-5 pt-5">
        <div class="row text-center g-5">
            <div class="col-6">
                <div style="border-top: 1px solid #cbd5e1; padding-top: 10px;">
                    <div class="fw-bold">CLIENT SIGNATURE</div>
                    <div class="text-muted small">I acknowledge receipt and findings of this assessment.</div>
                </div>
            </div>
            <div class="col-6">
                <div style="border-top: 1px solid #cbd5e1; padding-top: 10px;">
                    <div class="fw-bold">AUTHORIZED INSPECTOR</div>
                    <div class="text-muted small">Official Stamp & Signature</div>
                </div>
            </div>
        </div>
    </div>

    <div class="text-center mt-5 pt-5 text-muted small border-top">
        Thank you for choosing <?= e($company['name']) ?> for your vehicle care.
    </div>
</div>

<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
</body>
</html>

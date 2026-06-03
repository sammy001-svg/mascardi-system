<?php
require_once __DIR__ . '/../../includes/functions.php';

$id = (int)($_GET['id'] ?? 0);
$db = getDB();

$stmt = $db->prepare("
    SELECT qa.*,
           sb.booking_number,
           c.chassis_number,
           c.make, c.model, c.year, c.registration_number,
           u.name AS creator_name
    FROM quick_assessments qa
    LEFT JOIN service_bookings sb ON sb.id = qa.service_booking_id
    LEFT JOIN cars c ON c.id = qa.car_id
    LEFT JOIN users u ON u.id = qa.created_by
    WHERE qa.id = ?
");
$stmt->execute([$id]);
$a = $stmt->fetch();
if (!$a) die('Assessment not found.');

$company = [
    'name'    => getSetting('company_name', 'Mascardi Car Yard'),
    'address' => getSetting('company_address', 'Nairobi, Kenya'),
    'phone'   => getSetting('company_phone', ''),
    'email'   => getSetting('company_email', ''),
    'pin'     => getSetting('company_pin', ''),
];

// Check item labels — same order as add.php
$checkLabels = [
    'tyres'      => 'Tyres',
    'lights'     => 'Lights',
    'exterior'   => 'Exterior Body',
    'engine'     => 'Engine Bay',
    'interior'   => 'Interior',
    'brakes'     => 'Brakes',
    'fluids'     => 'Fluid Levels',
    'electrical' => 'Electrical',
    'jack'       => 'Jack & Tools',
    'radio'      => 'Radio / Audio',
];

// Only keep check items that have a value
$filledChecks = [];
foreach ($checkLabels as $key => $label) {
    $val = trim($a["check_{$key}"] ?? '');
    if ($val !== '') {
        $filledChecks[$key] = ['label' => $label, 'value' => $val];
    }
}

$overallMap = [
    'good'            => ['color' => '#16a34a', 'bg' => '#f0fdf4', 'border' => '#bbf7d0', 'label' => 'Good'],
    'fair'            => ['color' => '#d97706', 'bg' => '#fffbeb', 'border' => '#fde68a', 'label' => 'Fair'],
    'needs_attention' => ['color' => '#2563eb', 'bg' => '#eff6ff', 'border' => '#bfdbfe', 'label' => 'Needs Attention'],
    'critical'        => ['color' => '#dc2626', 'bg' => '#fef2f2', 'border' => '#fecaca', 'label' => 'Critical'],
];
$overall = $overallMap[$a['overall_condition']] ?? ['color' => '#64748b', 'bg' => '#f8fafc', 'border' => '#e2e8f0', 'label' => ucwords(str_replace('_', ' ', $a['overall_condition']))];

// Helper: truthy non-empty value
function filled($v): bool { return isset($v) && trim((string)$v) !== ''; }
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>QA-<?= e($a['assessment_number']) ?> — Quick Assessment</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
    <style>
        * { box-sizing: border-box; }
        body { background: #f1f5f9; font-size: 13px; font-family: 'Inter', system-ui, -apple-system, sans-serif; color: #0f172a; }

        .print-wrapper {
            max-width: 850px;
            margin: 30px auto;
            background: #fff;
            padding: 50px 52px;
            border-radius: 10px;
            box-shadow: 0 4px 24px rgba(0,0,0,.10);
        }

        /* Header */
        .header-logo  { font-size: 22px; font-weight: 800; color: #0f172a; letter-spacing: -.5px; }
        .doc-title    { font-size: 26px; font-weight: 800; color: #2563eb; text-transform: uppercase; letter-spacing: -.3px; }
        .doc-number   { font-size: 18px; font-weight: 700; color: #0f172a; }

        /* Info panels */
        .info-panel   { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 14px 16px; }
        .info-label   { font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: .7px; color: #64748b; margin-bottom: 3px; }
        .info-value   { font-weight: 600; color: #0f172a; font-size: 14px; }
        .info-sub     { color: #475569; font-size: 12px; margin-top: 2px; }

        /* Section headings */
        .section-heading {
            font-size: 10px; font-weight: 700; text-transform: uppercase;
            letter-spacing: .8px; color: #64748b;
            border-bottom: 2px solid #e2e8f0;
            padding-bottom: 6px; margin-bottom: 14px;
        }

        /* Check grid */
        .check-table  { width: 100%; border-collapse: collapse; }
        .check-table td { padding: 7px 10px; vertical-align: top; }
        .check-table tr:nth-child(odd) td  { background: #f8fafc; }
        .check-table tr:nth-child(even) td { background: #fff; }
        .check-table .check-label {
            width: 130px; font-weight: 600; color: #475569;
            font-size: 12px; white-space: nowrap;
            border-right: 1px solid #e2e8f0;
        }
        .check-table .check-value { color: #0f172a; font-size: 13px; }

        /* Textarea sections */
        .text-section { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 12px 14px; line-height: 1.6; white-space: pre-wrap; }

        /* Overall condition */
        .condition-box { border-radius: 8px; padding: 14px 18px; }

        /* Signatures */
        .sig-line { border-top: 1px solid #cbd5e1; padding-top: 8px; text-align: center; }

        /* Print controls */
        .no-print-bar {
            position: sticky; top: 0; z-index: 100;
            background: #1e293b; padding: 12px 24px;
            display: flex; align-items: center; gap: 12px;
        }

        @media print {
            body  { background: #fff; margin: 0; font-size: 12px; }
            .print-wrapper { box-shadow: none; margin: 0; padding: 20px 24px; border-radius: 0; max-width: 100%; }
            .no-print-bar { display: none !important; }
            @page { margin: 15mm 12mm; }
        }
    </style>
</head>
<body>

<!-- Print toolbar -->
<div class="no-print-bar">
    <button onclick="window.print()" class="btn btn-primary btn-sm px-4">
        <i class="fa fa-print me-2"></i>Print / Save PDF
    </button>
    <a href="view.php?id=<?= $id ?>" class="btn btn-outline-light btn-sm">
        <i class="fa fa-arrow-left me-1"></i>Back
    </a>
    <span class="text-white-50 small ms-auto">Only filled sections will appear on the printout</span>
</div>

<div class="print-wrapper">

    <!-- ── Document Header ─────────────────────────────────────────────────── -->
    <div class="row mb-4">
        <div class="col-7">
            <?php $__logo = getSetting('company_logo', ''); ?>
            <?php if ($__logo && file_exists(BASE_PATH . '/assets/images/' . $__logo)): ?>
            <img src="<?= BASE_URL ?>/assets/images/<?= e($__logo) ?>"
                 alt="<?= e($company['name']) ?>"
                 style="height:48px;max-width:170px;object-fit:contain;margin-bottom:6px;display:block">
            <?php else: ?>
            <div class="header-logo mb-1"><?= e($company['name']) ?></div>
            <?php endif; ?>
            <div style="color:#64748b;font-size:12px;line-height:1.7">
                <?php if (filled($company['address'])): ?><?= e($company['address']) ?><br><?php endif; ?>
                <?php if (filled($company['phone'])): ?>Tel: <?= e($company['phone']) ?><?php endif; ?>
                <?php if (filled($company['phone']) && filled($company['email'])): ?> &nbsp;|&nbsp; <?php endif; ?>
                <?php if (filled($company['email'])): ?>Email: <?= e($company['email']) ?><?php endif; ?>
                <?php if (filled($company['pin'])): ?><br>KRA PIN: <?= e($company['pin']) ?><?php endif; ?>
            </div>
        </div>
        <div class="col-5 text-end">
            <div class="doc-title">Quick Assessment</div>
            <div class="doc-number mt-1"># <?= e($a['assessment_number']) ?></div>
            <div style="color:#64748b;font-size:12px;margin-top:4px">
                Date: <strong><?= fmtDate($a['assessment_date'], 'd F Y') ?></strong>
            </div>
            <?php if (filled($a['booking_number'])): ?>
            <div style="color:#64748b;font-size:11px;margin-top:2px">
                Booking: <?= e($a['booking_number']) ?>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <hr style="border-color:#e2e8f0;margin-bottom:24px">

    <!-- ── Client & Vehicle ────────────────────────────────────────────────── -->
    <div class="row g-3 mb-4">
        <div class="col-6">
            <div class="info-panel h-100">
                <div class="info-label">Client Details</div>
                <div class="info-value"><?= e($a['client_name'] ?: 'Walk-in Client') ?></div>
                <?php if (filled($a['client_phone'])): ?>
                <div class="info-sub"><i class="fa fa-phone me-1" style="font-size:10px"></i><?= e($a['client_phone']) ?></div>
                <?php endif; ?>
                <?php if (filled($a['client_email'])): ?>
                <div class="info-sub"><i class="fa fa-envelope me-1" style="font-size:10px"></i><?= e($a['client_email']) ?></div>
                <?php endif; ?>
            </div>
        </div>
        <div class="col-6">
            <div class="info-panel h-100">
                <div class="info-label">Vehicle Details</div>
                <div class="info-value">
                    <?= e(trim(($a['car_make'] ?? '') . ' ' . ($a['car_model'] ?? ''))) ?>
                    <?php $yr = $a['car_year'] ?: ($a['year'] ?? ''); if (filled($yr)): ?>(<?= e($yr) ?>)<?php endif; ?>
                </div>
                <div class="info-sub" style="margin-top:4px">
                    <?php $reg = $a['car_registration'] ?: ($a['registration_number'] ?? ''); ?>
                    <?php $chs = $a['chassis_number'] ?? ''; ?>
                    <?php if (filled($reg)): ?><span>Reg: <strong><?= e(strtoupper($reg)) ?></strong></span><?php endif; ?>
                    <?php if (filled($reg) && filled($chs)): ?> &nbsp;|&nbsp; <?php endif; ?>
                    <?php if (filled($chs)): ?><span>Chassis: <strong><?= e($chs) ?></strong></span><?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- ── Mileage & Fuel (inline row, only if either is set) ──────────────── -->
    <?php $hasMileage = filled($a['check_mileage']); $hasFuel = filled($a['check_fuel_level']); ?>
    <?php if ($hasMileage || $hasFuel): ?>
    <div class="row g-3 mb-4">
        <?php if ($hasMileage): ?>
        <div class="col-<?= $hasFuel ? '6' : '4' ?>">
            <div class="info-panel">
                <div class="info-label"><i class="fa fa-gauge-high me-1"></i>Mileage</div>
                <div class="info-value"><?= number_format((float)$a['check_mileage']) ?> km</div>
            </div>
        </div>
        <?php endif; ?>
        <?php if ($hasFuel): ?>
        <div class="col-<?= $hasMileage ? '6' : '4' ?>">
            <div class="info-panel">
                <div class="info-label"><i class="fa fa-gas-pump me-1"></i>Fuel Level</div>
                <div class="info-value"><?= e($a['check_fuel_level']) ?></div>
            </div>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- ── Vehicle Condition Checks ────────────────────────────────────────── -->
    <?php if (!empty($filledChecks)): ?>
    <div class="mb-4">
        <div class="section-heading">Vehicle Condition Check</div>
        <?php
        // Split into two columns for side-by-side display
        $checkChunks = array_chunk($filledChecks, (int)ceil(count($filledChecks) / 2), true);
        $col1 = $checkChunks[0] ?? [];
        $col2 = $checkChunks[1] ?? [];
        $maxRows = max(count($col1), count($col2));
        $col1 = array_values($col1);
        $col2 = array_values($col2);
        ?>
        <table class="check-table" style="border:1px solid #e2e8f0;border-radius:8px;overflow:hidden">
            <tbody>
            <?php for ($r = 0; $r < $maxRows; $r++): ?>
            <tr>
                <?php if (isset($col1[$r])): ?>
                <td class="check-label"><?= e($col1[$r]['label']) ?></td>
                <td class="check-value"><?= e($col1[$r]['value']) ?></td>
                <?php else: ?>
                <td class="check-label"></td><td class="check-value"></td>
                <?php endif; ?>
                <td style="width:1px;background:#e2e8f0;padding:0"></td>
                <?php if (isset($col2[$r])): ?>
                <td class="check-label"><?= e($col2[$r]['label']) ?></td>
                <td class="check-value"><?= e($col2[$r]['value']) ?></td>
                <?php else: ?>
                <td class="check-label"></td><td class="check-value"></td>
                <?php endif; ?>
            </tr>
            <?php endfor; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

    <!-- ── Dents / Scratches ───────────────────────────────────────────────── -->
    <?php if (filled($a['check_dents'])): ?>
    <div class="mb-4">
        <div class="section-heading"><i class="fa fa-car-burst me-1"></i>Dents / Scratches</div>
        <div class="text-section"><?= e($a['check_dents']) ?></div>
    </div>
    <?php endif; ?>

    <!-- ── Items Left in Car ────────────────────────────────────────────────── -->
    <?php if (filled($a['check_items_left'])): ?>
    <div class="mb-4">
        <div class="section-heading"><i class="fa fa-box-open me-1"></i>Items Left in Car</div>
        <div class="text-section"><?= e($a['check_items_left']) ?></div>
    </div>
    <?php endif; ?>

    <!-- ── Observations ─────────────────────────────────────────────────────── -->
    <?php if (filled($a['observations'])): ?>
    <div class="mb-4">
        <div class="section-heading"><i class="fa fa-note-sticky me-1"></i>Observations &amp; Findings</div>
        <div class="text-section"><?= nl2br(e($a['observations'])) ?></div>
    </div>
    <?php endif; ?>

    <!-- ── Recommended Services ─────────────────────────────────────────────── -->
    <?php if (filled($a['recommended_services'])): ?>
    <div class="mb-4">
        <div class="section-heading"><i class="fa fa-list-check me-1"></i>Recommended Services / Actions</div>
        <div class="text-section"><?= nl2br(e($a['recommended_services'])) ?></div>
    </div>
    <?php endif; ?>

    <!-- ── Overall Condition ────────────────────────────────────────────────── -->
    <div class="mb-5">
        <div class="section-heading"><i class="fa fa-gauge me-1"></i>Overall Assessment Result</div>
        <div class="condition-box d-flex justify-content-between align-items-center"
             style="background:<?= $overall['bg'] ?>;border:1px solid <?= $overall['border'] ?>">
            <div>
                <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:<?= $overall['color'] ?>">Overall Condition</div>
                <div style="font-size:18px;font-weight:800;color:<?= $overall['color'] ?>"><?= e($overall['label']) ?></div>
            </div>
            <div class="text-end">
                <div style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:#64748b">Inspected By</div>
                <div style="font-weight:600;color:#0f172a"><?= e($a['assessed_by'] ?: $a['creator_name'] ?: '—') ?></div>
            </div>
        </div>
    </div>

    <!-- ── Signatures ───────────────────────────────────────────────────────── -->
    <div class="row g-5 mt-4 pt-3" style="border-top:1px solid #f1f5f9">
        <div class="col-6">
            <div style="height:48px"></div>
            <div class="sig-line">
                <div style="font-weight:700;font-size:12px">CLIENT SIGNATURE</div>
                <div style="color:#64748b;font-size:11px;margin-top:2px">I acknowledge receipt of this assessment.</div>
            </div>
        </div>
        <div class="col-6">
            <div style="height:48px"></div>
            <div class="sig-line">
                <div style="font-weight:700;font-size:12px">AUTHORIZED INSPECTOR</div>
                <div style="color:#64748b;font-size:11px;margin-top:2px">Official Stamp &amp; Signature</div>
            </div>
        </div>
    </div>

    <!-- ── Footer ───────────────────────────────────────────────────────────── -->
    <div class="text-center mt-5 pt-3" style="border-top:1px solid #f1f5f9;color:#94a3b8;font-size:11px">
        Thank you for choosing <?= e($company['name']) ?> for your vehicle care.
    </div>

</div><!-- /print-wrapper -->
</body>
</html>

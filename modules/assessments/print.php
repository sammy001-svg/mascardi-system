<?php
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
canAccess('assessments') || redirect(BASE_URL . '/index.php');

$id = (int)($_GET['id'] ?? 0);
if (!$id) redirect(BASE_URL . '/modules/assessments/index.php');

$db = getDB();

// ── Assessment + vehicle + mechanic ───────────────────────────────────────────
$stmt = $db->prepare("
    SELECT ca.*,
           c.make, c.model, c.year, c.color,
           c.chassis_number, c.registration_number, c.engine_number,
           c.transmission, c.fuel_type, c.body_type,
           m.name  AS mechanic_name,
           m.phone AS mechanic_phone,
           m.specialization AS mechanic_spec
    FROM car_assessments ca
    JOIN cars c ON c.id = ca.car_id
    LEFT JOIN mechanics m ON m.id = ca.mechanic_id
    WHERE ca.id = ?
");
$stmt->execute([$id]);
$assessment = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$assessment) {
    setFlash('error', 'Assessment not found.');
    redirect(BASE_URL . '/modules/assessments/index.php');
}

// ── Assessment items ───────────────────────────────────────────────────────────
$ait = $db->prepare("
    SELECT * FROM assessment_items
    WHERE assessment_id = ?
    ORDER BY part_category, part_name
");
$ait->execute([$id]);
$items = $ait->fetchAll(PDO::FETCH_ASSOC);

$byCategory = [];
$issueItems = [];
$goodCount  = 0;
foreach ($items as $item) {
    $byCategory[$item['part_category']][] = $item;
    if ($item['condition'] !== 'good') {
        $issueItems[] = $item;
    } else {
        $goodCount++;
    }
}
$totalItems  = count($items);
$issueCount  = count($issueItems);

// ── Company settings ───────────────────────────────────────────────────────────
$companyName    = getSetting('company_name',    'Mascardi Ventures Limited');
$companyAddress = getSetting('company_address', '291 Kabete Lane, Spring Valley, Nairobi');
$companyPhone   = getSetting('company_phone',   '');
$companyEmail   = getSetting('company_email',   '');
$companyPin     = getSetting('company_pin',     '');
$logo           = companyLogo();

// ── Condition / label maps ─────────────────────────────────────────────────────
$condMeta = [
    'good'          => ['label' => 'Good',         'icon' => '✓', 'cls' => 'cond-good'],
    'minor_damage'  => ['label' => 'Minor Damage', 'icon' => '⚠', 'cls' => 'cond-warn'],
    'major_damage'  => ['label' => 'Major Damage', 'icon' => '✗', 'cls' => 'cond-bad'],
    'missing'       => ['label' => 'Missing',       'icon' => '—', 'cls' => 'cond-bad'],
    'needs_service' => ['label' => 'Needs Service', 'icon' => '⚙', 'cls' => 'cond-svc'],
];

$fuelLabels = [
    'empty'         => 'Empty (E)',
    'quarter'       => '¼ Tank',
    'half'          => 'Half Tank (½)',
    'three_quarter' => '¾ Tank',
    'full'          => 'Full (F)',
];

$typeLabels = [
    'arrival'        => 'Vehicle Intake / Arrival',
    'pre_delivery'   => 'Pre-Delivery Inspection',
    'client_service' => 'Client Service Assessment',
    'yard'           => 'Yard Assessment',
];

$overallMeta = [
    'excellent' => ['label' => 'Excellent', 'color' => '#15803d'],
    'good'      => ['label' => 'Good',      'color' => '#1d4ed8'],
    'fair'      => ['label' => 'Fair',      'color' => '#b45309'],
    'poor'      => ['label' => 'Poor',      'color' => '#c2410c'],
    'critical'  => ['label' => 'Critical',  'color' => '#b91c1c'],
];

$reportNo = 'ASM-' . str_pad($id, 5, '0', STR_PAD_LEFT) . '-' . date('y');

$pageTitle = 'Assessment Report — ' . $assessment['make'] . ' ' . $assessment['model'];
include __DIR__ . '/../../includes/header.php';
?>
<style>
/* ── Print rules ─────────────────────────────────────────────────────────── */
@page { size: A4; margin: 0; }
@media print {
    .d-print-none { display: none !important; }
    .app-sidebar, .topbar, .sidebar-overlay, .app-topbar,
    header.app-topbar, #sidebarBackdrop, .fab-wa, .fab-chat,
    #pwaOverlay, #toastStack { display: none !important; }
    .main-wrap, .main-content, .page-body { margin: 0 !important; padding: 0 !important; }
    body { background: #fff !important; margin: 0 !important; }
    #asmDoc {
        box-shadow: none !important; border: none !important;
        border-radius: 0 !important; max-width: 100% !important;
        padding: 1.1cm 1.5cm !important; margin: 0 !important;
    }
    .asm-page-break { page-break-before: always; }
    .asm-no-break   { page-break-inside: avoid; }
}

/* ── Document wrapper ────────────────────────────────────────────────────── */
#asmDoc {
    max-width: 820px;
    margin: 0 auto 40px;
    background: #fff;
    border: 1px solid #d1d5db;
    border-radius: 8px;
    font-family: Arial, Helvetica, sans-serif;
    font-size: 12px;
    color: #111;
    line-height: 1.55;
    box-shadow: 0 4px 28px rgba(0,0,0,.1);
    padding: 32px 38px;
}

/* ── Header ──────────────────────────────────────────────────────────────── */
.asm-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    border-bottom: 3px solid #0f172a;
    padding-bottom: 16px;
    margin-bottom: 20px;
}
.asm-co-name {
    font-family: 'Times New Roman', Times, Georgia, serif;
    font-style: italic;
    font-size: 26px;
    font-weight: normal;
    line-height: 1.1;
    color: #0f172a;
    margin-bottom: 5px;
}
.asm-co-sub {
    font-size: 10.5px;
    color: #475569;
    line-height: 1.7;
}
.asm-doc-title {
    text-align: right;
}
.asm-doc-title-main {
    font-size: 22px;
    font-weight: 900;
    letter-spacing: 2px;
    text-transform: uppercase;
    color: #0f172a;
}
.asm-doc-subtitle {
    font-size: 10.5px;
    color: #64748b;
    font-style: italic;
    margin-top: 2px;
}
.asm-doc-meta {
    font-size: 11.5px;
    color: #374151;
    margin-top: 8px;
    line-height: 2;
}

/* ── Overall condition banner ────────────────────────────────────────────── */
.asm-status-banner {
    display: flex;
    align-items: center;
    gap: 0;
    margin-bottom: 18px;
    border: 1.5px solid #e2e8f0;
    border-radius: 8px;
    overflow: hidden;
}
.asm-status-cell {
    flex: 1;
    padding: 10px 14px;
    text-align: center;
    border-right: 1px solid #e2e8f0;
    font-size: 11px;
}
.asm-status-cell:last-child { border-right: none; }
.asm-status-label { color: #64748b; font-size: 10px; text-transform: uppercase; letter-spacing: .05em; margin-bottom: 3px; }
.asm-status-value { font-weight: 800; font-size: 13px; color: #0f172a; }

/* ── Section headings ────────────────────────────────────────────────────── */
.asm-section {
    margin: 18px 0 10px;
    padding-bottom: 4px;
    border-bottom: 2px solid #0f172a;
    display: flex;
    align-items: baseline;
    gap: 8px;
}
.asm-section-title {
    font-size: 11px;
    font-weight: 900;
    text-transform: uppercase;
    letter-spacing: .08em;
    color: #0f172a;
}
.asm-section-sub {
    font-size: 10px;
    color: #94a3b8;
    font-weight: 400;
}

/* ── Tables ──────────────────────────────────────────────────────────────── */
.asm-table {
    width: 100%;
    border-collapse: collapse;
    margin-bottom: 6px;
}
.asm-table td, .asm-table th {
    border: 1px solid #e2e8f0;
    padding: 6px 10px;
    vertical-align: top;
    font-size: 11.5px;
}
.asm-table th {
    background: #f8fafc;
    font-weight: 700;
    color: #374151;
    white-space: nowrap;
    width: 28%;
}
.asm-table td { color: #0f172a; }
.asm-table td strong { font-weight: 700; }

/* ── Issues table ────────────────────────────────────────────────────────── */
.asm-issues-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 11px;
    margin-bottom: 4px;
}
.asm-issues-table thead th {
    background: #0f172a;
    color: #fff;
    padding: 7px 10px;
    text-align: left;
    font-size: 10.5px;
    font-weight: 700;
    letter-spacing: .04em;
}
.asm-issues-table tbody td {
    border: 1px solid #e2e8f0;
    padding: 5px 10px;
    vertical-align: top;
}
.asm-issues-table tbody tr:nth-child(even) td { background: #f8fafc; }

/* ── Checklist grid ──────────────────────────────────────────────────────── */
.asm-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 0 24px;
}
.asm-cat-head {
    grid-column: 1 / -1;
    background: #1e3a5f;
    color: #fff;
    font-size: 10px;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: .07em;
    padding: 5px 9px;
    margin-top: 10px;
    display: flex;
    justify-content: space-between;
    align-items: center;
}
.asm-cat-badge {
    font-size: 9.5px;
    font-weight: 700;
    padding: 1px 6px;
    border-radius: 3px;
    background: rgba(255,255,255,.15);
}
.asm-cat-badge.bad { background: #ef4444; }
.asm-cat-badge.ok  { background: #16a34a; }
.asm-part-row {
    display: flex;
    align-items: flex-start;
    gap: 6px;
    padding: 4px 0 3px;
    border-bottom: 1px solid #f1f5f9;
    font-size: 11px;
}
.asm-part-name { flex: 1; color: #374151; }
.asm-part-note { font-size: 10px; color: #64748b; font-style: italic; grid-column: 1/-1; padding: 0 0 3px 4px; }

/* ── Condition colours ───────────────────────────────────────────────────── */
.cond-good { color: #15803d; font-weight: 700; white-space: nowrap; }
.cond-warn { color: #b45309; font-weight: 700; white-space: nowrap; }
.cond-bad  { color: #b91c1c; font-weight: 700; white-space: nowrap; }
.cond-svc  { color: #1d4ed8; font-weight: 700; white-space: nowrap; }

/* ── Fuel gauge ──────────────────────────────────────────────────────────── */
.fuel-bar {
    display: inline-flex;
    height: 10px;
    width: 80px;
    border: 1px solid #94a3b8;
    border-radius: 3px;
    overflow: hidden;
    vertical-align: middle;
    margin-left: 6px;
}
.fuel-fill {
    background: linear-gradient(90deg, #16a34a, #22c55e);
    height: 100%;
    transition: width .3s;
}

/* ── Signature block ─────────────────────────────────────────────────────── */
.asm-sig-grid {
    display: grid;
    grid-template-columns: 1fr 1fr 1fr;
    gap: 28px;
    margin-top: 28px;
}
.asm-sig-line {
    border-bottom: 1.5px solid #374151;
    min-height: 44px;
    margin-bottom: 5px;
}
.asm-sig-label { font-size: 11px; font-weight: 700; color: #0f172a; }
.asm-sig-name  { font-size: 10.5px; color: #64748b; margin-top: 2px; }

/* ── Footer ──────────────────────────────────────────────────────────────── */
.asm-footer {
    text-align: center;
    margin-top: 22px;
    font-size: 10px;
    color: #94a3b8;
    border-top: 1px solid #e2e8f0;
    padding-top: 10px;
    line-height: 1.8;
}
.asm-watermark {
    position: absolute;
    top: 50%;
    left: 50%;
    transform: translate(-50%,-50%) rotate(-30deg);
    font-size: 80px;
    font-weight: 900;
    color: rgba(0,0,0,.04);
    pointer-events: none;
    white-space: nowrap;
    letter-spacing: 6px;
}
</style>

<!-- ── Action bar (screen only) ──────────────────────────────────────────────── -->
<div class="d-print-none mb-4 d-flex align-items-center justify-content-between flex-wrap gap-2">
    <div class="d-flex align-items-center gap-2">
        <a href="view.php?id=<?= $id ?>" class="btn btn-outline-secondary btn-sm">
            <i class="fa fa-arrow-left me-1"></i>Back to Assessment
        </a>
        <span class="text-muted" style="font-size:12.5px">
            / <?= e($assessment['make'] . ' ' . $assessment['model'] . ' ' . $assessment['year']) ?>
            &mdash; <?= e($typeLabels[$assessment['assessment_type']] ?? ucwords(str_replace('_',' ',$assessment['assessment_type']))) ?>
        </span>
    </div>
    <button class="btn btn-success btn-sm" onclick="window.print()">
        <i class="fa fa-print me-1"></i>Print / Save PDF
    </button>
</div>

<!-- ── Printable Document ────────────────────────────────────────────────────── -->
<div id="asmDoc" style="position:relative">

    <!-- watermark for drafts if needed -->
    <?php if (($assessment['overall_status'] ?? '') === 'critical'): ?>
    <div class="asm-watermark d-print-none" style="color:rgba(220,38,38,.06)">CRITICAL</div>
    <?php endif; ?>

    <!-- ── Company Header ──────────────────────────────────────────────────── -->
    <div class="asm-header">
        <div>
            <?php if ($logo['exists']): ?>
            <img src="<?= e($logo['url']) ?>" alt="<?= e($companyName) ?>"
                 style="max-height:64px;max-width:200px;object-fit:contain;display:block;margin-bottom:6px">
            <?php else: ?>
            <div class="asm-co-name"><?= e($companyName) ?></div>
            <?php endif; ?>
            <div class="asm-co-sub">
                <i class="fa fa-location-dot d-print-none" style="color:#64748b;margin-right:4px"></i><?= e($companyAddress) ?><br>
                <?php if ($companyPhone): ?>
                <i class="fa fa-phone d-print-none" style="color:#64748b;margin-right:4px"></i><?= e($companyPhone) ?><br>
                <?php endif; ?>
                <?php if ($companyEmail): ?>
                <i class="fa fa-envelope d-print-none" style="color:#64748b;margin-right:4px"></i><?= e($companyEmail) ?>
                <?php endif; ?>
                <?php if ($companyPin): ?>
                &nbsp;&bull;&nbsp;PIN: <?= e($companyPin) ?>
                <?php endif; ?>
            </div>
        </div>
        <div class="asm-doc-title">
            <div class="asm-doc-title-main">Vehicle Assessment</div>
            <div class="asm-doc-subtitle">
                <?= e($typeLabels[$assessment['assessment_type']] ?? ucwords(str_replace('_',' ',$assessment['assessment_type']))) ?>
            </div>
            <div class="asm-doc-meta">
                Report No: <strong><?= e($reportNo) ?></strong><br>
                Date: <strong><?= fmtDate($assessment['assessment_date']) ?></strong><br>
                Printed: <strong><?= date('d M Y, H:i') ?></strong>
            </div>
        </div>
    </div>

    <!-- ── Overall Status Banner ───────────────────────────────────────────── -->
    <?php
    $oMeta      = $overallMeta[$assessment['overall_status']] ?? ['label' => ucfirst($assessment['overall_status']), 'color' => '#374151'];
    $fuelPct    = ['empty' => 0, 'quarter' => 25, 'half' => 50, 'three_quarter' => 75, 'full' => 100][$assessment['fuel_level']] ?? 50;
    $fuelLabel  = $fuelLabels[$assessment['fuel_level']] ?? ucfirst($assessment['fuel_level'] ?? '—');
    ?>
    <div class="asm-status-banner">
        <div class="asm-status-cell">
            <div class="asm-status-label">Overall Condition</div>
            <div class="asm-status-value" style="color:<?= $oMeta['color'] ?>"><?= $oMeta['label'] ?></div>
        </div>
        <div class="asm-status-cell">
            <div class="asm-status-label">Issues Found</div>
            <div class="asm-status-value" style="color:<?= $issueCount > 0 ? '#b91c1c' : '#15803d' ?>">
                <?= $issueCount ?> / <?= $totalItems ?>
            </div>
        </div>
        <div class="asm-status-cell">
            <div class="asm-status-label">Parts Good</div>
            <div class="asm-status-value" style="color:#15803d"><?= $goodCount ?></div>
        </div>
        <div class="asm-status-cell">
            <div class="asm-status-label">Mileage</div>
            <div class="asm-status-value">
                <?= $assessment['mileage'] ? number_format((int)$assessment['mileage']) . ' km' : '—' ?>
            </div>
        </div>
        <div class="asm-status-cell">
            <div class="asm-status-label">Fuel Level</div>
            <div class="asm-status-value">
                <?= $fuelLabel ?>
                <div class="fuel-bar"><div class="fuel-fill" style="width:<?= $fuelPct ?>%"></div></div>
            </div>
        </div>
    </div>

    <!-- ── Vehicle Details ─────────────────────────────────────────────────── -->
    <div class="asm-section asm-no-break">
        <span class="asm-section-title">Vehicle Information</span>
    </div>
    <table class="asm-table">
        <tr>
            <th>Make / Model</th>
            <td><strong><?= e($assessment['make'] . ' ' . $assessment['model']) ?></strong>
                <?php if ($assessment['year']): ?>&nbsp;(<?= e($assessment['year']) ?>)<?php endif; ?>
            </td>
            <th>Colour</th>
            <td><?= e(ucfirst($assessment['color'] ?? '—')) ?></td>
        </tr>
        <tr>
            <th>Chassis / VIN No.</th>
            <td><strong style="font-family:monospace"><?= e($assessment['chassis_number'] ?? '—') ?></strong></td>
            <?php if (!empty($assessment['registration_number'])): ?>
            <th>Registration No.</th>
            <td><?= e($assessment['registration_number']) ?></td>
            <?php elseif (!empty($assessment['engine_number'])): ?>
            <th>Engine No.</th>
            <td><?= e($assessment['engine_number']) ?></td>
            <?php else: ?>
            <th>Engine No.</th>
            <td>—</td>
            <?php endif; ?>
        </tr>
        <?php if (!empty($assessment['transmission']) || !empty($assessment['fuel_type']) || !empty($assessment['body_type'])): ?>
        <tr>
            <?php if (!empty($assessment['transmission'])): ?>
            <th>Transmission</th>
            <td><?= e(ucfirst($assessment['transmission'])) ?></td>
            <?php else: ?><th></th><td></td><?php endif; ?>
            <?php if (!empty($assessment['fuel_type'])): ?>
            <th>Fuel Type</th>
            <td><?= e(ucfirst($assessment['fuel_type'])) ?></td>
            <?php else: ?><th></th><td></td><?php endif; ?>
        </tr>
        <?php endif; ?>
    </table>

    <!-- ── Assessment Details ──────────────────────────────────────────────── -->
    <div class="asm-section asm-no-break">
        <span class="asm-section-title">Assessment Details</span>
    </div>
    <table class="asm-table">
        <tr>
            <th>Assessed By</th>
            <td><strong><?= e($assessment['mechanic_name'] ?? $assessment['driver_name'] ?? '—') ?></strong>
                <?php if (!empty($assessment['mechanic_phone'])): ?>
                &nbsp;&mdash;&nbsp;<?= e($assessment['mechanic_phone']) ?>
                <?php endif; ?>
            </td>
            <th>Assessment Date</th>
            <td><?= fmtDate($assessment['assessment_date']) ?></td>
        </tr>
        <tr>
            <th>Assessment Type</th>
            <td><?= e($typeLabels[$assessment['assessment_type']] ?? ucwords(str_replace('_',' ',$assessment['assessment_type']))) ?></td>
            <th>Report Number</th>
            <td><strong><?= e($reportNo) ?></strong></td>
        </tr>
        <?php if (!empty($assessment['mechanic_spec'])): ?>
        <tr>
            <th>Specialization</th>
            <td colspan="3"><?= e($assessment['mechanic_spec']) ?></td>
        </tr>
        <?php endif; ?>
        <?php if ($assessment['notes']): ?>
        <tr>
            <th>General Observations</th>
            <td colspan="3" style="white-space:pre-wrap"><?= e($assessment['notes']) ?></td>
        </tr>
        <?php endif; ?>
    </table>

    <!-- ── Defects Summary ─────────────────────────────────────────────────── -->
    <?php if ($issueItems): ?>
    <div class="asm-section asm-no-break">
        <span class="asm-section-title" style="color:#991b1b">
            Defects &amp; Issues Requiring Attention
        </span>
        <span class="asm-section-sub"><?= $issueCount ?> item<?= $issueCount > 1 ? 's' : '' ?> found</span>
    </div>
    <table class="asm-issues-table asm-no-break">
        <thead>
            <tr>
                <th style="width:20%">Category</th>
                <th style="width:28%">Part / Component</th>
                <th style="width:18%">Condition</th>
                <th>Notes / Description</th>
                <th style="width:13%;text-align:center">Action Taken</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($issueItems as $item):
                $meta    = $condMeta[$item['condition']] ?? $condMeta['needs_service'];
            ?>
            <tr class="asm-no-break">
                <td style="color:#64748b;font-size:10.5px"><?= e($item['part_category']) ?></td>
                <td><strong><?= e($item['part_name']) ?></strong></td>
                <td class="<?= $meta['cls'] ?>"><?= $meta['icon'] ?>&nbsp;<?= $meta['label'] ?></td>
                <td style="font-size:10.5px;color:#374151">
                    <?= $item['notes'] ? e($item['notes']) : '<em style="color:#cbd5e1">—</em>' ?>
                </td>
                <td style="text-align:center">
                    <div style="border-bottom:1px solid #94a3b8;min-height:20px"></div>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php else: ?>
    <div class="asm-section">
        <span class="asm-section-title" style="color:#15803d">No Defects Found</span>
    </div>
    <div style="padding:10px 12px;background:#f0fdf4;border:1px solid #86efac;border-radius:6px;font-size:11.5px;color:#14532d">
        ✓ All <?= $totalItems ?> inspected parts are in <strong>good condition</strong>. No issues were identified during this assessment.
    </div>
    <?php endif; ?>

    <!-- ── Full Parts Checklist ────────────────────────────────────────────── -->
    <?php if ($byCategory): ?>
    <div class="asm-section" style="margin-top:22px">
        <span class="asm-section-title">Full Parts &amp; Condition Checklist</span>
        <span class="asm-section-sub"><?= $totalItems ?> components assessed</span>
    </div>
    <div class="asm-grid">
        <?php foreach ($byCategory as $cat => $catItems):
            $catIssues = count(array_filter($catItems, fn($i) => $i['condition'] !== 'good'));
            $catGood   = count($catItems) - $catIssues;
        ?>
        <div class="asm-cat-head">
            <span><?= e($cat) ?></span>
            <span class="asm-cat-badge <?= $catIssues > 0 ? 'bad' : 'ok' ?>">
                <?= $catIssues > 0 ? $catIssues . ' issue' . ($catIssues > 1 ? 's' : '') : 'All Good' ?>
            </span>
        </div>
        <?php foreach ($catItems as $item):
            $meta    = $condMeta[$item['condition']] ?? $condMeta['good'];
            $isIssue = $item['condition'] !== 'good';
        ?>
        <div class="asm-part-row" style="<?= $isIssue ? 'background:#fff5f5;' : '' ?>">
            <span class="asm-part-name"><?= e($item['part_name']) ?></span>
            <span class="<?= $meta['cls'] ?>"><?= $meta['icon'] ?>&nbsp;<?= $meta['label'] ?></span>
        </div>
        <?php if ($isIssue && $item['notes']): ?>
        <div class="asm-part-note">&nbsp;&nbsp;↳ <?= e($item['notes']) ?></div>
        <?php endif; ?>
        <?php endforeach; ?>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- ── Sign-off ────────────────────────────────────────────────────────── -->
    <div class="asm-section" style="margin-top:28px">
        <span class="asm-section-title">Declaration &amp; Sign-off</span>
    </div>
    <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:5px;
                padding:9px 14px;font-size:11px;color:#374151;line-height:1.7;margin-bottom:14px">
        I, the undersigned, confirm that the above vehicle assessment has been carried out accurately
        and all findings are truthfully recorded as at
        <strong><?= fmtDate($assessment['assessment_date']) ?></strong>.
        This document serves as an official record of the vehicle's condition at the time of inspection.
    </div>
    <div class="asm-sig-grid">
        <div>
            <div class="asm-sig-line"></div>
            <div class="asm-sig-label">Inspector / Assessor</div>
            <div class="asm-sig-name"><?= e($assessment['mechanic_name'] ?? '________________________') ?></div>
            <div class="asm-sig-name">Date: ________________</div>
        </div>
        <div>
            <div class="asm-sig-line"></div>
            <div class="asm-sig-label">Workshop Supervisor</div>
            <div class="asm-sig-name">________________________</div>
            <div class="asm-sig-name">Date: ________________</div>
        </div>
        <div>
            <div class="asm-sig-line"></div>
            <div class="asm-sig-label">Authorised By</div>
            <div class="asm-sig-name">________________________</div>
            <div class="asm-sig-name">Date: ________________</div>
        </div>
    </div>

    <!-- ── Document Footer ─────────────────────────────────────────────────── -->
    <div class="asm-footer">
        <strong><?= e($companyName) ?></strong>
        <?php if ($companyAddress): ?> &bull; <?= e($companyAddress) ?><?php endif; ?>
        <?php if ($companyPhone): ?> &bull; <?= e($companyPhone) ?><?php endif; ?>
        <?php if ($companyEmail): ?> &bull; <?= e($companyEmail) ?><?php endif; ?>
        <?php if ($companyPin): ?><br>KRA PIN: <?= e($companyPin) ?><?php endif; ?>
        <br><span style="font-size:9.5px">Report <?= e($reportNo) ?> &bull; Generated <?= date('d M Y H:i') ?> &bull; Confidential — For Internal Use Only</span>
    </div>

</div>

<div class="d-print-none mt-4 mb-4"></div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>

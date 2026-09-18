<?php
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
canAccess('jobs') || redirect(BASE_URL . '/index.php');

$id = (int)($_GET['id'] ?? 0);
if (!$id) redirect(BASE_URL . '/modules/jobs/index.php');

$db = getDB();

// ── Job + vehicle + mechanic ──────────────────────────────────────────────────
try {
    $stmt = $db->prepare("
        SELECT j.*,
               c.make, c.model, c.year, c.color,
               c.chassis_number, c.registration_number, c.engine_number,
               m.name           AS mechanic_name,
               m.phone          AS mechanic_phone,
               m.specialization AS mechanic_spec
        FROM workshop_jobs j
        JOIN cars c ON c.id = j.car_id
        LEFT JOIN mechanics m ON m.id = j.mechanic_id
        WHERE j.id = ?
    ");
    $stmt->execute([$id]);
    $job = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (\Throwable $e) {
    // Fallback if specialization or engine_number column doesn't exist in this install
    $stmt = $db->prepare("
        SELECT j.*,
               c.make, c.model, c.year, c.color,
               c.chassis_number,
               m.name  AS mechanic_name,
               m.phone AS mechanic_phone,
               NULL    AS mechanic_spec,
               NULL    AS registration_number,
               NULL    AS engine_number
        FROM workshop_jobs j
        JOIN cars c ON c.id = j.car_id
        LEFT JOIN mechanics m ON m.id = j.mechanic_id
        WHERE j.id = ?
    ");
    $stmt->execute([$id]);
    $job = $stmt->fetch(PDO::FETCH_ASSOC);
}

if (!$job) {
    setFlash('error', 'Job card not found.');
    redirect(BASE_URL . '/modules/jobs/index.php');
}

// ── Assessment data (auto-populated when created from an assessment) ──────────
$assessment  = null;
$assessItems = [];
$byCategory  = [];
$issueItems  = [];

if (!empty($job['assessment_id'])) {
    $ast = $db->prepare("
        SELECT ca.*,
               m.name AS assessed_by
        FROM car_assessments ca
        LEFT JOIN mechanics m ON m.id = ca.mechanic_id
        WHERE ca.id = ?
    ");
    $ast->execute([(int)$job['assessment_id']]);
    $assessment = $ast->fetch(PDO::FETCH_ASSOC);


    if ($assessment) {
        $ait = $db->prepare("
            SELECT * FROM assessment_items
            WHERE assessment_id = ?
            ORDER BY part_category, part_name
        ");
        $ait->execute([(int)$job['assessment_id']]);
        $assessItems = $ait->fetchAll(PDO::FETCH_ASSOC);

        foreach ($assessItems as $item) {
            $byCategory[$item['part_category']][] = $item;
            if ($item['condition'] !== 'good') {
                $issueItems[] = $item;
            }
        }
    }
}

// ── Company settings ──────────────────────────────────────────────────────────
$companyName    = getSetting('company_name', 'Mascardi Ventures Limited');
$companyAddress = getSetting('company_address', '291 Kabete Lane, Spring Valley, Nairobi');
$companyPhone   = getSetting('company_phone', '');
$companyEmail   = getSetting('company_email', 'Sales@mascardi.co');
$logo           = companyLogo();

$condMeta = [
    'good'          => ['label' => 'Good',          'icon' => '✓'],
    'minor_damage'  => ['label' => 'Minor Damage',  'icon' => '⚠'],
    'major_damage'  => ['label' => 'Major Damage',  'icon' => '✗'],
    'missing'       => ['label' => 'Missing',        'icon' => '—'],
    'needs_service' => ['label' => 'Needs Service',  'icon' => '🔧'],
];

$fuelLabels = [
    'empty'         => 'Empty',
    'quarter'       => '¼ Tank',
    'half'          => 'Half Tank',
    'three_quarter' => '¾ Tank',
    'full'          => 'Full',
];

$pageTitle = 'Job Card — ' . $job['job_number'];
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
    #jcDoc {
        box-shadow: none !important; border: none !important;
        border-radius: 0 !important; max-width: 100% !important;
        padding: 1.2cm 1.6cm !important; margin: 0 !important;
    }
    .jc-section { page-break-inside: avoid; }
    .jc-issue-row { page-break-inside: avoid; }
}

/* ── Document wrapper ────────────────────────────────────────────────────── */
#jcDoc {
    max-width: 780px;
    margin: 0 auto 40px;
    background: #fff;
    border: 1px solid #d1d5db;
    border-radius: 8px;
    font-family: Arial, Helvetica, sans-serif;
    font-size: 12px;
    color: #111;
    line-height: 1.55;
    box-shadow: 0 4px 24px rgba(0,0,0,.1);
    padding: 30px 36px;
}

/* ── Header band ─────────────────────────────────────────────────────────── */
.jc-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    border-bottom: 3px solid #111;
    padding-bottom: 14px;
    margin-bottom: 18px;
}
.jc-company-name {
    font-family: 'Times New Roman', Times, Georgia, serif;
    font-style: italic;
    font-size: 26px;
    font-weight: normal;
    line-height: 1.1;
    color: #000;
}
.jc-company-sub {
    font-size: 10.5px;
    color: #444;
    margin-top: 5px;
    line-height: 1.65;
}
.jc-doc-title {
    text-align: right;
}
.jc-doc-title-text {
    font-size: 20px;
    font-weight: 900;
    letter-spacing: 2px;
    text-transform: uppercase;
    color: #111;
}
.jc-doc-meta {
    font-size: 11.5px;
    color: #444;
    margin-top: 6px;
    line-height: 1.9;
}

/* ── Status badge (print-safe: no Bootstrap colours needed) ──────────────── */
.jc-badge {
    display: inline-block;
    padding: 2px 8px;
    border-radius: 4px;
    font-size: 10.5px;
    font-weight: 700;
    border: 1px solid #999;
    color: #333;
    background: #f3f4f6;
}
.jc-badge.urgent   { border-color: #dc2626; color: #dc2626; background: #fef2f2; }
.jc-badge.high     { border-color: #ea580c; color: #ea580c; background: #fff7ed; }
.jc-badge.normal   { border-color: #2563eb; color: #2563eb; background: #eff6ff; }
.jc-badge.low      { border-color: #6b7280; color: #6b7280; background: #f9fafb; }
.jc-badge.progress { border-color: #7c3aed; color: #7c3aed; background: #f5f3ff; }
.jc-badge.pending  { border-color: #d97706; color: #d97706; background: #fffbeb; }
.jc-badge.done     { border-color: #16a34a; color: #16a34a; background: #f0fdf4; }

/* ── Tables ──────────────────────────────────────────────────────────────── */
.jc-table {
    width: 100%;
    border-collapse: collapse;
    margin: 0 0 14px;
}
.jc-table td, .jc-table th {
    border: 1px solid #d1d5db;
    padding: 6px 10px;
    vertical-align: top;
    font-size: 12px;
}
.jc-table th {
    background: #f3f4f6;
    font-weight: 700;
    white-space: nowrap;
    width: 32%;
}
.jc-table td strong { font-weight: 700; }

/* ── Section headings ────────────────────────────────────────────────────── */
.jc-section-head {
    font-size: 11.5px;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: .05em;
    color: #1e3a5f;
    border-bottom: 1.5px solid #1e3a5f;
    padding-bottom: 3px;
    margin: 18px 0 10px;
}

/* ── Issues checklist ────────────────────────────────────────────────────── */
.jc-issues-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 11.5px;
}
.jc-issues-table th {
    background: #1e3a5f;
    color: #fff;
    padding: 6px 9px;
    text-align: left;
    font-size: 11px;
    font-weight: 700;
}
.jc-issues-table td {
    border: 1px solid #d1d5db;
    padding: 5px 9px;
    vertical-align: top;
}
.jc-issues-table tr:nth-child(even) td { background: #f9fafb; }

/* ── Full checklist ──────────────────────────────────────────────────────── */
.jc-checklist-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 0 20px;
}
.jc-cat-title {
    font-size: 10.5px;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: .06em;
    color: #374151;
    background: #f3f4f6;
    border: 1px solid #d1d5db;
    padding: 4px 8px;
    margin-top: 10px;
    grid-column: 1 / -1;
}
.jc-part-row {
    display: flex;
    align-items: flex-start;
    gap: 6px;
    padding: 3px 0;
    border-bottom: 1px solid #f0f0f0;
    font-size: 11.5px;
}
.jc-part-name { flex: 1; color: #374151; }
.jc-cond-good    { color: #16a34a; font-weight: 700; font-size: 11px; }
.jc-cond-issue   { color: #dc2626; font-weight: 700; font-size: 11px; }
.jc-cond-warning { color: #d97706; font-weight: 700; font-size: 11px; }
.jc-cond-service { color: #2563eb; font-weight: 700; font-size: 11px; }

/* ── Work description box ────────────────────────────────────────────────── */
.jc-desc-box {
    min-height: 60px;
    border: 1px solid #d1d5db;
    border-radius: 4px;
    padding: 8px 12px;
    background: #fafafa;
    font-size: 12px;
    color: #111;
    line-height: 1.6;
    white-space: pre-wrap;
}

/* ── Signature area ──────────────────────────────────────────────────────── */
.jc-sig-grid {
    display: grid;
    grid-template-columns: 1fr 1fr 1fr;
    gap: 24px;
    margin-top: 28px;
}
.jc-sig-block { }
.jc-sig-line {
    border-bottom: 1.5px solid #374151;
    min-height: 40px;
    margin-bottom: 4px;
}
.jc-sig-label { font-size: 11px; font-weight: 700; }
.jc-sig-name  { font-size: 10.5px; color: #6b7280; margin-top: 1px; }

/* ── Footer ──────────────────────────────────────────────────────────────── */
.jc-footer {
    text-align: center;
    margin-top: 22px;
    font-size: 10px;
    color: #9ca3af;
    border-top: 1px solid #e5e7eb;
    padding-top: 10px;
}
</style>

<!-- ── Action bar (screen only) ──────────────────────────────────────────────── -->
<div class="d-print-none mb-4 d-flex align-items-center justify-content-between flex-wrap gap-2">
    <div class="d-flex align-items-center gap-2">
        <a href="view.php?id=<?= $id ?>" class="btn btn-outline-secondary btn-sm">
            <i class="fa fa-arrow-left me-1"></i>Back to Job Card
        </a>
        <span class="text-muted" style="font-size:12.5px">
            / <?= e($job['job_number']) ?> — <?= e($job['make'] . ' ' . $job['model'] . ' ' . $job['year']) ?>
        </span>
    </div>
    <button class="btn btn-success btn-sm" onclick="window.print()">
        <i class="fa fa-print me-1"></i>Print / Save PDF
    </button>
</div>

<!-- ── Printable Document ────────────────────────────────────────────────────── -->
<div id="jcDoc">

    <!-- ── Company Header ──────────────────────────────────────────────────── -->
    <div class="jc-header">
        <div>
            <?php if ($logo['exists']): ?>
            <img src="<?= e($logo['url']) ?>" alt="<?= e($companyName) ?>"
                 style="max-height:60px;max-width:180px;object-fit:contain;display:block;margin-bottom:6px">
            <?php else: ?>
            <div class="jc-company-name"><?= e($companyName) ?></div>
            <?php endif; ?>
            <div class="jc-company-sub">
                <?= e($companyAddress) ?><br>
                <?php if ($companyPhone): ?><?= e($companyPhone) ?><br><?php endif; ?>
                <?= e($companyEmail) ?>
            </div>
        </div>
        <div class="jc-doc-title">
            <div class="jc-doc-title-text">JOB CARD</div>
            <div style="font-size:10.5px;color:#666;font-style:italic;margin-top:2px">Workshop Work Order</div>
            <div class="jc-doc-meta">
                Job No: <strong><?= e($job['job_number']) ?></strong><br>
                Date: <strong><?= fmtDate($job['start_date'] ?: date('Y-m-d')) ?></strong><br>
                Status:
                <?php
                $stMap = [
                    'pending'       => ['text' => 'Pending',        'cls' => 'pending'],
                    'in_progress'   => ['text' => 'In Progress',    'cls' => 'progress'],
                    'waiting_parts' => ['text' => 'Waiting Parts',  'cls' => 'pending'],
                    'on_hold'       => ['text' => 'On Hold',        'cls' => 'low'],
                    'completed'     => ['text' => 'Completed',      'cls' => 'done'],
                    'cancelled'     => ['text' => 'Cancelled',      'cls' => 'low'],
                ];
                $st = $stMap[$job['status']] ?? ['text' => ucfirst($job['status']), 'cls' => 'normal'];
                $pr = ['urgent' => 'urgent', 'high' => 'high', 'normal' => 'normal', 'low' => 'low'][$job['priority']] ?? 'normal';
                ?>
                <span class="jc-badge <?= $st['cls'] ?>"><?= $st['text'] ?></span>
                &nbsp;
                Priority: <span class="jc-badge <?= $pr ?>"><?= ucfirst($job['priority']) ?></span>
            </div>
        </div>
    </div>

    <!-- ── Vehicle Details ─────────────────────────────────────────────────── -->
    <div class="jc-section-head">Vehicle Information</div>
    <table class="jc-table">
        <tr>
            <th>Make / Model</th>
            <td><strong><?= e($job['make'] . ' ' . $job['model']) ?></strong>
                <?php if ($job['year']): ?>(<?= e($job['year']) ?>)<?php endif; ?>
            </td>
            <th>Colour</th>
            <td><?= e(ucfirst($job['color'] ?? '—')) ?></td>
        </tr>
        <tr>
            <th>Chassis / VIN No.</th>
            <td><?= $job['chassis_number'] ? '<strong>' . e($job['chassis_number']) . '</strong>' : '—' ?></td>
            <?php if ($job['engine_number']): ?>
            <th>Engine No.</th>
            <td><?= e($job['engine_number']) ?></td>
            <?php else: ?>
            <th>Registration No.</th>
            <td><?= e($job['registration_number'] ?? '—') ?></td>
            <?php endif; ?>
        </tr>
        <?php if ($assessment): ?>
        <tr>
            <th>Mileage (at assessment)</th>
            <td><?= $assessment['mileage'] ? number_format((int)$assessment['mileage']) . ' km' : '—' ?></td>
            <th>Fuel Level</th>
            <td><?= e($fuelLabels[$assessment['fuel_level']] ?? ucfirst($assessment['fuel_level'] ?? '—')) ?></td>
        </tr>
        <?php endif; ?>
        <?php if ($job['registration_number'] && $job['engine_number']): ?>
        <tr>
            <th>Registration No.</th>
            <td colspan="3"><?= e($job['registration_number']) ?></td>
        </tr>
        <?php endif; ?>
    </table>

    <!-- ── Assigned Mechanic ───────────────────────────────────────────────── -->
    <div class="jc-section-head">Mechanic Assignment</div>
    <table class="jc-table">
        <tr>
            <th>Assigned To</th>
            <td><strong><?= e($job['mechanic_name'] ?? '—') ?></strong></td>
            <th>Phone</th>
            <td><?= e($job['mechanic_phone'] ?? '—') ?></td>
        </tr>
        <?php if (!empty($job['mechanic_spec'])): ?>
        <tr>
            <th>Specialization</th>
            <td colspan="3"><?= e($job['mechanic_spec']) ?></td>
        </tr>
        <?php endif; ?>
        <tr>
            <th>Start Date</th>
            <td><?= fmtDate($job['start_date']) ?></td>
            <th>Expected Completion</th>
            <td><?= $job['end_date'] ? fmtDate($job['end_date']) : '<em style="color:#9ca3af">Not set</em>' ?></td>
        </tr>
        <?php if ($assessment): ?>
        <tr>
            <th>Assessment By</th>
            <td><?= e($assessment['assessed_by'] ?? '—') ?></td>
            <th>Assessment Date</th>
            <td><?= fmtDate($assessment['assessment_date'] ?? '') ?></td>
        </tr>
        <?php endif; ?>
    </table>

    <!-- ── Work Description ────────────────────────────────────────────────── -->
    <div class="jc-section-head">Work Description / Instructions</div>
    <?php if ($job['description']): ?>
    <div class="jc-desc-box"><?= e($job['description']) ?></div>
    <?php elseif ($issueItems): ?>
    <div class="jc-desc-box" style="color:#6b7280;font-style:italic">
        Attend to all defects identified in the assessment checklist below.
    </div>
    <?php else: ?>
    <div class="jc-desc-box" style="min-height:80px;color:#d1d5db;font-style:italic">
        (Work description not specified)
    </div>
    <?php endif; ?>

    <?php if ($job['notes']): ?>
    <div style="margin-top:8px;padding:6px 12px;background:#fffbeb;border:1px solid #fde68a;border-radius:4px;font-size:11.5px;color:#92400e">
        <strong>Notes:</strong> <?= e($job['notes']) ?>
    </div>
    <?php endif; ?>

    <!-- ── Issues from Assessment ──────────────────────────────────────────── -->
    <?php if ($issueItems): ?>
    <div class="jc-section-head" style="color:#991b1b;border-color:#991b1b">
        Defects / Issues Found (<?= count($issueItems) ?> item<?= count($issueItems) > 1 ? 's' : '' ?> requiring attention)
    </div>
    <table class="jc-issues-table">
        <thead>
            <tr>
                <th style="width:18%">Category</th>
                <th style="width:26%">Part / Component</th>
                <th style="width:16%">Condition</th>
                <th>Assessment Notes</th>
                <th style="width:12%;text-align:center">Mechanic Sign-off</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($issueItems as $item):
                $meta = $condMeta[$item['condition']] ?? $condMeta['needs_service'];
                $condCls = match($item['condition']) {
                    'major_damage' => 'jc-cond-issue',
                    'minor_damage' => 'jc-cond-warning',
                    'missing'      => 'jc-cond-issue',
                    default        => 'jc-cond-service',
                };
            ?>
            <tr class="jc-issue-row">
                <td style="color:#6b7280;font-size:11px"><?= e($item['part_category']) ?></td>
                <td><strong><?= e($item['part_name']) ?></strong></td>
                <td class="<?= $condCls ?>"><?= $meta['icon'] ?> <?= $meta['label'] ?></td>
                <td style="font-size:11px;color:#374151"><?= $item['notes'] ? e($item['notes']) : '<em style="color:#d1d5db">—</em>' ?></td>
                <td style="text-align:center">
                    <div style="border-bottom:1px solid #9ca3af;min-height:22px"></div>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>

    <!-- ── Full Condition Checklist ────────────────────────────────────────── -->
    <?php if ($byCategory): ?>
    <div class="jc-section-head">Full Condition Checklist (from Assessment)</div>
    <div class="jc-checklist-grid">
        <?php foreach ($byCategory as $cat => $items): ?>
        <div class="jc-cat-title"><?= e($cat) ?>
            <span style="font-weight:400;color:#6b7280;font-size:10px;margin-left:6px">
                (<?= count($items) ?> items —
                <?= count(array_filter($items, fn($i) => $i['condition'] !== 'good')) ?> issue<?= count(array_filter($items, fn($i) => $i['condition'] !== 'good')) !== 1 ? 's' : '' ?>)
            </span>
        </div>
        <?php foreach ($items as $item):
            $meta = $condMeta[$item['condition']] ?? $condMeta['good'];
            $isIssue = $item['condition'] !== 'good';
            $condCls = match($item['condition']) {
                'good'          => 'jc-cond-good',
                'major_damage'  => 'jc-cond-issue',
                'missing'       => 'jc-cond-issue',
                'minor_damage'  => 'jc-cond-warning',
                default         => 'jc-cond-service',
            };
        ?>
        <div class="jc-part-row" style="<?= $isIssue ? 'background:#fff8f8;' : '' ?>">
            <span class="jc-part-name"><?= e($item['part_name']) ?></span>
            <span class="<?= $condCls ?>"><?= $meta['icon'] ?> <?= $meta['label'] ?></span>
        </div>
        <?php endforeach; ?>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- ── Authorisation & Signatures ─────────────────────────────────────── -->
    <div class="jc-section-head">Authorisation &amp; Sign-off</div>
    <div class="jc-sig-grid">
        <div class="jc-sig-block">
            <div class="jc-sig-line"></div>
            <div class="jc-sig-label">Mechanic / Technician</div>
            <div class="jc-sig-name"><?= e($job['mechanic_name'] ?? '_____________________') ?></div>
            <div class="jc-sig-name">Date: ________________</div>
        </div>
        <div class="jc-sig-block">
            <div class="jc-sig-line"></div>
            <div class="jc-sig-label">Workshop Manager</div>
            <div class="jc-sig-name">_____________________</div>
            <div class="jc-sig-name">Date: ________________</div>
        </div>
        <div class="jc-sig-block">
            <div class="jc-sig-line"></div>
            <div class="jc-sig-label">Authorised By</div>
            <div class="jc-sig-name">_____________________</div>
            <div class="jc-sig-name">Date: ________________</div>
        </div>
    </div>

    <!-- ── Footer ──────────────────────────────────────────────────────────── -->
    <div class="jc-footer">
        <?= e($companyName) ?> &bull; <?= e($companyAddress) ?>
        <?php if ($companyPhone): ?> &bull; <?= e($companyPhone) ?><?php endif; ?>
        &bull; <?= e($companyEmail) ?>
    </div>

</div>

<div class="d-print-none mt-4 mb-4"></div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>

<?php
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
canAccess('key_handovers') || die('Access denied.');
$db = getDB();

$id = (int)($_GET['id'] ?? 0);
if (!$id) die('Invalid request.');

$h = $db->prepare("
    SELECT kh.*,
           fl.name AS from_name, fl.address AS from_address, fl.phone AS from_phone,
           tl.name AS to_name,   tl.address AS to_address,   tl.phone AS to_phone,
           d.name AS driver_name_rel, d.phone AS driver_phone, d.license_number, d.id_number
    FROM key_handovers kh
    JOIN locations fl ON fl.id = kh.from_location_id
    JOIN locations tl ON tl.id = kh.to_location_id
    LEFT JOIN drivers d ON d.id = kh.driver_id
    WHERE kh.id = ?
");
$h->execute([$id]);
$h = $h->fetch();
if (!$h) die('Handover not found.');

$items = $db->prepare("
    SELECT khi.*, ck.key_label, c.make, c.model, c.registration_number, c.year
    FROM key_handover_items khi
    JOIN car_keys ck ON ck.id = khi.car_key_id
    JOIN cars c ON c.id = khi.car_id
    WHERE khi.handover_id = ?
    ORDER BY khi.id
");
$items->execute([$id]);
$items = $items->fetchAll();

$company = getSetting('company_name', 'Mascardi Car Yard');
$phone   = getSetting('company_phone', '');

$runLabels = ['morning_run' => 'Morning Key Run', 'evening_run' => 'Evening Key Run', 'ad_hoc' => 'Ad-hoc Key Run'];
$runLabel  = $runLabels[$h['run_type']] ?? 'Key Run';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Key Handover Sheet — <?= e($h['handover_number']) ?></title>
<style>
* { box-sizing:border-box; margin:0; padding:0; }
body { font-family:'Segoe UI',Arial,sans-serif; font-size:13px; color:#1a1a1a; background:#fff; padding:20px; }
.sheet { max-width:700px; margin:0 auto; border:2px solid #1d4ed8; border-radius:8px; overflow:hidden; }
.sheet-header { background:#1d4ed8; color:#fff; padding:18px 24px; display:flex; justify-content:space-between; align-items:center; }
.sheet-header h1 { font-size:18px; font-weight:700; }
.sheet-header .ref { font-size:24px; font-weight:800; }
.sheet-body { padding:20px 24px; }
.section-title { font-size:11px; text-transform:uppercase; letter-spacing:.8px; color:#2563eb; font-weight:700; border-bottom:1px solid #bfdbfe; padding-bottom:4px; margin-bottom:10px; margin-top:18px; }
.grid-2 { display:grid; grid-template-columns:1fr 1fr; gap:12px; }
.field label { font-size:10px; text-transform:uppercase; letter-spacing:.5px; color:#64748b; display:block; margin-bottom:2px; }
.field .val { font-weight:600; }
.route-box { display:flex; align-items:center; gap:12px; background:#f0f9ff; border-radius:6px; padding:12px 16px; margin:10px 0; }
.route-loc { flex:1; }
.route-loc .name { font-weight:700; font-size:14px; }
.route-loc .addr { font-size:11px; color:#64748b; }
table { width:100%; border-collapse:collapse; margin-top:8px; }
th { background:#f1f5f9; font-size:11px; text-transform:uppercase; letter-spacing:.4px; padding:6px 8px; border:1px solid #e2e8f0; text-align:left; }
td { padding:7px 8px; border:1px solid #e2e8f0; font-size:12px; vertical-align:top; }
.sig-row { display:grid; grid-template-columns:1fr 1fr; gap:24px; margin-top:28px; }
.sig-box { border-top:2px solid #1e293b; padding-top:6px; }
.sig-box .label { font-size:10px; text-transform:uppercase; letter-spacing:.5px; color:#64748b; }
.sig-box .name { font-weight:600; font-size:12px; margin-top:2px; }
.footer-note { background:#f8fafc; border-top:1px solid #e2e8f0; padding:10px 24px; font-size:11px; color:#64748b; }
@media print { body { padding:0; } @page { margin:10mm; } }
</style>
</head>
<body onload="window.print()">

<div class="sheet">
    <div class="sheet-header">
        <div>
            <div style="font-size:13px;opacity:.85"><?= e($company) ?></div>
            <h1><?= $runLabel ?> — Handover Sheet</h1>
            <?php if ($phone): ?><div style="font-size:11px;opacity:.75;margin-top:2px"><?= e($phone) ?></div><?php endif; ?>
        </div>
        <div style="text-align:right">
            <div class="ref"><?= e($h['handover_number']) ?></div>
            <div style="font-size:12px;opacity:.85;margin-top:4px"><?= fmtDate($h['handover_date'], 'd M Y') ?></div>
        </div>
    </div>

    <div class="sheet-body">

        <!-- Route -->
        <div class="section-title">Route</div>
        <div class="route-box">
            <div class="route-loc">
                <div style="font-size:10px;text-transform:uppercase;letter-spacing:.5px;color:#dc2626;margin-bottom:2px">From</div>
                <div class="name"><?= e($h['from_name']) ?></div>
                <?php if ($h['from_address']): ?><div class="addr"><?= e($h['from_address']) ?></div><?php endif; ?>
            </div>
            <div style="font-size:24px;color:#1d4ed8">→</div>
            <div class="route-loc">
                <div style="font-size:10px;text-transform:uppercase;letter-spacing:.5px;color:#16a34a;margin-bottom:2px">To</div>
                <div class="name"><?= e($h['to_name']) ?></div>
                <?php if ($h['to_address']): ?><div class="addr"><?= e($h['to_address']) ?></div><?php endif; ?>
            </div>
        </div>

        <!-- Driver -->
        <div class="section-title">Driver</div>
        <div class="grid-2">
            <div class="field"><label>Driver Name</label><div class="val"><?= e($h['driver_name_rel'] ?? $h['driver_name'] ?? '—') ?></div></div>
            <div class="field"><label>Phone</label><div class="val"><?= e($h['driver_phone'] ?: '—') ?></div></div>
            <div class="field"><label>License No.</label><div class="val"><?= e($h['license_number'] ?: '—') ?></div></div>
            <div class="field"><label>ID Number</label><div class="val"><?= e($h['id_number'] ?: '—') ?></div></div>
        </div>

        <!-- Keys -->
        <div class="section-title">Keys on this Run (<?= count($items) ?>)</div>
        <table>
            <thead>
                <tr>
                    <th style="width:3%">#</th>
                    <th style="width:22%">Key Label</th>
                    <th>Vehicle</th>
                    <th style="width:15%">Reg. No.</th>
                    <th style="width:18%">Checked Out ✓</th>
                    <th style="width:18%">Checked In ✓</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($items as $idx => $it): ?>
                <tr>
                    <td><?= $idx + 1 ?></td>
                    <td style="font-family:monospace;font-weight:700"><?= e($it['key_label']) ?></td>
                    <td><?= e($it['make'] . ' ' . $it['model']) ?> (<?= $it['year'] ?>)</td>
                    <td><?= e($it['registration_number'] ?: '—') ?></td>
                    <td style="color:#16a34a"><?= $it['checked_out_at'] ? '✓ ' . fmtDate($it['checked_out_at'], 'H:i') : '' ?></td>
                    <td style="color:#16a34a"><?= $it['checked_in_at']  ? '✓ ' . fmtDate($it['checked_in_at'], 'H:i')  : '' ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <?php if ($h['notes']): ?>
        <div class="field" style="margin-top:12px">
            <label>Notes</label>
            <div style="border:1px solid #e2e8f0;border-radius:4px;padding:8px;font-size:12px"><?= e($h['notes']) ?></div>
        </div>
        <?php endif; ?>

        <!-- Signatures -->
        <div class="section-title">Signatures</div>
        <div class="sig-row">
            <div class="sig-box">
                <div style="height:36px"></div>
                <div class="label">Issued by (Origin Staff)</div>
                <div class="name">Name: _______________________</div>
                <div class="name" style="margin-top:4px">Time: _______________________</div>
            </div>
            <div class="sig-box">
                <div style="height:36px"></div>
                <div class="label">Driver Signature</div>
                <div class="name">Name: <?= e($h['driver_name_rel'] ?? $h['driver_name'] ?? '') ?></div>
                <div class="name" style="margin-top:4px">Time: _______________________</div>
            </div>
            <div class="sig-box" style="margin-top:20px">
                <div style="height:36px"></div>
                <div class="label">Received by (Destination Staff)</div>
                <div class="name">Name: _______________________</div>
                <div class="name" style="margin-top:4px">Time: _______________________</div>
            </div>
            <div class="sig-box" style="margin-top:20px">
                <div style="height:36px"></div>
                <div class="label">Verified by (Manager)</div>
                <div class="name">Name: _______________________</div>
                <div class="name" style="margin-top:4px">Time: _______________________</div>
            </div>
        </div>

    </div>

    <div class="footer-note">
        Printed: <?= date('d M Y, H:i') ?> &nbsp;|&nbsp; <?= e($company) ?> &nbsp;|&nbsp; <?= e($h['handover_number']) ?>
    </div>
</div>

</body>
</html>

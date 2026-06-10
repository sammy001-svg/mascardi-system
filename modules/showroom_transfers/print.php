<?php
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
canAccess('showroom_transfers') || die('Access denied.');
$db = getDB();

$id = (int)($_GET['id'] ?? 0);
if (!$id) die('Invalid request.');

$t = $db->prepare("
    SELECT st.*,
           c.make, c.model, c.registration_number, c.chassis_number, c.year, c.color,
           fl.name AS from_name, fl.address AS from_address, fl.phone AS from_phone,
           tl.name AS to_name,   tl.address AS to_address,   tl.phone AS to_phone,
           d.name AS driver_name_rel, d.phone AS driver_phone, d.license_number
    FROM showroom_transfers st
    JOIN cars c          ON c.id  = st.car_id
    JOIN locations fl    ON fl.id = st.from_location_id
    JOIN locations tl    ON tl.id = st.to_location_id
    LEFT JOIN drivers d  ON d.id  = st.driver_id
    WHERE st.id = ?
");
$t->execute([$id]);
$t = $t->fetch();
if (!$t) die('Transfer not found.');

$company = getSetting('company_name', 'Mascardi Car Yard');
$phone   = getSetting('company_phone', '');
$email   = getSetting('company_email', '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Transfer Slip — <?= e($t['transfer_number']) ?></title>
<style>
* { box-sizing:border-box; margin:0; padding:0; }
body { font-family:'Segoe UI',Arial,sans-serif; font-size:13px; color:#1a1a1a; background:#fff; padding:20px; }
.slip { max-width:700px; margin:0 auto; border:2px solid #1d4ed8; border-radius:8px; overflow:hidden; }
.slip-header { background:#1d4ed8; color:#fff; padding:18px 24px; display:flex; justify-content:space-between; align-items:center; }
.slip-header h1 { font-size:18px; font-weight:700; }
.slip-header .ref { font-size:24px; font-weight:800; letter-spacing:1px; }
.slip-body { padding:20px 24px; }
.section-title { font-size:11px; text-transform:uppercase; letter-spacing:.8px; color:#2563eb; font-weight:700; border-bottom:1px solid #bfdbfe; padding-bottom:4px; margin-bottom:10px; margin-top:18px; }
.grid-2 { display:grid; grid-template-columns:1fr 1fr; gap:12px; }
.grid-3 { display:grid; grid-template-columns:1fr 1fr 1fr; gap:12px; }
.field { margin-bottom:8px; }
.field label { font-size:10px; text-transform:uppercase; letter-spacing:.5px; color:#64748b; display:block; margin-bottom:2px; }
.field .val { font-weight:600; font-size:13px; }
.route-box { display:flex; align-items:center; gap:12px; background:#f0f9ff; border-radius:6px; padding:12px 16px; margin:10px 0; }
.route-loc { flex:1; }
.route-loc .name { font-weight:700; font-size:14px; }
.route-loc .addr { font-size:11px; color:#64748b; }
.arrow { font-size:24px; color:#1d4ed8; }
.sig-row { display:grid; grid-template-columns:1fr 1fr; gap:24px; margin-top:28px; }
.sig-box { border-top:2px solid #1e293b; padding-top:6px; }
.sig-box .label { font-size:10px; text-transform:uppercase; letter-spacing:.5px; color:#64748b; }
.sig-box .name { font-weight:600; font-size:12px; margin-top:2px; }
.type-badge { display:inline-block; background:#dbeafe; color:#1d4ed8; border-radius:4px; padding:2px 10px; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.5px; }
.footer-note { background:#f8fafc; border-top:1px solid #e2e8f0; padding:10px 24px; font-size:11px; color:#64748b; }
@media print { body { padding:0; } @page { margin:10mm; } }
</style>
</head>
<body onload="window.print()">

<div class="slip">
    <div class="slip-header">
        <div>
            <div style="font-size:13px;opacity:.85"><?= e($company) ?></div>
            <h1>Vehicle Transfer Slip</h1>
            <?php if ($phone || $email): ?>
            <div style="font-size:11px;opacity:.75;margin-top:2px"><?= e($phone) ?><?= $email ? ' · '.e($email) : '' ?></div>
            <?php endif; ?>
        </div>
        <div style="text-align:right">
            <div class="ref"><?= e($t['transfer_number']) ?></div>
            <div style="font-size:12px;opacity:.85;margin-top:4px"><?= fmtDate($t['requested_date'], 'd M Y') ?></div>
            <div class="type-badge" style="margin-top:6px;background:rgba(255,255,255,.2);color:#fff">
                <?= ucwords(str_replace('_', ' ', $t['transfer_type'])) ?>
            </div>
        </div>
    </div>

    <div class="slip-body">

        <!-- Vehicle -->
        <div class="section-title">Vehicle Details</div>
        <div class="grid-3">
            <div class="field"><label>Make / Model</label><div class="val"><?= e($t['make'] . ' ' . $t['model']) ?> (<?= $t['year'] ?>)</div></div>
            <div class="field"><label>Registration</label><div class="val"><?= e($t['registration_number'] ?: '—') ?></div></div>
            <div class="field"><label>Chassis No.</label><div class="val" style="font-family:monospace"><?= e($t['chassis_number'] ?: '—') ?></div></div>
        </div>

        <!-- Route -->
        <div class="section-title">Transfer Route</div>
        <div class="route-box">
            <div class="route-loc">
                <div style="font-size:10px;text-transform:uppercase;letter-spacing:.5px;color:#dc2626;margin-bottom:2px">From</div>
                <div class="name"><?= e($t['from_name']) ?></div>
                <?php if ($t['from_address']): ?><div class="addr"><?= e($t['from_address']) ?></div><?php endif; ?>
                <?php if ($t['from_phone']): ?><div class="addr"><?= e($t['from_phone']) ?></div><?php endif; ?>
            </div>
            <div class="arrow">→</div>
            <div class="route-loc">
                <div style="font-size:10px;text-transform:uppercase;letter-spacing:.5px;color:#16a34a;margin-bottom:2px">To</div>
                <div class="name"><?= e($t['to_name']) ?></div>
                <?php if ($t['to_address']): ?><div class="addr"><?= e($t['to_address']) ?></div><?php endif; ?>
                <?php if ($t['to_phone']): ?><div class="addr"><?= e($t['to_phone']) ?></div><?php endif; ?>
            </div>
        </div>

        <!-- Driver -->
        <div class="section-title">Driver / Transporter</div>
        <div class="grid-3">
            <div class="field"><label>Driver Name</label><div class="val"><?= e($t['driver_name_rel'] ?? $t['driver_name'] ?? '—') ?></div></div>
            <div class="field"><label>Phone</label><div class="val"><?= e($t['driver_phone'] ?: '—') ?></div></div>
            <div class="field"><label>License No.</label><div class="val"><?= e($t['license_number'] ?: '—') ?></div></div>
        </div>

        <!-- Trip Log -->
        <div class="section-title">Trip Log</div>
        <div class="grid-2">
            <div class="field">
                <label>Departure Date / Time</label>
                <div class="val"><?= $t['departure_at'] ? fmtDate($t['departure_at'], 'd M Y, H:i') : '___________________' ?></div>
            </div>
            <div class="field">
                <label>Departure Mileage (km)</label>
                <div class="val"><?= $t['departure_mileage'] ? number_format($t['departure_mileage']) : '___________________' ?></div>
            </div>
            <div class="field">
                <label>Arrival Date / Time</label>
                <div class="val"><?= $t['arrival_at'] ? fmtDate($t['arrival_at'], 'd M Y, H:i') : '___________________' ?></div>
            </div>
            <div class="field">
                <label>Arrival Mileage (km)</label>
                <div class="val"><?= $t['arrival_mileage'] ? number_format($t['arrival_mileage']) : '___________________' ?></div>
            </div>
        </div>
        <div class="field">
            <label>Condition Notes</label>
            <div style="border:1px solid #e2e8f0;border-radius:4px;padding:8px;min-height:40px;font-size:12px">
                <?= $t['departure_condition'] ? e($t['departure_condition']) : '' ?>
            </div>
        </div>

        <?php if ($t['notes']): ?>
        <div class="field" style="margin-top:10px">
            <label>Transfer Notes</label>
            <div style="border:1px solid #e2e8f0;border-radius:4px;padding:8px;font-size:12px"><?= e($t['notes']) ?></div>
        </div>
        <?php endif; ?>

        <!-- Signatures -->
        <div class="section-title">Authorisation &amp; Handover</div>
        <div class="sig-row">
            <div class="sig-box">
                <div style="height:36px"></div>
                <div class="label">Checked out by (Origin)</div>
                <div class="name">Name: _______________________</div>
                <div class="name" style="margin-top:4px">Date: _______________________</div>
            </div>
            <div class="sig-box">
                <div style="height:36px"></div>
                <div class="label">Driver Signature</div>
                <div class="name">Name: <?= e($t['driver_name_rel'] ?? $t['driver_name'] ?? '') ?></div>
                <div class="name" style="margin-top:4px">Date: _______________________</div>
            </div>
            <div class="sig-box" style="margin-top:20px">
                <div style="height:36px"></div>
                <div class="label">Received by (Destination)</div>
                <div class="name">Name: _______________________</div>
                <div class="name" style="margin-top:4px">Date: _______________________</div>
            </div>
            <div class="sig-box" style="margin-top:20px">
                <div style="height:36px"></div>
                <div class="label">Approved by</div>
                <div class="name">Name: <?= e($t['approved_by'] ?: '') ?></div>
                <div class="name" style="margin-top:4px">Date: _______________________</div>
            </div>
        </div>

    </div><!-- /slip-body -->

    <div class="footer-note">
        Printed: <?= date('d M Y, H:i') ?> &nbsp;|&nbsp; <?= e($company) ?> &nbsp;|&nbsp; <?= e($t['transfer_number']) ?>
    </div>
</div>

</body>
</html>

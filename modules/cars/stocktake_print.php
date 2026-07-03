<?php
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();

if (!canAccess('cars')) {
    http_response_code(403);
    die('Access denied');
}

$db = getDB();
$id = (int)($_GET['id'] ?? 0);
if (!$id) die('Invalid stock take ID.');

$st = $db->prepare("
    SELECT st.*, u.name AS conducted_by_name
    FROM   stock_takes st
    LEFT   JOIN users u ON u.id = st.conducted_by
    WHERE  st.id = ?
");
$st->execute([$id]);
$stockTake = $st->fetch(PDO::FETCH_ASSOC);
if (!$stockTake) die('Stock take record not found.');

// All cars at the location, with confirmed status
$items = $db->prepare("
    SELECT c.id, c.make, c.model, c.year, c.registration_number,
           c.chassis_number, c.color, c.status, c.car_type,
           CASE WHEN sti.id IS NOT NULL THEN 1 ELSE 0 END AS confirmed
    FROM   cars c
    LEFT   JOIN stock_take_items sti
               ON sti.car_id = c.id AND sti.stock_take_id = ?
    WHERE  c.location_id = ?
    ORDER  BY confirmed DESC, c.make ASC, c.model ASC
");
$items->execute([$id, $stockTake['location_id']]);
$carList = $items->fetchAll(PDO::FETCH_ASSOC);

$confirmed = array_values(array_filter($carList, fn($c) => $c['confirmed']));
$missing   = array_values(array_filter($carList, fn($c) => !$c['confirmed']));

$company = [
    'name'    => getSetting('company_name',    'Mascardi Car Yard'),
    'address' => getSetting('company_address', 'Nairobi, Kenya'),
    'phone'   => getSetting('company_phone',   ''),
    'email'   => getSetting('company_email',   ''),
    'pin'     => getSetting('company_pin',     ''),
];
$logo = getSetting('company_logo', '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Stock Take — <?= e($stockTake['location_name'] ?? '') ?> — <?= e($stockTake['take_date']) ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
<style>
    body { background: #f1f5f9; font-size: 13px; font-family: "Inter", "Segoe UI", sans-serif; }
    .print-wrap { max-width: 920px; margin: 28px auto; background: #fff; padding: 44px; box-shadow: 0 2px 12px rgba(0,0,0,.1); border-radius: 10px; }
    .co-header  { border-bottom: 3px solid #2563eb; padding-bottom: 18px; margin-bottom: 24px; }
    .doc-label  { font-size: 24px; font-weight: 800; color: #1e293b; letter-spacing: -.5px; }
    .doc-meta   { font-size: 12px; color: #64748b; line-height: 1.7; }
    .doc-meta strong { color: #1e293b; }
    .sum-box    { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 14px 12px; text-align: center; }
    .sum-num    { font-size: 30px; font-weight: 800; }
    .sum-lbl    { font-size: 11px; color: #64748b; font-weight: 500; text-transform: uppercase; letter-spacing: .04em; }
    .section-hdr { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .06em; color: #64748b; margin: 20px 0 8px; }
    table       { font-size: 12px; }
    thead th    { background: #f1f5f9; font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: .04em; color: #475569; }
    .tick-yes   { color: #16a34a; font-weight: 700; }
    .tick-no    { color: #dc2626; font-weight: 700; }
    .sign-line  { border-top: 1px solid #cbd5e1; padding-top: 6px; margin-top: 48px; font-size: 12px; color: #475569; text-align: center; }
    @media print {
        body { background: #fff; }
        .no-print { display: none !important; }
        .print-wrap { box-shadow: none; margin: 0; padding: 24px; border-radius: 0; }
        table { page-break-inside: auto; }
        tr    { page-break-inside: avoid; }
    }
</style>
</head>
<body>

<div class="no-print text-center py-3 d-flex justify-content-center gap-2">
    <button onclick="window.print()" class="btn btn-primary px-4">
        <i class="fa fa-print me-1"></i>Print / Save as PDF
    </button>
    <a href="<?= BASE_URL ?>/modules/cars/index.php" class="btn btn-outline-secondary">
        <i class="fa fa-arrow-left me-1"></i>Back to Cars
    </a>
</div>

<div class="print-wrap">

    <!-- ── Company header ──────────────────────────────────────────────── -->
    <div class="co-header d-flex justify-content-between align-items-start gap-3">
        <div>
            <?php if ($logo && file_exists(BASE_PATH . $logo)): ?>
            <img src="<?= BASE_URL . e($logo) ?>" alt="<?= e($company['name']) ?>"
                 style="height:56px;max-width:210px;object-fit:contain;display:block;margin-bottom:6px">
            <?php else: ?>
            <div style="font-size:22px;font-weight:800;color:#0f172a;margin-bottom:4px"><?= e($company['name']) ?></div>
            <?php endif; ?>
            <div class="text-muted" style="font-size:12px"><?= e($company['address']) ?></div>
            <?php if ($company['phone']): ?><div style="font-size:12px">Tel: <?= e($company['phone']) ?></div><?php endif; ?>
            <?php if ($company['email']): ?><div style="font-size:12px">Email: <?= e($company['email']) ?></div><?php endif; ?>
            <?php if ($company['pin']): ?><div style="font-size:12px">KRA PIN: <?= e($company['pin']) ?></div><?php endif; ?>
        </div>
        <div class="text-end">
            <div class="doc-label">STOCK TAKE REPORT</div>
            <div class="doc-meta mt-2">
                <div><strong>Location:</strong> <?= e($stockTake['location_name'] ?? '—') ?></div>
                <div><strong>Date:</strong> <?= e(date('d F Y', strtotime($stockTake['take_date']))) ?></div>
                <div><strong>Time:</strong> <?= e(date('h:i A', strtotime($stockTake['take_time']))) ?></div>
                <div><strong>Conducted by:</strong> <?= e($stockTake['conducted_by_name'] ?? '—') ?></div>
                <div><strong>Report #:</strong> ST-<?= str_pad($id, 5, '0', STR_PAD_LEFT) ?></div>
            </div>
        </div>
    </div>

    <!-- ── Summary cards ───────────────────────────────────────────────── -->
    <div class="row g-3 mb-4">
        <div class="col-4">
            <div class="sum-box">
                <div class="sum-num" style="color:#2563eb"><?= count($carList) ?></div>
                <div class="sum-lbl">In System</div>
            </div>
        </div>
        <div class="col-4">
            <div class="sum-box">
                <div class="sum-num" style="color:#16a34a"><?= count($confirmed) ?></div>
                <div class="sum-lbl">Confirmed Present</div>
            </div>
        </div>
        <div class="col-4">
            <div class="sum-box">
                <div class="sum-num" style="color:#dc2626"><?= count($missing) ?></div>
                <div class="sum-lbl">Not Confirmed</div>
            </div>
        </div>
    </div>

    <?php if ($stockTake['notes']): ?>
    <div class="alert alert-light border mb-4 py-2 px-3" style="font-size:12px">
        <strong>Notes:</strong> <?= nl2br(e($stockTake['notes'])) ?>
    </div>
    <?php endif; ?>

    <!-- ── Full checklist ──────────────────────────────────────────────── -->
    <div class="section-hdr">Vehicle Inventory Checklist — <?= e($stockTake['location_name'] ?? '') ?></div>
    <table class="table table-bordered table-sm mb-0">
        <thead>
            <tr>
                <th style="width:32px" class="text-center">#</th>
                <th>Vehicle</th>
                <th>Chassis No.</th>
                <th>Reg. No.</th>
                <th>Color</th>
                <th>Type</th>
                <th>System Status</th>
                <th class="text-center" style="width:80px">Present</th>
            </tr>
        </thead>
        <tbody>
            <?php $rowNum = 1; foreach ($carList as $car): ?>
            <tr>
                <td class="text-center text-muted"><?= $rowNum++ ?></td>
                <td>
                    <strong><?= e($car['make'] . ' ' . $car['model']) ?></strong>
                    <?php if ($car['year']): ?><span class="text-muted"><?= e($car['year']) ?></span><?php endif; ?>
                </td>
                <td><code style="font-size:11px"><?= e($car['chassis_number'] ?: '—') ?></code></td>
                <td><?= e($car['registration_number'] ?: '—') ?></td>
                <td><?= e($car['color'] ?: '—') ?></td>
                <td>
                    <?php if ($car['car_type'] === 'client'): ?>
                    <span class="badge bg-info text-dark" style="font-size:10px">CLIENT</span>
                    <?php else: ?>
                    <span class="badge bg-primary" style="font-size:10px">INVENTORY</span>
                    <?php endif; ?>
                </td>
                <td><?= statusBadge($car['status']) ?></td>
                <td class="text-center">
                    <?php if ($car['confirmed']): ?>
                    <span class="tick-yes"><i class="fa fa-check-circle"></i> Yes</span>
                    <?php else: ?>
                    <span class="tick-no"><i class="fa fa-times-circle"></i> No</span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php if (!$carList): ?>
            <tr>
                <td colspan="8" class="text-center text-muted py-4">No vehicles on record for this location</td>
            </tr>
            <?php endif; ?>
        </tbody>
    </table>

    <!-- ── Signature block ─────────────────────────────────────────────── -->
    <div class="row mt-5">
        <div class="col-4">
            <div class="sign-line">
                <?= e($stockTake['conducted_by_name'] ?? '') ?><br>
                <span style="font-size:11px;color:#94a3b8">Conducted By</span>
            </div>
        </div>
        <div class="col-4">
            <div class="sign-line">
                &nbsp;<br>
                <span style="font-size:11px;color:#94a3b8">Verified By</span>
            </div>
        </div>
        <div class="col-4">
            <div class="sign-line">
                &nbsp;<br>
                <span style="font-size:11px;color:#94a3b8">Authorized By</span>
            </div>
        </div>
    </div>

    <!-- ── Page footer ─────────────────────────────────────────────────── -->
    <div class="d-flex justify-content-between mt-4 pt-3 border-top" style="font-size:11px;color:#94a3b8">
        <span>Generated: <?= date('d M Y H:i') ?> &mdash; <?= e($company['name']) ?></span>
        <span>ST-<?= str_pad($id, 5, '0', STR_PAD_LEFT) ?></span>
    </div>

</div><!-- /print-wrap -->
</body>
</html>

<?php
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
canAccess('parts_requests') || die('Access denied.');

$id = (int)($_GET['id'] ?? 0);
if (!$id) redirect(BASE_URL . '/modules/parts_requests/index.php');

$db = getDB();

$stmt = $db->prepare("
    SELECT pr.*,
           qa.assessment_number, qa.assessment_date,
           u.name AS created_by_name
    FROM parts_requests pr
    LEFT JOIN quick_assessments qa ON qa.id = pr.quick_assessment_id
    LEFT JOIN users u ON u.id = pr.created_by
    WHERE pr.id = ?
");
$stmt->execute([$id]);
$req = $stmt->fetch();
if (!$req) die('Quote request not found.');

$items = $db->prepare("
    SELECT pri.*, i.part_number AS stock_part_no, i.part_name AS stock_part_name
    FROM parts_request_items pri
    LEFT JOIN inventory i ON i.id = pri.inventory_id
    WHERE pri.request_id = ?
    ORDER BY pri.id
");
$items->execute([$id]);
$items = $items->fetchAll();

$company = [
    'name'    => getSetting('company_name',    'Mascardi Car Yard'),
    'address' => getSetting('company_address', 'Nairobi, Kenya'),
    'phone'   => getSetting('company_phone',   ''),
    'email'   => getSetting('company_email',   ''),
    'pin'     => getSetting('company_pin',     ''),
];

$statusLabels = [
    'pending'  => ['#d97706', '#fffbeb', '#fde68a', 'Pending'],
    'approved' => ['#16a34a', '#f0fdf4', '#bbf7d0', 'Approved'],
    'rejected' => ['#dc2626', '#fef2f2', '#fecaca', 'Rejected'],
    'issued'   => ['#0284c7', '#f0f9ff', '#bae6fd', 'Issued'],
];
[$stColor, $stBg, $stBorder, $stLabel] = $statusLabels[$req['status']] ?? ['#64748b','#f8fafc','#e2e8f0', ucfirst($req['status'])];
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Quote Request <?= e($req['request_number']) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
    <style>
        * { box-sizing: border-box; }
        body {
            background: #f1f5f9;
            font-size: 13px;
            font-family: 'Inter', system-ui, -apple-system, sans-serif;
            color: #0f172a;
            margin: 0;
        }

        .print-wrapper {
            max-width: 820px;
            margin: 30px auto;
            background: #fff;
            padding: 48px 52px;
            border-radius: 10px;
            box-shadow: 0 4px 24px rgba(0,0,0,.10);
        }

        /* Toolbar */
        .no-print-bar {
            position: sticky; top: 0; z-index: 100;
            background: #1e293b;
            padding: 11px 24px;
            display: flex; align-items: center; gap: 12px;
        }

        /* Header */
        .company-name  { font-size: 22px; font-weight: 800; color: #0f172a; letter-spacing: -.4px; }
        .doc-title     { font-size: 24px; font-weight: 800; color: #2563eb; text-transform: uppercase; letter-spacing: -.3px; }
        .doc-number    { font-size: 17px; font-weight: 700; color: #0f172a; }

        /* Info boxes */
        .info-box      { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 14px 16px; }
        .info-label    { font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: .7px; color: #64748b; margin-bottom: 3px; }
        .info-value    { font-weight: 600; color: #0f172a; font-size: 14px; }
        .info-sub      { color: #475569; font-size: 12px; margin-top: 2px; }

        /* Section headings */
        .section-heading {
            font-size: 10px; font-weight: 700; text-transform: uppercase;
            letter-spacing: .8px; color: #64748b;
            border-bottom: 2px solid #e2e8f0;
            padding-bottom: 6px; margin-bottom: 0;
        }

        /* Parts table */
        .parts-table   { width: 100%; border-collapse: collapse; }
        .parts-table thead tr {
            background: #f1f5f9;
            border-bottom: 2px solid #e2e8f0;
        }
        .parts-table thead th {
            padding: 9px 12px;
            font-size: 10px; font-weight: 700;
            text-transform: uppercase; letter-spacing: .6px;
            color: #64748b;
        }
        .parts-table tbody tr { border-bottom: 1px solid #f1f5f9; }
        .parts-table tbody tr:last-child { border-bottom: none; }
        .parts-table tbody td { padding: 10px 12px; font-size: 13px; vertical-align: top; }
        .parts-table tbody tr:nth-child(even) td { background: #fafafa; }
        .part-name     { font-weight: 600; color: #0f172a; }
        .part-no       { font-size: 11px; color: #64748b; font-family: monospace; margin-top: 2px; }

        /* Status badge */
        .status-badge {
            display: inline-block;
            padding: 4px 12px; border-radius: 20px;
            font-size: 11px; font-weight: 700;
            text-transform: uppercase; letter-spacing: .5px;
        }

        /* Notes */
        .notes-box     { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 12px 14px; line-height: 1.6; white-space: pre-wrap; }

        /* Signatures */
        .sig-line      { border-top: 1px solid #cbd5e1; padding-top: 8px; text-align: center; }

        @media print {
            body  { background: #fff; }
            .print-wrapper { box-shadow: none; margin: 0; padding: 20px 24px; border-radius: 0; max-width: 100%; }
            .no-print-bar  { display: none !important; }
            @page { margin: 15mm 12mm; }
        }
    </style>
</head>
<body>

<!-- Toolbar -->
<div class="no-print-bar">
    <button onclick="window.print()" class="btn btn-primary btn-sm px-4">
        <i class="fa fa-print me-2"></i>Print / Save PDF
    </button>
    <a href="view.php?id=<?= $id ?>" class="btn btn-outline-light btn-sm">
        <i class="fa fa-arrow-left me-1"></i>Back
    </a>
    <span class="ms-auto text-white-50 small"><?= e($req['request_number']) ?></span>
</div>

<div class="print-wrapper">

    <!-- ── Document Header ──────────────────────────────────────────────────── -->
    <div class="row mb-4 align-items-start">
        <div class="col-7">
            <?php $__logo = getSetting('company_logo', ''); ?>
            <?php if ($__logo && file_exists(BASE_PATH . '/assets/images/' . $__logo)): ?>
            <img src="<?= BASE_URL ?>/assets/images/<?= e($__logo) ?>"
                 alt="<?= e($company['name']) ?>"
                 style="height:48px;max-width:170px;object-fit:contain;margin-bottom:6px;display:block">
            <?php else: ?>
            <div class="company-name mb-1"><?= e($company['name']) ?></div>
            <?php endif; ?>
            <div style="color:#64748b;font-size:12px;line-height:1.8">
                <?php if ($company['address']): ?><?= e($company['address']) ?><br><?php endif; ?>
                <?php if ($company['phone']): ?>Tel: <?= e($company['phone']) ?><?php if ($company['email']): ?> &nbsp;|&nbsp; <?php endif; ?><?php endif; ?>
                <?php if ($company['email']): ?>Email: <?= e($company['email']) ?><?php endif; ?>
                <?php if ($company['pin']): ?><br>KRA PIN: <?= e($company['pin']) ?><?php endif; ?>
            </div>
        </div>
        <div class="col-5 text-end">
            <div class="doc-title">Quote Request</div>
            <div class="doc-number mt-1"><?= e($req['request_number']) ?></div>
            <div style="color:#64748b;font-size:12px;margin-top:5px">
                Date: <strong><?= fmtDate($req['created_at'], 'd F Y') ?></strong>
            </div>
            <div class="mt-2">
                <span class="status-badge"
                      style="background:<?= $stBg ?>;border:1px solid <?= $stBorder ?>;color:<?= $stColor ?>">
                    <?= $stLabel ?>
                </span>
            </div>
        </div>
    </div>

    <hr style="border-color:#e2e8f0;margin-bottom:24px">

    <!-- ── Client & Vehicle ─────────────────────────────────────────────────── -->
    <div class="row g-3 mb-4">
        <div class="col-6">
            <div class="info-box h-100">
                <div class="info-label"><i class="fa fa-user me-1"></i>Requested By / Client</div>
                <div class="info-value"><?= e($req['client_name'] ?: 'Walk-in') ?></div>
                <?php if ($req['client_phone']): ?>
                <div class="info-sub"><i class="fa fa-phone me-1" style="font-size:10px"></i><?= e($req['client_phone']) ?></div>
                <?php endif; ?>
                <?php if ($req['client_email']): ?>
                <div class="info-sub"><i class="fa fa-envelope me-1" style="font-size:10px"></i><?= e($req['client_email']) ?></div>
                <?php endif; ?>
                <?php if ($req['created_by_name']): ?>
                <div class="info-sub mt-1" style="border-top:1px solid #f1f5f9;padding-top:6px;margin-top:6px">
                    <span style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;color:#94a3b8">Prepared by</span><br>
                    <?= e($req['created_by_name']) ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <div class="col-6">
            <div class="info-box h-100">
                <div class="info-label"><i class="fa fa-car me-1"></i>Vehicle</div>
                <?php $veh = trim(($req['car_make'] ?? '') . ' ' . ($req['car_model'] ?? '')); ?>
                <?php if ($veh): ?>
                <div class="info-value"><?= e($veh) ?></div>
                <?php endif; ?>
                <div style="margin-top:6px">
                    <?php if ($req['car_registration']): ?>
                    <span style="display:inline-block;background:#0f172a;color:#fff;padding:2px 10px;border-radius:4px;font-size:12px;font-weight:700;letter-spacing:.5px;margin-right:6px">
                        <?= e(strtoupper($req['car_registration'])) ?>
                    </span>
                    <?php endif; ?>
                    <?php if ($req['car_chassis']): ?>
                    <span class="info-sub">Chassis: <code style="font-size:11px"><?= e($req['car_chassis']) ?></code></span>
                    <?php endif; ?>
                </div>
                <?php if ($req['assessment_number']): ?>
                <div class="info-sub mt-2" style="border-top:1px solid #f1f5f9;padding-top:6px">
                    <span style="font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;color:#94a3b8">Linked Assessment</span><br>
                    <?= e($req['assessment_number']) ?>
                    <?php if ($req['assessment_date']): ?>— <?= fmtDate($req['assessment_date']) ?><?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- ── Parts Requested ──────────────────────────────────────────────────── -->
    <div class="mb-4">
        <div class="section-heading mb-0"><i class="fa fa-list-ul me-1"></i>Parts Requested</div>
        <table class="parts-table" style="border:1px solid #e2e8f0;border-top:none;border-radius:0 0 8px 8px;overflow:hidden">
            <thead>
                <tr>
                    <th style="width:36px">#</th>
                    <th>Part Name</th>
                    <th style="width:130px">Part Number</th>
                    <th style="width:80px" class="text-center">Qty</th>
                    <th>Notes</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($items)): ?>
                <tr><td colspan="5" style="padding:20px;text-align:center;color:#94a3b8">No parts listed.</td></tr>
                <?php endif; ?>
                <?php foreach ($items as $idx => $item): ?>
                <tr>
                    <td style="color:#94a3b8;font-size:12px"><?= $idx + 1 ?></td>
                    <td>
                        <div class="part-name"><?= e($item['part_name']) ?></div>
                        <?php if ($item['stock_part_name'] && $item['stock_part_name'] !== $item['part_name']): ?>
                        <div class="part-no">Stock: <?= e($item['stock_part_name']) ?></div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php $pn = $item['part_number'] ?: $item['stock_part_no'] ?: ''; ?>
                        <?php if ($pn): ?>
                        <code style="font-size:11px;background:#f1f5f9;padding:2px 6px;border-radius:4px;color:#475569"><?= e($pn) ?></code>
                        <?php else: ?>
                        <span style="color:#cbd5e1">—</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-center">
                        <span style="font-weight:700;font-size:15px"><?= number_format((float)$item['quantity_requested'], 0) ?></span>
                        <?php if ($item['quantity_issued'] > 0): ?>
                        <br><span style="font-size:10px;color:#0284c7">Issued: <?= number_format((float)$item['quantity_issued'], 0) ?></span>
                        <?php endif; ?>
                    </td>
                    <td style="color:#475569;font-size:12px"><?= $item['notes'] ? e($item['notes']) : '—' ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- ── Notes ────────────────────────────────────────────────────────────── -->
    <?php if (!empty($req['notes'])): ?>
    <div class="mb-4">
        <div class="section-heading"><i class="fa fa-note-sticky me-1"></i>Notes</div>
        <div class="notes-box mt-3"><?= e($req['notes']) ?></div>
    </div>
    <?php endif; ?>

    <?php if (!empty($req['admin_notes'])): ?>
    <div class="mb-4">
        <div class="section-heading"><i class="fa fa-comment-dots me-1"></i>Response / Admin Notes</div>
        <div class="notes-box mt-3"><?= e($req['admin_notes']) ?></div>
        <?php if ($req['approved_by']): ?>
        <div style="text-align:right;font-size:11px;color:#64748b;margin-top:4px">— <?= e($req['approved_by']) ?></div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- ── Signatures ───────────────────────────────────────────────────────── -->
    <div class="row g-5 mt-4" style="border-top:1px solid #f1f5f9;padding-top:24px">
        <div class="col-4">
            <div style="height:44px"></div>
            <div class="sig-line">
                <div style="font-weight:700;font-size:11px;text-transform:uppercase;letter-spacing:.4px">Requested By</div>
                <div style="color:#64748b;font-size:11px;margin-top:2px"><?= e($req['client_name'] ?: 'Client') ?></div>
            </div>
        </div>
        <div class="col-4">
            <div style="height:44px"></div>
            <div class="sig-line">
                <div style="font-weight:700;font-size:11px;text-transform:uppercase;letter-spacing:.4px">Prepared By</div>
                <div style="color:#64748b;font-size:11px;margin-top:2px"><?= e($req['created_by_name'] ?? '—') ?></div>
            </div>
        </div>
        <div class="col-4">
            <div style="height:44px"></div>
            <div class="sig-line">
                <div style="font-weight:700;font-size:11px;text-transform:uppercase;letter-spacing:.4px">Authorized By</div>
                <div style="color:#64748b;font-size:11px;margin-top:2px">
                    <?= $req['approved_by'] ? e($req['approved_by']) : 'Stamp &amp; Signature' ?>
                </div>
            </div>
        </div>
    </div>

    <!-- ── Footer ───────────────────────────────────────────────────────────── -->
    <div class="text-center mt-5 pt-3" style="border-top:1px solid #f1f5f9;color:#94a3b8;font-size:11px">
        <?= e($company['name']) ?>
        <?php if ($company['phone']): ?> &bull; <?= e($company['phone']) ?><?php endif; ?>
        <?php if ($company['email']): ?> &bull; <?= e($company['email']) ?><?php endif; ?>
        <br>
        <span style="font-size:10px">Printed on <?= date('d F Y, H:i') ?></span>
    </div>

</div><!-- /print-wrapper -->
</body>
</html>

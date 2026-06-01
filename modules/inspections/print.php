<?php
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
canAccess('inspections') || die('Access denied.');

$db = getDB();
$id = (int)($_GET['id'] ?? 0);
if (!$id) die('Invalid ID.');

$cl = $db->prepare("
    SELECT cl.*, c.make, c.model, c.year, c.chassis_number, c.registration_number, c.color,
           c.engine_number, c.fuel_type, c.transmission,
           cs.sale_number, cs.buyer_name, cs.buyer_phone,
           u.name AS inspector_name,
           a.name AS approved_by_name
    FROM inspection_checklists cl
    JOIN cars c ON c.id = cl.car_id
    LEFT JOIN car_sales cs ON cs.id = cl.sale_id
    LEFT JOIN users u ON u.id = cl.inspector_id
    LEFT JOIN users a ON a.id = cl.approved_by
    WHERE cl.id = ?
");
$cl->execute([$id]); $cl = $cl->fetch();
if (!$cl) die('Not found.');

$items = $db->prepare("SELECT * FROM inspection_items WHERE checklist_id=? ORDER BY sort_order ASC");
$items->execute([$id]); $items = $items->fetchAll();

$grouped = [];
foreach ($items as $item) $grouped[$item['category']][] = $item;

$totalItems = count($items);
$okCount    = count(array_filter($items, fn($i)=>$i['result']==='ok'));
$failCount  = count(array_filter($items, fn($i)=>$i['result']==='fail'));
$naCount    = count(array_filter($items, fn($i)=>$i['result']==='na'));

$typeLabels   = ['pre_delivery'=>'Pre-Delivery Inspection','incoming'=>'Incoming Inspection','pre_sale'=>'Pre-Sale Inspection'];
$companyName  = getSetting('company_name', 'Mascardi Car Yard');
$companyPhone = getSetting('company_phone', '');
$companyAddr  = getSetting('company_address', '');
?><!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Inspection Checklist — <?= e($cl['chassis_number']) ?></title>
<style>
* { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: 'Segoe UI', Arial, sans-serif; font-size: 12px; color: #1a1a1a; background: #fff; }
.page { max-width: 850px; margin: 0 auto; padding: 28px 32px; }
h1 { font-size: 20px; font-weight: 700; }
h2 { font-size: 13px; font-weight: 600; margin-bottom: 8px; color: #1e40af; text-transform: uppercase; letter-spacing: .05em; }
.header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 20px; padding-bottom: 16px; border-bottom: 2px solid #1e40af; }
.company { font-size: 13px; color: #475569; }
.doc-title { text-align: right; }
.doc-title h1 { color: #1e40af; }
.doc-title .meta { font-size: 11px; color: #64748b; margin-top: 4px; }
.info-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 18px; }
.info-box { border: 1px solid #e2e8f0; border-radius: 6px; padding: 10px 14px; }
.info-box h3 { font-size: 11px; font-weight: 700; text-transform: uppercase; color: #64748b; letter-spacing: .04em; margin-bottom: 6px; }
.dl { display: grid; grid-template-columns: 130px 1fr; gap: 2px 8px; }
.dl .dt { color: #64748b; font-size: 11px; }
.dl .dd { font-size: 11px; font-weight: 600; }
.category { margin-bottom: 14px; }
.cat-header { background: #1e40af; color: #fff; padding: 5px 12px; border-radius: 4px 4px 0 0; font-weight: 700; font-size: 11px; text-transform: uppercase; letter-spacing: .05em; display: flex; justify-content: space-between; }
table { width: 100%; border-collapse: collapse; }
th { background: #f1f5f9; padding: 5px 10px; text-align: left; font-size: 11px; font-weight: 600; color: #475569; border-bottom: 1px solid #e2e8f0; }
td { padding: 5px 10px; border-bottom: 1px solid #f1f5f9; font-size: 11.5px; vertical-align: top; }
tr:last-child td { border-bottom: none; }
.badge { display: inline-block; padding: 1px 7px; border-radius: 10px; font-size: 10px; font-weight: 700; }
.badge-ok { background: #dcfce7; color: #15803d; }
.badge-fail { background: #fee2e2; color: #b91c1c; }
.badge-na { background: #f1f5f9; color: #475569; }
.badge-pending { background: #fef9c3; color: #92400e; }
.summary-row { display: flex; gap: 20px; margin-bottom: 14px; }
.summary-box { flex: 1; border: 1px solid #e2e8f0; border-radius: 6px; padding: 10px; text-align: center; }
.summary-box .num { font-size: 24px; font-weight: 700; }
.summary-box .lbl { font-size: 10px; color: #64748b; text-transform: uppercase; letter-spacing: .05em; }
.ok-num { color: #16a34a; }
.fail-num { color: #dc2626; }
.na-num { color: #64748b; }
.signature-section { display: grid; grid-template-columns: 1fr 1fr; gap: 24px; margin-top: 24px; border-top: 1px solid #e2e8f0; padding-top: 16px; }
.sig-box { border: 1px solid #e2e8f0; border-radius: 6px; padding: 12px; }
.sig-box h4 { font-size: 11px; font-weight: 700; text-transform: uppercase; color: #64748b; margin-bottom: 8px; }
.sig-line { border-top: 1px solid #1a1a1a; margin-top: 40px; padding-top: 4px; font-size: 10px; color: #64748b; }
.overall-notes { background: #fefce8; border: 1px solid #fde047; border-radius: 6px; padding: 10px 14px; margin-bottom: 14px; font-size: 11.5px; }
.status-banner { padding: 8px 16px; border-radius: 6px; margin-bottom: 14px; font-weight: 600; font-size: 12px; }
.status-approved { background: #dcfce7; color: #15803d; border: 1px solid #86efac; }
.status-failed { background: #fee2e2; color: #b91c1c; border: 1px solid #fca5a5; }
.status-submitted { background: #dbeafe; color: #1d4ed8; border: 1px solid #93c5fd; }
@media print {
    body { font-size: 11px; }
    .no-print { display: none; }
    .page { padding: 16px; }
}
</style>
</head>
<body>
<div class="page">

    <!-- Print button -->
    <div class="no-print" style="margin-bottom:16px;text-align:right">
        <button onclick="window.print()" style="background:#1e40af;color:#fff;border:none;padding:8px 18px;border-radius:6px;cursor:pointer;font-size:13px">
            &#128438; Print / Save as PDF
        </button>
        <a href="view.php?id=<?= $id ?>" style="margin-left:10px;color:#64748b;font-size:13px;text-decoration:none">← Back</a>
    </div>

    <!-- Header -->
    <div class="header">
        <div>
            <div style="font-size:18px;font-weight:800;color:#1e40af"><?= e($companyName) ?></div>
            <div class="company">
                <?= $companyPhone ? e($companyPhone).'<br>' : '' ?>
                <?= $companyAddr  ? e($companyAddr)         : '' ?>
            </div>
        </div>
        <div class="doc-title">
            <h1><?= $typeLabels[$cl['checklist_type']] ?? 'Inspection' ?></h1>
            <div class="meta">
                Checklist #<?= $id ?><br>
                Date: <?= fmtDate($cl['created_at'],'d M Y') ?><br>
                Status: <strong><?= strtoupper($cl['status']) ?></strong>
            </div>
        </div>
    </div>

    <!-- Status banner -->
    <?php if (in_array($cl['status'],['approved','failed','submitted'])): ?>
    <div class="status-banner status-<?= $cl['status'] ?>">
        <?php if ($cl['status']==='approved'): ?>
        ✓ APPROVED by <?= e($cl['approved_by_name']) ?> on <?= fmtDate($cl['approved_at'],'d M Y H:i') ?>
        <?php elseif ($cl['status']==='failed'): ?>
        ✗ FAILED — <?= $failCount ?> item<?= $failCount>1?'s':'' ?> did not pass inspection
        <?php else: ?>
        ● SUBMITTED — Awaiting approval
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- Vehicle + Inspector info -->
    <div class="info-grid">
        <div class="info-box">
            <h3>Vehicle Details</h3>
            <div class="dl">
                <span class="dt">Make / Model</span><span class="dd"><?= e($cl['make'].' '.$cl['model'].' '.$cl['year']) ?></span>
                <span class="dt">Chassis No.</span><span class="dd"><?= e($cl['chassis_number']) ?></span>
                <span class="dt">Registration</span><span class="dd"><?= e($cl['registration_number'] ?: '—') ?></span>
                <span class="dt">Colour</span><span class="dd"><?= e($cl['color'] ?? '—') ?></span>
                <span class="dt">Fuel Type</span><span class="dd"><?= ucfirst($cl['fuel_type'] ?? '—') ?></span>
                <span class="dt">Transmission</span><span class="dd"><?= ucfirst($cl['transmission'] ?? '—') ?></span>
            </div>
        </div>
        <div class="info-box">
            <h3>Inspection Details</h3>
            <div class="dl">
                <span class="dt">Type</span><span class="dd"><?= $typeLabels[$cl['checklist_type']] ?? $cl['checklist_type'] ?></span>
                <span class="dt">Inspector</span><span class="dd"><?= e($cl['inspector_name'] ?? 'Not assigned') ?></span>
                <span class="dt">Inspected</span><span class="dd"><?= fmtDate($cl['created_at'],'d M Y') ?></span>
                <?php if ($cl['sale_number']): ?>
                <span class="dt">Sale</span><span class="dd"><?= e($cl['sale_number']) ?></span>
                <span class="dt">Buyer</span><span class="dd"><?= e($cl['buyer_name']) ?></span>
                <span class="dt">Phone</span><span class="dd"><?= e($cl['buyer_phone'] ?? '—') ?></span>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Summary boxes -->
    <div class="summary-row">
        <div class="summary-box">
            <div class="num"><?= $totalItems ?></div>
            <div class="lbl">Total Items</div>
        </div>
        <div class="summary-box">
            <div class="num ok-num"><?= $okCount ?></div>
            <div class="lbl">Passed (OK)</div>
        </div>
        <div class="summary-box">
            <div class="num fail-num"><?= $failCount ?></div>
            <div class="lbl">Failed</div>
        </div>
        <div class="summary-box">
            <div class="num na-num"><?= $naCount ?></div>
            <div class="lbl">Not Applicable</div>
        </div>
        <div class="summary-box">
            <div class="num" style="color:#1e40af"><?= $totalItems > 0 ? round(($okCount+$naCount)/$totalItems*100) : 0 ?>%</div>
            <div class="lbl">Pass Rate</div>
        </div>
    </div>

    <!-- Overall notes -->
    <?php if ($cl['overall_notes']): ?>
    <div class="overall-notes">
        <strong>Notes:</strong> <?= nl2br(e($cl['overall_notes'])) ?>
    </div>
    <?php endif; ?>

    <!-- Checklist items by category -->
    <?php foreach ($grouped as $category => $catItems):
        $catOk   = count(array_filter($catItems, fn($i)=>$i['result']==='ok'));
        $catFail = count(array_filter($catItems, fn($i)=>$i['result']==='fail'));
    ?>
    <div class="category">
        <div class="cat-header">
            <span><?= e($category) ?></span>
            <span><?= $catOk ?> OK<?= $catFail > 0 ? ' · '.$catFail.' FAIL' : '' ?> / <?= count($catItems) ?> items</span>
        </div>
        <table>
            <thead>
                <tr>
                    <th style="width:50%">Item</th>
                    <th style="width:70px">Result</th>
                    <th>Notes</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($catItems as $item): ?>
            <tr style="<?= $item['result']==='fail'?'background:#fff5f5':'' ?>">
                <td><?= e($item['item']) ?></td>
                <td>
                    <span class="badge badge-<?= $item['result'] ?>">
                        <?= strtoupper($item['result']) ?>
                    </span>
                </td>
                <td style="color:#475569"><?= e($item['notes'] ?? '') ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endforeach; ?>

    <!-- Signature section -->
    <div class="signature-section">
        <div class="sig-box">
            <h4>Inspector Sign-off</h4>
            <div style="font-size:12px;margin-bottom:4px">Name: <strong><?= e($cl['inspector_name'] ?? '___________________') ?></strong></div>
            <div class="sig-line">Signature &amp; Date</div>
        </div>
        <div class="sig-box">
            <h4>Authorised By (Manager)</h4>
            <div style="font-size:12px;margin-bottom:4px">Name: <strong><?= e($cl['approved_by_name'] ?? '___________________') ?></strong></div>
            <div class="sig-line">Signature &amp; Date</div>
        </div>
    </div>

    <div style="margin-top:20px;text-align:center;font-size:10px;color:#94a3b8">
        <?= e($companyName) ?> · Generated <?= date('d M Y H:i') ?> · <?= e(BASE_URL) ?>
    </div>
</div>
</body>
</html>

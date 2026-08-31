<?php
/**
 * Imports — printable documents.
 *
 * Three documents, all built from data the pipeline already records, so nothing
 * here has to be keyed in twice:
 *
 *   order     the purchase order placed with the supplier for one vehicle —
 *             specification, price in its original currency and in shillings,
 *             auction reference, IDF, and where the vehicle has reached.
 *   landed    what the vehicle has actually cost by the time it reaches the
 *             yard: the purchase price plus every recorded import cost. This is
 *             the figure a sale price has to clear, and until now it lived only
 *             as rows in a table nobody could hand to anyone.
 *   manifest  every vehicle on one shipment, against the bill of lading and
 *             vessel — the sheet a clearing agent actually asks for.
 *
 * Documents the shipping line or KRA issue — the bill of lading itself, the
 * customs entry — are deliberately not generated here. We record their numbers;
 * producing a lookalike would be worse than useless.
 */
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
canAccess('imports') || die('Access denied.');

$db   = getDB();
$type = $_GET['type'] ?? 'order';
$id   = (int)($_GET['id'] ?? 0);
if (!$id || !in_array($type, ['order', 'landed', 'manifest'], true)) {
    die('Nothing to print.');
}

$company = [
    'name'    => getSetting('company_name', 'Mascardi Car Yard'),
    'address' => getSetting('company_address', 'Nairobi, Kenya'),
    'phone'   => getSetting('company_phone', ''),
    'email'   => getSetting('company_email', ''),
    'pin'     => getSetting('company_pin', ''),
];

/** Money in whichever currency it was actually paid in. */
function impMoney(?float $n, string $cur = 'KES'): string
{
    return strtoupper($cur ?: 'KES') . ' ' . number_format((float)$n, 2);
}

/** The stage names read as jargon in the database; spell them out on paper. */
function impStage(?string $s): string
{
    return [
        'purchased'       => 'Purchased at auction',
        'in_transit_sea'  => 'On the water',
        'arrived_port'    => 'Arrived at port',
        'customs'         => 'In customs',
        'cleared'         => 'Cleared customs',
        'in_transit_road' => 'On the road to the yard',
        'arrived_yard'    => 'Arrived at the yard',
        'intake'          => 'Being taken in',
        'completed'       => 'Completed',
    ][$s ?? ''] ?? ucwords(str_replace('_', ' ', (string)$s));
}

// ── Load ─────────────────────────────────────────────────────────────────────
$imp = $ship = null;
$rows = [];

if ($type === 'manifest') {
    $s = $db->prepare("SELECT * FROM car_shipments WHERE id = ?");
    $s->execute([$id]);
    $ship = $s->fetch(PDO::FETCH_ASSOC);
    if (!$ship) die('Shipment not found.');

    $r = $db->prepare("SELECT * FROM car_imports WHERE shipment_id = ? ORDER BY make, model");
    $r->execute([$id]);
    $rows = $r->fetchAll(PDO::FETCH_ASSOC);
} else {
    $s = $db->prepare("SELECT i.*, sh.ref AS ship_ref, sh.vessel_name, sh.bl_number,
                              sh.shipping_line, sh.eta, sh.etd, sh.origin_country
                         FROM car_imports i
                    LEFT JOIN car_shipments sh ON sh.id = i.shipment_id
                        WHERE i.id = ?");
    $s->execute([$id]);
    $imp = $s->fetch(PDO::FETCH_ASSOC);
    if (!$imp) die('Import order not found.');

    if ($type === 'landed') {
        $c = $db->prepare("SELECT * FROM import_costs WHERE import_id = ? ORDER BY paid_at, id");
        $c->execute([$id]);
        $rows = $c->fetchAll(PDO::FETCH_ASSOC);
    }
}

$titles = [
    'order'    => 'Import Order',
    'landed'   => 'Landed Cost Statement',
    'manifest' => 'Shipment Manifest',
];
$docTitle = $titles[$type];
$docRef   = $type === 'manifest' ? ($ship['ref'] ?? '') : ($imp['ref'] ?? '');
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= e($docTitle) ?> <?= e($docRef) ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="<?= BASE_URL ?>/assets/css/style.css" rel="stylesheet">
<style>
    body{ background:#f1f5f9; font-size:13px; }
    .print-wrapper{ max-width:820px; margin:30px auto; background:#fff; padding:40px;
        box-shadow:0 2px 8px rgba(0,0,0,.1); border-radius:8px; }
    .doc-head{ display:flex; justify-content:space-between; gap:24px;
        border-bottom:2px solid #0f172a; padding-bottom:16px; margin-bottom:22px; }
    .doc-title{ font-size:20px; font-weight:800; letter-spacing:-.4px; margin:0; }
    .doc-ref{ font-size:12px; color:#64748b; margin-top:2px; }
    .co{ text-align:right; font-size:11.5px; color:#475569; line-height:1.6; }
    .co b{ display:block; font-size:14px; color:#0f172a; }
    .sec{ font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.06em;
        color:#64748b; margin:22px 0 8px; }
    table.kv{ width:100%; font-size:12.5px; }
    table.kv td{ padding:4px 0; vertical-align:top; }
    table.kv td:first-child{ color:#64748b; width:170px; }
    table.kv td:last-child{ font-weight:600; }
    table.grid{ width:100%; border-collapse:collapse; font-size:12px; }
    table.grid th{ background:#0f172a; color:#fff; text-align:left; padding:7px 9px; font-weight:600; }
    table.grid td{ border-bottom:1px solid #e2e8f0; padding:7px 9px; }
    table.grid tfoot td{ border-top:2px solid #0f172a; font-weight:800; background:#f8fafc; }
    .muted{ color:#94a3b8; }
    .foot{ margin-top:28px; padding-top:14px; border-top:1px solid #e2e8f0;
        font-size:10.5px; color:#94a3b8; display:flex; justify-content:space-between; gap:16px; }
    @media print{
        body{ background:#fff; }
        .no-print{ display:none!important; }
        .print-wrapper{ box-shadow:none; margin:0; padding:18px; max-width:none; border-radius:0; }
    }
</style>
</head>
<body>

<div class="no-print text-center py-3">
    <button onclick="window.print()" class="btn btn-primary">
        <i class="fa fa-print me-1"></i>Print / Save as PDF</button>
    <a href="<?= BASE_URL ?>/modules/imports/index.php" class="btn btn-outline-secondary">Back</a>
</div>

<div class="print-wrapper">

    <div class="doc-head">
        <div>
            <h1 class="doc-title"><?= e($docTitle) ?></h1>
            <div class="doc-ref">
                <?= e($docRef) ?> &nbsp;·&nbsp; <?= date('j F Y') ?>
            </div>
        </div>
        <div class="co">
            <?php $__logo = getSetting('company_logo', ''); ?>
            <?php if ($__logo): ?>
                <img src="<?= BASE_URL ?>/assets/images/<?= e($__logo) ?>" alt=""
                     style="max-height:46px;margin-bottom:6px">
            <?php endif; ?>
            <b><?= e($company['name']) ?></b>
            <?= e($company['address']) ?><br>
            <?= e($company['phone']) ?><?= $company['email'] ? ' · ' . e($company['email']) : '' ?>
            <?= $company['pin'] ? '<br>PIN: ' . e($company['pin']) : '' ?>
        </div>
    </div>

<?php if ($type === 'order'): ?>

    <div class="sec">Supplier</div>
    <table class="kv">
        <tr><td>Supplier</td><td><?= e($imp['supplier_name'] ?: '—') ?></td></tr>
        <tr><td>Auction reference</td><td><?= e($imp['auction_ref'] ?: '—') ?></td></tr>
        <tr><td>Purchase date</td><td><?= $imp['purchase_date'] ? fmtDate($imp['purchase_date']) : '—' ?></td></tr>
        <tr><td>Country of origin</td><td><?= e($imp['origin_country'] ?: '—') ?></td></tr>
    </table>

    <div class="sec">Vehicle</div>
    <table class="kv">
        <tr><td>Make and model</td>
            <td><?= e(trim(($imp['year'] ?: '') . ' ' . $imp['make'] . ' ' . $imp['model'])) ?></td></tr>
        <tr><td>Chassis number</td><td><?= e($imp['chassis_number'] ?: '—') ?></td></tr>
        <tr><td>Engine number</td><td><?= e($imp['engine_number'] ?: '—') ?></td></tr>
        <tr><td>Colour</td><td><?= e($imp['color'] ?: '—') ?></td></tr>
        <tr><td>Body / transmission</td>
            <td><?= e(trim(($imp['body_type'] ?: '—') . ' · ' . ($imp['transmission'] ?: '—'))) ?></td></tr>
        <tr><td>Engine / fuel</td>
            <td><?= $imp['engine_cc'] ? (int)$imp['engine_cc'] . 'cc' : '—' ?>
                · <?= e($imp['fuel_type'] ?: '—') ?></td></tr>
        <tr><td>Mileage</td>
            <td><?= $imp['mileage'] ? number_format((int)$imp['mileage']) . ' km' : '—' ?></td></tr>
    </table>

    <div class="sec">Price</div>
    <table class="kv">
        <tr><td>Purchase price</td>
            <td><?= impMoney((float)$imp['purchase_price'], $imp['purchase_currency'] ?: 'JPY') ?></td></tr>
        <tr><td>Exchange rate</td>
            <td><?= $imp['exchange_rate'] ? rtrim(rtrim(number_format((float)$imp['exchange_rate'], 6), '0'), '.') : '—' ?></td></tr>
        <tr><td>Equivalent</td><td><?= impMoney((float)$imp['purchase_price_kes'], 'KES') ?></td></tr>
    </table>

    <div class="sec">Shipping and clearance</div>
    <table class="kv">
        <tr><td>Shipment</td><td><?= e($imp['ship_ref'] ?: 'Not assigned') ?></td></tr>
        <tr><td>Vessel</td><td><?= e($imp['vessel_name'] ?: '—') ?></td></tr>
        <tr><td>Shipping line</td><td><?= e($imp['shipping_line'] ?: '—') ?></td></tr>
        <tr><td>Bill of lading</td><td><?= e($imp['bl_number'] ?: '—') ?></td></tr>
        <tr><td>Departed / due</td>
            <td><?= $imp['etd'] ? fmtDate($imp['etd']) : '—' ?>
                → <?= $imp['eta'] ? fmtDate($imp['eta']) : '—' ?></td></tr>
        <tr><td>IDF number</td>
            <td><?= e($imp['idf_number'] ?: '—') ?><?= $imp['idf_date'] ? ' (' . fmtDate($imp['idf_date']) . ')' : '' ?></td></tr>
        <tr><td>Current stage</td><td><?= e(impStage($imp['stage'])) ?></td></tr>
    </table>

    <?php if (trim((string)$imp['notes']) !== ''): ?>
        <div class="sec">Notes</div>
        <div style="font-size:12.5px;white-space:pre-wrap"><?= e($imp['notes']) ?></div>
    <?php endif; ?>

<?php elseif ($type === 'landed'): ?>

    <div class="sec">Vehicle</div>
    <table class="kv">
        <tr><td>Make and model</td>
            <td><?= e(trim(($imp['year'] ?: '') . ' ' . $imp['make'] . ' ' . $imp['model'])) ?></td></tr>
        <tr><td>Chassis number</td><td><?= e($imp['chassis_number'] ?: '—') ?></td></tr>
        <tr><td>Supplier</td><td><?= e($imp['supplier_name'] ?: '—') ?></td></tr>
        <tr><td>Current stage</td><td><?= e(impStage($imp['stage'])) ?></td></tr>
    </table>

    <div class="sec">What this vehicle has cost</div>
    <table class="grid">
        <thead>
            <tr>
                <th style="width:96px">Date</th>
                <th>Item</th>
                <th style="width:120px">Reference</th>
                <th class="text-end" style="width:140px">Amount (KES)</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td><?= $imp['purchase_date'] ? fmtDate($imp['purchase_date']) : '—' ?></td>
                <td><strong>Purchase price</strong>
                    <?php if (($imp['purchase_currency'] ?: 'KES') !== 'KES'): ?>
                        <span class="muted">
                            — <?= impMoney((float)$imp['purchase_price'], $imp['purchase_currency']) ?>
                            at <?= rtrim(rtrim(number_format((float)$imp['exchange_rate'], 4), '0'), '.') ?>
                        </span>
                    <?php endif; ?>
                </td>
                <td class="muted"><?= e($imp['auction_ref'] ?: '—') ?></td>
                <td class="text-end"><?= number_format((float)$imp['purchase_price_kes'], 2) ?></td>
            </tr>
            <?php
            $total = (float)$imp['purchase_price_kes'];
            foreach ($rows as $c):
                $total += (float)$c['amount_kes'];
            ?>
            <tr>
                <td><?= $c['paid_at'] ? fmtDate($c['paid_at']) : '—' ?></td>
                <td><?= e(ucwords(str_replace('_', ' ', (string)$c['cost_type']))) ?>
                    <?php if (trim((string)$c['description']) !== ''): ?>
                        <span class="muted">— <?= e($c['description']) ?></span>
                    <?php endif; ?>
                </td>
                <td class="muted"><?= e($c['receipt_ref'] ?: '—') ?></td>
                <td class="text-end"><?= number_format((float)$c['amount_kes'], 2) ?></td>
            </tr>
            <?php endforeach; ?>
            <?php if (!$rows): ?>
            <tr><td colspan="4" class="muted" style="padding:14px 9px">
                No import costs recorded yet, so this is the purchase price alone.
            </td></tr>
            <?php endif; ?>
        </tbody>
        <tfoot>
            <tr>
                <td colspan="3">Total landed cost</td>
                <td class="text-end"><?= number_format($total, 2) ?></td>
            </tr>
        </tfoot>
    </table>
    <p class="muted" style="font-size:11px;margin-top:10px">
        This is what the vehicle has cost to put on the yard. Any sale price has to clear it
        before there is a margin.
    </p>

<?php else: ?>

    <div class="sec">Shipment</div>
    <table class="kv">
        <tr><td>Description</td><td><?= e($ship['name'] ?: '—') ?></td></tr>
        <tr><td>Origin</td><td><?= e($ship['origin_country'] ?: '—') ?></td></tr>
        <tr><td>Shipping line</td><td><?= e($ship['shipping_line'] ?: '—') ?></td></tr>
        <tr><td>Vessel</td><td><?= e($ship['vessel_name'] ?: '—') ?></td></tr>
        <tr><td>Bill of lading</td><td><?= e($ship['bl_number'] ?: '—') ?></td></tr>
        <tr><td>Departed / due</td>
            <td><?= $ship['etd'] ? fmtDate($ship['etd']) : '—' ?>
                → <?= $ship['eta'] ? fmtDate($ship['eta']) : '—' ?></td></tr>
        <tr><td>Arrived</td><td><?= $ship['actual_arrival'] ? fmtDate($ship['actual_arrival']) : 'Not yet' ?></td></tr>
        <tr><td>Status</td><td><?= e(ucwords(str_replace('_', ' ', (string)$ship['status']))) ?></td></tr>
    </table>

    <div class="sec"><?= count($rows) ?> vehicle<?= count($rows) === 1 ? '' : 's' ?> on this shipment</div>
    <table class="grid">
        <thead>
            <tr>
                <th style="width:34px">#</th>
                <th>Vehicle</th>
                <th style="width:150px">Chassis</th>
                <th style="width:110px">Engine</th>
                <th class="text-end" style="width:130px">Value (KES)</th>
            </tr>
        </thead>
        <tbody>
        <?php $n = 0; $sum = 0.0; foreach ($rows as $r): $n++; $sum += (float)$r['purchase_price_kes']; ?>
            <tr>
                <td class="muted"><?= $n ?></td>
                <td><?= e(trim(($r['year'] ?: '') . ' ' . $r['make'] . ' ' . $r['model'])) ?>
                    <?php if ($r['color']): ?><span class="muted">· <?= e($r['color']) ?></span><?php endif; ?>
                </td>
                <td><?= e($r['chassis_number'] ?: '—') ?></td>
                <td class="muted"><?= e($r['engine_number'] ?: '—') ?></td>
                <td class="text-end"><?= number_format((float)$r['purchase_price_kes'], 2) ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?>
            <tr><td colspan="5" class="muted" style="padding:14px 9px">
                No vehicles have been assigned to this shipment yet.
            </td></tr>
        <?php endif; ?>
        </tbody>
        <?php if ($rows): ?>
        <tfoot>
            <tr>
                <td colspan="4">Total declared value</td>
                <td class="text-end"><?= number_format($sum, 2) ?></td>
            </tr>
        </tfoot>
        <?php endif; ?>
    </table>

<?php endif; ?>

    <div class="foot">
        <span>Generated <?= date('j M Y, g:ia') ?> by <?= e(authUser()['name'] ?? '') ?></span>
        <span><?= e($company['name']) ?></span>
    </div>
</div>
</body>
</html>

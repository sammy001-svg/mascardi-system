<?php
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/_bootstrap.php';
requireLogin();
canAccess('trade_in') || die('Access denied.');

$db = getDB();
tradeInMigrate($db);

$id = (int)($_GET['id'] ?? 0);
$c  = $id ? consignmentFind($db, $id) : null;
if (!$c) die('Record not found.');

$isTrade    = $c['deal_type'] === 'trade_in';
$commission = consignmentCommission($c);
$payout     = consignmentPayout($c);

$co = [
    'name'    => getSetting('company_name',    'Mascardi Car Yard'),
    'address' => getSetting('company_address', 'Nairobi, Kenya'),
    'phone'   => getSetting('company_phone',   ''),
    'email'   => getSetting('company_email',   ''),
    'pin'     => getSetting('company_pin',     ''),
];
$logo = companyLogo();

$docTitle = $isTrade ? 'Vehicle Trade-In Agreement' : 'Vehicle Sale on Behalf Agreement';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title><?= $docTitle ?> — <?= e($c['reference']) ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
<style>
* { font-family: 'Times New Roman', serif; }
body { background: #f1f5f9; font-size: 13px; }
.print-wrapper { max-width: 820px; margin: 30px auto; background: #fff; padding: 48px; box-shadow: 0 2px 12px rgba(0,0,0,.1); }
.doc-title { font-size: 22px; font-weight: 800; text-align: center; letter-spacing: 2px; text-transform: uppercase; margin: 20px 0 4px; }
.doc-subtitle { text-align: center; color: #64748b; font-size: 12px; margin-bottom: 24px; }
.section-heading { font-weight: 700; text-transform: uppercase; letter-spacing: 1px; font-size: 11px; color: #1e3a5f; border-bottom: 2px solid #1e3a5f; padding-bottom: 4px; margin: 20px 0 12px; }
.data-row { display: flex; margin-bottom: 6px; font-size: 12.5px; }
.data-label { width: 170px; flex-shrink: 0; color: #64748b; }
.data-value { flex: 1; font-weight: 600; }
.sig-block { border-top: 1px solid #334155; padding-top: 6px; min-height: 70px; }
.sig-label { font-size: 11px; color: #475569; margin-top: 4px; }
.clause { margin-bottom: 10px; font-size: 12px; line-height: 1.7; }
.clause-num { font-weight: 700; color: #1e3a5f; }
.amount-box { background: #f0fdf4; border: 2px solid #16a34a; border-radius: 6px; padding: 12px 20px; text-align: center; display: inline-block; min-width: 210px; }
.amount-box .amt { font-size: 22px; font-weight: 800; color: #16a34a; }
.amount-box .lbl { font-size: 11px; color: #64748b; text-transform: uppercase; letter-spacing: 1px; }
.letterhead-divider { border-top: 3px solid #1e3a5f; border-bottom: 1px solid #93c5fd; padding: 6px 0; margin-bottom: 24px; text-align: center; color: #1e3a5f; font-size: 11px; letter-spacing: 1px; }
.terms-table { width: 100%; border-collapse: collapse; font-size: 12.5px; }
.terms-table td { padding: 7px 10px; border-bottom: 1px solid #e2e8f0; }
.terms-table td:first-child { color: #64748b; width: 55%; }
.terms-table td:last-child { text-align: right; font-weight: 700; }
@media print {
    body { background: #fff; }
    .no-print { display: none !important; }
    .print-wrapper { box-shadow: none; margin: 0; padding: 28px; }
}
</style>
</head>
<body>

<div class="no-print text-center py-3">
    <button onclick="window.print()" class="btn btn-primary btn-sm"><i class="fa fa-print me-1"></i>Print / Save as PDF</button>
    <a href="view.php?id=<?= $id ?>" class="btn btn-outline-secondary btn-sm ms-2"><i class="fa fa-arrow-left me-1"></i>Back</a>
</div>

<div class="print-wrapper">

    <!-- Letterhead -->
    <div class="row align-items-center mb-2">
        <div class="col-8">
            <?php if ($logo['exists']): ?>
            <img src="<?= e($logo['url']) ?>" alt="<?= e($co['name']) ?>" style="height:52px;max-width:180px;object-fit:contain;display:block;margin-bottom:4px">
            <?php else: ?>
            <div style="font-size:24px;font-weight:800;color:#1e3a5f;font-family:sans-serif"><?= e($co['name']) ?></div>
            <?php endif; ?>
            <div style="font-size:11px;color:#64748b"><?= e($co['address']) ?></div>
            <?php if ($co['phone']): ?><div style="font-size:11px;color:#64748b">Tel: <?= e($co['phone']) ?></div><?php endif; ?>
            <?php if ($co['email']): ?><div style="font-size:11px;color:#64748b">Email: <?= e($co['email']) ?></div><?php endif; ?>
            <?php if ($co['pin']):   ?><div style="font-size:11px;color:#64748b">KRA PIN: <?= e($co['pin']) ?></div><?php endif; ?>
        </div>
        <div class="col-4 text-end">
            <div style="font-size:11px;color:#64748b">Ref: <strong><?= e($c['reference']) ?></strong></div>
            <div style="font-size:11px;color:#64748b">Date: <strong><?= fmtDate($c['agreement_date'] ?: $c['created_at'], 'd F Y') ?></strong></div>
        </div>
    </div>
    <div class="letterhead-divider">MOTOR VEHICLE DEALER — LICENSED &amp; REGISTERED</div>

    <div class="doc-title"><?= $docTitle ?></div>
    <div class="doc-subtitle">
        This Agreement is made on <?= fmtDate($c['agreement_date'] ?: $c['created_at'], 'd F Y') ?>
        between the parties identified below.
    </div>

    <!-- Parties -->
    <div class="section-heading">1. Parties</div>
    <div class="row">
        <div class="col-6">
            <div style="font-size:11px;font-weight:700;color:#475569;margin-bottom:6px">
                <?= $isTrade ? 'DEALER (Purchaser)' : 'AGENT (Dealer)' ?>
            </div>
            <div class="data-row"><span class="data-label">Company</span><span class="data-value"><?= e($co['name']) ?></span></div>
            <div class="data-row"><span class="data-label">Address</span><span class="data-value"><?= e($co['address']) ?></span></div>
            <?php if ($co['phone']): ?>
            <div class="data-row"><span class="data-label">Telephone</span><span class="data-value"><?= e($co['phone']) ?></span></div>
            <?php endif; ?>
            <?php if ($co['pin']): ?>
            <div class="data-row"><span class="data-label">KRA PIN</span><span class="data-value"><?= e($co['pin']) ?></span></div>
            <?php endif; ?>
        </div>
        <div class="col-6">
            <div style="font-size:11px;font-weight:700;color:#475569;margin-bottom:6px">
                <?= $isTrade ? 'CUSTOMER (Vendor)' : 'VEHICLE OWNER (Principal)' ?>
            </div>
            <div class="data-row"><span class="data-label">Full Name</span><span class="data-value"><?= e($c['owner_name']) ?></span></div>
            <div class="data-row"><span class="data-label">ID / Passport</span><span class="data-value"><?= e($c['owner_id_number'] ?: '________________') ?></span></div>
            <div class="data-row"><span class="data-label">Telephone</span><span class="data-value"><?= e($c['owner_phone'] ?: '________________') ?></span></div>
            <?php if ($c['owner_email']): ?>
            <div class="data-row"><span class="data-label">Email</span><span class="data-value"><?= e($c['owner_email']) ?></span></div>
            <?php endif; ?>
            <div class="data-row"><span class="data-label">Address</span><span class="data-value"><?= e($c['owner_address'] ?: '________________') ?></span></div>
        </div>
    </div>

    <!-- Vehicle -->
    <div class="section-heading">2. The Vehicle</div>
    <div class="row">
        <div class="col-6">
            <div class="data-row"><span class="data-label">Make &amp; Model</span><span class="data-value"><?= e($c['make'] . ' ' . $c['model']) ?></span></div>
            <div class="data-row"><span class="data-label">Year</span><span class="data-value"><?= e((string)$c['year']) ?></span></div>
            <div class="data-row"><span class="data-label">Registration No.</span><span class="data-value"><?= e($c['registration_number'] ?: '—') ?></span></div>
            <div class="data-row"><span class="data-label">Chassis No.</span><span class="data-value"><?= e($c['chassis_number']) ?></span></div>
        </div>
        <div class="col-6">
            <div class="data-row"><span class="data-label">Colour</span><span class="data-value"><?= e($c['color'] ?: '—') ?></span></div>
            <div class="data-row"><span class="data-label">Body Type</span><span class="data-value"><?= e($c['body_type'] ?: '—') ?></span></div>
            <div class="data-row"><span class="data-label">Mileage</span><span class="data-value"><?= $c['mileage'] ? number_format((int)$c['mileage']) . ' km' : '—' ?></span></div>
            <div class="data-row"><span class="data-label">Fuel / Transmission</span><span class="data-value"><?= ucfirst($c['fuel_type'] ?: '—') ?> / <?= ucfirst($c['transmission'] ?: '—') ?></span></div>
        </div>
    </div>

    <!-- Commercial terms -->
    <div class="section-heading">3. <?= $isTrade ? 'Trade-In Terms' : 'Commercial Terms' ?></div>
    <?php if ($isTrade): ?>
    <table class="terms-table mb-3">
        <tr><td>Dealer valuation of the vehicle</td><td><?= money((float)($c['valuation_amount'] ?: 0)) ?></td></tr>
        <tr><td>Trade-in allowance credited to the Customer</td><td><?= money((float)($c['trade_in_value'] ?: 0)) ?></td></tr>
    </table>
    <div class="text-center my-3">
        <div class="amount-box">
            <div class="lbl">Allowance Credited</div>
            <div class="amt"><?= money((float)($c['trade_in_value'] ?: 0)) ?></div>
        </div>
    </div>
    <p class="clause" style="text-align:center;font-style:italic;color:#475569">
        <?= e(numberToWords((float)($c['trade_in_value'] ?: 0))) ?>
    </p>
    <?php else: ?>
    <table class="terms-table mb-3">
        <tr><td>Advertised selling price</td><td><?= money((float)($c['listing_price'] ?: 0)) ?></td></tr>
        <tr>
            <td>Agent's commission
                (<?= $c['commission_type'] === 'fixed'
                    ? 'fixed'
                    : rtrim(rtrim(number_format((float)$c['commission_value'], 2), '0'), '.') . '% of the sale price' ?>)
            </td>
            <td><?= money($commission) ?></td>
        </tr>
        <tr><td>Net amount payable to the Owner</td><td><?= money($payout) ?></td></tr>
        <?php if ($c['owner_expected_price']): ?>
        <tr><td>Minimum acceptable to the Owner</td><td><?= money((float)$c['owner_expected_price']) ?></td></tr>
        <?php endif; ?>
        <tr><td>Agreement period</td>
            <td><?= fmtDate($c['agreement_date'] ?: $c['created_at']) ?>
                <?= $c['expiry_date'] ? ' – ' . fmtDate($c['expiry_date']) : '' ?></td></tr>
    </table>
    <div class="text-center my-3">
        <div class="amount-box">
            <div class="lbl">Owner Receives (at listed price)</div>
            <div class="amt"><?= money($payout) ?></div>
        </div>
    </div>
    <p class="clause" style="text-align:center;font-style:italic;color:#475569">
        <?= e(numberToWords($payout)) ?>
    </p>
    <?php endif; ?>

    <!-- Terms -->
    <div class="section-heading">4. Terms and Conditions</div>
    <?php if ($isTrade): ?>
    <div class="clause"><span class="clause-num">4.1</span> The Customer confirms that they are the lawful owner of the vehicle described in Clause 2, that it is free from any encumbrance, charge, lien or outstanding finance, and that they have full authority to dispose of it.</div>
    <div class="clause"><span class="clause-num">4.2</span> The Customer shall surrender the original logbook, all keys, and all supporting documents at the time of handover.</div>
    <div class="clause"><span class="clause-num">4.3</span> The trade-in allowance stated in Clause 3 shall be credited against the purchase price of the vehicle acquired by the Customer from the Dealer, and is not redeemable for cash unless separately agreed in writing.</div>
    <div class="clause"><span class="clause-num">4.4</span> The vehicle is accepted on an "as is" basis following inspection by the Dealer. Ownership and all risk pass to the Dealer upon execution of this Agreement and physical handover.</div>
    <div class="clause"><span class="clause-num">4.5</span> The Customer shall assist with, and sign, all transfer of ownership documentation required by the National Transport and Safety Authority (NTSA).</div>
    <div class="clause"><span class="clause-num">4.6</span> The Customer warrants that the odometer reading is genuine and has not been altered, and that all material defects known to them have been disclosed.</div>
    <?php else: ?>
    <div class="clause"><span class="clause-num">4.1</span> The Owner confirms that they are the lawful owner of the vehicle described in Clause 2, that it is free from any encumbrance, charge, lien or outstanding finance, and that they have full authority to offer it for sale.</div>
    <div class="clause"><span class="clause-num">4.2</span> The Owner appoints the Agent to market and sell the vehicle on their behalf at the advertised price stated in Clause 3. The Agent may advertise the vehicle at its premises, on its website and on any other channel it considers appropriate.</div>
    <div class="clause"><span class="clause-num">4.3</span> Ownership of the vehicle remains with the Owner at all times until it is sold to a buyer. The Agent takes possession solely for the purpose of display, demonstration and sale.</div>
    <div class="clause"><span class="clause-num">4.4</span> Upon completion of a sale and receipt of cleared funds from the buyer, the Agent shall deduct the commission stated in Clause 3 and remit the net balance to the Owner within fourteen (14) days.</div>
    <div class="clause"><span class="clause-num">4.5</span> The selling price may only be varied by mutual written agreement of both parties. No sale below the Owner's stated minimum shall be concluded without the Owner's prior consent.</div>
    <div class="clause"><span class="clause-num">4.6</span> Either party may terminate this Agreement by giving seven (7) days' written notice. On termination, the vehicle shall be returned to the Owner, who remains liable for any commission due on a sale already concluded.</div>
    <div class="clause"><span class="clause-num">4.7</span> The Owner shall surrender the original logbook and all keys, and shall sign all transfer documentation required by the National Transport and Safety Authority (NTSA) upon a successful sale.</div>
    <div class="clause"><span class="clause-num">4.8</span> The Agent shall exercise reasonable care of the vehicle while in its possession. The Owner is responsible for maintaining comprehensive insurance cover unless otherwise agreed in writing.</div>
    <div class="clause"><span class="clause-num">4.9</span> The Owner warrants that the odometer reading is genuine and that all material defects known to them have been disclosed to the Agent.</div>
    <?php endif; ?>
    <div class="clause"><span class="clause-num">4.<?= $isTrade ? '7' : '10' ?></span> This Agreement is governed by the laws of the Republic of Kenya. Any dispute shall first be referred to good-faith negotiation between the parties.</div>

    <?php if ($c['notes']): ?>
    <div class="section-heading">5. Special Conditions</div>
    <div class="clause"><?= nl2br(e($c['notes'])) ?></div>
    <?php endif; ?>

    <!-- Signatures -->
    <div class="section-heading"><?= $c['notes'] ? '6' : '5' ?>. Execution</div>
    <p class="clause">
        The parties confirm that they have read, understood and agreed to the terms of this Agreement,
        and sign below on the date first written above.
    </p>
    <div class="row mt-4">
        <div class="col-6">
            <div class="sig-block"></div>
            <div class="sig-label">
                <strong><?= $isTrade ? 'CUSTOMER' : 'VEHICLE OWNER' ?></strong><br>
                <?= e($c['owner_name']) ?><br>
                ID: <?= e($c['owner_id_number'] ?: '________________') ?><br>
                Date: ________________
            </div>
        </div>
        <div class="col-6">
            <div class="sig-block"></div>
            <div class="sig-label">
                <strong>FOR AND ON BEHALF OF <?= strtoupper(e($co['name'])) ?></strong><br>
                Name: ________________<br>
                Designation: ________________<br>
                Date: ________________
            </div>
        </div>
    </div>
    <div class="row mt-4">
        <div class="col-6">
            <div class="sig-block"></div>
            <div class="sig-label"><strong>WITNESS</strong><br>Name: ________________<br>Signature &amp; Date</div>
        </div>
        <div class="col-6 text-end d-flex align-items-end justify-content-end">
            <div style="font-size:10.5px;color:#94a3b8">
                Company stamp<br>
                <div style="border:1px dashed #cbd5e1;width:150px;height:70px;margin-top:6px"></div>
            </div>
        </div>
    </div>

    <div class="text-center mt-4" style="font-size:10px;color:#94a3b8;border-top:1px solid #e2e8f0;padding-top:10px">
        <?= e($co['name']) ?> — <?= $docTitle ?> — Ref <?= e($c['reference']) ?>
        · Generated <?= date('d F Y, H:i') ?>
    </div>
</div>

</body>
</html>

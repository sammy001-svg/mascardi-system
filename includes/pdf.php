<?php
/**
 * PDF generation helper using DOMPDF.
 * Run `composer install` first to install DOMPDF.
 *
 * Usage:
 *   require_once __DIR__ . '/pdf.php';
 *   renderPdf($html, 'Invoice-001.pdf');   // streams to browser
 *   $content = renderPdfToString($html);   // returns binary string
 */

function _loadDompdf(): void {
    static $loaded = false;
    if ($loaded) return;
    $autoload = dirname(__DIR__) . '/vendor/autoload.php';
    if (!file_exists($autoload)) {
        throw new RuntimeException(
            'DOMPDF not installed. Run: composer install  (from the project root)'
        );
    }
    require_once $autoload;
    $loaded = true;
}

/**
 * Stream a PDF to the browser for download or inline display.
 *
 * @param string $html       Full HTML document string
 * @param string $filename   Downloaded filename (e.g. "Invoice-001.pdf")
 * @param bool   $inline     true = open in browser, false = force download
 */
function renderPdf(string $html, string $filename = 'document.pdf', bool $inline = true): void {
    _loadDompdf();

    $options = new \Dompdf\Options();
    $options->set('isHtml5ParserEnabled', true);
    $options->set('isPhpEnabled', false);
    $options->set('isRemoteEnabled', false);  // disable remote resources for security
    $options->set('defaultFont', 'DejaVu Sans');
    $options->set('chroot', realpath(dirname(__DIR__)));

    $dompdf = new \Dompdf\Dompdf($options);
    $dompdf->loadHtml($html, 'UTF-8');
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();

    $disposition = $inline ? 'inline' : 'attachment';
    $dompdf->stream($filename, ['Attachment' => !$inline]);
    exit;
}

/**
 * Render HTML to PDF and return the binary PDF string (for saving or emailing).
 */
function renderPdfToString(string $html): string {
    _loadDompdf();

    $options = new \Dompdf\Options();
    $options->set('isHtml5ParserEnabled', true);
    $options->set('isPhpEnabled', false);
    $options->set('isRemoteEnabled', false);
    $options->set('defaultFont', 'DejaVu Sans');

    $dompdf = new \Dompdf\Dompdf($options);
    $dompdf->loadHtml($html, 'UTF-8');
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();

    return $dompdf->output();
}

/**
 * Build the HTML for an invoice PDF from DB data.
 * Returns a complete HTML string ready to pass to renderPdf().
 */
function buildInvoiceHtml(array $inv, array $items, array $company): string {
    $balance = (float)$inv['total'] - (float)$inv['amount_paid'];
    ob_start();
    ?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<style>
* { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: 'DejaVu Sans', Arial, sans-serif; font-size: 12px; color: #1e293b; background: #fff; }
.header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 24px; padding-bottom: 16px; border-bottom: 2px solid #16a34a; }
.company-name { font-size: 20px; font-weight: 700; color: #0f172a; margin-bottom: 4px; }
.company-info { font-size: 11px; color: #64748b; line-height: 1.6; }
.inv-title { font-size: 26px; font-weight: 700; color: #16a34a; text-align: right; }
.inv-meta { text-align: right; font-size: 11px; color: #64748b; line-height: 1.8; }
.bill-section { display: flex; justify-content: space-between; margin-bottom: 20px; }
.bill-box { width: 48%; }
.bill-label { font-size: 10px; font-weight: 700; text-transform: uppercase; color: #94a3b8; letter-spacing: 0.5px; margin-bottom: 6px; }
.bill-name { font-size: 14px; font-weight: 600; color: #0f172a; }
.bill-detail { font-size: 11px; color: #64748b; line-height: 1.6; }
table.items { width: 100%; border-collapse: collapse; margin-bottom: 16px; }
table.items thead tr { background: #16a34a; color: #fff; }
table.items thead th { padding: 9px 10px; font-size: 11px; font-weight: 600; text-align: left; }
table.items tbody tr:nth-child(even) { background: #f8fafc; }
table.items tbody td { padding: 8px 10px; font-size: 11px; border-bottom: 1px solid #e2e8f0; }
.text-right { text-align: right; }
.text-center { text-align: center; }
.totals-table { width: 45%; margin-left: auto; border-collapse: collapse; }
.totals-table td { padding: 5px 10px; font-size: 12px; }
.totals-table tr.total-row td { background: #16a34a; color: #fff; font-weight: 700; font-size: 14px; }
.totals-table tr.balance-row td { color: <?= $balance > 0 ? '#dc2626' : '#16a34a' ?>; font-weight: 700; }
.footer-section { display: flex; justify-content: space-between; margin-top: 24px; }
.payment-box, .terms-box { width: 48%; font-size: 10.5px; }
.payment-box h4, .terms-box h4 { font-size: 11px; font-weight: 700; text-transform: uppercase; color: #64748b; margin-bottom: 8px; border-bottom: 1px solid #e2e8f0; padding-bottom: 4px; }
.payment-row { display: flex; margin-bottom: 3px; }
.payment-row .lbl { width: 40%; color: #94a3b8; }
.payment-row .val { font-weight: 600; color: #1e293b; }
.sig-section { display: flex; justify-content: space-between; margin-top: 40px; }
.sig-box { width: 45%; }
.sig-line { border-top: 1px dashed #94a3b8; padding-top: 6px; font-size: 10px; color: #94a3b8; margin-top: 30px; }
.page-footer { text-align: center; font-size: 10px; color: #94a3b8; border-top: 1px solid #e2e8f0; padding-top: 12px; margin-top: 24px; }
.words { font-size: 11px; font-style: italic; color: #2563eb; margin-bottom: 12px; }
</style>
</head>
<body>

<div class="header">
    <div>
        <div class="company-name"><?= htmlspecialchars($company['name']) ?></div>
        <div class="company-info">
            <?= htmlspecialchars($company['address']) ?><br>
            <?php if ($company['phone']): ?>Tel: <?= htmlspecialchars($company['phone']) ?><br><?php endif; ?>
            <?php if ($company['email']): ?>Email: <?= htmlspecialchars($company['email']) ?><br><?php endif; ?>
            <?php if ($company['pin']): ?>KRA PIN: <?= htmlspecialchars($company['pin']) ?><?php endif; ?>
        </div>
    </div>
    <div>
        <div class="inv-title">TAX INVOICE</div>
        <div class="inv-meta">
            <strong><?= htmlspecialchars($inv['invoice_number']) ?></strong><br>
            Date: <?= date('d M Y', strtotime($inv['date'])) ?><br>
            Due: <?= $inv['due_date'] ? date('d M Y', strtotime($inv['due_date'])) : '—' ?><br>
            Status: <?= ucfirst($inv['status']) ?>
        </div>
    </div>
</div>

<div class="bill-section">
    <div class="bill-box">
        <div class="bill-label">Bill To</div>
        <div class="bill-name"><?= htmlspecialchars($inv['customer_name'] ?? '—') ?></div>
        <div class="bill-detail">
            <?php if ($inv['customer_phone'] ?? ''): ?><?= htmlspecialchars($inv['customer_phone']) ?><?php endif; ?>
        </div>
    </div>
    <div class="bill-box" style="text-align:right">
        <div class="bill-label">Vehicle</div>
        <div class="bill-name"><?= htmlspecialchars($inv['make'] . ' ' . $inv['model'] . ' (' . $inv['year'] . ')') ?></div>
        <div class="bill-detail">
            Chassis: <?= htmlspecialchars($inv['chassis_number']) ?><br>
            <?php if ($inv['registration_number'] ?? ''): ?>Reg: <?= htmlspecialchars($inv['registration_number']) ?><?php endif; ?>
        </div>
    </div>
</div>

<table class="items">
    <thead>
        <tr>
            <th style="width:4%">#</th>
            <th style="width:12%">Type</th>
            <th>Description</th>
            <th class="text-center" style="width:8%">Qty</th>
            <th class="text-right" style="width:15%">Unit Price (KES)</th>
            <th class="text-right" style="width:15%">Total (KES)</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($items as $i => $item): ?>
        <tr>
            <td><?= $i + 1 ?></td>
            <td><?= ucfirst(htmlspecialchars($item['item_type'])) ?></td>
            <td><?= htmlspecialchars($item['description']) ?></td>
            <td class="text-center"><?= $item['quantity'] ?></td>
            <td class="text-right"><?= number_format((float)$item['unit_price'], 2) ?></td>
            <td class="text-right"><strong><?= number_format((float)$item['total'], 2) ?></strong></td>
        </tr>
        <?php endforeach; ?>
    </tbody>
</table>

<table class="totals-table">
    <tr><td>Subtotal</td><td class="text-right">KES <?= number_format((float)$inv['subtotal'], 2) ?></td></tr>
    <?php if ((float)$inv['discount'] > 0): ?>
    <tr><td>Discount</td><td class="text-right" style="color:#dc2626">-KES <?= number_format((float)$inv['discount'], 2) ?></td></tr>
    <?php endif; ?>
    <tr><td>VAT (<?= $inv['tax_rate'] ?>%)</td><td class="text-right">KES <?= number_format((float)$inv['tax_amount'], 2) ?></td></tr>
    <tr class="total-row"><td><strong>TOTAL</strong></td><td class="text-right"><strong>KES <?= number_format((float)$inv['total'], 2) ?></strong></td></tr>
    <tr><td style="color:#16a34a">Amount Paid</td><td class="text-right" style="color:#16a34a">KES <?= number_format((float)$inv['amount_paid'], 2) ?></td></tr>
    <tr class="balance-row"><td><strong>Balance Due</strong></td><td class="text-right"><strong>KES <?= number_format($balance, 2) ?></strong></td></tr>
</table>

<div class="words">In words: <?= numberToWords((float)$inv['total']) ?></div>

<div class="footer-section">
    <div class="payment-box">
        <h4>Payment Details</h4>
        <div class="payment-row"><span class="lbl">A/C Holder:</span><span class="val">Mascardi Ventures Limited</span></div>
        <div class="payment-row"><span class="lbl">Bank:</span><span class="val">DTB Bank</span></div>
        <div class="payment-row"><span class="lbl">A/C No:</span><span class="val">0581403001</span></div>
        <div class="payment-row"><span class="lbl">Branch Code:</span><span class="val">Lavington &amp; 63</span></div>
        <div class="payment-row"><span class="lbl">SWIFT:</span><span class="val">DTKEKENA</span></div>
    </div>
    <div class="terms-box">
        <h4>Terms &amp; Conditions</h4>
        <ol style="padding-left:16px;line-height:1.8">
            <li>All payments to be made to the official Mascardi account listed on this invoice only.</li>
            <li>Payment confirms acceptance of all terms and conditions.</li>
        </ol>
        <?php if ($inv['notes'] ?? ''): ?>
        <div style="margin-top:8px"><strong>Notes:</strong> <?= htmlspecialchars($inv['notes']) ?></div>
        <?php endif; ?>
    </div>
</div>

<div class="sig-section">
    <div class="sig-box">
        <div class="sig-line">Prepared by &amp; Signature</div>
    </div>
    <div class="sig-box">
        <div class="sig-line">Approved by &amp; Signature</div>
    </div>
</div>

<div class="page-footer">
    <?= htmlspecialchars($company['name']) ?> &bull; <?= htmlspecialchars($company['phone']) ?> &bull; <?= htmlspecialchars($company['email']) ?><br>
    Generated on <?= date('d M Y H:i') ?>
</div>

</body>
</html>
    <?php
    return ob_get_clean();
}

/**
 * Build the HTML for a quotation PDF.
 */
function buildQuotationHtml(array $qt, array $items, array $company): string {
    ob_start();
    ?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<style>
* { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: 'DejaVu Sans', Arial, sans-serif; font-size: 12px; color: #1e293b; background: #fff; }
.header { display: flex; justify-content: space-between; margin-bottom: 24px; padding-bottom: 16px; border-bottom: 2px solid #2563eb; }
.company-name { font-size: 20px; font-weight: 700; color: #0f172a; margin-bottom: 4px; }
.company-info { font-size: 11px; color: #64748b; line-height: 1.6; }
.qt-title { font-size: 26px; font-weight: 700; color: #2563eb; text-align: right; }
.qt-meta { text-align: right; font-size: 11px; color: #64748b; line-height: 1.8; }
.bill-section { display: flex; justify-content: space-between; margin-bottom: 20px; }
.bill-box { width: 48%; }
.bill-label { font-size: 10px; font-weight: 700; text-transform: uppercase; color: #94a3b8; margin-bottom: 6px; }
.bill-name { font-size: 14px; font-weight: 600; }
.bill-detail { font-size: 11px; color: #64748b; line-height: 1.6; }
table.items { width: 100%; border-collapse: collapse; margin-bottom: 16px; }
table.items thead tr { background: #2563eb; color: #fff; }
table.items thead th { padding: 9px 10px; font-size: 11px; font-weight: 600; }
table.items tbody tr:nth-child(even) { background: #f8fafc; }
table.items tbody td { padding: 8px 10px; font-size: 11px; border-bottom: 1px solid #e2e8f0; }
.text-right { text-align: right; }
.text-center { text-align: center; }
.totals-table { width: 45%; margin-left: auto; border-collapse: collapse; }
.totals-table td { padding: 5px 10px; font-size: 12px; }
.totals-table tr.total-row td { background: #2563eb; color: #fff; font-weight: 700; font-size: 14px; }
.validity { background: #fef3c7; border: 1px solid #fbbf24; border-radius: 6px; padding: 8px 12px; font-size: 11px; margin-top: 16px; }
.page-footer { text-align: center; font-size: 10px; color: #94a3b8; border-top: 1px solid #e2e8f0; padding-top: 12px; margin-top: 24px; }
</style>
</head>
<body>

<div class="header">
    <div>
        <div class="company-name"><?= htmlspecialchars($company['name']) ?></div>
        <div class="company-info">
            <?= htmlspecialchars($company['address']) ?><br>
            <?php if ($company['phone']): ?>Tel: <?= htmlspecialchars($company['phone']) ?><br><?php endif; ?>
            <?php if ($company['email']): ?>Email: <?= htmlspecialchars($company['email']) ?><br><?php endif; ?>
            <?php if ($company['pin']): ?>KRA PIN: <?= htmlspecialchars($company['pin']) ?><?php endif; ?>
        </div>
    </div>
    <div>
        <div class="qt-title">QUOTATION</div>
        <div class="qt-meta">
            <strong><?= htmlspecialchars($qt['quotation_number']) ?></strong><br>
            Date: <?= date('d M Y', strtotime($qt['date'])) ?><br>
            <?php if ($qt['valid_until'] ?? ''): ?>Valid Until: <?= date('d M Y', strtotime($qt['valid_until'])) ?><br><?php endif; ?>
            Status: <?= ucfirst($qt['status']) ?>
        </div>
    </div>
</div>

<div class="bill-section">
    <div class="bill-box">
        <div class="bill-label">Prepared For</div>
        <div class="bill-name"><?= htmlspecialchars($qt['customer_name'] ?? '—') ?></div>
        <div class="bill-detail">
            <?php if ($qt['customer_phone'] ?? ''): ?><?= htmlspecialchars($qt['customer_phone']) ?><?php endif; ?>
        </div>
    </div>
    <div class="bill-box" style="text-align:right">
        <div class="bill-label">Vehicle</div>
        <div class="bill-name"><?= htmlspecialchars(($qt['make'] ?? '') . ' ' . ($qt['model'] ?? '') . (isset($qt['year']) ? ' (' . $qt['year'] . ')' : '')) ?></div>
        <div class="bill-detail">Chassis: <?= htmlspecialchars($qt['chassis_number'] ?? '—') ?></div>
    </div>
</div>

<table class="items">
    <thead>
        <tr>
            <th style="width:4%">#</th>
            <th style="width:12%">Type</th>
            <th>Description</th>
            <th class="text-center" style="width:8%">Qty</th>
            <th class="text-right" style="width:15%">Unit Price</th>
            <th class="text-right" style="width:10%">Disc%</th>
            <th class="text-right" style="width:15%">Total (KES)</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($items as $i => $item): ?>
        <tr>
            <td><?= $i + 1 ?></td>
            <td><?= ucfirst(htmlspecialchars($item['item_type'] ?? '')) ?></td>
            <td><?= htmlspecialchars($item['description']) ?></td>
            <td class="text-center"><?= $item['quantity'] ?></td>
            <td class="text-right"><?= number_format((float)$item['unit_price'], 2) ?></td>
            <td class="text-right"><?= number_format((float)($item['discount_pct'] ?? 0), 1) ?>%</td>
            <td class="text-right"><strong><?= number_format((float)$item['total'], 2) ?></strong></td>
        </tr>
        <?php endforeach; ?>
    </tbody>
</table>

<table class="totals-table">
    <tr><td>Subtotal</td><td class="text-right">KES <?= number_format((float)$qt['subtotal'], 2) ?></td></tr>
    <?php if ((float)($qt['discount'] ?? 0) > 0): ?>
    <tr><td>Discount</td><td class="text-right" style="color:#dc2626">-KES <?= number_format((float)$qt['discount'], 2) ?></td></tr>
    <?php endif; ?>
    <tr><td>VAT (<?= $qt['tax_rate'] ?? '16' ?>%)</td><td class="text-right">KES <?= number_format((float)$qt['tax_amount'], 2) ?></td></tr>
    <tr class="total-row"><td><strong>TOTAL</strong></td><td class="text-right"><strong>KES <?= number_format((float)$qt['total'], 2) ?></strong></td></tr>
</table>

<?php if ($qt['valid_until'] ?? ''): ?>
<div class="validity">
    <strong>Validity Notice:</strong> This quotation is valid until <?= date('d M Y', strtotime($qt['valid_until'])) ?>.
    Prices are subject to change after this date.
</div>
<?php endif; ?>

<?php if ($qt['notes'] ?? ''): ?>
<div style="margin-top:12px;font-size:11px"><strong>Notes:</strong> <?= htmlspecialchars($qt['notes']) ?></div>
<?php endif; ?>

<div class="page-footer">
    <?= htmlspecialchars($company['name']) ?> &bull; <?= htmlspecialchars($company['phone']) ?> &bull; <?= htmlspecialchars($company['email']) ?><br>
    Generated on <?= date('d M Y H:i') ?>
</div>

</body>
</html>
    <?php
    return ob_get_clean();
}

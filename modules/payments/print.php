<?php
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
canAccess('payments') || die('Access denied.');

$id = (int)($_GET['id'] ?? 0);
if (!$id) redirect(BASE_URL . '/modules/payments/index.php');

$db = getDB();
$stmt = $db->prepare("
    SELECT p.*,
           i.invoice_number, i.id AS inv_id, i.total AS inv_total,
           sb.booking_number, sb.id AS bk_id,
           cl.id_number AS client_id_number
    FROM payments p
    LEFT JOIN invoices i ON i.id = p.invoice_id
    LEFT JOIN service_bookings sb ON sb.id = p.service_booking_id
    LEFT JOIN clients cl ON cl.id = p.client_id
    WHERE p.id = ?
");
$stmt->execute([$id]);
$p = $stmt->fetch();
if (!$p) die('Payment not found.');

$company = [
    'name'    => getSetting('company_name',    'Mascardi Car Yard'),
    'address' => getSetting('company_address', 'Nairobi, Kenya'),
    'phone'   => getSetting('company_phone',   ''),
    'email'   => getSetting('company_email',   ''),
    'pin'     => getSetting('company_pin',     ''),
];

$methodMeta = [
    'mpesa'  => ['M-Pesa',        '#16a34a', 'fa-mobile-screen'],
    'bank'   => ['Bank Transfer', '#2563eb', 'fa-building-columns'],
    'cheque' => ['Cheque',        '#d97706', 'fa-money-check'],
    'cash'   => ['Cash',          '#0f172a', 'fa-money-bill-wave'],
];
[$mlabel, $mcolor, $micon] = $methodMeta[$p['payment_method']] ?? [$p['payment_method'], '#64748b', 'fa-circle'];

$isConfirmed = $p['status'] === 'confirmed';
$isReversed  = $p['status'] === 'reversed';
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Receipt — <?= e($p['payment_number']) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
    <style>
        * { box-sizing: border-box; }
        body {
            background: #f1f5f9;
            font-family: 'Inter', system-ui, -apple-system, sans-serif;
            font-size: 13px;
            color: #0f172a;
            margin: 0;
        }

        .receipt-wrapper {
            max-width: 680px;
            margin: 30px auto;
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 4px 24px rgba(0,0,0,.10);
            overflow: hidden;
        }

        /* Top accent bar */
        .receipt-accent { height: 6px; background: linear-gradient(90deg,#2563eb,#0ea5e9); }

        .receipt-body { padding: 40px 44px; }

        /* Header */
        .company-name { font-size: 20px; font-weight: 800; color: #0f172a; letter-spacing: -.4px; }
        .doc-label    { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 1px; color: #64748b; }

        /* Amount display */
        .amount-block {
            background: linear-gradient(135deg,#1e40af,#2563eb);
            border-radius: 10px;
            padding: 22px 28px;
            color: #fff;
            margin: 24px 0;
        }
        .amount-label { font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: .8px; opacity: .8; }
        .amount-value { font-size: 36px; font-weight: 800; letter-spacing: -1px; line-height: 1.1; }

        /* Info rows */
        .info-row { display: flex; justify-content: space-between; padding: 9px 0; border-bottom: 1px solid #f1f5f9; font-size: 13px; }
        .info-row:last-child { border-bottom: none; }
        .info-row .label { color: #64748b; font-weight: 500; }
        .info-row .value { font-weight: 600; color: #0f172a; text-align: right; max-width: 60%; }

        /* Section heading */
        .section-title {
            font-size: 10px; font-weight: 700; text-transform: uppercase;
            letter-spacing: .8px; color: #94a3b8;
            margin-bottom: 10px; margin-top: 24px;
        }

        /* Status stamp */
        .status-stamp {
            display: inline-block;
            border: 3px solid;
            border-radius: 6px;
            padding: 4px 14px;
            font-size: 14px; font-weight: 800;
            text-transform: uppercase; letter-spacing: 2px;
            transform: rotate(-4deg);
            opacity: .85;
        }

        /* Divider */
        .dashed-divider {
            border: none;
            border-top: 2px dashed #e2e8f0;
            margin: 20px 0;
        }

        /* Footer */
        .receipt-footer {
            background: #f8fafc;
            border-top: 1px solid #e2e8f0;
            padding: 18px 44px;
            text-align: center;
            color: #64748b;
            font-size: 11.5px;
        }

        /* Signature area */
        .sig-area {
            border-top: 1px dashed #cbd5e1;
            padding-top: 8px;
            text-align: center;
            font-size: 11px;
            color: #64748b;
        }

        /* Toolbar */
        .no-print-bar {
            position: sticky; top: 0; z-index: 100;
            background: #1e293b; padding: 11px 24px;
            display: flex; align-items: center; gap: 12px;
        }

        @media print {
            body  { background: #fff; }
            .receipt-wrapper { box-shadow: none; margin: 0; border-radius: 0; max-width: 100%; }
            .no-print-bar    { display: none !important; }
            @page { margin: 10mm 12mm; size: A5; }
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
    <span class="ms-auto text-white-50 small"><?= e($p['payment_number']) ?></span>
</div>

<div class="receipt-wrapper">
    <div class="receipt-accent"></div>
    <div class="receipt-body">

        <!-- Header: company left, receipt info right -->
        <div class="d-flex justify-content-between align-items-start">
            <div>
                <?php $__logo = getSetting('company_logo', ''); ?>
                <?php if ($__logo && file_exists(BASE_PATH . '/assets/images/' . $__logo)): ?>
                <img src="<?= BASE_URL ?>/assets/images/<?= e($__logo) ?>"
                     alt="<?= e($company['name']) ?>"
                     style="height:40px;max-width:150px;object-fit:contain;margin-bottom:4px;display:block">
                <?php else: ?>
                <div class="company-name"><?= e($company['name']) ?></div>
                <?php endif; ?>
                <div style="color:#64748b;font-size:11.5px;line-height:1.7;margin-top:3px">
                    <?php if ($company['address']): ?><?= e($company['address']) ?><br><?php endif; ?>
                    <?php if ($company['phone']): ?>Tel: <?= e($company['phone']) ?><br><?php endif; ?>
                    <?php if ($company['email']): ?><?= e($company['email']) ?><?php endif; ?>
                    <?php if ($company['pin']): ?><br>KRA PIN: <?= e($company['pin']) ?><?php endif; ?>
                </div>
            </div>
            <div class="text-end">
                <div class="doc-label">Payment Receipt</div>
                <div style="font-size:18px;font-weight:800;color:#0f172a;margin-top:2px"><?= e($p['payment_number']) ?></div>
                <div style="font-size:12px;color:#64748b;margin-top:4px">
                    <?= fmtDate($p['payment_date'], 'd F Y') ?>
                </div>
                <?php if ($isConfirmed || $isReversed): ?>
                <div class="mt-2">
                    <span class="status-stamp" style="color:<?= $isReversed ? '#dc2626' : '#16a34a' ?>;border-color:<?= $isReversed ? '#dc2626' : '#16a34a' ?>">
                        <?= $isReversed ? 'Reversed' : 'Confirmed' ?>
                    </span>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Amount -->
        <div class="amount-block <?= $isReversed ? 'opacity-50' : '' ?>">
            <div class="amount-label">Amount Paid</div>
            <div class="amount-value">KES <?= number_format((float)$p['amount'], 2) ?></div>
            <div style="font-size:12px;opacity:.85;margin-top:6px">
                <i class="fa <?= $micon ?> me-1"></i><?= $mlabel ?>
                <?php if ($p['reference_number']): ?>
                &nbsp;·&nbsp; Ref: <strong><?= e(strtoupper($p['reference_number'])) ?></strong>
                <?php endif; ?>
            </div>
        </div>

        <!-- Client -->
        <div class="section-title">Received From</div>
        <div class="info-row">
            <span class="label">Name</span>
            <span class="value"><?= e($p['client_name'] ?: 'Walk-in') ?></span>
        </div>
        <?php if ($p['client_phone']): ?>
        <div class="info-row">
            <span class="label">Phone</span>
            <span class="value"><?= e($p['client_phone']) ?></span>
        </div>
        <?php endif; ?>
        <?php if ($p['client_id_number']): ?>
        <div class="info-row">
            <span class="label">KRA PIN</span>
            <span class="value"><?= e($p['client_id_number']) ?></span>
        </div>
        <?php endif; ?>

        <!-- Payment for -->
        <div class="section-title">Payment For</div>
        <?php if ($p['inv_id']): ?>
        <div class="info-row">
            <span class="label">Invoice</span>
            <span class="value"><?= e($p['invoice_number']) ?></span>
        </div>
        <?php if ($p['inv_total']): ?>
        <div class="info-row">
            <span class="label">Invoice Total</span>
            <span class="value">KES <?= number_format((float)$p['inv_total'], 2) ?></span>
        </div>
        <?php endif; ?>
        <?php elseif ($p['bk_id']): ?>
        <div class="info-row">
            <span class="label">Service Booking</span>
            <span class="value"><?= e($p['booking_number']) ?></span>
        </div>
        <?php elseif ($p['description']): ?>
        <div class="info-row">
            <span class="label">Description</span>
            <span class="value"><?= e($p['description']) ?></span>
        </div>
        <?php else: ?>
        <div class="info-row">
            <span class="label">Description</span>
            <span class="value text-muted">General payment</span>
        </div>
        <?php endif; ?>

        <!-- Method-specific details -->
        <?php if ($p['mpesa_phone'] || $p['mpesa_name'] || $p['bank_name'] || $p['account_number'] || $p['cheque_number']): ?>
        <div class="section-title">Transaction Details</div>
        <?php if ($p['mpesa_phone']): ?>
        <div class="info-row">
            <span class="label">Sender Phone</span>
            <span class="value"><?= e($p['mpesa_phone']) ?></span>
        </div>
        <?php endif; ?>
        <?php if ($p['mpesa_name']): ?>
        <div class="info-row">
            <span class="label">M-Pesa Name</span>
            <span class="value"><?= e($p['mpesa_name']) ?></span>
        </div>
        <?php endif; ?>
        <?php if ($p['bank_name']): ?>
        <div class="info-row">
            <span class="label">Bank</span>
            <span class="value"><?= e($p['bank_name']) ?></span>
        </div>
        <?php endif; ?>
        <?php if ($p['account_number']): ?>
        <div class="info-row">
            <span class="label">Account No.</span>
            <span class="value"><?= e($p['account_number']) ?></span>
        </div>
        <?php endif; ?>
        <?php if ($p['cheque_number']): ?>
        <div class="info-row">
            <span class="label">Cheque No.</span>
            <span class="value"><?= e($p['cheque_number']) ?></span>
        </div>
        <?php endif; ?>
        <?php if ($p['cheque_date']): ?>
        <div class="info-row">
            <span class="label">Cheque Date</span>
            <span class="value"><?= fmtDate($p['cheque_date']) ?></span>
        </div>
        <?php endif; ?>
        <?php endif; ?>

        <?php if ($p['confirmed_by']): ?>
        <div class="section-title">Confirmation</div>
        <div class="info-row">
            <span class="label">Confirmed By</span>
            <span class="value"><?= e($p['confirmed_by']) ?></span>
        </div>
        <div class="info-row">
            <span class="label">Confirmed On</span>
            <span class="value"><?= fmtDate($p['confirmed_at'], 'd M Y, H:i') ?></span>
        </div>
        <?php endif; ?>

        <?php if ($p['notes']): ?>
        <div class="section-title">Notes</div>
        <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:10px 14px;font-size:12.5px;color:#475569;line-height:1.6">
            <?= e($p['notes']) ?>
        </div>
        <?php endif; ?>

        <?php if ($p['reversal_reason']): ?>
        <div class="section-title" style="color:#dc2626">Reversal Note</div>
        <div style="background:#fef2f2;border:1px solid #fecaca;border-radius:8px;padding:10px 14px;font-size:12.5px;color:#dc2626">
            <?= e($p['reversal_reason']) ?>
        </div>
        <?php endif; ?>

        <!-- Signature -->
        <hr class="dashed-divider">
        <div class="row g-4 mt-1">
            <div class="col-6">
                <div style="height:38px"></div>
                <div class="sig-area">
                    <div style="font-weight:700;font-size:11px;text-transform:uppercase;letter-spacing:.4px">Received By</div>
                    <div style="margin-top:2px;color:#94a3b8"><?= e($p['recorded_by'] ?? '—') ?></div>
                </div>
            </div>
            <div class="col-6">
                <div style="height:38px"></div>
                <div class="sig-area">
                    <div style="font-weight:700;font-size:11px;text-transform:uppercase;letter-spacing:.4px">Client Signature</div>
                    <div style="margin-top:2px;color:#94a3b8">Stamp &amp; Signature</div>
                </div>
            </div>
        </div>

    </div><!-- /receipt-body -->

    <div class="receipt-footer">
        <div style="font-size:14px;font-weight:700;color:#0f172a;margin-bottom:4px">
            Thank you for your payment!
        </div>
        <div>
            <?= e($company['name']) ?>
            <?php if ($company['phone']): ?> &bull; <?= e($company['phone']) ?><?php endif; ?>
            <?php if ($company['email']): ?> &bull; <?= e($company['email']) ?><?php endif; ?>
        </div>
        <div style="margin-top:4px;color:#94a3b8;font-size:10.5px">
            Printed: <?= date('d F Y, H:i') ?>
        </div>
    </div>
</div><!-- /receipt-wrapper -->

<?php if (!empty($_GET['new'])): ?>
<script>
// Auto-open print dialog when redirected from a new payment
window.addEventListener('load', function() {
    setTimeout(function() { window.print(); }, 600);
});
</script>
<?php endif; ?>
</body>
</html>

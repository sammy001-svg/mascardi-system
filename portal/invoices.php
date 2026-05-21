<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/portal_auth.php';
$pageTitle = 'Invoices & Payments';
$db     = getDB();
$client = portalClient();
$cid    = $client['id'];

// Financial summary
$finStmt = $db->prepare("
    SELECT COALESCE(SUM(total),0) AS total_billed,
           COALESCE(SUM(amount_paid),0) AS total_paid,
           SUM(CASE WHEN status='paid' THEN 1 ELSE 0 END) AS paid_count,
           SUM(CASE WHEN status IN ('unpaid','partial') THEN 1 ELSE 0 END) AS unpaid_count
    FROM invoices WHERE client_id=? AND status NOT IN ('cancelled')
");
$finStmt->execute([$cid]); $fin = $finStmt->fetch();
$outstanding = (float)$fin['total_billed'] - (float)$fin['total_paid'];

// Invoices
$invoices = $db->prepare("
    SELECT i.*, c.make, c.model, c.year, c.registration_number
    FROM invoices i LEFT JOIN cars c ON c.id=i.car_id
    WHERE i.client_id=? AND i.status NOT IN ('cancelled')
    ORDER BY i.created_at DESC
");
$invoices->execute([$cid]); $invoices = $invoices->fetchAll();

// Payment history
$payments = $db->prepare("
    SELECT p.*, i.invoice_number, sb.booking_number
    FROM payments p
    LEFT JOIN invoices i ON i.id = p.invoice_id
    LEFT JOIN service_bookings sb ON sb.id = p.service_booking_id
    WHERE (i.client_id = ? OR sb.client_id = ?) AND p.status = 'confirmed'
    ORDER BY p.payment_date DESC, p.id DESC
");
$payments->execute([$cid, $cid]); $payments = $payments->fetchAll();

include __DIR__ . '/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h5 class="fw-bold mb-1">Invoices &amp; Payments</h5>
        <div class="text-muted small">Your billing history and payment records</div>
    </div>
    <a href="<?= BASE_URL ?>/portal/statement.php" class="btn btn-sm btn-outline-dark no-print">
        <i class="fa fa-print me-1"></i>Account Statement
    </a>
</div>

<!-- Summary -->
<div class="row g-3 mb-4">
    <div class="col-6 col-lg-3">
        <div class="p-stat">
            <div class="p-stat-icon" style="background:#dbeafe;color:#2563eb"><i class="fa fa-file-invoice-dollar"></i></div>
            <div>
                <div class="p-stat-label">Total Billed</div>
                <div class="p-stat-value" style="font-size:16px"><?= money((float)$fin['total_billed']) ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="p-stat">
            <div class="p-stat-icon" style="background:#dcfce7;color:#16a34a"><i class="fa fa-money-bill-wave"></i></div>
            <div>
                <div class="p-stat-label">Total Paid</div>
                <div class="p-stat-value" style="font-size:16px"><?= money((float)$fin['total_paid']) ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="p-stat">
            <div class="p-stat-icon" style="background:<?= $outstanding > 0 ? '#fee2e2' : '#dcfce7' ?>;color:<?= $outstanding > 0 ? '#dc2626' : '#16a34a' ?>">
                <i class="fa fa-<?= $outstanding > 0 ? 'triangle-exclamation' : 'circle-check' ?>"></i>
            </div>
            <div>
                <div class="p-stat-label">Outstanding</div>
                <div class="p-stat-value" style="font-size:16px;color:<?= $outstanding > 0 ? '#dc2626' : '#16a34a' ?>"><?= money($outstanding) ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="p-stat">
            <div class="p-stat-icon" style="background:#f3e8ff;color:#7c3aed"><i class="fa fa-receipt"></i></div>
            <div>
                <div class="p-stat-label">Payments Made</div>
                <div class="p-stat-value"><?= count($payments) ?></div>
            </div>
        </div>
    </div>
</div>

<!-- Invoices Table -->
<div class="p-card mb-4">
    <div class="p-card-header"><i class="fa fa-file-invoice-dollar me-2 text-primary"></i>Invoices (<?= count($invoices) ?>)</div>
    <?php if (empty($invoices)): ?>
    <div class="p-card-body text-center py-5 text-muted"><i class="fa fa-file-invoice fa-2x mb-2 d-block"></i>No invoices yet.</div>
    <?php else: ?>
    <table class="table table-hover mb-0">
        <thead style="font-size:12px;color:#64748b;text-transform:uppercase;letter-spacing:.04em">
            <tr>
                <th class="ps-4 py-3">Invoice #</th>
                <th class="py-3">Date</th>
                <th class="py-3">Vehicle</th>
                <th class="py-3 text-end">Total</th>
                <th class="py-3 text-end">Paid</th>
                <th class="py-3 text-end pe-4">Balance</th>
                <th class="py-3">Status</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($invoices as $inv):
                $balance = (float)$inv['total'] - (float)$inv['amount_paid'];
            ?>
            <tr>
                <td class="ps-4 py-3 fw-semibold small"><?= e($inv['invoice_number']) ?></td>
                <td class="py-3 small text-muted"><?= fmtDate($inv['date'] ?: $inv['created_at']) ?></td>
                <td class="py-3 small"><?= $inv['make'] ? e($inv['make'].' '.$inv['model'].' '.$inv['year']) : e($inv['customer_name'] ?? '—') ?></td>
                <td class="py-3 text-end fw-semibold small"><?= money((float)$inv['total']) ?></td>
                <td class="py-3 text-end text-success small"><?= money((float)$inv['amount_paid']) ?></td>
                <td class="py-3 text-end pe-4 small <?= $balance > 0 ? 'text-danger fw-semibold' : 'text-muted' ?>"><?= money($balance) ?></td>
                <td class="py-3"><?= statusBadge($inv['status']) ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
        <tfoot style="background:#f8fafc">
            <tr>
                <td colspan="3" class="ps-4 py-2 fw-semibold small">Totals</td>
                <td class="py-2 text-end fw-bold small"><?= money((float)$fin['total_billed']) ?></td>
                <td class="py-2 text-end text-success fw-bold small"><?= money((float)$fin['total_paid']) ?></td>
                <td class="py-2 text-end pe-4 fw-bold small <?= $outstanding > 0 ? 'text-danger' : 'text-success' ?>"><?= money($outstanding) ?></td>
                <td></td>
            </tr>
        </tfoot>
    </table>
    <?php endif; ?>
</div>

<!-- Payment History -->
<div class="p-card">
    <div class="p-card-header"><i class="fa fa-money-bill-transfer me-2 text-success"></i>Payment History (<?= count($payments) ?>)</div>
    <?php if (empty($payments)): ?>
    <div class="p-card-body text-center py-5 text-muted"><i class="fa fa-receipt fa-2x mb-2 d-block"></i>No payments recorded yet.</div>
    <?php else: ?>
    <table class="table table-hover mb-0">
        <thead style="font-size:12px;color:#64748b;text-transform:uppercase;letter-spacing:.04em">
            <tr>
                <th class="ps-4 py-3">Date</th>
                <th class="py-3">Reference</th>
                <th class="py-3">Linked Document</th>
                <th class="py-3">Method</th>
                <th class="py-3 text-end pe-4">Amount</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($payments as $pay): ?>
            <tr>
                <td class="ps-4 py-3 small"><?= fmtDate($pay['payment_date']) ?></td>
                <td class="py-3 small text-muted"><?= e($pay['payment_number'] ?? '—') ?></td>
                <td class="py-3 small">
                    <?php if ($pay['invoice_number']): ?>
                    <span class="badge bg-light text-dark border"><i class="fa fa-file-invoice me-1 text-primary"></i><?= e($pay['invoice_number']) ?></span>
                    <?php elseif ($pay['booking_number']): ?>
                    <span class="badge bg-light text-dark border"><i class="fa fa-calendar me-1 text-success"></i><?= e($pay['booking_number']) ?></span>
                    <?php else: ?>—<?php endif; ?>
                </td>
                <td class="py-3 small"><?= e(ucfirst(str_replace('_', ' ', $pay['payment_method'] ?? 'cash'))) ?></td>
                <td class="py-3 text-end pe-4 fw-semibold text-success"><?= money((float)$pay['amount']) ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/footer.php'; ?>

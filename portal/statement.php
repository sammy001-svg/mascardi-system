<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/portal_auth.php';
$pageTitle = 'Account Statement';
$db     = getDB();
$client = portalClient();
$cid    = $client['id'];

$fromDate = $_GET['from'] ?? '';
$toDate   = $_GET['to']   ?? date('Y-m-d');

// Opening balance (sum before fromDate, if filtered)
$openingBalance = 0.0;
if ($fromDate) {
    $obStmt = $db->prepare("
        SELECT
            COALESCE(SUM(CASE WHEN source='invoice' THEN amount ELSE 0 END),0) -
            COALESCE(SUM(CASE WHEN source='payment' THEN amount ELSE 0 END),0) AS balance
        FROM (
            SELECT 'invoice' AS source, total AS amount FROM invoices
            WHERE client_id=? AND status NOT IN ('cancelled') AND DATE(created_at) < ?
            UNION ALL
            SELECT 'payment' AS source, p.amount FROM payments p
            LEFT JOIN invoices i ON i.id=p.invoice_id
            LEFT JOIN service_bookings sb ON sb.id=p.service_booking_id
            WHERE (i.client_id=? OR sb.client_id=?) AND p.status='confirmed' AND DATE(p.payment_date) < ?
        ) t
    ");
    $obStmt->execute([$cid, $fromDate, $cid, $cid, $fromDate]);
    $openingBalance = (float)($obStmt->fetchColumn() ?? 0);
}

// Transactions in period
$invSql  = "SELECT 'invoice' AS type, invoice_number AS ref, DATE(created_at) AS date, total AS amount, NULL AS credit, total AS debit, status FROM invoices WHERE client_id=? AND status NOT IN ('cancelled')" . ($fromDate ? " AND DATE(created_at) BETWEEN ? AND ?" : " AND DATE(created_at) <= ?");
$paySql  = "SELECT 'payment' AS type, payment_number AS ref, DATE(payment_date) AS date, amount, amount AS credit, NULL AS debit, 'confirmed' AS status FROM payments p LEFT JOIN invoices i ON i.id=p.invoice_id LEFT JOIN service_bookings sb ON sb.id=p.service_booking_id WHERE (i.client_id=? OR sb.client_id=?) AND p.status='confirmed'" . ($fromDate ? " AND DATE(p.payment_date) BETWEEN ? AND ?" : " AND DATE(p.payment_date) <= ?");

$txns = [];

if ($fromDate) {
    $i = $db->prepare($invSql); $i->execute([$cid, $fromDate, $toDate]); $txns = array_merge($txns, $i->fetchAll());
    $p = $db->prepare($paySql); $p->execute([$cid, $cid, $fromDate, $toDate]); $txns = array_merge($txns, $p->fetchAll());
} else {
    $i = $db->prepare($invSql); $i->execute([$cid, $toDate]); $txns = array_merge($txns, $i->fetchAll());
    $p = $db->prepare($paySql); $p->execute([$cid, $cid, $toDate]); $txns = array_merge($txns, $p->fetchAll());
}

usort($txns, fn($a, $b) => strcmp($a['date'], $b['date']));

// Running balance
$runningBalance = $openingBalance;
foreach ($txns as &$t) {
    if ($t['type'] === 'invoice') { $runningBalance += (float)$t['amount']; }
    else { $runningBalance -= (float)$t['amount']; }
    $t['running'] = $runningBalance;
}
unset($t);
$closingBalance = $runningBalance;

$periodBilled = array_sum(array_map(fn($t) => $t['type']==='invoice' ? (float)$t['amount'] : 0, $txns));
$periodPaid   = array_sum(array_map(fn($t) => $t['type']==='payment' ? (float)$t['amount'] : 0, $txns));

$company = getSetting('company_name', APP_NAME);

include __DIR__ . '/header.php';
?>

<!-- Filter bar -->
<div class="d-flex justify-content-between align-items-center mb-4 no-print">
    <div>
        <h5 class="fw-bold mb-1">Account Statement</h5>
        <div class="text-muted small">Filter by date range or print all transactions</div>
    </div>
    <div class="d-flex gap-2">
        <form class="d-flex align-items-center gap-2" method="GET">
            <input type="date" name="from" class="form-control form-control-sm" value="<?= e($fromDate) ?>" placeholder="From">
            <span class="text-muted small">to</span>
            <input type="date" name="to" class="form-control form-control-sm" value="<?= e($toDate) ?>">
            <button class="btn btn-sm btn-primary">Filter</button>
            <?php if ($fromDate): ?><a href="statement.php" class="btn btn-sm btn-outline-secondary">Clear</a><?php endif; ?>
        </form>
        <button onclick="window.print()" class="btn btn-sm btn-outline-dark">
            <i class="fa fa-print me-1"></i>Print / PDF
        </button>
    </div>
</div>

<!-- Print header -->
<div class="d-none d-print-block mb-4">
    <div class="d-flex justify-content-between align-items-start">
        <div>
            <h4 class="fw-bold mb-1"><?= e($company) ?></h4>
            <div class="text-muted small"><?= e(getSetting('company_address','')) ?></div>
            <div class="text-muted small"><?= e(getSetting('company_phone','')) ?> &bull; <?= e(getSetting('company_email','')) ?></div>
        </div>
        <div class="text-end">
            <h5 class="fw-bold text-uppercase" style="letter-spacing:.05em">Account Statement</h5>
            <div class="text-muted small">Printed: <?= date('d M Y') ?></div>
            <?php if ($fromDate): ?><div class="small">Period: <?= fmtDate($fromDate) ?> – <?= fmtDate($toDate) ?></div><?php endif; ?>
        </div>
    </div>
    <hr>
    <div><strong><?= e($client['name']) ?></strong> &mdash; <?= e($client['email']) ?></div>
</div>

<!-- Account summary -->
<div class="row g-3 mb-4">
    <div class="col-md-3">
        <div class="p-stat">
            <div class="p-stat-icon" style="background:#dbeafe;color:#2563eb"><i class="fa fa-arrow-trend-up"></i></div>
            <div><div class="p-stat-label">Total Billed</div><div class="p-stat-value" style="font-size:15px"><?= money($periodBilled) ?></div></div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="p-stat">
            <div class="p-stat-icon" style="background:#dcfce7;color:#16a34a"><i class="fa fa-arrow-trend-down"></i></div>
            <div><div class="p-stat-label">Total Paid</div><div class="p-stat-value" style="font-size:15px"><?= money($periodPaid) ?></div></div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="p-stat">
            <div class="p-stat-icon" style="background:#fff7ed;color:#ea580c"><i class="fa fa-scale-balanced"></i></div>
            <div><div class="p-stat-label">Opening Balance</div><div class="p-stat-value" style="font-size:15px"><?= money($openingBalance) ?></div></div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="p-stat" style="border-left:3px solid <?= $closingBalance > 0 ? '#dc2626' : '#16a34a' ?>">
            <div class="p-stat-icon" style="background:<?= $closingBalance > 0 ? '#fee2e2' : '#dcfce7' ?>;color:<?= $closingBalance > 0 ? '#dc2626' : '#16a34a' ?>"><i class="fa fa-money-check-dollar"></i></div>
            <div><div class="p-stat-label">Closing Balance</div><div class="p-stat-value" style="font-size:15px;color:<?= $closingBalance > 0 ? '#dc2626' : '#16a34a' ?>"><?= money($closingBalance) ?></div></div>
        </div>
    </div>
</div>

<!-- Transactions ledger -->
<div class="p-card">
    <div class="p-card-header"><i class="fa fa-list me-2"></i>Transaction Ledger<?= $fromDate ? ' (' . fmtDate($fromDate) . ' – ' . fmtDate($toDate) . ')' : '' ?></div>
    <table class="table mb-0">
        <thead style="font-size:12px;color:#64748b;text-transform:uppercase;letter-spacing:.04em">
            <tr>
                <th class="ps-4 py-3">Date</th>
                <th class="py-3">Reference</th>
                <th class="py-3">Type</th>
                <th class="py-3 text-end">Debit (Charged)</th>
                <th class="py-3 text-end">Credit (Paid)</th>
                <th class="py-3 text-end pe-4">Balance</th>
            </tr>
        </thead>
        <tbody>
            <?php if ($fromDate && $openingBalance != 0): ?>
            <tr style="background:#f8fafc">
                <td class="ps-4 py-2 small text-muted"><?= fmtDate($fromDate) ?></td>
                <td class="py-2 small text-muted" colspan="2"><em>Opening Balance</em></td>
                <td class="py-2 text-end small"></td>
                <td class="py-2 text-end small"></td>
                <td class="py-2 text-end pe-4 fw-semibold small"><?= money($openingBalance) ?></td>
            </tr>
            <?php endif; ?>
            <?php if (empty($txns)): ?>
            <tr><td colspan="6" class="text-center py-5 text-muted">No transactions in this period.</td></tr>
            <?php else: ?>
            <?php foreach ($txns as $t): ?>
            <tr style="border-left:3px solid <?= $t['type']==='invoice' ? '#dc2626' : '#16a34a' ?>">
                <td class="ps-4 py-2 small"><?= fmtDate($t['date']) ?></td>
                <td class="py-2 small fw-medium"><?= e($t['ref'] ?? '—') ?></td>
                <td class="py-2 small">
                    <?php if ($t['type']==='invoice'): ?>
                    <span class="badge bg-danger bg-opacity-10 text-danger">Invoice</span>
                    <?php else: ?>
                    <span class="badge bg-success bg-opacity-10 text-success">Payment</span>
                    <?php endif; ?>
                </td>
                <td class="py-2 text-end small <?= $t['type']==='invoice' ? 'text-danger fw-semibold' : 'text-muted' ?>"><?= $t['type']==='invoice' ? money((float)$t['amount']) : '' ?></td>
                <td class="py-2 text-end small <?= $t['type']==='payment' ? 'text-success fw-semibold' : 'text-muted' ?>"><?= $t['type']==='payment' ? money((float)$t['amount']) : '' ?></td>
                <td class="py-2 text-end pe-4 fw-semibold small <?= $t['running'] > 0 ? 'text-danger' : 'text-success' ?>"><?= money($t['running']) ?></td>
            </tr>
            <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
        <tfoot style="background:#f8fafc">
            <tr>
                <td colspan="3" class="ps-4 py-2 fw-bold small">Closing Balance</td>
                <td class="py-2 text-end fw-bold text-danger small"><?= money($periodBilled) ?></td>
                <td class="py-2 text-end fw-bold text-success small"><?= money($periodPaid) ?></td>
                <td class="py-2 text-end pe-4 fw-bold small <?= $closingBalance > 0 ? 'text-danger' : 'text-success' ?>"><?= money($closingBalance) ?></td>
            </tr>
        </tfoot>
    </table>
</div>

<!-- Print footer -->
<div class="d-none d-print-block mt-4 pt-3 border-top text-center text-muted small">
    <?php if ($closingBalance > 0): ?>
    <strong class="text-danger">Amount Due: <?= money($closingBalance) ?></strong>
    <?php else: ?>
    <strong class="text-success">Account Settled — Thank you!</strong>
    <?php endif; ?>
    <br>This statement was generated on <?= date('d M Y H:i') ?> for <?= e($client['name']) ?>
</div>

<?php include __DIR__ . '/footer.php'; ?>

<?php
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
canAccess('clients') || die('Access denied.');

$db     = getDB();
$id     = (int)($_GET['id'] ?? 0);
$from   = trim($_GET['from'] ?? '');
$to     = trim($_GET['to'] ?? '');

if (!$id) redirect(BASE_URL . '/modules/clients/index.php');

try { $db->exec("ALTER TABLE clients ADD COLUMN kra_pin VARCHAR(20) NULL AFTER id_number"); } catch (\Throwable $_) {}
$client = $db->prepare("SELECT * FROM clients WHERE id=?");
$client->execute([$id]); $client = $client->fetch();
if (!$client) { setFlash('error','Client not found.'); redirect(BASE_URL.'/modules/clients/index.php'); }

// Validate dates
$fromDate = $from ? date('Y-m-d', strtotime($from)) : null;
$toDate   = $to   ? date('Y-m-d', strtotime($to))   : null;
if ($fromDate === '1970-01-01') $fromDate = null;
if ($toDate   === '1970-01-01') $toDate   = null;

$company = [
    'name'    => getSetting('company_name',    'Mascardi Car Yard'),
    'address' => getSetting('company_address', 'Nairobi, Kenya'),
    'phone'   => getSetting('company_phone',   ''),
    'email'   => getSetting('company_email',   ''),
    'pin'     => getSetting('company_pin',     ''),
];

// Opening balance = invoices - payments BEFORE $fromDate (if a start date is set)
$openingBalance = 0.00;
if ($fromDate) {
    $obStmt = $db->prepare("
        SELECT COALESCE(SUM(total),0) AS billed, COALESCE(SUM(amount_paid),0) AS paid
        FROM invoices
        WHERE client_id=? AND status NOT IN ('cancelled') AND DATE(created_at) < ?
    ");
    $obStmt->execute([$id, $fromDate]);
    $ob = $obStmt->fetch();
    $openingBalance = (float)$ob['billed'] - (float)$ob['paid'];
}

// Invoices in range
$invSql = "SELECT i.*, c.make, c.model, c.chassis_number FROM invoices i JOIN cars c ON c.id=i.car_id WHERE i.client_id=? AND i.status NOT IN ('cancelled')";
$invParams = [$id];
if ($fromDate) { $invSql .= " AND DATE(i.created_at) >= ?"; $invParams[] = $fromDate; }
if ($toDate)   { $invSql .= " AND DATE(i.created_at) <= ?"; $invParams[] = $toDate; }
$invSql .= " ORDER BY i.created_at ASC";
$invStmt = $db->prepare($invSql);
$invStmt->execute($invParams);
$invoices = $invStmt->fetchAll();

// Payments in range (confirmed, linked to this client via client_id, invoice, or booking)
$paySql = "
    SELECT p.*, i.invoice_number, i.id AS inv_id, sb.booking_number
    FROM payments p
    LEFT JOIN invoices i ON i.id = p.invoice_id
    LEFT JOIN service_bookings sb ON sb.id = p.service_booking_id
    WHERE (p.client_id = ? OR i.client_id = ? OR sb.client_id = ?) AND p.status = 'confirmed'
";
$payParams = [$id, $id, $id];
if ($fromDate) { $paySql .= " AND DATE(p.payment_date) >= ?"; $payParams[] = $fromDate; }
if ($toDate)   { $paySql .= " AND DATE(p.payment_date) <= ?"; $payParams[] = $toDate; }
$paySql .= " ORDER BY p.payment_date ASC, p.id ASC";
$payStmt = $db->prepare($paySql);
$payStmt->execute($payParams);
$payments = $payStmt->fetchAll();

// All outstanding invoices for this client (regardless of date filter)
$outStmt = $db->prepare("
    SELECT i.*, c.make, c.model, c.chassis_number,
           (i.total - i.amount_paid) AS balance_due
    FROM invoices i
    JOIN cars c ON c.id = i.car_id
    WHERE i.client_id = ?
      AND i.status NOT IN ('paid', 'cancelled')
    ORDER BY i.date ASC
");
$outStmt->execute([$id]);
$outstandingInvoices = $outStmt->fetchAll();

// Merge transactions chronologically
$transactions = [];
foreach ($invoices as $inv) {
    $transactions[] = [
        'type'        => 'invoice',
        'date'        => $inv['created_at'],
        'ref'         => $inv['invoice_number'],
        'description' => $inv['make'] . ' ' . $inv['model'] . ' — ' . $inv['chassis_number'],
        'debit'       => (float)$inv['total'],
        'credit'      => 0.00,
        'link_id'     => $inv['id'],
        'status'      => $inv['status'],
    ];
}
foreach ($payments as $pay) {
    $desc = $pay['invoice_number'] ? 'Payment for ' . $pay['invoice_number']
          : ($pay['booking_number'] ? 'Booking ' . $pay['booking_number'] : ($pay['description'] ?? 'Payment'));
    $transactions[] = [
        'type'        => 'payment',
        'date'        => $pay['payment_date'],
        'ref'         => $pay['payment_number'],
        'description' => $desc,
        'debit'       => 0.00,
        'credit'      => (float)$pay['amount'],
        'link_id'     => $pay['inv_id'] ?? null,
        'method'      => $pay['payment_method'],
        'receipt'     => $pay['reference_number'] ?? null,
    ];
}
usort($transactions, fn($a, $b) => strcmp($a['date'], $b['date']));

// Calculate running balance
$runBalance = $openingBalance;
foreach ($transactions as &$tx) {
    $runBalance += $tx['debit'] - $tx['credit'];
    $tx['balance'] = $runBalance;
}
unset($tx);

$closingBalance = $runBalance;
$periodLabel = ($fromDate || $toDate)
    ? (($fromDate ? fmtDate($fromDate) : 'Beginning') . ' to ' . ($toDate ? fmtDate($toDate) : 'Today'))
    : 'All Time';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Client Statement — <?= e($client['name']) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
    <style>
        body { background: #f1f5f9; font-size: 13px; font-family: 'Segoe UI', Arial, sans-serif; }
        .stmt-wrapper { max-width: 820px; margin: 30px auto; background: #fff; padding: 40px 45px; box-shadow: 0 2px 12px rgba(0,0,0,.1); border-radius: 8px; }
        .company-name { font-size: 22px; font-weight: 800; color: #0f172a; }
        .stmt-title { font-size: 28px; font-weight: 700; color: #2563eb; letter-spacing: -0.5px; }
        .info-grid dt { color: #64748b; font-weight: 400; }
        .info-grid dd { font-weight: 600; }
        .balance-row { background: #eff6ff; }
        .balance-row td { font-weight: 700; border-top: 2px solid #2563eb !important; }
        .tx-invoice td:first-child { border-left: 3px solid #ef4444; }
        .tx-payment td:first-child { border-left: 3px solid #16a34a; }
        .summary-box { border-radius: 10px; }
        tfoot tr.total-row td { font-weight: 700; font-size: 14px; background: #2563eb; color: #fff; }
        @media print {
            body { background: #fff; }
            .no-print { display: none !important; }
            .stmt-wrapper { box-shadow: none; margin: 0; padding: 20px; }
        }
    </style>
</head>
<body>

<!-- Controls bar -->
<div class="no-print bg-white border-bottom py-3 px-4 d-flex justify-content-between align-items-center sticky-top shadow-sm">
    <div class="d-flex align-items-center gap-3">
        <strong>Client Statement</strong>
        <span class="text-muted small">— <?= e($client['name']) ?></span>
    </div>
    <div class="d-flex gap-2 align-items-center">
        <!-- Date range filter -->
        <form method="GET" class="d-flex align-items-center gap-2">
            <input type="hidden" name="id" value="<?= $id ?>">
            <input type="date" name="from" class="form-control form-control-sm" style="width:150px" value="<?= e($fromDate ?? '') ?>" placeholder="From">
            <span class="text-muted">to</span>
            <input type="date" name="to" class="form-control form-control-sm" style="width:150px" value="<?= e($toDate ?? '') ?>" placeholder="To">
            <button type="submit" class="btn btn-sm btn-outline-primary"><i class="fa fa-filter me-1"></i>Filter</button>
            <?php if ($fromDate || $toDate): ?>
            <a href="statement.php?id=<?= $id ?>" class="btn btn-sm btn-outline-secondary">Clear</a>
            <?php endif; ?>
        </form>
        <button onclick="window.print()" class="btn btn-sm btn-primary"><i class="fa fa-print me-1"></i>Print / PDF</button>
        <a href="view.php?id=<?= $id ?>" class="btn btn-sm btn-outline-secondary"><i class="fa fa-arrow-left me-1"></i>Back</a>
    </div>
</div>

<div class="stmt-wrapper">

    <!-- Header -->
    <div class="row mb-4">
        <div class="col-7">
            <div class="company-name"><?= e($company['name']) ?></div>
            <div class="text-muted" style="font-size:12px"><?= e($company['address']) ?></div>
            <?php if ($company['phone']): ?><div style="font-size:12px">Tel: <?= e($company['phone']) ?></div><?php endif; ?>
            <?php if ($company['email']): ?><div style="font-size:12px">Email: <?= e($company['email']) ?></div><?php endif; ?>
            <?php if ($company['pin']): ?><div style="font-size:12px">KRA PIN: <?= e($company['pin']) ?></div><?php endif; ?>
        </div>
        <div class="col-5 text-end">
            <div class="stmt-title">ACCOUNT STATEMENT</div>
            <div class="text-muted small mt-1">Period: <strong><?= $periodLabel ?></strong></div>
            <div class="text-muted small">Generated: <?= date('d M Y, H:i') ?></div>
        </div>
    </div>

    <hr style="border-color:#e2e8f0">

    <!-- Client info + Summary -->
    <div class="row mb-4 g-3">
        <div class="col-md-6">
            <div class="p-3 border rounded-3 h-100" style="background:#f8fafc">
                <div class="text-muted small fw-bold text-uppercase mb-2" style="letter-spacing:.05em">Bill To</div>
                <div class="fw-bold" style="font-size:16px"><?= e($client['name']) ?></div>
                <?php if ($client['email']): ?>
                <div class="text-muted small"><i class="fa fa-envelope me-1"></i><?= e($client['email']) ?></div>
                <?php endif; ?>
                <?php if ($client['phone']): ?>
                <div class="text-muted small"><i class="fa fa-phone me-1"></i><?= e($client['phone']) ?></div>
                <?php endif; ?>
                <?php $stmtKraPin = !empty($client['kra_pin']) ? $client['kra_pin'] : ($client['id_number'] ?? ''); ?>
                <?php if ($stmtKraPin): ?>
                <div class="text-muted small"><i class="fa fa-fingerprint me-1"></i>KRA PIN: <strong><?= e(strtoupper($stmtKraPin)) ?></strong></div>
                <?php endif; ?>
            </div>
        </div>
        <div class="col-md-6">
            <div class="p-3 border rounded-3 h-100" style="background:#f8fafc">
                <div class="text-muted small fw-bold text-uppercase mb-2" style="letter-spacing:.05em">Account Summary</div>
                <?php
                $periodBilled = array_sum(array_column(array_filter($transactions, fn($t) => $t['type']==='invoice'), 'debit'));
                $periodPaid   = array_sum(array_column(array_filter($transactions, fn($t) => $t['type']==='payment'), 'credit'));
                ?>
                <table class="table table-sm mb-0" style="font-size:13px">
                    <tr>
                        <td class="border-0 ps-0 text-muted"><?= $fromDate ? 'Invoiced (period)' : 'Total Invoiced' ?></td>
                        <td class="border-0 text-end fw-medium"><?= money($periodBilled) ?></td>
                    </tr>
                    <tr>
                        <td class="border-0 ps-0 text-muted"><?= $fromDate ? 'Paid (period)' : 'Total Paid' ?></td>
                        <td class="border-0 text-end fw-medium text-success"><?= money($periodPaid) ?></td>
                    </tr>
                    <?php if ($fromDate): ?>
                    <tr>
                        <td class="border-0 ps-0 text-muted">Opening Balance</td>
                        <td class="border-0 text-end fw-medium <?= $openingBalance > 0 ? 'text-danger' : 'text-success' ?>"><?= money($openingBalance) ?></td>
                    </tr>
                    <?php endif; ?>
                    <tr style="background:#eff6ff">
                        <td class="border-top ps-0 fw-bold">Closing Balance</td>
                        <td class="border-top text-end fw-bold <?= $closingBalance > 0 ? 'text-danger' : 'text-success' ?>" style="font-size:15px"><?= money($closingBalance) ?></td>
                    </tr>
                </table>
            </div>
        </div>
    </div>

    <!-- Outstanding / Pending Invoices -->
    <?php if ($outstandingInvoices): ?>
    <div class="mb-4 p-3 border border-warning rounded-3" style="background:#fffbeb">
        <div class="d-flex justify-content-between align-items-center mb-2">
            <div class="fw-bold" style="color:#92400e;font-size:13px">
                <i class="fa fa-triangle-exclamation me-2 text-warning"></i>Pending / Outstanding Invoices
            </div>
            <span class="badge bg-warning text-dark"><?= count($outstandingInvoices) ?> invoice<?= count($outstandingInvoices) !== 1 ? 's' : '' ?> outstanding</span>
        </div>
        <table class="table table-sm mb-0 bg-white rounded" style="font-size:12px">
            <thead style="background:#fef3c7">
                <tr>
                    <th class="ps-2">Invoice #</th>
                    <th>Invoice Date</th>
                    <th>Due Date</th>
                    <th>Vehicle</th>
                    <th class="text-end">Invoice Total</th>
                    <th class="text-end">Amount Paid</th>
                    <th class="text-end">Balance Due</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
            <?php
            $totalOutstanding = 0;
            foreach ($outstandingInvoices as $oi):
                $balDue = (float)$oi['balance_due'];
                $totalOutstanding += $balDue;
                $overdue = $oi['due_date'] && $oi['due_date'] < date('Y-m-d');
            ?>
            <tr <?= $overdue ? 'style="background:#fff5f5"' : '' ?>>
                <td class="ps-2 fw-medium"><?= e($oi['invoice_number']) ?></td>
                <td><?= fmtDate($oi['date']) ?></td>
                <td class="<?= $overdue ? 'text-danger fw-semibold' : '' ?>"><?= $oi['due_date'] ? fmtDate($oi['due_date']) : '—' ?><?= $overdue ? ' <span class="badge bg-danger ms-1" style="font-size:9px">Overdue</span>' : '' ?></td>
                <td><?= e($oi['make'] . ' ' . $oi['model']) ?><div class="text-muted" style="font-size:10px"><?= e($oi['chassis_number']) ?></div></td>
                <td class="text-end"><?= number_format((float)$oi['total'], 2) ?></td>
                <td class="text-end text-success"><?= number_format((float)$oi['amount_paid'], 2) ?></td>
                <td class="text-end fw-bold text-danger"><?= number_format($balDue, 2) ?></td>
                <td><?= statusBadge($oi['status']) ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr style="background:#fee2e2">
                    <td colspan="6" class="ps-2 fw-bold text-danger">Total Outstanding</td>
                    <td class="text-end fw-bold text-danger" style="font-size:13px"><?= number_format($totalOutstanding, 2) ?></td>
                    <td></td>
                </tr>
            </tfoot>
        </table>
    </div>
    <?php endif; ?>

    <!-- Transactions Table -->
    <table class="table table-bordered" style="font-size:12.5px">
        <thead style="background:#1e293b;color:#fff">
            <tr>
                <th class="ps-2" style="width:90px">Date</th>
                <th style="width:130px">Reference</th>
                <th>Description</th>
                <th class="text-end" style="width:110px">Debit (KES)</th>
                <th class="text-end" style="width:110px">Credit (KES)</th>
                <th class="text-end" style="width:120px">Balance (KES)</th>
            </tr>
        </thead>
        <tbody>
            <?php if ($fromDate): ?>
            <tr class="balance-row">
                <td class="ps-2 text-muted"><?= fmtDate($fromDate) ?></td>
                <td colspan="4" class="fw-semibold">Opening Balance</td>
                <td class="text-end fw-bold <?= $openingBalance > 0 ? 'text-danger' : 'text-success' ?>"><?= number_format($openingBalance, 2) ?></td>
            </tr>
            <?php endif; ?>

            <?php if (empty($transactions)): ?>
            <tr>
                <td colspan="6" class="text-center text-muted py-4">No transactions found for this period.</td>
            </tr>
            <?php endif; ?>

            <?php foreach ($transactions as $tx):
                if ($tx['type'] === 'invoice') {
                    $rowBg = match($tx['status'] ?? '') {
                        'overdue'  => 'style="background:#fff5f5"',
                        'partial'  => 'style="background:#fffbeb"',
                        default    => '',
                    };
                    $statusStyle = match($tx['status'] ?? '') {
                        'paid'    => 'background:#dcfce7;color:#166534',
                        'partial' => 'background:#fef3c7;color:#92400e',
                        'overdue' => 'background:#fee2e2;color:#dc2626',
                        'sent'    => 'background:#dbeafe;color:#1d4ed8',
                        default   => 'background:#f1f5f9;color:#475569',
                    };
                } else {
                    $rowBg = '';
                    $statusStyle = '';
                }
            ?>
            <tr class="<?= $tx['type'] === 'invoice' ? 'tx-invoice' : 'tx-payment' ?>" <?= $rowBg ?>>
                <td class="ps-2 text-muted"><?= fmtDate($tx['date']) ?></td>
                <td class="fw-medium" style="font-size:12px">
                    <?= e($tx['ref']) ?>
                    <?php if ($tx['type'] === 'payment' && !empty($tx['receipt'])): ?>
                    <div class="text-muted" style="font-size:10px"><?= e($tx['receipt']) ?></div>
                    <?php endif; ?>
                </td>
                <td>
                    <?= e($tx['description']) ?>
                    <?php if ($tx['type'] === 'invoice'): ?>
                    <span class="badge ms-1" style="font-size:10px;<?= $statusStyle ?>"><?= ucwords(str_replace('_',' ',$tx['status'])) ?></span>
                    <?php elseif ($tx['type'] === 'payment'): ?>
                    <span class="badge ms-1" style="font-size:10px;background:#dcfce7;color:#166534">
                        <?= ucfirst($tx['method'] ?? '') ?>
                    </span>
                    <?php endif; ?>
                </td>
                <td class="text-end <?= $tx['debit'] > 0 ? 'text-danger fw-medium' : 'text-muted' ?>">
                    <?= $tx['debit'] > 0 ? number_format($tx['debit'], 2) : '—' ?>
                </td>
                <td class="text-end <?= $tx['credit'] > 0 ? 'text-success fw-medium' : 'text-muted' ?>">
                    <?= $tx['credit'] > 0 ? number_format($tx['credit'], 2) : '—' ?>
                </td>
                <td class="text-end fw-semibold <?= $tx['balance'] > 0 ? 'text-danger' : ($tx['balance'] < 0 ? 'text-success' : '') ?>">
                    <?= number_format($tx['balance'], 2) ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr class="total-row">
                <td colspan="3" class="ps-2">CLOSING BALANCE</td>
                <td class="text-end"><?= number_format($periodBilled, 2) ?></td>
                <td class="text-end"><?= number_format($periodPaid, 2) ?></td>
                <td class="text-end" style="font-size:14px"><?= number_format($closingBalance, 2) ?></td>
            </tr>
        </tfoot>
    </table>

    <!-- Legend + Balance due -->
    <div class="row mt-3 g-3">
        <div class="col-md-6">
            <div class="d-flex gap-3 small text-muted">
                <span><span style="display:inline-block;width:12px;height:12px;background:#ef4444;border-radius:2px;margin-right:4px"></span>Invoice / Charge</span>
                <span><span style="display:inline-block;width:12px;height:12px;background:#16a34a;border-radius:2px;margin-right:4px"></span>Payment / Credit</span>
            </div>
            <?php if ($client['notes']): ?>
            <div class="text-muted small mt-2"><strong>Notes:</strong> <?= e($client['notes']) ?></div>
            <?php endif; ?>
        </div>
        <div class="col-md-6 text-end">
            <?php if ($closingBalance > 0): ?>
            <div class="p-3 border border-danger rounded-3" style="background:#fff5f5">
                <div class="text-danger small fw-bold text-uppercase" style="letter-spacing:.05em">Amount Due</div>
                <div style="font-size:22px;font-weight:800;color:#dc2626"><?= money($closingBalance) ?></div>
            </div>
            <?php else: ?>
            <div class="p-3 border border-success rounded-3" style="background:#f0fdf4">
                <div class="text-success small fw-bold text-uppercase" style="letter-spacing:.05em">Account Settled</div>
                <div style="font-size:22px;font-weight:800;color:#16a34a">No Balance Due</div>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Payment Receipts Summary -->
    <?php if ($payments): ?>
    <div class="mt-4">
        <div class="fw-bold mb-2" style="font-size:12px;text-transform:uppercase;color:#166534;letter-spacing:.05em">
            <i class="fa fa-receipt me-1"></i>Payment Receipts
        </div>
        <table class="table table-bordered" style="font-size:12px">
            <thead style="background:#166534;color:#fff">
                <tr>
                    <th class="ps-2" style="width:90px">Date</th>
                    <th style="width:130px">Receipt #</th>
                    <th>Description</th>
                    <th style="width:100px">Method</th>
                    <th class="text-end" style="width:130px">Amount (KES)</th>
                </tr>
            </thead>
            <tbody>
            <?php
            $methodLabel = ['mpesa'=>'M-Pesa','bank'=>'Bank','cheque'=>'Cheque','cash'=>'Cash'];
            foreach ($payments as $pay): ?>
            <tr class="tx-payment">
                <td class="ps-2 text-muted"><?= fmtDate($pay['payment_date']) ?></td>
                <td class="fw-medium"><?= e($pay['payment_number']) ?></td>
                <td>
                    <?= e($pay['invoice_number'] ? 'Payment for ' . $pay['invoice_number'] : ($pay['booking_number'] ? 'Booking ' . $pay['booking_number'] : ($pay['description'] ?? 'Payment'))) ?>
                    <?php if ($pay['reference_number']): ?>
                    <span class="text-muted" style="font-size:10.5px"> — Ref: <?= e($pay['reference_number']) ?></span>
                    <?php endif; ?>
                </td>
                <td><?= e($methodLabel[$pay['payment_method']] ?? $pay['payment_method']) ?></td>
                <td class="text-end fw-semibold text-success"><?= number_format((float)$pay['amount'], 2) ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr style="background:#dcfce7">
                    <td colspan="4" class="ps-2 fw-bold text-success">Total Receipts</td>
                    <td class="text-end fw-bold text-success" style="font-size:13px"><?= number_format($periodPaid, 2) ?></td>
                </tr>
            </tfoot>
        </table>
    </div>
    <?php endif; ?>

    <!-- Signature area -->
    <div class="row mt-5 g-4" style="font-size:11px;color:#334155">
        <div class="col-6">
            <div class="p-3 border rounded-3">
                <div class="mb-4">Prepared by: ________________________</div>
                <div style="border-top:1px dashed #cbd5e1;padding-top:4px" class="text-muted">Signature &amp; Date</div>
            </div>
        </div>
        <div class="col-6">
            <div class="p-3 border rounded-3">
                <div class="mb-4">Received by: ________________________</div>
                <div style="border-top:1px dashed #cbd5e1;padding-top:4px" class="text-muted">Signature &amp; Date</div>
            </div>
        </div>
    </div>

    <!-- Footer -->
    <div class="text-center mt-4 text-muted border-top pt-3" style="font-size:10px">
        <?= e($company['name']) ?>
        <?php if ($company['phone']): ?> &bull; <?= e($company['phone']) ?><?php endif; ?>
        <?php if ($company['email']): ?> &bull; <?= e($company['email']) ?><?php endif; ?>
        <br>This statement is computer-generated. For queries, please contact us.
    </div>

</div><!-- /stmt-wrapper -->

</body>
</html>

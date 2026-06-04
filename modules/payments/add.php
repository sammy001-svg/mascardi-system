<?php
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/notifications.php';
require_once __DIR__ . '/../../includes/mailer.php';
requireLogin();
canAccess('payments') || die('Access denied.');
canWrite('payments') || die('Permission denied.');
$pageTitle = 'Record Payment';
$db = getDB();
$errors = [];

// Pre-fill from invoice or booking link
$preInvoiceId  = (int)($_GET['invoice_id']  ?? 0);
$preBookingId  = (int)($_GET['booking_id']  ?? 0);
$preClientId   = (int)($_GET['client_id']   ?? 0);

$preInvoice = null;
if ($preInvoiceId) {
    $s = $db->prepare("SELECT i.*, c.name AS cname, c.phone AS cphone FROM invoices i LEFT JOIN clients c ON c.id=i.client_id WHERE i.id=?");
    $s->execute([$preInvoiceId]); $preInvoice = $s->fetch();
}
$preBooking = null;
if ($preBookingId) {
    $s = $db->prepare("SELECT * FROM service_bookings WHERE id=?");
    $s->execute([$preBookingId]); $preBooking = $s->fetch();
}

$clients  = $db->query("SELECT id, name, phone, email FROM clients WHERE status='active' ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$invoices = $db->query("
    SELECT i.id, i.invoice_number, i.customer_name, i.total, i.client_id,
           COALESCE(i.amount_paid, 0) AS amount_paid,
           (i.total - COALESCE(i.amount_paid, 0)) AS balance
    FROM invoices i
    WHERE i.status IN ('unpaid','partial')
    ORDER BY i.id DESC
")->fetchAll(PDO::FETCH_ASSOC);
$bookings = $db->query("
    SELECT sb.id, sb.booking_number, sb.client_name, sb.client_id,
           COALESCE(sb.client_phone, '') AS client_phone
    FROM service_bookings sb
    WHERE sb.status NOT IN ('completed','cancelled')
    ORDER BY sb.id DESC
    LIMIT 100
")->fetchAll(PDO::FETCH_ASSOC);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $clientId      = (int)($_POST['client_id'] ?? 0) ?: null;
    $clientName    = trim($_POST['client_name'] ?? '');
    $clientPhone   = trim($_POST['client_phone'] ?? '');
    $invoiceId     = (int)($_POST['invoice_id'] ?? 0) ?: null;
    $bookingId     = (int)($_POST['service_booking_id'] ?? 0) ?: null;
    $description   = trim($_POST['description'] ?? '');
    $amount        = (float)($_POST['amount'] ?? 0);
    $method = $_POST['payment_method'] ?? '';

    // Each method uses unique field names — no JS required to pick the right value
    switch ($method) {
        case 'mpesa':
            $ref           = strtoupper(trim($_POST['mpesa_code']    ?? ''));
            $mpesaPhone    = trim($_POST['mpesa_phone']   ?? '');
            $mpesaName     = trim($_POST['mpesa_name']    ?? '');
            $bankName      = '';
            $accountNumber = '';
            $chequeNumber  = '';
            $chequeDate    = null;
            break;
        case 'bank':
            $ref           = trim($_POST['bank_ref']       ?? '');
            $bankName      = trim($_POST['bank_name']      ?? '');
            $accountNumber = trim($_POST['account_number'] ?? '');
            $mpesaPhone    = '';
            $mpesaName     = '';
            $chequeNumber  = '';
            $chequeDate    = null;
            break;
        case 'cheque':
            $ref           = trim($_POST['cheque_ref']    ?? '');
            $chequeNumber  = trim($_POST['cheque_number'] ?? '');
            $bankName      = trim($_POST['cheque_bank']   ?? '');
            $chequeDate    = $_POST['cheque_date'] ?: null;
            $mpesaPhone    = '';
            $mpesaName     = '';
            $accountNumber = '';
            break;
        default: // cash
            $ref           = trim($_POST['cash_ref'] ?? '');
            $mpesaPhone    = '';
            $mpesaName     = '';
            $bankName      = '';
            $accountNumber = '';
            $chequeNumber  = '';
            $chequeDate    = null;
            break;
    }
    $payDate       = $_POST['payment_date'] ?? date('Y-m-d');
    $notes         = trim($_POST['notes'] ?? '');
    $balAdj        = (float)($_POST['balance_adjustment'] ?? 0);
    $recordedBy    = authUser()['name'] ?? 'System';

    if (!$clientName)  $errors[] = 'Client name is required.';
    if ($amount <= 0)  $errors[] = 'Amount must be greater than zero.';
    if (!in_array($method, ['mpesa','bank','cheque','cash'])) $errors[] = 'Select a payment method.';
    if ($method === 'mpesa'  && !$ref) $errors[] = 'M-Pesa transaction code is required.';
    if ($method === 'bank'   && !$ref) $errors[] = 'Bank reference is required.';
    if ($method === 'cheque' && !$chequeNumber) $errors[] = 'Cheque number is required.';

    if (empty($errors)) {
        try {
            // Cash is always received immediately — confirm on the spot.
            // M-Pesa/bank/cheque stay pending until manually verified.
            $initStatus      = ($method === 'cash') ? 'confirmed' : 'pending';
            $initConfirmedBy = ($method === 'cash') ? $recordedBy : null;
            $initConfirmedAt = ($method === 'cash') ? date('Y-m-d H:i:s') : null;

            $payNum = nextNumber('payments', 'payment_number', 'PAY');
            $db->prepare("
                INSERT INTO payments
                (payment_number, payment_date, client_id, client_name, client_phone,
                 invoice_id, service_booking_id, description, amount, payment_method,
                 reference_number, mpesa_phone, mpesa_name, bank_name, account_number,
                 cheque_number, cheque_date, notes, balance_adjustment, recorded_by,
                 status, confirmed_by, confirmed_at)
                VALUES (?,?,?,?,?, ?,?,?,?,?, ?,?,?,?,?, ?,?,?,?,?, ?,?,?)
            ")->execute([
                $payNum, $payDate, $clientId, $clientName, $clientPhone,
                $invoiceId, $bookingId, $description, $amount, $method,
                $ref, $mpesaPhone, $mpesaName, $bankName, $accountNumber,
                $chequeNumber, $chequeDate, $notes, $balAdj, $recordedBy,
                $initStatus, $initConfirmedBy, $initConfirmedAt,
            ]);
            $newPayId = (int)$db->lastInsertId();

            // If cash and linked to an invoice, update invoice balance immediately
            if ($method === 'cash' && $invoiceId) {
                $invRow = $db->prepare("SELECT total FROM invoices WHERE id=?");
                $invRow->execute([$invoiceId]);
                $invRow = $invRow->fetch();
                if ($invRow) {
                    $paidQ = $db->prepare("SELECT COALESCE(SUM(amount),0) FROM payments WHERE invoice_id=? AND status='confirmed'");
                    $paidQ->execute([$invoiceId]);
                    $totalPaid = (float)$paidQ->fetchColumn();
                    $invStatus = $totalPaid >= (float)$invRow['total'] ? 'paid' : 'partial';
                    $db->prepare("UPDATE invoices SET status=?, amount_paid=? WHERE id=?")->execute([$invStatus, $totalPaid, $invoiceId]);
                }
            }
            logActivity('create', 'payments', $newPayId, "Recorded payment {$payNum} — KES {$amount} via {$method} for {$clientName}");
            notifyRoles(['admin','sales_officer'], 'payment',
                "Payment Received: KES " . number_format($amount, 2),
                "{$clientName} via " . strtoupper($method),
                BASE_URL . '/modules/payments/view.php?id=' . $newPayId
            );
            // Payment receipt email
            $clientEmail = '';
            if ($clientId) {
                $es = $db->prepare("SELECT email FROM clients WHERE id=?");
                $es->execute([$clientId]);
                $clientEmail = (string)($es->fetchColumn() ?: '');
            }
            if ($clientEmail && filter_var($clientEmail, FILTER_VALIDATE_EMAIL)) {
                $subj    = "Payment Receipt — {$payNum}";
                $methMap = ['mpesa'=>'M-Pesa','bank'=>'Bank Transfer','cheque'=>'Cheque','cash'=>'Cash'];
                $methLabel = $methMap[$method] ?? strtoupper($method);
                $refRow  = $ref ? "<tr><th>Reference</th><td>" . e($ref) . "</td></tr>" : '';
                $body    = "<p>Dear " . e($clientName) . ",</p>
                           <p>We have received your payment. Here is your receipt:</p>
                           <table class='data'>
                             <tr><th>Receipt No.</th><td><strong>" . e($payNum) . "</strong></td></tr>
                             <tr><th>Date</th><td>" . date('d M Y', strtotime($payDate)) . "</td></tr>
                             <tr><th>Amount</th><td><strong>" . money($amount) . "</strong></td></tr>
                             <tr><th>Method</th><td>{$methLabel}</td></tr>
                             {$refRow}
                             " . ($description ? "<tr><th>For</th><td>" . e($description) . "</td></tr>" : '') . "
                           </table>
                           <p>Thank you for your payment!</p>";
                sendMail($clientEmail, $clientName, $subj, mailTemplate($subj, $body), 'payment', $newPayId);
            }
            setFlash('success', "Payment {$payNum} recorded successfully.");
            redirect(BASE_URL . '/modules/payments/print.php?id=' . $newPayId . '&new=1');
        } catch (\Throwable $e) {
            $errors[] = $e->getMessage();
        }
    }
}

$method = $_POST['payment_method'] ?? 'mpesa';
include __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="mb-0"><i class="fa fa-money-bill-transfer me-2 text-primary"></i>Record Payment</h5>
    <a href="index.php" class="btn btn-sm btn-outline-secondary"><i class="fa fa-arrow-left me-1"></i>Back</a>
</div>

<?php if ($errors): ?>
<div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $err) echo '<li>'.e($err).'</li>'; ?></ul></div>
<?php endif; ?>

<form method="POST">
<div class="row g-4">

    <!-- Left column -->
    <div class="col-lg-7">

        <!-- Client -->
        <div class="card mb-3">
            <div class="card-header fw-semibold"><i class="fa fa-user me-2 text-primary"></i>Client Details</div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-12">
                        <label class="form-label small fw-semibold">Select Existing Client <span class="text-muted fw-normal">(optional — auto-fills below)</span></label>
                        <select name="client_id" class="form-select select2" id="clientSelect">
                            <option value="">— Walk-in / Manual Entry —</option>
                            <?php foreach ($clients as $c): ?>
                            <option value="<?= $c['id'] ?>"
                                    data-name="<?= e($c['name']) ?>"
                                    data-phone="<?= e($c['phone'] ?? '') ?>"
                                    <?= (($_POST['client_id'] ?? $preClientId) == $c['id']) ? 'selected' : '' ?>>
                                <?= e($c['name']) ?><?= $c['phone'] ? ' — '.e($c['phone']) : '' ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-7">
                        <label class="form-label small fw-semibold">Client Name <span class="text-danger">*</span></label>
                        <input type="text" name="client_name" id="clientName" class="form-control"
                               value="<?= e($_POST['client_name'] ?? $preBooking['client_name'] ?? $preInvoice['customer_name'] ?? '') ?>" required>
                    </div>
                    <div class="col-md-5">
                        <label class="form-label small fw-semibold">Phone</label>
                        <input type="text" name="client_phone" id="clientPhone" class="form-control"
                               value="<?= e($_POST['client_phone'] ?? $preBooking['client_phone'] ?? '') ?>">
                    </div>
                </div>
            </div>
        </div>

        <!-- Linked to -->
        <div class="card mb-3">
            <div class="card-header fw-semibold"><i class="fa fa-link me-2 text-primary"></i>Payment For</div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">Invoice</label>
                        <select name="invoice_id" id="invoiceSelect" class="form-select select2">
                            <option value="">— Select invoice —</option>
                            <?php foreach ($invoices as $inv):
                                $invBal = (float)$inv['balance'];
                            ?>
                            <option value="<?= $inv['id'] ?>"
                                    data-client-id="<?= (int)($inv['client_id'] ?? 0) ?>"
                                    data-balance="<?= number_format($invBal, 2, '.', '') ?>"
                                    data-total="<?= number_format((float)$inv['total'], 2, '.', '') ?>"
                                    data-customer="<?= e($inv['customer_name']) ?>"
                                    <?= (($_POST['invoice_id'] ?? $preInvoiceId) == $inv['id']) ? 'selected' : '' ?>>
                                <?= e($inv['invoice_number']) ?> — <?= e($inv['customer_name']) ?>
                                (Bal: <?= money($invBal) ?>)
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <div id="invoiceBalanceHint" class="mt-1 small fw-semibold d-none" style="color:#16a34a">
                            <i class="fa fa-circle-info me-1"></i>Balance due: <span id="invoiceBalanceAmt"></span>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">Service Booking</label>
                        <select name="service_booking_id" id="bookingSelect" class="form-select select2">
                            <option value="">— Select booking —</option>
                            <?php foreach ($bookings as $bk): ?>
                            <option value="<?= $bk['id'] ?>"
                                    data-client-id="<?= (int)($bk['client_id'] ?? 0) ?>"
                                    data-client-name="<?= e($bk['client_name'] ?? '') ?>"
                                    data-client-phone="<?= e($bk['client_phone'] ?? '') ?>"
                                    <?= (($_POST['service_booking_id'] ?? $preBookingId) == $bk['id']) ? 'selected' : '' ?>>
                                <?= e($bk['booking_number']) ?> — <?= e($bk['client_name'] ?? '') ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12">
                        <label class="form-label small fw-semibold">Or describe what it's for</label>
                        <input type="text" name="description" class="form-control" placeholder="e.g. Service deposit, Spare parts payment…"
                               value="<?= e($_POST['description'] ?? '') ?>">
                    </div>
                </div>
            </div>
        </div>

        <!-- Payment method -->
        <div class="card mb-3">
            <div class="card-header fw-semibold"><i class="fa fa-credit-card me-2 text-primary"></i>Payment Method</div>
            <div class="card-body">

                <!-- Method radio cards -->
                <div class="row g-2 mb-3" id="methodCards">
                    <?php
                    $methodCards = [
                        'mpesa'  => ['M-Pesa',        'success', 'fa-mobile-screen',      'Mobile money transfer'],
                        'bank'   => ['Bank Transfer',  'primary', 'fa-building-columns',   'Bank wire / EFT'],
                        'cheque' => ['Cheque',         'warning', 'fa-money-check',        'Post-dated or current cheque'],
                        'cash'   => ['Cash',           'dark',    'fa-money-bill-wave',    'Physical cash'],
                    ];
                    foreach ($methodCards as $k => [$lbl, $col, $ico, $sub]):
                    ?>
                    <div class="col-6">
                        <label class="method-card w-100 border rounded-3 p-3 d-flex align-items-center gap-3 cursor-pointer <?= $method === $k ? 'border-primary bg-primary bg-opacity-10' : '' ?>"
                               style="cursor:pointer;transition:all .15s">
                            <input type="radio" name="payment_method" value="<?= $k ?>"
                                   class="form-check-input method-radio" <?= $method === $k ? 'checked' : '' ?> required>
                            <div class="me-2 text-<?= $col ?>" style="font-size:22px"><i class="fa <?= $ico ?>"></i></div>
                            <div>
                                <div class="fw-semibold" style="font-size:13px"><?= $lbl ?></div>
                                <div class="text-muted" style="font-size:11px"><?= $sub ?></div>
                            </div>
                        </label>
                    </div>
                    <?php endforeach; ?>
                </div>

                <!-- M-Pesa fields -->
                <div id="fields-mpesa" class="method-fields row g-3 <?= $method !== 'mpesa' ? 'd-none' : '' ?>">
                    <div class="col-md-5">
                        <label class="form-label small fw-semibold">Transaction Code <span class="text-danger">*</span></label>
                        <input type="text" name="mpesa_code" class="form-control text-uppercase"
                               placeholder="e.g. QFG2X6Y7ZK" value="<?= e($_POST['mpesa_code'] ?? '') ?>" autocomplete="off">
                        <div class="form-text">M-Pesa confirmation code</div>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small fw-semibold">Sender Phone</label>
                        <input type="text" name="mpesa_phone" class="form-control" placeholder="07XX XXX XXX"
                               value="<?= e($_POST['mpesa_phone'] ?? '') ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small fw-semibold">Account Name</label>
                        <input type="text" name="mpesa_name" class="form-control" placeholder="As on M-Pesa"
                               value="<?= e($_POST['mpesa_name'] ?? '') ?>">
                    </div>
                </div>

                <!-- Bank fields -->
                <div id="fields-bank" class="method-fields row g-3 <?= $method !== 'bank' ? 'd-none' : '' ?>">
                    <div class="col-md-5">
                        <label class="form-label small fw-semibold">Transaction Reference <span class="text-danger">*</span></label>
                        <input type="text" name="bank_ref" class="form-control"
                               placeholder="Bank reference number" value="<?= e($_POST['bank_ref'] ?? '') ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small fw-semibold">Bank Name</label>
                        <input type="text" name="bank_name" class="form-control" placeholder="e.g. KCB, Equity, NCBA"
                               value="<?= e($_POST['bank_name'] ?? '') ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small fw-semibold">Account No.</label>
                        <input type="text" name="account_number" class="form-control" placeholder="Account number"
                               value="<?= e($_POST['account_number'] ?? '') ?>">
                    </div>
                </div>

                <!-- Cheque fields -->
                <div id="fields-cheque" class="method-fields row g-3 <?= $method !== 'cheque' ? 'd-none' : '' ?>">
                    <div class="col-md-4">
                        <label class="form-label small fw-semibold">Cheque Number <span class="text-danger">*</span></label>
                        <input type="text" name="cheque_number" class="form-control" placeholder="Cheque #"
                               value="<?= e($_POST['cheque_number'] ?? '') ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small fw-semibold">Bank / Branch</label>
                        <input type="text" name="cheque_bank" class="form-control" placeholder="Bank name"
                               value="<?= e($_POST['cheque_bank'] ?? '') ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small fw-semibold">Cheque Date</label>
                        <input type="date" name="cheque_date" class="form-control"
                               value="<?= e($_POST['cheque_date'] ?? '') ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">Reference Number <span class="text-muted fw-normal">(optional)</span></label>
                        <input type="text" name="cheque_ref" class="form-control" placeholder="If any"
                               value="<?= e($_POST['cheque_ref'] ?? '') ?>">
                    </div>
                </div>

                <!-- Cash fields -->
                <div id="fields-cash" class="method-fields row g-3 <?= $method !== 'cash' ? 'd-none' : '' ?>">
                    <div class="col-md-5">
                        <label class="form-label small fw-semibold">Receipt / Reference Number <span class="text-muted fw-normal">(optional)</span></label>
                        <input type="text" name="cash_ref" class="form-control" placeholder="Internal receipt #"
                               value="<?= e($_POST['cash_ref'] ?? '') ?>">
                    </div>
                </div>

            </div>
        </div>

    </div>

    <!-- Right column -->
    <div class="col-lg-5">

        <!-- Amount & Date -->
        <div class="card mb-3">
            <div class="card-header fw-semibold"><i class="fa fa-circle-dollar me-2 text-success"></i>Amount & Date</div>
            <div class="card-body">
                <div class="mb-3">
                    <label class="form-label small fw-semibold">Amount Paid (KES) <span class="text-danger">*</span></label>
                    <div class="input-group">
                        <span class="input-group-text fw-bold">KES</span>
                        <input type="number" name="amount" id="amountInput" class="form-control form-control-lg fw-bold"
                               min="0.01" step="0.01" placeholder="0.00"
                               value="<?= e($_POST['amount'] ?? (isset($preInvoice['balance']) ? $preInvoice['balance'] : ($preInvoice['total'] ?? ''))) ?>" required>
                    </div>
                </div>
                <div>
                    <label class="form-label small fw-semibold">Payment Date <span class="text-danger">*</span></label>
                    <input type="date" name="payment_date" class="form-control"
                           value="<?= e($_POST['payment_date'] ?? date('Y-m-d')) ?>" required>
                </div>
            </div>
        </div>

        <!-- Balance Adjustment -->
        <div class="card mb-3">
            <div class="card-header fw-semibold d-flex justify-content-between align-items-center">
                <span><i class="fa fa-scale-balanced me-2 text-info"></i>Balance Adjustment</span>
                <div class="form-check form-switch mb-0">
                    <input class="form-check-input" type="checkbox" id="adjToggle" <?= !empty($_POST['balance_adjustment']) ? 'checked' : '' ?>>
                </div>
            </div>
            <div class="card-body <?= empty($_POST['balance_adjustment']) ? 'd-none' : '' ?>" id="adjBody">
                <p class="small text-muted mb-2">Apply a credit (positive) or debit (negative) to the client balance alongside this payment.</p>
                <div class="input-group">
                    <span class="input-group-text">KES</span>
                    <input type="number" name="balance_adjustment" step="0.01" class="form-control"
                           placeholder="+500 credit, -200 debit" value="<?= e($_POST['balance_adjustment'] ?? '') ?>">
                </div>
            </div>
        </div>

        <!-- Notes -->
        <div class="card mb-3">
            <div class="card-header fw-semibold"><i class="fa fa-note-sticky me-2 text-warning"></i>Notes</div>
            <div class="card-body">
                <textarea name="notes" class="form-control" rows="4" placeholder="Any additional notes…"><?= e($_POST['notes'] ?? '') ?></textarea>
            </div>
        </div>

        <div class="d-grid">
            <button type="submit" class="btn btn-primary btn-lg">
                <i class="fa fa-save me-2"></i>Record Payment
            </button>
        </div>
    </div>

</div>
</form>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
<!-- NOTE: this script block is intentionally AFTER the footer so jQuery,
     Select2 and main.js are already loaded when it executes. -->
<style>
.method-card:hover { border-color: var(--bs-primary) !important; background: #f0f4ff; }
.method-card:has(input:checked) { border-color: var(--bs-primary) !important; background: #eff6ff; }
</style>
<script>
(function($) {
'use strict';

/* ── Static data from PHP ─────────────────────────────────────────────── */
var _invoices = <?= json_encode(array_values($invoices)) ?>;
var _bookings = <?= json_encode(array_values($bookings)) ?>;

/* ── Utilities ───────────────────────────────────────────────────────── */
function setField(id, val) {
    var el = document.getElementById(id);
    if (el) el.value = (val !== undefined && val !== null) ? val : '';
}
function fmtKES(n) {
    return 'KES ' + parseFloat(n || 0).toLocaleString('en-US', {minimumFractionDigits:2, maximumFractionDigits:2});
}

/* ── Rebuild invoice dropdown filtered by clientId or clientName ─────── */
function rebuildInvoices(clientId, clientName, keepVal) {
    var cid   = String(clientId  || '');
    var cname = (clientName || '').toLowerCase().trim();
    var $sel  = $('#invoiceSelect');
    try { $sel.select2('destroy'); } catch(e){}
    $sel.empty().append('<option value="">— Select invoice —</option>');

    var list = (cid || cname)
        ? _invoices.filter(function(i) {
            // Match by client_id (records linked via clients table)
            if (cid && i.client_id && String(i.client_id) === cid) return true;
            // Fallback: match by customer_name (records with no client_id linkage)
            if (cname && i.customer_name && i.customer_name.toLowerCase().trim() === cname) return true;
            return false;
          })
        : _invoices;

    list.forEach(function(inv) {
        var bal = parseFloat(inv.balance || 0);
        var opt = document.createElement('option');
        opt.value            = inv.id;
        opt.textContent      = inv.invoice_number + ' — ' + inv.customer_name + ' (Bal: ' + fmtKES(bal) + ')';
        opt.dataset.balance  = bal.toFixed(2);
        opt.dataset.total    = parseFloat(inv.total || 0).toFixed(2);
        opt.dataset.clientId = inv.client_id || 0;
        $sel[0].appendChild(opt);
    });

    $sel.select2({ theme: 'bootstrap-5', width: '100%', placeholder: '— Select invoice —' });

    var kept = keepVal && list.find(function(i){ return String(i.id) === String(keepVal); });
    if (kept) {
        $sel.val(keepVal).trigger('change');
    } else if (list.length === 1) {
        $sel.val(list[0].id).trigger('change');
    }
}

/* ── Rebuild booking dropdown filtered by clientId or clientName ──────── */
function rebuildBookings(clientId, clientName, keepVal) {
    var cid   = String(clientId  || '');
    var cname = (clientName || '').toLowerCase().trim();
    var $sel  = $('#bookingSelect');
    try { $sel.select2('destroy'); } catch(e){}
    $sel.empty().append('<option value="">— Select booking —</option>');

    var list = (cid || cname)
        ? _bookings.filter(function(b) {
            if (cid && b.client_id && String(b.client_id) === cid) return true;
            if (cname && b.client_name && b.client_name.toLowerCase().trim() === cname) return true;
            return false;
          })
        : _bookings;

    list.forEach(function(bk) {
        var opt = document.createElement('option');
        opt.value               = bk.id;
        opt.textContent         = bk.booking_number + ' — ' + (bk.client_name || '');
        opt.dataset.clientName  = bk.client_name  || '';
        opt.dataset.clientPhone = bk.client_phone || '';
        opt.dataset.clientId    = bk.client_id    || 0;
        $sel[0].appendChild(opt);
    });

    $sel.select2({ theme: 'bootstrap-5', width: '100%', placeholder: '— Select booking —' });

    if (keepVal && list.find(function(b){ return String(b.id) === String(keepVal); })) {
        $sel.val(keepVal).trigger('change');
    }
}

/* ── Show balance hint and fill amount field ─────────────────────────── */
function applyBalance(balance) {
    var hint = document.getElementById('invoiceBalanceHint');
    var lbl  = document.getElementById('invoiceBalanceAmt');
    var bal  = parseFloat(balance || 0);
    if (hint && lbl) {
        if (bal > 0) { lbl.textContent = fmtKES(bal); hint.classList.remove('d-none'); }
        else { hint.classList.add('d-none'); }
    }
    if (bal > 0) setField('amountInput', bal.toFixed(2));
}

/* ── Client select ────────────────────────────────────────────────────── */
// Use e.params.data.element (the actual <option> node) — more reliable than
// this.options[selectedIndex] which can lag behind Select2's internal state.
$('#clientSelect')
    .on('select2:select', function(e) {
        var el    = e.params.data.element;
        var cid   = String(e.params.data.id || '');
        var cname = el ? (el.dataset.name || '') : '';
        setField('clientName',  cname);
        setField('clientPhone', el ? (el.dataset.phone || '') : '');
        rebuildInvoices(cid, cname, $('#invoiceSelect').val());
        rebuildBookings(cid, cname, $('#bookingSelect').val());
    })
    .on('select2:clear', function() {
        setField('clientName',  '');
        setField('clientPhone', '');
        rebuildInvoices('', '', '');
        rebuildBookings('', '', '');
    });

/* ── Invoice select: fill amount with outstanding balance ────────────── */
$(document).on('change', '#invoiceSelect', function() {
    var val = $(this).val();
    if (!val) {
        var h = document.getElementById('invoiceBalanceHint');
        if (h) h.classList.add('d-none');
        return;
    }
    var opt = document.getElementById('invoiceSelect').querySelector('option[value="' + val + '"]');
    if (opt) applyBalance(parseFloat(opt.dataset.balance || 0));
});

/* ── Booking select: fill client details if not already entered ──────── */
$(document).on('change', '#bookingSelect', function() {
    var opt = this.options[this.selectedIndex];
    if (!opt || !opt.value) return;
    if (!document.getElementById('clientName').value) {
        setField('clientName',  opt.dataset.clientName  || '');
        setField('clientPhone', opt.dataset.clientPhone || '');
    }
});

/* ── Method radio toggle ─────────────────────────────────────────────── */
$(document).on('change', '.method-radio', function() {
    $('.method-fields').addClass('d-none');
    $('.method-card').removeClass('border-primary bg-primary bg-opacity-10');
    var sel = document.querySelector('.method-radio:checked');
    if (sel) {
        var el = document.getElementById('fields-' + sel.value);
        if (el) el.classList.remove('d-none');
        $(sel).closest('.method-card').addClass('border-primary bg-primary bg-opacity-10');
    }
});

/* ── Balance adjustment toggle ───────────────────────────────────────── */
$(document).on('change', '#adjToggle', function() {
    $('#adjBody').toggleClass('d-none', !this.checked);
    if (!this.checked) $('[name="balance_adjustment"]').val('');
});

/* ── Form submit: no extra work needed — each method uses unique field names */

/* ── On page load ────────────────────────────────────────────────────── */
$(function() {
    // Show balance hint if invoice was pre-selected (e.g. from URL param)
    var $invSel = $('#invoiceSelect');
    if ($invSel.val()) {
        var preOpt = $invSel[0].querySelector('option[value="' + $invSel.val() + '"]');
        if (preOpt) applyBalance(parseFloat(preOpt.dataset.balance || 0));
    }
    // Filter dropdowns if client was pre-selected
    var $clSel = $('#clientSelect');
    if ($clSel.val()) {
        var clOpt  = $clSel[0].querySelector('option[value="' + $clSel.val() + '"]');
        var clName = clOpt ? (clOpt.dataset.name || '') : '';
        rebuildInvoices(String($clSel.val()), clName, $invSel.val());
        rebuildBookings(String($clSel.val()), clName, $('#bookingSelect').val());
    }
});

}(jQuery));
</script>

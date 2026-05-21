<?php
require_once __DIR__ . '/../../includes/functions.php';
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
$invoices = $db->query("SELECT i.id, i.invoice_number, i.customer_name, i.total FROM invoices i WHERE i.status IN ('unpaid','partial') ORDER BY i.id DESC")->fetchAll(PDO::FETCH_ASSOC);
$bookings = $db->query("SELECT id, booking_number, client_name FROM service_bookings ORDER BY id DESC LIMIT 50")->fetchAll(PDO::FETCH_ASSOC);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $clientId      = (int)($_POST['client_id'] ?? 0) ?: null;
    $clientName    = trim($_POST['client_name'] ?? '');
    $clientPhone   = trim($_POST['client_phone'] ?? '');
    $invoiceId     = (int)($_POST['invoice_id'] ?? 0) ?: null;
    $bookingId     = (int)($_POST['service_booking_id'] ?? 0) ?: null;
    $description   = trim($_POST['description'] ?? '');
    $amount        = (float)($_POST['amount'] ?? 0);
    $method        = $_POST['payment_method'] ?? '';
    $ref           = trim($_POST['reference_number'] ?? '');
    $mpesaPhone    = trim($_POST['mpesa_phone'] ?? '');
    $mpesaName     = trim($_POST['mpesa_name'] ?? '');
    $bankName      = trim($_POST['bank_name'] ?? '');
    $accountNumber = trim($_POST['account_number'] ?? '');
    $chequeNumber  = trim($_POST['cheque_number'] ?? '');
    $chequeDate    = $_POST['cheque_date'] ?? null ?: null;
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
            $payNum = nextNumber('payments', 'payment_number', 'PAY');
            $db->prepare("
                INSERT INTO payments
                (payment_number, payment_date, client_id, client_name, client_phone,
                 invoice_id, service_booking_id, description, amount, payment_method,
                 reference_number, mpesa_phone, mpesa_name, bank_name, account_number,
                 cheque_number, cheque_date, notes, balance_adjustment, recorded_by)
                VALUES (?,?,?,?,?, ?,?,?,?,?, ?,?,?,?,?, ?,?,?,?,?)
            ")->execute([
                $payNum, $payDate, $clientId, $clientName, $clientPhone,
                $invoiceId, $bookingId, $description, $amount, $method,
                $ref, $mpesaPhone, $mpesaName, $bankName, $accountNumber,
                $chequeNumber, $chequeDate, $notes, $balAdj, $recordedBy,
            ]);
            $newPayId = (int)$db->lastInsertId();
            logActivity('create', 'payments', $newPayId, "Recorded payment {$payNum} — KES {$amount} via {$method} for {$clientName}");
            setFlash('success', "Payment {$payNum} recorded successfully.");
            redirect(BASE_URL . '/modules/payments/view.php?id=' . $newPayId);
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
                        <label class="form-label small fw-semibold">Invoice (optional)</label>
                        <select name="invoice_id" class="form-select select2">
                            <option value="">— None —</option>
                            <?php foreach ($invoices as $inv): ?>
                            <option value="<?= $inv['id'] ?>" <?= (($_POST['invoice_id'] ?? $preInvoiceId) == $inv['id']) ? 'selected' : '' ?>>
                                <?= e($inv['invoice_number']) ?> — <?= e($inv['customer_name']) ?> (<?= money($inv['total']) ?>)
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">Service Booking (optional)</label>
                        <select name="service_booking_id" class="form-select select2">
                            <option value="">— None —</option>
                            <?php foreach ($bookings as $bk): ?>
                            <option value="<?= $bk['id'] ?>" <?= (($_POST['service_booking_id'] ?? $preBookingId) == $bk['id']) ? 'selected' : '' ?>>
                                <?= e($bk['booking_number']) ?> — <?= e($bk['client_name']) ?>
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
                        <input type="text" name="reference_number" class="form-control text-uppercase"
                               placeholder="e.g. QFG2X6Y7ZK" value="<?= e($_POST['reference_number'] ?? '') ?>">
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
                        <input type="text" name="reference_number" class="form-control"
                               placeholder="Bank reference number" value="<?= e($_POST['reference_number'] ?? '') ?>">
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
                        <input type="text" name="bank_name" class="form-control" placeholder="Bank name"
                               value="<?= e($_POST['bank_name'] ?? '') ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small fw-semibold">Cheque Date</label>
                        <input type="date" name="cheque_date" class="form-control"
                               value="<?= e($_POST['cheque_date'] ?? '') ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small fw-semibold">Reference Number</label>
                        <input type="text" name="reference_number" class="form-control" placeholder="If any"
                               value="<?= e($_POST['reference_number'] ?? '') ?>">
                    </div>
                </div>

                <!-- Cash fields -->
                <div id="fields-cash" class="method-fields row g-3 <?= $method !== 'cash' ? 'd-none' : '' ?>">
                    <div class="col-md-5">
                        <label class="form-label small fw-semibold">Receipt / Reference Number <span class="text-muted fw-normal">(optional)</span></label>
                        <input type="text" name="reference_number" class="form-control" placeholder="Internal receipt #"
                               value="<?= e($_POST['reference_number'] ?? '') ?>">
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
                        <input type="number" name="amount" class="form-control form-control-lg fw-bold"
                               min="0.01" step="0.01" placeholder="0.00"
                               value="<?= e($_POST['amount'] ?? ($preInvoice['total'] ?? '')) ?>" required>
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

<style>
.method-card:hover { border-color: var(--bs-primary) !important; background: #f0f4ff; }
.method-card:has(input:checked) { border-color: var(--bs-primary) !important; background: #eff6ff; }
</style>

<script>
// Method radio toggle
document.querySelectorAll('.method-radio').forEach(radio => {
    radio.addEventListener('change', () => {
        document.querySelectorAll('.method-fields').forEach(f => f.classList.add('d-none'));
        document.querySelectorAll('.method-card').forEach(c => { c.classList.remove('border-primary','bg-primary','bg-opacity-10'); });
        const sel = document.querySelector('.method-radio:checked');
        if (sel) {
            document.getElementById('fields-' + sel.value)?.classList.remove('d-none');
            sel.closest('.method-card').classList.add('border-primary','bg-primary','bg-opacity-10');
        }
    });
});

// Balance adjustment toggle
document.getElementById('adjToggle')?.addEventListener('change', e => {
    document.getElementById('adjBody').classList.toggle('d-none', !e.target.checked);
    if (!e.target.checked) document.querySelector('[name="balance_adjustment"]').value = '';
});

// Client select auto-fill
document.getElementById('clientSelect')?.addEventListener('change', function () {
    const opt = this.options[this.selectedIndex];
    document.getElementById('clientName').value  = opt.dataset.name  || '';
    document.getElementById('clientPhone').value = opt.dataset.phone || '';
});
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>

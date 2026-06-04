<?php
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
canAccess('payments') || die('Access denied.');
$id = (int)($_GET['id'] ?? 0);
if (!$id) redirect(BASE_URL . '/modules/payments/index.php');
$db = getDB();

$stmt = $db->prepare("
    SELECT p.*,
           i.invoice_number, i.id AS inv_id,
           sb.booking_number, sb.id AS bk_id
    FROM payments p
    LEFT JOIN invoices i ON i.id = p.invoice_id
    LEFT JOIN service_bookings sb ON sb.id = p.service_booking_id
    WHERE p.id = ?
");
$stmt->execute([$id]);
$p = $stmt->fetch();
if (!$p) { setFlash('error', 'Payment not found.'); redirect(BASE_URL . '/modules/payments/index.php'); }

// Confirm action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $actor = authUser()['name'] ?? 'System';
    if ($_POST['action'] === 'confirm' && $p['status'] === 'pending') {
        $db->prepare("UPDATE payments SET status='confirmed', confirmed_by=?, confirmed_at=NOW() WHERE id=?")
           ->execute([$actor, $id]);
        // If linked to invoice, update invoice status
        if ($p['invoice_id']) {
            $inv = $db->prepare("SELECT total FROM invoices WHERE id=?"); $inv->execute([$p['invoice_id']]); $inv = $inv->fetch();
            $paid = $db->prepare("SELECT COALESCE(SUM(amount),0) FROM payments WHERE invoice_id=? AND status='confirmed'");
            $paid->execute([$p['invoice_id']]); $totalPaid = (float)$paid->fetchColumn();
            $newStatus = $totalPaid >= (float)($inv['total'] ?? 0) ? 'paid' : 'partial';
            $db->prepare("UPDATE invoices SET status=?, amount_paid=? WHERE id=?")->execute([$newStatus, $totalPaid, $p['invoice_id']]);
        }
        setFlash('success', 'Payment confirmed.');
    } elseif ($_POST['action'] === 'reverse' && canEditDelete()) {
        $reason = trim($_POST['reversal_reason'] ?? '');
        $db->prepare("UPDATE payments SET status='reversed', reversal_reason=? WHERE id=?")
           ->execute([$reason, $id]);
        // Revert invoice status if was paid
        if ($p['invoice_id']) {
            $db->prepare("UPDATE invoices SET status='unpaid' WHERE id=? AND status='paid'")->execute([$p['invoice_id']]);
        }
        setFlash('warning', 'Payment reversed.');
    }
    redirect(BASE_URL . '/modules/payments/view.php?id=' . $id);
}

// Re-fetch after possible update
$stmt->execute([$id]);
$p = $stmt->fetch();

$methodMeta = [
    'mpesa'  => ['M-Pesa',        'success', 'fa-mobile-screen'],
    'bank'   => ['Bank Transfer', 'primary', 'fa-building-columns'],
    'cheque' => ['Cheque',        'warning', 'fa-money-check'],
    'cash'   => ['Cash',          'dark',    'fa-money-bill-wave'],
];
[$mlabel, $mcolor, $micon] = $methodMeta[$p['payment_method']] ?? [$p['payment_method'], 'secondary', 'fa-circle'];

$statusConfig = [
    'pending'   => ['warning', 'clock',        'Pending Confirmation'],
    'confirmed' => ['success', 'circle-check', 'Confirmed'],
    'reversed'  => ['danger',  'rotate-left',  'Reversed'],
];
[$sc, $si, $sl] = $statusConfig[$p['status']] ?? ['secondary','circle','Unknown'];

$pageTitle = 'Payment — ' . $p['payment_number'];
include __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h5 class="mb-1"><i class="fa fa-money-bill-transfer me-2 text-primary"></i><?= e($p['payment_number']) ?></h5>
        <span class="badge bg-<?= $sc ?> fs-6 px-3 py-2">
            <i class="fa fa-<?= $si ?> me-1"></i><?= $sl ?>
        </span>
    </div>
    <div class="d-flex gap-2">
        <?php if ($p['status'] === 'pending' && canWrite('payments')): ?>
        <form method="POST" class="d-inline">
            <input type="hidden" name="action" value="confirm">
            <button class="btn btn-success btn-sm" onclick="return confirm('Confirm this payment?')">
                <i class="fa fa-circle-check me-1"></i>Confirm Payment
            </button>
        </form>
        <?php endif; ?>
        <?php if ($p['status'] === 'confirmed' && canEditDelete()): ?>
        <button class="btn btn-outline-danger btn-sm" data-bs-toggle="modal" data-bs-target="#reverseModal">
            <i class="fa fa-rotate-left me-1"></i>Reverse
        </button>
        <?php endif; ?>
        <a href="print.php?id=<?= $id ?>" class="btn btn-outline-dark btn-sm" target="_blank">
            <i class="fa fa-print me-1"></i>Receipt
        </a>
        <a href="index.php" class="btn btn-outline-secondary btn-sm"><i class="fa fa-arrow-left me-1"></i>Back</a>
    </div>
</div>

<div class="row g-4">
    <div class="col-lg-7">

        <!-- Payment details -->
        <div class="card mb-3">
            <div class="card-header fw-semibold"><i class="fa fa-receipt me-2 text-primary"></i>Payment Details</div>
            <div class="card-body">
                <dl class="row mb-0" style="font-size:13.5px">
                    <dt class="col-5 text-muted">Payment Number</dt>
                    <dd class="col-7 fw-bold"><?= e($p['payment_number']) ?></dd>
                    <dt class="col-5 text-muted">Date</dt>
                    <dd class="col-7"><?= fmtDate($p['payment_date']) ?></dd>
                    <dt class="col-5 text-muted">Amount</dt>
                    <dd class="col-7"><strong style="font-size:18px;color:#16a34a"><?= money($p['amount']) ?></strong></dd>
                    <?php if ($p['balance_adjustment'] != 0): ?>
                    <dt class="col-5 text-muted">Balance Adjustment</dt>
                    <dd class="col-7">
                        <span class="badge bg-<?= $p['balance_adjustment'] > 0 ? 'success' : 'danger' ?>">
                            <?= $p['balance_adjustment'] > 0 ? '+' : '' ?><?= money($p['balance_adjustment']) ?>
                        </span>
                    </dd>
                    <?php endif; ?>
                    <dt class="col-5 text-muted">Method</dt>
                    <dd class="col-7">
                        <span class="badge bg-<?= $mcolor ?>">
                            <i class="fa <?= $micon ?> me-1"></i><?= $mlabel ?>
                        </span>
                    </dd>
                    <dt class="col-5 text-muted">Recorded By</dt>
                    <dd class="col-7 text-muted"><?= e($p['recorded_by'] ?? '—') ?></dd>
                    <?php if ($p['confirmed_by']): ?>
                    <dt class="col-5 text-muted">Confirmed By</dt>
                    <dd class="col-7"><?= e($p['confirmed_by']) ?> <span class="text-muted small">(<?= fmtDate($p['confirmed_at'], 'd M Y H:i') ?>)</span></dd>
                    <?php endif; ?>
                    <?php if ($p['reversal_reason']): ?>
                    <dt class="col-5 text-muted">Reversal Reason</dt>
                    <dd class="col-7 text-danger"><?= e($p['reversal_reason']) ?></dd>
                    <?php endif; ?>
                </dl>
            </div>
        </div>

        <!-- Method-specific fields -->
        <div class="card mb-3">
            <div class="card-header fw-semibold">
                <i class="fa <?= $micon ?> me-2 text-<?= $mcolor ?>"></i><?= $mlabel ?> Details
            </div>
            <div class="card-body">
                <dl class="row mb-0" style="font-size:13.5px">
                    <?php if ($p['reference_number']): ?>
                    <dt class="col-5 text-muted">Reference / Code</dt>
                    <dd class="col-7"><code class="fs-6"><?= e($p['reference_number']) ?></code></dd>
                    <?php endif; ?>
                    <?php if ($p['mpesa_phone']): ?>
                    <dt class="col-5 text-muted">Sender Phone</dt>
                    <dd class="col-7"><?= e($p['mpesa_phone']) ?></dd>
                    <?php endif; ?>
                    <?php if ($p['mpesa_name']): ?>
                    <dt class="col-5 text-muted">M-Pesa Name</dt>
                    <dd class="col-7"><?= e($p['mpesa_name']) ?></dd>
                    <?php endif; ?>
                    <?php if ($p['bank_name']): ?>
                    <dt class="col-5 text-muted">Bank</dt>
                    <dd class="col-7"><?= e($p['bank_name']) ?></dd>
                    <?php endif; ?>
                    <?php if ($p['account_number']): ?>
                    <dt class="col-5 text-muted">Account No.</dt>
                    <dd class="col-7"><?= e($p['account_number']) ?></dd>
                    <?php endif; ?>
                    <?php if ($p['cheque_number']): ?>
                    <dt class="col-5 text-muted">Cheque No.</dt>
                    <dd class="col-7"><?= e($p['cheque_number']) ?></dd>
                    <?php endif; ?>
                    <?php if ($p['cheque_date']): ?>
                    <dt class="col-5 text-muted">Cheque Date</dt>
                    <dd class="col-7"><?= fmtDate($p['cheque_date']) ?></dd>
                    <?php endif; ?>
                    <?php if (!$p['reference_number'] && !$p['mpesa_phone'] && !$p['bank_name'] && !$p['cheque_number']): ?>
                    <dd class="col-12 text-muted">No additional details recorded.</dd>
                    <?php endif; ?>
                </dl>
            </div>
        </div>

        <?php if ($p['notes']): ?>
        <div class="card">
            <div class="card-header fw-semibold"><i class="fa fa-note-sticky me-2 text-warning"></i>Notes</div>
            <div class="card-body"><p class="mb-0 small"><?= e($p['notes']) ?></p></div>
        </div>
        <?php endif; ?>

    </div>

    <div class="col-lg-5">

        <!-- Client -->
        <div class="card mb-3">
            <div class="card-header fw-semibold"><i class="fa fa-user me-2 text-primary"></i>Client</div>
            <div class="card-body">
                <div class="fw-bold fs-6"><?= e($p['client_name']) ?></div>
                <?php if ($p['client_phone']): ?>
                <div class="text-muted small"><i class="fa fa-phone me-1"></i><?= e($p['client_phone']) ?></div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Linked to -->
        <div class="card mb-3">
            <div class="card-header fw-semibold"><i class="fa fa-link me-2 text-primary"></i>Payment For</div>
            <div class="card-body">
                <?php if ($p['inv_id']): ?>
                <a href="<?= BASE_URL ?>/modules/invoices/view.php?id=<?= $p['inv_id'] ?>" class="d-flex align-items-center gap-2 text-decoration-none text-dark">
                    <i class="fa fa-file-invoice-dollar text-primary fa-lg"></i>
                    <div>
                        <div class="fw-semibold"><?= e($p['invoice_number']) ?></div>
                        <div class="text-muted small">Invoice</div>
                    </div>
                </a>
                <?php elseif ($p['bk_id']): ?>
                <a href="<?= BASE_URL ?>/modules/service_bookings/view.php?id=<?= $p['bk_id'] ?>" class="d-flex align-items-center gap-2 text-decoration-none text-dark">
                    <i class="fa fa-calendar-check text-success fa-lg"></i>
                    <div>
                        <div class="fw-semibold"><?= e($p['booking_number']) ?></div>
                        <div class="text-muted small">Service Booking</div>
                    </div>
                </a>
                <?php elseif ($p['description']): ?>
                <p class="mb-0 text-muted small"><?= e($p['description']) ?></p>
                <?php else: ?>
                <p class="mb-0 text-muted small">No linked document.</p>
                <?php endif; ?>
            </div>
        </div>

        <!-- Status timeline -->
        <div class="card">
            <div class="card-header fw-semibold"><i class="fa fa-timeline me-2 text-primary"></i>Status</div>
            <div class="card-body">
                <div class="timeline">
                    <div class="timeline-item">
                        <div class="timeline-dot dot-success"></div>
                        <div class="fw-semibold small">Recorded</div>
                        <div class="text-muted" style="font-size:11px"><?= fmtDate($p['created_at'], 'd M Y H:i') ?> by <?= e($p['recorded_by'] ?? '—') ?></div>
                    </div>
                    <?php if ($p['confirmed_by']): ?>
                    <div class="timeline-item">
                        <div class="timeline-dot dot-success"></div>
                        <div class="fw-semibold small">Confirmed</div>
                        <div class="text-muted" style="font-size:11px"><?= fmtDate($p['confirmed_at'], 'd M Y H:i') ?> by <?= e($p['confirmed_by']) ?></div>
                    </div>
                    <?php elseif ($p['status'] === 'pending'): ?>
                    <div class="timeline-item">
                        <div class="timeline-dot dot-warning"></div>
                        <div class="fw-semibold small text-warning">Awaiting Confirmation</div>
                    </div>
                    <?php endif; ?>
                    <?php if ($p['status'] === 'reversed'): ?>
                    <div class="timeline-item">
                        <div class="timeline-dot dot-danger"></div>
                        <div class="fw-semibold small text-danger">Reversed</div>
                        <?php if ($p['reversal_reason']): ?>
                        <div class="text-muted" style="font-size:11px"><?= e($p['reversal_reason']) ?></div>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

    </div>
</div>

<!-- Reversal Modal -->
<?php if ($p['status'] === 'confirmed' && canEditDelete()): ?>
<div class="modal fade" id="reverseModal" tabindex="-1">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="action" value="reverse">
                <div class="modal-header">
                    <h6 class="modal-title text-danger"><i class="fa fa-rotate-left me-2"></i>Reverse Payment</h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p class="small text-muted">This will mark the payment as reversed and revert any invoice status changes.</p>
                    <label class="form-label small fw-semibold">Reason for reversal <span class="text-danger">*</span></label>
                    <textarea name="reversal_reason" class="form-control form-control-sm" rows="3" required placeholder="e.g. Bounced cheque, incorrect amount…"></textarea>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-sm btn-danger"><i class="fa fa-rotate-left me-1"></i>Reverse</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<?php include __DIR__ . '/../../includes/footer.php'; ?>

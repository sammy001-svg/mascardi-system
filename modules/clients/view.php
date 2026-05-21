<?php
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/mailer.php';
requireLogin();
canAccess('clients') || die('Access denied.');
$db   = getDB();
$user = authUser();
$id   = (int)($_GET['id'] ?? 0);
if (!$id) redirect(BASE_URL . '/modules/clients/index.php');

$client = $db->prepare("SELECT * FROM clients WHERE id=?");
$client->execute([$id]); $client = $client->fetch();
if (!$client) { setFlash('error','Not found.'); redirect(BASE_URL.'/modules/clients/index.php'); }

// Send notice
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_notice'])) {
    $subject = trim($_POST['notice_subject'] ?? '');
    $message = trim($_POST['notice_message'] ?? '');
    if ($subject && $message) {
        $db->prepare("INSERT INTO client_notices (client_id,subject,message,sent_by) VALUES (?,?,?,?)")
           ->execute([$id, $subject, $message, $user['name']]);
        if ($client['portal_enabled'] && $client['email']) {
            $html = mailTemplate($subject, '<p>' . nl2br(e($message)) . '</p>');
            sendMail($client['email'], $client['name'], $subject, $html, 'notice', $id);
        }
        setFlash('success', 'Notice sent.');
        redirect(BASE_URL . '/modules/clients/view.php?id=' . $id);
    }
}

$cars     = $db->prepare("SELECT * FROM cars WHERE client_id=? ORDER BY make,model"); $cars->execute([$id]); $cars=$cars->fetchAll();
$invoices = $db->prepare("SELECT i.*,c.make,c.model FROM invoices i JOIN cars c ON c.id=i.car_id WHERE i.client_id=? ORDER BY i.created_at DESC"); $invoices->execute([$id]); $invoices=$invoices->fetchAll();
$quotes   = $db->prepare("SELECT q.*,c.make,c.model FROM quotations q JOIN cars c ON c.id=q.car_id WHERE q.client_id=? ORDER BY q.created_at DESC"); $quotes->execute([$id]); $quotes=$quotes->fetchAll();
$bookings = $db->prepare("SELECT * FROM service_bookings WHERE client_id=? ORDER BY created_at DESC"); $bookings->execute([$id]); $bookings=$bookings->fetchAll();
$notices  = $db->prepare("SELECT * FROM client_notices WHERE client_id=? ORDER BY created_at DESC LIMIT 20"); $notices->execute([$id]); $notices=$notices->fetchAll();

// Financial summary
$finStmt = $db->prepare("SELECT COALESCE(SUM(total),0) AS total_billed, COALESCE(SUM(amount_paid),0) AS total_paid FROM invoices WHERE client_id=? AND status NOT IN ('cancelled')");
$finStmt->execute([$id]); $fin = $finStmt->fetch();
$outstanding = (float)$fin['total_billed'] - (float)$fin['total_paid'];

// Payments for this client (confirmed, via invoice or booking)
$paymentsStmt = $db->prepare("
    SELECT p.*, i.invoice_number, sb.booking_number
    FROM payments p
    LEFT JOIN invoices i ON i.id = p.invoice_id
    LEFT JOIN service_bookings sb ON sb.id = p.service_booking_id
    WHERE (i.client_id = ? OR sb.client_id = ?)
      AND p.status = 'confirmed'
    ORDER BY p.payment_date DESC, p.id DESC
");
$paymentsStmt->execute([$id, $id]);
$clientPayments = $paymentsStmt->fetchAll();

$pageTitle = $client['name'];
include __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <h5 class="mb-1"><i class="fa fa-user me-2 text-primary"></i><?= e($client['name']) ?></h5>
        <div class="text-muted small"><?= e($client['email']) ?><?= $client['phone'] ? ' · ' . e($client['phone']) : '' ?></div>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <a href="statement.php?id=<?= $id ?>" target="_blank" class="btn btn-sm btn-outline-primary">
            <i class="fa fa-file-lines me-1"></i>Statement
        </a>
        <?php if (canEditDelete()): ?>
        <a href="edit.php?id=<?= $id ?>" class="btn btn-sm btn-outline-secondary"><i class="fa fa-pen me-1"></i>Edit</a>
        <?php endif; ?>
        <a href="index.php" class="btn btn-sm btn-outline-secondary"><i class="fa fa-arrow-left me-1"></i>Back</a>
    </div>
</div>

<div class="row g-4 mb-4">
    <!-- Info -->
    <div class="col-md-4">
        <div class="card h-100">
            <div class="card-header">Client Info</div>
            <div class="card-body">
                <dl class="row mb-0" style="font-size:13.5px">
                    <dt class="col-5 text-muted">ID / KRA PIN</dt><dd class="col-7"><?= e($client['id_number'] ?? '—') ?></dd>
                    <dt class="col-5 text-muted">Status</dt><dd class="col-7"><?= statusBadge($client['status']) ?></dd>
                    <dt class="col-5 text-muted">Portal</dt>
                    <dd class="col-7">
                        <?php if ($client['portal_enabled'] && $client['portal_password']): ?>
                        <span class="badge bg-success">Active</span>
                        <?php elseif ($client['portal_enabled']): ?>
                        <span class="badge bg-warning text-dark">No password set</span>
                        <?php else: ?>
                        <span class="badge bg-secondary">Disabled</span>
                        <?php endif; ?>
                    </dd>
                    <dt class="col-5 text-muted">Portal URL</dt>
                    <dd class="col-7"><a href="<?= BASE_URL ?>/client/login.php" target="_blank" class="small">/client/login.php</a></dd>
                    <dt class="col-5 text-muted">Notes</dt><dd class="col-7 text-muted small"><?= $client['notes'] ? e($client['notes']) : '—' ?></dd>
                    <dt class="col-5 text-muted">Since</dt><dd class="col-7"><?= fmtDate($client['created_at']) ?></dd>
                </dl>
            </div>
        </div>
    </div>
    <!-- Send Notice -->
    <div class="col-md-8">
        <div class="card h-100" style="border-top:3px solid #2563eb">
            <div class="card-header"><i class="fa fa-bell me-2"></i>Send Notice to Client</div>
            <div class="card-body">
                <form method="POST" class="row g-3">
                    <input type="hidden" name="send_notice" value="1">
                    <div class="col-12">
                        <label class="form-label">Subject</label>
                        <input type="text" name="notice_subject" class="form-control" placeholder="e.g. Your vehicle is ready for collection" required>
                    </div>
                    <div class="col-12">
                        <label class="form-label">Message</label>
                        <textarea name="notice_message" class="form-control" rows="4" placeholder="Your message to the client…" required></textarea>
                    </div>
                    <div class="col-12 d-flex justify-content-end">
                        <button class="btn btn-primary"><i class="fa fa-paper-plane me-1"></i>Send Notice
                            <?php if ($client['portal_enabled'] && $client['email']): ?>
                            <span class="badge bg-white text-primary ms-1 small">+ Email</span>
                            <?php endif; ?>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Financial Summary -->
<div class="row g-3 mb-4">
    <div class="col-sm-6 col-xl-3">
        <div class="stat-card" style="border-left:4px solid #2563eb">
            <div class="stat-icon" style="background:#dbeafe;color:#2563eb"><i class="fa fa-file-invoice-dollar"></i></div>
            <div class="stat-info">
                <div class="stat-label">Total Billed</div>
                <div class="stat-value stat-value-sm"><?= money((float)$fin['total_billed']) ?></div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="stat-card" style="border-left:4px solid #16a34a">
            <div class="stat-icon" style="background:#dcfce7;color:#16a34a"><i class="fa fa-money-bill-wave"></i></div>
            <div class="stat-info">
                <div class="stat-label">Total Paid</div>
                <div class="stat-value stat-value-sm"><?= money((float)$fin['total_paid']) ?></div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <?php $balColor = $outstanding > 0 ? '#dc2626' : '#16a34a'; $balBg = $outstanding > 0 ? '#fee2e2' : '#dcfce7'; ?>
        <div class="stat-card" style="border-left:4px solid <?= $balColor ?>">
            <div class="stat-icon" style="background:<?= $balBg ?>;color:<?= $balColor ?>"><i class="fa fa-scale-balanced"></i></div>
            <div class="stat-info">
                <div class="stat-label">Outstanding</div>
                <div class="stat-value stat-value-sm" style="color:<?= $balColor ?>"><?= money($outstanding) ?></div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="stat-card" style="border-left:4px solid #8b5cf6">
            <div class="stat-icon" style="background:#f5f3ff;color:#8b5cf6"><i class="fa fa-calendar-check"></i></div>
            <div class="stat-info">
                <div class="stat-label">Bookings</div>
                <div class="stat-value"><?= count($bookings) ?></div>
            </div>
        </div>
    </div>
</div>

<!-- Vehicles -->
<div class="card mb-4">
    <div class="card-header d-flex justify-content-between"><span><i class="fa fa-car me-2"></i>Client Vehicles (<?= count($cars) ?>)</span></div>
    <div class="card-body p-0">
        <?php if ($cars): ?>
        <table class="table table-hover mb-0">
            <thead><tr><th class="ps-3">Make / Model</th><th>Chassis</th><th>Reg.</th><th>Status</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($cars as $car): ?>
            <tr>
                <td class="ps-3 fw-medium"><?= e($car['make'] . ' ' . $car['model'] . ' ' . $car['year']) ?></td>
                <td><code><?= e($car['chassis_number']) ?></code></td>
                <td><?= e($car['registration_number'] ?? '—') ?></td>
                <td><?= statusBadge($car['status']) ?></td>
                <td><a href="<?= BASE_URL ?>/modules/cars/view.php?id=<?= $car['id'] ?>" class="btn btn-xs btn-outline-primary"><i class="fa fa-eye"></i></a></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php else: ?>
        <p class="text-muted p-4 mb-0">No vehicles linked to this client.</p>
        <?php endif; ?>
    </div>
</div>

<!-- Invoices + Quotations row -->
<div class="row g-4 mb-4">
    <div class="col-md-6">
        <div class="card h-100">
            <div class="card-header"><i class="fa fa-file-invoice-dollar me-2"></i>Invoices (<?= count($invoices) ?>)</div>
            <div class="card-body p-0">
                <?php if ($invoices): ?>
                <table class="table table-sm mb-0">
                    <thead><tr><th class="ps-3">Invoice #</th><th>Total</th><th>Status</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($invoices as $inv): ?>
                    <tr>
                        <td class="ps-3 fw-bold"><?= e($inv['invoice_number']) ?></td>
                        <td><?= money((float)$inv['total']) ?></td>
                        <td><?= statusBadge($inv['status']) ?></td>
                        <td><a href="<?= BASE_URL ?>/modules/invoices/view.php?id=<?= $inv['id'] ?>" class="btn btn-xs btn-outline-primary"><i class="fa fa-eye"></i></a></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php else: ?><p class="text-muted p-3 mb-0">No invoices.</p><?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card h-100">
            <div class="card-header"><i class="fa fa-file-lines me-2"></i>Quotations (<?= count($quotes) ?>)</div>
            <div class="card-body p-0">
                <?php if ($quotes): ?>
                <table class="table table-sm mb-0">
                    <thead><tr><th class="ps-3">Quote #</th><th>Total</th><th>Status</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($quotes as $q): ?>
                    <tr>
                        <td class="ps-3 fw-bold"><?= e($q['quotation_number']) ?></td>
                        <td><?= money((float)$q['total']) ?></td>
                        <td><?= statusBadge($q['status']) ?></td>
                        <td><a href="<?= BASE_URL ?>/modules/quotations/view.php?id=<?= $q['id'] ?>" class="btn btn-xs btn-outline-primary"><i class="fa fa-eye"></i></a></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php else: ?><p class="text-muted p-3 mb-0">No quotations.</p><?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Payments -->
<?php if ($clientPayments): ?>
<div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="fa fa-money-bill-transfer me-2 text-success"></i>Payment History (<?= count($clientPayments) ?>)</span>
        <a href="statement.php?id=<?= $id ?>" target="_blank" class="btn btn-xs btn-outline-primary">
            <i class="fa fa-print me-1"></i>Print Statement
        </a>
    </div>
    <div class="card-body p-0">
        <table class="table table-hover mb-0" style="font-size:13.5px">
            <thead>
                <tr>
                    <th class="ps-3">Date</th>
                    <th>Payment #</th>
                    <th>For</th>
                    <th>Method</th>
                    <th>Ref / Receipt</th>
                    <th class="text-end pe-3">Amount</th>
                </tr>
            </thead>
            <tbody>
            <?php
            $methodLabel = ['mpesa'=>'M-Pesa','bank'=>'Bank','cheque'=>'Cheque','cash'=>'Cash'];
            foreach ($clientPayments as $pay): ?>
            <tr>
                <td class="ps-3 text-muted small"><?= fmtDate($pay['payment_date']) ?></td>
                <td class="fw-medium"><?= e($pay['payment_number']) ?></td>
                <td class="small text-muted">
                    <?php if ($pay['invoice_number']): ?>
                        <a href="<?= BASE_URL ?>/modules/invoices/view.php?id=<?= $pay['invoice_id'] ?>"><?= e($pay['invoice_number']) ?></a>
                    <?php elseif ($pay['booking_number']): ?>
                        Booking <?= e($pay['booking_number']) ?>
                    <?php else: ?>
                        <?= e($pay['description'] ?? '—') ?>
                    <?php endif; ?>
                </td>
                <td><span class="badge bg-secondary"><?= e($methodLabel[$pay['payment_method']] ?? $pay['payment_method']) ?></span></td>
                <td class="small"><code><?= e($pay['reference_number'] ?? '—') ?></code></td>
                <td class="text-end pe-3 fw-semibold text-success"><?= money((float)$pay['amount']) ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<!-- Notices -->
<?php if ($notices): ?>
<div class="card">
    <div class="card-header"><i class="fa fa-bell me-2"></i>Recent Notices</div>
    <div class="card-body p-0">
        <table class="table table-sm mb-0">
            <thead><tr><th class="ps-3">Subject</th><th>Sent By</th><th>Date</th><th>Read</th></tr></thead>
            <tbody>
            <?php foreach ($notices as $n): ?>
            <tr>
                <td class="ps-3 fw-medium"><?= e($n['subject']) ?></td>
                <td class="text-muted small"><?= e($n['sent_by']) ?></td>
                <td class="text-muted small"><?= fmtDate($n['created_at'], 'd M Y, H:i') ?></td>
                <td><?= $n['is_read'] ? '<span class="badge bg-success">Read</span>' : '<span class="badge bg-secondary">Unread</span>' ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php include __DIR__ . '/../../includes/footer.php'; ?>

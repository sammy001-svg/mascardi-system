<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
$pageTitle = 'Dashboard';
$db  = getDB();
requireClientLogin();
$cl  = clientAuth();
$cid = $cl['id'];

$cars     = $db->prepare("SELECT * FROM cars WHERE client_id=? ORDER BY make,model"); $cars->execute([$cid]); $cars=$cars->fetchAll();
$invoices = $db->prepare("SELECT * FROM invoices WHERE client_id=? ORDER BY created_at DESC LIMIT 5"); $invoices->execute([$cid]); $invoices=$invoices->fetchAll();
$bookings = $db->prepare("SELECT * FROM service_bookings WHERE client_id=? AND status NOT IN ('completed','cancelled') ORDER BY preferred_date,created_at LIMIT 5"); $bookings->execute([$cid]); $bookings=$bookings->fetchAll();
$unread   = (int)$db->prepare("SELECT COUNT(*) FROM client_notices WHERE client_id=? AND is_read=0")->execute([$cid]) ? $db->prepare("SELECT COUNT(*) FROM client_notices WHERE client_id=? AND is_read=0")->execute([$cid]) && 0 : 0;
$nStmt = $db->prepare("SELECT COUNT(*) FROM client_notices WHERE client_id=? AND is_read=0"); $nStmt->execute([$cid]); $unread=(int)$nStmt->fetchColumn();

$unpaidAmt = 0;
$unpaidStmt = $db->prepare("SELECT COALESCE(SUM(total-amount_paid),0) FROM invoices WHERE client_id=? AND status IN ('unpaid','partial')");
$unpaidStmt->execute([$cid]); $unpaidAmt=(float)$unpaidStmt->fetchColumn();

include __DIR__ . '/includes/header.php';
?>
<!-- Welcome -->
<div class="cp-welcome">
    <div class="d-flex justify-content-between align-items-start flex-wrap gap-3">
        <div>
            <h4 class="fw-700 mb-1">Welcome back, <?= e(explode(' ', $cl['name'])[0]) ?>!</h4>
            <p class="mb-0" style="opacity:.8;font-size:14px">Here's a summary of your vehicles and services.</p>
        </div>
        <a href="<?= BASE_URL ?>/client/bookings.php?new=1" class="btn btn-light btn-sm fw-600"><i class="fa fa-calendar-plus me-1"></i>New Booking</a>
    </div>
</div>

<!-- Stats -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="stat-card">
            <div class="stat-icon" style="background:#dbeafe;color:#2563eb"><i class="fa fa-car"></i></div>
            <div><div class="stat-label">My Vehicles</div><div class="stat-value"><?= count($cars) ?></div></div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card">
            <div class="stat-icon" style="background:#fef3c7;color:#d97706"><i class="fa fa-calendar-check"></i></div>
            <div><div class="stat-label">Active Bookings</div><div class="stat-value"><?= count($bookings) ?></div></div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card">
            <div class="stat-icon" style="background:#fee2e2;color:#dc2626"><i class="fa fa-file-invoice-dollar"></i></div>
            <div><div class="stat-label">Balance Due</div><div class="stat-value" style="font-size:16px"><?= money($unpaidAmt) ?></div></div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <a href="<?= BASE_URL ?>/client/assessments.php" style="text-decoration:none">
        <div class="stat-card">
            <div class="stat-icon" style="background:#f0fdf4;color:#16a34a"><i class="fa fa-list-check"></i></div>
            <?php
            $qaCount = (int)$db->prepare("SELECT COUNT(*) FROM quick_assessments qa JOIN cars c ON c.id=qa.car_id WHERE c.client_id=?")->execute([$cid]) ? 0 : 0;
            $qaStmt = $db->prepare("SELECT COUNT(*) FROM quick_assessments qa JOIN cars c ON c.id=qa.car_id WHERE c.client_id=?");
            $qaStmt->execute([$cid]); $qaCount = (int)$qaStmt->fetchColumn();
            ?>
            <div><div class="stat-label">Assessments</div><div class="stat-value"><?= $qaCount ?></div></div>
        </div>
        </a>
    </div>
    <div class="col-6 col-md-3">
        <a href="<?= BASE_URL ?>/client/notices.php" style="text-decoration:none">
        <div class="stat-card">
            <div class="stat-icon" style="background:#f5f3ff;color:#7c3aed"><i class="fa fa-bell"></i></div>
            <div><div class="stat-label">Unread Notices</div><div class="stat-value"><?= $unread ?></div></div>
        </div>
        </a>
    </div>
</div>

<div class="row g-4">
    <!-- My Vehicles -->
    <div class="col-md-5">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="fa fa-car me-2"></i>My Vehicles</span>
            </div>
            <div class="card-body p-0">
                <?php if ($cars): ?>
                <?php foreach ($cars as $car): ?>
                <div class="px-4 py-3 border-bottom d-flex justify-content-between align-items-center">
                    <div>
                        <div class="fw-semibold small"><?= e($car['make'] . ' ' . $car['model'] . ' ' . $car['year']) ?></div>
                        <div class="text-muted" style="font-size:12px"><?= e($car['chassis_number']) ?><?= $car['registration_number'] ? ' · ' . e($car['registration_number']) : '' ?></div>
                    </div>
                    <?= statusBadge($car['status']) ?>
                </div>
                <?php endforeach; ?>
                <?php else: ?>
                <p class="text-muted p-4 mb-0 small">No vehicles linked to your account.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Active Bookings + Recent Invoices -->
    <div class="col-md-7">
        <div class="card mb-4">
            <div class="card-header d-flex justify-content-between">
                <span><i class="fa fa-calendar me-2"></i>Active Bookings</span>
                <a href="<?= BASE_URL ?>/client/bookings.php" class="small">View all</a>
            </div>
            <div class="card-body p-0">
                <?php if ($bookings): ?>
                <?php $bColors=['pending'=>'warning','confirmed'=>'info','in_progress'=>'primary']; ?>
                <?php foreach ($bookings as $b): ?>
                <div class="px-4 py-3 border-bottom">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="fw-semibold small"><?= e($b['booking_number']) ?> — <?= e($b['service_type'] ?? 'Service') ?></div>
                            <div class="text-muted" style="font-size:12px"><?= $b['preferred_date'] ? 'Preferred: ' . fmtDate($b['preferred_date']) : fmtDate($b['booking_date']) ?></div>
                        </div>
                        <span class="badge bg-<?= $bColors[$b['status']] ?? 'secondary' ?>"><?= ucwords(str_replace('_',' ',$b['status'])) ?></span>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php else: ?>
                <p class="text-muted p-4 mb-0 small">No active bookings.</p>
                <?php endif; ?>
            </div>
        </div>

        <div class="card">
            <div class="card-header d-flex justify-content-between">
                <span><i class="fa fa-file-invoice me-2"></i>Recent Invoices</span>
                <a href="<?= BASE_URL ?>/client/invoices.php" class="small">View all</a>
            </div>
            <div class="card-body p-0">
                <?php if ($invoices): ?>
                <?php foreach ($invoices as $inv): ?>
                <div class="px-4 py-3 border-bottom d-flex justify-content-between align-items-center">
                    <div>
                        <div class="fw-semibold small"><?= e($inv['invoice_number']) ?></div>
                        <div class="text-muted" style="font-size:12px"><?= fmtDate($inv['date']) ?></div>
                    </div>
                    <div class="text-end">
                        <div class="fw-bold small"><?= money((float)$inv['total']) ?></div>
                        <?= statusBadge($inv['status']) ?>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php else: ?>
                <p class="text-muted p-4 mb-0 small">No invoices yet.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>

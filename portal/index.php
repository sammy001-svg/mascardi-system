<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/portal_auth.php';
$pageTitle = 'Dashboard';
$db     = getDB();
$client = portalClient();
$cid    = $client['id'];

// Financial summary
$finStmt = $db->prepare("
    SELECT COALESCE(SUM(total),0) AS total_billed,
           COALESCE(SUM(amount_paid),0) AS total_paid
    FROM invoices WHERE client_id=? AND status NOT IN ('cancelled')
");
$finStmt->execute([$cid]); $fin = $finStmt->fetch();
$outstanding = (float)$fin['total_billed'] - (float)$fin['total_paid'];

// Last payment
$lastPay = $db->prepare("
    SELECT p.amount, p.payment_date FROM payments p
    LEFT JOIN invoices i ON i.id = p.invoice_id
    LEFT JOIN service_bookings sb ON sb.id = p.service_booking_id
    WHERE (i.client_id = ? OR sb.client_id = ?) AND p.status = 'confirmed'
    ORDER BY p.payment_date DESC LIMIT 1
");
$lastPay->execute([$cid, $cid]); $lastPay = $lastPay->fetch();

// Vehicles
$vehicles = $db->prepare("SELECT * FROM cars WHERE client_id=? ORDER BY created_at DESC");
$vehicles->execute([$cid]); $vehicles = $vehicles->fetchAll();

// Active bookings count
$activeBk = $db->prepare("SELECT COUNT(*) FROM service_bookings WHERE client_id=? AND status IN ('pending','confirmed','in_progress')");
$activeBk->execute([$cid]); $activeBk = (int)$activeBk->fetchColumn();

// Recent bookings
$recentBk = $db->prepare("
    SELECT * FROM service_bookings WHERE client_id=? ORDER BY created_at DESC LIMIT 5
");
$recentBk->execute([$cid]); $recentBk = $recentBk->fetchAll();

include __DIR__ . '/header.php';
?>

<!-- Hero -->
<div class="no-print" style="background:#1e293b;color:#fff;padding:1.5rem 0;margin-top:-1.75rem;margin-bottom:1.75rem">
<div class="container">
    <h4 class="mb-1">Welcome back, <?= e(explode(' ', $client['name'])[0]) ?> <i class="fa fa-hand-wave ms-1 text-warning" style="font-size:18px"></i></h4>
    <div style="color:rgba(255,255,255,.6);font-size:13px"><?= date('l, d F Y') ?> &mdash; Here's your account overview</div>
</div>
</div>

<!-- Stat cards -->
<div class="row g-3 mb-4">
    <div class="col-6 col-lg-3">
        <div class="p-stat">
            <div class="p-stat-icon" style="background:#dbeafe;color:#2563eb"><i class="fa fa-car"></i></div>
            <div>
                <div class="p-stat-label">My Vehicles</div>
                <div class="p-stat-value"><?= count($vehicles) ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="p-stat">
            <div class="p-stat-icon" style="background:#dcfce7;color:#16a34a"><i class="fa fa-calendar-check"></i></div>
            <div>
                <div class="p-stat-label">Active Bookings</div>
                <div class="p-stat-value"><?= $activeBk ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="p-stat">
            <div class="p-stat-icon" style="background:<?= $outstanding > 0 ? '#fee2e2' : '#dcfce7' ?>;color:<?= $outstanding > 0 ? '#dc2626' : '#16a34a' ?>"><i class="fa fa-money-bill-wave"></i></div>
            <div>
                <div class="p-stat-label">Outstanding</div>
                <div class="p-stat-value" style="color:<?= $outstanding > 0 ? '#dc2626' : '#16a34a' ?>;font-size:16px"><?= money($outstanding) ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="p-stat">
            <div class="p-stat-icon" style="background:#f3e8ff;color:#7c3aed"><i class="fa fa-receipt"></i></div>
            <div>
                <div class="p-stat-label">Last Payment</div>
                <div class="p-stat-value" style="font-size:14px"><?= $lastPay ? money($lastPay['amount']) : '—' ?></div>
                <?php if ($lastPay): ?><div style="font-size:11px;color:#64748b"><?= fmtDate($lastPay['payment_date']) ?></div><?php endif; ?>
            </div>
        </div>
    </div>
</div>

<div class="row g-4">
    <!-- My Vehicles -->
    <div class="col-lg-7">
        <div class="p-card">
            <div class="p-card-header">
                <span><i class="fa fa-car me-2 text-primary"></i>My Vehicles</span>
                <a href="<?= BASE_URL ?>/portal/bookings.php?action=new" class="btn btn-sm btn-primary">
                    <i class="fa fa-plus me-1"></i>New Booking
                </a>
            </div>
            <?php if (empty($vehicles)): ?>
            <div class="p-card-body text-center py-5 text-muted">
                <i class="fa fa-car fa-2x mb-2 d-block"></i>No vehicles registered to your account yet.
            </div>
            <?php else: ?>
            <table class="table mb-0">
                <thead style="font-size:12px;color:#64748b;text-transform:uppercase;letter-spacing:.04em">
                    <tr>
                        <th class="ps-4 py-2">Vehicle</th>
                        <th class="py-2">Reg No.</th>
                        <th class="py-2">Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($vehicles as $v): ?>
                    <tr>
                        <td class="ps-4 py-3">
                            <div class="fw-semibold small"><?= e($v['make'] . ' ' . $v['model'] . ' ' . $v['year']) ?></div>
                            <div style="font-size:11px;color:#94a3b8">Chassis: <code><?= e($v['chassis_number']) ?></code></div>
                        </td>
                        <td class="py-3">
                            <?php if ($v['registration_number']): ?>
                            <span class="badge bg-dark"><?= e($v['registration_number']) ?></span>
                            <?php else: ?><span class="text-muted small">—</span><?php endif; ?>
                        </td>
                        <td class="py-3"><?= statusBadge($v['status']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
    </div>

    <!-- Recent Bookings -->
    <div class="col-lg-5">
        <div class="p-card">
            <div class="p-card-header">
                <span><i class="fa fa-calendar-check me-2 text-success"></i>Recent Bookings</span>
                <a href="<?= BASE_URL ?>/portal/bookings.php" class="btn btn-xs btn-outline-secondary">View All</a>
            </div>
            <?php if (empty($recentBk)): ?>
            <div class="p-card-body text-center py-4 text-muted small">No bookings yet.</div>
            <?php else: ?>
            <div class="list-group list-group-flush">
                <?php foreach ($recentBk as $bk): ?>
                <a href="<?= BASE_URL ?>/portal/booking_view.php?id=<?= $bk['id'] ?>"
                   class="list-group-item list-group-item-action px-4 py-3">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="fw-semibold small"><?= e($bk['booking_number']) ?></div>
                            <div style="font-size:11.5px;color:#64748b"><?= fmtDate($bk['preferred_date'] ?: $bk['booking_date']) ?></div>
                        </div>
                        <?= statusBadge($bk['status']) ?>
                    </div>
                    <?php if ($bk['service_type']): ?>
                    <div style="font-size:12px;color:#94a3b8;margin-top:3px"><?= e($bk['service_type']) ?></div>
                    <?php endif; ?>
                </a>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>

        <?php if ($outstanding > 0): ?>
        <div class="p-card" style="border-left:4px solid #dc2626">
            <div class="p-card-body">
                <div class="d-flex align-items-center gap-3">
                    <div style="width:40px;height:40px;border-radius:10px;background:#fee2e2;color:#dc2626;display:flex;align-items:center;justify-content:center;flex-shrink:0">
                        <i class="fa fa-triangle-exclamation"></i>
                    </div>
                    <div>
                        <div class="fw-semibold small">Outstanding Balance</div>
                        <div class="fw-bold text-danger"><?= money($outstanding) ?></div>
                    </div>
                    <a href="<?= BASE_URL ?>/portal/invoices.php" class="btn btn-sm btn-danger ms-auto">View Invoices</a>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . '/footer.php'; ?>

<?php
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
canAccess('sales') || die('Access denied.');
$pageTitle = 'Sales';
$db = getDB();

// Stats
$stats = $db->query("
    SELECT
        COUNT(*)                                              AS total_sales,
        COALESCE(SUM(sale_price),0)                          AS total_revenue,
        COALESCE(SUM(CASE WHEN MONTH(sale_date)=MONTH(NOW()) AND YEAR(sale_date)=YEAR(NOW()) THEN sale_price ELSE 0 END),0) AS month_revenue,
        SUM(delivered_at IS NULL AND status='active')        AS pending_delivery
    FROM car_sales WHERE status='active'
")->fetch();

// Sales list
$sales = $db->query("
    SELECT cs.*, c.make, c.model, c.year, c.chassis_number, c.registration_number,
           u.name AS sold_by_name
    FROM car_sales cs
    JOIN cars c ON c.id = cs.car_id
    LEFT JOIN users u ON u.id = cs.sold_by
    WHERE cs.status = 'active'
    ORDER BY cs.sale_date DESC, cs.id DESC
")->fetchAll();

include __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="mb-0"><i class="fa fa-tag me-2 text-success"></i>Sales</h5>
    <?php if (canWrite('sales')): ?>
    <a href="add.php" class="btn btn-sm btn-success"><i class="fa fa-plus me-1"></i>Record Sale</a>
    <?php endif; ?>
</div>

<!-- Stats -->
<div class="row g-3 mb-4">
    <div class="col-6 col-xl-3">
        <div class="stat-card" style="border-left:4px solid #16a34a">
            <div class="stat-icon" style="background:#dcfce7;color:#16a34a"><i class="fa fa-tag"></i></div>
            <div class="stat-info">
                <div class="stat-label">Total Sales</div>
                <div class="stat-value"><?= $stats['total_sales'] ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-xl-3">
        <div class="stat-card" style="border-left:4px solid #2563eb">
            <div class="stat-icon" style="background:#dbeafe;color:#2563eb"><i class="fa fa-money-bill-wave"></i></div>
            <div class="stat-info">
                <div class="stat-label">This Month</div>
                <div class="stat-value stat-value-sm"><?= money((float)$stats['month_revenue']) ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-xl-3">
        <div class="stat-card" style="border-left:4px solid #9333ea">
            <div class="stat-icon" style="background:#f3e8ff;color:#9333ea"><i class="fa fa-chart-line"></i></div>
            <div class="stat-info">
                <div class="stat-label">Total Revenue</div>
                <div class="stat-value stat-value-sm"><?= money((float)$stats['total_revenue']) ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-xl-3">
        <div class="stat-card" style="border-left:4px solid #f59e0b">
            <div class="stat-icon" style="background:#fef3c7;color:#d97706"><i class="fa fa-truck"></i></div>
            <div class="stat-info">
                <div class="stat-label">Pending Delivery</div>
                <div class="stat-value"><?= $stats['pending_delivery'] ?></div>
            </div>
        </div>
    </div>
</div>

<!-- Table -->
<div class="card">
    <div class="card-body p-0">
        <table class="table table-hover datatable mb-0">
            <thead>
                <tr>
                    <th class="ps-3">Sale #</th>
                    <th>Vehicle</th>
                    <th>Buyer</th>
                    <th>Sale Date</th>
                    <th class="text-end">Sale Price</th>
                    <th>Payment</th>
                    <th>Delivery</th>
                    <th>Sold By</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($sales as $s): ?>
                <tr>
                    <td class="ps-3 fw-semibold"><?= e($s['sale_number']) ?></td>
                    <td>
                        <div class="fw-medium small"><?= e($s['make'].' '.$s['model'].' '.$s['year']) ?></div>
                        <?php if ($s['registration_number']): ?>
                        <span class="badge bg-dark" style="font-size:10px"><?= e($s['registration_number']) ?></span>
                        <?php else: ?>
                        <div class="text-muted" style="font-size:11px"><code><?= e(substr($s['chassis_number'],0,12)) ?>…</code></div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="small fw-medium"><?= e($s['buyer_name']) ?></div>
                        <?php if ($s['buyer_phone']): ?><div class="text-muted" style="font-size:11px"><?= e($s['buyer_phone']) ?></div><?php endif; ?>
                    </td>
                    <td class="small"><?= fmtDate($s['sale_date']) ?></td>
                    <td class="text-end fw-semibold"><?= money((float)$s['sale_price']) ?></td>
                    <td><?= statusBadge($s['payment_status']) ?></td>
                    <td>
                        <?php if ($s['delivered_at']): ?>
                        <span class="badge bg-success"><i class="fa fa-check me-1"></i><?= fmtDate($s['delivered_at']) ?></span>
                        <?php else: ?>
                        <span class="badge bg-warning text-dark">Pending</span>
                        <?php endif; ?>
                    </td>
                    <td class="small text-muted"><?= e($s['sold_by_name'] ?? '—') ?></td>
                    <td class="pe-3 text-end">
                        <a href="view.php?id=<?= $s['id'] ?>" class="btn btn-xs btn-outline-primary">View</a>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($sales)): ?>
                <tr><td colspan="9" class="text-center text-muted py-5">No sales recorded yet. <a href="add.php">Record the first sale.</a></td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>

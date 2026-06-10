<?php
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
canAccess('reports') || die('Access denied.');
$db   = getDB();
$user = authUser();

// Inline migration for rotation columns
foreach ([
    "ALTER TABLE cars ADD COLUMN last_rotated_at DATE NULL",
    "ALTER TABLE cars ADD COLUMN rotation_notes TEXT NULL",
] as $_mig) { try { $db->exec($_mig); } catch (\Throwable $_e) {} }

// Rotation threshold settings
$ROTATION_DAYS = 30;

// Filter
$filterLoc = (int)($_GET['location_id'] ?? 0);

$locations = $db->query("SELECT id, name FROM locations WHERE status='active' ORDER BY name")->fetchAll();

$whereClause = "WHERE c.car_type = 'inventory' AND c.status NOT IN ('delivered','in_transit')";
$params = [];
if ($filterLoc) {
    $whereClause .= " AND c.location_id = ?";
    $params[] = $filterLoc;
}

$cars = $db->prepare("
    SELECT c.*,
           l.name AS location_name,
           DATEDIFF(NOW(), COALESCE(c.last_rotated_at, c.created_at)) AS days_at_location,
           (SELECT COUNT(st.id) FROM showroom_transfers st
            WHERE st.car_id = c.id AND st.status NOT IN ('cancelled')) AS transfer_count
    FROM cars c
    LEFT JOIN locations l ON l.id = c.location_id
    {$whereClause}
    ORDER BY days_at_location DESC
");
$cars->execute($params);
$cars = $cars->fetchAll();

// Stats
$overdueCount   = count(array_filter($cars, fn($c) => (int)$c['days_at_location'] >= $ROTATION_DAYS));
$totalCars      = count($cars);
$avgDays        = $totalCars ? round(array_sum(array_column($cars, 'days_at_location')) / $totalCars) : 0;

$pageTitle = 'Stock Rotation Report';
include __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h5 class="mb-1"><i class="fa fa-rotate me-2 text-primary"></i>Stock Rotation Report</h5>
        <div class="text-muted small">Cars ranked by days at current location — rotate those over <?= $ROTATION_DAYS ?> days</div>
    </div>
    <?php if (canAccess('showroom_transfers') && canWrite('showroom_transfers')): ?>
    <a href="<?= BASE_URL ?>/modules/showroom_transfers/add.php?type=stock_rotation" class="btn btn-success btn-sm">
        <i class="fa fa-right-left me-1"></i>New Rotation Transfer
    </a>
    <?php endif; ?>
</div>

<!-- Stats -->
<div class="row g-3 mb-4">
    <div class="col-sm-4">
        <div class="card text-center h-100">
            <div class="card-body py-3">
                <div style="font-size:32px;font-weight:800;color:#2563eb"><?= $totalCars ?></div>
                <div class="text-muted small">Showroom Cars</div>
            </div>
        </div>
    </div>
    <div class="col-sm-4">
        <div class="card text-center h-100 <?= $overdueCount ? 'border-warning' : '' ?>">
            <div class="card-body py-3">
                <div style="font-size:32px;font-weight:800;color:<?= $overdueCount ? '#d97706' : '#22c55e' ?>"><?= $overdueCount ?></div>
                <div class="text-muted small">Due for Rotation (&gt;<?= $ROTATION_DAYS ?> days)</div>
            </div>
        </div>
    </div>
    <div class="col-sm-4">
        <div class="card text-center h-100">
            <div class="card-body py-3">
                <div style="font-size:32px;font-weight:800;color:#64748b"><?= $avgDays ?></div>
                <div class="text-muted small">Avg Days at Location</div>
            </div>
        </div>
    </div>
</div>

<!-- Filter -->
<form method="GET" class="card mb-4">
    <div class="card-body py-2">
        <div class="d-flex gap-3 align-items-center flex-wrap">
            <div class="d-flex align-items-center gap-2">
                <label class="form-label mb-0 text-muted small fw-semibold">Filter by Location:</label>
                <select name="location_id" class="form-select form-select-sm" style="width:200px" onchange="this.form.submit()">
                    <option value="">All Locations</option>
                    <?php foreach ($locations as $loc): ?>
                    <option value="<?= $loc['id'] ?>" <?= $filterLoc == $loc['id'] ? 'selected' : '' ?>><?= e($loc['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php if ($filterLoc): ?>
            <a href="stock_rotation.php" class="btn btn-xs btn-outline-secondary">Clear</a>
            <?php endif; ?>
        </div>
    </div>
</form>

<!-- Cars table -->
<div class="card">
    <div class="card-body p-0">
        <div class="table-responsive">
        <table class="table table-hover datatable mb-0">
            <thead>
                <tr>
                    <th class="ps-3">Vehicle</th>
                    <th>Reg. No.</th>
                    <th>Current Location</th>
                    <th>Last Rotated</th>
                    <th>Days at Location</th>
                    <th>Transfers</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($cars as $c):
                    $days    = (int)$c['days_at_location'];
                    $overdue = $days >= $ROTATION_DAYS;
                    $warning = $days >= (int)($ROTATION_DAYS * 0.7) && !$overdue;
                ?>
                <tr class="<?= $overdue ? 'table-warning' : '' ?>">
                    <td class="ps-3 fw-semibold">
                        <a href="<?= BASE_URL ?>/modules/cars/view.php?id=<?= $c['id'] ?>" class="text-decoration-none">
                            <?= e($c['make'] . ' ' . $c['model']) ?>
                            <span class="text-muted fw-normal"><?= $c['year'] ?></span>
                        </a>
                        <?php if ($c['color']): ?>
                        <div class="text-muted small"><?= e($c['color']) ?></div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($c['registration_number']): ?>
                        <span class="badge bg-dark font-monospace"><?= e($c['registration_number']) ?></span>
                        <?php else: ?>—<?php endif; ?>
                    </td>
                    <td class="small">
                        <?php if ($c['location_name']): ?>
                        <i class="fa fa-location-dot me-1 text-primary"></i><?= e($c['location_name']) ?>
                        <?php else: ?><span class="text-muted">—</span><?php endif; ?>
                    </td>
                    <td class="small text-muted">
                        <?= $c['last_rotated_at'] ? fmtDate($c['last_rotated_at'], 'd M Y') : '<span class="text-muted">Never</span>' ?>
                    </td>
                    <td>
                        <span class="badge bg-<?= $overdue ? 'danger' : ($warning ? 'warning text-dark' : 'success') ?> px-2">
                            <?= $days ?> day<?= $days !== 1 ? 's' : '' ?>
                        </span>
                        <?php if ($overdue): ?>
                        <span class="badge bg-danger bg-opacity-20 text-danger ms-1" style="font-size:10px">Rotate Now</span>
                        <?php elseif ($warning): ?>
                        <span class="badge bg-warning bg-opacity-30 text-warning ms-1" style="font-size:10px">Due Soon</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-muted small text-center"><?= $c['transfer_count'] ?></td>
                    <td>
                        <span class="badge bg-<?= match($c['status']) {
                            'arrived' => 'success',
                            'in_assessment' => 'info',
                            'in_workshop' => 'warning',
                            default => 'secondary'
                        } ?>">
                            <?= ucfirst(str_replace('_', ' ', $c['status'])) ?>
                        </span>
                    </td>
                    <td>
                        <a href="<?= BASE_URL ?>/modules/cars/view.php?id=<?= $c['id'] ?>" class="btn btn-xs btn-outline-primary" title="View Car">
                            <i class="fa fa-eye"></i>
                        </a>
                        <?php if (canAccess('showroom_transfers') && canWrite('showroom_transfers')): ?>
                        <a href="<?= BASE_URL ?>/modules/showroom_transfers/add.php?car_id=<?= $c['id'] ?>&type=stock_rotation"
                           class="btn btn-xs btn-outline-success" title="Raise Rotation Transfer">
                            <i class="fa fa-right-left"></i>
                        </a>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (!$cars): ?>
                <tr><td colspan="8" class="text-center text-muted py-4">No showroom cars found.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
        </div>
    </div>
</div>

<!-- Legend -->
<div class="d-flex gap-3 mt-3 flex-wrap">
    <div class="d-flex align-items-center gap-1">
        <span class="badge bg-danger">&gt;<?= $ROTATION_DAYS ?>d</span>
        <span class="text-muted small">Rotate Now</span>
    </div>
    <div class="d-flex align-items-center gap-1">
        <span class="badge bg-warning text-dark">&gt;<?= (int)($ROTATION_DAYS*0.7) ?>d</span>
        <span class="text-muted small">Due Soon</span>
    </div>
    <div class="d-flex align-items-center gap-1">
        <span class="badge bg-success">&lt;<?= (int)($ROTATION_DAYS*0.7) ?>d</span>
        <span class="text-muted small">Fresh</span>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>

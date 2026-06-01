<?php
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
canAccess('car_costs') || redirect(BASE_URL . '/index.php');

$pageTitle = 'Car Import Costs';
$db = getDB();

$filterStatus = $_GET['status'] ?? '';
$search       = trim($_GET['q'] ?? '');

$where  = ['1=1'];
$params = [];
if ($filterStatus) { $where[] = 'c.status = ?'; $params[] = $filterStatus; }
if ($search) {
    $where[] = '(c.make LIKE ? OR c.model LIKE ? OR c.chassis_number LIKE ? OR c.registration_number LIKE ?)';
    $params  = array_merge($params, ["%$search%","%$search%","%$search%","%$search%"]);
}
$whereStr = implode(' AND ', $where);

try {
    $cars = $db->prepare("
        SELECT c.id, c.make, c.model, c.year, c.chassis_number, c.registration_number, c.status,
               cc.id AS cost_id,
               COALESCE(cc.purchase_price,0)    AS purchase_price,
               COALESCE(cc.freight,0)            AS freight,
               COALESCE(cc.marine_insurance,0)   AS marine_insurance,
               COALESCE(cc.port_charges,0)        AS port_charges,
               COALESCE(cc.duty_tax,0)            AS duty_tax,
               COALESCE(cc.clearing_fees,0)       AS clearing_fees,
               COALESCE(cc.transport_to_yard,0)   AS transport_to_yard,
               COALESCE(cc.workshop_costs,0)      AS workshop_costs,
               COALESCE(cc.other_costs,0)         AS other_costs,
               COALESCE(cc.purchase_price + cc.freight + cc.marine_insurance + cc.port_charges
                      + cc.duty_tax + cc.clearing_fees + cc.transport_to_yard
                      + cc.workshop_costs + cc.other_costs, 0) AS total_cost,
               cs.sale_price,
               cs.id AS sale_id, cs.sale_number
        FROM cars c
        LEFT JOIN car_costs cc ON cc.car_id = c.id
        LEFT JOIN car_sales cs ON cs.car_id = c.id AND cs.status = 'active'
        WHERE $whereStr
        ORDER BY c.created_at DESC
    ");
    $cars->execute($params);
    $cars = $cars->fetchAll();

    // Summary
    $summary = $db->query("
        SELECT
            COUNT(DISTINCT cc.car_id)                                           AS cars_with_costs,
            COALESCE(SUM(cc.purchase_price + cc.freight + cc.marine_insurance
                       + cc.port_charges + cc.duty_tax + cc.clearing_fees
                       + cc.transport_to_yard + cc.workshop_costs + cc.other_costs), 0) AS total_invested,
            COALESCE(SUM(CASE WHEN cs.sale_price IS NOT NULL
                THEN cs.sale_price - (cc.purchase_price + cc.freight + cc.marine_insurance
                    + cc.port_charges + cc.duty_tax + cc.clearing_fees
                    + cc.transport_to_yard + cc.workshop_costs + cc.other_costs)
                ELSE 0 END), 0) AS realised_profit
        FROM car_costs cc
        LEFT JOIN car_sales cs ON cs.car_id = cc.car_id AND cs.status = 'active'
    ")->fetch();
} catch (\Throwable $e) {
    $cars = []; $summary = ['cars_with_costs'=>0,'total_invested'=>0,'realised_profit'=>0];
}

include __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="mb-0"><i class="fa fa-calculator me-2 text-primary"></i>Car Import Costs &amp; Margins</h5>
</div>

<!-- Summary -->
<div class="row g-3 mb-4">
    <div class="col-sm-4">
        <div class="stat-card" style="border-left:4px solid #2563eb">
            <div class="stat-icon" style="background:#dbeafe;color:#2563eb"><i class="fa fa-car"></i></div>
            <div class="stat-info">
                <div class="stat-label">Cars with Costs Recorded</div>
                <div class="stat-value"><?= (int)$summary['cars_with_costs'] ?></div>
            </div>
        </div>
    </div>
    <div class="col-sm-4">
        <div class="stat-card" style="border-left:4px solid #dc2626">
            <div class="stat-icon" style="background:#fee2e2;color:#dc2626"><i class="fa fa-arrow-down"></i></div>
            <div class="stat-info">
                <div class="stat-label">Total Capital Invested</div>
                <div class="stat-value stat-value-sm"><?= money((float)$summary['total_invested']) ?></div>
            </div>
        </div>
    </div>
    <div class="col-sm-4">
        <?php $profitColor = (float)$summary['realised_profit'] >= 0 ? '#16a34a' : '#dc2626'; ?>
        <div class="stat-card" style="border-left:4px solid <?= $profitColor ?>">
            <div class="stat-icon" style="background:<?= (float)$summary['realised_profit'] >= 0 ? '#dcfce7' : '#fee2e2' ?>;color:<?= $profitColor ?>">
                <i class="fa fa-chart-line"></i>
            </div>
            <div class="stat-info">
                <div class="stat-label">Realised Gross Profit</div>
                <div class="stat-value stat-value-sm" style="color:<?= $profitColor ?>">
                    <?= money((float)$summary['realised_profit']) ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Filter -->
<div class="card mb-3">
    <div class="card-body py-2">
        <form method="GET" class="d-flex gap-2 flex-wrap align-items-center">
            <input type="text" name="q" class="form-control form-control-sm" style="width:220px"
                   placeholder="Search make, model, chassis…" value="<?= e($search) ?>">
            <select name="status" class="form-select form-select-sm" style="width:160px">
                <option value="">All Statuses</option>
                <?php foreach (['in_transit','arrived','in_assessment','in_workshop','completed','sold','delivered'] as $s): ?>
                <option value="<?= $s ?>" <?= $filterStatus === $s ? 'selected' : '' ?>><?= ucwords(str_replace('_',' ',$s)) ?></option>
                <?php endforeach; ?>
            </select>
            <button class="btn btn-sm btn-primary"><i class="fa fa-filter me-1"></i>Filter</button>
            <a href="index.php" class="btn btn-sm btn-outline-secondary">Clear</a>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-body p-0">
        <table class="table table-hover datatable mb-0" style="font-size:13px">
            <thead>
                <tr>
                    <th class="ps-3">Vehicle</th>
                    <th>Status</th>
                    <th class="text-end">Total Cost</th>
                    <th class="text-end">Sale Price</th>
                    <th class="text-end">Gross Profit</th>
                    <th class="text-end">Margin %</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($cars as $car):
                $totalCost  = (float)$car['total_cost'];
                $salePrice  = $car['sale_price'] !== null ? (float)$car['sale_price'] : null;
                $profit     = $salePrice !== null && $totalCost > 0 ? $salePrice - $totalCost : null;
                $margin     = $salePrice && $salePrice > 0 && $profit !== null ? round($profit / $salePrice * 100, 1) : null;
                $profitColor = $profit === null ? '' : ($profit >= 0 ? 'text-success fw-semibold' : 'text-danger fw-semibold');
                $marginColor = $margin === null ? '' : ($margin >= 20 ? 'bg-success' : ($margin >= 10 ? 'bg-warning' : 'bg-danger'));
            ?>
            <tr>
                <td class="ps-3">
                    <a href="<?= BASE_URL ?>/modules/cars/view.php?id=<?= $car['id'] ?>" class="fw-semibold text-decoration-none">
                        <?= e($car['make'].' '.$car['model'].' '.$car['year']) ?>
                    </a>
                    <div class="text-muted" style="font-size:11px"><code><?= e($car['chassis_number']) ?></code></div>
                </td>
                <td><?= statusBadge($car['status']) ?></td>
                <td class="text-end">
                    <?php if ($totalCost > 0): ?>
                    <span class="fw-medium"><?= money($totalCost) ?></span>
                    <?php else: ?>
                    <span class="text-muted fst-italic small">Not recorded</span>
                    <?php endif; ?>
                </td>
                <td class="text-end">
                    <?php if ($salePrice !== null): ?>
                    <a href="<?= BASE_URL ?>/modules/sales/view.php?id=<?= $car['sale_id'] ?>" class="text-decoration-none fw-medium text-success">
                        <?= money($salePrice) ?>
                    </a>
                    <?php else: ?>
                    <span class="text-muted">—</span>
                    <?php endif; ?>
                </td>
                <td class="text-end <?= $profitColor ?>">
                    <?= $profit !== null ? money($profit) : '<span class="text-muted">—</span>' ?>
                </td>
                <td class="text-end">
                    <?php if ($margin !== null): ?>
                    <span class="badge <?= $marginColor ?>"><?= $margin ?>%</span>
                    <?php else: ?>
                    <span class="text-muted">—</span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if (canWrite('car_costs')): ?>
                    <a href="edit.php?car_id=<?= $car['id'] ?>"
                       class="btn btn-xs <?= $car['cost_id'] ? 'btn-outline-secondary' : 'btn-outline-primary' ?>">
                        <i class="fa fa-<?= $car['cost_id'] ? 'pen' : 'plus' ?> me-1"></i>
                        <?= $car['cost_id'] ? 'Edit' : 'Add Costs' ?>
                    </a>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($cars)): ?>
            <tr><td colspan="7" class="text-center py-5 text-muted">No cars found</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>

<?php
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
canAccess('sales') || die('Access denied.');
$pageTitle = 'Sales';
$db = getDB();

// Inline migration — silent no-op if column already exists
try { $db->exec("ALTER TABLE car_sales ADD COLUMN cost_price DECIMAL(15,2) NULL DEFAULT NULL"); } catch (\Throwable $_) {}

$canSeeProfit = hasRole(['admin','super_admin','general_manager','sales_manager','finance_manager','finance_officer']);

// ── Filter params ─────────────────────────────────────────────────────────────
$fDateFrom  = $_GET['date_from']      ?? '';
$fDateTo    = $_GET['date_to']        ?? '';
$fPayStatus = $_GET['payment_status'] ?? '';
$fSoldBy    = (int)($_GET['sold_by']  ?? 0);
$isFiltered = $fDateFrom || $fDateTo || $fPayStatus || $fSoldBy;

// Salesperson list for the filter dropdown
$salespeople = $db->query("
    SELECT DISTINCT u.id, u.name
    FROM users u INNER JOIN car_sales cs ON cs.sold_by = u.id
    ORDER BY u.name
")->fetchAll();

// ── Build WHERE clause ────────────────────────────────────────────────────────
$where  = ["cs.status = 'active'"];
$params = [];
if ($fDateFrom)  { $where[] = 'cs.sale_date >= ?'; $params[] = $fDateFrom; }
if ($fDateTo)    { $where[] = 'cs.sale_date <= ?'; $params[] = $fDateTo; }
if ($fPayStatus) { $where[] = 'cs.payment_status = ?'; $params[] = $fPayStatus; }
if ($fSoldBy)    { $where[] = 'cs.sold_by = ?'; $params[] = $fSoldBy; }
$whereSQL = implode(' AND ', $where);

// ── Stats (respect active filters) ───────────────────────────────────────────
$statsStmt = $db->prepare("
    SELECT
        COUNT(*)                                              AS total_sales,
        COALESCE(SUM(cs.sale_price),0)                       AS total_revenue,
        COALESCE(SUM(CASE WHEN MONTH(cs.sale_date)=MONTH(NOW()) AND YEAR(cs.sale_date)=YEAR(NOW())
                          THEN cs.sale_price ELSE 0 END),0)  AS month_revenue,
        SUM(cs.delivered_at IS NULL)                         AS pending_delivery,
        COALESCE(SUM(CASE WHEN cs.cost_price IS NOT NULL
                          THEN cs.sale_price - cs.cost_price ELSE 0 END),0) AS total_profit,
        COALESCE(SUM(CASE WHEN MONTH(cs.sale_date)=MONTH(NOW()) AND YEAR(cs.sale_date)=YEAR(NOW())
                               AND cs.cost_price IS NOT NULL
                          THEN cs.sale_price - cs.cost_price ELSE 0 END),0) AS month_profit
    FROM car_sales cs
    WHERE $whereSQL
");
$statsStmt->execute($params);
$stats = $statsStmt->fetch();

// ── Monthly chart data — last 12 months, ignoring user filter ────────────────
try {
    $monthlyRows = $db->query("
        SELECT DATE_FORMAT(sale_date,'%b %Y') AS label,
               YEAR(sale_date) AS yr, MONTH(sale_date) AS mo,
               COUNT(*)                                AS units,
               COALESCE(SUM(sale_price),0)             AS revenue,
               COALESCE(SUM(CASE WHEN cost_price IS NOT NULL
                                 THEN sale_price - cost_price ELSE 0 END),0) AS profit
        FROM car_sales
        WHERE status='active'
          AND sale_date >= DATE_FORMAT(DATE_SUB(NOW(), INTERVAL 11 MONTH),'%Y-%m-01')
        GROUP BY YEAR(sale_date), MONTH(sale_date)
        ORDER BY YEAR(sale_date), MONTH(sale_date)
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (\Throwable $_) { $monthlyRows = []; }

$monthlyMap = [];
foreach ($monthlyRows as $r) $monthlyMap[$r['yr'].'-'.$r['mo']] = $r;

$chartLabels = $chartRevenue = $chartUnits = $chartProfit = [];
for ($i = 11; $i >= 0; $i--) {
    $ts  = strtotime("-$i months");
    $yr  = (int)date('Y', $ts);
    $mo  = (int)date('n', $ts);
    $key = "$yr-$mo";
    $chartLabels[]  = date('M Y', $ts);
    $chartRevenue[] = round((float)($monthlyMap[$key]['revenue'] ?? 0) / 1000, 1);
    $chartUnits[]   = (int)($monthlyMap[$key]['units'] ?? 0);
    $chartProfit[]  = round((float)($monthlyMap[$key]['profit']  ?? 0) / 1000, 1);
}

// ── Salesperson leaderboard ───────────────────────────────────────────────────
try {
    $leaderboard = $db->query("
        SELECT COALESCE(u.name,'Unassigned')                  AS agent,
               COUNT(cs.id)                                   AS units_all,
               COALESCE(SUM(cs.sale_price),0)                 AS rev_all,
               COALESCE(SUM(CASE WHEN cs.cost_price IS NOT NULL
                                 THEN cs.sale_price - cs.cost_price ELSE 0 END),0) AS profit_all,
               SUM(MONTH(cs.sale_date)=MONTH(NOW()) AND YEAR(cs.sale_date)=YEAR(NOW())) AS units_month,
               COALESCE(SUM(CASE WHEN MONTH(cs.sale_date)=MONTH(NOW()) AND YEAR(cs.sale_date)=YEAR(NOW())
                                 THEN cs.sale_price ELSE 0 END),0)                   AS rev_month
        FROM car_sales cs
        LEFT JOIN users u ON u.id = cs.sold_by
        WHERE cs.status = 'active'
        GROUP BY cs.sold_by, u.name
        ORDER BY units_month DESC, units_all DESC
        LIMIT 10
    ")->fetchAll();
} catch (\Throwable $_) { $leaderboard = []; }

// ── Sales list ────────────────────────────────────────────────────────────────
$listStmt = $db->prepare("
    SELECT cs.*, c.make, c.model, c.year, c.chassis_number, c.registration_number,
           u.name AS sold_by_name
    FROM car_sales cs
    JOIN cars c ON c.id = cs.car_id
    LEFT JOIN users u ON u.id = cs.sold_by
    WHERE $whereSQL
    ORDER BY cs.sale_date DESC, cs.id DESC
");
$listStmt->execute($params);
$sales = $listStmt->fetchAll();

// ── Chart.js ──────────────────────────────────────────────────────────────────
$profitDataset = $canSeeProfit
    ? '{ type:"bar",  label:"Profit (KES\'000s)", data:profit, backgroundColor:"rgba(34,197,94,.7)", borderRadius:4 },'
    : '';

$extraJs = '<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
(function() {
    var labels  = ' . json_encode($chartLabels) . ';
    var revenue = ' . json_encode($chartRevenue) . ';
    var units   = ' . json_encode($chartUnits) . ';
    var profit  = ' . json_encode($chartProfit) . ';
    new Chart(document.getElementById("revenueChart"), {
        data: {
            labels: labels,
            datasets: [
                { type:"bar",  label:"Revenue (KES\'000s)", data:revenue, backgroundColor:"rgba(37,99,235,.75)", borderRadius:4 },
                ' . $profitDataset . '
                { type:"line", label:"Units Sold", data:units, borderColor:"#f59e0b", backgroundColor:"transparent",
                  tension:.35, pointRadius:4, pointBackgroundColor:"#f59e0b", yAxisID:"y2" }
            ]
        },
        options:{
            responsive:true, maintainAspectRatio:false,
            plugins:{ legend:{ position:"top" } },
            scales:{
                y:  { beginAtZero:true, title:{ display:true, text:"KES \'000s" }, grid:{ color:"rgba(0,0,0,.05)" } },
                y2: { beginAtZero:true, position:"right", title:{ display:true, text:"Units" }, grid:{ drawOnChartArea:false } }
            }
        }
    });
}());
</script>';

include __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="mb-0"><i class="fa fa-tag me-2 text-success"></i>Sales</h5>
    <?php if (canWrite('sales')): ?>
    <a href="add.php" class="btn btn-sm btn-success"><i class="fa fa-plus me-1"></i>Record Sale</a>
    <?php endif; ?>
</div>

<!-- ── Stats ──────────────────────────────────────────────────────────────── -->
<div class="row g-3 mb-4">
    <div class="col-6 col-xl-3">
        <div class="stat-card" style="border-left:4px solid #16a34a">
            <div class="stat-icon" style="background:#dcfce7;color:#16a34a"><i class="fa fa-tag"></i></div>
            <div class="stat-info">
                <div class="stat-label"><?= $isFiltered ? 'Filtered Sales' : 'Total Sales' ?></div>
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
                <div class="stat-label"><?= $isFiltered ? 'Filtered Revenue' : 'Total Revenue' ?></div>
                <div class="stat-value stat-value-sm"><?= money((float)$stats['total_revenue']) ?></div>
            </div>
        </div>
    </div>
    <?php if ($canSeeProfit): ?>
    <div class="col-6 col-xl-3">
        <div class="stat-card" style="border-left:4px solid #16a34a">
            <div class="stat-icon" style="background:#dcfce7;color:#16a34a"><i class="fa fa-circle-dollar-to-slot"></i></div>
            <div class="stat-info">
                <div class="stat-label">Gross Profit (month)</div>
                <div class="stat-value stat-value-sm"><?= money((float)$stats['month_profit']) ?></div>
            </div>
        </div>
    </div>
    <?php else: ?>
    <div class="col-6 col-xl-3">
        <div class="stat-card" style="border-left:4px solid #f59e0b">
            <div class="stat-icon" style="background:#fef3c7;color:#d97706"><i class="fa fa-truck"></i></div>
            <div class="stat-info">
                <div class="stat-label">Pending Delivery</div>
                <div class="stat-value"><?= $stats['pending_delivery'] ?></div>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- ── Charts + Leaderboard ───────────────────────────────────────────────── -->
<div class="row g-3 mb-4">
    <!-- Revenue chart -->
    <div class="col-lg-8">
        <div class="card h-100">
            <div class="card-header fw-semibold">
                <i class="fa fa-chart-bar me-2 text-primary"></i>Revenue &amp; Units — Last 12 Months
            </div>
            <div class="card-body" style="height:260px">
                <canvas id="revenueChart" style="height:100%"></canvas>
            </div>
        </div>
    </div>

    <!-- Salesperson leaderboard -->
    <div class="col-lg-4">
        <div class="card h-100">
            <div class="card-header fw-semibold">
                <i class="fa fa-trophy me-2 text-warning"></i>Leaderboard
                <span class="badge bg-light text-muted border ms-1" style="font-size:10px">This Month / All-Time</span>
            </div>
            <div class="card-body p-0">
                <?php if (!$leaderboard): ?>
                <div class="text-center text-muted py-4 small">No sales recorded yet.</div>
                <?php else: ?>
                <table class="table table-sm mb-0" style="font-size:13px">
                    <thead style="font-size:11px">
                        <tr>
                            <th class="ps-3">#</th>
                            <th>Agent</th>
                            <th class="text-center">Units<br><span class="fw-normal text-muted">Mo / All</span></th>
                            <th class="text-end pe-3">Rev (mo)</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($leaderboard as $i => $lb): ?>
                    <tr <?= $i === 0 ? 'style="background:#fefce8"' : '' ?>>
                        <td class="ps-3">
                            <?php if ($i === 0): ?><i class="fa fa-trophy" style="color:#f59e0b"></i>
                            <?php elseif ($i === 1): ?><i class="fa fa-medal" style="color:#94a3b8"></i>
                            <?php elseif ($i === 2): ?><i class="fa fa-medal" style="color:#b45309"></i>
                            <?php else: ?><span class="text-muted"><?= $i + 1 ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="fw-semibold"><?= e($lb['agent']) ?></td>
                        <td class="text-center">
                            <span class="badge bg-primary-subtle text-primary border border-primary-subtle"><?= $lb['units_month'] ?></span>
                            <span class="text-muted small"> / <?= $lb['units_all'] ?></span>
                        </td>
                        <td class="text-end pe-3 small"><?= $lb['rev_month'] > 0 ? money((float)$lb['rev_month']) : '—' ?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- ── Filters ────────────────────────────────────────────────────────────── -->
<div class="card mb-3">
    <div class="card-body py-2">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-md-2 col-sm-6">
                <label class="form-label small text-muted mb-1">Date From</label>
                <input type="date" name="date_from" class="form-control form-control-sm" value="<?= e($fDateFrom) ?>">
            </div>
            <div class="col-md-2 col-sm-6">
                <label class="form-label small text-muted mb-1">Date To</label>
                <input type="date" name="date_to" class="form-control form-control-sm" value="<?= e($fDateTo) ?>">
            </div>
            <div class="col-md-2 col-sm-6">
                <label class="form-label small text-muted mb-1">Payment Status</label>
                <select name="payment_status" class="form-select form-select-sm">
                    <option value="">All Statuses</option>
                    <option value="paid_full"  <?= $fPayStatus==='paid_full'  ?'selected':'' ?>>Paid Full</option>
                    <option value="deposit"    <?= $fPayStatus==='deposit'    ?'selected':'' ?>>Deposit</option>
                    <option value="pending"    <?= $fPayStatus==='pending'    ?'selected':'' ?>>Pending</option>
                    <option value="financed"   <?= $fPayStatus==='financed'   ?'selected':'' ?>>Financed</option>
                </select>
            </div>
            <?php if ($salespeople): ?>
            <div class="col-md-2 col-sm-6">
                <label class="form-label small text-muted mb-1">Salesperson</label>
                <select name="sold_by" class="form-select form-select-sm">
                    <option value="">All Staff</option>
                    <?php foreach ($salespeople as $sp): ?>
                    <option value="<?= $sp['id'] ?>" <?= $fSoldBy === (int)$sp['id'] ? 'selected' : '' ?>><?= e($sp['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
            <div class="col-auto">
                <button type="submit" class="btn btn-sm btn-primary"><i class="fa fa-filter me-1"></i>Filter</button>
                <?php if ($isFiltered): ?>
                <a href="index.php" class="btn btn-sm btn-outline-secondary ms-1"><i class="fa fa-xmark me-1"></i>Clear</a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<!-- ── Sales Table ────────────────────────────────────────────────────────── -->
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
                    <?php if ($canSeeProfit): ?><th class="text-end">Profit</th><?php endif; ?>
                    <th>Payment</th>
                    <th>Delivery</th>
                    <th>Sold By</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($sales as $s):
                    $profit = ($canSeeProfit && $s['cost_price'] !== null)
                        ? (float)$s['sale_price'] - (float)$s['cost_price'] : null;
                ?>
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
                    <?php if ($canSeeProfit): ?>
                    <td class="text-end small">
                        <?php if ($profit !== null): ?>
                        <span class="<?= $profit >= 0 ? 'text-success fw-semibold' : 'text-danger fw-semibold' ?>">
                            <?= money($profit) ?>
                        </span>
                        <?php else: ?>
                        <span class="text-muted">—</span>
                        <?php endif; ?>
                    </td>
                    <?php endif; ?>
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
                <?php if (!$sales): ?>
                <tr><td colspan="<?= $canSeeProfit ? 10 : 9 ?>" class="text-center text-muted py-4">No sales found<?= $isFiltered ? ' matching your filters.' : '.' ?></td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>

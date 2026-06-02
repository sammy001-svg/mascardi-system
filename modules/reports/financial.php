<?php
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
canAccess('reports') || die('Access denied.');
$pageTitle = 'Financial Report';
$db = getDB();

// ── Period ────────────────────────────────────────────────────────────────────
$period = $_GET['period'] ?? 'this_month';
switch ($period) {
    case 'last_month':
        $dateFrom = date('Y-m-01', strtotime('first day of last month'));
        $dateTo   = date('Y-m-t',  strtotime('last day of last month'));
        $label    = 'Last Month (' . date('M Y', strtotime('last month')) . ')';
        break;
    case 'last_3_months':
        $dateFrom = date('Y-m-01', strtotime('-2 months'));
        $dateTo   = date('Y-m-d');
        $label    = 'Last 3 Months';
        break;
    case 'this_year':
        $dateFrom = date('Y-01-01');
        $dateTo   = date('Y-12-31');
        $label    = 'This Year (' . date('Y') . ')';
        break;
    case 'custom':
        $dateFrom = $_GET['date_from'] ?? date('Y-m-01');
        $dateTo   = $_GET['date_to']   ?? date('Y-m-d');
        $label    = fmtDate($dateFrom) . ' – ' . fmtDate($dateTo);
        break;
    default:
        $dateFrom = date('Y-m-01');
        $dateTo   = date('Y-m-d');
        $label    = 'This Month (' . date('M Y') . ')';
}

// ── Same period last year ──────────────────────────────────────────────────────
$yoyFrom = date('Y-m-d', strtotime($dateFrom . ' -1 year'));
$yoyTo   = date('Y-m-d', strtotime($dateTo   . ' -1 year'));

function yoyPct(float $current, float $prev): string {
    if ($prev == 0) return $current > 0 ? '<span class="badge bg-success">New</span>' : '—';
    $pct = round(($current - $prev) / $prev * 100, 1);
    $cls = $pct >= 0 ? 'success' : 'danger';
    $arrow = $pct >= 0 ? '↑' : '↓';
    return "<span class=\"badge bg-{$cls}\">{$arrow} " . abs($pct) . "%</span>";
}

// ── Revenue KPIs (current + YoY) ──────────────────────────────────────────────
$revStmt = $db->prepare("SELECT COALESCE(SUM(total),0) AS revenue, COUNT(*) AS cnt,
    SUM(CASE WHEN status='paid' THEN 1 ELSE 0 END) AS paid_count,
    COALESCE(SUM(CASE WHEN status='paid' THEN total END), 0) AS paid,
    COALESCE(SUM(CASE WHEN status IN ('unpaid','partial') THEN total - amount_paid END), 0) AS outstanding
    FROM invoices WHERE DATE(created_at) BETWEEN ? AND ?");
$revStmt->execute([$dateFrom, $dateTo]);  $rev = $revStmt->fetch();
$revStmt->execute([$yoyFrom,  $yoyTo]);   $revYoy = $revStmt->fetch();

// ── Monthly revenue — last 12 months ──────────────────────────────────────────
$monthlyRev = $db->query("
    SELECT DATE_FORMAT(created_at,'%b %Y') AS label,
           DATE_FORMAT(created_at,'%Y-%m') AS key,
           COALESCE(SUM(CASE WHEN status='paid' THEN total END),0) AS collected,
           COALESCE(SUM(total),0)                                    AS invoiced
    FROM invoices
    WHERE created_at >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
    GROUP BY `key`, label ORDER BY `key` ASC
")->fetchAll();

// ── P&L (sales with costs) ────────────────────────────────────────────────────
try {
    $plStmt = $db->prepare("
        SELECT
            COUNT(cs.id)                                AS sales_count,
            COALESCE(SUM(cs.sale_price), 0)             AS revenue,
            COALESCE(SUM(
                cc.purchase_price + cc.freight + cc.marine_insurance + cc.port_charges
                + cc.duty_tax + cc.clearing_fees + cc.transport_to_yard
                + cc.workshop_costs + cc.other_costs
            ), 0)                                        AS cogs,
            COALESCE(SUM(cs.sale_price - (
                cc.purchase_price + cc.freight + cc.marine_insurance + cc.port_charges
                + cc.duty_tax + cc.clearing_fees + cc.transport_to_yard
                + cc.workshop_costs + cc.other_costs
            )), 0)                                       AS gross_profit
        FROM car_sales cs JOIN car_costs cc ON cc.car_id = cs.car_id
        WHERE cs.status='active' AND DATE(cs.sale_date) BETWEEN ? AND ?
    ");
    $plStmt->execute([$dateFrom, $dateTo]);   $pl    = $plStmt->fetch();
    $plStmt->execute([$yoyFrom,  $yoyTo]);    $plYoy = $plStmt->fetch();
    $haspl = true;
} catch (\Throwable $e) { $haspl = false; $pl = $plYoy = null; }

// ── Expenses ──────────────────────────────────────────────────────────────────
try {
    $expStmt = $db->prepare("SELECT COALESCE(SUM(amount),0) AS total FROM expenses WHERE DATE(expense_date) BETWEEN ? AND ?");
    $expStmt->execute([$dateFrom, $dateTo]); $expTotal    = (float)$expStmt->fetchColumn();
    $expStmt->execute([$yoyFrom,  $yoyTo]);  $expTotalYoy = (float)$expStmt->fetchColumn();

    $expBreak = $db->prepare("
        SELECT category, COALESCE(SUM(amount),0) AS amt
        FROM expenses WHERE DATE(expense_date) BETWEEN ? AND ?
        GROUP BY category ORDER BY amt DESC
    ");
    $expBreak->execute([$dateFrom, $dateTo]); $expBreak = $expBreak->fetchAll();
    $hasexp = true;
} catch (\Throwable $e) { $hasexp = false; $expTotal = $expTotalYoy = 0; $expBreak = []; }

// ── Month-by-month expenses (last 12) ─────────────────────────────────────────
try {
    $monthlyExp = $db->query("
        SELECT DATE_FORMAT(expense_date,'%b %Y') AS label,
               DATE_FORMAT(expense_date,'%Y-%m') AS key,
               COALESCE(SUM(amount),0)           AS total
        FROM expenses
        WHERE expense_date >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
        GROUP BY `key`, label ORDER BY `key` ASC
    ")->fetchAll();
} catch (\Throwable $e) { $monthlyExp = []; }

// ── Outstanding installments ──────────────────────────────────────────────────
try {
    $instOut = $db->query("
        SELECT COALESCE(SUM(amount_due - amount_paid),0) AS outstanding,
               SUM(status='overdue') AS overdue_count
        FROM sale_installments WHERE status IN ('pending','overdue')
    ")->fetch();
} catch (\Throwable $e) { $instOut = null; }

// ── Per-vehicle profit list ───────────────────────────────────────────────────
try {
    $profitList = $db->prepare("
        SELECT c.make, c.model, c.year, c.chassis_number,
               cs.sale_number, cs.sale_date, cs.buyer_name,
               cs.sale_price,
               (cc.purchase_price + cc.freight + cc.marine_insurance + cc.port_charges
                + cc.duty_tax + cc.clearing_fees + cc.transport_to_yard
                + cc.workshop_costs + cc.other_costs) AS cogs,
               cs.sale_price - (cc.purchase_price + cc.freight + cc.marine_insurance + cc.port_charges
                + cc.duty_tax + cc.clearing_fees + cc.transport_to_yard
                + cc.workshop_costs + cc.other_costs) AS profit,
               cs.id AS sale_id
        FROM car_sales cs
        JOIN cars c ON c.id = cs.car_id
        JOIN car_costs cc ON cc.car_id = cs.car_id
        WHERE cs.status='active' AND DATE(cs.sale_date) BETWEEN ? AND ?
        ORDER BY profit DESC
    ");
    $profitList->execute([$dateFrom, $dateTo]);
    $profitList = $profitList->fetchAll();
} catch (\Throwable $e) { $profitList = []; }

// ── Chart JSON ────────────────────────────────────────────────────────────────
$chartRevLabels    = json_encode(array_column($monthlyRev, 'label'));
$chartCollected    = json_encode(array_map(fn($r) => round($r['collected'], 2), $monthlyRev));
$chartInvoiced     = json_encode(array_map(fn($r) => round($r['invoiced'],  2), $monthlyRev));
$chartExpLabels    = json_encode(array_column($monthlyExp, 'label'));
$chartExpAmounts   = json_encode(array_map(fn($r) => round($r['total'], 2), $monthlyExp));
$netProfit         = ($pl['gross_profit'] ?? 0) - $expTotal;
$netProfitYoy      = ($plYoy['gross_profit'] ?? 0) - $expTotalYoy;

$extraJs = <<<JS
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
(function(){
    var revEl = document.getElementById('revChart');
    if (revEl) new Chart(revEl, {
        type: 'line',
        data: {
            labels: {$chartRevLabels},
            datasets: [
                { label: 'Collected', data: {$chartCollected}, borderColor:'#16a34a', backgroundColor:'rgba(22,163,74,.1)', tension:.35, fill:true, borderWidth:2, pointRadius:3 },
                { label: 'Invoiced',  data: {$chartInvoiced},  borderColor:'#2563eb', backgroundColor:'rgba(37,99,235,.05)', tension:.35, fill:false, borderWidth:2, borderDash:[4,3], pointRadius:3 }
            ]
        },
        options:{ responsive:true, plugins:{ legend:{ position:'top', labels:{font:{size:11}} } },
            scales:{ y:{ beginAtZero:true, ticks:{ callback:v=>'KES '+v.toLocaleString() } } } }
    });

    var expEl = document.getElementById('expChart');
    if (expEl) new Chart(expEl, {
        type: 'bar',
        data: { labels: {$chartExpLabels}, datasets: [{ label:'Expenses', data:{$chartExpAmounts}, backgroundColor:'rgba(220,38,38,.7)', borderRadius:5 }] },
        options:{ responsive:true, plugins:{ legend:{ display:false } },
            scales:{ y:{ beginAtZero:true, ticks:{ callback:v=>'KES '+v.toLocaleString() } } } }
    });
}());
</script>
JS;

include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/_nav.php';
?>

<!-- KPI row with YoY -->
<div class="row g-3 mb-4">
    <?php
    $kpis = [
        ['Revenue Invoiced',   $rev['revenue'] ?? 0,       $revYoy['revenue'] ?? 0,       '#2563eb', 'fa-file-invoice-dollar', 'dbeafe', '2563eb'],
        ['Revenue Collected',  $rev['paid']    ?? 0,       $revYoy['paid']    ?? 0,       '#16a34a', 'fa-money-bill-wave',     'dcfce7', '16a34a'],
        ['Outstanding',        $rev['outstanding'] ?? 0,   $revYoy['outstanding'] ?? 0,   '#dc2626', 'fa-hourglass-half',      'fee2e2', 'dc2626'],
        ['Gross Profit',       $pl['gross_profit'] ?? 0,   $plYoy['gross_profit'] ?? 0,   '#9333ea', 'fa-scale-balanced',     'fdf4ff', '9333ea'],
    ];
    foreach ($kpis as [$lbl, $curr, $prev, $border, $icon, $iconBg, $iconColor]): ?>
    <div class="col-sm-6 col-xl-3">
        <div class="stat-card" style="border-left:4px solid <?= $border ?>">
            <div class="stat-icon" style="background:#<?= $iconBg ?>;color:#<?= $iconColor ?>">
                <i class="fa <?= $icon ?>"></i>
            </div>
            <div class="stat-info">
                <div class="stat-label"><?= $lbl ?></div>
                <div class="stat-value stat-value-sm"><?= money((float)$curr) ?></div>
                <div class="mt-1 d-flex align-items-center gap-1" style="font-size:11px">
                    <span class="text-muted">vs prev year:</span>
                    <?= yoyPct((float)$curr, (float)$prev) ?>
                </div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<!-- Revenue + Expenses charts -->
<div class="row g-4 mb-4">
    <div class="col-lg-8">
        <div class="card h-100">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="fa fa-chart-line me-2"></i>Revenue — Last 12 Months</span>
                <a href="<?= BASE_URL ?>/modules/reports/export.php?type=revenue_monthly&<?= $__qs ?>" class="btn btn-xs btn-outline-secondary">
                    <i class="fa fa-download me-1"></i>CSV
                </a>
            </div>
            <div class="card-body"><canvas id="revChart" height="90"></canvas></div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card h-100">
            <div class="card-header"><i class="fa fa-chart-bar me-2"></i>Monthly Expenses</div>
            <div class="card-body"><canvas id="expChart" height="170"></canvas></div>
        </div>
    </div>
</div>

<!-- P&L Summary -->
<?php if ($haspl && $pl): ?>
<div class="card mb-4">
    <div class="card-header fw-semibold d-flex justify-content-between">
        <span><i class="fa fa-scale-balanced me-2 text-success"></i>P&amp;L — <?= e($label) ?></span>
        <a href="<?= BASE_URL ?>/modules/reports/export.php?type=profit&period=<?= urlencode($period) ?>&date_from=<?= urlencode($dateFrom) ?>&date_to=<?= urlencode($dateTo) ?>" class="btn btn-xs btn-outline-secondary">
            <i class="fa fa-download me-1"></i>Export CSV
        </a>
    </div>
    <div class="card-body">
        <!-- Summary bar -->
        <div class="row g-3 text-center mb-4">
            <?php
            $rev2   = (float)($pl['revenue']      ?? 0);
            $cogs   = (float)($pl['cogs']          ?? 0);
            $gross  = (float)($pl['gross_profit']  ?? 0);
            $ops    = $hasexp ? $expTotal : 0;
            $net    = $gross - $ops;
            $margin = $rev2 > 0 ? round($gross / $rev2 * 100, 1) : 0;

            $plCols = [
                ['Cars Sold',     $pl['sales_count'],  'primary',  null],
                ['Sales Revenue', $rev2,               'success',  null],
                ['Total COGS',    $cogs,               'danger',   null],
                ['Gross Profit',  $gross,              $gross >= 0 ? 'success' : 'danger', $margin . '% margin'],
                ['Opex',          $ops,                'warning',  null],
                ['Net Profit',    $net,                $net >= 0 ? 'success' : 'danger',  null],
            ];
            foreach ($plCols as [$l, $v, $c, $sub]): ?>
            <div class="col-6 col-md-2">
                <div class="text-muted small mb-1"><?= $l ?></div>
                <div class="fw-bold text-<?= $c ?>" style="font-size:<?= is_int($v) ? '22px' : '15px' ?>">
                    <?= is_int($v) ? $v : money((float)$v) ?>
                </div>
                <?php if ($sub): ?><div class="text-muted" style="font-size:11px"><?= $sub ?></div><?php endif; ?>
                <div class="text-muted" style="font-size:10.5px">vs <?= yoyPct((float)$v, (float)(match($l){
                    'Cars Sold' => $plYoy['sales_count'] ?? 0,
                    'Sales Revenue' => $plYoy['revenue'] ?? 0,
                    'Total COGS' => $plYoy['cogs'] ?? 0,
                    'Gross Profit' => $plYoy['gross_profit'] ?? 0,
                    'Net Profit' => $netProfitYoy,
                    default => 0,
                })) ?></div>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- Per-vehicle profit table -->
        <?php if ($profitList): ?>
        <div class="table-responsive">
            <table class="table table-hover mb-0" style="font-size:13px">
                <thead><tr>
                    <th class="ps-3">Vehicle</th>
                    <th>Sale #</th>
                    <th>Buyer</th>
                    <th>Date</th>
                    <th class="text-end">COGS</th>
                    <th class="text-end">Sale Price</th>
                    <th class="text-end">Profit</th>
                    <th class="text-end">Margin</th>
                </tr></thead>
                <tbody>
                <?php foreach ($profitList as $row):
                    $m = $row['sale_price'] > 0 ? round($row['profit'] / $row['sale_price'] * 100, 1) : 0;
                    $mc = $m >= 20 ? 'success' : ($m >= 10 ? 'warning text-dark' : 'danger');
                ?>
                <tr>
                    <td class="ps-3 fw-medium small"><?= e($row['year'].' '.$row['make'].' '.$row['model']) ?></td>
                    <td><a href="<?= BASE_URL ?>/modules/sales/view.php?id=<?= $row['sale_id'] ?>" class="text-decoration-none small"><?= e($row['sale_number']) ?></a></td>
                    <td class="small text-muted"><?= e($row['buyer_name']) ?></td>
                    <td class="small text-muted"><?= fmtDate($row['sale_date']) ?></td>
                    <td class="text-end text-danger small"><?= money((float)$row['cogs']) ?></td>
                    <td class="text-end text-success fw-semibold small"><?= money((float)$row['sale_price']) ?></td>
                    <td class="text-end fw-bold small <?= $row['profit'] >= 0 ? 'text-success' : 'text-danger' ?>"><?= money((float)$row['profit']) ?></td>
                    <td class="text-end"><span class="badge bg-<?= $mc ?>"><?= $m ?>%</span></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
        <div class="text-center text-muted py-3 small">No sales with import costs in this period.
            <a href="<?= BASE_URL ?>/modules/car_costs/index.php">Add import costs →</a>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<!-- Expense breakdown -->
<?php if ($hasexp && $expBreak): ?>
<div class="card mb-4">
    <div class="card-header fw-semibold d-flex justify-content-between">
        <span><i class="fa fa-receipt me-2 text-danger"></i>Expense Breakdown — <?= e($label) ?></span>
        <a href="<?= BASE_URL ?>/modules/reports/export.php?type=expenses&period=<?= urlencode($period) ?>&date_from=<?= urlencode($dateFrom) ?>&date_to=<?= urlencode($dateTo) ?>" class="btn btn-xs btn-outline-secondary">
            <i class="fa fa-download me-1"></i>CSV
        </a>
    </div>
    <div class="card-body">
        <div class="row g-2">
        <?php
        $catColors = ['salaries'=>'#2563eb','rent'=>'#7c3aed','fuel'=>'#d97706','utilities'=>'#0891b2','marketing'=>'#ec4899','maintenance'=>'#16a34a','office'=>'#64748b','insurance'=>'#0284c7','taxes'=>'#dc2626','other'=>'#94a3b8'];
        $catLabels = ['salaries'=>'Salaries','rent'=>'Rent','fuel'=>'Fuel','utilities'=>'Utilities','marketing'=>'Marketing','maintenance'=>'Maintenance','office'=>'Office','insurance'=>'Insurance','taxes'=>'Taxes','other'=>'Other'];
        foreach ($expBreak as $row):
            $pct = $expTotal > 0 ? round($row['amt'] / $expTotal * 100) : 0;
            $col = $catColors[$row['category']] ?? '#94a3b8';
        ?>
        <div class="col-12">
            <div class="d-flex align-items-center gap-3" style="font-size:13px">
                <span style="min-width:110px;color:<?= $col ?>;font-weight:600"><?= $catLabels[$row['category']] ?? $row['category'] ?></span>
                <div class="progress flex-grow-1" style="height:8px">
                    <div class="progress-bar" style="width:<?= $pct ?>%;background:<?= $col ?>"></div>
                </div>
                <span class="fw-semibold" style="min-width:90px;text-align:right"><?= money((float)$row['amt']) ?></span>
                <span class="text-muted small" style="min-width:30px;text-align:right"><?= $pct ?>%</span>
            </div>
        </div>
        <?php endforeach; ?>
        </div>
        <?php if ($instOut && $instOut['outstanding'] > 0): ?>
        <div class="alert alert-warning py-2 px-3 mt-3 mb-0 small">
            <i class="fa fa-calendar-xmark me-1"></i>
            <strong><?= money((float)$instOut['outstanding']) ?></strong> due from
            <?= (int)$instOut['overdue_count'] ?> overdue instalment(s).
            <a href="<?= BASE_URL ?>/modules/installments/index.php" class="ms-1">View →</a>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<?php include __DIR__ . '/../../includes/footer.php'; ?>

<?php
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
canAccess('reports') || redirect(BASE_URL . '/index.php');
$pageTitle = 'KPI Targets';
$db  = getDB();
$me  = authUser();
$isAdmin = hasRole(['admin','general_manager','manager','sales_manager','finance_manager']);

// Auto-migrate
try {
    $db->exec("CREATE TABLE IF NOT EXISTS kpi_targets (
        id            INT AUTO_INCREMENT PRIMARY KEY,
        metric_key    ENUM('revenue','cars_sold','new_leads','jobs_closed') NOT NULL,
        target_month  TINYINT NOT NULL,
        target_year   YEAR(4) NOT NULL,
        target_value  DECIMAL(15,2) NOT NULL,
        created_by    INT NOT NULL,
        created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_target (metric_key, target_month, target_year)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (\Throwable $_) {}

// Month / year navigation
$month = max(1, min(12, (int)($_GET['month'] ?? date('n'))));
$year  = max(2020, min(2099, (int)($_GET['year']  ?? date('Y'))));
$prevTs = mktime(0, 0, 0, $month - 1, 1, $year);
$nextTs = mktime(0, 0, 0, $month + 1, 1, $year);
$prevLink = '?month=' . date('n', $prevTs) . '&year=' . date('Y', $prevTs);
$nextLink = '?month=' . date('n', $nextTs) . '&year=' . date('Y', $nextTs);
$monthLabel = date('F Y', mktime(0, 0, 0, $month, 1, $year));

// Save targets (admin)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isAdmin && ($_POST['action'] ?? '') === 'set_targets') {
    $tm = max(1, min(12, (int)($_POST['target_month'] ?? $month)));
    $ty = max(2020, min(2099, (int)($_POST['target_year'] ?? $year)));
    foreach (['revenue', 'cars_sold', 'new_leads', 'jobs_closed'] as $key) {
        $val = (float)str_replace(',', '', $_POST[$key] ?? '0');
        if ($val >= 0) {
            $db->prepare("INSERT INTO kpi_targets (metric_key, target_month, target_year, target_value, created_by)
                VALUES (?,?,?,?,?)
                ON DUPLICATE KEY UPDATE target_value=VALUES(target_value), updated_at=NOW()")
               ->execute([$key, $tm, $ty, $val, $me['id']]);
        }
    }
    setFlash('success', 'Targets saved for ' . date('F Y', mktime(0,0,0,$tm,1,$ty)));
    redirect(BASE_URL . "/modules/reports/kpi.php?month=$tm&year=$ty");
}

// Load targets for selected month
$targets = ['revenue' => 0, 'cars_sold' => 0, 'new_leads' => 0, 'jobs_closed' => 0];
try {
    $ts = $db->prepare("SELECT metric_key, target_value FROM kpi_targets WHERE target_month=? AND target_year=?");
    $ts->execute([$month, $year]);
    foreach ($ts->fetchAll() as $r) { $targets[$r['metric_key']] = (float)$r['target_value']; }
} catch (\Throwable $_) {}

// Load actuals for selected month
$actuals = ['revenue' => 0, 'cars_sold' => 0, 'new_leads' => 0, 'jobs_closed' => 0];
try {
    $s = $db->prepare("SELECT COALESCE(SUM(total),0) FROM invoices WHERE status='paid' AND MONTH(created_at)=? AND YEAR(created_at)=?");
    $s->execute([$month,$year]); $actuals['revenue'] = (float)$s->fetchColumn();
} catch (\Throwable $_) {}
try {
    $s = $db->prepare("SELECT COUNT(*) FROM car_sales WHERE MONTH(created_at)=? AND YEAR(created_at)=?");
    $s->execute([$month,$year]); $actuals['cars_sold'] = (int)$s->fetchColumn();
} catch (\Throwable $_) {}
try {
    $s = $db->prepare("SELECT COUNT(*) FROM leads WHERE MONTH(created_at)=? AND YEAR(created_at)=?");
    $s->execute([$month,$year]); $actuals['new_leads'] = (int)$s->fetchColumn();
} catch (\Throwable $_) {}
try {
    $s = $db->prepare("SELECT COUNT(*) FROM jobs WHERE status='completed' AND MONTH(updated_at)=? AND YEAR(updated_at)=?");
    $s->execute([$month,$year]); $actuals['jobs_closed'] = (int)$s->fetchColumn();
} catch (\Throwable $_) {}

// Trend: last 6 months of actuals
$trendLabels = $trendRevenue = $trendCarsSold = $trendLeads = [];
for ($i = 5; $i >= 0; $i--) {
    $ts2 = strtotime("-$i months", mktime(0,0,0,$month,1,$year));
    $m2  = (int)date('n', $ts2);
    $y2  = (int)date('Y', $ts2);
    $trendLabels[] = date('M y', mktime(0,0,0,$m2,1,$y2));
    try {
        $s = $db->prepare("SELECT COALESCE(SUM(total),0) FROM invoices WHERE status='paid' AND MONTH(created_at)=? AND YEAR(created_at)=?");
        $s->execute([$m2,$y2]); $trendRevenue[] = (float)$s->fetchColumn();
    } catch (\Throwable $_) { $trendRevenue[] = 0; }
    try {
        $s = $db->prepare("SELECT COUNT(*) FROM car_sales WHERE MONTH(created_at)=? AND YEAR(created_at)=?");
        $s->execute([$m2,$y2]); $trendCarsSold[] = (int)$s->fetchColumn();
    } catch (\Throwable $_) { $trendCarsSold[] = 0; }
    try {
        $s = $db->prepare("SELECT COUNT(*) FROM leads WHERE MONTH(created_at)=? AND YEAR(created_at)=?");
        $s->execute([$m2,$y2]); $trendLeads[] = (int)$s->fetchColumn();
    } catch (\Throwable $_) { $trendLeads[] = 0; }
}

// Revenue target line for chart (same target repeated across 6 months for the selected month only)
$trendRevTarget = array_fill(0, 6, $targets['revenue']);

// Required by _nav.php
$period   = 'this_month';
$dateFrom = date('Y-m-01', mktime(0,0,0,$month,1,$year));
$dateTo   = date('Y-m-t',  mktime(0,0,0,$month,1,$year));
$label    = $monthLabel;

$chartJson = json_encode([
    'labels'     => $trendLabels,
    'revenue'    => $trendRevenue,
    'cars_sold'  => $trendCarsSold,
    'leads'      => $trendLeads,
    'revTarget'  => $trendRevTarget,
]);

$extraJs = <<<JS
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
(function(){
    var d = {$chartJson};
    var c = document.getElementById('kpiTrendChart');
    if (!c) return;
    new Chart(c, {
        type: 'bar',
        data: {
            labels: d.labels,
            datasets: [
                {
                    label: 'Revenue (KES)',
                    data: d.revenue,
                    backgroundColor: 'rgba(34,211,238,.65)',
                    borderColor: '#22d3ee',
                    borderWidth: 1,
                    borderRadius: 4,
                    yAxisID: 'yRev',
                    order: 2
                },
                {
                    label: 'Rev. Target',
                    data: d.revTarget,
                    type: 'line',
                    borderColor: '#f59e0b',
                    borderWidth: 2,
                    borderDash: [6,4],
                    pointRadius: 0,
                    fill: false,
                    yAxisID: 'yRev',
                    order: 1
                },
                {
                    label: 'Cars Sold',
                    data: d.cars_sold,
                    backgroundColor: 'rgba(74,222,128,.65)',
                    borderColor: '#4ade80',
                    borderWidth: 1,
                    borderRadius: 4,
                    yAxisID: 'yCount',
                    order: 3
                },
                {
                    label: 'New Leads',
                    data: d.leads,
                    backgroundColor: 'rgba(167,139,250,.65)',
                    borderColor: '#a78bfa',
                    borderWidth: 1,
                    borderRadius: 4,
                    yAxisID: 'yCount',
                    order: 3
                }
            ]
        },
        options: {
            responsive: true,
            interaction: { mode: 'index', intersect: false },
            plugins: {
                legend: { position: 'bottom', labels: { font: { size: 12 }, padding: 16 } },
                tooltip: {
                    callbacks: {
                        label: function(ctx) {
                            var v = ctx.raw;
                            if (ctx.dataset.yAxisID === 'yRev') {
                                return ' ' + ctx.dataset.label + ': KES ' + (v >= 1e6 ? (v/1e6).toFixed(2)+'M' : v >= 1e3 ? (v/1e3).toFixed(1)+'K' : v);
                            }
                            return ' ' + ctx.dataset.label + ': ' + v;
                        }
                    }
                }
            },
            scales: {
                yRev: {
                    position: 'left',
                    ticks: { callback: function(v){ return v>=1e6?(v/1e6).toFixed(1)+'M':v>=1e3?(v/1e3).toFixed(0)+'K':v; } }
                },
                yCount: {
                    position: 'right',
                    grid: { drawOnChartArea: false },
                    ticks: { stepSize: 1 }
                }
            }
        }
    });
}());
</script>
JS;

include __DIR__ . '/../../includes/header.php';
require __DIR__ . '/_nav.php';

// Helper: achievement pct + color
function kpiPct(float $actual, float $target): array {
    if ($target <= 0) return [null, 'secondary'];
    $pct = round($actual / $target * 100, 1);
    $color = $pct >= 100 ? 'success' : ($pct >= 70 ? 'warning' : 'danger');
    return [$pct, $color];
}
?>

<!-- ── Month navigation ───────────────────────────────────────────────────── -->
<div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
    <div>
        <h6 class="mb-0 fw-bold"><i class="fa fa-bullseye me-2 text-primary"></i>KPI Targets — <?= e($monthLabel) ?></h6>
        <div class="text-muted small">Target vs actual performance for your team</div>
    </div>
    <div class="d-flex align-items-center gap-2">
        <a href="<?= $prevLink ?>" class="btn btn-sm btn-outline-secondary"><i class="fa fa-chevron-left"></i></a>
        <form method="GET" class="d-flex gap-1 align-items-center">
            <select name="month" class="form-select form-select-sm" style="width:auto" onchange="this.form.submit()">
                <?php for ($m=1;$m<=12;$m++): ?>
                <option value="<?= $m ?>" <?= $m===$month?'selected':'' ?>><?= date('F',mktime(0,0,0,$m,1)) ?></option>
                <?php endfor; ?>
            </select>
            <select name="year" class="form-select form-select-sm" style="width:80px" onchange="this.form.submit()">
                <?php for ($y=date('Y');$y>=2023;$y--): ?>
                <option value="<?= $y ?>" <?= $y===$year?'selected':'' ?>><?= $y ?></option>
                <?php endfor; ?>
            </select>
        </form>
        <a href="<?= $nextLink ?>" class="btn btn-sm btn-outline-secondary"><i class="fa fa-chevron-right"></i></a>
        <?php if ($isAdmin): ?>
        <button class="btn btn-sm btn-primary" type="button" data-bs-toggle="collapse" data-bs-target="#setTargetsPanel">
            <i class="fa fa-sliders me-1"></i>Set Targets
        </button>
        <?php endif; ?>
    </div>
</div>

<?php if ($isAdmin): ?>
<!-- ── Set Targets Form (collapsible) ────────────────────────────────────── -->
<div class="collapse mb-4" id="setTargetsPanel">
    <div class="card border-primary">
        <div class="card-header bg-primary text-white fw-semibold">
            <i class="fa fa-sliders me-2"></i>Set Monthly Targets
        </div>
        <div class="card-body">
            <form method="POST" class="row g-3 align-items-end">
                <input type="hidden" name="action" value="set_targets">
                <div class="col-md-1">
                    <label class="form-label small fw-semibold">Month</label>
                    <select name="target_month" class="form-select form-select-sm">
                        <?php for ($m=1;$m<=12;$m++): ?>
                        <option value="<?= $m ?>" <?= $m===$month?'selected':'' ?>><?= date('M',mktime(0,0,0,$m,1)) ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div class="col-md-1">
                    <label class="form-label small fw-semibold">Year</label>
                    <select name="target_year" class="form-select form-select-sm">
                        <?php for ($y=date('Y')+1;$y>=2023;$y--): ?>
                        <option value="<?= $y ?>" <?= $y===$year?'selected':'' ?>><?= $y ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label small fw-semibold">Revenue Target (KES)</label>
                    <input type="number" name="revenue" class="form-control form-control-sm" min="0" step="1000"
                           value="<?= $targets['revenue'] > 0 ? $targets['revenue'] : '' ?>" placeholder="e.g. 5000000">
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-semibold">Cars Sold Target</label>
                    <input type="number" name="cars_sold" class="form-control form-control-sm" min="0" step="1"
                           value="<?= $targets['cars_sold'] > 0 ? (int)$targets['cars_sold'] : '' ?>" placeholder="e.g. 20">
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-semibold">New Leads Target</label>
                    <input type="number" name="new_leads" class="form-control form-control-sm" min="0" step="1"
                           value="<?= $targets['new_leads'] > 0 ? (int)$targets['new_leads'] : '' ?>" placeholder="e.g. 50">
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-semibold">Jobs Closed Target</label>
                    <input type="number" name="jobs_closed" class="form-control form-control-sm" min="0" step="1"
                           value="<?= $targets['jobs_closed'] > 0 ? (int)$targets['jobs_closed'] : '' ?>" placeholder="e.g. 30">
                </div>
                <div class="col-md-1">
                    <button class="btn btn-success btn-sm w-100"><i class="fa fa-save me-1"></i>Save</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<?php
// KPI card definitions
$kpis = [
    'revenue' => [
        'label'  => 'Revenue',
        'icon'   => 'fa-money-bill-wave',
        'color'  => '#22d3ee',
        'bg'     => 'rgba(34,211,238,.1)',
        'format' => fn($v) => 'KES ' . (($v >= 1e6) ? number_format($v/1e6,2).'M' : (($v >= 1e3) ? number_format($v/1e3,1).'K' : number_format($v,0))),
        'target_fmt' => fn($v) => $v > 0 ? 'KES '.number_format($v,0) : 'Not set',
    ],
    'cars_sold' => [
        'label'  => 'Cars Sold',
        'icon'   => 'fa-car-side',
        'color'  => '#4ade80',
        'bg'     => 'rgba(74,222,128,.1)',
        'format' => fn($v) => number_format($v),
        'target_fmt' => fn($v) => $v > 0 ? number_format($v).' cars' : 'Not set',
    ],
    'new_leads' => [
        'label'  => 'New Leads',
        'icon'   => 'fa-user-plus',
        'color'  => '#a78bfa',
        'bg'     => 'rgba(167,139,250,.1)',
        'format' => fn($v) => number_format($v),
        'target_fmt' => fn($v) => $v > 0 ? number_format($v).' leads' : 'Not set',
    ],
    'jobs_closed' => [
        'label'  => 'Jobs Closed',
        'icon'   => 'fa-screwdriver-wrench',
        'color'  => '#f59e0b',
        'bg'     => 'rgba(245,158,11,.1)',
        'format' => fn($v) => number_format($v),
        'target_fmt' => fn($v) => $v > 0 ? number_format($v).' jobs' : 'Not set',
    ],
];
?>

<!-- ── KPI Cards ──────────────────────────────────────────────────────────── -->
<div class="row g-3 mb-4">
<?php foreach ($kpis as $key => $cfg):
    $actual  = $actuals[$key];
    $target  = $targets[$key];
    [$pct, $barColor] = kpiPct((float)$actual, (float)$target);
    $noTarget = $target <= 0;
?>
<div class="col-sm-6 col-xl-3">
    <div class="card h-100" style="border-top:3px solid <?= $cfg['color'] ?>">
        <div class="card-body">
            <div class="d-flex align-items-start justify-content-between mb-3">
                <div>
                    <div class="text-muted small fw-semibold text-uppercase" style="letter-spacing:.05em;font-size:10.5px">
                        <?= $cfg['label'] ?>
                    </div>
                    <div class="fw-bold mt-1" style="font-size:1.6rem;line-height:1.1;color:<?= $cfg['color'] ?>">
                        <?= $cfg['format']($actual) ?>
                    </div>
                </div>
                <div class="rounded-3 d-flex align-items-center justify-content-center"
                     style="width:44px;height:44px;background:<?= $cfg['bg'] ?>;flex-shrink:0">
                    <i class="fa <?= $cfg['icon'] ?>" style="color:<?= $cfg['color'] ?>;font-size:18px"></i>
                </div>
            </div>

            <?php if (!$noTarget): ?>
            <div class="mb-2">
                <div class="progress" style="height:6px;border-radius:4px">
                    <div class="progress-bar bg-<?= $barColor ?>"
                         style="width:<?= min(100, $pct) ?>%;transition:width .6s ease"></div>
                </div>
            </div>
            <div class="d-flex justify-content-between align-items-center">
                <div class="text-muted small">Target: <strong><?= $cfg['target_fmt']($target) ?></strong></div>
                <span class="badge bg-<?= $barColor ?>-subtle text-<?= $barColor ?> fw-bold border border-<?= $barColor ?>-subtle" style="font-size:11px">
                    <?= $pct ?>%
                    <?php if ($pct >= 100): ?><i class="fa fa-check ms-1"></i><?php endif; ?>
                </span>
            </div>
            <?php else: ?>
            <div class="text-muted small mt-2">
                <i class="fa fa-circle-info me-1"></i>No target set for <?= e($monthLabel) ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php endforeach; ?>
</div>

<!-- ── Summary Row ────────────────────────────────────────────────────────── -->
<?php
$setCount = count(array_filter($targets, fn($v) => $v > 0));
$hitCount = 0;
foreach ($kpis as $key => $_) {
    [$pct2] = kpiPct((float)$actuals[$key], (float)$targets[$key]);
    if ($pct2 !== null && $pct2 >= 100) $hitCount++;
}
?>
<?php if ($setCount > 0): ?>
<div class="alert <?= $hitCount === $setCount ? 'alert-success' : ($hitCount > 0 ? 'alert-warning' : 'alert-secondary') ?> d-flex align-items-center gap-3 mb-4">
    <i class="fa <?= $hitCount === $setCount ? 'fa-trophy' : 'fa-chart-line' ?> fa-lg"></i>
    <div>
        <?php if ($hitCount === $setCount): ?>
            <strong>All targets achieved!</strong> Excellent performance for <?= e($monthLabel) ?>.
        <?php elseif ($hitCount > 0): ?>
            <strong><?= $hitCount ?> of <?= $setCount ?> targets achieved</strong> — keep pushing for <?= e($monthLabel) ?>.
        <?php else: ?>
            <strong>No targets hit yet</strong> for <?= e($monthLabel) ?> — <?= $month === (int)date('n') && $year === (int)date('Y') ? 'month still in progress.' : 'consider reviewing targets.' ?>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<!-- ── Trend Chart ────────────────────────────────────────────────────────── -->
<div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="fa fa-chart-bar me-2"></i>6-Month Performance Trend</span>
        <span class="text-muted small">Dashed line = revenue target</span>
    </div>
    <div class="card-body">
        <canvas id="kpiTrendChart" height="110"></canvas>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>

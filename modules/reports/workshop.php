<?php
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
canAccess('reports') || die('Access denied.');
$pageTitle = 'Workshop Report';
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
        $dateFrom = date('Y-01-01'); $dateTo = date('Y-12-31');
        $label = 'This Year (' . date('Y') . ')';
        break;
    case 'custom':
        $dateFrom = $_GET['date_from'] ?? date('Y-m-01');
        $dateTo   = $_GET['date_to']   ?? date('Y-m-d');
        $label    = fmtDate($dateFrom) . ' – ' . fmtDate($dateTo);
        break;
    default:
        $dateFrom = date('Y-m-01'); $dateTo = date('Y-m-d');
        $label = 'This Month (' . date('M Y') . ')';
}

// ── Job KPIs ──────────────────────────────────────────────────────────────────
$jobKpi = $db->prepare("
    SELECT
        COUNT(*)                                          AS total,
        SUM(status='completed')                           AS completed,
        SUM(status IN ('in_progress','pending'))          AS active,
        SUM(status='cancelled')                           AS cancelled,
        SUM(status NOT IN ('completed','cancelled') AND end_date < CURDATE() AND end_date IS NOT NULL) AS overdue,
        ROUND(AVG(CASE WHEN status='completed'
            THEN DATEDIFF(updated_at, created_at) END), 1) AS avg_completion_days,
        ROUND(SUM(CASE WHEN status='completed'
            AND (end_date IS NULL OR updated_at <= end_date) THEN 1 ELSE 0 END)
            / NULLIF(SUM(status='completed'),0) * 100, 1) AS on_time_rate
    FROM workshop_jobs
    WHERE DATE(created_at) BETWEEN ? AND ?
");
$jobKpi->execute([$dateFrom, $dateTo]);
$jk = $jobKpi->fetch();

// ── Mechanic leaderboard with efficiency ──────────────────────────────────────
$mechanics = $db->prepare("
    SELECT m.id, m.name, m.specialization,
           COUNT(j.id)                                           AS total_jobs,
           SUM(j.status='completed')                            AS completed,
           SUM(j.status IN ('in_progress','pending'))           AS active,
           SUM(j.status='cancelled')                            AS cancelled,
           ROUND(AVG(CASE WHEN j.status='completed'
               THEN DATEDIFF(j.updated_at, j.created_at) END), 1) AS avg_days,
           ROUND(SUM(CASE WHEN j.status='completed'
               AND (j.end_date IS NULL OR j.updated_at <= j.end_date) THEN 1 ELSE 0 END)
               / NULLIF(SUM(j.status='completed'), 0) * 100, 1) AS on_time_pct,
           SUM(CASE WHEN j.priority='urgent' THEN 1 ELSE 0 END) AS urgent_jobs
    FROM mechanics m
    JOIN workshop_jobs j ON j.mechanic_id = m.id
    WHERE DATE(j.created_at) BETWEEN ? AND ?
    GROUP BY m.id, m.name, m.specialization
    ORDER BY completed DESC, on_time_pct DESC
");
$mechanics->execute([$dateFrom, $dateTo]);
$mechanics = $mechanics->fetchAll();

// ── Jobs by priority ──────────────────────────────────────────────────────────
$byPriority = $db->prepare("
    SELECT priority, COUNT(*) AS cnt,
           SUM(status='completed') AS done
    FROM workshop_jobs
    WHERE DATE(created_at) BETWEEN ? AND ?
    GROUP BY priority ORDER BY FIELD(priority,'urgent','high','normal','low')
");
$byPriority->execute([$dateFrom, $dateTo]);
$byPriority = $byPriority->fetchAll();

// ── Jobs by day of week (workload pattern) ────────────────────────────────────
$byDow = $db->prepare("
    SELECT DAYNAME(created_at) AS dow, COUNT(*) AS cnt
    FROM workshop_jobs WHERE DATE(created_at) BETWEEN ? AND ?
    GROUP BY dow ORDER BY DAYOFWEEK(created_at)
");
$byDow->execute([$dateFrom, $dateTo]);
$byDow = $byDow->fetchAll();

// ── Assessment stats ──────────────────────────────────────────────────────────
$assessStats = $db->prepare("
    SELECT assessment_type, COUNT(*) AS cnt,
           AVG(mileage) AS avg_mileage
    FROM car_assessments
    WHERE DATE(assessment_date) BETWEEN ? AND ?
    GROUP BY assessment_type
");
$assessStats->execute([$dateFrom, $dateTo]);
$assessStats = $assessStats->fetchAll();

// ── Parts usage (top requested parts) ────────────────────────────────────────
try {
    $partsUsage = $db->prepare("
        SELECT i.part_name, i.category,
               COUNT(pri.id)   AS request_count,
               SUM(pri.qty)    AS total_qty
        FROM parts_request_items pri
        JOIN inventory i ON i.id = pri.inventory_id
        JOIN parts_requests pr ON pr.id = pri.request_id
        WHERE DATE(pr.created_at) BETWEEN ? AND ?
        GROUP BY i.id, i.part_name, i.category
        ORDER BY total_qty DESC LIMIT 10
    ");
    $partsUsage->execute([$dateFrom, $dateTo]);
    $partsUsage = $partsUsage->fetchAll();
} catch (\Throwable $e) { $partsUsage = []; }

// ── Overdue jobs detail ─────────────────────────────────────────────────────────────
$overdueJobs = [];
try {
    $ovStmt = $db->query("
        SELECT j.id, j.end_date, j.priority, j.status,
               DATEDIFF(CURDATE(), j.end_date) AS days_overdue,
               m.name AS mechanic_name,
               c.make, c.model, c.year, c.registration_number,
               COALESCE(j.description, 'Workshop Job') AS description
        FROM workshop_jobs j
        LEFT JOIN mechanics m ON m.id = j.mechanic_id
        LEFT JOIN cars c ON c.id = j.car_id
        WHERE j.status NOT IN ('completed','cancelled')
          AND j.end_date IS NOT NULL
          AND j.end_date < CURDATE()
        ORDER BY days_overdue DESC
        LIMIT 15
    ");
    $overdueJobs = $ovStmt->fetchAll();
} catch (\Throwable $e) { $overdueJobs = []; }

// ── Monthly job trend (last 6 months) ────────────────────────────────────────────
$monthlyJobTrend = [];
try {
    $mjStmt = $db->query("
        SELECT DATE_FORMAT(created_at,'%b %Y') AS label,
               DATE_FORMAT(created_at,'%Y-%m') AS mkey,
               COUNT(*) AS total,
               SUM(status='completed') AS completed,
               SUM(status NOT IN ('completed','cancelled') AND end_date < CURDATE() AND end_date IS NOT NULL) AS overdue
        FROM workshop_jobs
        WHERE created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
        GROUP BY mkey, label ORDER BY mkey ASC
    ");
    $monthlyJobTrend = $mjStmt->fetchAll();
} catch (\Throwable $e) { $monthlyJobTrend = []; }

// ── Mechanic efficiency scores ────────────────────────────────────────────────────
// Score = (on_time_pct * 0.5) + (completion_rate * 0.3) + (speed_factor * 0.2)
// Speed factor: 100 if avg_days <= 3, scales down to 0 at 30 days
foreach ($mechanics as &$__mech) {
    $__onTime  = (float)($__mech['on_time_pct'] ?? 0);
    $__compRate = $__mech['total_jobs'] > 0 ? round((float)$__mech['completed'] / $__mech['total_jobs'] * 100, 1) : 0;
    $__avgDays  = (float)($__mech['avg_days'] ?? 15);
    $__speedFactor = max(0, round((1 - ($__avgDays / 30)) * 100, 1));
    $__mech['efficiency_score'] = round($__onTime * 0.5 + $__compRate * 0.3 + $__speedFactor * 0.2, 1);
    $__mech['completion_rate']  = $__compRate;
}
unset($__mech);

// ── Charts ────────────────────────────────────────────────────────────────────────
$dowLabels  = json_encode(array_column($byDow, 'dow'));
$dowCounts  = json_encode(array_column($byDow, 'cnt'));
$priLabels  = json_encode(array_map(fn($r) => ucfirst($r['priority']), $byPriority));
$priCounts  = json_encode(array_column($byPriority, 'cnt'));
$trendLabels   = json_encode(array_column($monthlyJobTrend, 'label'));
$trendTotal    = json_encode(array_column($monthlyJobTrend, 'total'));
$trendComplete = json_encode(array_column($monthlyJobTrend, 'completed'));
$trendOverdue  = json_encode(array_column($monthlyJobTrend, 'overdue'));

$extraJs = <<<JS
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
(function(){
    var dowEl = document.getElementById('dowChart');
    if (dowEl) new Chart(dowEl, {
        type:'bar',
        data:{ labels:{$dowLabels}, datasets:[{ data:{$dowCounts}, backgroundColor:'rgba(37,99,235,.7)', borderRadius:5, label:'Jobs' }] },
        options:{ responsive:true, plugins:{ legend:{display:false} }, scales:{ y:{ beginAtZero:true, ticks:{stepSize:1} } } }
    });
    var priEl = document.getElementById('priChart');
    if (priEl) new Chart(priEl, {
        type:'doughnut',
        data:{ labels:{$priLabels}, datasets:[{ data:{$priCounts}, backgroundColor:['#dc2626','#f97316','#2563eb','#64748b'], borderWidth:2, borderColor:'#fff' }] },
        options:{ cutout:'55%', plugins:{ legend:{ position:'bottom', labels:{ font:{size:11}, padding:8, boxWidth:10 } } } }
    });
    // Monthly Job Trend chart
    var trendEl = document.getElementById('jobTrendChart');
    if (trendEl) new Chart(trendEl, {
        type: 'bar',
        data: {
            labels: {$trendLabels},
            datasets: [
                { label: 'Total', data: {$trendTotal}, backgroundColor: 'rgba(37,99,235,.5)', borderRadius: 4, order: 2 },
                { label: 'Completed', data: {$trendComplete}, backgroundColor: 'rgba(22,163,74,.7)', borderRadius: 4, order: 3 },
                { label: 'Overdue', data: {$trendOverdue}, type: 'line', borderColor: '#ef4444', backgroundColor: 'rgba(239,68,68,.1)', borderWidth: 2, pointRadius: 4, tension: 0.3, fill: false, order: 1 }
            ]
        },
        options: {
            responsive: true,
            plugins: { legend: { position: 'top', labels: { font: { size: 11 }, padding: 10, boxWidth: 12 } }, tooltip: { mode: 'index', intersect: false } },
            scales: { y: { beginAtZero: true, ticks: { stepSize: 1 } } }
        }
    });
}());
</script>
JS;

include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/_nav.php';
?>

<!-- KPIs -->
<div class="row g-3 mb-4">
    <?php
    $kpis = [
        ['Jobs Created',      $jk['total'],       'primary',  'fa-toolbox',         'dbeafe','2563eb'],
        ['Completed',         $jk['completed'],   'success',  'fa-circle-check',    'dcfce7','16a34a'],
        ['Avg Completion',    ($jk['avg_completion_days'] ?? 0) . ' days', 'info', 'fa-clock', 'e0f2fe','0284c7'],
        ['On-Time Rate',      ($jk['on_time_rate'] ?? 0) . '%', $jk['on_time_rate'] >= 80 ? 'success' : 'warning', 'fa-bullseye', 'fef3c7','d97706'],
    ];
    foreach ($kpis as [$lbl, $val, $col, $icon, $ibg, $icol]): ?>
    <div class="col-sm-6 col-xl-3">
        <div class="stat-card" style="border-left:4px solid #<?= $icol ?>">
            <div class="stat-icon" style="background:#<?= $ibg ?>;color:#<?= $icol ?>"><i class="fa <?= $icon ?>"></i></div>
            <div class="stat-info">
                <div class="stat-label"><?= $lbl ?></div>
                <div class="stat-value <?= strlen((string)$val) > 4 ? 'stat-value-sm' : '' ?>"><?= $val ?></div>
                <?php if ($lbl === 'Avg Completion'): ?>
                <div class="text-muted" style="font-size:11px">per completed job</div>
                <?php elseif ($lbl === 'On-Time Rate'): ?>
                <div class="text-muted" style="font-size:11px">jobs finished on schedule</div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<!-- Mechanic leaderboard + Charts -->
<div class="row g-4 mb-4">
    <div class="col-lg-8">
        <div class="card h-100">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="fa fa-trophy me-2 text-warning"></i>Mechanic Performance — <?= e($label) ?></span>
                <a href="<?= BASE_URL ?>/modules/reports/export.php?type=mechanics&period=<?= urlencode($period) ?>&date_from=<?= urlencode($dateFrom) ?>&date_to=<?= urlencode($dateTo) ?>" class="btn btn-xs btn-outline-secondary">
                    <i class="fa fa-download me-1"></i>CSV
                </a>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0" style="font-size:13px">
                        <thead><tr>
                            <th class="ps-3">#</th>
                            <th>Mechanic</th>
                            <th class="text-center">Total</th>
                            <th class="text-center">Done</th>
                            <th class="text-center">Active</th>
                            <th class="text-center">Avg Days</th>
                            <th class="text-center">On-Time</th>
                            <th class="text-center">Urgent</th>
                            <th class="text-center">Score</th>
                        </tr></thead>
                        <tbody>
                        <?php if (!$mechanics): ?>
                        <tr><td colspan="8" class="text-center text-muted py-4">No jobs assigned in this period</td></tr>
                        <?php endif; ?>
                        <?php foreach ($mechanics as $i => $m):
                            $onTimeCls = $m['on_time_pct'] >= 80 ? 'success' : ($m['on_time_pct'] >= 60 ? 'warning text-dark' : 'danger');
                            $__scoreCls = $m['efficiency_score'] >= 70 ? 'success' : ($m['efficiency_score'] >= 50 ? 'warning text-dark' : 'danger');
                        ?>
                        <tr>
                            <td class="ps-3 text-muted"><?= $i+1 ?></td>
                            <td>
                                <div class="fw-semibold"><?= e($m['name']) ?></div>
                                <?php if ($m['specialization']): ?>
                                <div class="text-muted" style="font-size:11px"><?= e($m['specialization']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td class="text-center fw-semibold"><?= $m['total_jobs'] ?></td>
                            <td class="text-center"><span class="badge bg-success"><?= $m['completed'] ?></span></td>
                            <td class="text-center"><span class="badge bg-primary"><?= $m['active'] ?></span></td>
                            <td class="text-center text-muted"><?= $m['avg_days'] ?? '—' ?></td>
                            <td class="text-center">
                                <?php if ($m['on_time_pct'] !== null): ?>
                                <span class="badge bg-<?= $onTimeCls ?>"><?= $m['on_time_pct'] ?>%</span>
                                <?php else: ?>—<?php endif; ?>
                            </td>
                            <td class="text-center">
                                <?php if ($m['urgent_jobs'] > 0): ?>
                                <span class="badge bg-danger"><?= $m['urgent_jobs'] ?></span>
                                <?php else: ?><span class="text-muted">—</span><?php endif; ?>
                            </td>
                            <td class="text-center">
                                <span class="badge bg-<?= $__scoreCls ?>" title="Weighted: on-time 50%, completion 30%, speed 20%"><?= $m['efficiency_score'] ?></span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card mb-3">
            <div class="card-header" style="font-size:13px"><i class="fa fa-calendar-week me-2"></i>Jobs by Day of Week</div>
            <div class="card-body"><canvas id="dowChart" height="130"></canvas></div>
        </div>
        <div class="card">
            <div class="card-header" style="font-size:13px"><i class="fa fa-flag me-2"></i>Jobs by Priority</div>
            <div class="card-body"><canvas id="priChart" height="140"></canvas></div>
        </div>
    </div>
</div>

<!-- Assessments + Parts usage -->
<div class="row g-4 mb-4">
    <div class="col-lg-5">
        <div class="card h-100">
            <div class="card-header"><i class="fa fa-clipboard-check me-2 text-primary"></i>Assessments — <?= e($label) ?></div>
            <div class="card-body p-0">
                <table class="table table-hover mb-0">
                    <thead><tr><th class="ps-3">Type</th><th class="text-center">Count</th><th class="text-end pe-3">Avg Mileage</th></tr></thead>
                    <tbody>
                    <?php if (!$assessStats): ?>
                    <tr><td colspan="3" class="text-center text-muted py-4">No assessments in this period</td></tr>
                    <?php endif; ?>
                    <?php
                    $assessLabels = ['pre_departure'=>'Pre-Departure','arrival'=>'Arrival','pre_sales'=>'Pre-Sales','pre_delivery'=>'Pre-Delivery'];
                    foreach ($assessStats as $as): ?>
                    <tr>
                        <td class="ps-3"><?= $assessLabels[$as['assessment_type']] ?? $as['assessment_type'] ?></td>
                        <td class="text-center fw-semibold"><?= $as['cnt'] ?></td>
                        <td class="text-end pe-3 text-muted small"><?= $as['avg_mileage'] ? number_format((float)$as['avg_mileage']) . ' km' : '—' ?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <div class="col-lg-7">
        <div class="card h-100">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="fa fa-hand-holding-box me-2 text-primary"></i>Top Parts Requested — <?= e($label) ?></span>
                <a href="<?= BASE_URL ?>/modules/reports/export.php?type=parts_usage&period=<?= urlencode($period) ?>&date_from=<?= urlencode($dateFrom) ?>&date_to=<?= urlencode($dateTo) ?>" class="btn btn-xs btn-outline-secondary">
                    <i class="fa fa-download me-1"></i>CSV
                </a>
            </div>
            <div class="card-body p-0">
                <table class="table table-hover mb-0" style="font-size:13px">
                    <thead><tr><th class="ps-3">Part</th><th>Category</th><th class="text-center">Requests</th><th class="text-end pe-3">Total Qty</th></tr></thead>
                    <tbody>
                    <?php if (!$partsUsage): ?>
                    <tr><td colspan="4" class="text-center text-muted py-4">No parts request data</td></tr>
                    <?php endif; ?>
                    <?php foreach ($partsUsage as $p): ?>
                    <tr>
                        <td class="ps-3 fw-medium small"><?= e($p['part_name']) ?></td>
                        <td class="text-muted small"><?= e($p['category'] ?? '—') ?></td>
                        <td class="text-center"><?= $p['request_count'] ?></td>
                        <td class="text-end pe-3 fw-semibold"><?= number_format($p['total_qty']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- ── Monthly Job Trend Chart ────────────────────────────────────────────────── -->
<?php if (!empty($monthlyJobTrend)): ?>
<div class="card mb-4">
    <div class="card-header fw-semibold"><i class="fa fa-chart-bar me-2 text-primary"></i>Monthly Job Volume — Last 6 Months</div>
    <div class="card-body"><canvas id="jobTrendChart" height="70"></canvas></div>
</div>
<?php endif; ?>

<!-- ── Overdue Jobs ─────────────────────────────────────────────────────────────────── -->
<?php if (!empty($overdueJobs)): ?>
<div class="card mb-4 border-danger-subtle">
    <div class="card-header fw-semibold text-danger bg-danger-subtle">
        <i class="fa fa-triangle-exclamation me-2"></i>Overdue Jobs (<?= count($overdueJobs) ?>)
    </div>
    <div class="table-responsive">
        <table class="table table-hover mb-0" style="font-size:13px">
            <thead style="background:#fff5f5;font-size:11px;text-transform:uppercase;letter-spacing:.05em;color:#64748b">
                <tr>
                    <th class="ps-3 py-2">Vehicle</th>
                    <th class="py-2">Mechanic</th>
                    <th class="py-2">Description</th>
                    <th class="py-2 text-center">Priority</th>
                    <th class="py-2 text-center">Due Date</th>
                    <th class="py-2 text-end pe-3">Days Overdue</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($overdueJobs as $__oj):
                $__priCls = match($__oj['priority'] ?? 'normal') { 'urgent'=>'danger','high'=>'warning text-dark','low'=>'secondary', default=>'primary' };
            ?>
            <tr>
                <td class="ps-3 fw-medium">
                    <?php if ($__oj['make'] || $__oj['model']): ?>
                    <?= e($__oj['year'].' '.$__oj['make'].' '.$__oj['model']) ?>
                    <?php if ($__oj['registration_number']): ?><br><span class="badge bg-dark" style="font-size:10px"><?= e($__oj['registration_number']) ?></span><?php endif; ?>
                    <?php else: ?>Job #<?= $__oj['id'] ?><?php endif; ?>
                </td>
                <td class="text-muted"><?= e($__oj['mechanic_name'] ?? '—') ?></td>
                <td class="text-muted small" style="max-width:200px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= e($__oj['description']) ?></td>
                <td class="text-center"><span class="badge bg-<?= $__priCls ?>"><?= ucfirst($__oj['priority'] ?? 'normal') ?></span></td>
                <td class="text-center text-muted small"><?= fmtDate($__oj['end_date']) ?></td>
                <td class="text-end pe-3 fw-bold text-danger"><?= $__oj['days_overdue'] ?> day<?= $__oj['days_overdue'] != 1 ? 's' : '' ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<!-- ── Mechanic Efficiency Scores ──────────────────────────────────────────────────── -->
<?php if (!empty($mechanics)): ?>
<div class="card mb-4">
    <div class="card-header fw-semibold"><i class="fa fa-star me-2 text-warning"></i>Mechanic Efficiency Scores — <?= e($label) ?></div>
    <div class="card-body">
        <p class="text-muted small mb-3">Score = On-Time Rate (50%) + Completion Rate (30%) + Speed Factor (20%). Higher is better.</p>
        <div class="row g-3">
        <?php foreach ($mechanics as $__mech):
            $__esc = $__mech['efficiency_score'] >= 70 ? 'success' : ($__mech['efficiency_score'] >= 50 ? 'warning' : 'danger');
        ?>
        <div class="col-md-6 col-lg-4">
            <div class="p-3 rounded-3 border" style="font-size:13px">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <div>
                        <div class="fw-semibold"><?= e($__mech['name']) ?></div>
                        <div class="text-muted" style="font-size:11px"><?= e($__mech['specialization'] ?? 'General') ?></div>
                    </div>
                    <div class="text-<?= $__esc ?> fw-bold" style="font-size:1.6rem"><?= $__mech['efficiency_score'] ?></div>
                </div>
                <div class="progress mb-2" style="height:6px;border-radius:3px">
                    <div class="progress-bar bg-<?= $__esc ?>" style="width:<?= min(100,$__mech['efficiency_score']) ?>%;transition:width .8s"></div>
                </div>
                <div class="row g-1 text-center" style="font-size:11px">
                    <div class="col-4"><div class="text-muted">On-Time</div><div class="fw-semibold"><?= $__mech['on_time_pct'] ?? '—' ?>%</div></div>
                    <div class="col-4"><div class="text-muted">Completion</div><div class="fw-semibold"><?= $__mech['completion_rate'] ?>%</div></div>
                    <div class="col-4"><div class="text-muted">Avg Days</div><div class="fw-semibold"><?= $__mech['avg_days'] ?? '—' ?></div></div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<?php include __DIR__ . '/../../includes/footer.php'; ?>

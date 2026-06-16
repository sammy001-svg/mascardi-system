<?php
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
canAccess('crm') || redirect(BASE_URL . '/index.php');

// Only managers/admin may view this page — CRM agents are redirected to their own dashboard
$me = authUser();
if ($me['role'] === 'customer_relations') {
    redirect(BASE_URL . '/modules/crm/my_dashboard.php');
}

$db        = getDB();
$pageTitle = 'Team Performance';

// ── Date range ────────────────────────────────────────────────────────────────
$period       = $_GET['period'] ?? 'this_month';
$validPeriods = ['this_month', 'last_month', 'last_3_months', 'this_year', 'last_30_days'];
if (!in_array($period, $validPeriods)) $period = 'this_month';

switch ($period) {
    case 'last_month':
        $dateFrom    = date('Y-m-01', strtotime('first day of last month'));
        $dateTo      = date('Y-m-t',  strtotime('last day of last month'));
        $periodLabel = 'Last Month';
        break;
    case 'last_3_months':
        $dateFrom    = date('Y-m-01', strtotime('-2 months'));
        $dateTo      = date('Y-m-d');
        $periodLabel = 'Last 3 Months';
        break;
    case 'this_year':
        $dateFrom    = date('Y-01-01');
        $dateTo      = date('Y-12-31');
        $periodLabel = 'This Year';
        break;
    case 'last_30_days':
        $dateFrom    = date('Y-m-d', strtotime('-29 days'));
        $dateTo      = date('Y-m-d');
        $periodLabel = 'Last 30 Days';
        break;
    default: // this_month
        $dateFrom    = date('Y-m-01');
        $dateTo      = date('Y-m-t');
        $periodLabel = 'This Month';
        break;
}

// ── Data queries ──────────────────────────────────────────────────────────────
$agents       = [];
$actMap       = [];
$totalActive  = 0;
$totalDelivered = 0;
$totalLost    = 0;
$totalLeads   = 0;

try {
    // 1. Per-agent performance
    $stmt = $db->prepare("
        SELECT
            u.id,
            u.name,
            u.email,
            COUNT(DISTINCT l.id) AS total_leads,
            SUM(CASE WHEN l.stage NOT IN ('lost','delivered') THEN 1 ELSE 0 END) AS active_leads,
            SUM(CASE WHEN l.stage = 'delivered'
                      AND l.converted_at BETWEEN ? AND ?
                     THEN 1 ELSE 0 END) AS delivered_period,
            SUM(CASE WHEN l.stage = 'lost'
                      AND l.updated_at BETWEEN ? AND ?
                     THEN 1 ELSE 0 END) AS lost_period,
            SUM(CASE WHEN l.stage = 'delivered' THEN 1 ELSE 0 END) AS total_delivered,
            COUNT(DISTINCT CASE
                WHEN l.follow_up_date < CURDATE()
                 AND l.stage NOT IN ('lost','delivered')
                THEN l.id
            END) AS overdue_count
        FROM users u
        LEFT JOIN crm_leads l ON l.assigned_to = u.id
        WHERE u.role = 'customer_relations'
          AND u.status = 'active'
        GROUP BY u.id, u.name, u.email
        ORDER BY delivered_period DESC, active_leads DESC
    ");
    $stmt->execute([$dateFrom, $dateTo, $dateFrom, $dateTo]);
    $agents = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (\Throwable $ex) {
    $agents = [];
}

try {
    // 2. Activity counts per agent in period
    $stmt = $db->prepare("
        SELECT created_by, COUNT(*) AS act_count
        FROM crm_activities
        WHERE created_at BETWEEN ? AND ?
        GROUP BY created_by
    ");
    $stmt->execute([$dateFrom . ' 00:00:00', $dateTo . ' 23:59:59']);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $actMap[(int)$row['created_by']] = (int)$row['act_count'];
    }
} catch (\Throwable $ex) {
    $actMap = [];
}

try {
    // 3. Overall period totals
    $totalActive = (int)$db->query(
        "SELECT COUNT(*) FROM crm_leads WHERE stage NOT IN ('lost','delivered')"
    )->fetchColumn();

    $stmtDel = $db->prepare(
        "SELECT COUNT(*) FROM crm_leads WHERE stage = 'delivered' AND converted_at BETWEEN ? AND ?"
    );
    $stmtDel->execute([$dateFrom . ' 00:00:00', $dateTo . ' 23:59:59']);
    $totalDelivered = (int)$stmtDel->fetchColumn();

    $stmtLost = $db->prepare(
        "SELECT COUNT(*) FROM crm_leads WHERE stage = 'lost' AND updated_at BETWEEN ? AND ?"
    );
    $stmtLost->execute([$dateFrom . ' 00:00:00', $dateTo . ' 23:59:59']);
    $totalLost = (int)$stmtLost->fetchColumn();

    $totalLeads = (int)$db->query("SELECT COUNT(*) FROM crm_leads")->fetchColumn();
} catch (\Throwable $ex) {
    $totalActive = $totalDelivered = $totalLost = $totalLeads = 0;
}

// Overall conversion % (all-time delivered / all leads)
$overallConv = $totalLeads > 0 ? round($totalDelivered / $totalLeads * 100, 1) : 0;

// ── Chart data ────────────────────────────────────────────────────────────────
$chartLabels = [];
$chartData   = [];
foreach ($agents as $a) {
    $chartLabels[] = $a['name'];
    $chartData[]   = (int)$a['delivered_period'];
}
$chartLabelsJson = json_encode($chartLabels);
$chartDataJson   = json_encode($chartData);

// ── Chart JS (set before header.php so footer.php can echo it) ───────────────
$extraJs = <<<JS
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
(function () {
    var ctx = document.getElementById('agentDeliveredChart');
    if (!ctx) return;
    new Chart(ctx, {
        type: 'bar',
        data: {
            labels: {$chartLabelsJson},
            datasets: [{
                label: 'Delivered',
                data: {$chartDataJson},
                backgroundColor: 'rgba(37,99,235,0.75)',
                borderColor: '#2563eb',
                borderWidth: 1,
                borderRadius: 6,
                borderSkipped: false
            }]
        },
        options: {
            indexAxis: 'y',
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label: function(ctx) { return ' ' + ctx.parsed.x + ' delivered'; }
                    }
                }
            },
            scales: {
                x: {
                    beginAtZero: true,
                    ticks: { stepSize: 1, precision: 0 },
                    grid: { color: '#f1f5f9' }
                },
                y: {
                    grid: { display: false },
                    ticks: { font: { size: 12 } }
                }
            }
        }
    });
}());
</script>
JS;

include __DIR__ . '/../../includes/header.php';
?>

<!-- ── Page header ─────────────────────────────────────────────────────────── -->
<div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
    <div>
        <h5 class="mb-1 fw-bold">
            <i class="fa fa-chart-bar me-2 text-primary"></i>Team Performance
        </h5>
        <div class="text-muted small">
            Agent delivery overview &mdash; <?= e($periodLabel) ?>
            (<?= date('d M Y', strtotime($dateFrom)) ?> &ndash; <?= date('d M Y', strtotime($dateTo)) ?>)
        </div>
    </div>
    <a href="index.php" class="btn btn-sm btn-outline-secondary">
        <i class="fa fa-arrow-left me-1"></i>Back to CRM
    </a>
</div>

<!-- ── Period filter ───────────────────────────────────────────────────────── -->
<div class="card mb-4">
    <div class="card-body py-2 px-3">
        <div class="d-flex align-items-center gap-3 flex-wrap">
            <span class="small fw-semibold text-muted">Period:</span>
            <?php
            $periods = [
                'this_month'    => 'This Month',
                'last_month'    => 'Last Month',
                'last_30_days'  => 'Last 30 Days',
                'last_3_months' => 'Last 3 Months',
                'this_year'     => 'This Year',
            ];
            ?>
            <div class="btn-group btn-group-sm" role="group" aria-label="Period filter">
                <?php foreach ($periods as $pk => $pl): ?>
                <a href="?period=<?= $pk ?>"
                   class="btn <?= $period === $pk ? 'btn-primary' : 'btn-outline-secondary' ?>">
                    <?= e($pl) ?>
                </a>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>

<!-- ── KPI Summary Cards ───────────────────────────────────────────────────── -->
<div class="row g-3 mb-4">
    <div class="col-6 col-lg-3">
        <div class="card h-100 border-top border-primary border-3">
            <div class="card-body d-flex align-items-center gap-3">
                <div class="rounded-3 p-3" style="background:#dbeafe;color:#2563eb;font-size:20px;flex-shrink:0">
                    <i class="fa fa-user-group"></i>
                </div>
                <div>
                    <div class="text-muted" style="font-size:11.5px;font-weight:600;text-transform:uppercase;letter-spacing:.04em">Total Active Leads</div>
                    <div class="fw-bold" style="font-size:28px;line-height:1"><?= $totalActive ?></div>
                    <div class="text-muted" style="font-size:11.5px">in pipeline now</div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card h-100 border-top border-success border-3">
            <div class="card-body d-flex align-items-center gap-3">
                <div class="rounded-3 p-3" style="background:#dcfce7;color:#16a34a;font-size:20px;flex-shrink:0">
                    <i class="fa fa-trophy"></i>
                </div>
                <div>
                    <div class="text-muted" style="font-size:11.5px;font-weight:600;text-transform:uppercase;letter-spacing:.04em">Delivered</div>
                    <div class="fw-bold" style="font-size:28px;line-height:1"><?= $totalDelivered ?></div>
                    <div class="text-muted" style="font-size:11.5px"><?= e($periodLabel) ?></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card h-100 border-top border-danger border-3">
            <div class="card-body d-flex align-items-center gap-3">
                <div class="rounded-3 p-3" style="background:#fee2e2;color:#dc2626;font-size:20px;flex-shrink:0">
                    <i class="fa fa-circle-xmark"></i>
                </div>
                <div>
                    <div class="text-muted" style="font-size:11.5px;font-weight:600;text-transform:uppercase;letter-spacing:.04em">Lost</div>
                    <div class="fw-bold" style="font-size:28px;line-height:1"><?= $totalLost ?></div>
                    <div class="text-muted" style="font-size:11.5px"><?= e($periodLabel) ?></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card h-100 border-top border-3" style="border-color:#9333ea!important">
            <div class="card-body d-flex align-items-center gap-3">
                <div class="rounded-3 p-3" style="background:#f3e8ff;color:#9333ea;font-size:20px;flex-shrink:0">
                    <i class="fa fa-chart-line"></i>
                </div>
                <div>
                    <div class="text-muted" style="font-size:11.5px;font-weight:600;text-transform:uppercase;letter-spacing:.04em">Overall Conv. %</div>
                    <div class="fw-bold" style="font-size:28px;line-height:1"><?= $overallConv ?>%</div>
                    <div class="text-muted" style="font-size:11.5px"><?= $totalDelivered ?> of <?= $totalLeads ?> all-time</div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ── Agents table + Bar chart ────────────────────────────────────────────── -->
<div class="row g-4 mb-4">

    <!-- Agents table -->
    <div class="col-lg-8">
        <div class="card h-100">
            <div class="card-header fw-semibold">
                <i class="fa fa-users me-2 text-primary"></i>Agent Leaderboard
                <span class="badge bg-secondary ms-1 fw-normal" style="font-size:11px"><?= e($periodLabel) ?></span>
            </div>
            <div class="card-body p-0">
                <?php if (empty($agents)): ?>
                <div class="text-center text-muted py-5">
                    <i class="fa fa-users-slash fa-2x d-block mb-3 opacity-25"></i>
                    <p class="mb-0 fw-semibold">No active CRM agents found.</p>
                    <p class="small mt-1">Assign the <code>customer_relations</code> role to users to see them here.</p>
                </div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0" style="font-size:13.5px">
                        <thead class="table-light">
                            <tr>
                                <th class="ps-3" style="width:42px">#</th>
                                <th>Agent</th>
                                <th class="text-center">Active</th>
                                <th class="text-center">Delivered &#9989;</th>
                                <th class="text-center">Lost &#10060;</th>
                                <th class="text-center">Activities</th>
                                <th class="text-center">Overdue &#9888;</th>
                                <th class="text-center">Conv.&nbsp;%</th>
                                <th style="min-width:100px">Progress</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php
                        // Determine max delivered_period for the progress bar scale
                        $maxDelivered = max(1, max(array_column($agents, 'delivered_period')));
                        foreach ($agents as $rank => $agent):
                            $delivered  = (int)$agent['delivered_period'];
                            $lost       = (int)$agent['lost_period'];
                            $active     = (int)$agent['active_leads'];
                            $overdue    = (int)$agent['overdue_count'];
                            $totalAgent = (int)$agent['total_leads'];
                            $activities = $actMap[(int)$agent['id']] ?? 0;
                            $convPct    = $totalAgent > 0 ? round((int)$agent['total_delivered'] / $totalAgent * 100, 1) : 0;
                            $convClass  = $convPct >= 50 ? 'text-success' : ($convPct >= 30 ? 'text-warning' : 'text-danger');
                            $barWidth   = $maxDelivered > 0 ? round($delivered / $maxDelivered * 100) : 0;
                        ?>
                        <tr>
                            <td class="ps-3 text-muted fw-semibold"><?= $rank + 1 ?></td>
                            <td>
                                <a href="leads.php?assigned=<?= (int)$agent['id'] ?>"
                                   class="fw-semibold text-decoration-none">
                                    <?= e($agent['name']) ?>
                                </a>
                                <div class="text-muted" style="font-size:11.5px"><?= e($agent['email']) ?></div>
                            </td>
                            <td class="text-center">
                                <span class="badge bg-primary bg-opacity-10 text-primary fw-semibold"><?= $active ?></span>
                            </td>
                            <td class="text-center">
                                <?php if ($delivered > 0): ?>
                                <span class="badge bg-success bg-opacity-10 text-success fw-semibold"><?= $delivered ?></span>
                                <?php else: ?>
                                <span class="text-muted">0</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-center">
                                <?php if ($lost > 0): ?>
                                <span class="badge bg-danger bg-opacity-10 text-danger fw-semibold"><?= $lost ?></span>
                                <?php else: ?>
                                <span class="text-muted">0</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-center text-muted"><?= $activities ?></td>
                            <td class="text-center <?= $overdue > 0 ? 'text-danger fw-semibold' : 'text-muted' ?>">
                                <?= $overdue ?>
                            </td>
                            <td class="text-center fw-bold <?= $convClass ?>"><?= $convPct ?>%</td>
                            <td>
                                <div class="progress" style="height:8px;border-radius:100px;background:#f1f5f9">
                                    <div class="progress-bar bg-primary"
                                         role="progressbar"
                                         style="width:<?= $barWidth ?>%;border-radius:100px"
                                         aria-valuenow="<?= $barWidth ?>"
                                         aria-valuemin="0"
                                         aria-valuemax="100">
                                    </div>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Horizontal bar chart -->
    <div class="col-lg-4">
        <div class="card h-100">
            <div class="card-header fw-semibold">
                <i class="fa fa-chart-bar me-2 text-primary"></i>Delivered per Agent
                <span class="badge bg-secondary ms-1 fw-normal" style="font-size:11px"><?= e($periodLabel) ?></span>
            </div>
            <div class="card-body" style="position:relative;min-height:<?= max(180, count($agents) * 42) ?>px">
                <?php if (empty($agents)): ?>
                <div class="d-flex align-items-center justify-content-center h-100 text-muted">
                    <div class="text-center">
                        <i class="fa fa-chart-bar fa-2x d-block mb-2 opacity-25"></i>
                        No data to display.
                    </div>
                </div>
                <?php else: ?>
                <canvas id="agentDeliveredChart"></canvas>
                <?php endif; ?>
            </div>
        </div>
    </div>

</div>

<div class="mb-4"></div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>

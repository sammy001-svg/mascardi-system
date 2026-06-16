<?php
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
canAccess('crm') || redirect(BASE_URL . '/index.php');

$db  = getDB();
$me  = authUser();
$uid = (int)$me['id'];

// Data isolation
$isCrmAgent = ($me['role'] === 'customer_relations');
$pageTitle  = $isCrmAgent ? 'My Reports' : 'CRM Reports';

// ── Date range ───────────────────────────────────────────────────────────────
$period = $_GET['period'] ?? 'this_month';
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

// ── Ownership filters ────────────────────────────────────────────────────────
$ownerWhere = $isCrmAgent ? "AND assigned_to = {$uid}" : '';
$ownerJoin  = $isCrmAgent ? "AND l.assigned_to = {$uid}" : '';

// ── Reference data ───────────────────────────────────────────────────────────
$stages = [
    'hot'       => ['label' => 'Hot',       'badge' => 'danger'],
    'lukewarm'  => ['label' => 'Lukewarm',  'badge' => 'warning'],
    'cold'      => ['label' => 'Cold',      'badge' => 'info'],
    'reserved'  => ['label' => 'Reserved',  'badge' => 'purple'],
    'lost'      => ['label' => 'Lost',      'badge' => 'secondary'],
    'delivered' => ['label' => 'Delivered', 'badge' => 'success'],
];

$sources = [
    'walk_in'    => 'Walk-in',
    'referral'   => 'Referral',
    'facebook'   => 'Facebook',
    'instagram'  => 'Instagram',
    'website'    => 'Website',
    'phone_call' => 'Phone Call',
    'whatsapp'   => 'WhatsApp',
    'other'      => 'Other',
];

$activityTypes = [
    'call'       => 'Call',
    'whatsapp'   => 'WhatsApp',
    'email'      => 'Email',
    'visit'      => 'Visit',
    'test_drive' => 'Test Drive',
    'meeting'    => 'Meeting',
    'note'       => 'Note',
];

// ── Queries ──────────────────────────────────────────────────────────────────
try {
    // KPI: Leads added in period
    $kpiAdded = (int)$db->query("
        SELECT COUNT(*) FROM crm_leads
        WHERE DATE(created_at) BETWEEN '{$dateFrom}' AND '{$dateTo}'
        {$ownerWhere}
    ")->fetchColumn();

    // KPI: Delivered in period
    $kpiDelivered = (int)$db->query("
        SELECT COUNT(*) FROM crm_leads
        WHERE stage = 'delivered'
          AND DATE(updated_at) BETWEEN '{$dateFrom}' AND '{$dateTo}'
        {$ownerWhere}
    ")->fetchColumn();

    // KPI: Lost in period
    $kpiLost = (int)$db->query("
        SELECT COUNT(*) FROM crm_leads
        WHERE stage = 'lost'
          AND DATE(updated_at) BETWEEN '{$dateFrom}' AND '{$dateTo}'
        {$ownerWhere}
    ")->fetchColumn();

    // KPI: All-time conversion rate
    $totalAllTime     = (int)$db->query("SELECT COUNT(*) FROM crm_leads WHERE 1 {$ownerWhere}")->fetchColumn();
    $deliveredAllTime = (int)$db->query("SELECT COUNT(*) FROM crm_leads WHERE stage='delivered' {$ownerWhere}")->fetchColumn();
    $convRate         = $totalAllTime > 0 ? round($deliveredAllTime / $totalAllTime * 100, 1) : 0;

    // Monthly performance — last 6 months
    $monthlyPerf = [];
    for ($i = 5; $i >= 0; $i--) {
        $ymStart = date('Y-m-01', strtotime("-{$i} months"));
        $ymEnd   = date('Y-m-t',  strtotime("-{$i} months"));
        $ymLabel = date('M Y',    strtotime($ymStart));
        $added     = (int)$db->query("SELECT COUNT(*) FROM crm_leads WHERE DATE(created_at) BETWEEN '{$ymStart}' AND '{$ymEnd}' {$ownerWhere}")->fetchColumn();
        $delivered = (int)$db->query("SELECT COUNT(*) FROM crm_leads WHERE stage='delivered' AND DATE(updated_at) BETWEEN '{$ymStart}' AND '{$ymEnd}' {$ownerWhere}")->fetchColumn();
        $lost      = (int)$db->query("SELECT COUNT(*) FROM crm_leads WHERE stage='lost' AND DATE(updated_at) BETWEEN '{$ymStart}' AND '{$ymEnd}' {$ownerWhere}")->fetchColumn();
        $active    = (int)$db->query("SELECT COUNT(*) FROM crm_leads WHERE stage NOT IN ('lost','delivered') AND DATE(created_at) <= '{$ymEnd}' {$ownerWhere}")->fetchColumn();
        $mConv     = $added > 0 ? round($delivered / $added * 100, 1) : 0;
        $monthlyPerf[] = compact('ymLabel', 'added', 'delivered', 'lost', 'active', 'mConv');
    }

    // Activity breakdown in period
    $actRows = $db->query("
        SELECT a.type, COUNT(*) cnt
        FROM crm_activities a
        " . ($isCrmAgent ? "WHERE a.created_by = {$uid} AND" : "WHERE") . "
              DATE(a.created_at) BETWEEN '{$dateFrom}' AND '{$dateTo}'
        GROUP BY a.type
        ORDER BY cnt DESC
    ")->fetchAll(\PDO::FETCH_ASSOC);
    $actMap = [];
    foreach ($actRows as $r) $actMap[$r['type']] = (int)$r['cnt'];
    $actMax = $actMap ? max($actMap) : 1;

    // Lead source analysis in period
    $sourceRows = $db->query("
        SELECT source, COUNT(*) cnt
        FROM crm_leads
        WHERE DATE(created_at) BETWEEN '{$dateFrom}' AND '{$dateTo}'
        {$ownerWhere}
        GROUP BY source
        ORDER BY cnt DESC
    ")->fetchAll(\PDO::FETCH_ASSOC);
    $sourceMap   = [];
    $sourceTotal = 0;
    foreach ($sourceRows as $r) {
        $sourceMap[$r['source']] = (int)$r['cnt'];
        $sourceTotal += (int)$r['cnt'];
    }

    // Stage distribution — current active leads
    $stageDistRows = $db->query("
        SELECT stage, COUNT(*) cnt
        FROM crm_leads
        WHERE stage NOT IN ('lost','delivered')
        {$ownerWhere}
        GROUP BY stage
        ORDER BY cnt DESC
    ")->fetchAll(\PDO::FETCH_ASSOC);
    $stageDistMap = [];
    foreach ($stageDistRows as $r) $stageDistMap[$r['stage']] = (int)$r['cnt'];

    // Follow-up compliance — active leads with follow_up_date set
    $activeTotal  = (int)$db->query("SELECT COUNT(*) FROM crm_leads WHERE stage NOT IN ('lost','delivered') {$ownerWhere}")->fetchColumn();
    $withFollowUp = (int)$db->query("SELECT COUNT(*) FROM crm_leads WHERE stage NOT IN ('lost','delivered') AND follow_up_date IS NOT NULL {$ownerWhere}")->fetchColumn();
    $followUpPct  = $activeTotal > 0 ? round($withFollowUp / $activeTotal * 100, 1) : 0;

    // Lost reason analysis — top 5
    $lostReasonRows = $db->query("
        SELECT lost_reason, COUNT(*) cnt
        FROM crm_leads
        WHERE stage = 'lost'
          AND lost_reason IS NOT NULL
          AND lost_reason != ''
        {$ownerWhere}
        GROUP BY lost_reason
        ORDER BY cnt DESC
        LIMIT 5
    ")->fetchAll(\PDO::FETCH_ASSOC);

    // Chart: 6-month trend labels/data
    $trendLabels = [];
    $trendCounts = [];
    foreach ($monthlyPerf as $mp) {
        $trendLabels[] = $mp['ymLabel'];
        $trendCounts[] = $mp['added'];
    }

    // Chart: stage doughnut
    $stageChartColors = [
        'hot'      => '#dc2626',
        'lukewarm' => '#d97706',
        'cold'     => '#0891b2',
        'reserved' => '#7c3aed',
    ];
    $stageChartLabels = [];
    $stageChartData   = [];
    $stageChartColorsArr = [];
    foreach ($stageDistMap as $sk => $sv) {
        $stageChartLabels[]    = $stages[$sk]['label'] ?? ucfirst($sk);
        $stageChartData[]      = $sv;
        $stageChartColorsArr[] = $stageChartColors[$sk] ?? '#94a3b8';
    }

} catch (\Throwable $ex) {
    $kpiAdded = $kpiDelivered = $kpiLost = 0;
    $totalAllTime = $deliveredAllTime = 0;
    $convRate = 0.0;
    $monthlyPerf = $actMap = $sourceMap = $stageDistMap = $lostReasonRows = [];
    $actMax = 1;
    $sourceTotal = 0;
    $activeTotal = $withFollowUp = 0;
    $followUpPct = 0.0;
    $trendLabels = $trendCounts = [];
    $stageChartLabels = $stageChartData = $stageChartColorsArr = [];
}

// ── Chart JS (assigned before header.php) ────────────────────────────────────
$trendLabelsJson      = json_encode($trendLabels);
$trendCountsJson      = json_encode($trendCounts);
$stageChartLabelsJson = json_encode($stageChartLabels);
$stageChartDataJson   = json_encode($stageChartData);
$stageChartColorsJson = json_encode($stageChartColorsArr);

$extraJs = <<<JS
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
(function () {
    // Line chart — 6-month lead trend
    var tc = document.getElementById('trendChart');
    if (tc) {
        new Chart(tc, {
            type: 'line',
            data: {
                labels: {$trendLabelsJson},
                datasets: [{
                    label: 'Leads Added',
                    data: {$trendCountsJson},
                    borderColor: '#2563eb',
                    backgroundColor: 'rgba(37,99,235,0.08)',
                    borderWidth: 2.5,
                    pointRadius: 4,
                    pointBackgroundColor: '#2563eb',
                    tension: 0.4,
                    fill: true
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: {
                    y: { beginAtZero: true, ticks: { stepSize: 1 }, grid: { color: '#f1f5f9' } },
                    x: { grid: { display: false } }
                }
            }
        });
    }

    // Doughnut chart — stage distribution
    var sc = document.getElementById('stageChart');
    if (sc) {
        new Chart(sc, {
            type: 'doughnut',
            data: {
                labels: {$stageChartLabelsJson},
                datasets: [{
                    data: {$stageChartDataJson},
                    backgroundColor: {$stageChartColorsJson},
                    borderWidth: 2,
                    borderColor: '#fff',
                    hoverOffset: 6
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: { padding: 12, font: { size: 12 } }
                    }
                },
                cutout: '62%'
            }
        });
    }
}());
</script>
JS;

include __DIR__ . '/../../includes/header.php';
?>

<style>
/* ── CRM Reports page ─────────────────────────────────────────── */
.rpt-kpi {
    background: var(--surface, #fff);
    border-radius: 14px;
    padding: 20px 22px;
    border: 1px solid var(--border, #e5e7eb);
    border-top-width: 3px;
    display: flex;
    align-items: center;
    gap: 16px;
    transition: box-shadow .2s, transform .2s;
}
.rpt-kpi:hover {
    box-shadow: 0 6px 20px rgba(0,0,0,.08);
    transform: translateY(-2px);
}
.rpt-kpi-icon {
    width: 50px; height: 50px;
    border-radius: 12px;
    display: flex; align-items: center; justify-content: center;
    font-size: 22px;
    flex-shrink: 0;
}
.rpt-kpi-label {
    font-size: 11.5px;
    font-weight: 600;
    color: var(--text-3, #6b7280);
    text-transform: uppercase;
    letter-spacing: .04em;
    margin-bottom: 3px;
}
.rpt-kpi-value {
    font-size: 30px;
    font-weight: 800;
    line-height: 1;
    color: var(--text, #111827);
}
.rpt-kpi-sub {
    font-size: 11.5px;
    color: var(--text-3, #6b7280);
    margin-top: 4px;
}
.bar-row {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 10px;
}
.bar-label {
    min-width: 110px;
    font-size: 13px;
    font-weight: 500;
    color: var(--text, #111);
    white-space: nowrap;
}
.bar-track {
    flex: 1;
    background: #f1f5f9;
    border-radius: 100px;
    height: 10px;
    overflow: hidden;
}
.bar-fill {
    height: 100%;
    border-radius: 100px;
    transition: width .5s ease;
}
.bar-count {
    min-width: 36px;
    text-align: right;
    font-size: 13px;
    font-weight: 700;
    color: var(--text, #111);
}
.bar-pct {
    min-width: 40px;
    text-align: right;
    font-size: 12px;
    color: var(--text-3, #6b7280);
}
.stage-pill {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 6px 14px;
    border-radius: 30px;
    font-size: 13px;
    font-weight: 600;
    margin: 4px;
}
</style>

<!-- Page header -->
<div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
    <div>
        <h5 class="mb-1 fw-bold">
            <i class="fa fa-chart-bar me-2 text-primary"></i><?= e($pageTitle) ?>
        </h5>
        <div class="text-muted small">Performance analytics &amp; pipeline intelligence</div>
    </div>
    <a href="my_dashboard.php" class="btn btn-sm btn-outline-secondary">
        <i class="fa fa-arrow-left me-1"></i>Back to Dashboard
    </a>
</div>

<!-- Period filter -->
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
            <div class="btn-group btn-group-sm" role="group">
                <?php foreach ($periods as $pk => $pl): ?>
                <a href="?period=<?= $pk ?>"
                   class="btn <?= $period === $pk ? 'btn-primary' : 'btn-outline-secondary' ?>">
                    <?= e($pl) ?>
                </a>
                <?php endforeach; ?>
            </div>
            <span class="ms-auto small text-muted">
                <i class="fa fa-calendar-days me-1"></i>
                <?= date('d M Y', strtotime($dateFrom)) ?> &ndash; <?= date('d M Y', strtotime($dateTo)) ?>
            </span>
        </div>
    </div>
</div>

<!-- ── KPI Summary Cards ──────────────────────────────────────── -->
<div class="row g-3 mb-4">
    <div class="col-6 col-lg-3">
        <div class="rpt-kpi" style="border-top-color:#2563eb">
            <div class="rpt-kpi-icon" style="background:#dbeafe;color:#2563eb">
                <i class="fa fa-user-plus"></i>
            </div>
            <div>
                <div class="rpt-kpi-label">Leads Added</div>
                <div class="rpt-kpi-value"><?= $kpiAdded ?></div>
                <div class="rpt-kpi-sub"><?= e($periodLabel) ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="rpt-kpi" style="border-top-color:#16a34a">
            <div class="rpt-kpi-icon" style="background:#dcfce7;color:#16a34a">
                <i class="fa fa-trophy"></i>
            </div>
            <div>
                <div class="rpt-kpi-label">Delivered / Won</div>
                <div class="rpt-kpi-value"><?= $kpiDelivered ?></div>
                <div class="rpt-kpi-sub"><?= e($periodLabel) ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="rpt-kpi" style="border-top-color:#dc2626">
            <div class="rpt-kpi-icon" style="background:#fee2e2;color:#dc2626">
                <i class="fa fa-circle-xmark"></i>
            </div>
            <div>
                <div class="rpt-kpi-label">Leads Lost</div>
                <div class="rpt-kpi-value"><?= $kpiLost ?></div>
                <div class="rpt-kpi-sub"><?= e($periodLabel) ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="rpt-kpi" style="border-top-color:#9333ea">
            <div class="rpt-kpi-icon" style="background:#f3e8ff;color:#9333ea">
                <i class="fa fa-chart-line"></i>
            </div>
            <div>
                <div class="rpt-kpi-label">Conversion Rate</div>
                <div class="rpt-kpi-value"><?= $convRate ?>%</div>
                <div class="rpt-kpi-sub"><?= $deliveredAllTime ?> of <?= $totalAllTime ?> all-time</div>
            </div>
        </div>
    </div>
</div>

<!-- ── Monthly Performance Table + Trend Chart ───────────────── -->
<div class="row g-4 mb-4">

    <div class="col-lg-7">
        <div class="card h-100">
            <div class="card-header fw-semibold">
                <i class="fa fa-table me-2 text-primary"></i>Monthly Performance <span class="text-muted fw-normal small">(last 6 months)</span>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0" style="font-size:13.5px">
                        <thead class="table-light">
                            <tr>
                                <th class="ps-3">Month</th>
                                <th class="text-center">Added</th>
                                <th class="text-center">Delivered</th>
                                <th class="text-center">Lost</th>
                                <th class="text-center">Active</th>
                                <th class="text-center">Conv %</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($monthlyPerf as $mp): ?>
                        <tr>
                            <td class="ps-3 fw-semibold"><?= e($mp['ymLabel']) ?></td>
                            <td class="text-center">
                                <span class="badge bg-primary bg-opacity-10 text-primary fw-semibold"><?= $mp['added'] ?></span>
                            </td>
                            <td class="text-center">
                                <?php if ($mp['delivered'] > 0): ?>
                                <span class="badge bg-success bg-opacity-10 text-success fw-semibold"><?= $mp['delivered'] ?></span>
                                <?php else: ?>
                                <span class="text-muted">0</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-center">
                                <?php if ($mp['lost'] > 0): ?>
                                <span class="badge bg-danger bg-opacity-10 text-danger fw-semibold"><?= $mp['lost'] ?></span>
                                <?php else: ?>
                                <span class="text-muted">0</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-center text-muted"><?= $mp['active'] ?></td>
                            <td class="text-center">
                                <span class="fw-bold <?= $mp['mConv'] >= 20 ? 'text-success' : ($mp['mConv'] >= 10 ? 'text-warning' : 'text-muted') ?>">
                                    <?= $mp['mConv'] ?>%
                                </span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <div class="col-lg-5">
        <div class="card h-100">
            <div class="card-header fw-semibold">
                <i class="fa fa-chart-line me-2 text-primary"></i>Lead Trend <span class="text-muted fw-normal small">(6 months)</span>
            </div>
            <div class="card-body" style="height:260px;position:relative">
                <canvas id="trendChart"></canvas>
            </div>
        </div>
    </div>

</div>

<!-- ── Activity Breakdown + Lead Source Analysis ─────────────── -->
<div class="row g-4 mb-4">

    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header fw-semibold">
                <i class="fa fa-list-check me-2 text-primary"></i>Activity Breakdown
                <span class="badge bg-secondary ms-1 fw-normal" style="font-size:11px"><?= e($periodLabel) ?></span>
            </div>
            <div class="card-body">
                <?php if (empty($actMap)): ?>
                <div class="text-center text-muted py-4">
                    <i class="fa fa-inbox fa-2x d-block mb-2 opacity-25"></i>
                    No activity logged in this period.
                </div>
                <?php else: ?>
                <?php
                $actColors = [
                    'call'       => '#16a34a',
                    'whatsapp'   => '#25d366',
                    'email'      => '#2563eb',
                    'visit'      => '#d97706',
                    'test_drive' => '#9333ea',
                    'meeting'    => '#0891b2',
                    'note'       => '#64748b',
                ];
                $actIcons = [
                    'call'       => 'fa-phone',
                    'whatsapp'   => 'fa-whatsapp',
                    'email'      => 'fa-envelope',
                    'visit'      => 'fa-location-dot',
                    'test_drive' => 'fa-car-side',
                    'meeting'    => 'fa-users',
                    'note'       => 'fa-note-sticky',
                ];
                foreach ($activityTypes as $aKey => $aLabel):
                    $cnt   = $actMap[$aKey] ?? 0;
                    $pct   = $actMax > 0 ? round($cnt / $actMax * 100) : 0;
                    $color = $actColors[$aKey] ?? '#94a3b8';
                    $icon  = $actIcons[$aKey] ?? 'fa-circle';
                ?>
                <div class="bar-row">
                    <div class="bar-label">
                        <i class="fa <?= $icon ?> me-1" style="color:<?= $color ?>;width:14px"></i>
                        <?= e($aLabel) ?>
                    </div>
                    <div class="bar-track">
                        <div class="bar-fill" style="width:<?= $pct ?>%;background:<?= $color ?>"></div>
                    </div>
                    <div class="bar-count"><?= $cnt ?></div>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header fw-semibold">
                <i class="fa fa-signs-post me-2 text-primary"></i>Lead Source Analysis
                <span class="badge bg-secondary ms-1 fw-normal" style="font-size:11px"><?= e($periodLabel) ?></span>
            </div>
            <div class="card-body">
                <?php if (empty($sourceMap)): ?>
                <div class="text-center text-muted py-4">
                    <i class="fa fa-inbox fa-2x d-block mb-2 opacity-25"></i>
                    No leads in this period.
                </div>
                <?php else: ?>
                <?php
                $sourceColors = [
                    'walk_in'    => '#2563eb',
                    'referral'   => '#16a34a',
                    'facebook'   => '#1877f2',
                    'instagram'  => '#e1306c',
                    'website'    => '#0891b2',
                    'phone_call' => '#d97706',
                    'whatsapp'   => '#25d366',
                    'other'      => '#94a3b8',
                ];
                $srcMax = $sourceMap ? max($sourceMap) : 1;
                foreach ($sources as $sKey => $sLabel):
                    $cnt = $sourceMap[$sKey] ?? 0;
                    if ($cnt === 0) continue;
                    $pct   = $srcMax > 0 ? round($cnt / $srcMax * 100) : 0;
                    $share = $sourceTotal > 0 ? round($cnt / $sourceTotal * 100, 1) : 0;
                    $color = $sourceColors[$sKey] ?? '#94a3b8';
                ?>
                <div class="bar-row">
                    <div class="bar-label" style="min-width:100px"><?= e($sLabel) ?></div>
                    <div class="bar-track">
                        <div class="bar-fill" style="width:<?= $pct ?>%;background:<?= $color ?>"></div>
                    </div>
                    <div class="bar-count"><?= $cnt ?></div>
                    <div class="bar-pct"><?= $share ?>%</div>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

</div>

<!-- ── Stage Distribution + Doughnut Chart ───────────────────── -->
<div class="row g-4 mb-4">

    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header fw-semibold">
                <i class="fa fa-layer-group me-2 text-primary"></i>Stage Distribution
                <span class="text-muted fw-normal small ms-1">(active leads)</span>
            </div>
            <div class="card-body">
                <?php if (empty($stageDistMap)): ?>
                <div class="text-center text-muted py-4">
                    <i class="fa fa-inbox fa-2x d-block mb-2 opacity-25"></i>
                    No active leads in pipeline.
                </div>
                <?php else: ?>
                <!-- Pill badges -->
                <div class="d-flex flex-wrap mb-3">
                <?php
                $stageBadgeColors = [
                    'hot'       => ['bg' => '#fee2e2', 'color' => '#dc2626'],
                    'lukewarm'  => ['bg' => '#fef3c7', 'color' => '#92400e'],
                    'cold'      => ['bg' => '#cffafe', 'color' => '#0e7490'],
                    'reserved'  => ['bg' => '#ede9fe', 'color' => '#5b21b6'],
                    'lost'      => ['bg' => '#f3f4f6', 'color' => '#6b7280'],
                    'delivered' => ['bg' => '#dcfce7', 'color' => '#15803d'],
                ];
                foreach ($stageDistMap as $sk => $sv):
                    $cfg = $stageBadgeColors[$sk] ?? ['bg' => '#f1f5f9', 'color' => '#374151'];
                ?>
                <span class="stage-pill" style="background:<?= $cfg['bg'] ?>;color:<?= $cfg['color'] ?>">
                    <?= e($stages[$sk]['label'] ?? ucfirst($sk)) ?>
                    <span style="background:<?= $cfg['color'] ?>;color:#fff;border-radius:30px;padding:1px 9px;font-size:12px;font-weight:800">
                        <?= $sv ?>
                    </span>
                </span>
                <?php endforeach; ?>
                </div>

                <!-- Horizontal bar breakdown -->
                <?php
                $distTotal = array_sum($stageDistMap);
                $distMax   = $distTotal > 0 ? max($stageDistMap) : 1;
                $stageChartColorsLocal = [
                    'hot'      => '#dc2626',
                    'lukewarm' => '#d97706',
                    'cold'     => '#0891b2',
                    'reserved' => '#7c3aed',
                    'lost'     => '#94a3b8',
                    'delivered'=> '#16a34a',
                ];
                foreach ($stageDistMap as $sk => $sv):
                    $pct   = $distMax > 0 ? round($sv / $distMax * 100) : 0;
                    $share = $distTotal > 0 ? round($sv / $distTotal * 100, 1) : 0;
                    $color = $stageChartColorsLocal[$sk] ?? '#94a3b8';
                ?>
                <div class="bar-row">
                    <div class="bar-label"><?= e($stages[$sk]['label'] ?? ucfirst($sk)) ?></div>
                    <div class="bar-track">
                        <div class="bar-fill" style="width:<?= $pct ?>%;background:<?= $color ?>"></div>
                    </div>
                    <div class="bar-count"><?= $sv ?></div>
                    <div class="bar-pct"><?= $share ?>%</div>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header fw-semibold">
                <i class="fa fa-chart-pie me-2 text-primary"></i>Stage Distribution Chart
            </div>
            <div class="card-body d-flex align-items-center justify-content-center" style="min-height:260px;position:relative">
                <?php if (empty($stageDistMap)): ?>
                <div class="text-center text-muted">
                    <i class="fa fa-chart-pie fa-3x d-block mb-3 opacity-25"></i>
                    No active leads to display.
                </div>
                <?php else: ?>
                <canvas id="stageChart" style="max-height:260px"></canvas>
                <?php endif; ?>
            </div>
        </div>
    </div>

</div>

<!-- ── Follow-up Compliance + Lost Reason Analysis ───────────── -->
<div class="row g-4 mb-4">

    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header fw-semibold">
                <i class="fa fa-calendar-check me-2 text-primary"></i>Follow-up Compliance
                <span class="text-muted fw-normal small ms-1">(active leads)</span>
            </div>
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <span class="fw-semibold" style="font-size:14.5px">
                        <?= $withFollowUp ?> of <?= $activeTotal ?> leads scheduled
                    </span>
                    <span class="fw-bold fs-5 <?= $followUpPct >= 80 ? 'text-success' : ($followUpPct >= 50 ? 'text-warning' : 'text-danger') ?>">
                        <?= $followUpPct ?>%
                    </span>
                </div>
                <div class="progress mb-3" style="height:14px;border-radius:100px">
                    <div class="progress-bar <?= $followUpPct >= 80 ? 'bg-success' : ($followUpPct >= 50 ? 'bg-warning' : 'bg-danger') ?>"
                         role="progressbar"
                         style="width:<?= $followUpPct ?>%;border-radius:100px"
                         aria-valuenow="<?= $followUpPct ?>"
                         aria-valuemin="0"
                         aria-valuemax="100">
                    </div>
                </div>
                <div class="small text-muted mb-3">
                    <?php if ($followUpPct >= 80): ?>
                    <i class="fa fa-circle-check text-success me-1"></i>Excellent — most active leads have a follow-up scheduled.
                    <?php elseif ($followUpPct >= 50): ?>
                    <i class="fa fa-circle-exclamation text-warning me-1"></i>Fair — consider scheduling follow-ups on remaining leads.
                    <?php else: ?>
                    <i class="fa fa-triangle-exclamation text-danger me-1"></i>Low compliance — leads without a follow-up date risk being forgotten.
                    <?php endif; ?>
                </div>
                <div class="row g-3">
                    <div class="col-6">
                        <div class="rounded-3 p-3 text-center" style="background:#f0fdf4;border:1px solid #bbf7d0">
                            <div class="fw-bold fs-4 text-success"><?= $withFollowUp ?></div>
                            <div class="small text-success fw-semibold">Scheduled</div>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="rounded-3 p-3 text-center" style="background:#fef2f2;border:1px solid #fecaca">
                            <div class="fw-bold fs-4 text-danger"><?= max(0, $activeTotal - $withFollowUp) ?></div>
                            <div class="small text-danger fw-semibold">Not Scheduled</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header fw-semibold">
                <i class="fa fa-circle-xmark me-2 text-danger"></i>Lost Reason Analysis
                <span class="text-muted fw-normal small ms-1">(top 5, all-time)</span>
            </div>
            <div class="card-body">
                <?php if (empty($lostReasonRows)): ?>
                <div class="text-center text-muted py-4">
                    <i class="fa fa-circle-check fa-2x d-block mb-2 text-success opacity-50"></i>
                    No lost reason data available yet.
                </div>
                <?php else:
                    $lostMax = max(array_column($lostReasonRows, 'cnt'));
                ?>
                <div class="d-flex flex-column gap-3">
                <?php foreach ($lostReasonRows as $idx => $lr): ?>
                <div>
                    <div class="d-flex justify-content-between align-items-center mb-1">
                        <div class="d-flex align-items-center gap-2">
                            <span class="fw-bold text-muted" style="font-size:11px;min-width:18px">#<?= $idx + 1 ?></span>
                            <span class="fw-semibold" style="font-size:13.5px"><?= e($lr['lost_reason']) ?></span>
                        </div>
                        <span class="badge bg-secondary"><?= (int)$lr['cnt'] ?></span>
                    </div>
                    <div class="bar-track">
                        <div class="bar-fill"
                             style="width:<?= $lostMax > 0 ? round((int)$lr['cnt'] / $lostMax * 100) : 0 ?>%;background:#dc2626">
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

</div>

<div class="mb-4"></div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>

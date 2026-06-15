<?php
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
canAccess('reports') || die('Access denied.');
$pageTitle = 'CRM Report';
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

// ── Stage config ──────────────────────────────────────────────────────────────
$stageConfig = [
    'new'          => ['New Lead',    '#64748b'],
    'contacted'    => ['Contacted',   '#2563eb'],
    'interested'   => ['Interested',  '#0891b2'],
    'test_drive'   => ['Test Drive',  '#7c3aed'],
    'negotiation'  => ['Negotiation', '#d97706'],
    'closed_won'   => ['Closed Won',  '#16a34a'],
    'closed_lost'  => ['Lost',        '#dc2626'],
];

$sourceLabels = [
    'walk_in'    => 'Walk-in',    'referral'   => 'Referral',
    'facebook'   => 'Facebook',   'instagram'  => 'Instagram',
    'website'    => 'Website',    'phone_call' => 'Phone Call',
    'whatsapp'   => 'WhatsApp',   'other'      => 'Other',
];

// ── Queries ───────────────────────────────────────────────────────────────────
$kpi = ['total' => 0, 'won' => 0, 'lost' => 0, 'active' => 0, 'win_rate' => 0, 'avg_days' => 0, 'pipeline_value' => 0];
$stageBreakdown = [];
$sourceBreakdown = [];
$repStats = [];
$lostReasons = [];
$monthlyTrend = [];

try {
    // Overall KPIs — created in period
    $k = $db->prepare("
        SELECT
            COUNT(*)                                              AS total,
            SUM(stage = 'closed_won')                            AS won,
            SUM(stage = 'closed_lost')                           AS lost,
            SUM(stage NOT IN ('closed_won','closed_lost'))        AS active,
            COALESCE(AVG(CASE WHEN stage='closed_won' AND converted_at IS NOT NULL
                THEN DATEDIFF(converted_at, created_at) END), 0) AS avg_days,
            COALESCE(SUM(CASE WHEN stage NOT IN ('closed_won','closed_lost')
                THEN budget END), 0)                             AS pipeline_value
        FROM crm_leads
        WHERE DATE(created_at) BETWEEN ? AND ?
    ");
    $k->execute([$dateFrom, $dateTo]); $kpi = $k->fetch();
    $closed = (int)$kpi['won'] + (int)$kpi['lost'];
    $kpi['win_rate'] = $closed > 0 ? round((int)$kpi['won'] / $closed * 100, 1) : 0;

    // Stage breakdown — all time (pipeline snapshot)
    $sb = $db->query("
        SELECT stage, COUNT(*) AS cnt, COALESCE(SUM(budget),0) AS total_budget
        FROM crm_leads GROUP BY stage ORDER BY FIELD(stage,'new','contacted','interested','test_drive','negotiation','closed_won','closed_lost')
    ")->fetchAll();
    foreach ($sb as $r) $stageBreakdown[$r['stage']] = $r;

    // Source breakdown — created in period
    $sbd = $db->prepare("
        SELECT source, COUNT(*) AS total,
               SUM(stage='closed_won') AS won
        FROM crm_leads WHERE DATE(created_at) BETWEEN ? AND ?
        GROUP BY source ORDER BY total DESC
    ");
    $sbd->execute([$dateFrom, $dateTo]); $sourceBreakdown = $sbd->fetchAll();

    // Rep performance — leads assigned, created in period
    $rep = $db->prepare("
        SELECT u.name,
               COUNT(l.id)                    AS total,
               SUM(l.stage='closed_won')      AS won,
               SUM(l.stage='closed_lost')     AS lost,
               SUM(l.stage NOT IN ('closed_won','closed_lost')) AS active,
               COALESCE(SUM(CASE WHEN l.stage NOT IN ('closed_won','closed_lost') THEN l.budget END),0) AS pipeline_value,
               COALESCE(AVG(CASE WHEN l.stage='closed_won' AND l.converted_at IS NOT NULL
                   THEN DATEDIFF(l.converted_at, l.created_at) END), 0) AS avg_days_to_close
        FROM crm_leads l JOIN users u ON u.id = l.assigned_to
        WHERE DATE(l.created_at) BETWEEN ? AND ?
        GROUP BY u.id, u.name ORDER BY won DESC, total DESC
    ");
    $rep->execute([$dateFrom, $dateTo]); $repStats = $rep->fetchAll();

    // Top lost reasons
    $lr = $db->prepare("
        SELECT lost_reason, COUNT(*) AS cnt
        FROM crm_leads
        WHERE stage='closed_lost' AND lost_reason IS NOT NULL AND lost_reason != ''
          AND DATE(created_at) BETWEEN ? AND ?
        GROUP BY lost_reason ORDER BY cnt DESC LIMIT 8
    ");
    $lr->execute([$dateFrom, $dateTo]); $lostReasons = $lr->fetchAll();

    // Monthly trend — leads created last 6 months
    $mt = $db->query("
        SELECT DATE_FORMAT(created_at,'%b %Y') AS label,
               DATE_FORMAT(created_at,'%Y-%m') AS key,
               COUNT(*) AS total,
               SUM(stage='closed_won') AS won
        FROM crm_leads
        WHERE created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
        GROUP BY `key`, label ORDER BY `key` ASC
    ")->fetchAll();
    foreach ($mt as $r) $monthlyTrend[$r['label']] = $r;

} catch (\Throwable $e) {
    // tables may not exist yet — silently degrade
}

include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/_nav.php';
?>

<!-- ── KPI Cards ─────────────────────────────────────────────────────────── -->
<div class="row g-3 mb-4">
    <div class="col-6 col-lg-3">
        <div class="card border-0 shadow-sm h-100" style="border-radius:12px">
            <div class="card-body d-flex align-items-center gap-3 p-3">
                <div class="flex-shrink-0 d-flex align-items-center justify-content-center rounded-3"
                     style="width:46px;height:46px;background:#eff6ff">
                    <i class="fa fa-user-group text-primary"></i>
                </div>
                <div>
                    <div class="text-muted" style="font-size:11.5px;text-transform:uppercase;letter-spacing:.04em;font-weight:600">Leads Created</div>
                    <div class="fw-bold" style="font-size:22px;line-height:1.2"><?= number_format($kpi['total']) ?></div>
                    <div class="text-muted" style="font-size:11px"><?= (int)$kpi['active'] ?> still active</div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card border-0 shadow-sm h-100" style="border-radius:12px">
            <div class="card-body d-flex align-items-center gap-3 p-3">
                <div class="flex-shrink-0 d-flex align-items-center justify-content-center rounded-3"
                     style="width:46px;height:46px;background:#f0fdf4">
                    <i class="fa fa-circle-check text-success"></i>
                </div>
                <div>
                    <div class="text-muted" style="font-size:11.5px;text-transform:uppercase;letter-spacing:.04em;font-weight:600">Won</div>
                    <div class="fw-bold text-success" style="font-size:22px;line-height:1.2"><?= number_format($kpi['won']) ?></div>
                    <div class="text-muted" style="font-size:11px"><?= $kpi['win_rate'] ?>% close rate</div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card border-0 shadow-sm h-100" style="border-radius:12px">
            <div class="card-body d-flex align-items-center gap-3 p-3">
                <div class="flex-shrink-0 d-flex align-items-center justify-content-center rounded-3"
                     style="width:46px;height:46px;background:#fefce8">
                    <i class="fa fa-chart-line text-warning"></i>
                </div>
                <div>
                    <div class="text-muted" style="font-size:11.5px;text-transform:uppercase;letter-spacing:.04em;font-weight:600">Pipeline Value</div>
                    <div class="fw-bold" style="font-size:18px;line-height:1.2"><?= money((float)$kpi['pipeline_value']) ?></div>
                    <div class="text-muted" style="font-size:11px">Active leads budget</div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="card border-0 shadow-sm h-100" style="border-radius:12px">
            <div class="card-body d-flex align-items-center gap-3 p-3">
                <div class="flex-shrink-0 d-flex align-items-center justify-content-center rounded-3"
                     style="width:46px;height:46px;background:#faf5ff">
                    <i class="fa fa-hourglass-half" style="color:#9333ea"></i>
                </div>
                <div>
                    <div class="text-muted" style="font-size:11.5px;text-transform:uppercase;letter-spacing:.04em;font-weight:600">Avg Days to Close</div>
                    <div class="fw-bold" style="font-size:22px;line-height:1.2"><?= round((float)$kpi['avg_days']) ?></div>
                    <div class="text-muted" style="font-size:11px">Among won leads</div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ── Charts Row ──────────────────────────────────────────────────────────── -->
<div class="row g-4 mb-4">

    <!-- Funnel / Stage snapshot -->
    <div class="col-lg-5">
        <div class="card border-0 shadow-sm h-100" style="border-radius:12px">
            <div class="card-body p-4">
                <h6 class="fw-bold mb-0">Pipeline Snapshot</h6>
                <div class="text-muted small mb-3">Lead count by stage (all time)</div>
                <?php foreach ($stageConfig as $sk => [$sl, $sc]): ?>
                <?php
                    $cnt   = (int)($stageBreakdown[$sk]['cnt'] ?? 0);
                    $maxCnt = max(1, max(array_map(fn($r) => (int)($r['cnt'] ?? 0), $stageBreakdown ?: [[]])));
                    $w     = $maxCnt > 0 ? round($cnt / $maxCnt * 100) : 0;
                ?>
                <div class="mb-2">
                    <div class="d-flex justify-content-between mb-1" style="font-size:12.5px">
                        <span style="color:<?= $sc ?>;font-weight:600"><?= $sl ?></span>
                        <span class="fw-bold"><?= $cnt ?></span>
                    </div>
                    <div class="progress" style="height:6px;border-radius:3px;background:#f1f5f9">
                        <div class="progress-bar" style="width:<?= $w ?>%;background:<?= $sc ?>;border-radius:3px"></div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- Monthly trend line chart -->
    <div class="col-lg-7">
        <div class="card border-0 shadow-sm h-100" style="border-radius:12px">
            <div class="card-body p-4">
                <h6 class="fw-bold mb-0">Monthly Trend</h6>
                <div class="text-muted small mb-3">Leads created vs closed won — last 6 months</div>
                <canvas id="trendChart" height="190"></canvas>
            </div>
        </div>
    </div>

</div>

<div class="row g-4 mb-4">

    <!-- Source breakdown -->
    <div class="col-lg-5">
        <div class="card border-0 shadow-sm h-100" style="border-radius:12px">
            <div class="card-body p-4">
                <h6 class="fw-bold mb-0">Lead Sources</h6>
                <div class="text-muted small mb-3">Where leads came from (this period)</div>
                <?php if (empty($sourceBreakdown)): ?>
                <div class="text-muted small text-center py-4">No leads in this period</div>
                <?php else: ?>
                <?php
                $totalLeads = array_sum(array_column($sourceBreakdown, 'total')) ?: 1;
                $srcColors  = ['#2563eb','#16a34a','#d97706','#dc2626','#9333ea','#0891b2','#f97316','#64748b'];
                $ci = 0;
                ?>
                <canvas id="sourceChart" height="200" class="mb-3"></canvas>
                <div class="row g-2" style="font-size:11.5px">
                <?php foreach ($sourceBreakdown as $src):
                    $pct = round($src['total'] / $totalLeads * 100); ?>
                <div class="col-6 d-flex align-items-center gap-2">
                    <span class="rounded-1 flex-shrink-0" style="width:10px;height:10px;background:<?= $srcColors[$ci % count($srcColors)] ?>"></span>
                    <span class="text-muted"><?= e($sourceLabels[$src['source']] ?? $src['source']) ?></span>
                    <span class="fw-semibold ms-auto"><?= $src['total'] ?></span>
                    <span class="text-muted">(<?= $pct ?>%)</span>
                </div>
                <?php $ci++; endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Lost reasons -->
    <div class="col-lg-7">
        <div class="card border-0 shadow-sm h-100" style="border-radius:12px">
            <div class="card-body p-4">
                <h6 class="fw-bold mb-0">Top Lost Reasons</h6>
                <div class="text-muted small mb-3">Why leads were marked as lost (this period)</div>
                <?php if (empty($lostReasons)): ?>
                <div class="text-center text-muted py-4">
                    <i class="fa fa-face-smile fa-2x mb-2 d-block opacity-25"></i>
                    No lost reasons recorded for this period
                </div>
                <?php else: ?>
                <?php $maxLr = max(array_column($lostReasons, 'cnt')) ?: 1; ?>
                <?php foreach ($lostReasons as $lr): ?>
                <div class="mb-3">
                    <div class="d-flex justify-content-between mb-1" style="font-size:12.5px">
                        <span class="fw-medium"><?= e($lr['lost_reason']) ?></span>
                        <span class="badge bg-danger-subtle text-danger border border-danger-subtle"><?= $lr['cnt'] ?></span>
                    </div>
                    <div class="progress" style="height:5px;border-radius:3px">
                        <div class="progress-bar bg-danger" style="width:<?= round($lr['cnt']/$maxLr*100) ?>%"></div>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

</div>

<!-- ── Sales Rep Performance Table ───────────────────────────────────────── -->
<?php if (!empty($repStats)): ?>
<div class="card border-0 shadow-sm mb-4" style="border-radius:12px">
    <div class="card-body p-0">
        <div class="p-4 pb-3 border-bottom">
            <h6 class="fw-bold mb-0"><i class="fa fa-trophy me-2 text-warning"></i>Sales Rep Performance</h6>
            <div class="text-muted small">Leads assigned in period — sorted by wins</div>
        </div>
        <div class="table-responsive">
            <table class="table table-hover mb-0" style="font-size:13px">
                <thead style="font-size:11.5px;color:#64748b;text-transform:uppercase;letter-spacing:.05em;background:#f8fafc">
                    <tr>
                        <th class="ps-4 py-3">Sales Person</th>
                        <th class="py-3 text-center">Total Leads</th>
                        <th class="py-3 text-center">Active</th>
                        <th class="py-3 text-center">Won</th>
                        <th class="py-3 text-center">Lost</th>
                        <th class="py-3 text-center">Close Rate</th>
                        <th class="py-3 text-end">Pipeline</th>
                        <th class="py-3 text-end pe-4">Avg Days</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($repStats as $i => $rep):
                    $repClosed  = (int)$rep['won'] + (int)$rep['lost'];
                    $repWinRate = $repClosed > 0 ? round((int)$rep['won'] / $repClosed * 100) : 0;
                    $medal = match($i) { 0 => '🥇', 1 => '🥈', 2 => '🥉', default => '' };
                ?>
                <tr>
                    <td class="ps-4 py-3 fw-semibold">
                        <?= $medal ? '<span class="me-1">'.$medal.'</span>' : '' ?>
                        <?= e($rep['name']) ?>
                    </td>
                    <td class="py-3 text-center"><?= number_format($rep['total']) ?></td>
                    <td class="py-3 text-center">
                        <span class="badge bg-primary-subtle text-primary border border-primary-subtle"><?= $rep['active'] ?></span>
                    </td>
                    <td class="py-3 text-center">
                        <span class="badge bg-success-subtle text-success border border-success-subtle"><?= $rep['won'] ?></span>
                    </td>
                    <td class="py-3 text-center">
                        <span class="badge bg-danger-subtle text-danger border border-danger-subtle"><?= $rep['lost'] ?></span>
                    </td>
                    <td class="py-3 text-center">
                        <span class="badge bg-<?= $repWinRate >= 50 ? 'success' : ($repWinRate >= 25 ? 'warning' : 'secondary') ?>">
                            <?= $repWinRate ?>%
                        </span>
                    </td>
                    <td class="py-3 text-end small"><?= money((float)$rep['pipeline_value']) ?></td>
                    <td class="py-3 text-end pe-4 small text-muted"><?= round((float)$rep['avg_days_to_close']) ?>d</td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ── Charts JS ─────────────────────────────────────────────────────────── -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
(function () {
    const isDark    = document.documentElement.getAttribute('data-theme') === 'dark';
    const grid      = isDark ? 'rgba(255,255,255,.07)' : 'rgba(0,0,0,.06)';
    const labelClr  = isDark ? '#94a3b8' : '#64748b';

    // Monthly trend
    const trendData = <?= json_encode(array_values($monthlyTrend)) ?>;
    if (document.getElementById('trendChart') && trendData.length) {
        new Chart(document.getElementById('trendChart'), {
            type: 'line',
            data: {
                labels: trendData.map(d => d.label),
                datasets: [
                    {
                        label: 'Leads Created',
                        data: trendData.map(d => parseInt(d.total)),
                        borderColor: '#2563eb', backgroundColor: 'rgba(37,99,235,.1)',
                        borderWidth: 2, fill: true, tension: 0.4, pointRadius: 4,
                    },
                    {
                        label: 'Closed Won',
                        data: trendData.map(d => parseInt(d.won)),
                        borderColor: '#16a34a', backgroundColor: 'rgba(22,163,74,.08)',
                        borderWidth: 2, fill: true, tension: 0.4, pointRadius: 4,
                    },
                ]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: { legend: { position: 'top', labels: { color: labelClr, font: { size: 11 } } } },
                scales: {
                    x: { ticks: { color: labelClr, font: { size: 11 } }, grid: { color: grid } },
                    y: { ticks: { color: labelClr, font: { size: 11 }, stepSize: 1 }, grid: { color: grid }, beginAtZero: true }
                }
            }
        });
    }

    // Source donut
    const srcData = <?= json_encode(array_values($sourceBreakdown)) ?>;
    const srcLabels = <?= json_encode($sourceLabels) ?>;
    const srcColors = ['#2563eb','#16a34a','#d97706','#dc2626','#9333ea','#0891b2','#f97316','#64748b'];
    if (document.getElementById('sourceChart') && srcData.length) {
        new Chart(document.getElementById('sourceChart'), {
            type: 'doughnut',
            data: {
                labels: srcData.map(d => srcLabels[d.source] || d.source),
                datasets: [{
                    data: srcData.map(d => parseInt(d.total)),
                    backgroundColor: srcColors.slice(0, srcData.length),
                    borderWidth: 2,
                    borderColor: isDark ? '#1e293b' : '#fff',
                }]
            },
            options: {
                responsive: true, maintainAspectRatio: false, cutout: '65%',
                plugins: {
                    legend: { display: false },
                    tooltip: { callbacks: { label: ctx => ctx.label + ': ' + ctx.parsed } }
                }
            }
        });
    }
}());
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>

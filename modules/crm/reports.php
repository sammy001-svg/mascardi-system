<?php
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
canAccess('crm') || redirect(BASE_URL . '/index.php');

$db  = getDB();
$me  = authUser();
$uid = (int)$me['id'];

$isCrmAgent = ($me['role'] === 'customer_relations');
$isAdmin    = in_array($me['role'], ['admin', 'super_admin']);
$pageTitle  = $isCrmAgent ? 'My Performance Report' : 'CRM Analytics Report';

// ── Date range ───────────────────────────────────────────────────────────────
$period      = $_GET['period'] ?? 'this_month';
$customFrom  = $_GET['date_from'] ?? '';
$customTo    = $_GET['date_to']   ?? '';
$validPeriods = ['this_month','last_month','last_3_months','this_year','last_30_days','custom'];
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
        $periodLabel = 'This Year (' . date('Y') . ')';
        break;
    case 'last_30_days':
        $dateFrom    = date('Y-m-d', strtotime('-29 days'));
        $dateTo      = date('Y-m-d');
        $periodLabel = 'Last 30 Days';
        break;
    case 'custom':
        $dateFrom    = preg_match('/^\d{4}-\d{2}-\d{2}$/', $customFrom) ? $customFrom : date('Y-m-01');
        $dateTo      = preg_match('/^\d{4}-\d{2}-\d{2}$/', $customTo)   ? $customTo   : date('Y-m-d');
        if ($dateTo < $dateFrom) $dateTo = $dateFrom;
        $periodLabel = date('d M Y', strtotime($dateFrom)) . ' – ' . date('d M Y', strtotime($dateTo));
        break;
    default:
        $dateFrom    = date('Y-m-01');
        $dateTo      = date('Y-m-t');
        $periodLabel = 'This Month (' . date('M Y') . ')';
        break;
}

// Previous period of same length (for delta)
$periodDays   = (int)ceil((strtotime($dateTo) - strtotime($dateFrom)) / 86400) + 1;
$prevDateTo   = date('Y-m-d', strtotime($dateFrom) - 86400);
$prevDateFrom = date('Y-m-d', strtotime($prevDateTo) - ($periodDays - 1) * 86400);

// Ownership filters
$ownerWhere = $isCrmAgent ? "AND assigned_to = {$uid}" : '';
$ownerJoin  = $isCrmAgent ? "AND l.assigned_to = {$uid}" : '';

// Reference maps
$stages = [
    'hot'       => ['label' => 'Hot',       'color' => '#dc2626', 'bg' => '#fee2e2'],
    'lukewarm'  => ['label' => 'Lukewarm',  'color' => '#d97706', 'bg' => '#fef3c7'],
    'cold'      => ['label' => 'Cold',      'color' => '#0891b2', 'bg' => '#cffafe'],
    'reserved'  => ['label' => 'Reserved',  'color' => '#7c3aed', 'bg' => '#ede9fe'],
    'lost'      => ['label' => 'Lost',      'color' => '#6b7280', 'bg' => '#f3f4f6'],
    'delivered' => ['label' => 'Delivered', 'color' => '#16a34a', 'bg' => '#dcfce7'],
];
$sources = [
    'walk_in'    => ['label' => 'Walk-in',    'color' => '#2563eb'],
    'referral'   => ['label' => 'Referral',   'color' => '#16a34a'],
    'facebook'   => ['label' => 'Facebook',   'color' => '#1877f2'],
    'instagram'  => ['label' => 'Instagram',  'color' => '#e1306c'],
    'website'    => ['label' => 'Website',    'color' => '#0891b2'],
    'phone_call' => ['label' => 'Phone Call', 'color' => '#d97706'],
    'whatsapp'   => ['label' => 'WhatsApp',   'color' => '#25d366'],
    'other'      => ['label' => 'Other',      'color' => '#94a3b8'],
];
$activityTypes = [
    'call'       => ['label' => 'Call',       'color' => '#16a34a', 'icon' => 'fa-phone'],
    'whatsapp'   => ['label' => 'WhatsApp',   'color' => '#25d366', 'icon' => 'fa-whatsapp'],
    'email'      => ['label' => 'Email',      'color' => '#2563eb', 'icon' => 'fa-envelope'],
    'visit'      => ['label' => 'Visit',      'color' => '#d97706', 'icon' => 'fa-location-dot'],
    'test_drive' => ['label' => 'Test Drive', 'color' => '#9333ea', 'icon' => 'fa-car-side'],
    'meeting'    => ['label' => 'Meeting',    'color' => '#0891b2', 'icon' => 'fa-users'],
    'note'       => ['label' => 'Note',       'color' => '#64748b', 'icon' => 'fa-note-sticky'],
];

// ── Queries ──────────────────────────────────────────────────────────────────
try {
    // ── KPIs: current period ──
    $kpiAdded     = (int)$db->query("SELECT COUNT(*) FROM crm_leads WHERE DATE(created_at) BETWEEN '{$dateFrom}' AND '{$dateTo}' {$ownerWhere}")->fetchColumn();
    $kpiDelivered = (int)$db->query("SELECT COUNT(*) FROM crm_leads WHERE stage='delivered' AND DATE(updated_at) BETWEEN '{$dateFrom}' AND '{$dateTo}' {$ownerWhere}")->fetchColumn();
    $kpiLost      = (int)$db->query("SELECT COUNT(*) FROM crm_leads WHERE stage='lost' AND DATE(updated_at) BETWEEN '{$dateFrom}' AND '{$dateTo}' {$ownerWhere}")->fetchColumn();
    $kpiActive    = (int)$db->query("SELECT COUNT(*) FROM crm_leads WHERE stage NOT IN ('lost','delivered') {$ownerWhere}")->fetchColumn();

    // ── KPIs: previous period (for delta) ──
    $prevAdded     = (int)$db->query("SELECT COUNT(*) FROM crm_leads WHERE DATE(created_at) BETWEEN '{$prevDateFrom}' AND '{$prevDateTo}' {$ownerWhere}")->fetchColumn();
    $prevDelivered = (int)$db->query("SELECT COUNT(*) FROM crm_leads WHERE stage='delivered' AND DATE(updated_at) BETWEEN '{$prevDateFrom}' AND '{$prevDateTo}' {$ownerWhere}")->fetchColumn();
    $prevLost      = (int)$db->query("SELECT COUNT(*) FROM crm_leads WHERE stage='lost' AND DATE(updated_at) BETWEEN '{$prevDateFrom}' AND '{$prevDateTo}' {$ownerWhere}")->fetchColumn();

    // ── All-time conversion ──
    $totalAllTime     = (int)$db->query("SELECT COUNT(*) FROM crm_leads WHERE 1 {$ownerWhere}")->fetchColumn();
    $deliveredAllTime = (int)$db->query("SELECT COUNT(*) FROM crm_leads WHERE stage='delivered' {$ownerWhere}")->fetchColumn();
    $convRate         = $totalAllTime > 0 ? round($deliveredAllTime / $totalAllTime * 100, 1) : 0;

    // ── Lead velocity: avg days from created to delivered ──
    $velRow = $db->query("
        SELECT ROUND(AVG(DATEDIFF(updated_at, created_at)), 1) AS avg_days
        FROM crm_leads WHERE stage = 'delivered' {$ownerWhere}
    ")->fetch();
    $avgDays = $velRow['avg_days'] ?? null;

    // ── Monthly performance — last 6 months ──
    $monthlyPerf = [];
    $trendLabels = $trendAdded = $trendDelivered = $trendLost = [];
    for ($i = 5; $i >= 0; $i--) {
        $ymStart = date('Y-m-01', strtotime("-{$i} months"));
        $ymEnd   = date('Y-m-t',  strtotime("-{$i} months"));
        $ymLabel = date('M Y',    strtotime($ymStart));
        $ma = (int)$db->query("SELECT COUNT(*) FROM crm_leads WHERE DATE(created_at) BETWEEN '{$ymStart}' AND '{$ymEnd}' {$ownerWhere}")->fetchColumn();
        $md = (int)$db->query("SELECT COUNT(*) FROM crm_leads WHERE stage='delivered' AND DATE(updated_at) BETWEEN '{$ymStart}' AND '{$ymEnd}' {$ownerWhere}")->fetchColumn();
        $ml = (int)$db->query("SELECT COUNT(*) FROM crm_leads WHERE stage='lost' AND DATE(updated_at) BETWEEN '{$ymStart}' AND '{$ymEnd}' {$ownerWhere}")->fetchColumn();
        $mAct = (int)$db->query("SELECT COUNT(*) FROM crm_leads WHERE stage NOT IN ('lost','delivered') AND DATE(created_at) <= '{$ymEnd}' {$ownerWhere}")->fetchColumn();
        $mConv = $ma > 0 ? round($md / $ma * 100, 1) : 0;
        $monthlyPerf[] = ['ymLabel'=>$ymLabel,'added'=>$ma,'delivered'=>$md,'lost'=>$ml,'active'=>$mAct,'mConv'=>$mConv];
        $trendLabels[]    = $ymLabel;
        $trendAdded[]     = $ma;
        $trendDelivered[] = $md;
        $trendLost[]      = $ml;
    }

    // ── Activity breakdown ──
    $actRows = $db->query("
        SELECT a.type, COUNT(*) cnt FROM crm_activities a
        " . ($isCrmAgent ? "WHERE a.created_by = {$uid} AND" : "WHERE") . "
        DATE(a.created_at) BETWEEN '{$dateFrom}' AND '{$dateTo}'
        GROUP BY a.type ORDER BY cnt DESC
    ")->fetchAll(PDO::FETCH_ASSOC);
    $actMap = []; foreach ($actRows as $r) $actMap[$r['type']] = (int)$r['cnt'];
    $actTotal = array_sum($actMap);

    // ── Lead source analysis ──
    $sourceRows = $db->query("
        SELECT source, COUNT(*) cnt FROM crm_leads
        WHERE DATE(created_at) BETWEEN '{$dateFrom}' AND '{$dateTo}' {$ownerWhere}
        GROUP BY source ORDER BY cnt DESC
    ")->fetchAll(PDO::FETCH_ASSOC);
    $sourceMap = []; $sourceTotal = 0;
    foreach ($sourceRows as $r) { $sourceMap[$r['source']] = (int)$r['cnt']; $sourceTotal += (int)$r['cnt']; }

    // ── Stage distribution ──
    $stageDistRows = $db->query("
        SELECT stage, COUNT(*) cnt FROM crm_leads
        WHERE stage NOT IN ('lost','delivered') {$ownerWhere}
        GROUP BY stage ORDER BY cnt DESC
    ")->fetchAll(PDO::FETCH_ASSOC);
    $stageDistMap = []; foreach ($stageDistRows as $r) $stageDistMap[$r['stage']] = (int)$r['cnt'];
    $stageTotal = array_sum($stageDistMap);

    // ── Follow-up compliance ──
    $activeTotal  = (int)$db->query("SELECT COUNT(*) FROM crm_leads WHERE stage NOT IN ('lost','delivered') {$ownerWhere}")->fetchColumn();
    $withFollowUp = (int)$db->query("SELECT COUNT(*) FROM crm_leads WHERE stage NOT IN ('lost','delivered') AND follow_up_date IS NOT NULL {$ownerWhere}")->fetchColumn();
    $followUpPct  = $activeTotal > 0 ? round($withFollowUp / $activeTotal * 100, 1) : 0;

    // ── Lost reason analysis ──
    $lostReasonRows = $db->query("
        SELECT lost_reason, COUNT(*) cnt FROM crm_leads
        WHERE stage='lost' AND lost_reason IS NOT NULL AND lost_reason != '' {$ownerWhere}
        GROUP BY lost_reason ORDER BY cnt DESC LIMIT 6
    ")->fetchAll(PDO::FETCH_ASSOC);

    // ── Test drive stats ──
    $tdTotal     = 0;
    $tdCompleted = 0;
    try {
        $tdTotal     = (int)$db->query("SELECT COUNT(*) FROM crm_test_drives td JOIN crm_leads l ON l.id=td.lead_id WHERE DATE(td.created_at) BETWEEN '{$dateFrom}' AND '{$dateTo}' {$ownerJoin}")->fetchColumn();
        $tdCompleted = (int)$db->query("SELECT COUNT(*) FROM crm_test_drives td JOIN crm_leads l ON l.id=td.lead_id WHERE td.status='completed' AND DATE(td.created_at) BETWEEN '{$dateFrom}' AND '{$dateTo}' {$ownerJoin}")->fetchColumn();
    } catch (\Throwable $_) {}
    $tdConvPct = $tdTotal > 0 ? round($tdCompleted / $tdTotal * 100, 1) : 0;

    // ── Agent leaderboard (admin only) ──
    $agentRows = [];
    if ($isAdmin) {
        $agentRows = $db->query("
            SELECT u.name, u.id AS uid,
                   COUNT(l.id) AS total,
                   SUM(l.stage='delivered') AS delivered,
                   SUM(l.stage='lost') AS lost,
                   SUM(l.stage NOT IN ('lost','delivered')) AS active,
                   ROUND(SUM(l.stage='delivered') / GREATEST(COUNT(l.id),1) * 100, 1) AS conv
            FROM crm_leads l
            JOIN users u ON u.id = l.assigned_to
            WHERE DATE(l.created_at) BETWEEN '{$dateFrom}' AND '{$dateTo}'
            GROUP BY u.id, u.name
            ORDER BY delivered DESC, total DESC
            LIMIT 10
        ")->fetchAll(PDO::FETCH_ASSOC);
    }

    // ── Chart JSON ──
    $srcChartLabels = $srcChartData = $srcChartColors = [];
    foreach ($sources as $sk => $sInfo) {
        if (isset($sourceMap[$sk]) && $sourceMap[$sk] > 0) {
            $srcChartLabels[] = $sInfo['label'];
            $srcChartData[]   = $sourceMap[$sk];
            $srcChartColors[] = $sInfo['color'];
        }
    }
    $stageChartLabels = $stageChartData = $stageChartColors = [];
    foreach ($stageDistMap as $sk => $sv) {
        $stageChartLabels[] = $stages[$sk]['label'] ?? ucfirst($sk);
        $stageChartData[]   = $sv;
        $stageChartColors[] = $stages[$sk]['color'] ?? '#94a3b8';
    }

} catch (\Throwable $ex) {
    $kpiAdded=$kpiDelivered=$kpiLost=$kpiActive=$prevAdded=$prevDelivered=$prevLost=0;
    $totalAllTime=$deliveredAllTime=0; $convRate=$followUpPct=0.0; $avgDays=null;
    $monthlyPerf=$actMap=$sourceMap=$stageDistMap=$lostReasonRows=$agentRows=[];
    $actTotal=$sourceTotal=$stageTotal=$activeTotal=$withFollowUp=$tdTotal=$tdCompleted=0;
    $tdConvPct=0.0;
    $trendLabels=$trendAdded=$trendDelivered=$trendLost=[];
    $srcChartLabels=$srcChartData=$srcChartColors=[];
    $stageChartLabels=$stageChartData=$stageChartColors=[];
}

// ── Delta helper ──────────────────────────────────────────────────────────────
function kpiDelta(int $curr, int $prev): array {
    if ($prev === 0) return ['pct' => null, 'dir' => 'neutral'];
    $pct = round(($curr - $prev) / $prev * 100, 1);
    return ['pct' => abs($pct), 'dir' => $pct > 0 ? 'up' : ($pct < 0 ? 'down' : 'neutral')];
}
$deltaAdded     = kpiDelta($kpiAdded,     $prevAdded);
$deltaDelivered = kpiDelta($kpiDelivered, $prevDelivered);
$deltaLost      = kpiDelta($kpiLost,      $prevLost);

$trendLabelsJson    = json_encode($trendLabels);
$trendAddedJson     = json_encode($trendAdded);
$trendDeliveredJson = json_encode($trendDelivered);
$trendLostJson      = json_encode($trendLost);
$srcLabelsJson      = json_encode($srcChartLabels);
$srcDataJson        = json_encode($srcChartData);
$srcColorsJson      = json_encode($srcChartColors);
$stageLabelsJson    = json_encode($stageChartLabels);
$stageDataJson      = json_encode($stageChartData);
$stageColorsJson    = json_encode($stageChartColors);

$extraJs = <<<JS
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
(function () {
    var gridColor = 'rgba(0,0,0,0.05)';

    // ── Monthly trend bar+line chart ──
    var tc = document.getElementById('trendChart');
    if (tc) {
        new Chart(tc, {
            data: {
                labels: {$trendLabelsJson},
                datasets: [
                    {
                        type: 'bar',
                        label: 'Leads Added',
                        data: {$trendAddedJson},
                        backgroundColor: 'rgba(37,99,235,0.15)',
                        borderColor: '#2563eb',
                        borderWidth: 1.5,
                        borderRadius: 6,
                        order: 2
                    },
                    {
                        type: 'line',
                        label: 'Delivered',
                        data: {$trendDeliveredJson},
                        borderColor: '#16a34a',
                        backgroundColor: 'rgba(22,163,74,0.08)',
                        borderWidth: 2.5,
                        pointRadius: 5,
                        pointBackgroundColor: '#16a34a',
                        tension: 0.4, fill: false, order: 1
                    },
                    {
                        type: 'line',
                        label: 'Lost',
                        data: {$trendLostJson},
                        borderColor: '#dc2626',
                        backgroundColor: 'transparent',
                        borderWidth: 2,
                        borderDash: [5,4],
                        pointRadius: 4,
                        pointBackgroundColor: '#dc2626',
                        tension: 0.4, fill: false, order: 0
                    }
                ]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: { position: 'top', labels: { padding: 16, font: { size: 12 } } }
                },
                scales: {
                    y: { beginAtZero: true, ticks: { stepSize: 1, font: { size: 11 } }, grid: { color: gridColor } },
                    x: { grid: { display: false }, ticks: { font: { size: 11 } } }
                }
            }
        });
    }

    // ── Source doughnut ──
    var src = document.getElementById('sourceChart');
    if (src) {
        new Chart(src, {
            type: 'doughnut',
            data: {
                labels: {$srcLabelsJson},
                datasets: [{ data: {$srcDataJson}, backgroundColor: {$srcColorsJson}, borderWidth: 3, borderColor: '#fff', hoverOffset: 8 }]
            },
            options: {
                responsive: true, maintainAspectRatio: false, cutout: '60%',
                plugins: { legend: { position: 'right', labels: { padding: 12, font: { size: 12 }, boxWidth: 12, boxHeight: 12 } } }
            }
        });
    }

    // ── Stage doughnut ──
    var sc = document.getElementById('stageChart');
    if (sc) {
        new Chart(sc, {
            type: 'doughnut',
            data: {
                labels: {$stageLabelsJson},
                datasets: [{ data: {$stageDataJson}, backgroundColor: {$stageColorsJson}, borderWidth: 3, borderColor: '#fff', hoverOffset: 8 }]
            },
            options: {
                responsive: true, maintainAspectRatio: false, cutout: '60%',
                plugins: { legend: { position: 'right', labels: { padding: 12, font: { size: 12 }, boxWidth: 12, boxHeight: 12 } } }
            }
        });
    }
}());
</script>
JS;

include __DIR__ . '/../../includes/header.php';
?>

<style>
/* ── Reports page styles ───────────────────────────────────────────────── */
.rpt-kpi {
    background: #fff;
    border-radius: 14px;
    padding: 22px 22px 18px;
    border: 1px solid #e5e7eb;
    border-top: 4px solid transparent;
    transition: box-shadow .2s, transform .2s;
    height: 100%;
}
.rpt-kpi:hover { box-shadow: 0 8px 24px rgba(0,0,0,.09); transform: translateY(-2px); }
.rpt-kpi-icon {
    width: 48px; height: 48px; border-radius: 12px;
    display: flex; align-items: center; justify-content: center;
    font-size: 20px; flex-shrink: 0;
}
.rpt-kpi-label { font-size: 11px; font-weight: 700; color: #6b7280; text-transform: uppercase; letter-spacing: .07em; margin-bottom: 4px; }
.rpt-kpi-value { font-size: 34px; font-weight: 800; line-height: 1; color: #111827; }
.rpt-kpi-sub   { font-size: 12px; color: #9ca3af; margin-top: 6px; }
.rpt-kpi-delta { font-size: 12px; font-weight: 700; display: inline-flex; align-items: center; gap: 3px; padding: 2px 8px; border-radius: 20px; }
.delta-up      { background: #dcfce7; color: #15803d; }
.delta-down    { background: #fee2e2; color: #b91c1c; }
.delta-neutral { background: #f3f4f6; color: #6b7280; }

.section-card {
    background: #fff;
    border-radius: 14px;
    border: 1px solid #e5e7eb;
    overflow: hidden;
    height: 100%;
}
.section-card .card-hdr {
    padding: 16px 20px;
    border-bottom: 1px solid #f3f4f6;
    display: flex; align-items: center; justify-content: space-between; gap: 10px;
    background: #fafafa;
}
.section-card .card-hdr-title { font-size: 14px; font-weight: 700; color: #111827; display: flex; align-items: center; gap: 8px; }
.section-card .card-hdr-badge { font-size: 11px; color: #6b7280; background: #e5e7eb; padding: 2px 10px; border-radius: 20px; font-weight: 600; }
.section-card .card-bdy { padding: 20px; }

.bar-row { display: flex; align-items: center; gap: 10px; margin-bottom: 11px; }
.bar-label { min-width: 108px; font-size: 13px; font-weight: 500; color: #374151; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.bar-track { flex: 1; background: #f1f5f9; border-radius: 100px; height: 9px; overflow: hidden; }
.bar-fill  { height: 100%; border-radius: 100px; transition: width .6s cubic-bezier(.22,.61,.36,1); }
.bar-count { min-width: 32px; text-align: right; font-size: 13px; font-weight: 700; color: #111827; }
.bar-pct   { min-width: 42px; text-align: right; font-size: 12px; color: #9ca3af; }

.agent-rank { width: 26px; height: 26px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 11px; font-weight: 800; flex-shrink: 0; }

.period-btn { padding: 6px 14px; border-radius: 8px; font-size: 13px; font-weight: 600; border: 1.5px solid #e5e7eb; background: #fff; color: #374151; cursor: pointer; text-decoration: none; transition: all .15s; }
.period-btn:hover { border-color: #2563eb; color: #2563eb; background: #eff6ff; }
.period-btn.active { background: #2563eb; color: #fff; border-color: #2563eb; }

@media print {
    .no-print { display: none !important; }
    .rpt-kpi  { break-inside: avoid; }
    .section-card { break-inside: avoid; box-shadow: none; border: 1px solid #ddd; }
    body { background: #fff !important; }
    @page { margin: 12mm 14mm; }
}
</style>

<!-- ── Page Header ─────────────────────────────────────────────────────────── -->
<div class="d-flex flex-wrap align-items-start justify-content-between gap-3 mb-4 no-print">
    <div>
        <h5 class="mb-1 fw-bold">
            <i class="fa fa-chart-bar me-2 text-primary"></i><?= e($pageTitle) ?>
        </h5>
        <div class="text-muted small">
            <i class="fa fa-calendar-days me-1"></i>
            <?= date('d M Y', strtotime($dateFrom)) ?> &ndash; <?= date('d M Y', strtotime($dateTo)) ?>
            &nbsp;&middot;&nbsp;
            <span class="fw-semibold text-dark"><?= e($periodLabel) ?></span>
        </div>
    </div>
    <div class="d-flex gap-2 flex-wrap align-items-center">
        <button onclick="window.print()" class="btn btn-sm btn-outline-secondary">
            <i class="fa fa-print me-1"></i>Print Report
        </button>
        <a href="leads.php" class="btn btn-sm btn-outline-secondary">
            <i class="fa fa-arrow-left me-1"></i>Back
        </a>
    </div>
</div>

<!-- ── Period Selector ─────────────────────────────────────────────────────── -->
<div class="section-card mb-4 no-print">
    <div class="card-bdy py-3">
        <form method="GET" class="d-flex align-items-center gap-2 flex-wrap">
            <?php
            $periods = [
                'this_month'    => 'This Month',
                'last_month'    => 'Last Month',
                'last_30_days'  => 'Last 30 Days',
                'last_3_months' => 'Last 3 Months',
                'this_year'     => 'This Year',
                'custom'        => 'Custom',
            ];
            foreach ($periods as $pk => $pl): ?>
            <a href="?period=<?= $pk ?>" class="period-btn <?= $period === $pk ? 'active' : '' ?>"><?= $pl ?></a>
            <?php endforeach; ?>
            <div class="d-flex align-items-center gap-2 ms-auto flex-wrap" id="customRangeWrap"
                 style="<?= $period !== 'custom' ? 'display:none!important' : '' ?>">
                <input type="date" name="date_from" class="form-control form-control-sm" style="width:150px"
                       value="<?= e($customFrom ?: $dateFrom) ?>"
                       onchange="document.getElementById('periodField').value='custom'">
                <span class="text-muted small">to</span>
                <input type="date" name="date_to" class="form-control form-control-sm" style="width:150px"
                       value="<?= e($customTo ?: $dateTo) ?>"
                       onchange="document.getElementById('periodField').value='custom'">
                <input type="hidden" name="period" id="periodField" value="custom">
                <button type="submit" class="btn btn-sm btn-primary">Apply</button>
            </div>
        </form>
    </div>
</div>
<script>
document.querySelectorAll('.period-btn').forEach(function(btn) {
    btn.addEventListener('click', function(e) {
        if (this.href.includes('period=custom')) {
            e.preventDefault();
            var wrap = document.getElementById('customRangeWrap');
            wrap.style.removeProperty('display');
            document.getElementById('periodField').value = 'custom';
        }
    });
});
</script>

<!-- ── KPI Cards ───────────────────────────────────────────────────────────── -->
<div class="row g-3 mb-4">

    <?php
    function renderDelta(array $d, bool $invertBad = false): string {
        if ($d['pct'] === null) return '<span class="rpt-kpi-delta delta-neutral">— new</span>';
        if ($d['dir'] === 'neutral') return '<span class="rpt-kpi-delta delta-neutral">no change</span>';
        $good = $invertBad ? ($d['dir'] === 'down') : ($d['dir'] === 'up');
        $cls  = $good ? 'delta-up' : 'delta-down';
        $icon = $d['dir'] === 'up' ? '&#8599;' : '&#8600;';
        return "<span class='rpt-kpi-delta {$cls}'>{$icon} {$d['pct']}%</span>";
    }
    ?>

    <!-- Leads Added -->
    <div class="col-6 col-xl-3">
        <div class="rpt-kpi" style="border-top-color:#2563eb">
            <div class="d-flex align-items-start gap-3 mb-2">
                <div class="rpt-kpi-icon" style="background:#dbeafe;color:#2563eb"><i class="fa fa-user-plus"></i></div>
                <div class="flex-grow-1">
                    <div class="rpt-kpi-label">Leads Added</div>
                    <div class="rpt-kpi-value"><?= $kpiAdded ?></div>
                </div>
            </div>
            <div class="d-flex align-items-center justify-content-between">
                <?= renderDelta($deltaAdded) ?>
                <span class="rpt-kpi-sub">vs prev. period</span>
            </div>
        </div>
    </div>

    <!-- Delivered / Won -->
    <div class="col-6 col-xl-3">
        <div class="rpt-kpi" style="border-top-color:#16a34a">
            <div class="d-flex align-items-start gap-3 mb-2">
                <div class="rpt-kpi-icon" style="background:#dcfce7;color:#16a34a"><i class="fa fa-trophy"></i></div>
                <div class="flex-grow-1">
                    <div class="rpt-kpi-label">Delivered / Won</div>
                    <div class="rpt-kpi-value"><?= $kpiDelivered ?></div>
                </div>
            </div>
            <div class="d-flex align-items-center justify-content-between">
                <?= renderDelta($deltaDelivered) ?>
                <span class="rpt-kpi-sub">vs prev. period</span>
            </div>
        </div>
    </div>

    <!-- Leads Lost -->
    <div class="col-6 col-xl-3">
        <div class="rpt-kpi" style="border-top-color:#dc2626">
            <div class="d-flex align-items-start gap-3 mb-2">
                <div class="rpt-kpi-icon" style="background:#fee2e2;color:#dc2626"><i class="fa fa-circle-xmark"></i></div>
                <div class="flex-grow-1">
                    <div class="rpt-kpi-label">Leads Lost</div>
                    <div class="rpt-kpi-value"><?= $kpiLost ?></div>
                </div>
            </div>
            <div class="d-flex align-items-center justify-content-between">
                <?= renderDelta($deltaLost, true) ?>
                <span class="rpt-kpi-sub">vs prev. period</span>
            </div>
        </div>
    </div>

    <!-- Conversion Rate -->
    <div class="col-6 col-xl-3">
        <div class="rpt-kpi" style="border-top-color:#9333ea">
            <div class="d-flex align-items-start gap-3 mb-2">
                <div class="rpt-kpi-icon" style="background:#f3e8ff;color:#9333ea"><i class="fa fa-chart-line"></i></div>
                <div class="flex-grow-1">
                    <div class="rpt-kpi-label">Conversion Rate</div>
                    <div class="rpt-kpi-value"><?= $convRate ?>%</div>
                </div>
            </div>
            <div class="d-flex align-items-center justify-content-between">
                <span class="rpt-kpi-sub"><?= $deliveredAllTime ?> / <?= $totalAllTime ?> all-time leads</span>
            </div>
        </div>
    </div>

</div>

<!-- ── Secondary KPIs ──────────────────────────────────────────────────────── -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="section-card">
            <div class="card-bdy text-center py-3">
                <div style="font-size:26px;font-weight:800;color:#0891b2"><?= $kpiActive ?></div>
                <div style="font-size:11px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.06em;margin-top:4px">Active Pipeline</div>
                <div style="font-size:12px;color:#9ca3af;margin-top:2px">open leads today</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="section-card">
            <div class="card-bdy text-center py-3">
                <div style="font-size:26px;font-weight:800;color:#d97706"><?= $avgDays !== null ? $avgDays.'d' : '—' ?></div>
                <div style="font-size:11px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.06em;margin-top:4px">Lead Velocity</div>
                <div style="font-size:12px;color:#9ca3af;margin-top:2px">avg. days to deliver</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="section-card">
            <div class="card-bdy text-center py-3">
                <div style="font-size:26px;font-weight:800;color:#9333ea"><?= $tdTotal ?></div>
                <div style="font-size:11px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.06em;margin-top:4px">Test Drives</div>
                <div style="font-size:12px;color:#9ca3af;margin-top:2px"><?= $tdCompleted ?> completed &middot; <?= $tdConvPct ?>%</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="section-card">
            <div class="card-bdy text-center py-3">
                <div style="font-size:26px;font-weight:800;<?= $followUpPct >= 80 ? 'color:#16a34a' : ($followUpPct >= 50 ? 'color:#d97706' : 'color:#dc2626') ?>"><?= $followUpPct ?>%</div>
                <div style="font-size:11px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.06em;margin-top:4px">Follow-up Rate</div>
                <div style="font-size:12px;color:#9ca3af;margin-top:2px"><?= $withFollowUp ?> of <?= $activeTotal ?> scheduled</div>
            </div>
        </div>
    </div>
</div>

<!-- ── Trend Chart + Monthly Table ─────────────────────────────────────────── -->
<div class="row g-4 mb-4">

    <div class="col-xl-8">
        <div class="section-card">
            <div class="card-hdr">
                <span class="card-hdr-title"><i class="fa fa-chart-mixed text-primary"></i>6-Month Performance Trend</span>
                <span class="card-hdr-badge">Added · Delivered · Lost</span>
            </div>
            <div class="card-bdy" style="height:280px;position:relative">
                <canvas id="trendChart"></canvas>
            </div>
        </div>
    </div>

    <div class="col-xl-4">
        <div class="section-card">
            <div class="card-hdr">
                <span class="card-hdr-title"><i class="fa fa-table text-primary"></i>Monthly Breakdown</span>
            </div>
            <div style="overflow-x:auto">
                <table style="width:100%;font-size:12.5px;border-collapse:collapse">
                    <thead>
                        <tr style="background:#f9fafb;border-bottom:1px solid #e5e7eb">
                            <th style="padding:10px 16px;font-weight:700;color:#374151;text-align:left">Month</th>
                            <th style="padding:10px 8px;font-weight:700;color:#374151;text-align:center">Added</th>
                            <th style="padding:10px 8px;font-weight:700;color:#374151;text-align:center">Won</th>
                            <th style="padding:10px 8px;font-weight:700;color:#374151;text-align:center">Conv</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($monthlyPerf as $mp): ?>
                    <tr style="border-bottom:1px solid #f3f4f6">
                        <td style="padding:9px 16px;font-weight:600;color:#111827"><?= e($mp['ymLabel']) ?></td>
                        <td style="padding:9px 8px;text-align:center">
                            <span style="background:#dbeafe;color:#1d4ed8;padding:2px 8px;border-radius:20px;font-weight:700;font-size:12px"><?= $mp['added'] ?></span>
                        </td>
                        <td style="padding:9px 8px;text-align:center">
                            <?php if ($mp['delivered'] > 0): ?>
                            <span style="background:#dcfce7;color:#15803d;padding:2px 8px;border-radius:20px;font-weight:700;font-size:12px"><?= $mp['delivered'] ?></span>
                            <?php else: ?><span style="color:#d1d5db">0</span><?php endif; ?>
                        </td>
                        <td style="padding:9px 8px;text-align:center;font-weight:700;color:<?= $mp['mConv']>=20?'#15803d':($mp['mConv']>=10?'#92400e':'#9ca3af') ?>">
                            <?= $mp['mConv'] ?>%
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

</div>

<!-- ── Source Donut + Stage Donut ─────────────────────────────────────────── -->
<div class="row g-4 mb-4">

    <div class="col-lg-6">
        <div class="section-card">
            <div class="card-hdr">
                <span class="card-hdr-title"><i class="fa fa-signs-post text-primary"></i>Lead Sources</span>
                <span class="card-hdr-badge"><?= e($periodLabel) ?></span>
            </div>
            <div class="card-bdy">
                <?php if (empty($sourceMap)): ?>
                <div class="text-center text-muted py-5">
                    <i class="fa fa-inbox fa-2x d-block mb-2 opacity-25"></i>No leads in this period.
                </div>
                <?php else: ?>
                <div style="height:220px;position:relative">
                    <canvas id="sourceChart"></canvas>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="section-card">
            <div class="card-hdr">
                <span class="card-hdr-title"><i class="fa fa-chart-pie text-primary"></i>Pipeline Stage Distribution</span>
                <span class="card-hdr-badge"><?= $stageTotal ?> active leads</span>
            </div>
            <div class="card-bdy">
                <?php if (empty($stageDistMap)): ?>
                <div class="text-center text-muted py-5">
                    <i class="fa fa-inbox fa-2x d-block mb-2 opacity-25"></i>No active leads in pipeline.
                </div>
                <?php else: ?>
                <div style="height:220px;position:relative">
                    <canvas id="stageChart"></canvas>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

</div>

<!-- ── Activity + Lost Reasons ────────────────────────────────────────────── -->
<div class="row g-4 mb-4">

    <div class="col-lg-6">
        <div class="section-card">
            <div class="card-hdr">
                <span class="card-hdr-title"><i class="fa fa-list-check text-primary"></i>Activity Breakdown</span>
                <span class="card-hdr-badge"><?= $actTotal ?> total &middot; <?= e($periodLabel) ?></span>
            </div>
            <div class="card-bdy">
                <?php if (empty($actMap)): ?>
                <div class="text-center text-muted py-5">
                    <i class="fa fa-inbox fa-2x d-block mb-2 opacity-25"></i>No activity logged in this period.
                </div>
                <?php else:
                    $actMax = max($actMap) ?: 1;
                    foreach ($activityTypes as $aKey => $aInfo):
                        $cnt   = $actMap[$aKey] ?? 0;
                        $pct   = round($cnt / $actMax * 100);
                        $share = $actTotal > 0 ? round($cnt / $actTotal * 100, 1) : 0;
                ?>
                <div class="bar-row">
                    <div class="bar-label">
                        <i class="fa <?= $aInfo['icon'] ?> me-1" style="color:<?= $aInfo['color'] ?>;width:15px"></i>
                        <?= e($aInfo['label']) ?>
                    </div>
                    <div class="bar-track">
                        <div class="bar-fill" style="width:<?= $pct ?>%;background:<?= $aInfo['color'] ?>"></div>
                    </div>
                    <div class="bar-count"><?= $cnt ?></div>
                    <div class="bar-pct"><?= $share ?>%</div>
                </div>
                <?php endforeach; endif; ?>
            </div>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="section-card">
            <div class="card-hdr">
                <span class="card-hdr-title"><i class="fa fa-circle-xmark text-danger"></i>Lost Reason Analysis</span>
                <span class="card-hdr-badge">top reasons &middot; all-time</span>
            </div>
            <div class="card-bdy">
                <?php if (empty($lostReasonRows)): ?>
                <div class="text-center text-muted py-5">
                    <i class="fa fa-circle-check fa-2x d-block mb-2 text-success opacity-50"></i>
                    No lost reason data yet.
                </div>
                <?php else:
                    $lostMax = max(array_column($lostReasonRows, 'cnt')) ?: 1;
                    $lostAllTotal = array_sum(array_column($lostReasonRows, 'cnt'));
                    foreach ($lostReasonRows as $idx => $lr):
                        $cnt   = (int)$lr['cnt'];
                        $pct   = round($cnt / $lostMax * 100);
                        $share = $lostAllTotal > 0 ? round($cnt / $lostAllTotal * 100, 1) : 0;
                        $intensity = ['#991b1b','#b91c1c','#dc2626','#ef4444','#f87171','#fca5a5'];
                        $barColor  = $intensity[$idx] ?? '#fca5a5';
                ?>
                <div class="mb-3">
                    <div class="d-flex align-items-center justify-content-between mb-1">
                        <div class="d-flex align-items-center gap-2">
                            <span style="width:20px;height:20px;border-radius:50%;background:<?= $barColor ?>;color:#fff;font-size:10px;font-weight:800;display:inline-flex;align-items:center;justify-content:center;flex-shrink:0"><?= $idx+1 ?></span>
                            <span style="font-size:13px;font-weight:600;color:#111827"><?= e($lr['lost_reason']) ?></span>
                        </div>
                        <div class="d-flex align-items-center gap-2">
                            <span style="font-size:12px;color:#9ca3af"><?= $share ?>%</span>
                            <span style="background:#fee2e2;color:#b91c1c;padding:2px 9px;border-radius:20px;font-size:12px;font-weight:700"><?= $cnt ?></span>
                        </div>
                    </div>
                    <div class="bar-track">
                        <div class="bar-fill" style="width:<?= $pct ?>%;background:<?= $barColor ?>"></div>
                    </div>
                </div>
                <?php endforeach; endif; ?>
            </div>
        </div>
    </div>

</div>

<!-- ── Agent Leaderboard (admin only) ─────────────────────────────────────── -->
<?php if ($isAdmin && !empty($agentRows)): ?>
<div class="row g-4 mb-4">
    <div class="col-12">
        <div class="section-card">
            <div class="card-hdr">
                <span class="card-hdr-title"><i class="fa fa-ranking-star text-primary"></i>Agent Leaderboard</span>
                <span class="card-hdr-badge"><?= e($periodLabel) ?></span>
            </div>
            <div style="overflow-x:auto">
                <table style="width:100%;font-size:13px;border-collapse:collapse">
                    <thead>
                        <tr style="background:#f9fafb;border-bottom:1px solid #e5e7eb">
                            <th style="padding:11px 20px;font-weight:700;color:#374151;text-align:left">Rank</th>
                            <th style="padding:11px 12px;font-weight:700;color:#374151;text-align:left">Agent</th>
                            <th style="padding:11px 12px;font-weight:700;color:#374151;text-align:center">Total Leads</th>
                            <th style="padding:11px 12px;font-weight:700;color:#374151;text-align:center">Delivered</th>
                            <th style="padding:11px 12px;font-weight:700;color:#374151;text-align:center">Lost</th>
                            <th style="padding:11px 12px;font-weight:700;color:#374151;text-align:center">Active</th>
                            <th style="padding:11px 20px;font-weight:700;color:#374151;text-align:center">Conv. Rate</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($agentRows as $idx => $ag):
                        $rankColors = ['#f59e0b','#94a3b8','#cd7c34'];
                        $rankIcons  = ['fa-trophy','fa-medal','fa-award'];
                        $isTop3     = $idx < 3;
                        $convColor  = (float)$ag['conv'] >= 20 ? '#15803d' : ((float)$ag['conv'] >= 10 ? '#92400e' : '#9ca3af');
                    ?>
                    <tr style="border-bottom:1px solid #f3f4f6;<?= $idx===0?'background:#fffbeb':'' ?>">
                        <td style="padding:12px 20px">
                            <?php if ($isTop3): ?>
                            <i class="fa <?= $rankIcons[$idx] ?>" style="color:<?= $rankColors[$idx] ?>;font-size:18px"></i>
                            <?php else: ?>
                            <span style="font-size:13px;font-weight:700;color:#9ca3af">#<?= $idx+1 ?></span>
                            <?php endif; ?>
                        </td>
                        <td style="padding:12px 12px">
                            <div class="d-flex align-items-center gap-2">
                                <div style="width:34px;height:34px;border-radius:50%;background:linear-gradient(135deg,#667eea,#764ba2);color:#fff;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:13px;flex-shrink:0">
                                    <?= strtoupper(substr($ag['name'], 0, 1)) ?>
                                </div>
                                <span style="font-weight:600;color:#111827"><?= e($ag['name']) ?></span>
                            </div>
                        </td>
                        <td style="padding:12px;text-align:center;font-weight:700;color:#374151"><?= (int)$ag['total'] ?></td>
                        <td style="padding:12px;text-align:center">
                            <?php if ((int)$ag['delivered'] > 0): ?>
                            <span style="background:#dcfce7;color:#15803d;padding:3px 10px;border-radius:20px;font-weight:700"><?= (int)$ag['delivered'] ?></span>
                            <?php else: ?><span style="color:#d1d5db">0</span><?php endif; ?>
                        </td>
                        <td style="padding:12px;text-align:center">
                            <?php if ((int)$ag['lost'] > 0): ?>
                            <span style="background:#fee2e2;color:#b91c1c;padding:3px 10px;border-radius:20px;font-weight:700"><?= (int)$ag['lost'] ?></span>
                            <?php else: ?><span style="color:#d1d5db">0</span><?php endif; ?>
                        </td>
                        <td style="padding:12px;text-align:center;color:#6b7280;font-weight:600"><?= (int)$ag['active'] ?></td>
                        <td style="padding:12px 20px;text-align:center">
                            <span style="font-size:14px;font-weight:800;color:<?= $convColor ?>"><?= $ag['conv'] ?>%</span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ── Follow-up Compliance ───────────────────────────────────────────────── -->
<div class="row g-4 mb-4">
    <div class="col-12">
        <div class="section-card">
            <div class="card-hdr">
                <span class="card-hdr-title"><i class="fa fa-calendar-check text-primary"></i>Follow-up Compliance</span>
                <span class="card-hdr-badge">active leads only</span>
            </div>
            <div class="card-bdy">
                <div class="row g-4 align-items-center">
                    <div class="col-md-8">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <span style="font-size:15px;font-weight:700;color:#111827">
                                <?= $withFollowUp ?> of <?= $activeTotal ?> active leads have a follow-up date set
                            </span>
                            <span style="font-size:22px;font-weight:800;color:<?= $followUpPct>=80?'#16a34a':($followUpPct>=50?'#d97706':'#dc2626') ?>">
                                <?= $followUpPct ?>%
                            </span>
                        </div>
                        <div style="background:#f1f5f9;border-radius:100px;height:14px;overflow:hidden;margin-bottom:10px">
                            <div style="height:100%;border-radius:100px;width:<?= $followUpPct ?>%;background:<?= $followUpPct>=80?'#16a34a':($followUpPct>=50?'#d97706':'#dc2626') ?>;transition:width .8s ease"></div>
                        </div>
                        <p class="small mb-0" style="color:#6b7280">
                            <?php if ($followUpPct >= 80): ?>
                            <i class="fa fa-circle-check text-success me-1"></i>Excellent — team is well on top of follow-ups.
                            <?php elseif ($followUpPct >= 50): ?>
                            <i class="fa fa-circle-exclamation text-warning me-1"></i>Fair — consider scheduling follow-ups for remaining <?= max(0,$activeTotal-$withFollowUp) ?> leads.
                            <?php else: ?>
                            <i class="fa fa-triangle-exclamation text-danger me-1"></i>Low — <?= max(0,$activeTotal-$withFollowUp) ?> leads have no follow-up scheduled and are at risk.
                            <?php endif; ?>
                        </p>
                    </div>
                    <div class="col-md-4">
                        <div class="row g-3">
                            <div class="col-6">
                                <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:12px;padding:16px;text-align:center">
                                    <div style="font-size:28px;font-weight:800;color:#15803d"><?= $withFollowUp ?></div>
                                    <div style="font-size:11px;font-weight:700;color:#15803d;text-transform:uppercase;letter-spacing:.05em;margin-top:4px">Scheduled</div>
                                </div>
                            </div>
                            <div class="col-6">
                                <div style="background:#fef2f2;border:1px solid #fecaca;border-radius:12px;padding:16px;text-align:center">
                                    <div style="font-size:28px;font-weight:800;color:#b91c1c"><?= max(0,$activeTotal-$withFollowUp) ?></div>
                                    <div style="font-size:11px;font-weight:700;color:#b91c1c;text-transform:uppercase;letter-spacing:.05em;margin-top:4px">Overdue</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="mb-4"></div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>

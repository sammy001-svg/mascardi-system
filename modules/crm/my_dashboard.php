<?php
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
canAccess('crm') || redirect(BASE_URL . '/index.php');

$pageTitle = 'My Pipeline';
$db  = getDB();
$me  = authUser();
$uid = (int)$me['id'];

// Data isolation: CRM agents see only their own leads
$isCrmAgent  = ($me['role'] === 'customer_relations');
$ownerWhere  = $isCrmAgent ? "AND assigned_to = {$uid}"   : '';
$ownerJoin   = $isCrmAgent ? "AND l.assigned_to = {$uid}" : '';

$stages = [
    'hot'      => ['label'=>'Hot',      'color'=>'#dc2626','bg'=>'#fff1f2','icon'=>'fa-fire'],
    'lukewarm' => ['label'=>'Warm',     'color'=>'#d97706','bg'=>'#fffbeb','icon'=>'fa-temperature-half'],
    'cold'     => ['label'=>'Cold',     'color'=>'#0891b2','bg'=>'#ecfeff','icon'=>'fa-snowflake'],
    'reserved' => ['label'=>'Reserved', 'color'=>'#7c3aed','bg'=>'#f5f3ff','icon'=>'fa-bookmark'],
];

$activityIcons = [
    'call'       => ['fa-phone',        '#16a34a'],
    'whatsapp'   => ['fa-whatsapp',     '#16a34a'],
    'email'      => ['fa-envelope',     '#2563eb'],
    'visit'      => ['fa-location-dot', '#d97706'],
    'test_drive' => ['fa-car-side',     '#9333ea'],
    'meeting'    => ['fa-users',        '#0891b2'],
    'note'       => ['fa-note-sticky',  '#64748b'],
];

try {
    // KPI metrics
    $kpiActive = (int)$db->query("SELECT COUNT(*) FROM crm_leads WHERE stage NOT IN ('lost','delivered') {$ownerWhere}")->fetchColumn();
    $kpiDueNow = (int)$db->query("SELECT COUNT(*) FROM crm_leads WHERE follow_up_date <= CURDATE() AND stage NOT IN ('lost','delivered') {$ownerWhere}")->fetchColumn();
    $kpiOverdue = (int)$db->query("SELECT COUNT(*) FROM crm_leads WHERE follow_up_date < CURDATE() AND stage NOT IN ('lost','delivered') {$ownerWhere}")->fetchColumn();
    $kpiWon    = (int)$db->query("SELECT COUNT(*) FROM crm_leads WHERE stage='delivered' AND MONTH(updated_at)=MONTH(NOW()) AND YEAR(updated_at)=YEAR(NOW()) {$ownerWhere}")->fetchColumn();
    $kpiValue  = (float)$db->query("SELECT COALESCE(SUM(budget),0) FROM crm_leads WHERE stage NOT IN ('lost','delivered') {$ownerWhere}")->fetchColumn();

    // Stage breakdown for Kanban
    $stageRows = $db->query("
        SELECT stage, COUNT(*) cnt, COALESCE(SUM(budget),0) total_budget
        FROM crm_leads
        WHERE stage NOT IN ('lost','delivered') {$ownerWhere}
        GROUP BY stage
    ")->fetchAll();
    $stageMap = [];
    foreach ($stageRows as $r) $stageMap[$r['stage']] = $r;

    // Leads for Kanban — overdue follow-ups float to top
    $kanbanLeads = $db->query("
        SELECT l.*, u.name assigned_name
        FROM crm_leads l
        LEFT JOIN users u ON u.id = l.assigned_to
        WHERE l.stage NOT IN ('lost','delivered') {$ownerJoin}
        ORDER BY
            CASE WHEN l.follow_up_date < CURDATE() THEN 0
                 WHEN l.follow_up_date = CURDATE()  THEN 1
                 ELSE 2 END,
            l.follow_up_date ASC,
            l.updated_at DESC
    ")->fetchAll();

    $kanban = [];
    foreach ($kanbanLeads as $l) $kanban[$l['stage']][] = $l;

    // Follow-up timeline (all upcoming + overdue)
    $followUps = $db->query("
        SELECT l.*
        FROM crm_leads l
        WHERE l.follow_up_date IS NOT NULL
          AND l.stage NOT IN ('lost','delivered')
          {$ownerJoin}
        ORDER BY l.follow_up_date ASC
        LIMIT 20
    ")->fetchAll();

    // Recent activity
    $stmt = $db->prepare("
        SELECT a.*, l.name lead_name, l.id lead_id
        FROM crm_activities a
        LEFT JOIN crm_leads l ON l.id = a.lead_id
        WHERE a.created_by = ?
        ORDER BY a.created_at DESC
        LIMIT 10
    ");
    $stmt->execute([$uid]);
    $myActivities = $stmt->fetchAll();

    // All-time conversion stats
    $totalLeads = (int)$db->query("SELECT COUNT(*) FROM crm_leads WHERE 1 {$ownerWhere}")->fetchColumn();
    $wonTotal   = (int)$db->query("SELECT COUNT(*) FROM crm_leads WHERE stage='delivered' {$ownerWhere}")->fetchColumn();
    $lostTotal  = (int)$db->query("SELECT COUNT(*) FROM crm_leads WHERE stage='lost' {$ownerWhere}")->fetchColumn();
    $lostMonth  = (int)$db->query("SELECT COUNT(*) FROM crm_leads WHERE stage='lost' AND MONTH(updated_at)=MONTH(NOW()) AND YEAR(updated_at)=YEAR(NOW()) {$ownerWhere}")->fetchColumn();
    $convRate   = $totalLeads > 0 ? round($wonTotal / $totalLeads * 100, 1) : 0;

    // Monthly trend — leads added last 6 months
    $trendLabels = [];
    $trendCounts = [];
    for ($i = 5; $i >= 0; $i--) {
        $ym = date('Y-m', strtotime("-{$i} months"));
        $trendLabels[] = date('M', strtotime($ym . '-01'));
        $trendCounts[] = (int)$db->query("SELECT COUNT(*) FROM crm_leads WHERE DATE_FORMAT(created_at,'%Y-%m')='{$ym}' {$ownerWhere}")->fetchColumn();
    }

} catch (\Throwable $e) {
    $kpiActive = $kpiDueNow = $kpiOverdue = $kpiWon = $kpiValue = 0;
    $stageMap = $kanban = $followUps = $myActivities = [];
    $totalLeads = $wonTotal = $lostTotal = $lostMonth = 0;
    $convRate = 0;
    $trendLabels = $trendCounts = [];
}

// Chart data
$trendLabelsJson = json_encode($trendLabels);
$trendCountsJson = json_encode($trendCounts);

$extraJs = <<<JS
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
(function () {
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
}());
</script>
JS;

include __DIR__ . '/../../includes/header.php';

$initials = implode('', array_map(fn($w) => strtoupper($w[0]), array_slice(explode(' ', $me['name']), 0, 2)));
$hour     = (int)date('G');
$greeting = $hour < 12 ? 'Good morning' : ($hour < 17 ? 'Good afternoon' : 'Good evening');
?>

<style>
/* ── CRM Dashboard ──────────────────────────────────────── */
.crm-welcome {
    background: linear-gradient(135deg, #1e3a8a 0%, #1d4ed8 50%, #2563eb 100%);
    border-radius: 16px;
    padding: 24px 28px;
    color: #fff;
    display: flex;
    align-items: center;
    gap: 20px;
    margin-bottom: 24px;
    position: relative;
    overflow: hidden;
}
.crm-welcome::before {
    content: '';
    position: absolute;
    top: -40px; right: -40px;
    width: 200px; height: 200px;
    background: rgba(255,255,255,0.05);
    border-radius: 50%;
}
.crm-welcome::after {
    content: '';
    position: absolute;
    bottom: -60px; right: 80px;
    width: 150px; height: 150px;
    background: rgba(255,255,255,0.04);
    border-radius: 50%;
}
.crm-avatar {
    width: 56px; height: 56px;
    border-radius: 14px;
    background: rgba(255,255,255,0.18);
    border: 2px solid rgba(255,255,255,0.3);
    display: flex; align-items: center; justify-content: center;
    font-size: 22px; font-weight: 800; letter-spacing: -1px;
    flex-shrink: 0;
}
.crm-welcome-body { flex: 1; }
.crm-welcome-body h4 { margin: 0 0 2px; font-size: 20px; font-weight: 700; }
.crm-welcome-body p  { margin: 0; font-size: 13px; opacity: .8; }
.crm-welcome-actions { display: flex; gap: 10px; flex-shrink: 0; }
.crm-welcome-actions .btn { font-size: 13px; font-weight: 600; border-radius: 10px; padding: 8px 16px; }
.crm-kpi-grid { display: grid; grid-template-columns: repeat(4,1fr); gap: 14px; margin-bottom: 20px; }
@media(max-width:992px) { .crm-kpi-grid { grid-template-columns: repeat(2,1fr); } }
@media(max-width:576px) { .crm-kpi-grid { grid-template-columns: 1fr 1fr; } .crm-welcome-actions { display:none; } }
.crm-kpi {
    background: var(--surface);
    border-radius: 14px;
    padding: 18px 20px;
    border: 1px solid var(--border);
    display: flex;
    align-items: center;
    gap: 14px;
    transition: box-shadow .2s, transform .2s;
    text-decoration: none;
    color: inherit;
}
.crm-kpi:hover { box-shadow: 0 6px 20px rgba(0,0,0,.08); transform: translateY(-2px); color: inherit; }
.crm-kpi-icon {
    width: 48px; height: 48px;
    border-radius: 12px;
    display: flex; align-items: center; justify-content: center;
    font-size: 20px;
    flex-shrink: 0;
}
.crm-kpi-body { min-width: 0; }
.crm-kpi-label { font-size: 11.5px; font-weight: 600; color: var(--text-3); text-transform: uppercase; letter-spacing: .04em; margin-bottom: 2px; }
.crm-kpi-value { font-size: 26px; font-weight: 800; color: var(--text); line-height: 1; }
.crm-kpi-sub   { font-size: 11px; color: var(--text-3); margin-top: 3px; }

/* Kanban */
.crm-board { display: flex; gap: 12px; overflow-x: auto; padding-bottom: 6px; }
.crm-col   { min-width: 220px; max-width: 220px; display: flex; flex-direction: column; }
.crm-col-hdr {
    padding: 10px 14px;
    border-radius: 10px 10px 0 0;
    font-size: 12px; font-weight: 700;
    display: flex; justify-content: space-between; align-items: center;
}
.crm-col-body {
    flex: 1; min-height: 180px;
    padding: 8px;
    border-radius: 0 0 10px 10px;
    border: 1px solid #e9ecef; border-top: none;
    background: var(--surface-alt);
}
.crm-lead-card {
    background: var(--surface);
    border-radius: 10px;
    padding: 11px 12px;
    margin-bottom: 8px;
    box-shadow: 0 1px 4px rgba(0,0,0,.06);
    font-size: 12.5px;
    cursor: pointer;
    border-left: 3px solid transparent;
    transition: box-shadow .15s, transform .1s;
}
.crm-lead-card:hover { box-shadow: 0 4px 12px rgba(0,0,0,.1); transform: translateY(-1px); }
.crm-lead-card.is-overdue { border-left-color: #dc2626; }
.crm-lead-card.is-today   { border-left-color: #f59e0b; }
.crm-lead-card.is-soon    { border-left-color: #0891b2; }
.crm-lead-name { font-weight: 700; font-size: 13px; color: var(--text); margin-bottom: 4px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.crm-lead-meta { font-size: 11px; color: var(--text-3); margin-bottom: 2px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

/* Follow-up timeline */
.fu-item {
    display: flex; gap: 12px; align-items: flex-start;
    padding: 10px 16px; border-bottom: 1px solid var(--border);
    transition: background .12s;
    text-decoration: none; color: inherit;
}
.fu-item:hover { background: var(--surface-alt); color: inherit; }
.fu-item:last-child { border-bottom: none; }
.fu-badge { flex-shrink: 0; padding: 3px 9px; border-radius: 20px; font-size: 10.5px; font-weight: 700; white-space: nowrap; }
.fu-name  { font-size: 13px; font-weight: 600; color: var(--text); }
.fu-sub   { font-size: 11.5px; color: var(--text-3); }

/* Activity timeline */
.act-dot {
    width: 28px; height: 28px;
    border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-size: 12px;
    flex-shrink: 0;
}
.stat-ring {
    width: 64px; height: 64px;
    border-radius: 50%;
    border: 5px solid #e2e8f0;
    display: flex; align-items: center; justify-content: center;
    font-size: 13px; font-weight: 800;
    position: relative;
    margin: 0 auto;
}
</style>

<?php if ($kpiOverdue > 0): ?>
<!-- Overdue alert strip -->
<div class="d-flex align-items-center gap-3 mb-3 px-4 py-3"
     style="background:#fef2f2;border:1px solid #fecaca;border-radius:12px">
    <i class="fa fa-circle-exclamation fa-lg text-danger"></i>
    <div>
        <span class="fw-bold text-danger"><?= $kpiOverdue ?> overdue follow-up<?= $kpiOverdue !== 1 ? 's' : '' ?></span>
        <span class="text-danger"> — take action to keep your pipeline moving</span>
    </div>
    <a href="leads.php?stage=" class="btn btn-sm ms-auto" style="background:#dc2626;color:#fff;border-radius:8px;font-size:12px;font-weight:700">
        View Overdue <i class="fa fa-arrow-right ms-1"></i>
    </a>
</div>
<?php endif; ?>

<!-- Welcome banner -->
<div class="crm-welcome mb-4">
    <div class="crm-avatar"><?= e($initials) ?></div>
    <div class="crm-welcome-body">
        <h4><?= e($greeting) ?>, <?= e(explode(' ', $me['name'])[0]) ?></h4>
        <p><?= date('l, j F Y') ?> &nbsp;&bull;&nbsp; Customer Relations Manager</p>
    </div>
    <div class="crm-welcome-actions">
        <?php if (canWrite('crm')): ?>
        <a href="add_lead.php" class="btn btn-light">
            <i class="fa fa-plus me-1"></i>New Lead
        </a>
        <?php endif; ?>
        <a href="leads.php" class="btn" style="background:rgba(255,255,255,.15);color:#fff;border:1px solid rgba(255,255,255,.25)">
            <i class="fa fa-list me-1"></i>All My Leads
        </a>
    </div>
</div>

<!-- KPI Cards -->
<div class="crm-kpi-grid">
    <a href="leads.php" class="crm-kpi" style="border-top:3px solid #2563eb">
        <div class="crm-kpi-icon" style="background:#dbeafe;color:#2563eb"><i class="fa fa-user-group"></i></div>
        <div class="crm-kpi-body">
            <div class="crm-kpi-label">Active Leads</div>
            <div class="crm-kpi-value"><?= $kpiActive ?></div>
            <div class="crm-kpi-sub">in pipeline</div>
        </div>
    </a>
    <a href="leads.php" class="crm-kpi" style="border-top:3px solid <?= $kpiDueNow > 0 ? '#dc2626' : '#16a34a' ?>">
        <div class="crm-kpi-icon" style="background:<?= $kpiDueNow > 0 ? '#fee2e2' : '#dcfce7' ?>;color:<?= $kpiDueNow > 0 ? '#dc2626' : '#16a34a' ?>">
            <i class="fa <?= $kpiDueNow > 0 ? 'fa-calendar-exclamation' : 'fa-calendar-check' ?>"></i>
        </div>
        <div class="crm-kpi-body">
            <div class="crm-kpi-label">Follow-ups Due</div>
            <div class="crm-kpi-value" style="color:<?= $kpiDueNow > 0 ? '#dc2626' : 'inherit' ?>"><?= $kpiDueNow ?></div>
            <div class="crm-kpi-sub"><?= $kpiOverdue > 0 ? "{$kpiOverdue} overdue" : 'all on track' ?></div>
        </div>
    </a>
    <div class="crm-kpi" style="border-top:3px solid #16a34a">
        <div class="crm-kpi-icon" style="background:#dcfce7;color:#16a34a"><i class="fa fa-trophy"></i></div>
        <div class="crm-kpi-body">
            <div class="crm-kpi-label">Won This Month</div>
            <div class="crm-kpi-value"><?= $kpiWon ?></div>
            <div class="crm-kpi-sub">deliveries</div>
        </div>
    </div>
    <div class="crm-kpi" style="border-top:3px solid #9333ea">
        <div class="crm-kpi-icon" style="background:#f3e8ff;color:#9333ea"><i class="fa fa-chart-line"></i></div>
        <div class="crm-kpi-body">
            <div class="crm-kpi-label">Pipeline Value</div>
            <div class="crm-kpi-value" style="font-size:18px;padding-top:4px"><?= money($kpiValue) ?></div>
            <div class="crm-kpi-sub">active budgets</div>
        </div>
    </div>
</div>

<!-- MAIN LAYOUT: Pipeline + Follow-ups -->
<div class="row g-4 mb-4">

    <!-- Pipeline Board -->
    <div class="col-lg-8">
        <div class="card h-100">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span class="fw-semibold"><i class="fa fa-columns me-2 text-primary"></i>Sales Pipeline</span>
                <div class="d-flex gap-2">
                    <a href="index.php" class="btn btn-xs btn-outline-secondary">Full View</a>
                    <?php if (canWrite('crm')): ?>
                    <a href="add_lead.php" class="btn btn-xs btn-primary"><i class="fa fa-plus me-1"></i>Lead</a>
                    <?php endif; ?>
                </div>
            </div>
            <div class="card-body" style="overflow-x:auto;padding:16px">
                <div class="crm-board">
                <?php foreach ($stages as $key => $cfg):
                    $colLeads = $kanban[$key] ?? [];
                    $colCount = count($colLeads);
                    $colValue = $stageMap[$key]['total_budget'] ?? 0;
                ?>
                <div class="crm-col">
                    <div class="crm-col-hdr" style="background:<?= $cfg['bg'] ?>;color:<?= $cfg['color'] ?>">
                        <span><i class="fa <?= $cfg['icon'] ?> me-1"></i><?= $cfg['label'] ?></span>
                        <span class="badge" style="background:<?= $cfg['color'] ?>;color:#fff"><?= $colCount ?></span>
                    </div>
                    <div class="crm-col-body">
                        <?php if ($colValue > 0): ?>
                        <div class="text-center mb-2" style="font-size:10.5px;color:<?= $cfg['color'] ?>;font-weight:700">
                            <?= money($colValue) ?>
                        </div>
                        <?php endif; ?>
                        <?php foreach ($colLeads as $lead):
                            $isOverdue = $lead['follow_up_date'] && $lead['follow_up_date'] < date('Y-m-d');
                            $isToday   = !$isOverdue && $lead['follow_up_date'] === date('Y-m-d');
                            $isSoon    = !$isOverdue && !$isToday && $lead['follow_up_date'] && $lead['follow_up_date'] <= date('Y-m-d', strtotime('+2 days'));
                            $cardClass = $isOverdue ? 'is-overdue' : ($isToday ? 'is-today' : ($isSoon ? 'is-soon' : ''));
                        ?>
                        <div class="crm-lead-card <?= $cardClass ?>"
                             onclick="location.href='view_lead.php?id=<?= $lead['id'] ?>'">
                            <div class="crm-lead-name"><?= e($lead['name']) ?></div>
                            <?php if ($lead['phone']): ?>
                            <div class="crm-lead-meta"><i class="fa fa-phone me-1"></i><?= e($lead['phone']) ?></div>
                            <?php endif; ?>
                            <?php if ($lead['interested_in']): ?>
                            <div class="crm-lead-meta"><i class="fa fa-car me-1"></i><?= e($lead['interested_in']) ?></div>
                            <?php endif; ?>
                            <div class="d-flex justify-content-between align-items-center mt-2 gap-1">
                                <?php if ($lead['budget']): ?>
                                <span style="font-size:10px;font-weight:700;color:#16a34a;background:#dcfce7;border-radius:6px;padding:2px 6px">
                                    <?= money((float)$lead['budget']) ?>
                                </span>
                                <?php else: ?>
                                <span></span>
                                <?php endif; ?>
                                <?php if ($lead['follow_up_date']): ?>
                                <span style="font-size:10px;font-weight:600;padding:2px 6px;border-radius:6px;
                                    background:<?= $isOverdue ? '#fee2e2' : ($isToday ? '#fef3c7' : '#f1f5f9') ?>;
                                    color:<?= $isOverdue ? '#dc2626' : ($isToday ? '#92400e' : '#64748b') ?>">
                                    <i class="fa fa-calendar me-1"></i><?= fmtDate($lead['follow_up_date'], 'd M') ?>
                                </span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                        <?php if (!$colLeads): ?>
                        <div class="text-center text-muted py-4" style="font-size:12px;opacity:.6">
                            <i class="fa fa-inbox d-block mb-1 fa-lg"></i>Empty
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Follow-up Panel -->
    <div class="col-lg-4">
        <div class="card h-100">
            <div class="card-header fw-semibold">
                <i class="fa fa-calendar-clock me-2 text-warning"></i>Follow-up Queue
                <?php if ($kpiDueNow > 0): ?>
                <span class="badge bg-danger ms-1"><?= $kpiDueNow ?></span>
                <?php endif; ?>
            </div>
            <?php if (empty($followUps)): ?>
            <div class="card-body d-flex flex-column align-items-center justify-content-center py-5 text-muted">
                <i class="fa fa-circle-check fa-2x text-success mb-3 opacity-75"></i>
                <p class="mb-0 fw-semibold">All caught up!</p>
                <p class="small mt-1">No follow-ups pending.</p>
            </div>
            <?php else: ?>
            <div style="overflow-y:auto;max-height:480px">
                <?php
                $today    = date('Y-m-d');
                $tomorrow = date('Y-m-d', strtotime('+1 day'));
                $week     = date('Y-m-d', strtotime('+7 days'));
                $shownSections = [];

                foreach ($followUps as $f):
                    $fd = $f['follow_up_date'];
                    if ($fd < $today)       $section = 'overdue';
                    elseif ($fd === $today) $section = 'today';
                    elseif ($fd === $tomorrow) $section = 'tomorrow';
                    elseif ($fd <= $week)   $section = 'week';
                    else                    $section = 'later';

                    $sectionLabel = [
                        'overdue'  => ['⚠ Overdue',    '#dc2626','#fef2f2'],
                        'today'    => ['Today',         '#92400e','#fffbeb'],
                        'tomorrow' => ['Tomorrow',      '#0c4a6e','#eff6ff'],
                        'week'     => ['This Week',     '#1e3a8a','#f8fafc'],
                        'later'    => ['Upcoming',      '#374151','#f9fafb'],
                    ][$section];

                    if (!isset($shownSections[$section])):
                        $shownSections[$section] = true;
                ?>
                <div class="px-3 py-1" style="font-size:10px;font-weight:700;letter-spacing:.06em;color:<?= $sectionLabel[1] ?>;background:<?= $sectionLabel[2] ?>;border-bottom:1px solid var(--border)">
                    <?= $sectionLabel[0] ?>
                </div>
                <?php endif; ?>
                <a href="view_lead.php?id=<?= $f['id'] ?>" class="fu-item">
                    <span class="fu-badge"
                          style="background:<?= $fd < $today ? '#fee2e2' : ($fd === $today ? '#fef3c7' : '#dbeafe') ?>;
                                 color:<?= $fd < $today ? '#dc2626' : ($fd === $today ? '#92400e' : '#1e40af') ?>">
                        <?= $fd < $today ? 'Overdue' : ($fd === $today ? 'Today' : fmtDate($fd, 'd M')) ?>
                    </span>
                    <div class="min-w-0">
                        <div class="fu-name text-truncate"><?= e($f['name']) ?></div>
                        <div class="fu-sub text-truncate"><?= e($f['phone'] ?? '—') ?></div>
                    </div>
                    <i class="fa fa-chevron-right ms-auto text-muted" style="font-size:10px;flex-shrink:0"></i>
                </a>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Bottom Row: Activity + Stats -->
<div class="row g-4">

    <!-- Recent Activity -->
    <div class="col-lg-8">
        <div class="card">
            <div class="card-header fw-semibold d-flex justify-content-between align-items-center">
                <span><i class="fa fa-clock-rotate-left me-2 text-primary"></i>My Recent Activity</span>
            </div>
            <?php if (empty($myActivities)): ?>
            <div class="card-body text-center text-muted py-5">
                <i class="fa fa-clipboard fa-2x mb-3 d-block opacity-25"></i>
                No activity logged yet. Start by viewing a lead and logging a call or note.
            </div>
            <?php else: ?>
            <div class="list-group list-group-flush">
                <?php foreach ($myActivities as $act):
                    [$aIcon, $aColor] = $activityIcons[$act['type']] ?? ['fa-note-sticky','#64748b'];
                ?>
                <a href="view_lead.php?id=<?= $act['lead_id'] ?>" class="list-group-item list-group-item-action px-4 py-2 d-flex gap-3 align-items-start">
                    <div class="act-dot mt-1" style="background:<?= $aColor ?>1a;color:<?= $aColor ?>">
                        <i class="fa <?= $aIcon ?>"></i>
                    </div>
                    <div class="flex-grow-1 min-w-0">
                        <div style="font-size:13px">
                            <span class="fw-semibold"><?= e($act['lead_name'] ?? '—') ?></span>
                            <span class="text-muted ms-1">— <?= e($act['summary']) ?></span>
                        </div>
                        <div class="text-muted" style="font-size:11px"><?= fmtDate($act['created_at'], 'd M Y, H:i') ?></div>
                    </div>
                </a>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Performance Stats -->
    <div class="col-lg-4">
        <div class="card mb-3">
            <div class="card-header fw-semibold"><i class="fa fa-chart-pie me-2 text-primary"></i>My Performance</div>
            <div class="card-body">
                <!-- Conversion rate ring -->
                <div class="text-center mb-4">
                    <div class="stat-ring mx-auto mb-2"
                         style="border-color:#2563eb;border-left-color:#e2e8f0;width:80px;height:80px;font-size:17px">
                        <?= $convRate ?>%
                    </div>
                    <div class="small fw-semibold text-muted">Conversion Rate</div>
                    <div class="text-muted" style="font-size:11px"><?= $wonTotal ?> won of <?= $totalLeads ?> total leads</div>
                </div>

                <div class="d-flex flex-column gap-2">
                    <div class="d-flex justify-content-between align-items-center py-2 border-bottom">
                        <div class="small text-muted fw-semibold">Total Leads</div>
                        <div class="fw-bold"><?= $totalLeads ?></div>
                    </div>
                    <div class="d-flex justify-content-between align-items-center py-2 border-bottom">
                        <div class="small text-muted fw-semibold">Delivered (All-time)</div>
                        <div class="fw-bold text-success"><?= $wonTotal ?></div>
                    </div>
                    <div class="d-flex justify-content-between align-items-center py-2 border-bottom">
                        <div class="small text-muted fw-semibold">Lost (All-time)</div>
                        <div class="fw-bold text-danger"><?= $lostTotal ?></div>
                    </div>
                    <div class="d-flex justify-content-between align-items-center py-2">
                        <div class="small text-muted fw-semibold">Lost This Month</div>
                        <div class="fw-bold <?= $lostMonth > 0 ? 'text-warning' : 'text-muted' ?>"><?= $lostMonth ?></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- 6-month trend -->
        <div class="card">
            <div class="card-header fw-semibold" style="font-size:13px">
                <i class="fa fa-chart-line me-2 text-primary"></i>Leads Added (6 months)
            </div>
            <div class="card-body pt-2" style="height:130px">
                <canvas id="trendChart"></canvas>
            </div>
        </div>
    </div>

</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>

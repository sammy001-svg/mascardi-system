<?php
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
canAccess('crm') || redirect(BASE_URL . '/index.php');

$pageTitle = 'CRM — Sales Pipeline';
$db   = getDB();
$me   = authUser();
$uid  = (int)$me['id'];

// Data isolation: customer_relations agents see only their own leads
$isCrmAgent = ($me['role'] === 'customer_relations');
$scopeWhere = $isCrmAgent ? "AND assigned_to = {$uid}"   : '';
$scopeJoin  = $isCrmAgent ? "AND l.assigned_to = {$uid}" : '';

// ── Stage config ──────────────────────────────────────────────────────────────
$stages = [
    'hot'       => ['label' => 'Hot',       'color' => '#dc2626', 'bg' => '#fef2f2', 'icon' => 'fa-fire'],
    'lukewarm'  => ['label' => 'Lukewarm',  'color' => '#d97706', 'bg' => '#fffbeb', 'icon' => 'fa-temperature-half'],
    'cold'      => ['label' => 'Cold',      'color' => '#0891b2', 'bg' => '#ecfeff', 'icon' => 'fa-snowflake'],
    'lost'      => ['label' => 'Lost',      'color' => '#64748b', 'bg' => '#f8fafc', 'icon' => 'fa-circle-xmark'],
    'reserved'  => ['label' => 'Reserved',  'color' => '#7c3aed', 'bg' => '#f5f3ff', 'icon' => 'fa-bookmark'],
    'delivered' => ['label' => 'Delivered', 'color' => '#16a34a', 'bg' => '#f0fdf4', 'icon' => 'fa-truck'],
];

try {
    // Summary stats (scoped to me if CRM agent)
    $stats = $db->query("
        SELECT
            COUNT(*)                                                           AS total,
            SUM(stage NOT IN ('lost','delivered'))                             AS active,
            SUM(stage = 'delivered')                                           AS won,
            SUM(stage = 'lost')                                                AS lost,
            COALESCE(SUM(CASE WHEN stage='delivered' THEN budget END), 0)     AS won_value,
            SUM(follow_up_date IS NOT NULL AND follow_up_date < CURDATE() AND stage NOT IN ('lost','delivered')) AS overdue_followups
        FROM crm_leads
        WHERE 1 {$scopeWhere}
    ")->fetch();

    // Pipeline counts per stage
    $stageCounts = $db->query("
        SELECT stage, COUNT(*) AS cnt, COALESCE(SUM(budget),0) AS total_budget
        FROM crm_leads
        WHERE stage NOT IN ('lost','delivered') {$scopeWhere}
        GROUP BY stage
    ")->fetchAll(PDO::FETCH_ASSOC);
    $stageMap = [];
    foreach ($stageCounts as $r) $stageMap[$r['stage']] = $r;

    // All active leads for Kanban (exclude closed, scoped)
    $leads = $db->query("
        SELECT l.*, u.name AS assigned_name
        FROM crm_leads l
        LEFT JOIN users u ON u.id = l.assigned_to
        WHERE l.stage NOT IN ('lost','delivered') {$scopeJoin}
        ORDER BY l.updated_at DESC
    ")->fetchAll();

    // Upcoming follow-ups (next 7 days, not closed, scoped)
    $followUps = $db->query("
        SELECT l.*, u.name AS assigned_name
        FROM crm_leads l
        LEFT JOIN users u ON u.id = l.assigned_to
        WHERE l.follow_up_date IS NOT NULL
          AND l.follow_up_date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)
          AND l.stage NOT IN ('lost','delivered') {$scopeJoin}
        ORDER BY l.follow_up_date ASC
        LIMIT 10
    ")->fetchAll();

    // Recent activities (scoped: CRM agents see only their own)
    $actScopeWhere = $isCrmAgent ? "AND a.created_by = {$uid}" : '';
    $recentActivities = $db->query("
        SELECT a.*, l.name AS lead_name, u.name AS by_name
        FROM crm_activities a
        LEFT JOIN crm_leads l ON l.id = a.lead_id
        LEFT JOIN users u ON u.id = a.created_by
        WHERE 1 {$actScopeWhere}
        ORDER BY a.created_at DESC
        LIMIT 8
    ")->fetchAll();

    $kanban = [];
    foreach ($leads as $l) $kanban[$l['stage']][] = $l;

} catch (\Throwable $e) {
    $stats = ['total'=>0,'active'=>0,'won'=>0,'lost'=>0,'won_value'=>0,'overdue_followups'=>0];
    $stageMap = []; $kanban = []; $followUps = []; $recentActivities = [];
}

$sourceLabels = [
    'walk_in'   => 'Walk-in',  'referral' => 'Referral',
    'facebook'  => 'Facebook', 'instagram'=> 'Instagram',
    'website'   => 'Website',  'phone_call'=> 'Phone Call',
    'whatsapp'  => 'WhatsApp', 'other'    => 'Other',
];

$activityIcons = [
    'call'      => ['fa-phone',       'text-success'],
    'whatsapp'  => ['fa-whatsapp',    'text-success'],
    'email'     => ['fa-envelope',    'text-primary'],
    'visit'     => ['fa-location-dot','text-warning'],
    'test_drive'=> ['fa-car-side',    'text-purple'],
    'meeting'   => ['fa-users',       'text-info'],
    'note'      => ['fa-note-sticky', 'text-secondary'],
];

include __DIR__ . '/../../includes/header.php';
?>
<style>
.kanban-board { display:flex; gap:12px; overflow-x:auto; padding-bottom:12px; }
.kanban-col   { min-width:230px; max-width:230px; display:flex; flex-direction:column; }
.kanban-hdr   { padding:8px 12px; border-radius:8px 8px 0 0; font-size:12.5px; font-weight:700; display:flex; justify-content:space-between; align-items:center; }
.kanban-body  { flex:1; min-height:120px; padding:6px; border-radius:0 0 8px 8px; border:1px solid #e9edf3; border-top:none; background:#f8fafc; }
.lead-card    { background:#fff; border-radius:8px; padding:10px 11px; margin-bottom:7px; box-shadow:0 1px 3px rgba(0,0,0,.07); font-size:13px; cursor:pointer; transition:box-shadow .15s; border-left:3px solid transparent; }
.lead-card:hover { box-shadow:0 3px 8px rgba(0,0,0,.12); }
.lead-card.overdue { border-left-color:#dc2626; }
.lead-card.soon    { border-left-color:#f59e0b; }
</style>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="mb-0">
        <i class="fa fa-funnel-dollar me-2 text-primary"></i>
        <?= $isCrmAgent ? 'My Sales Pipeline' : 'Sales Pipeline' ?>
    </h5>
    <div class="d-flex gap-2">
        <?php if ($isCrmAgent): ?>
        <a href="my_dashboard.php" class="btn btn-sm btn-outline-secondary"><i class="fa fa-gauge-high me-1"></i>Dashboard</a>
        <?php endif; ?>
        <a href="leads.php" class="btn btn-sm btn-outline-secondary"><i class="fa fa-list me-1"></i><?= $isCrmAgent ? 'My Leads' : 'All Leads' ?></a>
        <?php if (canWrite('crm')): ?>
        <a href="add_lead.php" class="btn btn-sm btn-primary"><i class="fa fa-plus me-1"></i>New Lead</a>
        <?php endif; ?>
    </div>
</div>

<!-- Stats row -->
<div class="row g-3 mb-4">
    <div class="col-6 col-xl-3">
        <div class="stat-card" style="border-left:4px solid #2563eb">
            <div class="stat-icon" style="background:#dbeafe;color:#2563eb"><i class="fa fa-user-group"></i></div>
            <div class="stat-info">
                <div class="stat-label">Active Leads</div>
                <div class="stat-value"><?= (int)$stats['active'] ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-xl-3">
        <div class="stat-card" style="border-left:4px solid #16a34a">
            <div class="stat-icon" style="background:#dcfce7;color:#16a34a"><i class="fa fa-circle-check"></i></div>
            <div class="stat-info">
                <div class="stat-label">Delivered</div>
                <div class="stat-value"><?= (int)$stats['won'] ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-xl-3">
        <div class="stat-card" style="border-left:4px solid #9333ea">
            <div class="stat-icon" style="background:#f3e8ff;color:#9333ea"><i class="fa fa-chart-line"></i></div>
            <div class="stat-info">
                <div class="stat-label">Pipeline Value</div>
                <div class="stat-value stat-value-sm"><?= money(array_sum(array_column($stageMap, 'total_budget'))) ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-xl-3">
        <?php $overdueColor = $stats['overdue_followups'] > 0 ? '#dc2626' : '#16a34a'; ?>
        <div class="stat-card" style="border-left:4px solid <?= $overdueColor ?>">
            <div class="stat-icon" style="background:<?= $stats['overdue_followups'] > 0 ? '#fee2e2' : '#dcfce7' ?>;color:<?= $overdueColor ?>">
                <i class="fa fa-calendar-exclamation"></i>
            </div>
            <div class="stat-info">
                <div class="stat-label">Overdue Follow-ups</div>
                <div class="stat-value" style="color:<?= $overdueColor ?>"><?= (int)$stats['overdue_followups'] ?></div>
            </div>
        </div>
    </div>
</div>

<!-- Kanban pipeline (active stages only) -->
<div class="card mb-4">
    <div class="card-header fw-semibold"><i class="fa fa-columns me-2"></i>Pipeline Board</div>
    <div class="card-body" style="overflow-x:auto">
        <div class="kanban-board">
        <?php
        $activeStages = array_filter($stages, fn($k) => !in_array($k, ['lost','delivered']), ARRAY_FILTER_USE_KEY);
        foreach ($activeStages as $stageKey => $stageCfg):
            $colLeads = $kanban[$stageKey] ?? [];
            $colCount = count($colLeads);
        ?>
        <div class="kanban-col">
            <div class="kanban-hdr" style="background:<?= $stageCfg['bg'] ?>;color:<?= $stageCfg['color'] ?>">
                <span><i class="fa <?= $stageCfg['icon'] ?> me-1"></i><?= $stageCfg['label'] ?></span>
                <span class="badge" style="background:<?= $stageCfg['color'] ?>;color:#fff"><?= $colCount ?></span>
            </div>
            <div class="kanban-body">
                <?php foreach ($colLeads as $lead):
                    $isOverdue = $lead['follow_up_date'] && $lead['follow_up_date'] < date('Y-m-d');
                    $isSoon    = !$isOverdue && $lead['follow_up_date'] && $lead['follow_up_date'] <= date('Y-m-d', strtotime('+2 days'));
                ?>
                <div class="lead-card <?= $isOverdue ? 'overdue' : ($isSoon ? 'soon' : '') ?>"
                     onclick="location.href='view_lead.php?id=<?= $lead['id'] ?>'">
                    <div class="fw-semibold mb-1" style="font-size:13px"><?= e($lead['name']) ?></div>
                    <?php if ($lead['phone']): ?>
                    <div class="text-muted mb-1" style="font-size:11px"><i class="fa fa-phone me-1"></i><?= e($lead['phone']) ?></div>
                    <?php endif; ?>
                    <?php if ($lead['interested_in']): ?>
                    <div class="text-muted" style="font-size:11px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">
                        <i class="fa fa-car me-1"></i><?= e($lead['interested_in']) ?>
                    </div>
                    <?php endif; ?>
                    <div class="d-flex justify-content-between align-items-center mt-2">
                        <?php if ($lead['budget']): ?>
                        <span class="badge bg-success-subtle text-success border border-success-subtle" style="font-size:10px"><?= money((float)$lead['budget']) ?></span>
                        <?php else: ?>
                        <span></span>
                        <?php endif; ?>
                        <?php if ($lead['follow_up_date']): ?>
                        <span class="badge <?= $isOverdue ? 'bg-danger' : ($isSoon ? 'bg-warning text-dark' : 'bg-light text-dark border') ?>" style="font-size:10px">
                            <i class="fa fa-calendar me-1"></i><?= fmtDate($lead['follow_up_date'], 'd M') ?>
                        </span>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php if (!$colLeads): ?>
                <div class="text-center text-muted py-3" style="font-size:12px">No leads</div>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
        </div>
    </div>
</div>

<div class="row g-4">
    <!-- Upcoming follow-ups -->
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header fw-semibold d-flex justify-content-between align-items-center">
                <span><i class="fa fa-calendar-check me-2 text-warning"></i>Upcoming Follow-ups</span>
                <a href="leads.php" class="btn btn-xs btn-outline-secondary">View all</a>
            </div>
            <?php if (empty($followUps)): ?>
            <div class="card-body text-center text-muted py-4">
                <i class="fa fa-calendar-check fa-2x mb-2 d-block opacity-25"></i>No follow-ups due this week
            </div>
            <?php else: ?>
            <div class="list-group list-group-flush">
                <?php foreach ($followUps as $f):
                    $isOverdue = $f['follow_up_date'] < date('Y-m-d');
                    $isToday   = $f['follow_up_date'] === date('Y-m-d');
                    $stg = $stages[$f['stage']] ?? $stages['hot'];
                ?>
                <a href="view_lead.php?id=<?= $f['id'] ?>"
                   class="list-group-item list-group-item-action px-3 py-2 d-flex align-items-center gap-3">
                    <div class="flex-shrink-0">
                        <span class="badge rounded-pill <?= $isOverdue ? 'bg-danger' : ($isToday ? 'bg-warning text-dark' : 'bg-primary') ?>" style="font-size:10px">
                            <?= $isOverdue ? 'Overdue' : ($isToday ? 'Today' : fmtDate($f['follow_up_date'], 'd M')) ?>
                        </span>
                    </div>
                    <div class="flex-grow-1 min-w-0">
                        <div class="fw-semibold" style="font-size:13px"><?= e($f['name']) ?></div>
                        <div class="text-muted small">
                            <span style="color:<?= $stg['color'] ?>"><?= $stg['label'] ?></span>
                            <?= $f['phone'] ? ' · ' . e($f['phone']) : '' ?>
                        </div>
                    </div>
                    <i class="fa fa-chevron-right text-muted" style="font-size:11px"></i>
                </a>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Recent activity -->
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header fw-semibold">
                <i class="fa fa-clock-rotate-left me-2 text-primary"></i>Recent Activity
            </div>
            <?php if (empty($recentActivities)): ?>
            <div class="card-body text-center text-muted py-4">
                <i class="fa fa-clipboard fa-2x mb-2 d-block opacity-25"></i>No activity logged yet
            </div>
            <?php else: ?>
            <div class="list-group list-group-flush">
                <?php foreach ($recentActivities as $act):
                    [$aIcon, $aColor] = $activityIcons[$act['type']] ?? ['fa-note-sticky','text-secondary'];
                ?>
                <div class="list-group-item px-3 py-2 d-flex gap-3 align-items-start">
                    <div class="flex-shrink-0 mt-1">
                        <i class="fa <?= $aIcon ?> <?= $aColor ?>"></i>
                    </div>
                    <div class="flex-grow-1">
                        <div style="font-size:13px">
                            <span class="fw-medium"><?= e($act['lead_name'] ?? '—') ?></span>
                            <span class="text-muted"> — <?= e($act['summary']) ?></span>
                        </div>
                        <div class="text-muted small">
                            <?= e($act['by_name'] ?? 'System') ?> · <?= fmtDate($act['created_at'], 'd M, H:i') ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>

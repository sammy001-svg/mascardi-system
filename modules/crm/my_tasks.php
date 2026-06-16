<?php
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
canAccess('crm') || redirect(BASE_URL . '/index.php');

$pageTitle = 'My Tasks';
$db  = getDB();
$me  = authUser();
$uid = (int)$me['id'];

$isCrmAgent = ($me['role'] === 'customer_relations');

// ── POST handlers ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && canWrite('crm')) {
    $action = $_POST['action'] ?? '';

    if ($action === 'reschedule') {
        $leadId       = (int)($_POST['lead_id']       ?? 0);
        $followUpDate = trim($_POST['follow_up_date'] ?? '');
        if ($leadId && $followUpDate) {
            try {
                if ($isCrmAgent) {
                    $stmt = $db->prepare("UPDATE crm_leads SET follow_up_date = ?, updated_at = NOW() WHERE id = ? AND assigned_to = ?");
                    $stmt->execute([$followUpDate, $leadId, $uid]);
                } else {
                    $stmt = $db->prepare("UPDATE crm_leads SET follow_up_date = ?, updated_at = NOW() WHERE id = ?");
                    $stmt->execute([$followUpDate, $leadId]);
                }
                setFlash('success', 'Follow-up date rescheduled.');
            } catch (\Throwable $e) {
                setFlash('error', 'Could not reschedule follow-up.');
            }
        }
        redirect(BASE_URL . '/modules/crm/my_tasks.php' . (isset($_POST['tab']) ? '?tab=' . urlencode($_POST['tab']) : ''));
    }

    if ($action === 'clear_followup') {
        $leadId = (int)($_POST['lead_id'] ?? 0);
        if ($leadId) {
            try {
                if ($isCrmAgent) {
                    $stmt = $db->prepare("UPDATE crm_leads SET follow_up_date = NULL, updated_at = NOW() WHERE id = ? AND assigned_to = ?");
                    $stmt->execute([$leadId, $uid]);
                } else {
                    $stmt = $db->prepare("UPDATE crm_leads SET follow_up_date = NULL, updated_at = NOW() WHERE id = ?");
                    $stmt->execute([$leadId]);
                }
                setFlash('success', 'Follow-up cleared.');
            } catch (\Throwable $e) {
                setFlash('error', 'Could not clear follow-up.');
            }
        }
        redirect(BASE_URL . '/modules/crm/my_tasks.php' . (isset($_POST['tab']) ? '?tab=' . urlencode($_POST['tab']) : ''));
    }

    if ($action === 'log_activity') {
        $leadId      = (int)($_POST['lead_id']       ?? 0);
        $type        = $_POST['type']                ?? 'note';
        $summary     = trim($_POST['summary']        ?? '');
        $outcome     = trim($_POST['outcome']        ?? '') ?: null;
        $newFollowUp = trim($_POST['follow_up_date'] ?? '') ?: null;

        $allowedTypes = ['call', 'whatsapp', 'email', 'visit', 'test_drive', 'meeting', 'note'];
        if (!in_array($type, $allowedTypes)) $type = 'note';

        if ($leadId && $summary) {
            try {
                $db->prepare("
                    INSERT INTO crm_activities (lead_id, type, summary, outcome, follow_up_date, created_by)
                    VALUES (?, ?, ?, ?, ?, ?)
                ")->execute([$leadId, $type, $summary, $outcome, $newFollowUp, $uid]);

                if ($newFollowUp) {
                    if ($isCrmAgent) {
                        $db->prepare("UPDATE crm_leads SET follow_up_date = ?, updated_at = NOW() WHERE id = ? AND assigned_to = ?")
                           ->execute([$newFollowUp, $leadId, $uid]);
                    } else {
                        $db->prepare("UPDATE crm_leads SET follow_up_date = ?, updated_at = NOW() WHERE id = ?")
                           ->execute([$newFollowUp, $leadId]);
                    }
                }
                setFlash('success', 'Activity logged successfully.');
            } catch (\Throwable $e) {
                setFlash('error', 'Could not log activity.');
            }
        }
        redirect(BASE_URL . '/modules/crm/my_tasks.php' . (isset($_POST['tab']) ? '?tab=' . urlencode($_POST['tab']) : ''));
    }
}

// ── Date boundaries ───────────────────────────────────────────────────────────
$today    = date('Y-m-d');
$tomorrow = date('Y-m-d', strtotime('+1 day'));
$weekEnd  = date('Y-m-d', strtotime('+7 days'));
$upcoming = date('Y-m-d', strtotime('+14 days'));

// ── Data isolation ────────────────────────────────────────────────────────────
$ownerWhere = $isCrmAgent ? "AND l.assigned_to = {$uid}" : '';

// ── Load all due/upcoming leads ───────────────────────────────────────────────
try {
    $leads = $db->query("
        SELECT l.*, u.name AS assigned_name
        FROM crm_leads l
        LEFT JOIN users u ON u.id = l.assigned_to
        WHERE l.follow_up_date IS NOT NULL
          AND l.follow_up_date <= '{$upcoming}'
          AND l.stage NOT IN ('lost', 'delivered')
          {$ownerWhere}
        ORDER BY l.follow_up_date ASC, l.name ASC
    ")->fetchAll();
} catch (\Throwable $e) {
    $leads = [];
}

// ── Group leads into categories ───────────────────────────────────────────────
$groups = [
    'overdue'   => [],
    'today'     => [],
    'tomorrow'  => [],
    'this_week' => [],
    'upcoming'  => [],
];

foreach ($leads as $lead) {
    $fd = $lead['follow_up_date'];
    if ($fd < $today)          $groups['overdue'][]   = $lead;
    elseif ($fd === $today)    $groups['today'][]     = $lead;
    elseif ($fd === $tomorrow) $groups['tomorrow'][]  = $lead;
    elseif ($fd <= $weekEnd)   $groups['this_week'][] = $lead;
    else                       $groups['upcoming'][]  = $lead;
}

$countAll      = count($leads);
$countOverdue  = count($groups['overdue']);
$countToday    = count($groups['today']);
$countTomorrow = count($groups['tomorrow']);
$countWeek     = count($groups['this_week']);
$countUpcoming = count($groups['upcoming']);

// ── Stale leads (no activity in 7+ days, not closed) ─────────────────────────
try {
    $staleLeads = $db->query("
        SELECT l.*, u.name AS assigned_name
        FROM crm_leads l
        LEFT JOIN users u ON u.id = l.assigned_to
        WHERE l.stage NOT IN ('lost','delivered')
          AND l.created_at < DATE_SUB(NOW(), INTERVAL 7 DAY)
          {$ownerWhere}
          AND NOT EXISTS (
              SELECT 1 FROM crm_activities a
              WHERE a.lead_id = l.id
                AND a.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
          )
        ORDER BY l.updated_at ASC
    ")->fetchAll();
} catch (\Throwable $_) { $staleLeads = []; }

$countStale = count($staleLeads);

// For stale tab: fetch last activity date per lead
$lastActivityDates = [];
if ($staleLeads) {
    $ids  = implode(',', array_column($staleLeads, 'id'));
    $rows = $db->query("SELECT lead_id, MAX(created_at) AS last_at FROM crm_activities WHERE lead_id IN ({$ids}) GROUP BY lead_id")->fetchAll();
    foreach ($rows as $r) $lastActivityDates[$r['lead_id']] = $r['last_at'];
}

// ── Active tab ────────────────────────────────────────────────────────────────
$activeTab    = $_GET['tab'] ?? 'all';
$allowedTabs  = ['all', 'overdue', 'today', 'tomorrow', 'this_week', 'upcoming', 'stale'];
if (!in_array($activeTab, $allowedTabs)) $activeTab = 'all';

$displayLeads = match ($activeTab) {
    'overdue'   => $groups['overdue'],
    'today'     => $groups['today'],
    'tomorrow'  => $groups['tomorrow'],
    'this_week' => $groups['this_week'],
    'upcoming'  => $groups['upcoming'],
    'stale'     => $staleLeads,
    default     => $leads,
};

$stages = [
    'hot'      => ['Hot',      'danger'],
    'lukewarm' => ['Lukewarm', 'warning'],
    'cold'     => ['Cold',     'info'],
    'reserved' => ['Reserved', 'purple'],
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

include __DIR__ . '/../../includes/header.php';
?>

<style>
.tasks-stat-card {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 14px;
    padding: 16px 20px;
    display: flex;
    align-items: center;
    gap: 14px;
    cursor: pointer;
    transition: box-shadow .2s, transform .15s;
    text-decoration: none;
    color: inherit;
}
.tasks-stat-card:hover { box-shadow: 0 6px 20px rgba(0,0,0,.08); transform: translateY(-2px); color: inherit; }
.tasks-stat-card.active-stat { box-shadow: 0 0 0 2px var(--stat-color); }
.tasks-stat-icon {
    width: 44px; height: 44px;
    border-radius: 11px;
    display: flex; align-items: center; justify-content: center;
    font-size: 18px; flex-shrink: 0;
}
.tasks-stat-label { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; color: var(--text-3); margin-bottom: 2px; }
.tasks-stat-value { font-size: 24px; font-weight: 800; line-height: 1; }
.fu-overdue  { color: #dc2626; background: #fee2e2; }
.fu-today    { color: #92400e; background: #fef3c7; }
.fu-soon     { color: #1e40af; background: #dbeafe; }
.fu-upcoming { color: #374151; background: #f3f4f6; }
</style>

<!-- Page header -->
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h5 class="mb-0">
        <i class="fa fa-calendar-check me-2 text-primary"></i>My Tasks &amp; Follow-up Reminders
        <?php if ($countAll > 0): ?>
        <span class="badge bg-secondary ms-1"><?= $countAll ?></span>
        <?php endif; ?>
    </h5>
    <div class="d-flex gap-2">
        <a href="my_dashboard.php" class="btn btn-sm btn-outline-secondary">
            <i class="fa fa-gauge me-1"></i>Dashboard
        </a>
        <a href="leads.php" class="btn btn-sm btn-outline-primary">
            <i class="fa fa-list me-1"></i>All Leads
        </a>
        <?php if (canWrite('crm')): ?>
        <a href="add_lead.php" class="btn btn-sm btn-primary">
            <i class="fa fa-plus me-1"></i>New Lead
        </a>
        <?php endif; ?>
    </div>
</div>

<?php if ($countOverdue > 0): ?>
<!-- Overdue alert strip -->
<div class="d-flex align-items-center gap-3 mb-4 px-4 py-3"
     style="background:#fef2f2;border:1px solid #fecaca;border-radius:12px">
    <i class="fa fa-triangle-exclamation fa-lg text-danger"></i>
    <div>
        <span class="fw-bold text-danger"><?= $countOverdue ?> overdue follow-up<?= $countOverdue !== 1 ? 's' : '' ?></span>
        <span class="text-danger ms-1">— these leads need immediate attention</span>
    </div>
    <a href="?tab=overdue" class="btn btn-sm ms-auto"
       style="background:#dc2626;color:#fff;border-radius:8px;font-size:12px;font-weight:700">
        View Overdue <i class="fa fa-arrow-right ms-1"></i>
    </a>
</div>
<?php endif; ?>

<!-- Stats row -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <a href="?tab=overdue" class="tasks-stat-card <?= $activeTab === 'overdue' ? 'active-stat' : '' ?>"
           style="--stat-color:#dc2626;border-top:3px solid #dc2626">
            <div class="tasks-stat-icon" style="background:#fee2e2;color:#dc2626">
                <i class="fa fa-circle-exclamation"></i>
            </div>
            <div>
                <div class="tasks-stat-label">Overdue</div>
                <div class="tasks-stat-value" style="color:#dc2626"><?= $countOverdue ?></div>
            </div>
        </a>
    </div>
    <div class="col-6 col-md-3">
        <a href="?tab=today" class="tasks-stat-card <?= $activeTab === 'today' ? 'active-stat' : '' ?>"
           style="--stat-color:#d97706;border-top:3px solid #d97706">
            <div class="tasks-stat-icon" style="background:#fef3c7;color:#d97706">
                <i class="fa fa-calendar-day"></i>
            </div>
            <div>
                <div class="tasks-stat-label">Due Today</div>
                <div class="tasks-stat-value" style="color:#d97706"><?= $countToday ?></div>
            </div>
        </a>
    </div>
    <div class="col-6 col-md-3">
        <a href="?tab=this_week" class="tasks-stat-card <?= $activeTab === 'this_week' ? 'active-stat' : '' ?>"
           style="--stat-color:#2563eb;border-top:3px solid #2563eb">
            <div class="tasks-stat-icon" style="background:#dbeafe;color:#2563eb">
                <i class="fa fa-calendar-week"></i>
            </div>
            <div>
                <div class="tasks-stat-label">This Week</div>
                <div class="tasks-stat-value" style="color:#2563eb"><?= $countWeek ?></div>
            </div>
        </a>
    </div>
    <div class="col-6 col-md-3">
        <a href="?tab=all" class="tasks-stat-card <?= $activeTab === 'all' ? 'active-stat' : '' ?>"
           style="--stat-color:#374151;border-top:3px solid #374151">
            <div class="tasks-stat-icon" style="background:#f3f4f6;color:#374151">
                <i class="fa fa-calendar-check"></i>
            </div>
            <div>
                <div class="tasks-stat-label">Total Due</div>
                <div class="tasks-stat-value"><?= $countAll ?></div>
            </div>
        </a>
    </div>
    <?php if ($countStale > 0): ?>
    <div class="col-6 col-md-3">
        <a href="?tab=stale" class="tasks-stat-card <?= $activeTab === 'stale' ? 'active-stat' : '' ?>"
           style="--stat-color:#f59e0b;border-top:3px solid #f59e0b">
            <div class="tasks-stat-icon" style="background:#fef3c7;color:#f59e0b">
                <i class="fa fa-hourglass-half"></i>
            </div>
            <div>
                <div class="tasks-stat-label">Going Cold</div>
                <div class="tasks-stat-value" style="color:#f59e0b"><?= $countStale ?></div>
            </div>
        </a>
    </div>
    <?php endif; ?>
</div>

<!-- Filter tabs + table card -->
<div class="card mb-0">
    <div class="card-header p-0 border-bottom-0">
        <ul class="nav nav-tabs card-header-tabs px-3 pt-2">
            <li class="nav-item">
                <a class="nav-link <?= $activeTab === 'all' ? 'active' : '' ?>" href="?tab=all">
                    All Due
                    <?php if ($countAll > 0): ?>
                    <span class="badge bg-secondary ms-1"><?= $countAll ?></span>
                    <?php endif; ?>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?= $activeTab === 'overdue' ? 'active' : '' ?>" href="?tab=overdue">
                    Overdue
                    <?php if ($countOverdue > 0): ?>
                    <span class="badge bg-danger ms-1"><?= $countOverdue ?></span>
                    <?php endif; ?>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?= $activeTab === 'today' ? 'active' : '' ?>" href="?tab=today">
                    Today
                    <?php if ($countToday > 0): ?>
                    <span class="badge bg-warning text-dark ms-1"><?= $countToday ?></span>
                    <?php endif; ?>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?= $activeTab === 'tomorrow' ? 'active' : '' ?>" href="?tab=tomorrow">
                    Tomorrow
                    <?php if ($countTomorrow > 0): ?>
                    <span class="badge bg-info text-dark ms-1"><?= $countTomorrow ?></span>
                    <?php endif; ?>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?= $activeTab === 'this_week' ? 'active' : '' ?>" href="?tab=this_week">
                    This Week
                    <?php if ($countWeek > 0): ?>
                    <span class="badge bg-primary ms-1"><?= $countWeek ?></span>
                    <?php endif; ?>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?= $activeTab === 'upcoming' ? 'active' : '' ?>" href="?tab=upcoming">
                    Upcoming
                    <?php if ($countUpcoming > 0): ?>
                    <span class="badge bg-secondary ms-1"><?= $countUpcoming ?></span>
                    <?php endif; ?>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?= $activeTab === 'stale' ? 'active' : '' ?>" href="?tab=stale">
                    <i class="fa fa-hourglass-half me-1 text-danger"></i>Going Cold
                    <?php if ($countStale): ?>
                    <span class="badge bg-danger ms-1"><?= $countStale ?></span>
                    <?php endif; ?>
                </a>
            </li>
        </ul>
    </div>

    <div class="card-body p-0">

        <?php if ($activeTab === 'stale'): ?>
        <?php if (empty($staleLeads)): ?>
        <!-- Empty state — stale -->
        <div class="text-center py-5 text-muted">
            <i class="fa fa-hourglass-half fa-3x mb-3 d-block opacity-50" style="color:#f59e0b"></i>
            <h6 class="fw-semibold mb-1">No cold leads!</h6>
            <p class="small mb-3">All your leads have recent activity — great work.</p>
            <a href="?tab=all" class="btn btn-sm btn-outline-secondary">View all tasks</a>
        </div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0" style="font-size:13.5px">
                <thead class="table-light">
                    <tr>
                        <th class="ps-3">Lead Name</th>
                        <th>Stage</th>
                        <th>Phone</th>
                        <th>Last Activity</th>
                        <th style="min-width:140px">Days Since Activity</th>
                        <th class="text-end pe-3" style="min-width:140px">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($staleLeads as $lead):
                    [$stageLabel, $stageColor] = $stages[$lead['stage']] ?? ['—', 'secondary'];
                    $lastAt   = $lastActivityDates[$lead['id']] ?? null;
                    $daysSince = $lastAt
                        ? (int)floor((time() - strtotime($lastAt)) / 86400)
                        : null;
                    $wNum = $wMsg = '';
                    if (!empty($lead['phone'])) {
                        $wNum = preg_replace('/[^0-9]/', '', $lead['phone']);
                        if (str_starts_with($lead['phone'], '0')) $wNum = '254' . substr($wNum, 1);
                        $wMsg = rawurlencode("Hello {$lead['name']}! Following up on your" . ($lead['interested_in'] ? " interest in the {$lead['interested_in']}" : ' enquiry') . ". Are you still looking?");
                    }
                ?>
                <tr>
                    <td class="ps-3">
                        <a href="view_lead.php?id=<?= $lead['id'] ?>"
                           class="fw-semibold text-decoration-none text-dark">
                            <?= e($lead['name']) ?>
                        </a>
                        <?php if (!$isCrmAgent && $lead['assigned_name']): ?>
                        <div class="text-muted" style="font-size:11px">
                            <i class="fa fa-user me-1"></i><?= e($lead['assigned_name']) ?>
                        </div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <span class="badge bg-<?= $stageColor ?>"><?= e($stageLabel) ?></span>
                    </td>
                    <td>
                        <?php if ($lead['phone']): ?>
                        <a href="tel:<?= e($lead['phone']) ?>" class="text-decoration-none text-dark small">
                            <?= e($lead['phone']) ?>
                        </a>
                        <?php if ($wNum): ?>
                        <a href="https://wa.me/<?= $wNum ?>?text=<?= $wMsg ?>" target="_blank"
                           class="btn btn-xs btn-success ms-1" title="WhatsApp" style="padding:2px 6px">
                            <i class="fab fa-whatsapp"></i>
                        </a>
                        <?php endif; ?>
                        <?php else: ?>
                        <span class="text-muted">—</span>
                        <?php endif; ?>
                    </td>
                    <td class="small text-muted">
                        <?= $lastAt ? fmtDate($lastAt, 'd M Y') : '<span class="text-muted fst-italic">None logged</span>' ?>
                    </td>
                    <td>
                        <?php if ($daysSince === null): ?>
                        <span class="badge" style="background:#f59e0b;color:#fff;font-size:11px;padding:4px 8px">
                            No activity logged
                        </span>
                        <?php else: ?>
                        <span class="badge" style="background:<?= $daysSince >= 14 ? '#dc2626' : '#f59e0b' ?>;color:#fff;font-size:11px;padding:4px 8px">
                            <i class="fa fa-hourglass-half me-1"></i><?= $daysSince ?>d no activity
                        </span>
                        <?php endif; ?>
                    </td>
                    <td class="text-end pe-3">
                        <div class="d-flex justify-content-end gap-1 flex-wrap">
                            <a href="view_lead.php?id=<?= $lead['id'] ?>"
                               class="btn btn-xs btn-outline-primary">
                                <i class="fa fa-eye me-1"></i>View
                            </a>
                            <?php if (canWrite('crm')): ?>
                            <button type="button" class="btn btn-xs btn-outline-success"
                                    onclick="openLogModal(<?= $lead['id'] ?>, <?= json_encode($lead['name']) ?>)">
                                <i class="fa fa-pen-to-square me-1"></i>Log
                            </button>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

        <?php else: /* regular follow-up tabs */ ?>

        <?php if (empty($displayLeads)): ?>
        <!-- Empty state -->
        <div class="text-center py-5 text-muted">
            <i class="fa fa-circle-check fa-3x text-success mb-3 d-block opacity-50"></i>
            <h6 class="fw-semibold mb-1">All clear!</h6>
            <p class="small mb-3">No follow-ups in this category.</p>
            <?php if ($activeTab !== 'all'): ?>
            <a href="?tab=all" class="btn btn-sm btn-outline-secondary">View all tasks</a>
            <?php else: ?>
            <a href="leads.php" class="btn btn-sm btn-outline-primary">Go to Leads</a>
            <?php endif; ?>
        </div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0" style="font-size:13.5px">
                <thead class="table-light">
                    <tr>
                        <th class="ps-3">Lead Name</th>
                        <th>Stage</th>
                        <th>Phone</th>
                        <th>Interested In</th>
                        <th>Follow-up Date</th>
                        <th style="min-width:80px">Days</th>
                        <th class="text-end pe-3" style="min-width:200px">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($displayLeads as $lead):
                    $fd       = $lead['follow_up_date'];
                    $diffDays = (int)round((strtotime($fd) - strtotime($today)) / 86400);
                    $isOverdue = $fd < $today;
                    $isToday   = $fd === $today;

                    if ($isOverdue) {
                        $daysLabel  = abs($diffDays) . 'd overdue';
                        $daysClass  = 'text-danger fw-semibold';
                        $badgeClass = 'fu-overdue';
                    } elseif ($isToday) {
                        $daysLabel  = 'Today';
                        $daysClass  = 'text-warning fw-bold';
                        $badgeClass = 'fu-today';
                    } elseif ($diffDays <= 7) {
                        $daysLabel  = 'in ' . $diffDays . 'd';
                        $daysClass  = 'text-primary fw-semibold';
                        $badgeClass = 'fu-soon';
                    } else {
                        $daysLabel  = 'in ' . $diffDays . 'd';
                        $daysClass  = 'text-secondary';
                        $badgeClass = 'fu-upcoming';
                    }

                    [$stageLabel, $stageColor] = $stages[$lead['stage']] ?? ['—', 'secondary'];
                ?>
                <tr>
                    <td class="ps-3">
                        <a href="view_lead.php?id=<?= $lead['id'] ?>"
                           class="fw-semibold text-decoration-none text-dark">
                            <?= e($lead['name']) ?>
                        </a>
                        <?php if (!$isCrmAgent && $lead['assigned_name']): ?>
                        <div class="text-muted" style="font-size:11px">
                            <i class="fa fa-user me-1"></i><?= e($lead['assigned_name']) ?>
                        </div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <span class="badge bg-<?= $stageColor ?>"><?= e($stageLabel) ?></span>
                    </td>
                    <td>
                        <?php if ($lead['phone']): ?>
                        <a href="tel:<?= e($lead['phone']) ?>" class="text-decoration-none text-dark">
                            <?= e($lead['phone']) ?>
                        </a>
                        <?php else: ?>
                        <span class="text-muted">—</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($lead['interested_in']): ?>
                        <span class="text-truncate d-inline-block" style="max-width:160px"
                              title="<?= e($lead['interested_in']) ?>">
                            <?= e($lead['interested_in']) ?>
                        </span>
                        <?php else: ?>
                        <span class="text-muted">—</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <span class="badge <?= $badgeClass ?>"
                              style="font-size:11.5px;padding:4px 10px;border-radius:20px">
                            <i class="fa fa-calendar me-1"></i><?= fmtDate($fd, 'd M Y') ?>
                        </span>
                    </td>
                    <td>
                        <span class="<?= $daysClass ?>" style="font-size:12px">
                            <?= $daysLabel ?>
                        </span>
                    </td>
                    <td class="text-end pe-3">
                        <div class="d-flex justify-content-end gap-1 flex-wrap">
                            <a href="view_lead.php?id=<?= $lead['id'] ?>"
                               class="btn btn-xs btn-outline-primary">
                                <i class="fa fa-eye me-1"></i>View
                            </a>
                            <?php if ($lead['phone']): ?>
                            <?php
                              $wNum = preg_replace('/[^0-9]/', '', $lead['phone']);
                              if (str_starts_with($lead['phone'], '0')) $wNum = '254' . substr($wNum, 1);
                              $wMsg = rawurlencode("Hello {$lead['name']}! Following up on your" . ($lead['interested_in'] ? " interest in the {$lead['interested_in']}" : ' enquiry') . ". Are you still looking?");
                            ?>
                            <a href="https://wa.me/<?= $wNum ?>?text=<?= $wMsg ?>" target="_blank"
                               class="btn btn-xs btn-success" title="WhatsApp" style="padding:2px 6px">
                                <i class="fab fa-whatsapp"></i>
                            </a>
                            <?php endif; ?>
                            <?php if (canWrite('crm')): ?>
                            <button type="button" class="btn btn-xs btn-outline-success"
                                    onclick="openLogModal(<?= $lead['id'] ?>, <?= json_encode($lead['name']) ?>)">
                                <i class="fa fa-pen-to-square me-1"></i>Log
                            </button>
                            <button type="button" class="btn btn-xs btn-outline-warning"
                                    onclick="openRescheduleModal(<?= $lead['id'] ?>, <?= json_encode($lead['name']) ?>, '<?= e($fd) ?>')">
                                <i class="fa fa-calendar-pen me-1"></i>Reschedule
                            </button>
                            <form method="POST" class="d-inline"
                                  onsubmit="return confirm('Clear follow-up date for <?= e(addslashes($lead['name'])) ?>?')">
                                <input type="hidden" name="action"  value="clear_followup">
                                <input type="hidden" name="lead_id" value="<?= $lead['id'] ?>">
                                <input type="hidden" name="tab"     value="<?= e($activeTab) ?>">
                                <button class="btn btn-xs btn-outline-secondary" title="Clear follow-up">
                                    <i class="fa fa-xmark"></i>
                                </button>
                            </form>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

        <?php endif; /* end stale/regular branch */ ?>
    </div>
</div>

<?php if (canWrite('crm')): ?>

<!-- ── Log Activity Modal ──────────────────────────────────────────────────── -->
<div class="modal fade" id="logModal" tabindex="-1" aria-labelledby="logModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST" id="logForm">
                <input type="hidden" name="action"  value="log_activity">
                <input type="hidden" name="lead_id" id="logLeadId" value="">
                <input type="hidden" name="tab"     value="<?= e($activeTab) ?>">
                <div class="modal-header">
                    <h6 class="modal-title fw-bold" id="logModalLabel">
                        <i class="fa fa-pen-to-square me-2 text-success"></i>Log Activity
                        <span id="logLeadName" class="fw-normal text-muted ms-1" style="font-size:13px"></span>
                    </h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label fw-semibold small">Activity Type</label>
                            <select name="type" class="form-select form-select-sm">
                                <?php foreach ($activityTypes as $k => $lbl): ?>
                                <option value="<?= $k ?>"><?= $lbl ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-8">
                            <label class="form-label fw-semibold small">
                                Summary <span class="text-danger">*</span>
                            </label>
                            <input type="text" name="summary" class="form-control form-control-sm" required
                                   placeholder="e.g. Called client, discussed pricing on Land Cruiser…">
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold small">Outcome / Notes</label>
                            <textarea name="outcome" class="form-control form-control-sm" rows="3"
                                      placeholder="What was the outcome? Any commitments or next steps?"></textarea>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold small">New Follow-up Date</label>
                            <input type="date" name="follow_up_date" class="form-control form-control-sm"
                                   min="<?= $today ?>">
                            <div class="form-text">Leave blank to keep the existing date.</div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-sm btn-success">
                        <i class="fa fa-check me-1"></i>Log Activity
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ── Reschedule Modal ────────────────────────────────────────────────────── -->
<div class="modal fade" id="rescheduleModal" tabindex="-1" aria-labelledby="rescheduleModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <form method="POST" id="rescheduleForm">
                <input type="hidden" name="action"  value="reschedule">
                <input type="hidden" name="lead_id" id="rescheduleLeadId" value="">
                <input type="hidden" name="tab"     value="<?= e($activeTab) ?>">
                <div class="modal-header py-2">
                    <h6 class="modal-title fw-bold" id="rescheduleModalLabel">
                        <i class="fa fa-calendar-pen me-2 text-warning"></i>Reschedule Follow-up
                    </h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p class="small text-muted mb-3" id="rescheduleLeadName"></p>
                    <label class="form-label fw-semibold small">
                        New Follow-up Date <span class="text-danger">*</span>
                    </label>
                    <input type="date" name="follow_up_date" id="rescheduleDateInput"
                           class="form-control form-control-sm" required min="<?= $today ?>">
                </div>
                <div class="modal-footer py-2">
                    <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-sm btn-warning">
                        <i class="fa fa-calendar-check me-1"></i>Reschedule
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function openLogModal(leadId, leadName) {
    document.getElementById('logLeadId').value = leadId;
    document.getElementById('logLeadName').textContent = '— ' + leadName;
    // Reset form fields
    document.getElementById('logForm').querySelector('[name="summary"]').value = '';
    document.getElementById('logForm').querySelector('[name="outcome"]').value = '';
    document.getElementById('logForm').querySelector('[name="follow_up_date"]').value = '';
    new bootstrap.Modal(document.getElementById('logModal')).show();
}

function openRescheduleModal(leadId, leadName, currentDate) {
    document.getElementById('rescheduleLeadId').value    = leadId;
    document.getElementById('rescheduleLeadName').textContent = leadName;
    document.getElementById('rescheduleDateInput').value = currentDate;
    new bootstrap.Modal(document.getElementById('rescheduleModal')).show();
}
</script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    ['logModal', 'rescheduleModal'].forEach(function (id) {
        var el = document.getElementById(id);
        if (el && el.parentNode !== document.body) document.body.appendChild(el);
    });
});
</script>

<?php endif; ?>

<?php include __DIR__ . '/../../includes/footer.php'; ?>

<?php
/**
 * Meetings — list and overview.
 */
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/_bootstrap.php';
requireLogin();
canAccess('meetings') || redirect(BASE_URL . '/index.php');

$db = getDB();
meetingsMigrate($db);
meetingAutoStatus($db);

$me      = authUser();
$meId    = (int)$me['id'];
$canMake = canWrite('meetings');

$tab    = $_GET['tab'] ?? 'upcoming';
if (!in_array($tab, ['upcoming','mine','past','all'], true)) $tab = 'upcoming';
$search = trim($_GET['q'] ?? '');

// Only meetings the viewer is entitled to. Management and the super admin see
// everything; everyone else sees what they organised or were invited to.
$seesAll = isSuperAdmin() || hasRole(['general_manager','manager']);
$scope   = $seesAll ? '' : " AND (m.organiser_id = :me OR m.created_by = :me2
                                  OR EXISTS (SELECT 1 FROM meeting_participants p
                                             WHERE p.meeting_id = m.id AND p.user_id = :me3))";

$where = ['1=1'];
switch ($tab) {
    case 'upcoming': $where[] = "m.status IN ('scheduled','in_progress')"; break;
    case 'past':     $where[] = "m.status IN ('completed','cancelled')";   break;
    case 'mine':     $where[] = "(m.organiser_id = :orgme OR EXISTS (SELECT 1 FROM meeting_participants p2
                                   WHERE p2.meeting_id = m.id AND p2.user_id = :partme))"; break;
}
if ($search !== '') $where[] = "(m.title LIKE :s OR m.purpose LIKE :s2 OR m.venue LIKE :s3)";

$sql = "SELECT m.*, u.name AS organiser_name,
               (SELECT COUNT(*) FROM meeting_participants p WHERE p.meeting_id = m.id) AS participant_count,
               (SELECT COUNT(*) FROM meeting_agenda_items a WHERE a.meeting_id = m.id) AS agenda_count,
               (SELECT COUNT(*) FROM meeting_actions x WHERE x.meeting_id = m.id) AS action_count,
               (SELECT COUNT(*) FROM meeting_actions x WHERE x.meeting_id = m.id AND x.status = 'done') AS action_done
        FROM meetings m
        LEFT JOIN users u ON u.id = m.organiser_id
        WHERE " . implode(' AND ', $where) . $scope . "
        ORDER BY " . ($tab === 'past' ? 'm.scheduled_start DESC' : 'm.scheduled_start ASC') . "
        LIMIT 200";

$meetings = [];
try {
    $st = $db->prepare($sql);
    if (!$seesAll) { $st->bindValue(':me', $meId); $st->bindValue(':me2', $meId); $st->bindValue(':me3', $meId); }
    if ($tab === 'mine') { $st->bindValue(':orgme', $meId); $st->bindValue(':partme', $meId); }
    if ($search !== '') {
        $like = "%{$search}%";
        $st->bindValue(':s', $like); $st->bindValue(':s2', $like); $st->bindValue(':s3', $like);
    }
    $st->execute();
    $meetings = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (\Throwable $e) { error_log('meetings/index: ' . $e->getMessage()); }

// ── Headline numbers ─────────────────────────────────────────────────────────
$stats = ['upcoming' => 0, 'today' => 0, 'my_actions' => 0, 'overdue' => 0];
try {
    $vis = $seesAll ? '' : " AND (m.organiser_id = {$meId} OR EXISTS (SELECT 1 FROM meeting_participants p
                                  WHERE p.meeting_id = m.id AND p.user_id = {$meId}))";
    $stats['upcoming'] = (int)$db->query("SELECT COUNT(*) FROM meetings m
        WHERE m.status IN ('scheduled','in_progress') AND m.scheduled_start >= NOW(){$vis}")->fetchColumn();
    $stats['today'] = (int)$db->query("SELECT COUNT(*) FROM meetings m
        WHERE DATE(m.scheduled_start) = CURDATE() AND m.status <> 'cancelled'{$vis}")->fetchColumn();

    $s = $db->prepare("SELECT COUNT(*) FROM meeting_actions
                       WHERE assigned_to = ? AND status IN ('pending','in_progress','blocked')");
    $s->execute([$meId]); $stats['my_actions'] = (int)$s->fetchColumn();

    $s = $db->prepare("SELECT COUNT(*) FROM meeting_actions
                       WHERE assigned_to = ? AND status IN ('pending','in_progress','blocked')
                         AND due_date IS NOT NULL AND due_date < CURDATE()");
    $s->execute([$meId]); $stats['overdue'] = (int)$s->fetchColumn();
} catch (\Throwable $_) {}

// What this person owes, so it is visible without hunting through meetings.
$myActions = [];
try {
    $s = $db->prepare("SELECT a.*, m.title AS meeting_title
                       FROM meeting_actions a JOIN meetings m ON m.id = a.meeting_id
                       WHERE a.assigned_to = ? AND a.status IN ('pending','in_progress','blocked')
                       ORDER BY (a.due_date IS NULL), a.due_date ASC LIMIT 6");
    $s->execute([$meId]);
    $myActions = $s->fetchAll(PDO::FETCH_ASSOC);
} catch (\Throwable $_) {}

$pageTitle = 'Meetings';
include __DIR__ . '/../../includes/header.php';
?>
<style>
.mt-head{ display:flex; justify-content:space-between; align-items:center; gap:14px; flex-wrap:wrap; margin-bottom:16px; }
.mt-head h1{ font-size:21px; font-weight:800; letter-spacing:-.4px; margin:0; color:var(--text); }
.mt-head p{ font-size:13px; color:var(--text-2,#64748b); margin:2px 0 0; }

.mt-stats{ display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:12px; margin-bottom:16px; }
@media(max-width:768px){ .mt-stats{ grid-template-columns:repeat(2,minmax(0,1fr)); } }
.mt-stat{ background:var(--surface); border:1px solid var(--border); border-radius:var(--r-lg); padding:14px 16px; box-shadow:var(--sh-sm); }
.mt-stat-v{ font-size:22px; font-weight:900; line-height:1.1; color:var(--text); }
.mt-stat-l{ font-size:10.5px; text-transform:uppercase; letter-spacing:.6px; color:var(--text-2,#64748b); margin-top:5px; }

.mt-tabs{ display:flex; gap:8px; flex-wrap:wrap; margin-bottom:16px; }
.mt-tab{ padding:7px 15px; border-radius:20px; font-size:12.5px; font-weight:600; text-decoration:none;
    border:1px solid var(--border); background:var(--surface); color:var(--text); transition:.12s; }
.mt-tab:hover{ border-color:var(--brand); color:var(--brand); }
.mt-tab.on{ background:var(--brand); border-color:var(--brand); color:#fff; }

.mt-card{ background:var(--surface); border:1px solid var(--border); border-radius:var(--r-lg); box-shadow:var(--sh-sm); overflow:hidden; margin-bottom:16px; }
.mt-card-head{ padding:12px 16px; border-bottom:1px solid var(--border); background:var(--surface-alt);
    display:flex; align-items:center; justify-content:space-between; gap:10px; }
.mt-card-title{ font-size:13.5px; font-weight:700; color:var(--text); margin:0; display:flex; align-items:center; gap:8px; }
.mt-card-title i{ color:var(--brand); }

.mt-row{ display:flex; gap:14px; padding:14px 16px; border-bottom:1px solid var(--border); text-decoration:none; color:var(--text); }
.mt-row:last-child{ border-bottom:0; }
.mt-row:hover{ background:var(--surface-alt); color:var(--text); }
.mt-when{ flex:0 0 62px; text-align:center; border-right:1px solid var(--border); padding-right:12px; }
.mt-when .d{ font-size:20px; font-weight:800; line-height:1; color:var(--text); }
.mt-when .m{ font-size:10.5px; text-transform:uppercase; letter-spacing:.6px; color:var(--text-2,#64748b); margin-top:3px; }
.mt-when .t{ font-size:11px; color:var(--brand); font-weight:700; margin-top:4px; }
.mt-body{ flex:1; min-width:0; }
.mt-title{ font-size:14.5px; font-weight:700; color:var(--text); }
.mt-meta{ font-size:12px; color:var(--text-2,#64748b); margin-top:3px; display:flex; gap:12px; flex-wrap:wrap; }
.mt-meta i{ margin-right:4px; opacity:.75; }
.mt-tag{ font-size:10.5px; font-weight:700; padding:3px 10px; border-radius:20px; white-space:nowrap; }
.mt-side{ display:flex; flex-direction:column; align-items:flex-end; gap:6px; }
.mt-prog{ width:88px; height:6px; border-radius:4px; background:var(--surface-alt); border:1px solid var(--border); overflow:hidden; }
.mt-prog > i{ display:block; height:100%; background:#16a34a; }
.mt-empty{ text-align:center; padding:44px 20px; color:var(--text-2,#64748b); }
.mt-empty i{ font-size:34px; opacity:.3; display:block; margin-bottom:12px; }
.mt-act{ display:flex; justify-content:space-between; align-items:center; gap:10px; padding:10px 16px; border-bottom:1px solid var(--border); font-size:12.5px; }
.mt-act:last-child{ border-bottom:0; }
</style>

<div class="mt-head">
    <div>
        <h1><i class="fa fa-handshake-angle me-2" style="color:var(--brand)"></i>Meetings</h1>
        <p>Schedule meetings, run them to an agenda, keep the minutes and chase what was agreed.</p>
    </div>
    <div class="d-flex gap-2">
        <a href="<?= BASE_URL ?>/modules/meetings/actions.php" class="btn btn-sm btn-outline-primary">
            <i class="fa fa-list-check me-1"></i>Deliverables
            <?php if ($stats['my_actions']): ?><span class="badge bg-warning text-dark ms-1"><?= $stats['my_actions'] ?></span><?php endif; ?>
        </a>
        <?php if ($canMake): ?>
        <a href="<?= BASE_URL ?>/modules/meetings/edit.php" class="btn btn-sm btn-primary">
            <i class="fa fa-plus me-1"></i>Schedule Meeting
        </a>
        <?php endif; ?>
    </div>
</div>

<div class="mt-stats">
    <div class="mt-stat"><div class="mt-stat-v"><?= $stats['today'] ?></div><div class="mt-stat-l">Today</div></div>
    <div class="mt-stat"><div class="mt-stat-v"><?= $stats['upcoming'] ?></div><div class="mt-stat-l">Upcoming</div></div>
    <div class="mt-stat"><div class="mt-stat-v" style="color:<?= $stats['my_actions'] ? '#f59e0b' : 'var(--text)' ?>"><?= $stats['my_actions'] ?></div><div class="mt-stat-l">My Deliverables</div></div>
    <div class="mt-stat"><div class="mt-stat-v" style="color:<?= $stats['overdue'] ? '#dc2626' : 'var(--text)' ?>"><?= $stats['overdue'] ?></div><div class="mt-stat-l">Overdue</div></div>
</div>

<?php if ($myActions): ?>
<div class="mt-card">
    <div class="mt-card-head">
        <h2 class="mt-card-title"><i class="fa fa-clipboard-check"></i>Assigned to You</h2>
        <a href="<?= BASE_URL ?>/modules/meetings/actions.php?mine=1" class="btn btn-xs btn-outline-primary">See all</a>
    </div>
    <?php foreach ($myActions as $a):
        $overdue = $a['due_date'] && strtotime($a['due_date']) < strtotime('today');
        [$sl, $sc] = meetingActionStatuses()[$a['status']] ?? ['Pending', '#f59e0b'];
    ?>
    <div class="mt-act">
        <div style="min-width:0">
            <div style="font-weight:600;color:var(--text)"><?= e($a['title']) ?></div>
            <div class="text-muted" style="font-size:11.5px">
                <?= e($a['meeting_title']) ?>
                <?php if ($a['due_date']): ?>
                &middot; due <span style="color:<?= $overdue ? '#dc2626' : 'inherit' ?>">
                    <?= date('j M Y', strtotime($a['due_date'])) ?><?= $overdue ? ' (overdue)' : '' ?></span>
                <?php endif; ?>
            </div>
        </div>
        <div class="d-flex align-items-center gap-2">
            <span class="mt-tag" style="background:<?= $sc ?>1f;color:<?= $sc ?>"><?= $sl ?></span>
            <a href="<?= BASE_URL ?>/modules/meetings/view.php?id=<?= (int)$a['meeting_id'] ?>#actions"
               class="btn btn-xs btn-outline-secondary"><i class="fa fa-arrow-right"></i></a>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
    <div class="mt-tabs mb-0">
        <a class="mt-tab <?= $tab === 'upcoming' ? 'on' : '' ?>" href="?tab=upcoming">Upcoming</a>
        <a class="mt-tab <?= $tab === 'mine'     ? 'on' : '' ?>" href="?tab=mine">Mine</a>
        <a class="mt-tab <?= $tab === 'past'     ? 'on' : '' ?>" href="?tab=past">Past</a>
        <a class="mt-tab <?= $tab === 'all'      ? 'on' : '' ?>" href="?tab=all">All</a>
    </div>
    <form method="GET" class="d-flex gap-2">
        <input type="hidden" name="tab" value="<?= e($tab) ?>">
        <input type="text" name="q" value="<?= e($search) ?>" class="form-control form-control-sm"
               style="width:230px" placeholder="Search title, purpose, venue…">
        <button class="btn btn-sm btn-outline-secondary"><i class="fa fa-magnifying-glass"></i></button>
    </form>
</div>

<div class="mt-card">
    <?php if (!$meetings): ?>
        <div class="mt-empty">
            <i class="fa fa-calendar-day"></i>
            <?php if ($search !== ''): ?>
                Nothing matches “<?= e($search) ?>”.
            <?php elseif ($tab === 'upcoming'): ?>
                No meetings scheduled.<?= $canMake ? ' <a href="' . BASE_URL . '/modules/meetings/edit.php">Schedule one</a>.' : '' ?>
            <?php else: ?>
                Nothing here yet.
            <?php endif; ?>
        </div>
    <?php else: foreach ($meetings as $m):
        [$sl, $sc] = meetingStatuses()[$m['status']] ?? ['Scheduled', '#2563eb'];
        $ts   = strtotime($m['scheduled_start']);
        $type = meetingTypes()[$m['meeting_type']] ?? ['In person', 'fa-building'];
        $pct  = (int)$m['action_count'] > 0 ? round((int)$m['action_done'] / (int)$m['action_count'] * 100) : null;
        $isToday = date('Y-m-d', $ts) === date('Y-m-d');
    ?>
    <a class="mt-row" href="<?= BASE_URL ?>/modules/meetings/view.php?id=<?= (int)$m['id'] ?>">
        <div class="mt-when">
            <div class="d"><?= date('j', $ts) ?></div>
            <div class="m"><?= date('M', $ts) ?></div>
            <div class="t" style="<?= $isToday ? '' : 'color:var(--text-2,#64748b)' ?>"><?= date('H:i', $ts) ?></div>
        </div>
        <div class="mt-body">
            <div class="mt-title"><?= e($m['title']) ?></div>
            <div class="mt-meta">
                <span><i class="fa <?= $type[1] ?>"></i><?= $type[0] ?></span>
                <?php if ($m['venue']): ?><span><i class="fa fa-location-dot"></i><?= e($m['venue']) ?></span><?php endif; ?>
                <span><i class="fa fa-users"></i><?= (int)$m['participant_count'] ?></span>
                <?php if ((int)$m['agenda_count']): ?><span><i class="fa fa-list-ol"></i><?= (int)$m['agenda_count'] ?> item<?= (int)$m['agenda_count'] === 1 ? '' : 's' ?></span><?php endif; ?>
                <?php if ($m['organiser_name']): ?><span><i class="fa fa-user-tie"></i><?= e($m['organiser_name']) ?></span><?php endif; ?>
            </div>
        </div>
        <div class="mt-side">
            <span class="mt-tag" style="background:<?= $sc ?>1f;color:<?= $sc ?>"><?= $sl ?></span>
            <?php if ($pct !== null): ?>
            <div class="mt-prog" title="<?= (int)$m['action_done'] ?> of <?= (int)$m['action_count'] ?> deliverables done">
                <i style="width:<?= $pct ?>%"></i>
            </div>
            <span class="text-muted" style="font-size:10.5px"><?= (int)$m['action_done'] ?>/<?= (int)$m['action_count'] ?> done</span>
            <?php endif; ?>
            <?php if ($m['status'] === 'in_progress' && $m['meeting_type'] !== 'physical'): ?>
            <span class="mt-tag" style="background:#16a34a1f;color:#16a34a"><i class="fa fa-circle me-1" style="font-size:7px"></i>Live</span>
            <?php endif; ?>
        </div>
    </a>
    <?php endforeach; endif; ?>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>

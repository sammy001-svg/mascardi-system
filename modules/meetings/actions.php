<?php
/**
 * Meetings — deliverables tracker.
 *
 * Everything agreed in every meeting, in one list: what is done, what is still
 * open, and what is late. This is the view that makes minutes worth writing.
 */
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/_bootstrap.php';
requireLogin();
canAccess('meetings') || redirect(BASE_URL . '/index.php');

$db = getDB();
meetingsMigrate($db);

$me   = authUser();
$meId = (int)$me['id'];

$fStatus  = $_GET['status'] ?? 'open';
$fOwner   = (int)($_GET['owner'] ?? 0);
$fMeeting = (int)($_GET['meeting_id'] ?? 0);
$mineOnly = !empty($_GET['mine']);
if ($mineOnly) $fOwner = $meId;

// Only deliverables from meetings this person is entitled to see.
$seesAll = isSuperAdmin() || hasRole(['general_manager','manager']);

$where  = ['1=1'];
$params = [];

if (!$seesAll) {
    $where[] = "(m.organiser_id = ? OR a.assigned_to = ?
                 OR EXISTS (SELECT 1 FROM meeting_participants p
                            WHERE p.meeting_id = m.id AND p.user_id = ?))";
    array_push($params, $meId, $meId, $meId);
}

switch ($fStatus) {
    case 'open':      $where[] = "a.status IN ('pending','in_progress','blocked')"; break;
    case 'overdue':   $where[] = "a.status IN ('pending','in_progress','blocked')
                                  AND a.due_date IS NOT NULL AND a.due_date < CURDATE()"; break;
    case 'done':      $where[] = "a.status = 'done'"; break;
    case 'all':       break;
    default:          $fStatus = 'open'; $where[] = "a.status IN ('pending','in_progress','blocked')";
}
if ($fOwner)   { $where[] = 'a.assigned_to = ?'; $params[] = $fOwner; }
if ($fMeeting) { $where[] = 'a.meeting_id = ?';  $params[] = $fMeeting; }

$rows = [];
try {
    $st = $db->prepare("
        SELECT a.*, m.title AS meeting_title, m.scheduled_start,
               u.name AS owner_name, ai.title AS item_title
        FROM meeting_actions a
        JOIN meetings m ON m.id = a.meeting_id
        LEFT JOIN users u ON u.id = a.assigned_to
        LEFT JOIN meeting_agenda_items ai ON ai.id = a.agenda_item_id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY FIELD(a.status,'blocked','pending','in_progress','done','cancelled'),
                 (a.due_date IS NULL), a.due_date ASC
        LIMIT 400");
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (\Throwable $e) { error_log('meetings/actions: ' . $e->getMessage()); }

// Counts for the filter chips, using the same visibility rule.
$counts = ['open' => 0, 'overdue' => 0, 'done' => 0, 'all' => 0];
try {
    $vis = $seesAll ? '' : " AND (m.organiser_id = {$meId} OR a.assigned_to = {$meId}
                             OR EXISTS (SELECT 1 FROM meeting_participants p
                                        WHERE p.meeting_id = m.id AND p.user_id = {$meId}))";
    $c = $db->query("SELECT
            SUM(a.status IN ('pending','in_progress','blocked')) AS open,
            SUM(a.status IN ('pending','in_progress','blocked')
                AND a.due_date IS NOT NULL AND a.due_date < CURDATE()) AS overdue,
            SUM(a.status = 'done') AS done,
            COUNT(*) AS total
          FROM meeting_actions a JOIN meetings m ON m.id = a.meeting_id WHERE 1=1{$vis}")->fetch(PDO::FETCH_ASSOC) ?: [];
    $counts = ['open' => (int)($c['open'] ?? 0), 'overdue' => (int)($c['overdue'] ?? 0),
               'done' => (int)($c['done'] ?? 0), 'all' => (int)($c['total'] ?? 0)];
} catch (\Throwable $_) {}

$owners = [];
try {
    $owners = $db->query("SELECT DISTINCT u.id, u.name FROM meeting_actions a
                          JOIN users u ON u.id = a.assigned_to ORDER BY u.name")->fetchAll(PDO::FETCH_ASSOC);
} catch (\Throwable $_) {}

$pageTitle = 'Meeting Deliverables';
include __DIR__ . '/../../includes/header.php';
?>
<style>
.dl-head{ display:flex; justify-content:space-between; align-items:center; gap:14px; flex-wrap:wrap; margin-bottom:16px; }
.dl-head h1{ font-size:21px; font-weight:800; letter-spacing:-.4px; margin:0; color:var(--text); }
.dl-head p{ font-size:13px; color:var(--text-2,#64748b); margin:2px 0 0; }
.dl-chips{ display:flex; gap:8px; flex-wrap:wrap; margin-bottom:14px; }
.dl-chip{ padding:6px 14px; border-radius:20px; font-size:12.5px; font-weight:600; text-decoration:none;
    border:1px solid var(--border); background:var(--surface); color:var(--text); transition:.12s; }
.dl-chip:hover{ border-color:var(--brand); color:var(--brand); }
.dl-chip.on{ background:var(--brand); border-color:var(--brand); color:#fff; }
.dl-chip.danger.on{ background:#dc2626; border-color:#dc2626; }
.dl-card{ background:var(--surface); border:1px solid var(--border); border-radius:var(--r-lg); box-shadow:var(--sh-sm); overflow:hidden; }
.dl-row{ display:flex; gap:12px; align-items:flex-start; padding:13px 16px; border-bottom:1px solid var(--border); }
.dl-row:last-child{ border-bottom:0; }
.dl-dot{ width:9px; height:9px; border-radius:50%; flex:0 0 9px; margin-top:6px; }
.dl-t{ font-size:13.5px; font-weight:700; color:var(--text); }
.dl-m{ font-size:11.5px; color:var(--text-2,#64748b); margin-top:3px; display:flex; gap:12px; flex-wrap:wrap; }
.dl-m i{ margin-right:4px; opacity:.75; }
.dl-late{ color:#dc2626; font-weight:700; }
.dl-tag{ font-size:10.5px; font-weight:700; padding:3px 10px; border-radius:20px; white-space:nowrap; }
.dl-empty{ text-align:center; padding:44px 20px; color:var(--text-2,#64748b); }
.dl-empty i{ font-size:34px; opacity:.3; display:block; margin-bottom:12px; }
</style>

<div class="dl-head">
    <div>
        <h1><i class="fa fa-list-check me-2" style="color:var(--brand)"></i>Meeting Deliverables</h1>
        <p>Everything agreed in meetings — what has been done and what is still outstanding.</p>
    </div>
    <a href="<?= BASE_URL ?>/modules/meetings/index.php" class="btn btn-sm btn-outline-secondary">
        <i class="fa fa-arrow-left me-1"></i>Meetings
    </a>
</div>

<div class="dl-chips">
    <?php
    $base = array_filter(['owner' => $fOwner ?: null, 'meeting_id' => $fMeeting ?: null]);
    foreach ([
        ['open',    'Open',    $counts['open'],    ''],
        ['overdue', 'Overdue', $counts['overdue'], 'danger'],
        ['done',    'Done',    $counts['done'],    ''],
        ['all',     'All',     $counts['all'],     ''],
    ] as [$k, $lbl, $n, $cls]): ?>
    <a class="dl-chip <?= $cls ?> <?= $fStatus === $k ? 'on' : '' ?>"
       href="?<?= http_build_query($base + ['status' => $k]) ?>"><?= $lbl ?> <?= $n ?></a>
    <?php endforeach; ?>
    <a class="dl-chip <?= $fOwner === $meId ? 'on' : '' ?>"
       href="?<?= http_build_query(['status' => $fStatus, 'mine' => 1] + ($fMeeting ? ['meeting_id' => $fMeeting] : [])) ?>">
        Mine
    </a>
</div>

<form method="GET" class="d-flex gap-2 align-items-center mb-3 flex-wrap">
    <input type="hidden" name="status" value="<?= e($fStatus) ?>">
    <?php if ($fMeeting): ?><input type="hidden" name="meeting_id" value="<?= $fMeeting ?>"><?php endif; ?>
    <select name="owner" class="form-select form-select-sm" style="width:220px" onchange="this.form.submit()">
        <option value="">Everyone</option>
        <?php foreach ($owners as $o): ?>
        <option value="<?= (int)$o['id'] ?>" <?= $fOwner === (int)$o['id'] ? 'selected' : '' ?>>
            <?= e($o['name']) ?><?= (int)$o['id'] === $meId ? ' (you)' : '' ?>
        </option>
        <?php endforeach; ?>
    </select>
    <?php if ($fOwner || $fMeeting): ?>
    <a href="?status=<?= e($fStatus) ?>" class="btn btn-sm btn-outline-secondary">Clear filters</a>
    <?php endif; ?>
    <span class="small text-muted ms-auto"><?= count($rows) ?> shown</span>
</form>

<div class="dl-card">
    <?php if (!$rows): ?>
        <div class="dl-empty">
            <i class="fa fa-clipboard-check"></i>
            <?= $fStatus === 'overdue' ? 'Nothing is overdue.' : 'Nothing here.' ?>
        </div>
    <?php else: foreach ($rows as $a):
        [$sl, $sc] = meetingActionStatuses()[$a['status']] ?? ['Pending', '#f59e0b'];
        $open    = in_array($a['status'], ['pending','in_progress','blocked'], true);
        $overdue = $open && $a['due_date'] && strtotime($a['due_date']) < strtotime('today');
        $days    = $a['due_date'] ? (int)floor((strtotime($a['due_date']) - strtotime('today')) / 86400) : null;
    ?>
    <div class="dl-row">
        <span class="dl-dot" style="background:<?= $overdue ? '#dc2626' : $sc ?>"></span>
        <div style="flex:1;min-width:0">
            <div class="dl-t"><?= e($a['title']) ?></div>
            <?php if ($a['detail']): ?>
            <div style="font-size:12px;color:var(--text-2,#64748b);margin-top:2px"><?= e($a['detail']) ?></div>
            <?php endif; ?>
            <div class="dl-m">
                <span><i class="fa fa-user"></i><?= e($a['owner_name'] ?: 'Unassigned') ?><?= (int)$a['assigned_to'] === $meId ? ' (you)' : '' ?></span>
                <span><i class="fa fa-handshake-angle"></i>
                    <a href="<?= BASE_URL ?>/modules/meetings/view.php?id=<?= (int)$a['meeting_id'] ?>"><?= e($a['meeting_title']) ?></a>
                    <span class="text-muted">· <?= date('j M Y', strtotime($a['scheduled_start'])) ?></span>
                </span>
                <?php if ($a['due_date']): ?>
                <span class="<?= $overdue ? 'dl-late' : '' ?>">
                    <i class="fa fa-calendar"></i>
                    <?php if ($overdue): ?>
                        <?= abs($days) ?> day<?= abs($days) === 1 ? '' : 's' ?> overdue
                    <?php elseif ($open && $days === 0): ?>
                        due today
                    <?php elseif ($open && $days > 0): ?>
                        due in <?= $days ?> day<?= $days === 1 ? '' : 's' ?>
                    <?php else: ?>
                        due <?= date('j M Y', strtotime($a['due_date'])) ?>
                    <?php endif; ?>
                </span>
                <?php endif; ?>
                <?php if ($a['priority'] === 'high'): ?><span style="color:#dc2626;font-weight:700">High</span><?php endif; ?>
                <?php if ($a['completed_at']): ?>
                <span style="color:#16a34a"><i class="fa fa-check"></i>done <?= date('j M Y', strtotime($a['completed_at'])) ?></span>
                <?php endif; ?>
            </div>
            <?php if ($a['progress_note']): ?>
            <div style="font-size:11.5px;color:var(--text-2,#64748b);font-style:italic;margin-top:4px">
                “<?= e($a['progress_note']) ?>”
            </div>
            <?php endif; ?>
        </div>
        <div class="d-flex align-items-center gap-2">
            <span class="dl-tag" style="background:<?= $sc ?>1f;color:<?= $sc ?>"><?= $sl ?></span>
            <a href="<?= BASE_URL ?>/modules/meetings/view.php?id=<?= (int)$a['meeting_id'] ?>#actions"
               class="btn btn-xs btn-outline-secondary" title="Open in the meeting"><i class="fa fa-arrow-right"></i></a>
        </div>
    </div>
    <?php endforeach; endif; ?>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>

<?php
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
canAccess('team') || die('Access denied.');
$db   = getDB();
$user = authUser();

// ── Inline migrations ─────────────────────────────────────────────────────────
foreach ([
    "CREATE TABLE IF NOT EXISTS team_checkins (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        location_id INT NULL,
        custom_location VARCHAR(200) NULL,
        status ENUM('at_location','in_transit','off_site','wfh','on_leave','absent') DEFAULT 'at_location',
        notes VARCHAR(255) NULL,
        checked_in_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY unique_user (user_id)
    )",
    "CREATE TABLE IF NOT EXISTS team_movement_log (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        user_name VARCHAR(150) NOT NULL DEFAULT '',
        from_location_label VARCHAR(200) NULL,
        to_location_label VARCHAR(200) NULL,
        from_status VARCHAR(50) NULL,
        to_status VARCHAR(50) NULL,
        notes VARCHAR(255) NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )",
] as $_mig) { try { $db->exec($_mig); } catch (\Throwable $_e) {} }

// ── POST: check-in update ─────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['checkin_action'])) {
    $targetUserId = hasRole(['admin','manager','general_manager','sales_manager','workshop_manager','hr_manager'])
        ? ((int)($_POST['target_user_id'] ?? 0) ?: $user['id'])
        : $user['id'];

    $newStatus   = $_POST['checkin_status']   ?? 'at_location';
    $locId       = (int)($_POST['location_id'] ?? 0) ?: null;
    $customLoc   = trim($_POST['custom_location'] ?? '') ?: null;
    $notes       = trim($_POST['checkin_notes']   ?? '') ?: null;

    // Resolve location label for log
    $locLabel = $customLoc;
    if ($locId && !$locLabel) {
        $locRow = $db->prepare("SELECT name FROM locations WHERE id=?"); $locRow->execute([$locId]); $locRow=$locRow->fetch();
        $locLabel = $locRow['name'] ?? null;
    }

    // Get previous checkin for movement log
    $prev = $db->prepare("SELECT tc.*, l.name AS loc_name FROM team_checkins tc LEFT JOIN locations l ON l.id=tc.location_id WHERE tc.user_id=?");
    $prev->execute([$targetUserId]); $prev = $prev->fetch();
    $prevLocLabel = $prev ? ($prev['custom_location'] ?: $prev['loc_name']) : null;

    // Upsert team_checkins
    $db->prepare("INSERT INTO team_checkins (user_id,location_id,custom_location,status,notes,checked_in_at)
        VALUES (?,?,?,?,?,NOW())
        ON DUPLICATE KEY UPDATE location_id=VALUES(location_id),custom_location=VALUES(custom_location),
            status=VALUES(status),notes=VALUES(notes),checked_in_at=VALUES(checked_in_at)")
       ->execute([$targetUserId, $locId, $customLoc, $newStatus, $notes]);

    // Log the movement
    $targetName = ($targetUserId == $user['id']) ? $user['name'] : 'User #'.$targetUserId;
    $db->prepare("INSERT INTO team_movement_log (user_id,user_name,from_location_label,to_location_label,from_status,to_status,notes) VALUES (?,?,?,?,?,?,?)")
       ->execute([$targetUserId, $targetName, $prevLocLabel, $locLabel, $prev['status'] ?? null, $newStatus, $notes]);

    setFlash('success', 'Location updated.');
    redirect(BASE_URL . '/modules/team/index.php');
}

// ── Data ──────────────────────────────────────────────────────────────────────
$locations = $db->query("SELECT * FROM locations WHERE status='active' ORDER BY name")->fetchAll();

// All active users with their checkin status
$team = $db->query("
    SELECT u.id, u.name, u.role, u.status,
           tc.status AS presence, tc.custom_location,
           tc.checked_in_at, tc.location_id, tc.notes AS checkin_notes,
           l.name AS location_name
    FROM users u
    LEFT JOIN team_checkins tc ON tc.user_id = u.id
    LEFT JOIN locations l      ON l.id = tc.location_id
    WHERE u.status = 'active'
    ORDER BY
        FIELD(tc.status,'en_route','in_transit',NULL,'at_location','off_site','wfh','on_leave','absent'),
        u.name
")->fetchAll();

// My current checkin
$myCheckin = null;
foreach ($team as $t) { if ($t['id'] == $user['id']) { $myCheckin = $t; break; } }

// My movement log (last 10)
$myLog = $db->prepare("SELECT * FROM team_movement_log WHERE user_id=? ORDER BY created_at DESC LIMIT 20");
$myLog->execute([$user['id']]); $myLog = $myLog->fetchAll();

// Pending leave count (for tab badge)
$pendingLeave = 0;
try {
    if (hasRole(['admin','general_manager','manager','hr_manager','sales_manager','workshop_manager'])) {
        $pStmt = $db->prepare("SELECT COUNT(*) FROM leave_requests WHERE status='pending'");
        $pStmt->execute(); $pendingLeave = (int)$pStmt->fetchColumn();
    }
} catch (\Throwable $e) {}

// Presence display helpers
$presenceColors = ['at_location'=>'#22c55e','in_transit'=>'#f59e0b','off_site'=>'#3b82f6','wfh'=>'#8b5cf6','on_leave'=>'#94a3b8','absent'=>'#ef4444'];
$presenceLabels = ['at_location'=>'At Location','in_transit'=>'In Transit','off_site'=>'Off Site','wfh'=>'Working from Home','on_leave'=>'On Leave','absent'=>'Absent'];
$presenceIcons  = ['at_location'=>'fa-location-dot','in_transit'=>'fa-road','off_site'=>'fa-building-circle-exclamation','wfh'=>'fa-house-laptop','on_leave'=>'fa-umbrella-beach','absent'=>'fa-circle-xmark'];

$roleColors = ['admin'=>'#1e3a5f','manager'=>'#1e40af','general_manager'=>'#1e40af','sales_manager'=>'#0891b2','sales_officer'=>'#0284c7','sales_person'=>'#38bdf8','workshop_manager'=>'#d97706','mechanic'=>'#16a34a','driver'=>'#7c3aed','hr_manager'=>'#db2777'];
$roleColor = fn($r) => $roleColors[$r] ?? '#64748b';

$tab = $_GET['tab'] ?? 'board';
$pageTitle = 'Team';
include __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div>
        <h5 class="mb-1"><i class="fa fa-people-group me-2 text-primary"></i>Team</h5>
        <div class="text-muted small">Live presence, movements &amp; leave management</div>
    </div>
</div>

<!-- Tab nav -->
<ul class="nav nav-tabs mb-4">
    <li class="nav-item"><a class="nav-link <?= $tab==='board'?'active':'' ?>" href="?tab=board"><i class="fa fa-map-location-dot me-1"></i>Live Board</a></li>
    <li class="nav-item"><a class="nav-link <?= $tab==='log'?'active':'' ?>" href="?tab=log"><i class="fa fa-route me-1"></i>My Movements</a></li>
    <li class="nav-item">
        <a class="nav-link <?= $tab==='leave'?'active':'' ?>" href="leave.php">
            <i class="fa fa-umbrella-beach me-1"></i>Leave
            <?php if ($pendingLeave): ?><span class="badge bg-danger ms-1"><?= $pendingLeave ?></span><?php endif; ?>
        </a>
    </li>
    <li class="nav-item"><a class="nav-link <?= $tab==='absences'?'active':'' ?>" href="absences.php"><i class="fa fa-calendar-xmark me-1"></i>Absences</a></li>
</ul>

<?php if ($tab === 'board'): ?>

<!-- My Status Card -->
<div class="card mb-4" style="border-top:3px solid #2563eb">
    <div class="card-header fw-semibold"><i class="fa fa-location-crosshairs me-2"></i>My Current Status</div>
    <div class="card-body">
        <?php if ($myCheckin && $myCheckin['presence']): ?>
        <div class="d-flex align-items-center gap-3 mb-3">
            <div style="width:12px;height:12px;border-radius:50%;background:<?= $presenceColors[$myCheckin['presence']] ?? '#94a3b8' ?>;flex-shrink:0"></div>
            <div>
                <span class="fw-semibold"><?= $presenceLabels[$myCheckin['presence']] ?? $myCheckin['presence'] ?></span>
                <?php $myLocLabel = $myCheckin['custom_location'] ?: $myCheckin['location_name']; ?>
                <?php if ($myLocLabel): ?> — <span class="text-muted"><?= e($myLocLabel) ?></span><?php endif; ?>
                <div class="text-muted small">Updated <?= $myCheckin['checked_in_at'] ? fmtDate($myCheckin['checked_in_at'], 'd M H:i') : 'never' ?></div>
            </div>
        </div>
        <?php else: ?>
        <div class="alert alert-info py-2 small mb-3"><i class="fa fa-info-circle me-1"></i>You haven't checked in today. Update your status below.</div>
        <?php endif; ?>

        <form method="POST" class="row g-2 align-items-end">
            <input type="hidden" name="checkin_action" value="1">
            <div class="col-md-3">
                <label class="form-label small">I am</label>
                <select name="checkin_status" class="form-select form-select-sm">
                    <?php foreach ($presenceLabels as $val => $lbl): ?>
                    <option value="<?= $val ?>" <?= ($myCheckin['presence'] ?? '') === $val ? 'selected' : '' ?>><?= $lbl ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label small">At Location</label>
                <select name="location_id" class="form-select form-select-sm select2">
                    <option value="">— Select location —</option>
                    <?php foreach ($locations as $l): ?>
                    <option value="<?= $l['id'] ?>" <?= ($myCheckin['location_id'] ?? 0) == $l['id'] ? 'selected' : '' ?>><?= e($l['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label small">Or Custom Location / Address</label>
                <input type="text" name="custom_location" class="form-control form-control-sm"
                       placeholder="e.g. Client site, Karen"
                       value="<?= e($myCheckin['custom_location'] ?? '') ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label small">Note (optional)</label>
                <input type="text" name="checkin_notes" class="form-control form-control-sm" placeholder="Quick note…">
            </div>
            <div class="col-md-1">
                <button class="btn btn-primary btn-sm w-100"><i class="fa fa-check"></i></button>
            </div>
        </form>

        <?php if (hasRole(['admin','manager','general_manager','sales_manager','workshop_manager','hr_manager'])): ?>
        <details class="mt-3">
            <summary class="text-muted small" style="cursor:pointer">Update status for another team member</summary>
            <form method="POST" class="row g-2 align-items-end mt-2">
                <input type="hidden" name="checkin_action" value="1">
                <div class="col-md-3">
                    <select name="target_user_id" class="form-select form-select-sm select2">
                        <option value="">— Select staff member —</option>
                        <?php foreach ($team as $t): if ($t['id'] == $user['id']) continue; ?>
                        <option value="<?= $t['id'] ?>"><?= e($t['name']) ?> (<?= e(ucwords(str_replace('_',' ',$t['role']))) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2"><select name="checkin_status" class="form-select form-select-sm"><?php foreach ($presenceLabels as $v=>$l): ?><option value="<?= $v ?>"><?= $l ?></option><?php endforeach; ?></select></div>
                <div class="col-md-3"><select name="location_id" class="form-select form-select-sm select2"><option value="">— Location —</option><?php foreach ($locations as $l): ?><option value="<?= $l['id'] ?>"><?= e($l['name']) ?></option><?php endforeach; ?></select></div>
                <div class="col-md-3"><input type="text" name="custom_location" class="form-control form-control-sm" placeholder="Or custom location"></div>
                <div class="col-md-1"><button class="btn btn-outline-primary btn-sm w-100"><i class="fa fa-check"></i></button></div>
            </form>
        </details>
        <?php endif; ?>
    </div>
</div>

<!-- Team Grid -->
<?php
// Group by presence status
$grouped = [];
$order = ['in_transit','at_location','off_site','wfh','on_leave','absent',null];
foreach ($order as $s) {
    foreach ($team as $t) {
        $p = $t['presence'] ?? null;
        if ($p === $s) $grouped[$s ?? 'unknown'][] = $t;
    }
}
$totalPresent = count(array_filter($team, fn($t)=>in_array($t['presence'],['at_location','in_transit','off_site'])));
$onLeave     = count(array_filter($team, fn($t)=>$t['presence']==='on_leave'));
$absent      = count(array_filter($team, fn($t)=>$t['presence']==='absent'));
$unchecked   = count(array_filter($team, fn($t)=>!$t['presence']));
?>
<div class="d-flex gap-3 mb-3 flex-wrap align-items-center">
    <span class="text-muted small"><strong><?= count($team) ?></strong> staff</span>
    <span style="color:#22c55e;font-size:12px"><i class="fa fa-circle me-1"></i><?= $totalPresent ?> present</span>
    <?php if ($onLeave): ?><span style="color:#94a3b8;font-size:12px"><i class="fa fa-umbrella-beach me-1"></i><?= $onLeave ?> on leave</span><?php endif; ?>
    <?php if ($absent): ?><span style="color:#ef4444;font-size:12px"><i class="fa fa-circle-xmark me-1"></i><?= $absent ?> absent</span><?php endif; ?>
    <?php if ($unchecked): ?><span style="color:#cbd5e1;font-size:12px"><i class="fa fa-circle-question me-1"></i><?= $unchecked ?> not checked in</span><?php endif; ?>
</div>

<div class="row g-3">
<?php foreach ($team as $t):
    $pStatus  = $t['presence'] ?? null;
    $dotColor = $pStatus ? ($presenceColors[$pStatus] ?? '#cbd5e1') : '#cbd5e1';
    $locLabel = $t['custom_location'] ?: ($t['location_name'] ?? null);
    $timeAgo  = null;
    if ($t['checked_in_at']) {
        $diff = time() - strtotime($t['checked_in_at']);
        $timeAgo = $diff < 3600 ? round($diff/60).'m ago' : (round($diff/3600).'h ago');
    }
    $bgColor = $roleColor($t['role']);
    $initials = implode('', array_map(fn($w)=>strtoupper(substr($w,0,1)), array_slice(explode(' ', $t['name']), 0, 2)));
?>
<div class="col-6 col-md-3 col-lg-2">
    <div class="card h-100 p-3 text-center" style="position:relative">
        <!-- Status dot -->
        <div style="position:absolute;top:8px;right:8px;width:10px;height:10px;border-radius:50%;background:<?= $dotColor ?>;border:2px solid #fff;box-shadow:0 0 0 1px <?= $dotColor ?>"></div>

        <!-- Avatar -->
        <div style="width:44px;height:44px;border-radius:50%;background:<?= $bgColor ?>;color:#fff;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:15px;margin:0 auto 8px">
            <?= $initials ?>
        </div>

        <!-- Name + role -->
        <div class="fw-semibold" style="font-size:12.5px;line-height:1.3"><?= e($t['name']) ?></div>
        <div class="text-muted" style="font-size:10px"><?= e(ucwords(str_replace('_',' ',$t['role']))) ?></div>

        <!-- Status -->
        <?php if ($pStatus): ?>
        <div class="mt-1" style="font-size:10.5px;color:<?= $dotColor ?>;font-weight:600">
            <i class="fa <?= $presenceIcons[$pStatus] ?? 'fa-circle' ?>" style="font-size:9px"></i>
            <?= $presenceLabels[$pStatus] ?? $pStatus ?>
        </div>
        <?php endif; ?>

        <!-- Location -->
        <?php if ($locLabel): ?>
        <div style="font-size:10px;color:#64748b;margin-top:2px"><?= e($locLabel) ?></div>
        <?php endif; ?>

        <!-- Time ago -->
        <?php if ($timeAgo): ?>
        <div style="font-size:9px;color:#94a3b8;margin-top:1px"><?= $timeAgo ?></div>
        <?php endif; ?>

        <!-- Note -->
        <?php if ($t['checkin_notes']): ?>
        <div title="<?= e($t['checkin_notes']) ?>" style="font-size:9px;color:#94a3b8;margin-top:1px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
            <i class="fa fa-comment-dots me-1"></i><?= e($t['checkin_notes']) ?>
        </div>
        <?php endif; ?>

        <?php if (!$pStatus): ?><div style="font-size:10px;color:#94a3b8;margin-top:4px">Not checked in</div><?php endif; ?>
    </div>
</div>
<?php endforeach; ?>
</div>

<?php elseif ($tab === 'log'): ?>

<!-- My Movement Log -->
<div class="card">
    <div class="card-header"><i class="fa fa-route me-2"></i>My Movement Log (Last 20)</div>
    <div class="card-body p-0">
        <?php if ($myLog): ?>
        <table class="table table-hover mb-0" style="font-size:13px">
            <thead style="background:#f8fafc"><tr><th class="ps-3">Time</th><th>From</th><th>To</th><th>Status Change</th><th class="pe-3">Note</th></tr></thead>
            <tbody>
            <?php foreach ($myLog as $log): ?>
            <tr>
                <td class="ps-3 text-muted small"><?= fmtDate($log['created_at'], 'd M H:i') ?></td>
                <td class="small"><?= $log['from_location_label'] ? e($log['from_location_label']) : '<span class="text-muted">—</span>' ?></td>
                <td class="small fw-semibold"><?= $log['to_location_label'] ? e($log['to_location_label']) : '<span class="text-muted">—</span>' ?></td>
                <td class="small">
                    <?php if ($log['from_status'] || $log['to_status']): ?>
                    <span class="text-muted"><?= $log['from_status'] ? ucwords(str_replace('_',' ',$log['from_status'])) : '?' ?></span>
                    → <strong><?= $log['to_status'] ? ucwords(str_replace('_',' ',$log['to_status'])) : '?' ?></strong>
                    <?php else: ?>—<?php endif; ?>
                </td>
                <td class="pe-3 small text-muted"><?= $log['notes'] ? e($log['notes']) : '—' ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php else: ?>
        <p class="text-muted p-4 mb-0">No movement history yet.</p>
        <?php endif; ?>
    </div>
</div>

<?php endif; ?>

<?php include __DIR__ . '/../../includes/footer.php'; ?>

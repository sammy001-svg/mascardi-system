<?php
/**
 * HR — Leave management
 *
 * Requests, approvals and entitlement balances for every employee. The
 * team module has a self-service view of the same `leave_requests` table;
 * this is the HR-side register that decides them.
 */
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/_bootstrap.php';
requireLogin();
canAccess('hr') || redirect(BASE_URL . '/index.php');

$db = getDB();
hrMigrate($db);

$canManage = canWrite('hr');
$me        = authUser();
$year      = (int)date('Y');

$leaveTypes = [
    'annual'    => 'Annual',
    'sick'      => 'Sick',
    'emergency' => 'Emergency',
    'maternity' => 'Maternity',
    'paternity' => 'Paternity',
    'unpaid'    => 'Unpaid',
    'study'     => 'Study',
];

/** Working days between two dates, excluding weekends. */
function hrLeaveDays(string $start, string $end): float {
    $s = new DateTimeImmutable($start);
    $e = new DateTimeImmutable($end);
    if ($e < $s) return 0.0;
    $days = 0;
    for ($d = $s; $d <= $e; $d = $d->modify('+1 day')) {
        if ((int)$d->format('N') < 6) $days++;
    }
    return (float)$days;
}

$errors = [];

// ── Actions ───────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canManage) {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    // Record a request on an employee's behalf
    if ($action === 'add_leave') {
        $p     = hrParseKey($_POST['staff_key'] ?? '');
        $type  = $_POST['leave_type'] ?? 'annual';
        $start = $_POST['start_date'] ?? '';
        $end   = $_POST['end_date']   ?? '';
        $reason= trim($_POST['reason'] ?? '');
        $status= ($_POST['decision'] ?? 'pending') === 'approved' ? 'approved' : 'pending';

        if (!$p)                                       $errors[] = 'Select an employee.';
        if (!isset($leaveTypes[$type]))                $errors[] = 'Select a valid leave type.';
        if (!$start || !$end || !strtotime($start) || !strtotime($end)) $errors[] = 'Enter valid start and end dates.';
        elseif (strtotime($end) < strtotime($start))   $errors[] = 'The end date cannot be before the start date.';

        if (!$errors) {
            $person = hrStaffMember($db, $p['type'], $p['id']);
            $days   = hrLeaveDays($start, $end);
            if ($days <= 0) {
                $errors[] = 'That range contains no working days.';
            } else {
                try {
                    $db->prepare("INSERT INTO leave_requests
                            (staff_type, staff_id, user_name, leave_type, start_date, end_date,
                             days_count, reason, status, approved_by, approved_at)
                         VALUES (?,?,?,?,?,?,?,?,?,?,?)")
                       ->execute([
                           $p['type'], $p['id'], $person['name'] ?? 'Employee', $type,
                           $start, $end, $days, $reason ?: null, $status,
                           $status === 'approved' ? (int)$me['id'] : null,
                           $status === 'approved' ? date('Y-m-d H:i:s') : null,
                       ]);
                    if ($status === 'approved') hrApplyLeaveToBalance($db, $p['type'], $p['id'], $type, $days, $year);
                    logActivity('create', 'hr', $p['id'],
                        "Leave recorded for {$person['name']}: {$type}, {$days} day(s)");
                    setFlash('success', 'Leave recorded for ' . ($person['name'] ?? 'employee') . '.');
                    redirect(BASE_URL . '/modules/hr/leave.php');
                } catch (\Throwable $e) {
                    error_log('hr/leave add: ' . $e->getMessage());
                    $errors[] = 'Could not save the request: ' . $e->getMessage();
                }
            }
        }
    }

    // Approve / reject
    if ($action === 'decide') {
        $id       = (int)($_POST['id'] ?? 0);
        $decision = $_POST['decision'] ?? '';
        if ($id && in_array($decision, ['approved','rejected'], true)) {
            try {
                $st = $db->prepare("SELECT * FROM leave_requests WHERE id = ? AND status = 'pending'");
                $st->execute([$id]);
                $req = $st->fetch(PDO::FETCH_ASSOC);
                if (!$req) {
                    setFlash('error', 'That request has already been decided.');
                } else {
                    $db->prepare("UPDATE leave_requests
                                  SET status=?, approved_by=?, approved_at=NOW(), notes=?
                                  WHERE id=? AND status='pending'")
                       ->execute([$decision, (int)$me['id'], trim($_POST['notes'] ?? '') ?: null, $id]);

                    // Only an approval consumes entitlement.
                    if ($decision === 'approved') {
                        hrApplyLeaveToBalance($db, $req['staff_type'], (int)$req['staff_id'],
                                              $req['leave_type'], (float)$req['days_count'], $year);
                    }
                    logActivity('update', 'hr', $id, "Leave request #{$id} {$decision} for {$req['user_name']}");

                    // Tell the person, when they have a login to be told through.
                    if ($req['staff_type'] === 'user') {
                        try {
                            require_once __DIR__ . '/../../includes/notifications.php';
                            createNotification((int)$req['staff_id'], 'leave',
                                'Leave ' . ucfirst($decision),
                                ucfirst($req['leave_type']) . ' leave ' . date('j M', strtotime($req['start_date']))
                                . '–' . date('j M Y', strtotime($req['end_date'])) . ' was ' . $decision . '.',
                                BASE_URL . '/modules/team/leave.php');
                        } catch (\Throwable $_) {}
                    }
                    setFlash('success', 'Request ' . $decision . '.');
                }
            } catch (\Throwable $e) {
                error_log('hr/leave decide: ' . $e->getMessage());
                setFlash('error', 'Could not update the request: ' . $e->getMessage());
            }
            redirect(BASE_URL . '/modules/hr/leave.php?tab=' . ($_POST['tab'] ?? 'pending'));
        }
    }

    // Set an entitlement
    if ($action === 'set_balance') {
        $p      = hrParseKey($_POST['staff_key'] ?? '');
        $annual = max(0, (float)($_POST['annual_days'] ?? 0));
        $sick   = max(0, (float)($_POST['sick_days']   ?? 0));
        if ($p) {
            try {
                $db->prepare("INSERT INTO leave_balances
                        (staff_type, staff_id, leave_year, annual_days, sick_days, taken_annual, taken_sick)
                     VALUES (?,?,?,?,?,0,0)
                     ON DUPLICATE KEY UPDATE annual_days=VALUES(annual_days), sick_days=VALUES(sick_days)")
                   ->execute([$p['type'], $p['id'], $year, $annual, $sick]);
                setFlash('success', 'Entitlement updated.');
            } catch (\Throwable $e) {
                // No unique key on (staff_type, staff_id, leave_year) in older
                // installs — fall back to an explicit update/insert.
                try {
                    $st = $db->prepare("SELECT id FROM leave_balances
                                        WHERE staff_type=? AND staff_id=? AND leave_year=?");
                    $st->execute([$p['type'], $p['id'], $year]);
                    if ($rowId = (int)$st->fetchColumn()) {
                        $db->prepare("UPDATE leave_balances SET annual_days=?, sick_days=? WHERE id=?")
                           ->execute([$annual, $sick, $rowId]);
                    } else {
                        $db->prepare("INSERT INTO leave_balances
                                (staff_type, staff_id, leave_year, annual_days, sick_days, taken_annual, taken_sick)
                             VALUES (?,?,?,?,?,0,0)")
                           ->execute([$p['type'], $p['id'], $year, $annual, $sick]);
                    }
                    setFlash('success', 'Entitlement updated.');
                } catch (\Throwable $e2) {
                    error_log('hr/leave balance: ' . $e2->getMessage());
                    setFlash('error', 'Could not save the entitlement: ' . $e2->getMessage());
                }
            }
            redirect(BASE_URL . '/modules/hr/leave.php?tab=balances');
        }
    }
}

/** Adds approved days onto the year's taken total, creating the row if needed. */
function hrApplyLeaveToBalance(PDO $db, string $type, int $id, string $leaveType, float $days, int $year): void
{
    // Only annual and sick draw down an entitlement; the other types are
    // statutory or unpaid and are not capped by a balance.
    $col = ['annual' => 'taken_annual', 'sick' => 'taken_sick'][$leaveType] ?? null;
    if (!$col) return;
    try {
        $st = $db->prepare("SELECT id FROM leave_balances WHERE staff_type=? AND staff_id=? AND leave_year=?");
        $st->execute([$type, $id, $year]);
        if ($rowId = (int)$st->fetchColumn()) {
            $db->prepare("UPDATE leave_balances SET {$col} = COALESCE({$col},0) + ? WHERE id = ?")
               ->execute([$days, $rowId]);
        } else {
            // 21 annual / 14 sick are the Kenyan statutory minimums, used only
            // as a starting entitlement when HR has not set one.
            $db->prepare("INSERT INTO leave_balances
                    (staff_type, staff_id, leave_year, annual_days, sick_days, taken_annual, taken_sick)
                 VALUES (?,?,?,21,14,?,?)")
               ->execute([$type, $id, $year,
                          $col === 'taken_annual' ? $days : 0,
                          $col === 'taken_sick'   ? $days : 0]);
        }
    } catch (\Throwable $e) {
        error_log('hrApplyLeaveToBalance: ' . $e->getMessage());
    }
}

// ── Data ──────────────────────────────────────────────────────────────────────
$tab = $_GET['tab'] ?? 'pending';
if (!in_array($tab, ['pending','all','balances'], true)) $tab = 'pending';

$staff = hrStaffDirectory($db);

$requests = [];
try {
    $sql = "SELECT * FROM leave_requests"
         . ($tab === 'pending' ? " WHERE status = 'pending'" : '')
         . " ORDER BY FIELD(status,'pending','approved','rejected'), start_date DESC LIMIT 200";
    $requests = $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
} catch (\Throwable $_) {}

$counts = ['pending' => 0, 'approved' => 0, 'rejected' => 0, 'today' => 0];
try {
    foreach ($db->query("SELECT status, COUNT(*) n FROM leave_requests GROUP BY status") as $r) {
        $counts[$r['status']] = (int)$r['n'];
    }
    $st = $db->prepare("SELECT COUNT(*) FROM leave_requests
                        WHERE status='approved' AND CURDATE() BETWEEN start_date AND end_date");
    $st->execute();
    $counts['today'] = (int)$st->fetchColumn();
} catch (\Throwable $_) {}

$balances = [];
try {
    $st = $db->prepare("SELECT * FROM leave_balances WHERE leave_year = ?");
    $st->execute([$year]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $b) {
        $balances[hrKey($b['staff_type'], (int)$b['staff_id'])] = $b;
    }
} catch (\Throwable $_) {}

$pageTitle = 'Leave Management';
include __DIR__ . '/../../includes/header.php';
?>
<style>
.lv-head{ display:flex; justify-content:space-between; align-items:center; gap:14px; flex-wrap:wrap; margin-bottom:16px; }
.lv-head h1{ font-size:21px; font-weight:800; letter-spacing:-.4px; margin:0; color:var(--text); }
.lv-head p{ font-size:13px; color:var(--text-2,#64748b); margin:2px 0 0; }

.lv-stats{ display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:12px; margin-bottom:16px; }
@media(max-width:768px){ .lv-stats{ grid-template-columns:repeat(2,minmax(0,1fr)); } }
.lv-stat{ background:var(--surface); border:1px solid var(--border); border-radius:var(--r-lg);
    padding:14px 16px; box-shadow:var(--sh-sm); }
.lv-stat-v{ font-size:22px; font-weight:900; line-height:1.1; color:var(--text); }
.lv-stat-l{ font-size:10.5px; text-transform:uppercase; letter-spacing:.6px; color:var(--text-2,#64748b); margin-top:5px; }

.lv-tabs{ display:flex; gap:8px; flex-wrap:wrap; margin-bottom:16px; }
.lv-tab{ padding:7px 15px; border-radius:20px; font-size:12.5px; font-weight:600; text-decoration:none;
    border:1px solid var(--border); background:var(--surface); color:var(--text); transition:.12s; }
.lv-tab:hover{ border-color:var(--brand); color:var(--brand); }
.lv-tab.on{ background:var(--brand); border-color:var(--brand); color:#fff; }

.lv-card{ background:var(--surface); border:1px solid var(--border); border-radius:var(--r-lg);
    box-shadow:var(--sh-sm); overflow:hidden; margin-bottom:16px; }
.lv-card-head{ padding:12px 16px; border-bottom:1px solid var(--border); background:var(--surface-alt);
    display:flex; align-items:center; justify-content:space-between; gap:10px; }
.lv-card-title{ font-size:13.5px; font-weight:700; color:var(--text); margin:0; display:flex; align-items:center; gap:8px; }
.lv-card-title i{ color:var(--brand); }
.lv-card-body{ padding:16px; }

.lv-req{ display:flex; align-items:center; gap:12px; padding:12px 16px; border-bottom:1px solid var(--border); flex-wrap:wrap; }
.lv-req:last-child{ border-bottom:0; }
.lv-avatar{ width:38px; height:38px; border-radius:10px; flex:0 0 38px; color:#fff;
    display:flex; align-items:center; justify-content:center; font-size:13px; font-weight:800; }
.lv-req-main{ flex:1; min-width:180px; }
.lv-req-name{ font-size:13.5px; font-weight:700; color:var(--text); }
.lv-req-sub{ font-size:12px; color:var(--text-2,#64748b); margin-top:2px; }
.lv-req-reason{ font-size:11.5px; color:var(--text-2,#64748b); font-style:italic; margin-top:3px; }
.lv-tag{ font-size:10.5px; font-weight:700; padding:3px 10px; border-radius:20px; white-space:nowrap; }
.lv-empty{ text-align:center; padding:38px 20px; color:var(--text-2,#64748b); }
.lv-empty i{ font-size:32px; opacity:.3; display:block; margin-bottom:11px; }

.lv-bal{ display:grid; grid-template-columns:minmax(0,2fr) repeat(2,minmax(0,1.4fr)) auto; gap:12px;
    align-items:center; padding:11px 16px; border-bottom:1px solid var(--border); }
.lv-bal:last-child{ border-bottom:0; }
@media(max-width:768px){ .lv-bal{ grid-template-columns:minmax(0,1fr); } }
.lv-track{ height:7px; border-radius:5px; background:var(--surface-alt); border:1px solid var(--border); overflow:hidden; margin-top:5px; }
.lv-fill{ height:100%; }
.form-label{ font-size:12px; font-weight:600; color:var(--text); margin-bottom:4px; }
</style>

<div class="lv-head">
    <div>
        <h1><i class="fa fa-plane-departure me-2" style="color:var(--brand)"></i>Leave Management</h1>
        <p>Requests, approvals and entitlements for <?= $year ?>.</p>
    </div>
    <a href="<?= BASE_URL ?>/modules/hr/index.php" class="btn btn-sm btn-outline-secondary">
        <i class="fa fa-arrow-left me-1"></i>HR Dashboard
    </a>
</div>

<div class="lv-stats">
    <div class="lv-stat"><div class="lv-stat-v" style="color:#f59e0b"><?= $counts['pending'] ?></div><div class="lv-stat-l">Awaiting Decision</div></div>
    <div class="lv-stat"><div class="lv-stat-v" style="color:#6366f1"><?= $counts['today'] ?></div><div class="lv-stat-l">Away Today</div></div>
    <div class="lv-stat"><div class="lv-stat-v" style="color:#16a34a"><?= $counts['approved'] ?></div><div class="lv-stat-l">Approved</div></div>
    <div class="lv-stat"><div class="lv-stat-v" style="color:#dc2626"><?= $counts['rejected'] ?></div><div class="lv-stat-l">Rejected</div></div>
</div>

<?php if ($errors): ?>
<div class="alert alert-danger py-2">
    <ul class="mb-0 small ps-3"><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul>
</div>
<?php endif; ?>

<div class="lv-tabs">
    <a class="lv-tab <?= $tab === 'pending'  ? 'on' : '' ?>" href="?tab=pending">Awaiting decision (<?= $counts['pending'] ?>)</a>
    <a class="lv-tab <?= $tab === 'all'      ? 'on' : '' ?>" href="?tab=all">All requests</a>
    <a class="lv-tab <?= $tab === 'balances' ? 'on' : '' ?>" href="?tab=balances">Entitlements</a>
</div>

<?php if ($tab === 'balances'): ?>

    <?php if ($canManage): ?>
    <div class="lv-card">
        <div class="lv-card-head"><h2 class="lv-card-title"><i class="fa fa-sliders"></i>Set an Entitlement</h2></div>
        <div class="lv-card-body">
            <form method="POST" class="row g-2 align-items-end">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="set_balance">
                <div class="col-md-5">
                    <label class="form-label">Employee</label>
                    <select name="staff_key" class="form-select form-select-sm" required>
                        <option value="">Select…</option>
                        <?php foreach ($staff as $k => $s): ?>
                        <option value="<?= e($k) ?>"><?= e($s['name']) ?> — <?= e(hrStaffTypes()[$s['staff_type']]['label']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Annual days</label>
                    <input type="number" name="annual_days" class="form-control form-control-sm" min="0" step="0.5" value="21">
                </div>
                <div class="col-md-2">
                    <label class="form-label">Sick days</label>
                    <input type="number" name="sick_days" class="form-control form-control-sm" min="0" step="0.5" value="14">
                </div>
                <div class="col-md-2">
                    <button class="btn btn-sm btn-primary w-100"><i class="fa fa-check me-1"></i>Save</button>
                </div>
                <div class="col-12">
                    <div class="form-text" style="font-size:11px">
                        Defaults follow the Kenyan statutory minimum — 21 annual and 14 sick days a year.
                    </div>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <div class="lv-card">
        <div class="lv-card-head">
            <h2 class="lv-card-title"><i class="fa fa-chart-simple"></i><?= $year ?> Entitlements</h2>
            <span class="small text-muted"><?= count($staff) ?> employees</span>
        </div>
        <?php if (!$staff): ?>
            <div class="lv-empty"><i class="fa fa-users-slash"></i>No staff on record.</div>
        <?php else: foreach ($staff as $k => $s):
            $b        = $balances[$k] ?? null;
            $annTotal = $b ? (float)$b['annual_days']  : 0.0;
            $annTaken = $b ? (float)$b['taken_annual'] : 0.0;
            $sickTotal= $b ? (float)$b['sick_days']    : 0.0;
            $sickTaken= $b ? (float)$b['taken_sick']   : 0.0;
            $annPct   = $annTotal  > 0 ? min(100, $annTaken  / $annTotal  * 100) : 0;
            $sickPct  = $sickTotal > 0 ? min(100, $sickTaken / $sickTotal * 100) : 0;
            $fmt      = fn($n) => rtrim(rtrim(number_format($n, 1), '0'), '.');
        ?>
        <div class="lv-bal">
            <div class="d-flex align-items-center gap-2" style="min-width:0">
                <div class="lv-avatar" style="background:<?= hrAvatarColor($k) ?>;width:32px;height:32px;flex:0 0 32px;font-size:11px">
                    <?= e(hrInitials($s['name'])) ?>
                </div>
                <div style="min-width:0">
                    <div style="font-size:13px;font-weight:600;color:var(--text);white-space:nowrap;overflow:hidden;text-overflow:ellipsis">
                        <?= e($s['name']) ?>
                    </div>
                    <div class="text-muted" style="font-size:11.5px"><?= e($s['department'] ?: hrStaffTypes()[$s['staff_type']]['label']) ?></div>
                </div>
            </div>
            <div>
                <div style="font-size:11.5px;color:var(--text)">
                    Annual
                    <?php if ($b): ?>
                        <strong><?= $fmt(max(0, $annTotal - $annTaken)) ?></strong> left of <?= $fmt($annTotal) ?>
                    <?php else: ?>
                        <span class="text-muted">not set</span>
                    <?php endif; ?>
                </div>
                <div class="lv-track"><div class="lv-fill" style="width:<?= $annPct ?>%;background:#6366f1"></div></div>
            </div>
            <div>
                <div style="font-size:11.5px;color:var(--text)">
                    Sick
                    <?php if ($b): ?>
                        <strong><?= $fmt(max(0, $sickTotal - $sickTaken)) ?></strong> left of <?= $fmt($sickTotal) ?>
                    <?php else: ?>
                        <span class="text-muted">not set</span>
                    <?php endif; ?>
                </div>
                <div class="lv-track"><div class="lv-fill" style="width:<?= $sickPct ?>%;background:#0891b2"></div></div>
            </div>
            <a href="<?= BASE_URL ?>/modules/hr/employee.php?staff=<?= e($k) ?>"
               class="btn btn-xs btn-outline-primary" title="Open record"><i class="fa fa-arrow-right"></i></a>
        </div>
        <?php endforeach; endif; ?>
    </div>

<?php else: ?>

    <?php if ($canManage): ?>
    <div class="lv-card">
        <div class="lv-card-head"><h2 class="lv-card-title"><i class="fa fa-plus"></i>Record Leave</h2></div>
        <div class="lv-card-body">
            <form method="POST" class="row g-2 align-items-end">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="add_leave">
                <div class="col-md-3">
                    <label class="form-label">Employee</label>
                    <select name="staff_key" class="form-select form-select-sm" required>
                        <option value="">Select…</option>
                        <?php foreach ($staff as $k => $s): ?>
                        <option value="<?= e($k) ?>"><?= e($s['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Type</label>
                    <select name="leave_type" class="form-select form-select-sm">
                        <?php foreach ($leaveTypes as $k => $l): ?><option value="<?= $k ?>"><?= $l ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">From</label>
                    <input type="date" name="start_date" class="form-control form-control-sm" required>
                </div>
                <div class="col-md-2">
                    <label class="form-label">To</label>
                    <input type="date" name="end_date" class="form-control form-control-sm" required>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Decision</label>
                    <select name="decision" class="form-select form-select-sm">
                        <option value="approved">Approve now</option>
                        <option value="pending">Leave pending</option>
                    </select>
                </div>
                <div class="col-md-1">
                    <button class="btn btn-sm btn-primary w-100"><i class="fa fa-check"></i></button>
                </div>
                <div class="col-12">
                    <input type="text" name="reason" class="form-control form-control-sm" placeholder="Reason (optional)">
                    <div class="form-text" style="font-size:11px">Days are counted excluding weekends.</div>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <div class="lv-card">
        <div class="lv-card-head">
            <h2 class="lv-card-title">
                <i class="fa fa-list-check"></i><?= $tab === 'pending' ? 'Awaiting Decision' : 'All Requests' ?>
            </h2>
            <span class="small text-muted"><?= count($requests) ?> shown</span>
        </div>

        <?php if (!$requests): ?>
            <div class="lv-empty">
                <i class="fa fa-circle-check"></i>
                <?= $tab === 'pending' ? 'Nothing waiting — every request has been decided.' : 'No leave requests recorded yet.' ?>
            </div>
        <?php else: foreach ($requests as $r):
            $sc = ['pending'=>'#f59e0b','approved'=>'#16a34a','rejected'=>'#dc2626'][$r['status']] ?? '#64748b';
            $k  = hrKey($r['staff_type'], (int)$r['staff_id']);
        ?>
        <div class="lv-req">
            <div class="lv-avatar" style="background:<?= hrAvatarColor($k) ?>"><?= e(hrInitials($r['user_name'])) ?></div>
            <div class="lv-req-main">
                <div class="lv-req-name"><?= e($r['user_name']) ?></div>
                <div class="lv-req-sub">
                    <strong><?= e($leaveTypes[$r['leave_type']] ?? ucfirst($r['leave_type'])) ?></strong> &middot;
                    <?= date('j M', strtotime($r['start_date'])) ?> – <?= date('j M Y', strtotime($r['end_date'])) ?> &middot;
                    <?= rtrim(rtrim(number_format((float)$r['days_count'], 1), '0'), '.') ?> day<?= (float)$r['days_count'] == 1 ? '' : 's' ?>
                </div>
                <?php if ($r['reason']): ?>
                <div class="lv-req-reason">“<?= e($r['reason']) ?>”</div>
                <?php endif; ?>
            </div>

            <span class="lv-tag" style="background:<?= $sc ?>1f;color:<?= $sc ?>"><?= ucfirst($r['status']) ?></span>

            <?php if ($r['status'] === 'pending' && $canManage): ?>
            <div class="d-flex gap-1">
                <form method="POST" class="d-inline">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="decide">
                    <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                    <input type="hidden" name="tab" value="<?= e($tab) ?>">
                    <input type="hidden" name="decision" value="approved">
                    <button class="btn btn-xs btn-success"><i class="fa fa-check me-1"></i>Approve</button>
                </form>
                <form method="POST" class="d-inline"
                      onsubmit="return confirm('Reject this leave request?')">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="decide">
                    <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                    <input type="hidden" name="tab" value="<?= e($tab) ?>">
                    <input type="hidden" name="decision" value="rejected">
                    <button class="btn btn-xs btn-outline-danger"><i class="fa fa-xmark me-1"></i>Reject</button>
                </form>
            </div>
            <?php endif; ?>

            <a href="<?= BASE_URL ?>/modules/hr/employee.php?staff=<?= e($k) ?>"
               class="btn btn-xs btn-outline-secondary" title="Open record"><i class="fa fa-user"></i></a>
        </div>
        <?php endforeach; endif; ?>
    </div>

<?php endif; ?>

<?php include __DIR__ . '/../../includes/footer.php'; ?>

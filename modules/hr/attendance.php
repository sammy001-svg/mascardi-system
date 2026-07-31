<?php
/**
 * HR — Daily attendance register
 *
 * Replaces the mechanic/driver-only register. Every employee the directory
 * knows about can be marked here, including office staff, which the old
 * attendance_records ENUM made impossible.
 */
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/_bootstrap.php';
requireLogin();
(canAccess('hr') || canAccess('attendance')) || redirect(BASE_URL . '/index.php');

$db = getDB();
hrMigrate($db);

$canMark = canWrite('hr') || canWrite('attendance');

// Date — never allow a future register; it would let today's numbers be
// pre-filled and quietly invalidate the attendance rate on the dashboard.
$date = $_GET['date'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !strtotime($date)) $date = date('Y-m-d');
if (strtotime($date) > strtotime(date('Y-m-d')))                       $date = date('Y-m-d');

$fType = $_GET['type'] ?? '';
if (!isset(hrStaffTypes()[$fType])) $fType = '';

$statuses = [
    'present'  => ['Present',  '#16a34a', 'fa-circle-check'],
    'late'     => ['Late',     '#f59e0b', 'fa-clock'],
    'half_day' => ['Half day', '#0891b2', 'fa-circle-half-stroke'],
    'leave'    => ['Leave',    '#6366f1', 'fa-plane-departure'],
    'absent'   => ['Absent',   '#dc2626', 'fa-circle-xmark'],
];

// ── Save the register ─────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_register' && $canMark) {
    verifyCsrf();
    $marks   = $_POST['status']    ?? [];
    $clockIn = $_POST['clock_in']  ?? [];
    $clockOut= $_POST['clock_out'] ?? [];
    $notes   = $_POST['notes']     ?? [];
    $postDate = $_POST['date'] ?? $date;
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $postDate) || strtotime($postDate) > strtotime(date('Y-m-d'))) {
        $postDate = date('Y-m-d');
    }

    $saved = 0;
    try {
        hrEnsureAttendanceKey($db);

        $ins = $db->prepare("INSERT INTO attendance_records
                (staff_type, staff_id, attendance_date, status, clock_in, clock_out, notes, recorded_by)
             VALUES (?,?,?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE
                status=VALUES(status), clock_in=VALUES(clock_in), clock_out=VALUES(clock_out),
                notes=VALUES(notes), recorded_by=VALUES(recorded_by)");

        foreach ($marks as $key => $status) {
            if (!isset($statuses[$status])) continue;
            $p = hrParseKey($key);
            if (!$p) continue;
            $ins->execute([
                $p['type'], $p['id'], $postDate, $status,
                trim($clockIn[$key]  ?? '') ?: null,
                trim($clockOut[$key] ?? '') ?: null,
                trim($notes[$key]    ?? '') ?: null,
                (int)(authUser()['id'] ?? 0),
            ]);
            $saved++;
        }
        logActivity('update', 'attendance', 0, "Recorded attendance for {$saved} staff on {$postDate}");
        setFlash('success', "Register saved for {$saved} staff on " . date('j M Y', strtotime($postDate)) . '.');
    } catch (\Throwable $e) {
        error_log('hr/attendance save: ' . $e->getMessage());
        setFlash('error', 'Could not save the register: ' . $e->getMessage());
    }
    redirect(BASE_URL . '/modules/hr/attendance.php?date=' . urlencode($postDate) . ($fType ? '&type=' . $fType : ''));
}

$staff = hrStaffDirectory($db, ['type' => $fType]);

// Existing marks for this date
$existing = [];
try {
    $st = $db->prepare("SELECT * FROM attendance_records WHERE attendance_date = ?");
    $st->execute([$date]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $existing[hrKey($r['staff_type'], (int)$r['staff_id'])] = $r;
    }
} catch (\Throwable $_) {}

// Anyone on approved leave covering this date is pre-selected as 'leave' — HR
// should not have to re-key a decision they already approved.
$autoLeave = [];
try {
    $st = $db->prepare("SELECT staff_type, staff_id FROM leave_requests
                        WHERE status='approved' AND ? BETWEEN start_date AND end_date");
    $st->execute([$date]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $autoLeave[hrKey($r['staff_type'], (int)$r['staff_id'])] = true;
    }
} catch (\Throwable $_) {}

$tally = array_fill_keys(array_keys($statuses), 0);
foreach ($staff as $k => $s) {
    if (isset($existing[$k])) $tally[$existing[$k]['status']] = ($tally[$existing[$k]['status']] ?? 0) + 1;
}
$marked   = count(array_intersect_key($existing, $staff));
$unmarked = count($staff) - $marked;

$pageTitle = 'Attendance Register';
include __DIR__ . '/../../includes/header.php';
?>
<style>
.at-head{ display:flex; justify-content:space-between; align-items:center; gap:14px; flex-wrap:wrap; margin-bottom:16px; }
.at-head h1{ font-size:21px; font-weight:800; letter-spacing:-.4px; margin:0; color:var(--text); }
.at-head p{ font-size:13px; color:var(--text-2,#64748b); margin:2px 0 0; }

.at-bar{
    background:var(--surface); border:1px solid var(--border); border-radius:var(--r-lg);
    padding:14px 16px; margin-bottom:16px; box-shadow:var(--sh-sm);
    display:flex; gap:14px; align-items:flex-end; flex-wrap:wrap;
}
.at-tally{ display:flex; gap:8px; flex-wrap:wrap; margin-left:auto; }
.at-pill{
    display:inline-flex; align-items:center; gap:6px; padding:6px 12px; border-radius:20px;
    font-size:12px; font-weight:600; border:1px solid var(--border); background:var(--surface-alt); color:var(--text);
}
.at-pill b{ font-weight:800; }

.at-card{ background:var(--surface); border:1px solid var(--border); border-radius:var(--r-lg); box-shadow:var(--sh-sm); overflow:hidden; }
.at-row{
    display:grid; grid-template-columns:minmax(0,2.2fr) minmax(0,3fr) 96px 96px minmax(0,1.6fr);
    gap:12px; align-items:center; padding:11px 16px; border-bottom:1px solid var(--border);
}
.at-row:last-child{ border-bottom:0; }
.at-row.unmarked{ background:color-mix(in srgb, #f59e0b 6%, var(--surface)); }
@media(max-width:900px){ .at-row{ grid-template-columns:minmax(0,1fr); } }

.at-person{ display:flex; align-items:center; gap:10px; min-width:0; }
.at-avatar{ width:34px; height:34px; border-radius:9px; flex:0 0 34px; color:#fff;
    display:flex; align-items:center; justify-content:center; font-size:12px; font-weight:800; }
.at-name{ font-size:13.5px; font-weight:600; color:var(--text); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.at-sub{ font-size:11.5px; color:var(--text-2,#64748b); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }

.at-opts{ display:flex; gap:5px; flex-wrap:wrap; }
.at-opt{ position:relative; }
.at-opt input{ position:absolute; opacity:0; pointer-events:none; }
.at-opt span{
    display:inline-flex; align-items:center; gap:5px; padding:5px 10px; border-radius:7px; cursor:pointer;
    font-size:11.5px; font-weight:600; border:1px solid var(--border); background:var(--surface-alt);
    color:var(--text-2,#64748b); user-select:none; transition:.12s;
}
.at-opt input:checked + span{ color:#fff; border-color:transparent; }
.at-opt input:focus-visible + span{ outline:2px solid var(--brand); outline-offset:2px; }
.at-opt:hover span{ border-color:var(--brand); }

.at-head-row{
    display:grid; grid-template-columns:minmax(0,2.2fr) minmax(0,3fr) 96px 96px minmax(0,1.6fr);
    gap:12px; padding:9px 16px; background:var(--surface-alt); border-bottom:1px solid var(--border);
    font-size:10.5px; font-weight:800; letter-spacing:.6px; text-transform:uppercase; color:var(--text-2,#64748b);
}
@media(max-width:900px){ .at-head-row{ display:none; } }
.at-sticky{
    position:sticky; bottom:0; background:var(--surface); border-top:1px solid var(--border);
    padding:12px 16px; display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap;
}
.at-empty{ text-align:center; padding:44px 20px; color:var(--text-2,#64748b); }
.at-empty i{ font-size:34px; opacity:.3; display:block; margin-bottom:12px; }
</style>

<div class="at-head">
    <div>
        <h1><i class="fa fa-calendar-check me-2" style="color:var(--brand)"></i>Attendance Register</h1>
        <p><?= date('l, j F Y', strtotime($date)) ?><?= $date === date('Y-m-d') ? ' — today' : '' ?></p>
    </div>
    <div class="d-flex gap-2">
        <?php if (canAccess('attendance')): ?>
        <a href="<?= BASE_URL ?>/modules/attendance/report.php" class="btn btn-sm btn-outline-primary">
            <i class="fa fa-chart-column me-1"></i>Monthly report
        </a>
        <?php endif; ?>
        <a href="<?= BASE_URL ?>/modules/hr/index.php" class="btn btn-sm btn-outline-secondary">
            <i class="fa fa-arrow-left me-1"></i>HR Dashboard
        </a>
    </div>
</div>

<form method="GET" class="at-bar">
    <div>
        <label class="form-label small mb-1">Date</label>
        <input type="date" name="date" value="<?= e($date) ?>" max="<?= date('Y-m-d') ?>"
               class="form-control form-control-sm" style="width:170px" onchange="this.form.submit()">
    </div>
    <div>
        <label class="form-label small mb-1">Staff group</label>
        <select name="type" class="form-select form-select-sm" style="width:170px" onchange="this.form.submit()">
            <option value="">Everyone</option>
            <?php foreach (hrStaffTypes() as $k => $t): ?>
            <option value="<?= $k ?>" <?= $fType === $k ? 'selected' : '' ?>><?= $t['label'] ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="at-tally">
        <?php foreach ($statuses as $k => [$l, $c, $ic]): ?>
        <span class="at-pill"><i class="fa <?= $ic ?>" style="color:<?= $c ?>"></i><?= $l ?> <b><?= (int)$tally[$k] ?></b></span>
        <?php endforeach; ?>
        <?php if ($unmarked > 0): ?>
        <span class="at-pill" style="border-color:#f59e0b;color:#b45309"><i class="fa fa-circle-question"></i>Not marked <b><?= $unmarked ?></b></span>
        <?php endif; ?>
    </div>
</form>

<?php if (!$staff): ?>
    <div class="at-card"><div class="at-empty">
        <i class="fa fa-users-slash"></i>
        No staff to mark<?= $fType ? ' in this group' : '' ?>.
    </div></div>
<?php else: ?>
<form method="POST">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="save_register">
    <input type="hidden" name="date" value="<?= e($date) ?>">

    <div class="at-card">
        <div class="at-head-row">
            <div>Employee</div><div>Status</div><div>In</div><div>Out</div><div>Note</div>
        </div>

        <?php foreach ($staff as $k => $s):
            $rec = $existing[$k] ?? null;
            // Precedence: what was actually recorded wins; otherwise an approved
            // leave pre-fills; otherwise nothing is selected so a blank register
            // is visibly blank rather than silently "all present".
            $sel = $rec['status'] ?? (isset($autoLeave[$k]) ? 'leave' : '');
            $ti  = hrStaffTypes()[$s['staff_type']];
        ?>
        <div class="at-row <?= $rec ? '' : 'unmarked' ?>">
            <div class="at-person">
                <div class="at-avatar" style="background:<?= hrAvatarColor($k) ?>"><?= e(hrInitials($s['name'])) ?></div>
                <div style="min-width:0">
                    <div class="at-name"><?= e($s['name']) ?></div>
                    <div class="at-sub"><?= e($s['job_title'] ?: $ti['label']) ?></div>
                </div>
            </div>

            <div class="at-opts">
                <?php foreach ($statuses as $sk => [$sl, $sc, $si]): ?>
                <label class="at-opt">
                    <input type="radio" name="status[<?= e($k) ?>]" value="<?= $sk ?>"
                           <?= $sel === $sk ? 'checked' : '' ?> <?= $canMark ? '' : 'disabled' ?>>
                    <span style="<?= $sel === $sk ? "background:{$sc}" : '' ?>"><i class="fa <?= $si ?>"></i><?= $sl ?></span>
                </label>
                <?php endforeach; ?>
            </div>

            <div><input type="time" name="clock_in[<?= e($k) ?>]" class="form-control form-control-sm"
                        value="<?= e(substr((string)($rec['clock_in'] ?? ''), 0, 5)) ?>" <?= $canMark ? '' : 'disabled' ?>></div>
            <div><input type="time" name="clock_out[<?= e($k) ?>]" class="form-control form-control-sm"
                        value="<?= e(substr((string)($rec['clock_out'] ?? ''), 0, 5)) ?>" <?= $canMark ? '' : 'disabled' ?>></div>
            <div><input type="text" name="notes[<?= e($k) ?>]" class="form-control form-control-sm"
                        value="<?= e((string)($rec['notes'] ?? '')) ?>" placeholder="Optional" <?= $canMark ? '' : 'disabled' ?>></div>
        </div>
        <?php endforeach; ?>

        <?php if ($canMark): ?>
        <div class="at-sticky">
            <div class="small text-muted">
                <?= count($staff) ?> staff &middot; <?= $marked ?> already marked
                <?php if ($unmarked): ?>&middot; <span style="color:#b45309"><?= $unmarked ?> still blank</span><?php endif; ?>
            </div>
            <div class="d-flex gap-2">
                <button type="button" class="btn btn-sm btn-outline-success" id="markAllPresent">
                    <i class="fa fa-check-double me-1"></i>Mark all present
                </button>
                <button class="btn btn-sm btn-primary"><i class="fa fa-floppy-disk me-1"></i>Save register</button>
            </div>
        </div>
        <?php endif; ?>
    </div>
</form>

<script>
(function () {
    // Recolour the chip as soon as a status is picked, so the register reads at
    // a glance while it is being filled in rather than only after saving.
    var colors = <?= json_encode(array_map(fn($s) => $s[1], $statuses)) ?>;
    document.addEventListener('change', function (e) {
        var el = e.target;
        if (!el.matches('.at-opt input[type=radio]')) return;
        document.querySelectorAll('input[name="' + CSS.escape(el.name) + '"]').forEach(function (r) {
            var chip = r.nextElementSibling;
            if (chip) chip.style.background = (r === el && r.checked) ? (colors[r.value] || '') : '';
        });
        var row = el.closest('.at-row');
        if (row) row.classList.remove('unmarked');
    });

    var btn = document.getElementById('markAllPresent');
    if (btn) btn.addEventListener('click', function () {
        // Only fills the blanks — a status already recorded is a decision
        // someone made and is not overwritten by a bulk action.
        document.querySelectorAll('.at-row').forEach(function (row) {
            if (row.querySelector('input[type=radio]:checked')) return;
            var p = row.querySelector('input[type=radio][value=present]');
            if (p) { p.checked = true; p.dispatchEvent(new Event('change', { bubbles: true })); }
        });
    });
})();
</script>
<?php endif; ?>

<?php include __DIR__ . '/../../includes/footer.php'; ?>

<?php
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
canAccess('attendance') || redirect(BASE_URL . '/index.php');

$pageTitle = 'HR Command Centre';
$db        = getDB();
$me        = authUser();
$isManager = hasRole(['admin','hr_manager','general_manager','workshop_manager','finance_manager']);
$today     = date('Y-m-d');
$thisMonth = (int)date('n');
$thisYear  = (int)date('Y');
$monthName = date('F Y');

// ── Staff roll call ────────────────────────────────────────────────────────────
try {
    $mechanics = $db->query("SELECT id, 'mechanic' AS stype, name FROM mechanics WHERE status='active' ORDER BY name")->fetchAll();
    $drivers   = $db->query("SELECT id, 'driver'   AS stype, name FROM drivers   WHERE status='active' ORDER BY name")->fetchAll();
    $allStaff  = array_merge($mechanics, $drivers);
    $totalStaff = count($allStaff);

    // Today's attendance records
    $todayRecs = $db->query("SELECT staff_type, staff_id, status, clock_in, clock_out FROM attendance_records WHERE attendance_date='{$today}'")->fetchAll();
    $attMap = [];
    foreach ($todayRecs as $r) $attMap[$r['staff_type'].'_'.$r['staff_id']] = $r;

    // Aggregate counts
    $cntPresent = $cntLate = $cntAbsent = $cntLeave = $cntHalf = $cntNone = 0;
    foreach ($allStaff as $s) {
        $a = $attMap[$s['stype'].'_'.$s['id']] ?? null;
        if (!$a) { $cntNone++; continue; }
        match($a['status']) {
            'present'  => $cntPresent++,
            'late'     => $cntLate++,
            'absent'   => $cntAbsent++,
            'leave'    => $cntLeave++,
            'half_day' => $cntHalf++,
            default    => $cntNone++,
        };
    }
    $attRate = $totalStaff > 0 ? round(($cntPresent + $cntLate + $cntHalf) / $totalStaff * 100) : 0;

} catch (\Throwable $e) {
    $allStaff = []; $totalStaff = 0; $attMap = [];
    $cntPresent = $cntLate = $cntAbsent = $cntLeave = $cntHalf = $cntNone = 0; $attRate = 0;
}

// ── Pending leave requests ─────────────────────────────────────────────────────
try {
    $pendingLeave = $db->query("SELECT * FROM leave_requests WHERE status='pending' ORDER BY created_at ASC LIMIT 15")->fetchAll();
    $pendingCount = count($pendingLeave);
} catch (\Throwable $e) { $pendingLeave = []; $pendingCount = 0; }

// ── Active leave today ─────────────────────────────────────────────────────────
try {
    $onLeaveToday = $db->query("SELECT user_name, leave_type FROM leave_requests WHERE status='approved' AND start_date <= '{$today}' AND end_date >= '{$today}' ORDER BY user_name")->fetchAll();
} catch (\Throwable $e) { $onLeaveToday = []; }

// ── Payroll status ─────────────────────────────────────────────────────────────
try {
    $pr = $db->prepare("SELECT * FROM payroll_runs WHERE period_month=? AND period_year=? LIMIT 1");
    $pr->execute([$thisMonth, $thisYear]); $payrollRun = $pr->fetch();
    $staffWithSalary = (int)$db->query("SELECT COUNT(DISTINCT staff_id) FROM staff_salaries WHERE status='active'")->fetchColumn();
} catch (\Throwable $e) { $payrollRun = null; $staffWithSalary = 0; }

// ── Upcoming leave (next 21 days) ──────────────────────────────────────────────
try {
    $upcomingLeave = $db->query("
        SELECT user_name, leave_type, start_date, end_date, days_count, status
        FROM leave_requests
        WHERE status = 'approved'
          AND end_date   >= '{$today}'
          AND start_date <= DATE_ADD('{$today}', INTERVAL 21 DAY)
        ORDER BY start_date ASC
        LIMIT 20
    ")->fetchAll();
} catch (\Throwable $e) { $upcomingLeave = []; }

// ── This month attendance summary ──────────────────────────────────────────────
try {
    $monthSummary = $db->prepare("
        SELECT staff_type, staff_id,
            SUM(status='present')  AS presents,
            SUM(status='late')     AS lates,
            SUM(status='absent')   AS absents,
            SUM(status='leave')    AS leaves,
            SUM(status='half_day') AS halfdays
        FROM attendance_records
        WHERE MONTH(attendance_date)=? AND YEAR(attendance_date)=?
        GROUP BY staff_type, staff_id
    ");
    $monthSummary->execute([$thisMonth, $thisYear]);
    $summaryMap = [];
    foreach ($monthSummary->fetchAll() as $r) $summaryMap[$r['staff_type'].'_'.$r['staff_id']] = $r;
} catch (\Throwable $e) { $summaryMap = []; }

// Working days elapsed this month
$daysElapsed = min((int)date('j'), cal_days_in_month(CAL_GREGORIAN, $thisMonth, $thisYear));
// Count weekdays elapsed
$wdElapsed = 0;
for ($d = 1; $d <= $daysElapsed; $d++) {
    $dow = date('N', mktime(0,0,0,$thisMonth,$d,$thisYear));
    if ($dow <= 5) $wdElapsed++;
}

$leaveTypeLabels = [
    'annual'=>'Annual','sick'=>'Sick','emergency'=>'Emergency',
    'maternity'=>'Maternity','paternity'=>'Paternity','unpaid'=>'Unpaid','study'=>'Study',
];

include __DIR__ . '/../../includes/header.php';

$hour     = (int)date('G');
$greeting = $hour < 12 ? 'Good morning' : ($hour < 17 ? 'Good afternoon' : 'Good evening');
$initials = implode('', array_map(fn($w) => strtoupper($w[0]), array_slice(explode(' ', $me['name']), 0, 2)));

$statusConfig = [
    'present'  => ['Present',      '#16a34a','#dcfce7','fa-circle-check'],
    'late'     => ['Late',         '#d97706','#fef3c7','fa-clock'],
    'absent'   => ['Absent',       '#dc2626','#fee2e2','fa-circle-xmark'],
    'leave'    => ['On Leave',     '#7c3aed','#f3e8ff','fa-umbrella-beach'],
    'half_day' => ['Half Day',     '#0891b2','#ecfeff','fa-circle-half-stroke'],
];
?>

<style>
/* HR Dashboard ───────────────────────────────────────── */
.hr-hero {
    background: linear-gradient(135deg, #1e3a8a 0%, #1d4ed8 55%, #3b82f6 100%);
    border-radius: 16px; padding: 22px 28px;
    color: #fff; display: flex; align-items: center; gap: 20px;
    margin-bottom: 22px; position: relative; overflow: hidden;
}
.hr-hero::before { content:''; position:absolute; top:-50px; right:-50px; width:220px; height:220px; background:rgba(255,255,255,.05); border-radius:50%; }
.hr-hero-avatar { width:52px;height:52px;border-radius:13px;background:rgba(255,255,255,.18);border:2px solid rgba(255,255,255,.3);display:flex;align-items:center;justify-content:center;font-size:20px;font-weight:800;flex-shrink:0; }
.hr-hero-body h4 { margin:0 0 2px;font-size:19px;font-weight:700; }
.hr-hero-body p  { margin:0;font-size:13px;opacity:.8; }
.hr-hero-actions { margin-left:auto;display:flex;gap:10px;flex-shrink:0; }
@media(max-width:640px){.hr-hero-actions{display:none}}

.hr-kpi { background:var(--surface);border-radius:14px;padding:18px 20px;border:1px solid var(--border);display:flex;align-items:center;gap:14px; }
.hr-kpi-icon { width:48px;height:48px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:20px;flex-shrink:0; }
.hr-kpi-label { font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--text-3);margin-bottom:2px; }
.hr-kpi-val   { font-size:28px;font-weight:800;line-height:1;color:var(--text); }
.hr-kpi-sub   { font-size:11px;color:var(--text-3);margin-top:3px; }

/* Roll call table */
.roll-status {
    display: inline-flex; align-items: center; gap: 5px;
    font-size: 12px; font-weight: 700; padding: 3px 9px;
    border-radius: 20px; white-space: nowrap;
}
/* Leave calendar strip */
.leave-strip-item { display:flex; align-items:center; gap:10px; padding:8px 0; border-bottom:1px solid var(--border); font-size:13px; }
.leave-strip-item:last-child { border-bottom:none; }
.leave-dot { width:8px;height:8px;border-radius:50%;flex-shrink:0; }

/* Payroll status card */
.pr-status-pill { display:inline-flex;align-items:center;gap:6px;padding:4px 12px;border-radius:20px;font-size:12px;font-weight:700; }
</style>

<!-- Hero banner -->
<div class="hr-hero mb-4">
    <div class="hr-hero-avatar"><?= e($initials) ?></div>
    <div class="hr-hero-body">
        <h4><?= e($greeting) ?>, <?= e(explode(' ', $me['name'])[0]) ?></h4>
        <p><?= date('l, j F Y') ?> &nbsp;·&nbsp; HR Command Centre</p>
    </div>
    <div class="hr-hero-actions">
        <a href="index.php" class="btn btn-light btn-sm fw-semibold">
            <i class="fa fa-calendar-days me-1"></i>Attendance
        </a>
        <a href="../team/leave_calendar.php" class="btn btn-sm fw-semibold" style="background:rgba(255,255,255,.15);color:#fff;border:1px solid rgba(255,255,255,.25)">
            <i class="fa fa-calendar-week me-1"></i>Leave Calendar
        </a>
        <?php if ($isManager): ?>
        <a href="../payroll/index.php" class="btn btn-sm fw-semibold" style="background:rgba(255,255,255,.15);color:#fff;border:1px solid rgba(255,255,255,.25)">
            <i class="fa fa-money-bill-wave me-1"></i>Payroll
        </a>
        <?php endif; ?>
    </div>
</div>

<!-- KPI strip -->
<div class="row g-3 mb-4">
    <div class="col-6 col-xl-3">
        <div class="hr-kpi" style="border-top:3px solid #2563eb">
            <div class="hr-kpi-icon" style="background:#dbeafe;color:#2563eb"><i class="fa fa-users"></i></div>
            <div>
                <div class="hr-kpi-label">Total Staff</div>
                <div class="hr-kpi-val"><?= $totalStaff ?></div>
                <div class="hr-kpi-sub"><?= $staffWithSalary ?> with salary profiles</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-xl-3">
        <div class="hr-kpi" style="border-top:3px solid #16a34a">
            <div class="hr-kpi-icon" style="background:#dcfce7;color:#16a34a"><i class="fa fa-circle-check"></i></div>
            <div>
                <div class="hr-kpi-label">Present Today</div>
                <div class="hr-kpi-val"><?= $cntPresent + $cntLate + $cntHalf ?></div>
                <div class="hr-kpi-sub"><?= $attRate ?>% attendance rate</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-xl-3">
        <div class="hr-kpi" style="border-top:3px solid <?= $cntAbsent > 0 ? '#dc2626' : '#64748b' ?>">
            <div class="hr-kpi-icon" style="background:<?= $cntAbsent > 0 ? '#fee2e2' : '#f8fafc' ?>;color:<?= $cntAbsent > 0 ? '#dc2626' : '#64748b' ?>"><i class="fa fa-circle-xmark"></i></div>
            <div>
                <div class="hr-kpi-label">Absent Today</div>
                <div class="hr-kpi-val" style="color:<?= $cntAbsent > 0 ? '#dc2626' : 'inherit' ?>"><?= $cntAbsent ?></div>
                <div class="hr-kpi-sub"><?= $cntNone ?> not recorded yet</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-xl-3">
        <div class="hr-kpi" style="border-top:3px solid <?= $pendingCount > 0 ? '#d97706' : '#16a34a' ?>">
            <div class="hr-kpi-icon" style="background:<?= $pendingCount > 0 ? '#fef3c7' : '#dcfce7' ?>;color:<?= $pendingCount > 0 ? '#d97706' : '#16a34a' ?>"><i class="fa fa-umbrella-beach"></i></div>
            <div>
                <div class="hr-kpi-label">Leave Pending</div>
                <div class="hr-kpi-val" style="color:<?= $pendingCount > 0 ? '#d97706' : 'inherit' ?>"><?= $pendingCount ?></div>
                <div class="hr-kpi-sub"><?= count($onLeaveToday) ?> on leave today</div>
            </div>
        </div>
    </div>
</div>

<!-- Main layout: Roll Call + Leave Queue | Payroll + Upcoming -->
<div class="row g-4 mb-4">

    <!-- Left: Today's Roll Call -->
    <div class="col-lg-8">
        <div class="card mb-4">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span class="fw-semibold"><i class="fa fa-clipboard-user me-2 text-primary"></i>Today's Roll Call
                    <span class="text-muted fw-normal small ms-2"><?= date('D, d M') ?></span>
                </span>
                <?php if (canWrite('attendance')): ?>
                <a href="index.php?month=<?= $thisMonth ?>&year=<?= $thisYear ?>" class="btn btn-xs btn-primary">
                    <i class="fa fa-pencil me-1"></i>Mark Attendance
                </a>
                <?php endif; ?>
            </div>
            <div class="card-body p-0" style="max-height:380px;overflow-y:auto">
                <table class="table table-hover mb-0" style="font-size:13px">
                    <thead style="position:sticky;top:0;z-index:1;background:var(--surface)">
                        <tr>
                            <th class="ps-3">Name</th>
                            <th>Role</th>
                            <th>Status</th>
                            <th>Clock In</th>
                            <th class="pe-3">Clock Out</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($allStaff as $s):
                        $key = $s['stype'].'_'.$s['id'];
                        $att = $attMap[$key] ?? null;
                        $status = $att['status'] ?? 'not_recorded';
                        $cfg = $statusConfig[$status] ?? ['Not Recorded', '#94a3b8', '#f1f5f9', 'fa-circle-minus'];
                    ?>
                    <tr>
                        <td class="ps-3 fw-semibold"><?= e($s['name']) ?></td>
                        <td><span class="badge bg-light text-dark border" style="font-size:11px"><?= ucfirst($s['stype']) ?></span></td>
                        <td>
                            <span class="roll-status" style="background:<?= $cfg[2] ?>;color:<?= $cfg[1] ?>">
                                <i class="fa <?= $cfg[3] ?>"></i><?= $cfg[0] ?>
                            </span>
                        </td>
                        <td class="text-muted small"><?= $att['clock_in']  ? substr($att['clock_in'],0,5)  : '—' ?></td>
                        <td class="pe-3 text-muted small"><?= $att['clock_out'] ? substr($att['clock_out'],0,5) : '—' ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($allStaff)): ?>
                    <tr><td colspan="5" class="text-center py-4 text-muted">No active staff found.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <!-- Summary bar -->
            <div class="card-footer py-2 d-flex flex-wrap gap-3" style="font-size:12px">
                <span class="text-success fw-semibold"><i class="fa fa-circle-check me-1"></i>Present <?= $cntPresent ?></span>
                <span style="color:#d97706;font-weight:600"><i class="fa fa-clock me-1"></i>Late <?= $cntLate ?></span>
                <span class="text-danger fw-semibold"><i class="fa fa-circle-xmark me-1"></i>Absent <?= $cntAbsent ?></span>
                <span style="color:#7c3aed;font-weight:600"><i class="fa fa-umbrella-beach me-1"></i>Leave <?= $cntLeave ?></span>
                <span class="text-muted"><i class="fa fa-circle-minus me-1"></i>Unrecorded <?= $cntNone ?></span>
            </div>
        </div>

        <!-- Pending Leave Requests -->
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span class="fw-semibold">
                    <i class="fa fa-inbox me-2 text-warning"></i>Pending Leave Requests
                    <?php if ($pendingCount > 0): ?>
                    <span class="badge bg-warning text-dark ms-1"><?= $pendingCount ?></span>
                    <?php endif; ?>
                </span>
                <a href="../team/leave.php?tab=all" class="btn btn-xs btn-outline-secondary">All Requests</a>
            </div>
            <?php if (empty($pendingLeave)): ?>
            <div class="card-body text-center text-muted py-4">
                <i class="fa fa-circle-check fa-lg text-success mb-2 d-block"></i>No pending leave requests.
            </div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover mb-0" style="font-size:13px">
                    <thead>
                        <tr>
                            <th class="ps-3">Employee</th>
                            <th>Type</th>
                            <th>Period</th>
                            <th>Days</th>
                            <th>Requested</th>
                            <?php if ($isManager): ?><th class="pe-3">Actions</th><?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($pendingLeave as $lr): ?>
                    <tr>
                        <td class="ps-3 fw-semibold"><?= e($lr['user_name']) ?></td>
                        <td>
                            <span class="badge bg-light text-dark border" style="font-size:11px">
                                <?= e($leaveTypeLabels[$lr['leave_type']] ?? $lr['leave_type']) ?>
                            </span>
                        </td>
                        <td class="small text-muted">
                            <?= fmtDate($lr['start_date'],'d M') ?> – <?= fmtDate($lr['end_date'],'d M Y') ?>
                        </td>
                        <td class="fw-semibold"><?= number_format((float)$lr['days_count'],1) ?></td>
                        <td class="text-muted small"><?= fmtDate($lr['created_at'],'d M') ?></td>
                        <?php if ($isManager): ?>
                        <td class="pe-3">
                            <div class="d-flex gap-1">
                                <form method="POST" action="../team/leave.php" class="d-inline">
                                    <input type="hidden" name="action"   value="approve">
                                    <input type="hidden" name="leave_id" value="<?= $lr['id'] ?>">
                                    <button class="btn btn-xs btn-success" onclick="return confirm('Approve this leave?')">
                                        <i class="fa fa-check"></i>
                                    </button>
                                </form>
                                <button class="btn btn-xs btn-danger" data-bs-toggle="modal"
                                        data-bs-target="#rejectModal"
                                        data-id="<?= $lr['id'] ?>"
                                        data-name="<?= e($lr['user_name']) ?>">
                                    <i class="fa fa-xmark"></i>
                                </button>
                            </div>
                        </td>
                        <?php endif; ?>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Right: Payroll + Upcoming Leave -->
    <div class="col-lg-4">

        <!-- Payroll Status -->
        <div class="card mb-4">
            <div class="card-header fw-semibold">
                <i class="fa fa-money-bill-wave me-2 text-primary"></i><?= $monthName ?> Payroll
            </div>
            <div class="card-body">
                <?php if ($payrollRun): ?>
                <?php
                $prStatus = $payrollRun['status'];
                $prColors = ['draft'=>['#d97706','#fffbeb'],'approved'=>['#2563eb','#dbeafe'],'paid'=>['#16a34a','#dcfce7']];
                [$prC, $prBg] = $prColors[$prStatus] ?? ['#64748b','#f8fafc'];
                ?>
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <span class="pr-status-pill" style="background:<?= $prBg ?>;color:<?= $prC ?>">
                        <i class="fa fa-<?= $prStatus === 'paid' ? 'circle-check' : ($prStatus === 'approved' ? 'thumbs-up' : 'pencil') ?>"></i>
                        <?= ucfirst($prStatus) ?>
                    </span>
                    <span class="fw-bold text-success"><?= money((float)$payrollRun['total_net']) ?></span>
                </div>
                <div class="d-flex justify-content-between small text-muted mb-1">
                    <span>Gross Pay</span><span><?= money((float)$payrollRun['total_gross']) ?></span>
                </div>
                <div class="d-flex justify-content-between small text-muted mb-3">
                    <span>Deductions</span><span class="text-danger">-<?= money((float)$payrollRun['total_deductions']) ?></span>
                </div>
                <?php
                $stages = ['draft','approved','paid'];
                $curIdx = array_search($prStatus, $stages);
                ?>
                <div class="d-flex gap-1 mb-3">
                    <?php foreach ($stages as $i => $s): ?>
                    <div class="flex-fill text-center" style="font-size:10px;font-weight:700">
                        <div style="height:4px;border-radius:4px;background:<?= $i <= $curIdx ? $prC : '#e2e8f0' ?>;margin-bottom:3px"></div>
                        <?= ucfirst($s) ?>
                    </div>
                    <?php endforeach; ?>
                </div>
                <a href="../payroll/run.php?id=<?= $payrollRun['id'] ?>" class="btn btn-sm btn-outline-primary w-100">
                    <i class="fa fa-external-link me-1"></i>View Payroll Run
                </a>
                <?php else: ?>
                <div class="text-center py-3">
                    <i class="fa fa-file-invoice-dollar fa-2x text-muted d-block mb-2 opacity-50"></i>
                    <p class="text-muted small mb-3">No payroll run for <?= $monthName ?> yet.</p>
                    <?php if (canWrite('payroll')): ?>
                    <a href="../payroll/create.php" class="btn btn-primary btn-sm">
                        <i class="fa fa-plus me-1"></i>Create Payroll Run
                    </a>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Upcoming Leave -->
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center fw-semibold">
                <span><i class="fa fa-calendar-week me-2 text-primary"></i>Upcoming Leave</span>
                <a href="../team/leave_calendar.php" class="btn btn-xs btn-outline-secondary">Calendar</a>
            </div>
            <?php if (empty($upcomingLeave)): ?>
            <div class="card-body text-center text-muted py-4">
                <i class="fa fa-calendar-check fa-lg text-success d-block mb-2"></i>No upcoming leave in the next 21 days.
            </div>
            <?php else: ?>
            <div class="card-body py-2">
                <?php foreach ($upcomingLeave as $ul):
                    $isNow = $ul['start_date'] <= $today && $ul['end_date'] >= $today;
                    $typeColors = ['annual'=>'#2563eb','sick'=>'#dc2626','emergency'=>'#d97706','maternity'=>'#9333ea','paternity'=>'#0891b2','unpaid'=>'#64748b','study'=>'#16a34a'];
                    $ulColor = $typeColors[$ul['leave_type']] ?? '#64748b';
                ?>
                <div class="leave-strip-item">
                    <div class="leave-dot" style="background:<?= $ulColor ?>"></div>
                    <div class="flex-grow-1 min-w-0">
                        <div class="fw-semibold text-truncate"><?= e($ul['user_name']) ?></div>
                        <div class="text-muted" style="font-size:11px">
                            <?= $leaveTypeLabels[$ul['leave_type']] ?? $ul['leave_type'] ?> &bull;
                            <?= fmtDate($ul['start_date'],'d M') ?> – <?= fmtDate($ul['end_date'],'d M') ?>
                            (<?= number_format((float)$ul['days_count'],1) ?>d)
                        </div>
                    </div>
                    <?php if ($isNow): ?>
                    <span class="badge bg-primary" style="font-size:10px">Now</span>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>

    </div>
</div>

<!-- Monthly Summary -->
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center fw-semibold">
        <span><i class="fa fa-chart-bar me-2 text-primary"></i><?= $monthName ?> Attendance Summary</span>
        <a href="report.php?month=<?= $thisMonth ?>&year=<?= $thisYear ?>" class="btn btn-xs btn-outline-secondary">Full Report</a>
    </div>
    <div class="card-body p-0">
        <table class="table table-hover mb-0" style="font-size:13px">
            <thead>
                <tr>
                    <th class="ps-3">Staff</th>
                    <th>Type</th>
                    <th class="text-center text-success">Present</th>
                    <th class="text-center" style="color:#d97706">Late</th>
                    <th class="text-center text-danger">Absent</th>
                    <th class="text-center" style="color:#7c3aed">Leave</th>
                    <th class="text-center">Rate</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($allStaff as $s):
                $key = $s['stype'].'_'.$s['id'];
                $sm  = $summaryMap[$key] ?? ['presents'=>0,'lates'=>0,'absents'=>0,'leaves'=>0,'halfdays'=>0];
                $worked = (int)$sm['presents'] + (int)$sm['lates'];
                $rate   = $wdElapsed > 0 ? round($worked / $wdElapsed * 100) : 0;
                $rateColor = $rate >= 90 ? '#16a34a' : ($rate >= 75 ? '#d97706' : '#dc2626');
            ?>
            <tr>
                <td class="ps-3 fw-medium"><?= e($s['name']) ?></td>
                <td><span class="badge bg-light text-dark border" style="font-size:10px"><?= ucfirst($s['stype']) ?></span></td>
                <td class="text-center fw-bold text-success"><?= (int)$sm['presents'] ?></td>
                <td class="text-center fw-bold" style="color:#d97706"><?= (int)$sm['lates'] ?></td>
                <td class="text-center fw-bold text-danger"><?= (int)$sm['absents'] ?></td>
                <td class="text-center fw-bold" style="color:#7c3aed"><?= (int)$sm['leaves'] ?></td>
                <td class="text-center">
                    <span style="font-size:12px;font-weight:700;color:<?= $rateColor ?>"><?= $rate ?>%</span>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($allStaff)): ?>
            <tr><td colspan="7" class="text-center py-4 text-muted">No staff data available.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Reject Modal -->
<?php if ($isManager): ?>
<div class="modal fade" id="rejectModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="POST" action="../team/leave.php">
                <input type="hidden" name="action"   value="reject">
                <input type="hidden" name="leave_id" id="rejectLeaveId">
                <div class="modal-header border-0 pb-0">
                    <h5 class="modal-title text-danger"><i class="fa fa-circle-xmark me-2"></i>Reject Leave Request</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p class="text-muted mb-3">Rejecting leave for <strong id="rejectName"></strong>. Please provide a reason:</p>
                    <textarea name="rejection_reason" class="form-control" rows="3" required
                              placeholder="Reason for rejection…"></textarea>
                </div>
                <div class="modal-footer border-0">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger">Reject Leave</button>
                </div>
            </form>
        </div>
    </div>
</div>
<script>
document.getElementById('rejectModal').addEventListener('show.bs.modal', function (e) {
    var btn = e.relatedTarget;
    document.getElementById('rejectLeaveId').value = btn.getAttribute('data-id');
    document.getElementById('rejectName').textContent  = btn.getAttribute('data-name');
});
</script>
<?php endif; ?>

<?php include __DIR__ . '/../../includes/footer.php'; ?>

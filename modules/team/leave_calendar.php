<?php
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
canAccess('team') || canAccess('attendance') || redirect(BASE_URL . '/index.php');

$db   = getDB();
$user = authUser();
$isManager = hasRole(['admin','hr_manager','general_manager','workshop_manager','finance_manager','sales_manager']);

$month = (int)($_GET['month'] ?? date('n'));
$year  = (int)($_GET['year']  ?? date('Y'));
if ($month < 1 || $month > 12) $month = (int)date('n');
if ($year  < 2020 || $year  > 2099) $year  = (int)date('Y');

$pageTitle    = 'Leave Calendar';
$daysInMonth  = cal_days_in_month(CAL_GREGORIAN, $month, $year);
$firstDow     = (int)date('N', mktime(0,0,0,$month,1,$year)); // 1=Mon, 7=Sun
$today        = date('Y-m-d');
$monthLabel   = date('F Y', mktime(0,0,0,$month,1,$year));
$prevM = $month === 1 ? 12 : $month - 1; $prevY = $month === 1 ? $year - 1 : $year;
$nextM = $month === 12 ? 1 : $month + 1; $nextY = $month === 12 ? $year + 1 : $year;

// ── Leave data ──────────────────────────────────────────────────────────────────
try {
    $startOfMonth = sprintf('%04d-%02d-01', $year, $month);
    $endOfMonth   = sprintf('%04d-%02d-%02d', $year, $month, $daysInMonth);

    $leavesStmt = $db->prepare("
        SELECT id, leave_number, user_name, user_id, leave_type, start_date, end_date, days_count, status, reason
        FROM leave_requests
        WHERE status IN ('approved','pending')
          AND start_date <= ?
          AND end_date   >= ?
        ORDER BY user_name ASC, start_date ASC
    ");
    $leavesStmt->execute([$endOfMonth, $startOfMonth]);
    $allLeaves = $leavesStmt->fetchAll();

    // Map each calendar day to overlapping leave entries
    $dayMap = [];
    foreach ($allLeaves as $lr) {
        $s = max(1,  (int)date('j', strtotime(max($lr['start_date'], $startOfMonth))));
        $e = min($daysInMonth, (int)date('j', strtotime(min($lr['end_date'], $endOfMonth))));
        for ($d = $s; $d <= $e; $d++) $dayMap[$d][] = $lr;
    }

    // Summary stats for this month
    $totalApproved = count(array_filter($allLeaves, fn($l) => $l['status'] === 'approved'));
    $totalPending  = count(array_filter($allLeaves, fn($l) => $l['status'] === 'pending'));

    // List of people on leave today (if viewing current month)
    $onLeaveNow = [];
    if ($month === (int)date('n') && $year === (int)date('Y')) {
        $todayDay = (int)date('j');
        $onLeaveNow = $dayMap[$todayDay] ?? [];
        $onLeaveNow = array_filter($onLeaveNow, fn($l) => $l['status'] === 'approved');
    }
} catch (\Throwable $e) {
    $allLeaves = []; $dayMap = []; $totalApproved = $totalPending = 0; $onLeaveNow = [];
}

$leaveTypeColors = [
    'annual'    => ['#2563eb','#dbeafe'],
    'sick'      => ['#dc2626','#fee2e2'],
    'emergency' => ['#d97706','#fef3c7'],
    'maternity' => ['#9333ea','#f3e8ff'],
    'paternity' => ['#0891b2','#ecfeff'],
    'unpaid'    => ['#64748b','#f1f5f9'],
    'study'     => ['#16a34a','#dcfce7'],
];
$leaveTypeLabels = [
    'annual'=>'Annual','sick'=>'Sick','emergency'=>'Emergency',
    'maternity'=>'Maternity','paternity'=>'Paternity','unpaid'=>'Unpaid','study'=>'Study',
];
$dayNames = ['Mon','Tue','Wed','Thu','Fri','Sat','Sun'];

include __DIR__ . '/../../includes/header.php';
?>

<style>
.cal-nav   { display:flex;align-items:center;justify-content:space-between;margin-bottom:20px; }
.cal-title { font-size:20px;font-weight:800;color:var(--text); }
.cal-grid  { display:grid;grid-template-columns:repeat(7,1fr);gap:4px; }
.cal-day-hdr { text-align:center;font-size:11px;font-weight:700;color:var(--text-3);text-transform:uppercase;letter-spacing:.05em;padding:6px 0; }
.cal-day-hdr.weekend { color:#dc2626; }
.cal-cell {
    background:var(--surface);
    border:1px solid var(--border);
    border-radius:10px;
    min-height:100px;
    padding:8px;
    transition:box-shadow .15s;
    position:relative;
}
.cal-cell:hover { box-shadow:0 2px 8px rgba(0,0,0,.1); }
.cal-cell.today { border-color:#2563eb;border-width:2px; }
.cal-cell.weekend { background:#f8fafc; }
.cal-cell.other-month { opacity:0; pointer-events:none; }
.cal-day-num {
    font-size:13px;font-weight:700;color:var(--text);
    margin-bottom:4px;display:flex;align-items:center;gap:4px;
}
.cal-today-dot {
    width:6px;height:6px;border-radius:50%;background:#2563eb;flex-shrink:0;
}
.leave-pill {
    display:block;
    font-size:10.5px;font-weight:600;
    border-radius:6px;padding:2px 6px;
    margin-bottom:2px;
    white-space:nowrap;overflow:hidden;text-overflow:ellipsis;
    cursor:default;
}
.leave-pill.pending { opacity:.7; }
.more-badge { font-size:10px;color:var(--text-3);font-weight:600;margin-top:1px; }

/* List view */
.lv-item { display:flex;align-items:center;gap:12px;padding:10px 0;border-bottom:1px solid var(--border); }
.lv-item:last-child { border-bottom:none; }
.lv-bar  { width:4px;border-radius:4px;align-self:stretch;flex-shrink:0; }
.lv-dates { font-size:11.5px;color:var(--text-3); }
.lv-badge { font-size:11px;font-weight:700;padding:2px 10px;border-radius:20px; }
</style>

<!-- Header / navigation -->
<div class="cal-nav">
    <div>
        <h5 class="mb-1"><i class="fa fa-calendar-week me-2 text-primary"></i>Leave Calendar</h5>
        <div class="text-muted small"><?= $totalApproved ?> approved &bull; <?= $totalPending ?> pending this month</div>
    </div>
    <div class="d-flex align-items-center gap-3">
        <a href="?month=<?= $prevM ?>&year=<?= $prevY ?>" class="btn btn-sm btn-outline-secondary">
            <i class="fa fa-chevron-left"></i>
        </a>
        <span class="cal-title"><?= $monthLabel ?></span>
        <a href="?month=<?= $nextM ?>&year=<?= $nextY ?>" class="btn btn-sm btn-outline-secondary">
            <i class="fa fa-chevron-right"></i>
        </a>
        <a href="?month=<?= date('n') ?>&year=<?= date('Y') ?>" class="btn btn-sm btn-primary">Today</a>
        <a href="leave.php" class="btn btn-sm btn-outline-secondary">
            <i class="fa fa-list me-1"></i>List View
        </a>
        <?php if (canWrite('team')): ?>
        <a href="leave.php?action=new" class="btn btn-sm btn-success">
            <i class="fa fa-plus me-1"></i>New Request
        </a>
        <?php endif; ?>
    </div>
</div>

<!-- Legend -->
<div class="d-flex flex-wrap gap-3 mb-4 align-items-center">
    <span class="text-muted small fw-semibold">Leave types:</span>
    <?php foreach ($leaveTypeColors as $type => [$color, $bg]): ?>
    <span style="font-size:11.5px;font-weight:600;background:<?= $bg ?>;color:<?= $color ?>;border-radius:6px;padding:3px 10px">
        <?= $leaveTypeLabels[$type] ?>
    </span>
    <?php endforeach; ?>
    <span class="text-muted small ms-2">Faded = pending approval</span>
</div>

<!-- On leave TODAY strip (only current month) -->
<?php if (!empty($onLeaveNow)): ?>
<div class="d-flex align-items-center gap-3 mb-4 px-4 py-3"
     style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:12px">
    <i class="fa fa-umbrella-beach text-success fa-lg"></i>
    <div>
        <span class="fw-bold text-success">On leave today:</span>
        <span class="text-success ms-2"><?= implode(', ', array_map(fn($l) => e($l['user_name']), $onLeaveNow)) ?></span>
    </div>
</div>
<?php endif; ?>

<div class="row g-4">
    <!-- Calendar Grid -->
    <div class="col-lg-9">
        <div class="card">
            <div class="card-body p-3">
                <!-- Day headers -->
                <div class="cal-grid mb-2">
                    <?php foreach ($dayNames as $i => $dn): ?>
                    <div class="cal-day-hdr <?= $i >= 5 ? 'weekend' : '' ?>"><?= $dn ?></div>
                    <?php endforeach; ?>
                </div>

                <!-- Calendar cells -->
                <div class="cal-grid">
                    <?php
                    // Blank cells before month starts (Mon-based grid)
                    for ($b = 1; $b < $firstDow; $b++):
                    ?>
                    <div class="cal-cell other-month"></div>
                    <?php endfor; ?>

                    <?php for ($day = 1; $day <= $daysInMonth; $day++):
                        $cellDate = sprintf('%04d-%02d-%02d', $year, $month, $day);
                        $dow      = (int)date('N', strtotime($cellDate));
                        $isToday  = $cellDate === $today;
                        $isWknd   = $dow >= 6;
                        $dayLeaves = $dayMap[$day] ?? [];
                        $showMax   = 3;
                    ?>
                    <div class="cal-cell <?= $isToday ? 'today' : '' ?> <?= $isWknd ? 'weekend' : '' ?>">
                        <div class="cal-day-num">
                            <?php if ($isToday): ?><div class="cal-today-dot"></div><?php endif; ?>
                            <?= $day ?>
                        </div>
                        <?php foreach (array_slice($dayLeaves, 0, $showMax) as $lr):
                            [$lc,$lb] = $leaveTypeColors[$lr['leave_type']] ?? ['#94a3b8','#f1f5f9'];
                        ?>
                        <span class="leave-pill <?= $lr['status'] === 'pending' ? 'pending' : '' ?>"
                              style="background:<?= $lb ?>;color:<?= $lc ?>;border:1px solid <?= $lc ?>33"
                              title="<?= e($lr['user_name']) ?> — <?= $leaveTypeLabels[$lr['leave_type']] ?? $lr['leave_type'] ?> (<?= $lr['status'] ?>)">
                            <?= e(explode(' ', $lr['user_name'])[0]) ?>
                        </span>
                        <?php endforeach; ?>
                        <?php $extra = count($dayLeaves) - $showMax; if ($extra > 0): ?>
                        <div class="more-badge">+<?= $extra ?> more</div>
                        <?php endif; ?>
                    </div>
                    <?php endfor; ?>

                    <?php
                    // Trailing blank cells
                    $lastDow = (int)date('N', mktime(0,0,0,$month,$daysInMonth,$year));
                    for ($t = $lastDow + 1; $t <= 7; $t++):
                    ?>
                    <div class="cal-cell other-month"></div>
                    <?php endfor; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Sidebar: Leave list this month -->
    <div class="col-lg-3">
        <div class="card">
            <div class="card-header fw-semibold" style="font-size:13px">
                <i class="fa fa-list me-2 text-primary"></i>This Month
            </div>
            <div class="card-body py-2 px-3">
                <?php if (empty($allLeaves)): ?>
                <div class="text-center text-muted py-4">
                    <i class="fa fa-calendar-check fa-lg d-block mb-2 opacity-25"></i>
                    No leave requests this month.
                </div>
                <?php else: ?>
                <?php foreach ($allLeaves as $lr):
                    [$lc,$lb] = $leaveTypeColors[$lr['leave_type']] ?? ['#94a3b8','#f1f5f9'];
                    $isActive = $lr['start_date'] <= $today && $lr['end_date'] >= $today;
                ?>
                <div class="lv-item">
                    <div class="lv-bar" style="background:<?= $lc ?>"></div>
                    <div class="flex-grow-1 min-w-0">
                        <div class="fw-semibold" style="font-size:13px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">
                            <?= e($lr['user_name']) ?>
                            <?php if ($isActive): ?>
                            <span class="badge bg-primary" style="font-size:9px">Now</span>
                            <?php endif; ?>
                        </div>
                        <div class="lv-dates">
                            <?= fmtDate($lr['start_date'],'d M') ?> – <?= fmtDate($lr['end_date'],'d M') ?>
                            · <?= number_format((float)$lr['days_count'],1) ?>d
                        </div>
                    </div>
                    <span class="lv-badge"
                          style="background:<?= $lr['status']==='approved'?'#dcfce7':($lr['status']==='pending'?'#fef3c7':'#f1f5f9') ?>;
                                 color:<?= $lr['status']==='approved'?'#16a34a':($lr['status']==='pending'?'#92400e':'#64748b') ?>">
                        <?= ucfirst($lr['status']) ?>
                    </span>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($isManager): ?>
        <!-- Quick approve pending -->
        <?php $monthPending = array_filter($allLeaves, fn($l) => $l['status'] === 'pending');
        if (!empty($monthPending)): ?>
        <div class="card mt-3" style="border-color:#fef3c7">
            <div class="card-header fw-semibold text-warning" style="background:#fffbeb;font-size:13px">
                <i class="fa fa-clock me-2"></i><?= count($monthPending) ?> Awaiting Approval
            </div>
            <div class="card-body py-2 px-3">
                <?php foreach ($monthPending as $pl): ?>
                <div class="d-flex align-items-center justify-content-between py-2 border-bottom">
                    <div>
                        <div class="fw-semibold" style="font-size:12.5px"><?= e($pl['user_name']) ?></div>
                        <div class="text-muted" style="font-size:11px"><?= fmtDate($pl['start_date'],'d M') ?> · <?= number_format((float)$pl['days_count'],1) ?>d</div>
                    </div>
                    <form method="POST" action="leave.php" class="d-inline">
                        <input type="hidden" name="action"   value="approve">
                        <input type="hidden" name="leave_id" value="<?= $pl['id'] ?>">
                        <button class="btn btn-xs btn-success" onclick="return confirm('Approve this leave?')">
                            <i class="fa fa-check me-1"></i>Approve
                        </button>
                    </form>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
        <?php endif; ?>

    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>

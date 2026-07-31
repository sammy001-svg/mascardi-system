<?php
/**
 * HR — Command Centre
 *
 * The landing page for hr_manager (see index.php routing). Everything here is
 * read-only summary; the action always hands off to the module that owns it.
 */
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/_bootstrap.php';
requireLogin();
canAccess('hr') || redirect(BASE_URL . '/index.php');

$db = getDB();
hrMigrate($db);

$pageTitle = 'HR Command Centre';
$today     = date('Y-m-d');
$thisYear  = (int)date('Y');
$thisMonth = (int)date('n');

$staff      = hrStaffDirectory($db);
$totalStaff = count($staff);

// ── Headcount breakdown ───────────────────────────────────────────────────────
$byType = ['user' => 0, 'mechanic' => 0, 'driver' => 0];
$byDept = [];
$onProbation = $noRecord = $noSalary = 0;
$payrollBill = 0.0;
foreach ($staff as $s) {
    $byType[$s['staff_type']] = ($byType[$s['staff_type']] ?? 0) + 1;
    $dept = $s['department'] ?: 'Unassigned';
    $byDept[$dept] = ($byDept[$dept] ?? 0) + 1;
    if ($s['emp_status'] === 'probation') $onProbation++;
    if (!$s['has_hr_record']) $noRecord++;
    if (!$s['salary'])        $noSalary++;
    $payrollBill += $s['gross'];
}
arsort($byDept);

// ── Today's attendance ────────────────────────────────────────────────────────
$att = ['present' => 0, 'late' => 0, 'absent' => 0, 'leave' => 0, 'half_day' => 0];
$attRecorded = 0;
try {
    $st = $db->prepare("SELECT status, COUNT(*) n FROM attendance_records
                        WHERE attendance_date = ? GROUP BY status");
    $st->execute([$today]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $att[$r['status']] = (int)$r['n'];
        $attRecorded += (int)$r['n'];
    }
} catch (\Throwable $_) {}
$notMarked = max(0, $totalStaff - $attRecorded);
$attendanceRate = $attRecorded > 0
    ? round((($att['present'] + $att['late'] + $att['half_day']) / $attRecorded) * 100)
    : 0;

// ── Leave ─────────────────────────────────────────────────────────────────────
$pendingLeave = [];
$onLeaveToday = 0;
try {
    $pendingLeave = $db->query("
        SELECT id, user_name, leave_type, start_date, end_date, days_count, created_at
        FROM leave_requests WHERE status = 'pending'
        ORDER BY start_date ASC LIMIT 6
    ")->fetchAll(PDO::FETCH_ASSOC);

    $st = $db->prepare("SELECT COUNT(*) FROM leave_requests
                        WHERE status='approved' AND ? BETWEEN start_date AND end_date");
    $st->execute([$today]);
    $onLeaveToday = (int)$st->fetchColumn();
} catch (\Throwable $_) {}
$pendingLeaveCount = 0;
try {
    $pendingLeaveCount = (int)$db->query("SELECT COUNT(*) FROM leave_requests WHERE status='pending'")->fetchColumn();
} catch (\Throwable $_) {}

// ── Payroll ───────────────────────────────────────────────────────────────────
$lastRun = null;
$runThisMonth = false;
try {
    $lastRun = $db->query("SELECT * FROM payroll_runs ORDER BY period_year DESC, period_month DESC LIMIT 1")
                  ->fetch(PDO::FETCH_ASSOC) ?: null;
    $st = $db->prepare("SELECT COUNT(*) FROM payroll_runs WHERE period_year=? AND period_month=?");
    $st->execute([$thisYear, $thisMonth]);
    $runThisMonth = (bool)$st->fetchColumn();
} catch (\Throwable $_) {}

// ── Compliance: documents expiring, contracts ending, probations due ──────────
$expiringDocs = [];
try {
    $expiringDocs = $db->query("
        SELECT d.*, DATEDIFF(d.expiry_date, CURDATE()) AS days_left
        FROM hr_documents d
        WHERE d.expiry_date IS NOT NULL AND d.expiry_date <= DATE_ADD(CURDATE(), INTERVAL 60 DAY)
        ORDER BY d.expiry_date ASC LIMIT 6
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (\Throwable $_) {}

$endingContracts = [];
try {
    $endingContracts = $db->query("
        SELECT staff_type, staff_id, contract_end, probation_end, employment_status,
               DATEDIFF(contract_end, CURDATE())  AS c_days,
               DATEDIFF(probation_end, CURDATE()) AS p_days
        FROM hr_employees
        WHERE employment_status <> 'exited'
          AND ((contract_end  IS NOT NULL AND contract_end  <= DATE_ADD(CURDATE(), INTERVAL 45 DAY))
            OR (probation_end IS NOT NULL AND probation_end <= DATE_ADD(CURDATE(), INTERVAL 45 DAY)))
        ORDER BY COALESCE(contract_end, probation_end) ASC LIMIT 6
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (\Throwable $_) {}

// ── New joiners & upcoming anniversaries ─────────────────────────────────────
$newJoiners = [];
foreach ($staff as $s) {
    if ($s['hire_date'] && strtotime($s['hire_date']) >= strtotime('-90 days')) $newJoiners[] = $s;
}
usort($newJoiners, fn($a, $b) => strcmp($b['hire_date'], $a['hire_date']));
$newJoiners = array_slice($newJoiners, 0, 5);

$alertCount = count($expiringDocs) + count($endingContracts) + ($noRecord > 0 ? 1 : 0);

include __DIR__ . '/../../includes/header.php';
?>
<style>
/* Every colour below resolves through a theme variable or is painted on a
   deliberately dark gradient — no hardcoded light backgrounds, which is what
   made earlier dashboards unreadable in dark mode. */
.hr-hero{
    background:linear-gradient(135deg,#312e81 0%,#4f46e5 48%,#7c3aed 100%);
    border-radius:var(--r-xl); padding:26px 30px; color:#fff; margin-bottom:22px;
    position:relative; overflow:hidden;
}
.hr-hero::after{
    content:''; position:absolute; right:-70px; top:-70px; width:260px; height:260px;
    border-radius:50%; background:rgba(255,255,255,.07);
}
.hr-hero-top{ display:flex; justify-content:space-between; align-items:flex-start; gap:16px; flex-wrap:wrap; position:relative; z-index:1; }
.hr-hero h1{ font-size:23px; font-weight:800; letter-spacing:-.5px; margin:0 0 4px; color:#fff; }
.hr-hero .sub{ font-size:13px; opacity:.8; margin:0; }
.hr-hero-date{ text-align:right; font-size:12px; opacity:.85; }
.hr-hero-date strong{ display:block; font-size:15px; font-weight:700; }

.hr-kpis{ display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:12px; margin-top:22px; position:relative; z-index:1; }
@media(max-width:900px){ .hr-kpis{ grid-template-columns:repeat(2,minmax(0,1fr)); } }
.hr-kpi{ background:rgba(255,255,255,.12); border:1px solid rgba(255,255,255,.2); border-radius:var(--r); padding:13px 15px; }
.hr-kpi-val{ font-size:21px; font-weight:900; line-height:1.15; letter-spacing:-.5px; color:#fff; }
.hr-kpi-lbl{ font-size:10.5px; opacity:.75; margin-top:4px; text-transform:uppercase; letter-spacing:.6px; }
.hr-kpi-note{ font-size:11px; opacity:.9; margin-top:5px; }

.hr-grid{ display:grid; grid-template-columns:repeat(auto-fit,minmax(320px,1fr)); gap:16px; margin-bottom:16px; }
.hr-card{ background:var(--surface); border:1px solid var(--border); border-radius:var(--r-lg); box-shadow:var(--sh-sm); overflow:hidden; }
.hr-card-head{
    display:flex; align-items:center; justify-content:space-between; gap:10px;
    padding:13px 16px; border-bottom:1px solid var(--border); background:var(--surface-alt);
}
.hr-card-title{ font-size:13.5px; font-weight:700; color:var(--text); display:flex; align-items:center; gap:8px; margin:0; }
.hr-card-title i{ color:var(--brand); }
.hr-card-body{ padding:14px 16px; }
.hr-empty{ text-align:center; padding:22px 10px; color:var(--text-2,#64748b); font-size:13px; }
.hr-empty i{ font-size:26px; opacity:.35; display:block; margin-bottom:8px; }

/* Attendance donut-ish bar */
.hr-bar{ display:flex; height:10px; border-radius:6px; overflow:hidden; background:var(--surface-alt); border:1px solid var(--border); }
.hr-bar span{ display:block; height:100%; }
.hr-legend{ display:flex; flex-wrap:wrap; gap:12px; margin-top:12px; font-size:12px; color:var(--text); }
.hr-legend b{ font-weight:700; }
.hr-dot{ width:9px; height:9px; border-radius:50%; display:inline-block; margin-right:5px; vertical-align:baseline; }

/* Person rows */
.hr-person{ display:flex; align-items:center; gap:10px; padding:9px 0; border-bottom:1px solid var(--border); }
.hr-person:last-child{ border-bottom:0; }
.hr-avatar{
    width:34px; height:34px; border-radius:50%; flex:0 0 34px; color:#fff;
    display:flex; align-items:center; justify-content:center; font-size:12px; font-weight:800;
}
.hr-person-main{ min-width:0; flex:1; }
.hr-person-name{ font-size:13px; font-weight:600; color:var(--text); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.hr-person-sub{ font-size:11.5px; color:var(--text-2,#64748b); }
.hr-pill{ font-size:10.5px; font-weight:700; padding:2px 8px; border-radius:20px; white-space:nowrap; }

/* Department bars */
.hr-dept{ margin-bottom:11px; }
.hr-dept-top{ display:flex; justify-content:space-between; font-size:12.5px; color:var(--text); margin-bottom:4px; }
.hr-dept-track{ height:7px; border-radius:5px; background:var(--surface-alt); border:1px solid var(--border); overflow:hidden; }
.hr-dept-fill{ height:100%; background:linear-gradient(90deg,var(--brand),#7c3aed); }

/* Quick links */
.hr-links{ display:grid; grid-template-columns:repeat(auto-fit,minmax(150px,1fr)); gap:10px; }
.hr-link{
    display:flex; align-items:center; gap:10px; padding:13px 14px; text-decoration:none;
    background:var(--surface); border:1px solid var(--border); border-radius:var(--r);
    color:var(--text); transition:transform .12s ease, box-shadow .12s ease, border-color .12s ease;
}
.hr-link:hover{ transform:translateY(-2px); box-shadow:var(--sh); border-color:var(--brand); color:var(--text); }
.hr-link-icon{
    width:36px; height:36px; border-radius:9px; flex:0 0 36px; background:var(--brand-soft); color:var(--brand);
    display:flex; align-items:center; justify-content:center; font-size:15px;
}
.hr-link-t{ font-size:13px; font-weight:700; line-height:1.2; }
.hr-link-s{ font-size:11px; color:var(--text-2,#64748b); margin-top:2px; }
</style>

<div class="hr-hero">
    <div class="hr-hero-top">
        <div>
            <h1><i class="fa fa-people-roof me-2"></i>HR Command Centre</h1>
            <p class="sub">Headcount, attendance, leave and payroll across the whole company.</p>
        </div>
        <div class="hr-hero-date">
            <strong><?= date('l') ?></strong>
            <?= date('j F Y') ?>
        </div>
    </div>

    <div class="hr-kpis">
        <div class="hr-kpi">
            <div class="hr-kpi-val"><?= $totalStaff ?></div>
            <div class="hr-kpi-lbl">Total Headcount</div>
            <div class="hr-kpi-note">
                <?= (int)$byType['user'] ?> office &middot; <?= (int)$byType['mechanic'] ?> mechanics &middot; <?= (int)$byType['driver'] ?> drivers
            </div>
        </div>
        <div class="hr-kpi">
            <div class="hr-kpi-val"><?= $attRecorded ? $attendanceRate . '%' : '—' ?></div>
            <div class="hr-kpi-lbl">Attendance Today</div>
            <div class="hr-kpi-note">
                <?= $attRecorded ? $attRecorded . ' of ' . $totalStaff . ' marked' : 'Register not taken yet' ?>
            </div>
        </div>
        <div class="hr-kpi">
            <div class="hr-kpi-val"><?= $pendingLeaveCount ?></div>
            <div class="hr-kpi-lbl">Leave Awaiting You</div>
            <div class="hr-kpi-note"><?= $onLeaveToday ?> away today</div>
        </div>
        <div class="hr-kpi">
            <div class="hr-kpi-val" style="font-size:<?= strlen(number_format($payrollBill, 0)) > 9 ? '16' : '21' ?>px">
                KES <?= number_format($payrollBill, 0) ?>
            </div>
            <div class="hr-kpi-lbl">Monthly Gross Bill</div>
            <div class="hr-kpi-note">
                <?= $noSalary ? $noSalary . ' without a salary profile' : 'All profiles complete' ?>
            </div>
        </div>
    </div>
</div>

<?php if ($noRecord > 0 && canWrite('hr')): ?>
<div class="alert alert-warning py-2">
    <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
        <span class="small">
            <i class="fa fa-user-plus me-1"></i>
            <strong><?= $noRecord ?></strong> <?= $noRecord === 1 ? 'person has' : 'people have' ?>
            no employment record yet — job title, department, contract and statutory numbers are blank,
            so <?= $noRecord === 1 ? 'they are' : 'they are' ?> missing from contract and compliance tracking.
        </span>
        <a href="<?= BASE_URL ?>/modules/hr/employees.php?missing=1" class="btn btn-sm btn-warning">
            <i class="fa fa-arrow-right me-1"></i>Complete their records
        </a>
    </div>
</div>
<?php endif; ?>

<!-- ── Row 1: attendance + leave ───────────────────────────────────────────── -->
<div class="hr-grid">

    <div class="hr-card">
        <div class="hr-card-head">
            <h2 class="hr-card-title"><i class="fa fa-calendar-check"></i>Today's Register</h2>
            <a href="<?= BASE_URL ?>/modules/hr/attendance.php" class="btn btn-xs btn-outline-primary">
                <?= $notMarked > 0 ? 'Take register' : 'Open' ?>
            </a>
        </div>
        <div class="hr-card-body">
            <?php if ($attRecorded === 0): ?>
                <div class="hr-empty">
                    <i class="fa fa-clipboard-user"></i>
                    No one has been marked today.<br>
                    <a href="<?= BASE_URL ?>/modules/hr/attendance.php">Take the register</a> for <?= $totalStaff ?> staff.
                </div>
            <?php else: ?>
                <?php
                $segments = [
                    ['present',  '#16a34a', 'Present'],
                    ['late',     '#f59e0b', 'Late'],
                    ['half_day', '#0891b2', 'Half day'],
                    ['leave',    '#6366f1', 'On leave'],
                    ['absent',   '#dc2626', 'Absent'],
                ];
                $denom = max(1, $attRecorded + $notMarked);
                ?>
                <div class="hr-bar">
                    <?php foreach ($segments as [$k, $c, $l]): if (!$att[$k]) continue; ?>
                        <span style="width:<?= round($att[$k] / $denom * 100, 2) ?>%;background:<?= $c ?>"
                              title="<?= $l ?>: <?= $att[$k] ?>"></span>
                    <?php endforeach; ?>
                    <?php if ($notMarked): ?>
                        <span style="width:<?= round($notMarked / $denom * 100, 2) ?>%;background:var(--border)"
                              title="Not marked: <?= $notMarked ?>"></span>
                    <?php endif; ?>
                </div>
                <div class="hr-legend">
                    <?php foreach ($segments as [$k, $c, $l]): ?>
                        <span><i class="hr-dot" style="background:<?= $c ?>"></i><?= $l ?> <b><?= $att[$k] ?></b></span>
                    <?php endforeach; ?>
                    <?php if ($notMarked): ?>
                        <span><i class="hr-dot" style="background:var(--border)"></i>Not marked <b><?= $notMarked ?></b></span>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="hr-card">
        <div class="hr-card-head">
            <h2 class="hr-card-title"><i class="fa fa-plane-departure"></i>Leave Awaiting Approval</h2>
            <a href="<?= BASE_URL ?>/modules/hr/leave.php" class="btn btn-xs btn-outline-primary">Manage</a>
        </div>
        <div class="hr-card-body">
            <?php if (!$pendingLeave): ?>
                <div class="hr-empty"><i class="fa fa-circle-check"></i>Nothing waiting. All leave requests are decided.</div>
            <?php else: foreach ($pendingLeave as $lv): ?>
                <div class="hr-person">
                    <div class="hr-avatar" style="background:<?= hrAvatarColor($lv['user_name']) ?>">
                        <?= e(hrInitials($lv['user_name'])) ?>
                    </div>
                    <div class="hr-person-main">
                        <div class="hr-person-name"><?= e($lv['user_name']) ?></div>
                        <div class="hr-person-sub">
                            <?= e(ucfirst($lv['leave_type'])) ?> &middot;
                            <?= date('j M', strtotime($lv['start_date'])) ?>–<?= date('j M', strtotime($lv['end_date'])) ?>
                        </div>
                    </div>
                    <span class="hr-pill" style="background:var(--brand-soft);color:var(--brand)">
                        <?= rtrim(rtrim(number_format((float)$lv['days_count'], 1), '0'), '.') ?>d
                    </span>
                </div>
            <?php endforeach; endif; ?>
        </div>
    </div>
</div>

<!-- ── Row 2: headcount + payroll ──────────────────────────────────────────── -->
<div class="hr-grid">

    <div class="hr-card">
        <div class="hr-card-head">
            <h2 class="hr-card-title"><i class="fa fa-sitemap"></i>Headcount by Department</h2>
            <a href="<?= BASE_URL ?>/modules/hr/employees.php" class="btn btn-xs btn-outline-primary">Directory</a>
        </div>
        <div class="hr-card-body">
            <?php if (!$byDept): ?>
                <div class="hr-empty"><i class="fa fa-users"></i>No staff on record yet.</div>
            <?php else:
                $maxDept = max($byDept);
                foreach (array_slice($byDept, 0, 7, true) as $dept => $n): ?>
                <div class="hr-dept">
                    <div class="hr-dept-top">
                        <span<?= $dept === 'Unassigned' ? ' class="text-muted"' : '' ?>><?= e($dept) ?></span>
                        <strong><?= $n ?></strong>
                    </div>
                    <div class="hr-dept-track">
                        <div class="hr-dept-fill" style="width:<?= round($n / $maxDept * 100) ?>%"></div>
                    </div>
                </div>
            <?php endforeach; endif; ?>
        </div>
    </div>

    <div class="hr-card">
        <div class="hr-card-head">
            <h2 class="hr-card-title"><i class="fa fa-money-check-dollar"></i>Payroll</h2>
            <?php if (canAccess('payroll')): ?>
            <a href="<?= BASE_URL ?>/modules/payroll/index.php" class="btn btn-xs btn-outline-primary">Open payroll</a>
            <?php endif; ?>
        </div>
        <div class="hr-card-body">
            <?php if (!$runThisMonth): ?>
                <div class="alert alert-info py-2 mb-3 small">
                    <i class="fa fa-circle-info me-1"></i>
                    <?= date('F Y') ?> has not been run yet.
                </div>
            <?php endif; ?>

            <?php if (!$lastRun): ?>
                <div class="hr-empty">
                    <i class="fa fa-money-bill-wave"></i>
                    No payroll has been processed yet.
                    <?php if (canWrite('payroll')): ?>
                        <br><a href="<?= BASE_URL ?>/modules/payroll/create.php">Create the first run</a>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <div>
                        <div style="font-size:13px;font-weight:700;color:var(--text)">
                            <?= date('F Y', mktime(0, 0, 0, (int)$lastRun['period_month'], 1, (int)$lastRun['period_year'])) ?>
                        </div>
                        <div class="hr-person-sub"><?= e($lastRun['reference'] ?: 'Latest run') ?></div>
                    </div>
                    <?php
                    $rs = ['draft' => ['#f59e0b','Draft'], 'approved' => ['#0891b2','Approved'], 'paid' => ['#16a34a','Paid']];
                    [$rc, $rl] = $rs[$lastRun['status']] ?? $rs['draft'];
                    ?>
                    <span class="hr-pill" style="background:<?= $rc ?>1f;color:<?= $rc ?>"><?= $rl ?></span>
                </div>
                <table class="table table-sm mb-0" style="font-size:12.5px">
                    <tr><td class="text-muted border-0">Gross</td>
                        <td class="text-end fw-semibold border-0"><?= money((float)$lastRun['total_gross']) ?></td></tr>
                    <tr><td class="text-muted border-0">Deductions</td>
                        <td class="text-end text-danger border-0"><?= money((float)$lastRun['total_deductions']) ?></td></tr>
                    <tr><td class="text-muted border-0">Net paid</td>
                        <td class="text-end fw-bold text-success border-0"><?= money((float)$lastRun['total_net']) ?></td></tr>
                </table>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- ── Row 3: compliance + new joiners ─────────────────────────────────────── -->
<div class="hr-grid">

    <div class="hr-card">
        <div class="hr-card-head">
            <h2 class="hr-card-title"><i class="fa fa-shield-halved"></i>Needs Attention</h2>
            <?php if ($alertCount): ?><span class="hr-pill" style="background:#dc26261f;color:#dc2626"><?= $alertCount ?></span><?php endif; ?>
        </div>
        <div class="hr-card-body">
            <?php if (!$expiringDocs && !$endingContracts): ?>
                <div class="hr-empty"><i class="fa fa-circle-check"></i>Nothing expiring in the next 60 days.</div>
            <?php else: ?>
                <?php foreach ($endingContracts as $ec):
                    $p   = hrStaffMember($db, $ec['staff_type'], (int)$ec['staff_id']);
                    $nm  = $p['name'] ?? 'Unknown employee';
                    $isProb = $ec['probation_end'] && (int)$ec['p_days'] <= 45 && $ec['employment_status'] === 'probation';
                    $days   = $isProb ? (int)$ec['p_days'] : (int)$ec['c_days'];
                    $label  = $isProb ? 'Probation ends' : 'Contract ends';
                    $col    = $days < 0 ? '#dc2626' : ($days <= 14 ? '#f59e0b' : '#0891b2');
                ?>
                <div class="hr-person">
                    <div class="hr-avatar" style="background:<?= hrAvatarColor($nm) ?>"><?= e(hrInitials($nm)) ?></div>
                    <div class="hr-person-main">
                        <div class="hr-person-name"><?= e($nm) ?></div>
                        <div class="hr-person-sub"><?= $label ?>
                            <?= date('j M Y', strtotime($isProb ? $ec['probation_end'] : $ec['contract_end'])) ?></div>
                    </div>
                    <span class="hr-pill" style="background:<?= $col ?>1f;color:<?= $col ?>">
                        <?= $days < 0 ? abs($days) . 'd over' : $days . 'd' ?>
                    </span>
                </div>
                <?php endforeach; ?>

                <?php foreach ($expiringDocs as $doc):
                    $p  = hrStaffMember($db, $doc['staff_type'], (int)$doc['staff_id']);
                    $nm = $p['name'] ?? 'Unknown employee';
                    $d  = (int)$doc['days_left'];
                    $col = $d < 0 ? '#dc2626' : ($d <= 14 ? '#f59e0b' : '#0891b2');
                ?>
                <div class="hr-person">
                    <div class="hr-avatar" style="background:<?= hrAvatarColor($nm) ?>"><?= e(hrInitials($nm)) ?></div>
                    <div class="hr-person-main">
                        <div class="hr-person-name"><?= e($nm) ?></div>
                        <div class="hr-person-sub"><?= e($doc['title']) ?> &middot;
                            expires <?= date('j M Y', strtotime($doc['expiry_date'])) ?></div>
                    </div>
                    <span class="hr-pill" style="background:<?= $col ?>1f;color:<?= $col ?>">
                        <?= $d < 0 ? 'Expired' : $d . 'd' ?>
                    </span>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <div class="hr-card">
        <div class="hr-card-head">
            <h2 class="hr-card-title"><i class="fa fa-user-plus"></i>Joined in the Last 90 Days</h2>
            <?php if ($onProbation): ?>
            <span class="hr-pill" style="background:#ca8a041f;color:#ca8a04"><?= $onProbation ?> on probation</span>
            <?php endif; ?>
        </div>
        <div class="hr-card-body">
            <?php if (!$newJoiners): ?>
                <div class="hr-empty"><i class="fa fa-user-clock"></i>
                    No recent hires recorded.<br>
                    <span class="small">Hire dates are set on each employee's record.</span>
                </div>
            <?php else: foreach ($newJoiners as $nj):
                [$sl, $sc] = hrEmploymentBadge($nj['emp_status']); ?>
                <a class="hr-person text-decoration-none"
                   href="<?= BASE_URL ?>/modules/hr/employee.php?staff=<?= e($nj['key']) ?>">
                    <div class="hr-avatar" style="background:<?= hrAvatarColor($nj['key']) ?>"><?= e(hrInitials($nj['name'])) ?></div>
                    <div class="hr-person-main">
                        <div class="hr-person-name"><?= e($nj['name']) ?></div>
                        <div class="hr-person-sub">
                            <?= e($nj['job_title'] ?: hrStaffTypes()[$nj['staff_type']]['label']) ?> &middot;
                            joined <?= date('j M Y', strtotime($nj['hire_date'])) ?>
                        </div>
                    </div>
                    <span class="hr-pill" style="background:<?= $sc ?>1f;color:<?= $sc ?>"><?= $sl ?></span>
                </a>
            <?php endforeach; endif; ?>
        </div>
    </div>
</div>

<!-- ── Quick links ─────────────────────────────────────────────────────────── -->
<div class="hr-card">
    <div class="hr-card-head"><h2 class="hr-card-title"><i class="fa fa-bolt"></i>HR Modules</h2></div>
    <div class="hr-card-body">
        <div class="hr-links">
            <a class="hr-link" href="<?= BASE_URL ?>/modules/hr/employees.php">
                <span class="hr-link-icon"><i class="fa fa-users"></i></span>
                <span><span class="hr-link-t">Employees</span><span class="hr-link-s"><?= $totalStaff ?> on record</span></span>
            </a>
            <a class="hr-link" href="<?= BASE_URL ?>/modules/hr/attendance.php">
                <span class="hr-link-icon"><i class="fa fa-calendar-days"></i></span>
                <span><span class="hr-link-t">Attendance</span><span class="hr-link-s">Daily register</span></span>
            </a>
            <a class="hr-link" href="<?= BASE_URL ?>/modules/hr/biometric.php">
                <span class="hr-link-icon"><i class="fa fa-fingerprint"></i></span>
                <span><span class="hr-link-t">Biometric</span><span class="hr-link-s">
                    <?php
                    // Surface the two states that stop scans counting, rather
                    // than a device count that looks healthy either way.
                    $zkPending = $zkUnlinked = 0;
                    try {
                        $zkPending  = (int)$db->query("SELECT COUNT(*) FROM zk_devices WHERE status='pending'")->fetchColumn();
                        $zkUnlinked = (int)$db->query("SELECT COUNT(DISTINCT device_pin) FROM zk_punches WHERE staff_id IS NULL")->fetchColumn();
                    } catch (\Throwable $_) {}
                    if ($zkPending)       echo $zkPending . ' awaiting approval';
                    elseif ($zkUnlinked)  echo $zkUnlinked . ' number' . ($zkUnlinked === 1 ? '' : 's') . ' unlinked';
                    else                  echo 'ZKTeco terminals';
                    ?>
                </span></span>
            </a>
            <a class="hr-link" href="<?= BASE_URL ?>/modules/hr/leave.php">
                <span class="hr-link-icon"><i class="fa fa-plane-departure"></i></span>
                <span><span class="hr-link-t">Leave</span><span class="hr-link-s"><?= $pendingLeaveCount ?> pending</span></span>
            </a>
            <a class="hr-link" href="<?= BASE_URL ?>/modules/hr/documents.php">
                <span class="hr-link-icon"><i class="fa fa-folder-open"></i></span>
                <span><span class="hr-link-t">Documents</span><span class="hr-link-s">Contracts &amp; renewals</span></span>
            </a>
            <?php if (canAccess('payroll')): ?>
            <a class="hr-link" href="<?= BASE_URL ?>/modules/payroll/index.php">
                <span class="hr-link-icon"><i class="fa fa-money-bill-wave"></i></span>
                <span><span class="hr-link-t">Payroll</span><span class="hr-link-s">Monthly runs</span></span>
            </a>
            <a class="hr-link" href="<?= BASE_URL ?>/modules/payroll/staff.php">
                <span class="hr-link-icon"><i class="fa fa-wallet"></i></span>
                <span><span class="hr-link-t">Salary Profiles</span><span class="hr-link-s"><?= $noSalary ?> missing</span></span>
            </a>
            <?php endif; ?>
            <?php if (canAccess('attendance')): ?>
            <a class="hr-link" href="<?= BASE_URL ?>/modules/attendance/report.php">
                <span class="hr-link-icon"><i class="fa fa-chart-column"></i></span>
                <span><span class="hr-link-t">Attendance Report</span><span class="hr-link-s">Monthly summary</span></span>
            </a>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>

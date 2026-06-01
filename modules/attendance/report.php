<?php
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
canAccess('attendance') || redirect(BASE_URL . '/index.php');

$pageTitle = 'Attendance Report';
$db    = getDB();
$month = (int)($_GET['month'] ?? date('n'));
$year  = (int)($_GET['year']  ?? date('Y'));
if ($month < 1 || $month > 12) $month = (int)date('n');

$months      = ['','January','February','March','April','May','June','July','August','September','October','November','December'];
$daysInMonth = cal_days_in_month(CAL_GREGORIAN, $month, $year);

// Count working days (Mon–Fri) in the month
$workingDays = 0;
for ($d = 1; $d <= $daysInMonth; $d++) {
    if (date('N', mktime(0,0,0,$month,$d,$year)) < 6) $workingDays++;
}

try {
    $mechanics = $db->query("SELECT id,'mechanic' AS type, name FROM mechanics WHERE status='active' ORDER BY name")->fetchAll();
    $drivers   = $db->query("SELECT id,'driver'   AS type, name FROM drivers   WHERE status='active' ORDER BY name")->fetchAll();
    $staff     = array_merge($mechanics, $drivers);

    $summaryStmt = $db->prepare("
        SELECT staff_type, staff_id,
               SUM(status = 'present')   AS present,
               SUM(status = 'absent')    AS absent,
               SUM(status = 'late')      AS late,
               SUM(status = 'half_day')  AS half_day,
               SUM(status = 'leave')     AS leave,
               COUNT(*)                  AS total_recorded
        FROM attendance_records
        WHERE MONTH(attendance_date) = ? AND YEAR(attendance_date) = ?
        GROUP BY staff_type, staff_id
    ");
    $summaryStmt->execute([$month, $year]);
    $summaryMap = [];
    foreach ($summaryStmt->fetchAll() as $r) {
        $summaryMap[$r['staff_type'].'_'.$r['staff_id']] = $r;
    }

    // Overall stats
    $overall = $db->prepare("
        SELECT
            SUM(status = 'present')  AS total_present,
            SUM(status = 'absent')   AS total_absent,
            SUM(status = 'late')     AS total_late,
            SUM(status = 'leave')    AS total_leave,
            COUNT(DISTINCT CONCAT(staff_type,'_',staff_id)) AS staff_count
        FROM attendance_records
        WHERE MONTH(attendance_date) = ? AND YEAR(attendance_date) = ?
    ");
    $overall->execute([$month, $year]);
    $overall = $overall->fetch();

} catch (\Throwable $e) {
    $staff = []; $summaryMap = []; $overall = null;
}

include __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h5 class="mb-0">
        <i class="fa fa-chart-bar me-2 text-primary"></i>
        Attendance Report — <?= $months[$month] ?> <?= $year ?>
    </h5>
    <div class="d-flex gap-2">
        <?php
        $pm = $month - 1; $py = $year; if ($pm < 1) { $pm = 12; $py--; }
        $nm = $month + 1; $ny = $year; if ($nm > 12) { $nm = 1;  $ny++; }
        ?>
        <a href="?month=<?= $pm ?>&year=<?= $py ?>" class="btn btn-sm btn-outline-secondary"><i class="fa fa-chevron-left"></i></a>
        <a href="?month=<?= $nm ?>&year=<?= $ny ?>" class="btn btn-sm btn-outline-secondary"><i class="fa fa-chevron-right"></i></a>
        <a href="index.php?month=<?= $month ?>&year=<?= $year ?>" class="btn btn-sm btn-outline-primary">
            <i class="fa fa-calendar me-1"></i>Register
        </a>
    </div>
</div>

<!-- Summary cards -->
<?php if ($overall): ?>
<div class="row g-3 mb-4">
    <div class="col-sm-3">
        <div class="stat-card" style="border-left:4px solid #2563eb">
            <div class="stat-icon" style="background:#dbeafe;color:#2563eb"><i class="fa fa-calendar-days"></i></div>
            <div class="stat-info">
                <div class="stat-label">Working Days</div>
                <div class="stat-value"><?= $workingDays ?></div>
            </div>
        </div>
    </div>
    <div class="col-sm-3">
        <div class="stat-card" style="border-left:4px solid #16a34a">
            <div class="stat-icon" style="background:#dcfce7;color:#16a34a"><i class="fa fa-circle-check"></i></div>
            <div class="stat-info">
                <div class="stat-label">Total Present</div>
                <div class="stat-value"><?= (int)$overall['total_present'] ?></div>
            </div>
        </div>
    </div>
    <div class="col-sm-3">
        <div class="stat-card" style="border-left:4px solid #dc2626">
            <div class="stat-icon" style="background:#fee2e2;color:#dc2626"><i class="fa fa-circle-xmark"></i></div>
            <div class="stat-info">
                <div class="stat-label">Total Absent</div>
                <div class="stat-value"><?= (int)$overall['total_absent'] ?></div>
            </div>
        </div>
    </div>
    <div class="col-sm-3">
        <div class="stat-card" style="border-left:4px solid #d97706">
            <div class="stat-icon" style="background:#fef3c7;color:#d97706"><i class="fa fa-clock"></i></div>
            <div class="stat-info">
                <div class="stat-label">Late Arrivals</div>
                <div class="stat-value"><?= (int)$overall['total_late'] ?></div>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Per-staff report -->
<div class="card">
    <div class="card-header fw-semibold d-flex justify-content-between align-items-center">
        <span><i class="fa fa-table me-2"></i>Staff Attendance Summary</span>
        <span class="text-muted small"><?= $workingDays ?> working days in <?= $months[$month] ?></span>
    </div>
    <div class="card-body p-0">
        <table class="table table-hover mb-0" style="font-size:13.5px">
            <thead class="table-light">
                <tr>
                    <th class="ps-3">Staff Member</th>
                    <th>Type</th>
                    <th class="text-center" style="color:#16a34a">Present</th>
                    <th class="text-center" style="color:#dc2626">Absent</th>
                    <th class="text-center" style="color:#d97706">Late</th>
                    <th class="text-center" style="color:#2563eb">Half Day</th>
                    <th class="text-center" style="color:#7c3aed">Leave</th>
                    <th class="text-center">Attendance %</th>
                    <th class="text-center">Effective Days</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($staff as $s):
                $key = $s['type'].'_'.$s['id'];
                $sm  = $summaryMap[$key] ?? ['present'=>0,'absent'=>0,'late'=>0,'half_day'=>0,'leave'=>0];
                $present   = (int)$sm['present'];
                $absent    = (int)$sm['absent'];
                $late      = (int)$sm['late'];
                $half      = (int)$sm['half_day'];
                $leave     = (int)$sm['leave'];
                $effective = $present + $late + ($half * 0.5);
                $attPct    = $workingDays > 0 ? round($effective / $workingDays * 100, 1) : 0;
                $pctColor  = $attPct >= 90 ? 'success' : ($attPct >= 75 ? 'warning' : 'danger');
            ?>
            <tr>
                <td class="ps-3 fw-medium"><?= e($s['name']) ?></td>
                <td><span class="badge bg-light text-dark border" style="font-size:11px"><?= ucfirst($s['type']) ?></span></td>
                <td class="text-center">
                    <span class="badge bg-success-subtle text-success border border-success-subtle"><?= $present ?></span>
                </td>
                <td class="text-center">
                    <?php if ($absent > 0): ?>
                    <span class="badge bg-danger-subtle text-danger border border-danger-subtle"><?= $absent ?></span>
                    <?php else: ?>
                    <span class="text-muted">0</span>
                    <?php endif; ?>
                </td>
                <td class="text-center">
                    <?php if ($late > 0): ?>
                    <span class="badge bg-warning-subtle text-warning border border-warning-subtle"><?= $late ?></span>
                    <?php else: ?>
                    <span class="text-muted">0</span>
                    <?php endif; ?>
                </td>
                <td class="text-center">
                    <?php if ($half > 0): ?>
                    <span class="badge bg-primary-subtle text-primary border border-primary-subtle"><?= $half ?></span>
                    <?php else: ?>
                    <span class="text-muted">0</span>
                    <?php endif; ?>
                </td>
                <td class="text-center">
                    <?php if ($leave > 0): ?>
                    <span class="badge bg-purple-subtle" style="background:#f3e8ff;color:#7c3aed;border:1px solid #ddd8fe"><?= $leave ?></span>
                    <?php else: ?>
                    <span class="text-muted">0</span>
                    <?php endif; ?>
                </td>
                <td class="text-center">
                    <div class="d-flex align-items-center justify-content-center gap-2">
                        <div class="progress" style="width:60px;height:6px">
                            <div class="progress-bar bg-<?= $pctColor ?>" style="width:<?= $attPct ?>%"></div>
                        </div>
                        <span class="badge bg-<?= $pctColor ?>" style="font-size:11px"><?= $attPct ?>%</span>
                    </div>
                </td>
                <td class="text-center fw-semibold">
                    <?= number_format($effective, 1) ?> / <?= $workingDays ?>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($staff)): ?>
            <tr><td colspan="9" class="text-center text-muted py-4">No active staff found.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
    <div class="card-footer bg-white py-2 px-3 text-muted small">
        <i class="fa fa-circle-info me-1"></i>
        Effective Days = Present + Late + (Half Day × 0.5). Used for payroll pro-rating.
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>

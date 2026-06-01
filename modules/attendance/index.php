<?php
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
canAccess('attendance') || redirect(BASE_URL . '/index.php');

$pageTitle = 'Attendance';
$db = getDB();

$month = (int)($_GET['month'] ?? date('n'));
$year  = (int)($_GET['year']  ?? date('Y'));
if ($month < 1 || $month > 12) $month = (int)date('n');
if ($year < 2020 || $year > 2099) $year = (int)date('Y');

$daysInMonth = cal_days_in_month(CAL_GREGORIAN, $month, $year);
$months = ['','January','February','March','April','May','June','July','August','September','October','November','December'];

// Save attendance POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && canWrite('attendance')) {
    $records = $_POST['att'] ?? [];  // att[type_id][date] = status
    $notes   = $_POST['notes'] ?? [];
    $clockIn = $_POST['clock_in'] ?? [];
    $clockOut= $_POST['clock_out'] ?? [];

    $upsert = $db->prepare("
        INSERT INTO attendance_records (staff_type, staff_id, attendance_date, status, clock_in, clock_out, notes, recorded_by)
        VALUES (?,?,?,?,?,?,?,?)
        ON DUPLICATE KEY UPDATE status=VALUES(status), clock_in=VALUES(clock_in), clock_out=VALUES(clock_out),
            notes=VALUES(notes), recorded_by=VALUES(recorded_by), updated_at=NOW()
    ");
    foreach ($records as $typeId => $dates) {
        [$type, $staffId] = explode('_', $typeId, 2);
        foreach ($dates as $date => $status) {
            if (!$status) continue;
            $ci  = $clockIn[$typeId][$date]  ?? null;
            $co  = $clockOut[$typeId][$date] ?? null;
            $note= $notes[$typeId][$date]    ?? null;
            $upsert->execute([$type, (int)$staffId, $date, $status, $ci?:null, $co?:null, $note?:null, authUser()['id']]);
        }
    }
    setFlash('success','Attendance saved.');
    redirect(BASE_URL.'/modules/attendance/index.php?month='.$month.'&year='.$year);
}

// Load all staff
try {
    $mechanics = $db->query("SELECT id,'mechanic' AS type, name FROM mechanics WHERE status='active' ORDER BY name")->fetchAll();
    $drivers   = $db->query("SELECT id,'driver'   AS type, name FROM drivers   WHERE status='active' ORDER BY name")->fetchAll();
    $staff     = array_merge($mechanics, $drivers);

    // Load existing records for the month
    $recs = $db->prepare("
        SELECT staff_type, staff_id, attendance_date, status, clock_in, clock_out, notes
        FROM attendance_records
        WHERE MONTH(attendance_date)=? AND YEAR(attendance_date)=?
    ");
    $recs->execute([$month, $year]);
    $recMap = [];
    foreach ($recs->fetchAll() as $r) {
        $recMap[$r['staff_type'].'_'.$r['staff_id']][$r['attendance_date']] = $r;
    }

    // Monthly summary
    $summaryStmt = $db->prepare("
        SELECT staff_type, staff_id,
               SUM(status='present') AS present,
               SUM(status='absent')  AS absent,
               SUM(status='late')    AS late,
               SUM(status='half_day')AS half,
               SUM(status='leave')   AS leave
        FROM attendance_records
        WHERE MONTH(attendance_date)=? AND YEAR(attendance_date)=?
        GROUP BY staff_type, staff_id
    ");
    $summaryStmt->execute([$month, $year]);
    $summaryMap = [];
    foreach ($summaryStmt->fetchAll() as $s) {
        $summaryMap[$s['staff_type'].'_'.$s['staff_id']] = $s;
    }
} catch (\Throwable $e) { $staff = []; $recMap = []; $summaryMap = []; }

$statusCodes  = ['P'=>'present','A'=>'absent','L'=>'late','H'=>'half_day','LV'=>'leave'];
$statusColors = [
    'present'  => ['bg'=>'#dcfce7','color'=>'#15803d','label'=>'P'],
    'absent'   => ['bg'=>'#fee2e2','color'=>'#b91c1c','label'=>'A'],
    'late'     => ['bg'=>'#fef9c3','color'=>'#92400e','label'=>'L'],
    'half_day' => ['bg'=>'#dbeafe','color'=>'#1d4ed8','label'=>'H'],
    'leave'    => ['bg'=>'#f3e8ff','color'=>'#6d28d9','label'=>'LV'],
    ''         => ['bg'=>'','color'=>'#94a3b8','label'=>'—'],
];

// Get weekends for visual distinction
$weekends = [];
for ($d = 1; $d <= $daysInMonth; $d++) {
    $dow = date('N', mktime(0,0,0,$month,$d,$year));
    if ($dow >= 6) $weekends[$d] = true;
}

include __DIR__ . '/../../includes/header.php';
?>

<style>
.att-table th, .att-table td { padding: 4px 5px; font-size: 11.5px; text-align: center; white-space: nowrap; }
.att-table th.staff-col, .att-table td.staff-col { text-align: left; padding-left: 12px; min-width: 140px; }
.att-cell { width: 26px; height: 26px; border-radius: 5px; display: inline-flex; align-items: center; justify-content: center; font-size: 10px; font-weight: 700; cursor: pointer; border: 1px solid transparent; transition: opacity .1s; }
.att-cell:hover { opacity: .8; }
.weekend-col { background: #f8fafc !important; }
.att-select { font-size: 10px; padding: 1px 2px; width: 36px; border: 1px solid #e2e8f0; border-radius: 4px; }
</style>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h5 class="mb-0"><i class="fa fa-calendar-days me-2 text-primary"></i>Attendance Register</h5>
    <div class="d-flex gap-2">
        <a href="report.php?month=<?= $month ?>&year=<?= $year ?>" class="btn btn-sm btn-outline-secondary">
            <i class="fa fa-chart-bar me-1"></i>Monthly Report
        </a>
    </div>
</div>

<!-- Month navigation -->
<div class="card mb-3">
    <div class="card-body py-2">
        <div class="d-flex align-items-center gap-3 flex-wrap">
            <?php
            $prevMonth = $month - 1; $prevYear = $year;
            if ($prevMonth < 1) { $prevMonth = 12; $prevYear--; }
            $nextMonth = $month + 1; $nextYear = $year;
            if ($nextMonth > 12) { $nextMonth = 1; $nextYear++; }
            ?>
            <a href="?month=<?= $prevMonth ?>&year=<?= $prevYear ?>" class="btn btn-sm btn-outline-secondary">
                <i class="fa fa-chevron-left"></i>
            </a>
            <h6 class="mb-0 fw-bold"><?= $months[$month] ?> <?= $year ?></h6>
            <a href="?month=<?= $nextMonth ?>&year=<?= $nextYear ?>" class="btn btn-sm btn-outline-secondary">
                <i class="fa fa-chevron-right"></i>
            </a>
            <a href="?month=<?= date('n') ?>&year=<?= date('Y') ?>" class="btn btn-sm btn-outline-primary ms-2">Today's Month</a>

            <!-- Legend -->
            <div class="ms-auto d-flex gap-2 flex-wrap align-items-center" style="font-size:11px">
                <?php foreach (['present'=>'Present','absent'=>'Absent','late'=>'Late','half_day'=>'Half Day','leave'=>'Leave'] as $k=>$lbl): ?>
                <span class="att-cell" style="background:<?= $statusColors[$k]['bg'] ?>;color:<?= $statusColors[$k]['color'] ?>;border-color:<?= $statusColors[$k]['color'] ?>22">
                    <?= $statusColors[$k]['label'] ?>
                </span> <?= $lbl ?>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>

<?php if (empty($staff)): ?>
<div class="alert alert-info">No active mechanics or drivers found.</div>
<?php else: ?>
<div class="card">
    <div class="card-body p-0">
        <form method="POST">
            <div class="table-responsive">
            <table class="table table-bordered att-table mb-0">
                <thead>
                    <tr class="table-dark">
                        <th class="staff-col" rowspan="2">Staff Member</th>
                        <th rowspan="2" style="min-width:60px">Type</th>
                        <?php for ($d = 1; $d <= $daysInMonth; $d++): ?>
                        <th class="<?= isset($weekends[$d]) ? 'weekend-col' : '' ?>" style="min-width:30px">
                            <?= $d ?><br>
                            <span style="font-size:9px;font-weight:400;opacity:.7"><?= date('D',mktime(0,0,0,$month,$d,$year))[0] ?></span>
                        </th>
                        <?php endfor; ?>
                        <th style="min-width:40px">P</th>
                        <th style="min-width:40px">A</th>
                        <th style="min-width:40px">L</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($staff as $s):
                    $key = $s['type'].'_'.$s['id'];
                    $sum = $summaryMap[$key] ?? ['present'=>0,'absent'=>0,'late'=>0,'half'=>0,'leave'=>0];
                ?>
                <tr>
                    <td class="staff-col fw-medium"><?= e($s['name']) ?></td>
                    <td><span class="badge bg-light text-dark border" style="font-size:10px"><?= ucfirst($s['type']) ?></span></td>
                    <?php for ($d = 1; $d <= $daysInMonth; $d++):
                        $date   = sprintf('%04d-%02d-%02d', $year, $month, $d);
                        $rec    = $recMap[$key][$date] ?? null;
                        $status = $rec['status'] ?? '';
                        $sc     = $statusColors[$status] ?? $statusColors[''];
                        $isWeekend = isset($weekends[$d]);
                    ?>
                    <td class="<?= $isWeekend ? 'weekend-col' : '' ?>" style="padding:2px">
                        <?php if (canWrite('attendance') && !$isWeekend): ?>
                        <select name="att[<?= $key ?>][<?= $date ?>]"
                                class="att-select"
                                style="background:<?= $status ? $sc['bg'] : '' ?>;color:<?= $status ? $sc['color'] : '#94a3b8' ?>"
                                onchange="this.style.background=statusBg[this.value]||'';this.style.color=statusColor[this.value]||'#94a3b8'">
                            <option value="">—</option>
                            <option value="present"  <?= $status==='present' ?'selected':'' ?>>P</option>
                            <option value="absent"   <?= $status==='absent'  ?'selected':'' ?>>A</option>
                            <option value="late"     <?= $status==='late'    ?'selected':'' ?>>L</option>
                            <option value="half_day" <?= $status==='half_day'?'selected':'' ?>>H</option>
                            <option value="leave"    <?= $status==='leave'   ?'selected':'' ?>>LV</option>
                        </select>
                        <?php elseif ($status): ?>
                        <span class="att-cell" style="background:<?= $sc['bg'] ?>;color:<?= $sc['color'] ?>">
                            <?= $sc['label'] ?>
                        </span>
                        <?php else: ?>
                        <span style="color:#e2e8f0;font-size:11px">—</span>
                        <?php endif; ?>
                    </td>
                    <?php endfor; ?>
                    <td class="fw-semibold text-success"><?= (int)$sum['present'] ?></td>
                    <td class="fw-semibold text-danger"><?= (int)$sum['absent'] ?></td>
                    <td class="fw-semibold text-warning"><?= (int)$sum['late'] ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <?php if (canWrite('attendance')): ?>
            <div class="p-3 border-top">
                <button type="submit" class="btn btn-primary">
                    <i class="fa fa-save me-1"></i>Save Attendance
                </button>
                <span class="text-muted small ms-3">Changes are saved for the entire month view.</span>
            </div>
            <?php endif; ?>
        </form>
    </div>
</div>
<?php endif; ?>

<script>
var statusBg = {
    present:'#dcfce7', absent:'#fee2e2', late:'#fef9c3', half_day:'#dbeafe', leave:'#f3e8ff'
};
var statusColor = {
    present:'#15803d', absent:'#b91c1c', late:'#92400e', half_day:'#1d4ed8', leave:'#6d28d9'
};
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>

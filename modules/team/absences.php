<?php
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
canAccess('team') || die('Access denied.');
$db   = getDB();
$user = authUser();

$isManager = hasRole(['admin','general_manager','manager','hr_manager','sales_manager','workshop_manager']);

// ── Migration ─────────────────────────────────────────────────────────────────
try { $db->exec("CREATE TABLE IF NOT EXISTS absence_records (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    user_name VARCHAR(150) NOT NULL DEFAULT '',
    absence_date DATE NOT NULL,
    absence_type ENUM('no_show','late_no_notice','left_early','partial') DEFAULT 'no_show',
    reason VARCHAR(255) NULL,
    recorded_by VARCHAR(100) NOT NULL DEFAULT '',
    notes TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)"); } catch (\Throwable $e) {}

// ── POST: log absence (managers only) ────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isManager) {
    $action = $_POST['action'] ?? '';
    if ($action === 'log_absence') {
        $absUserId   = (int)($_POST['abs_user_id']   ?? 0);
        $absDate     = $_POST['abs_date']            ?? date('Y-m-d');
        $absType     = $_POST['abs_type']            ?? 'no_show';
        $absReason   = trim($_POST['abs_reason']     ?? '');
        $absNotes    = trim($_POST['abs_notes']      ?? '');
        if (!$absUserId) { setFlash('error','Select a staff member.'); redirect('absences.php'); }
        // Get user name
        $uRow = $db->prepare("SELECT name FROM users WHERE id=?"); $uRow->execute([$absUserId]); $uRow=$uRow->fetch();
        $db->prepare("INSERT INTO absence_records (user_id,user_name,absence_date,absence_type,reason,recorded_by,notes) VALUES (?,?,?,?,?,?,?)")
           ->execute([$absUserId, $uRow['name'] ?? 'Unknown', $absDate, $absType, $absReason ?: null, $user['name'], $absNotes ?: null]);
        // Also update team_checkin status to absent for today
        if ($absDate === date('Y-m-d')) {
            try {
                $db->prepare("INSERT INTO team_checkins (user_id,status,notes,checked_in_at) VALUES (?,'absent',?,NOW())
                    ON DUPLICATE KEY UPDATE status='absent',notes=VALUES(notes),checked_in_at=NOW()")
                   ->execute([$absUserId, $absType === 'no_show' ? 'No show' : ucwords(str_replace('_',' ',$absType))]);
                // Append to movement log
                $db->prepare("INSERT INTO team_movement_log (user_id,user_name,to_status,notes) VALUES (?,?,'absent','Absence logged by {$user['name']}')")
                   ->execute([$absUserId, $uRow['name'] ?? '']);
            } catch (\Throwable $e) {}
        }
        setFlash('success', 'Absence recorded.');
        redirect('absences.php');
    }
    if ($action === 'delete_absence') {
        $aid = (int)($_POST['abs_id'] ?? 0);
        if ($aid) $db->prepare("DELETE FROM absence_records WHERE id=?")->execute([$aid]);
        setFlash('success', 'Record deleted.');
        redirect('absences.php');
    }
}

// ── Data ──────────────────────────────────────────────────────────────────────
$filterUser  = (int)($_GET['user_id'] ?? 0);
$filterMonth = $_GET['month'] ?? date('Y-m');

$where  = "WHERE 1=1";
$params = [];
if ($filterUser) { $where .= " AND ar.user_id=?"; $params[] = $filterUser; }
if ($filterMonth) {
    $mStart = $filterMonth . '-01';
    $mEnd   = date('Y-m-t', strtotime($mStart));
    $where .= " AND ar.absence_date BETWEEN ? AND ?"; $params[] = $mStart; $params[] = $mEnd;
}

$absences = $db->prepare("SELECT ar.* FROM absence_records ar $where ORDER BY ar.absence_date DESC, ar.id DESC");
$absences->execute($params); $absences = $absences->fetchAll();

// Stats for current month
$monthAbsences = $db->prepare("SELECT ar.user_id, ar.user_name, COUNT(*) AS total,
    SUM(ar.absence_type='no_show') AS no_shows, SUM(ar.absence_type='late_no_notice') AS late_count
    FROM absence_records ar WHERE ar.absence_date BETWEEN ? AND ?
    GROUP BY ar.user_id, ar.user_name ORDER BY total DESC");
$monthStart = date('Y-m-01'); $monthEnd = date('Y-m-t');
$monthAbsences->execute([$monthStart, $monthEnd]); $monthAbsences = $monthAbsences->fetchAll();

$users = $db->query("SELECT id,name,role FROM users WHERE status='active' ORDER BY name")->fetchAll();

$absTypes  = ['no_show'=>'No Show','late_no_notice'=>'Late — No Notice','left_early'=>'Left Early','partial'=>'Partial Day'];
$typeColors= ['no_show'=>'danger','late_no_notice'=>'warning','left_early'=>'info','partial'=>'secondary'];

$pageTitle = 'Absences';
include __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div>
        <h5 class="mb-1"><i class="fa fa-calendar-xmark me-2 text-danger"></i>Absenteeism</h5>
        <div class="text-muted small">Track unplanned absences and attendance issues</div>
    </div>
    <a href="index.php" class="btn btn-sm btn-outline-secondary"><i class="fa fa-arrow-left me-1"></i>Team Board</a>
</div>

<!-- Month stats -->
<?php if ($monthAbsences): ?>
<div class="card mb-4">
    <div class="card-header"><i class="fa fa-chart-bar me-2"></i>This Month — <?= date('F Y') ?></div>
    <div class="card-body p-0">
        <table class="table table-sm mb-0" style="font-size:13px">
            <thead style="background:#f8fafc"><tr><th class="ps-3">Employee</th><th>Total Absences</th><th>No Shows</th><th>Late w/o Notice</th><th class="pe-3">Risk</th></tr></thead>
            <tbody>
            <?php foreach ($monthAbsences as $m): ?>
            <tr>
                <td class="ps-3 fw-semibold"><?= e($m['user_name']) ?></td>
                <td><span class="badge bg-danger"><?= $m['total'] ?></span></td>
                <td class="text-muted small"><?= $m['no_shows'] ?></td>
                <td class="text-muted small"><?= $m['late_count'] ?></td>
                <td class="pe-3">
                    <?php if ($m['total'] >= 4): ?><span class="badge bg-danger">High Risk</span>
                    <?php elseif ($m['total'] >= 2): ?><span class="badge bg-warning text-dark">Monitor</span>
                    <?php else: ?><span class="badge bg-light text-muted border">Normal</span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<div class="row g-4">
    <div class="col-lg-8">

        <!-- Filter -->
        <div class="card mb-3">
            <div class="card-body py-2">
                <form method="GET" class="d-flex gap-2 flex-wrap align-items-center">
                    <select name="user_id" class="form-select form-select-sm select2" style="width:220px">
                        <option value="">All Staff</option>
                        <?php foreach ($users as $u): ?><option value="<?= $u['id'] ?>" <?= $filterUser==$u['id']?'selected':'' ?>><?= e($u['name']) ?></option><?php endforeach; ?>
                    </select>
                    <input type="month" name="month" class="form-control form-control-sm" style="width:160px" value="<?= $filterMonth ?>">
                    <button class="btn btn-sm btn-outline-primary">Filter</button>
                    <?php if ($filterUser || $filterMonth !== date('Y-m')): ?><a href="absences.php" class="btn btn-sm btn-outline-secondary">Clear</a><?php endif; ?>
                </form>
            </div>
        </div>

        <!-- Records table -->
        <div class="card">
            <div class="card-header">Absence Records (<?= count($absences) ?>)</div>
            <div class="card-body p-0">
                <?php if ($absences): ?>
                <table class="table table-hover mb-0" style="font-size:13px">
                    <thead style="background:#f8fafc"><tr><th class="ps-3">Date</th><th>Employee</th><th>Type</th><th>Reason</th><th>Recorded By</th><?php if ($isManager): ?><th class="pe-3"></th><?php endif; ?></tr></thead>
                    <tbody>
                    <?php foreach ($absences as $a): ?>
                    <tr>
                        <td class="ps-3 fw-semibold"><?= fmtDate($a['absence_date'], 'd M Y') ?></td>
                        <td><?= e($a['user_name']) ?></td>
                        <td><span class="badge bg-<?= $typeColors[$a['absence_type']] ?? 'secondary' ?>"><?= $absTypes[$a['absence_type']] ?? $a['absence_type'] ?></span></td>
                        <td class="text-muted small"><?= $a['reason'] ? e($a['reason']) : '—' ?></td>
                        <td class="small text-muted"><?= e($a['recorded_by']) ?><br><span style="font-size:10px"><?= fmtDate($a['created_at'],'d M H:i') ?></span></td>
                        <?php if ($isManager): ?>
                        <td class="pe-3">
                            <form method="POST" onsubmit="return confirm('Delete this record?')">
                                <input type="hidden" name="action" value="delete_absence">
                                <input type="hidden" name="abs_id" value="<?= $a['id'] ?>">
                                <button class="btn btn-xs btn-outline-danger"><i class="fa fa-trash"></i></button>
                            </form>
                        </td>
                        <?php endif; ?>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php else: ?>
                <p class="text-muted p-4 mb-0">No absence records found.</p>
                <?php endif; ?>
            </div>
        </div>

    </div>

    <?php if ($isManager): ?>
    <div class="col-lg-4">
        <!-- Log absence form -->
        <div class="card" style="border-top:3px solid #ef4444">
            <div class="card-header fw-semibold text-danger"><i class="fa fa-plus me-2"></i>Log Absence</div>
            <div class="card-body">
                <form method="POST" class="row g-3">
                    <input type="hidden" name="action" value="log_absence">
                    <div class="col-12">
                        <label class="form-label small">Staff Member <span class="text-danger">*</span></label>
                        <select name="abs_user_id" class="form-select form-select-sm select2" required>
                            <option value="">— Select —</option>
                            <?php foreach ($users as $u): ?><option value="<?= $u['id'] ?>"><?= e($u['name']) ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12">
                        <label class="form-label small">Date</label>
                        <input type="date" name="abs_date" class="form-control form-control-sm" value="<?= date('Y-m-d') ?>">
                    </div>
                    <div class="col-12">
                        <label class="form-label small">Absence Type</label>
                        <select name="abs_type" class="form-select form-select-sm">
                            <?php foreach ($absTypes as $v=>$l): ?><option value="<?= $v ?>"><?= $l ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12">
                        <label class="form-label small">Reason / Notes</label>
                        <input type="text" name="abs_reason" class="form-control form-control-sm" placeholder="Brief explanation">
                    </div>
                    <div class="col-12">
                        <button class="btn btn-danger w-100 btn-sm"><i class="fa fa-save me-1"></i>Log Absence</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>

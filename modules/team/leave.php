<?php
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/notifications.php';
requireLogin();
canAccess('team') || die('Access denied.');
$db   = getDB();
$user = authUser();

$isManager = hasRole(['admin','general_manager','manager','hr_manager','sales_manager','workshop_manager']);

// ── POST handlers ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // Submit new leave request (for self OR HR submitting for staff)
    if ($action === 'submit_leave') {
        $staffKey  = $_POST['staff_key']   ?? 'user_'.$user['id'];
        $leaveType = $_POST['leave_type']  ?? 'annual';
        $startDate = $_POST['start_date']  ?? '';
        $endDate   = $_POST['end_date']    ?? '';
        $reason    = trim($_POST['reason'] ?? '');
        
        if (!$startDate || !$endDate || !$reason) { setFlash('error','Please fill all required fields.'); redirect('leave.php'); }
        
        // Resolve staff type and ID
        [$stype, $sid] = explode('_', $staffKey);
        $sname = $user['name'];
        if ($staffKey !== 'user_'.$user['id'] && $isManager) {
            // HR submitting for someone else
            $table = $stype === 'user' ? 'users' : ($stype === 'mechanic' ? 'mechanics' : 'drivers');
            $srow = $db->query("SELECT name FROM $table WHERE id=".(int)$sid)->fetch();
            $sname = $srow['name'] ?? 'Unknown';
        }

        // Calculate days (calendar days, inclusive, excl weekends)
        $days = 0;
        $cur  = strtotime($startDate);
        $end  = strtotime($endDate);
        while ($cur <= $end) { if (!in_array(date('N',$cur),[6,7])) $days++; $cur = strtotime('+1 day',$cur); }
        $days = max(0.5, $days);

        $db->prepare("INSERT INTO leave_requests (staff_type, staff_id, user_name, leave_type, start_date, end_date, days_count, reason, status) VALUES (?,?,?,?,?,?,?,?,'pending')")
           ->execute([$stype, $sid, $sname, $leaveType, $startDate, $endDate, $days, $reason]);
        $newId = $db->lastInsertId();
        
        logActivity('create','team',$newId,"Leave request for $sname submitted");
        setFlash('success', "Leave request submitted — awaiting approval.");
        redirect('leave.php' . ($staffKey !== 'user_'.$user['id'] ? '?tab=all' : ''));
    }

    // Cancel own request
    if ($action === 'cancel_leave') {
        $lid = (int)($_POST['leave_id'] ?? 0);
        $req = $db->prepare("SELECT * FROM leave_requests WHERE id=? AND staff_type='user' AND staff_id=?"); 
        $req->execute([$lid,$user['id']]); $req=$req->fetch();
        if ($req && $req['status'] === 'pending') {
            $db->prepare("UPDATE leave_requests SET status='rejected', notes='Cancelled by user', updated_at=NOW() WHERE id=?")->execute([$lid]);
            setFlash('success', 'Leave request cancelled.');
        }
        redirect('leave.php');
    }

    // Manager: approve / reject
    if ($isManager && in_array($action, ['approve','reject'])) {
        $lid    = (int)($_POST['leave_id']          ?? 0);
        $reason = trim($_POST['rejection_reason']   ?? '');
        $req    = $db->prepare("SELECT * FROM leave_requests WHERE id=?"); $req->execute([$lid]); $req=$req->fetch();
        
        if ($req && $req['status'] === 'pending') {
            if ($action === 'approve') {
                $db->prepare("UPDATE leave_requests SET status='approved', approved_by=?, approved_at=NOW(), updated_at=NOW() WHERE id=?")
                   ->execute([$user['id'], $lid]);
                
                // Track balance usage
                $db->prepare("UPDATE leave_balances SET taken_annual = taken_annual + ? WHERE staff_type=? AND staff_id=? AND leave_year=? AND ?='annual'")
                   ->execute([$req['days_count'], $req['staff_type'], $req['staff_id'], date('Y', strtotime($req['start_date'])), $req['leave_type']]);
                $db->prepare("UPDATE leave_balances SET taken_sick = taken_sick + ? WHERE staff_type=? AND staff_id=? AND leave_year=? AND ?='sick'")
                   ->execute([$req['days_count'], $req['staff_type'], $req['staff_id'], date('Y', strtotime($req['start_date'])), $req['leave_type']]);

                setFlash('success', "Leave approved.");
            } else {
                if (!$reason) { setFlash('error','Please provide a rejection reason.'); redirect('leave.php?tab=pending'); }
                $db->prepare("UPDATE leave_requests SET status='rejected', approved_by=?, notes=?, updated_at=NOW() WHERE id=?")
                   ->execute([$user['id'], $reason, $lid]);
                setFlash('success', "Leave rejected.");
            }
        }
        redirect('leave.php?tab=pending');
    }

    // Manager: update balance entitlement
    if ($isManager && $action === 'set_balance') {
        $staffKey = $_POST['balance_staff_key'] ?? '';
        $aDays    = (float)($_POST['annual_days'] ?? 21);
        $sDays    = (float)($_POST['sick_days']   ?? 14);
        $bYear    = (int)($_POST['balance_year']    ?? date('Y'));
        
        if ($staffKey) {
            [$stype, $sid] = explode('_', $staffKey);
            $db->prepare("INSERT INTO leave_balances (staff_type,staff_id,leave_year,annual_days,sick_days) VALUES (?,?,?,?,?)
                ON DUPLICATE KEY UPDATE annual_days=VALUES(annual_days),sick_days=VALUES(sick_days),updated_at=NOW()")
               ->execute([$stype, $sid, $bYear, $aDays, $sDays]);
            setFlash('success', 'Leave balance updated.');
        }
        redirect('leave.php?tab=balances');
    }
}

// ── Data ──────────────────────────────────────────────────────────────────────
$tab = $_GET['tab'] ?? 'mine';

// My leave requests
$myLeave = $db->prepare("SELECT * FROM leave_requests WHERE staff_type='user' AND staff_id=? ORDER BY created_at DESC");
$myLeave->execute([$user['id']]); $myLeave = $myLeave->fetchAll();

// My leave balances for current year
$myBalances = $db->prepare("SELECT * FROM leave_balances WHERE staff_type='user' AND staff_id=? AND leave_year=?");
$myBalances->execute([$user['id'], date('Y')]); $myBalances = $myBalances->fetch() ?: ['annual_days'=>21,'sick_days'=>14,'taken_annual'=>0,'taken_sick'=>0];

// Manager: pending requests
$pending = [];
if ($isManager) {
    $pending = $db->query("SELECT lr.*, u.name as approver_name FROM leave_requests lr LEFT JOIN users u ON u.id=lr.approved_by WHERE lr.status='pending' ORDER BY lr.start_date")->fetchAll();
}

// Manager: all requests (for history tab)
$allLeave = [];
if ($isManager) {
    $allLeave = $db->query("SELECT lr.*, u.name as approver_name FROM leave_requests lr LEFT JOIN users u ON u.id=lr.approved_by ORDER BY lr.created_at DESC LIMIT 200")->fetchAll();
}

// All Staff for balance/HR management
$allStaff = [];
if ($isManager) {
    $u = $db->query("SELECT id, 'user' as stype, name, role FROM users WHERE status='active'")->fetchAll();
    $m = $db->query("SELECT id, 'mechanic' as stype, name, 'mechanic' as role FROM mechanics WHERE status='active'")->fetchAll();
    $d = $db->query("SELECT id, 'driver' as stype, name, 'driver' as role FROM drivers WHERE status='active'")->fetchAll();
    $allStaff = array_merge($u, $m, $d);
    usort($allStaff, fn($a,$b) => strcmp($a['name'], $b['name']));
}

$leaveTypes  = ['annual'=>'Annual','sick'=>'Sick','emergency'=>'Emergency','maternity'=>'Maternity','paternity'=>'Paternity','unpaid'=>'Unpaid','study'=>'Study'];
$typeColors  = ['annual'=>'primary','sick'=>'danger','emergency'=>'warning','maternity'=>'pink','paternity'=>'info','unpaid'=>'secondary','study'=>'success'];
$stColors    = ['pending'=>'warning','approved'=>'success','rejected'=>'danger','cancelled'=>'secondary'];

$pageTitle = 'Leave Management';
include __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div>
        <h5 class="mb-1"><i class="fa fa-umbrella-beach me-2 text-primary"></i>Leave Management</h5>
    </div>
    <a href="index.php" class="btn btn-sm btn-outline-secondary"><i class="fa fa-arrow-left me-1"></i>Team Board</a>
</div>

<ul class="nav nav-tabs mb-4">
    <li class="nav-item"><a class="nav-link <?= $tab==='mine'?'active':'' ?>" href="?tab=mine">My Leave</a></li>
    <?php if ($isManager): ?>
    <li class="nav-item">
        <a class="nav-link <?= $tab==='pending'?'active':'' ?>" href="?tab=pending">
            Pending Approval <?php if ($pending): ?><span class="badge bg-danger ms-1"><?= count($pending) ?></span><?php endif; ?>
        </a>
    </li>
    <li class="nav-item"><a class="nav-link <?= $tab==='all'?'active':'' ?>" href="?tab=all">All Requests</a></li>
    <li class="nav-item"><a class="nav-link <?= $tab==='balances'?'active':'' ?>" href="?tab=balances">Balances</a></li>
    <?php endif; ?>
</ul>

<?php if ($tab === 'mine'): ?>

<!-- Leave balances summary -->
<div class="row g-3 mb-4">
    <?php foreach (['annual','sick'] as $typ):
        $used = (float)$myBalances["taken_$typ"];
        $entitled = (float)$myBalances["{$typ}_days"];
        $remaining = max(0, $entitled - $used);
        $pct = $entitled > 0 ? min(100, round($used/$entitled*100)) : 0;
    ?>
    <div class="col-md-3 col-6">
        <div class="card p-3">
            <div class="fw-semibold small"><?= ucfirst($typ) ?> Leave</div>
            <div class="d-flex justify-content-between mt-1">
                <span class="text-success fw-bold"><?= $remaining ?> remaining</span>
                <span class="text-muted small"><?= $used ?>/<?= $entitled ?> used</span>
            </div>
            <div class="progress mt-1" style="height:4px">
                <div class="progress-bar bg-<?= $typeColors[$typ] ?? 'primary' ?>" style="width:<?= $pct ?>%"></div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<!-- Apply for leave -->
<div class="card mb-4">
    <div class="card-header fw-semibold d-flex justify-content-between align-items-center">
        <span><i class="fa fa-plus me-2"></i>Apply for Leave</span>
    </div>
    <div class="card-body">
        <form method="POST" class="row g-3">
            <input type="hidden" name="action" value="submit_leave">
            <?php if ($isManager): ?>
            <div class="col-md-3">
                <label class="form-label">Employee <span class="text-danger">*</span></label>
                <select name="staff_key" class="form-select select2">
                    <?php foreach ($allStaff as $s): ?>
                    <option value="<?= $s['stype'].'_'.$s['id'] ?>" <?= ($s['stype']==='user' && $s['id']==$user['id'])?'selected':'' ?>>
                        <?= e($s['name']) ?> (<?= ucfirst($s['stype']) ?>)
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php else: ?>
            <input type="hidden" name="staff_key" value="user_<?= $user['id'] ?>">
            <?php endif; ?>
            <div class="col-md-2">
                <label class="form-label">Type <span class="text-danger">*</span></label>
                <select name="leave_type" class="form-select">
                    <?php foreach ($leaveTypes as $v=>$l): ?><option value="<?= $v ?>"><?= $l ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">From <span class="text-danger">*</span></label>
                <input type="date" name="start_date" class="form-control" required min="<?= date('Y-m-d') ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label">To <span class="text-danger">*</span></label>
                <input type="date" name="end_date" class="form-control" required min="<?= date('Y-m-d') ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label">Reason <span class="text-danger">*</span></label>
                <input type="text" name="reason" class="form-control" required>
            </div>
            <div class="col-md-1 d-flex align-items-end">
                <button class="btn btn-primary w-100"><i class="fa fa-paper-plane"></i></button>
            </div>
        </form>
    </div>
</div>

<!-- My requests -->
<div class="card">
    <div class="card-header"><i class="fa fa-list me-2"></i>My Leave Requests</div>
    <div class="card-body p-0">
        <?php if ($myLeave): ?>
        <table class="table table-hover mb-0" style="font-size:13px">
            <thead style="background:#f8fafc"><tr><th>Type</th><th>Period</th><th>Days</th><th>Reason</th><th>Status</th><th class="pe-3">Action</th></tr></thead>
            <tbody>
            <?php foreach ($myLeave as $lr): ?>
            <tr>
                <td><span class="badge bg-<?= $typeColors[$lr['leave_type']] ?? 'secondary' ?>"><?= $leaveTypes[$lr['leave_type']] ?? $lr['leave_type'] ?></span></td>
                <td class="small"><?= fmtDate($lr['start_date'],'d M Y') ?> – <?= fmtDate($lr['end_date'],'d M Y') ?></td>
                <td class="fw-semibold"><?= $lr['days_count'] ?></td>
                <td class="small text-muted" style="max-width:180px"><?= e($lr['reason']) ?></td>
                <td>
                    <span class="badge bg-<?= $stColors[$lr['status']] ?? 'secondary' ?>"><?= ucfirst($lr['status']) ?></span>
                    <?php if ($lr['notes']): ?>
                    <div class="text-danger small mt-1" style="font-size:10px"><?= e($lr['notes']) ?></div>
                    <?php endif; ?>
                </td>
                <td class="pe-3">
                    <?php if ($lr['status'] === 'pending'): ?>
                    <form method="POST" onsubmit="return confirm('Cancel this leave request?')">
                        <input type="hidden" name="action" value="cancel_leave">
                        <input type="hidden" name="leave_id" value="<?= $lr['id'] ?>">
                        <button class="btn btn-xs btn-outline-danger"><i class="fa fa-xmark"></i></button>
                    </form>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php else: ?>
        <p class="text-muted p-4 mb-0">No leave requests submitted yet.</p>
        <?php endif; ?>
    </div>
</div>

<?php elseif ($tab === 'pending' && $isManager): ?>

<div class="card">
    <div class="card-header"><i class="fa fa-clock me-2 text-warning"></i>Pending Approval (<?= count($pending) ?>)</div>
    <div class="card-body p-0">
        <?php if ($pending): ?>
        <?php foreach ($pending as $lr): ?>
        <div class="p-4 border-bottom">
            <div class="d-flex justify-content-between flex-wrap gap-2">
                <div>
                    <div class="fw-semibold"><?= e($lr['user_name']) ?> <span class="badge bg-secondary ms-1"><?= ucfirst($lr['staff_type']) ?></span></div>
                    <div class="text-muted small">Submitted <?= fmtDate($lr['created_at']) ?></div>
                    <div class="mt-1">
                        <span class="badge bg-<?= $typeColors[$lr['leave_type']] ?? 'secondary' ?>"><?= $leaveTypes[$lr['leave_type']] ?></span>
                        <span class="ms-2"><?= fmtDate($lr['start_date'],'d M Y') ?> – <?= fmtDate($lr['end_date'],'d M Y') ?></span>
                        <strong class="ms-2"><?= $lr['days_count'] ?> working day<?= $lr['days_count'] != 1 ? 's' : '' ?></strong>
                    </div>
                    <div class="text-muted small mt-1"><i class="fa fa-quote-left me-1"></i><?= e($lr['reason']) ?></div>
                </div>
                <div class="d-flex gap-2 align-items-start">
                    <form method="POST">
                        <input type="hidden" name="action" value="approve">
                        <input type="hidden" name="leave_id" value="<?= $lr['id'] ?>">
                        <button class="btn btn-sm btn-success" onclick="return confirm('Approve this leave request?')">
                            <i class="fa fa-check me-1"></i>Approve
                        </button>
                    </form>
                    <form method="POST" class="d-flex gap-1 align-items-center">
                        <input type="hidden" name="action" value="reject">
                        <input type="hidden" name="leave_id" value="<?= $lr['id'] ?>">
                        <input type="text" name="rejection_reason" class="form-control form-control-sm" style="width:180px" placeholder="Rejection reason *" required>
                        <button class="btn btn-sm btn-outline-danger"><i class="fa fa-xmark me-1"></i>Reject</button>
                    </form>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
        <?php else: ?>
        <div class="text-center py-5 text-muted"><i class="fa fa-check-circle fa-2x mb-2 d-block opacity-25"></i>No pending leave requests.</div>
        <?php endif; ?>
    </div>
</div>

<?php elseif ($tab === 'all' && $isManager): ?>

<div class="card">
    <div class="card-header">All Leave Requests</div>
    <div class="card-body p-0">
        <div class="table-responsive">
        <table class="table table-hover datatable mb-0" style="font-size:13px">
            <thead style="background:#f8fafc"><tr><th>Employee</th><th>Type</th><th>Period</th><th>Days</th><th>Status</th><th class="pe-3">Actioned By</th></tr></thead>
            <tbody>
            <?php foreach ($allLeave as $lr): ?>
            <tr>
                <td class="fw-semibold"><?= e($lr['user_name']) ?> <span class="text-muted small">(<?= ucfirst($lr['staff_type']) ?>)</span></td>
                <td><span class="badge bg-<?= $typeColors[$lr['leave_type']] ?? 'secondary' ?>"><?= $leaveTypes[$lr['leave_type']] ?></span></td>
                <td class="small"><?= fmtDate($lr['start_date'],'d M Y') ?> – <?= fmtDate($lr['end_date'],'d M Y') ?></td>
                <td class="fw-semibold"><?= $lr['days_count'] ?></td>
                <td><span class="badge bg-<?= $stColors[$lr['status']] ?? 'secondary' ?>"><?= ucfirst($lr['status']) ?></span></td>
                <td class="pe-3 small text-muted"><?= e($lr['approver_name'] ?? '—') ?><?= $lr['approved_at'] ? '<br>'.fmtDate($lr['approved_at'],'d M Y') : '' ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>
</div>

<?php elseif ($tab === 'balances' && $isManager): ?>

<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="fa fa-scale-balanced me-2"></i>Leave Balances — <?= date('Y') ?></span>
    </div>
    <div class="card-body">
        <form method="POST" class="row g-2 align-items-end mb-4 p-3 border rounded" style="background:#f8fafc">
            <input type="hidden" name="action" value="set_balance">
            <div class="col-md-3">
                <label class="form-label small">Employee</label>
                <select name="balance_staff_key" class="form-select form-select-sm select2" required>
                    <option value="">— Select —</option>
                    <?php foreach ($allStaff as $s): ?><option value="<?= $s['stype'].'_'.$s['id'] ?>"><?= e($s['name']) ?> (<?= ucfirst($s['stype']) ?>)</option><?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small">Annual Entitlement</label>
                <input type="number" name="annual_days" class="form-control form-control-sm" value="21" min="0" step="0.5" required>
            </div>
            <div class="col-md-2">
                <label class="form-label small">Sick Entitlement</label>
                <input type="number" name="sick_days" class="form-control form-control-sm" value="14" min="0" step="0.5" required>
            </div>
            <div class="col-md-2">
                <label class="form-label small">Year</label>
                <input type="number" name="balance_year" class="form-control form-control-sm" value="<?= date('Y') ?>">
            </div>
            <div class="col-md-2">
                <button class="btn btn-primary btn-sm"><i class="fa fa-save me-1"></i>Set Balances</button>
            </div>
        </form>

        <?php
        try {
            $allBal = $db->query("SELECT * FROM leave_balances WHERE leave_year=".date('Y'))->fetchAll();
            $balMap = [];
            foreach ($allBal as $b) $balMap[$b['staff_type'].'_'.$b['staff_id']] = $b;
        } catch (\Throwable $e) { $balMap = []; }
        ?>
        <div class="table-responsive">
        <table class="table table-sm table-bordered mb-0" style="font-size:13px; text-align:center">
            <thead style="background:#f8fafc">
                <tr><th class="text-start ps-3" rowspan="2" style="vertical-align:middle">Employee</th>
                <th colspan="3">Annual Leave</th><th colspan="3">Sick Leave</th></tr>
                <tr><th>Entitled</th><th>Taken</th><th>Remaining</th>
                <th>Entitled</th><th>Taken</th><th>Remaining</th></tr>
            </thead>
            <tbody>
            <?php foreach ($allStaff as $s):
                $b = $balMap[$s['stype'].'_'.$s['id']] ?? ['annual_days'=>21,'sick_days'=>14,'taken_annual'=>0,'taken_sick'=>0];
                $remA = max(0, $b['annual_days'] - $b['taken_annual']);
                $remS = max(0, $b['sick_days'] - $b['taken_sick']);
            ?>
            <tr>
                <td class="text-start ps-3 fw-semibold"><?= e($s['name']) ?> <span class="text-muted small ms-1">(<?= ucfirst($s['stype']) ?>)</span></td>
                <td><?= (float)$b['annual_days'] ?></td>
                <td class="text-danger"><?= (float)$b['taken_annual'] ?></td>
                <td class="fw-bold <?= $remA <= 0 ? 'text-danger':'text-success' ?>"><?= $remA ?></td>
                <td><?= (float)$b['sick_days'] ?></td>
                <td class="text-danger"><?= (float)$b['taken_sick'] ?></td>
                <td class="fw-bold <?= $remS <= 0 ? 'text-danger':'text-success' ?>"><?= $remS ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>
</div>

<?php endif; ?>

<?php include __DIR__ . '/../../includes/footer.php'; ?>

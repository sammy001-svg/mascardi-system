<?php
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
canAccess('team') || die('Access denied.');
$db   = getDB();
$user = authUser();

$isManager = hasRole(['admin','general_manager','manager','hr_manager','sales_manager','workshop_manager']);

// ── Migrations ────────────────────────────────────────────────────────────────
foreach ([
    "CREATE TABLE IF NOT EXISTS leave_requests (
        id INT AUTO_INCREMENT PRIMARY KEY,
        leave_number VARCHAR(20) UNIQUE NOT NULL,
        user_id INT NOT NULL,
        user_name VARCHAR(150) NOT NULL DEFAULT '',
        leave_type ENUM('annual','sick','emergency','maternity','paternity','unpaid','study') NOT NULL,
        start_date DATE NOT NULL,
        end_date DATE NOT NULL,
        days_count DECIMAL(4,1) NOT NULL DEFAULT 1.0,
        reason TEXT NOT NULL,
        status ENUM('pending','approved','rejected','cancelled') DEFAULT 'pending',
        approved_by VARCHAR(100) NULL,
        approved_at DATETIME NULL,
        rejection_reason TEXT NULL,
        notes TEXT NULL,
        raised_by VARCHAR(100) NOT NULL DEFAULT '',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )",
    "CREATE TABLE IF NOT EXISTS leave_balances (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        year YEAR NOT NULL,
        leave_type ENUM('annual','sick','emergency','maternity','paternity','unpaid','study') NOT NULL,
        days_entitled DECIMAL(4,1) DEFAULT 21.0,
        carried_over DECIMAL(4,1) DEFAULT 0.0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY unique_balance (user_id, year, leave_type)
    )",
] as $_mig) { try { $db->exec($_mig); } catch (\Throwable $_e) {} }

// ── POST handlers ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // Submit new leave request
    if ($action === 'submit_leave') {
        $leaveType = $_POST['leave_type']  ?? 'annual';
        $startDate = $_POST['start_date']  ?? '';
        $endDate   = $_POST['end_date']    ?? '';
        $reason    = trim($_POST['reason'] ?? '');
        if (!$startDate || !$endDate || !$reason) { setFlash('error','Please fill all required fields.'); redirect('leave.php'); }
        // Calculate days (calendar days, inclusive, excl weekends)
        $days = 0;
        $cur  = strtotime($startDate);
        $end  = strtotime($endDate);
        while ($cur <= $end) { if (!in_array(date('N',$cur),[6,7])) $days++; $cur = strtotime('+1 day',$cur); }
        $days = max(0.5, $days);

        $leaveNum = nextNumber('leave_requests','leave_number','LR');
        $db->prepare("INSERT INTO leave_requests (leave_number,user_id,user_name,leave_type,start_date,end_date,days_count,reason,status,raised_by) VALUES (?,?,?,?,?,?,?,?,'pending',?)")
           ->execute([$leaveNum, $user['id'], $user['name'], $leaveType, $startDate, $endDate, $days, $reason, $user['name']]);
        logActivity('create','team',$db->lastInsertId(),"Leave request $leaveNum submitted by {$user['name']}");
        setFlash('success', "Leave request $leaveNum submitted — awaiting approval.");
        redirect('leave.php');
    }

    // Cancel own request
    if ($action === 'cancel_leave') {
        $lid = (int)($_POST['leave_id'] ?? 0);
        $req = $db->prepare("SELECT * FROM leave_requests WHERE id=? AND user_id=?"); $req->execute([$lid,$user['id']]); $req=$req->fetch();
        if ($req && $req['status'] === 'pending') {
            $db->prepare("UPDATE leave_requests SET status='cancelled',updated_at=NOW() WHERE id=?")->execute([$lid]);
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
                $db->prepare("UPDATE leave_requests SET status='approved',approved_by=?,approved_at=NOW(),updated_at=NOW() WHERE id=?")
                   ->execute([$user['name'], $lid]);
                // Update team_checkin if leave starts today or is active
                $today = date('Y-m-d');
                if ($req['start_date'] <= $today && $req['end_date'] >= $today) {
                    try {
                        $db->prepare("INSERT INTO team_checkins (user_id,status,notes,checked_in_at) VALUES (?,'on_leave','Approved leave',NOW()) ON DUPLICATE KEY UPDATE status='on_leave',notes='Approved leave',checked_in_at=NOW()")
                           ->execute([$req['user_id']]);
                    } catch (\Throwable $e) {}
                }
                setFlash('success', "Leave request {$req['leave_number']} approved.");
            } else {
                if (!$reason) { setFlash('error','Please provide a rejection reason.'); redirect('leave.php'); }
                $db->prepare("UPDATE leave_requests SET status='rejected',approved_by=?,rejection_reason=?,updated_at=NOW() WHERE id=?")
                   ->execute([$user['name'], $reason, $lid]);
                setFlash('success', "Leave request {$req['leave_number']} rejected.");
            }
            logActivity('update','team',$lid,"Leave request {$req['leave_number']} {$action}d by {$user['name']}");
        }
        redirect('leave.php');
    }

    // Manager: update balance entitlement
    if ($isManager && $action === 'set_balance') {
        $bUserId  = (int)($_POST['balance_user_id'] ?? 0);
        $bType    = $_POST['balance_type']          ?? 'annual';
        $bDays    = (float)($_POST['days_entitled'] ?? 21);
        $bYear    = (int)($_POST['balance_year']    ?? date('Y'));
        $db->prepare("INSERT INTO leave_balances (user_id,year,leave_type,days_entitled) VALUES (?,?,?,?)
            ON DUPLICATE KEY UPDATE days_entitled=VALUES(days_entitled),updated_at=NOW()")
           ->execute([$bUserId, $bYear, $bType, $bDays]);
        setFlash('success', 'Leave balance updated.');
        redirect('leave.php?tab=balances');
    }
}

// ── Data ──────────────────────────────────────────────────────────────────────
$tab = $_GET['tab'] ?? 'mine';

// My leave requests
$myLeave = $db->prepare("SELECT * FROM leave_requests WHERE user_id=? ORDER BY created_at DESC");
$myLeave->execute([$user['id']]); $myLeave = $myLeave->fetchAll();

// My leave balances for current year
$myBalances = [];
try {
    $mbStmt = $db->prepare("SELECT lb.leave_type, lb.days_entitled, lb.carried_over,
        COALESCE((SELECT SUM(days_count) FROM leave_requests WHERE user_id=lb.user_id AND leave_type=lb.leave_type AND year(start_date)=lb.year AND status='approved'),0) AS days_used
        FROM leave_balances lb WHERE lb.user_id=? AND lb.year=?");
    $mbStmt->execute([$user['id'], date('Y')]); $myBalances = $mbStmt->fetchAll();
} catch (\Throwable $e) {}

// Manager: pending requests
$pending = [];
if ($isManager) {
    $pStmt = $db->query("SELECT lr.*, u.role FROM leave_requests lr LEFT JOIN users u ON u.id=lr.user_id WHERE lr.status='pending' ORDER BY lr.start_date");
    $pending = $pStmt->fetchAll();
}

// Manager: all requests (for history tab)
$allLeave = [];
if ($isManager) {
    $allStmt = $db->query("SELECT lr.*, u.role FROM leave_requests lr LEFT JOIN users u ON u.id=lr.user_id ORDER BY lr.created_at DESC LIMIT 200");
    $allLeave = $allStmt->fetchAll();
}

// Users for balance management
$users = $isManager ? $db->query("SELECT id,name,role FROM users WHERE status='active' ORDER BY name")->fetchAll() : [];

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
<?php if ($myBalances): ?>
<div class="row g-3 mb-4">
    <?php foreach ($myBalances as $bal):
        $used = (float)$bal['days_used'];
        $entitled = (float)$bal['days_entitled'] + (float)$bal['carried_over'];
        $remaining = max(0, $entitled - $used);
        $pct = $entitled > 0 ? min(100, round($used/$entitled*100)) : 0;
    ?>
    <div class="col-md-3 col-6">
        <div class="card p-3">
            <div class="fw-semibold small"><?= $leaveTypes[$bal['leave_type']] ?> Leave</div>
            <div class="d-flex justify-content-between mt-1">
                <span class="text-success fw-bold"><?= $remaining ?> remaining</span>
                <span class="text-muted small"><?= $used ?>/<?= $entitled ?> used</span>
            </div>
            <div class="progress mt-1" style="height:4px">
                <div class="progress-bar bg-<?= $typeColors[$bal['leave_type']] ?? 'primary' ?>" style="width:<?= $pct ?>%"></div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- Apply for leave -->
<div class="card mb-4">
    <div class="card-header fw-semibold d-flex justify-content-between align-items-center">
        <span><i class="fa fa-plus me-2"></i>Apply for Leave</span>
    </div>
    <div class="card-body">
        <form method="POST" class="row g-3">
            <input type="hidden" name="action" value="submit_leave">
            <div class="col-md-3">
                <label class="form-label">Leave Type <span class="text-danger">*</span></label>
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
            <div class="col-md-4">
                <label class="form-label">Reason <span class="text-danger">*</span></label>
                <input type="text" name="reason" class="form-control" placeholder="Brief reason for leave" required>
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
            <thead style="background:#f8fafc"><tr><th class="ps-3">Ref #</th><th>Type</th><th>Period</th><th>Days</th><th>Reason</th><th>Status</th><th class="pe-3">Action</th></tr></thead>
            <tbody>
            <?php foreach ($myLeave as $lr): ?>
            <tr>
                <td class="ps-3 fw-bold"><?= e($lr['leave_number']) ?></td>
                <td><span class="badge bg-<?= $typeColors[$lr['leave_type']] ?? 'secondary' ?>"><?= $leaveTypes[$lr['leave_type']] ?? $lr['leave_type'] ?></span></td>
                <td class="small"><?= fmtDate($lr['start_date'],'d M Y') ?> – <?= fmtDate($lr['end_date'],'d M Y') ?></td>
                <td class="fw-semibold"><?= $lr['days_count'] ?></td>
                <td class="small text-muted" style="max-width:180px"><?= e($lr['reason']) ?></td>
                <td>
                    <span class="badge bg-<?= $stColors[$lr['status']] ?? 'secondary' ?>"><?= ucfirst($lr['status']) ?></span>
                    <?php if ($lr['rejection_reason']): ?>
                    <div class="text-danger small mt-1" style="font-size:10px"><?= e($lr['rejection_reason']) ?></div>
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
                    <div class="fw-semibold"><?= e($lr['user_name']) ?> <span class="badge bg-secondary ms-1"><?= e(ucwords(str_replace('_',' ',$lr['role'] ?? ''))) ?></span></div>
                    <div class="text-muted small"><?= e($lr['leave_number']) ?> · Submitted <?= fmtDate($lr['created_at']) ?></div>
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
            <thead style="background:#f8fafc"><tr><th class="ps-3">Ref</th><th>Employee</th><th>Type</th><th>Period</th><th>Days</th><th>Status</th><th class="pe-3">Actioned By</th></tr></thead>
            <tbody>
            <?php foreach ($allLeave as $lr): ?>
            <tr>
                <td class="ps-3 fw-bold"><?= e($lr['leave_number']) ?></td>
                <td class="fw-semibold"><?= e($lr['user_name']) ?></td>
                <td><span class="badge bg-<?= $typeColors[$lr['leave_type']] ?? 'secondary' ?>"><?= $leaveTypes[$lr['leave_type']] ?></span></td>
                <td class="small"><?= fmtDate($lr['start_date'],'d M Y') ?> – <?= fmtDate($lr['end_date'],'d M Y') ?> (<?= $lr['days_count'] ?>d)</td>
                <td class="fw-semibold"><?= $lr['days_count'] ?></td>
                <td><span class="badge bg-<?= $stColors[$lr['status']] ?? 'secondary' ?>"><?= ucfirst($lr['status']) ?></span></td>
                <td class="pe-3 small text-muted"><?= e($lr['approved_by'] ?? '—') ?><?= $lr['approved_at'] ? '<br>'.fmtDate($lr['approved_at'],'d M Y') : '' ?></td>
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
                <select name="balance_user_id" class="form-select form-select-sm select2" required>
                    <option value="">— Select —</option>
                    <?php foreach ($users as $u): ?><option value="<?= $u['id'] ?>"><?= e($u['name']) ?> (<?= ucwords(str_replace('_',' ',$u['role'])) ?>)</option><?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small">Leave Type</label>
                <select name="balance_type" class="form-select form-select-sm"><?php foreach ($leaveTypes as $v=>$l): ?><option value="<?= $v ?>"><?= $l ?></option><?php endforeach; ?></select>
            </div>
            <div class="col-md-2">
                <label class="form-label small">Days Entitled</label>
                <input type="number" name="days_entitled" class="form-control form-control-sm" value="21" min="0" step="0.5" required>
            </div>
            <div class="col-md-2">
                <label class="form-label small">Year</label>
                <input type="number" name="balance_year" class="form-control form-control-sm" value="<?= date('Y') ?>">
            </div>
            <div class="col-md-2">
                <button class="btn btn-primary btn-sm"><i class="fa fa-save me-1"></i>Set Balance</button>
            </div>
        </form>

        <?php
        // Show all balances for this year
        try {
            $allBal = $db->prepare("
                SELECT lb.*, u.name AS user_name, u.role,
                    COALESCE((SELECT SUM(lr.days_count) FROM leave_requests lr WHERE lr.user_id=lb.user_id AND lr.leave_type=lb.leave_type AND YEAR(lr.start_date)=lb.year AND lr.status='approved'),0) AS days_used
                FROM leave_balances lb JOIN users u ON u.id=lb.user_id WHERE lb.year=? ORDER BY u.name, lb.leave_type
            "); $allBal->execute([date('Y')]); $allBal=$allBal->fetchAll();
        } catch (\Throwable $e) { $allBal = []; }
        if ($allBal): ?>
        <div class="table-responsive">
        <table class="table table-sm mb-0" style="font-size:13px">
            <thead style="background:#f8fafc"><tr><th class="ps-3">Employee</th><th>Type</th><th>Entitled</th><th>Used</th><th>Remaining</th></tr></thead>
            <tbody>
            <?php foreach ($allBal as $b):
                $remaining = max(0,(float)$b['days_entitled']+(float)$b['carried_over']-(float)$b['days_used']);
            ?>
            <tr>
                <td class="ps-3 fw-semibold"><?= e($b['user_name']) ?></td>
                <td><span class="badge bg-<?= $typeColors[$b['leave_type']] ?? 'secondary' ?>"><?= $leaveTypes[$b['leave_type']] ?></span></td>
                <td><?= $b['days_entitled'] ?></td>
                <td class="text-danger"><?= $b['days_used'] ?></td>
                <td class="fw-semibold <?= $remaining <= 0 ? 'text-danger' : 'text-success' ?>"><?= $remaining ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php else: ?><p class="text-muted mb-0">No leave balances set yet for <?= date('Y') ?>.</p><?php endif; ?>
    </div>
</div>

<?php endif; ?>

<?php include __DIR__ . '/../../includes/footer.php'; ?>

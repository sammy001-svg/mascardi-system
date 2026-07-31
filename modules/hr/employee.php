<?php
/**
 * HR — Employee record
 *
 * View and edit one person's employment file. The person's identity (name,
 * phone, login) still belongs to their source table — Users, Mechanics or
 * Drivers — so this page never writes to those; it owns hr_employees only.
 */
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/_bootstrap.php';
requireLogin();
canAccess('hr') || redirect(BASE_URL . '/index.php');

$db = getDB();
hrMigrate($db);

$ref = hrParseKey($_GET['staff'] ?? '');
if (!$ref) { setFlash('error', 'No employee selected.'); redirect(BASE_URL . '/modules/hr/employees.php'); }

$person = hrStaffMember($db, $ref['type'], $ref['id']);
if (!$person) { setFlash('error', 'That employee no longer exists.'); redirect(BASE_URL . '/modules/hr/employees.php'); }

$canEdit = canWrite('hr');
$errors  = [];

// ── Save the employment record ────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save' && $canEdit) {
    verifyCsrf();

    $dateOrNull = static function (string $k): ?string {
        $v = trim($_POST[$k] ?? '');
        return $v !== '' ? $v : null;
    };

    $status   = $_POST['employment_status'] ?? 'active';
    $contract = $_POST['contract_type']     ?? 'permanent';
    if (!in_array($status, ['active','probation','suspended','exited'], true)) $status = 'active';
    if (!isset(hrContractTypes()[$contract])) $contract = 'permanent';

    $dept = trim($_POST['department'] ?? '');
    if ($dept !== '' && !in_array($dept, hrDepartments(), true)) $dept = '';

    $empNo = trim($_POST['employee_no'] ?? '');
    // Employee numbers are used on payslips and letters — a duplicate makes two
    // people indistinguishable on paper, so it is checked rather than trusted.
    if ($empNo !== '') {
        try {
            $dupe = $db->prepare("SELECT COUNT(*) FROM hr_employees
                                  WHERE employee_no = ? AND NOT (staff_type = ? AND staff_id = ?)");
            $dupe->execute([$empNo, $ref['type'], $ref['id']]);
            if ((int)$dupe->fetchColumn() > 0) $errors[] = 'That employee number is already used by someone else.';
        } catch (\Throwable $_) {}
    }

    $exitDate = $dateOrNull('exit_date');
    if ($status === 'exited' && !$exitDate) {
        $errors[] = 'An exit date is required when the employment status is Exited.';
    }

    $hire = $dateOrNull('hire_date');
    if ($hire && $exitDate && strtotime($exitDate) < strtotime($hire)) {
        $errors[] = 'The exit date cannot be before the hire date.';
    }

    if (!$errors) {
        $fields = [
            'employee_no'          => $empNo ?: null,
            'job_title'            => trim($_POST['job_title'] ?? '') ?: null,
            'department'           => $dept ?: null,
            'contract_type'        => $contract,
            'hire_date'            => $hire,
            'probation_end'        => $dateOrNull('probation_end'),
            'contract_end'         => $dateOrNull('contract_end'),
            'exit_date'            => $exitDate,
            'exit_reason'          => trim($_POST['exit_reason'] ?? '') ?: null,
            'employment_status'    => $status,
            'national_id'          => trim($_POST['national_id'] ?? '') ?: null,
            'kra_pin'              => trim($_POST['kra_pin'] ?? '') ?: null,
            'nssf_no'              => trim($_POST['nssf_no'] ?? '') ?: null,
            'nhif_no'              => trim($_POST['nhif_no'] ?? '') ?: null,
            'bank_name'            => trim($_POST['bank_name'] ?? '') ?: null,
            'bank_account'         => trim($_POST['bank_account'] ?? '') ?: null,
            'next_of_kin'          => trim($_POST['next_of_kin'] ?? '') ?: null,
            'next_of_kin_phone'    => trim($_POST['next_of_kin_phone'] ?? '') ?: null,
            'next_of_kin_relation' => trim($_POST['next_of_kin_relation'] ?? '') ?: null,
            'notes'                => trim($_POST['notes'] ?? '') ?: null,
        ];

        try {
            $cols   = array_keys($fields);
            $set    = implode(', ', array_map(fn($c) => "{$c} = VALUES({$c})", $cols));
            $insCol = implode(', ', array_merge(['staff_type','staff_id','created_by'], $cols));
            $insVal = implode(', ', array_fill(0, count($cols) + 3, '?'));

            $db->prepare("INSERT INTO hr_employees ({$insCol}) VALUES ({$insVal})
                          ON DUPLICATE KEY UPDATE {$set}")
               ->execute(array_merge(
                   [$ref['type'], $ref['id'], (int)(authUser()['id'] ?? 0)],
                   array_values($fields)
               ));

            logActivity('update', 'hr', $ref['id'],
                "Updated employment record for {$person['name']} ({$ref['type']})");
            setFlash('success', 'Employment record saved.');
            redirect(BASE_URL . '/modules/hr/employee.php?staff=' . hrKey($ref['type'], $ref['id']));
        } catch (\Throwable $e) {
            error_log('hr/employee save: ' . $e->getMessage());
            $errors[] = 'Could not save the record: ' . $e->getMessage();
        }
    }
}

// ── Reload after any POST so the form shows what is actually stored ──────────
$person = hrStaffMember($db, $ref['type'], $ref['id']) ?: $person;
$hr     = $person['hr'] ?: [];
$sal    = $person['salary'] ?: [];
$key    = $person['key'];

$v = static fn(string $f, string $d = '') => $hr[$f] ?? $d;

// ── Attendance summary (last 30 days) ────────────────────────────────────────
$attSummary = [];
try {
    $st = $db->prepare("SELECT status, COUNT(*) n FROM attendance_records
                        WHERE staff_type = ? AND staff_id = ?
                          AND attendance_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                        GROUP BY status");
    $st->execute([$ref['type'], $ref['id']]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $attSummary[$r['status']] = (int)$r['n'];
} catch (\Throwable $_) {}

// ── Leave ────────────────────────────────────────────────────────────────────
$leaveRows = $balance = [];
try {
    $st = $db->prepare("SELECT * FROM leave_requests WHERE staff_type = ? AND staff_id = ?
                        ORDER BY start_date DESC LIMIT 6");
    $st->execute([$ref['type'], $ref['id']]);
    $leaveRows = $st->fetchAll(PDO::FETCH_ASSOC);

    $st = $db->prepare("SELECT * FROM leave_balances WHERE staff_type = ? AND staff_id = ? AND leave_year = ?");
    $st->execute([$ref['type'], $ref['id'], (int)date('Y')]);
    $balance = $st->fetch(PDO::FETCH_ASSOC) ?: [];
} catch (\Throwable $_) {}

// ── Documents ────────────────────────────────────────────────────────────────
$docs = [];
try {
    $st = $db->prepare("SELECT *, DATEDIFF(expiry_date, CURDATE()) AS days_left
                        FROM hr_documents WHERE staff_type = ? AND staff_id = ?
                        ORDER BY COALESCE(expiry_date, '9999-12-31') ASC, id DESC");
    $st->execute([$ref['type'], $ref['id']]);
    $docs = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (\Throwable $_) {}

[$statusLabel, $statusColor] = hrEmploymentBadge($person['emp_status']);
$typeInfo  = hrStaffTypes()[$ref['type']];
$pageTitle = $person['name'];

include __DIR__ . '/../../includes/header.php';
?>
<style>
.ep-head{
    background:var(--surface); border:1px solid var(--border); border-radius:var(--r-lg);
    box-shadow:var(--sh-sm); padding:20px 22px; margin-bottom:16px;
    display:flex; align-items:center; gap:16px; flex-wrap:wrap;
}
.ep-avatar{
    width:66px; height:66px; border-radius:16px; flex:0 0 66px; color:#fff;
    display:flex; align-items:center; justify-content:center; font-size:23px; font-weight:800;
}
.ep-id{ flex:1; min-width:200px; }
.ep-id h1{ font-size:21px; font-weight:800; letter-spacing:-.4px; margin:0 0 3px; color:var(--text); }
.ep-id .role{ font-size:13.5px; color:var(--text-2,#64748b); }
.ep-id .meta{ font-size:12px; color:var(--text-2,#64748b); margin-top:6px; display:flex; gap:14px; flex-wrap:wrap; }
.ep-id .meta i{ margin-right:5px; opacity:.7; }
.ep-tag{ font-size:11px; font-weight:700; padding:3px 11px; border-radius:20px; white-space:nowrap; }

.ep-cols{ display:grid; grid-template-columns:minmax(0,2fr) minmax(0,1fr); gap:16px; align-items:start; }
@media(max-width:992px){ .ep-cols{ grid-template-columns:minmax(0,1fr); } }

.ep-card{ background:var(--surface); border:1px solid var(--border); border-radius:var(--r-lg); box-shadow:var(--sh-sm); margin-bottom:16px; overflow:hidden; }
.ep-card-head{ padding:12px 16px; border-bottom:1px solid var(--border); background:var(--surface-alt);
    display:flex; align-items:center; justify-content:space-between; gap:10px; }
.ep-card-title{ font-size:13.5px; font-weight:700; color:var(--text); margin:0; display:flex; align-items:center; gap:8px; }
.ep-card-title i{ color:var(--brand); }
.ep-card-body{ padding:16px; }
.ep-section{ font-size:11px; font-weight:800; letter-spacing:.7px; text-transform:uppercase;
    color:var(--text-2,#64748b); margin:20px 0 10px; padding-bottom:6px; border-bottom:1px solid var(--border); }
.ep-section:first-child{ margin-top:0; }

.ep-fact{ display:flex; justify-content:space-between; gap:12px; padding:8px 0; border-bottom:1px solid var(--border); font-size:13px; }
.ep-fact:last-child{ border-bottom:0; }
.ep-fact-k{ color:var(--text-2,#64748b); }
.ep-fact-v{ font-weight:600; color:var(--text); text-align:right; }
.ep-fact-v.muted{ font-weight:400; color:var(--text-2,#64748b); font-style:italic; }

.ep-stats{ display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:8px; }
.ep-stat{ background:var(--surface-alt); border:1px solid var(--border); border-radius:var(--r); padding:10px; text-align:center; }
.ep-stat-v{ font-size:18px; font-weight:800; color:var(--text); line-height:1.1; }
.ep-stat-l{ font-size:10px; text-transform:uppercase; letter-spacing:.5px; color:var(--text-2,#64748b); margin-top:4px; }

.ep-row{ display:flex; justify-content:space-between; align-items:center; gap:10px; padding:9px 0; border-bottom:1px solid var(--border); font-size:12.5px; }
.ep-row:last-child{ border-bottom:0; }
.ep-empty{ text-align:center; padding:20px 8px; color:var(--text-2,#64748b); font-size:12.5px; }
.ep-empty i{ font-size:24px; opacity:.3; display:block; margin-bottom:7px; }
.form-label{ font-size:12px; font-weight:600; color:var(--text); margin-bottom:4px; }
</style>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <a href="<?= BASE_URL ?>/modules/hr/employees.php" class="btn btn-sm btn-outline-secondary">
        <i class="fa fa-arrow-left me-1"></i>All employees
    </a>
    <div class="d-flex gap-2">
        <a href="<?= BASE_URL ?>/modules/hr/documents.php?staff=<?= e($key) ?>" class="btn btn-sm btn-outline-primary">
            <i class="fa fa-folder-open me-1"></i>Documents
        </a>
        <?php if (canAccess('payroll')): ?>
        <a href="<?= BASE_URL ?>/modules/payroll/staff.php" class="btn btn-sm btn-outline-primary">
            <i class="fa fa-wallet me-1"></i>Salary profile
        </a>
        <?php endif; ?>
    </div>
</div>

<div class="ep-head">
    <div class="ep-avatar" style="background:<?= hrAvatarColor($key) ?>"><?= e(hrInitials($person['name'])) ?></div>
    <div class="ep-id">
        <h1><?= e($person['name']) ?></h1>
        <div class="role"><?= e($v('job_title') ?: ucwords(str_replace('_', ' ', (string)$person['source_role'])) ?: $typeInfo['label']) ?></div>
        <div class="meta">
            <span><i class="fa <?= $typeInfo['icon'] ?>"></i><?= e($typeInfo['label']) ?></span>
            <?php if ($v('employee_no')): ?><span><i class="fa fa-hashtag"></i><?= e($v('employee_no')) ?></span><?php endif; ?>
            <?php if ($person['phone']): ?><span><i class="fa fa-phone"></i><?= e($person['phone']) ?></span><?php endif; ?>
            <?php if ($person['email']): ?><span><i class="fa fa-envelope"></i><?= e($person['email']) ?></span><?php endif; ?>
        </div>
    </div>
    <span class="ep-tag" style="background:<?= $statusColor ?>1f;color:<?= $statusColor ?>"><?= $statusLabel ?></span>
</div>

<?php if ($errors): ?>
<div class="alert alert-danger py-2">
    <ul class="mb-0 small ps-3"><?php foreach ($errors as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul>
</div>
<?php endif; ?>

<?php if (!$person['has_hr_record']): ?>
<div class="alert alert-warning py-2 small">
    <i class="fa fa-triangle-exclamation me-1"></i>
    This person has no employment record yet.
    <?= $canEdit ? 'Fill in the details below and save — they will then be included in contract and compliance tracking.'
                 : 'An HR user needs to complete it before they appear in contract and compliance tracking.' ?>
</div>
<?php endif; ?>

<div class="ep-cols">
    <!-- ── Left: the employment file ──────────────────────────────────────── -->
    <div>
        <div class="ep-card">
            <div class="ep-card-head">
                <h2 class="ep-card-title"><i class="fa fa-id-badge"></i>Employment Record</h2>
                <?php if (!$canEdit): ?><span class="small text-muted">Read only</span><?php endif; ?>
            </div>
            <div class="ep-card-body">
            <?php if ($canEdit): ?>
                <form method="POST">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="save">

                    <div class="ep-section">Position</div>
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label">Employee number</label>
                            <input type="text" name="employee_no" class="form-control form-control-sm"
                                   value="<?= e($v('employee_no')) ?>" placeholder="e.g. MSC-014">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Job title</label>
                            <input type="text" name="job_title" class="form-control form-control-sm"
                                   value="<?= e($v('job_title')) ?>" placeholder="e.g. Sales Executive">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Department</label>
                            <select name="department" class="form-select form-select-sm">
                                <option value="">— not set —</option>
                                <?php foreach (hrDepartments() as $d): ?>
                                <option value="<?= e($d) ?>" <?= $v('department') === $d ? 'selected' : '' ?>><?= e($d) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="ep-section">Contract</div>
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label">Contract type</label>
                            <select name="contract_type" class="form-select form-select-sm">
                                <?php foreach (hrContractTypes() as $k => $l): ?>
                                <option value="<?= $k ?>" <?= ($v('contract_type','permanent') === $k) ? 'selected' : '' ?>><?= $l ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Employment status</label>
                            <select name="employment_status" class="form-select form-select-sm">
                                <?php foreach (['active'=>'Active','probation'=>'Probation','suspended'=>'Suspended','exited'=>'Exited'] as $k => $l): ?>
                                <option value="<?= $k ?>" <?= ($v('employment_status','active') === $k) ? 'selected' : '' ?>><?= $l ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Hire date</label>
                            <input type="date" name="hire_date" class="form-control form-control-sm" value="<?= e($v('hire_date')) ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Probation ends</label>
                            <input type="date" name="probation_end" class="form-control form-control-sm" value="<?= e($v('probation_end')) ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Contract ends</label>
                            <input type="date" name="contract_end" class="form-control form-control-sm" value="<?= e($v('contract_end')) ?>">
                            <div class="form-text" style="font-size:11px">Leave blank for permanent staff.</div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Exit date</label>
                            <input type="date" name="exit_date" class="form-control form-control-sm" value="<?= e($v('exit_date')) ?>">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Reason for leaving</label>
                            <input type="text" name="exit_reason" class="form-control form-control-sm"
                                   value="<?= e($v('exit_reason')) ?>" placeholder="Only needed when the employee has exited">
                        </div>
                    </div>

                    <div class="ep-section">Statutory &amp; Payment</div>
                    <div class="row g-3">
                        <div class="col-md-3">
                            <label class="form-label">National ID</label>
                            <input type="text" name="national_id" class="form-control form-control-sm" value="<?= e($v('national_id')) ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">KRA PIN</label>
                            <input type="text" name="kra_pin" class="form-control form-control-sm" value="<?= e($v('kra_pin')) ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">NSSF number</label>
                            <input type="text" name="nssf_no" class="form-control form-control-sm" value="<?= e($v('nssf_no')) ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">NHIF / SHIF number</label>
                            <input type="text" name="nhif_no" class="form-control form-control-sm" value="<?= e($v('nhif_no')) ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Bank</label>
                            <input type="text" name="bank_name" class="form-control form-control-sm" value="<?= e($v('bank_name')) ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Account number</label>
                            <input type="text" name="bank_account" class="form-control form-control-sm" value="<?= e($v('bank_account')) ?>">
                        </div>
                    </div>

                    <div class="ep-section">Next of Kin</div>
                    <div class="row g-3">
                        <div class="col-md-5">
                            <label class="form-label">Full name</label>
                            <input type="text" name="next_of_kin" class="form-control form-control-sm" value="<?= e($v('next_of_kin')) ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Phone</label>
                            <input type="text" name="next_of_kin_phone" class="form-control form-control-sm" value="<?= e($v('next_of_kin_phone')) ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Relationship</label>
                            <input type="text" name="next_of_kin_relation" class="form-control form-control-sm"
                                   value="<?= e($v('next_of_kin_relation')) ?>" placeholder="e.g. Spouse">
                        </div>
                        <div class="col-12">
                            <label class="form-label">HR notes</label>
                            <textarea name="notes" rows="3" class="form-control form-control-sm"><?= e($v('notes')) ?></textarea>
                        </div>
                    </div>

                    <div class="d-flex gap-2 mt-4">
                        <button class="btn btn-primary btn-sm"><i class="fa fa-floppy-disk me-1"></i>Save record</button>
                        <a href="<?= BASE_URL ?>/modules/hr/employees.php" class="btn btn-outline-secondary btn-sm">Cancel</a>
                    </div>
                </form>
            <?php else: ?>
                <?php
                $view = [
                    'Position' => [
                        'Employee number' => $v('employee_no'),
                        'Job title'       => $v('job_title'),
                        'Department'      => $v('department'),
                    ],
                    'Contract' => [
                        'Contract type' => hrContractTypes()[$v('contract_type','permanent')] ?? '',
                        'Hire date'     => $v('hire_date')     ? date('j M Y', strtotime($v('hire_date')))     : '',
                        'Probation ends'=> $v('probation_end') ? date('j M Y', strtotime($v('probation_end'))) : '',
                        'Contract ends' => $v('contract_end')  ? date('j M Y', strtotime($v('contract_end')))  : '',
                        'Exit date'     => $v('exit_date')     ? date('j M Y', strtotime($v('exit_date')))     : '',
                    ],
                    'Statutory & Payment' => [
                        'National ID'  => $v('national_id'),
                        'KRA PIN'      => $v('kra_pin'),
                        'NSSF number'  => $v('nssf_no'),
                        'NHIF / SHIF'  => $v('nhif_no'),
                        'Bank'         => trim($v('bank_name') . ' ' . $v('bank_account')),
                    ],
                    'Next of Kin' => [
                        'Name'         => $v('next_of_kin'),
                        'Phone'        => $v('next_of_kin_phone'),
                        'Relationship' => $v('next_of_kin_relation'),
                    ],
                ];
                foreach ($view as $sec => $facts): ?>
                    <div class="ep-section"><?= e($sec) ?></div>
                    <?php foreach ($facts as $k => $val): ?>
                    <div class="ep-fact">
                        <span class="ep-fact-k"><?= e($k) ?></span>
                        <span class="ep-fact-v <?= $val ? '' : 'muted' ?>"><?= e($val ?: 'Not set') ?></span>
                    </div>
                    <?php endforeach; ?>
                <?php endforeach; ?>
            <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- ── Right: activity ─────────────────────────────────────────────────── -->
    <div>
        <div class="ep-card">
            <div class="ep-card-head">
                <h2 class="ep-card-title"><i class="fa fa-calendar-check"></i>Attendance</h2>
                <span class="small text-muted">Last 30 days</span>
            </div>
            <div class="ep-card-body">
                <?php if (!$attSummary): ?>
                    <div class="ep-empty"><i class="fa fa-clipboard-user"></i>No attendance recorded.</div>
                <?php else: ?>
                <div class="ep-stats">
                    <?php foreach ([['present','Present','#16a34a'],['late','Late','#f59e0b'],
                                    ['leave','Leave','#6366f1'],['absent','Absent','#dc2626']] as [$k,$l,$c]): ?>
                    <div class="ep-stat">
                        <div class="ep-stat-v" style="color:<?= $c ?>"><?= (int)($attSummary[$k] ?? 0) ?></div>
                        <div class="ep-stat-l"><?= $l ?></div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="ep-card">
            <div class="ep-card-head">
                <h2 class="ep-card-title"><i class="fa fa-plane-departure"></i>Leave</h2>
                <a href="<?= BASE_URL ?>/modules/hr/leave.php" class="btn btn-xs btn-outline-primary">Manage</a>
            </div>
            <div class="ep-card-body">
                <?php if ($balance): ?>
                <div class="ep-fact">
                    <span class="ep-fact-k">Annual remaining (<?= date('Y') ?>)</span>
                    <span class="ep-fact-v">
                        <?= rtrim(rtrim(number_format((float)$balance['annual_days'] - (float)$balance['taken_annual'], 1), '0'), '.') ?>
                        of <?= rtrim(rtrim(number_format((float)$balance['annual_days'], 1), '0'), '.') ?> days
                    </span>
                </div>
                <div class="ep-fact">
                    <span class="ep-fact-k">Sick remaining</span>
                    <span class="ep-fact-v">
                        <?= rtrim(rtrim(number_format((float)$balance['sick_days'] - (float)$balance['taken_sick'], 1), '0'), '.') ?>
                        of <?= rtrim(rtrim(number_format((float)$balance['sick_days'], 1), '0'), '.') ?> days
                    </span>
                </div>
                <?php endif; ?>

                <?php if (!$leaveRows): ?>
                    <div class="ep-empty"><i class="fa fa-calendar-xmark"></i>No leave requests.</div>
                <?php else: foreach ($leaveRows as $lv):
                    $lc = ['pending'=>'#f59e0b','approved'=>'#16a34a','rejected'=>'#dc2626'][$lv['status']] ?? '#64748b'; ?>
                <div class="ep-row">
                    <div>
                        <div style="font-weight:600;color:var(--text)"><?= e(ucfirst($lv['leave_type'])) ?></div>
                        <div class="text-muted" style="font-size:11.5px">
                            <?= date('j M', strtotime($lv['start_date'])) ?>–<?= date('j M Y', strtotime($lv['end_date'])) ?>
                        </div>
                    </div>
                    <span class="ep-tag" style="background:<?= $lc ?>1f;color:<?= $lc ?>"><?= ucfirst($lv['status']) ?></span>
                </div>
                <?php endforeach; endif; ?>
            </div>
        </div>

        <?php if (canAccess('payroll')): ?>
        <div class="ep-card">
            <div class="ep-card-head">
                <h2 class="ep-card-title"><i class="fa fa-wallet"></i>Salary</h2>
                <a href="<?= BASE_URL ?>/modules/payroll/staff.php" class="btn btn-xs btn-outline-primary">Edit</a>
            </div>
            <div class="ep-card-body">
                <?php if (!$sal): ?>
                    <div class="ep-empty"><i class="fa fa-money-bill"></i>
                        No salary profile.<br>
                        <span style="font-size:11.5px">They will be skipped by payroll until one is set.</span>
                    </div>
                <?php else: ?>
                    <div class="ep-fact"><span class="ep-fact-k">Basic</span>
                        <span class="ep-fact-v"><?= money((float)$sal['basic_salary']) ?></span></div>
                    <div class="ep-fact"><span class="ep-fact-k">House allowance</span>
                        <span class="ep-fact-v"><?= money((float)$sal['house_allowance']) ?></span></div>
                    <div class="ep-fact"><span class="ep-fact-k">Transport</span>
                        <span class="ep-fact-v"><?= money((float)$sal['transport_allow']) ?></span></div>
                    <div class="ep-fact"><span class="ep-fact-k"><strong>Gross monthly</strong></span>
                        <span class="ep-fact-v text-success"><strong><?= money($person['gross']) ?></strong></span></div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <div class="ep-card">
            <div class="ep-card-head">
                <h2 class="ep-card-title"><i class="fa fa-folder-open"></i>Documents</h2>
                <a href="<?= BASE_URL ?>/modules/hr/documents.php?staff=<?= e($key) ?>" class="btn btn-xs btn-outline-primary">
                    <?= $canEdit ? 'Add' : 'View' ?>
                </a>
            </div>
            <div class="ep-card-body">
                <?php if (!$docs): ?>
                    <div class="ep-empty"><i class="fa fa-file-circle-plus"></i>No documents on file.</div>
                <?php else: foreach (array_slice($docs, 0, 6) as $d):
                    $dl = $d['expiry_date'] !== null ? (int)$d['days_left'] : null;
                    $dc = $dl === null ? '#64748b' : ($dl < 0 ? '#dc2626' : ($dl <= 30 ? '#f59e0b' : '#16a34a')); ?>
                <div class="ep-row">
                    <div style="min-width:0">
                        <div style="font-weight:600;color:var(--text);white-space:nowrap;overflow:hidden;text-overflow:ellipsis">
                            <?= e($d['title']) ?>
                        </div>
                        <div class="text-muted" style="font-size:11.5px">
                            <?= e(hrDocumentTypes()[$d['doc_type']] ?? 'Document') ?>
                        </div>
                    </div>
                    <span class="ep-tag" style="background:<?= $dc ?>1f;color:<?= $dc ?>">
                        <?= $dl === null ? 'No expiry' : ($dl < 0 ? 'Expired' : $dl . 'd') ?>
                    </span>
                </div>
                <?php endforeach; endif; ?>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>

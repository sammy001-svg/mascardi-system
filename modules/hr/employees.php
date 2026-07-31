<?php
/**
 * HR — Employee Directory
 *
 * One list covering all three staff sources (office users, mechanics, drivers).
 * The people themselves stay owned by their source table; this page owns the
 * employment record attached to them.
 */
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/_bootstrap.php';
requireLogin();
canAccess('hr') || redirect(BASE_URL . '/index.php');

$db = getDB();
hrMigrate($db);

$pageTitle = 'Employees';
$canEdit   = canWrite('hr');

$fType   = $_GET['type']       ?? '';
$fDept   = $_GET['department'] ?? '';
$fSearch = trim($_GET['q']     ?? '');
$fStatus = $_GET['status']     ?? '';
$onlyMissing = !empty($_GET['missing']);
if (!isset(hrStaffTypes()[$fType])) $fType = '';
if (!in_array($fStatus, ['active','probation','suspended','exited'], true)) $fStatus = '';

$staff = hrStaffDirectory($db, [
    'type'           => $fType,
    'department'     => $fDept,
    'search'         => $fSearch,
    // "Exited" is only reachable by asking for it explicitly, so leavers don't
    // pad the headcount on the default view.
    'include_exited' => ($fStatus === 'exited'),
]);

if ($fStatus)     $staff = array_filter($staff, fn($s) => $s['emp_status'] === $fStatus);
if ($onlyMissing) $staff = array_filter($staff, fn($s) => !$s['has_hr_record']);

// Counts for the filter chips come from the unfiltered set, otherwise the chips
// would only ever show the numbers of whatever is already selected.
$all = hrStaffDirectory($db, ['include_exited' => true]);
$cnt = ['all' => 0, 'user' => 0, 'mechanic' => 0, 'driver' => 0, 'missing' => 0, 'exited' => 0];
$departments = [];
foreach ($all as $s) {
    if ($s['emp_status'] === 'exited') { $cnt['exited']++; continue; }
    $cnt['all']++;
    $cnt[$s['staff_type']]++;
    if (!$s['has_hr_record']) $cnt['missing']++;
    if ($s['department']) $departments[$s['department']] = true;
}
$departments = array_keys($departments);
sort($departments);

$isFiltered = $fType || $fDept || $fSearch !== '' || $fStatus || $onlyMissing;

include __DIR__ . '/../../includes/header.php';
?>
<style>
.emp-head{ display:flex; justify-content:space-between; align-items:center; gap:14px; flex-wrap:wrap; margin-bottom:16px; }
.emp-head h1{ font-size:21px; font-weight:800; letter-spacing:-.4px; margin:0; color:var(--text); }
.emp-head p{ font-size:13px; color:var(--text-2,#64748b); margin:2px 0 0; }

.emp-filters{
    background:var(--surface); border:1px solid var(--border); border-radius:var(--r-lg);
    padding:14px 16px; margin-bottom:16px; box-shadow:var(--sh-sm);
}
.emp-chips{ display:flex; gap:8px; flex-wrap:wrap; margin-bottom:12px; }
.emp-chip{
    display:inline-flex; align-items:center; gap:6px; padding:6px 13px; border-radius:20px;
    font-size:12.5px; font-weight:600; text-decoration:none; border:1px solid var(--border);
    background:var(--surface-alt); color:var(--text); transition:.12s;
}
.emp-chip:hover{ border-color:var(--brand); color:var(--brand); }
.emp-chip.on{ background:var(--brand); border-color:var(--brand); color:#fff; }
.emp-chip .n{ font-size:11px; opacity:.75; font-weight:700; }
.emp-chip.on .n{ opacity:.9; }

.emp-grid{ display:grid; grid-template-columns:repeat(auto-fill,minmax(280px,1fr)); gap:14px; }
.emp-card{
    background:var(--surface); border:1px solid var(--border); border-radius:var(--r-lg);
    box-shadow:var(--sh-sm); overflow:hidden; text-decoration:none; color:var(--text);
    display:flex; flex-direction:column; transition:transform .12s ease, box-shadow .12s ease, border-color .12s ease;
}
.emp-card:hover{ transform:translateY(-2px); box-shadow:var(--sh); border-color:var(--brand); color:var(--text); }
.emp-card-top{ display:flex; gap:12px; padding:15px 16px 12px; align-items:center; }
.emp-avatar{
    width:46px; height:46px; border-radius:12px; flex:0 0 46px; color:#fff;
    display:flex; align-items:center; justify-content:center; font-size:16px; font-weight:800;
}
.emp-id{ min-width:0; flex:1; }
.emp-name{ font-size:14.5px; font-weight:700; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.emp-role{ font-size:12px; color:var(--text-2,#64748b); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.emp-meta{
    padding:10px 16px; border-top:1px solid var(--border); background:var(--surface-alt);
    display:flex; justify-content:space-between; align-items:center; gap:8px; margin-top:auto;
}
.emp-tag{ font-size:10.5px; font-weight:700; padding:2px 9px; border-radius:20px; white-space:nowrap; }
.emp-facts{ padding:0 16px 12px; font-size:12px; color:var(--text-2,#64748b); display:grid; gap:3px; }
.emp-facts i{ width:14px; text-align:center; margin-right:5px; opacity:.7; }
.emp-incomplete{ border-style:dashed; border-color:#f59e0b; }
.emp-blank{
    background:var(--surface); border:1px solid var(--border); border-radius:var(--r-lg);
    padding:44px 20px; text-align:center; color:var(--text-2,#64748b);
}
.emp-blank i{ font-size:34px; opacity:.3; display:block; margin-bottom:12px; }
</style>

<div class="emp-head">
    <div>
        <h1><i class="fa fa-users me-2" style="color:var(--brand)"></i>Employees</h1>
        <p>Everyone on the payroll — office staff, mechanics and drivers in one register.</p>
    </div>
    <a href="<?= BASE_URL ?>/modules/hr/index.php" class="btn btn-sm btn-outline-secondary">
        <i class="fa fa-arrow-left me-1"></i>HR Dashboard
    </a>
</div>

<div class="emp-filters">
    <div class="emp-chips">
        <?php
        $chips = [
            ['label' => 'Everyone',   'q' => [],                        'n' => $cnt['all'],      'on' => !$fType && !$fStatus && !$onlyMissing],
            ['label' => 'Office',     'q' => ['type' => 'user'],        'n' => $cnt['user'],     'on' => $fType === 'user'],
            ['label' => 'Mechanics',  'q' => ['type' => 'mechanic'],    'n' => $cnt['mechanic'], 'on' => $fType === 'mechanic'],
            ['label' => 'Drivers',    'q' => ['type' => 'driver'],      'n' => $cnt['driver'],   'on' => $fType === 'driver'],
            ['label' => 'Incomplete', 'q' => ['missing' => 1],          'n' => $cnt['missing'],  'on' => $onlyMissing],
            ['label' => 'Exited',     'q' => ['status' => 'exited'],    'n' => $cnt['exited'],   'on' => $fStatus === 'exited'],
        ];
        foreach ($chips as $c):
            // Keep the text search when switching chips — losing it on every
            // click makes narrowing down a name needlessly fiddly.
            if ($fSearch !== '') $c['q']['q'] = $fSearch;
        ?>
        <a class="emp-chip <?= $c['on'] ? 'on' : '' ?>"
           href="?<?= http_build_query($c['q']) ?>"><?= $c['label'] ?> <span class="n"><?= $c['n'] ?></span></a>
        <?php endforeach; ?>
    </div>

    <form method="GET" class="row g-2 align-items-center">
        <?php if ($fType):      ?><input type="hidden" name="type"    value="<?= e($fType) ?>"><?php endif; ?>
        <?php if ($fStatus):    ?><input type="hidden" name="status"  value="<?= e($fStatus) ?>"><?php endif; ?>
        <?php if ($onlyMissing): ?><input type="hidden" name="missing" value="1"><?php endif; ?>
        <div class="col-sm-5">
            <input type="text" name="q" value="<?= e($fSearch) ?>" class="form-control form-control-sm"
                   placeholder="Search name, phone, employee number, job title…">
        </div>
        <div class="col-sm-4">
            <select name="department" class="form-select form-select-sm">
                <option value="">All departments</option>
                <?php foreach ($departments as $d): ?>
                <option value="<?= e($d) ?>" <?= $fDept === $d ? 'selected' : '' ?>><?= e($d) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-sm-3 d-flex gap-2">
            <button class="btn btn-sm btn-primary flex-fill"><i class="fa fa-magnifying-glass me-1"></i>Filter</button>
            <?php if ($isFiltered): ?>
            <a href="?" class="btn btn-sm btn-outline-secondary" title="Clear"><i class="fa fa-xmark"></i></a>
            <?php endif; ?>
        </div>
    </form>
</div>

<?php if ($isFiltered): ?>
<p class="small text-muted mb-2">
    Showing <strong><?= count($staff) ?></strong> of <?= $cnt['all'] ?> employees.
</p>
<?php endif; ?>

<?php if (!$staff): ?>
    <div class="emp-blank">
        <i class="fa fa-user-slash"></i>
        <?php if ($isFiltered): ?>
            No employees match these filters.<br><a href="?">Clear them</a>
        <?php else: ?>
            No staff on record.<br>
            <span class="small">People appear here automatically once they exist as a system user, mechanic or driver.</span>
        <?php endif; ?>
    </div>
<?php else: ?>
<div class="emp-grid">
    <?php foreach ($staff as $s):
        [$sl, $sc] = hrEmploymentBadge($s['emp_status']);
        $typeInfo  = hrStaffTypes()[$s['staff_type']];
    ?>
    <a class="emp-card <?= $s['has_hr_record'] ? '' : 'emp-incomplete' ?>"
       href="<?= BASE_URL ?>/modules/hr/employee.php?staff=<?= e($s['key']) ?>">
        <div class="emp-card-top">
            <div class="emp-avatar" style="background:<?= hrAvatarColor($s['key']) ?>">
                <?= e(hrInitials($s['name'])) ?>
            </div>
            <div class="emp-id">
                <div class="emp-name"><?= e($s['name']) ?></div>
                <div class="emp-role">
                    <?= e($s['job_title'] ?: ucwords(str_replace('_', ' ', (string)$s['source_role'])) ?: $typeInfo['label']) ?>
                </div>
            </div>
        </div>

        <div class="emp-facts">
            <div><i class="fa <?= $typeInfo['icon'] ?>"></i><?= e($typeInfo['label']) ?>
                <?= $s['employee_no'] ? ' &middot; ' . e($s['employee_no']) : '' ?></div>
            <div><i class="fa fa-building"></i><?= e($s['department'] ?: 'No department set') ?></div>
            <?php if ($s['phone']): ?>
            <div><i class="fa fa-phone"></i><?= e($s['phone']) ?></div>
            <?php endif; ?>
            <?php if ($s['hire_date']): ?>
            <div><i class="fa fa-calendar-day"></i>Joined <?= date('M Y', strtotime($s['hire_date'])) ?></div>
            <?php endif; ?>
        </div>

        <div class="emp-meta">
            <span class="emp-tag" style="background:<?= $sc ?>1f;color:<?= $sc ?>"><?= $sl ?></span>
            <?php if (!$s['has_hr_record']): ?>
                <span class="emp-tag" style="background:#f59e0b1f;color:#b45309">
                    <i class="fa fa-triangle-exclamation me-1"></i>Record incomplete
                </span>
            <?php elseif ($s['gross'] > 0 && canAccess('payroll')): ?>
                <span class="small text-muted"><?= money($s['gross']) ?>/mo</span>
            <?php else: ?>
                <span class="small text-muted"><?= e(hrContractTypes()[$s['contract_type']] ?? '') ?></span>
            <?php endif; ?>
        </div>
    </a>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php include __DIR__ . '/../../includes/footer.php'; ?>

<?php
require_once __DIR__ . '/../../includes/functions.php';
requireRole('admin');
$id = (int)($_GET['id'] ?? 0);
if (!$id) redirect(BASE_URL . '/modules/users/index.php');
$db = getDB();
$stmt = $db->prepare("SELECT * FROM users WHERE id=?"); $stmt->execute([$id]); $user = $stmt->fetch();
if (!$user) { setFlash('error', 'User not found.'); redirect(BASE_URL . '/modules/users/index.php'); }

$pageTitle = 'Edit User';
$errors = [];
$isSelf = ($id === authUser()['id']);

$freeMechanics = $db->query("SELECT m.id, m.name FROM mechanics m WHERE m.status='active' AND (NOT EXISTS (SELECT 1 FROM users u WHERE u.linked_type='mechanic' AND u.linked_id=m.id) OR m.id=" . (int)($user['linked_id'] ?? 0) . ") ORDER BY m.name")->fetchAll();

// Module groups definition
$moduleGroups = [
    'Fleet' => [
        ['key' => 'cars',               'label' => 'All Cars',            'icon' => 'fa-car'],
        ['key' => 'mechanics',          'label' => 'Mechanics',           'icon' => 'fa-screwdriver-wrench'],
        ['key' => 'drivers',            'label' => 'Drivers',             'icon' => 'fa-id-card'],
        ['key' => 'car_documents',      'label' => 'Car Documents',       'icon' => 'fa-folder-open'],
        ['key' => 'car_costs',          'label' => 'Import Costs',        'icon' => 'fa-calculator'],
        ['key' => 'inspections',        'label' => 'Inspections',         'icon' => 'fa-clipboard-list'],
    ],
    'Logistics' => [
        ['key' => 'intake',             'label' => 'Mombasa Intake',      'icon' => 'fa-anchor'],
        ['key' => 'assessments',        'label' => 'Assessments',         'icon' => 'fa-clipboard-check'],
        ['key' => 'quick_assessments',  'label' => 'Quick Assessment',    'icon' => 'fa-magnifying-glass-chart'],
    ],
    'Workshop' => [
        ['key' => 'jobs',               'label' => 'Job Cards',           'icon' => 'fa-toolbox'],
        ['key' => 'lpo',                'label' => 'LPO',                 'icon' => 'fa-file-import'],
        ['key' => 'parts_requests',     'label' => 'Quote Requests',      'icon' => 'fa-file-invoice'],
        ['key' => 'issues',             'label' => 'Issues',              'icon' => 'fa-triangle-exclamation'],
    ],
    'Inventory' => [
        ['key' => 'inventory',          'label' => 'Parts Stock',         'icon' => 'fa-boxes-stacked'],
        ['key' => 'suppliers',          'label' => 'Suppliers',           'icon' => 'fa-truck'],
    ],
    'Clients & CRM' => [
        ['key' => 'clients',            'label' => 'Clients',             'icon' => 'fa-users'],
        ['key' => 'service_bookings',   'label' => 'Service Bookings',    'icon' => 'fa-calendar-check'],
        ['key' => 'crm',                'label' => 'CRM / Sales Pipeline','icon' => 'fa-filter'],
    ],
    'Financial' => [
        ['key' => 'payments',           'label' => 'Payments',            'icon' => 'fa-money-bill-transfer'],
        ['key' => 'quotations',         'label' => 'Quotations',          'icon' => 'fa-file-lines'],
        ['key' => 'invoices',           'label' => 'Invoices',            'icon' => 'fa-file-invoice-dollar'],
        ['key' => 'sales',              'label' => 'Sales',               'icon' => 'fa-tag'],
        ['key' => 'installments',       'label' => 'Payment Plans',       'icon' => 'fa-calendar-check'],
        ['key' => 'expenses',           'label' => 'Expenses',            'icon' => 'fa-receipt'],
    ],
    'HR' => [
        ['key' => 'attendance',         'label' => 'Attendance',          'icon' => 'fa-calendar-days'],
        ['key' => 'payroll',            'label' => 'Payroll',             'icon' => 'fa-money-bill-wave'],
    ],
    'Analytics' => [
        ['key' => 'reports',            'label' => 'Reports',             'icon' => 'fa-chart-bar'],
    ],
    'Communication' => [
        ['key' => 'chat',               'label' => 'Internal Chat',       'icon' => 'fa-comments'],
    ],
];
$viewOnlyModules = ['issues', 'reports', 'car_costs'];

$allModuleKeys = [];
foreach ($moduleGroups as $group) {
    foreach ($group as $m) { $allModuleKeys[] = $m['key']; }
}

// Role defaults (mirrors auth.php fallback maps)
$roleAccessDefaults = [
    'workshop_manager'  => ['cars','mechanics','drivers','assessments','jobs','parts_requests','issues','quick_assessments','lpo','inventory','suppliers','car_documents','car_costs','inspections','attendance','payroll','chat','reports'],
    'sales_person'      => ['cars','clients','service_bookings','quick_assessments','quotations','invoices','payments','sales','crm','installments','car_documents','inspections','chat'],
    'sales_officer'     => ['cars','clients','service_bookings','quotations','invoices','payments','quick_assessments','sales','crm','installments','car_costs','car_documents','inspections','chat'],
    'accountant'        => ['payments','invoices','quotations','expenses','reports','clients','sales','installments','car_costs','cars','chat'],
    'hr_manager'        => ['attendance','payroll','mechanics','drivers','expenses','reports','chat'],
    'inventory_manager' => ['inventory','suppliers','lpo','parts_requests','cars','issues','car_documents','chat'],
    'receptionist'      => ['clients','service_bookings','quick_assessments','cars','chat'],
    'mechanic'          => ['jobs','assessments','parts_requests','issues','car_documents','inspections','chat'],
    'driver'            => ['cars','assessments'],
];
$roleWriteDefaults = [
    'workshop_manager'  => ['cars','jobs','assessments','mechanics','drivers','parts_requests','issues','quick_assessments','lpo','car_documents','inspections','attendance','payroll'],
    'sales_person'      => ['service_bookings','quick_assessments','clients','payments','sales','crm','installments'],
    'sales_officer'     => ['payments','quotations','invoices','clients','service_bookings','quick_assessments','sales','crm','installments'],
    'accountant'        => ['payments','invoices','quotations','expenses','sales','installments'],
    'hr_manager'        => ['attendance','payroll'],
    'inventory_manager' => ['inventory','suppliers','lpo','parts_requests'],
    'receptionist'      => ['clients','service_bookings','quick_assessments'],
    'mechanic'          => ['assessments','parts_requests'],
    'driver'            => [],
];

// Load current saved permissions for this user
$permStmt = $db->prepare("SELECT module, can_access, can_write FROM user_permissions WHERE user_id=?");
$permStmt->execute([$id]);
$savedPerms = [];
$hasCustomPerms = false;
foreach ($permStmt->fetchAll() as $row) {
    $savedPerms[$row['module']] = [(bool)$row['can_access'], (bool)$row['can_write']];
    $hasCustomPerms = true;
}

// If no saved perms, build display state from role defaults
if (!$hasCustomPerms && $user['role'] !== 'admin') {
    foreach ($roleAccessDefaults[$user['role']] ?? [] as $m) { $savedPerms[$m][0] = true; }
    foreach ($roleWriteDefaults[$user['role']]  ?? [] as $m) { $savedPerms[$m][1] = true; }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name       = trim($_POST['name']     ?? '');
    $username   = trim($_POST['username'] ?? '');
    $email      = trim($_POST['email']    ?? '');
    $role       = $isSelf ? 'admin' : ($_POST['role'] ?? $user['role']);
    $pass       = $_POST['password']         ?? '';
    $pass2      = $_POST['password_confirm'] ?? '';
    $linkedType = $_POST['linked_type'] ?? '';
    $linkedId   = (int)($_POST['linked_id'] ?? 0);
    $status     = $isSelf ? 'active' : ($_POST['status'] ?? 'active');

    if (!$name)                $errors[] = 'Full name is required.';
    if (!$username)            $errors[] = 'Username is required.';
    if ($pass !== '' && strlen($pass) < 6) $errors[] = 'Password must be at least 6 characters.';
    if ($pass !== '' && $pass !== $pass2)  $errors[] = 'Passwords do not match.';

    if (empty($errors)) {
        try {
            $lt = ($linkedType && $linkedId) ? $linkedType : null;
            $li = ($linkedType && $linkedId) ? $linkedId   : null;

            if ($pass !== '') {
                $db->prepare("UPDATE users SET name=?,username=?,email=?,password=?,role=?,linked_id=?,linked_type=?,status=? WHERE id=?")
                   ->execute([$name, $username, $email, password_hash($pass, PASSWORD_DEFAULT), $role, $li, $lt, $status, $id]);
            } else {
                $db->prepare("UPDATE users SET name=?,username=?,email=?,role=?,linked_id=?,linked_type=?,status=? WHERE id=?")
                   ->execute([$name, $username, $email, $role, $li, $lt, $status, $id]);
            }

            // Save permissions
            $db->prepare("DELETE FROM user_permissions WHERE user_id=?")->execute([$id]);
            if ($role !== 'admin') {
                $accessList = $_POST['perm_access'] ?? [];
                $writeList  = $_POST['perm_write']  ?? [];
                $ps = $db->prepare("INSERT INTO user_permissions (user_id, module, can_access, can_write) VALUES (?,?,?,?)");
                foreach ($allModuleKeys as $mod) {
                    $acc = in_array($mod, $accessList) ? 1 : 0;
                    $wrt = in_array($mod, $writeList)  ? 1 : 0;
                    if ($wrt) $acc = 1;
                    $ps->execute([$id, $mod, $acc, $wrt]);
                }
            }

            logActivity('update', 'users', $id, "Updated user: {$name} ({$role})");
            setFlash('success', 'User updated successfully.');
            redirect(BASE_URL . '/modules/users/index.php');
        } catch (PDOException $e) {
            $errors[] = $e->getCode() === '23000' ? 'Username already taken by another user.' : $e->getMessage();
        }
    }
    // Re-build display perms from POST on validation failure
    $savedPerms = [];
    foreach ($_POST['perm_access'] ?? [] as $m) { $savedPerms[$m][0] = true; }
    foreach ($_POST['perm_write']  ?? [] as $m) { $savedPerms[$m][1] = true; }
    $user = array_merge($user, compact('name','username','email','role','status'));
}

include __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="mb-0"><i class="fa fa-user-pen me-2 text-primary"></i>Edit User: <?= e($user['name']) ?></h5>
    <a href="index.php" class="btn btn-sm btn-outline-secondary"><i class="fa fa-arrow-left me-1"></i>Back</a>
</div>

<?php if ($errors): ?>
<div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $err) echo '<li>' . e($err) . '</li>'; ?></ul></div>
<?php endif; ?>

<form method="POST" autocomplete="off" id="userForm">

<!-- Basic Info -->
<div class="card mb-4">
    <div class="card-header fw-semibold"><i class="fa fa-id-card me-2 text-primary"></i>Basic Information</div>
    <div class="card-body">
        <div class="row g-3">
            <div class="col-md-6">
                <label class="form-label">Full Name <span class="text-danger">*</span></label>
                <input type="text" name="name" class="form-control" value="<?= e($user['name']) ?>" required>
            </div>
            <div class="col-md-3">
                <label class="form-label">Username <span class="text-danger">*</span></label>
                <input type="text" name="username" class="form-control" value="<?= e($user['username']) ?>" required>
            </div>
            <div class="col-md-3">
                <label class="form-label">Status</label>
                <select name="status" class="form-select" <?= $isSelf ? 'disabled' : '' ?>>
                    <option value="active"   <?= $user['status'] === 'active'   ? 'selected' : '' ?>>Active</option>
                    <option value="inactive" <?= $user['status'] === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                </select>
                <?php if ($isSelf): ?><input type="hidden" name="status" value="active"><?php endif; ?>
            </div>
            <div class="col-md-6">
                <label class="form-label">Email</label>
                <input type="email" name="email" class="form-control" value="<?= e($user['email'] ?? '') ?>">
            </div>
        </div>
    </div>
</div>

<!-- Role & Password -->
<div class="card mb-4">
    <div class="card-header fw-semibold"><i class="fa fa-shield-halved me-2 text-primary"></i>Role &amp; Security</div>
    <div class="card-body">
        <div class="row g-3">
            <div class="col-md-6">
                <label class="form-label">System Role <span class="text-danger">*</span></label>
                <select name="role" id="roleSelect" class="form-select" <?= $isSelf ? 'disabled' : '' ?>>
                    <optgroup label="Administration">
                        <option value="admin"             <?= $user['role']==='admin'             ?'selected':'' ?>>Admin — Full unrestricted access</option>
                    </optgroup>
                    <optgroup label="Operations">
                        <option value="workshop_manager"  <?= $user['role']==='workshop_manager'  ?'selected':'' ?>>Workshop Manager</option>
                        <option value="mechanic"          <?= $user['role']==='mechanic'          ?'selected':'' ?>>Mechanic</option>
                        <option value="driver"            <?= $user['role']==='driver'            ?'selected':'' ?>>Driver</option>
                        <option value="inventory_manager" <?= $user['role']==='inventory_manager' ?'selected':'' ?>>Inventory Manager</option>
                    </optgroup>
                    <optgroup label="Sales & Client Relations">
                        <option value="sales_person"      <?= $user['role']==='sales_person'      ?'selected':'' ?>>Sales Person</option>
                        <option value="sales_officer"     <?= $user['role']==='sales_officer'     ?'selected':'' ?>>Sales Officer</option>
                        <option value="receptionist"      <?= $user['role']==='receptionist'      ?'selected':'' ?>>Receptionist / Front Desk</option>
                    </optgroup>
                    <optgroup label="Finance & HR">
                        <option value="accountant"        <?= $user['role']==='accountant'        ?'selected':'' ?>>Accountant</option>
                        <option value="hr_manager"        <?= $user['role']==='hr_manager'        ?'selected':'' ?>>HR Manager</option>
                    </optgroup>
                    <?php if (in_array($user['role'],['manager'])): ?>
                    <optgroup label="Legacy">
                        <option value="manager"           <?= $user['role']==='manager'           ?'selected':'' ?>>Manager (legacy)</option>
                    </optgroup>
                    <?php endif; ?>
                </select>
                <?php if ($isSelf): ?><input type="hidden" name="role" value="admin"><?php endif; ?>
                <div class="form-text" id="roleDesc"></div>
            </div>
            <div class="col-md-6">
                <label class="form-label">Link to Mechanic Profile <span class="text-muted">(optional)</span></label>
                <select name="linked_id" class="form-select">
                    <option value="">— None —</option>
                    <?php foreach ($freeMechanics as $m): ?>
                    <option value="<?= $m['id'] ?>" <?= $user['linked_id'] == $m['id'] ? 'selected' : '' ?>><?= e($m['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <input type="hidden" name="linked_type" value="mechanic">
            </div>
            <div class="col-md-4">
                <label class="form-label">New Password <span class="text-muted">(leave blank to keep)</span></label>
                <input type="password" name="password" class="form-control" placeholder="Min 6 characters" autocomplete="new-password">
            </div>
            <div class="col-md-4">
                <label class="form-label">Confirm New Password</label>
                <input type="password" name="password_confirm" class="form-control" autocomplete="new-password">
            </div>
        </div>
    </div>
</div>

<!-- Permissions Checklist -->
<div class="card mb-4" id="permissionsCard">
    <div class="card-header d-flex justify-content-between align-items-center">
        <div>
            <span class="fw-semibold"><i class="fa fa-list-check me-2 text-primary"></i>Module Permissions</span>
            <?php if ($hasCustomPerms): ?>
            <span class="badge bg-success ms-2">Custom</span>
            <?php else: ?>
            <span class="badge bg-secondary ms-2">Role defaults</span>
            <?php endif; ?>
        </div>
        <div class="d-flex gap-2">
            <button type="button" class="btn btn-xs btn-outline-secondary" onclick="resetToRole()"><i class="fa fa-rotate me-1"></i>Reset to Role Defaults</button>
            <button type="button" class="btn btn-xs btn-outline-success" onclick="setAllPerms(true)"><i class="fa fa-check-double me-1"></i>Grant All</button>
            <button type="button" class="btn btn-xs btn-outline-danger"  onclick="setAllPerms(false)"><i class="fa fa-ban me-1"></i>Clear All</button>
        </div>
    </div>
    <div class="card-body p-0">
        <div class="alert alert-info rounded-0 mb-0 py-2 px-4 border-0 border-bottom small">
            <i class="fa fa-info-circle me-1"></i>
            Changes take effect at the user's next page load. <strong>Delete</strong> is always Admin-only regardless of these settings.
        </div>
        <div class="table-responsive">
            <table class="table table-hover mb-0" style="font-size:13.5px">
                <thead class="table-dark">
                    <tr>
                        <th class="ps-4" style="width:240px">Module</th>
                        <th class="text-center" style="width:150px">
                            <i class="fa fa-eye me-1"></i>View / Access
                        </th>
                        <th class="text-center" style="width:150px">
                            <i class="fa fa-pen me-1"></i>Create / Edit
                        </th>
                        <th class="text-muted" style="font-size:11px;font-weight:400">Description</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($moduleGroups as $sectionName => $modules): ?>
                    <tr class="table-light">
                        <td colspan="4" class="ps-4 py-2">
                            <span class="fw-bold text-uppercase small" style="letter-spacing:.06em;color:#475569"><?= $sectionName ?></span>
                        </td>
                    </tr>
                    <?php foreach ($modules as $m):
                        $isViewOnly = in_array($m['key'], $viewOnlyModules);
                        $accOn = $savedPerms[$m['key']][0] ?? false;
                        $wrtOn = !$isViewOnly && ($savedPerms[$m['key']][1] ?? false);
                    ?>
                    <tr>
                        <td class="ps-4">
                            <i class="fa <?= $m['icon'] ?> me-2 text-primary" style="width:16px;opacity:.7"></i>
                            <?= e($m['label']) ?>
                        </td>
                        <td class="text-center">
                            <input type="checkbox"
                                   name="perm_access[]"
                                   value="<?= $m['key'] ?>"
                                   class="form-check-input perm-access"
                                   data-module="<?= $m['key'] ?>"
                                   <?= $accOn ? 'checked' : '' ?>>
                        </td>
                        <td class="text-center">
                            <?php if ($isViewOnly): ?>
                            <span class="badge bg-light text-muted border" style="font-size:10px">View only</span>
                            <?php else: ?>
                            <input type="checkbox"
                                   name="perm_write[]"
                                   value="<?= $m['key'] ?>"
                                   class="form-check-input perm-write"
                                   data-module="<?= $m['key'] ?>"
                                   <?= $wrtOn ? 'checked' : '' ?>>
                            <?php endif; ?>
                        </td>
                        <td class="text-muted small" style="font-size:11.5px"><?= permDesc($m['key']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endforeach; ?>

                    <!-- Admin-only section -->
                    <tr class="table-light">
                        <td colspan="4" class="ps-4 py-2">
                            <span class="fw-bold text-uppercase small" style="letter-spacing:.06em;color:#475569">Admin</span>
                            <span class="badge bg-danger ms-2" style="font-size:10px">Admin-only modules</span>
                        </td>
                    </tr>
                    <?php foreach ([['icon'=>'fa-users-gear','label'=>'Users & Roles'],['icon'=>'fa-gear','label'=>'System Settings'],['icon'=>'fa-history','label'=>'Audit Logs'],['icon'=>'fa-location-dot','label'=>'Locations']] as $am): ?>
                    <tr style="opacity:.55">
                        <td class="ps-4">
                            <i class="fa <?= $am['icon'] ?> me-2 text-secondary" style="width:16px"></i>
                            <?= $am['label'] ?>
                        </td>
                        <td class="text-center"><i class="fa fa-lock text-danger"></i></td>
                        <td class="text-center"><i class="fa fa-lock text-danger"></i></td>
                        <td class="text-muted small" style="font-size:11.5px">Restricted to Admin role</td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="d-flex gap-2 mb-4">
    <button type="submit" class="btn btn-primary"><i class="fa fa-save me-1"></i>Save Changes</button>
    <a href="index.php" class="btn btn-outline-secondary">Cancel</a>
</div>

</form>

<!-- API Token Card -->
<div class="card mt-2 mb-4">
    <div class="card-header d-flex align-items-center gap-2">
        <i class="fa fa-key text-warning"></i>
        <span>REST API Token</span>
        <?php if ($user['api_token'] ?? null): ?>
        <span class="badge bg-success ms-auto">Token Active</span>
        <?php else: ?>
        <span class="badge bg-secondary ms-auto">No Token</span>
        <?php endif; ?>
    </div>
    <div class="card-body">
        <?php $showToken = $_SESSION['show_token_' . $id] ?? null; unset($_SESSION['show_token_' . $id]); ?>
        <?php if ($showToken): ?>
        <div class="alert alert-warning">
            <i class="fa fa-exclamation-triangle me-2"></i><strong>Copy this token now — it will not be shown again:</strong>
            <div class="mt-2 font-monospace bg-dark text-white p-2 rounded" style="word-break:break-all;font-size:13px"><?= e($showToken) ?></div>
        </div>
        <?php endif; ?>
        <p class="text-muted small mb-3">API tokens allow this user to authenticate to the REST API. Send as <code>Authorization: Bearer &lt;token&gt;</code>.</p>
        <div class="d-flex gap-2">
            <form method="POST" action="generate_token.php">
                <input type="hidden" name="user_id" value="<?= $id ?>">
                <input type="hidden" name="action" value="generate">
                <button type="submit" class="btn btn-warning" onclick="return confirm('Generate a new API token? The old token will be invalidated immediately.')">
                    <i class="fa fa-rotate me-1"></i><?= ($user['api_token'] ?? null) ? 'Regenerate Token' : 'Generate Token' ?>
                </button>
            </form>
            <?php if ($user['api_token'] ?? null): ?>
            <form method="POST" action="generate_token.php">
                <input type="hidden" name="user_id" value="<?= $id ?>">
                <input type="hidden" name="action" value="revoke">
                <button type="submit" class="btn btn-outline-danger" onclick="return confirm('Revoke this token?')">
                    <i class="fa fa-ban me-1"></i>Revoke Token
                </button>
            </form>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php
function permDesc(string $key): string {
    $d = [
        'cars'             => 'View and manage vehicle records',
        'mechanics'        => 'View and manage mechanic profiles',
        'intake'           => 'Record and confirm Mombasa port arrivals',
        'assessments'      => 'Conduct and view vehicle assessments',
        'jobs'             => 'Create and manage workshop job cards',
        'lpo'              => 'Issue and manage Local Purchase Orders',
        'parts_requests'   => 'Submit and manage quote requests for parts',
        'issues'           => 'View outstanding vehicle issues (read-only)',
        'inventory'        => 'View and adjust parts stock levels',
        'suppliers'        => 'Manage supplier contacts',
        'clients'          => 'View and manage client accounts',
        'service_bookings' => 'Book and manage client service appointments',
        'quick_assessments'=> 'Perform quick walk-in vehicle assessments',
        'payments'         => 'Record and confirm client payments',
        'quotations'       => 'Create and send price quotations',
        'invoices'         => 'Create and manage tax invoices',
        'reports'          => 'View system reports and charts (read-only)',
    ];
    return $d[$key] ?? '';
}
?>

<script>
(function () {
    var roleDesc = {
        admin:             'Full unrestricted access. Permissions below do not apply to Admin.',
        workshop_manager:  'Manages the full workshop — jobs, mechanics, assessments, inventory, attendance and payroll.',
        sales_person:      'Handles client interactions, bookings, quick assessments and the sales pipeline.',
        sales_officer:     'Manages invoices, quotations, payment collection and the sales pipeline.',
        accountant:        'Full financial access — invoices, payments, expenses, reports and cost analysis.',
        hr_manager:        'Manages staff attendance and processes monthly payroll.',
        inventory_manager: 'Manages parts stock, supplier orders and purchase requisitions.',
        receptionist:      'Front desk — client check-ins, service bookings and quick assessments.',
        mechanic:          'Accesses assigned job cards and performs vehicle assessments.',
        driver:            'Field staff — limited access to car records and pre-departure assessments.',
        manager:           'Legacy broad-access role. Consider migrating to a specific role.',
    };

    var roleDefaults = {
        workshop_manager: {
            access: ['cars','mechanics','drivers','assessments','jobs','parts_requests','issues','quick_assessments','lpo','inventory','suppliers','car_documents','car_costs','inspections','attendance','payroll','chat','reports'],
            write:  ['cars','jobs','assessments','mechanics','drivers','parts_requests','issues','quick_assessments','lpo','car_documents','inspections','attendance','payroll']
        },
        sales_person: {
            access: ['cars','clients','service_bookings','quick_assessments','quotations','invoices','payments','sales','crm','installments','car_documents','inspections','chat'],
            write:  ['service_bookings','quick_assessments','clients','payments','sales','crm','installments']
        },
        sales_officer: {
            access: ['cars','clients','service_bookings','quotations','invoices','payments','quick_assessments','sales','crm','installments','car_costs','car_documents','inspections','chat'],
            write:  ['payments','quotations','invoices','clients','service_bookings','quick_assessments','sales','crm','installments']
        },
        accountant: {
            access: ['payments','invoices','quotations','expenses','reports','clients','sales','installments','car_costs','cars','chat'],
            write:  ['payments','invoices','quotations','expenses','sales','installments']
        },
        hr_manager: {
            access: ['attendance','payroll','mechanics','drivers','expenses','reports','chat'],
            write:  ['attendance','payroll']
        },
        inventory_manager: {
            access: ['inventory','suppliers','lpo','parts_requests','cars','issues','car_documents','chat'],
            write:  ['inventory','suppliers','lpo','parts_requests']
        },
        receptionist: {
            access: ['clients','service_bookings','quick_assessments','cars','chat'],
            write:  ['clients','service_bookings','quick_assessments']
        },
        mechanic: {
            access: ['jobs','assessments','parts_requests','issues','car_documents','inspections','chat'],
            write:  ['assessments','parts_requests']
        },
        driver: {
            access: ['cars','assessments'],
            write:  []
        },
        manager: {
            access: ['cars','mechanics','drivers','intake','assessments','jobs','quotations','invoices','lpo','inventory','suppliers','reports','parts_requests','clients','service_bookings','issues','chat','car_documents','crm','car_costs','installments','expenses','inspections','attendance','payroll','quick_assessments','sales'],
            write:  ['cars','jobs','assessments','mechanics','inventory','parts_requests','intake','issues','lpo','quotations','invoices','clients','service_bookings','car_documents','car_costs','installments','expenses','inspections','attendance','payroll','quick_assessments','sales','crm']
        }
    };

    var roleSelect = document.getElementById('roleSelect');
    var permCard   = document.getElementById('permissionsCard');
    var descEl     = document.getElementById('roleDesc');

    function syncAdminState(role) {
        descEl.textContent = roleDesc[role] || '';
        if (role === 'admin') {
            permCard.style.opacity = '.45';
            permCard.style.pointerEvents = 'none';
            descEl.className = 'form-text text-primary fw-semibold';
        } else {
            permCard.style.opacity = '';
            permCard.style.pointerEvents = '';
            descEl.className = 'form-text text-muted';
        }
    }

    function applyPreset(role) {
        var preset = roleDefaults[role] || { access: [], write: [] };
        document.querySelectorAll('.perm-access, .perm-write').forEach(function(cb) { cb.checked = false; });
        preset.access.forEach(function(mod) {
            var cb = document.querySelector('.perm-access[data-module="' + mod + '"]');
            if (cb) cb.checked = true;
        });
        preset.write.forEach(function(mod) {
            var wcb = document.querySelector('.perm-write[data-module="' + mod + '"]');
            if (wcb) wcb.checked = true;
            var acb = document.querySelector('.perm-access[data-module="' + mod + '"]');
            if (acb) acb.checked = true;
        });
    }

    // Enforce: write requires access
    document.querySelectorAll('.perm-write').forEach(function(cb) {
        cb.addEventListener('change', function() {
            if (this.checked) {
                var acc = document.querySelector('.perm-access[data-module="' + this.dataset.module + '"]');
                if (acc) acc.checked = true;
            }
        });
    });

    // Enforce: removing access also removes write
    document.querySelectorAll('.perm-access').forEach(function(cb) {
        cb.addEventListener('change', function() {
            if (!this.checked) {
                var wrt = document.querySelector('.perm-write[data-module="' + this.dataset.module + '"]');
                if (wrt) wrt.checked = false;
            }
        });
    });

    roleSelect.addEventListener('change', function() {
        syncAdminState(this.value);
        if (this.value !== 'admin') {
            if (confirm('Reset permissions to the default template for "' + this.options[this.selectedIndex].text.split('—')[0].trim() + '"?')) {
                applyPreset(this.value);
            }
        }
    });

    // Init
    syncAdminState(roleSelect.value);

    // Global helpers
    window.setAllPerms = function(state) {
        document.querySelectorAll('.perm-access, .perm-write').forEach(function(cb) { cb.checked = state; });
    };
    window.resetToRole = function() {
        var role = roleSelect.value;
        if (role === 'admin') return;
        if (confirm('Reset all permissions to the default template for this role?')) {
            applyPreset(role);
        }
    };
}());
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>

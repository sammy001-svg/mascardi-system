<?php
require_once __DIR__ . '/../../includes/functions.php';
requireRole('admin');
$pageTitle = 'Add User';
$db = getDB();
try { $db->exec("ALTER TABLE users ADD COLUMN location_id INT NULL DEFAULT NULL"); } catch (\Throwable $_) {}
try { $db->exec("ALTER TABLE users ADD COLUMN profile_image VARCHAR(255) NULL DEFAULT NULL"); } catch (\Throwable $_) {}
$errors = [];

$freeMechanics = $db->query("SELECT m.id, m.name FROM mechanics m WHERE m.status='active' AND NOT EXISTS (SELECT 1 FROM users u WHERE u.linked_type='mechanic' AND u.linked_id=m.id) ORDER BY m.name")->fetchAll();
$locations = $db->query("SELECT id, name FROM locations WHERE status='active' ORDER BY name ASC")->fetchAll();

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

$validRoles = [
    'admin','general_manager',
    'supervisor',
    'finance_manager','accountant','cashier',
    'sales_manager','sales_officer','sales_person','customer_relations','receptionist',
    'workshop_manager','mechanic','driver',
    'inventory_manager','procurement_officer',
    'hr_manager',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name       = trim($_POST['name']     ?? '');
    $username   = trim($_POST['username'] ?? '');
    $email      = trim($_POST['email']    ?? '');
    $role       = $_POST['role']   ?? 'mechanic';
    $pass       = $_POST['password']         ?? '';
    $pass2      = $_POST['password_confirm'] ?? '';
    $linkedType = $_POST['linked_type'] ?? '';
    $linkedId   = (int)($_POST['linked_id'] ?? 0);
    $status     = $_POST['status'] ?? 'active';
    $locationId = ($role === 'supervisor') ? (int)($_POST['location_id'] ?? 0) : null;

    if (!$name)                $errors[] = 'Full name is required.';
    if (!$username)            $errors[] = 'Username is required.';
    if (!$pass)                $errors[] = 'Password is required.';
    elseif (strlen($pass) < 6) $errors[] = 'Password must be at least 6 characters.';
    elseif ($pass !== $pass2)  $errors[] = 'Passwords do not match.';
    if (!in_array($role, $validRoles)) {
        $errors[] = 'Invalid role selected.';
    }

    if (empty($errors)) {
        try {
            $lt = ($linkedType && $linkedId) ? $linkedType : null;
            $li = ($linkedType && $linkedId) ? $linkedId   : null;
            $db->prepare("INSERT INTO users (name,username,email,password,role,linked_id,linked_type,status,location_id) VALUES (?,?,?,?,?,?,?,?,?)")
               ->execute([$name, $username, $email, password_hash($pass, PASSWORD_DEFAULT), $role, $li, $lt, $status, $locationId]);
            $newId = (int)$db->lastInsertId();

            if ($role !== 'admin') {
                $accessList = $_POST['perm_access'] ?? [];
                $writeList  = $_POST['perm_write']  ?? [];
                $permStmt   = $db->prepare("INSERT INTO user_permissions (user_id, module, can_access, can_write) VALUES (?,?,?,?)");
                foreach ($allModuleKeys as $mod) {
                    $acc = in_array($mod, $accessList) ? 1 : 0;
                    $wrt = in_array($mod, $writeList)  ? 1 : 0;
                    if ($wrt) $acc = 1;
                    $permStmt->execute([$newId, $mod, $acc, $wrt]);
                }
            }

            logActivity('create', 'users', $newId, "Created user: {$name} ({$role})");
            setFlash('success', "User {$name} created successfully.");
            redirect(BASE_URL . '/modules/users/index.php');
        } catch (PDOException $e) {
            $errors[] = $e->getCode() === '23000' ? 'Username already exists.' : $e->getMessage();
        }
    }
}

include __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="mb-0"><i class="fa fa-user-plus me-2 text-primary"></i>Add User</h5>
    <a href="index.php" class="btn btn-sm btn-outline-secondary"><i class="fa fa-arrow-left me-1"></i>Back</a>
</div>

<?php if ($errors): ?>
<div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $err) echo '<li>' . e($err) . '</li>'; ?></ul></div>
<?php endif; ?>

<form method="POST" id="userForm" autocomplete="off">

<!-- Basic Info -->
<div class="card mb-4">
    <div class="card-header fw-semibold"><i class="fa fa-id-card me-2 text-primary"></i>Basic Information</div>
    <div class="card-body">
        <div class="row g-3">
            <div class="col-md-6">
                <label class="form-label">Full Name <span class="text-danger">*</span></label>
                <input type="text" name="name" class="form-control" value="<?= e($_POST['name'] ?? '') ?>" required>
            </div>
            <div class="col-md-3">
                <label class="form-label">Username <span class="text-danger">*</span></label>
                <input type="text" name="username" class="form-control" value="<?= e($_POST['username'] ?? '') ?>" required autocomplete="off">
            </div>
            <div class="col-md-3">
                <label class="form-label">Status</label>
                <select name="status" class="form-select">
                    <option value="active"   <?= ($_POST['status'] ?? 'active') === 'active'   ? 'selected' : '' ?>>Active</option>
                    <option value="inactive" <?= ($_POST['status'] ?? '') === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                </select>
            </div>
            <div class="col-md-6">
                <label class="form-label">Email</label>
                <input type="email" name="email" class="form-control" value="<?= e($_POST['email'] ?? '') ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label">Password <span class="text-danger">*</span></label>
                <input type="password" name="password" class="form-control" autocomplete="new-password">
            </div>
            <div class="col-md-3">
                <label class="form-label">Confirm Password <span class="text-danger">*</span></label>
                <input type="password" name="password_confirm" class="form-control" autocomplete="new-password">
            </div>
        </div>
    </div>
</div>

<!-- Role & Link -->
<div class="card mb-4">
    <div class="card-header fw-semibold"><i class="fa fa-shield-halved me-2 text-primary"></i>Role &amp; Security</div>
    <div class="card-body">
        <div class="row g-3 align-items-end">
            <div class="col-md-6">
                <label class="form-label">System Role <span class="text-danger">*</span></label>
                <select name="role" id="roleSelect" class="form-select">
                    <optgroup label="Administration">
                        <option value="admin"              <?= ($_POST['role'] ?? '') === 'admin'              ? 'selected' : '' ?>>Admin — Full unrestricted access</option>
                        <option value="general_manager"    <?= ($_POST['role'] ?? '') === 'general_manager'    ? 'selected' : '' ?>>General Manager</option>
                        <option value="supervisor"         <?= ($_POST['role'] ?? '') === 'supervisor'         ? 'selected' : '' ?>>Supervisor (Location-scoped)</option>
                    </optgroup>
                    <optgroup label="Finance">
                        <option value="finance_manager"    <?= ($_POST['role'] ?? '') === 'finance_manager'    ? 'selected' : '' ?>>Finance Manager</option>
                        <option value="accountant"         <?= ($_POST['role'] ?? '') === 'accountant'         ? 'selected' : '' ?>>Accountant</option>
                        <option value="cashier"            <?= ($_POST['role'] ?? '') === 'cashier'            ? 'selected' : '' ?>>Cashier</option>
                    </optgroup>
                    <optgroup label="Sales &amp; Client Relations">
                        <option value="sales_manager"      <?= ($_POST['role'] ?? '') === 'sales_manager'      ? 'selected' : '' ?>>Sales Manager</option>
                        <option value="sales_officer"      <?= ($_POST['role'] ?? '') === 'sales_officer'      ? 'selected' : '' ?>>Sales Officer</option>
                        <option value="sales_person"       <?= ($_POST['role'] ?? '') === 'sales_person'       ? 'selected' : '' ?>>Sales Person</option>
                        <option value="customer_relations" <?= ($_POST['role'] ?? '') === 'customer_relations' ? 'selected' : '' ?>>Customer Relations Officer</option>
                        <option value="receptionist"       <?= ($_POST['role'] ?? '') === 'receptionist'       ? 'selected' : '' ?>>Receptionist / Front Desk</option>
                    </optgroup>
                    <optgroup label="Workshop &amp; Operations">
                        <option value="workshop_manager"   <?= ($_POST['role'] ?? 'workshop_manager') === 'workshop_manager' ? 'selected' : '' ?>>Workshop Manager</option>
                        <option value="mechanic"           <?= ($_POST['role'] ?? '') === 'mechanic'           ? 'selected' : '' ?>>Mechanic / Technician</option>
                        <option value="driver"             <?= ($_POST['role'] ?? '') === 'driver'             ? 'selected' : '' ?>>Driver</option>
                    </optgroup>
                    <optgroup label="Inventory &amp; Procurement">
                        <option value="inventory_manager"  <?= ($_POST['role'] ?? '') === 'inventory_manager'  ? 'selected' : '' ?>>Inventory Manager</option>
                        <option value="procurement_officer"<?= ($_POST['role'] ?? '') === 'procurement_officer'? 'selected' : '' ?>>Procurement Officer</option>
                    </optgroup>
                    <optgroup label="HR">
                        <option value="hr_manager"         <?= ($_POST['role'] ?? '') === 'hr_manager'         ? 'selected' : '' ?>>HR Manager</option>
                    </optgroup>
                </select>
                <div class="form-text" id="roleDesc"></div>
            </div>
            <div class="col-md-6">
                <label class="form-label">Link to Mechanic Profile <span class="text-muted">(optional)</span></label>
                <select name="linked_id" class="form-select">
                    <option value="">— None —</option>
                    <?php foreach ($freeMechanics as $m): ?>
                    <option value="<?= $m['id'] ?>" <?= ($_POST['linked_id'] ?? '') == $m['id'] ? 'selected' : '' ?>><?= e($m['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <input type="hidden" name="linked_type" value="mechanic">
            </div>
        </div>
    </div>
</div>

<!-- Supervisor Location Assignment -->
<div class="card mb-4" id="supervisorLocationCard" style="display:none">
    <div class="card-header fw-semibold" style="background:#ecfeff;border-color:#22d3ee44">
        <i class="fa fa-location-dot me-2" style="color:#0891b2"></i>Supervisor Location Assignment
    </div>
    <div class="card-body">
        <div class="row g-3">
            <div class="col-md-6">
                <label class="form-label fw-semibold">Assigned Location <span class="text-danger">*</span></label>
                <select name="location_id" id="locationIdSelect" class="form-select">
                    <option value="">— Select a location —</option>
                    <?php foreach ($locations as $loc): ?>
                    <option value="<?= $loc['id'] ?>" <?= ($_POST['location_id'] ?? '') == $loc['id'] ? 'selected' : '' ?>><?= e($loc['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <div class="form-text">The supervisor will only see data from this location.</div>
            </div>
        </div>
    </div>
</div>

<!-- Permissions Checklist -->
<div class="card mb-4" id="permissionsCard">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span class="fw-semibold"><i class="fa fa-list-check me-2 text-primary"></i>Module Permissions</span>
        <div class="d-flex gap-2">
            <button type="button" class="btn btn-xs btn-outline-success" onclick="setAllPerms(true)"><i class="fa fa-check-double me-1"></i>Grant All</button>
            <button type="button" class="btn btn-xs btn-outline-danger"  onclick="setAllPerms(false)"><i class="fa fa-ban me-1"></i>Clear All</button>
        </div>
    </div>
    <div class="card-body p-0">
        <div class="alert alert-info rounded-0 mb-0 py-2 px-4 border-0 border-bottom small">
            <i class="fa fa-info-circle me-1"></i>
            Selecting a role above pre-fills the recommended permissions. You can override any module below.
            <strong>Delete</strong> is always Admin-only regardless of these settings.
        </div>
        <div class="table-responsive">
            <table class="table table-hover mb-0" style="font-size:13.5px">
                <thead class="table-dark">
                    <tr>
                        <th class="ps-4" style="width:240px">Module</th>
                        <th class="text-center" style="width:150px"><i class="fa fa-eye me-1"></i>View / Access</th>
                        <th class="text-center" style="width:150px"><i class="fa fa-pen me-1"></i>Create / Edit</th>
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
                        $accChecked = in_array($m['key'], $_POST['perm_access'] ?? []) ? 'checked' : '';
                        $wrtChecked = !$isViewOnly && in_array($m['key'], $_POST['perm_write'] ?? []) ? 'checked' : '';
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
                                   <?= $accChecked ?>>
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
                                   <?= $wrtChecked ?>>
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
                    <?php foreach ([
                        ['icon'=>'fa-users-gear',         'label'=>'Users & Roles'],
                        ['icon'=>'fa-gear',               'label'=>'System Settings'],
                        ['icon'=>'fa-history',            'label'=>'Audit Logs'],
                        ['icon'=>'fa-location-dot',       'label'=>'Locations'],
                    ] as $am): ?>
                    <tr style="opacity:.55">
                        <td class="ps-4">
                            <i class="fa <?= $am['icon'] ?> me-2 text-secondary" style="width:16px"></i>
                            <?= $am['label'] ?>
                        </td>
                        <td class="text-center"><i class="fa fa-lock text-danger" title="Admin only"></i></td>
                        <td class="text-center"><i class="fa fa-lock text-danger" title="Admin only"></i></td>
                        <td class="text-muted small" style="font-size:11.5px">Restricted to Admin role</td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="d-flex gap-2 mb-4">
    <button type="submit" class="btn btn-primary"><i class="fa fa-save me-1"></i>Create User</button>
    <a href="index.php" class="btn btn-outline-secondary">Cancel</a>
</div>

</form>

<?php
function permDesc(string $key): string {
    $d = [
        'cars'             => 'View and manage vehicle records',
        'mechanics'        => 'View and manage mechanic profiles',
        'drivers'          => 'View and manage driver profiles',
        'car_documents'    => 'Manage vehicle documents and paperwork',
        'car_costs'        => 'View vehicle import and running costs (read-only)',
        'inspections'      => 'Conduct and record vehicle inspections',
        'intake'           => 'Record and confirm Mombasa port arrivals',
        'assessments'      => 'Conduct and view vehicle assessments',
        'quick_assessments'=> 'Perform quick walk-in vehicle assessments',
        'jobs'             => 'Create and manage workshop job cards',
        'lpo'              => 'Issue and manage Local Purchase Orders',
        'parts_requests'   => 'Submit and manage quote requests for parts',
        'issues'           => 'View outstanding vehicle issues (read-only)',
        'inventory'        => 'View and adjust parts stock levels',
        'suppliers'        => 'Manage supplier contacts',
        'clients'          => 'View and manage client accounts',
        'service_bookings' => 'Book and manage client service appointments',
        'crm'              => 'Manage the sales pipeline and lead tracking',
        'payments'         => 'Record and confirm client payments',
        'quotations'       => 'Create and send price quotations',
        'invoices'         => 'Create and manage tax invoices',
        'sales'            => 'Record and track vehicle sales',
        'installments'     => 'Manage client payment plans',
        'expenses'         => 'Record and view operational expenses',
        'attendance'       => 'Record and manage staff attendance',
        'payroll'          => 'Process and view staff payroll',
        'reports'          => 'View system reports and analytics (read-only)',
        'chat'             => 'Internal messaging and team communication',
    ];
    return $d[$key] ?? '';
}
?>

<script>
(function () {
    var roleDesc = {
        admin:               'Full unrestricted access to all modules and system settings. Permissions below do not apply.',
        general_manager:     'Cross-departmental oversight — read access to all modules with limited write for approvals.',
        finance_manager:     'Full financial control — payments, invoices, expenses, payroll, LPO and all reporting.',
        accountant:          'Standard accounting access — invoices, payments, quotations, expenses and financial reports.',
        cashier:             'Payment collection focus — records client payments and manages installment plans.',
        sales_manager:       'Leads the sales team — full access to sales pipeline, quotations, invoices and reporting.',
        sales_officer:       'Senior sales — manages invoices, quotations, payment collection and the CRM pipeline.',
        sales_person:        'Front-line sales — client interactions, bookings, quick assessments and the pipeline.',
        customer_relations:  'Client follow-up and retention — CRM pipeline, service bookings and installment tracking.',
        receptionist:        'Front desk — client check-ins, service bookings and quick walk-in assessments.',
        workshop_manager:    'Manages the full workshop — jobs, mechanics, assessments, inventory, attendance and payroll.',
        mechanic:            'Technician access — assigned job cards, vehicle assessments and parts requests.',
        driver:              'Field staff — limited access to car records and pre-departure assessments.',
        inventory_manager:   'Manages parts stock levels, supplier relationships and purchase requisitions.',
        procurement_officer: 'Purchasing specialist — LPO management, supplier orders and inventory replenishment.',
        hr_manager:          'HR administration — staff attendance tracking and monthly payroll processing.',
        supervisor:          'Supervisor — Oversees a specific location: cars, staff, service bookings, quick assessments, quotations, and invoices.',
    };

    var roleDefaults = {
        supervisor: {
            access: ['cars','service_bookings','quick_assessments','quotations','invoices','reports'],
            write:  []
        },
        general_manager: {
            access: ['cars','mechanics','drivers','intake','assessments','jobs','parts_requests','issues',
                     'quick_assessments','lpo','inventory','suppliers','car_documents','car_costs',
                     'inspections','attendance','payroll','chat','reports','clients','service_bookings',
                     'crm','payments','invoices','quotations','sales','installments','expenses'],
            write:  ['quotations','invoices','sales']
        },
        finance_manager: {
            access: ['payments','invoices','quotations','expenses','reports','clients','sales',
                     'installments','car_costs','cars','chat','lpo','payroll','attendance',
                     'inventory','suppliers','parts_requests'],
            write:  ['payments','invoices','quotations','expenses','sales','installments','payroll','lpo']
        },
        accountant: {
            access: ['payments','invoices','quotations','expenses','reports','clients','sales',
                     'installments','car_costs','cars','chat'],
            write:  ['payments','invoices','quotations','expenses','sales','installments']
        },
        cashier: {
            access: ['payments','invoices','installments','clients','chat','sales'],
            write:  ['payments','installments']
        },
        sales_manager: {
            access: ['cars','clients','service_bookings','quotations','invoices','payments',
                     'quick_assessments','sales','crm','installments','car_costs','car_documents',
                     'inspections','chat','reports','expenses','assessments'],
            write:  ['payments','quotations','invoices','clients','service_bookings',
                     'quick_assessments','sales','crm','installments','expenses']
        },
        sales_officer: {
            access: ['cars','clients','service_bookings','quotations','invoices','payments',
                     'quick_assessments','sales','crm','installments','car_costs','car_documents',
                     'inspections','chat'],
            write:  ['payments','quotations','invoices','clients','service_bookings',
                     'quick_assessments','sales','crm','installments']
        },
        sales_person: {
            access: ['cars','clients','service_bookings','quick_assessments','quotations','invoices',
                     'payments','sales','crm','installments','car_documents','inspections','chat'],
            write:  ['service_bookings','quick_assessments','clients','payments','sales','crm','installments']
        },
        customer_relations: {
            access: ['clients','service_bookings','crm','quick_assessments','cars','chat',
                     'inspections','installments','quotations','invoices'],
            write:  ['clients','service_bookings','crm','quick_assessments','installments']
        },
        receptionist: {
            access: ['clients','service_bookings','quick_assessments','cars','chat'],
            write:  ['clients','service_bookings','quick_assessments']
        },
        workshop_manager: {
            access: ['cars','mechanics','drivers','assessments','jobs','parts_requests','issues',
                     'quick_assessments','lpo','inventory','suppliers','car_documents','car_costs',
                     'inspections','attendance','payroll','chat','reports'],
            write:  ['cars','jobs','assessments','mechanics','drivers','parts_requests','issues',
                     'quick_assessments','lpo','car_documents','inspections','attendance','payroll']
        },
        mechanic: {
            access: ['jobs','assessments','parts_requests','issues','car_documents','inspections','chat'],
            write:  ['assessments','parts_requests']
        },
        driver: {
            access: ['cars','assessments'],
            write:  []
        },
        inventory_manager: {
            access: ['inventory','suppliers','lpo','parts_requests','cars','issues','car_documents','chat'],
            write:  ['inventory','suppliers','lpo','parts_requests']
        },
        procurement_officer: {
            access: ['inventory','suppliers','lpo','parts_requests','cars','issues','car_documents','chat','reports'],
            write:  ['lpo','suppliers','inventory','parts_requests']
        },
        hr_manager: {
            access: ['attendance','payroll','mechanics','drivers','expenses','reports','chat'],
            write:  ['attendance','payroll']
        },
    };

    var roleSelect = document.getElementById('roleSelect');
    var permCard   = document.getElementById('permissionsCard');
    var descEl     = document.getElementById('roleDesc');

    function applyRole(role) {
        descEl.textContent = roleDesc[role] || '';
        
        // Show/hide supervisor location selection
        var locCard = document.getElementById('supervisorLocationCard');
        var locSelect = document.getElementById('locationIdSelect');
        if (locCard && locSelect) {
            if (role === 'supervisor') {
                locCard.style.display = 'block';
                locSelect.required = true;
            } else {
                locCard.style.display = 'none';
                locSelect.required = false;
                locSelect.value = '';
            }
        }

        if (role === 'admin') {
            permCard.style.opacity = '.45';
            permCard.style.pointerEvents = 'none';
            descEl.className = 'form-text text-primary fw-semibold';
            return;
        }
        permCard.style.opacity = '';
        permCard.style.pointerEvents = '';
        descEl.className = 'form-text text-muted';

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

    // write requires access
    document.querySelectorAll('.perm-write').forEach(function(cb) {
        cb.addEventListener('change', function() {
            if (this.checked) {
                var acc = document.querySelector('.perm-access[data-module="' + this.dataset.module + '"]');
                if (acc) acc.checked = true;
            }
        });
    });

    // removing access also removes write
    document.querySelectorAll('.perm-access').forEach(function(cb) {
        cb.addEventListener('change', function() {
            if (!this.checked) {
                var wrt = document.querySelector('.perm-write[data-module="' + this.dataset.module + '"]');
                if (wrt) wrt.checked = false;
            }
        });
    });

    roleSelect.addEventListener('change', function() { applyRole(this.value); });

    applyRole(roleSelect.value);

    window.setAllPerms = function(state) {
        document.querySelectorAll('.perm-access, .perm-write').forEach(function(cb) { cb.checked = state; });
    };
}());
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>

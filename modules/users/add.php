<?php
require_once __DIR__ . '/../../includes/functions.php';
requireRole('admin');
$pageTitle = 'Add User';
$db = getDB();
$errors = [];

$freeMechanics = $db->query("SELECT m.id, m.name FROM mechanics m WHERE m.status='active' AND NOT EXISTS (SELECT 1 FROM users u WHERE u.linked_type='mechanic' AND u.linked_id=m.id) ORDER BY m.name")->fetchAll();

// Module groups definition (shared by form + save logic)
$moduleGroups = [
    'Fleet' => [
        ['key' => 'cars',               'label' => 'All Cars',            'icon' => 'fa-car'],
        ['key' => 'mechanics',          'label' => 'Mechanics',           'icon' => 'fa-screwdriver-wrench'],
    ],
    'Logistics' => [
        ['key' => 'intake',             'label' => 'Mombasa Intake',      'icon' => 'fa-anchor'],
        ['key' => 'assessments',        'label' => 'Assessments',         'icon' => 'fa-clipboard-check'],
    ],
    'Workshop' => [
        ['key' => 'jobs',               'label' => 'Job Cards',           'icon' => 'fa-toolbox'],
        ['key' => 'lpo',                'label' => 'LPO',                 'icon' => 'fa-file-import'],
        ['key' => 'parts_requests',     'label' => 'Part Requests',       'icon' => 'fa-hand-holding-box'],
        ['key' => 'issues',             'label' => 'Issues',              'icon' => 'fa-triangle-exclamation'],
    ],
    'Inventory' => [
        ['key' => 'inventory',          'label' => 'Parts Stock',         'icon' => 'fa-boxes-stacked'],
        ['key' => 'suppliers',          'label' => 'Suppliers',           'icon' => 'fa-truck'],
    ],
    'Clients' => [
        ['key' => 'clients',            'label' => 'Clients',             'icon' => 'fa-users'],
        ['key' => 'service_bookings',   'label' => 'Service Bookings',    'icon' => 'fa-calendar-check'],
        ['key' => 'quick_assessments',  'label' => 'Quick Assessment',    'icon' => 'fa-magnifying-glass-chart'],
    ],
    'Financial' => [
        ['key' => 'payments',           'label' => 'Payments',            'icon' => 'fa-money-bill-transfer'],
        ['key' => 'quotations',         'label' => 'Quotations',          'icon' => 'fa-file-lines'],
        ['key' => 'invoices',           'label' => 'Invoices',            'icon' => 'fa-file-invoice-dollar'],
    ],
    'Analytics' => [
        ['key' => 'reports',            'label' => 'Reports',             'icon' => 'fa-chart-bar'],
    ],
];

// Modules that are view-only (no write operations)
$viewOnlyModules = ['issues', 'reports'];

// Flatten all module keys for save loop
$allModuleKeys = [];
foreach ($moduleGroups as $group) {
    foreach ($group as $m) { $allModuleKeys[] = $m['key']; }
}

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

    if (!$name)                $errors[] = 'Full name is required.';
    if (!$username)            $errors[] = 'Username is required.';
    if (!$pass)                $errors[] = 'Password is required.';
    elseif (strlen($pass) < 6) $errors[] = 'Password must be at least 6 characters.';
    elseif ($pass !== $pass2)  $errors[] = 'Passwords do not match.';
    if (!in_array($role, ['admin','workshop_manager','sales_person','sales_officer','mechanic','driver'])) {
        $errors[] = 'Invalid role selected.';
    }

    if (empty($errors)) {
        try {
            $lt = ($linkedType && $linkedId) ? $linkedType : null;
            $li = ($linkedType && $linkedId) ? $linkedId   : null;
            $db->prepare("INSERT INTO users (name,username,email,password,role,linked_id,linked_type,status) VALUES (?,?,?,?,?,?,?,?)")
               ->execute([$name, $username, $email, password_hash($pass, PASSWORD_DEFAULT), $role, $li, $lt, $status]);
            $newId = (int)$db->lastInsertId();

            // Save permissions (skip for admin — always has full access)
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

<form method="POST" id="userForm">

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

<!-- Role -->
<div class="card mb-4">
    <div class="card-header fw-semibold"><i class="fa fa-shield-halved me-2 text-primary"></i>Role</div>
    <div class="card-body">
        <div class="row g-3 align-items-end">
            <div class="col-md-6">
                <label class="form-label">System Role <span class="text-danger">*</span></label>
                <select name="role" id="roleSelect" class="form-select">
                    <option value="admin"            <?= ($_POST['role'] ?? '') === 'admin'            ? 'selected' : '' ?>>Admin — Full unrestricted access</option>
                    <option value="workshop_manager" <?= ($_POST['role'] ?? 'workshop_manager') === 'workshop_manager' ? 'selected' : '' ?>>Workshop Manager</option>
                    <option value="sales_person"     <?= ($_POST['role'] ?? '') === 'sales_person'     ? 'selected' : '' ?>>Sales Person</option>
                    <option value="sales_officer"    <?= ($_POST['role'] ?? '') === 'sales_officer'    ? 'selected' : '' ?>>Sales Officer</option>
                    <option value="mechanic"         <?= ($_POST['role'] ?? '') === 'mechanic'         ? 'selected' : '' ?>>Mechanic</option>
                    <option value="driver"           <?= ($_POST['role'] ?? '') === 'driver'           ? 'selected' : '' ?>>Driver</option>
                </select>
                <div class="form-text" id="roleDesc"></div>
            </div>
            <div class="col-md-6">
                <!-- Link mechanic profile -->
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
                    <?php foreach ([['icon'=>'fa-users-gear','label'=>'Users & Roles'],['icon'=>'fa-gear','label'=>'System Settings'],['icon'=>'fa-history','label'=>'Audit Logs'],['icon'=>'fa-location-dot','label'=>'Locations']] as $am): ?>
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
        'intake'           => 'Record and confirm Mombasa port arrivals',
        'assessments'      => 'Conduct and view vehicle assessments',
        'jobs'             => 'Create and manage workshop job cards',
        'lpo'              => 'Issue and manage Local Purchase Orders',
        'parts_requests'   => 'Request parts from inventory',
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
        admin:            'Full unrestricted access to all modules. Permissions below do not apply.',
        workshop_manager: 'Manages workshop operations — jobs, assessments, parts requests.',
        sales_person:     'Handles bookings, quick assessments, and basic client interactions.',
        sales_officer:    'Manages invoices, quotations, and payment collection.',
        mechanic:         'Accesses assigned jobs and performs assessments.',
        driver:           'Custom — configure permissions below based on operational needs.',
    };

    var roleDefaults = {
        workshop_manager: {
            access: ['cars','mechanics','assessments','jobs','parts_requests','issues','quick_assessments'],
            write:  ['jobs','assessments','mechanics','parts_requests','issues','quick_assessments']
        },
        sales_person: {
            access: ['cars','clients','service_bookings','quick_assessments','quotations','invoices','payments'],
            write:  ['service_bookings','quick_assessments','clients','payments']
        },
        sales_officer: {
            access: ['cars','clients','service_bookings','quotations','invoices','payments','quick_assessments'],
            write:  ['payments','quotations','invoices','clients','service_bookings','quick_assessments']
        },
        mechanic: {
            access: ['jobs','assessments','parts_requests','issues'],
            write:  ['assessments','parts_requests']
        },
        driver: {
            access: [],
            write:  []
        }
    };

    var roleSelect   = document.getElementById('roleSelect');
    var permCard     = document.getElementById('permissionsCard');
    var descEl       = document.getElementById('roleDesc');

    function applyRole(role) {
        descEl.textContent = roleDesc[role] || '';
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
        // Reset all
        document.querySelectorAll('.perm-access, .perm-write').forEach(function(cb) { cb.checked = false; });
        // Apply access
        preset.access.forEach(function(mod) {
            var cb = document.querySelector('.perm-access[data-module="' + mod + '"]');
            if (cb) cb.checked = true;
        });
        // Apply write (write also checks access)
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

    roleSelect.addEventListener('change', function() { applyRole(this.value); });

    // Init on page load
    applyRole(roleSelect.value);

    // Global helpers
    window.setAllPerms = function(state) {
        document.querySelectorAll('.perm-access, .perm-write').forEach(function(cb) { cb.checked = state; });
    };
}());
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>

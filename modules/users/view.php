<?php
require_once __DIR__ . '/../../includes/functions.php';
requireRole('admin');
$db = getDB();

$id = (int)($_GET['id'] ?? 0);
if (!$id) { setFlash('error', 'Invalid user.'); redirect('index.php'); }

$stmt = $db->prepare("
    SELECT u.*, l.name AS location_name
    FROM users u
    LEFT JOIN locations l ON l.id = u.location_id
    WHERE u.id = ?
");
$stmt->execute([$id]);
$user = $stmt->fetch();
if (!$user) { setFlash('error', 'User not found.'); redirect('index.php'); }

// Load all active locations for assignment dropdown
$locations = $db->query("SELECT id, name, type FROM locations WHERE status='active' ORDER BY name ASC")->fetchAll();

// Load user permissions
$perms = [];
try {
    $ps = $db->prepare("SELECT module, can_access, can_write FROM user_permissions WHERE user_id = ? ORDER BY module ASC");
    $ps->execute([$id]);
    $perms = $ps->fetchAll();
} catch (\Throwable $_) {}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $locationId = (int)($_POST['location_id'] ?? 0) ?: null;
    $db->prepare("UPDATE users SET location_id = ? WHERE id = ?")->execute([$locationId, $id]);
    logActivity('update', 'users', $id, 'Updated location assignment for: ' . $user['name']);
    setFlash('success', 'Location assignment saved for <strong>' . htmlspecialchars($user['name']) . '</strong>.');
    redirect('view.php?id=' . $id);
}

$pageTitle = 'User Account: ' . $user['name'];
include __DIR__ . '/../../includes/header.php';

$roleLabels = [
    'admin'               => ['danger',    'Admin'],
    'super_admin'         => ['warning',   'Super Admin'],
    'general_manager'     => ['dark',      'General Manager'],
    'supervisor'          => ['info',      'Supervisor'],
    'finance_manager'     => ['warning',   'Finance Manager'],
    'accountant'          => ['warning',   'Accountant'],
    'cashier'             => ['warning',   'Cashier'],
    'sales_manager'       => ['success',   'Sales Manager'],
    'sales_officer'       => ['info',      'Sales Officer'],
    'sales_person'        => ['success',   'Sales Person'],
    'customer_relations'  => ['info',      'Customer Relations'],
    'receptionist'        => ['secondary', 'Receptionist'],
    'workshop_manager'    => ['primary',   'Workshop Manager'],
    'mechanic'            => ['secondary', 'Mechanic'],
    'driver'              => ['secondary', 'Driver'],
    'inventory_manager'   => ['primary',   'Inventory Manager'],
    'procurement_officer' => ['primary',   'Procurement Officer'],
    'hr_manager'          => ['dark',      'HR Manager'],
];
[$rc, $rl] = $roleLabels[$user['role']] ?? ['secondary', ucwords(str_replace('_', ' ', $user['role']))];
?>

<div class="mb-4 d-flex align-items-center gap-3">
    <a href="index.php" class="btn btn-xs btn-outline-secondary">
        <i class="fa fa-arrow-left me-1"></i>Back to Users
    </a>
    <h5 class="mb-0"><i class="fa fa-user-circle me-2 text-primary"></i><?= e($user['name']) ?></h5>
    <span class="badge bg-<?= $rc ?>"><?= $rl ?></span>
    <?= statusBadge($user['status']) ?>
</div>

<div class="row g-4">

    <!-- ── Left: Account Details ─────────────────────────── -->
    <div class="col-lg-5">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-header fw-semibold" style="background:#f8fafc;font-size:13px">
                <i class="fa fa-id-card me-2 text-primary"></i>Account Details
            </div>
            <div class="card-body">

                <!-- Avatar -->
                <div class="d-flex align-items-center gap-3 mb-4 pb-3 border-bottom">
                    <?php if (!empty($user['profile_image'])): ?>
                    <img src="<?= BASE_URL ?>/uploads/profiles/<?= e($user['profile_image']) ?>"
                         class="rounded-circle border" style="width:60px;height:60px;object-fit:cover">
                    <?php else: ?>
                    <div class="rounded-circle d-flex align-items-center justify-content-center fw-bold text-white"
                         style="width:60px;height:60px;background:#2563eb;font-size:22px;flex-shrink:0">
                        <?= strtoupper(substr($user['name'], 0, 1)) ?>
                    </div>
                    <?php endif; ?>
                    <div>
                        <div class="fw-bold" style="font-size:16px"><?= e($user['name']) ?></div>
                        <div class="text-muted small">@<?= e($user['username']) ?></div>
                    </div>
                </div>

                <table class="table table-sm table-borderless mb-0" style="font-size:13px">
                    <tr>
                        <td class="text-muted ps-0" style="width:40%">Email</td>
                        <td><?= $user['email'] ? e($user['email']) : '<span class="text-muted">—</span>' ?></td>
                    </tr>
                    <tr>
                        <td class="text-muted ps-0">Role</td>
                        <td><span class="badge bg-<?= $rc ?>"><?= $rl ?></span></td>
                    </tr>
                    <tr>
                        <td class="text-muted ps-0">Status</td>
                        <td><?= statusBadge($user['status']) ?></td>
                    </tr>
                    <tr>
                        <td class="text-muted ps-0">Location</td>
                        <td>
                            <?= $user['location_name']
                                ? '<i class="fa fa-location-dot me-1 text-primary"></i>' . e($user['location_name'])
                                : '<span class="text-muted">Not assigned</span>' ?>
                        </td>
                    </tr>
                    <tr>
                        <td class="text-muted ps-0">Last Login</td>
                        <td><?= $user['last_login'] ? fmtDate($user['last_login'], 'd M Y H:i') : '<span class="text-muted">Never</span>' ?></td>
                    </tr>
                    <tr>
                        <td class="text-muted ps-0">Created</td>
                        <td><?= fmtDate($user['created_at'], 'd M Y') ?></td>
                    </tr>
                </table>

                <div class="mt-4 pt-3 border-top">
                    <a href="edit.php?id=<?= $id ?>" class="btn btn-sm btn-outline-primary">
                        <i class="fa fa-pen me-1"></i>Edit Full Profile
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- ── Right: Location Assignment ────────────────────── -->
    <div class="col-lg-7">
        <div class="card shadow-sm border-0 mb-4">
            <div class="card-header fw-semibold" style="background:#f8fafc;font-size:13px">
                <i class="fa fa-location-dot me-2 text-primary"></i>Location Assignment
            </div>
            <div class="card-body">
                <form method="POST">
                    <?php if (csrfToken()): ?>
                    <input type="hidden" name="csrf_token" value="<?= csrfToken() ?>">
                    <?php endif; ?>

                    <p class="text-muted small mb-3">
                        Assign this user to a work location. For supervisors this scopes their data view; for other roles it records their primary base.
                    </p>

                    <div class="mb-4">
                        <label class="form-label fw-semibold">Location of Work</label>
                        <select name="location_id" class="form-select">
                            <option value="">— No location assigned —</option>
                            <?php foreach ($locations as $loc):
                                $typeIcon = ['yard'=>'fa-warehouse','showroom'=>'fa-car-side','port'=>'fa-anchor','office'=>'fa-building'][$loc['type']] ?? 'fa-map-marker-alt';
                            ?>
                            <option value="<?= $loc['id'] ?>"
                                <?= (int)($user['location_id'] ?? 0) === (int)$loc['id'] ? 'selected' : '' ?>>
                                <?= e($loc['name']) ?> (<?= ucfirst($loc['type']) ?>)
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text">Changes take effect immediately upon saving.</div>
                    </div>

                    <button type="submit" class="btn btn-primary">
                        <i class="fa fa-save me-1"></i>Save Location
                    </button>
                </form>
            </div>
        </div>

        <!-- Module Access Summary -->
        <?php if ($perms): ?>
        <div class="card shadow-sm border-0">
            <div class="card-header fw-semibold" style="background:#f8fafc;font-size:13px">
                <i class="fa fa-shield-halved me-2 text-primary"></i>Module Permissions
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm mb-0" style="font-size:12px">
                        <thead style="background:#f1f5f9">
                            <tr>
                                <th class="ps-3">Module</th>
                                <th class="text-center">View</th>
                                <th class="text-center">Write</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($perms as $p): ?>
                            <?php if (!$p['can_access'] && !$p['can_write']) continue; ?>
                            <tr>
                                <td class="ps-3"><?= ucwords(str_replace('_', ' ', $p['module'])) ?></td>
                                <td class="text-center">
                                    <?= $p['can_access']
                                        ? '<i class="fa fa-check text-success"></i>'
                                        : '<i class="fa fa-minus text-muted"></i>' ?>
                                </td>
                                <td class="text-center">
                                    <?= $p['can_write']
                                        ? '<i class="fa fa-check text-success"></i>'
                                        : '<i class="fa fa-minus text-muted"></i>' ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>

</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>

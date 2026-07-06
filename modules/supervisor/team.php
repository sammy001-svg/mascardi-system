<?php
require_once __DIR__ . '/../../includes/functions.php';
requireRole('supervisor');
$pageTitle = 'My Team';
$db    = getDB();
$me    = authUser();
$locId = supervisorLocationId();

if (!$locId) { header('Location: ' . BASE_URL . '/modules/supervisor/dashboard.php'); exit; }

$location = $db->prepare("SELECT name FROM locations WHERE id=?");
$location->execute([$locId]);
$locName = $location->fetchColumn() ?: 'Location';

// Fetch all users at this location (excluding the supervisor themselves)
try {
    $stmt = $db->prepare("
        SELECT u.*, l.name AS location_name
        FROM users u
        LEFT JOIN locations l ON l.id = u.location_id
        WHERE u.location_id = ?
        ORDER BY u.status DESC, u.name ASC
    ");
    $stmt->execute([$locId]);
    $teamMembers = $stmt->fetchAll();
} catch (\Throwable $_) { $teamMembers = []; }

$roleColors = [
    'sales_manager'       => ['success', 'Sales Manager'],
    'sales_officer'       => ['info', 'Sales Officer'],
    'sales_person'        => ['success', 'Sales Person'],
    'customer_relations'  => ['info', 'Customer Relations'],
    'receptionist'        => ['secondary', 'Receptionist'],
    'workshop_manager'    => ['primary', 'Workshop Manager'],
    'mechanic'            => ['secondary', 'Mechanic'],
    'driver'              => ['secondary', 'Driver'],
    'finance_manager'     => ['warning', 'Finance Manager'],
    'accountant'          => ['warning', 'Accountant'],
    'cashier'             => ['warning', 'Cashier'],
    'supervisor'          => ['dark', 'Supervisor'],
];

include __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div>
        <h5 class="mb-0"><i class="fa fa-people-group me-2 text-primary"></i>Team — <span class="text-primary"><?= e($locName) ?></span></h5>
        <div class="text-muted small"><?= count($teamMembers) ?> staff member<?= count($teamMembers) !== 1 ? 's' : '' ?> at this location</div>
    </div>
    <a href="<?= BASE_URL ?>/modules/supervisor/dashboard.php" class="btn btn-sm btn-outline-secondary"><i class="fa fa-arrow-left me-1"></i>Dashboard</a>
</div>

<?php if (empty($teamMembers)): ?>
<div class="card">
    <div class="card-body text-center py-5 text-muted">
        <i class="fa fa-users fa-3x mb-3 d-block opacity-25"></i>
        <p>No team members found for this location.</p>
        <div class="text-muted small">Ask your admin to assign users to <strong><?= e($locName) ?></strong>.</div>
    </div>
</div>
<?php else: ?>

<div class="row g-3">
    <?php foreach ($teamMembers as $u):
        [$rc, $rl] = $roleColors[$u['role']] ?? ['secondary', ucfirst(str_replace('_', ' ', $u['role']))];
        $initials = strtoupper(implode('', array_map(fn($w) => $w[0], array_slice(explode(' ', $u['name']), 0, 2))));
        $isSelf   = ((int)$u['id'] === (int)$me['id']);
    ?>
    <div class="col-sm-6 col-lg-4">
        <div class="card <?= $isSelf ? 'border-primary' : '' ?>" style="<?= $isSelf ? 'border-width:2px' : '' ?>">
            <div class="card-body d-flex align-items-center gap-3">
                <div class="d-flex align-items-center justify-content-center rounded-circle fw-bold"
                     style="width:48px;height:48px;background:#<?= substr(md5($u['name']), 0, 6) ?>22;color:#<?= substr(md5($u['name']), 0, 6) ?>;border:2px solid #<?= substr(md5($u['name']), 0, 6) ?>44;flex-shrink:0;font-size:15px">
                    <?= $initials ?>
                </div>
                <div class="flex-grow-1 min-w-0">
                    <div class="fw-semibold" style="font-size:13.5px">
                        <?= e($u['name']) ?>
                        <?php if ($isSelf): ?><span class="badge bg-primary ms-1" style="font-size:9px">You</span><?php endif; ?>
                    </div>
                    <div class="text-muted small"><?= e($u['username']) ?></div>
                    <div class="mt-1 d-flex gap-1 flex-wrap">
                        <span class="badge bg-<?= $rc ?>" style="font-size:10px"><?= $rl ?></span>
                        <?= statusBadge($u['status']) ?>
                    </div>
                </div>
            </div>
            <div class="card-footer py-2 d-flex justify-content-between align-items-center" style="font-size:11px;background:#f8fafc">
                <?php if ($u['email']): ?>
                <a href="mailto:<?= e($u['email']) ?>" class="text-muted text-decoration-none">
                    <i class="fa fa-envelope me-1"></i><?= e($u['email']) ?>
                </a>
                <?php else: ?>
                <span class="text-muted">No email</span>
                <?php endif; ?>
                <span class="text-muted"><?= $u['last_login'] ? 'Last: ' . fmtDate($u['last_login'], 'd M') : 'Never logged in' ?></span>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php include __DIR__ . '/../../includes/footer.php'; ?>

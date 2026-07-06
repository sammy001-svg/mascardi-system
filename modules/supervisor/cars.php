<?php
require_once __DIR__ . '/../../includes/functions.php';
requireRole('supervisor');
$pageTitle = 'Cars at My Location';
$db    = getDB();
$locId = supervisorLocationId();

if (!$locId) { header('Location: ' . BASE_URL . '/modules/supervisor/dashboard.php'); exit; }

$location = $db->prepare("SELECT name FROM locations WHERE id=?");
$location->execute([$locId]);
$locName = $location->fetchColumn() ?: 'Location';

// Filters
$fStatus = $_GET['status'] ?? '';
$fSearch = trim($_GET['q'] ?? '');

// Include cars at the supervisor's main location AND all its sub-locations
$where  = "WHERE c.location_id IN (SELECT id FROM locations WHERE id=? OR parent_id=?)";
$params = [$locId, $locId];

if ($fStatus) { $where .= " AND c.status = ?"; $params[] = $fStatus; }
if ($fSearch) {
    $where .= " AND (c.make LIKE ? OR c.model LIKE ? OR c.chassis_number LIKE ? OR c.registration_number LIKE ?)";
    $s = "%{$fSearch}%";
    $params = array_merge($params, [$s, $s, $s, $s]);
}

$stmt = $db->prepare("
    SELECT c.*, l.name AS loc_name
    FROM cars c
    LEFT JOIN locations l ON l.id = c.location_id
    {$where}
    ORDER BY c.updated_at DESC
");
$stmt->execute($params);
$cars = $stmt->fetchAll();

// Distinct statuses across location + sub-locations for filter dropdown
$statuses = $db->prepare("SELECT DISTINCT status FROM cars WHERE location_id IN (SELECT id FROM locations WHERE id=? OR parent_id=?) ORDER BY status");
$statuses->execute([$locId, $locId]);
$statuses = array_column($statuses->fetchAll(), 'status');

include __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div>
        <h5 class="mb-0"><i class="fa fa-car me-2 text-primary"></i>Cars — <span class="text-primary"><?= e($locName) ?></span></h5>
        <div class="text-muted small"><?= count($cars) ?> vehicle<?= count($cars) !== 1 ? 's' : '' ?> found</div>
    </div>
    <a href="<?= BASE_URL ?>/modules/supervisor/dashboard.php" class="btn btn-sm btn-outline-secondary"><i class="fa fa-arrow-left me-1"></i>Dashboard</a>
</div>

<!-- Filters -->
<form method="GET" class="card card-body mb-3 py-2">
    <div class="row g-2 align-items-end">
        <div class="col-md-5">
            <input type="text" name="q" class="form-control form-control-sm" placeholder="Search make, model, chassis, reg…" value="<?= e($fSearch) ?>">
        </div>
        <div class="col-md-4">
            <select name="status" class="form-select form-select-sm">
                <option value="">All Statuses</option>
                <?php foreach ($statuses as $st): ?>
                <option value="<?= e($st) ?>" <?= $fStatus === $st ? 'selected' : '' ?>><?= ucwords(str_replace('_', ' ', $st)) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3 d-flex gap-2">
            <button class="btn btn-primary btn-sm flex-fill"><i class="fa fa-search me-1"></i>Filter</button>
            <a href="?" class="btn btn-outline-secondary btn-sm"><i class="fa fa-rotate-right"></i></a>
        </div>
    </div>
</form>

<div class="card">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0 datatable">
                <thead>
                    <tr>
                        <th class="ps-3">Vehicle</th>
                        <th>Chassis / Reg</th>
                        <th>Location</th>
                        <th>Status</th>
                        <th>Body Type</th>
                        <th>Year</th>
                        <th class="text-end pe-3">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($cars as $car): ?>
                    <tr>
                        <td class="ps-3">
                            <div class="fw-semibold" style="font-size:13.5px"><?= e($car['make'] . ' ' . $car['model']) ?></div>
                            <div class="text-muted small"><?= e($car['color'] ?? '') ?><?= $car['transmission'] ? ' · ' . e($car['transmission']) : '' ?></div>
                        </td>
                        <td>
                            <?php if ($car['registration_number']): ?><code style="font-size:11px"><?= e($car['registration_number']) ?></code><br><?php endif; ?>
                            <span class="text-muted" style="font-size:10px"><?= e($car['chassis_number'] ?: '—') ?></span>
                        </td>
                        <td class="small text-muted"><?= e($car['loc_name'] ?? '—') ?></td>
                        <td><?= statusBadge($car['status']) ?></td>
                        <td class="text-muted small"><?= e($car['body_type'] ?? '—') ?></td>
                        <td class="text-muted small"><?= e($car['year'] ?? '—') ?></td>
                        <td class="text-end pe-3">
                            <a href="<?= BASE_URL ?>/modules/cars/view.php?id=<?= $car['id'] ?>" class="btn btn-xs btn-outline-primary">
                                <i class="fa fa-eye me-1"></i>View
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($cars)): ?>
                    <tr><td colspan="7" class="text-center py-5 text-muted">
                        <i class="fa fa-car fa-2x mb-2 d-block opacity-25"></i>No cars found at this location.
                    </td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>

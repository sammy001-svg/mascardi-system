<?php
require_once __DIR__ . '/../../includes/functions.php';
requireRole('supervisor');
$pageTitle = 'Quick Assessments';
$db    = getDB();
$locId = supervisorLocationId();

if (!$locId) { header('Location: ' . BASE_URL . '/modules/supervisor/dashboard.php'); exit; }

$location = $db->prepare("SELECT name FROM locations WHERE id=?");
$location->execute([$locId]);
$locName = $location->fetchColumn() ?: 'Location';

$fSearch = trim($_GET['q'] ?? '');
$fStatus = trim($_GET['status'] ?? '');

// Sub-location IDs for scoping
$locationIds = [$locId];
try {
    $subStmt = $db->prepare("SELECT id FROM locations WHERE parent_id=?");
    $subStmt->execute([$locId]);
    foreach ($subStmt->fetchAll() as $sub) { $locationIds[] = (int)$sub['id']; }
} catch (\Throwable $_) {}
$inList = implode(',', $locationIds);

// Build the WHERE clause:
// An assessment belongs to this location if:
//   (a) it has a car_id and that car is at this location, OR
//   (b) it has no car_id but was created by a user assigned to this location
$params = [];
$searchSql = '';
if ($fSearch) {
    $searchSql = " AND (qa.assessment_number LIKE ? OR COALESCE(c.make, qa.car_make) LIKE ? OR COALESCE(c.model, qa.car_model) LIKE ? OR COALESCE(c.chassis_number, '') LIKE ? OR COALESCE(c.registration_number, qa.car_registration) LIKE ? OR qa.client_name LIKE ?)";
    $s = "%{$fSearch}%";
    $params = array_merge($params, [$s, $s, $s, $s, $s, $s]);
}
$statusSql = '';
if ($fStatus) {
    $statusSql = " AND qa.overall_condition = ?";
    $params[] = $fStatus;
}

try {
    $stmt = $db->prepare("
        SELECT qa.*,
               COALESCE(c.make, qa.car_make) AS make,
               COALESCE(c.model, qa.car_model) AS model,
               COALESCE(c.chassis_number, '') AS chassis_number,
               COALESCE(c.registration_number, qa.car_registration) AS registration_number
        FROM quick_assessments qa
        LEFT JOIN cars c ON c.id = qa.car_id
        LEFT JOIN users u ON u.id = qa.created_by
        WHERE (
            (qa.car_id IS NOT NULL AND c.location_id IN ({$inList}))
            OR
            (qa.car_id IS NULL AND (u.location_id IN ({$inList}) OR u.location_id IS NULL))
        )
        {$searchSql}
        {$statusSql}
        ORDER BY qa.assessment_date DESC, qa.id DESC
    ");
    $stmt->execute($params);
    $assessments = $stmt->fetchAll();
} catch (\Throwable $e) {
    error_log('[supervisor/quick_assessments] ' . $e->getMessage());
    $assessments = [];
}

include __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div>
        <h5 class="mb-0"><i class="fa fa-magnifying-glass-chart me-2 text-primary"></i>Quick Assessments — <span class="text-primary"><?= e($locName) ?></span></h5>
        <div class="text-muted small"><?= count($assessments) ?> record<?= count($assessments) !== 1 ? 's' : '' ?></div>
    </div>
    <div class="d-flex gap-2">
        <?php if (canWrite('quick_assessments')): ?>
        <a href="<?= BASE_URL ?>/modules/quick_assessments/add.php" class="btn btn-sm btn-primary"><i class="fa fa-plus me-1"></i>New Assessment</a>
        <?php endif; ?>
        <a href="<?= BASE_URL ?>/modules/supervisor/dashboard.php" class="btn btn-sm btn-outline-secondary"><i class="fa fa-arrow-left me-1"></i>Dashboard</a>
    </div>
</div>

<form method="GET" class="card card-body mb-3 py-2">
    <div class="row g-2 align-items-end">
        <div class="col-md-5">
            <input type="text" name="q" class="form-control form-control-sm" placeholder="Search #, make, model, reg, client…" value="<?= e($fSearch) ?>">
        </div>
        <div class="col-md-4">
            <select name="status" class="form-select form-select-sm">
                <option value="">All Conditions</option>
                <?php foreach (['good'=>'Good','fair'=>'Fair','needs_attention'=>'Needs Attention','critical'=>'Critical'] as $k=>$v): ?>
                <option value="<?= $k ?>" <?= $fStatus === $k ? 'selected' : '' ?>><?= $v ?></option>
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
                        <th class="ps-3">Ref #</th>
                        <th>Vehicle</th>
                        <th>Reg / Chassis</th>
                        <th>Client</th>
                        <th>Date</th>
                        <th>Condition</th>
                        <th>Assessed By</th>
                        <th class="pe-3">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($assessments as $qa): ?>
                    <tr>
                        <td class="ps-3"><code style="font-size:11px"><?= e($qa['assessment_number'] ?? $qa['id']) ?></code></td>
                        <td class="fw-medium small">
                            <?= e(trim($qa['make'] . ' ' . $qa['model'])) ?: '<span class="text-muted">—</span>' ?>
                            <?php if (!empty($qa['car_year'])): ?>
                            <span class="text-muted ms-1">(<?= e($qa['car_year']) ?>)</span>
                            <?php endif; ?>
                        </td>
                        <td class="small text-muted">
                            <?php if ($qa['registration_number']): ?><code style="font-size:10px"><?= e($qa['registration_number']) ?></code><?php endif; ?>
                            <?php if ($qa['chassis_number']): ?><div class="text-muted" style="font-size:10px"><?= e($qa['chassis_number']) ?></div><?php endif; ?>
                            <?php if (!$qa['registration_number'] && !$qa['chassis_number']): ?>—<?php endif; ?>
                        </td>
                        <td class="small">
                            <div><?= $qa['client_name'] ? e($qa['client_name']) : '<span class="text-muted">—</span>' ?></div>
                            <?php if ($qa['client_phone']): ?><div class="text-muted" style="font-size:11px"><?= e($qa['client_phone']) ?></div><?php endif; ?>
                        </td>
                        <td class="small text-muted"><?= $qa['assessment_date'] ? fmtDate($qa['assessment_date']) : fmtDate($qa['created_at'], 'd M Y') ?></td>
                        <td><?= isset($qa['overall_condition']) ? statusBadge($qa['overall_condition']) : '<span class="text-muted">—</span>' ?></td>
                        <td class="small text-muted"><?= e($qa['assessed_by'] ?? '—') ?></td>
                        <td class="text-end pe-3">
                            <a href="<?= BASE_URL ?>/modules/quick_assessments/view.php?id=<?= $qa['id'] ?>" class="btn btn-xs btn-outline-primary">
                                <i class="fa fa-eye me-1"></i>View
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($assessments)): ?>
                    <tr><td colspan="8" class="text-center py-5 text-muted">
                        <i class="fa fa-magnifying-glass-chart fa-2x mb-2 d-block opacity-25"></i>No assessments found for this location.
                    </td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>

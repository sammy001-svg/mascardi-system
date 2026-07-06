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
$params  = [$locId, $locId];
$searchSql = '';
if ($fSearch) {
    $searchSql = " AND (qa.assessment_number LIKE ? OR c.make LIKE ? OR c.model LIKE ? OR c.chassis_number LIKE ?)";
    $s = "%{$fSearch}%";
    $params = array_merge($params, [$s, $s, $s, $s]);
}

try {
    $stmt = $db->prepare("
        SELECT qa.*, c.make, c.model, c.chassis_number, c.registration_number
        FROM quick_assessments qa
        LEFT JOIN cars c ON c.id = qa.car_id
        WHERE (c.location_id=? OR qa.location_id=?)
        {$searchSql}
        ORDER BY qa.created_at DESC
    ");
    $stmt->execute($params);
    $assessments = $stmt->fetchAll();
} catch (\Throwable $_) { $assessments = []; }

include __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div>
        <h5 class="mb-0"><i class="fa fa-magnifying-glass-chart me-2 text-primary"></i>Quick Assessments — <span class="text-primary"><?= e($locName) ?></span></h5>
        <div class="text-muted small"><?= count($assessments) ?> record<?= count($assessments) !== 1 ? 's' : '' ?></div>
    </div>
    <a href="<?= BASE_URL ?>/modules/supervisor/dashboard.php" class="btn btn-sm btn-outline-secondary"><i class="fa fa-arrow-left me-1"></i>Dashboard</a>
</div>

<form method="GET" class="card card-body mb-3 py-2">
    <div class="row g-2 align-items-end">
        <div class="col-md-7">
            <input type="text" name="q" class="form-control form-control-sm" placeholder="Search assessment #, make, model, chassis…" value="<?= e($fSearch) ?>">
        </div>
        <div class="col-md-5 d-flex gap-2">
            <button class="btn btn-primary btn-sm flex-fill"><i class="fa fa-search me-1"></i>Search</button>
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
                        <th>Chassis / Reg</th>
                        <th>Date</th>
                        <th>Overall</th>
                        <th class="pe-3">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($assessments as $qa): ?>
                    <tr>
                        <td class="ps-3"><code style="font-size:11px"><?= e($qa['assessment_number'] ?? $qa['id']) ?></code></td>
                        <td class="fw-medium small"><?= e(trim($qa['make'] . ' ' . $qa['model'])) ?></td>
                        <td class="small text-muted">
                            <?= e($qa['chassis_number'] ?: '—') ?>
                            <?php if ($qa['registration_number']): ?><br><code style="font-size:10px"><?= e($qa['registration_number']) ?></code><?php endif; ?>
                        </td>
                        <td class="small text-muted"><?= $qa['assessment_date'] ? fmtDate($qa['assessment_date']) : fmtDate($qa['created_at'], 'd M Y') ?></td>
                        <td><?= isset($qa['overall_condition']) ? statusBadge($qa['overall_condition']) : '<span class="text-muted">—</span>' ?></td>
                        <td class="text-end pe-3">
                            <a href="<?= BASE_URL ?>/modules/quick_assessments/view.php?id=<?= $qa['id'] ?>" class="btn btn-xs btn-outline-primary">
                                <i class="fa fa-eye me-1"></i>View
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($assessments)): ?>
                    <tr><td colspan="6" class="text-center py-5 text-muted">
                        <i class="fa fa-magnifying-glass-chart fa-2x mb-2 d-block opacity-25"></i>No assessments found for this location.
                    </td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>

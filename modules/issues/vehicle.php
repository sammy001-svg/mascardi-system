<?php
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
canAccess('issues') || die('Access denied.');

$carId = (int)($_GET['car_id'] ?? 0);
if (!$carId) redirect(BASE_URL . '/modules/issues/index.php');

$db = getDB();

// Resolve an issue
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['resolve_item'])) {
    $itemId    = (int)($_POST['item_id'] ?? 0);
    $resolvedBy = trim($_POST['resolved_by'] ?? authUser()['name'] ?? 'System');
    if ($itemId) {
        $db->prepare("UPDATE assessment_items SET is_resolved=1, resolved_at=NOW(), resolved_by=? WHERE id=?")
           ->execute([$resolvedBy, $itemId]);
    }
    redirect(BASE_URL . '/modules/issues/vehicle.php?car_id=' . $carId);
}

// Reopen an issue
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reopen_item'])) {
    $itemId = (int)($_POST['item_id'] ?? 0);
    if ($itemId) {
        $db->prepare("UPDATE assessment_items SET is_resolved=0, resolved_at=NULL, resolved_by=NULL WHERE id=?")
           ->execute([$itemId]);
    }
    redirect(BASE_URL . '/modules/issues/vehicle.php?car_id=' . $carId);
}

// Car details
$car = $db->prepare("SELECT c.*, l.name AS location_name FROM cars c LEFT JOIN locations l ON l.id=c.location_id WHERE c.id=?");
$car->execute([$carId]);
$car = $car->fetch();
if (!$car) { setFlash('error', 'Vehicle not found.'); redirect(BASE_URL . '/modules/issues/index.php'); }

// All issues for this vehicle (condition != good), grouped for display
$items = $db->prepare("
    SELECT
        ai.id, ai.part_name, ai.part_category, ai.`condition`, ai.notes,
        ai.is_resolved, ai.resolved_at, ai.resolved_by,
        ca.assessment_date, ca.assessment_type, ca.id AS assessment_id
    FROM assessment_items ai
    JOIN car_assessments ca ON ca.id = ai.assessment_id
    WHERE ca.car_id = ? AND ai.`condition` != 'good'
    ORDER BY ai.is_resolved ASC, ai.part_category ASC, ca.assessment_date DESC
");
$items->execute([$carId]);
$items = $items->fetchAll(PDO::FETCH_ASSOC);

// Group by category
$grouped = [];
foreach ($items as $item) {
    $grouped[$item['part_category']][] = $item;
}

// Stats
$total    = count($items);
$open     = count(array_filter($items, fn($i) => !$i['is_resolved']));
$resolved = $total - $open;

$conditionMeta = [
    'major_damage'  => ['Major Damage',  'danger'],
    'minor_damage'  => ['Minor Damage',  'warning'],
    'needs_service' => ['Needs Service', 'primary'],
    'missing'       => ['Missing',       'dark'],
];

$catIcons = [
    'Exterior'             => 'fa-car-side',
    'Wheels & Tyres'       => 'fa-circle-dot',
    'Interior'             => 'fa-couch',
    'Electronics'          => 'fa-microchip',
    'Engine & Mechanical'  => 'fa-gears',
    'Lighting'             => 'fa-lightbulb',
    'Documents'            => 'fa-file-lines',
];

$typeLabel = [
    'arrival'        => 'Vehicle Intake Protocol',
    'pre_delivery'   => 'Pre-Delivery',
    'client_service' => 'Client Service',
    'yard'           => 'Yard Assessment',
    'workshop'       => 'Workshop',
];

$pageTitle = 'Issues — ' . $car['make'] . ' ' . $car['model'];
include __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h5 class="mb-1">
            <i class="fa fa-triangle-exclamation me-2 text-warning"></i>
            <?= e($car['make'] . ' ' . $car['model'] . ' ' . $car['year']) ?>
        </h5>
        <div class="d-flex align-items-center gap-2 text-muted small">
            <?php if ($car['registration_number']): ?>
            <span class="badge bg-dark"><?= e($car['registration_number']) ?></span>
            <?php endif; ?>
            <code style="font-size:11px"><?= e($car['chassis_number']) ?></code>
            <?php if ($car['location_name']): ?>
            <span><i class="fa fa-location-dot me-1"></i><?= e($car['location_name']) ?></span>
            <?php endif; ?>
        </div>
    </div>
    <div class="d-flex gap-2">
        <a href="<?= BASE_URL ?>/modules/cars/view.php?id=<?= $carId ?>" class="btn btn-sm btn-outline-secondary">
            <i class="fa fa-car me-1"></i>Vehicle Profile
        </a>
        <a href="index.php" class="btn btn-sm btn-outline-secondary">
            <i class="fa fa-arrow-left me-1"></i>All Issues
        </a>
    </div>
</div>

<!-- Stat cards -->
<div class="row g-3 mb-4">
    <div class="col-4">
        <div class="card border-0 shadow-sm">
            <div class="card-body d-flex align-items-center gap-3 py-3">
                <div style="width:42px;height:42px;border-radius:10px;background:#fef3c7;color:#d97706;display:flex;align-items:center;justify-content:center;flex-shrink:0">
                    <i class="fa fa-circle-exclamation"></i>
                </div>
                <div>
                    <div style="font-size:24px;font-weight:700;line-height:1"><?= $total ?></div>
                    <div class="text-muted small">Total</div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-4">
        <div class="card border-0 shadow-sm">
            <div class="card-body d-flex align-items-center gap-3 py-3">
                <div style="width:42px;height:42px;border-radius:10px;background:#fee2e2;color:#dc2626;display:flex;align-items:center;justify-content:center;flex-shrink:0">
                    <i class="fa fa-lock-open"></i>
                </div>
                <div>
                    <div style="font-size:24px;font-weight:700;line-height:1;color:#dc2626"><?= $open ?></div>
                    <div class="text-muted small">Open</div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-4">
        <div class="card border-0 shadow-sm">
            <div class="card-body d-flex align-items-center gap-3 py-3">
                <div style="width:42px;height:42px;border-radius:10px;background:#dcfce7;color:#16a34a;display:flex;align-items:center;justify-content:center;flex-shrink:0">
                    <i class="fa fa-circle-check"></i>
                </div>
                <div>
                    <div style="font-size:24px;font-weight:700;line-height:1;color:#16a34a"><?= $resolved ?></div>
                    <div class="text-muted small">Resolved</div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php if (empty($grouped)): ?>
<div class="card">
    <div class="card-body text-center py-5 text-muted">
        <i class="fa fa-circle-check fa-2x mb-2 d-block text-success"></i>
        No issues found for this vehicle.
    </div>
</div>
<?php else: ?>

<?php foreach ($grouped as $category => $catItems):
    $catOpen     = count(array_filter($catItems, fn($i) => !$i['is_resolved']));
    $catResolved = count($catItems) - $catOpen;
    $icon        = $catIcons[$category] ?? 'fa-box';
?>
<div class="card mb-3">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span class="fw-semibold">
            <i class="fa <?= $icon ?> me-2 text-primary"></i><?= e($category) ?>
        </span>
        <div class="d-flex gap-2" style="font-size:12px">
            <?php if ($catOpen): ?>
            <span class="badge bg-danger"><?= $catOpen ?> open</span>
            <?php endif; ?>
            <?php if ($catResolved): ?>
            <span class="badge bg-success"><?= $catResolved ?> resolved</span>
            <?php endif; ?>
        </div>
    </div>
    <div class="card-body p-0">
        <table class="table table-hover mb-0" style="font-size:13px">
            <thead style="background:#f8fafc;font-size:11px;color:#64748b;text-transform:uppercase;letter-spacing:.04em">
                <tr>
                    <th class="ps-3 py-2">Part / Item</th>
                    <th class="py-2">Condition</th>
                    <th class="py-2">Notes</th>
                    <th class="py-2">From Assessment</th>
                    <th class="py-2">Status</th>
                    <th class="py-2 pe-3 text-end">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($catItems as $item):
                    [$condLabel, $condColor] = $conditionMeta[$item['condition']] ?? [ucwords(str_replace('_',' ',$item['condition'])), 'secondary'];
                    $assLabel = $typeLabel[$item['assessment_type']] ?? ucwords(str_replace('_',' ',$item['assessment_type']));
                ?>
                <tr class="<?= $item['is_resolved'] ? 'table-success bg-opacity-25' : '' ?>">
                    <td class="ps-3 py-2 fw-semibold"><?= e($item['part_name']) ?></td>
                    <td class="py-2">
                        <span class="badge bg-<?= $condColor ?>"><?= $condLabel ?></span>
                    </td>
                    <td class="py-2 text-muted" style="max-width:220px">
                        <?= $item['notes'] ? e($item['notes']) : '<span class="text-muted">—</span>' ?>
                    </td>
                    <td class="py-2" style="font-size:12px">
                        <a href="<?= BASE_URL ?>/modules/assessments/view.php?id=<?= $item['assessment_id'] ?>" class="text-decoration-none text-primary">
                            <?= e($assLabel) ?>
                        </a>
                        <div class="text-muted"><?= fmtDate($item['assessment_date']) ?></div>
                    </td>
                    <td class="py-2">
                        <?php if ($item['is_resolved']): ?>
                        <span class="text-success fw-semibold" style="font-size:12px">
                            <i class="fa fa-check-circle me-1"></i>Resolved
                        </span>
                        <?php if ($item['resolved_by']): ?>
                        <div class="text-muted" style="font-size:11px">by <?= e($item['resolved_by']) ?></div>
                        <?php endif; ?>
                        <?php if ($item['resolved_at']): ?>
                        <div class="text-muted" style="font-size:11px"><?= fmtDate($item['resolved_at']) ?></div>
                        <?php endif; ?>
                        <?php else: ?>
                        <span class="text-danger fw-semibold" style="font-size:12px">
                            <i class="fa fa-circle-xmark me-1"></i>Open
                        </span>
                        <?php endif; ?>
                    </td>
                    <td class="py-2 pe-3 text-end">
                        <?php if ($item['is_resolved']): ?>
                        <form method="POST" class="d-inline">
                            <input type="hidden" name="item_id" value="<?= $item['id'] ?>">
                            <button type="submit" name="reopen_item" class="btn btn-xs btn-outline-warning" title="Reopen">
                                <i class="fa fa-rotate-left me-1"></i>Reopen
                            </button>
                        </form>
                        <?php else: ?>
                        <button type="button" class="btn btn-xs btn-outline-success resolve-btn"
                                data-item-id="<?= $item['id'] ?>"
                                data-part="<?= e($item['part_name']) ?>">
                            <i class="fa fa-check me-1"></i>Resolve
                        </button>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endforeach; ?>
<?php endif; ?>

<!-- Resolve modal -->
<div class="modal fade" id="resolveModal" tabindex="-1">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="item_id" id="modalItemId">
                <div class="modal-header">
                    <h6 class="modal-title"><i class="fa fa-check-circle text-success me-2"></i>Mark as Resolved</h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p class="small text-muted mb-3">Resolving: <strong id="modalPartName"></strong></p>
                    <label class="form-label small fw-semibold">Resolved by</label>
                    <input type="text" name="resolved_by" class="form-control form-control-sm"
                           value="<?= e(authUser()['name'] ?? '') ?>" placeholder="Your name" required>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="resolve_item" class="btn btn-sm btn-success">
                        <i class="fa fa-check me-1"></i>Confirm
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.querySelectorAll('.resolve-btn').forEach(btn => {
    btn.addEventListener('click', () => {
        document.getElementById('modalItemId').value   = btn.dataset.itemId;
        document.getElementById('modalPartName').textContent = btn.dataset.part;
        new bootstrap.Modal(document.getElementById('resolveModal')).show();
    });
});
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>

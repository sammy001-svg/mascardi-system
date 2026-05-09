<?php
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
$id = (int)($_GET['id'] ?? 0);
if (!$id) redirect(BASE_URL . '/modules/assessments/index.php');
$db = getDB();

$stmt = $db->prepare("
    SELECT ca.*, c.chassis_number, c.make, c.model, c.year, c.color,
           m.name AS mechanic_name, d.name AS driver_name
    FROM car_assessments ca
    JOIN cars c ON c.id = ca.car_id
    LEFT JOIN mechanics m ON m.id = ca.mechanic_id
    LEFT JOIN drivers d ON d.id = ca.driver_id
    WHERE ca.id = ?
");
$stmt->execute([$id]);
$assessment = $stmt->fetch();
if (!$assessment) { setFlash('error', 'Assessment not found.'); redirect(BASE_URL . '/modules/assessments/index.php'); }

$items = $db->prepare("SELECT * FROM assessment_items WHERE assessment_id = ? ORDER BY part_category, part_name");
$items->execute([$id]);
$items = $items->fetchAll();

// Group by category
$byCategory = [];
foreach ($items as $item) {
    $byCategory[$item['part_category']][] = $item;
}

$totalItems   = count($items);
$issuedItems  = array_filter($items, fn($i) => $i['condition'] !== 'good');
$issueCount   = count($issuedItems);
$goodCount    = $totalItems - $issueCount;

$condMeta = [
    'good'          => ['label' => 'Good',          'badge' => 'success', 'icon' => 'fa-check'],
    'minor_damage'  => ['label' => 'Minor Damage',  'badge' => 'warning', 'icon' => 'fa-triangle-exclamation'],
    'major_damage'  => ['label' => 'Major Damage',  'badge' => 'danger',  'icon' => 'fa-circle-xmark'],
    'missing'       => ['label' => 'Missing',        'badge' => 'dark',    'icon' => 'fa-ban'],
    'needs_service' => ['label' => 'Needs Service',  'badge' => 'primary', 'icon' => 'fa-wrench'],
];

$catIcons = [
    'Exterior'            => 'fa-car-side',
    'Lighting'            => 'fa-lightbulb',
    'Wheels & Tyres'      => 'fa-circle-dot',
    'Interior'            => 'fa-couch',
    'Electronics'         => 'fa-microchip',
    'Engine & Mechanical' => 'fa-gears',
    'Documents'           => 'fa-file-lines',
];

$fuelLabels = ['empty' => 'Empty', 'quarter' => '¼ Tank', 'half' => 'Half', 'three_quarter' => '¾ Tank', 'full' => 'Full'];

$pageTitle = 'Assessment — ' . $assessment['make'] . ' ' . $assessment['model'];
include __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <h5 class="mb-1">
            <?= e($assessment['make'] . ' ' . $assessment['model'] . ' ' . $assessment['year']) ?>
            <span class="text-muted fw-normal">&mdash; Assessment</span>
        </h5>
        <div class="text-muted small">
            <code><?= e($assessment['chassis_number']) ?></code>
            &bull; <?= fmtDate($assessment['assessment_date']) ?>
            &bull; <?= ucwords(str_replace('_', ' ', $assessment['assessment_type'])) ?>
        </div>
    </div>
    <div class="d-flex gap-2 flex-wrap no-print">
        <?php if (canAccess('jobs') && canEditDelete()): ?>
        <a href="<?= BASE_URL ?>/modules/jobs/add.php?car_id=<?= $assessment['car_id'] ?>&assessment_id=<?= $id ?>"
           class="btn btn-sm btn-primary">
            <i class="fa fa-toolbox me-1"></i>Create Job Card
        </a>
        <?php endif; ?>
        <?php if (canAccess('cars')): ?>
        <a href="<?= BASE_URL ?>/modules/cars/view.php?id=<?= $assessment['car_id'] ?>"
           class="btn btn-sm btn-outline-secondary">
            <i class="fa fa-car me-1"></i>View Car
        </a>
        <?php endif; ?>
        <?php if (canEditDelete()): ?>
        <a href="delete.php?id=<?= $id ?>" class="btn btn-sm btn-outline-danger confirm-delete">
            <i class="fa fa-trash me-1"></i>Delete
        </a>
        <?php endif; ?>
        <button onclick="window.print()" class="btn btn-sm btn-outline-dark">
            <i class="fa fa-print me-1"></i>Print
        </button>
        <a href="index.php" class="btn btn-sm btn-outline-secondary">
            <i class="fa fa-arrow-left me-1"></i>Back
        </a>
    </div>
</div>

<!-- ── Summary KPI Strip ─────────────────────────────────────────────────── -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="stat-card" style="border-left:4px solid <?= $issueCount > 0 ? '#dc2626' : '#16a34a' ?>">
            <div class="stat-icon" style="background:<?= $issueCount > 0 ? '#fee2e2;color:#dc2626' : '#dcfce7;color:#16a34a' ?>">
                <i class="fa <?= $issueCount > 0 ? 'fa-triangle-exclamation' : 'fa-circle-check' ?>"></i>
            </div>
            <div class="stat-info">
                <div class="stat-label">Issues Found</div>
                <div class="stat-value"><?= $issueCount ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card" style="border-left:4px solid #16a34a">
            <div class="stat-icon" style="background:#dcfce7;color:#16a34a">
                <i class="fa fa-check-double"></i>
            </div>
            <div class="stat-info">
                <div class="stat-label">Parts Good</div>
                <div class="stat-value"><?= $goodCount ?> <span style="font-size:13px;font-weight:500" class="text-muted">/ <?= $totalItems ?></span></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card" style="border-left:4px solid #2563eb">
            <div class="stat-icon" style="background:#dbeafe;color:#2563eb">
                <i class="fa fa-gauge-high"></i>
            </div>
            <div class="stat-info">
                <div class="stat-label">Mileage</div>
                <div class="stat-value stat-value-sm"><?= $assessment['mileage'] ? number_format((int)$assessment['mileage']) . ' km' : '—' ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card" style="border-left:4px solid #16a34a">
            <div class="stat-icon" style="background:#dcfce7;color:#16a34a">
                <i class="fa fa-gas-pump"></i>
            </div>
            <div class="stat-info">
                <div class="stat-label">Fuel Level</div>
                <div class="stat-value stat-value-sm"><?= e($fuelLabels[$assessment['fuel_level']] ?? ucfirst($assessment['fuel_level'])) ?></div>
            </div>
        </div>
    </div>
</div>

<!-- ── Meta Row ──────────────────────────────────────────────────────────── -->
<div class="card mb-4">
    <div class="card-body py-3">
        <div class="row g-2 text-center">
            <div class="col-6 col-md-3 border-end">
                <div class="text-muted small mb-1">Overall Condition</div>
                <?= statusBadge($assessment['overall_status']) ?>
            </div>
            <div class="col-6 col-md-3 border-end">
                <div class="text-muted small mb-1">Assessed By</div>
                <div class="fw-semibold small"><?= e($assessment['mechanic_name'] ?? $assessment['driver_name'] ?? '—') ?></div>
            </div>
            <div class="col-6 col-md-3 border-end">
                <div class="text-muted small mb-1">Assessment Type</div>
                <div class="fw-semibold small"><?= ucwords(str_replace('_', ' ', $assessment['assessment_type'])) ?></div>
            </div>
            <div class="col-6 col-md-3">
                <div class="text-muted small mb-1">Date</div>
                <div class="fw-semibold small"><?= fmtDate($assessment['assessment_date']) ?></div>
            </div>
        </div>
        <?php if ($assessment['notes']): ?>
        <hr class="my-2">
        <div class="text-muted small"><i class="fa fa-info-circle me-1"></i><?= e($assessment['notes']) ?></div>
        <?php endif; ?>
    </div>
</div>

<!-- ── Issues Summary (only if any) ─────────────────────────────────────── -->
<?php if ($issueCount > 0): ?>
<div class="card mb-4 border-danger-subtle">
    <div class="card-header" style="background:#fff5f5;border-color:#fca5a5">
        <i class="fa fa-triangle-exclamation text-danger me-2"></i>
        <span class="fw-semibold text-danger"><?= $issueCount ?> Issue<?= $issueCount > 1 ? 's' : '' ?> Found</span>
        <span class="text-muted small ms-2">— Items requiring attention</span>
    </div>
    <div class="card-body p-0">
        <table class="table table-hover mb-0">
            <thead>
                <tr>
                    <th class="ps-3">Category</th>
                    <th>Part</th>
                    <th>Condition</th>
                    <th>Notes</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($issuedItems as $item):
                    $meta = $condMeta[$item['condition']] ?? $condMeta['needs_service'];
                ?>
                <tr>
                    <td class="ps-3 text-muted small"><?= e($item['part_category']) ?></td>
                    <td class="fw-medium"><?= e($item['part_name']) ?></td>
                    <td>
                        <span class="badge bg-<?= $meta['badge'] ?>">
                            <i class="fa <?= $meta['icon'] ?> me-1"></i><?= $meta['label'] ?>
                        </span>
                    </td>
                    <td class="text-muted small"><?= $item['notes'] ? e($item['notes']) : '—' ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<!-- ── Full Checklist by Category ───────────────────────────────────────── -->
<h6 class="fw-semibold mb-3"><i class="fa fa-list-check me-2 text-primary"></i>Full Parts Checklist</h6>

<?php foreach ($byCategory as $cat => $catItems):
    $catIssues = count(array_filter($catItems, fn($i) => $i['condition'] !== 'good'));
    $icon = $catIcons[$cat] ?? 'fa-box';
?>
<div class="assess-category mb-3">
    <div class="assess-cat-header" onclick="toggleCat(this)" style="cursor:pointer">
        <div class="d-flex align-items-center gap-2">
            <i class="fa fa-chevron-down assess-chevron" style="font-size:12px;transition:transform .2s"></i>
            <i class="fa <?= $icon ?> text-primary"></i>
            <span class="fw-semibold"><?= e($cat) ?></span>
            <span class="badge bg-light text-dark border ms-1"><?= count($catItems) ?></span>
        </div>
        <?php if ($catIssues > 0): ?>
        <span class="badge bg-danger"><?= $catIssues ?> issue<?= $catIssues > 1 ? 's' : '' ?></span>
        <?php else: ?>
        <span class="badge bg-success"><i class="fa fa-check me-1"></i>All Good</span>
        <?php endif; ?>
    </div>
    <div class="assess-cat-body">
        <?php foreach ($catItems as $item):
            $meta = $condMeta[$item['condition']] ?? $condMeta['good'];
            $isIssue = $item['condition'] !== 'good';
        ?>
        <div class="assess-part-row<?= $isIssue ? ' view-issue-row' : '' ?>">
            <span class="assess-part-name"><?= e($item['part_name']) ?></span>
            <span class="badge bg-<?= $meta['badge'] ?> ms-auto">
                <i class="fa <?= $meta['icon'] ?> me-1"></i><?= $meta['label'] ?>
            </span>
            <?php if ($item['notes']): ?>
            <span class="text-muted small fst-italic" style="min-width:200px"><?= e($item['notes']) ?></span>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endforeach; ?>

<?php
$extraJs = <<<'JS'
<script>
function toggleCat(header) {
    var body    = header.nextElementSibling;
    var chevron = header.querySelector('.assess-chevron');
    var open    = body.style.display !== 'none';
    body.style.display      = open ? 'none' : '';
    chevron.style.transform = open ? 'rotate(-90deg)' : '';
}
</script>
JS;
include __DIR__ . '/../../includes/footer.php';
?>

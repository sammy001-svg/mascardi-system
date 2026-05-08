<?php
require_once __DIR__ . '/../../includes/functions.php';
$id = (int)($_GET['id']??0); if(!$id) redirect(BASE_URL.'/modules/assessments/index.php');
$db = getDB();
$stmt = $db->prepare("SELECT ca.*, c.chassis_number, c.make, c.model, c.year, m.name AS mechanic_name, m.phone AS mechanic_phone FROM car_assessments ca JOIN cars c ON c.id=ca.car_id LEFT JOIN mechanics m ON m.id=ca.mechanic_id WHERE ca.id=?");
$stmt->execute([$id]); $assessment = $stmt->fetch();
if(!$assessment){setFlash('error','Not found.');redirect(BASE_URL.'/modules/assessments/index.php');}

$items = $db->prepare("SELECT * FROM assessment_items WHERE assessment_id=? ORDER BY part_category, part_name");
$items->execute([$id]); $items = $items->fetchAll();

// Group by category
$byCategory = [];
foreach ($items as $item) { $byCategory[$item['part_category']][] = $item; }

$conditionColors = ['good'=>'success','minor_damage'=>'warning','major_damage'=>'danger','missing'=>'dark','needs_service'=>'info'];
$pageTitle = 'Assessment';
include __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="mb-0">Assessment: <?= e($assessment['make'].' '.$assessment['model']) ?> <span class="text-muted fw-normal small"><?= fmtDate($assessment['assessment_date']) ?></span></h5>
    <div class="d-flex gap-2">
        <a href="<?= BASE_URL ?>/modules/jobs/add.php?car_id=<?= $assessment['car_id'] ?>&assessment_id=<?= $id ?>" class="btn btn-sm btn-primary"><i class="fa fa-toolbox me-1"></i>Create Job Card</a>
        <a href="<?= BASE_URL ?>/modules/cars/view.php?id=<?= $assessment['car_id'] ?>" class="btn btn-sm btn-outline-secondary"><i class="fa fa-car me-1"></i>View Car</a>
        <a href="index.php" class="btn btn-sm btn-outline-secondary"><i class="fa fa-arrow-left me-1"></i>Back</a>
        <button onclick="window.print()" class="btn btn-sm btn-outline-dark no-print"><i class="fa fa-print me-1"></i>Print</button>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-md-2"><div class="card text-center p-3"><div class="text-muted small">Vehicle</div><div class="fw-semibold"><?= e($assessment['make'].' '.$assessment['model']) ?></div><div class="small text-muted"><?= e($assessment['chassis_number']) ?></div></div></div>
    <div class="col-md-2"><div class="card text-center p-3"><div class="text-muted small">Type</div><div class="fw-semibold"><?= ucwords(str_replace('_',' ',$assessment['assessment_type'])) ?></div></div></div>
    <div class="col-md-2"><div class="card text-center p-3"><div class="text-muted small">Overall Status</div><?= statusBadge($assessment['overall_status']) ?></div></div>
    <div class="col-md-2"><div class="card text-center p-3"><div class="text-muted small">Mileage</div><div class="fw-semibold"><?= $assessment['mileage'] ? number_format($assessment['mileage']).' km' : '—' ?></div></div></div>
    <div class="col-md-2"><div class="card text-center p-3"><div class="text-muted small">Fuel Level</div><div class="fw-semibold"><?= ucwords(str_replace('_',' ',$assessment['fuel_level'])) ?></div></div></div>
    <div class="col-md-2"><div class="card text-center p-3"><div class="text-muted small">Mechanic</div><div class="fw-semibold"><?= e($assessment['mechanic_name']??'—') ?></div></div></div>
</div>

<?php if ($assessment['notes']): ?>
<div class="alert alert-info"><i class="fa fa-info-circle me-2"></i><?= e($assessment['notes']) ?></div>
<?php endif; ?>

<!-- Summary of damaged parts -->
<?php
$damaged = array_filter($items, fn($i) => $i['condition'] !== 'good');
if ($damaged):
?>
<div class="card mb-4 border-warning">
    <div class="card-header bg-warning text-dark"><i class="fa fa-triangle-exclamation me-2"></i>Issues Found (<?= count($damaged) ?> items)</div>
    <div class="card-body p-0">
        <table class="table mb-0">
            <thead><tr><th class="ps-3">Category</th><th>Part</th><th>Condition</th><th>Notes</th></tr></thead>
            <tbody>
                <?php foreach ($damaged as $item): ?>
                <tr>
                    <td class="ps-3"><?= e($item['part_category']) ?></td>
                    <td><?= e($item['part_name']) ?></td>
                    <td><span class="badge bg-<?= $conditionColors[$item['condition']]??'secondary' ?>"><?= ucwords(str_replace('_',' ',$item['condition'])) ?></span></td>
                    <td><?= e($item['notes']??'—') ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<!-- Full checklist -->
<div class="card">
    <div class="card-header"><i class="fa fa-list-check me-2"></i>Full Parts Checklist</div>
    <div class="card-body">
        <div class="parts-grid">
            <?php foreach ($byCategory as $cat => $catItems): ?>
            <div class="parts-category">
                <div class="category-title"><?= e($cat) ?></div>
                <?php foreach ($catItems as $item): ?>
                <div class="part-row">
                    <span><?= e($item['part_name']) ?></span>
                    <div class="text-end">
                        <span class="badge bg-<?= $conditionColors[$item['condition']]??'secondary' ?>"><?= ucwords(str_replace('_',' ',$item['condition'])) ?></span>
                        <?php if($item['notes']): ?><div class="text-muted" style="font-size:11px"><?= e($item['notes']) ?></div><?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>

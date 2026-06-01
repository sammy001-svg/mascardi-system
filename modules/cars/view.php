<?php
require_once __DIR__ . '/../../includes/functions.php';
$id = (int)($_GET['id'] ?? 0);
if (!$id) redirect(BASE_URL . '/modules/cars/index.php');
$db = getDB();

$car = $db->prepare("SELECT c.*, l.name AS location_name, cl.phone AS owner_phone FROM cars c LEFT JOIN locations l ON l.id = c.location_id LEFT JOIN clients cl ON cl.id = c.client_id WHERE c.id=?");
$car->execute([$id]);
$car = $car->fetch();
if (!$car) { setFlash('error','Car not found.'); redirect(BASE_URL.'/modules/cars/index.php'); }

$intake   = $db->prepare("SELECT * FROM car_intake WHERE car_id=? ORDER BY id DESC LIMIT 1");
$intake->execute([$id]); $intake = $intake->fetch();

$transfers = $db->prepare("SELECT ct.*, d.name AS transported_by FROM car_transfers ct LEFT JOIN drivers d ON d.id = ct.driver_id WHERE ct.car_id=? ORDER BY ct.id DESC");
$transfers->execute([$id]); $transfers = $transfers->fetchAll();

$assessments = $db->prepare("SELECT ca.*, m.name AS mechanic_name FROM car_assessments ca LEFT JOIN mechanics m ON m.id=ca.mechanic_id WHERE ca.car_id=? ORDER BY ca.id DESC");
$assessments->execute([$id]); $assessments = $assessments->fetchAll();

$jobs = $db->prepare("SELECT j.*, m.name AS mechanic_name FROM workshop_jobs j LEFT JOIN mechanics m ON m.id=j.mechanic_id WHERE j.car_id=? ORDER BY j.id DESC");
$jobs->execute([$id]); $jobs = $jobs->fetchAll();

$quotations = $db->prepare("SELECT * FROM quotations WHERE car_id=? ORDER BY id DESC");
$quotations->execute([$id]); $quotations = $quotations->fetchAll();

$invoices = $db->prepare("SELECT * FROM invoices WHERE car_id=? ORDER BY id DESC");
$invoices->execute([$id]); $invoices = $invoices->fetchAll();

$images = $db->prepare("SELECT * FROM car_images WHERE car_id=? ORDER BY is_primary DESC, created_at DESC");
$images->execute([$id]); $images = $images->fetchAll();
$primaryImage = null;
foreach($images as $img) if($img['is_primary']) $primaryImage = $img;

$existingSale = $db->prepare("SELECT id, sale_number FROM car_sales WHERE car_id=? AND status='active' LIMIT 1");
$existingSale->execute([$id]); $existingSale = $existingSale->fetch();

$pageTitle = $car['make'] . ' ' . $car['model'];
include __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="mb-0"><?= e($car['make'].' '.$car['model']) ?> <code class="ms-2"><?= e($car['chassis_number']) ?></code></h5>
    <div class="d-flex gap-2 flex-wrap">
        <a href="media.php?id=<?= $id ?>" class="btn btn-sm btn-outline-primary"><i class="fa fa-camera me-1"></i>Photos (<?= count($images) ?>)</a>
        <?php if ($car['car_type'] === 'inventory' && canWrite('sales')): ?>
            <?php if ($existingSale): ?>
            <a href="<?= BASE_URL ?>/modules/sales/view.php?id=<?= $existingSale['id'] ?>" class="btn btn-sm btn-success">
                <i class="fa fa-tag me-1"></i>View Sale <span class="ms-1 opacity-75">(<?= e($existingSale['sale_number']) ?>)</span>
            </a>
            <?php elseif (in_array($car['status'], ['completed','arrived','in_workshop'])): ?>
            <a href="<?= BASE_URL ?>/modules/sales/add.php?car_id=<?= $id ?>" class="btn btn-sm btn-success">
                <i class="fa fa-tag me-1"></i>Record Sale
            </a>
            <?php endif; ?>
        <?php endif; ?>
        <?php if (canAccess('inspections')): ?>
        <a href="<?= BASE_URL ?>/modules/inspections/create.php?car_id=<?= $id ?>"
           class="btn btn-sm btn-outline-info">
            <i class="fa fa-clipboard-check me-1"></i>Inspect
        </a>
        <?php endif; ?>
        <?php if (canEditDelete()): ?>
        <a href="edit.php?id=<?= $id ?>" class="btn btn-sm btn-outline-secondary"><i class="fa fa-pen me-1"></i>Edit</a>
        <?php endif; ?>
        <a href="index.php" class="btn btn-sm btn-outline-secondary"><i class="fa fa-arrow-left me-1"></i>Back</a>
    </div>
</div>

<?php if ($primaryImage): ?>
<div class="card mb-4 overflow-hidden">
    <div class="row g-0">
        <div class="col-md-7">
            <img src="<?= BASE_URL ?>/uploads/cars/<?= e($primaryImage['file_path']) ?>" class="img-fluid w-100 h-100" style="object-fit:cover; max-height:400px;">
        </div>
        <div class="col-md-5 d-flex flex-column">
            <div class="card-body bg-light flex-grow-1">
                <h6 class="fw-bold mb-3">Vehicle Gallery</h6>
                <div class="row g-2">
                    <?php 
                    $thumbCount = 0;
                    foreach($images as $img): 
                        if($img['is_primary']) continue;
                        if($thumbCount >= 6) break;
                    ?>
                    <div class="col-4">
                        <img src="<?= BASE_URL ?>/uploads/cars/<?= e($img['file_path']) ?>" class="img-fluid rounded border shadow-sm" style="height:80px; width:100%; object-fit:cover;">
                    </div>
                    <?php $thumbCount++; endforeach; ?>
                    <?php if (count($images) > 7): ?>
                    <div class="col-4">
                        <a href="media.php?id=<?= $id ?>" class="d-flex align-items-center justify-content-center bg-white border rounded text-decoration-none text-primary fw-bold" style="height:80px">
                            +<?= count($images) - 7 ?>
                        </a>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php
$stageSteps = [
    ['label' => 'Port Intake',  'icon' => 'fa-anchor',           'statuses' => ['arrived']],
    ['label' => 'Transport',    'icon' => 'fa-truck-moving',      'statuses' => ['in_transit']],
    ['label' => 'Assessment',   'icon' => 'fa-clipboard-check',   'statuses' => []],
    ['label' => 'Workshop',     'icon' => 'fa-toolbox',           'statuses' => ['in_workshop']],
    ['label' => 'Completed',    'icon' => 'fa-circle-check',      'statuses' => ['completed']],
    ['label' => 'Delivered',    'icon' => 'fa-flag-checkered',    'statuses' => ['delivered','sold']],
];
$activeStep = 0;
if ($intake)            $activeStep = 1;
if (!empty($transfers)) $activeStep = 2;
if (!empty($assessments)) $activeStep = 3;
if (!empty($jobs))      $activeStep = 4;
if (in_array($car['status'], ['completed']))            $activeStep = 5;
if (in_array($car['status'], ['delivered','sold']))     $activeStep = 6;
?>
<div class="card mb-4">
    <div class="card-body py-3">
        <div class="d-flex align-items-center justify-content-between" style="overflow-x:auto">
        <?php foreach ($stageSteps as $i => $step):
            $stepNum  = $i + 1;
            $isDone   = $stepNum < $activeStep;
            $isActive = $stepNum === $activeStep;
        ?>
            <div class="text-center flex-fill" style="min-width:80px">
                <div class="mx-auto rounded-circle d-flex align-items-center justify-content-center mb-1
                    <?= $isDone ? 'bg-success text-white' : ($isActive ? 'bg-primary text-white' : 'bg-light text-muted border') ?>"
                    style="width:40px;height:40px">
                    <i class="fa <?= $step['icon'] ?> fa-sm"></i>
                </div>
                <div class="small fw-<?= $isActive ? 'bold' : 'normal' ?> <?= $isDone ? 'text-success' : ($isActive ? 'text-primary' : 'text-muted') ?>">
                    <?= $step['label'] ?>
                </div>
            </div>
            <?php if ($i < count($stageSteps) - 1): ?>
            <div class="flex-fill" style="height:2px;background:<?= $isDone ? '#198754' : '#dee2e6' ?>;max-width:60px;min-width:20px;margin-bottom:18px"></div>
            <?php endif; ?>
        <?php endforeach; ?>
        </div>
    </div>
</div>

<div class="row g-4">
    <!-- Car Details -->
    <div class="col-lg-4">
        <div class="card">
            <div class="card-header"><i class="fa fa-car me-2"></i>Vehicle Details</div>
            <div class="card-body">
                <dl class="row mb-0" style="font-size:13.5px">
                    <dt class="col-5 text-muted">Vehicle Type</dt>
                    <dd class="col-7">
                        <?php if ($car['car_type'] === 'client'): ?>
                            <span class="badge bg-info text-dark">CLIENT VEHICLE</span>
                        <?php else: ?>
                            <span class="badge bg-primary">INVENTORY (STOCK)</span>
                        <?php endif; ?>
                    </dd>
                    <?php if ($car['car_type'] === 'client'): ?>
                    <dt class="col-5 text-muted">Owner</dt>
                    <dd class="col-7">
                        <div class="fw-bold"><?= e($car['owner_name']) ?></div>
                        <div class="small text-muted"><?= e(($car['owner_phone'] ?? '') ?: 'No Phone') ?></div>
                    </dd>
                    <?php endif; ?>
                    <dt class="col-5 text-muted">Current Yard</dt>
                    <dd class="col-7 fw-bold text-primary"><i class="fa fa-location-dot me-1"></i><?= e($car['location_name'] ?: '—') ?></dd>
                    <dt class="col-5 text-muted">Status</dt>
                    <dd class="col-7"><?= statusBadge($car['status']) ?></dd>
                    <dt class="col-5 text-muted">Chassis</dt>
                    <dd class="col-7"><code><?= e($car['chassis_number']) ?></code></dd>
                    <dt class="col-5 text-muted">Reg. No.</dt>
                    <dd class="col-7"><?= e($car['registration_number'] ?: '—') ?></dd>
                    <dt class="col-5 text-muted">Engine No.</dt>
                    <dd class="col-7"><?= e($car['engine_number'] ?: '—') ?></dd>
                    <dt class="col-5 text-muted">Make</dt>
                    <dd class="col-7"><?= e($car['make']) ?></dd>
                    <dt class="col-5 text-muted">Model</dt>
                    <dd class="col-7"><?= e($car['model']) ?></dd>
                    <dt class="col-5 text-muted">Year</dt>
                    <dd class="col-7"><?= e($car['year']) ?></dd>
                    <dt class="col-5 text-muted">Color</dt>
                    <dd class="col-7"><?= e($car['color'] ?: '—') ?></dd>
                    <dt class="col-5 text-muted">Body Type</dt>
                    <dd class="col-7"><?= e($car['body_type'] ?: '—') ?></dd>
                    <dt class="col-5 text-muted">Transmission</dt>
                    <dd class="col-7"><?= ucfirst($car['transmission'] ?: '—') ?></dd>
                    <dt class="col-5 text-muted">Fuel</dt>
                    <dd class="col-7"><?= ucfirst($car['fuel_type'] ?: '—') ?></dd>
                    <dt class="col-5 text-muted">Added</dt>
                    <dd class="col-7"><?= fmtDate($car['created_at']) ?></dd>
                </dl>
                <?php if ($car['notes']): ?>
                <hr><p class="small text-muted mb-0"><?= e($car['notes']) ?></p>
                <?php endif; ?>
            </div>
        </div>

        <!-- Quick links -->
        <?php 
        $hasAnyAction = canAccess('intake') || canAccess('assessments') || canAccess('jobs') || canAccess('quotations');
        if ($hasAnyAction): 
        ?>
        <div class="card mt-3">
            <div class="card-header"><i class="fa fa-bolt me-2"></i>Actions</div>
            <div class="card-body d-grid gap-2">
                <?php if (canAccess('intake')): ?>
                <a href="<?= BASE_URL ?>/modules/intake/add.php?car_id=<?= $id ?>" class="btn btn-sm btn-outline-primary"><i class="fa fa-anchor me-1"></i>Register Intake</a>
                <?php endif; ?>
                <?php if (canAccess('assessments')): ?>
                <a href="<?= BASE_URL ?>/modules/assessments/add.php?car_id=<?= $id ?>" class="btn btn-sm btn-outline-secondary"><i class="fa fa-clipboard-check me-1"></i>New Assessment</a>
                <?php endif; ?>
                <?php if (canAccess('jobs')): ?>
                <a href="<?= BASE_URL ?>/modules/jobs/add.php?car_id=<?= $id ?>" class="btn btn-sm btn-outline-dark"><i class="fa fa-toolbox me-1"></i>Create Job Card</a>
                <?php endif; ?>
                <?php if (canAccess('quotations')): ?>
                <a href="<?= BASE_URL ?>/modules/quotations/add.php?car_id=<?= $id ?>" class="btn btn-sm btn-outline-info"><i class="fa fa-file-lines me-1"></i>New Quotation</a>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <div class="col-lg-8">
        <!-- Timeline/History -->
        <div class="card mb-3">
            <div class="card-header"><i class="fa fa-route me-2"></i>Journey Timeline</div>
            <div class="card-body">
                <div class="timeline">
                    <?php if ($intake): ?>
                    <div class="timeline-item">
                        <div class="timeline-dot dot-success"></div>
                        <div class="fw-semibold">Arrived at Mombasa Port</div>
                        <div class="small text-muted"><?= fmtDate($intake['intake_date']) ?> — <?= e($intake['port']) ?></div>
                        <?php if ($intake['condition_notes']): ?><div class="small"><?= e($intake['condition_notes']) ?></div><?php endif; ?>
                    </div>
                    <?php endif; ?>

                    <?php foreach ($transfers as $t): ?>
                    <div class="timeline-item">
                        <div class="timeline-dot dot-<?= $t['status'] === 'arrived' ? 'success' : 'warning' ?>"></div>
                        <div class="fw-semibold">Transfer: <?= e($t['from_location']) ?> → <?= e($t['to_location']) ?></div>
                        <div class="small text-muted"><?= $t['transported_by'] ? 'Transported by: <strong>'.e($t['transported_by']).'</strong> | ' : '' ?><?= fmtDate($t['departure_date']) ?></div>
                        <div><?= statusBadge($t['status']) ?></div>
                    </div>
                    <?php endforeach; ?>

                    <?php foreach ($assessments as $a): ?>
                    <div class="timeline-item">
                        <div class="timeline-dot"></div>
                        <div class="fw-semibold"><?= ucwords(str_replace('_',' ',$a['assessment_type'])) ?> Assessment</div>
                        <div class="small text-muted"><?= fmtDate($a['assessment_date']) ?><?= $a['mechanic_name'] ? ' — ' . e($a['mechanic_name']) : '' ?></div>
                        <div><?= statusBadge($a['overall_status']) ?> <a href="<?= BASE_URL ?>/modules/assessments/view.php?id=<?= $a['id'] ?>" class="btn btn-xs btn-outline-secondary ms-2">View</a></div>
                    </div>
                    <?php endforeach; ?>

                    <?php foreach ($jobs as $j): ?>
                    <div class="timeline-item">
                        <div class="timeline-dot"></div>
                        <div class="fw-semibold">Workshop Job: <?= e($j['job_number']) ?></div>
                        <div class="small text-muted"><?= fmtDate($j['start_date']) ?><?= $j['mechanic_name'] ? ' — ' . e($j['mechanic_name']) : '' ?></div>
                        <div><?= statusBadge($j['status']) ?> <a href="<?= BASE_URL ?>/modules/jobs/view.php?id=<?= $j['id'] ?>" class="btn btn-xs btn-outline-secondary ms-2">View Job</a></div>
                    </div>
                    <?php endforeach; ?>

                    <?php if (empty($intake) && empty($transfers) && empty($assessments) && empty($jobs)): ?>
                    <p class="text-muted small">No history yet for this vehicle.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Quotations & Invoices -->
        <?php if ($quotations || $invoices): ?>
        <div class="row g-3">
            <?php if ($quotations): ?>
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header"><i class="fa fa-file-lines me-2"></i>Quotations</div>
                    <div class="list-group list-group-flush">
                        <?php foreach ($quotations as $q): ?>
                        <a href="<?= BASE_URL ?>/modules/quotations/view.php?id=<?= $q['id'] ?>" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center">
                            <span><?= e($q['quotation_number']) ?></span>
                            <span><?= statusBadge($q['status']) ?> <strong class="ms-2"><?= money($q['total']) ?></strong></span>
                        </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            <?php if ($invoices): ?>
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header"><i class="fa fa-file-invoice-dollar me-2"></i>Invoices</div>
                    <div class="list-group list-group-flush">
                        <?php foreach ($invoices as $inv): ?>
                        <a href="<?= BASE_URL ?>/modules/invoices/view.php?id=<?= $inv['id'] ?>" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center">
                            <span><?= e($inv['invoice_number']) ?></span>
                            <span><?= statusBadge($inv['status']) ?> <strong class="ms-2"><?= money($inv['total']) ?></strong></span>
                        </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php
// ── Car Import Costs ──────────────────────────────────────────────────────────
if (canAccess('car_costs')):
    try {
        $costRow = $db->prepare("SELECT * FROM car_costs WHERE car_id=?");
        $costRow->execute([$id]); $costRow = $costRow->fetch();
        $totalCost = $costRow
            ? array_sum(array_map(fn($f)=>(float)($costRow[$f]??0),
                ['purchase_price','freight','marine_insurance','port_charges','duty_tax',
                 'clearing_fees','transport_to_yard','workshop_costs','other_costs']))
            : null;
        $saleForCost = $db->prepare("SELECT sale_price FROM car_sales WHERE car_id=? AND status='active' LIMIT 1");
        $saleForCost->execute([$id]); $saleForCost = $saleForCost->fetch();
    } catch (\Throwable $e) { $costRow = null; $totalCost = null; $saleForCost = null; }
?>
<div class="card mt-4" id="costs">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span class="fw-semibold"><i class="fa fa-calculator me-2 text-primary"></i>Import Costs & Margin</span>
        <?php if (canWrite('car_costs')): ?>
        <a href="<?= BASE_URL ?>/modules/car_costs/edit.php?car_id=<?= $id ?>&back=<?= urlencode(BASE_URL.'/modules/cars/view.php?id='.$id.'#costs') ?>"
           class="btn btn-sm btn-outline-primary">
            <i class="fa fa-<?= $costRow ? 'pen' : 'plus' ?> me-1"></i><?= $costRow ? 'Edit Costs' : 'Record Costs' ?>
        </a>
        <?php endif; ?>
    </div>
    <?php if (!$costRow): ?>
    <div class="card-body text-center py-3 text-muted">
        <i class="fa fa-calculator fa-2x mb-2 d-block opacity-25"></i>No import costs recorded yet.
        <?php if (canWrite('car_costs')): ?>
        <div class="mt-2">
            <a href="<?= BASE_URL ?>/modules/car_costs/edit.php?car_id=<?= $id ?>&back=<?= urlencode(BASE_URL.'/modules/cars/view.php?id='.$id.'#costs') ?>"
               class="btn btn-sm btn-outline-primary">Record Import Costs</a>
        </div>
        <?php endif; ?>
    </div>
    <?php else:
        $salePrice = $saleForCost ? (float)$saleForCost['sale_price'] : null;
        $profit    = $salePrice !== null ? $salePrice - $totalCost : null;
        $margin    = $salePrice && $salePrice > 0 && $profit !== null ? round($profit / $salePrice * 100, 1) : null;
        $costItems = [
            'purchase_price'    => 'Purchase Price',
            'freight'           => 'Freight',
            'marine_insurance'  => 'Marine Insurance',
            'port_charges'      => 'Port Charges',
            'duty_tax'          => 'Duty & Taxes',
            'clearing_fees'     => 'Clearing Fees',
            'transport_to_yard' => 'Transport to Yard',
            'workshop_costs'    => 'Workshop Costs',
            'other_costs'       => 'Other Costs',
        ];
    ?>
    <div class="card-body p-0">
        <div class="row g-0">
            <div class="col-md-8">
                <table class="table table-sm mb-0" style="font-size:13px">
                    <tbody>
                    <?php foreach ($costItems as $field => $label):
                        $val = (float)($costRow[$field] ?? 0);
                        if ($val <= 0) continue;
                    ?>
                    <tr>
                        <td class="ps-3 text-muted"><?= $label ?></td>
                        <td class="text-end pe-3 fw-medium"><?= money($val) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <tr class="table-dark">
                        <td class="ps-3 fw-bold">Total Cost</td>
                        <td class="text-end pe-3 fw-bold"><?= money($totalCost) ?></td>
                    </tr>
                    </tbody>
                </table>
            </div>
            <div class="col-md-4 border-start d-flex flex-column justify-content-center align-items-center p-4 text-center">
                <?php if ($salePrice !== null): ?>
                <div class="text-muted small mb-1">Sale Price</div>
                <div class="fw-bold text-success fs-6 mb-2"><?= money($salePrice) ?></div>
                <div class="text-muted small mb-1">Gross Profit</div>
                <div class="fw-bold fs-5 <?= $profit >= 0 ? 'text-success' : 'text-danger' ?> mb-2"><?= money($profit) ?></div>
                <span class="badge fs-6 <?= $margin >= 20 ? 'bg-success' : ($margin >= 10 ? 'bg-warning text-dark' : 'bg-danger') ?>"><?= $margin ?>% margin</span>
                <?php else: ?>
                <div class="text-muted small"><i class="fa fa-tag me-1"></i>Profit will show once the car is sold.</div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php
// ── Car Documents ─────────────────────────────────────────────────────────────
if (canAccess('car_documents')):
    $docTypes = [
        'logbook'           => ['Logbook',                  'fa-book',             'primary'],
        'import_entry'      => ['Import Entry',              'fa-file-import',      'info'],
        'ntsa_inspection'   => ['NTSA Inspection',           'fa-clipboard-check',  'success'],
        'ntsa_registration' => ['NTSA Registration',         'fa-id-card',          'success'],
        'insurance'         => ['Insurance',                 'fa-shield-halved',    'warning'],
        'duty_clearance'    => ['Duty Clearance',            'fa-stamp',            'secondary'],
        'purchase_invoice'  => ['Purchase Invoice',          'fa-file-invoice',     'dark'],
        'other'             => ['Other',                     'fa-file',             'secondary'],
    ];
    try {
        $carDocs = $db->prepare("SELECT * FROM car_documents WHERE car_id=? ORDER BY created_at DESC");
        $carDocs->execute([$id]);
        $carDocs = $carDocs->fetchAll();
    } catch (\Throwable $e) { $carDocs = []; }
?>
<div class="card mt-4" id="documents">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span class="fw-semibold">
            <i class="fa fa-folder-open me-2 text-primary"></i>Documents
            <?php if ($carDocs): ?>
            <span class="badge bg-secondary ms-1"><?= count($carDocs) ?></span>
            <?php endif; ?>
        </span>
        <?php if (canWrite('car_documents')): ?>
        <a href="<?= BASE_URL ?>/modules/car_documents/upload.php?car_id=<?= $id ?>&back=<?= urlencode(BASE_URL . '/modules/cars/view.php?id=' . $id . '#documents') ?>"
           class="btn btn-sm btn-primary">
            <i class="fa fa-upload me-1"></i>Upload
        </a>
        <?php endif; ?>
    </div>
    <?php if (empty($carDocs)): ?>
    <div class="card-body text-center py-4 text-muted">
        <i class="fa fa-folder-open fa-2x mb-2 d-block opacity-25"></i>
        No documents uploaded yet.
        <?php if (canWrite('car_documents')): ?>
        <div class="mt-2">
            <a href="<?= BASE_URL ?>/modules/car_documents/upload.php?car_id=<?= $id ?>&back=<?= urlencode(BASE_URL . '/modules/cars/view.php?id=' . $id . '#documents') ?>"
               class="btn btn-sm btn-outline-primary">
                <i class="fa fa-upload me-1"></i>Upload first document
            </a>
        </div>
        <?php endif; ?>
    </div>
    <?php else: ?>
    <div class="list-group list-group-flush">
        <?php foreach ($carDocs as $doc):
            [$dtLabel, $dtIcon, $dtColor] = $docTypes[$doc['doc_type']] ?? ['Other','fa-file','secondary'];
            $expired  = $doc['expiry_date'] && $doc['expiry_date'] < date('Y-m-d');
            $expSoon  = !$expired && $doc['expiry_date'] && $doc['expiry_date'] <= date('Y-m-d', strtotime('+30 days'));
        ?>
        <div class="list-group-item d-flex align-items-center gap-3 px-3 py-2">
            <div class="flex-shrink-0 text-<?= $dtColor ?>" style="width:32px;text-align:center">
                <i class="fa <?= $dtIcon ?> fa-lg"></i>
            </div>
            <div class="flex-grow-1 min-w-0">
                <div class="fw-medium"><?= e($doc['title']) ?></div>
                <div class="d-flex gap-2 align-items-center mt-1 flex-wrap">
                    <span class="badge bg-<?= $dtColor ?>-subtle text-<?= $dtColor ?> border border-<?= $dtColor ?>-subtle"
                          style="font-size:10px"><?= $dtLabel ?></span>
                    <?php if ($doc['expiry_date']): ?>
                    <span class="badge bg-<?= $expired ? 'danger' : ($expSoon ? 'warning' : 'success') ?>"
                          style="font-size:10px">
                        <i class="fa fa-<?= $expired ? 'circle-xmark' : ($expSoon ? 'triangle-exclamation' : 'circle-check') ?> me-1"></i>
                        <?= $expired ? 'Expired ' : ($expSoon ? 'Expires ' : 'Valid until ') ?>
                        <?= fmtDate($doc['expiry_date'], 'd M Y') ?>
                    </span>
                    <?php endif; ?>
                    <span class="text-muted small"><?= fmtDate($doc['created_at'], 'd M Y') ?></span>
                </div>
            </div>
            <div class="d-flex gap-1 flex-shrink-0">
                <a href="<?= BASE_URL ?>/modules/car_documents/download.php?id=<?= $doc['id'] ?>&view=1"
                   class="btn btn-xs btn-outline-secondary" target="_blank" title="View">
                    <i class="fa fa-eye"></i>
                </a>
                <a href="<?= BASE_URL ?>/modules/car_documents/download.php?id=<?= $doc['id'] ?>"
                   class="btn btn-xs btn-outline-primary" title="Download">
                    <i class="fa fa-download"></i>
                </a>
                <?php if (canWrite('car_documents')): ?>
                <form method="POST" action="<?= BASE_URL ?>/modules/car_documents/delete.php"
                      class="d-inline"
                      onsubmit="return confirm('Delete this document?')">
                    <input type="hidden" name="id" value="<?= $doc['id'] ?>">
                    <input type="hidden" name="redirect"
                           value="<?= e(BASE_URL . '/modules/cars/view.php?id=' . $id . '#documents') ?>">
                    <button class="btn btn-xs btn-outline-danger" title="Delete">
                        <i class="fa fa-trash"></i>
                    </button>
                </form>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php include __DIR__ . '/../../includes/footer.php'; ?>

<?php
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
canWrite('inspections') || redirect(BASE_URL . '/index.php');

$pageTitle = 'New Inspection Checklist';
$db       = getDB();
$errors   = [];

$preCarId  = (int)($_GET['car_id']  ?? 0);
$preSaleId = (int)($_GET['sale_id'] ?? 0);

// Default checklist template — category => [items]
$TEMPLATE = [
    'Exterior' => [
        'Bodywork & panel condition (no dents/rust)',
        'Paint condition & colour match',
        'Windscreen (no chips or cracks)',
        'All side windows (condition & operation)',
        'Door seals & door operation',
        'Side mirrors (condition & adjustment)',
        'Headlights — low beam',
        'Headlights — high beam',
        'Tail lights & brake lights',
        'Indicators — front & rear',
        'Reversing lights',
        'Hazard lights',
        'Windscreen wipers & washers',
        'Front bumper condition',
        'Rear bumper condition',
        'Tyre — Front Left (tread & pressure)',
        'Tyre — Front Right (tread & pressure)',
        'Tyre — Rear Left (tread & pressure)',
        'Tyre — Rear Right (tread & pressure)',
        'Spare tyre & tools (jack, spanner)',
        'Wheel rims condition',
    ],
    'Interior' => [
        'Seats & upholstery condition',
        'Driver seatbelt operation',
        'All passenger seatbelts',
        'Dashboard & instrument cluster',
        'Warning lights (no faults on dash)',
        'Air conditioning operation',
        'Heater operation',
        'Radio / entertainment system',
        'Horn',
        'Central locking (all doors)',
        'Power windows (all)',
        'Interior lighting',
        'Boot / trunk condition & latch',
        'Carpets & floor mats',
        'Fuel level (minimum quarter tank)',
    ],
    'Engine & Mechanicals' => [
        'Engine oil level',
        'Coolant level',
        'Brake fluid level',
        'Power steering fluid level',
        'Windscreen washer fluid',
        'Battery condition & terminals',
        'Engine bay — clean, no leaks',
        'No oil leaks underneath',
        'No coolant leaks',
        'Brakes — front (condition & response)',
        'Brakes — rear (condition & response)',
        'Handbrake / parking brake',
        'Steering — no pulling or vibration',
        'Transmission / gearbox smooth',
        'Exhaust — no excessive smoke',
    ],
    'Documents' => [
        'Logbook present & matches chassis',
        'Insurance certificate',
        'NTSA inspection certificate',
        'Import entry / duty clearance (if applicable)',
        'Service history records',
        'Radio licence (if applicable)',
    ],
    'Road Test' => [
        'Engine starts and idles smoothly',
        'Acceleration (no hesitation)',
        'All gears engage smoothly',
        'Braking — straight and responsive',
        'Steering — no play or unusual noise',
        'Air conditioning cools properly',
        'No unusual engine noises',
        'No vibration at speed',
    ],
];

$cars  = $db->query("SELECT id, make, model, year, chassis_number, registration_number FROM cars ORDER BY make, model")->fetchAll();
$sales = $db->query("
    SELECT cs.id, cs.sale_number, cs.buyer_name, c.make, c.model, c.year, c.id AS car_id
    FROM car_sales cs JOIN cars c ON c.id = cs.car_id
    WHERE cs.status='active' ORDER BY cs.sale_date DESC
")->fetchAll();
$users = $db->query("SELECT id, name, role FROM users WHERE status='active' ORDER BY name")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $carId   = (int)($_POST['car_id']         ?? 0);
    $saleId  = (int)($_POST['sale_id']        ?? 0) ?: null;
    $type    = $_POST['checklist_type']        ?? 'pre_delivery';
    $inspId  = (int)($_POST['inspector_id']   ?? 0) ?: null;
    $notes   = trim($_POST['overall_notes']    ?? '');

    if (!$carId) $errors[] = 'Please select a vehicle.';

    if (empty($errors)) {
        try {
            $db->beginTransaction();

            $db->prepare("INSERT INTO inspection_checklists
                (car_id, sale_id, checklist_type, status, inspector_id, overall_notes)
                VALUES (?,?,?,'draft',?,?)")
               ->execute([$carId, $saleId, $type, $inspId, $notes ?: null]);
            $clId = (int)$db->lastInsertId();

            // Insert default items
            $ins   = $db->prepare("INSERT INTO inspection_items (checklist_id, category, item, result, sort_order) VALUES (?,?,?,'pending',?)");
            $order = 0;
            foreach ($TEMPLATE as $cat => $items) {
                foreach ($items as $item) {
                    $ins->execute([$clId, $cat, $item, $order++]);
                }
            }

            $db->commit();
            logActivity('create','inspections',$clId,"New checklist for car #{$carId}");
            setFlash('success','Checklist created. Fill it in below.');
            redirect(BASE_URL . '/modules/inspections/view.php?id=' . $clId);
        } catch (\Throwable $e) {
            $db->rollBack();
            $errors[] = 'Failed: ' . $e->getMessage();
        }
    }
}

include __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="mb-0"><i class="fa fa-clipboard-list me-2 text-primary"></i>New Inspection Checklist</h5>
    <a href="index.php" class="btn btn-sm btn-outline-secondary"><i class="fa fa-arrow-left me-1"></i>Back</a>
</div>

<?php if ($errors): ?>
<div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $e) echo '<li>'.e($e).'</li>'; ?></ul></div>
<?php endif; ?>

<div class="row g-4">
    <div class="col-lg-6">
        <div class="card">
            <div class="card-header fw-semibold"><i class="fa fa-car me-2"></i>Checklist Setup</div>
            <div class="card-body">
                <form method="POST">
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label fw-semibold">Vehicle <span class="text-danger">*</span></label>
                            <select name="car_id" class="form-select select2" required id="carSelect">
                                <option value="">— Select vehicle —</option>
                                <?php foreach ($cars as $c): ?>
                                <option value="<?= $c['id'] ?>" <?= $preCarId==$c['id']?'selected':'' ?>>
                                    <?= e($c['make'].' '.$c['model'].' '.$c['year']) ?> — <code><?= e($c['chassis_number']) ?></code>
                                    <?= $c['registration_number'] ? ' ('.e($c['registration_number']).')' : '' ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold">Checklist Type</label>
                            <select name="checklist_type" class="form-select">
                                <option value="pre_delivery">Pre-Delivery (before handing to buyer)</option>
                                <option value="incoming">Incoming Inspection (on arrival at yard)</option>
                                <option value="pre_sale">Pre-Sale Inspection (before listing for sale)</option>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold">Link to Sale <span class="text-muted">(optional)</span></label>
                            <select name="sale_id" class="form-select select2">
                                <option value="">— Not linked to a sale —</option>
                                <?php foreach ($sales as $s): ?>
                                <option value="<?= $s['id'] ?>" <?= $preSaleId==$s['id']?'selected':'' ?>>
                                    <?= e($s['sale_number'].' — '.$s['buyer_name'].' — '.$s['make'].' '.$s['model'].' '.$s['year']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold">Inspector</label>
                            <select name="inspector_id" class="form-select select2">
                                <option value="">— Unassigned —</option>
                                <?php foreach ($users as $u): ?>
                                <option value="<?= $u['id'] ?>"><?= e($u['name']) ?> (<?= ucwords(str_replace('_',' ',$u['role'])) ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label fw-semibold">General Notes</label>
                            <textarea name="overall_notes" class="form-control" rows="2"
                                      placeholder="Any notes before inspection begins…"></textarea>
                        </div>
                        <div class="col-12">
                            <button type="submit" class="btn btn-primary w-100">
                                <i class="fa fa-clipboard-check me-1"></i>Create Checklist &amp; Start Inspection
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="card">
            <div class="card-header fw-semibold"><i class="fa fa-list-check me-2 text-primary"></i>Checklist Preview</div>
            <div class="card-body p-0" style="max-height:440px;overflow-y:auto">
                <?php foreach ($TEMPLATE as $cat => $items): ?>
                <div class="px-3 py-2 bg-light border-bottom fw-semibold" style="font-size:12px;text-transform:uppercase;letter-spacing:.05em;color:#475569">
                    <?= $cat ?> <span class="badge bg-secondary ms-1"><?= count($items) ?></span>
                </div>
                <?php foreach ($items as $item): ?>
                <div class="px-3 py-1 border-bottom d-flex align-items-center gap-2" style="font-size:12.5px">
                    <i class="fa fa-circle text-muted" style="font-size:6px;flex-shrink:0"></i>
                    <?= e($item) ?>
                </div>
                <?php endforeach; ?>
                <?php endforeach; ?>
            </div>
            <div class="card-footer text-muted small">
                <?= array_sum(array_map('count',$TEMPLATE)) ?> inspection items across <?= count($TEMPLATE) ?> categories will be created.
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>

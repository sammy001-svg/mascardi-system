<?php
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
canWrite('car_costs') || redirect(BASE_URL . '/index.php');

$pageTitle = 'Car Import Costs';
$db     = getDB();
$carId  = (int)($_GET['car_id'] ?? 0);
$back   = $_GET['back'] ?? BASE_URL . '/modules/car_costs/index.php';
if (!$carId) redirect(BASE_URL . '/modules/car_costs/index.php');

$car = $db->prepare("SELECT id, make, model, year, chassis_number FROM cars WHERE id=?");
$car->execute([$carId]); $car = $car->fetch();
if (!$car) { setFlash('error','Car not found.'); redirect(BASE_URL.'/modules/car_costs/index.php'); }

// Load existing
$existing = $db->prepare("SELECT * FROM car_costs WHERE car_id=?");
$existing->execute([$carId]); $existing = $existing->fetch();

$fields = ['purchase_price','freight','marine_insurance','port_charges','duty_tax',
           'clearing_fees','transport_to_yard','workshop_costs','other_costs'];

$d = [];
foreach ($fields as $f) $d[$f] = $existing[$f] ?? '0';
$d['other_notes'] = $existing['other_notes'] ?? '';
$d['currency']    = $existing['currency']    ?? 'KES';
$d['notes']       = $existing['notes']       ?? '';

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($fields as $f) $d[$f] = max(0, (float)($_POST[$f] ?? 0));
    $d['other_notes'] = trim($_POST['other_notes'] ?? '');
    $d['currency']    = $_POST['currency'] ?? 'KES';
    $d['notes']       = trim($_POST['notes'] ?? '');
    $back             = $_POST['back'] ?? $back;

    try {
        if ($existing) {
            $db->prepare("UPDATE car_costs SET
                purchase_price=?, freight=?, marine_insurance=?, port_charges=?,
                duty_tax=?, clearing_fees=?, transport_to_yard=?, workshop_costs=?,
                other_costs=?, other_notes=?, currency=?, notes=?, recorded_by=?, updated_at=NOW()
                WHERE car_id=?")
               ->execute([
                   $d['purchase_price'], $d['freight'], $d['marine_insurance'], $d['port_charges'],
                   $d['duty_tax'], $d['clearing_fees'], $d['transport_to_yard'], $d['workshop_costs'],
                   $d['other_costs'], $d['other_notes'], $d['currency'], $d['notes'],
                   authUser()['id'], $carId
               ]);
        } else {
            $db->prepare("INSERT INTO car_costs
                (car_id, purchase_price, freight, marine_insurance, port_charges,
                 duty_tax, clearing_fees, transport_to_yard, workshop_costs,
                 other_costs, other_notes, currency, notes, recorded_by)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
               ->execute([
                   $carId,
                   $d['purchase_price'], $d['freight'], $d['marine_insurance'], $d['port_charges'],
                   $d['duty_tax'], $d['clearing_fees'], $d['transport_to_yard'], $d['workshop_costs'],
                   $d['other_costs'], $d['other_notes'], $d['currency'], $d['notes'],
                   authUser()['id']
               ]);
        }
        logActivity('update','car_costs',$carId,"Updated import costs for car #{$carId}");
        setFlash('success','Import costs saved.');
        redirect(str_starts_with($back, BASE_URL) ? $back : BASE_URL.'/modules/car_costs/index.php');
    } catch (\Throwable $e) {
        $errors[] = 'Save failed: ' . $e->getMessage();
    }
}

$total = array_sum(array_map(fn($f) => (float)$d[$f], $fields));

$costRows = [
    ['purchase_price',   'Purchase Price',       'Buying price of the vehicle (ex-Japan/UK)'],
    ['freight',          'Freight / Shipping',    'Sea freight from origin port'],
    ['marine_insurance', 'Marine Insurance',      'Cargo insurance for transit'],
    ['port_charges',     'Port Charges',          'KPA handling & storage at Mombasa port'],
    ['duty_tax',         'Duty & Taxes',          'Import duty + VAT + other government levies'],
    ['clearing_fees',    'Clearing Fees',         'Clearing agent / customs broker fees'],
    ['transport_to_yard','Transport to Yard',     'Road transport from Mombasa to yard'],
    ['workshop_costs',   'Workshop / Repair Costs','Pre-sale repair & preparation costs'],
    ['other_costs',      'Other Costs',           'Any other costs not listed above'],
];

include __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h5 class="mb-1"><i class="fa fa-calculator me-2 text-primary"></i>Import Costs — <?= e($car['make'].' '.$car['model'].' '.$car['year']) ?></h5>
        <div class="text-muted small"><code><?= e($car['chassis_number']) ?></code></div>
    </div>
    <a href="<?= e($back) ?>" class="btn btn-sm btn-outline-secondary"><i class="fa fa-arrow-left me-1"></i>Back</a>
</div>

<?php if ($errors): ?>
<div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $e) echo '<li>'.e($e).'</li>'; ?></ul></div>
<?php endif; ?>

<form method="POST" id="costForm">
    <input type="hidden" name="back" value="<?= e($back) ?>">

    <div class="row g-4">
        <div class="col-lg-8">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span class="fw-semibold"><i class="fa fa-list-check me-2"></i>Cost Breakdown</span>
                    <div class="d-flex align-items-center gap-2">
                        <label class="form-label mb-0 small text-muted">Currency:</label>
                        <select name="currency" class="form-select form-select-sm" style="width:80px">
                            <option value="KES" <?= $d['currency']==='KES'?'selected':'' ?>>KES</option>
                            <option value="USD" <?= $d['currency']==='USD'?'selected':'' ?>>USD</option>
                            <option value="JPY" <?= $d['currency']==='JPY'?'selected':'' ?>>JPY</option>
                        </select>
                    </div>
                </div>
                <div class="card-body p-0">
                    <table class="table mb-0">
                        <thead class="table-light">
                            <tr>
                                <th class="ps-3" style="width:220px">Cost Item</th>
                                <th class="text-end" style="width:180px">Amount</th>
                                <th class="text-muted" style="font-size:12px;font-weight:400">Notes</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($costRows as [$field, $label, $hint]): ?>
                        <tr>
                            <td class="ps-3 fw-medium" style="font-size:13.5px"><?= $label ?></td>
                            <td class="text-end">
                                <input type="number" name="<?= $field ?>" step="0.01" min="0"
                                       class="form-control form-control-sm text-end cost-input"
                                       style="width:160px;margin-left:auto"
                                       value="<?= number_format((float)$d[$field], 2, '.', '') ?>"
                                       oninput="calcTotal()">
                            </td>
                            <td class="text-muted small"><?= $hint ?>
                                <?php if ($field === 'other_costs'): ?>
                                <input type="text" name="other_notes" class="form-control form-control-sm mt-1"
                                       placeholder="Describe…" value="<?= e($d['other_notes']) ?>">
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <tr class="table-dark">
                            <td class="ps-3 fw-bold">TOTAL COST</td>
                            <td class="text-end fw-bold fs-6" id="totalDisplay"><?= money($total) ?></td>
                            <td></td>
                        </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="card mt-3">
                <div class="card-body">
                    <label class="form-label fw-semibold">General Notes</label>
                    <textarea name="notes" class="form-control" rows="2"
                              placeholder="e.g. USD amounts converted at rate of 130…"><?= e($d['notes']) ?></textarea>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card sticky-top" style="top:80px">
                <div class="card-header fw-semibold"><i class="fa fa-chart-pie me-2"></i>Cost Summary</div>
                <div class="card-body" id="summaryPanel">
                    <?php
                    $totalCost = $total;
                    $saleStmt  = $db->prepare("SELECT sale_price FROM car_sales WHERE car_id=? AND status='active' LIMIT 1");
                    $saleStmt->execute([$carId]); $saleRow = $saleStmt->fetch();
                    $salePrice = $saleRow ? (float)$saleRow['sale_price'] : null;
                    $profit    = $salePrice !== null ? $salePrice - $totalCost : null;
                    $margin    = $salePrice && $salePrice > 0 && $profit !== null ? round($profit / $salePrice * 100, 1) : null;
                    ?>
                    <dl class="row mb-0" style="font-size:13.5px">
                        <dt class="col-7 text-muted">Total Cost</dt>
                        <dd class="col-5 fw-bold text-end" id="totalSide"><?= money($totalCost) ?></dd>
                        <?php if ($salePrice !== null): ?>
                        <dt class="col-7 text-muted">Sale Price</dt>
                        <dd class="col-5 text-end text-success fw-semibold"><?= money($salePrice) ?></dd>
                        <dt class="col-7 text-muted">Gross Profit</dt>
                        <dd class="col-5 text-end fw-bold <?= $profit >= 0 ? 'text-success' : 'text-danger' ?>"><?= money($profit) ?></dd>
                        <dt class="col-7 text-muted">Margin</dt>
                        <dd class="col-5 text-end">
                            <span class="badge <?= $margin >= 20 ? 'bg-success' : ($margin >= 10 ? 'bg-warning text-dark' : 'bg-danger') ?>">
                                <?= $margin ?>%
                            </span>
                        </dd>
                        <?php else: ?>
                        <dt class="col-12 text-muted small mt-2">No sale recorded yet — profit will appear once the car is sold.</dt>
                        <?php endif; ?>
                    </dl>
                </div>
                <div class="card-footer">
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="fa fa-save me-1"></i>Save Cost Breakdown
                    </button>
                </div>
            </div>
        </div>
    </div>
</form>

<script>
function calcTotal() {
    var inputs = document.querySelectorAll('.cost-input');
    var total  = 0;
    inputs.forEach(function(i){ total += parseFloat(i.value) || 0; });
    var fmt = total.toLocaleString('en-KE', {minimumFractionDigits:2, maximumFractionDigits:2});
    document.getElementById('totalDisplay').textContent = 'KES ' + fmt;
    document.getElementById('totalSide').textContent    = 'KES ' + fmt;
}
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>

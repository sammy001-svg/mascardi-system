<?php
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
requireWrite('imports');
$db   = getDB();
$user = authUser();

$suppliers = $db->query("SELECT id,name FROM suppliers ORDER BY name")->fetchAll();
$shipments = [];
try {
    $shipments = $db->query("SELECT id,ref,vessel_name,origin_country FROM car_shipments WHERE status NOT IN ('closed') ORDER BY created_at DESC")->fetchAll();
} catch(\Throwable $e) {}
$locations = $db->query("SELECT id,name FROM locations ORDER BY name")->fetchAll();

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $make         = trim($_POST['make']            ?? '');
    $model        = trim($_POST['model']           ?? '');
    $year         = (int)($_POST['year']           ?? 0);
    $color        = trim($_POST['color']           ?? '');
    $chassis      = trim($_POST['chassis_number']  ?? '');
    $engine       = trim($_POST['engine_number']   ?? '');
    $bodyType     = trim($_POST['body_type']        ?? '');
    $trans        = $_POST['transmission']         ?? 'automatic';
    $fuel         = $_POST['fuel_type']            ?? 'petrol';
    $engineCc     = ($_POST['engine_cc']    ?? '') !== '' ? (int)$_POST['engine_cc']    : null;
    $mileage      = ($_POST['mileage']      ?? '') !== '' ? (int)$_POST['mileage']      : null;
    $supplierId   = ($_POST['supplier_id']  ?? '') !== '' ? (int)$_POST['supplier_id']  : null;
    $supplierName = trim($_POST['supplier_name']   ?? '');
    $auctionRef   = trim($_POST['auction_ref']     ?? '');
    $currency     = $_POST['purchase_currency']    ?? 'JPY';
    $purchasePrice= ($_POST['purchase_price'] ?? '') !== '' ? (float)$_POST['purchase_price'] : null;
    $exchangeRate = ($_POST['exchange_rate']  ?? '') !== '' ? (float)$_POST['exchange_rate']  : 1.0;
    $purchaseDate = $_POST['purchase_date']        ?? null;
    $shipmentId   = ($_POST['shipment_id']   ?? '') !== '' ? (int)$_POST['shipment_id'] : null;
    $idfNumber    = trim($_POST['idf_number']      ?? '');
    $idfDate      = ($_POST['idf_date'] ?? '') ?: null;
    $notes        = trim($_POST['notes']           ?? '');

    $purchasePriceKes = ($purchasePrice && $exchangeRate) ? round($purchasePrice * $exchangeRate, 2) : null;
    if ($supplierId) {
        $row = $db->prepare("SELECT name FROM suppliers WHERE id=?"); $row->execute([$supplierId]); $row=$row->fetch();
        if ($row) $supplierName = $row['name'];
    }

    if (!$make)  $errors[] = 'Make is required.';
    if (!$model) $errors[] = 'Model is required.';
    if (!$year)  $errors[] = 'Year is required.';

    if (empty($errors)) {
        $ref = nextNumber('car_imports','ref','IMP-');
        $db->prepare("INSERT INTO car_imports
            (ref,shipment_id,make,model,year,color,chassis_number,engine_number,body_type,transmission,fuel_type,engine_cc,mileage,
             supplier_id,supplier_name,auction_ref,purchase_currency,purchase_price,exchange_rate,purchase_price_kes,
             purchase_date,idf_number,idf_date,stage,purchased_at,notes,created_by)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,'purchased',?,?,?)")
        ->execute([$ref,$shipmentId,$make,$model,$year,$color,$chassis,$engine,$bodyType,$trans,$fuel,$engineCc,$mileage,
                   $supplierId,$supplierName,$auctionRef,$currency,$purchasePrice,$exchangeRate,$purchasePriceKes,
                   $purchaseDate,$idfNumber,$idfDate,$purchaseDate,$notes,$user['id']]);
        $id = $db->lastInsertId();
        logActivity('create','imports',$id,"New import $ref: $make $model");
        setFlash('success',"Import $ref created.");
        redirect(BASE_URL.'/modules/imports/view.php?id='.$id);
    }
}

$pageTitle = 'New Import';
include __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="mb-0"><i class="fa fa-plus me-2 text-primary"></i>New Import</h5>
    <a href="index.php" class="btn btn-sm btn-outline-secondary"><i class="fa fa-arrow-left me-1"></i>Back</a>
</div>

<?php foreach($errors as $e): ?>
<div class="alert alert-danger py-2"><?= e($e) ?></div>
<?php endforeach; ?>

<form method="POST" class="row g-4">
    <!-- Vehicle Details -->
    <div class="col-lg-7">
        <div class="card mb-4">
            <div class="card-header fw-semibold"><i class="fa fa-car me-2 text-primary"></i>Vehicle Details</div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-sm-6">
                        <label class="form-label small">Make <span class="text-danger">*</span></label>
                        <input type="text" name="make" class="form-control form-control-sm" value="<?= e($_POST['make']??'') ?>" required placeholder="e.g. Toyota">
                    </div>
                    <div class="col-sm-6">
                        <label class="form-label small">Model <span class="text-danger">*</span></label>
                        <input type="text" name="model" class="form-control form-control-sm" value="<?= e($_POST['model']??'') ?>" required placeholder="e.g. Land Cruiser">
                    </div>
                    <div class="col-sm-4">
                        <label class="form-label small">Year <span class="text-danger">*</span></label>
                        <input type="number" name="year" class="form-control form-control-sm" value="<?= e($_POST['year']??date('Y')) ?>" min="1990" max="<?= date('Y')+1 ?>" required>
                    </div>
                    <div class="col-sm-4">
                        <label class="form-label small">Color</label>
                        <input type="text" name="color" class="form-control form-control-sm" value="<?= e($_POST['color']??'') ?>" placeholder="e.g. White">
                    </div>
                    <div class="col-sm-4">
                        <label class="form-label small">Body Type</label>
                        <select name="body_type" class="form-select form-select-sm">
                            <option value="">— Select —</option>
                            <?php foreach(['SUV','Saloon','Pick-Up','Van','Truck','Hatchback','Coupe','Bus','Minibus','Other'] as $bt): ?>
                            <option value="<?= $bt ?>" <?= ($_POST['body_type']??'')===$bt?'selected':'' ?>><?= $bt ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-sm-6">
                        <label class="form-label small">Chassis Number</label>
                        <input type="text" name="chassis_number" class="form-control form-control-sm font-monospace" value="<?= e($_POST['chassis_number']??'') ?>" placeholder="VIN / Chassis No.">
                    </div>
                    <div class="col-sm-6">
                        <label class="form-label small">Engine Number</label>
                        <input type="text" name="engine_number" class="form-control form-control-sm font-monospace" value="<?= e($_POST['engine_number']??'') ?>">
                    </div>
                    <div class="col-sm-4">
                        <label class="form-label small">Transmission</label>
                        <select name="transmission" class="form-select form-select-sm">
                            <option value="automatic" <?= ($_POST['transmission']??'automatic')==='automatic'?'selected':'' ?>>Automatic</option>
                            <option value="manual"    <?= ($_POST['transmission']??'')==='manual'?'selected':'' ?>>Manual</option>
                        </select>
                    </div>
                    <div class="col-sm-4">
                        <label class="form-label small">Fuel Type</label>
                        <select name="fuel_type" class="form-select form-select-sm">
                            <?php foreach(['petrol','diesel','hybrid','electric'] as $ft): ?>
                            <option value="<?= $ft ?>" <?= ($_POST['fuel_type']??'petrol')===$ft?'selected':'' ?>><?= ucfirst($ft) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-sm-4">
                        <label class="form-label small">Engine CC</label>
                        <input type="number" name="engine_cc" class="form-control form-control-sm" value="<?= e($_POST['engine_cc']??'') ?>" placeholder="e.g. 3000">
                    </div>
                    <div class="col-sm-6">
                        <label class="form-label small">Mileage (km)</label>
                        <input type="number" name="mileage" class="form-control form-control-sm" value="<?= e($_POST['mileage']??'') ?>" placeholder="e.g. 45000">
                    </div>
                </div>
            </div>
        </div>

        <!-- Purchase Details -->
        <div class="card">
            <div class="card-header fw-semibold"><i class="fa fa-file-invoice-dollar me-2 text-success"></i>Purchase Details</div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-12">
                        <label class="form-label small">Supplier</label>
                        <select name="supplier_id" class="form-select form-select-sm select2">
                            <option value="">— Select or type below —</option>
                            <?php foreach($suppliers as $sp): ?>
                            <option value="<?= $sp['id'] ?>" <?= ($_POST['supplier_id']??'')==$sp['id']?'selected':'' ?>><?= e($sp['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12">
                        <label class="form-label small">Supplier Name <small class="text-muted">(if not in list)</small></label>
                        <input type="text" name="supplier_name" class="form-control form-control-sm" value="<?= e($_POST['supplier_name']??'') ?>" placeholder="Auction house or dealer name">
                    </div>
                    <div class="col-sm-6">
                        <label class="form-label small">Auction / Lot Reference</label>
                        <input type="text" name="auction_ref" class="form-control form-control-sm" value="<?= e($_POST['auction_ref']??'') ?>" placeholder="e.g. USS-12345">
                    </div>
                    <div class="col-sm-6">
                        <label class="form-label small">Purchase Date</label>
                        <input type="date" name="purchase_date" class="form-control form-control-sm" value="<?= e($_POST['purchase_date']??date('Y-m-d')) ?>">
                    </div>
                    <div class="col-sm-4">
                        <label class="form-label small">Currency</label>
                        <select name="purchase_currency" class="form-select form-select-sm" id="currencySelect">
                            <?php foreach(['JPY'=>'Japanese Yen (¥)','USD'=>'US Dollar ($)','GBP'=>'British Pound (£)','AED'=>'UAE Dirham','EUR'=>'Euro (€)','KES'=>'Kenyan Shilling'] as $code=>$lbl): ?>
                            <option value="<?= $code ?>" <?= ($_POST['purchase_currency']??'JPY')===$code?'selected':'' ?>><?= $code ?> — <?= $lbl ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-sm-4">
                        <label class="form-label small">Purchase Price</label>
                        <input type="number" name="purchase_price" id="purchasePrice" class="form-control form-control-sm" value="<?= e($_POST['purchase_price']??'') ?>" step="0.01" placeholder="0.00">
                    </div>
                    <div class="col-sm-4">
                        <label class="form-label small">Exchange Rate → KES</label>
                        <input type="number" name="exchange_rate" id="exchangeRate" class="form-control form-control-sm" value="<?= e($_POST['exchange_rate']??'0.65') ?>" step="0.0001" placeholder="1.0">
                    </div>
                    <div class="col-12">
                        <div class="alert alert-info py-2 mb-0 small">
                            <strong>Landed value (KES):</strong>
                            <span id="kesPreview">—</span>
                            <span class="text-muted ms-2">= price × exchange rate (before additional import costs)</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Right column -->
    <div class="col-lg-5">
        <!-- Shipment -->
        <div class="card mb-4">
            <div class="card-header fw-semibold"><i class="fa fa-boxes-stacked me-2 text-secondary"></i>Shipment / Consignment</div>
            <div class="card-body">
                <label class="form-label small">Link to Shipment <small class="text-muted">(optional)</small></label>
                <select name="shipment_id" class="form-select form-select-sm select2">
                    <option value="">— No shipment —</option>
                    <?php foreach($shipments as $sh): ?>
                    <option value="<?= $sh['id'] ?>" <?= ($_POST['shipment_id']??'')==$sh['id']?'selected':'' ?>>
                        <?= e($sh['ref']) ?> — <?= e($sh['vessel_name'] ?? $sh['origin_country']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                <div class="mt-2">
                    <a href="shipment_add.php" class="small text-muted"><i class="fa fa-plus me-1"></i>Create new shipment</a>
                </div>
            </div>
        </div>

        <!-- IDF / Customs -->
        <div class="card mb-4">
            <div class="card-header fw-semibold"><i class="fa fa-stamp me-2 text-warning"></i>Customs / IDF</div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-sm-6">
                        <label class="form-label small">IDF Number</label>
                        <input type="text" name="idf_number" class="form-control form-control-sm" value="<?= e($_POST['idf_number']??'') ?>" placeholder="IDF-…">
                    </div>
                    <div class="col-sm-6">
                        <label class="form-label small">IDF Date</label>
                        <input type="date" name="idf_date" class="form-control form-control-sm" value="<?= e($_POST['idf_date']??'') ?>">
                    </div>
                </div>
            </div>
        </div>

        <!-- Notes -->
        <div class="card mb-4">
            <div class="card-header fw-semibold"><i class="fa fa-note-sticky me-2"></i>Notes</div>
            <div class="card-body">
                <textarea name="notes" class="form-control form-control-sm" rows="4" placeholder="Any additional notes about this import…"><?= e($_POST['notes']??'') ?></textarea>
            </div>
        </div>

        <div class="d-grid gap-2">
            <button type="submit" class="btn btn-primary"><i class="fa fa-save me-2"></i>Create Import</button>
            <a href="index.php" class="btn btn-outline-secondary">Cancel</a>
        </div>
    </div>
</form>

<script>
function updateKes() {
    const price = parseFloat(document.getElementById('purchasePrice').value) || 0;
    const rate  = parseFloat(document.getElementById('exchangeRate').value)  || 1;
    const kes   = price * rate;
    document.getElementById('kesPreview').textContent = kes > 0
        ? 'KES ' + kes.toLocaleString('en-KE', {minimumFractionDigits:2, maximumFractionDigits:2})
        : '—';
}
// Default exchange rates
const defaultRates = { JPY: 0.65, USD: 130, GBP: 165, AED: 35.4, EUR: 142, KES: 1 };
document.getElementById('currencySelect').addEventListener('change', function() {
    const rate = defaultRates[this.value] || 1;
    document.getElementById('exchangeRate').value = rate;
    updateKes();
});
document.getElementById('purchasePrice').addEventListener('input', updateKes);
document.getElementById('exchangeRate').addEventListener('input', updateKes);
updateKes();
</script>
<?php include __DIR__ . '/../../includes/footer.php'; ?>

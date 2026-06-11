<?php
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
requireWrite('imports');
$db   = getDB();
$user = authUser();
$id   = (int)($_GET['id'] ?? 0);

$imp = $db->prepare("SELECT * FROM car_imports WHERE id=?");
$imp->execute([$id]); $imp = $imp->fetch();
if (!$imp) { setFlash('error','Import not found.'); redirect(BASE_URL.'/modules/imports/index.php'); }

$suppliers = $db->query("SELECT id,name FROM suppliers ORDER BY name")->fetchAll();
$shipments = [];
try { $shipments = $db->query("SELECT id,ref,vessel_name FROM car_shipments WHERE status NOT IN ('closed') ORDER BY created_at DESC")->fetchAll(); } catch(\Throwable $e) {}

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
        if($row) $supplierName = $row['name'];
    }

    if (!$make)  $errors[] = 'Make is required.';
    if (!$model) $errors[] = 'Model is required.';

    if (empty($errors)) {
        $db->prepare("UPDATE car_imports SET make=?,model=?,year=?,color=?,chassis_number=?,engine_number=?,body_type=?,transmission=?,fuel_type=?,engine_cc=?,mileage=?,supplier_id=?,supplier_name=?,auction_ref=?,purchase_currency=?,purchase_price=?,exchange_rate=?,purchase_price_kes=?,purchase_date=?,shipment_id=?,idf_number=?,idf_date=?,notes=?,updated_at=NOW() WHERE id=?")
           ->execute([$make,$model,$year,$color,$chassis,$engine,$bodyType,$trans,$fuel,$engineCc,$mileage,$supplierId,$supplierName,$auctionRef,$currency,$purchasePrice,$exchangeRate,$purchasePriceKes,$purchaseDate,$shipmentId,$idfNumber,$idfDate,$notes,$id]);
        logActivity('update','imports',$id,"Updated import {$imp['ref']}");
        setFlash('success','Import updated.');
        redirect(BASE_URL.'/modules/imports/view.php?id='.$id);
    }
    // Re-fill from POST on error
    $imp = array_merge($imp, $_POST);
}

$pageTitle = 'Edit '.$imp['ref'];
include __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="mb-0"><i class="fa fa-pen me-2 text-secondary"></i>Edit <?= e($imp['ref']) ?></h5>
    <a href="view.php?id=<?= $id ?>" class="btn btn-sm btn-outline-secondary"><i class="fa fa-arrow-left me-1"></i>Back</a>
</div>

<?php foreach($errors as $e): ?><div class="alert alert-danger py-2"><?= e($e) ?></div><?php endforeach; ?>

<form method="POST" class="row g-4">
    <div class="col-lg-7">
        <div class="card mb-4">
            <div class="card-header fw-semibold"><i class="fa fa-car me-2 text-primary"></i>Vehicle Details</div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-sm-6"><label class="form-label small">Make <span class="text-danger">*</span></label><input type="text" name="make" class="form-control form-control-sm" value="<?= e($imp['make']) ?>" required></div>
                    <div class="col-sm-6"><label class="form-label small">Model <span class="text-danger">*</span></label><input type="text" name="model" class="form-control form-control-sm" value="<?= e($imp['model']) ?>" required></div>
                    <div class="col-sm-4"><label class="form-label small">Year</label><input type="number" name="year" class="form-control form-control-sm" value="<?= e($imp['year']) ?>" min="1990" max="<?= date('Y')+1 ?>"></div>
                    <div class="col-sm-4"><label class="form-label small">Color</label><input type="text" name="color" class="form-control form-control-sm" value="<?= e($imp['color']) ?>"></div>
                    <div class="col-sm-4">
                        <label class="form-label small">Body Type</label>
                        <select name="body_type" class="form-select form-select-sm">
                            <option value="">— Select —</option>
                            <?php foreach(['SUV','Saloon','Pick-Up','Van','Truck','Hatchback','Coupe','Bus','Minibus','Other'] as $bt): ?>
                            <option value="<?= $bt ?>" <?= $imp['body_type']===$bt?'selected':'' ?>><?= $bt ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-sm-6"><label class="form-label small">Chassis Number</label><input type="text" name="chassis_number" class="form-control form-control-sm font-monospace" value="<?= e($imp['chassis_number']) ?>"></div>
                    <div class="col-sm-6"><label class="form-label small">Engine Number</label><input type="text" name="engine_number" class="form-control form-control-sm font-monospace" value="<?= e($imp['engine_number']) ?>"></div>
                    <div class="col-sm-4">
                        <label class="form-label small">Transmission</label>
                        <select name="transmission" class="form-select form-select-sm">
                            <option value="automatic" <?= $imp['transmission']==='automatic'?'selected':'' ?>>Automatic</option>
                            <option value="manual"    <?= $imp['transmission']==='manual'?'selected':'' ?>>Manual</option>
                        </select>
                    </div>
                    <div class="col-sm-4">
                        <label class="form-label small">Fuel Type</label>
                        <select name="fuel_type" class="form-select form-select-sm">
                            <?php foreach(['petrol','diesel','hybrid','electric'] as $ft): ?>
                            <option value="<?= $ft ?>" <?= $imp['fuel_type']===$ft?'selected':'' ?>><?= ucfirst($ft) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-sm-4"><label class="form-label small">Engine CC</label><input type="number" name="engine_cc" class="form-control form-control-sm" value="<?= e($imp['engine_cc']) ?>"></div>
                    <div class="col-sm-6"><label class="form-label small">Mileage (km)</label><input type="number" name="mileage" class="form-control form-control-sm" value="<?= e($imp['mileage']) ?>"></div>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header fw-semibold"><i class="fa fa-file-invoice-dollar me-2 text-success"></i>Purchase Details</div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-12"><label class="form-label small">Supplier</label>
                        <select name="supplier_id" class="form-select form-select-sm select2">
                            <option value="">— Select —</option>
                            <?php foreach($suppliers as $sp): ?><option value="<?= $sp['id'] ?>" <?= $imp['supplier_id']==$sp['id']?'selected':'' ?>><?= e($sp['name']) ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12"><label class="form-label small">Supplier Name</label><input type="text" name="supplier_name" class="form-control form-control-sm" value="<?= e($imp['supplier_name']) ?>"></div>
                    <div class="col-sm-6"><label class="form-label small">Auction Ref</label><input type="text" name="auction_ref" class="form-control form-control-sm" value="<?= e($imp['auction_ref']) ?>"></div>
                    <div class="col-sm-6"><label class="form-label small">Purchase Date</label><input type="date" name="purchase_date" class="form-control form-control-sm" value="<?= e($imp['purchase_date']) ?>"></div>
                    <div class="col-sm-4">
                        <label class="form-label small">Currency</label>
                        <select name="purchase_currency" class="form-select form-select-sm">
                            <?php foreach(['JPY','USD','GBP','AED','EUR','KES'] as $c): ?><option value="<?= $c ?>" <?= $imp['purchase_currency']===$c?'selected':'' ?>><?= $c ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-sm-4"><label class="form-label small">Purchase Price</label><input type="number" name="purchase_price" class="form-control form-control-sm" value="<?= e($imp['purchase_price']) ?>" step="0.01"></div>
                    <div class="col-sm-4"><label class="form-label small">Exchange Rate</label><input type="number" name="exchange_rate" class="form-control form-control-sm" value="<?= e($imp['exchange_rate']) ?>" step="0.0001"></div>
                    <div class="col-sm-6"><label class="form-label small">IDF Number</label><input type="text" name="idf_number" class="form-control form-control-sm" value="<?= e($imp['idf_number']) ?>"></div>
                    <div class="col-sm-6"><label class="form-label small">IDF Date</label><input type="date" name="idf_date" class="form-control form-control-sm" value="<?= e($imp['idf_date']) ?>"></div>
                </div>
            </div>
        </div>
    </div>

    <div class="col-lg-5">
        <div class="card mb-4">
            <div class="card-header fw-semibold"><i class="fa fa-boxes-stacked me-2"></i>Shipment</div>
            <div class="card-body">
                <select name="shipment_id" class="form-select form-select-sm select2">
                    <option value="">— No shipment —</option>
                    <?php foreach($shipments as $sh): ?><option value="<?= $sh['id'] ?>" <?= $imp['shipment_id']==$sh['id']?'selected':'' ?>><?= e($sh['ref']) ?> — <?= e($sh['vessel_name']) ?></option><?php endforeach; ?>
                </select>
            </div>
        </div>
        <div class="card mb-4">
            <div class="card-header fw-semibold"><i class="fa fa-note-sticky me-2"></i>Notes</div>
            <div class="card-body">
                <textarea name="notes" class="form-control form-control-sm" rows="5"><?= e($imp['notes']) ?></textarea>
            </div>
        </div>
        <div class="d-grid gap-2">
            <button type="submit" class="btn btn-primary"><i class="fa fa-save me-2"></i>Save Changes</button>
            <a href="view.php?id=<?= $id ?>" class="btn btn-outline-secondary">Cancel</a>
        </div>
    </div>
</form>
<?php include __DIR__ . '/../../includes/footer.php'; ?>

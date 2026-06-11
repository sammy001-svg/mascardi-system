<?php
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
requireWrite('imports');
$db   = getDB();
$user = authUser();

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name        = trim($_POST['name']          ?? '');
    $origin      = trim($_POST['origin_country']?? 'Japan');
    $shippingLine= trim($_POST['shipping_line'] ?? '');
    $blNumber    = trim($_POST['bl_number']     ?? '');
    $vessel      = trim($_POST['vessel_name']   ?? '');
    $etd         = ($_POST['etd']  ?? '') ?: null;
    $eta         = ($_POST['eta']  ?? '') ?: null;
    $notes       = trim($_POST['notes']         ?? '');

    if (!$origin) $errors[] = 'Origin country is required.';

    if (empty($errors)) {
        $ref = nextNumber('car_shipments','ref','SHIP-');
        $db->prepare("INSERT INTO car_shipments (ref,name,origin_country,shipping_line,bl_number,vessel_name,etd,eta,notes,created_by)
            VALUES (?,?,?,?,?,?,?,?,?,?)")
           ->execute([$ref,$name,$origin,$shippingLine,$blNumber,$vessel,$etd,$eta,$notes,$user['id']]);
        $sid = $db->lastInsertId();
        logActivity('create','imports',$sid,"New shipment $ref");
        setFlash('success',"Shipment $ref created.");
        redirect(BASE_URL.'/modules/imports/shipment_view.php?id='.$sid);
    }
}

$pageTitle = 'New Shipment';
include __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="mb-0"><i class="fa fa-boxes-stacked me-2 text-secondary"></i>New Shipment / Consignment</h5>
    <a href="index.php" class="btn btn-sm btn-outline-secondary"><i class="fa fa-arrow-left me-1"></i>Back</a>
</div>

<?php foreach($errors as $e): ?><div class="alert alert-danger py-2"><?= e($e) ?></div><?php endforeach; ?>

<div class="row justify-content-center">
<div class="col-lg-7">
<form method="POST" class="card">
    <div class="card-header fw-semibold"><i class="fa fa-ship me-2 text-primary"></i>Shipment Details</div>
    <div class="card-body">
        <div class="row g-3">
            <div class="col-12">
                <label class="form-label small">Shipment Name / Description</label>
                <input type="text" name="name" class="form-control form-control-sm" value="<?= e($_POST['name']??'') ?>" placeholder="e.g. Japan March 2026 — Toyota Batch">
            </div>
            <div class="col-sm-6">
                <label class="form-label small">Origin Country <span class="text-danger">*</span></label>
                <select name="origin_country" class="form-select form-select-sm">
                    <?php foreach(['Japan','United Kingdom','UAE','Germany','South Africa','Singapore','Australia','USA','Other'] as $c): ?>
                    <option value="<?= $c ?>" <?= ($_POST['origin_country']??'Japan')===$c?'selected':'' ?>><?= $c ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-sm-6">
                <label class="form-label small">Shipping Line / Carrier</label>
                <input type="text" name="shipping_line" class="form-control form-control-sm" value="<?= e($_POST['shipping_line']??'') ?>" placeholder="e.g. Mombasa Shipping, MITSUI">
            </div>
            <div class="col-sm-6">
                <label class="form-label small">Bill of Lading Number</label>
                <input type="text" name="bl_number" class="form-control form-control-sm font-monospace" value="<?= e($_POST['bl_number']??'') ?>" placeholder="B/L No.">
            </div>
            <div class="col-sm-6">
                <label class="form-label small">Vessel Name</label>
                <input type="text" name="vessel_name" class="form-control form-control-sm" value="<?= e($_POST['vessel_name']??'') ?>" placeholder="e.g. MV Ever Given">
            </div>
            <div class="col-sm-6">
                <label class="form-label small">ETD (Estimated Departure)</label>
                <input type="date" name="etd" class="form-control form-control-sm" value="<?= e($_POST['etd']??'') ?>">
            </div>
            <div class="col-sm-6">
                <label class="form-label small">ETA Mombasa (Estimated Arrival)</label>
                <input type="date" name="eta" class="form-control form-control-sm" value="<?= e($_POST['eta']??'') ?>">
            </div>
            <div class="col-12">
                <label class="form-label small">Notes</label>
                <textarea name="notes" class="form-control form-control-sm" rows="3" placeholder="Any additional notes…"><?= e($_POST['notes']??'') ?></textarea>
            </div>
        </div>
    </div>
    <div class="card-footer d-flex gap-2">
        <button type="submit" class="btn btn-primary"><i class="fa fa-save me-2"></i>Create Shipment</button>
        <a href="index.php" class="btn btn-outline-secondary">Cancel</a>
    </div>
</form>
</div>
</div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>

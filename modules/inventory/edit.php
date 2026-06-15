<?php
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
canWrite('inventory') || die('Permission denied.');
$pageTitle = 'Edit Part';
$db = getDB();

$id = (int)($_GET['id'] ?? 0);
if (!$id) redirect(BASE_URL . '/modules/inventory/index.php');

$item = $db->prepare("SELECT * FROM inventory WHERE id=?");
$item->execute([$id]);
$item = $item->fetch();
if (!$item) { setFlash('error', 'Part not found.'); redirect(BASE_URL . '/modules/inventory/index.php'); }

$suppliers = $db->query("SELECT id, name FROM suppliers WHERE status='active' ORDER BY name")->fetchAll();
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $d = [
        'part_number'   => trim($_POST['part_number']   ?? ''),
        'part_name'     => trim($_POST['part_name']     ?? ''),
        'description'   => trim($_POST['description']   ?? ''),
        'category'      => trim($_POST['category']      ?? ''),
        'brand'         => trim($_POST['brand']         ?? '') ?: null,
        'make'          => trim($_POST['make']          ?? '') ?: null,
        'model'         => trim($_POST['model']         ?? '') ?: null,
        'code'          => trim($_POST['code']          ?? '') ?: null,
        'unit'          => trim($_POST['unit']          ?? 'piece'),
        'unit_price'    => (float)($_POST['unit_price'] ?? 0),
        'selling_price' => (float)($_POST['selling_price'] ?? 0),
        'reorder_level' => (float)($_POST['reorder_level'] ?? 5),
        'reorder_qty'   => (float)($_POST['reorder_qty']   ?? 0),
        'supplier_id'   => (int)($_POST['supplier_id'] ?? 0) ?: null,
    ];

    if (!$d['part_name']) {
        $error = 'Part name is required.';
    } else {
        try {
            $db->prepare("
                UPDATE inventory SET
                    part_number=?, part_name=?, description=?, category=?,
                    brand=?, make=?, model=?, code=?,
                    unit=?, unit_price=?, selling_price=?, reorder_level=?, reorder_qty=?, supplier_id=?
                WHERE id=?
            ")->execute([
                $d['part_number'], $d['part_name'], $d['description'], $d['category'],
                $d['brand'], $d['make'], $d['model'], $d['code'],
                $d['unit'], $d['unit_price'], $d['selling_price'], $d['reorder_level'], $d['reorder_qty'], $d['supplier_id'],
                $id,
            ]);
            setFlash('success', 'Part updated successfully.');
            redirect(BASE_URL . '/modules/inventory/index.php');
        } catch (PDOException $e) {
            $error = $e->getCode() === '23000' ? 'Part number already exists.' : $e->getMessage();
        }
    }
}

$categories = ['Engine Parts','Body Parts','Electrical','Tyres & Wheels','Brakes','Suspension','Filters','Fluids & Lubricants','Interior','Tools','Other'];
$f = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $item;
include __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h5 class="mb-0">Edit Part — <?= e($item['part_name']) ?></h5>
    <div class="d-flex gap-2">
        <a href="adjust.php?id=<?= $id ?>" class="btn btn-sm btn-outline-primary"><i class="fa fa-sliders me-1"></i>Adjust Stock</a>
        <a href="index.php" class="btn btn-sm btn-outline-secondary"><i class="fa fa-arrow-left me-1"></i>Back</a>
    </div>
</div>

<!-- Stock badge -->
<div class="mb-3">
    <?php $qty = (float)$item['quantity']; $rl = (float)$item['reorder_level'];
    if ($qty == 0)       echo '<span class="badge bg-danger fs-6">Out of Stock — 0 ' . e($item['unit']) . '</span>';
    elseif ($qty <= $rl) echo '<span class="badge bg-warning text-dark fs-6">Low Stock — ' . number_format($qty,2) . ' ' . e($item['unit']) . '</span>';
    else                 echo '<span class="badge bg-success fs-6">In Stock — ' . number_format($qty,2) . ' ' . e($item['unit']) . '</span>';
    ?>
    <span class="text-muted small ms-2">Use "Adjust Stock" to change quantity.</span>
</div>

<?php if ($error): ?>
<div class="alert alert-danger"><i class="fa fa-exclamation-circle me-2"></i><?= e($error) ?></div>
<?php endif; ?>

<form method="POST">
<div class="row g-4">

    <!-- Left column -->
    <div class="col-lg-8">

        <!-- Identification -->
        <div class="card mb-4">
            <div class="card-header fw-semibold"><i class="fa fa-tag me-2 text-primary"></i>Part Identification</div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label">Part Number</label>
                        <input type="text" name="part_number" class="form-control"
                               value="<?= e($f['part_number'] ?? '') ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Code <span class="text-muted fw-normal small">(OEM / barcode)</span></label>
                        <input type="text" name="code" class="form-control"
                               placeholder="e.g. 90915-YZZD4"
                               value="<?= e($f['code'] ?? '') ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Category</label>
                        <select name="category" class="form-select">
                            <option value="">— Select Category —</option>
                            <?php foreach ($categories as $cat): ?>
                            <option value="<?= e($cat) ?>" <?= ($f['category'] ?? '')===$cat?'selected':'' ?>><?= e($cat) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12">
                        <label class="form-label">Part Name <span class="text-danger">*</span></label>
                        <input type="text" name="part_name" class="form-control" required
                               value="<?= e($f['part_name']) ?>">
                    </div>
                    <div class="col-12">
                        <label class="form-label">Description</label>
                        <textarea name="description" class="form-control" rows="2"><?= e($f['description'] ?? '') ?></textarea>
                    </div>
                </div>
            </div>
        </div>

        <!-- Compatibility -->
        <div class="card mb-4">
            <div class="card-header fw-semibold"><i class="fa fa-car me-2 text-primary"></i>Compatibility</div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label">Brand</label>
                        <input type="text" name="brand" class="form-control"
                               placeholder="e.g. Denso, Bosch, NGK"
                               value="<?= e($f['brand'] ?? '') ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Make <span class="text-muted fw-normal small">(car make)</span></label>
                        <input type="text" name="make" class="form-control"
                               placeholder="e.g. Toyota, BMW, Isuzu"
                               value="<?= e($f['make'] ?? '') ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Model <span class="text-muted fw-normal small">(car model)</span></label>
                        <input type="text" name="model" class="form-control"
                               placeholder="e.g. Hilux, X5, D-Max"
                               value="<?= e($f['model'] ?? '') ?>">
                    </div>
                </div>
            </div>
        </div>

        <!-- Pricing -->
        <div class="card">
            <div class="card-header fw-semibold"><i class="fa fa-coins me-2 text-primary"></i>Pricing &amp; Reorder</div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label">Unit</label>
                        <select name="unit" class="form-select">
                            <?php foreach (['piece','litre','kg','set','pair','box','roll','metre'] as $u): ?>
                            <option value="<?= $u ?>" <?= ($f['unit'] ?? 'piece')===$u?'selected':'' ?>><?= ucfirst($u) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Buying Price (KES)</label>
                        <input type="number" name="unit_price" class="form-control"
                               min="0" step="0.01" value="<?= e($f['unit_price']) ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Selling Price (KES)</label>
                        <input type="number" name="selling_price" class="form-control"
                               min="0" step="0.01" value="<?= e($f['selling_price']) ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Reorder At</label>
                        <input type="number" name="reorder_level" class="form-control"
                               min="0" step="0.01" value="<?= e($f['reorder_level']) ?>">
                        <div class="form-text">Alert when stock reaches this level</div>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Reorder Quantity</label>
                        <input type="number" name="reorder_qty" class="form-control"
                               min="0" step="0.01" value="<?= e($f['reorder_qty'] ?? '0') ?>">
                        <div class="form-text">How many to order when restocking</div>
                    </div>
                </div>
            </div>
        </div>

    </div>

    <!-- Right column -->
    <div class="col-lg-4">
        <div class="card">
            <div class="card-header fw-semibold"><i class="fa fa-truck me-2 text-primary"></i>Supplier</div>
            <div class="card-body">
                <label class="form-label">Supplier</label>
                <select name="supplier_id" class="form-select select2">
                    <option value="">— No Supplier —</option>
                    <?php foreach ($suppliers as $s): ?>
                    <option value="<?= $s['id'] ?>" <?= ($f['supplier_id'] ?? '')==$s['id']?'selected':'' ?>>
                        <?= e($s['name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="mt-4 d-grid gap-2">
            <button type="submit" class="btn btn-primary"><i class="fa fa-check me-1"></i>Save Changes</button>
            <a href="index.php" class="btn btn-outline-secondary">Cancel</a>
        </div>
    </div>

</div>
</form>

<?php include __DIR__ . '/../../includes/footer.php'; ?>

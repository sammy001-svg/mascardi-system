<?php
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
canWrite('inventory') || die('Permission denied.');
$pageTitle = 'Add Part';
$db = getDB();
$error = '';

$suppliers = $db->query("SELECT id, name FROM suppliers WHERE status='active' ORDER BY name")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = [
        'part_number'   => trim($_POST['part_number']   ?? ''),
        'part_name'     => trim($_POST['part_name']     ?? ''),
        'description'   => trim($_POST['description']   ?? ''),
        'category'      => trim($_POST['category']      ?? ''),
        'brand'         => trim($_POST['brand']         ?? ''),
        'make'          => trim($_POST['make']          ?? ''),
        'model'         => trim($_POST['model']         ?? ''),
        'code'          => trim($_POST['code']          ?? ''),
        'quantity'      => (float)($_POST['quantity']   ?? 0),
        'unit'          => trim($_POST['unit']          ?? 'piece'),
        'unit_price'    => (float)($_POST['unit_price'] ?? 0),
        'selling_price' => (float)($_POST['selling_price'] ?? 0),
        'reorder_level' => (float)($_POST['reorder_level'] ?? 5),
        'supplier_id'   => (int)($_POST['supplier_id'] ?? 0) ?: null,
    ];

    if (!$data['part_name']) {
        $error = 'Part name is required.';
    } else {
        try {
            $db->prepare("
                INSERT INTO inventory
                    (part_number, part_name, description, category,
                     brand, make, model, code,
                     quantity, unit, unit_price, selling_price, reorder_level, supplier_id)
                VALUES (?,?,?,?, ?,?,?,?, ?,?,?,?,?,?)
            ")->execute([
                $data['part_number'],  $data['part_name'],  $data['description'], $data['category'],
                $data['brand'] ?: null, $data['make'] ?: null, $data['model'] ?: null, $data['code'] ?: null,
                $data['quantity'], $data['unit'], $data['unit_price'], $data['selling_price'],
                $data['reorder_level'], $data['supplier_id'],
            ]);
            $newId = $db->lastInsertId();

            if ($data['quantity'] > 0) {
                $db->prepare("INSERT INTO inventory_transactions (inventory_id, transaction_type, quantity, balance, notes, created_by)
                              VALUES (?, 'in', ?, ?, 'Opening stock', ?)")
                   ->execute([$newId, $data['quantity'], $data['quantity'], authUser()['name']]);
            }

            setFlash('success', 'Part added successfully.');
            redirect(BASE_URL . '/modules/inventory/index.php');
        } catch (PDOException $e) {
            $error = $e->getCode() === '23000' ? 'Part number already exists.' : $e->getMessage();
        }
    }
}

$categories = ['Engine Parts','Body Parts','Electrical','Tyres & Wheels','Brakes','Suspension','Filters','Fluids & Lubricants','Interior','Tools','Other'];
$p = $_POST;
include __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h5 class="mb-0">Add Part to Inventory</h5>
    <a href="index.php" class="btn btn-sm btn-outline-secondary"><i class="fa fa-arrow-left me-1"></i>Back</a>
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
                               placeholder="e.g. OIL-FILTER-001"
                               value="<?= e($p['part_number'] ?? '') ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Code <span class="text-muted fw-normal small">(OEM / barcode)</span></label>
                        <input type="text" name="code" class="form-control"
                               placeholder="e.g. 90915-YZZD4"
                               value="<?= e($p['code'] ?? '') ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Category</label>
                        <select name="category" class="form-select">
                            <option value="">— Select Category —</option>
                            <?php foreach ($categories as $cat): ?>
                            <option value="<?= e($cat) ?>" <?= ($p['category'] ?? '')===$cat?'selected':'' ?>><?= e($cat) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12">
                        <label class="form-label">Part Name <span class="text-danger">*</span></label>
                        <input type="text" name="part_name" class="form-control" required
                               placeholder="e.g. Oil Filter Toyota Hilux 2.5D"
                               value="<?= e($p['part_name'] ?? '') ?>">
                    </div>
                    <div class="col-12">
                        <label class="form-label">Description</label>
                        <textarea name="description" class="form-control" rows="2"
                                  placeholder="Optional specifications, notes…"><?= e($p['description'] ?? '') ?></textarea>
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
                               value="<?= e($p['brand'] ?? '') ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Make <span class="text-muted fw-normal small">(car make)</span></label>
                        <input type="text" name="make" class="form-control"
                               placeholder="e.g. Toyota, BMW, Isuzu"
                               value="<?= e($p['make'] ?? '') ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Model <span class="text-muted fw-normal small">(car model)</span></label>
                        <input type="text" name="model" class="form-control"
                               placeholder="e.g. Hilux, X5, D-Max"
                               value="<?= e($p['model'] ?? '') ?>">
                    </div>
                </div>
            </div>
        </div>

        <!-- Pricing & Stock -->
        <div class="card">
            <div class="card-header fw-semibold"><i class="fa fa-coins me-2 text-primary"></i>Pricing &amp; Stock</div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label">Opening Quantity</label>
                        <input type="number" name="quantity" class="form-control"
                               min="0" step="0.01" value="<?= e($p['quantity'] ?? '0') ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Unit</label>
                        <select name="unit" class="form-select">
                            <?php foreach (['piece','litre','kg','set','pair','box','roll','metre'] as $u): ?>
                            <option value="<?= $u ?>" <?= ($p['unit'] ?? 'piece')===$u?'selected':'' ?>><?= ucfirst($u) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Buying Price (KES)</label>
                        <input type="number" name="unit_price" class="form-control"
                               min="0" step="0.01" value="<?= e($p['unit_price'] ?? '0') ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Selling Price (KES)</label>
                        <input type="number" name="selling_price" class="form-control"
                               min="0" step="0.01" value="<?= e($p['selling_price'] ?? '0') ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Reorder At</label>
                        <input type="number" name="reorder_level" class="form-control"
                               min="0" step="0.01" value="<?= e($p['reorder_level'] ?? '5') ?>">
                        <div class="form-text">Alert when stock reaches this level</div>
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
                    <option value="<?= $s['id'] ?>" <?= ($p['supplier_id'] ?? '')==$s['id']?'selected':'' ?>>
                        <?= e($s['name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="mt-4 d-grid gap-2">
            <button type="submit" class="btn btn-primary"><i class="fa fa-check me-1"></i>Save Part</button>
            <a href="index.php" class="btn btn-outline-secondary">Cancel</a>
        </div>
    </div>

</div>
</form>

<?php include __DIR__ . '/../../includes/footer.php'; ?>

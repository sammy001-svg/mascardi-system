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
    $partNum   = trim($_POST['part_number'] ?? '');
    $partName  = trim($_POST['part_name'] ?? '');
    $desc      = trim($_POST['description'] ?? '');
    $cat       = trim($_POST['category'] ?? '');
    $unit      = trim($_POST['unit'] ?? 'piece');
    $buyPrice  = (float)($_POST['unit_price'] ?? 0);
    $sellPrice = (float)($_POST['selling_price'] ?? 0);
    $reorder   = (float)($_POST['reorder_level'] ?? 5);
    $supId     = (int)($_POST['supplier_id'] ?? 0) ?: null;

    if (!$partName) {
        $error = 'Part name is required.';
    } else {
        try {
            $db->prepare("UPDATE inventory SET part_number=?,part_name=?,description=?,category=?,unit=?,unit_price=?,selling_price=?,reorder_level=?,supplier_id=? WHERE id=?")
               ->execute([$partNum,$partName,$desc,$cat,$unit,$buyPrice,$sellPrice,$reorder,$supId,$id]);
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

<?php if ($error): ?>
<div class="alert alert-danger"><i class="fa fa-exclamation-circle me-2"></i><?= e($error) ?></div>
<?php endif; ?>

<!-- Stock info pill -->
<div class="mb-3">
    <?php $qty = (float)$item['quantity']; $rl = (float)$item['reorder_level'];
    if ($qty == 0) echo '<span class="badge bg-danger fs-6">Out of Stock — 0 ' . e($item['unit']) . '</span>';
    elseif ($qty <= $rl) echo '<span class="badge bg-warning text-dark fs-6">Low Stock — ' . number_format($qty,2) . ' ' . e($item['unit']) . '</span>';
    else echo '<span class="badge bg-success fs-6">In Stock — ' . number_format($qty,2) . ' ' . e($item['unit']) . '</span>';
    ?>
    <span class="text-muted small ms-2">Editing pricing &amp; details only. Use "Adjust Stock" to change quantity.</span>
</div>

<div class="card">
    <div class="card-body">
        <form method="POST">
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label">Part Number</label>
                    <input type="text" name="part_number" class="form-control" value="<?= e($f['part_number'] ?? '') ?>">
                </div>
                <div class="col-md-8">
                    <label class="form-label">Part Name <span class="text-danger">*</span></label>
                    <input type="text" name="part_name" class="form-control" required value="<?= e($f['part_name']) ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Category</label>
                    <select name="category" class="form-select">
                        <option value="">-- Select Category --</option>
                        <?php foreach ($categories as $cat): ?>
                        <option value="<?= e($cat) ?>" <?= ($f['category'] ?? '')===$cat?'selected':'' ?>><?= e($cat) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Supplier</label>
                    <select name="supplier_id" class="form-select select2">
                        <option value="">-- No Supplier --</option>
                        <?php foreach ($suppliers as $s): ?>
                        <option value="<?= $s['id'] ?>" <?= ($f['supplier_id'] ?? '')==$s['id']?'selected':'' ?>><?= e($s['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Unit of Measure</label>
                    <select name="unit" class="form-select">
                        <?php foreach (['piece','litre','kg','set','pair','box','roll','metre'] as $u): ?>
                        <option value="<?= $u ?>" <?= ($f['unit'] ?? 'piece')===$u?'selected':'' ?>><?= ucfirst($u) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12">
                    <label class="form-label">Description</label>
                    <textarea name="description" class="form-control" rows="2"><?= e($f['description'] ?? '') ?></textarea>
                </div>
                <div class="col-12"><hr class="my-1"><div class="form-section-title">Pricing &amp; Reorder</div></div>
                <div class="col-md-4">
                    <label class="form-label">Buy Price (KES)</label>
                    <input type="number" name="unit_price" class="form-control" min="0" step="0.01" value="<?= e($f['unit_price']) ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Selling Price (KES)</label>
                    <input type="number" name="selling_price" class="form-control" min="0" step="0.01" value="<?= e($f['selling_price']) ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Reorder Level</label>
                    <input type="number" name="reorder_level" class="form-control" min="0" step="0.01" value="<?= e($f['reorder_level']) ?>">
                </div>
            </div>

            <div class="mt-4 d-flex gap-2">
                <button type="submit" class="btn btn-primary"><i class="fa fa-check me-1"></i>Save Changes</button>
                <a href="index.php" class="btn btn-outline-secondary">Cancel</a>
            </div>
        </form>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>

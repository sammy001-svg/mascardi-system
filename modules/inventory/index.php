<?php
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
canAccess('inventory') || die('Access denied.');
$pageTitle = 'Parts & Inventory';
$db = getDB();
$showPrices = !hasRole('mechanic');

$filter   = $_GET['filter']   ?? '';
$search   = trim($_GET['q']   ?? '');
$category = $_GET['category'] ?? '';
$brand    = trim($_GET['brand'] ?? '');
$make     = trim($_GET['make']  ?? '');

$where  = [];
$params = [];

if ($filter === 'low_stock') {
    $where[] = 'i.quantity <= i.reorder_level AND i.quantity > 0';
} elseif ($filter === 'out_of_stock') {
    $where[] = 'i.quantity = 0';
}

if ($search) {
    $where[] = '(i.part_name LIKE ? OR i.part_number LIKE ? OR i.category LIKE ? OR i.brand LIKE ? OR i.make LIKE ? OR i.model LIKE ? OR i.code LIKE ?)';
    $params  = array_merge($params, array_fill(0, 7, "%$search%"));
}
if ($category) { $where[] = 'i.category = ?'; $params[] = $category; }
if ($brand)    { $where[] = 'i.brand = ?';    $params[] = $brand; }
if ($make)     { $where[] = 'i.make = ?';     $params[] = $make; }

$sql = "SELECT i.*, s.name AS supplier_name
        FROM inventory i
        LEFT JOIN suppliers s ON s.id = i.supplier_id"
    . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
    . " ORDER BY i.created_at DESC";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$items = $stmt->fetchAll();

$categories = $db->query("SELECT DISTINCT category FROM inventory WHERE category IS NOT NULL AND category <> '' ORDER BY category")->fetchAll(PDO::FETCH_COLUMN);
$brands     = $db->query("SELECT DISTINCT brand    FROM inventory WHERE brand    IS NOT NULL AND brand    <> '' ORDER BY brand")->fetchAll(PDO::FETCH_COLUMN);
$makes      = $db->query("SELECT DISTINCT make     FROM inventory WHERE make     IS NOT NULL AND make     <> '' ORDER BY make")->fetchAll(PDO::FETCH_COLUMN);

$stats = [
    'total'     => $db->query("SELECT COUNT(*) FROM inventory")->fetchColumn(),
    'low_stock' => $db->query("SELECT COUNT(*) FROM inventory WHERE quantity <= reorder_level AND quantity > 0")->fetchColumn(),
    'out'       => $db->query("SELECT COUNT(*) FROM inventory WHERE quantity = 0")->fetchColumn(),
    'value'     => $db->query("SELECT COALESCE(SUM(quantity * unit_price),0) FROM inventory")->fetchColumn(),
];

include __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <h5 class="mb-1">Parts &amp; Inventory</h5>
        <div class="text-muted small">Stock management for parts and materials</div>
    </div>
    <?php if (canWrite('inventory')): ?>
    <a href="add.php" class="btn btn-primary btn-sm"><i class="fa fa-plus me-1"></i>Add Part</a>
    <?php endif; ?>
</div>

<!-- KPI Strip -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="stat-card">
            <div class="stat-icon" style="background:#dbeafe;color:#2563eb"><i class="fa fa-boxes-stacked"></i></div>
            <div class="stat-info">
                <div class="stat-label">Total Parts</div>
                <div class="stat-value"><?= number_format($stats['total']) ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <a href="?filter=low_stock" class="stat-card-link">
        <div class="stat-card w-100" style="border-left:3px solid #d97706">
            <div class="stat-icon" style="background:#fef3c7;color:#d97706"><i class="fa fa-triangle-exclamation"></i></div>
            <div class="stat-info">
                <div class="stat-label">Low Stock</div>
                <div class="stat-value"><?= $stats['low_stock'] ?></div>
            </div>
        </div>
        </a>
    </div>
    <div class="col-6 col-md-3">
        <a href="?filter=out_of_stock" class="stat-card-link">
        <div class="stat-card w-100" style="border-left:3px solid #dc2626">
            <div class="stat-icon" style="background:#fee2e2;color:#dc2626"><i class="fa fa-ban"></i></div>
            <div class="stat-info">
                <div class="stat-label">Out of Stock</div>
                <div class="stat-value"><?= $stats['out'] ?></div>
            </div>
        </div>
        </a>
    </div>
    <?php if ($showPrices): ?>
    <div class="col-6 col-md-3">
        <div class="stat-card">
            <div class="stat-icon" style="background:#dcfce7;color:#16a34a"><i class="fa fa-coins"></i></div>
            <div class="stat-info">
                <div class="stat-label">Stock Value</div>
                <div class="stat-value stat-value-sm"><?= money((float)$stats['value']) ?></div>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- Filters -->
<div class="card mb-3">
    <div class="card-body py-2 px-3">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-12 col-md-4">
                <input type="text" name="q" class="form-control form-control-sm"
                       placeholder="Search part name, no., brand, make, model, code…"
                       value="<?= e($search) ?>">
            </div>
            <div class="col-6 col-md-2">
                <select name="category" class="form-select form-select-sm">
                    <option value="">All Categories</option>
                    <?php foreach ($categories as $cat): ?>
                    <option value="<?= e($cat) ?>" <?= $category===$cat?'selected':'' ?>><?= e($cat) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <select name="brand" class="form-select form-select-sm">
                    <option value="">All Brands</option>
                    <?php foreach ($brands as $b): ?>
                    <option value="<?= e($b) ?>" <?= $brand===$b?'selected':'' ?>><?= e($b) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <select name="make" class="form-select form-select-sm">
                    <option value="">All Makes</option>
                    <?php foreach ($makes as $m): ?>
                    <option value="<?= e($m) ?>" <?= $make===$m?'selected':'' ?>><?= e($m) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-1">
                <select name="filter" class="form-select form-select-sm">
                    <option value="" <?= !$filter?'selected':'' ?>>All</option>
                    <option value="low_stock"    <?= $filter==='low_stock'?'selected':'' ?>>Low Stock</option>
                    <option value="out_of_stock" <?= $filter==='out_of_stock'?'selected':'' ?>>Out of Stock</option>
                </select>
            </div>
            <div class="col-12 col-md-1 d-flex gap-1">
                <button class="btn btn-sm btn-primary flex-grow-1"><i class="fa fa-search"></i></button>
                <a href="?" class="btn btn-sm btn-outline-secondary"><i class="fa fa-xmark"></i></a>
            </div>
        </form>
    </div>
</div>

<!-- Table -->
<div class="card">
    <div class="card-body p-0">
        <div class="table-responsive">
        <table class="table table-hover datatable mb-0" style="white-space:nowrap">
            <thead>
                <tr>
                    <th class="ps-3">Part No.</th>
                    <th>Part Name</th>
                    <th>Category</th>
                    <th>Brand</th>
                    <th>Make</th>
                    <th>Model</th>
                    <th>Code</th>
                    <th>Quantity</th>
                    <?php if ($showPrices): ?>
                    <th>Buying Price</th>
                    <th>Selling Price</th>
                    <?php endif; ?>
                    <th>Unit</th>
                    <th>Reorder At</th>
                    <th>Status</th>
                    <th>Supplier</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($items as $item):
                    $qty = (float)$item['quantity'];
                    $rl  = (float)$item['reorder_level'];
                    if ($qty == 0)       $stockBadge = '<span class="badge bg-danger">Out of Stock</span>';
                    elseif ($qty <= $rl) $stockBadge = '<span class="badge bg-warning text-dark">Low Stock</span>';
                    else                 $stockBadge = '<span class="badge bg-success">In Stock</span>';
                ?>
                <tr>
                    <td class="ps-3"><code><?= e($item['part_number'] ?: '—') ?></code></td>
                    <td class="fw-medium"><?= e($item['part_name']) ?></td>
                    <td>
                        <?php if ($item['category']): ?>
                        <span class="badge bg-light text-dark border"><?= e($item['category']) ?></span>
                        <?php else: ?><span class="text-muted">—</span><?php endif; ?>
                    </td>
                    <td><?= e($item['brand'] ?: '—') ?></td>
                    <td><?= e($item['make']  ?: '—') ?></td>
                    <td><?= e($item['model'] ?: '—') ?></td>
                    <td><code><?= e($item['code'] ?: '—') ?></code></td>
                    <td class="fw-semibold <?= $qty==0?'text-danger':($qty<=$rl?'text-warning':'') ?>">
                        <?= number_format($qty, 2) ?>
                    </td>
                    <?php if ($showPrices): ?>
                    <td><?= money((float)$item['unit_price']) ?></td>
                    <td><?= money((float)$item['selling_price']) ?></td>
                    <?php endif; ?>
                    <td class="text-muted small"><?= e($item['unit']) ?></td>
                    <td class="text-muted"><?= number_format($rl, 2) ?></td>
                    <td><?= $stockBadge ?></td>
                    <td class="text-muted small"><?= e($item['supplier_name'] ?? '—') ?></td>
                    <td>
                        <?php if (canWrite('inventory')): ?>
                        <a href="edit.php?id=<?= $item['id'] ?>" class="btn btn-xs btn-outline-secondary" title="Edit"><i class="fa fa-pen"></i></a>
                        <a href="adjust.php?id=<?= $item['id'] ?>" class="btn btn-xs btn-outline-primary" title="Adjust Stock"><i class="fa fa-sliders"></i></a>
                        <?php endif; ?>
                        <?php if (canEditDelete()): ?>
                        <a href="delete.php?id=<?= $item['id'] ?>" class="btn btn-xs btn-outline-danger" title="Delete"
                           onclick="return confirm('Delete this part? This cannot be undone.')"><i class="fa fa-trash"></i></a>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (!$items): ?>
                <tr><td colspan="15" class="text-center text-muted py-4">No parts found.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>

<?php
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
$pageTitle = 'Suppliers';
$db = getDB();

$search = trim($_GET['q'] ?? '');
$status = $_GET['status'] ?? '';

$where = [];
$params = [];

if ($search) {
    $where[] = '(name LIKE ? OR contact_person LIKE ? OR phone LIKE ? OR email LIKE ?)';
    $params = array_merge($params, ["%$search%","%$search%","%$search%","%$search%"]);
}
if (in_array($status, ['active','inactive'])) {
    $where[] = 'status = ?';
    $params[] = $status;
}

$sql = "SELECT s.*, (SELECT COUNT(*) FROM lpo WHERE supplier_id=s.id) AS lpo_count,
               (SELECT COUNT(*) FROM inventory WHERE supplier_id=s.id) AS parts_count
        FROM suppliers s"
    . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
    . " ORDER BY s.name ASC";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$suppliers = $stmt->fetchAll();

include __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <h5 class="mb-1">Suppliers</h5>
        <div class="text-muted small">Manage parts and materials suppliers</div>
    </div>
    <?php if (canEditDelete()): ?>
    <a href="add.php" class="btn btn-primary btn-sm"><i class="fa fa-plus me-1"></i>Add Supplier</a>
    <?php endif; ?>
</div>

<!-- Stats -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="stat-card">
            <div class="stat-icon" style="background:#dbeafe;color:#2563eb"><i class="fa fa-truck"></i></div>
            <div class="stat-info">
                <div class="stat-label">Total Suppliers</div>
                <div class="stat-value"><?= count($suppliers) ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card">
            <div class="stat-icon" style="background:#dcfce7;color:#16a34a"><i class="fa fa-circle-check"></i></div>
            <div class="stat-info">
                <div class="stat-label">Active</div>
                <div class="stat-value"><?= count(array_filter($suppliers, fn($s) => $s['status']==='active')) ?></div>
            </div>
        </div>
    </div>
</div>

<!-- Filters -->
<div class="card mb-3">
    <div class="card-body py-2 px-3">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-12 col-md-6">
                <input type="text" name="q" class="form-control form-control-sm" placeholder="Search name, contact, phone, email…" value="<?= e($search) ?>">
            </div>
            <div class="col-6 col-md-3">
                <select name="status" class="form-select form-select-sm">
                    <option value="">All Status</option>
                    <option value="active"   <?= $status==='active'?'selected':''   ?>>Active</option>
                    <option value="inactive" <?= $status==='inactive'?'selected':'' ?>>Inactive</option>
                </select>
            </div>
            <div class="col-6 col-md-3 d-flex gap-2">
                <button class="btn btn-sm btn-primary flex-grow-1"><i class="fa fa-search me-1"></i>Filter</button>
                <a href="?" class="btn btn-sm btn-outline-secondary">Clear</a>
            </div>
        </form>
    </div>
</div>

<!-- Table -->
<div class="card">
    <div class="card-body p-0">
        <table class="table table-hover datatable mb-0">
            <thead>
                <tr>
                    <th class="ps-3">Supplier</th>
                    <th>Contact Person</th>
                    <th>Phone</th>
                    <th>Email</th>
                    <th>PIN</th>
                    <th>Payment Terms</th>
                    <th>Parts</th>
                    <th>LPOs</th>
                    <th>Status</th>
                    <?php if (canEditDelete()): ?><th>Actions</th><?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($suppliers as $s): ?>
                <tr>
                    <td class="ps-3 fw-semibold"><?= e($s['name']) ?></td>
                    <td><?= e($s['contact_person'] ?? '—') ?></td>
                    <td><a href="tel:<?= e($s['phone'] ?? '') ?>"><?= e($s['phone'] ?? '—') ?></a></td>
                    <td class="text-muted small"><?= $s['email'] ? '<a href="mailto:'.e($s['email']).'">'.e($s['email']).'</a>' : '—' ?></td>
                    <td><code><?= e($s['pin_number'] ?? '—') ?></code></td>
                    <td class="text-muted small"><?= e($s['payment_terms'] ?? '—') ?></td>
                    <td><span class="badge bg-light text-dark border"><?= $s['parts_count'] ?></span></td>
                    <td><span class="badge bg-light text-dark border"><?= $s['lpo_count'] ?></span></td>
                    <td><?= statusBadge($s['status']) ?></td>
                    <?php if (canEditDelete()): ?>
                    <td>
                        <a href="edit.php?id=<?= $s['id'] ?>" class="btn btn-xs btn-outline-secondary"><i class="fa fa-pen"></i></a>
                        <?php if ($s['lpo_count'] == 0 && $s['parts_count'] == 0): ?>
                        <a href="delete.php?id=<?= $s['id'] ?>" class="btn btn-xs btn-outline-danger"
                           onclick="return confirm('Delete this supplier?')"><i class="fa fa-trash"></i></a>
                        <?php endif; ?>
                    </td>
                    <?php endif; ?>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>

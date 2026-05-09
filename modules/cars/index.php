<?php
require_once __DIR__ . '/../../includes/functions.php';
$pageTitle = 'Cars';
$db = getDB();

$status  = $_GET['status'] ?? '';
$carType = $_GET['car_type'] ?? '';
$search  = $_GET['q'] ?? '';

$where = ['1=1'];
$params = [];
if ($status)  { $where[] = 'c.status = ?'; $params[] = $status; }
if ($carType) { $where[] = 'c.car_type = ?'; $params[] = $carType; }
if ($search) { 
    $where[] = '(c.chassis_number LIKE ? OR c.make LIKE ? OR c.model LIKE ? OR c.registration_number LIKE ? OR c.owner_name LIKE ?)'; 
    $params = array_merge($params, ["%$search%","%$search%","%$search%","%$search%","%$search%"]); 
}

$sql = "SELECT c.*, ci.intake_date, l.name AS location_name,
               (SELECT file_path FROM car_images WHERE car_id = c.id AND is_primary = 1 LIMIT 1) AS primary_image
        FROM cars c
        LEFT JOIN car_intake ci ON ci.car_id = c.id
        LEFT JOIN locations l ON l.id = c.location_id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY c.created_at DESC";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$cars = $stmt->fetchAll();

include __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="mb-0">All Cars <span class="badge bg-secondary ms-2"><?= count($cars) ?></span></h5>
    <a href="<?= BASE_URL ?>/modules/cars/add.php" class="btn btn-primary btn-sm"><i class="fa fa-plus me-1"></i>Add Car</a>
</div>

<!-- Filters -->
<div class="card mb-3">
    <div class="card-body py-2">
        <form class="row g-2 align-items-end">
            <div class="col-md-3">
                <label class="small text-muted mb-1">Search</label>
                <input type="text" name="q" class="form-control form-control-sm" placeholder="Chassis, make, owner..." value="<?= e($search) ?>">
            </div>
            <div class="col-md-3">
                <label class="small text-muted mb-1">Type</label>
                <select name="car_type" class="form-select form-select-sm">
                    <option value="">All Types</option>
                    <option value="inventory" <?= $carType === 'inventory' ? 'selected' : '' ?>>Inventory (Imported)</option>
                    <option value="client" <?= $carType === 'client' ? 'selected' : '' ?>>Client (Service)</option>
                </select>
            </div>
            <div class="col-md-3">
                <label class="small text-muted mb-1">Status</label>
                <select name="status" class="form-select form-select-sm">
                    <option value="">All Statuses</option>
                    <?php foreach (['in_transit','arrived','in_assessment','in_workshop','completed','delivered'] as $s): ?>
                    <option value="<?= $s ?>" <?= $status === $s ? 'selected' : '' ?>><?= ucwords(str_replace('_',' ',$s)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3 d-flex gap-2">
                <button type="submit" class="btn btn-sm btn-primary w-100">Filter</button>
                <a href="?" class="btn btn-sm btn-outline-secondary">Clear</a>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-body p-0">
        <table class="table table-hover datatable mb-0">
            <thead>
                <tr>
                    <th class="ps-3">Vehicle</th>
                    <th>Type</th>
                    <th>Chassis</th>
                    <th>Location</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($cars as $car): ?>
                <tr>
                    <td class="ps-3">
                        <div class="d-flex align-items-center">
                            <?php if ($car['primary_image']): ?>
                                <img src="<?= BASE_URL ?>/uploads/cars/<?= e($car['primary_image']) ?>" class="rounded me-2 border shadow-sm" style="width:50px; height:40px; object-fit:cover;">
                            <?php else: ?>
                                <div class="bg-light rounded me-2 border d-flex align-items-center justify-content-center text-muted" style="width:50px; height:40px; font-size:10px">NO IMG</div>
                            <?php endif; ?>
                            <div>
                                <div class="fw-bold"><?= e($car['make'] . ' ' . $car['model']) ?></div>
                                <div class="text-muted small"><?= e($car['year']) ?> &bull; <?= e($car['registration_number'] ?: 'No Reg') ?></div>
                            </div>
                        </div>
                    </td>
                    <td>
                        <?php if ($car['car_type'] === 'client'): ?>
                            <span class="badge bg-info text-dark">CLIENT</span>
                            <div class="small text-muted"><?= e($car['owner_name']) ?></div>
                        <?php else: ?>
                            <span class="badge bg-primary">INVENTORY</span>
                        <?php endif; ?>
                    </td>
                    <td><code><?= e($car['chassis_number']) ?></code></td>
                    <td class="small text-muted"><i class="fa fa-location-dot me-1"></i><?= e($car['location_name'] ?: '—') ?></td>
                    <td><?= statusBadge($car['status']) ?></td>
                    <td>
                        <div class="d-flex gap-1">
                            <a href="view.php?id=<?= $car['id'] ?>" class="btn btn-xs btn-outline-primary" title="View"><i class="fa fa-eye"></i></a>
                            <?php if (canEditDelete()): ?>
                            <a href="edit.php?id=<?= $car['id'] ?>" class="btn btn-xs btn-outline-secondary" title="Edit"><i class="fa fa-pen"></i></a>
                            <a href="delete.php?id=<?= $car['id'] ?>" class="btn btn-xs btn-outline-danger confirm-delete" title="Delete"><i class="fa fa-trash"></i></a>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>

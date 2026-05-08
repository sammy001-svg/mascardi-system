<?php
require_once __DIR__ . '/../../includes/functions.php';
$pageTitle = 'Cars';
$db = getDB();

$status = $_GET['status'] ?? '';
$search = $_GET['q'] ?? '';

$where = ['1=1'];
$params = [];
if ($status) { $where[] = 'c.status = ?'; $params[] = $status; }
if ($search) { $where[] = '(c.chassis_number LIKE ? OR c.make LIKE ? OR c.model LIKE ? OR c.registration_number LIKE ?)'; $params = array_merge($params, ["%$search%","%$search%","%$search%","%$search%"]); }

$sql = "SELECT c.*, ci.intake_date, d.name AS driver_name
        FROM cars c
        LEFT JOIN car_intake ci ON ci.car_id = c.id
        LEFT JOIN car_transfers ct ON ct.car_id = c.id AND ct.status != 'arrived'
        LEFT JOIN drivers d ON d.id = ct.driver_id
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
            <div class="col-md-5">
                <input type="text" name="q" class="form-control form-control-sm" placeholder="Search chassis, make, model, reg..." value="<?= e($search) ?>">
            </div>
            <div class="col-md-4">
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
                    <th class="ps-3">#</th>
                    <th>Chassis No.</th>
                    <th>Reg. No.</th>
                    <th>Vehicle</th>
                    <th>Year</th>
                    <th>Color</th>
                    <th>Status</th>
                    <th>Intake Date</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($cars as $i => $car): ?>
                <tr>
                    <td class="ps-3"><?= $i + 1 ?></td>
                    <td><code><?= e($car['chassis_number']) ?></code></td>
                    <td><?= e($car['registration_number'] ?: '—') ?></td>
                    <td><?= e($car['make'] . ' ' . $car['model']) ?></td>
                    <td><?= e($car['year']) ?></td>
                    <td><?= e($car['color'] ?: '—') ?></td>
                    <td><?= statusBadge($car['status']) ?></td>
                    <td><?= fmtDate($car['intake_date']) ?></td>
                    <td>
                        <a href="view.php?id=<?= $car['id'] ?>" class="btn btn-xs btn-outline-primary" title="View"><i class="fa fa-eye"></i></a>
                        <a href="edit.php?id=<?= $car['id'] ?>" class="btn btn-xs btn-outline-secondary" title="Edit"><i class="fa fa-pen"></i></a>
                        <a href="delete.php?id=<?= $car['id'] ?>" class="btn btn-xs btn-outline-danger confirm-delete" title="Delete"><i class="fa fa-trash"></i></a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>

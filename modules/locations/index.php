<?php
require_once __DIR__ . '/../../includes/functions.php';
requireRole(['admin', 'manager']);
$pageTitle = 'Locations & Yards';
$db = getDB();

// Handle status toggle
if (isset($_GET['toggle'])) {
    $id = (int)$_GET['toggle'];
    $stmt = $db->prepare("UPDATE locations SET status = IF(status='active','inactive','active') WHERE id=?");
    $stmt->execute([$id]);
    logActivity('update', 'locations', $id, 'Toggled location status');
    setFlash('success', 'Location status updated.');
    redirect('index.php');
}

$locations = $db->query("SELECT l.*, (SELECT COUNT(*) FROM cars WHERE location_id = l.id) AS car_count FROM locations l ORDER BY l.name ASC")->fetchAll();

include __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h5 class="mb-0"><i class="fa fa-map-location-dot me-2 text-primary"></i>Locations &amp; Yards</h5>
    <a href="add.php" class="btn btn-primary btn-sm"><i class="fa fa-plus me-1"></i>Add Location</a>
</div>

<div class="card shadow-sm border-0">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 datatable">
                <thead>
                    <tr>
                        <th class="ps-4">Location Name</th>
                        <th>Type</th>
                        <th>Address</th>
                        <th class="text-center">Cars</th>
                        <th>Status</th>
                        <th class="text-end pe-4">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($locations as $l): ?>
                    <tr>
                        <td class="ps-4 fw-bold text-dark"><?= e($l['name']) ?></td>
                        <td>
                            <?php
                            $typeIcons = ['yard'=>'fa-warehouse','showroom'=>'fa-car-side','port'=>'fa-anchor','office'=>'fa-building'];
                            $icon = $typeIcons[$l['type']] ?? 'fa-map-marker-alt';
                            ?>
                            <i class="fa <?= $icon ?> me-2 text-muted"></i><?= ucfirst($l['type']) ?>
                        </td>
                        <td class="text-muted small"><?= e($l['address']) ?></td>
                        <td class="text-center">
                            <span class="badge bg-light text-primary border"><?= $l['car_count'] ?> vehicles</span>
                        </td>
                        <td><?= statusBadge($l['status']) ?></td>
                        <td class="text-end pe-4">
                            <div class="btn-group btn-group-xs">
                                <a href="edit.php?id=<?= $l['id'] ?>" class="btn btn-outline-secondary" title="Edit"><i class="fa fa-edit"></i></a>
                                <a href="?toggle=<?= $l['id'] ?>" class="btn btn-outline-<?= $l['status'] === 'active' ? 'warning' : 'success' ?>" title="<?= $l['status'] === 'active' ? 'Deactivate' : 'Activate' ?>">
                                    <i class="fa <?= $l['status'] === 'active' ? 'fa-ban' : 'fa-check' ?>"></i>
                                </a>
                                <?php if ($l['car_count'] == 0): ?>
                                <a href="delete.php?id=<?= $l['id'] ?>" class="btn btn-outline-danger" onclick="return confirm('Are you sure you want to delete this location?')" title="Delete"><i class="fa fa-trash"></i></a>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>

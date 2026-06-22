<?php
require_once __DIR__ . '/../../includes/functions.php';
$pageTitle = 'Cars';
$db = getDB();

$section = $_GET['section'] ?? 'inventory';
if (!in_array($section, ['inventory', 'client', 'workshop'])) $section = 'inventory';
$search = trim($_GET['q'] ?? '');

$where  = ['1=1'];
$params = [];

if ($section === 'workshop') {
    $where[] = "c.status = 'in_workshop'";
} elseif ($section === 'inventory') {
    $where[] = "c.car_type = 'inventory'";
} else {
    $where[] = "c.car_type = 'client'";
}

if ($search) {
    $where[] = '(c.chassis_number LIKE ? OR c.make LIKE ? OR c.model LIKE ? OR c.registration_number LIKE ? OR c.owner_name LIKE ?)';
    $params   = array_merge($params, ["%$search%","%$search%","%$search%","%$search%","%$search%"]);
}

$sql = "SELECT c.*, ci.intake_date, l.name AS location_name,
               (SELECT file_path FROM car_images WHERE car_id = c.id AND is_primary = 1 LIMIT 1) AS primary_image
        FROM cars c
        LEFT JOIN car_intake ci ON ci.car_id = c.id
        LEFT JOIN locations l   ON l.id = c.location_id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY c.created_at DESC";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$cars = $stmt->fetchAll();

$cntInv  = (int)$db->query("SELECT COUNT(*) FROM cars WHERE car_type='inventory'")->fetchColumn();
$cntCli  = (int)$db->query("SELECT COUNT(*) FROM cars WHERE car_type='client'")->fetchColumn();
$cntWork = (int)$db->query("SELECT COUNT(*) FROM cars WHERE status='in_workshop'")->fetchColumn();

include __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h5 class="mb-0">
        <i class="fa fa-car-side me-2 text-primary"></i>
        <?php
        echo $section === 'inventory' ? 'Mascardi Inventory'
           : ($section === 'client'    ? 'Client Cars'
           :                            'Workshop');
        ?>
        <span class="badge bg-secondary ms-2"><?= count($cars) ?></span>
    </h5>
    <?php if (canWrite('cars')): ?>
    <a href="<?= BASE_URL ?>/modules/cars/add.php" class="btn btn-primary btn-sm">
        <i class="fa fa-plus me-1"></i>Add Car
    </a>
    <?php endif; ?>
</div>

<!-- ── Section Buttons ──────────────────────────────────────────────────── -->
<div class="d-flex gap-2 mb-4 flex-wrap">
    <a href="?section=inventory<?= $search ? '&q='.urlencode($search) : '' ?>"
       class="btn btn-lg <?= $section === 'inventory' ? 'btn-primary' : 'btn-outline-primary' ?> flex-fill text-center" style="min-width:160px">
        <i class="fa fa-warehouse me-2"></i>Mascardi Inventory
        <span class="badge <?= $section === 'inventory' ? 'bg-white text-primary' : 'bg-primary' ?> ms-1"><?= $cntInv ?></span>
    </a>
    <a href="?section=client<?= $search ? '&q='.urlencode($search) : '' ?>"
       class="btn btn-lg <?= $section === 'client' ? 'btn-info text-dark' : 'btn-outline-info' ?> flex-fill text-center" style="min-width:160px">
        <i class="fa fa-user-tie me-2"></i>Client Cars
        <span class="badge <?= $section === 'client' ? 'bg-white text-dark' : 'bg-info' ?> ms-1"><?= $cntCli ?></span>
    </a>
    <a href="?section=workshop<?= $search ? '&q='.urlencode($search) : '' ?>"
       class="btn btn-lg <?= $section === 'workshop' ? 'btn-warning text-dark' : 'btn-outline-warning' ?> flex-fill text-center" style="min-width:160px">
        <i class="fa fa-screwdriver-wrench me-2"></i>Workshop
        <?php if ($cntWork): ?>
        <span class="badge <?= $section === 'workshop' ? 'bg-dark' : 'bg-warning text-dark border' ?> ms-1"><?= $cntWork ?></span>
        <?php endif; ?>
    </a>
</div>

<!-- ── Search ───────────────────────────────────────────────────────────── -->
<div class="card mb-3">
    <div class="card-body py-2 px-3">
        <form class="d-flex gap-2 align-items-center" method="GET">
            <input type="hidden" name="section" value="<?= e($section) ?>">
            <input type="text" name="q" class="form-control form-control-sm"
                   placeholder="Search chassis, make, model, owner…" value="<?= e($search) ?>" style="max-width:360px">
            <button type="submit" class="btn btn-sm btn-primary"><i class="fa fa-search me-1"></i>Search</button>
            <?php if ($search): ?>
            <a href="?section=<?= e($section) ?>" class="btn btn-sm btn-outline-secondary">Clear</a>
            <?php endif; ?>
        </form>
    </div>
</div>

<!-- ── Cars Table ───────────────────────────────────────────────────────── -->
<div class="card">
    <div class="card-body p-0">
        <?php if (empty($cars)): ?>
        <div class="text-center py-5 text-muted">
            <i class="fa fa-<?= $section === 'workshop' ? 'screwdriver-wrench' : 'car' ?> fa-3x mb-3 opacity-25"></i>
            <p class="mb-0">
                <?php
                if ($search) {
                    echo 'No cars matched your search.';
                } elseif ($section === 'workshop') {
                    echo 'No vehicles currently in the workshop.';
                } elseif ($section === 'client') {
                    echo 'No client cars on record.';
                } else {
                    echo 'No inventory cars on record.';
                }
                ?>
            </p>
        </div>
        <?php else: ?>
        <table class="table table-hover datatable mb-0">
            <thead>
                <tr>
                    <th class="ps-3">Vehicle</th>
                    <?php if ($section !== 'workshop'): ?>
                    <th>Type</th>
                    <?php else: ?>
                    <th>Owner / Type</th>
                    <?php endif; ?>
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
                        <div class="d-flex align-items-center gap-2">
                            <?php if ($car['primary_image']): ?>
                            <img src="<?= BASE_URL ?>/uploads/cars/<?= e($car['primary_image']) ?>"
                                 class="rounded border shadow-sm" style="width:50px;height:40px;object-fit:cover"
                                 loading="lazy" decoding="async" width="50" height="40">
                            <?php else: ?>
                            <div class="bg-light rounded border d-flex align-items-center justify-content-center text-muted"
                                 style="width:50px;height:40px;font-size:10px">NO IMG</div>
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
                        <?php if ($car['owner_name']): ?>
                        <div class="small text-muted"><?= e($car['owner_name']) ?></div>
                        <?php endif; ?>
                        <?php else: ?>
                        <span class="badge bg-primary">INVENTORY</span>
                        <?php endif; ?>
                    </td>
                    <td><code class="small"><?= e($car['chassis_number']) ?></code></td>
                    <td class="small text-muted">
                        <i class="fa fa-location-dot me-1"></i><?= e($car['location_name'] ?: '—') ?>
                    </td>
                    <td><?= statusBadge($car['status']) ?></td>
                    <td>
                        <div class="d-flex gap-1">
                            <a href="view.php?id=<?= $car['id'] ?>" class="btn btn-xs btn-outline-primary" title="View">
                                <i class="fa fa-eye"></i>
                            </a>
                            <?php if (canWrite('cars')): ?>
                            <a href="edit.php?id=<?= $car['id'] ?>" class="btn btn-xs btn-outline-secondary" title="Edit">
                                <i class="fa fa-pen"></i>
                            </a>
                            <?php endif; ?>
                            <?php if (canEditDelete()): ?>
                            <a href="delete.php?id=<?= $car['id'] ?>" class="btn btn-xs btn-outline-danger confirm-delete" title="Delete">
                                <i class="fa fa-trash"></i>
                            </a>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>

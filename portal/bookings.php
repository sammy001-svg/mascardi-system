<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/portal_auth.php';
$pageTitle = 'My Bookings';
$db     = getDB();
$client = portalClient();
$cid    = $client['id'];

$filterStatus = $_GET['status'] ?? '';
$validStatuses = ['pending','confirmed','in_progress','completed','cancelled'];

$sql = "SELECT sb.*, ca.make, ca.model, ca.year, ca.registration_number
        FROM service_bookings sb
        LEFT JOIN cars ca ON ca.id = sb.car_id
        WHERE sb.client_id = ?";
$params = [$cid];
if ($filterStatus && in_array($filterStatus, $validStatuses)) {
    $sql .= " AND sb.status = ?";
    $params[] = $filterStatus;
}
$sql .= " ORDER BY sb.created_at DESC";

$stmt = $db->prepare($sql); $stmt->execute($params);
$bookings = $stmt->fetchAll();

include __DIR__ . '/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h5 class="mb-1 fw-bold">My Bookings</h5>
        <div class="text-muted small"><?= count($bookings) ?> booking<?= count($bookings) != 1 ? 's' : '' ?> found</div>
    </div>
</div>

<!-- Status Filter -->
<div class="p-card mb-4">
    <div class="p-card-body py-2">
        <div class="d-flex gap-2 flex-wrap">
            <a href="bookings.php" class="btn btn-sm <?= !$filterStatus ? 'btn-dark' : 'btn-outline-secondary' ?>">All</a>
            <?php foreach (['pending' => 'warning', 'confirmed' => 'success', 'in_progress' => 'primary', 'completed' => 'success', 'cancelled' => 'danger'] as $s => $c): ?>
            <a href="bookings.php?status=<?= $s ?>"
               class="btn btn-sm <?= $filterStatus === $s ? 'btn-'.$c : 'btn-outline-secondary' ?>">
                <?= ucwords(str_replace('_', ' ', $s)) ?>
            </a>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<?php if (empty($bookings)): ?>
<div class="p-card">
    <div class="p-card-body text-center py-5 text-muted">
        <i class="fa fa-calendar-xmark fa-2x mb-3 d-block"></i>
        No bookings found<?= $filterStatus ? ' with status "' . e(str_replace('_', ' ', $filterStatus)) . '"' : '' ?>.
    </div>
</div>
<?php else: ?>
<div class="p-card">
    <table class="table table-hover mb-0">
        <thead style="font-size:12px;color:#64748b;text-transform:uppercase;letter-spacing:.04em">
            <tr>
                <th class="ps-4 py-3">Booking #</th>
                <th class="py-3">Booking Date</th>
                <th class="py-3">Preferred Date</th>
                <th class="py-3">Service</th>
                <th class="py-3">Vehicle</th>
                <th class="py-3">Status</th>
                <th class="py-3 pe-4"></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($bookings as $bk): ?>
            <tr>
                <td class="ps-4 py-3 fw-semibold small"><?= e($bk['booking_number']) ?></td>
                <td class="py-3 small text-muted"><?= fmtDate($bk['booking_date']) ?></td>
                <td class="py-3 small"><?= $bk['preferred_date'] ? fmtDate($bk['preferred_date']) . ($bk['preferred_time'] ? ' ' . date('g:ia', strtotime($bk['preferred_time'])) : '') : '—' ?></td>
                <td class="py-3 small"><?= e($bk['service_type'] ?: '—') ?></td>
                <td class="py-3 small">
                    <?php if ($bk['make']): ?>
                    <?= e($bk['make'] . ' ' . $bk['model'] . ' ' . $bk['year']) ?>
                    <?php elseif ($bk['car_make']): ?>
                    <?= e($bk['car_make'] . ' ' . $bk['car_model']) ?>
                    <?php else: ?>—<?php endif; ?>
                </td>
                <td class="py-3"><?= statusBadge($bk['status']) ?></td>
                <td class="py-3 pe-4 text-end">
                    <a href="booking_view.php?id=<?= $bk['id'] ?>" class="btn btn-xs btn-outline-primary">View</a>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<?php include __DIR__ . '/footer.php'; ?>

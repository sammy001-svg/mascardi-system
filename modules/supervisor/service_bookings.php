<?php
require_once __DIR__ . '/../../includes/functions.php';
requireRole('supervisor');
$pageTitle = 'Service Bookings';
$db    = getDB();
$locId = supervisorLocationId();

if (!$locId) { header('Location: ' . BASE_URL . '/modules/supervisor/dashboard.php'); exit; }

$location = $db->prepare("SELECT name FROM locations WHERE id=?");
$location->execute([$locId]);
$locName = $location->fetchColumn() ?: 'Location';

$fStatus = $_GET['status'] ?? '';
$fSearch  = trim($_GET['q'] ?? '');

$where  = "(c.location_id=? OR sb.intake_location_id=?)";
$params = [$locId, $locId];

if ($fStatus) { $where .= " AND sb.status=?"; $params[] = $fStatus; }
if ($fSearch) {
    $where .= " AND (sb.client_name LIKE ? OR sb.client_phone LIKE ? OR sb.booking_number LIKE ?)";
    $s = "%{$fSearch}%";
    $params = array_merge($params, [$s, $s, $s]);
}

try {
    $stmt = $db->prepare("
        SELECT sb.*, c.make, c.model, c.registration_number
        FROM service_bookings sb
        LEFT JOIN cars c ON c.id = sb.car_id
        WHERE {$where}
        ORDER BY sb.preferred_date DESC, sb.created_at DESC
    ");
    $stmt->execute($params);
    $bookings = $stmt->fetchAll();
} catch (\Throwable $_) { $bookings = []; }

include __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div>
        <h5 class="mb-0"><i class="fa fa-calendar-check me-2 text-primary"></i>Service Bookings — <span class="text-primary"><?= e($locName) ?></span></h5>
        <div class="text-muted small"><?= count($bookings) ?> booking<?= count($bookings) !== 1 ? 's' : '' ?></div>
    </div>
    <a href="<?= BASE_URL ?>/modules/supervisor/dashboard.php" class="btn btn-sm btn-outline-secondary"><i class="fa fa-arrow-left me-1"></i>Dashboard</a>
</div>

<form method="GET" class="card card-body mb-3 py-2">
    <div class="row g-2 align-items-end">
        <div class="col-md-5">
            <input type="text" name="q" class="form-control form-control-sm" placeholder="Search client, phone, booking #…" value="<?= e($fSearch) ?>">
        </div>
        <div class="col-md-4">
            <select name="status" class="form-select form-select-sm">
                <option value="">All Statuses</option>
                <?php foreach (['pending','confirmed','completed','cancelled'] as $st): ?>
                <option value="<?= $st ?>" <?= $fStatus === $st ? 'selected' : '' ?>><?= ucfirst($st) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3 d-flex gap-2">
            <button class="btn btn-primary btn-sm flex-fill"><i class="fa fa-search me-1"></i>Filter</button>
            <a href="?" class="btn btn-outline-secondary btn-sm"><i class="fa fa-rotate-right"></i></a>
        </div>
    </div>
</form>

<div class="card">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0 datatable">
                <thead>
                    <tr>
                        <th class="ps-3">Booking #</th>
                        <th>Client</th>
                        <th>Vehicle</th>
                        <th>Service Type</th>
                        <th>Date</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($bookings as $bk): ?>
                    <tr>
                        <td class="ps-3"><code style="font-size:11px"><?= e($bk['booking_number'] ?? '—') ?></code></td>
                        <td>
                            <div class="fw-medium small"><?= e($bk['client_name']) ?></div>
                            <?php if ($bk['client_phone']): ?>
                            <div class="text-muted" style="font-size:11px"><?= e($bk['client_phone']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="small text-muted"><?= e(trim($bk['make'] . ' ' . $bk['model'])) ?: '—' ?>
                            <?php if ($bk['registration_number']): ?><br><code style="font-size:10px"><?= e($bk['registration_number']) ?></code><?php endif; ?>
                        </td>
                        <td class="small"><?= e(implode(', ', array_slice(explode(', ', $bk['service_type'] ?? ''), 0, 2))) ?></td>
                        <td class="small text-muted"><?= $bk['preferred_date'] ? fmtDate($bk['preferred_date'], 'd M Y') : '—' ?></td>
                        <td><?= statusBadge($bk['status']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($bookings)): ?>
                    <tr><td colspan="6" class="text-center py-5 text-muted">
                        <i class="fa fa-calendar fa-2x mb-2 d-block opacity-25"></i>No bookings found for this location.
                    </td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>

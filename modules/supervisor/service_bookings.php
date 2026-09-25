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

$fStatus   = $_GET['status'] ?? '';
$fSearch   = trim($_GET['q'] ?? '');
$fRange    = $_GET['range'] ?? '';
$fDateFrom = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date_from'] ?? '') ? $_GET['date_from'] : '';
$fDateTo   = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date_to']   ?? '') ? $_GET['date_to']   : '';

if ($fRange === 'today') {
    $fDateFrom = date('Y-m-d');
    $fDateTo   = date('Y-m-d');
} elseif ($fRange === 'this_week') {
    $fDateFrom = date('Y-m-d', strtotime('monday this week'));
    $fDateTo   = date('Y-m-d', strtotime('sunday this week'));
} elseif ($fRange === 'this_month') {
    $fDateFrom = date('Y-m-01');
    $fDateTo   = date('Y-m-t');
}

$where  = "(c.location_id IN (SELECT id FROM locations WHERE id=? OR parent_id=?) OR sb.intake_location_id IN (SELECT id FROM locations WHERE id=? OR parent_id=?))";
$params = [$locId, $locId, $locId, $locId];

if ($fStatus) { $where .= " AND sb.status=?"; $params[] = $fStatus; }
if ($fSearch) {
    $where .= " AND (sb.client_name LIKE ? OR sb.client_phone LIKE ? OR sb.booking_number LIKE ? OR c.registration_number LIKE ? OR sb.car_registration LIKE ?)";
    $s = "%{$fSearch}%";
    $params = array_merge($params, [$s, $s, $s, $s, $s]);
}
if ($fDateFrom) {
    $where .= " AND COALESCE(sb.preferred_date, sb.booking_date, DATE(sb.created_at)) >= ?";
    $params[] = $fDateFrom;
}
if ($fDateTo) {
    $where .= " AND COALESCE(sb.preferred_date, sb.booking_date, DATE(sb.created_at)) <= ?";
    $params[] = $fDateTo;
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
        <div class="col-md-3">
            <label class="form-label mb-1 text-muted" style="font-size:11px;font-weight:600">Search</label>
            <input type="text" name="q" class="form-control form-control-sm" placeholder="Search client, phone, booking #…" value="<?= e($fSearch) ?>">
        </div>
        <div class="col-md-2 col-sm-6">
            <label class="form-label mb-1 text-muted" style="font-size:11px;font-weight:600">Status</label>
            <select name="status" class="form-select form-select-sm">
                <option value="">All Statuses</option>
                <?php foreach (['pending','confirmed','in_progress','completed','cancelled'] as $st): ?>
                <option value="<?= $st ?>" <?= $fStatus === $st ? 'selected' : '' ?>><?= ucfirst(str_replace('_', ' ', $st)) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2 col-sm-6">
            <label class="form-label mb-1 text-muted" style="font-size:11px;font-weight:600">Quick Preset</label>
            <select name="range" class="form-select form-select-sm" onchange="this.form.submit()">
                <option value="">All Dates</option>
                <option value="today" <?= $fRange === 'today' ? 'selected' : '' ?>>Today</option>
                <option value="this_week" <?= $fRange === 'this_week' ? 'selected' : '' ?>>This Week</option>
                <option value="this_month" <?= $fRange === 'this_month' ? 'selected' : '' ?>>This Month</option>
                <option value="custom" <?= ($fRange === 'custom' || ($fDateFrom && !in_array($fRange, ['today','this_week','this_month']))) ? 'selected' : '' ?>>Custom Range</option>
            </select>
        </div>
        <div class="col-md-2 col-sm-6">
            <label class="form-label mb-1 text-muted" style="font-size:11px;font-weight:600">From Date</label>
            <input type="date" name="date_from" class="form-control form-control-sm" value="<?= e($fDateFrom) ?>">
        </div>
        <div class="col-md-2 col-sm-6">
            <label class="form-label mb-1 text-muted" style="font-size:11px;font-weight:600">To Date</label>
            <input type="date" name="date_to" class="form-control form-control-sm" value="<?= e($fDateTo) ?>">
        </div>
        <div class="col-md-1 d-flex gap-1">
            <button type="submit" class="btn btn-primary btn-sm flex-fill" title="Filter"><i class="fa fa-filter"></i></button>
            <a href="service_bookings.php" class="btn btn-outline-secondary btn-sm" title="Reset Filters"><i class="fa fa-rotate-right"></i></a>
        </div>
    </div>
    <?php if ($fDateFrom || $fDateTo || $fRange): ?>
    <div class="mt-2 d-flex align-items-center gap-2" style="font-size:12px">
        <span class="badge bg-primary-subtle text-primary border border-primary-subtle">
            <i class="fa fa-calendar-days me-1"></i>
            <?php if ($fDateFrom && $fDateTo): ?>
                Filter: <?= fmtDate($fDateFrom) ?> — <?= fmtDate($fDateTo) ?>
            <?php elseif ($fDateFrom): ?>
                Filter: From <?= fmtDate($fDateFrom) ?>
            <?php else: ?>
                Filter: Up to <?= fmtDate($fDateTo) ?>
            <?php endif; ?>
        </span>
        <a href="service_bookings.php" class="text-muted text-decoration-none" style="font-size:11px"><i class="fa fa-times me-1"></i>Clear date filter</a>
    </div>
    <?php endif; ?>
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

<?php
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
canAccess('service_bookings') || die('Access denied.');
$pageTitle = 'Service Bookings';
$db   = getDB();
$user = authUser();

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

$where  = ['1=1'];
$params = [];

if ($fStatus)   { $where[] = 'sb.status = ?'; $params[] = $fStatus; }
if ($fSearch)  {
    $where[]  = '(sb.client_name LIKE ? OR sb.client_phone LIKE ? OR sb.booking_number LIKE ? OR ca.registration_number LIKE ? OR sb.car_registration LIKE ?)';
    $s = "%{$fSearch}%";
    $params   = array_merge($params, [$s, $s, $s, $s, $s]);
}
if ($fDateFrom) { $where[] = 'COALESCE(sb.preferred_date, sb.booking_date, DATE(sb.created_at)) >= ?'; $params[] = $fDateFrom; }
if ($fDateTo)   { $where[] = 'COALESCE(sb.preferred_date, sb.booking_date, DATE(sb.created_at)) <= ?'; $params[] = $fDateTo; }

$whereStr = implode(' AND ', $where);

try {
    $stmt = $db->prepare("
        SELECT sb.*, c.name AS client_name_link, ca.make, ca.model, ca.chassis_number, ca.registration_number
        FROM service_bookings sb
        LEFT JOIN clients c  ON c.id  = sb.client_id
        LEFT JOIN cars ca    ON ca.id = sb.car_id
        WHERE {$whereStr}
        ORDER BY sb.preferred_date DESC, sb.created_at DESC
    ");
    $stmt->execute($params);
    $bookings = $stmt->fetchAll();
} catch (\Throwable $_) { $bookings = []; }

$statusColors = ['pending'=>'warning','confirmed'=>'info','in_progress'=>'primary','completed'=>'success','cancelled'=>'danger'];
$pending = array_filter($bookings, fn($b) => $b['status'] === 'pending');

include __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div>
        <h5 class="mb-1"><i class="fa fa-calendar-check me-2 text-primary"></i>Service Bookings</h5>
        <div class="text-muted small"><?= count($pending) ?> pending booking<?= count($pending) !== 1 ? 's' : '' ?></div>
    </div>
    <a href="add.php" class="btn btn-primary btn-sm"><i class="fa fa-plus me-1"></i>New Booking</a>
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
            <a href="index.php" class="btn btn-outline-secondary btn-sm" title="Reset Filters"><i class="fa fa-rotate-right"></i></a>
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
        <a href="index.php" class="text-muted text-decoration-none" style="font-size:11px"><i class="fa fa-times me-1"></i>Clear date filter</a>
    </div>
    <?php endif; ?>
</form>

<?php if (count($pending)): ?>
<div class="alert alert-warning d-flex align-items-center gap-2 mb-3">
    <i class="fa fa-bell"></i>
    <span><?= count($pending) ?> service booking<?= count($pending)!==1?'s':'' ?> awaiting confirmation.</span>
</div>
<?php endif; ?>

<div class="card">
    <div class="card-body p-0">
        <table class="table table-hover datatable mb-0">
            <thead>
                <tr>
                    <th class="ps-3">Booking #</th>
                    <th>Client</th>
                    <th>Phone</th>
                    <th>Vehicle</th>
                    <th>Service</th>
                    <th>Preferred</th>
                    <th>Status</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($bookings as $b): ?>
                <tr>
                    <td class="ps-3 fw-bold">
                        <a href="view.php?id=<?= $b['id'] ?>"><?= e($b['booking_number']) ?></a>
                        <div class="text-muted" style="font-size:11px"><?= fmtDate($b['booking_date']) ?></div>
                    </td>
                    <td class="fw-medium small"><?= e($b['client_name']) ?></td>
                    <td class="text-muted small">
                        <?php if ($b['client_phone']): ?>
                        <i class="fa-brands fa-whatsapp text-success me-1"></i><?= e($b['client_phone']) ?>
                        <?php else: ?>—<?php endif; ?>
                    </td>
                    <td class="text-muted small">
                        <?php
                        $vLabel = trim(($b['car_make'] ?: $b['make']).' '.($b['car_model'] ?: $b['model']));
                        $vReg   = $b['car_registration'] ?: $b['registration_number'] ?? '';
                        echo e($vLabel ?: $b['car_description'] ?: '—');
                        if ($vReg) echo ' <span class="badge bg-dark ms-1">'.e($vReg).'</span>';
                        ?>
                    </td>
                    <td class="text-muted small"><?= e($b['service_type'] ?? '—') ?></td>
                    <td class="text-muted small"><?= $b['preferred_date'] ? fmtDate($b['preferred_date']) : '—' ?></td>
                    <td><span class="badge bg-<?= $statusColors[$b['status']] ?? 'secondary' ?>"><?= ucwords(str_replace('_',' ',$b['status'])) ?></span></td>
                    <td>
                        <a href="view.php?id=<?= $b['id'] ?>" class="btn btn-xs btn-outline-primary"><i class="fa fa-eye"></i></a>
                        <?php if (hasRole('admin')): ?>
                        <a href="delete.php?id=<?= $b['id'] ?>" class="btn btn-xs btn-outline-danger"
                           onclick="return confirm('Delete booking <?= e($b['booking_number']) ?>? This cannot be undone.')">
                            <i class="fa fa-trash"></i>
                        </a>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>

<?php
require_once __DIR__ . '/../../includes/functions.php';
requireRole('supervisor');
$pageTitle = 'Invoices';
$db    = getDB();

// Supervisors see ALL invoices company-wide (not location-scoped) —
// unlike cars/bookings/assessments, which remain scoped to their location.
$fStatus = $_GET['status'] ?? '';
$fSearch  = trim($_GET['q'] ?? '');

$where  = "1=1";
$params = [];
if ($fStatus) { $where .= " AND i.status=?"; $params[] = $fStatus; }
if ($fSearch) {
    $where .= " AND (i.invoice_number LIKE ? OR i.customer_name LIKE ?)";
    $s = "%{$fSearch}%";
    $params = array_merge($params, [$s, $s]);
}

try {
    $stmt = $db->prepare("
        SELECT i.*, c.make, c.model
        FROM invoices i
        LEFT JOIN cars c ON c.id = i.car_id
        WHERE {$where}
        ORDER BY i.created_at DESC
    ");
    $stmt->execute($params);
    $invoices = $stmt->fetchAll();
} catch (\Throwable $_) { $invoices = []; }

include __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div>
        <h5 class="mb-0"><i class="fa fa-file-invoice-dollar me-2 text-primary"></i>Invoices — <span class="text-primary">All Locations</span></h5>
        <div class="text-muted small"><?= count($invoices) ?> invoice<?= count($invoices) !== 1 ? 's' : '' ?> company-wide</div>
    </div>
    <a href="<?= BASE_URL ?>/modules/supervisor/dashboard.php" class="btn btn-sm btn-outline-secondary"><i class="fa fa-arrow-left me-1"></i>Dashboard</a>
</div>

<form method="GET" class="card card-body mb-3 py-2">
    <div class="row g-2 align-items-end">
        <div class="col-md-5">
            <input type="text" name="q" class="form-control form-control-sm" placeholder="Search invoice #, client…" value="<?= e($fSearch) ?>">
        </div>
        <div class="col-md-4">
            <select name="status" class="form-select form-select-sm">
                <option value="">All Statuses</option>
                <?php foreach (['unpaid','paid','cancelled','partial'] as $st): ?>
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
                        <th class="ps-3">Invoice #</th>
                        <th>Client</th>
                        <th>Vehicle</th>
                        <th>Total</th>
                        <th>Status</th>
                        <th>Date</th>
                        <th class="pe-3">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($invoices as $inv): ?>
                    <tr>
                        <td class="ps-3"><code style="font-size:11px"><?= e($inv['invoice_number'] ?? '—') ?></code></td>
                        <td class="fw-medium small"><?= e($inv['customer_name'] ?? '—') ?></td>
                        <td class="small text-muted"><?= e(trim($inv['make'] . ' ' . $inv['model'])) ?: '—' ?></td>
                        <td class="fw-semibold small"><?= money($inv['total'] ?? 0) ?></td>
                        <td><?= statusBadge($inv['status']) ?></td>
                        <td class="small text-muted"><?= fmtDate($inv['created_at'], 'd M Y') ?></td>
                        <td class="text-end pe-3">
                            <a href="<?= BASE_URL ?>/modules/invoices/view.php?id=<?= $inv['id'] ?>" class="btn btn-xs btn-outline-primary">
                                <i class="fa fa-eye me-1"></i>View
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($invoices)): ?>
                    <tr><td colspan="7" class="text-center py-5 text-muted">
                        <i class="fa fa-file-invoice fa-2x mb-2 d-block opacity-25"></i>No invoices found.
                    </td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>

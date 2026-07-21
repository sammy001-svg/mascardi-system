<?php
require_once __DIR__ . '/../../includes/functions.php';
requireRole('supervisor');
$pageTitle = 'Quotations';
$db    = getDB();

// Supervisors see ALL quotations company-wide (not location-scoped) —
// unlike cars/bookings/assessments, which remain scoped to their location.
$fStatus = $_GET['status'] ?? '';
$fSearch  = trim($_GET['q'] ?? '');

$where  = "1=1";
$params = [];
if ($fStatus) { $where .= " AND q.status=?"; $params[] = $fStatus; }
if ($fSearch) {
    $where .= " AND (q.quotation_number LIKE ? OR q.customer_name LIKE ?)";
    $s = "%{$fSearch}%";
    $params = array_merge($params, [$s, $s]);
}

try {
    $stmt = $db->prepare("
        SELECT q.*, c.make, c.model
        FROM quotations q
        LEFT JOIN cars c ON c.id = q.car_id
        WHERE {$where}
        ORDER BY q.created_at DESC
    ");
    $stmt->execute($params);
    $quotations = $stmt->fetchAll();
} catch (\Throwable $_) { $quotations = []; }

include __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div>
        <h5 class="mb-0"><i class="fa fa-file-lines me-2 text-primary"></i>Quotations — <span class="text-primary">All Locations</span></h5>
        <div class="text-muted small"><?= count($quotations) ?> quotation<?= count($quotations) !== 1 ? 's' : '' ?> company-wide</div>
    </div>
    <a href="<?= BASE_URL ?>/modules/supervisor/dashboard.php" class="btn btn-sm btn-outline-secondary"><i class="fa fa-arrow-left me-1"></i>Dashboard</a>
</div>

<form method="GET" class="card card-body mb-3 py-2">
    <div class="row g-2 align-items-end">
        <div class="col-md-5">
            <input type="text" name="q" class="form-control form-control-sm" placeholder="Search quote #, client…" value="<?= e($fSearch) ?>">
        </div>
        <div class="col-md-4">
            <select name="status" class="form-select form-select-sm">
                <option value="">All Statuses</option>
                <?php foreach (['draft','sent','accepted','rejected','expired'] as $st): ?>
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
                        <th class="ps-3">Quote #</th>
                        <th>Client</th>
                        <th>Vehicle</th>
                        <th>Total</th>
                        <th>Status</th>
                        <th>Date</th>
                        <th class="pe-3">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($quotations as $q): ?>
                    <tr>
                        <td class="ps-3"><code style="font-size:11px"><?= e($q['quotation_number'] ?? '—') ?></code></td>
                        <td class="fw-medium small"><?= e($q['customer_name'] ?? '—') ?></td>
                        <td class="small text-muted"><?= e(trim($q['make'] . ' ' . $q['model'])) ?: '—' ?></td>
                        <td class="fw-semibold small"><?= money($q['total'] ?? 0) ?></td>
                        <td><?= statusBadge($q['status']) ?></td>
                        <td class="small text-muted"><?= fmtDate($q['created_at'], 'd M Y') ?></td>
                        <td class="text-end pe-3">
                            <a href="<?= BASE_URL ?>/modules/quotations/view.php?id=<?= $q['id'] ?>" class="btn btn-xs btn-outline-primary">
                                <i class="fa fa-eye me-1"></i>View
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($quotations)): ?>
                    <tr><td colspan="7" class="text-center py-5 text-muted">
                        <i class="fa fa-file-lines fa-2x mb-2 d-block opacity-25"></i>No quotations found.
                    </td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>

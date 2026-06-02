<?php
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
canAccess('parts_requests') || die('Access denied.');
$db   = getDB();
$user = authUser();
$role = $user['role'];

$id = (int)($_GET['id'] ?? 0);
if (!$id) redirect(BASE_URL . '/modules/parts_requests/index.php');

$stmt = $db->prepare("
    SELECT pr.*,
           qa.assessment_number, qa.assessment_date,
           m.name AS mechanic_name
    FROM parts_requests pr
    LEFT JOIN quick_assessments qa ON qa.id = pr.quick_assessment_id
    LEFT JOIN mechanics m ON m.id = pr.mechanic_id
    WHERE pr.id = ?
");
$stmt->execute([$id]);
$req = $stmt->fetch();
if (!$req) { setFlash('error', 'Request not found.'); redirect(BASE_URL . '/modules/parts_requests/index.php'); }

$items = $db->prepare("
    SELECT pri.*, i.quantity AS stock_qty
    FROM parts_request_items pri
    LEFT JOIN inventory i ON i.id = pri.inventory_id
    WHERE pri.request_id = ?
    ORDER BY pri.id
");
$items->execute([$id]);
$items = $items->fetchAll();

// ── Approve / Reject / Issue ─────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && hasRole(['admin','workshop_manager','manager'])) {
    $action     = $_POST['action'] ?? '';
    $adminNotes = trim($_POST['admin_notes'] ?? '');

    if ($action === 'approved' || $action === 'rejected') {
        $db->prepare("UPDATE parts_requests SET status=?, admin_notes=?, approved_by=?, updated_at=NOW() WHERE id=?")
           ->execute([$action, $adminNotes, $user['name'], $id]);
        setFlash('success', 'Request ' . $action . '.');
        redirect(BASE_URL . '/modules/parts_requests/view.php?id=' . $id);
    }

    if ($action === 'issued') {
        $db->beginTransaction();
        try {
            foreach ($items as $it) {
                $issued = (float)($_POST['issued_qty'][$it['id']] ?? $it['quantity_requested']);
                if ($issued <= 0) continue;
                $db->prepare("UPDATE parts_request_items SET quantity_issued=? WHERE id=?")
                   ->execute([$issued, $it['id']]);
                if ($it['inventory_id']) {
                    $db->prepare("UPDATE inventory SET quantity = quantity - ? WHERE id=?")
                       ->execute([$issued, $it['inventory_id']]);
                    $nq = (float)$db->query("SELECT quantity FROM inventory WHERE id={$it['inventory_id']}")->fetchColumn();
                    $db->prepare("INSERT INTO inventory_transactions (inventory_id, transaction_type, quantity, balance, reference_type, reference_id, notes, created_by) VALUES (?,?,?,?,?,?,?,?)")
                       ->execute([$it['inventory_id'], 'out', $issued, $nq, 'parts_request', $id, 'Issued for ' . $req['request_number'], $user['name']]);
                }
            }
            $db->prepare("UPDATE parts_requests SET status='issued', admin_notes=?, approved_by=?, updated_at=NOW() WHERE id=?")
               ->execute([$adminNotes ?: $req['admin_notes'], $user['name'], $id]);
            $db->commit();
            setFlash('success', 'Parts issued and stock updated.');
            redirect(BASE_URL . '/modules/parts_requests/view.php?id=' . $id);
        } catch (\Throwable $e) {
            $db->rollBack();
            setFlash('error', 'Issue failed: ' . $e->getMessage());
        }
    }
}

$statusMeta = [
    'pending'  => ['warning', 'fa-clock',        'Pending'],
    'approved' => ['success', 'fa-check-circle',  'Approved'],
    'rejected' => ['danger',  'fa-times-circle',  'Rejected'],
    'issued'   => ['info',    'fa-box-open',      'Issued'],
];
[$statusColor, $statusIcon, $statusLabel] = $statusMeta[$req['status']] ?? ['secondary','fa-question','Unknown'];

$pageTitle = 'Quote Request ' . $req['request_number'];
include __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <h5 class="mb-1">
            <i class="fa fa-file-invoice me-2 text-primary"></i><?= e($req['request_number']) ?>
        </h5>
        <div class="text-muted small">
            Submitted by <strong><?= e($user['name']) ?></strong>
            on <?= fmtDate($req['created_at'], 'd M Y, H:i') ?>
        </div>
    </div>
    <div class="d-flex align-items-center gap-2">
        <span class="badge bg-<?= $statusColor ?> fs-6 px-3 py-2">
            <i class="fa <?= $statusIcon ?> me-1"></i><?= $statusLabel ?>
        </span>
        <a href="index.php" class="btn btn-sm btn-outline-secondary"><i class="fa fa-arrow-left me-1"></i>Back</a>
    </div>
</div>

<!-- Client + Vehicle row -->
<div class="row g-4 mb-4">
    <div class="col-md-6">
        <div class="card h-100">
            <div class="card-header fw-semibold"><i class="fa fa-user me-2 text-primary"></i>Client</div>
            <div class="card-body">
                <?php if ($req['client_name'] || $req['client_phone'] || $req['client_email']): ?>
                <div class="fw-bold fs-6 mb-1"><?= e($req['client_name'] ?: 'Walk-in') ?></div>
                <?php if ($req['client_phone']): ?>
                <div class="text-muted small mb-1">
                    <i class="fa fa-phone me-1"></i>
                    <a href="tel:<?= e($req['client_phone']) ?>"><?= e($req['client_phone']) ?></a>
                </div>
                <?php endif; ?>
                <?php if ($req['client_email']): ?>
                <div class="text-muted small">
                    <i class="fa fa-envelope me-1"></i>
                    <a href="mailto:<?= e($req['client_email']) ?>"><?= e($req['client_email']) ?></a>
                </div>
                <?php endif; ?>
                <?php else: ?>
                <span class="text-muted">No client details recorded.</span>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card h-100">
            <div class="card-header fw-semibold"><i class="fa fa-car me-2 text-primary"></i>Vehicle</div>
            <div class="card-body">
                <?php if ($req['car_make'] || $req['car_model'] || $req['car_registration']): ?>
                <div class="fw-bold fs-6 mb-1"><?= e(trim(($req['car_make'] ?? '') . ' ' . ($req['car_model'] ?? ''))) ?></div>
                <div class="d-flex flex-wrap gap-3 mt-2">
                    <?php if ($req['car_registration']): ?>
                    <div>
                        <div class="text-muted" style="font-size:11px;text-transform:uppercase;letter-spacing:.5px">Registration</div>
                        <span class="badge bg-dark"><?= e($req['car_registration']) ?></span>
                    </div>
                    <?php endif; ?>
                    <?php if ($req['car_chassis']): ?>
                    <div>
                        <div class="text-muted" style="font-size:11px;text-transform:uppercase;letter-spacing:.5px">Chassis No.</div>
                        <code class="small"><?= e($req['car_chassis']) ?></code>
                    </div>
                    <?php endif; ?>
                </div>
                <?php else: ?>
                <span class="text-muted">No vehicle details recorded.</span>
                <?php endif; ?>
                <?php if ($req['assessment_number']): ?>
                <div class="mt-3 pt-2 border-top">
                    <span class="text-muted small">Linked Assessment: </span>
                    <a href="<?= BASE_URL ?>/modules/quick_assessments/view.php?id=<?= $req['quick_assessment_id'] ?>" class="fw-medium text-decoration-none">
                        <i class="fa fa-magnifying-glass-chart me-1 text-primary"></i><?= e($req['assessment_number']) ?>
                    </a>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Parts table -->
<div class="card mb-4">
    <div class="card-header fw-semibold"><i class="fa fa-list-ul me-2"></i>Parts for Quotation</div>

    <?php if ($req['status'] === 'approved' && hasRole(['admin','workshop_manager','manager'])): ?>
    <!-- Issue form -->
    <form method="POST">
        <input type="hidden" name="action" value="issued">
        <div class="card-body p-0">
            <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="ps-4">#</th>
                        <th>Part No.</th>
                        <th>Part Name</th>
                        <th>Requested Qty</th>
                        <th>In Stock</th>
                        <th>Issue Qty</th>
                        <th>Note</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($items as $idx => $it): ?>
                    <tr>
                        <td class="ps-4 text-muted"><?= $idx + 1 ?></td>
                        <td><code class="small"><?= $it['part_number'] ? e($it['part_number']) : '—' ?></code></td>
                        <td class="fw-medium"><?= e($it['part_name']) ?></td>
                        <td><?= number_format($it['quantity_requested'], 2) ?></td>
                        <td>
                            <?php if ($it['inventory_id']): ?>
                            <span class="<?= (float)$it['stock_qty'] < (float)$it['quantity_requested'] ? 'text-danger fw-semibold' : 'text-success' ?>">
                                <?= number_format((float)$it['stock_qty'], 2) ?>
                            </span>
                            <?php else: ?><span class="text-muted small">—</span><?php endif; ?>
                        </td>
                        <td style="width:120px">
                            <input type="number" name="issued_qty[<?= $it['id'] ?>]"
                                   class="form-control form-control-sm"
                                   value="<?= number_format($it['quantity_requested'], 2) ?>"
                                   min="0" step="0.01">
                        </td>
                        <td class="text-muted small"><?= $it['notes'] ? e($it['notes']) : '—' ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        </div>
        <div class="card-body border-top">
            <div class="row g-3 align-items-end">
                <div class="col-md-8">
                    <label class="form-label">Issue Note</label>
                    <input type="text" name="admin_notes" class="form-control"
                           placeholder="e.g. Parts collected from store room B"
                           value="<?= e($req['admin_notes'] ?? '') ?>">
                </div>
                <div class="col-md-4 text-end">
                    <button type="submit" class="btn btn-info px-4">
                        <i class="fa fa-box-open me-2"></i>Issue &amp; Deduct Stock
                    </button>
                </div>
            </div>
        </div>
    </form>

    <?php else: ?>
    <!-- Read-only view -->
    <div class="card-body p-0">
        <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead class="table-light">
                <tr>
                    <th class="ps-4">#</th>
                    <th>Part No.</th>
                    <th>Part Name</th>
                    <th>QTY</th>
                    <?php if ($req['status'] === 'issued'): ?><th>Issued</th><?php endif; ?>
                    <th>Note</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($items as $idx => $it): ?>
                <tr>
                    <td class="ps-4 text-muted"><?= $idx + 1 ?></td>
                    <td><code class="small"><?= $it['part_number'] ? e($it['part_number']) : '—' ?></code></td>
                    <td class="fw-medium"><?= e($it['part_name']) ?></td>
                    <td><?= number_format($it['quantity_requested'], 2) ?></td>
                    <?php if ($req['status'] === 'issued'): ?>
                    <td class="fw-semibold text-info"><?= number_format((float)$it['quantity_issued'], 2) ?></td>
                    <?php endif; ?>
                    <td class="text-muted small"><?= $it['notes'] ? e($it['notes']) : '—' ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- Notes / Admin Response -->
<div class="row g-4 mb-4">
    <div class="col-md-6">
        <div class="card h-100">
            <div class="card-header">Request Notes</div>
            <div class="card-body">
                <p class="mb-0 text-muted"><?= $req['notes'] ? nl2br(e($req['notes'])) : 'No notes.' ?></p>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card h-100">
            <div class="card-header">Response</div>
            <div class="card-body">
                <?php if ($req['admin_notes']): ?>
                <p class="mb-1"><?= nl2br(e($req['admin_notes'])) ?></p>
                <?php if ($req['approved_by']): ?>
                <div class="text-muted small">— <?= e($req['approved_by']) ?></div>
                <?php endif; ?>
                <?php else: ?>
                <p class="text-muted mb-0">No response yet.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Approve / Reject action -->
<?php if (hasRole(['admin','workshop_manager','manager']) && $req['status'] === 'pending'): ?>
<div class="card" style="border-top:3px solid #2563eb">
    <div class="card-header fw-semibold"><i class="fa fa-gavel me-2"></i>Take Action</div>
    <div class="card-body">
        <form method="POST" class="row g-3">
            <div class="col-md-8">
                <label class="form-label">Note</label>
                <input type="text" name="admin_notes" class="form-control"
                       placeholder="e.g. Approved — proceed with quotation">
            </div>
            <div class="col-md-4 d-flex align-items-end gap-2">
                <button type="submit" name="action" value="approved" class="btn btn-success flex-grow-1">
                    <i class="fa fa-check me-1"></i>Approve
                </button>
                <button type="submit" name="action" value="rejected" class="btn btn-danger flex-grow-1">
                    <i class="fa fa-xmark me-1"></i>Reject
                </button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<?php include __DIR__ . '/../../includes/footer.php'; ?>

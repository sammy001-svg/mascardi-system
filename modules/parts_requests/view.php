<?php
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
canAccess('parts_requests') || die('Access denied.');
$pageTitle = 'Part Request';
$db   = getDB();
$user = authUser();
$role = $user['role'];

$id = (int)($_GET['id'] ?? 0);
if (!$id) redirect(BASE_URL . '/modules/parts_requests/index.php');

$stmt = $db->prepare("
    SELECT pr.*, m.name AS mechanic_name, j.job_number
    FROM parts_requests pr
    JOIN mechanics m ON m.id = pr.mechanic_id
    LEFT JOIN workshop_jobs j ON j.id = pr.job_id
    WHERE pr.id = ?
");
$stmt->execute([$id]); $req = $stmt->fetch();
if (!$req) { setFlash('error','Request not found.'); redirect(BASE_URL.'/modules/parts_requests/index.php'); }

// Mechanic can only view their own
if ($role === 'mechanic' && (int)($user['linked_id'] ?? 0) !== (int)$req['mechanic_id']) {
    setFlash('error', 'Access denied.'); redirect(BASE_URL . '/modules/parts_requests/index.php');
}

$items = $db->prepare("
    SELECT pri.*, i.quantity AS stock_qty, i.unit AS stock_unit
    FROM parts_request_items pri
    LEFT JOIN inventory i ON i.id = pri.inventory_id
    WHERE pri.request_id = ?
    ORDER BY pri.id
");
$items->execute([$id]); $items = $items->fetchAll();

// ── Approve / Reject / Issue action ─────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && hasRole(['admin','manager'])) {
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
                    $newQty = $db->prepare("SELECT quantity FROM inventory WHERE id=?");
                    $newQty->execute([$it['inventory_id']]); $nq = (float)$newQty->fetchColumn();
                    $db->prepare("INSERT INTO inventory_transactions (inventory_id, transaction_type, quantity, balance, reference_type, reference_id, notes, created_by) VALUES (?,?,?,?,?,?,?,?)")
                       ->execute([$it['inventory_id'], 'out', $issued, $nq, 'parts_request', $id, 'Issued for ' . $req['request_number'], $user['name']]);
                }
            }
            $db->prepare("UPDATE parts_requests SET status='issued', admin_notes=?, approved_by=?, updated_at=NOW() WHERE id=?")
               ->execute([$adminNotes ?: $req['admin_notes'], $user['name'], $id]);
            $db->commit();
            setFlash('success', 'Parts issued and stock deducted.');
            redirect(BASE_URL . '/modules/parts_requests/view.php?id=' . $id);
        } catch (Exception $e) {
            $db->rollBack();
            setFlash('error', 'Issue failed: ' . $e->getMessage());
        }
    }
}

$statusColors = ['pending'=>['warning','fa-clock'],'approved'=>['success','fa-check-circle'],'rejected'=>['danger','fa-times-circle'],'issued'=>['info','fa-box-open']];
[$statusColor, $statusIcon] = $statusColors[$req['status']] ?? ['secondary','fa-question'];

$pageTitle = 'Request ' . $req['request_number'];
include __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <h5 class="mb-1"><i class="fa fa-hand-holding-box me-2 text-primary"></i><?= e($req['request_number']) ?></h5>
        <div class="text-muted small">
            Requested by <strong><?= e($req['mechanic_name']) ?></strong>
            on <?= fmtDate($req['created_at'], 'd M Y, H:i') ?>
            <?= $req['job_number'] ? ' &mdash; Job <strong>' . e($req['job_number']) . '</strong>' : '' ?>
        </div>
    </div>
    <div class="d-flex align-items-center gap-2">
        <span class="badge bg-<?= $statusColor ?> fs-6 px-3 py-2">
            <i class="fa <?= $statusIcon ?> me-1"></i><?= ucfirst($req['status']) ?>
        </span>
        <a href="index.php" class="btn btn-sm btn-outline-secondary"><i class="fa fa-arrow-left me-1"></i>Back</a>
    </div>
</div>

<!-- Requested Parts -->
<div class="card mb-4">
    <div class="card-header"><i class="fa fa-list me-2"></i>Requested Parts</div>

    <?php if ($req['status'] === 'approved' && hasRole(['admin','manager'])): ?>
    <!-- Issue form: admin fills how much of each item to issue -->
    <form method="POST">
        <input type="hidden" name="action" value="issued">
        <div class="card-body p-0">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th class="ps-4">Part Name</th>
                        <th>Requested Qty</th>
                        <th>In Stock</th>
                        <th>Issue Qty</th>
                        <th>Notes</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($items as $it): ?>
                    <tr>
                        <td class="ps-4 fw-medium"><?= e($it['part_name']) ?></td>
                        <td><?= number_format($it['quantity_requested'], 2) ?> <?= e($it['unit']) ?></td>
                        <td>
                            <?php if ($it['inventory_id']): ?>
                            <span class="<?= (float)$it['stock_qty'] < (float)$it['quantity_requested'] ? 'text-danger fw-semibold' : 'text-success' ?>">
                                <?= number_format((float)$it['stock_qty'], 2) ?>
                            </span>
                            <?php else: ?>
                            <span class="text-muted small">Not in system</span>
                            <?php endif; ?>
                        </td>
                        <td style="width:130px">
                            <input type="number" name="issued_qty[<?= $it['id'] ?>]"
                                   class="form-control form-control-sm"
                                   value="<?= number_format($it['quantity_requested'], 2) ?>"
                                   min="0" step="0.01"
                                   max="<?= $it['inventory_id'] ? (float)$it['stock_qty'] : '' ?>">
                        </td>
                        <td class="text-muted small"><?= $it['notes'] ? e($it['notes']) : '—' ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="card-body border-top">
            <div class="row g-3 align-items-end">
                <div class="col-md-8">
                    <label class="form-label">Issue Note</label>
                    <input type="text" name="admin_notes" class="form-control" placeholder="e.g. Parts issued from workshop shelf B…" value="<?= e($req['admin_notes'] ?? '') ?>">
                </div>
                <div class="col-md-4 text-end">
                    <button type="submit" class="btn btn-info px-4">
                        <i class="fa fa-box-open me-2"></i>Issue Parts &amp; Deduct Stock
                    </button>
                </div>
            </div>
        </div>
    </form>

    <?php else: ?>
    <!-- Read-only view -->
    <div class="card-body p-0">
        <table class="table table-hover mb-0">
            <thead>
                <tr>
                    <th class="ps-4">Part Name</th>
                    <th>Qty Requested</th>
                    <?php if ($req['status'] === 'issued'): ?><th>Qty Issued</th><?php endif; ?>
                    <?php if (!hasRole('mechanic') && array_filter($items, fn($i)=>$i['inventory_id'])): ?>
                    <th>Stock Now</th>
                    <?php endif; ?>
                    <th>Unit</th>
                    <th>Notes</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($items as $it): ?>
                <tr>
                    <td class="ps-4 fw-medium"><?= e($it['part_name']) ?></td>
                    <td><?= number_format($it['quantity_requested'], 2) ?></td>
                    <?php if ($req['status'] === 'issued'): ?>
                    <td class="fw-semibold text-info"><?= number_format((float)$it['quantity_issued'], 2) ?></td>
                    <?php endif; ?>
                    <?php if (!hasRole('mechanic') && array_filter($items, fn($i)=>$i['inventory_id'])): ?>
                    <td class="text-muted small">
                        <?= $it['inventory_id'] ? number_format((float)$it['stock_qty'], 2) : '—' ?>
                    </td>
                    <?php endif; ?>
                    <td class="text-muted small"><?= e($it['unit']) ?></td>
                    <td class="text-muted small"><?= $it['notes'] ? e($it['notes']) : '—' ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<!-- Status / Notes -->
<div class="row g-4">
    <div class="col-md-6">
        <div class="card h-100">
            <div class="card-header">Request Notes</div>
            <div class="card-body">
                <p class="mb-0 text-muted"><?= $req['notes'] ? e($req['notes']) : 'No notes.' ?></p>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card h-100">
            <div class="card-header">Admin Response</div>
            <div class="card-body">
                <?php if ($req['admin_notes']): ?>
                <p class="mb-1"><?= e($req['admin_notes']) ?></p>
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

<!-- Approve / Reject form (admin/manager, pending only) -->
<?php if (hasRole(['admin','manager']) && $req['status'] === 'pending'): ?>
<div class="card mt-4" style="border-top:3px solid #2563eb">
    <div class="card-header"><i class="fa fa-gavel me-2"></i>Take Action</div>
    <div class="card-body">
        <form method="POST" class="row g-3">
            <div class="col-md-8">
                <label class="form-label">Note to mechanic</label>
                <input type="text" name="admin_notes" class="form-control" placeholder="e.g. Approved — collect from store room B">
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

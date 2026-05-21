<?php
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
canAccess('parts_requests') || die('Access denied.');
$pageTitle = 'Part Requests';
$db   = getDB();
$user = authUser();
$role = $user['role'];

// Handle quick status change (approve / reject / issue) — admin/manager only
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_id'])) {
    if (!hasRole(['admin','manager'])) { setFlash('error','Access denied.'); redirect(BASE_URL.'/modules/parts_requests/index.php'); }
    $rid    = (int)$_POST['action_id'];
    $action = $_POST['action'] ?? '';
    $anotes = trim($_POST['admin_notes'] ?? '');
    if (in_array($action, ['approved','rejected'])) {
        $db->prepare("UPDATE parts_requests SET status=?, admin_notes=?, approved_by=?, updated_at=NOW() WHERE id=?")
           ->execute([$action, $anotes, $user['name'], $rid]);
        setFlash('success', 'Request ' . $action . '.');
    }
    redirect(BASE_URL . '/modules/parts_requests/index.php');
}

// Build query by role
if ($role === 'mechanic') {
    $mechId = (int)($user['linked_id'] ?? 0);
    $stmt = $db->prepare("
        SELECT pr.*, m.name AS mechanic_name, j.job_number,
               COUNT(pri.id) AS item_count
        FROM parts_requests pr
        JOIN mechanics m ON m.id = pr.mechanic_id
        LEFT JOIN workshop_jobs j ON j.id = pr.job_id
        LEFT JOIN parts_request_items pri ON pri.request_id = pr.id
        WHERE pr.mechanic_id = ?
        GROUP BY pr.id
        ORDER BY pr.created_at DESC
    ");
    $stmt->execute([$mechId]);
} else {
    $stmt = $db->query("
        SELECT pr.*, m.name AS mechanic_name, j.job_number,
               COUNT(pri.id) AS item_count
        FROM parts_requests pr
        JOIN mechanics m ON m.id = pr.mechanic_id
        LEFT JOIN workshop_jobs j ON j.id = pr.job_id
        LEFT JOIN parts_request_items pri ON pri.request_id = pr.id
        GROUP BY pr.id
        ORDER BY pr.created_at DESC
    ");
}
$requests = $stmt->fetchAll();

$pending = array_filter($requests, fn($r) => $r['status'] === 'pending');

$statusColors = ['pending'=>'warning','approved'=>'success','rejected'=>'danger','issued'=>'info'];

include __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h5 class="mb-1">Part Requests</h5>
        <?php if ($role === 'mechanic'): ?>
        <div class="text-muted small">Submit a request for parts needed to complete a repair.</div>
        <?php else: ?>
        <div class="text-muted small">
            <?= count($pending) ?> pending approval<?= count($pending) !== 1 ? 's' : '' ?>
        </div>
        <?php endif; ?>
    </div>
    <?php if (canWrite('parts_requests')): ?>
    <a href="add.php" class="btn btn-primary btn-sm"><i class="fa fa-plus me-1"></i>New Request</a>
    <?php endif; ?>
</div>

<?php if (hasRole(['admin','manager']) && count($pending)): ?>
<div class="alert alert-warning d-flex align-items-center gap-2 mb-3">
    <i class="fa fa-bell"></i>
    <span><?= count($pending) ?> part request<?= count($pending) !== 1 ? 's' : '' ?> waiting for your approval.</span>
</div>
<?php endif; ?>

<div class="card">
    <div class="card-body p-0">
        <table class="table table-hover datatable mb-0">
            <thead>
                <tr>
                    <th class="ps-3">Req #</th>
                    <th>Date</th>
                    <?php if ($role !== 'mechanic'): ?><th>Mechanic</th><?php endif; ?>
                    <th>Job</th>
                    <th>Parts</th>
                    <th>Status</th>
                    <th>Notes</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($requests as $r): ?>
                <tr>
                    <td class="ps-3 fw-bold"><a href="view.php?id=<?= $r['id'] ?>"><?= e($r['request_number']) ?></a></td>
                    <td><?= fmtDate($r['created_at'], 'd M Y') ?></td>
                    <?php if ($role !== 'mechanic'): ?>
                    <td class="fw-medium small"><?= e($r['mechanic_name']) ?></td>
                    <?php endif; ?>
                    <td class="text-muted small"><?= $r['job_number'] ? e($r['job_number']) : '—' ?></td>
                    <td><span class="badge bg-light text-dark border"><?= $r['item_count'] ?> part<?= $r['item_count'] !== '1' ? 's' : '' ?></span></td>
                    <td><span class="badge bg-<?= $statusColors[$r['status']] ?? 'secondary' ?>"><?= ucfirst($r['status']) ?></span></td>
                    <td class="text-muted small" style="max-width:180px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= $r['notes'] ? e($r['notes']) : '—' ?></td>
                    <td>
                        <a href="view.php?id=<?= $r['id'] ?>" class="btn btn-xs btn-outline-primary"><i class="fa fa-eye"></i></a>
                        <?php if (hasRole(['admin','manager']) && $r['status'] === 'pending'): ?>
                        <button class="btn btn-xs btn-success" onclick="approveReject(<?= $r['id'] ?>, 'approved')" title="Approve"><i class="fa fa-check"></i></button>
                        <button class="btn btn-xs btn-danger"  onclick="approveReject(<?= $r['id'] ?>, 'rejected')"  title="Reject"><i class="fa fa-xmark"></i></button>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Quick approve/reject modal -->
<div class="modal fade" id="actionModal" tabindex="-1">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="action_id"  id="modalReqId">
                <input type="hidden" name="action"     id="modalAction">
                <div class="modal-header">
                    <h6 class="modal-title" id="modalTitle">Action</h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <label class="form-label">Note (optional)</label>
                    <textarea name="admin_notes" class="form-control" rows="2" placeholder="Reason or additional info…"></textarea>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-sm" id="modalBtn">Confirm</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php
$extraJs = <<<'JS'
<script>
function approveReject(id, action) {
    document.getElementById('modalReqId').value = id;
    document.getElementById('modalAction').value = action;
    var isApprove = action === 'approved';
    document.getElementById('modalTitle').textContent = isApprove ? 'Approve Request' : 'Reject Request';
    var btn = document.getElementById('modalBtn');
    btn.textContent = isApprove ? 'Approve' : 'Reject';
    btn.className = 'btn btn-sm ' + (isApprove ? 'btn-success' : 'btn-danger');
    new bootstrap.Modal(document.getElementById('actionModal')).show();
}
</script>
JS;
include __DIR__ . '/../../includes/footer.php';
?>

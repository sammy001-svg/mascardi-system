<?php
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
canAccess('parts_requests') || die('Access denied.');
$pageTitle = 'Quote Requests';
$db   = getDB();
$user = authUser();
$role = $user['role'];

// Handle quick approve / reject — admin/manager only
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_id'])) {
    if (!hasRole(['admin','manager','workshop_manager'])) {
        setFlash('error', 'Access denied.');
        redirect(BASE_URL . '/modules/parts_requests/index.php');
    }
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

// Fetch all requests with client + assessment info
$requests = $db->query("
    SELECT pr.*,
           COALESCE(pr.client_name, m.name, 'Walk-in')  AS display_client,
           qa.assessment_number,
           COUNT(pri.id) AS item_count
    FROM parts_requests pr
    LEFT JOIN mechanics m    ON m.id  = pr.mechanic_id
    LEFT JOIN quick_assessments qa ON qa.id = pr.quick_assessment_id
    LEFT JOIN parts_request_items pri ON pri.request_id = pr.id
    GROUP BY pr.id
    ORDER BY pr.created_at DESC
")->fetchAll();

$pending = array_filter($requests, fn($r) => $r['status'] === 'pending');

$statusColors = [
    'pending'  => 'warning',
    'approved' => 'success',
    'rejected' => 'danger',
    'issued'   => 'info',
];

include __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h5 class="mb-1"><i class="fa fa-file-invoice me-2 text-primary"></i>Quote Requests</h5>
        <div class="text-muted small">
            <?= count($pending) ?> pending approval<?= count($pending) !== 1 ? 's' : '' ?>
        </div>
    </div>
    <?php if (canWrite('parts_requests')): ?>
    <a href="add.php" class="btn btn-primary btn-sm"><i class="fa fa-plus me-1"></i>New Quote Request</a>
    <?php endif; ?>
</div>

<?php if (hasRole(['admin','manager','workshop_manager']) && count($pending)): ?>
<div class="alert alert-warning d-flex align-items-center gap-2 mb-3">
    <i class="fa fa-bell"></i>
    <span><?= count($pending) ?> quote request<?= count($pending) !== 1 ? 's' : '' ?> waiting for your approval.</span>
</div>
<?php endif; ?>

<div class="card">
    <div class="card-body p-0">
        <div class="table-responsive">
        <table class="table table-hover datatable mb-0">
            <thead>
                <tr>
                    <th class="ps-3">Ref #</th>
                    <th>Date</th>
                    <th>Client</th>
                    <th>Vehicle</th>
                    <th>Assessment</th>
                    <th>Parts</th>
                    <th>Status</th>
                    <th>Notes</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($requests as $r):
                    $vehicle = trim(($r['car_make'] ?? '') . ' ' . ($r['car_model'] ?? ''));
                    if ($r['car_registration']) $vehicle .= ' · ' . $r['car_registration'];
                ?>
                <tr>
                    <td class="ps-3 fw-bold">
                        <a href="view.php?id=<?= $r['id'] ?>"><?= e($r['request_number']) ?></a>
                    </td>
                    <td class="text-muted small"><?= fmtDate($r['created_at'], 'd M Y') ?></td>
                    <td class="fw-medium small"><?= e($r['display_client']) ?></td>
                    <td class="text-muted small"><?= $vehicle ? e($vehicle) : '—' ?></td>
                    <td class="text-muted small">
                        <?php if ($r['assessment_number']): ?>
                        <a href="<?= BASE_URL ?>/modules/quick_assessments/view.php?id=<?= $r['quick_assessment_id'] ?>" class="text-decoration-none">
                            <?= e($r['assessment_number']) ?>
                        </a>
                        <?php else: ?>—<?php endif; ?>
                    </td>
                    <td>
                        <span class="badge bg-light text-dark border">
                            <?= $r['item_count'] ?> part<?= $r['item_count'] !== '1' ? 's' : '' ?>
                        </span>
                    </td>
                    <td>
                        <span class="badge bg-<?= $statusColors[$r['status']] ?? 'secondary' ?>">
                            <?= ucfirst($r['status']) ?>
                        </span>
                    </td>
                    <td class="text-muted small" style="max-width:160px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
                        <?= $r['notes'] ? e($r['notes']) : '—' ?>
                    </td>
                    <td>
                        <a href="view.php?id=<?= $r['id'] ?>" class="btn btn-xs btn-outline-primary" title="View">
                            <i class="fa fa-eye"></i>
                        </a>
                        <?php if (hasRole(['admin','manager','workshop_manager']) && $r['status'] === 'pending'): ?>
                        <button class="btn btn-xs btn-success" onclick="approveReject(<?= $r['id'] ?>, 'approved')" title="Approve">
                            <i class="fa fa-check"></i>
                        </button>
                        <button class="btn btn-xs btn-danger"  onclick="approveReject(<?= $r['id'] ?>, 'rejected')"  title="Reject">
                            <i class="fa fa-xmark"></i>
                        </button>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (!$requests): ?>
                <tr><td colspan="9" class="text-center text-muted py-4">No quote requests yet.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
        </div>
    </div>
</div>

<!-- Quick approve/reject modal -->
<div class="modal fade" id="actionModal" tabindex="-1">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="action_id" id="modalReqId">
                <input type="hidden" name="action"    id="modalAction">
                <div class="modal-header">
                    <h6 class="modal-title" id="modalTitle">Action</h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <label class="form-label">Note <span class="text-muted small">(optional)</span></label>
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
    document.getElementById('modalTitle').textContent = isApprove ? 'Approve Quote Request' : 'Reject Quote Request';
    var btn = document.getElementById('modalBtn');
    btn.textContent  = isApprove ? 'Approve' : 'Reject';
    btn.className    = 'btn btn-sm ' + (isApprove ? 'btn-success' : 'btn-danger');
    new bootstrap.Modal(document.getElementById('actionModal')).show();
}
</script>
JS;
include __DIR__ . '/../../includes/footer.php';
?>

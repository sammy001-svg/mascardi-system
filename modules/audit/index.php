<?php
require_once __DIR__ . '/../../includes/functions.php';
requireRole(['admin']);
$pageTitle = 'System Audit Logs';
$db = getDB();

$page    = (int)($_GET['page'] ?? 1);
$perPage = 25;
$offset  = ($page - 1) * $perPage;

$query = "SELECT a.*, u.name AS user_name FROM audit_logs a LEFT JOIN users u ON u.id = a.user_id ORDER BY a.created_at DESC LIMIT $perPage OFFSET $offset";
$logs = $db->query($query)->fetchAll();

$total = $db->query("SELECT COUNT(*) FROM audit_logs")->fetchColumn();

include __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h5 class="mb-0"><i class="fa fa-history me-2 text-primary"></i>System Audit Logs</h5>
    <div class="text-muted small">Total entries: <?= number_format($total) ?></div>
</div>

<div class="card shadow-sm border-0">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0" style="font-size:13.5px">
                <thead>
                    <tr>
                        <th class="ps-4">Timestamp</th>
                        <th>User</th>
                        <th>Action</th>
                        <th>Module</th>
                        <th>Record ID</th>
                        <th>Details</th>
                        <th class="text-end pe-4">Values</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($logs as $l): ?>
                    <tr>
                        <td class="ps-4 small text-muted"><?= fmtDate($l['created_at'], 'd M Y, H:i') ?></td>
                        <td>
                            <div class="fw-semibold"><?= e($l['user_name'] ?: 'System') ?></div>
                            <div class="text-muted small" style="font-size:11px"><?= e($l['ip_address']) ?></div>
                        </td>
                        <td>
                            <?php
                            $badge = ['create'=>'success','update'=>'info','delete'=>'danger','login'=>'primary','logout'=>'secondary'];
                            $class = $badge[$l['action']] ?? 'secondary';
                            ?>
                            <span class="badge bg-<?= $class ?>"><?= strtoupper($l['action']) ?></span>
                        </td>
                        <td class="fw-medium text-dark"><?= ucfirst($l['module']) ?></td>
                        <td><span class="badge bg-light text-dark border">#<?= $l['record_id'] ?: '—' ?></span></td>
                        <td style="max-width:300px" class="text-truncate" title="<?= e($l['details']) ?>"><?= e($l['details']) ?></td>
                        <td class="text-end pe-4">
                            <?php if ($l['old_values'] || $l['new_values']): ?>
                            <button class="btn btn-xs btn-outline-primary" type="button" data-bs-toggle="collapse" data-bs-target="#log-<?= $l['id'] ?>">
                                View Changes
                            </button>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php if ($l['old_values'] || $l['new_values']): ?>
                    <tr class="collapse" id="log-<?= $l['id'] ?>">
                        <td colspan="7" class="bg-light p-3">
                            <div class="row">
                                <?php if ($l['old_values']): ?>
                                <div class="col-md-6">
                                    <div class="small fw-bold text-muted mb-1">OLD VALUES</div>
                                    <pre class="small bg-white p-2 border rounded mb-0" style="max-height:200px;overflow:auto"><?= e(json_encode(json_decode($l['old_values']), JSON_PRETTY_PRINT)) ?></pre>
                                </div>
                                <?php endif; ?>
                                <?php if ($l['new_values']): ?>
                                <div class="col-md-6">
                                    <div class="small fw-bold text-muted mb-1">NEW VALUES</div>
                                    <pre class="small bg-white p-2 border rounded mb-0" style="max-height:200px;overflow:auto"><?= e(json_encode(json_decode($l['new_values']), JSON_PRETTY_PRINT)) ?></pre>
                                </div>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endif; ?>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php if ($total > $perPage): ?>
    <div class="card-footer bg-white border-top py-3">
        <?= paginate($total, $page, $perPage, '?') ?>
    </div>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>

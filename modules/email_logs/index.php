<?php
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
hasRole('admin') || die('Access denied.');
$pageTitle = 'Email Logs';
$db = getDB();

$filter = $_GET['status'] ?? '';
if ($filter && !in_array($filter, ['sent', 'failed'])) $filter = '';
$stmt = $filter
    ? $db->prepare("SELECT * FROM email_logs WHERE status=? ORDER BY created_at DESC LIMIT 200")
    : $db->prepare("SELECT * FROM email_logs ORDER BY created_at DESC LIMIT 200");
$filter ? $stmt->execute([$filter]) : $stmt->execute([]);
$logs = $stmt->fetchAll();

include __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="mb-0"><i class="fa fa-envelope-open-text me-2"></i>Email Logs
        <span class="badge bg-secondary ms-2"><?= count($logs) ?></span>
    </h5>
    <div class="d-flex gap-2">
        <a href="?status=" class="btn btn-sm <?= !$filter ? 'btn-secondary' : 'btn-outline-secondary' ?>">All</a>
        <a href="?status=sent" class="btn btn-sm <?= $filter==='sent' ? 'btn-success' : 'btn-outline-success' ?>">Sent</a>
        <a href="?status=failed" class="btn btn-sm <?= $filter==='failed' ? 'btn-danger' : 'btn-outline-danger' ?>">Failed</a>
    </div>
</div>

<div class="card">
    <div class="card-body p-0">
        <table class="table table-hover datatable mb-0" style="font-size:13px">
            <thead>
                <tr>
                    <th class="ps-3">#</th>
                    <th>To</th>
                    <th>Subject</th>
                    <th>Status</th>
                    <th>Reference</th>
                    <th>Sent By</th>
                    <th>Date</th>
                    <th>Error</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($logs as $i => $log): ?>
                <tr>
                    <td class="ps-3 text-muted"><?= $i + 1 ?></td>
                    <td>
                        <div class="fw-semibold"><?= e($log['to_name'] ?: $log['to_email']) ?></div>
                        <div class="text-muted small"><?= e($log['to_email']) ?></div>
                    </td>
                    <td><?= e($log['subject']) ?></td>
                    <td>
                        <?php if ($log['status'] === 'sent'): ?>
                        <span class="badge bg-success"><i class="fa fa-check me-1"></i>Sent</span>
                        <?php else: ?>
                        <span class="badge bg-danger"><i class="fa fa-xmark me-1"></i>Failed</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($log['reference_type'] && $log['reference_id']): ?>
                        <?php
                            $refLink = [
                                'invoice'         => 'modules/invoices/view.php',
                                'quotation'       => 'modules/quotations/view.php',
                                'service_booking' => 'modules/service_bookings/view.php',
                                'payment'         => 'modules/payments/view.php',
                                'lpo'             => 'modules/lpo/view.php',
                                'settings'        => null,
                            ][$log['reference_type']] ?? null;
                        ?>
                        <?php if ($refLink): ?>
                        <a href="<?= BASE_URL ?>/<?= $refLink ?>?id=<?= $log['reference_id'] ?>" class="small">
                            <?= ucfirst($log['reference_type']) ?> #<?= $log['reference_id'] ?>
                        </a>
                        <?php else: ?>
                        <span class="small text-muted"><?= e($log['reference_type']) ?></span>
                        <?php endif; ?>
                        <?php else: ?>
                        <span class="text-muted">—</span>
                        <?php endif; ?>
                    </td>
                    <td class="small"><?= e($log['sent_by'] ?? '—') ?></td>
                    <td class="small text-muted text-nowrap"><?= fmtDate($log['created_at']) ?></td>
                    <td>
                        <?php if ($log['error_message']): ?>
                        <span class="text-danger small" title="<?= e($log['error_message']) ?>"
                              data-bs-toggle="tooltip">
                            <i class="fa fa-circle-exclamation me-1"></i><?= e(mb_substr($log['error_message'], 0, 60)) ?>…
                        </span>
                        <?php else: ?>—<?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (!$logs): ?>
                <tr><td colspan="8" class="text-center text-muted py-4">No email logs found.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>

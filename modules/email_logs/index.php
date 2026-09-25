<?php
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/mailer.php';
requireLogin();
hasRole('admin') || die('Access denied.');
$pageTitle = 'Email Logs';
$db = getDB();

// Ensure table exists and has all required columns (self-healing)
ensureEmailLogsTable($db);

// ── Handle POST Actions ───────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // 1. Resend Email Action
    if ($action === 'resend_email') {
        verifyCsrf();
        $logId = (int)($_POST['log_id'] ?? 0);
        if ($logId > 0) {
            $stmt = $db->prepare("SELECT * FROM email_logs WHERE id = ?");
            $stmt->execute([$logId]);
            $log = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($log) {
                $body = $log['body'] ?: mailTemplate($log['subject'] ?: 'System Notification', '<p>Resent email notification.</p>');
                $res  = sendMail(
                    $log['to_email'],
                    $log['to_name'] ?: $log['to_email'],
                    $log['subject'] ?: 'Notification',
                    $body,
                    $log['reference_type'] ?: '',
                    (int)($log['reference_id'] ?? 0)
                );
                if ($res['ok']) {
                    setFlash('success', 'Email successfully resent to ' . e($log['to_email']));
                } else {
                    setFlash('error', 'Resend failed: ' . e($res['error']));
                }
            } else {
                setFlash('error', 'Log record not found.');
            }
        }
        redirect(BASE_URL . '/modules/email_logs/index.php');
    }

    // 2. Clear Logs Action
    if ($action === 'clear_logs') {
        verifyCsrf();
        $days = (int)($_POST['days'] ?? 0);
        try {
            if ($days > 0) {
                $stmt = $db->prepare("DELETE FROM email_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)");
                $stmt->execute([$days]);
                setFlash('success', "Deleted logs older than {$days} days.");
            } else {
                $db->exec("TRUNCATE TABLE email_logs");
                setFlash('success', "All email logs cleared.");
            }
        } catch (\Throwable $e) {
            setFlash('error', 'Failed to clear logs: ' . $e->getMessage());
        }
        redirect(BASE_URL . '/modules/email_logs/index.php');
    }
}

// ── Search & Filters ─────────────────────────────────────────────────────────
$filterStatus = trim($_GET['status'] ?? '');
if ($filterStatus && !in_array($filterStatus, ['sent', 'failed'])) $filterStatus = '';

$search = trim($_GET['q'] ?? '');
$refFilter = trim($_GET['ref'] ?? '');

$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 30;
$offset  = ($page - 1) * $perPage;

// ── Stats summary ────────────────────────────────────────────────────────────
$stats = [
    'total'  => 0,
    'sent'   => 0,
    'failed' => 0,
    'rate'   => 100,
];
try {
    $stats['total']  = (int)$db->query("SELECT COUNT(*) FROM email_logs")->fetchColumn();
    $stats['sent']   = (int)$db->query("SELECT COUNT(*) FROM email_logs WHERE status='sent'")->fetchColumn();
    $stats['failed'] = (int)$db->query("SELECT COUNT(*) FROM email_logs WHERE status='failed'")->fetchColumn();
    if ($stats['total'] > 0) {
        $stats['rate'] = round(($stats['sent'] / $stats['total']) * 100, 1);
    }
} catch (\Throwable $_) {}

// ── Build Query ──────────────────────────────────────────────────────────────
$where  = [];
$params = [];

if ($filterStatus) {
    $where[]  = "status = ?";
    $params[] = $filterStatus;
}
if ($refFilter) {
    $where[]  = "reference_type = ?";
    $params[] = $refFilter;
}
if ($search !== '') {
    $where[]  = "(to_email LIKE ? OR to_name LIKE ? OR subject LIKE ? OR sent_by LIKE ? OR error_message LIKE ?)";
    $term     = "%{$search}%";
    $params   = array_merge($params, [$term, $term, $term, $term, $term]);
}

$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$totalRows = 0;
$logs      = [];

try {
    $countStmt = $db->prepare("SELECT COUNT(*) FROM email_logs {$whereSql}");
    $countStmt->execute($params);
    $totalRows = (int)$countStmt->fetchColumn();

    $query = "SELECT id, to_email, to_name, subject, body, status, error_message, reference_type, reference_id, sent_by, created_at 
              FROM email_logs {$whereSql} 
              ORDER BY created_at DESC LIMIT {$perPage} OFFSET {$offset}";
    $stmt = $db->prepare($query);
    $stmt->execute($params);
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (\Throwable $e) {
    error_log('email_logs query error: ' . $e->getMessage());
}

// SMTP check status
$smtpFrom = getSetting('smtp_from_email', '');
$smtpHost = getSetting('smtp_host', '');
$smtpOk   = !empty($smtpFrom) && !empty($smtpHost);

include __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h5 class="mb-1"><i class="fa fa-envelope-open-text me-2 text-primary"></i>Email Logs</h5>
        <div class="text-muted small">System outbound email history, delivery tracking, and activity audit</div>
    </div>
    <div class="d-flex gap-2">
        <a href="<?= BASE_URL ?>/modules/settings/messaging.php?tab=credentials" class="btn btn-sm btn-outline-primary">
            <i class="fa fa-gear me-1"></i>SMTP Settings
        </a>
        <button type="button" class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#clearLogsModal">
            <i class="fa fa-trash me-1"></i>Clear Logs
        </button>
    </div>
</div>

<?php if (!$smtpOk): ?>
<div class="alert alert-warning d-flex align-items-center justify-content-between mb-4">
    <div>
        <i class="fa fa-triangle-exclamation me-2"></i>
        <strong>SMTP Email Not Configured!</strong> System emails cannot be delivered until SMTP host and sender credentials are configured.
    </div>
    <a href="<?= BASE_URL ?>/modules/settings/index.php?tab=email" class="btn btn-sm btn-warning text-dark fw-bold">Configure SMTP</a>
</div>
<?php endif; ?>

<!-- Stats Row -->
<div class="row g-3 mb-4">
    <div class="col-md-3">
        <div class="card border-0 shadow-sm bg-white">
            <div class="card-body py-3 d-flex align-items-center gap-3">
                <div class="rounded-circle bg-primary-subtle text-primary p-3 d-flex align-items-center justify-content-center" style="width:48px;height:48px">
                    <i class="fa fa-paper-plane fa-lg"></i>
                </div>
                <div>
                    <div class="text-muted small">Total Logs</div>
                    <div class="fs-4 fw-bold"><?= number_format($stats['total']) ?></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card border-0 shadow-sm bg-white">
            <div class="card-body py-3 d-flex align-items-center gap-3">
                <div class="rounded-circle bg-success-subtle text-success p-3 d-flex align-items-center justify-content-center" style="width:48px;height:48px">
                    <i class="fa fa-circle-check fa-lg"></i>
                </div>
                <div>
                    <div class="text-muted small">Successfully Sent</div>
                    <div class="fs-4 fw-bold text-success"><?= number_format($stats['sent']) ?></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card border-0 shadow-sm bg-white">
            <div class="card-body py-3 d-flex align-items-center gap-3">
                <div class="rounded-circle bg-danger-subtle text-danger p-3 d-flex align-items-center justify-content-center" style="width:48px;height:48px">
                    <i class="fa fa-circle-xmark fa-lg"></i>
                </div>
                <div>
                    <div class="text-muted small">Failed Deliveries</div>
                    <div class="fs-4 fw-bold text-danger"><?= number_format($stats['failed']) ?></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card border-0 shadow-sm bg-white">
            <div class="card-body py-3 d-flex align-items-center gap-3">
                <div class="rounded-circle bg-info-subtle text-info p-3 d-flex align-items-center justify-content-center" style="width:48px;height:48px">
                    <i class="fa fa-chart-line fa-lg"></i>
                </div>
                <div>
                    <div class="text-muted small">Delivery Success Rate</div>
                    <div class="fs-4 fw-bold text-info"><?= $stats['rate'] ?>%</div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Filters & Search -->
<div class="card mb-3">
    <div class="card-body py-2 px-3">
        <form method="GET" class="row g-2 align-items-center">
            <?php if ($filterStatus): ?>
                <input type="hidden" name="status" value="<?= e($filterStatus) ?>">
            <?php endif; ?>
            <div class="col-md-5">
                <div class="input-group input-group-sm">
                    <span class="input-group-text bg-white"><i class="fa fa-search text-muted"></i></span>
                    <input type="text" name="q" class="form-control" placeholder="Search by recipient, subject, error..." value="<?= e($search) ?>">
                    <?php if ($search): ?>
                        <a href="?status=<?= e($filterStatus) ?>" class="btn btn-outline-secondary"><i class="fa fa-xmark"></i></a>
                    <?php endif; ?>
                </div>
            </div>
            <div class="col-md-3">
                <select name="ref" class="form-select form-select-sm" onchange="this.form.submit()">
                    <option value="">All Reference Types</option>
                    <?php
                    $refTypes = ['invoice','quotation','service_booking','payment','lpo','notice','doc_expiry_alert','weekly_digest','daily_alerts','test','settings'];
                    foreach ($refTypes as $rt):
                    ?>
                        <option value="<?= $rt ?>" <?= $refFilter === $rt ? 'selected' : '' ?>><?= ucfirst(str_replace('_', ' ', $rt)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4 text-md-end">
                <div class="btn-group btn-group-sm" role="group">
                    <a href="?status=&q=<?= urlencode($search) ?>&ref=<?= urlencode($refFilter) ?>" class="btn <?= !$filterStatus ? 'btn-secondary' : 'btn-outline-secondary' ?>">All</a>
                    <a href="?status=sent&q=<?= urlencode($search) ?>&ref=<?= urlencode($refFilter) ?>" class="btn <?= $filterStatus==='sent' ? 'btn-success' : 'btn-outline-success' ?>">Sent (<?= number_format($stats['sent']) ?>)</a>
                    <a href="?status=failed&q=<?= urlencode($search) ?>&ref=<?= urlencode($refFilter) ?>" class="btn <?= $filterStatus==='failed' ? 'btn-danger' : 'btn-outline-danger' ?>">Failed (<?= number_format($stats['failed']) ?>)</a>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Logs Table -->
<div class="card shadow-sm">
    <div class="card-body p-0">
        <?php if (empty($logs)): ?>
            <div class="text-center py-5 text-muted">
                <i class="fa fa-envelope-circle-check fa-3x mb-3 text-secondary opacity-50"></i>
                <h6>No Email Logs Found</h6>
                <p class="small mb-0">
                    <?php if ($search || $filterStatus || $refFilter): ?>
                        No logs match your search or filter parameters. <a href="index.php">Reset Filters</a>
                    <?php else: ?>
                        System email activity will automatically log here as notifications, receipts, and reports are sent.
                    <?php endif; ?>
                </p>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0" style="font-size:13px">
                    <thead class="table-light">
                        <tr>
                            <th class="ps-3" style="width:40px">#</th>
                            <th>Recipient</th>
                            <th>Subject</th>
                            <th>Status</th>
                            <th>Reference</th>
                            <th>Sent By</th>
                            <th>Date / Time</th>
                            <th class="text-end pe-3">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($logs as $i => $log): ?>
                        <tr>
                            <td class="ps-3 text-muted"><?= $offset + $i + 1 ?></td>
                            <td>
                                <div class="fw-semibold text-dark"><?= e($log['to_name'] ?: $log['to_email']) ?></div>
                                <div class="text-muted small font-monospace"><i class="fa fa-envelope me-1 opacity-50"></i><?= e($log['to_email']) ?></div>
                            </td>
                            <td>
                                <div class="fw-medium text-truncate" style="max-width:280px" title="<?= e($log['subject']) ?>">
                                    <?= e($log['subject'] ?: '(No Subject)') ?>
                                </div>
                                <?php if ($log['error_message']): ?>
                                    <div class="text-danger small text-truncate" style="max-width:280px" title="<?= e($log['error_message']) ?>">
                                        <i class="fa fa-triangle-exclamation me-1"></i><?= e(mb_substr($log['error_message'], 0, 50)) ?>…
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($log['status'] === 'sent'): ?>
                                    <span class="badge bg-success-subtle text-success border border-success-subtle px-2 py-1">
                                        <i class="fa fa-check me-1"></i>Sent
                                    </span>
                                <?php else: ?>
                                    <span class="badge bg-danger-subtle text-danger border border-danger-subtle px-2 py-1"
                                          title="<?= e($log['error_message']) ?>" data-bs-toggle="tooltip">
                                        <i class="fa fa-xmark me-1"></i>Failed
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($log['reference_type']): ?>
                                    <?php
                                        $refLink = match($log['reference_type']) {
                                            'invoice'         => 'modules/invoices/view.php',
                                            'quotation'       => 'modules/quotations/view.php',
                                            'service_booking' => 'modules/service_bookings/view.php',
                                            'payment'         => 'modules/payments/view.php',
                                            'lpo'             => 'modules/lpo/view.php',
                                            'client'          => 'modules/clients/view.php',
                                            'job'             => 'modules/jobs/edit.php',
                                            default           => null,
                                        };
                                    ?>
                                    <?php if ($refLink && $log['reference_id']): ?>
                                        <a href="<?= BASE_URL ?>/<?= $refLink ?>?id=<?= $log['reference_id'] ?>" class="badge bg-light text-dark border text-decoration-none">
                                            <i class="fa fa-link me-1 text-primary"></i><?= ucfirst(str_replace('_', ' ', $log['reference_type'])) ?> #<?= $log['reference_id'] ?>
                                        </a>
                                    <?php else: ?>
                                        <span class="badge bg-light text-muted border"><?= ucfirst(str_replace('_', ' ', $log['reference_type'])) ?></span>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td class="small">
                                <span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle">
                                    <i class="fa fa-user me-1 opacity-50"></i><?= e($log['sent_by'] ?: 'system') ?>
                                </span>
                            </td>
                            <td class="small text-muted text-nowrap">
                                <?= fmtDate($log['created_at'], 'd M Y H:i') ?>
                            </td>
                            <td class="text-end pe-3">
                                <div class="btn-group btn-group-sm">
                                    <?php if (!empty($log['body'])): ?>
                                        <button type="button" class="btn btn-outline-secondary" 
                                                onclick="previewEmail(<?= $log['id'] ?>)" title="View Email Content">
                                            <i class="fa fa-eye"></i>
                                        </button>
                                    <?php endif; ?>
                                    <form method="POST" class="d-inline" onsubmit="return confirm('Resend email to <?= e($log['to_email']) ?>?')">
                                        <?= csrfField() ?>
                                        <input type="hidden" name="action" value="resend_email">
                                        <input type="hidden" name="log_id" value="<?= $log['id'] ?>">
                                        <button type="submit" class="btn btn-outline-primary" title="Resend Email">
                                            <i class="fa fa-rotate-right"></i>
                                        </button>
                                    </form>
                                </div>
                                <?php if (!empty($log['body'])): ?>
                                    <div id="email-body-<?= $log['id'] ?>" class="d-none">
                                        <?= $log['body'] ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Pagination Footer -->
            <?php if ($totalRows > $perPage): ?>
            <div class="card-footer bg-white border-top d-flex align-items-center justify-content-between py-2 px-3">
                <small class="text-muted">
                    Showing <?= number_format($offset + 1) ?>–<?= number_format(min($offset + $perPage, $totalRows)) ?> of <?= number_format($totalRows) ?>
                </small>
                <?= paginate($totalRows, $page, $perPage, "?status=" . urlencode($filterStatus) . "&q=" . urlencode($search) . "&ref=" . urlencode($refFilter)) ?>
            </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<!-- Preview Modal -->
<div class="modal fade" id="previewModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h6 class="modal-title"><i class="fa fa-envelope me-2 text-primary"></i>Email Message Preview</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-0">
                <iframe id="emailPreviewFrame" style="width:100%;height:450px;border:none"></iframe>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- Clear Logs Modal -->
<div class="modal fade" id="clearLogsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="clear_logs">
                <div class="modal-header">
                    <h6 class="modal-title"><i class="fa fa-trash me-2 text-danger"></i>Clear Email Logs</h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p class="small text-muted mb-3">
                        Choose how many logs you wish to prune. This action cannot be undone.
                    </p>
                    <div class="mb-3">
                        <label class="form-label font-weight-semibold">Log Retention Period</label>
                        <select name="days" class="form-select">
                            <option value="30">Delete logs older than 30 days</option>
                            <option value="60">Delete logs older than 60 days</option>
                            <option value="90">Delete logs older than 90 days</option>
                            <option value="0">TRUNCATE / Delete ALL logs</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-sm btn-danger"><i class="fa fa-trash me-1"></i>Confirm Clear</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function previewEmail(id) {
    const content = document.getElementById('email-body-' + id);
    if (!content) return;
    const iframe = document.getElementById('emailPreviewFrame');
    const doc = iframe.contentWindow.document;
    doc.open();
    doc.write(content.innerHTML);
    doc.close();
    const modal = new bootstrap.Modal(document.getElementById('previewModal'));
    modal.show();
}
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>

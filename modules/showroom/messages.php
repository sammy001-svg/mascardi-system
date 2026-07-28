<?php
/**
 * Contact-message inbox.
 *
 * showroom/contact.php has always written to contact_messages, but nothing in
 * the app ever read it — messages were unreachable even when the insert worked.
 * This is that missing inbox.
 */
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
canAccess('showroom') || hasRole(['admin','sales_manager','sales_officer','sales_person','general_manager']) || die('Access denied.');
$pageTitle = 'Contact Messages';
$db = getDB();

require_once __DIR__ . '/../../showroom/_leads_bootstrap.php';
showroomLeadsMigrate($db);

// ── Status / notes update ─────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    verifyCsrf();
    $mid = (int)($_POST['message_id'] ?? 0);
    if ($mid) {
        if ($_POST['action'] === 'update_status') {
            $status = in_array($_POST['status'] ?? '', ['new','contacted','closed'], true) ? $_POST['status'] : 'new';
            $db->prepare("UPDATE contact_messages SET status=?, responded_by=?, responded_at=NOW() WHERE id=?")
               ->execute([$status, authUser()['id'], $mid]);
            setFlash('success', 'Message status updated.');
        } elseif ($_POST['action'] === 'save_notes') {
            $db->prepare("UPDATE contact_messages SET notes=?, responded_by=?, responded_at=NOW() WHERE id=?")
               ->execute([trim($_POST['notes'] ?? ''), authUser()['id'], $mid]);
            setFlash('success', 'Notes saved.');
        }
    }
    redirect(BASE_URL . '/modules/showroom/messages.php' . (!empty($_GET['id']) ? '?id=' . (int)$_GET['id'] : ''));
}

// ── Detail ────────────────────────────────────────────────────────────────
$viewId = (int)($_GET['id'] ?? 0);
$detail = null;
if ($viewId) {
    $s = $db->prepare("SELECT m.*, u.name AS responded_by_name
                       FROM contact_messages m
                       LEFT JOIN users u ON u.id = m.responded_by
                       WHERE m.id = ?");
    $s->execute([$viewId]);
    $detail = $s->fetch(PDO::FETCH_ASSOC);
}

// ── List ──────────────────────────────────────────────────────────────────
$filterStatus = $_GET['status'] ?? '';
$where  = ['1=1'];
$params = [];
if (in_array($filterStatus, ['new','contacted','closed'], true)) {
    $where[]  = 'm.status = ?';
    $params[] = $filterStatus;
}
try {
    $stmt = $db->prepare("SELECT m.*, u.name AS responded_by_name
                          FROM contact_messages m
                          LEFT JOIN users u ON u.id = m.responded_by
                          WHERE " . implode(' AND ', $where) . "
                          ORDER BY m.created_at DESC");
    $stmt->execute($params);
    $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (\Throwable $_) { $messages = []; }

$counts = ['new' => 0, 'contacted' => 0, 'closed' => 0];
try {
    foreach ($db->query("SELECT status, COUNT(*) c FROM contact_messages GROUP BY status")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if (isset($counts[$r['status']])) $counts[$r['status']] = (int)$r['c'];
    }
} catch (\Throwable $_) {}

$statusColors = ['new' => 'warning', 'contacted' => 'info', 'closed' => 'secondary'];

include __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div>
        <h5 class="mb-0"><i class="fa fa-envelope-open-text me-2 text-primary"></i>Contact Messages</h5>
        <div class="text-muted small">Enquiries submitted through the website contact form</div>
    </div>
    <a href="<?= BASE_URL ?>/modules/showroom/index.php" class="btn btn-sm btn-outline-secondary">
        <i class="fa fa-car me-1"></i>Vehicle Enquiries
    </a>
</div>

<!-- Status filter -->
<div class="d-flex gap-2 mb-3 flex-wrap">
    <a href="?" class="btn btn-sm <?= $filterStatus === '' ? 'btn-primary' : 'btn-outline-primary' ?>">
        All <span class="badge bg-light text-dark ms-1"><?= array_sum($counts) ?></span>
    </a>
    <?php foreach (['new' => 'New', 'contacted' => 'Contacted', 'closed' => 'Closed'] as $k => $lbl): ?>
    <a href="?status=<?= $k ?>" class="btn btn-sm <?= $filterStatus === $k ? 'btn-primary' : 'btn-outline-primary' ?>">
        <?= $lbl ?> <span class="badge bg-light text-dark ms-1"><?= $counts[$k] ?></span>
    </a>
    <?php endforeach; ?>
</div>

<?php if ($detail): ?>
<!-- ── Detail view ──────────────────────────────────────────────────────── -->
<div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
        <span>
            <i class="fa fa-envelope me-2"></i><?= e($detail['name']) ?>
            <span class="badge bg-<?= $statusColors[$detail['status']] ?? 'secondary' ?> ms-2"><?= ucfirst($detail['status']) ?></span>
        </span>
        <a href="?<?= $filterStatus ? 'status=' . e($filterStatus) : '' ?>" class="btn btn-xs btn-outline-secondary">
            <i class="fa fa-xmark me-1"></i>Close
        </a>
    </div>
    <div class="card-body">
        <div class="row g-3 mb-3" style="font-size:13.5px">
            <div class="col-md-3">
                <div class="text-muted small">Phone</div>
                <div class="fw-medium">
                    <?= $detail['phone'] ? '<a href="tel:' . e($detail['phone']) . '">' . e($detail['phone']) . '</a>' : '—' ?>
                </div>
            </div>
            <div class="col-md-4">
                <div class="text-muted small">Email</div>
                <div class="fw-medium">
                    <?= $detail['email'] ? '<a href="mailto:' . e($detail['email']) . '">' . e($detail['email']) . '</a>' : '—' ?>
                </div>
            </div>
            <div class="col-md-3">
                <div class="text-muted small">Subject</div>
                <div class="fw-medium"><?= e($detail['subject'] ?: '—') ?></div>
            </div>
            <div class="col-md-2">
                <div class="text-muted small">Received</div>
                <div class="fw-medium"><?= fmtDate($detail['created_at'], 'd M Y H:i') ?></div>
            </div>
        </div>

        <div class="mb-3">
            <div class="text-muted small mb-1">Message</div>
            <div class="p-3 rounded" style="background:var(--surface-alt);font-size:13.5px;line-height:1.7">
                <?= nl2br(e($detail['message'])) ?>
            </div>
        </div>

        <?php if (!empty($detail['lead_id'])): ?>
        <div class="alert alert-info py-2 small d-flex align-items-center justify-content-between flex-wrap gap-2">
            <span><i class="fa fa-user-plus me-1"></i>This message is linked to a CRM lead.</span>
            <a href="<?= BASE_URL ?>/modules/crm/view_lead.php?id=<?= (int)$detail['lead_id'] ?>" class="btn btn-xs btn-info">
                Open Lead <i class="fa fa-arrow-right ms-1"></i>
            </a>
        </div>
        <?php endif; ?>

        <div class="row g-3">
            <div class="col-md-5">
                <form method="POST">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="update_status">
                    <input type="hidden" name="message_id" value="<?= (int)$detail['id'] ?>">
                    <label class="form-label small">Status</label>
                    <div class="input-group input-group-sm">
                        <select name="status" class="form-select">
                            <?php foreach (['new' => 'New', 'contacted' => 'Contacted', 'closed' => 'Closed'] as $k => $lbl): ?>
                            <option value="<?= $k ?>" <?= $detail['status'] === $k ? 'selected' : '' ?>><?= $lbl ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button class="btn btn-primary"><i class="fa fa-check me-1"></i>Update</button>
                    </div>
                </form>
            </div>
            <div class="col-md-7">
                <form method="POST">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="save_notes">
                    <input type="hidden" name="message_id" value="<?= (int)$detail['id'] ?>">
                    <label class="form-label small">Internal notes</label>
                    <div class="input-group input-group-sm">
                        <input type="text" name="notes" class="form-control" value="<?= e($detail['notes'] ?? '') ?>"
                               placeholder="What was discussed / next step…">
                        <button class="btn btn-outline-primary"><i class="fa fa-save me-1"></i>Save</button>
                    </div>
                    <?php if (!empty($detail['responded_by_name'])): ?>
                    <div class="form-text">
                        Last updated by <?= e($detail['responded_by_name']) ?>
                        <?= $detail['responded_at'] ? ' on ' . fmtDate($detail['responded_at'], 'd M Y H:i') : '' ?>
                    </div>
                    <?php endif; ?>
                </form>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ── List ─────────────────────────────────────────────────────────────── -->
<div class="card">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0 datatable">
                <thead>
                    <tr>
                        <th class="ps-3">Received</th>
                        <th>Name</th>
                        <th>Contact</th>
                        <th>Subject</th>
                        <th>Message</th>
                        <th>Status</th>
                        <th class="pe-3">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($messages as $m): ?>
                    <tr>
                        <td class="ps-3 small text-muted text-nowrap"><?= fmtDate($m['created_at'], 'd M Y H:i') ?></td>
                        <td class="fw-medium small"><?= e($m['name']) ?></td>
                        <td class="small">
                            <?php if ($m['phone']): ?><a href="tel:<?= e($m['phone']) ?>"><?= e($m['phone']) ?></a><br><?php endif; ?>
                            <?php if ($m['email']): ?><a href="mailto:<?= e($m['email']) ?>" class="text-muted"><?= e($m['email']) ?></a><?php endif; ?>
                            <?= (!$m['phone'] && !$m['email']) ? '<span class="text-muted">—</span>' : '' ?>
                        </td>
                        <td class="small"><?= e($m['subject'] ?: '—') ?></td>
                        <td class="small text-muted">
                            <span class="d-inline-block text-truncate" style="max-width:260px" title="<?= e($m['message']) ?>">
                                <?= e($m['message']) ?>
                            </span>
                        </td>
                        <td><span class="badge bg-<?= $statusColors[$m['status']] ?? 'secondary' ?>"><?= ucfirst($m['status']) ?></span></td>
                        <td class="text-end pe-3 text-nowrap">
                            <a href="?id=<?= (int)$m['id'] ?><?= $filterStatus ? '&status=' . e($filterStatus) : '' ?>"
                               class="btn btn-xs btn-outline-primary"><i class="fa fa-eye me-1"></i>Open</a>
                            <?php if (!empty($m['lead_id'])): ?>
                            <a href="<?= BASE_URL ?>/modules/crm/view_lead.php?id=<?= (int)$m['lead_id'] ?>"
                               class="btn btn-xs btn-outline-info" title="Open linked CRM lead"><i class="fa fa-user"></i></a>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($messages)): ?>
                    <tr><td colspan="7" class="text-center py-5 text-muted">
                        <i class="fa fa-inbox fa-2x mb-2 d-block opacity-25"></i>No contact messages yet.
                    </td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>

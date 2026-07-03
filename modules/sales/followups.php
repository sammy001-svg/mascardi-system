<?php
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
canAccess('sales') || die('Access denied.');
$db   = getDB();
$user = authUser();

// Ensure table exists
try { $db->exec("CREATE TABLE IF NOT EXISTS sale_followups (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sale_id INT NOT NULL,
    assigned_to INT NULL,
    type VARCHAR(20) DEFAULT 'custom',
    title VARCHAR(255) NOT NULL,
    scheduled_date DATE NOT NULL,
    status ENUM('pending','done','skipped') DEFAULT 'pending',
    notes TEXT,
    completed_by INT NULL,
    completed_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); } catch (\Throwable $_) {}

$isManager = hasRole(['admin','super_admin','general_manager','sales_manager']);
$today     = date('Y-m-d');

// ── Handle action (mark done / skip / add note) ──────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['fu_action'])) {
    verifyCsrf();
    $fuId  = (int)($_POST['fu_id'] ?? 0);
    $action = $_POST['fu_action'];
    if ($fuId) {
        if ($action === 'done') {
            $db->prepare("UPDATE sale_followups SET status='done', completed_by=?, completed_at=NOW() WHERE id=?")
               ->execute([$user['id'], $fuId]);
        } elseif ($action === 'skip') {
            $db->prepare("UPDATE sale_followups SET status='skipped', completed_by=?, completed_at=NOW() WHERE id=?")
               ->execute([$user['id'], $fuId]);
        } elseif ($action === 'note') {
            $db->prepare("UPDATE sale_followups SET notes=? WHERE id=?")->execute([trim($_POST['notes'] ?? ''), $fuId]);
        }
    }
    redirect(BASE_URL . '/modules/sales/followups.php' . (isset($_GET['show']) ? '?show=' . urlencode($_GET['show']) : ''));
}

// ── Filter ────────────────────────────────────────────────────────────────────
$show = $_GET['show'] ?? 'pending';
$where  = ['sf.status = ?'];
$params = [$show === 'all' ? '%' : ($show === 'done' ? 'done' : ($show === 'overdue' ? 'pending' : 'pending'))];
if ($show === 'all') {
    $where  = ['1=1'];
    $params = [];
} elseif ($show === 'overdue') {
    $where[]  = 'sf.scheduled_date < ?';
    $params[] = $today;
}

if (!$isManager) {
    $where[]  = 'sf.assigned_to = ?';
    $params[] = $user['id'];
}

$followups = $db->prepare("
    SELECT sf.*, cs.sale_number, cs.buyer_name, cs.buyer_phone, cs.sale_date, cs.sale_price,
           c.make, c.model, c.year,
           u.name AS assigned_name,
           cb.name AS completed_name
    FROM sale_followups sf
    JOIN car_sales cs ON cs.id = sf.sale_id
    JOIN cars c ON c.id = cs.car_id
    LEFT JOIN users u ON u.id = sf.assigned_to
    LEFT JOIN users cb ON cb.id = sf.completed_by
    WHERE " . implode(' AND ', $where) . "
    ORDER BY sf.scheduled_date ASC
");
$followups->execute($params);
$followups = $followups->fetchAll();

// Counts for tab badges
$counts = [];
foreach (['pending','overdue','done','all'] as $s) {
    $cw = ['sf.status = ?']; $cp = ['pending'];
    if ($s === 'overdue') { $cw[] = 'sf.scheduled_date < ?'; $cp[] = $today; }
    elseif ($s === 'done') { $cw = ['sf.status = ?']; $cp = ['done']; }
    elseif ($s === 'all')  { $cw = ['1=1']; $cp = []; }
    if (!$isManager) { $cw[] = 'sf.assigned_to = ?'; $cp[] = $user['id']; }
    $cnt = $db->prepare("SELECT COUNT(*) FROM sale_followups sf JOIN car_sales cs ON cs.id=sf.sale_id WHERE " . implode(' AND ', $cw));
    $cnt->execute($cp);
    $counts[$s] = (int)$cnt->fetchColumn();
}

$pageTitle = 'Post-Sale Follow-ups';
include __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div>
        <h5 class="mb-0"><i class="fa fa-clipboard-check me-2 text-success"></i>Post-Sale Follow-ups</h5>
        <div class="text-muted small">Scheduled check-ins with buyers after their purchase</div>
    </div>
    <a href="index.php" class="btn btn-sm btn-outline-secondary"><i class="fa fa-arrow-left me-1"></i>Back to Sales</a>
</div>

<!-- Tabs -->
<div class="d-flex gap-2 mb-3 flex-wrap">
    <?php
    $tabs = [
        'pending' => ['Pending',  'primary'],
        'overdue' => ['Overdue',  'danger'],
        'done'    => ['Completed','success'],
        'all'     => ['All',      'secondary'],
    ];
    foreach ($tabs as $key => [$label, $color]):
    ?>
    <a href="?show=<?= $key ?>" class="btn btn-sm <?= $show === $key ? 'btn-'.$color : 'btn-outline-secondary' ?>">
        <?= $label ?>
        <?php if ($counts[$key]): ?><span class="badge <?= $show === $key ? 'bg-white text-'.$color : 'bg-'.$color ?> ms-1"><?= $counts[$key] ?></span><?php endif; ?>
    </a>
    <?php endforeach; ?>
</div>

<div class="card">
    <div class="card-body p-0">
        <table class="table table-hover mb-0 datatable" style="font-size:13px">
            <thead style="font-size:11.5px;color:#64748b;text-transform:uppercase;letter-spacing:.05em;background:#f8fafc">
                <tr>
                    <th class="ps-3 py-3">Task</th>
                    <th class="py-3">Sale</th>
                    <th class="py-3">Buyer</th>
                    <th class="py-3 text-center">Scheduled</th>
                    <th class="py-3 text-center">Status</th>
                    <?php if ($isManager): ?><th class="py-3">Agent</th><?php endif; ?>
                    <th class="py-3 text-center pe-3">Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($followups as $fu):
                $isOverdue = ($fu['status'] === 'pending' && $fu['scheduled_date'] < $today);
                $statusBadge = match($fu['status']) {
                    'done'    => '<span class="badge bg-success">Done</span>',
                    'skipped' => '<span class="badge bg-secondary">Skipped</span>',
                    default   => $isOverdue
                        ? '<span class="badge bg-danger"><i class="fa fa-triangle-exclamation me-1"></i>Overdue</span>'
                        : '<span class="badge bg-primary">Pending</span>',
                };
            ?>
            <tr class="<?= $isOverdue ? 'table-warning' : '' ?>">
                <td class="ps-3 py-3">
                    <div class="fw-medium"><?= e($fu['title']) ?></div>
                    <?php if ($fu['notes']): ?>
                    <div class="text-muted" style="font-size:11px"><?= e(mb_substr($fu['notes'],0,60)) ?>...</div>
                    <?php endif; ?>
                </td>
                <td class="py-3">
                    <a href="view.php?id=<?= $fu['sale_id'] ?>" class="fw-semibold text-decoration-none">
                        <?= e($fu['sale_number']) ?>
                    </a>
                    <div class="text-muted" style="font-size:11px"><?= e($fu['year'].' '.$fu['make'].' '.$fu['model']) ?></div>
                </td>
                <td class="py-3">
                    <div><?= e($fu['buyer_name']) ?></div>
                    <?php if ($fu['buyer_phone']): ?><div class="text-muted" style="font-size:11px"><?= e($fu['buyer_phone']) ?></div><?php endif; ?>
                </td>
                <td class="py-3 text-center"><?= fmtDate($fu['scheduled_date'], 'd M Y') ?></td>
                <td class="py-3 text-center"><?= $statusBadge ?></td>
                <?php if ($isManager): ?>
                <td class="py-3 text-muted small"><?= e($fu['assigned_name'] ?? '—') ?></td>
                <?php endif; ?>
                <td class="py-3 text-center pe-3">
                    <?php if ($fu['status'] === 'pending'): ?>
                    <form method="POST" class="d-inline">
                        <input type="hidden" name="fu_id" value="<?= $fu['id'] ?>">
                        <input type="hidden" name="fu_action" value="done">
                        <?= csrfField() ?>
                        <button class="btn btn-xs btn-success" title="Mark Done"><i class="fa fa-check"></i></button>
                    </form>
                    <form method="POST" class="d-inline" onsubmit="return confirm('Skip this follow-up?')">
                        <input type="hidden" name="fu_id" value="<?= $fu['id'] ?>">
                        <input type="hidden" name="fu_action" value="skip">
                        <?= csrfField() ?>
                        <button class="btn btn-xs btn-outline-secondary" title="Skip"><i class="fa fa-forward"></i></button>
                    </form>
                    <?php endif; ?>
                    <button class="btn btn-xs btn-outline-primary" type="button"
                            data-bs-toggle="collapse" data-bs-target="#note-<?= $fu['id'] ?>" title="Add Note">
                        <i class="fa fa-note-sticky"></i>
                    </button>
                </td>
            </tr>
            <tr class="collapse" id="note-<?= $fu['id'] ?>">
                <td colspan="<?= $isManager ? 7 : 6 ?>" class="bg-light">
                    <form method="POST" class="d-flex gap-2 align-items-end p-2">
                        <input type="hidden" name="fu_id" value="<?= $fu['id'] ?>">
                        <input type="hidden" name="fu_action" value="note">
                        <?= csrfField() ?>
                        <textarea name="notes" class="form-control form-control-sm" rows="2" style="font-size:12px"
                                  placeholder="Add a note about this follow-up…"><?= e($fu['notes'] ?? '') ?></textarea>
                        <button class="btn btn-sm btn-primary flex-shrink-0"><i class="fa fa-save me-1"></i>Save</button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php if (!$followups): ?>
            <tr><td colspan="<?= $isManager ? 7 : 6 ?>" class="text-center text-muted py-5">
                <i class="fa fa-clipboard-check fa-2x mb-2 d-block opacity-25"></i>
                No <?= $show ?> follow-ups.
            </td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>

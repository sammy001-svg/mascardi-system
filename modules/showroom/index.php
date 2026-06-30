<?php
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
canAccess('showroom') || hasRole(['admin','sales_manager','sales_officer','sales_person','general_manager']) || die('Access denied.');
$pageTitle = 'Showroom Inquiries';
$db = getDB();

// Inline migrations — ensure columns exist
try { $db->exec("ALTER TABLE showroom_inquiries ADD COLUMN status VARCHAR(20) NOT NULL DEFAULT 'new'"); } catch (\Throwable $_) {}
try { $db->exec("ALTER TABLE showroom_inquiries ADD COLUMN notes TEXT NULL DEFAULT NULL"); } catch (\Throwable $_) {}
try { $db->exec("ALTER TABLE showroom_inquiries ADD COLUMN responded_by INT NULL DEFAULT NULL"); } catch (\Throwable $_) {}
try { $db->exec("ALTER TABLE showroom_inquiries ADD COLUMN responded_at DATETIME NULL DEFAULT NULL"); } catch (\Throwable $_) {}

// ── Handle status update ──────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    verifyCsrf();
    $iqId = (int)($_POST['inquiry_id'] ?? 0);
    if ($iqId) {
        if ($_POST['action'] === 'update_status') {
            $status = in_array($_POST['status'], ['new','contacted','closed']) ? $_POST['status'] : 'new';
            $db->prepare("UPDATE showroom_inquiries SET status=?, responded_by=?, responded_at=NOW() WHERE id=?")
               ->execute([$status, authUser()['id'], $iqId]);
        } elseif ($_POST['action'] === 'save_notes') {
            $db->prepare("UPDATE showroom_inquiries SET notes=?, responded_by=?, responded_at=NOW() WHERE id=?")
               ->execute([trim($_POST['notes'] ?? ''), authUser()['id'], $iqId]);
        }
    }
    redirect(BASE_URL . '/modules/showroom/index.php' . ($_GET['id'] ? '?id=' . (int)$_GET['id'] : ''));
}

// ── Inquiry detail view ───────────────────────────────────────────────────
$viewId = (int)($_GET['id'] ?? 0);
$detail = null;
if ($viewId) {
    $s = $db->prepare("SELECT i.*, c.make, c.model, c.year, c.asking_price,
                              u.name AS responded_by_name
                       FROM showroom_inquiries i
                       JOIN cars c ON c.id = i.car_id
                       LEFT JOIN users u ON u.id = i.responded_by
                       WHERE i.id = ?");
    $s->execute([$viewId]);
    $detail = $s->fetch(PDO::FETCH_ASSOC);
}

// ── List ──────────────────────────────────────────────────────────────────
$filterStatus = $_GET['status'] ?? '';
$where  = ['1=1'];
$params = [];
if ($filterStatus) { $where[] = 'i.status = ?'; $params[] = $filterStatus; }

$inquiries = $db->prepare("
    SELECT i.*, c.make, c.model, c.year
    FROM showroom_inquiries i
    JOIN cars c ON c.id = i.car_id
    WHERE " . implode(' AND ', $where) . "
    ORDER BY i.status='new' DESC, i.created_at DESC
    LIMIT 200
");
$inquiries->execute($params);
$inquiries = $inquiries->fetchAll(PDO::FETCH_ASSOC);

$counts = $db->query("SELECT status, COUNT(*) AS n FROM showroom_inquiries GROUP BY status")->fetchAll(PDO::FETCH_KEY_PAIR);

include __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div>
        <h5 class="mb-0"><i class="fa fa-store me-2 text-primary"></i>Showroom Inquiries</h5>
    </div>
    <div class="d-flex gap-2">
        <a href="<?= BASE_URL ?>/showroom/" target="_blank" class="btn btn-sm btn-outline-primary">
            <i class="fa fa-external-link me-1"></i>View Showroom
        </a>
    </div>
</div>

<!-- Status tabs -->
<div class="d-flex gap-2 mb-3 flex-wrap">
    <?php
    $tabs = [''=>'All', 'new'=>'New', 'contacted'=>'Contacted', 'closed'=>'Closed'];
    $tabColors = ['new'=>'danger','contacted'=>'primary','closed'=>'secondary'];
    foreach ($tabs as $val => $label):
        $cnt = $val === '' ? array_sum($counts) : ($counts[$val] ?? 0);
    ?>
    <a href="?status=<?= $val ?>" class="btn btn-sm <?= $filterStatus === $val ? 'btn-primary' : 'btn-outline-secondary' ?>">
        <?= $label ?>
        <?php if ($cnt): ?><span class="badge <?= $filterStatus === $val ? 'bg-white text-primary' : 'bg-' . ($tabColors[$val] ?? 'secondary') ?> ms-1"><?= $cnt ?></span><?php endif; ?>
    </a>
    <?php endforeach; ?>
</div>

<div class="row g-3">

    <!-- ── Inquiry list ──────────────────────────────────── -->
    <div class="col-lg-<?= $detail ? '5' : '12' ?>">
        <div class="card">
            <div class="card-body p-0">
                <table class="table table-hover mb-0">
                    <thead>
                        <tr>
                            <th class="ps-3">Enquirer</th>
                            <th>Vehicle</th>
                            <th>Contact</th>
                            <th>Status</th>
                            <th>Received</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$inquiries): ?>
                        <tr><td colspan="5" class="text-center text-muted py-4">No inquiries yet.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($inquiries as $iq): ?>
                        <tr class="<?= $viewId === $iq['id'] ? 'table-active' : '' ?>" style="cursor:pointer"
                            onclick="location.href='?<?= $filterStatus ? 'status='.$filterStatus.'&' : '' ?>id=<?= $iq['id'] ?>'">
                            <td class="ps-3">
                                <div class="fw-semibold" style="font-size:13.5px"><?= e($iq['inquiry_name']) ?></div>
                                <?php if ($iq['status'] === 'new'): ?>
                                <span class="badge bg-danger" style="font-size:10px">New</span>
                                <?php endif; ?>
                            </td>
                            <td class="small text-muted">
                                <?= e($iq['year'] . ' ' . $iq['make'] . ' ' . $iq['model']) ?>
                            </td>
                            <td class="small">
                                <?php if ($iq['inquiry_phone']): ?>
                                <div><i class="fa fa-phone me-1 text-muted"></i><?= e($iq['inquiry_phone']) ?></div>
                                <?php endif; ?>
                                <?php if ($iq['inquiry_email']): ?>
                                <div><i class="fa fa-envelope me-1 text-muted"></i><?= e($iq['inquiry_email']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php
                                $sc = ['new'=>'danger','contacted'=>'primary','closed'=>'secondary'];
                                echo '<span class="badge bg-' . ($sc[$iq['status']] ?? 'secondary') . '">' . ucfirst($iq['status']) . '</span>';
                                ?>
                            </td>
                            <td class="text-muted small"><?= fmtDate($iq['created_at'], 'd M H:i') ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- ── Inquiry detail ─────────────────────────────────── -->
    <?php if ($detail): ?>
    <div class="col-lg-7">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="fa fa-envelope-open me-2 text-primary"></i>Inquiry #<?= $detail['id'] ?></span>
                <a href="?<?= $filterStatus ? 'status='.$filterStatus : '' ?>" class="btn btn-xs btn-outline-secondary">
                    <i class="fa fa-xmark me-1"></i>Close
                </a>
            </div>
            <div class="card-body">
                <!-- Vehicle -->
                <div class="mb-3 p-3 rounded-3" style="background:#f8fafc;border:1px solid #e2e8f0">
                    <div class="text-muted small mb-1">Vehicle of Interest</div>
                    <div class="fw-bold" style="font-size:15px"><?= e($detail['year'] . ' ' . $detail['make'] . ' ' . $detail['model']) ?></div>
                    <?php if ($detail['asking_price']): ?>
                    <div class="text-primary fw-semibold">KES <?= number_format((float)$detail['asking_price']) ?></div>
                    <?php endif; ?>
                    <a href="<?= BASE_URL ?>/showroom/view.php?id=<?= $detail['car_id'] ?>" target="_blank" class="small text-muted">
                        <i class="fa fa-external-link me-1"></i>View in showroom
                    </a>
                    &nbsp;&bull;&nbsp;
                    <a href="<?= BASE_URL ?>/modules/cars/view.php?id=<?= $detail['car_id'] ?>" class="small text-muted">
                        <i class="fa fa-car me-1"></i>View car record
                    </a>
                </div>

                <!-- Contact -->
                <div class="row g-3 mb-3">
                    <div class="col-sm-6">
                        <div class="text-muted small mb-1">Name</div>
                        <div class="fw-semibold"><?= e($detail['inquiry_name']) ?></div>
                    </div>
                    <?php if ($detail['inquiry_phone']): ?>
                    <div class="col-sm-6">
                        <div class="text-muted small mb-1">Phone</div>
                        <div class="d-flex gap-2 align-items-center flex-wrap">
                            <a href="tel:<?= e($detail['inquiry_phone']) ?>" class="fw-semibold">
                                <i class="fa fa-phone me-1 text-success"></i><?= e($detail['inquiry_phone']) ?>
                            </a>
                            <?php $wp = preg_replace('/[^0-9]/', '', $detail['inquiry_phone']); ?>
                            <a href="https://wa.me/<?= $wp ?>" target="_blank" rel="noopener"
                               class="btn btn-xs" style="background:#dcfce7;color:#16a34a;border:none">
                                <i class="fa-brands fa-whatsapp me-1"></i>WhatsApp
                            </a>
                        </div>
                    </div>
                    <?php endif; ?>
                    <?php if ($detail['inquiry_email']): ?>
                    <div class="col-sm-6">
                        <div class="text-muted small mb-1">Email</div>
                        <a href="mailto:<?= e($detail['inquiry_email']) ?>" class="fw-semibold">
                            <i class="fa fa-envelope me-1 text-primary"></i><?= e($detail['inquiry_email']) ?>
                        </a>
                    </div>
                    <?php endif; ?>
                    <div class="col-sm-6">
                        <div class="text-muted small mb-1">Received</div>
                        <div class="fw-semibold"><?= fmtDate($detail['created_at'], 'd M Y, H:i') ?></div>
                    </div>
                </div>

                <!-- Message -->
                <?php if ($detail['message']): ?>
                <div class="mb-3">
                    <div class="text-muted small mb-1">Message</div>
                    <div class="p-3 rounded-3" style="background:#f0f9ff;border:1px solid #bae6fd;font-size:14px;line-height:1.7;color:#0c4a6e">
                        <?= nl2br(e($detail['message'])) ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Status update -->
                <form method="POST" class="d-flex gap-2 mb-3 flex-wrap align-items-end">
                    <input type="hidden" name="inquiry_id" value="<?= $detail['id'] ?>">
                    <input type="hidden" name="action" value="update_status">
                    <div>
                        <label class="form-label small fw-semibold">Update Status</label>
                        <select name="status" class="form-select form-select-sm" style="width:auto">
                            <option value="new"       <?= $detail['status']==='new'       ? 'selected' : '' ?>>New</option>
                            <option value="contacted" <?= $detail['status']==='contacted' ? 'selected' : '' ?>>Contacted</option>
                            <option value="closed"    <?= $detail['status']==='closed'    ? 'selected' : '' ?>>Closed</option>
                        </select>
                    </div>
                    <button class="btn btn-sm btn-primary"><i class="fa fa-save me-1"></i>Save</button>
                    <?php if ($detail['responded_by_name']): ?>
                    <small class="text-muted align-self-end">Last updated by <?= e($detail['responded_by_name']) ?></small>
                    <?php endif; ?>
                </form>

                <!-- Internal notes -->
                <form method="POST">
                    <input type="hidden" name="inquiry_id" value="<?= $detail['id'] ?>">
                    <input type="hidden" name="action" value="save_notes">
                    <label class="form-label small fw-semibold">Internal Notes</label>
                    <textarea name="notes" class="form-control form-control-sm mb-2" rows="3"
                              placeholder="Spoke to client on 12 June, interested but checking financing..."><?= e($detail['notes'] ?? '') ?></textarea>
                    <button class="btn btn-sm btn-outline-secondary"><i class="fa fa-save me-1"></i>Save Notes</button>
                </form>

            </div>
        </div>
    </div>
    <?php endif; ?>

</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>

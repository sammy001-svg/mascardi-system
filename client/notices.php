<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
$pageTitle = 'Notices';
requireClientLogin();
$cl = clientAuth();
$db = getDB();

$notices = $db->prepare("SELECT * FROM client_notices WHERE client_id=? ORDER BY created_at DESC");
$notices->execute([$cl['id']]); $notices = $notices->fetchAll();

// Mark all as read
$db->prepare("UPDATE client_notices SET is_read=1 WHERE client_id=? AND is_read=0")->execute([$cl['id']]);

include __DIR__ . '/includes/header.php';
?>
<h5 class="fw-700 mb-4"><i class="fa fa-bell me-2 text-primary"></i>Notices from <?= e(getSetting('company_name','the Workshop')) ?></h5>

<?php if ($notices): ?>
<div class="d-flex flex-column gap-3">
<?php foreach ($notices as $n): ?>
<div class="card p-4">
    <div class="d-flex justify-content-between align-items-start mb-2">
        <h6 class="fw-700 mb-0"><?= e($n['subject']) ?></h6>
        <small class="text-muted ms-3 text-nowrap"><?= fmtDate($n['created_at'], 'd M Y, H:i') ?></small>
    </div>
    <div style="font-size:13.5px;color:#334155;line-height:1.75"><?= nl2br(e($n['message'])) ?></div>
    <div class="mt-2 text-muted" style="font-size:12px">— <?= e($n['sent_by']) ?></div>
</div>
<?php endforeach; ?>
</div>
<?php else: ?>
<div class="card text-center py-5">
    <i class="fa fa-bell-slash fa-2x mb-3 d-block" style="color:#cbd5e1"></i>
    <p class="text-muted mb-0">No notices yet.</p>
</div>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>

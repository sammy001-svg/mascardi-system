<?php
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
canAccess('key_handovers') || die('Access denied.');
$db   = getDB();
$user = authUser();

$id = (int)($_GET['id'] ?? 0);
if (!$id) redirect(BASE_URL . '/modules/key_handovers/index.php');

$stmt = $db->prepare("
    SELECT kh.*,
           fl.name AS from_name,
           tl.name AS to_name,
           d.name  AS driver_name_rel, d.phone AS driver_phone
    FROM key_handovers kh
    JOIN locations fl ON fl.id = kh.from_location_id
    JOIN locations tl ON tl.id = kh.to_location_id
    LEFT JOIN drivers d ON d.id = kh.driver_id
    WHERE kh.id = ?
");
$stmt->execute([$id]);
$h = $stmt->fetch();
if (!$h) { setFlash('error', 'Handover not found.'); redirect(BASE_URL . '/modules/key_handovers/index.php'); }

$items = $db->prepare("
    SELECT khi.*, ck.key_label, c.make, c.model, c.registration_number
    FROM key_handover_items khi
    JOIN car_keys ck ON ck.id = khi.car_key_id
    JOIN cars c ON c.id = khi.car_id
    WHERE khi.handover_id = ?
    ORDER BY khi.id
");
$items->execute([$id]);
$items = $items->fetchAll();

// ── POST Actions ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && canWrite('key_handovers')) {
    $action = $_POST['action'] ?? '';

    if ($action === 'checkout') {
        $db->beginTransaction();
        try {
            $db->prepare("UPDATE key_handovers SET status='checked_out', checked_out_at=NOW(), checked_out_by=?, updated_at=NOW() WHERE id=?")
               ->execute([$user['name'], $id]);
            $db->prepare("UPDATE key_handover_items SET checked_out_at=NOW(), checked_out_by=? WHERE handover_id=?")
               ->execute([$user['name'], $id]);
            // Mark keys as in_transit / with driver
            foreach ($items as $it) {
                $db->prepare("UPDATE car_keys SET status='with_driver', updated_at=NOW() WHERE id=?")
                   ->execute([$it['car_key_id']]);
            }
            $db->commit();
            logActivity('update', 'key_handovers', $id, "Keys checked out for {$h['handover_number']}");
            setFlash('success', 'Keys checked out. Driver is en route.');
        } catch (\Throwable $e) {
            $db->rollBack();
            setFlash('error', 'Failed: ' . $e->getMessage());
        }
        redirect(BASE_URL . '/modules/key_handovers/view.php?id=' . $id);
    }

    if ($action === 'checkin') {
        $db->beginTransaction();
        try {
            $db->prepare("UPDATE key_handovers SET status='completed', checked_in_at=NOW(), checked_in_by=?, updated_at=NOW() WHERE id=?")
               ->execute([$user['name'], $id]);
            $db->prepare("UPDATE key_handover_items SET checked_in_at=NOW(), checked_in_by=? WHERE handover_id=?")
               ->execute([$user['name'], $id]);
            // Mark keys as at_showroom at destination
            foreach ($items as $it) {
                $db->prepare("UPDATE car_keys SET status='at_showroom', current_location_id=?, updated_at=NOW() WHERE id=?")
                   ->execute([$h['to_location_id'], $it['car_key_id']]);
            }
            $db->commit();
            logActivity('update', 'key_handovers', $id, "Keys checked in — {$h['handover_number']} complete");
            setFlash('success', 'Keys received. Handover complete.');
        } catch (\Throwable $e) {
            $db->rollBack();
            setFlash('error', 'Failed: ' . $e->getMessage());
        }
        redirect(BASE_URL . '/modules/key_handovers/view.php?id=' . $id);
    }

    if ($action === 'cancel') {
        $db->prepare("UPDATE key_handovers SET status='cancelled', updated_at=NOW() WHERE id=?")->execute([$id]);
        setFlash('success', 'Run cancelled.');
        redirect(BASE_URL . '/modules/key_handovers/view.php?id=' . $id);
    }
}

$statusMeta = [
    'pending'     => ['warning',   'fa-clock',       'Pending Checkout'],
    'checked_out' => ['primary',   'fa-truck-moving', 'Keys Out with Driver'],
    'completed'   => ['success',   'fa-circle-check', 'Completed'],
    'cancelled'   => ['secondary', 'fa-ban',          'Cancelled'],
];
[$sColor, $sIcon, $sLabel] = $statusMeta[$h['status']] ?? ['secondary','fa-question','Unknown'];

$runMeta = [
    'morning_run' => ['fa-sun',  'Morning Run',  'warning'],
    'evening_run' => ['fa-moon', 'Evening Run',  'info'],
    'ad_hoc'      => ['fa-bolt', 'Ad-hoc Run',   'secondary'],
];
[$rIcon, $rLabel, $rColor] = $runMeta[$h['run_type']] ?? ['fa-key','Run','secondary'];

$pageTitle = 'Key Run ' . $h['handover_number'];
include __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <h5 class="mb-1"><i class="fa fa-key me-2 text-primary"></i><?= e($h['handover_number']) ?></h5>
        <div class="text-muted small">Created by <strong><?= e($h['created_by']) ?></strong> on <?= fmtDate($h['created_at'], 'd M Y, H:i') ?></div>
    </div>
    <div class="d-flex align-items-center gap-2 flex-wrap">
        <span class="badge bg-<?= $sColor ?> fs-6 px-3 py-2"><i class="fa <?= $sIcon ?> me-1"></i><?= $sLabel ?></span>
        <span class="badge bg-<?= $rColor ?> bg-opacity-75"><i class="fa <?= $rIcon ?> me-1"></i><?= $rLabel ?></span>
        <a href="print.php?id=<?= $id ?>" class="btn btn-sm btn-outline-dark" target="_blank">
            <i class="fa fa-print me-1"></i>Print Sheet
        </a>
        <a href="index.php" class="btn btn-sm btn-outline-secondary"><i class="fa fa-arrow-left me-1"></i>Back</a>
    </div>
</div>

<!-- Info cards -->
<div class="row g-4 mb-4">
    <div class="col-md-4">
        <div class="card h-100">
            <div class="card-header fw-semibold"><i class="fa fa-route me-2 text-primary"></i>Route</div>
            <div class="card-body">
                <div class="d-flex align-items-center gap-2 mb-2">
                    <span class="badge bg-danger bg-opacity-75">FROM</span>
                    <span class="fw-semibold"><?= e($h['from_name']) ?></span>
                </div>
                <div class="text-muted ps-1 mb-2"><i class="fa fa-arrow-down"></i></div>
                <div class="d-flex align-items-center gap-2">
                    <span class="badge bg-success bg-opacity-75">TO</span>
                    <span class="fw-semibold"><?= e($h['to_name']) ?></span>
                </div>
                <hr class="my-2">
                <div class="text-muted small"><i class="fa fa-calendar me-1"></i><?= fmtDate($h['handover_date'], 'd M Y') ?></div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card h-100">
            <div class="card-header fw-semibold"><i class="fa fa-id-card me-2 text-primary"></i>Driver</div>
            <div class="card-body">
                <div class="fw-bold"><?= e($h['driver_name_rel'] ?? $h['driver_name'] ?? '—') ?></div>
                <?php if ($h['driver_phone']): ?>
                <div class="text-muted small mt-1"><i class="fa fa-phone me-1"></i><?= e($h['driver_phone']) ?></div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card h-100">
            <div class="card-header fw-semibold"><i class="fa fa-clock me-2 text-primary"></i>Timestamps</div>
            <div class="card-body">
                <?php if ($h['checked_out_at']): ?>
                <div class="mb-2">
                    <div class="text-muted small">Checked Out</div>
                    <div class="fw-semibold"><?= fmtDate($h['checked_out_at'], 'd M Y, H:i') ?></div>
                    <div class="text-muted small">by <?= e($h['checked_out_by']) ?></div>
                </div>
                <?php endif; ?>
                <?php if ($h['checked_in_at']): ?>
                <div>
                    <div class="text-muted small">Checked In</div>
                    <div class="fw-semibold"><?= fmtDate($h['checked_in_at'], 'd M Y, H:i') ?></div>
                    <div class="text-muted small">by <?= e($h['checked_in_by']) ?></div>
                </div>
                <?php endif; ?>
                <?php if (!$h['checked_out_at'] && !$h['checked_in_at']): ?>
                <span class="text-muted small">Not yet started.</span>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Keys table -->
<div class="card mb-4">
    <div class="card-header fw-semibold">
        <i class="fa fa-key me-2"></i>Keys on this Run
        <span class="badge bg-primary ms-2"><?= count($items) ?></span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead class="table-light">
                <tr>
                    <th class="ps-3">#</th>
                    <th>Key Label</th>
                    <th>Vehicle</th>
                    <th>Checked Out</th>
                    <th>Checked In</th>
                    <th>Notes</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($items as $idx => $it): ?>
                <tr>
                    <td class="ps-3 text-muted"><?= $idx + 1 ?></td>
                    <td class="fw-semibold font-monospace"><?= e($it['key_label']) ?></td>
                    <td class="small">
                        <?= e($it['make'] . ' ' . $it['model']) ?>
                        <?php if ($it['registration_number']): ?>
                        <span class="badge bg-dark bg-opacity-75 ms-1"><?= e($it['registration_number']) ?></span>
                        <?php endif; ?>
                    </td>
                    <td class="small">
                        <?php if ($it['checked_out_at']): ?>
                        <span class="text-success"><i class="fa fa-check me-1"></i><?= fmtDate($it['checked_out_at'], 'H:i') ?></span>
                        <?php else: ?><span class="text-muted">—</span><?php endif; ?>
                    </td>
                    <td class="small">
                        <?php if ($it['checked_in_at']): ?>
                        <span class="text-success"><i class="fa fa-check me-1"></i><?= fmtDate($it['checked_in_at'], 'H:i') ?></span>
                        <?php else: ?><span class="text-muted">—</span><?php endif; ?>
                    </td>
                    <td class="text-muted small"><?= $it['notes'] ? e($it['notes']) : '—' ?></td>
                </tr>
                <?php endforeach; ?>
                <?php if (!$items): ?>
                <tr><td colspan="6" class="text-center text-muted py-3">No keys on this run.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
        </div>
    </div>
</div>

<?php if ($h['notes']): ?>
<div class="card mb-4">
    <div class="card-header fw-semibold">Notes</div>
    <div class="card-body"><p class="mb-0"><?= nl2br(e($h['notes'])) ?></p></div>
</div>
<?php endif; ?>

<?php if (canWrite('key_handovers')): ?>

<!-- ── CHECK OUT (pending) ─────────────────────────────────────── -->
<?php if ($h['status'] === 'pending' && count($items)): ?>
<div class="card mb-3" style="border-top:3px solid #2563eb">
    <div class="card-header fw-semibold"><i class="fa fa-box-open me-2"></i>Check Out Keys</div>
    <div class="card-body">
        <p class="text-muted mb-3">Confirm that <strong><?= e($h['driver_name_rel'] ?? $h['driver_name']) ?></strong> has physically received all <?= count($items) ?> key<?= count($items) !== 1 ? 's' : '' ?> listed above.</p>
        <form method="POST" class="d-flex gap-2">
            <input type="hidden" name="action" value="checkout">
            <button type="submit" class="btn btn-primary">
                <i class="fa fa-box-open me-2"></i>Confirm Keys Checked Out
            </button>
            <button type="submit" form="cancelRunForm" class="btn btn-outline-danger">
                <i class="fa fa-xmark me-1"></i>Cancel Run
            </button>
        </form>
        <form method="POST" id="cancelRunForm"><input type="hidden" name="action" value="cancel"></form>
    </div>
</div>
<?php endif; ?>

<!-- ── CHECK IN (checked_out) ─────────────────────────────────── -->
<?php if ($h['status'] === 'checked_out'): ?>
<div class="card mb-3" style="border-top:3px solid #22c55e">
    <div class="card-header fw-semibold"><i class="fa fa-circle-check me-2"></i>Confirm Keys Received at <?= e($h['to_name']) ?></div>
    <div class="card-body">
        <p class="text-muted mb-3">Confirm that all <?= count($items) ?> key<?= count($items) !== 1 ? 's' : '' ?> have been received and signed in at <strong><?= e($h['to_name']) ?></strong>. Key locations will be updated automatically.</p>
        <form method="POST">
            <input type="hidden" name="action" value="checkin">
            <button type="submit" class="btn btn-success px-4">
                <i class="fa fa-circle-check me-2"></i>Confirm Keys Received
            </button>
        </form>
    </div>
</div>
<?php endif; ?>

<?php endif; ?>

<?php include __DIR__ . '/../../includes/footer.php'; ?>

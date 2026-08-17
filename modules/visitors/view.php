<?php
/**
 * Visitors — one visit in full.
 *
 * Where the "someone is at reception to see you" notification lands, so the
 * person being visited sees who is waiting, why, and how long they have been
 * there — without having to search a list.
 */
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/_bootstrap.php';
requireLogin();

$db = getDB();
visitorsMigrate($db);
$me   = authUser();
$meId = (int)$me['id'];
$id   = (int)($_GET['id'] ?? 0);

$st = $db->prepare("
    SELECT v.*, u.name AS staff_name, a.name AS officer_name, r.name AS recorded_by_name,
           o.name AS checked_out_by_name, loc.name AS location_name,
           TRIM(CONCAT_WS(' ', c.year, c.make, c.model)) AS car_label,
           c.registration_number AS car_reg, c.id AS car_ref
    FROM visitors v
    LEFT JOIN users u ON u.id = v.staff_id
    LEFT JOIN users a ON a.id = v.assigned_to
    LEFT JOIN users r ON r.id = v.recorded_by
    LEFT JOIN users o ON o.id = v.checked_out_by
    LEFT JOIN cars  c ON c.id = v.car_id
    LEFT JOIN locations loc ON loc.id = v.location_id
    WHERE v.id = ?");
$st->execute([$id]);
$v = $st->fetch(PDO::FETCH_ASSOC);
if (!$v) { setFlash('error', 'That visitor record no longer exists.'); redirect(BASE_URL . '/modules/visitors/index.php'); }

// The visitors log is management information, but the person a visitor asked for
// must be able to open the notification they were just sent about themselves.
$isHost = (int)$v['staff_id'] === $meId;
if (!canAccess('visitors') && !$isHost) {
    setFlash('error', 'You can only open a visit you were the host for.');
    redirect(BASE_URL . '/index.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'check_out') {
    if (visitorCheckOut($db, $id, $meId)) setFlash('success', 'Visitor signed out.');
    else                                  setFlash('error', 'That visitor was already signed out.');
    redirect(BASE_URL . '/modules/visitors/view.php?id=' . $id);
}

[$pl, $pi, $pc] = visitorPurposes()[$v['purpose']] ?? ['—', 'fa-user', '#64748b'];
$mins = (int)round(((strtotime($v['checked_out_at'] ?: 'now')) - strtotime($v['created_at'])) / 60);

$pageTitle = 'Visitor — ' . visitorFullName($v);
include __DIR__ . '/../../includes/header.php';
?>
<style>
.vv-row{ display:flex; gap:12px; padding:9px 0; border-bottom:1px solid var(--border); font-size:13.5px; }
.vv-row:last-child{ border-bottom:0; }
.vv-row .k{ width:150px; flex:0 0 150px; color:var(--text-2,#64748b); font-size:12.5px; }
.vv-row .val{ flex:1; min-width:0; }
</style>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <a href="<?= BASE_URL ?>/modules/visitors/index.php" class="btn btn-sm btn-outline-secondary">
        <i class="fa fa-arrow-left me-1"></i>All visitors
    </a>
    <div class="d-flex gap-2">
        <a href="<?= BASE_URL ?>/modules/visitors/badge.php?id=<?= $id ?>" target="_blank"
           class="btn btn-sm btn-outline-primary">
            <i class="fa fa-id-badge me-1"></i>Print badge
        </a>
        <?php if (!$v['checked_out_at']): ?>
        <form method="POST" class="d-inline">
            <?= csrfField() ?><input type="hidden" name="action" value="check_out">
            <button class="btn btn-sm btn-success">
                <i class="fa fa-right-from-bracket me-1"></i>Sign this visitor out
            </button>
        </form>
        <?php endif; ?>
    </div>
</div>

<div class="card mb-3">
    <div class="card-body">
        <div class="d-flex justify-content-between align-items-start flex-wrap gap-3">
            <div style="min-width:0">
                <h1 style="font-size:21px;font-weight:800;letter-spacing:-.4px;margin:0">
                    <?= e(visitorFullName($v)) ?>
                </h1>
                <div class="mt-2 d-flex align-items-center gap-2 flex-wrap" style="font-size:12.5px">
                    <span class="vs-tag" style="background:<?= $pc ?>1f;color:<?= $pc ?>;
                          display:inline-flex;align-items:center;gap:6px;padding:3px 10px;
                          border-radius:20px;font-weight:700">
                        <i class="fa <?= $pi ?>"></i><?= e($pl) ?>
                    </span>
                    <span class="text-muted">
                        <i class="fa fa-clock me-1"></i><?= date('D j M Y, H:i', strtotime($v['created_at'])) ?>
                    </span>
                </div>
            </div>
            <div class="text-end">
                <?php if ($v['checked_out_at']): ?>
                <span class="badge bg-secondary" style="font-size:11px">Signed out</span>
                <div class="text-muted mt-1" style="font-size:11.5px">
                    <?= date('H:i', strtotime($v['checked_out_at'])) ?>
                    <?= $v['checked_out_by_name'] ? ' by ' . e($v['checked_out_by_name']) : '' ?>
                </div>
                <div class="text-muted" style="font-size:11.5px">
                    on site <?= $mins < 60 ? $mins . ' min' : floor($mins / 60) . 'h ' . ($mins % 60) . 'm' ?>
                </div>
                <?php else: ?>
                <span class="badge bg-success" style="font-size:11px">
                    <i class="fa fa-circle me-1" style="font-size:7px"></i>On site
                </span>
                <div class="text-muted mt-1" style="font-size:11.5px">
                    here <?= $mins < 60 ? $mins . ' min' : floor($mins / 60) . 'h ' . ($mins % 60) . 'm' ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header fw-semibold"><i class="fa fa-user me-2"></i>Contact</div>
            <div class="card-body">
                <div class="vv-row"><span class="k">Phone</span>
                    <span class="val"><a href="tel:<?= e($v['phone']) ?>"><?= e($v['phone']) ?></a></span></div>
                <?php if ($v['email']): ?>
                <div class="vv-row"><span class="k">Email</span>
                    <span class="val text-break"><a href="mailto:<?= e($v['email']) ?>"><?= e($v['email']) ?></a></span></div>
                <?php endif; ?>
                <?php if ($v['id_number']): ?>
                <div class="vv-row"><span class="k">ID number</span>
                    <span class="val"><?= e($v['id_number']) ?></span></div>
                <?php endif; ?>
                <div class="vv-row"><span class="k">Signed in at</span>
                    <span class="val"><?= $v['location_name']
                        ? '<i class="fa fa-location-dot me-1 text-muted"></i>' . e($v['location_name'])
                        : '<span class="text-muted">No location recorded</span>' ?></span></div>
                <div class="vv-row"><span class="k">Heard about us</span>
                    <span class="val"><?= e($v['heard_from'] ?: '—') ?></span></div>
                <div class="vv-row"><span class="k">Signed in by</span>
                    <span class="val"><?= e($v['recorded_by_name'] ?: 'Reception') ?></span></div>
            </div>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header fw-semibold">
                <i class="fa <?= $pi ?> me-2"></i><?= e($pl) ?>
            </div>
            <div class="card-body">
                <?php if ($v['purpose'] === 'buy_car'): ?>
                    <div class="vv-row"><span class="k">Vehicle</span>
                        <span class="val">
                            <?php if ($v['car_ref']): ?>
                            <a href="<?= BASE_URL ?>/modules/cars/view.php?id=<?= (int)$v['car_ref'] ?>">
                                <?= e($v['car_label']) ?></a>
                            <?php else: ?><?= e($v['car_label'] ?: 'Not recorded') ?><?php endif; ?>
                        </span></div>
                    <?php if ($v['buy_comment']): ?>
                    <div class="vv-row"><span class="k">Their comment</span>
                        <span class="val" style="white-space:pre-wrap"><?= e($v['buy_comment']) ?></span></div>
                    <?php endif; ?>
                    <div class="vv-row"><span class="k">Lead</span>
                        <span class="val">
                            <?php if ($v['lead_id']): ?>
                            <a href="<?= BASE_URL ?>/modules/crm/view_lead.php?id=<?= (int)$v['lead_id'] ?>">
                                Lead #<?= (int)$v['lead_id'] ?></a>
                            <?= $v['officer_name'] ? ' — with ' . e($v['officer_name']) : '' ?>
                            <?php else: ?><span class="text-muted">Not created</span><?php endif; ?>
                        </span></div>

                <?php elseif ($v['purpose'] === 'car_service'): ?>
                    <div class="vv-row"><span class="k">Vehicle</span>
                        <span class="val fw-semibold"><?= e(trim($v['svc_make'] . ' ' . $v['svc_model'])) ?></span></div>
                    <div class="vv-row"><span class="k">Registration</span>
                        <span class="val"><?= e($v['svc_reg'] ?: '—') ?></span></div>
                    <div class="vv-row"><span class="k">Year / mileage</span>
                        <span class="val"><?= e($v['svc_year'] ?: '—') ?>
                            <?= $v['svc_mileage'] ? ' · ' . number_format((int)$v['svc_mileage']) . ' km' : '' ?></span></div>
                    <?php if ($v['svc_notes']): ?>
                    <div class="vv-row"><span class="k">What needs doing</span>
                        <span class="val" style="white-space:pre-wrap"><?= e($v['svc_notes']) ?></span></div>
                    <?php endif; ?>
                    <div class="vv-row"><span class="k">Client record</span>
                        <span class="val">
                            <?php if ($v['client_id']): ?>
                            <a href="<?= BASE_URL ?>/modules/clients/view.php?id=<?= (int)$v['client_id'] ?>">
                                Client #<?= (int)$v['client_id'] ?></a>
                            <?php else: ?><span class="text-muted">Not created</span><?php endif; ?>
                        </span></div>

                <?php else: ?>
                    <div class="vv-row"><span class="k">Came to see</span>
                        <span class="val fw-semibold"><?= e($v['staff_name'] ?: '—') ?></span></div>
                    <div class="vv-row"><span class="k">Reason</span>
                        <span class="val" style="white-space:pre-wrap"><?= e($v['visit_reason'] ?: '—') ?></span></div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>

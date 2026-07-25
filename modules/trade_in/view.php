<?php
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/_bootstrap.php';
requireLogin();
canAccess('trade_in') || redirect(BASE_URL . '/index.php');

$db = getDB();
tradeInMigrate($db);

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$c  = $id ? consignmentFind($db, $id) : null;
if (!$c) { setFlash('error', 'Record not found.'); redirect(BASE_URL . '/modules/trade_in/index.php'); }

$types    = consignmentTypes();
$statuses = consignmentStatuses();
$isTrade  = $c['deal_type'] === 'trade_in';
$user     = authUser();

// ── Actions ───────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    canWrite('trade_in') || die('Permission denied.');
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'record_sale') {
            $price = (float)($_POST['sold_price'] ?? 0);
            $date  = $_POST['sold_date'] ?? date('Y-m-d');
            if ($price <= 0) {
                setFlash('error', 'Enter the price the vehicle sold for.');
            } else {
                $commission = consignmentCommission($c, $price);
                $payout     = round(max(0, $price - $commission), 2);
                $db->beginTransaction();
                $db->prepare("UPDATE consignments
                              SET status='sold', sold_price=?, sold_date=?, commission_amount=?,
                                  payout_amount=?, payout_status='pending'
                              WHERE id=?")
                   ->execute([$price, $date, $commission, $payout, $id]);
                // The vehicle is gone from the yard — take it off the website.
                $db->prepare("UPDATE cars SET status='sold', show_on_website=0 WHERE id=?")
                   ->execute([(int)$c['car_id']]);
                $db->commit();

                logActivity('update', 'trade_in', $id,
                    "Sold {$c['reference']} for " . money($price) . " — commission " . money($commission));

                require_once __DIR__ . '/../../includes/notifications.php';
                notifyRoles(['admin','general_manager','finance_manager','finance_officer'], 'sale',
                    "Consignment Sold: {$c['reference']}",
                    "{$c['make']} {$c['model']} sold for " . money($price) . " — owner " . money($payout) . " due to {$c['owner_name']}",
                    BASE_URL . '/modules/trade_in/view.php?id=' . $id);

                setFlash('success', 'Sale recorded. ' . money($payout) . ' is now due to the owner.');
            }
        }

        elseif ($action === 'record_payout') {
            $amount = (float)($_POST['payout_amount'] ?? 0);
            $due    = (float)($c['payout_amount'] ?: 0);
            $paid   = (float)($c['payout_paid'] ?: 0);
            if ($amount <= 0) {
                setFlash('error', 'Enter the amount paid to the owner.');
            } elseif ($amount > ($due - $paid) + 0.01) {
                setFlash('error', 'That is more than the outstanding balance of ' . money($due - $paid) . '.');
            } else {
                $newPaid = round($paid + $amount, 2);
                $status  = $newPaid >= $due - 0.01 ? 'paid' : 'partial';
                $db->prepare("UPDATE consignments
                              SET payout_paid=?, payout_status=?, payout_date=?, payout_reference=?
                              WHERE id=?")
                   ->execute([$newPaid, $status, $_POST['payout_date'] ?: date('Y-m-d'),
                              trim($_POST['payout_reference'] ?? '') ?: null, $id]);
                logActivity('update', 'trade_in', $id, "Paid owner " . money($amount) . " on {$c['reference']}");
                setFlash('success', 'Payout of ' . money($amount) . ' recorded.');
            }
        }

        elseif ($action === 'withdraw') {
            $db->beginTransaction();
            $db->prepare("UPDATE consignments SET status='withdrawn' WHERE id=?")->execute([$id]);
            $db->prepare("UPDATE cars SET show_on_website=0 WHERE id=?")->execute([(int)$c['car_id']]);
            $db->commit();
            logActivity('update', 'trade_in', $id, "Withdrew {$c['reference']} — vehicle returned to owner");
            setFlash('success', 'Marked as withdrawn and removed from the website.');
        }

        elseif ($action === 'reactivate') {
            $db->prepare("UPDATE consignments SET status='active' WHERE id=?")->execute([$id]);
            logActivity('update', 'trade_in', $id, "Reactivated {$c['reference']}");
            setFlash('success', 'Record set back to active.');
        }

        elseif ($action === 'toggle_website') {
            $new = (int)$c['show_on_website'] ? 0 : 1;
            $db->prepare("UPDATE cars SET show_on_website=? WHERE id=?")->execute([$new, (int)$c['car_id']]);
            setFlash('success', $new ? 'Now advertised on the public website.' : 'Removed from the public website.');
        }

        elseif ($action === 'convert_to_inventory' && $isTrade) {
            $db->beginTransaction();
            $db->prepare("UPDATE cars SET car_type='inventory', owner_name=NULL, owner_phone=NULL WHERE id=?")
               ->execute([(int)$c['car_id']]);
            $db->prepare("UPDATE consignments SET status='sold', sold_date=COALESCE(sold_date, CURDATE()) WHERE id=?")
               ->execute([$id]);
            $db->commit();
            logActivity('update', 'trade_in', $id, "Converted {$c['reference']} to inventory stock");
            setFlash('success', 'Vehicle moved into Mascardi inventory. Set its asking price to list it for sale.');
            redirect(BASE_URL . '/modules/cars/view.php?id=' . (int)$c['car_id']);
        }
    } catch (\Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        setFlash('error', 'Action failed: ' . $e->getMessage());
    }
    redirect(BASE_URL . '/modules/trade_in/view.php?id=' . $id);
}

// ── Derived figures ───────────────────────────────────────────────────────────
$st          = $statuses[$c['status']] ?? $statuses['active'];
$commission  = $c['status'] === 'sold' ? (float)($c['commission_amount'] ?: 0) : consignmentCommission($c);
$payout      = $c['status'] === 'sold' ? (float)($c['payout_amount'] ?: 0)     : consignmentPayout($c);
$paid        = (float)($c['payout_paid'] ?: 0);
$outstanding = max(0, $payout - $paid);
$img         = $c['primary_image'] ? thumbUrl('cars', $c['primary_image']) : null;
$expiring    = $c['status'] === 'active' && $c['expiry_date'] && strtotime($c['expiry_date']) < strtotime('+14 days');
$expired     = $c['status'] === 'active' && $c['expiry_date'] && strtotime($c['expiry_date']) < time();

$againstCar = null;
if ($c['against_car_id']) {
    try {
        $s = $db->prepare("SELECT id, make, model, year, registration_number FROM cars WHERE id=?");
        $s->execute([(int)$c['against_car_id']]);
        $againstCar = $s->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (\Throwable $_) {}
}

$pageTitle = $c['reference'] ?: $types[$c['deal_type']]['label'];
include __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div>
        <h5 class="mb-1">
            <i class="fa <?= $types[$c['deal_type']]['icon'] ?> me-2" style="color:<?= $types[$c['deal_type']]['color'] ?>"></i>
            <?= e($c['reference'] ?: $types[$c['deal_type']]['label']) ?>
            <span class="badge ms-1" style="background:<?= $st['color'] ?>;font-size:11px"><?= $st['label'] ?></span>
        </h5>
        <div class="text-muted small">
            <?= $types[$c['deal_type']]['label'] ?> · Recorded <?= fmtDate($c['created_at']) ?>
            <?= $c['created_by_name'] ? ' by ' . e($c['created_by_name']) : '' ?>
        </div>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <a href="index.php?tab=<?= e($c['deal_type']) ?>" class="btn btn-sm btn-outline-secondary">
            <i class="fa fa-arrow-left me-1"></i>Back
        </a>
        <a href="<?= BASE_URL ?>/modules/cars/view.php?id=<?= (int)$c['car_id'] ?>" class="btn btn-sm btn-outline-secondary">
            <i class="fa fa-car me-1"></i>Vehicle Record
        </a>
        <?php if (!$isTrade): ?>
        <a href="agreement.php?id=<?= $id ?>" target="_blank" class="btn btn-sm btn-outline-primary">
            <i class="fa fa-file-contract me-1"></i>Agreement
        </a>
        <?php endif; ?>
        <?php if (canWrite('trade_in')): ?>
        <a href="edit.php?id=<?= $id ?>" class="btn btn-sm btn-primary">
            <i class="fa fa-pen me-1"></i>Edit
        </a>
        <?php endif; ?>
    </div>
</div>

<?php if ($expired): ?>
<div class="alert alert-danger py-2 small">
    <i class="fa fa-triangle-exclamation me-1"></i>
    This agreement expired on <strong><?= fmtDate($c['expiry_date']) ?></strong>.
    Renew it with the owner or withdraw the vehicle.
</div>
<?php elseif ($expiring): ?>
<div class="alert alert-warning py-2 small">
    <i class="fa fa-clock me-1"></i>
    This agreement expires on <strong><?= fmtDate($c['expiry_date']) ?></strong>.
</div>
<?php endif; ?>

<div class="row g-3">
    <!-- ── Left: vehicle + owner ──────────────────────────────────────────── -->
    <div class="col-lg-7">
        <div class="card mb-3">
            <div class="card-header"><i class="fa fa-car-side me-2"></i>Vehicle</div>
            <div class="card-body">
                <div class="d-flex gap-3 flex-wrap">
                    <div style="width:180px;height:130px;border-radius:12px;overflow:hidden;background:var(--surface-alt,#f1f5f9);flex-shrink:0">
                        <?php if ($img): ?>
                        <img src="<?= e($img) ?>" alt="" style="width:100%;height:100%;object-fit:cover">
                        <?php else: ?>
                        <div class="d-flex align-items-center justify-content-center h-100" style="color:#cbd5e1;font-size:34px">
                            <i class="fa fa-car-side"></i>
                        </div>
                        <?php endif; ?>
                    </div>
                    <div class="flex-grow-1" style="min-width:220px">
                        <div class="fw-bold" style="font-size:18px"><?= e($c['year'] . ' ' . $c['make'] . ' ' . $c['model']) ?></div>
                        <div class="text-muted small mb-2">
                            <?= e($c['registration_number'] ?: 'No reg.') ?> · <?= e($c['chassis_number']) ?>
                        </div>
                        <div class="row g-2" style="font-size:12.5px">
                            <div class="col-6"><span class="text-muted">Body</span><br><span class="fw-semibold"><?= e($c['body_type'] ?: '—') ?></span></div>
                            <div class="col-6"><span class="text-muted">Color</span><br><span class="fw-semibold"><?= e($c['color'] ?: '—') ?></span></div>
                            <div class="col-6"><span class="text-muted">Transmission</span><br><span class="fw-semibold"><?= ucfirst($c['transmission'] ?: '—') ?></span></div>
                            <div class="col-6"><span class="text-muted">Fuel</span><br><span class="fw-semibold"><?= ucfirst($c['fuel_type'] ?: '—') ?></span></div>
                            <div class="col-6"><span class="text-muted">Mileage</span><br><span class="fw-semibold"><?= $c['mileage'] ? number_format((int)$c['mileage']) . ' km' : '—' ?></span></div>
                            <div class="col-6"><span class="text-muted">Engine</span><br><span class="fw-semibold"><?= $c['engine_cc'] ? number_format((int)$c['engine_cc']) . ' cc' : '—' ?></span></div>
                        </div>
                    </div>
                </div>
                <?php if ($c['car_notes']): ?>
                <div class="mt-3 pt-3 border-top">
                    <div class="text-muted small mb-1">Description</div>
                    <div style="font-size:13px"><?= nl2br(e($c['car_notes'])) ?></div>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="card">
            <div class="card-header"><i class="fa fa-user me-2"></i>Vehicle Owner</div>
            <div class="card-body">
                <div class="row g-3" style="font-size:13px">
                    <div class="col-md-6">
                        <div class="text-muted small">Name</div>
                        <div class="fw-semibold"><?= e($c['owner_name']) ?></div>
                    </div>
                    <div class="col-md-6">
                        <div class="text-muted small">Phone</div>
                        <div class="fw-semibold">
                            <?php if ($c['owner_phone']): ?>
                            <a href="tel:<?= e($c['owner_phone']) ?>" class="text-decoration-none"><?= e($c['owner_phone']) ?></a>
                            <?php $wa = preg_replace('/[^0-9]/', '', $c['owner_phone']);
                                  if (str_starts_with($c['owner_phone'], '0')) $wa = '254' . substr($wa, 1); ?>
                            <a href="https://wa.me/<?= $wa ?>" target="_blank" class="btn btn-xs btn-success ms-1" style="font-size:10px;padding:1px 6px">
                                <i class="fab fa-whatsapp"></i>
                            </a>
                            <?php else: ?>—<?php endif; ?>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="text-muted small">Email</div>
                        <div class="fw-semibold"><?= $c['owner_email'] ? e($c['owner_email']) : '—' ?></div>
                    </div>
                    <div class="col-md-6">
                        <div class="text-muted small">ID / Passport</div>
                        <div class="fw-semibold"><?= $c['owner_id_number'] ? e($c['owner_id_number']) : '—' ?></div>
                    </div>
                    <div class="col-12">
                        <div class="text-muted small">Address</div>
                        <div class="fw-semibold"><?= $c['owner_address'] ? e($c['owner_address']) : '—' ?></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ── Right: commercials + actions ───────────────────────────────────── -->
    <div class="col-lg-5">
        <div class="card mb-3">
            <div class="card-header">
                <i class="fa fa-coins me-2"></i><?= $isTrade ? 'Valuation & Allowance' : 'Deal Summary' ?>
            </div>
            <div class="card-body">
            <?php if ($isTrade): ?>
                <div class="d-flex justify-content-between py-2 border-bottom">
                    <span class="text-muted" style="font-size:13px">Our valuation</span>
                    <span class="fw-bold"><?= money((float)($c['valuation_amount'] ?: 0)) ?></span>
                </div>
                <div class="d-flex justify-content-between py-2 border-bottom">
                    <span class="text-muted" style="font-size:13px">Allowance given</span>
                    <span class="fw-bold" style="color:#c2410c"><?= money((float)($c['trade_in_value'] ?: 0)) ?></span>
                </div>
                <?php
                $margin = (float)($c['valuation_amount'] ?: 0) - (float)($c['trade_in_value'] ?: 0);
                if ((float)($c['valuation_amount'] ?: 0) > 0): ?>
                <div class="d-flex justify-content-between py-2">
                    <span class="text-muted" style="font-size:13px">Margin on trade</span>
                    <span class="fw-bold" style="color:<?= $margin >= 0 ? '#15803d' : '#dc2626' ?>">
                        <?= ($margin >= 0 ? '' : '−') ?><?= money(abs($margin)) ?>
                    </span>
                </div>
                <?php endif; ?>
                <?php if ($againstCar): ?>
                <div class="mt-2 pt-2 border-top">
                    <div class="text-muted small mb-1">Traded against</div>
                    <a href="<?= BASE_URL ?>/modules/cars/view.php?id=<?= (int)$againstCar['id'] ?>" class="fw-semibold text-decoration-none">
                        <?= e($againstCar['year'] . ' ' . $againstCar['make'] . ' ' . $againstCar['model']) ?>
                        <?= $againstCar['registration_number'] ? ' — ' . e($againstCar['registration_number']) : '' ?>
                    </a>
                </div>
                <?php endif; ?>
                <div class="mt-2 pt-2 border-top">
                    <div class="text-muted small">Date received</div>
                    <div class="fw-semibold"><?= $c['agreement_date'] ? fmtDate($c['agreement_date']) : '—' ?></div>
                </div>
            <?php else: ?>
                <div class="d-flex justify-content-between py-2 border-bottom">
                    <span class="text-muted" style="font-size:13px"><?= $c['status'] === 'sold' ? 'Sold for' : 'Listed at' ?></span>
                    <span class="fw-bold" style="font-size:15px;color:#1d4ed8">
                        <?= money((float)($c['status'] === 'sold' ? $c['sold_price'] : $c['listing_price'])) ?>
                    </span>
                </div>
                <div class="d-flex justify-content-between py-2 border-bottom">
                    <span class="text-muted" style="font-size:13px">
                        Commission
                        <span class="badge bg-secondary-subtle text-secondary ms-1" style="font-size:10px">
                            <?= $c['commission_type'] === 'fixed'
                                ? 'Fixed'
                                : rtrim(rtrim(number_format((float)$c['commission_value'], 2), '0'), '.') . '%' ?>
                        </span>
                    </span>
                    <span class="fw-bold" style="color:#15803d"><?= money($commission) ?></span>
                </div>
                <div class="d-flex justify-content-between py-2 <?= $c['status'] === 'sold' ? 'border-bottom' : '' ?>">
                    <span class="text-muted" style="font-size:13px">Owner receives</span>
                    <span class="fw-bold" style="color:#c2410c"><?= money($payout) ?></span>
                </div>
                <?php if ($c['status'] === 'sold'): ?>
                <div class="d-flex justify-content-between py-2 border-bottom">
                    <span class="text-muted" style="font-size:13px">Paid so far</span>
                    <span class="fw-bold"><?= money($paid) ?></span>
                </div>
                <div class="d-flex justify-content-between py-2">
                    <span class="text-muted" style="font-size:13px">Outstanding</span>
                    <span class="fw-bold" style="color:<?= $outstanding > 0 ? '#dc2626' : '#15803d' ?>">
                        <?= money($outstanding) ?>
                    </span>
                </div>
                <?php endif; ?>
                <?php if ($c['owner_expected_price']): ?>
                <div class="alert <?= $payout >= (float)$c['owner_expected_price'] ? 'alert-success' : 'alert-warning' ?> py-2 small mt-2 mb-0">
                    <i class="fa fa-bullseye me-1"></i>
                    Owner expected <strong><?= money((float)$c['owner_expected_price']) ?></strong> —
                    <?= $payout >= (float)$c['owner_expected_price']
                        ? 'target met.'
                        : 'short by ' . money((float)$c['owner_expected_price'] - $payout) . '.' ?>
                </div>
                <?php endif; ?>
                <div class="row g-2 mt-2 pt-2 border-top" style="font-size:12.5px">
                    <div class="col-6">
                        <div class="text-muted">Agreement</div>
                        <div class="fw-semibold"><?= $c['agreement_date'] ? fmtDate($c['agreement_date']) : '—' ?></div>
                    </div>
                    <div class="col-6">
                        <div class="text-muted">Expires</div>
                        <div class="fw-semibold <?= $expired ? 'text-danger' : ($expiring ? 'text-warning' : '') ?>">
                            <?= $c['expiry_date'] ? fmtDate($c['expiry_date']) : '—' ?>
                        </div>
                    </div>
                    <?php if ($c['sold_date']): ?>
                    <div class="col-6">
                        <div class="text-muted">Sold on</div>
                        <div class="fw-semibold"><?= fmtDate($c['sold_date']) ?></div>
                    </div>
                    <?php endif; ?>
                    <?php if ($c['payout_reference']): ?>
                    <div class="col-6">
                        <div class="text-muted">Payout ref</div>
                        <div class="fw-semibold"><?= e($c['payout_reference']) ?></div>
                    </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
            </div>
        </div>

        <?php if (canWrite('trade_in')): ?>
        <!-- ── Website visibility ─────────────────────────────────────────── -->
        <div class="card mb-3">
            <div class="card-body py-3 d-flex align-items-center justify-content-between gap-2 flex-wrap">
                <div>
                    <div class="fw-semibold" style="font-size:13.5px">
                        <i class="fa fa-globe me-1 <?= $c['show_on_website'] ? 'text-success' : 'text-muted' ?>"></i>
                        Public website
                    </div>
                    <div class="text-muted" style="font-size:12px">
                        <?= $c['show_on_website'] ? 'Visible in the public showroom' : 'Hidden from the public showroom' ?>
                    </div>
                </div>
                <div class="d-flex gap-2">
                    <?php if ($c['show_on_website']): ?>
                    <a href="<?= BASE_URL ?>/showroom/view.php?id=<?= (int)$c['car_id'] ?>" target="_blank"
                       class="btn btn-sm btn-outline-success"><i class="fa fa-up-right-from-square"></i></a>
                    <?php endif; ?>
                    <form method="POST" class="d-inline">
                        <?= csrfField() ?>
                        <input type="hidden" name="id" value="<?= $id ?>">
                        <input type="hidden" name="action" value="toggle_website">
                        <button class="btn btn-sm <?= $c['show_on_website'] ? 'btn-outline-secondary' : 'btn-success' ?>">
                            <?= $c['show_on_website'] ? 'Unpublish' : 'Publish' ?>
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <!-- ── Settlement ─────────────────────────────────────────────────── -->
        <?php if (!$isTrade && $c['status'] === 'active'): ?>
        <div class="card mb-3">
            <div class="card-header"><i class="fa fa-cash-register me-2"></i>Record Sale</div>
            <div class="card-body">
                <form method="POST">
                    <?= csrfField() ?>
                    <input type="hidden" name="id" value="<?= $id ?>">
                    <input type="hidden" name="action" value="record_sale">
                    <div class="row g-2">
                        <div class="col-7">
                            <label class="form-label small">Sold For (KES)</label>
                            <input type="number" name="sold_price" class="form-control" step="1" min="0" required
                                   value="<?= e((string)($c['listing_price'] ?: '')) ?>">
                        </div>
                        <div class="col-5">
                            <label class="form-label small">Date</label>
                            <input type="date" name="sold_date" class="form-control" value="<?= date('Y-m-d') ?>">
                        </div>
                    </div>
                    <button class="btn btn-primary btn-sm w-100 mt-3">
                        <i class="fa fa-check me-1"></i>Mark as Sold
                    </button>
                    <div class="form-text mt-2">
                        Commission and the owner's payout are calculated from the actual sale price.
                    </div>
                </form>
            </div>
        </div>
        <?php endif; ?>

        <?php if (!$isTrade && $c['status'] === 'sold' && $outstanding > 0): ?>
        <div class="card mb-3">
            <div class="card-header"><i class="fa fa-hand-holding-dollar me-2"></i>Pay Owner</div>
            <div class="card-body">
                <form method="POST">
                    <?= csrfField() ?>
                    <input type="hidden" name="id" value="<?= $id ?>">
                    <input type="hidden" name="action" value="record_payout">
                    <div class="row g-2">
                        <div class="col-7">
                            <label class="form-label small">Amount (KES)</label>
                            <input type="number" name="payout_amount" class="form-control" step="1" min="0"
                                   max="<?= $outstanding ?>" required value="<?= round($outstanding) ?>">
                        </div>
                        <div class="col-5">
                            <label class="form-label small">Date</label>
                            <input type="date" name="payout_date" class="form-control" value="<?= date('Y-m-d') ?>">
                        </div>
                        <div class="col-12">
                            <label class="form-label small">Reference <small class="text-muted">(M-Pesa code, cheque no.)</small></label>
                            <input type="text" name="payout_reference" class="form-control" value="<?= e((string)$c['payout_reference']) ?>">
                        </div>
                    </div>
                    <button class="btn btn-success btn-sm w-100 mt-3">
                        <i class="fa fa-money-bill-transfer me-1"></i>Record Payout
                    </button>
                    <div class="form-text mt-2">Outstanding: <strong><?= money($outstanding) ?></strong></div>
                </form>
            </div>
        </div>
        <?php elseif (!$isTrade && $c['status'] === 'sold'): ?>
        <div class="alert alert-success py-2 small">
            <i class="fa fa-circle-check me-1"></i>
            Owner fully paid<?= $c['payout_date'] ? ' on ' . fmtDate($c['payout_date']) : '' ?>.
        </div>
        <?php endif; ?>

        <!-- ── Other actions ──────────────────────────────────────────────── -->
        <div class="card">
            <div class="card-header"><i class="fa fa-gear me-2"></i>Actions</div>
            <div class="card-body d-flex flex-column gap-2">
                <?php if ($isTrade && $c['car_type'] === 'trade_in'): ?>
                <form method="POST" onsubmit="return confirm('Move this vehicle into Mascardi inventory? It will then be sold as your own stock.')">
                    <?= csrfField() ?>
                    <input type="hidden" name="id" value="<?= $id ?>">
                    <input type="hidden" name="action" value="convert_to_inventory">
                    <button class="btn btn-sm btn-primary w-100">
                        <i class="fa fa-warehouse me-1"></i>Convert to Inventory Stock
                    </button>
                </form>
                <div class="form-text mt-0">
                    Once reconditioned, move the vehicle into normal stock so it can be priced and sold.
                </div>
                <?php endif; ?>

                <?php if ($c['status'] === 'active'): ?>
                <form method="POST" onsubmit="return confirm('Mark as withdrawn? The vehicle is returned to the owner and removed from the website.')">
                    <?= csrfField() ?>
                    <input type="hidden" name="id" value="<?= $id ?>">
                    <input type="hidden" name="action" value="withdraw">
                    <button class="btn btn-sm btn-outline-danger w-100">
                        <i class="fa fa-rotate-left me-1"></i>Withdraw / Return to Owner
                    </button>
                </form>
                <?php elseif (in_array($c['status'], ['withdrawn','expired'], true)): ?>
                <form method="POST">
                    <?= csrfField() ?>
                    <input type="hidden" name="id" value="<?= $id ?>">
                    <input type="hidden" name="action" value="reactivate">
                    <button class="btn btn-sm btn-outline-primary w-100">
                        <i class="fa fa-play me-1"></i>Reactivate
                    </button>
                </form>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php if ($c['notes']): ?>
<div class="card mt-3">
    <div class="card-header"><i class="fa fa-note-sticky me-2"></i>Deal Notes</div>
    <div class="card-body" style="font-size:13px"><?= nl2br(e($c['notes'])) ?></div>
</div>
<?php endif; ?>

<?php include __DIR__ . '/../../includes/footer.php'; ?>

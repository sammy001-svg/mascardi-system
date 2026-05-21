<?php
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
canAccess('sales') || die('Access denied.');
$id = (int)($_GET['id'] ?? 0);
if (!$id) redirect(BASE_URL . '/modules/sales/index.php');
$db = getDB();

$stmt = $db->prepare("
    SELECT cs.*, c.make, c.model, c.year, c.chassis_number, c.registration_number,
           c.color, c.engine_number, c.fuel_type, c.transmission, c.body_type, c.status AS car_status,
           u.name AS sold_by_name
    FROM car_sales cs
    JOIN cars c ON c.id = cs.car_id
    LEFT JOIN users u ON u.id = cs.sold_by
    WHERE cs.id = ?
");
$stmt->execute([$id]); $sale = $stmt->fetch();
if (!$sale) { setFlash('error', 'Sale not found.'); redirect(BASE_URL . '/modules/sales/index.php'); }

// Confirm delivery POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'confirm_delivery') {
    canWrite('sales') || die('Permission denied.');
    $deliveryNotes = trim($_POST['delivery_notes'] ?? '');
    $db->prepare("UPDATE car_sales SET delivered_at=NOW(), delivery_notes=? WHERE id=?")->execute([$deliveryNotes, $id]);
    $db->prepare("UPDATE cars SET status='delivered' WHERE id=?")->execute([$sale['car_id']]);
    logActivity('update', 'sales', $id, "Delivery confirmed for sale {$sale['sale_number']} — {$sale['buyer_name']}");
    setFlash('success', 'Delivery confirmed. Car status updated to Delivered.');
    redirect(BASE_URL . '/modules/sales/view.php?id=' . $id);
}

// Cancel sale POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'cancel_sale') {
    canWrite('sales') || die('Permission denied.');
    $db->prepare("UPDATE car_sales SET status='cancelled' WHERE id=?")->execute([$id]);
    $db->prepare("UPDATE cars SET status='completed' WHERE id=?")->execute([$sale['car_id']]);
    logActivity('update', 'sales', $id, "Sale {$sale['sale_number']} cancelled");
    setFlash('success', 'Sale cancelled. Car returned to completed status.');
    redirect(BASE_URL . '/modules/sales/index.php');
}

$pageTitle = $sale['sale_number'];
include __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="mb-0"><i class="fa fa-tag me-2 text-success"></i>Sale: <strong><?= e($sale['sale_number']) ?></strong></h5>
    <div class="d-flex gap-2">
        <?php if (canWrite('sales') && $sale['status'] === 'active'): ?>
        <a href="edit.php?id=<?= $id ?>" class="btn btn-sm btn-outline-secondary"><i class="fa fa-pen me-1"></i>Edit</a>
        <?php endif; ?>
        <a href="index.php" class="btn btn-sm btn-outline-secondary"><i class="fa fa-arrow-left me-1"></i>Back</a>
    </div>
</div>

<?php if ($sale['status'] === 'cancelled'): ?>
<div class="alert alert-danger"><i class="fa fa-ban me-2"></i>This sale has been cancelled.</div>
<?php endif; ?>

<div class="row g-4">
    <div class="col-lg-5">
        <!-- Sale Summary -->
        <div class="card mb-3" style="border-top:3px solid #16a34a">
            <div class="card-header"><i class="fa fa-tag me-2 text-success"></i>Sale Summary</div>
            <div class="card-body">
                <dl class="row mb-0" style="font-size:13.5px">
                    <dt class="col-5 text-muted">Sale Number</dt><dd class="col-7 fw-bold"><?= e($sale['sale_number']) ?></dd>
                    <dt class="col-5 text-muted">Sale Date</dt><dd class="col-7"><?= fmtDate($sale['sale_date']) ?></dd>
                    <dt class="col-5 text-muted">Sale Price</dt><dd class="col-7 fw-bold text-success fs-6"><?= money((float)$sale['sale_price']) ?></dd>
                    <dt class="col-5 text-muted">Payment</dt><dd class="col-7"><?= statusBadge($sale['payment_status']) ?> — <?= e(ucwords(str_replace('_',' ',$sale['payment_method']))) ?></dd>
                    <?php if ($sale['deposit_amount'] > 0): ?>
                    <dt class="col-5 text-muted">Deposit</dt><dd class="col-7"><?= money((float)$sale['deposit_amount']) ?></dd>
                    <dt class="col-5 text-muted">Balance</dt><dd class="col-7 <?= $sale['balance_amount']>0?'text-danger fw-semibold':'' ?>"><?= money((float)$sale['balance_amount']) ?></dd>
                    <?php endif; ?>
                    <?php if ($sale['finance_company']): ?>
                    <dt class="col-5 text-muted">Financed By</dt><dd class="col-7"><?= e($sale['finance_company']) ?></dd>
                    <?php endif; ?>
                    <dt class="col-5 text-muted">Sold By</dt><dd class="col-7"><?= e($sale['sold_by_name'] ?? '—') ?></dd>
                    <dt class="col-5 text-muted">Recorded</dt><dd class="col-7 text-muted small"><?= fmtDate($sale['created_at']) ?></dd>
                </dl>
                <?php if ($sale['notes']): ?><hr><p class="small text-muted mb-0"><?= nl2br(e($sale['notes'])) ?></p><?php endif; ?>
            </div>
        </div>

        <!-- Delivery Status -->
        <div class="card <?= !$sale['delivered_at'] && $sale['status']==='active' ? 'border-warning' : '' ?>">
            <div class="card-header"><i class="fa fa-truck me-2 text-warning"></i>Delivery Status</div>
            <div class="card-body">
                <?php if ($sale['delivered_at']): ?>
                <div class="d-flex align-items-center gap-3 mb-3">
                    <div style="width:40px;height:40px;border-radius:10px;background:#dcfce7;color:#16a34a;display:flex;align-items:center;justify-content:center">
                        <i class="fa fa-circle-check fa-lg"></i>
                    </div>
                    <div>
                        <div class="fw-semibold text-success">Delivered</div>
                        <div class="text-muted small"><?= fmtDate($sale['delivered_at'], 'd M Y H:i') ?></div>
                    </div>
                </div>
                <?php if ($sale['delivery_notes']): ?>
                <div class="small text-muted"><?= nl2br(e($sale['delivery_notes'])) ?></div>
                <?php endif; ?>

                <?php elseif ($sale['status'] === 'active'): ?>
                <div class="alert alert-warning py-2 small mb-3">
                    <i class="fa fa-truck me-1"></i>Vehicle sold but not yet delivered to buyer.
                </div>
                <?php if (canWrite('sales')): ?>
                <form method="POST">
                    <input type="hidden" name="action" value="confirm_delivery">
                    <div class="mb-3">
                        <label class="form-label small">Delivery Notes (optional)</label>
                        <textarea name="delivery_notes" class="form-control form-control-sm" rows="2"
                                  placeholder="e.g. Delivered at our yard, buyer satisfied…"></textarea>
                    </div>
                    <button type="submit" class="btn btn-success w-100"
                            onclick="return confirm('Confirm delivery to <?= e(addslashes($sale['buyer_name'])) ?>?')">
                        <i class="fa fa-truck me-2"></i>Confirm Delivery
                    </button>
                </form>
                <?php endif; ?>
                <?php else: ?>
                <div class="text-muted small">Delivery not applicable — sale cancelled.</div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-lg-7">
        <!-- Buyer Info -->
        <div class="card mb-3">
            <div class="card-header"><i class="fa fa-user me-2"></i>Buyer Information</div>
            <div class="card-body">
                <dl class="row mb-0" style="font-size:13.5px">
                    <dt class="col-4 text-muted">Name</dt><dd class="col-8 fw-semibold"><?= e($sale['buyer_name']) ?></dd>
                    <dt class="col-4 text-muted">Phone</dt><dd class="col-8"><?= $sale['buyer_phone'] ? e($sale['buyer_phone']) : '—' ?></dd>
                    <dt class="col-4 text-muted">Email</dt><dd class="col-8"><?= $sale['buyer_email'] ? e($sale['buyer_email']) : '—' ?></dd>
                    <dt class="col-4 text-muted">ID / KRA PIN</dt><dd class="col-8"><?= $sale['buyer_id_number'] ? e($sale['buyer_id_number']) : '—' ?></dd>
                </dl>
            </div>
        </div>

        <!-- Vehicle Info -->
        <div class="card mb-3">
            <div class="card-header"><i class="fa fa-car me-2"></i>Vehicle Sold</div>
            <div class="card-body">
                <dl class="row mb-0" style="font-size:13.5px">
                    <dt class="col-4 text-muted">Vehicle</dt><dd class="col-8 fw-semibold"><?= e($sale['make'].' '.$sale['model'].' '.$sale['year']) ?></dd>
                    <dt class="col-4 text-muted">Reg. No.</dt><dd class="col-8"><?= $sale['registration_number'] ? '<span class="badge bg-dark">'.e($sale['registration_number']).'</span>' : '—' ?></dd>
                    <dt class="col-4 text-muted">Chassis</dt><dd class="col-8"><code style="font-size:11px"><?= e($sale['chassis_number']) ?></code></dd>
                    <dt class="col-4 text-muted">Color</dt><dd class="col-8"><?= e($sale['color'] ?? '—') ?></dd>
                    <dt class="col-4 text-muted">Fuel</dt><dd class="col-8"><?= ucfirst($sale['fuel_type'] ?? '—') ?></dd>
                    <dt class="col-4 text-muted">Transmission</dt><dd class="col-8"><?= ucfirst($sale['transmission'] ?? '—') ?></dd>
                    <dt class="col-4 text-muted">Car Status</dt><dd class="col-8"><?= statusBadge($sale['car_status']) ?></dd>
                </dl>
                <div class="mt-2">
                    <a href="<?= BASE_URL ?>/modules/cars/view.php?id=<?= $sale['car_id'] ?>" class="btn btn-sm btn-outline-primary">
                        <i class="fa fa-car me-1"></i>View Full Car History
                    </a>
                </div>
            </div>
        </div>

        <?php if (canWrite('sales') && $sale['status'] === 'active' && !$sale['delivered_at']): ?>
        <div class="card border-danger">
            <div class="card-header text-danger"><i class="fa fa-triangle-exclamation me-2"></i>Danger Zone</div>
            <div class="card-body">
                <p class="small text-muted mb-2">Cancelling this sale will return the car to 'completed' status.</p>
                <form method="POST" onsubmit="return confirm('Cancel this sale? This cannot be undone.')">
                    <input type="hidden" name="action" value="cancel_sale">
                    <button type="submit" class="btn btn-sm btn-outline-danger"><i class="fa fa-ban me-1"></i>Cancel Sale</button>
                </form>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>

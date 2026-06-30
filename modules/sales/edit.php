<?php
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
canAccess('sales') || die('Access denied.');
canWrite('sales') || die('Permission denied.');
$id = (int)($_GET['id'] ?? 0);
if (!$id) redirect(BASE_URL . '/modules/sales/index.php');
$db = getDB();

$stmt = $db->prepare("SELECT cs.*, c.make, c.model, c.year FROM car_sales cs JOIN cars c ON c.id=cs.car_id WHERE cs.id=?");
$stmt->execute([$id]); $sale = $stmt->fetch();
if (!$sale || $sale['status'] === 'cancelled') {
    setFlash('error', 'Sale not found or cancelled.'); redirect(BASE_URL . '/modules/sales/index.php');
}

$errors = [];
$d = $sale;

try { $db->exec("ALTER TABLE car_sales ADD COLUMN cost_price DECIMAL(15,2) NULL DEFAULT NULL"); } catch (\Throwable $_) {}
$canSeeProfit = hasRole(['admin','super_admin','general_manager','sales_manager','finance_manager','finance_officer']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $d['sale_date']       = $_POST['sale_date'] ?? $sale['sale_date'];
    $d['sale_price']      = $_POST['sale_price'] ?? $sale['sale_price'];
    $d['buyer_name']      = trim($_POST['buyer_name'] ?? '');
    $d['buyer_phone']     = trim($_POST['buyer_phone'] ?? '');
    $d['buyer_email']     = trim($_POST['buyer_email'] ?? '');
    $d['buyer_id_number'] = trim($_POST['buyer_id_number'] ?? '');
    $d['payment_method']  = $_POST['payment_method'] ?? 'cash';
    $d['payment_status']  = $_POST['payment_status'] ?? 'paid_full';
    $d['deposit_amount']  = $_POST['deposit_amount'] ?: '0';
    $d['balance_amount']  = $_POST['balance_amount'] ?: '0';
    $d['finance_company'] = trim($_POST['finance_company'] ?? '');
    $d['cost_price']      = $_POST['cost_price'] ?? null;
    $d['notes']           = trim($_POST['notes'] ?? '');

    if (!$d['buyer_name'])  $errors[] = 'Buyer name is required.';
    if (!$d['sale_price'] || (float)$d['sale_price'] <= 0) $errors[] = 'A valid sale price is required.';
    if ($d['buyer_email'] && !filter_var($d['buyer_email'], FILTER_VALIDATE_EMAIL)) $errors[] = 'Invalid buyer email.';

    if (empty($errors)) {
        try {
            $costPrice = ($d['cost_price'] !== '' && $d['cost_price'] !== null) ? (float)$d['cost_price'] : null;
            $db->prepare("UPDATE car_sales SET
                sale_date=?, sale_price=?, cost_price=?, buyer_name=?, buyer_phone=?, buyer_email=?,
                buyer_id_number=?, payment_method=?, payment_status=?, deposit_amount=?,
                balance_amount=?, finance_company=?, notes=?
                WHERE id=?")
               ->execute([
                   $d['sale_date'], (float)$d['sale_price'], $costPrice,
                   $d['buyer_name'], $d['buyer_phone'], $d['buyer_email'], $d['buyer_id_number'],
                   $d['payment_method'], $d['payment_status'],
                   (float)$d['deposit_amount'], (float)$d['balance_amount'],
                   $d['finance_company'], $d['notes'], $id,
               ]);
            logActivity('update', 'sales', $id, "Updated sale {$sale['sale_number']} — {$d['buyer_name']}");
            setFlash('success', 'Sale updated successfully.');
            redirect(BASE_URL . '/modules/sales/view.php?id=' . $id);
        } catch (\Throwable $e) {
            $errors[] = 'Save failed: ' . $e->getMessage();
        }
    }
}

$pageTitle = 'Edit Sale — ' . $sale['sale_number'];
include __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="mb-0">Edit Sale: <?= e($sale['sale_number']) ?></h5>
    <a href="view.php?id=<?= $id ?>" class="btn btn-sm btn-outline-secondary"><i class="fa fa-arrow-left me-1"></i>Back</a>
</div>

<?php if ($errors): ?>
<div class="alert alert-danger"><?php foreach ($errors as $e) echo '<div>'.e($e).'</div>'; ?></div>
<?php endif; ?>

<div class="alert alert-info py-2 small"><i class="fa fa-info-circle me-1"></i>
    Vehicle: <strong><?= e($sale['make'].' '.$sale['model'].' '.$sale['year']) ?></strong> — cannot be changed after recording the sale.
</div>

<form method="POST">
<div class="row g-4">
    <div class="col-lg-8">
        <div class="card mb-4">
            <div class="card-header"><i class="fa fa-tag me-2"></i>Sale Details</div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label">Sale Date</label>
                        <input type="date" name="sale_date" class="form-control" value="<?= e($d['sale_date']) ?>" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Sale Price (KES)</label>
                        <input type="number" name="sale_price" id="salePrice" class="form-control" min="0" step="0.01"
                               value="<?= e($d['sale_price']) ?>" required>
                    </div>
                    <?php if ($canSeeProfit): ?>
                    <div class="col-md-4">
                        <label class="form-label">Cost Price (KES) <small class="text-muted">(optional)</small></label>
                        <input type="number" name="cost_price" class="form-control" min="0" step="0.01"
                               value="<?= e($d['cost_price'] ?? '') ?>" placeholder="What we paid for the car">
                    </div>
                    <?php endif; ?>
                    <div class="col-12">
                        <label class="form-label">Notes</label>
                        <textarea name="notes" class="form-control" rows="2"><?= e($d['notes'] ?? '') ?></textarea>
                    </div>
                </div>
            </div>
        </div>
        <div class="card">
            <div class="card-header"><i class="fa fa-user me-2"></i>Buyer Information</div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">Full Name <span class="text-danger">*</span></label>
                        <input type="text" name="buyer_name" class="form-control" value="<?= e($d['buyer_name']) ?>" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Phone</label>
                        <input type="text" name="buyer_phone" class="form-control" value="<?= e($d['buyer_phone'] ?? '') ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Email</label>
                        <input type="email" name="buyer_email" class="form-control" value="<?= e($d['buyer_email'] ?? '') ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">ID / KRA PIN</label>
                        <input type="text" name="buyer_id_number" class="form-control" value="<?= e($d['buyer_id_number'] ?? '') ?>">
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="card" style="border-top:3px solid #16a34a">
            <div class="card-header"><i class="fa fa-money-bill-wave me-2"></i>Payment</div>
            <div class="card-body">
                <div class="mb-3">
                    <label class="form-label">Method</label>
                    <select name="payment_method" class="form-select">
                        <?php foreach (['cash'=>'Cash','bank_transfer'=>'Bank Transfer','mpesa'=>'M-Pesa','cheque'=>'Cheque','financing'=>'Financing'] as $v=>$l): ?>
                        <option value="<?= $v ?>" <?= $d['payment_method']===$v?'selected':'' ?>><?= $l ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label">Status</label>
                    <select name="payment_status" id="paymentStatus" class="form-select" onchange="togglePayFields()">
                        <?php foreach (['paid_full'=>'Paid in Full','partial'=>'Partial Payment','financed'=>'Financed','pending'=>'Pending'] as $v=>$l): ?>
                        <option value="<?= $v ?>" <?= $d['payment_status']===$v?'selected':'' ?>><?= $l ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div id="partialFields">
                    <div class="mb-3">
                        <label class="form-label">Deposit (KES)</label>
                        <input type="number" name="deposit_amount" id="depositAmt" class="form-control" min="0" step="0.01"
                               value="<?= e($d['deposit_amount']) ?>" oninput="calcBalance()">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Balance (KES)</label>
                        <input type="number" name="balance_amount" id="balanceAmt" class="form-control" min="0" step="0.01"
                               value="<?= e($d['balance_amount']) ?>">
                    </div>
                </div>
                <div id="financeFields">
                    <div class="mb-3">
                        <label class="form-label">Finance Company</label>
                        <input type="text" name="finance_company" class="form-control" value="<?= e($d['finance_company'] ?? '') ?>">
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="d-flex justify-content-end gap-2 mt-2">
    <a href="view.php?id=<?= $id ?>" class="btn btn-outline-secondary">Cancel</a>
    <button type="submit" class="btn btn-primary px-4"><i class="fa fa-save me-1"></i>Save Changes</button>
</div>
</form>

<?php
$extraJs = <<<'JS'
<script>
function togglePayFields() {
    var v = document.getElementById('paymentStatus').value;
    document.getElementById('partialFields').style.display = (v==='partial') ? '' : 'none';
    document.getElementById('financeFields').style.display = (v==='financed') ? '' : 'none';
}
function calcBalance() {
    var price   = parseFloat(document.getElementById('salePrice').value) || 0;
    var deposit = parseFloat(document.getElementById('depositAmt').value) || 0;
    document.getElementById('balanceAmt').value = Math.max(0, price - deposit).toFixed(2);
}
togglePayFields();
</script>
JS;
include __DIR__ . '/../../includes/footer.php';
?>

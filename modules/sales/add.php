<?php
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
canAccess('sales') || die('Access denied.');
canWrite('sales') || die('Permission denied.');
$pageTitle = 'Record Sale';
$db   = getDB();
$user = authUser();

$preCarId = (int)($_GET['car_id'] ?? 0);
$errors   = [];

// Cars available for sale (completed + no existing active sale)
$cars = $db->query("
    SELECT c.id, c.make, c.model, c.year, c.chassis_number, c.registration_number, c.status
    FROM cars c
    WHERE c.status IN ('completed','arrived','in_workshop')
      AND c.car_type = 'inventory'
      AND NOT EXISTS (
          SELECT 1 FROM car_sales cs WHERE cs.car_id = c.id AND cs.status='active'
      )
    ORDER BY c.make, c.model
")->fetchAll();

$d = [
    'car_id'          => $preCarId,
    'sale_date'       => date('Y-m-d'),
    'sale_price'      => '',
    'buyer_name'      => '',
    'buyer_phone'     => '',
    'buyer_email'     => '',
    'buyer_id_number' => '',
    'payment_method'  => 'cash',
    'payment_status'  => 'paid_full',
    'deposit_amount'  => '',
    'balance_amount'  => '',
    'finance_company' => '',
    'notes'           => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $d['car_id']          = (int)($_POST['car_id'] ?? 0);
    $d['sale_date']       = $_POST['sale_date'] ?? date('Y-m-d');
    $d['sale_price']      = $_POST['sale_price'] ?? '';
    $d['buyer_name']      = trim($_POST['buyer_name'] ?? '');
    $d['buyer_phone']     = trim($_POST['buyer_phone'] ?? '');
    $d['buyer_email']     = trim($_POST['buyer_email'] ?? '');
    $d['buyer_id_number'] = trim($_POST['buyer_id_number'] ?? '');
    $d['payment_method']  = $_POST['payment_method'] ?? 'cash';
    $d['payment_status']  = $_POST['payment_status'] ?? 'paid_full';
    $d['deposit_amount']  = $_POST['deposit_amount'] ?: '0';
    $d['balance_amount']  = $_POST['balance_amount'] ?: '0';
    $d['finance_company'] = trim($_POST['finance_company'] ?? '');
    $d['notes']           = trim($_POST['notes'] ?? '');

    if (!$d['car_id'])        $errors[] = 'Please select a vehicle.';
    if (!$d['buyer_name'])    $errors[] = 'Buyer name is required.';
    if (!$d['sale_price'] || (float)$d['sale_price'] <= 0) $errors[] = 'A valid sale price is required.';
    if ($d['buyer_email'] && !filter_var($d['buyer_email'], FILTER_VALIDATE_EMAIL)) $errors[] = 'Invalid buyer email.';

    if (empty($errors)) {
        try {
            $db->beginTransaction();
            $saleNum = nextNumber('car_sales', 'sale_number', getSetting('sale_prefix', 'SALE'));
            $db->prepare("INSERT INTO car_sales
                (sale_number, car_id, sale_date, sale_price, buyer_name, buyer_phone, buyer_email,
                 buyer_id_number, payment_method, payment_status, deposit_amount, balance_amount,
                 finance_company, sold_by, notes)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
               ->execute([
                   $saleNum, $d['car_id'], $d['sale_date'], (float)$d['sale_price'],
                   $d['buyer_name'], $d['buyer_phone'], $d['buyer_email'], $d['buyer_id_number'],
                   $d['payment_method'], $d['payment_status'],
                   (float)$d['deposit_amount'], (float)$d['balance_amount'],
                   $d['finance_company'], $user['id'], $d['notes'],
               ]);
            $saleId = (int)$db->lastInsertId();
            $db->prepare("UPDATE cars SET status='sold' WHERE id=?")->execute([$d['car_id']]);
            $db->commit();
            logActivity('create', 'sales', $saleId, "Recorded sale {$saleNum} — {$d['buyer_name']} — " . money((float)$d['sale_price']));
            require_once __DIR__ . '/../../includes/notifications.php';
            $soldCar = $db->prepare("SELECT make, model, year FROM cars WHERE id=?");
            $soldCar->execute([$d['car_id']]); $soldCar = $soldCar->fetch();
            notifyRoles(['admin','general_manager','finance_manager','finance_officer'], 'sale',
                "Vehicle Sold: {$saleNum}",
                ($soldCar ? "{$soldCar['make']} {$soldCar['model']} {$soldCar['year']}" : '') . " — " . money((float)$d['sale_price']) . " — " . $d['buyer_name'],
                BASE_URL . '/modules/sales/view.php?id=' . $saleId
            );
            setFlash('success', "Sale {$saleNum} recorded successfully.");
            redirect(BASE_URL . '/modules/sales/view.php?id=' . $saleId);
        } catch (\Throwable $e) {
            $db->rollBack();
            $errors[] = 'Save failed: ' . $e->getMessage();
        }
    }
}

include __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="mb-0"><i class="fa fa-tag me-2 text-success"></i>Record Sale</h5>
    <a href="index.php" class="btn btn-sm btn-outline-secondary"><i class="fa fa-arrow-left me-1"></i>Back</a>
</div>

<?php if ($errors): ?>
<div class="alert alert-danger"><?php foreach ($errors as $e) echo '<div><i class="fa fa-circle-exclamation me-1"></i>'.e($e).'</div>'; ?></div>
<?php endif; ?>

<form method="POST" id="saleForm">
<div class="row g-4">
    <!-- Left column -->
    <div class="col-lg-8">
        <!-- Vehicle + Sale -->
        <div class="card mb-4">
            <div class="card-header"><i class="fa fa-car me-2"></i>Vehicle &amp; Sale Details</div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-12">
                        <label class="form-label">Vehicle <span class="text-danger">*</span></label>
                        <select name="car_id" class="form-select select2" required>
                            <option value="">Select vehicle…</option>
                            <?php foreach ($cars as $c): ?>
                            <option value="<?= $c['id'] ?>" <?= $d['car_id'] == $c['id'] ? 'selected' : '' ?>
                                data-price="">
                                <?= e($c['make'].' '.$c['model'].' '.$c['year']) ?>
                                <?= $c['registration_number'] ? ' — '.e($c['registration_number']) : '' ?>
                                — <code><?= e($c['chassis_number']) ?></code>
                                (<?= ucwords(str_replace('_',' ',$c['status'])) ?>)
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <?php if (empty($cars)): ?>
                        <div class="form-text text-warning"><i class="fa fa-triangle-exclamation me-1"></i>No inventory vehicles are ready for sale. Vehicles must be in 'completed', 'arrived', or 'in_workshop' status.</div>
                        <?php endif; ?>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Sale Date <span class="text-danger">*</span></label>
                        <input type="date" name="sale_date" class="form-control" value="<?= e($d['sale_date']) ?>" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Sale Price (KES) <span class="text-danger">*</span></label>
                        <input type="number" name="sale_price" id="salePrice" class="form-control" min="0" step="0.01"
                               value="<?= e($d['sale_price']) ?>" required placeholder="0.00">
                    </div>
                    <div class="col-12">
                        <label class="form-label">Notes</label>
                        <textarea name="notes" class="form-control" rows="2" placeholder="Any special conditions, trade-in details, etc."><?= e($d['notes']) ?></textarea>
                    </div>
                </div>
            </div>
        </div>

        <!-- Buyer Info -->
        <div class="card mb-4">
            <div class="card-header"><i class="fa fa-user me-2"></i>Buyer Information</div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">Buyer Full Name <span class="text-danger">*</span></label>
                        <input type="text" name="buyer_name" class="form-control" value="<?= e($d['buyer_name']) ?>" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Phone</label>
                        <input type="text" name="buyer_phone" class="form-control" value="<?= e($d['buyer_phone']) ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Email</label>
                        <input type="email" name="buyer_email" class="form-control" value="<?= e($d['buyer_email']) ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">ID / KRA PIN</label>
                        <input type="text" name="buyer_id_number" class="form-control" value="<?= e($d['buyer_id_number']) ?>" placeholder="National ID or KRA PIN">
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Right column: Payment -->
    <div class="col-lg-4">
        <div class="card" style="border-top:3px solid #16a34a">
            <div class="card-header"><i class="fa fa-money-bill-wave me-2"></i>Payment Details</div>
            <div class="card-body">
                <div class="mb-3">
                    <label class="form-label">Payment Method</label>
                    <select name="payment_method" class="form-select">
                        <?php foreach (['cash' => 'Cash', 'bank_transfer' => 'Bank Transfer', 'mpesa' => 'M-Pesa', 'cheque' => 'Cheque', 'financing' => 'Financing'] as $v => $l): ?>
                        <option value="<?= $v ?>" <?= $d['payment_method']===$v?'selected':'' ?>><?= $l ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label">Payment Status</label>
                    <select name="payment_status" id="paymentStatus" class="form-select" onchange="togglePayFields()">
                        <?php foreach (['paid_full' => 'Paid in Full', 'partial' => 'Partial Payment', 'financed' => 'Financed', 'pending' => 'Pending'] as $v => $l): ?>
                        <option value="<?= $v ?>" <?= $d['payment_status']===$v?'selected':'' ?>><?= $l ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div id="partialFields" style="display:none">
                    <div class="mb-3">
                        <label class="form-label">Deposit Received (KES)</label>
                        <input type="number" name="deposit_amount" id="depositAmt" class="form-control" min="0" step="0.01"
                               value="<?= e($d['deposit_amount']) ?>" placeholder="0.00" oninput="calcBalance()">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Balance Remaining (KES)</label>
                        <input type="number" name="balance_amount" id="balanceAmt" class="form-control" min="0" step="0.01"
                               value="<?= e($d['balance_amount']) ?>" placeholder="Auto-calculated">
                    </div>
                </div>
                <div id="financeFields" style="display:none">
                    <div class="mb-3">
                        <label class="form-label">Finance Company</label>
                        <input type="text" name="finance_company" class="form-control" value="<?= e($d['finance_company']) ?>"
                               placeholder="e.g. NCBA Asset Finance">
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="d-flex justify-content-end gap-2 mt-2">
    <a href="index.php" class="btn btn-outline-secondary">Cancel</a>
    <button type="submit" class="btn btn-success px-4"><i class="fa fa-tag me-1"></i>Record Sale</button>
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

<?php
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
canWrite('installments') || redirect(BASE_URL . '/index.php');

$pageTitle = 'Create Payment Plan';
$db      = getDB();
$errors  = [];

$preSaleId = (int)($_GET['sale_id'] ?? 0);

// Sales without a plan
$sales = $db->query("
    SELECT cs.id, cs.sale_number, cs.buyer_name, cs.sale_price, cs.deposit_amount, cs.balance_amount,
           c.make, c.model, c.year
    FROM car_sales cs
    JOIN cars c ON c.id = cs.car_id
    WHERE cs.status = 'active'
      AND NOT EXISTS (SELECT 1 FROM sale_payment_plans p WHERE p.sale_id = cs.id)
    ORDER BY cs.sale_date DESC
")->fetchAll();

$d = [
    'sale_id'             => $preSaleId,
    'deposit_paid'        => '',
    'installment_amount'  => '',
    'total_installments'  => '',
    'frequency'           => 'monthly',
    'start_date'          => date('Y-m-d', strtotime('+1 month')),
    'notes'               => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $d['sale_id']            = (int)($_POST['sale_id'] ?? 0);
    $d['deposit_paid']       = (float)($_POST['deposit_paid']       ?? 0);
    $d['installment_amount'] = (float)($_POST['installment_amount'] ?? 0);
    $d['total_installments'] = (int)($_POST['total_installments']   ?? 0);
    $d['frequency']          = $_POST['frequency'] ?? 'monthly';
    $d['start_date']         = $_POST['start_date'] ?? date('Y-m-d');
    $d['notes']              = trim($_POST['notes'] ?? '');

    if (!$d['sale_id'])            $errors[] = 'Please select a sale.';
    if ($d['installment_amount'] <= 0) $errors[] = 'Instalment amount must be greater than zero.';
    if ($d['total_installments'] <= 0) $errors[] = 'Number of instalments must be at least 1.';
    if (!$d['start_date'])         $errors[] = 'Start date is required.';

    if (empty($errors)) {
        // Get sale details
        $saleRow = $db->prepare("SELECT sale_price, deposit_amount FROM car_sales WHERE id=?");
        $saleRow->execute([$d['sale_id']]); $saleRow = $saleRow->fetch();
        $totalAmount     = (float)$saleRow['sale_price'];
        $balanceFinanced = $totalAmount - $d['deposit_paid'];

        // Calculate end date
        $freqMap = ['weekly'=>'+1 week','bi_weekly'=>'+2 weeks','monthly'=>'+1 month','quarterly'=>'+3 months'];
        $freqAdd = $freqMap[$d['frequency']] ?? '+1 month';
        $endDate = date('Y-m-d', strtotime($d['start_date'] . ' ' . str_replace('+1 ','+'.($d['total_installments']).' ',$freqAdd)));
        // Simpler approach: calculate end date from last instalment
        $lastDue = $d['start_date'];
        for ($i = 1; $i < $d['total_installments']; $i++) $lastDue = date('Y-m-d', strtotime($lastDue . ' ' . $freqAdd));
        $endDate = $lastDue;

        try {
            $db->beginTransaction();

            $db->prepare("INSERT INTO sale_payment_plans
                (sale_id, total_amount, deposit_paid, balance_financed, installment_amount,
                 frequency, total_installments, start_date, end_date, notes, created_by)
                VALUES (?,?,?,?,?,?,?,?,?,?,?)")
               ->execute([
                   $d['sale_id'], $totalAmount, $d['deposit_paid'], $balanceFinanced,
                   $d['installment_amount'], $d['frequency'], $d['total_installments'],
                   $d['start_date'], $endDate, $d['notes'], authUser()['id']
               ]);
            $planId = (int)$db->lastInsertId();

            // Generate instalment schedule
            $ins  = $db->prepare("INSERT INTO sale_installments (plan_id, installment_number, due_date, amount_due) VALUES (?,?,?,?)");
            $due  = $d['start_date'];
            $remaining = $balanceFinanced;
            for ($n = 1; $n <= $d['total_installments']; $n++) {
                $amt = ($n === $d['total_installments']) ? round($remaining, 2) : $d['installment_amount'];
                $ins->execute([$planId, $n, $due, $amt]);
                $remaining -= $d['installment_amount'];
                $due = date('Y-m-d', strtotime($due . ' ' . $freqAdd));
            }

            $db->commit();
            logActivity('create','installments',$planId,"Payment plan created for sale #{$d['sale_id']}");
            setFlash('success','Payment plan created with '.$d['total_installments'].' instalments.');
            redirect(BASE_URL . '/modules/installments/view.php?id=' . $planId);
        } catch (\Throwable $e) {
            $db->rollBack();
            $errors[] = 'Failed: ' . $e->getMessage();
        }
    }
}

include __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="mb-0"><i class="fa fa-calendar-plus me-2 text-primary"></i>Create Payment Plan</h5>
    <a href="index.php" class="btn btn-sm btn-outline-secondary"><i class="fa fa-arrow-left me-1"></i>Back</a>
</div>

<?php if ($errors): ?>
<div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $e) echo '<li>'.e($e).'</li>'; ?></ul></div>
<?php endif; ?>

<div class="row g-4">
    <div class="col-lg-7">
        <div class="card">
            <div class="card-header fw-semibold"><i class="fa fa-file-invoice me-2"></i>Plan Details</div>
            <div class="card-body">
                <form method="POST" id="planForm">
                    <div class="row g-3">

                        <div class="col-12">
                            <label class="form-label fw-semibold">Sale <span class="text-danger">*</span></label>
                            <select name="sale_id" class="form-select select2" required id="saleSelect"
                                    onchange="prefillFromSale(this)">
                                <option value="">— Select a sale —</option>
                                <?php foreach ($sales as $s): ?>
                                <option value="<?= $s['id'] ?>"
                                    data-price="<?= $s['sale_price'] ?>"
                                    data-deposit="<?= $s['deposit_amount'] ?>"
                                    data-balance="<?= $s['balance_amount'] ?>"
                                    <?= $d['sale_id'] == $s['id'] ? 'selected' : '' ?>>
                                    <?= e($s['sale_number'] . ' — ' . $s['buyer_name'] . ' — ' . $s['make'].' '.$s['model'].' '.$s['year']) ?>
                                    (<?= money((float)$s['sale_price']) ?>)
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <?php if (empty($sales)): ?>
                            <div class="form-text text-warning">No eligible sales found. All active sales may already have a payment plan.</div>
                            <?php endif; ?>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Deposit Already Paid (KES)</label>
                            <input type="number" name="deposit_paid" id="depositPaid" class="form-control"
                                   min="0" step="0.01" value="<?= e($d['deposit_paid']) ?>"
                                   oninput="calcBalance()">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Balance to Finance (KES)</label>
                            <input type="text" id="balanceDisplay" class="form-control bg-light" readonly
                                   value="<?= money(0) ?>">
                        </div>

                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Frequency <span class="text-danger">*</span></label>
                            <select name="frequency" class="form-select" onchange="calcInstalment()">
                                <option value="weekly"     <?= $d['frequency']==='weekly'     ?'selected':'' ?>>Weekly</option>
                                <option value="bi_weekly"  <?= $d['frequency']==='bi_weekly'  ?'selected':'' ?>>Bi-weekly (every 2 weeks)</option>
                                <option value="monthly"    <?= $d['frequency']==='monthly'    ?'selected':'' ?>>Monthly</option>
                                <option value="quarterly"  <?= $d['frequency']==='quarterly'  ?'selected':'' ?>>Quarterly</option>
                            </select>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label fw-semibold">No. of Instalments <span class="text-danger">*</span></label>
                            <input type="number" name="total_installments" id="numInst" class="form-control"
                                   min="1" max="120" value="<?= e($d['total_installments']) ?>"
                                   oninput="calcInstalment()" required>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Instalment Amount (KES)</label>
                            <input type="number" name="installment_amount" id="instAmt" class="form-control"
                                   min="0" step="0.01" value="<?= e($d['installment_amount']) ?>"
                                   oninput="calcInstalmentsFromAmt()" required>
                            <div class="form-text">Edit to override; or set no. of instalments above.</div>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-semibold">First Instalment Date <span class="text-danger">*</span></label>
                            <input type="date" name="start_date" class="form-control"
                                   value="<?= e($d['start_date']) ?>" required>
                        </div>

                        <div class="col-12">
                            <label class="form-label fw-semibold">Notes</label>
                            <textarea name="notes" class="form-control" rows="2"
                                      placeholder="e.g. Client pays via M-Pesa on the 1st of each month…"><?= e($d['notes']) ?></textarea>
                        </div>

                        <div class="col-12">
                            <button type="submit" class="btn btn-primary">
                                <i class="fa fa-calendar-plus me-1"></i>Create Plan &amp; Generate Schedule
                            </button>
                            <a href="index.php" class="btn btn-outline-secondary ms-2">Cancel</a>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-5">
        <div class="card">
            <div class="card-header fw-semibold"><i class="fa fa-circle-info me-2 text-primary"></i>How It Works</div>
            <div class="card-body text-muted small">
                <ul class="mb-0 ps-3">
                    <li class="mb-2">Select the sale and enter how much deposit has already been paid.</li>
                    <li class="mb-2">The <strong>balance to finance</strong> is auto-calculated (Sale Price − Deposit).</li>
                    <li class="mb-2">Set the frequency and either the <strong>number of instalments</strong> (to auto-calculate the amount) or the <strong>instalment amount</strong> directly.</li>
                    <li class="mb-2">The system auto-generates the full instalment schedule with due dates.</li>
                    <li>You can then record individual payments on the plan view page.</li>
                </ul>
            </div>
        </div>
    </div>
</div>

<script>
var salePrice = 0;
function prefillFromSale(sel) {
    var opt = sel.options[sel.selectedIndex];
    salePrice = parseFloat(opt.dataset.price) || 0;
    var dep   = parseFloat(opt.dataset.deposit) || 0;
    document.getElementById('depositPaid').value = dep.toFixed(2);
    calcBalance();
}
function calcBalance() {
    var dep = parseFloat(document.getElementById('depositPaid').value) || 0;
    var bal = Math.max(0, salePrice - dep);
    document.getElementById('balanceDisplay').value = 'KES ' + bal.toLocaleString('en-KE', {minimumFractionDigits:2});
    calcInstalment();
}
function calcInstalment() {
    var dep  = parseFloat(document.getElementById('depositPaid').value) || 0;
    var bal  = Math.max(0, salePrice - dep);
    var n    = parseInt(document.getElementById('numInst').value) || 0;
    if (n > 0 && bal > 0) {
        document.getElementById('instAmt').value = (bal / n).toFixed(2);
    }
}
function calcInstalmentsFromAmt() {
    var dep  = parseFloat(document.getElementById('depositPaid').value) || 0;
    var bal  = Math.max(0, salePrice - dep);
    var amt  = parseFloat(document.getElementById('instAmt').value) || 0;
    if (amt > 0 && bal > 0) {
        document.getElementById('numInst').value = Math.ceil(bal / amt);
    }
}
// Pre-fill if sale already selected
(function(){
    var sel = document.getElementById('saleSelect');
    if (sel && sel.value) prefillFromSale(sel);
})();
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>

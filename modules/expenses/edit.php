<?php
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
canWrite('expenses') || redirect(BASE_URL . '/index.php');

$pageTitle = 'Edit Expense';
$db     = getDB();
$id     = (int)($_GET['id'] ?? 0);
$errors = [];
if (!$id) redirect(BASE_URL . '/modules/expenses/index.php');

$exp = $db->prepare("SELECT * FROM expenses WHERE id=?");
$exp->execute([$id]); $exp = $exp->fetch();
if (!$exp) { setFlash('error','Expense not found.'); redirect(BASE_URL.'/modules/expenses/index.php'); }

$categories = [
    'salaries'=>'Salaries & Wages','rent'=>'Rent & Premises','fuel'=>'Fuel & Transport',
    'utilities'=>'Utilities','marketing'=>'Marketing & Advertising',
    'maintenance'=>'Yard Maintenance','office'=>'Office & Stationery',
    'insurance'=>'Insurance','taxes'=>'Taxes & Levies','other'=>'Other',
];
$methods = ['cash'=>'Cash','mpesa'=>'M-Pesa','bank'=>'Bank Transfer','cheque'=>'Cheque'];

$d = $exp;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $d['category']       = $_POST['category']       ?? 'other';
    $d['description']    = trim($_POST['description']    ?? '');
    $d['amount']         = $_POST['amount']         ?? '';
    $d['expense_date']   = $_POST['expense_date']   ?? date('Y-m-d');
    $d['payment_method'] = $_POST['payment_method'] ?? 'cash';
    $d['reference']      = trim($_POST['reference']      ?? '');
    $d['vendor']         = trim($_POST['vendor']         ?? '');
    $d['notes']          = trim($_POST['notes']          ?? '');

    if (!$d['description'])                         $errors[] = 'Description is required.';
    if (!$d['amount'] || (float)$d['amount'] <= 0) $errors[] = 'Amount must be greater than zero.';

    $receiptFile = $exp['receipt_file'];
    if (!empty($_FILES['receipt']['name']) && $_FILES['receipt']['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES['receipt'];
        $ext  = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (in_array($ext,['pdf','jpg','jpeg','png','webp']) && $file['size'] <= 5*1024*1024) {
            $fname = date('Ymd_His').'_'.uniqid().'.'.$ext;
            if (move_uploaded_file($file['tmp_name'], BASE_PATH.'/uploads/receipts/'.$fname)) {
                if ($receiptFile) @unlink(BASE_PATH.'/uploads/receipts/'.$receiptFile);
                $receiptFile = $fname;
            }
        } else { $errors[] = 'Invalid receipt file.'; }
    }

    if (empty($errors)) {
        try {
            $db->prepare("UPDATE expenses SET
                category=?, description=?, amount=?, expense_date=?,
                payment_method=?, reference=?, vendor=?, receipt_file=?, notes=?, updated_at=NOW()
                WHERE id=?")
               ->execute([
                   $d['category'], $d['description'], (float)$d['amount'], $d['expense_date'],
                   $d['payment_method'], $d['reference']?:null, $d['vendor']?:null,
                   $receiptFile, $d['notes']?:null, $id
               ]);
            logActivity('update','expenses',$id,"Updated expense: {$d['description']}");
            setFlash('success','Expense updated.');
            redirect(BASE_URL . '/modules/expenses/index.php');
        } catch (\Throwable $e) { $errors[] = 'Save failed: ' . $e->getMessage(); }
    }
}

include __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="mb-0"><i class="fa fa-pen me-2 text-danger"></i>Edit Expense</h5>
    <a href="index.php" class="btn btn-sm btn-outline-secondary"><i class="fa fa-arrow-left me-1"></i>Back</a>
</div>
<?php if ($errors): ?><div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $e) echo '<li>'.e($e).'</li>'; ?></ul></div><?php endif; ?>
<div class="card">
    <div class="card-body">
        <form method="POST" enctype="multipart/form-data">
            <div class="row g-3">
                <div class="col-md-5">
                    <label class="form-label fw-semibold">Category</label>
                    <select name="category" class="form-select">
                        <?php foreach ($categories as $k => $lbl): ?>
                        <option value="<?= $k ?>" <?= $d['category']===$k?'selected':'' ?>><?= $lbl ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold">Amount (KES)</label>
                    <input type="number" name="amount" class="form-control" min="0.01" step="0.01" value="<?= e($d['amount']) ?>" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Date</label>
                    <input type="date" name="expense_date" class="form-control" value="<?= e($d['expense_date']) ?>" required>
                </div>
                <div class="col-12">
                    <label class="form-label fw-semibold">Description</label>
                    <input type="text" name="description" class="form-control" value="<?= e($d['description']) ?>" required>
                </div>
                <div class="col-md-5">
                    <label class="form-label fw-semibold">Vendor / Paid To</label>
                    <input type="text" name="vendor" class="form-control" value="<?= e($d['vendor'] ?? '') ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold">Payment Method</label>
                    <select name="payment_method" class="form-select">
                        <?php foreach ($methods as $k => $lbl): ?>
                        <option value="<?= $k ?>" <?= $d['payment_method']===$k?'selected':'' ?>><?= $lbl ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Reference #</label>
                    <input type="text" name="reference" class="form-control" value="<?= e($d['reference'] ?? '') ?>">
                </div>
                <div class="col-md-8">
                    <label class="form-label fw-semibold">Replace Receipt <span class="text-muted">(leave blank to keep)</span></label>
                    <input type="file" name="receipt" class="form-control" accept=".pdf,.jpg,.jpeg,.png,.webp">
                    <?php if ($d['receipt_file']): ?>
                    <div class="form-text">
                        Current: <a href="<?= BASE_URL ?>/uploads/receipts/<?= e(basename($d['receipt_file'])) ?>" target="_blank">View receipt</a>
                    </div>
                    <?php endif; ?>
                </div>
                <div class="col-12">
                    <label class="form-label fw-semibold">Notes</label>
                    <textarea name="notes" class="form-control" rows="2"><?= e($d['notes'] ?? '') ?></textarea>
                </div>
                <div class="col-12">
                    <button type="submit" class="btn btn-danger"><i class="fa fa-save me-1"></i>Save Changes</button>
                    <a href="index.php" class="btn btn-outline-secondary ms-2">Cancel</a>
                </div>
            </div>
        </form>
    </div>
</div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>

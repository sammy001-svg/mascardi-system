<?php
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
canWrite('expenses') || redirect(BASE_URL . '/index.php');

$pageTitle = 'Record Expense';
$db     = getDB();
$errors = [];

$categories = [
    'salaries'    => 'Salaries & Wages',
    'rent'        => 'Rent & Premises',
    'fuel'        => 'Fuel & Transport',
    'utilities'   => 'Utilities (Electricity, Water, Internet)',
    'marketing'   => 'Marketing & Advertising',
    'maintenance' => 'Yard & Equipment Maintenance',
    'office'      => 'Office Supplies & Stationery',
    'insurance'   => 'Insurance',
    'taxes'       => 'Taxes & Government Levies',
    'other'       => 'Other',
];

$methods = ['cash' => 'Cash', 'mpesa' => 'M-Pesa', 'bank' => 'Bank Transfer', 'cheque' => 'Cheque'];

$d = [
    'category'       => 'other',
    'description'    => '',
    'amount'         => '',
    'expense_date'   => date('Y-m-d'),
    'payment_method' => 'cash',
    'reference'      => '',
    'vendor'         => '',
    'notes'          => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $d['category']       = $_POST['category']       ?? 'other';
    $d['description']    = trim($_POST['description']    ?? '');
    $d['amount']         = $_POST['amount']         ?? '';
    $d['expense_date']   = $_POST['expense_date']   ?? date('Y-m-d');
    $d['payment_method'] = $_POST['payment_method'] ?? 'cash';
    $d['reference']      = trim($_POST['reference']      ?? '');
    $d['vendor']         = trim($_POST['vendor']         ?? '');
    $d['notes']          = trim($_POST['notes']          ?? '');

    if (!$d['description'])                          $errors[] = 'Description is required.';
    if (!$d['amount'] || (float)$d['amount'] <= 0)  $errors[] = 'Amount must be greater than zero.';
    if (!$d['expense_date'])                         $errors[] = 'Date is required.';
    if (!array_key_exists($d['category'], $categories)) $errors[] = 'Invalid category.';

    // Handle receipt upload
    $receiptFile = null;
    if (!empty($_FILES['receipt']['name'])) {
        $file    = $_FILES['receipt'];
        $ext     = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $allowed = ['pdf','jpg','jpeg','png','webp'];
        if (!in_array($ext, $allowed))          $errors[] = 'Receipt must be PDF or image (JPG, PNG).';
        elseif ($file['size'] > 5*1024*1024)    $errors[] = 'Receipt file too large (max 5 MB).';
        elseif ($file['error'] !== UPLOAD_ERR_OK) $errors[] = 'Upload error. Please try again.';
        else {
            $fname = date('Ymd_His') . '_' . uniqid() . '.' . $ext;
            $dest  = BASE_PATH . '/uploads/receipts/' . $fname;
            if (!move_uploaded_file($file['tmp_name'], $dest)) {
                $errors[] = 'Could not save receipt file.';
            } else {
                $receiptFile = $fname;
            }
        }
    }

    if (empty($errors)) {
        try {
            $expNum = nextNumber('expenses', 'expense_number', 'EXP');
            $db->prepare("INSERT INTO expenses
                (expense_number, category, description, amount, expense_date,
                 payment_method, reference, vendor, receipt_file, notes, recorded_by)
                VALUES (?,?,?,?,?,?,?,?,?,?,?)")
               ->execute([
                   $expNum, $d['category'], $d['description'],
                   (float)$d['amount'], $d['expense_date'],
                   $d['payment_method'],
                   $d['reference'] ?: null,
                   $d['vendor']    ?: null,
                   $receiptFile,
                   $d['notes']     ?: null,
                   authUser()['id'],
               ]);
            logActivity('create','expenses',(int)$db->lastInsertId(),"Expense: {$d['description']} — ".money((float)$d['amount']));
            setFlash('success', "Expense recorded: {$expNum}");
            redirect(BASE_URL . '/modules/expenses/index.php');
        } catch (\Throwable $e) {
            $errors[] = 'Save failed: ' . $e->getMessage();
        }
    }
}

include __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="mb-0"><i class="fa fa-receipt me-2 text-danger"></i>Record Expense</h5>
    <a href="index.php" class="btn btn-sm btn-outline-secondary"><i class="fa fa-arrow-left me-1"></i>Back</a>
</div>

<?php if ($errors): ?>
<div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $e) echo '<li>'.e($e).'</li>'; ?></ul></div>
<?php endif; ?>

<div class="row g-4">
    <div class="col-lg-8">
        <div class="card">
            <div class="card-header fw-semibold"><i class="fa fa-pen me-2"></i>Expense Details</div>
            <div class="card-body">
                <form method="POST" enctype="multipart/form-data">
                    <div class="row g-3">

                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Category <span class="text-danger">*</span></label>
                            <select name="category" class="form-select" required>
                                <?php foreach ($categories as $k => $lbl): ?>
                                <option value="<?= $k ?>" <?= $d['category']===$k?'selected':'' ?>><?= $lbl ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-3">
                            <label class="form-label fw-semibold">Amount (KES) <span class="text-danger">*</span></label>
                            <input type="number" name="amount" class="form-control" min="0.01" step="0.01"
                                   value="<?= e($d['amount']) ?>" required placeholder="0.00">
                        </div>

                        <div class="col-md-3">
                            <label class="form-label fw-semibold">Date <span class="text-danger">*</span></label>
                            <input type="date" name="expense_date" class="form-control"
                                   value="<?= e($d['expense_date']) ?>" required>
                        </div>

                        <div class="col-12">
                            <label class="form-label fw-semibold">Description <span class="text-danger">*</span></label>
                            <input type="text" name="description" class="form-control"
                                   value="<?= e($d['description']) ?>" required
                                   placeholder="e.g. April salaries — workshop mechanics, Electricity bill — April">
                        </div>

                        <div class="col-md-6">
                            <label class="form-label fw-semibold">Vendor / Paid To</label>
                            <input type="text" name="vendor" class="form-control"
                                   value="<?= e($d['vendor']) ?>"
                                   placeholder="e.g. Kenya Power, Total Petrol Station">
                        </div>

                        <div class="col-md-3">
                            <label class="form-label fw-semibold">Payment Method</label>
                            <select name="payment_method" class="form-select">
                                <?php foreach ($methods as $k => $lbl): ?>
                                <option value="<?= $k ?>" <?= $d['payment_method']===$k?'selected':'' ?>><?= $lbl ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-3">
                            <label class="form-label fw-semibold">Reference / Receipt #</label>
                            <input type="text" name="reference" class="form-control"
                                   value="<?= e($d['reference']) ?>"
                                   placeholder="Receipt or ref no.">
                        </div>

                        <div class="col-md-8">
                            <label class="form-label fw-semibold">Upload Receipt <span class="text-muted">(optional)</span></label>
                            <input type="file" name="receipt" class="form-control"
                                   accept=".pdf,.jpg,.jpeg,.png,.webp">
                            <div class="form-text">PDF or image, max 5 MB</div>
                        </div>

                        <div class="col-12">
                            <label class="form-label fw-semibold">Notes</label>
                            <textarea name="notes" class="form-control" rows="2"
                                      placeholder="Any additional notes…"><?= e($d['notes']) ?></textarea>
                        </div>

                        <div class="col-12">
                            <button type="submit" class="btn btn-danger">
                                <i class="fa fa-save me-1"></i>Save Expense
                            </button>
                            <a href="index.php" class="btn btn-outline-secondary ms-2">Cancel</a>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="card">
            <div class="card-header fw-semibold"><i class="fa fa-circle-info me-2 text-primary"></i>Category Guide</div>
            <div class="card-body p-0">
                <table class="table table-sm mb-0" style="font-size:12.5px">
                    <tbody>
                    <?php foreach ($categories as $k => $lbl): ?>
                    <tr>
                        <td class="ps-3 text-muted"><?= $lbl ?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>

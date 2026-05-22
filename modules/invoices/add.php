<?php
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
canAccess('invoices') || die('Access denied.');
canWrite('invoices')  || die('Permission denied.');

$db = getDB();

// Pre-fill from job card
$preJobId = (int)($_GET['job_id'] ?? 0);
$prefillJob = null;
if ($preJobId) {
    $pj = $db->prepare("
        SELECT j.*, c.make, c.model, c.chassis_number, c.registration_number,
               c.owner_name, c.owner_phone, c.owner_email
        FROM workshop_jobs j JOIN cars c ON c.id=j.car_id
        WHERE j.id=?
    ");
    $pj->execute([$preJobId]); $prefillJob = $pj->fetch();
}

$cars    = $db->query("SELECT id, make, model, chassis_number, registration_number FROM cars ORDER BY make, model")->fetchAll();
$jobs    = $db->query("SELECT id, job_number FROM workshop_jobs WHERE status NOT IN ('cancelled') ORDER BY job_number DESC")->fetchAll();
$clients = $db->query("SELECT id, name, phone, email FROM clients WHERE status='active' ORDER BY name")->fetchAll();

$errors = [];
$d = [
    'car_id'         => $prefillJob['car_id'] ?? '',
    'job_id'         => $prefillJob ? $prefillJob['id'] : '',
    'client_id'      => '',
    'date'           => date('Y-m-d'),
    'due_date'       => date('Y-m-d', strtotime('+30 days')),
    'customer_name'  => $prefillJob['owner_name']  ?? '',
    'customer_phone' => $prefillJob['owner_phone'] ?? '',
    'customer_email' => $prefillJob['owner_email'] ?? '',
    'discount'       => 0,
    'tax_rate'       => getSetting('vat_rate', '16'),
    'notes'          => '',
];
$lineRows = [['item_type' => 'service', 'description' => '', 'quantity' => 1, 'unit_price' => 0]];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $d['car_id']        = (int)($_POST['car_id'] ?? 0);
    $d['job_id']        = $_POST['job_id'] ? (int)$_POST['job_id'] : null;
    $d['client_id']     = $_POST['client_id'] ? (int)$_POST['client_id'] : null;
    $d['date']          = $_POST['date'] ?? date('Y-m-d');
    $d['due_date']      = $_POST['due_date'] ?: null;
    $d['customer_name'] = trim($_POST['customer_name'] ?? '');
    $d['customer_phone']= trim($_POST['customer_phone'] ?? '');
    $d['customer_email']= trim($_POST['customer_email'] ?? '');
    $d['discount']      = max(0, (float)($_POST['discount'] ?? 0));
    $d['tax_rate']      = (float)($_POST['tax_rate'] ?? 16);
    $d['notes']         = trim($_POST['notes'] ?? '');

    $itemTypes  = $_POST['item_type']  ?? [];
    $itemDescs  = $_POST['item_desc']  ?? [];
    $itemQtys   = $_POST['item_qty']   ?? [];
    $itemPrices = $_POST['item_price'] ?? [];

    if (!$d['car_id'])         $errors[] = 'Please select a vehicle.';
    if (!$d['customer_name'])  $errors[] = 'Customer name is required.';
    if (empty(array_filter($itemDescs))) $errors[] = 'Add at least one line item.';

    if (empty($errors)) {
        $db->beginTransaction();
        try {
            $subtotal = 0;
            foreach ($itemQtys as $i => $qty) {
                $subtotal += (float)$qty * (float)($itemPrices[$i] ?? 0);
            }
            $discountAmt = min($d['discount'], $subtotal);
            $taxable     = $subtotal - $discountAmt;
            $taxAmt      = $taxable * ($d['tax_rate'] / 100);
            $total       = $taxable + $taxAmt;

            $invNum = nextNumber('invoices', 'invoice_number', getSetting('invoice_prefix', 'INV'));
            $db->prepare("
                INSERT INTO invoices
                    (invoice_number, car_id, job_id, client_id, date, due_date,
                     customer_name, customer_phone, customer_email,
                     subtotal, discount, tax_rate, tax_amount, total, notes)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
            ")->execute([
                $invNum, $d['car_id'], $d['job_id'], $d['client_id'],
                $d['date'], $d['due_date'],
                $d['customer_name'], $d['customer_phone'], $d['customer_email'],
                $subtotal, $discountAmt, $d['tax_rate'], $taxAmt, $total,
                $d['notes'],
            ]);
            $invId = (int)$db->lastInsertId();

            $iStmt = $db->prepare("
                INSERT INTO invoice_items (invoice_id, item_type, description, quantity, unit_price, total)
                VALUES (?,?,?,?,?,?)
            ");
            foreach ($itemDescs as $i => $desc) {
                if (!trim($desc)) continue;
                $qty   = (float)($itemQtys[$i]   ?? 1);
                $price = (float)($itemPrices[$i] ?? 0);
                $type  = in_array($itemTypes[$i] ?? '', ['part','labour','service']) ? $itemTypes[$i] : 'service';
                $iStmt->execute([$invId, $type, $desc, $qty, $price, $qty * $price]);
            }

            $db->commit();
            logActivity('create', 'invoices', $invId, "Created invoice {$invNum}");
            setFlash('success', "Invoice {$invNum} created.");
            redirect(BASE_URL . '/modules/invoices/view.php?id=' . $invId);
        } catch (\Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            $errors[] = 'Save failed: ' . $e->getMessage();
        }
    }

    // Rebuild line rows on error
    $lineRows = [];
    foreach ($itemDescs as $i => $_) {
        $lineRows[] = [
            'item_type'   => $itemTypes[$i]  ?? 'service',
            'description' => $itemDescs[$i]  ?? '',
            'quantity'    => $itemQtys[$i]   ?? 1,
            'unit_price'  => $itemPrices[$i] ?? 0,
        ];
    }
    if (empty($lineRows)) {
        $lineRows = [['item_type'=>'service','description'=>'','quantity'=>1,'unit_price'=>0]];
    }
}

$vatRate   = getSetting('vat_rate', '16');
$pageTitle = 'New Invoice';
$extraJs   = <<<'JS'
<script>
$(function () {
    // Client auto-fill
    $('#client_select').on('change', function () {
        var opt = $(this).find(':selected');
        var n = opt.data('name')  || '';
        var p = opt.data('phone') || '';
        var e = opt.data('email') || '';
        if (n) $('#customer_name').val(n);
        if (p) $('#customer_phone').val(p);
        if (e) $('#customer_email').val(e);
    });

    // KES discount recalc — runs after main.js recalcTotals (which uses overall_discount=0)
    function adjustDiscount() {
        var subtotal = parseFloat($('#subtotal_display').text()) || 0;
        var discount = parseFloat($('#manual_discount').val())   || 0;
        var taxRate  = parseFloat($('#tax_rate').val())          || 0;
        discount     = Math.min(discount, subtotal);
        var taxable  = subtotal - discount;
        var taxAmt   = taxable * (taxRate / 100);
        var total    = taxable + taxAmt;
        $('#tax_display').text(taxAmt.toFixed(2));
        $('#total_display').text(total.toFixed(2));
    }

    $(document).on('input change', '.item-qty, .item-price', adjustDiscount);
    $(document).on('input change', '#manual_discount, #tax_rate', adjustDiscount);
    adjustDiscount();
});
</script>
JS;
include __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h5 class="mb-1"><i class="fa fa-file-invoice-dollar me-2"></i>New Invoice</h5>
        <?php if ($prefillJob): ?>
        <div class="text-muted small">From Job Card: <strong><?= e($prefillJob['job_number']) ?></strong>
            &mdash; <?= e($prefillJob['make'].' '.$prefillJob['model']) ?>
            <code class="ms-1"><?= e($prefillJob['chassis_number']) ?></code>
        </div>
        <?php endif; ?>
    </div>
    <a href="index.php" class="btn btn-sm btn-outline-secondary"><i class="fa fa-arrow-left me-1"></i>Back</a>
</div>

<?php if ($errors): ?>
<div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $err) echo '<li>'.e($err).'</li>'; ?></ul></div>
<?php endif; ?>

<form method="POST">
<div class="row g-4">

    <!-- ── Left: Line Items ──────────────────────────────────── -->
    <div class="col-lg-8">
        <div class="card">
            <div class="card-header"><i class="fa fa-list me-2"></i>Line Items</div>
            <div class="card-body line-items-wrapper">
                <div class="table-responsive">
                    <table class="table table-sm mb-2">
                        <thead>
                            <tr>
                                <th style="width:115px">Type</th>
                                <th>Description</th>
                                <th style="width:78px">Qty</th>
                                <th style="width:105px">Unit Price</th>
                                <th style="width:95px">Total</th>
                                <th style="width:36px"></th>
                            </tr>
                        </thead>
                        <tbody class="line-items-body">
                            <?php foreach ($lineRows as $row): ?>
                            <tr class="line-item-row">
                                <td>
                                    <select name="item_type[]" class="form-select form-select-sm">
                                        <option value="service" <?= ($row['item_type']??'service')==='service'?'selected':'' ?>>Service</option>
                                        <option value="labour"  <?= ($row['item_type']??'')==='labour' ?'selected':'' ?>>Labour</option>
                                        <option value="part"    <?= ($row['item_type']??'')==='part'   ?'selected':'' ?>>Part</option>
                                    </select>
                                </td>
                                <td>
                                    <input type="text" name="item_desc[]"
                                           class="form-control form-control-sm"
                                           value="<?= e($row['description']??'') ?>"
                                           placeholder="Description…" required>
                                </td>
                                <td>
                                    <input type="number" name="item_qty[]"
                                           class="form-control form-control-sm item-qty"
                                           value="<?= e($row['quantity']??1) ?>"
                                           min="0.01" step="0.01" style="width:68px">
                                </td>
                                <td>
                                    <input type="number" name="item_price[]"
                                           class="form-control form-control-sm item-price"
                                           value="<?= e($row['unit_price']??0) ?>"
                                           min="0" step="0.01" style="width:95px">
                                </td>
                                <td>
                                    <?php $lt = (float)($row['quantity']??1) * (float)($row['unit_price']??0); ?>
                                    <strong class="item-total"><?= number_format($lt, 2) ?></strong>
                                </td>
                                <td>
                                    <button type="button" class="btn btn-xs btn-outline-danger remove-line-item">
                                        <i class="fa fa-times"></i>
                                    </button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <button type="button" class="btn btn-sm btn-outline-primary add-line-item">
                    <i class="fa fa-plus me-1"></i>Add Item
                </button>
            </div>
        </div>

        <div class="card mt-3">
            <div class="card-body">
                <label class="form-label">Notes / Payment Terms</label>
                <textarea name="notes" class="form-control" rows="2"
                          placeholder="Bank details, payment instructions…"><?= e($d['notes']??'') ?></textarea>
            </div>
        </div>
    </div>

    <!-- ── Right: Details ────────────────────────────────────── -->
    <div class="col-lg-4">

        <!-- Invoice Details -->
        <div class="card mb-3">
            <div class="card-header">Invoice Details</div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-12">
                        <label class="form-label">Vehicle <span class="text-danger">*</span></label>
                        <?php if ($prefillJob): ?>
                        <input type="hidden" name="car_id" value="<?= $prefillJob['car_id'] ?>">
                        <input type="text" class="form-control" disabled
                               value="<?= e($prefillJob['make'].' '.$prefillJob['model'].' — '.$prefillJob['chassis_number']) ?>">
                        <?php else: ?>
                        <select name="car_id" class="form-select select2" required>
                            <option value="">Select vehicle…</option>
                            <?php foreach ($cars as $car): ?>
                            <option value="<?= $car['id'] ?>" <?= $d['car_id']==$car['id']?'selected':'' ?>>
                                <?= e($car['make'].' '.$car['model'].' ('.$car['chassis_number'].')') ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <?php endif; ?>
                    </div>
                    <div class="col-12">
                        <label class="form-label">Linked Job Card</label>
                        <select name="job_id" class="form-select select2">
                            <option value="">None</option>
                            <?php foreach ($jobs as $j): ?>
                            <option value="<?= $j['id'] ?>" <?= $d['job_id']==$j['id']?'selected':'' ?>>
                                <?= e($j['job_number']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-6">
                        <label class="form-label">Invoice Date</label>
                        <input type="date" name="date" class="form-control" value="<?= e($d['date']??date('Y-m-d')) ?>">
                    </div>
                    <div class="col-6">
                        <label class="form-label">Due Date</label>
                        <input type="date" name="due_date" class="form-control" value="<?= e($d['due_date']??'') ?>">
                    </div>
                </div>
            </div>
        </div>

        <!-- Customer -->
        <div class="card mb-3">
            <div class="card-header">Customer</div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-12">
                        <label class="form-label">Link to Client</label>
                        <select name="client_id" class="form-select select2" id="client_select">
                            <option value="">Walk-in / Manual</option>
                            <?php foreach ($clients as $cl): ?>
                            <option value="<?= $cl['id'] ?>"
                                    data-name="<?= e($cl['name']) ?>"
                                    data-phone="<?= e($cl['phone'] ?? '') ?>"
                                    data-email="<?= e($cl['email'] ?? '') ?>"
                                    <?= $d['client_id']==$cl['id']?'selected':'' ?>>
                                <?= e($cl['name']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12">
                        <label class="form-label">Name <span class="text-danger">*</span></label>
                        <input type="text" name="customer_name" id="customer_name"
                               class="form-control" value="<?= e($d['customer_name']??'') ?>" required>
                    </div>
                    <div class="col-12">
                        <label class="form-label">Phone</label>
                        <input type="text" name="customer_phone" id="customer_phone"
                               class="form-control" value="<?= e($d['customer_phone']??'') ?>">
                    </div>
                    <div class="col-12">
                        <label class="form-label">Email</label>
                        <input type="email" name="customer_email" id="customer_email"
                               class="form-control" value="<?= e($d['customer_email']??'') ?>"
                               placeholder="for emailing invoice">
                    </div>
                </div>
            </div>
        </div>

        <!-- Totals -->
        <div class="card">
            <div class="card-header"><i class="fa fa-calculator me-2"></i>Totals</div>
            <div class="card-body">
                <table class="table table-sm mb-0">
                    <tr>
                        <td class="text-muted">Subtotal</td>
                        <td class="text-end fw-semibold">KES <span id="subtotal_display">0.00</span></td>
                    </tr>
                    <tr>
                        <td class="text-muted">Discount (KES)</td>
                        <td class="text-end">
                            <input type="number" id="manual_discount" name="discount"
                                   class="form-control form-control-sm text-end"
                                   style="max-width:110px;margin-left:auto"
                                   value="<?= e($d['discount']??0) ?>" min="0" step="0.01">
                        </td>
                    </tr>
                    <tr>
                        <td class="text-muted">VAT</td>
                        <td class="text-end">
                            <div class="input-group input-group-sm justify-content-end" style="max-width:110px;margin-left:auto">
                                <input type="number" id="tax_rate" name="tax_rate"
                                       class="form-control form-control-sm"
                                       value="<?= e($d['tax_rate']??$vatRate) ?>" min="0" max="100" step="0.01">
                                <span class="input-group-text">%</span>
                            </div>
                            <div class="mt-1 text-muted small">KES <span id="tax_display">0.00</span></div>
                        </td>
                    </tr>
                    <tr class="table-primary">
                        <td><strong>Total</strong></td>
                        <td class="text-end"><strong>KES <span id="total_display">0.00</span></strong></td>
                    </tr>
                </table>
                <!-- Keep overall_discount=0 so main.js recalcTotals works correctly -->
                <input type="hidden" id="overall_discount" value="0">
                <input type="hidden" id="hidden_subtotal" name="hidden_subtotal">
                <input type="hidden" id="hidden_discount" name="hidden_discount">
                <input type="hidden" id="hidden_tax"      name="hidden_tax">
                <input type="hidden" id="hidden_total"    name="hidden_total">
                <button type="submit" class="btn btn-primary w-100 mt-3">
                    <i class="fa fa-save me-1"></i>Create Invoice
                </button>
            </div>
        </div>

    </div>
</div>
</form>

<?php include __DIR__ . '/../../includes/footer.php'; ?>

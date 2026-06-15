<?php
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
canAccess('invoices') || die('Access denied.');
canWrite('invoices')  || die('Permission denied.');

$id = (int)($_GET['id'] ?? 0);
if (!$id) redirect(BASE_URL . '/modules/invoices/index.php');
$db = getDB();

$stmt = $db->prepare("SELECT i.*, c.make, c.model, c.chassis_number FROM invoices i JOIN cars c ON c.id=i.car_id WHERE i.id=?");
$stmt->execute([$id]); $inv = $stmt->fetch();
if (!$inv) { setFlash('error', 'Invoice not found.'); redirect(BASE_URL . '/modules/invoices/index.php'); }

if (!in_array($inv['status'], ['unpaid', 'partial'])) {
    setFlash('error', 'Only unpaid or partial invoices can be edited (current status: ' . $inv['status'] . ').');
    redirect(BASE_URL . '/modules/invoices/view.php?id=' . $id);
}

$existingItems = $db->prepare("SELECT * FROM invoice_items WHERE invoice_id=? ORDER BY id");
$existingItems->execute([$id]); $existingItems = $existingItems->fetchAll();

// Auto-add customer_kra_pin column if not present
try { $db->exec("ALTER TABLE invoices ADD COLUMN customer_kra_pin VARCHAR(20) NULL AFTER customer_email"); } catch (\Throwable $_) {}
try { $db->exec("ALTER TABLE clients ADD COLUMN kra_pin VARCHAR(20) NULL AFTER id_number"); } catch (\Throwable $_) {}

$jobs    = $db->query("SELECT id, job_number FROM workshop_jobs WHERE status NOT IN ('cancelled') ORDER BY job_number DESC")->fetchAll();
$clients = $db->query("SELECT id, name, phone, email, kra_pin FROM clients WHERE status='active' ORDER BY name")->fetchAll();

$errors = [];
$d = $inv;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $d['job_id']        = $_POST['job_id'] ? (int)$_POST['job_id'] : null;
    $d['client_id']     = $_POST['client_id'] ? (int)$_POST['client_id'] : null;
    $d['date']          = $_POST['date'] ?? date('Y-m-d');
    $d['due_date']      = $_POST['due_date'] ?: null;
    $d['customer_name']    = trim($_POST['customer_name'] ?? '');
    $d['customer_phone']   = trim($_POST['customer_phone'] ?? '');
    $d['customer_email']   = trim($_POST['customer_email'] ?? '');
    $d['customer_kra_pin'] = strtoupper(trim($_POST['customer_kra_pin'] ?? ''));
    $d['discount']         = max(0, (float)($_POST['discount'] ?? 0));
    $d['tax_rate']         = (float)($_POST['tax_rate'] ?? 16);
    $d['notes']            = trim($_POST['notes'] ?? '');

    $itemTypes  = $_POST['item_type']  ?? [];
    $itemDescs  = $_POST['item_desc']  ?? [];
    $itemQtys   = $_POST['item_qty']   ?? [];
    $itemPrices = $_POST['item_price'] ?? [];

    if (!$d['customer_name'])              $errors[] = 'Customer name is required.';
    if (empty(array_filter($itemDescs)))   $errors[] = 'Add at least one line item.';

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

            // Recalculate paid status
            $newStatus = $inv['amount_paid'] >= $total ? 'paid'
                       : ($inv['amount_paid'] > 0     ? 'partial' : 'unpaid');

            $db->prepare("
                UPDATE invoices SET
                    job_id=?, client_id=?, date=?, due_date=?,
                    customer_name=?, customer_phone=?, customer_email=?, customer_kra_pin=?,
                    subtotal=?, discount=?, tax_rate=?, tax_amount=?, total=?,
                    status=?, notes=?
                WHERE id=?
            ")->execute([
                $d['job_id'], $d['client_id'], $d['date'], $d['due_date'],
                $d['customer_name'], $d['customer_phone'], $d['customer_email'], $d['customer_kra_pin'],
                $subtotal, $discountAmt, $d['tax_rate'], $taxAmt, $total,
                $newStatus, $d['notes'],
                $id,
            ]);

            $db->prepare("DELETE FROM invoice_items WHERE invoice_id=?")->execute([$id]);

            $iStmt = $db->prepare("
                INSERT INTO invoice_items (invoice_id, item_type, description, quantity, unit_price, total)
                VALUES (?,?,?,?,?,?)
            ");
            foreach ($itemDescs as $i => $desc) {
                if (!trim($desc)) continue;
                $qty   = (float)($itemQtys[$i]   ?? 1);
                $price = (float)($itemPrices[$i] ?? 0);
                $type  = in_array($itemTypes[$i] ?? '', ['part','labour','service']) ? $itemTypes[$i] : 'service';
                $iStmt->execute([$id, $type, $desc, $qty, $price, $qty * $price]);
            }

            $db->commit();
            logActivity('update', 'invoices', $id, "Updated invoice {$inv['invoice_number']}");
            setFlash('success', "Invoice {$inv['invoice_number']} updated.");
            redirect(BASE_URL . '/modules/invoices/view.php?id=' . $id);
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
} else {
    $lineRows = $existingItems;
    if (empty($lineRows)) {
        $lineRows = [['item_type'=>'service','description'=>'','quantity'=>1,'unit_price'=>0]];
    }
}

$vatRate   = getSetting('vat_rate', '16');
$pageTitle = 'Edit Invoice — ' . $inv['invoice_number'];
$extraJs   = <<<'JS'
<script>
$(function () {
    // Client auto-fill
    $('#client_select').on('change', function () {
        var opt = $(this).find(':selected');
        var n = opt.data('name')   || '';
        var p = opt.data('phone')  || '';
        var e = opt.data('email')  || '';
        var k = opt.data('krapin') || '';
        if (n) $('#customer_name').val(n);
        if (p) $('#customer_phone').val(p);
        if (e) $('#customer_email').val(e);
        $('#customer_kra_pin').val(k ? k.toUpperCase() : '');
    });

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
        <h5 class="mb-1"><i class="fa fa-pen me-2"></i>Edit Invoice: <strong><?= e($inv['invoice_number']) ?></strong></h5>
        <div class="text-muted small"><?= e($inv['make'].' '.$inv['model']) ?>
            <code class="ms-1"><?= e($inv['chassis_number']) ?></code>
            &mdash; <?= statusBadge($inv['status']) ?>
        </div>
    </div>
    <a href="view.php?id=<?= $id ?>" class="btn btn-sm btn-outline-secondary"><i class="fa fa-arrow-left me-1"></i>Back</a>
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
                <textarea name="notes" class="form-control" rows="2"><?= e($d['notes']??'') ?></textarea>
            </div>
        </div>
    </div>

    <!-- ── Right: Details ────────────────────────────────────── -->
    <div class="col-lg-4">

        <!-- Invoice Details (car is read-only) -->
        <div class="card mb-3">
            <div class="card-header">Invoice Details</div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-12">
                        <label class="form-label">Vehicle</label>
                        <input type="text" class="form-control" disabled
                               value="<?= e($inv['make'].' '.$inv['model'].' — '.$inv['chassis_number']) ?>">
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
                                    data-krapin="<?= e($cl['kra_pin'] ?? '') ?>"
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
                               class="form-control" value="<?= e($d['customer_email']??'') ?>">
                    </div>
                    <div class="col-12">
                        <label class="form-label">KRA PIN</label>
                        <input type="text" name="customer_kra_pin" id="customer_kra_pin"
                               class="form-control text-uppercase"
                               value="<?= e($d['customer_kra_pin']??'') ?>"
                               placeholder="e.g. A001234567B" maxlength="20"
                               oninput="this.value=this.value.toUpperCase()">
                    </div>
                </div>
            </div>
        </div>

        <!-- Totals -->
        <div class="card">
            <div class="card-header"><i class="fa fa-calculator me-2"></i>Totals</div>
            <div class="card-body">
                <?php if ($inv['amount_paid'] > 0): ?>
                <div class="alert alert-info py-2 mb-3 small">
                    <i class="fa fa-info-circle me-1"></i>
                    <strong><?= money($inv['amount_paid']) ?></strong> already recorded. Changing the total will recalculate the balance.
                </div>
                <?php endif; ?>
                <table class="table table-sm mb-0">
                    <tr>
                        <td class="text-muted">Subtotal</td>
                        <td class="text-end fw-semibold">KES <span id="subtotal_display"><?= number_format($inv['subtotal'],2) ?></span></td>
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
                            <div class="mt-1 text-muted small">KES <span id="tax_display"><?= number_format($inv['tax_amount'],2) ?></span></div>
                        </td>
                    </tr>
                    <tr class="table-primary">
                        <td><strong>Total</strong></td>
                        <td class="text-end"><strong>KES <span id="total_display"><?= number_format($inv['total'],2) ?></span></strong></td>
                    </tr>
                    <?php if ($inv['amount_paid'] > 0): ?>
                    <tr>
                        <td class="text-success small">Amount Paid</td>
                        <td class="text-end text-success small"><?= money($inv['amount_paid']) ?></td>
                    </tr>
                    <?php endif; ?>
                </table>
                <input type="hidden" id="overall_discount" value="0">
                <input type="hidden" id="hidden_subtotal" name="hidden_subtotal">
                <input type="hidden" id="hidden_discount" name="hidden_discount">
                <input type="hidden" id="hidden_tax"      name="hidden_tax">
                <input type="hidden" id="hidden_total"    name="hidden_total">
                <button type="submit" class="btn btn-warning w-100 mt-3 text-dark">
                    <i class="fa fa-save me-1"></i>Save Changes
                </button>
            </div>
        </div>

    </div>
</div>
</form>

<?php include __DIR__ . '/../../includes/footer.php'; ?>

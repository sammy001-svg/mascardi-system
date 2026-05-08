<?php
require_once __DIR__ . '/../../includes/functions.php';
$id=(int)($_GET['id']??0); if(!$id) redirect(BASE_URL.'/modules/invoices/index.php');
$db=getDB();
$stmt=$db->prepare("SELECT i.*,c.chassis_number,c.make,c.model,c.year,c.color,c.registration_number FROM invoices i JOIN cars c ON c.id=i.car_id WHERE i.id=?");
$stmt->execute([$id]); $inv=$stmt->fetch();
if(!$inv){setFlash('error','Not found.');redirect(BASE_URL.'/modules/invoices/index.php');}
$items=$db->prepare("SELECT * FROM invoice_items WHERE invoice_id=?"); $items->execute([$id]); $items=$items->fetchAll();

// Record payment
if($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['payment_amount'])){
    $amount=(float)$_POST['payment_amount'];
    $newPaid=$inv['amount_paid']+$amount;
    $newStatus=$newPaid>=$inv['total']?'paid':($newPaid>0?'partial':'unpaid');
    $db->prepare("UPDATE invoices SET amount_paid=?, status=? WHERE id=?")->execute([$newPaid,$newStatus,$id]);
    setFlash('success','Payment of '.money($amount).' recorded.');
    redirect('view.php?id='.$id);
}
if(isset($_GET['status'])){
    $db->prepare("UPDATE invoices SET status=? WHERE id=?")->execute([$_GET['status'],$id]);
    redirect('view.php?id='.$id);
}

$pageTitle=$inv['invoice_number'];
include __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="mb-0">Invoice: <strong><?= e($inv['invoice_number']) ?></strong> <?= statusBadge($inv['status']) ?></h5>
    <div class="d-flex gap-2 flex-wrap">
        <a href="print.php?id=<?= $id ?>" class="btn btn-sm btn-outline-dark" target="_blank"><i class="fa fa-print me-1"></i>Print / PDF</a>
        <?php if($inv['status']!=='paid'): ?><a href="?id=<?= $id ?>&status=cancelled" class="btn btn-sm btn-outline-danger" onclick="return confirm('Cancel invoice?')">Cancel</a><?php endif; ?>
        <a href="index.php" class="btn btn-sm btn-outline-secondary"><i class="fa fa-arrow-left me-1"></i>Back</a>
    </div>
</div>

<div class="row g-4">
    <div class="col-lg-4">
        <div class="card mb-3"><div class="card-header">Invoice Info</div><div class="card-body">
            <dl class="row mb-0" style="font-size:13.5px">
                <dt class="col-5 text-muted">Invoice No.</dt><dd class="col-7 fw-bold"><?= e($inv['invoice_number']) ?></dd>
                <dt class="col-5 text-muted">Date</dt><dd class="col-7"><?= fmtDate($inv['date']) ?></dd>
                <dt class="col-5 text-muted">Due Date</dt><dd class="col-7"><?= fmtDate($inv['due_date']) ?></dd>
                <dt class="col-5 text-muted">Customer</dt><dd class="col-7"><?= e($inv['customer_name']??'—') ?></dd>
                <dt class="col-5 text-muted">Phone</dt><dd class="col-7"><?= e($inv['customer_phone']??'—') ?></dd>
                <dt class="col-5 text-muted">Vehicle</dt><dd class="col-7"><?= e($inv['make'].' '.$inv['model']) ?></dd>
                <dt class="col-5 text-muted">Chassis</dt><dd class="col-7"><code><?= e($inv['chassis_number']) ?></code></dd>
            </dl>
        </div></div>

        <!-- Totals -->
        <div class="card mb-3"><div class="card-header">Payment Summary</div><div class="card-body">
            <table class="table table-sm mb-0">
                <tr><td class="text-muted">Subtotal</td><td class="text-end"><?= money($inv['subtotal']) ?></td></tr>
                <?php if($inv['discount']>0): ?><tr><td class="text-muted">Discount</td><td class="text-end text-danger">-<?= money($inv['discount']) ?></td></tr><?php endif; ?>
                <tr><td class="text-muted">VAT (<?= $inv['tax_rate'] ?>%)</td><td class="text-end"><?= money($inv['tax_amount']) ?></td></tr>
                <tr class="table-primary"><td><strong>Total</strong></td><td class="text-end"><strong><?= money($inv['total']) ?></strong></td></tr>
                <tr><td class="text-success">Amount Paid</td><td class="text-end text-success"><?= money($inv['amount_paid']) ?></td></tr>
                <tr><td class="text-<?= ($inv['total']-$inv['amount_paid'])>0?'danger':'success' ?>"><strong>Balance</strong></td><td class="text-end text-<?= ($inv['total']-$inv['amount_paid'])>0?'danger':'success' ?>"><strong><?= money($inv['total']-$inv['amount_paid']) ?></strong></td></tr>
            </table>
        </div></div>

        <!-- Record payment -->
        <?php if($inv['status']!=='paid' && $inv['status']!=='cancelled'): ?>
        <div class="card"><div class="card-header"><i class="fa fa-money-bill-wave me-2"></i>Record Payment</div><div class="card-body">
            <form method="POST">
                <div class="mb-2"><label class="form-label small">Amount</label><input type="number" name="payment_amount" class="form-control" min="0.01" step="0.01" max="<?= $inv['total']-$inv['amount_paid'] ?>" value="<?= $inv['total']-$inv['amount_paid'] ?>" required></div>
                <button type="submit" class="btn btn-success w-100"><i class="fa fa-check me-1"></i>Record Payment</button>
            </form>
        </div></div>
        <?php endif; ?>
    </div>

    <div class="col-lg-8">
        <div class="card"><div class="card-header">Line Items</div>
            <div class="card-body p-0">
                <table class="table mb-0">
                    <thead><tr><th class="ps-3">#</th><th>Type</th><th>Description</th><th>Qty</th><th>Unit Price</th><th>Total</th></tr></thead>
                    <tbody>
                        <?php foreach($items as $i => $item): ?>
                        <tr>
                            <td class="ps-3"><?= $i+1 ?></td>
                            <td><span class="badge bg-light text-dark border"><?= ucfirst($item['item_type']) ?></span></td>
                            <td><?= e($item['description']) ?></td>
                            <td><?= $item['quantity'] ?></td>
                            <td><?= money($item['unit_price']) ?></td>
                            <td><strong><?= money($item['total']) ?></strong></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>

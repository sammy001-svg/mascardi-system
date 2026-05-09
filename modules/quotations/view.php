<?php
require_once __DIR__ . '/../../includes/functions.php';
$id=(int)($_GET['id']??0); if(!$id) redirect(BASE_URL.'/modules/quotations/index.php');
$db=getDB();
$stmt=$db->prepare("SELECT q.*,c.chassis_number,c.make,c.model,c.year,c.color,c.registration_number FROM quotations q JOIN cars c ON c.id=q.car_id WHERE q.id=?");
$stmt->execute([$id]); $q=$stmt->fetch();
if(!$q){setFlash('error','Not found.');redirect(BASE_URL.'/modules/quotations/index.php');}
$items=$db->prepare("SELECT * FROM quotation_items WHERE quotation_id=? ORDER BY id"); $items->execute([$id]); $items=$items->fetchAll();

// Handle status change
if(isset($_GET['status'])){
    $db->prepare("UPDATE quotations SET status=? WHERE id=?")->execute([$_GET['status'],$id]);
    setFlash('success','Status updated.'); redirect('view.php?id='.$id);
}
// Convert to invoice
if(isset($_GET['convert'])){
    try{
        $db->beginTransaction();
        $invNum=nextNumber('invoices','invoice_number',getSetting('invoice_prefix','INV'));
        $db->prepare("INSERT INTO invoices (invoice_number,quotation_id,car_id,job_id,date,due_date,customer_name,customer_phone,customer_email,subtotal,discount,tax_rate,tax_amount,total,notes) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
           ->execute([$invNum,$id,$q['car_id'],$q['job_id'],date('Y-m-d'),date('Y-m-d',strtotime('+30 days')),$q['customer_name'],$q['customer_phone'],$q['customer_email'],$q['subtotal'],$q['discount'],$q['tax_rate'],$q['tax_amount'],$q['total'],$q['notes']]);
        $invId=(int)$db->lastInsertId();
        $iStmt=$db->prepare("INSERT INTO invoice_items (invoice_id,item_type,description,quantity,unit_price,total) VALUES (?,?,?,?,?,?)");
        foreach($items as $item) $iStmt->execute([$invId,$item['item_type'],$item['description'],$item['quantity'],$item['unit_price'],$item['total']]);
        $db->prepare("UPDATE quotations SET status='converted' WHERE id=?")->execute([$id]);
        $db->commit();
        setFlash('success',"Invoice {$invNum} created.");
        redirect(BASE_URL.'/modules/invoices/view.php?id='.$invId);
    }catch(\Throwable $e){
        if($db->inTransaction()) $db->rollBack();
        setFlash('error','Conversion failed: '.$e->getMessage());
        redirect(BASE_URL.'/modules/quotations/view.php?id='.$id);
    }
}

$pageTitle=$q['quotation_number'];
include __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="mb-0">Quotation: <strong><?= e($q['quotation_number']) ?></strong> <?= statusBadge($q['status']) ?></h5>
    <div class="d-flex gap-2 flex-wrap">
        <?php if($q['status']!=='converted' && $q['status']!=='rejected'): ?>
        <a href="?id=<?= $id ?>&convert=1" class="btn btn-sm btn-success" onclick="return confirm('Convert to Invoice?')"><i class="fa fa-file-invoice-dollar me-1"></i>Convert to Invoice</a>
        <?php endif; ?>
        <a href="print.php?id=<?= $id ?>" class="btn btn-sm btn-outline-dark" target="_blank"><i class="fa fa-print me-1"></i>Print / PDF</a>
        <?php if($q['status']==='draft'): ?>
        <a href="?id=<?= $id ?>&status=sent" class="btn btn-sm btn-outline-info">Mark as Sent</a>
        <a href="?id=<?= $id ?>&status=approved" class="btn btn-sm btn-outline-success">Approve</a>
        <a href="?id=<?= $id ?>&status=rejected" class="btn btn-sm btn-outline-danger">Reject</a>
        <?php endif; ?>
        <a href="index.php" class="btn btn-sm btn-outline-secondary"><i class="fa fa-arrow-left me-1"></i>Back</a>
    </div>
</div>

<div class="row g-4">
    <div class="col-lg-4">
        <div class="card mb-3"><div class="card-header"><i class="fa fa-info-circle me-2"></i>Details</div><div class="card-body">
            <dl class="row mb-0" style="font-size:13.5px">
                <dt class="col-5 text-muted">Number</dt><dd class="col-7 fw-bold"><?= e($q['quotation_number']) ?></dd>
                <dt class="col-5 text-muted">Date</dt><dd class="col-7"><?= fmtDate($q['date']) ?></dd>
                <dt class="col-5 text-muted">Valid Until</dt><dd class="col-7"><?= fmtDate($q['valid_until']) ?></dd>
                <dt class="col-5 text-muted">Customer</dt><dd class="col-7"><?= e($q['customer_name']??'—') ?></dd>
                <dt class="col-5 text-muted">Phone</dt><dd class="col-7"><?= e($q['customer_phone']??'—') ?></dd>
                <dt class="col-5 text-muted">Vehicle</dt><dd class="col-7"><?= e($q['make'].' '.$q['model']) ?></dd>
                <dt class="col-5 text-muted">Chassis</dt><dd class="col-7"><code><?= e($q['chassis_number']) ?></code></dd>
            </dl>
        </div></div>
        <div class="card"><div class="card-header"><i class="fa fa-calculator me-2"></i>Totals</div><div class="card-body">
            <table class="table table-sm mb-0">
                <tr><td class="text-muted">Subtotal</td><td class="text-end"><?= money($q['subtotal']) ?></td></tr>
                <tr><td class="text-muted">Discount</td><td class="text-end text-danger">-<?= money($q['discount']) ?></td></tr>
                <tr><td class="text-muted">VAT (<?= $q['tax_rate'] ?>%)</td><td class="text-end"><?= money($q['tax_amount']) ?></td></tr>
                <tr class="table-primary"><td><strong>Total</strong></td><td class="text-end"><strong><?= money($q['total']) ?></strong></td></tr>
            </table>
        </div></div>
    </div>
    <div class="col-lg-8">
        <div class="card"><div class="card-header"><i class="fa fa-list me-2"></i>Line Items</div>
            <div class="card-body p-0">
                <table class="table mb-0">
                    <thead><tr><th class="ps-3">Type</th><th>Description</th><th>Qty</th><th>Unit Price</th><th>Disc%</th><th>Total</th></tr></thead>
                    <tbody>
                        <?php foreach($items as $item): ?>
                        <tr>
                            <td class="ps-3"><span class="badge bg-light text-dark border"><?= ucfirst($item['item_type']) ?></span></td>
                            <td><?= e($item['description']) ?></td>
                            <td><?= $item['quantity'] ?></td>
                            <td><?= money($item['unit_price']) ?></td>
                            <td><?= $item['discount'] ? $item['discount'].'%' : '—' ?></td>
                            <td><strong><?= money($item['total']) ?></strong></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php if($q['notes']||$q['terms']): ?>
        <div class="row g-3 mt-1">
            <?php if($q['notes']): ?><div class="col-md-6"><div class="card"><div class="card-header small fw-bold">Notes</div><div class="card-body small"><?= e($q['notes']) ?></div></div></div><?php endif; ?>
            <?php if($q['terms']): ?><div class="col-md-6"><div class="card"><div class="card-header small fw-bold">Terms & Conditions</div><div class="card-body small"><?= e($q['terms']) ?></div></div></div><?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>

<?php
require_once __DIR__ . '/../../includes/functions.php';
$id=(int)($_GET['id']??0); if(!$id) redirect(BASE_URL.'/modules/invoices/index.php');
$db=getDB();
$stmt=$db->prepare("SELECT i.*,c.chassis_number,c.make,c.model,c.year,c.color,c.registration_number FROM invoices i JOIN cars c ON c.id=i.car_id WHERE i.id=?");
$stmt->execute([$id]); $inv=$stmt->fetch();
if(!$inv) die('Not found');
$items=$db->prepare("SELECT * FROM invoice_items WHERE invoice_id=? ORDER BY id"); $items->execute([$id]); $items=$items->fetchAll();
$company=['name'=>getSetting('company_name','Mascardi Car Yard'),'address'=>getSetting('company_address','Nairobi, Kenya'),'phone'=>getSetting('company_phone',''),'email'=>getSetting('company_email',''),'pin'=>getSetting('company_pin','')];
?><!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"><title>Invoice <?= e($inv['invoice_number']) ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="<?= BASE_URL ?>/assets/css/style.css" rel="stylesheet">
<style>body{background:#f1f5f9;font-size:13px}.print-wrapper{max-width:800px;margin:30px auto;background:#fff;padding:40px;box-shadow:0 2px 8px rgba(0,0,0,.1);border-radius:8px}@media print{body{background:#fff}.no-print{display:none!important}.print-wrapper{box-shadow:none;margin:0;padding:20px}}</style>
</head><body>
<div class="no-print text-center py-3">
    <button onclick="window.print()" class="btn btn-primary"><i class="fa fa-print me-1"></i>Print / Save as PDF</button>
    <a href="view.php?id=<?= $id ?>" class="btn btn-outline-secondary ms-2">Back</a>
</div>
<div class="print-wrapper">
    <div class="row mb-4">
        <div class="col-6">
            <div style="font-size:22px;font-weight:800;color:#0f172a"><?= e($company['name']) ?></div>
            <div class="text-muted small"><?= e($company['address']) ?></div>
            <?php if($company['phone']): ?><div class="small">Tel: <?= e($company['phone']) ?></div><?php endif; ?>
            <?php if($company['email']): ?><div class="small">Email: <?= e($company['email']) ?></div><?php endif; ?>
            <?php if($company['pin']): ?><div class="small">KRA PIN: <?= e($company['pin']) ?></div><?php endif; ?>
        </div>
        <div class="col-6 text-end">
            <div style="font-size:26px;font-weight:700;color:#16a34a">TAX INVOICE</div>
            <div class="fw-bold"><?= e($inv['invoice_number']) ?></div>
            <div class="text-muted small">Date: <?= fmtDate($inv['date']) ?></div>
            <div class="text-muted small">Due: <?= fmtDate($inv['due_date']) ?></div>
            <div class="mt-1"><?= statusBadge($inv['status']) ?></div>
        </div>
    </div>
    <hr>
    <div class="row mb-4">
        <div class="col-6">
            <div class="text-muted small fw-bold text-uppercase mb-1">Bill To</div>
            <div class="fw-semibold"><?= e($inv['customer_name']??'—') ?></div>
            <?php if($inv['customer_phone']): ?><div class="small"><?= e($inv['customer_phone']) ?></div><?php endif; ?>
        </div>
        <div class="col-6">
            <div class="text-muted small fw-bold text-uppercase mb-1">Vehicle</div>
            <div class="fw-semibold"><?= e($inv['make'].' '.$inv['model'].' ('.$inv['year'].')') ?></div>
            <div class="small">Chassis: <?= e($inv['chassis_number']) ?></div>
            <?php if($inv['registration_number']): ?><div class="small">Reg: <?= e($inv['registration_number']) ?></div><?php endif; ?>
        </div>
    </div>
    <table class="table table-bordered mb-0" style="font-size:12px">
        <thead style="background:#16a34a;color:#fff">
            <tr><th class="ps-2">#</th><th>Type</th><th>Description</th><th class="text-center">Qty</th><th class="text-end">Unit Price</th><th class="text-end">Amount</th></tr>
        </thead>
        <tbody>
            <?php foreach($items as $i=>$item): ?>
            <tr>
                <td class="ps-2"><?= $i+1 ?></td>
                <td><?= ucfirst($item['item_type']) ?></td>
                <td><?= e($item['description']) ?></td>
                <td class="text-center"><?= $item['quantity'] ?></td>
                <td class="text-end"><?= number_format($item['unit_price'],2) ?></td>
                <td class="text-end fw-semibold"><?= number_format($item['total'],2) ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <div class="row mt-3">
        <div class="col-6">
            <?php if($inv['notes']): ?><div class="small text-muted"><?= e($inv['notes']) ?></div><?php endif; ?>
        </div>
        <div class="col-6">
            <table class="table table-sm mb-0" style="font-size:12px">
                <tr><td class="text-muted">Subtotal</td><td class="text-end">KES <?= number_format($inv['subtotal'],2) ?></td></tr>
                <?php if($inv['discount']>0): ?><tr><td class="text-muted">Discount</td><td class="text-end text-danger">-KES <?= number_format($inv['discount'],2) ?></td></tr><?php endif; ?>
                <tr><td class="text-muted">VAT (<?= $inv['tax_rate'] ?>%)</td><td class="text-end">KES <?= number_format($inv['tax_amount'],2) ?></td></tr>
                <tr style="background:#16a34a;color:#fff"><td><strong>TOTAL</strong></td><td class="text-end"><strong>KES <?= number_format($inv['total'],2) ?></strong></td></tr>
                <tr><td class="text-muted">Amount Paid</td><td class="text-end text-success">KES <?= number_format($inv['amount_paid'],2) ?></td></tr>
                <tr class="<?= ($inv['total']-$inv['amount_paid'])>0?'table-danger':'' ?>"><td><strong>Balance Due</strong></td><td class="text-end"><strong>KES <?= number_format($inv['total']-$inv['amount_paid'],2) ?></strong></td></tr>
            </table>
        </div>
    </div>
    <div class="row mt-4 pt-3 border-top" style="font-size:11px;color:#64748b">
        <div class="col-4 text-center"><div style="border-top:1px solid #cbd5e1;margin-top:30px;padding-top:8px">Prepared By</div></div>
        <div class="col-4 text-center"><div style="border-top:1px solid #cbd5e1;margin-top:30px;padding-top:8px">Authorized By</div></div>
        <div class="col-4 text-center"><div style="border-top:1px solid #cbd5e1;margin-top:30px;padding-top:8px">Received By / Signature</div></div>
    </div>
    <div class="text-center mt-4 text-muted" style="font-size:11px"><?= e($company['name']) ?> — <?= e($company['phone']) ?> — <?= e($company['email']) ?></div>
</div>
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
</body></html>

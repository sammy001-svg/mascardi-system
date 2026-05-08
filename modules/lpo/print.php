<?php
require_once __DIR__ . '/../../includes/functions.php';
$id=(int)($_GET['id']??0); if(!$id) redirect(BASE_URL.'/modules/lpo/index.php');
$db=getDB();
$stmt=$db->prepare("SELECT l.*,s.name AS supplier_name,s.contact_person,s.phone AS supplier_phone,s.email AS supplier_email,s.address AS supplier_address,s.pin_number AS supplier_pin FROM lpo l JOIN suppliers s ON s.id=l.supplier_id WHERE l.id=?");
$stmt->execute([$id]); $lpo=$stmt->fetch(); if(!$lpo) die('Not found');
$items=$db->prepare("SELECT * FROM lpo_items WHERE lpo_id=? ORDER BY id"); $items->execute([$id]); $items=$items->fetchAll();
$company=['name'=>getSetting('company_name','Mascardi Car Yard'),'address'=>getSetting('company_address','Nairobi, Kenya'),'phone'=>getSetting('company_phone',''),'email'=>getSetting('company_email',''),'pin'=>getSetting('company_pin','')];
?><!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"><title>LPO <?= e($lpo['lpo_number']) ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="<?= BASE_URL ?>/assets/css/style.css" rel="stylesheet">
<style>body{background:#f1f5f9;font-size:13px}.print-wrapper{max-width:800px;margin:30px auto;background:#fff;padding:40px;box-shadow:0 2px 8px rgba(0,0,0,.1);border-radius:8px}@media print{body{background:#fff}.no-print{display:none!important}.print-wrapper{box-shadow:none;margin:0;padding:20px}}</style>
</head><body>
<div class="no-print text-center py-3"><button onclick="window.print()" class="btn btn-primary"><i class="fa fa-print me-1"></i>Print / Save as PDF</button><a href="view.php?id=<?= $id ?>" class="btn btn-outline-secondary ms-2">Back</a></div>
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
            <div style="font-size:26px;font-weight:700;color:#d97706">LOCAL PURCHASE ORDER</div>
            <div class="fw-bold"><?= e($lpo['lpo_number']) ?></div>
            <div class="text-muted small">Date: <?= fmtDate($lpo['date']) ?></div>
            <?php if($lpo['expected_delivery']): ?><div class="text-muted small">Expected Delivery: <?= fmtDate($lpo['expected_delivery']) ?></div><?php endif; ?>
            <div class="mt-1"><?= statusBadge($lpo['status']) ?></div>
        </div>
    </div>
    <hr>
    <div class="row mb-4">
        <div class="col-6">
            <div class="text-muted small fw-bold text-uppercase mb-1">To (Supplier)</div>
            <div class="fw-semibold"><?= e($lpo['supplier_name']) ?></div>
            <?php if($lpo['contact_person']): ?><div class="small">Attn: <?= e($lpo['contact_person']) ?></div><?php endif; ?>
            <?php if($lpo['supplier_phone']): ?><div class="small"><?= e($lpo['supplier_phone']) ?></div><?php endif; ?>
            <?php if($lpo['supplier_email']): ?><div class="small"><?= e($lpo['supplier_email']) ?></div><?php endif; ?>
            <?php if($lpo['supplier_pin']): ?><div class="small">PIN: <?= e($lpo['supplier_pin']) ?></div><?php endif; ?>
        </div>
        <div class="col-6">
            <div class="text-muted small fw-bold text-uppercase mb-1">Deliver To</div>
            <div class="small"><?= e($lpo['delivery_address'] ?: $company['address']) ?></div>
            <?php if($lpo['approved_by']): ?><div class="mt-2 small"><strong>Approved By:</strong> <?= e($lpo['approved_by']) ?></div><?php endif; ?>
        </div>
    </div>
    <table class="table table-bordered mb-0" style="font-size:12px">
        <thead style="background:#d97706;color:#fff">
            <tr><th class="ps-2">#</th><th>Description</th><th class="text-center">Qty</th><th>Unit</th><th class="text-end">Unit Price (KES)</th><th class="text-end">Total (KES)</th></tr>
        </thead>
        <tbody>
            <?php foreach($items as $i=>$item): ?>
            <tr>
                <td class="ps-2"><?= $i+1 ?></td>
                <td><?= e($item['description']) ?></td>
                <td class="text-center"><?= $item['quantity'] ?></td>
                <td><?= e($item['unit']) ?></td>
                <td class="text-end"><?= number_format($item['unit_price'],2) ?></td>
                <td class="text-end fw-semibold"><?= number_format($item['total'],2) ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <div class="row mt-3">
        <div class="col-6"><?php if($lpo['notes']): ?><div class="small text-muted"><strong>Notes:</strong><br><?= e($lpo['notes']) ?></div><?php endif; ?></div>
        <div class="col-6">
            <table class="table table-sm mb-0" style="font-size:12px">
                <tr><td class="text-muted">Subtotal</td><td class="text-end">KES <?= number_format($lpo['subtotal'],2) ?></td></tr>
                <tr><td class="text-muted">VAT (<?= $lpo['tax_rate'] ?>%)</td><td class="text-end">KES <?= number_format($lpo['tax_amount'],2) ?></td></tr>
                <tr style="background:#d97706;color:#fff"><td><strong>TOTAL</strong></td><td class="text-end"><strong>KES <?= number_format($lpo['total'],2) ?></strong></td></tr>
            </table>
        </div>
    </div>
    <div class="row mt-4 pt-3 border-top" style="font-size:11px;color:#64748b">
        <div class="col-4 text-center"><div style="border-top:1px solid #cbd5e1;margin-top:30px;padding-top:8px">Requested By</div></div>
        <div class="col-4 text-center"><div style="border-top:1px solid #cbd5e1;margin-top:30px;padding-top:8px">Authorized By</div></div>
        <div class="col-4 text-center"><div style="border-top:1px solid #cbd5e1;margin-top:30px;padding-top:8px">Supplier Acknowledgement</div></div>
    </div>
    <div class="text-center mt-4 text-muted" style="font-size:11px"><?= e($company['name']) ?> — <?= e($company['phone']) ?></div>
</div>
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
</body></html>

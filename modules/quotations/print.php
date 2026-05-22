<?php
require_once __DIR__ . '/../../includes/functions.php';
$id=(int)($_GET['id']??0); if(!$id) redirect(BASE_URL.'/modules/quotations/index.php');
$db=getDB();
$stmt=$db->prepare("SELECT q.*,c.chassis_number,c.make,c.model,c.year,c.color,c.registration_number FROM quotations q JOIN cars c ON c.id=q.car_id WHERE q.id=?");
$stmt->execute([$id]); $q=$stmt->fetch();
if(!$q) die('Not found');
$items=$db->prepare("SELECT * FROM quotation_items WHERE quotation_id=? ORDER BY id"); $items->execute([$id]); $items=$items->fetchAll();
$company = ['name'=>getSetting('company_name','Mascardi Car Yard'),'address'=>getSetting('company_address','Nairobi, Kenya'),'phone'=>getSetting('company_phone',''),'email'=>getSetting('company_email',''),'pin'=>getSetting('company_pin','')];
$isClient = (bool)($_SESSION['_client'] ?? false);
if ($isClient && $q['client_id'] !== $_SESSION['_client']['id']) {
    die('Unauthorized access.');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Quotation <?= e($q['quotation_number']) ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="<?= BASE_URL ?>/assets/css/style.css" rel="stylesheet">
<style>
    body{background:#f1f5f9;font-size:13px}
    .print-wrapper{max-width:800px;margin:30px auto;background:#fff;padding:40px;box-shadow:0 2px 8px rgba(0,0,0,.1);border-radius:8px}
    .watermark{position:absolute;opacity:.04;font-size:100px;font-weight:900;transform:rotate(-30deg);top:200px;left:80px;color:#000;pointer-events:none;text-transform:uppercase}
    @media print{body{background:#fff}.no-print{display:none!important}.print-wrapper{box-shadow:none;margin:0;padding:20px}}
</style>
</head>
<body>
<div class="no-print text-center py-3">
    <button onclick="window.print()" class="btn btn-primary"><i class="fa fa-print me-1"></i>Print / Save as PDF</button>
    <?php if ($isClient): ?>
        <a href="<?= BASE_URL ?>/client/quotations.php" class="btn btn-outline-secondary ms-2">Back to Portal</a>
    <?php else: ?>
        <a href="view.php?id=<?= $id ?>" class="btn btn-outline-secondary ms-2">Back</a>
    <?php endif; ?>
</div>
<div class="print-wrapper position-relative">
    <?php if(in_array($q['status'],['rejected','cancelled'])): ?><div class="watermark"><?= $q['status'] ?></div><?php endif; ?>

    <!-- Header -->
    <div class="row mb-4">
        <div class="col-6">
            <?php $__logo = getSetting('company_logo',''); ?>
            <?php if ($__logo && file_exists(BASE_PATH.'/assets/images/'.$__logo)): ?>
            <img src="<?= BASE_URL ?>/assets/images/<?= e($__logo) ?>" alt="<?= e($company['name']) ?>" style="height:52px;max-width:180px;object-fit:contain;margin-bottom:6px;display:block">
            <?php else: ?>
            <div style="font-size:22px;font-weight:800;color:#0f172a;margin-bottom:4px"><?= e($company['name']) ?></div>
            <?php endif; ?>
            <div class="text-muted small"><?= e($company['address']) ?></div>
            <?php if($company['phone']): ?><div class="small">Tel: <?= e($company['phone']) ?></div><?php endif; ?>
            <?php if($company['email']): ?><div class="small">Email: <?= e($company['email']) ?></div><?php endif; ?>
            <?php if($company['pin']): ?><div class="small">PIN: <?= e($company['pin']) ?></div><?php endif; ?>
        </div>
        <div class="col-6 text-end">
            <div style="font-size:26px;font-weight:700;color:#2563eb">QUOTATION</div>
            <div class="fw-bold"><?= e($q['quotation_number']) ?></div>
            <div class="text-muted small">Date: <?= fmtDate($q['date']) ?></div>
            <div class="text-muted small">Valid Until: <?= fmtDate($q['valid_until']) ?></div>
            <div class="mt-1"><?= statusBadge($q['status']) ?></div>
        </div>
    </div>

    <hr>

    <!-- Bill to / Vehicle -->
    <div class="row mb-4">
        <div class="col-6">
            <div class="text-muted small fw-bold text-uppercase mb-1">Prepared For</div>
            <div class="fw-semibold"><?= e($q['customer_name']??'—') ?></div>
            <?php if($q['customer_phone']): ?><div class="small"><?= e($q['customer_phone']) ?></div><?php endif; ?>
            <?php if($q['customer_email']): ?><div class="small"><?= e($q['customer_email']) ?></div><?php endif; ?>
        </div>
        <div class="col-6">
            <div class="text-muted small fw-bold text-uppercase mb-1">Vehicle Details</div>
            <div class="fw-semibold"><?= e($q['make'].' '.$q['model'].' ('.$q['year'].')') ?></div>
            <div class="small">Chassis: <?= e($q['chassis_number']) ?></div>
            <?php if($q['registration_number']): ?><div class="small">Reg: <?= e($q['registration_number']) ?></div><?php endif; ?>
            <?php if($q['color']): ?><div class="small">Color: <?= e($q['color']) ?></div><?php endif; ?>
        </div>
    </div>

    <!-- Items Table -->
    <table class="table table-bordered mb-0" style="font-size:12px">
        <thead style="background:#2563eb;color:#fff">
            <tr><th class="ps-2">#</th><th>Type</th><th>Description</th><th class="text-center">Qty</th><th class="text-end">Unit Price</th><th class="text-end">Disc%</th><th class="text-end">Amount</th></tr>
        </thead>
        <tbody>
            <?php foreach($items as $i => $item): ?>
            <tr>
                <td class="ps-2"><?= $i+1 ?></td>
                <td><?= ucfirst($item['item_type']) ?></td>
                <td><?= e($item['description']) ?></td>
                <td class="text-center"><?= $item['quantity'] ?></td>
                <td class="text-end"><?= number_format($item['unit_price'],2) ?></td>
                <td class="text-end"><?= $item['discount'] ? $item['discount'].'%' : '—' ?></td>
                <td class="text-end fw-semibold"><?= number_format($item['total'],2) ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <!-- Totals -->
    <div class="row mt-3">
        <div class="col-6">
            <?php if($q['notes']): ?><div class="mb-2"><strong class="small">Notes:</strong><div class="small text-muted"><?= e($q['notes']) ?></div></div><?php endif; ?>
            <?php if($q['terms']): ?><div><strong class="small">Terms & Conditions:</strong><div class="small text-muted"><?= e($q['terms']) ?></div></div><?php endif; ?>
        </div>
        <div class="col-6">
            <table class="table table-sm mb-0" style="font-size:12px">
                <tr><td class="text-muted">Subtotal</td><td class="text-end">KES <?= number_format($q['subtotal'],2) ?></td></tr>
                <?php if($q['discount']>0): ?><tr><td class="text-muted">Discount</td><td class="text-end text-danger">-KES <?= number_format($q['discount'],2) ?></td></tr><?php endif; ?>
                <tr><td class="text-muted">VAT (<?= $q['tax_rate'] ?>%)</td><td class="text-end">KES <?= number_format($q['tax_amount'],2) ?></td></tr>
                <tr style="background:#2563eb;color:#fff"><td><strong>TOTAL</strong></td><td class="text-end"><strong>KES <?= number_format($q['total'],2) ?></strong></td></tr>
            </table>
        </div>
    </div>

    <div class="row mt-4 pt-3 border-top" style="font-size:11px;color:#64748b">
        <div class="col-4 text-center"><div style="border-top:1px solid #cbd5e1;margin-top:30px;padding-top:8px">Prepared By</div></div>
        <div class="col-4 text-center"><div style="border-top:1px solid #cbd5e1;margin-top:30px;padding-top:8px">Authorized By</div></div>
        <div class="col-4 text-center"><div style="border-top:1px solid #cbd5e1;margin-top:30px;padding-top:8px">Customer Signature</div></div>
    </div>
    <div class="text-center mt-4 text-muted" style="font-size:11px">Quotation generated by <?= e($company['name']) ?> — <?= e($company['phone']) ?></div>
</div>
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
</body>
</html>

<?php
require_once __DIR__ . '/../../includes/functions.php';
$id=(int)($_GET['id']??0); if(!$id) redirect(BASE_URL.'/modules/invoices/index.php');
$db=getDB();
try { $db->exec("ALTER TABLE invoices ADD COLUMN customer_kra_pin VARCHAR(20) NULL AFTER customer_email"); } catch (\Throwable $_) {}
try { $db->exec("ALTER TABLE clients ADD COLUMN kra_pin VARCHAR(20) NULL AFTER id_number"); } catch (\Throwable $_) {}
// Join the client even when client_id is NULL by falling back to a name-match.
// COALESCE priority: (1) client.kra_pin (always current)  (2) client.id_number (legacy)  (3) customer_kra_pin on invoice (walk-ins only)
$stmt=$db->prepare("
    SELECT i.*, c.chassis_number, c.make, c.model, c.year, c.color, c.registration_number,
           cl.id_number AS client_id_number,
           COALESCE(
               NULLIF(TRIM(cl.kra_pin), ''),
               NULLIF(TRIM(cl.id_number), ''),
               NULLIF(TRIM(i.customer_kra_pin), '')
           ) AS display_kra_pin
    FROM invoices i
    JOIN cars c ON c.id = i.car_id
    LEFT JOIN clients cl ON cl.id = COALESCE(
        i.client_id,
        (SELECT id FROM clients
         WHERE LOWER(TRIM(name)) = LOWER(TRIM(i.customer_name))
           AND status = 'active'
         LIMIT 1)
    )
    WHERE i.id = ?
");
$stmt->execute([$id]); $inv=$stmt->fetch();
if(!$inv) die('Not found');
$items=$db->prepare("SELECT * FROM invoice_items WHERE invoice_id=? ORDER BY id"); $items->execute([$id]); $items=$items->fetchAll();
$company=['name'=>getSetting('company_name','Mascardi Car Yard'),'address'=>getSetting('company_address','Nairobi, Kenya'),'phone'=>getSetting('company_phone',''),'email'=>getSetting('company_email',''),'pin'=>getSetting('company_pin','')];
$isClient = (bool)($_SESSION['_client'] ?? false);
if ($isClient && $inv['client_id'] !== $_SESSION['_client']['id']) {
    die('Unauthorized access.');
}
?>
<!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"><title>Invoice <?= e($inv['invoice_number']) ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="<?= BASE_URL ?>/assets/css/style.css" rel="stylesheet">
<style>body{background:#f1f5f9;font-size:13px}.print-wrapper{max-width:800px;margin:30px auto;background:#fff;padding:40px;box-shadow:0 2px 8px rgba(0,0,0,.1);border-radius:8px}@media print{body{background:#fff}.no-print{display:none!important}.print-wrapper{box-shadow:none;margin:0;padding:20px}}</style>
</head><body>
<div class="no-print text-center py-3">
    <button onclick="window.print()" class="btn btn-primary"><i class="fa fa-print me-1"></i>Print / Save as PDF</button>
    <?php if ($isClient): ?>
        <a href="<?= BASE_URL ?>/client/invoices.php" class="btn btn-outline-secondary ms-2">Back to Portal</a>
    <?php else: ?>
        <a href="view.php?id=<?= $id ?>" class="btn btn-outline-secondary ms-2">Back</a>
    <?php endif; ?>
</div>
<div class="print-wrapper">
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
            <?php if($inv['customer_phone']): ?><div class="small">Tel: <?= e($inv['customer_phone']) ?></div><?php endif; ?>
            <?php if($inv['customer_email'] ?? ''): ?><div class="small"><?= e($inv['customer_email']) ?></div><?php endif; ?>
            <?php if($inv['display_kra_pin']): ?><div class="small" style="margin-top:3px">KRA PIN: <strong><?= e(strtoupper($inv['display_kra_pin'])) ?></strong></div><?php endif; ?>
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
        <div class="col-7">
            <div class="mb-3">
                <div class="small text-muted fw-bold text-uppercase" style="font-size:10px">Total in words:</div>
                <div class="fw-bold text-primary" style="font-size:12px"><?= numberToWords($inv['total']) ?></div>
            </div>
            
            <div class="p-3 border rounded-3 bg-light bg-opacity-25 mb-3" style="font-size:11px">
                <h6 class="fw-bold mb-2" style="font-size:12px;text-decoration:underline;color:#16a34a">COMPANY PAYMENT DETAILS:</h6>
                <div class="row g-1">
                    <div class="col-5 text-muted">A/C Holder's Name:</div><div class="col-7 fw-semibold">Mascardi Ventures Limited</div>
                    <div class="col-5 text-muted">Bank Name:</div><div class="col-7 fw-semibold">DTB Bank Ksh</div>
                    <div class="col-5 text-muted">A/C No:</div><div class="col-7 fw-semibold">0581403001</div>
                    <div class="col-5 text-muted">Branch & Bank code:</div><div class="col-7 fw-semibold">Lavingtone & 63</div>
                    <div class="col-5 text-muted">SWIFT code:</div><div class="col-7 fw-semibold">DTKEKENA</div>
                </div>
            </div>

            <?php if($inv['notes']): ?><div class="small text-muted mb-3"><strong>Notes:</strong> <?= e($inv['notes']) ?></div><?php endif; ?>

            <div class="mt-2">
                <h6 class="fw-bold mb-1" style="font-size:11px;text-decoration:underline">TERMS & CONDITIONS:</h6>
                <ol class="text-muted ps-3 mb-0" style="font-size:10.5px;line-height:1.4">
                    <li>All payments to be made to the official Mascardi account listed on this invoice only.</li>
                </ol>
            </div>
        </div>
        <div class="col-5">
            <table class="table table-sm mb-0" style="font-size:12px">
                <tr><td class="text-muted border-0">Subtotal</td><td class="text-end border-0">KES <?= number_format($inv['subtotal'],2) ?></td></tr>
                <?php if($inv['discount']>0): ?><tr><td class="text-muted border-0">Discount</td><td class="text-end text-danger border-0">-KES <?= number_format($inv['discount'],2) ?></td></tr><?php endif; ?>
                <tr><td class="text-muted border-0">VAT (<?= $inv['tax_rate'] ?>%)</td><td class="text-end border-0">KES <?= number_format($inv['tax_amount'],2) ?></td></tr>
                <tr style="background:#16a34a;color:#fff"><td class="border-0"><strong>TOTAL</strong></td><td class="text-end border-0"><strong>KES <?= number_format($inv['total'],2) ?></strong></td></tr>
                <tr><td class="text-muted border-0">Amount Paid</td><td class="text-end text-success border-0">KES <?= number_format($inv['amount_paid'],2) ?></td></tr>
                <tr class="<?= ($inv['total']-$inv['amount_paid'])>0?'table-danger':'' ?> border-top"><td class="border-0"><strong>Balance Due</strong></td><td class="text-end border-0"><strong>KES <?= number_format($inv['total']-$inv['amount_paid'],2) ?></strong></td></tr>
            </table>
        </div>
    </div>

    <div class="mt-5 pt-4">
        <div class="row g-4" style="font-size:11px;color:#334155">
            <div class="col-6">
                <div class="p-3 border rounded-3 bg-light bg-opacity-10">
                    <div class="mb-4"><strong>Prepared by:</strong></div>
                    <div style="border-top:1px dashed #cbd5e1;margin-top:20px;padding-top:5px" class="text-muted">Signature & Date</div>
                </div>
            </div>
            <div class="col-6">
                <div class="p-3 border rounded-3 bg-light bg-opacity-10">
                    <div class="mb-4"><strong>Approved by:</strong></div>
                    <div style="border-top:1px dashed #cbd5e1;margin-top:20px;padding-top:5px" class="text-muted">Signature & Date</div>
                </div>
            </div>
        </div>
    </div>

    <div class="text-center mt-5 text-muted border-top pt-3" style="font-size:10px">
        <?= e($company['name']) ?> &bull; <?= e($company['phone']) ?> &bull; <?= e($company['email']) ?>
    </div>
</div>
</div>
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
</body></html>

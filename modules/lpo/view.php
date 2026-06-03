<?php
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/notifications.php';
require_once __DIR__ . '/../../includes/mailer.php';
requireLogin();
canAccess('lpo') || die('Access denied.');
$id=(int)($_GET['id']??0); if(!$id) redirect(BASE_URL.'/modules/lpo/index.php');
$db=getDB();
$stmt=$db->prepare("SELECT l.*,s.name AS supplier_name,s.contact_person,s.phone AS supplier_phone,s.email AS supplier_email,s.address AS supplier_address,s.pin_number AS supplier_pin,pr.request_number AS linked_qr_number,pr.id AS linked_qr_id FROM lpo l JOIN suppliers s ON s.id=l.supplier_id LEFT JOIN parts_requests pr ON pr.id=l.parts_request_id WHERE l.id=?");
$stmt->execute([$id]); $lpo=$stmt->fetch();
if(!$lpo){setFlash('error','Not found.');redirect(BASE_URL.'/modules/lpo/index.php');}
$items=$db->prepare("SELECT * FROM lpo_items WHERE lpo_id=? ORDER BY id"); $items->execute([$id]); $items=$items->fetchAll();

if(isset($_GET['status'])){
    canWrite('lpo') || die('Permission denied.');
    $db->prepare("UPDATE lpo SET status=? WHERE id=?")->execute([$_GET['status'],$id]);
    // Email supplier when LPO is marked sent
    if ($_GET['status'] === 'sent' && $lpo['supplier_email'] && filter_var($lpo['supplier_email'], FILTER_VALIDATE_EMAIL)) {
        $subj = "Purchase Order " . $lpo['lpo_number'] . " from " . getSetting('company_name', 'Mascardi System');
        $rows = '';
        foreach ($items as $it) {
            $rows .= '<tr><td>' . e($it['description']) . '</td><td style="text-align:center">' . $it['quantity'] . ' ' . e($it['unit']) . '</td><td style="text-align:right">' . money((float)$it['unit_price']) . '</td><td style="text-align:right">' . money((float)$it['total']) . '</td></tr>';
        }
        $attn = $lpo['contact_person'] ? ' (' . e($lpo['contact_person']) . ')' : '';
        $body = "<p>Dear " . e($lpo['supplier_name']) . "{$attn},</p>
                <p>Please find below our Purchase Order <strong>" . e($lpo['lpo_number']) . "</strong>. Kindly acknowledge receipt and confirm delivery details.</p>
                <table class='data'>
                  <thead><tr><th>Description</th><th>Qty</th><th>Unit Price</th><th>Total</th></tr></thead>
                  <tbody>{$rows}</tbody>
                </table>
                <table class='data' style='margin-top:8px'>
                  <tr><td>Subtotal</td><td style='text-align:right'>" . money((float)$lpo['subtotal']) . "</td></tr>
                  <tr><td>VAT (" . $lpo['tax_rate'] . "%)</td><td style='text-align:right'>" . money((float)$lpo['tax_amount']) . "</td></tr>
                  <tr class='total-row'><td><strong>Total</strong></td><td style='text-align:right'><strong>" . money((float)$lpo['total']) . "</strong></td></tr>
                </table>
                " . ($lpo['expected_delivery'] ? "<p>Expected delivery: <strong>" . fmtDate($lpo['expected_delivery']) . "</strong></p>" : '') . "
                " . ($lpo['notes'] ? "<p><em>" . e($lpo['notes']) . "</em></p>" : '') . "
                <p>Please deliver to: " . e(getSetting('company_address', '')) . "</p>";
        sendMail($lpo['supplier_email'], $lpo['supplier_name'], $subj, mailTemplate($subj, $body), 'lpo', $id);
    }
    // If received, update inventory
    if($_GET['status']==='received'){
        foreach($items as $item){
            if($item['inventory_id']){
                $db->prepare("UPDATE inventory SET quantity=quantity+? WHERE id=?")->execute([$item['quantity'],$item['inventory_id']]);
                $newQty=$db->query("SELECT quantity FROM inventory WHERE id={$item['inventory_id']}")->fetchColumn();
                $db->prepare("INSERT INTO inventory_transactions (inventory_id,transaction_type,quantity,balance,reference_type,reference_id,notes) VALUES (?,?,?,?,?,?,?)")->execute([$item['inventory_id'],'in',$item['quantity'],$newQty,'lpo',$id,'Received from LPO '.$lpo['lpo_number']]);
            }
        }
        notifyRoles(['admin','workshop_manager'], 'lpo',
            "LPO Received: {$lpo['lpo_number']}",
            count($items) . ' item(s) added to inventory from ' . $lpo['supplier_name'],
            BASE_URL . '/modules/lpo/view.php?id=' . $id
        );
        setFlash('success','LPO marked as received. Inventory updated.');
    } else { setFlash('success','Status updated.'); }
    redirect('view.php?id='.$id);
}

$pageTitle=$lpo['lpo_number'];
include __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="mb-0">LPO: <strong><?= e($lpo['lpo_number']) ?></strong> <?= statusBadge($lpo['status']) ?></h5>
    <div class="d-flex gap-2 flex-wrap">
        <a href="print.php?id=<?= $id ?>" class="btn btn-sm btn-outline-dark" target="_blank"><i class="fa fa-print me-1"></i>Print / PDF</a>
        <?php if($lpo['status']==='draft' && canWrite('lpo')): ?>
        <a href="edit.php?id=<?= $id ?>" class="btn btn-sm btn-outline-secondary"><i class="fa fa-pen me-1"></i>Edit</a>
        <?php endif; ?>
        <?php if($lpo['status']==='draft'): ?>
        <a href="?id=<?= $id ?>&status=sent" class="btn btn-sm btn-outline-info">Mark Sent</a>
        <a href="?id=<?= $id ?>&status=acknowledged" class="btn btn-sm btn-outline-primary">Acknowledged</a>
        <?php endif; ?>
        <?php if(in_array($lpo['status'],['sent','acknowledged','partial'])): ?>
        <a href="?id=<?= $id ?>&status=received" class="btn btn-sm btn-success" onclick="return confirm('Mark as received and update inventory?')"><i class="fa fa-check me-1"></i>Mark Received</a>
        <?php endif; ?>
        <a href="index.php" class="btn btn-sm btn-outline-secondary"><i class="fa fa-arrow-left me-1"></i>Back</a>
        <?php if (hasRole('admin')): ?>
        <a href="delete.php?id=<?= $id ?>" class="btn btn-sm btn-danger"
           onclick="return confirm('Permanently delete LPO <?= e($lpo['lpo_number']) ?>? This cannot be undone.')">
            <i class="fa fa-trash me-1"></i>Delete
        </a>
        <?php endif; ?>
    </div>
</div>

<div class="row g-4">
    <div class="col-lg-4">
        <div class="card mb-3"><div class="card-header"><i class="fa fa-truck me-2"></i>Supplier</div><div class="card-body">
            <dl class="row mb-0" style="font-size:13.5px">
                <dt class="col-5 text-muted">Supplier</dt><dd class="col-7 fw-semibold"><?= e($lpo['supplier_name']) ?></dd>
                <dt class="col-5 text-muted">Contact</dt><dd class="col-7"><?= e($lpo['contact_person']??'—') ?></dd>
                <dt class="col-5 text-muted">Phone</dt><dd class="col-7"><?= e($lpo['supplier_phone']??'—') ?></dd>
                <dt class="col-5 text-muted">Email</dt><dd class="col-7"><?= e($lpo['supplier_email']??'—') ?></dd>
                <dt class="col-5 text-muted">PIN</dt><dd class="col-7"><?= e($lpo['supplier_pin']??'—') ?></dd>
            </dl>
        </div></div>
        <div class="card mb-3"><div class="card-header">LPO Info</div><div class="card-body">
            <dl class="row mb-0" style="font-size:13.5px">
                <dt class="col-5 text-muted">LPO No.</dt><dd class="col-7 fw-bold"><?= e($lpo['lpo_number']) ?></dd>
                <dt class="col-5 text-muted">Date</dt><dd class="col-7"><?= fmtDate($lpo['date']) ?></dd>
                <dt class="col-5 text-muted">Expected Del.</dt><dd class="col-7"><?= fmtDate($lpo['expected_delivery']) ?></dd>
                <dt class="col-5 text-muted">Delivery Date</dt><dd class="col-7"><?= fmtDate($lpo['delivery_date']) ?></dd>
                <dt class="col-5 text-muted">Quote Request</dt>
                <dd class="col-7">
                    <?php if (!empty($lpo['linked_qr_number'])): ?>
                    <a href="<?= BASE_URL ?>/modules/parts_requests/view.php?id=<?= $lpo['linked_qr_id'] ?>" class="fw-medium text-decoration-none">
                        <?= e($lpo['linked_qr_number']) ?>
                    </a>
                    <?php else: ?>—<?php endif; ?>
                </dd>
                <dt class="col-5 text-muted">Approved By</dt><dd class="col-7"><?= e($lpo['approved_by']??'—') ?></dd>
            </dl>
        </div></div>
        <div class="card"><div class="card-header">Totals</div><div class="card-body">
            <table class="table table-sm mb-0">
                <tr><td class="text-muted">Subtotal</td><td class="text-end"><?= money($lpo['subtotal']) ?></td></tr>
                <tr><td class="text-muted">VAT (<?= $lpo['tax_rate'] ?>%)</td><td class="text-end"><?= money($lpo['tax_amount']) ?></td></tr>
                <tr class="table-warning"><td><strong>Total</strong></td><td class="text-end"><strong><?= money($lpo['total']) ?></strong></td></tr>
            </table>
        </div></div>
    </div>
    <div class="col-lg-8">
        <div class="card"><div class="card-header">Items</div>
            <div class="card-body p-0">
                <table class="table mb-0">
                    <thead><tr><th class="ps-3">#</th><th>Description</th><th>Qty</th><th>Unit</th><th>Unit Price</th><th>Total</th></tr></thead>
                    <tbody>
                        <?php foreach($items as $i=>$item): ?>
                        <tr>
                            <td class="ps-3"><?= $i+1 ?></td>
                            <td><?= e($item['description']) ?></td>
                            <td><?= $item['quantity'] ?></td>
                            <td><?= e($item['unit']) ?></td>
                            <td><?= money($item['unit_price']) ?></td>
                            <td><strong><?= money($item['total']) ?></strong></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php if($lpo['notes']): ?><div class="card mt-3"><div class="card-body small text-muted"><?= e($lpo['notes']) ?></div></div><?php endif; ?>
    </div>
</div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>

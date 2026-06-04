<?php
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
canAccess('invoices') || die('Access denied.');
require_once __DIR__ . '/../../includes/mailer.php';
$id=(int)($_GET['id']??0); if(!$id) redirect(BASE_URL.'/modules/invoices/index.php');
$db=getDB();
$stmt=$db->prepare("SELECT i.*,c.chassis_number,c.make,c.model,c.year,c.color,c.registration_number FROM invoices i JOIN cars c ON c.id=i.car_id WHERE i.id=?");
$stmt->execute([$id]); $inv=$stmt->fetch();
if(!$inv){setFlash('error','Not found.');redirect(BASE_URL.'/modules/invoices/index.php');}
$items=$db->prepare("SELECT * FROM invoice_items WHERE invoice_id=?"); $items->execute([$id]); $items=$items->fetchAll();

// Link to client
if($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['link_client_id'])){
    canWrite('invoices') || die('Permission denied.');
    $lcid=(int)$_POST['link_client_id']?:null;
    $db->prepare("UPDATE invoices SET client_id=? WHERE id=?")->execute([$lcid,$id]);
    setFlash('success','Client linked.');redirect(BASE_URL.'/modules/invoices/view.php?id='.$id);
}

// Send invoice email
if($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['send_email'])){
    $toEmail=trim($_POST['to_email']??'');
    $toName =trim($_POST['to_name']??'Invoice');
    if($toEmail && filter_var($toEmail,FILTER_VALIDATE_EMAIL)){
        $subj='Invoice '.$inv['invoice_number'].' from '.getSetting('company_name','Mascardi System');
        $rows='';
        foreach($items as $it) $rows.='<tr><td>'.e($it['item_type']).'</td><td>'.e($it['description']).'</td><td style="text-align:center">'.$it['quantity'].'</td><td style="text-align:right">'.money((float)$it['unit_price']).'</td><td style="text-align:right">'.money((float)$it['total']).'</td></tr>';
        $body='<p>Dear '.e($toName).',</p><p>Please find below your invoice from '.e(getSetting('company_name','us')).'.</p>
               <table class="data"><thead><tr><th>Type</th><th>Description</th><th>Qty</th><th>Unit Price</th><th>Total</th></tr></thead><tbody>'.$rows.'</tbody></table>
               <table class="data" style="margin-top:8px"><tr><td>Subtotal</td><td style="text-align:right">'.money((float)$inv['subtotal']).'</td></tr>
               <tr><td>Discount</td><td style="text-align:right">-'.money((float)$inv['discount']).'</td></tr>
               <tr><td>VAT ('.$inv['tax_rate'].'%)</td><td style="text-align:right">'.money((float)$inv['tax_amount']).'</td></tr>
               <tr class="total-row"><td>Total</td><td style="text-align:right">'.money((float)$inv['total']).'</td></tr>
               <tr><td>Amount Paid</td><td style="text-align:right">'.money((float)$inv['amount_paid']).'</td></tr>
               <tr><td><strong>Balance Due</strong></td><td style="text-align:right"><strong>'.money((float)$inv['total']-(float)$inv['amount_paid']).'</strong></td></tr></table>';
        $r=sendMail($toEmail,$toName,$subj,mailTemplate($subj,$body),'invoice',$id);
        setFlash($r['ok']?'success':'error',$r['ok']?'Invoice emailed to '.$toEmail.'.'  :'Email failed: '.$r['error']);
    } else { setFlash('error','Invalid email address.'); }
    redirect(BASE_URL.'/modules/invoices/view.php?id='.$id);
}

// Record payment
if($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['payment_amount'])){
    (canWrite('payments') || canWrite('invoices')) || die('Permission denied.');
    $amount=(float)$_POST['payment_amount'];
    $newPaid=$inv['amount_paid']+$amount;
    $newStatus=$newPaid>=$inv['total']?'paid':($newPaid>0?'partial':'unpaid');
    $db->prepare("UPDATE invoices SET amount_paid=?, status=? WHERE id=?")->execute([$newPaid,$newStatus,$id]);
    // Receipt email
    if ($inv['customer_email'] && filter_var($inv['customer_email'],FILTER_VALIDATE_EMAIL)) {
        $subj = 'Payment Received — ' . $inv['invoice_number'];
        $balDue = max(0, $inv['total'] - $newPaid);
        $body = "<p>Dear " . e($inv['customer_name'] ?: 'Customer') . ",</p>
                <p>We have received your payment of <strong>" . money($amount) . "</strong> against invoice <strong>" . e($inv['invoice_number']) . "</strong>.</p>
                <table class='data'>
                  <tr><th>Invoice No.</th><td>" . e($inv['invoice_number']) . "</td></tr>
                  <tr><th>Invoice Total</th><td>" . money((float)$inv['total']) . "</td></tr>
                  <tr><th>Amount Paid</th><td>" . money($newPaid) . "</td></tr>
                  <tr><th>Balance Due</th><td>" . ($balDue > 0 ? money($balDue) : '<span style=\"color:#16a34a\">Fully Paid</span>') . "</td></tr>
                </table>
                <p>Thank you for your payment!</p>";
        sendMail($inv['customer_email'], $inv['customer_name'] ?: 'Customer', $subj, mailTemplate($subj, $body), 'invoice', $id);
    }
    setFlash('success','Payment of '.money($amount).' recorded.');
    redirect('view.php?id='.$id);
}
if(isset($_GET['status'])){
    canWrite('invoices') || die('Permission denied.');
    $db->prepare("UPDATE invoices SET status=? WHERE id=?")->execute([$_GET['status'],$id]);
    redirect('view.php?id='.$id);
}

$pageTitle=$inv['invoice_number'];
include __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="mb-0">Invoice: <strong><?= e($inv['invoice_number']) ?></strong> <?= statusBadge($inv['status']) ?></h5>
    <div class="d-flex gap-2 flex-wrap">
        <?php if (in_array($inv['status'], ['unpaid','partial']) && canWrite('invoices')): ?>
        <a href="edit.php?id=<?= $id ?>" class="btn btn-sm btn-outline-secondary"><i class="fa fa-pen me-1"></i>Edit</a>
        <?php endif; ?>
        <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#emailModal"><i class="fa fa-envelope me-1"></i>Send to Client</button>
        <a href="print.php?id=<?= $id ?>" class="btn btn-sm btn-outline-dark" target="_blank"><i class="fa fa-print me-1"></i>Print</a>
        <a href="download_pdf.php?id=<?= $id ?>" class="btn btn-sm btn-outline-danger" target="_blank"><i class="fa fa-file-pdf me-1"></i>Download PDF</a>
        <?php if($inv['status']!=='paid' && canWrite('invoices')): ?><a href="?id=<?= $id ?>&status=cancelled" class="btn btn-sm btn-outline-danger" onclick="return confirm('Cancel invoice?')">Cancel</a><?php endif; ?>
        <a href="index.php" class="btn btn-sm btn-outline-secondary"><i class="fa fa-arrow-left me-1"></i>Back</a>
        <?php if (hasRole('admin')): ?>
        <a href="delete.php?id=<?= $id ?>" class="btn btn-sm btn-danger"
           onclick="return confirm('Permanently delete invoice <?= e($inv['invoice_number']) ?>? This cannot be undone.')">
            <i class="fa fa-trash me-1"></i>Delete
        </a>
        <?php endif; ?>
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
        <?php if($inv['status']!=='paid' && $inv['status']!=='cancelled' && (canWrite('payments') || canWrite('invoices'))): ?>
        <div class="card"><div class="card-header"><i class="fa fa-money-bill-wave me-2"></i>Record Payment</div><div class="card-body">
            <form method="POST">
                <div class="mb-2"><label class="form-label small">Amount</label><input type="number" name="payment_amount" class="form-control" min="0.01" step="0.01" max="<?= $inv['total']-$inv['amount_paid'] ?>" value="<?= $inv['total']-$inv['amount_paid'] ?>" required></div>
                <button type="submit" class="btn btn-success w-100"><i class="fa fa-check me-1"></i>Record Manual Payment</button>
            </form>
            <?php if(getSetting('mpesa_consumer_key','')): ?>
            <hr class="my-3">
            <div class="mb-1"><small class="text-muted fw-semibold"><i class="fa fa-mobile-screen-button text-success me-1"></i>M-Pesa STK Push</small></div>
            <div class="input-group mb-2">
                <span class="input-group-text"><i class="fa fa-phone"></i></span>
                <input type="tel" id="mpesa_phone" class="form-control" placeholder="2547XXXXXXXX" value="<?= e($inv['customer_phone']??'') ?>">
            </div>
            <button type="button" class="btn btn-outline-success w-100" id="mpesaPushBtn" onclick="sendMpesaPush(<?= $id ?>,<?= $inv['total']-$inv['amount_paid'] ?>)">
                <i class="fa fa-paper-plane me-1"></i>Request M-Pesa Payment
            </button>
            <div id="mpesaStatus" class="mt-2 small"></div>
            <?php endif; ?>
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

<!-- Email Modal -->
<div class="modal fade" id="emailModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="POST">
        <div class="modal-header"><h5 class="modal-title"><i class="fa fa-envelope me-2"></i>Send Invoice by Email</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label">Recipient Name</label>
            <input type="text" name="to_name" class="form-control" value="<?= e($inv['customer_name']??'') ?>" placeholder="Customer name" required>
          </div>
          <div class="mb-3">
            <label class="form-label">Email Address</label>
            <input type="email" name="to_email" class="form-control" value="<?= e($inv['customer_email']??'') ?>" placeholder="customer@example.com" required>
          </div>
          <div class="alert alert-info small mb-0"><i class="fa fa-info-circle me-1"></i>The full invoice with line items and payment summary will be included in the email.</div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" name="send_email" value="1" class="btn btn-primary"><i class="fa fa-paper-plane me-1"></i>Send</button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php if(getSetting('mpesa_consumer_key','')): ?>
<script>
function sendMpesaPush(invoiceId, amount) {
    var phone = document.getElementById('mpesa_phone').value.trim();
    var status = document.getElementById('mpesaStatus');
    var btn = document.getElementById('mpesaPushBtn');
    if (!phone) { status.innerHTML = '<span class="text-danger">Please enter a phone number.</span>'; return; }
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Sending...';
    status.innerHTML = '';
    fetch('<?= BASE_URL ?>/modules/payments/mpesa_push.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'invoice_id=' + invoiceId + '&phone=' + encodeURIComponent(phone) + '&amount=' + amount
    })
    .then(r => r.json())
    .then(data => {
        btn.disabled = false;
        btn.innerHTML = '<i class="fa fa-paper-plane me-1"></i>Request M-Pesa Payment';
        if (data.success) {
            status.innerHTML = '<span class="text-success"><i class="fa fa-check-circle me-1"></i>' + data.message + '</span>';
        } else {
            status.innerHTML = '<span class="text-danger"><i class="fa fa-exclamation-circle me-1"></i>' + data.error + '</span>';
        }
    })
    .catch(() => {
        btn.disabled = false;
        btn.innerHTML = '<i class="fa fa-paper-plane me-1"></i>Request M-Pesa Payment';
        status.innerHTML = '<span class="text-danger">Network error. Please try again.</span>';
    });
}
</script>
<?php endif; ?>
<?php include __DIR__ . '/../../includes/footer.php'; ?>

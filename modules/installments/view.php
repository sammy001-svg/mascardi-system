<?php
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
canAccess('installments') || redirect(BASE_URL . '/index.php');

$pageTitle = 'Payment Plan';
$db  = getDB();
$id  = (int)($_GET['id'] ?? 0);
if (!$id) redirect(BASE_URL . '/modules/installments/index.php');

// Update overdue
$db->query("UPDATE sale_installments SET status='overdue' WHERE status='pending' AND due_date < CURDATE() AND plan_id=$id");

$plan = $db->prepare("
    SELECT p.*, cs.sale_number, cs.buyer_name, cs.buyer_phone, cs.sale_price,
           c.make, c.model, c.year, c.registration_number
    FROM sale_payment_plans p
    JOIN car_sales cs ON cs.id = p.sale_id
    JOIN cars c ON c.id = cs.car_id
    WHERE p.id = ?
");
$plan->execute([$id]); $plan = $plan->fetch();
if (!$plan) { setFlash('error','Plan not found.'); redirect(BASE_URL.'/modules/installments/index.php'); }

$pageTitle = 'Plan — ' . $plan['buyer_name'];

$installments = $db->prepare("SELECT * FROM sale_installments WHERE plan_id=? ORDER BY installment_number ASC");
$installments->execute([$id]); $installments = $installments->fetchAll();

$totalCollected = array_sum(array_column($installments,'amount_paid'));
$outstanding    = (float)$plan['balance_financed'] - $totalCollected;
$paidCount      = count(array_filter($installments, fn($i) => $i['status']==='paid'));
$overdueCount   = count(array_filter($installments, fn($i) => $i['status']==='overdue'));

// POST: record a payment
if ($_SERVER['REQUEST_METHOD'] === 'POST' && canWrite('installments')) {
    $action   = $_POST['action'] ?? '';
    $instId   = (int)($_POST['inst_id'] ?? 0);

    if ($action === 'record_payment' && $instId) {
        $amtPaid  = (float)($_POST['amount_paid'] ?? 0);
        $paidDate = $_POST['paid_date']       ?? date('Y-m-d');
        $method   = $_POST['payment_method']  ?? 'cash';
        $ref      = trim($_POST['reference']  ?? '');
        $notes    = trim($_POST['notes']      ?? '');

        $inst = $db->prepare("SELECT * FROM sale_installments WHERE id=? AND plan_id=?");
        $inst->execute([$instId, $id]); $inst = $inst->fetch();

        if ($inst && $amtPaid > 0) {
            $newPaid   = (float)$inst['amount_paid'] + $amtPaid;
            $newStatus = $newPaid >= (float)$inst['amount_due'] ? 'paid' : 'partial';
            $db->prepare("UPDATE sale_installments SET amount_paid=?, paid_date=?, payment_method=?, reference=?, notes=?, status=?, recorded_by=? WHERE id=?")
               ->execute([$newPaid, $paidDate, $method, $ref ?: null, $notes ?: null, $newStatus, authUser()['id'], $instId]);

            // Check if all instalments paid → complete plan
            $remaining = $db->prepare("SELECT COUNT(*) FROM sale_installments WHERE plan_id=? AND status NOT IN ('paid')");
            $remaining->execute([$id]);
            if ((int)$remaining->fetchColumn() === 0) {
                $db->prepare("UPDATE sale_payment_plans SET status='completed' WHERE id=?")->execute([$id]);
            }
            logActivity('update','installments',$instId,"Payment of ".money($amtPaid)." recorded for instalment #{$inst['installment_number']}");
            setFlash('success','Payment recorded.');
        }
        redirect(BASE_URL . '/modules/installments/view.php?id=' . $id);
    }

    if ($action === 'mark_defaulted') {
        $db->prepare("UPDATE sale_payment_plans SET status='defaulted' WHERE id=?")->execute([$id]);
        setFlash('success','Plan marked as defaulted.');
        redirect(BASE_URL . '/modules/installments/view.php?id=' . $id);
    }
}

$freqLabels = ['weekly'=>'Weekly','bi_weekly'=>'Bi-weekly','monthly'=>'Monthly','quarterly'=>'Quarterly'];
$methodLabels = ['cash'=>'Cash','mpesa'=>'M-Pesa','bank'=>'Bank Transfer','cheque'=>'Cheque'];

include __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div>
        <h5 class="mb-1"><i class="fa fa-calendar-check me-2 text-primary"></i>
            Payment Plan — <?= e($plan['buyer_name']) ?>
        </h5>
        <div class="text-muted small"><?= e($plan['make'].' '.$plan['model'].' '.$plan['year']) ?> ·
            <a href="<?= BASE_URL ?>/modules/sales/view.php?id=<?= $plan['sale_id'] ?>" class="text-decoration-none">
                <?= e($plan['sale_number']) ?>
            </a>
        </div>
    </div>
    <div class="d-flex gap-2">
        <?php if (canWrite('installments') && $plan['status']==='active'): ?>
        <form method="POST" class="d-inline" onsubmit="return confirm('Mark this plan as defaulted?')">
            <input type="hidden" name="action" value="mark_defaulted">
            <button class="btn btn-sm btn-outline-danger"><i class="fa fa-ban me-1"></i>Mark Defaulted</button>
        </form>
        <?php endif; ?>
        <a href="index.php" class="btn btn-sm btn-outline-secondary"><i class="fa fa-arrow-left me-1"></i>Back</a>
    </div>
</div>

<!-- Summary cards -->
<div class="row g-3 mb-4">
    <div class="col-sm-3">
        <div class="stat-card" style="border-left:4px solid #2563eb">
            <div class="stat-icon" style="background:#dbeafe;color:#2563eb"><i class="fa fa-money-bill"></i></div>
            <div class="stat-info"><div class="stat-label">Total Financed</div><div class="stat-value stat-value-sm"><?= money((float)$plan['balance_financed']) ?></div></div>
        </div>
    </div>
    <div class="col-sm-3">
        <div class="stat-card" style="border-left:4px solid #16a34a">
            <div class="stat-icon" style="background:#dcfce7;color:#16a34a"><i class="fa fa-circle-check"></i></div>
            <div class="stat-info"><div class="stat-label">Collected</div><div class="stat-value stat-value-sm"><?= money($totalCollected) ?></div></div>
        </div>
    </div>
    <div class="col-sm-3">
        <div class="stat-card" style="border-left:4px solid <?= $outstanding > 0 ? '#dc2626' : '#16a34a' ?>">
            <div class="stat-icon" style="background:<?= $outstanding > 0 ? '#fee2e2' : '#dcfce7' ?>;color:<?= $outstanding > 0 ? '#dc2626' : '#16a34a' ?>"><i class="fa fa-scale-balanced"></i></div>
            <div class="stat-info"><div class="stat-label">Outstanding</div><div class="stat-value stat-value-sm <?= $outstanding > 0 ? 'text-danger' : 'text-success' ?>"><?= money($outstanding) ?></div></div>
        </div>
    </div>
    <div class="col-sm-3">
        <div class="stat-card" style="border-left:4px solid <?= $overdueCount > 0 ? '#f59e0b' : '#16a34a' ?>">
            <div class="stat-icon" style="background:<?= $overdueCount > 0 ? '#fef3c7' : '#dcfce7' ?>;color:<?= $overdueCount > 0 ? '#d97706' : '#16a34a' ?>"><i class="fa fa-hourglass-half"></i></div>
            <div class="stat-info"><div class="stat-label">Overdue Instalments</div><div class="stat-value"><?= $overdueCount ?></div></div>
        </div>
    </div>
</div>

<div class="row g-4">
    <!-- Plan info + Record Payment -->
    <div class="col-lg-4">
        <div class="card mb-3">
            <div class="card-header fw-semibold"><i class="fa fa-circle-info me-2"></i>Plan Details</div>
            <div class="card-body" style="font-size:13.5px">
                <dl class="row mb-0">
                    <dt class="col-6 text-muted">Frequency</dt><dd class="col-6"><?= e($freqLabels[$plan['frequency']] ?? $plan['frequency']) ?></dd>
                    <dt class="col-6 text-muted">Instalments</dt><dd class="col-6"><?= $paidCount ?> / <?= $plan['total_installments'] ?> paid</dd>
                    <dt class="col-6 text-muted">Instalment Amt</dt><dd class="col-6 fw-semibold"><?= money((float)$plan['installment_amount']) ?></dd>
                    <dt class="col-6 text-muted">Start Date</dt><dd class="col-6"><?= fmtDate($plan['start_date']) ?></dd>
                    <dt class="col-6 text-muted">End Date</dt><dd class="col-6"><?= fmtDate($plan['end_date']) ?></dd>
                    <dt class="col-6 text-muted">Status</dt><dd class="col-6"><?= statusBadge($plan['status']) ?></dd>
                </dl>
                <?php if ($plan['notes']): ?>
                <hr><p class="small text-muted mb-0"><?= nl2br(e($plan['notes'])) ?></p>
                <?php endif; ?>
            </div>
        </div>

        <?php if (canWrite('installments') && $plan['status']==='active'): ?>
        <div class="card">
            <div class="card-header fw-semibold"><i class="fa fa-plus me-2 text-success"></i>Record Payment</div>
            <div class="card-body">
                <form method="POST">
                    <input type="hidden" name="action" value="record_payment">
                    <div class="mb-2">
                        <label class="form-label small fw-semibold">Instalment # <span class="text-danger">*</span></label>
                        <select name="inst_id" class="form-select form-select-sm" required>
                            <option value="">— Select —</option>
                            <?php foreach ($installments as $inst):
                                if ($inst['status'] === 'paid') continue;
                            ?>
                            <option value="<?= $inst['id'] ?>">
                                #<?= $inst['installment_number'] ?> — <?= fmtDate($inst['due_date'],'d M Y') ?>
                                — <?= money((float)$inst['amount_due']) ?>
                                <?= $inst['status']==='overdue' ? ' [OVERDUE]' : '' ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-2">
                        <label class="form-label small fw-semibold">Amount Paid (KES) <span class="text-danger">*</span></label>
                        <input type="number" name="amount_paid" class="form-control form-control-sm" min="0.01" step="0.01" required>
                    </div>
                    <div class="row g-2 mb-2">
                        <div class="col-6">
                            <label class="form-label small fw-semibold">Date</label>
                            <input type="date" name="paid_date" class="form-control form-control-sm" value="<?= date('Y-m-d') ?>">
                        </div>
                        <div class="col-6">
                            <label class="form-label small fw-semibold">Method</label>
                            <select name="payment_method" class="form-select form-select-sm">
                                <?php foreach ($methodLabels as $k=>$l): ?>
                                <option value="<?= $k ?>"><?= $l ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="mb-2">
                        <label class="form-label small fw-semibold">Reference / Receipt #</label>
                        <input type="text" name="reference" class="form-control form-control-sm" placeholder="M-Pesa code, bank ref…">
                    </div>
                    <div class="mb-2">
                        <label class="form-label small fw-semibold">Notes</label>
                        <textarea name="notes" class="form-control form-control-sm" rows="1"></textarea>
                    </div>
                    <button class="btn btn-sm btn-success w-100"><i class="fa fa-check me-1"></i>Record Payment</button>
                </form>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Instalment schedule -->
    <div class="col-lg-8">
        <div class="card">
            <div class="card-header fw-semibold d-flex justify-content-between">
                <span><i class="fa fa-table me-2"></i>Instalment Schedule</span>
                <div class="text-muted small">
                    <?= $paidCount ?>/<?= count($installments) ?> paid ·
                    <?= $overdueCount ?> overdue
                </div>
            </div>
            <div class="card-body p-0">
                <table class="table table-hover mb-0" style="font-size:13.5px">
                    <thead>
                        <tr>
                            <th class="ps-3">#</th>
                            <th>Due Date</th>
                            <th class="text-end">Amount Due</th>
                            <th class="text-end">Amount Paid</th>
                            <th>Status</th>
                            <th>Method</th>
                            <th>Reference</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($installments as $inst):
                        $rowClass = match($inst['status']) {
                            'overdue'  => 'table-danger',
                            'partial'  => 'table-warning',
                            'paid'     => 'table-success',
                            default    => '',
                        };
                    ?>
                    <tr class="<?= $rowClass ?>">
                        <td class="ps-3 fw-semibold"><?= $inst['installment_number'] ?></td>
                        <td><?= fmtDate($inst['due_date'],'d M Y') ?></td>
                        <td class="text-end fw-medium"><?= money((float)$inst['amount_due']) ?></td>
                        <td class="text-end">
                            <?php if ((float)$inst['amount_paid'] > 0): ?>
                            <span class="text-success fw-semibold"><?= money((float)$inst['amount_paid']) ?></span>
                            <?php else: ?>
                            <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td><?= statusBadge($inst['status']) ?></td>
                        <td class="small text-muted"><?= e($methodLabels[$inst['payment_method']] ?? ($inst['payment_method'] ?? '—')) ?></td>
                        <td class="small"><code><?= e($inst['reference'] ?? '—') ?></code></td>
                    </tr>
                    <?php endforeach; ?>
                    <tr class="table-dark">
                        <td colspan="2" class="ps-3 fw-bold">TOTALS</td>
                        <td class="text-end fw-bold"><?= money((float)$plan['balance_financed']) ?></td>
                        <td class="text-end fw-bold text-success"><?= money($totalCollected) ?></td>
                        <td colspan="3" class="text-muted small ps-2">
                            Outstanding: <strong class="<?= $outstanding > 0 ? 'text-danger' : 'text-success' ?>"><?= money($outstanding) ?></strong>
                        </td>
                    </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>

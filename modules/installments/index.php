<?php
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
canAccess('installments') || redirect(BASE_URL . '/index.php');

$pageTitle = 'Payment Plans';
$db = getDB();

$filterStatus = $_GET['status'] ?? '';

try {
    $where  = $filterStatus ? "WHERE p.status = '{$db->quote($filterStatus)}'" : '';

    $plans = $db->query("
        SELECT p.*,
               cs.sale_number, cs.buyer_name, cs.buyer_phone, cs.sale_price,
               c.make, c.model, c.year, c.registration_number,
               COUNT(i.id)                                      AS total_inst,
               SUM(i.status = 'paid')                           AS paid_inst,
               SUM(i.status = 'overdue')                        AS overdue_inst,
               COALESCE(SUM(i.amount_paid), 0)                  AS total_collected,
               MIN(CASE WHEN i.status IN ('pending','overdue') THEN i.due_date END) AS next_due
        FROM sale_payment_plans p
        JOIN car_sales cs ON cs.id = p.sale_id
        JOIN cars c ON c.id = cs.car_id
        LEFT JOIN sale_installments i ON i.plan_id = p.id
        " . ($filterStatus ? "WHERE p.status = " . $db->quote($filterStatus) : '') . "
        GROUP BY p.id
        ORDER BY p.created_at DESC
    ")->fetchAll();

    // Update overdue status
    $db->query("
        UPDATE sale_installments
        SET status = 'overdue'
        WHERE status = 'pending' AND due_date < CURDATE()
    ");

    $summary = $db->query("
        SELECT
            SUM(p.status = 'active')                                AS active_plans,
            SUM(p.status = 'completed')                             AS completed_plans,
            COALESCE(SUM(i.amount_paid), 0)                        AS total_collected,
            COALESCE(SUM(CASE WHEN i.status='overdue' THEN i.amount_due - i.amount_paid ELSE 0 END), 0) AS overdue_balance
        FROM sale_payment_plans p
        LEFT JOIN sale_installments i ON i.plan_id = p.id
    ")->fetch();

} catch (\Throwable $e) {
    $plans = [];
    $summary = ['active_plans'=>0,'completed_plans'=>0,'total_collected'=>0,'overdue_balance'=>0];
}

include __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="mb-0"><i class="fa fa-calendar-check me-2 text-primary"></i>Installment Payment Plans</h5>
    <?php if (canWrite('installments')): ?>
    <a href="create.php" class="btn btn-sm btn-primary"><i class="fa fa-plus me-1"></i>New Plan</a>
    <?php endif; ?>
</div>

<div class="row g-3 mb-4">
    <div class="col-sm-3">
        <div class="stat-card" style="border-left:4px solid #2563eb">
            <div class="stat-icon" style="background:#dbeafe;color:#2563eb"><i class="fa fa-file-invoice"></i></div>
            <div class="stat-info"><div class="stat-label">Active Plans</div><div class="stat-value"><?= (int)$summary['active_plans'] ?></div></div>
        </div>
    </div>
    <div class="col-sm-3">
        <div class="stat-card" style="border-left:4px solid #16a34a">
            <div class="stat-icon" style="background:#dcfce7;color:#16a34a"><i class="fa fa-circle-check"></i></div>
            <div class="stat-info"><div class="stat-label">Completed</div><div class="stat-value"><?= (int)$summary['completed_plans'] ?></div></div>
        </div>
    </div>
    <div class="col-sm-3">
        <div class="stat-card" style="border-left:4px solid #9333ea">
            <div class="stat-icon" style="background:#f3e8ff;color:#9333ea"><i class="fa fa-money-bill-transfer"></i></div>
            <div class="stat-info"><div class="stat-label">Total Collected</div><div class="stat-value stat-value-sm"><?= money((float)$summary['total_collected']) ?></div></div>
        </div>
    </div>
    <div class="col-sm-3">
        <div class="stat-card" style="border-left:4px solid #dc2626">
            <div class="stat-icon" style="background:#fee2e2;color:#dc2626"><i class="fa fa-triangle-exclamation"></i></div>
            <div class="stat-info"><div class="stat-label">Overdue Balance</div><div class="stat-value stat-value-sm text-danger"><?= money((float)$summary['overdue_balance']) ?></div></div>
        </div>
    </div>
</div>

<!-- Status tabs -->
<div class="d-flex gap-1 mb-3">
    <?php foreach (['' => 'All', 'active' => 'Active', 'completed' => 'Completed', 'defaulted' => 'Defaulted'] as $v => $l): ?>
    <a href="?status=<?= $v ?>" class="btn btn-sm <?= $filterStatus === $v ? 'btn-primary' : 'btn-outline-secondary' ?>"><?= $l ?></a>
    <?php endforeach; ?>
</div>

<div class="card">
    <div class="card-body p-0">
        <table class="table table-hover datatable mb-0" style="font-size:13.5px">
            <thead>
                <tr>
                    <th class="ps-3">Buyer / Vehicle</th>
                    <th>Sale</th>
                    <th class="text-end">Financed</th>
                    <th class="text-end">Collected</th>
                    <th class="text-end">Outstanding</th>
                    <th>Progress</th>
                    <th>Next Due</th>
                    <th>Status</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($plans as $p):
                $outstanding  = (float)$p['balance_financed'] - (float)$p['total_collected'];
                $pctCollected = $p['balance_financed'] > 0 ? min(100, round((float)$p['total_collected'] / (float)$p['balance_financed'] * 100)) : 0;
                $hasOverdue   = $p['overdue_inst'] > 0;
                $nextDue      = $p['next_due'];
                $nextOverdue  = $nextDue && $nextDue < date('Y-m-d');
            ?>
            <tr class="<?= $hasOverdue ? 'table-warning' : '' ?>">
                <td class="ps-3">
                    <div class="fw-semibold"><?= e($p['buyer_name']) ?></div>
                    <div class="text-muted small"><?= e($p['make'].' '.$p['model'].' '.$p['year']) ?></div>
                    <?php if ($p['buyer_phone']): ?>
                    <div class="text-muted" style="font-size:11px"><i class="fa fa-phone me-1"></i><?= e($p['buyer_phone']) ?></div>
                    <?php endif; ?>
                </td>
                <td>
                    <a href="<?= BASE_URL ?>/modules/sales/view.php?id=<?= $p['sale_id'] ?>"
                       class="text-decoration-none small fw-medium"><?= e($p['sale_number']) ?></a>
                </td>
                <td class="text-end fw-medium"><?= money((float)$p['balance_financed']) ?></td>
                <td class="text-end text-success fw-medium"><?= money((float)$p['total_collected']) ?></td>
                <td class="text-end <?= $outstanding > 0 ? 'text-danger fw-semibold' : 'text-success' ?>"><?= money($outstanding) ?></td>
                <td style="min-width:120px">
                    <div class="d-flex align-items-center gap-2">
                        <div class="progress flex-grow-1" style="height:6px">
                            <div class="progress-bar <?= $pctCollected >= 100 ? 'bg-success' : 'bg-primary' ?>"
                                 style="width:<?= $pctCollected ?>%"></div>
                        </div>
                        <span class="text-muted small" style="min-width:34px"><?= $pctCollected ?>%</span>
                    </div>
                    <div class="text-muted" style="font-size:10px"><?= (int)$p['paid_inst'] ?>/<?= (int)$p['total_inst'] ?> instalments</div>
                </td>
                <td>
                    <?php if ($nextDue): ?>
                    <span class="badge <?= $nextOverdue ? 'bg-danger' : ($nextDue === date('Y-m-d') ? 'bg-warning text-dark' : 'bg-light text-dark border') ?>"
                          style="font-size:11px">
                        <?= fmtDate($nextDue,'d M Y') ?>
                    </span>
                    <?php else: ?>
                    <span class="text-muted small">—</span>
                    <?php endif; ?>
                </td>
                <td><?= statusBadge($p['status']) ?></td>
                <td class="pe-3">
                    <a href="view.php?id=<?= $p['id'] ?>" class="btn btn-xs btn-outline-primary">View</a>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>

<?php
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
canAccess('payroll') || redirect(BASE_URL . '/index.php');

$pageTitle = 'Payroll';
$db = getDB();

try {
    $runs = $db->query("
        SELECT pr.*, u.name AS created_by_name, a.name AS approved_by_name,
               COUNT(pi.id) AS staff_count
        FROM payroll_runs pr
        LEFT JOIN users u ON u.id = pr.created_by
        LEFT JOIN users a ON a.id = pr.approved_by
        LEFT JOIN payroll_items pi ON pi.run_id = pr.id
        GROUP BY pr.id
        ORDER BY pr.period_year DESC, pr.period_month DESC
    ")->fetchAll();

    $summary = $db->query("
        SELECT
            COUNT(*)                          AS total_runs,
            SUM(status = 'paid')              AS paid_runs,
            COALESCE(SUM(total_net), 0)       AS total_paid_net,
            COALESCE(SUM(CASE WHEN status='paid' AND period_year=YEAR(NOW()) THEN total_net END), 0) AS ytd_net
        FROM payroll_runs
    ")->fetch();

    $unsetStaff = $db->query("
        SELECT COUNT(*) FROM (
            SELECT m.id FROM mechanics m WHERE m.status='active' AND NOT EXISTS (SELECT 1 FROM staff_salaries ss WHERE ss.staff_type='mechanic' AND ss.staff_id=m.id)
            UNION
            SELECT d.id FROM drivers d WHERE d.status='active' AND NOT EXISTS (SELECT 1 FROM staff_salaries ss WHERE ss.staff_type='driver' AND ss.staff_id=d.id)
        ) t
    ")->fetchColumn();
} catch (\Throwable $e) { $runs = []; $summary = null; $unsetStaff = 0; }

$months = ['','January','February','March','April','May','June','July','August','September','October','November','December'];
$statusColors = ['draft'=>'secondary','approved'=>'primary','paid'=>'success'];

include __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="mb-0"><i class="fa fa-money-bill-wave me-2 text-success"></i>Payroll</h5>
    <div class="d-flex gap-2">
        <a href="staff.php" class="btn btn-sm btn-outline-secondary"><i class="fa fa-users-gear me-1"></i>Staff Salaries</a>
        <?php if (canWrite('payroll')): ?>
        <a href="create.php" class="btn btn-sm btn-success"><i class="fa fa-plus me-1"></i>New Payroll Run</a>
        <?php endif; ?>
    </div>
</div>

<?php if ($unsetStaff > 0): ?>
<div class="alert alert-warning py-2 small">
    <i class="fa fa-triangle-exclamation me-1"></i>
    <strong><?= (int)$unsetStaff ?> active staff</strong> don't have salary profiles set.
    <a href="staff.php" class="ms-1">Set up salaries →</a>
</div>
<?php endif; ?>

<?php if ($summary): ?>
<div class="row g-3 mb-4">
    <div class="col-sm-3">
        <div class="stat-card" style="border-left:4px solid #2563eb">
            <div class="stat-icon" style="background:#dbeafe;color:#2563eb"><i class="fa fa-file-invoice"></i></div>
            <div class="stat-info"><div class="stat-label">Total Runs</div><div class="stat-value"><?= (int)$summary['total_runs'] ?></div></div>
        </div>
    </div>
    <div class="col-sm-3">
        <div class="stat-card" style="border-left:4px solid #16a34a">
            <div class="stat-icon" style="background:#dcfce7;color:#16a34a"><i class="fa fa-circle-check"></i></div>
            <div class="stat-info"><div class="stat-label">Paid Runs</div><div class="stat-value"><?= (int)$summary['paid_runs'] ?></div></div>
        </div>
    </div>
    <div class="col-sm-3">
        <div class="stat-card" style="border-left:4px solid #9333ea">
            <div class="stat-icon" style="background:#f3e8ff;color:#9333ea"><i class="fa fa-chart-line"></i></div>
            <div class="stat-info"><div class="stat-label">YTD Net Payroll</div><div class="stat-value stat-value-sm"><?= money((float)$summary['ytd_net']) ?></div></div>
        </div>
    </div>
    <div class="col-sm-3">
        <div class="stat-card" style="border-left:4px solid #dc2626">
            <div class="stat-icon" style="background:#fee2e2;color:#dc2626"><i class="fa fa-money-bill-transfer"></i></div>
            <div class="stat-info"><div class="stat-label">Total Net Paid</div><div class="stat-value stat-value-sm"><?= money((float)$summary['total_paid_net']) ?></div></div>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="card">
    <div class="card-body p-0">
        <table class="table table-hover datatable mb-0" style="font-size:13.5px">
            <thead>
                <tr>
                    <th class="ps-3">Run #</th>
                    <th>Period</th>
                    <th class="text-center">Staff</th>
                    <th class="text-end">Gross</th>
                    <th class="text-end">Deductions</th>
                    <th class="text-end">Net Pay</th>
                    <th>Status</th>
                    <th>Created By</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($runs as $r): ?>
            <tr>
                <td class="ps-3 fw-semibold"><?= e($r['run_number']) ?></td>
                <td class="fw-medium"><?= $months[(int)$r['period_month']] ?> <?= $r['period_year'] ?></td>
                <td class="text-center"><span class="badge bg-light text-dark border"><?= $r['staff_count'] ?></span></td>
                <td class="text-end"><?= money((float)$r['total_gross']) ?></td>
                <td class="text-end text-danger"><?= money((float)$r['total_deductions']) ?></td>
                <td class="text-end fw-bold text-success"><?= money((float)$r['total_net']) ?></td>
                <td><span class="badge bg-<?= $statusColors[$r['status']] ?? 'secondary' ?>"><?= ucfirst($r['status']) ?></span></td>
                <td class="small text-muted"><?= e($r['created_by_name'] ?? '—') ?></td>
                <td class="pe-3">
                    <a href="run.php?id=<?= $r['id'] ?>" class="btn btn-xs btn-outline-primary">View</a>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>

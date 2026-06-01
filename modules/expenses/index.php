<?php
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
canAccess('expenses') || redirect(BASE_URL . '/index.php');

$pageTitle = 'Expenses';
$db = getDB();

$filterCat    = $_GET['cat']    ?? '';
$filterPeriod = $_GET['period'] ?? 'this_month';
$search       = trim($_GET['q'] ?? '');

switch ($filterPeriod) {
    case 'last_month':
        $dateFrom = date('Y-m-01', strtotime('first day of last month'));
        $dateTo   = date('Y-m-t',  strtotime('last day of last month'));
        $label    = 'Last Month';
        break;
    case 'this_year':
        $dateFrom = date('Y-01-01');
        $dateTo   = date('Y-12-31');
        $label    = 'This Year';
        break;
    case 'all':
        $dateFrom = '2000-01-01';
        $dateTo   = '2099-12-31';
        $label    = 'All Time';
        break;
    default: // this_month
        $dateFrom = date('Y-m-01');
        $dateTo   = date('Y-m-d');
        $label    = 'This Month (' . date('M Y') . ')';
        break;
}

$categories = [
    'salaries'    => ['label' => 'Salaries & Wages',     'icon' => 'fa-users',              'color' => '#2563eb'],
    'rent'        => ['label' => 'Rent & Premises',      'icon' => 'fa-building',            'color' => '#7c3aed'],
    'fuel'        => ['label' => 'Fuel & Transport',     'icon' => 'fa-gas-pump',            'color' => '#d97706'],
    'utilities'   => ['label' => 'Utilities',            'icon' => 'fa-bolt',                'color' => '#0891b2'],
    'marketing'   => ['label' => 'Marketing & Ads',      'icon' => 'fa-bullhorn',            'color' => '#ec4899'],
    'maintenance' => ['label' => 'Yard Maintenance',     'icon' => 'fa-screwdriver-wrench',  'color' => '#16a34a'],
    'office'      => ['label' => 'Office & Stationery',  'icon' => 'fa-briefcase',           'color' => '#64748b'],
    'insurance'   => ['label' => 'Insurance',            'icon' => 'fa-shield-halved',       'color' => '#0284c7'],
    'taxes'       => ['label' => 'Taxes & Levies',       'icon' => 'fa-file-invoice',        'color' => '#dc2626'],
    'other'       => ['label' => 'Other',                'icon' => 'fa-circle-dot',          'color' => '#94a3b8'],
];

$where  = ['DATE(e.expense_date) BETWEEN ? AND ?'];
$params = [$dateFrom, $dateTo];
if ($filterCat) { $where[] = 'e.category = ?'; $params[] = $filterCat; }
if ($search)    {
    $where[] = '(e.description LIKE ? OR e.vendor LIKE ? OR e.reference LIKE ?)';
    $params  = array_merge($params, ["%$search%", "%$search%", "%$search%"]);
}
$whereStr = implode(' AND ', $where);

try {
    $expenses = $db->prepare("
        SELECT e.*, u.name AS recorded_by_name
        FROM expenses e
        LEFT JOIN users u ON u.id = e.recorded_by
        WHERE $whereStr
        ORDER BY e.expense_date DESC, e.id DESC
    ");
    $expenses->execute($params);
    $expenses = $expenses->fetchAll();

    // Summary
    $summaryStmt = $db->prepare("
        SELECT
            COALESCE(SUM(amount), 0)                           AS total,
            COUNT(*)                                            AS count,
            COALESCE(SUM(CASE WHEN category='salaries'    THEN amount END), 0) AS salaries,
            COALESCE(SUM(CASE WHEN category='fuel'        THEN amount END), 0) AS fuel,
            COALESCE(SUM(CASE WHEN category='rent'        THEN amount END), 0) AS rent,
            COALESCE(SUM(CASE WHEN category='utilities'   THEN amount END), 0) AS utilities
        FROM expenses
        WHERE DATE(expense_date) BETWEEN ? AND ?
    ");
    $summaryStmt->execute([$dateFrom, $dateTo]);
    $summary = $summaryStmt->fetch();

    // By category for current period
    $byCat = $db->prepare("
        SELECT category, COALESCE(SUM(amount),0) AS total
        FROM expenses WHERE DATE(expense_date) BETWEEN ? AND ?
        GROUP BY category ORDER BY total DESC
    ");
    $byCat->execute([$dateFrom, $dateTo]);
    $byCat = $byCat->fetchAll();

} catch (\Throwable $e) {
    $expenses = []; $summary = ['total'=>0,'count'=>0]; $byCat = [];
}

include __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="mb-0"><i class="fa fa-receipt me-2 text-danger"></i>Expenses</h5>
    <?php if (canWrite('expenses')): ?>
    <a href="add.php" class="btn btn-sm btn-danger"><i class="fa fa-plus me-1"></i>Record Expense</a>
    <?php endif; ?>
</div>

<!-- Period + filter bar -->
<div class="card mb-3">
    <div class="card-body py-2">
        <form method="GET" class="d-flex flex-wrap gap-2 align-items-center">
            <select name="period" class="form-select form-select-sm" style="width:160px" onchange="this.form.submit()">
                <option value="this_month" <?= $filterPeriod==='this_month'?'selected':'' ?>>This Month</option>
                <option value="last_month" <?= $filterPeriod==='last_month'?'selected':'' ?>>Last Month</option>
                <option value="this_year"  <?= $filterPeriod==='this_year' ?'selected':'' ?>>This Year</option>
                <option value="all"        <?= $filterPeriod==='all'       ?'selected':'' ?>>All Time</option>
            </select>
            <select name="cat" class="form-select form-select-sm" style="width:180px">
                <option value="">All Categories</option>
                <?php foreach ($categories as $k => $c): ?>
                <option value="<?= $k ?>" <?= $filterCat===$k?'selected':'' ?>><?= $c['label'] ?></option>
                <?php endforeach; ?>
            </select>
            <input type="text" name="q" class="form-control form-control-sm" style="width:200px"
                   placeholder="Search description, vendor…" value="<?= e($search) ?>">
            <button class="btn btn-sm btn-primary"><i class="fa fa-filter me-1"></i>Filter</button>
            <a href="index.php" class="btn btn-sm btn-outline-secondary">Clear</a>
            <span class="badge bg-light text-dark border px-3 py-2 ms-auto"><?= e($label) ?></span>
        </form>
    </div>
</div>

<!-- Summary row -->
<div class="row g-3 mb-4">
    <div class="col-sm-6 col-xl-3">
        <div class="stat-card" style="border-left:4px solid #dc2626">
            <div class="stat-icon" style="background:#fee2e2;color:#dc2626"><i class="fa fa-money-bill-wave"></i></div>
            <div class="stat-info">
                <div class="stat-label">Total Expenses</div>
                <div class="stat-value stat-value-sm"><?= money((float)$summary['total']) ?></div>
                <div class="text-muted" style="font-size:11px"><?= (int)$summary['count'] ?> records</div>
            </div>
        </div>
    </div>
    <?php
    $topCats = array_slice($byCat, 0, 3);
    foreach ($topCats as $tc):
        $ci = $categories[$tc['category']] ?? ['label'=>ucfirst($tc['category']),'color'=>'#64748b','icon'=>'fa-circle-dot'];
    ?>
    <div class="col-sm-6 col-xl-3">
        <div class="stat-card" style="border-left:4px solid <?= $ci['color'] ?>">
            <div class="stat-icon" style="background:<?= $ci['color'] ?>18;color:<?= $ci['color'] ?>"><i class="fa <?= $ci['icon'] ?>"></i></div>
            <div class="stat-info">
                <div class="stat-label"><?= $ci['label'] ?></div>
                <div class="stat-value stat-value-sm"><?= money((float)$tc['total']) ?></div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<!-- Expenses table -->
<div class="card">
    <div class="card-body p-0">
        <table class="table table-hover datatable mb-0" style="font-size:13.5px">
            <thead>
                <tr>
                    <th class="ps-3">Date</th>
                    <th>Category</th>
                    <th>Description</th>
                    <th>Vendor</th>
                    <th>Method</th>
                    <th>Reference</th>
                    <th class="text-end">Amount</th>
                    <th>Recorded By</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($expenses as $exp):
                $ci = $categories[$exp['category']] ?? ['label'=>ucfirst($exp['category']),'color'=>'#64748b','icon'=>'fa-circle-dot'];
            ?>
            <tr>
                <td class="ps-3 text-muted small"><?= fmtDate($exp['expense_date'],'d M Y') ?></td>
                <td>
                    <span class="badge" style="background:<?= $ci['color'] ?>22;color:<?= $ci['color'] ?>;border:1px solid <?= $ci['color'] ?>44;font-size:11px">
                        <i class="fa <?= $ci['icon'] ?> me-1"></i><?= $ci['label'] ?>
                    </span>
                </td>
                <td class="fw-medium"><?= e($exp['description']) ?></td>
                <td class="small text-muted"><?= e($exp['vendor'] ?: '—') ?></td>
                <td><span class="badge bg-light text-dark border" style="font-size:11px"><?= ucfirst($exp['payment_method']) ?></span></td>
                <td><code style="font-size:11px"><?= e($exp['reference'] ?: '—') ?></code></td>
                <td class="text-end fw-semibold text-danger"><?= money((float)$exp['amount']) ?></td>
                <td class="small text-muted"><?= e($exp['recorded_by_name'] ?? '—') ?></td>
                <td class="pe-3">
                    <div class="d-flex gap-1">
                        <?php if ($exp['receipt_file']): ?>
                        <a href="<?= BASE_URL ?>/uploads/receipts/<?= e(basename($exp['receipt_file'])) ?>"
                           target="_blank" class="btn btn-xs btn-outline-secondary" title="View Receipt">
                            <i class="fa fa-receipt"></i>
                        </a>
                        <?php endif; ?>
                        <?php if (canWrite('expenses')): ?>
                        <a href="edit.php?id=<?= $exp['id'] ?>" class="btn btn-xs btn-outline-secondary"><i class="fa fa-pen"></i></a>
                        <?php endif; ?>
                        <?php if (canEditDelete()): ?>
                        <form method="POST" action="delete.php" class="d-inline"
                              onsubmit="return confirm('Delete this expense?')">
                            <input type="hidden" name="id" value="<?= $exp['id'] ?>">
                            <button class="btn btn-xs btn-outline-danger"><i class="fa fa-trash"></i></button>
                        </form>
                        <?php endif; ?>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php if (!empty($expenses)): ?>
    <div class="card-footer bg-white d-flex justify-content-end align-items-center gap-3 py-2 px-4">
        <span class="text-muted small"><?= count($expenses) ?> record<?= count($expenses) !== 1 ? 's' : '' ?> in period</span>
        <span class="fw-bold text-danger fs-6"><?= money((float)$summary['total']) ?></span>
    </div>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>

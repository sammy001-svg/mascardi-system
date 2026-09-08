<?php
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
hasRole(['super_admin','admin','general_manager']) || die('Access denied. GM / Admin only.');
$pageTitle = 'Executive Business Digest';
$db = getDB();
require_once __DIR__ . '/../../includes/mailer.php';

$sent  = false;
$error = '';

// ── Period Switcher ───────────────────────────────────────────────────────────
$period = $_GET['period'] ?? 'this_month';
switch ($period) {
    case 'this_week':
        $dateFrom = date('Y-m-d', strtotime('monday this week'));
        $dateTo   = date('Y-m-d');
        $label    = 'This Week (' . date('d M', strtotime($dateFrom)) . ' – ' . date('d M', strtotime($dateTo)) . ')';
        break;
    case 'last_month':
        $dateFrom = date('Y-m-01', strtotime('first day of last month'));
        $dateTo   = date('Y-m-t',  strtotime('last day of last month'));
        $label    = 'Last Month (' . date('M Y', strtotime('last month')) . ')';
        break;
    case 'last_3_months':
        $dateFrom = date('Y-m-01', strtotime('-2 months'));
        $dateTo   = date('Y-m-d');
        $label    = 'Last 3 Months';
        break;
    case 'last_6_months':
        $dateFrom = date('Y-m-01', strtotime('-5 months'));
        $dateTo   = date('Y-m-d');
        $label    = 'Last 6 Months';
        break;
    case 'this_year':
        $dateFrom = date('Y-01-01');
        $dateTo   = date('Y-12-31');
        $label    = 'This Year (' . date('Y') . ')';
        break;
    case 'custom':
        $dateFrom = $_GET['date_from'] ?? date('Y-m-01');
        $dateTo   = $_GET['date_to']   ?? date('Y-m-d');
        $label    = fmtDate($dateFrom) . ' – ' . fmtDate($dateTo);
        break;
    default: // this_month
        $dateFrom = date('Y-m-01');
        $dateTo   = date('Y-m-d');
        $label    = 'This Month (' . date('M Y') . ')';
}

$daysDiff = max(1, (int)round((strtotime($dateTo) - strtotime($dateFrom)) / 86400));
$prevFrom = date('Y-m-d', strtotime("$dateFrom -$daysDiff days"));
$prevTo   = date('Y-m-d', strtotime("$dateFrom -1 day"));

// ── Gather Data ───────────────────────────────────────────────────────────────

// 1. Vehicle Sales & Profitability
$salesRow = $db->prepare("
    SELECT
        COUNT(*) AS units,
        COALESCE(SUM(cs.sale_price),0) AS revenue,
        COALESCE(SUM(cc.purchase_price + cc.freight + cc.marine_insurance + cc.port_charges + cc.duty_tax + cc.clearing_fees + cc.transport_to_yard + cc.workshop_costs + cc.other_costs), 0) AS cogs,
        COALESCE(SUM(cs.sale_price - (cc.purchase_price + cc.freight + cc.marine_insurance + cc.port_charges + cc.duty_tax + cc.clearing_fees + cc.transport_to_yard + cc.workshop_costs + cc.other_costs)), 0) AS profit
    FROM car_sales cs
    LEFT JOIN car_costs cc ON cc.car_id = cs.car_id
    WHERE cs.status='active' AND DATE(cs.sale_date) BETWEEN ? AND ?
");
$salesRow->execute([$dateFrom, $dateTo]);
$sales = $salesRow->fetch(PDO::FETCH_ASSOC);

$avgMargin = (float)$sales['revenue'] > 0 ? round((float)$sales['profit'] / (float)$sales['revenue'] * 100, 1) : 0;

// 2. Delivered Cars Summary
$deliveredRow = $db->prepare("
    SELECT COUNT(*) AS units, COALESCE(SUM(sale_price),0) AS revenue
    FROM car_sales
    WHERE status='active' AND delivered_at IS NOT NULL AND DATE(delivered_at) BETWEEN ? AND ?
");
$deliveredRow->execute([$dateFrom, $dateTo]);
$deliveredSales = $deliveredRow->fetch(PDO::FETCH_ASSOC);

// 3. Comparison Sales (prev period)
$prevRow = $db->prepare("SELECT COUNT(*) AS units, COALESCE(SUM(sale_price),0) AS revenue FROM car_sales WHERE status='active' AND DATE(sale_date) BETWEEN ? AND ?");
$prevRow->execute([$prevFrom, $prevTo]);
$prevSales = $prevRow->fetch(PDO::FETCH_ASSOC);

$revenueChange = (float)$prevSales['revenue'] > 0
    ? round(((float)$sales['revenue'] - (float)$prevSales['revenue']) / (float)$prevSales['revenue'] * 100, 1)
    : null;

// 4. CRM Pipeline
try {
    $crmRow = $db->prepare("
        SELECT
            SUM(stage NOT IN ('closed_won','closed_lost')) AS open_leads,
            SUM(stage NOT IN ('closed_won','closed_lost') AND follow_up_date < CURDATE()) AS overdue_followups,
            SUM(DATE(created_at) BETWEEN ? AND ?) AS new_in_period,
            SUM(stage='closed_won' AND DATE(created_at) BETWEEN ? AND ?) AS won_in_period
        FROM crm_leads
    ");
    $crmRow->execute([$dateFrom, $dateTo, $dateFrom, $dateTo]);
    $crmRow = $crmRow->fetch(PDO::FETCH_ASSOC);
} catch (\Throwable $_) {
    $crmRow = ['open_leads' => 0, 'overdue_followups' => 0, 'new_in_period' => 0, 'won_in_period' => 0];
}

// 5. Workshop Performance
try {
    $wsRow = $db->prepare("
        SELECT
            COUNT(*) AS total_jobs,
            SUM(status='completed') AS completed_jobs,
            SUM(status IN ('in_progress','waiting_parts')) AS active_jobs
        FROM workshop_jobs
        WHERE DATE(created_at) BETWEEN ? AND ? OR (status='completed' AND DATE(updated_at) BETWEEN ? AND ?)
    ");
    $wsRow->execute([$dateFrom, $dateTo, $dateFrom, $dateTo]);
    $wsData = $wsRow->fetch(PDO::FETCH_ASSOC);
} catch (\Throwable $_) {
    $wsData = ['total_jobs' => 0, 'completed_jobs' => 0, 'active_jobs' => 0];
}

// 6. Inventory Valuation & Aging
$invRow = $db->query("
    SELECT
        COUNT(*) AS total_stock,
        SUM(DATEDIFF(NOW(), created_at) > 90) AS slow_movers,
        COALESCE(SUM(asking_price), 0) AS stock_value
    FROM cars WHERE car_type='inventory' AND status NOT IN ('sold','cancelled','delivered')
")->fetch(PDO::FETCH_ASSOC);

// 7. Finance & Invoices
try {
    $invoiceRow = $db->prepare("
        SELECT
            COALESCE(SUM(total), 0) AS total_invoiced,
            COALESCE(SUM(CASE WHEN status='paid' THEN total END), 0) AS total_collected,
            COALESCE(SUM(CASE WHEN status NOT IN ('paid','cancelled') AND due_date < CURDATE() THEN total - amount_paid END), 0) AS overdue_balance,
            SUM(status NOT IN ('paid','cancelled') AND due_date < CURDATE()) AS overdue_cnt
        FROM invoices WHERE DATE(created_at) BETWEEN ? AND ? OR (status NOT IN ('paid','cancelled') AND due_date < CURDATE())
    ");
    $invoiceRow->execute([$dateFrom, $dateTo]);
    $invoiceRow = $invoiceRow->fetch(PDO::FETCH_ASSOC);
} catch (\Throwable $_) {
    $invoiceRow = ['total_invoiced' => 0, 'total_collected' => 0, 'overdue_balance' => 0, 'overdue_cnt' => 0];
}

// 8. Top Performers
try {
    $topSales = $db->prepare("
        SELECT u.name, COUNT(cs.id) AS units, COALESCE(SUM(cs.sale_price),0) AS revenue
        FROM car_sales cs JOIN users u ON u.id = cs.sold_by
        WHERE cs.status='active' AND DATE(cs.sale_date) BETWEEN ? AND ?
        GROUP BY u.id, u.name ORDER BY units DESC, revenue DESC LIMIT 1
    ");
    $topSales->execute([$dateFrom, $dateTo]);
    $topSales = $topSales->fetch(PDO::FETCH_ASSOC);
} catch (\Throwable $_) { $topSales = null; }

try {
    $topModel = $db->prepare("
        SELECT c.make, c.model, COUNT(*) AS units, COALESCE(SUM(cs.sale_price),0) AS revenue
        FROM car_sales cs JOIN cars c ON c.id = cs.car_id
        WHERE cs.status='active' AND DATE(cs.sale_date) BETWEEN ? AND ?
        GROUP BY c.make, c.model ORDER BY units DESC, revenue DESC LIMIT 1
    ");
    $topModel->execute([$dateFrom, $dateTo]);
    $topModel = $topModel->fetch(PDO::FETCH_ASSOC);
} catch (\Throwable $_) { $topModel = null; }

// Recipients list
$recipientRows = $db->query("
    SELECT name, email FROM users
    WHERE role IN ('super_admin','admin','general_manager')
      AND status = 'active' AND email IS NOT NULL AND email != ''
")->fetchAll(PDO::FETCH_ASSOC);

// ── Handle Send Digest Email ──────────────────────────────────────────────────
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['send_digest'])) {
    verifyCsrf();
    $co   = getSetting('company_name', 'Mascardi Car Yard');
    $numFmt = fn($n) => number_format((float)$n);

    $html = '<!DOCTYPE html><html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>'
. '<body style="margin:0;padding:0;background:#f1f5f9;font-family:Arial,sans-serif">'
. '<table width="100%" cellpadding="0" cellspacing="0" style="background:#f1f5f9;padding:32px 0"><tr><td>'
. '<table width="640" align="center" cellpadding="0" cellspacing="0" style="background:#fff;border-radius:12px;overflow:hidden;max-width:640px;box-shadow:0 4px 12px rgba(0,0,0,.08)">'

// Email Header
. '<tr><td style="background:#1e40af;padding:28px 32px;text-align:center">'
. '<div style="color:#fff;font-size:22px;font-weight:700">' . htmlspecialchars($co) . '</div>'
. '<div style="color:#bfdbfe;font-size:13.5px;margin-top:4px">Executive Business Digest — ' . htmlspecialchars($label) . '</div>'
. '</td></tr>'

// Vehicle Sales & Financial Summary
. '<tr><td style="padding:28px 32px 0">'
. '<div style="font-size:11.5px;text-transform:uppercase;letter-spacing:.06em;color:#64748b;font-weight:700;margin-bottom:12px">Vehicle Sales &amp; Financial Performance</div>'
. '<table width="100%" cellpadding="8" cellspacing="0"><tr>'
. '<td style="background:#f0fdf4;border-radius:10px;padding:14px;text-align:center">'
. '<div style="font-size:24px;font-weight:800;color:#16a34a">' . (int)$sales['units'] . '</div>'
. '<div style="font-size:11px;color:#64748b;margin-top:2px">Cars Sold</div></td>'
. '<td width="10"></td>'
. '<td style="background:#eff6ff;border-radius:10px;padding:14px;text-align:center">'
. '<div style="font-size:16px;font-weight:800;color:#1d4ed8">KES ' . $numFmt($sales['revenue']) . '</div>'
. '<div style="font-size:11px;color:#64748b;margin-top:2px">Sales Revenue</div></td>'
. '<td width="10"></td>'
. '<td style="background:#faf5ff;border-radius:10px;padding:14px;text-align:center">'
. '<div style="font-size:16px;font-weight:800;color:#7c3aed">KES ' . $numFmt($sales['profit']) . '</div>'
. '<div style="font-size:11px;color:#64748b;margin-top:2px">Gross Profit (' . $avgMargin . '%)</div></td>'
. '</tr></table>'
. '</td></tr>'

// Delivered Cars & Workshop
. '<tr><td style="padding:20px 32px 0">'
. '<div style="font-size:11.5px;text-transform:uppercase;letter-spacing:.06em;color:#64748b;font-weight:700;margin-bottom:12px">Delivered Cars &amp; Workshop Operations</div>'
. '<table width="100%" cellpadding="8" cellspacing="0"><tr>'
. '<td style="background:#f0f9ff;border-radius:10px;padding:14px;text-align:center">'
. '<div style="font-size:22px;font-weight:800;color:#0369a1">' . (int)$deliveredSales['units'] . '</div>'
. '<div style="font-size:11px;color:#64748b;margin-top:2px">Delivered Cars</div>'
. '<div style="font-size:10.5px;color:#0369a1;font-weight:600">KES ' . $numFmt($deliveredSales['revenue']) . '</div></td>'
. '<td width="10"></td>'
. '<td style="background:#fffbeb;border-radius:10px;padding:14px;text-align:center">'
. '<div style="font-size:22px;font-weight:800;color:#d97706">' . (int)$wsData['completed_jobs'] . ' / ' . (int)$wsData['total_jobs'] . '</div>'
. '<div style="font-size:11px;color:#64748b;margin-top:2px">Workshop Jobs Completed</div></td>'
. '</tr></table>'
. '</td></tr>'

// CRM & Inventory
. '<tr><td style="padding:20px 32px 0">'
. '<div style="font-size:11.5px;text-transform:uppercase;letter-spacing:.06em;color:#64748b;font-weight:700;margin-bottom:12px">CRM Pipeline &amp; Inventory Health</div>'
. '<table width="100%" cellpadding="8" cellspacing="0"><tr>'
. '<td style="background:#fdf4ff;border-radius:10px;padding:14px;text-align:center">'
. '<div style="font-size:22px;font-weight:800;color:#c026d3">' . (int)$crmRow['open_leads'] . '</div>'
. '<div style="font-size:11px;color:#64748b;margin-top:2px">Active Leads</div>'
. '<div style="font-size:10.5px;color:#dc2626">' . (int)$crmRow['overdue_followups'] . ' overdue</div></td>'
. '<td width="10"></td>'
. '<td style="background:#f0f9ff;border-radius:10px;padding:14px;text-align:center">'
. '<div style="font-size:22px;font-weight:800;color:#0284c7">' . (int)$invRow['total_stock'] . '</div>'
. '<div style="font-size:11px;color:#64748b;margin-top:2px">Stock Valuation</div>'
. '<div style="font-size:10.5px;color:#0284c7;font-weight:600">KES ' . $numFmt($invRow['stock_value']) . '</div></td>'
. '</tr></table>'
. '</td></tr>'

// Top performer spotlight
. ($topSales ? (
    '<tr><td style="padding:20px 32px 0">'
    . '<div style="font-size:11.5px;text-transform:uppercase;letter-spacing:.06em;color:#64748b;font-weight:700;margin-bottom:10px">Top Sales Representative</div>'
    . '<div style="background:#fefce8;border:1px solid #fef08a;border-radius:10px;padding:14px;display:flex;align-items:center">'
    . '<span style="font-size:24px;margin-right:12px">🏆</span>'
    . '<div><div style="font-weight:700;font-size:15px;color:#854d0e">' . htmlspecialchars($topSales['name']) . '</div>'
    . '<div style="font-size:12px;color:#a16207">' . (int)$topSales['units'] . ' unit(s) sold — KES ' . $numFmt($topSales['revenue']) . ' revenue</div></div>'
    . '</div></td></tr>'
) : '')

// Email Footer
. '<tr><td style="padding:28px 32px;border-top:1px solid #f1f5f9;margin-top:24px">'
. '<div style="font-size:11.5px;color:#94a3b8;text-align:center">'
. 'Generated by ' . htmlspecialchars($co) . ' Management System on ' . date('d M Y H:i') . '<br>'
. '<a href="' . BASE_URL . '/modules/reports/weekly_digest.php" style="color:#2563eb;text-decoration:none;font-weight:600">Open Full Executive Dashboard →</a>'
. '</div></td></tr>'

. '</table></td></tr></table>'
. '</body></html>';

    $subject   = $co . ' — Executive Digest (' . $label . ')';
    $sendCount = 0;
    $errors    = [];
    foreach ($recipientRows as $rec) {
        $result = sendMail($rec['email'], $rec['name'], $subject, $html, 'weekly_digest', 0);
        if ($result['success']) $sendCount++;
        else $errors[] = $rec['email'] . ': ' . ($result['error'] ?? 'unknown error');
    }

    if ($sendCount > 0) {
        $sent = true;
        logActivity('send', 'weekly_digest', 0, "Executive digest sent to {$sendCount} recipient(s)");
    } else {
        $error = 'Failed to send: ' . implode('; ', $errors);
    }
}

include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/_nav.php';
?>

<?php if ($sent): ?>
<div class="alert alert-success d-flex align-items-center gap-2">
    <i class="fa fa-circle-check"></i>
    <strong>Digest Sent!</strong> Emailed to <?= count($recipientRows) ?> recipient(s): <?= implode(', ', array_column($recipientRows, 'email')) ?>
</div>
<?php endif; ?>
<?php if ($error): ?>
<div class="alert alert-danger"><i class="fa fa-circle-exclamation me-2"></i><?= e($error) ?></div>
<?php endif; ?>

<!-- ── 5 Highlight KPI Cards ──────────────────────────────────────────────── -->
<div class="row g-3 mb-4">
    <!-- Sales & Revenue -->
    <div class="col-6 col-xl-4">
        <div class="card border-0 shadow-sm h-100" style="border-radius:12px;border-left:4px solid #16a34a !important">
            <div class="card-body p-3">
                <div class="d-flex justify-content-between align-items-start mb-2">
                    <span class="text-muted small fw-semibold text-uppercase" style="letter-spacing:.05em">Sales &amp; Revenue</span>
                    <span class="badge bg-success-subtle text-success border border-success-subtle"><i class="fa fa-car me-1"></i><?= $sales['units'] ?> Sold</span>
                </div>
                <div class="fw-bold fs-4 text-success"><?= money((float)$sales['revenue']) ?></div>
                <div class="text-muted small mt-1">
                    Gross Profit: <strong class="text-purple"><?= money((float)$sales['profit']) ?></strong> (<?= $avgMargin ?>% avg margin)
                </div>
                <?php if ($revenueChange !== null): ?>
                <div class="mt-2 text-muted" style="font-size:11px">
                    vs previous period:
                    <span class="badge bg-<?= $revenueChange >= 0 ? 'success' : 'danger' ?>">
                        <?= $revenueChange >= 0 ? '↑' : '↓' ?> <?= abs($revenueChange) ?>%
                    </span>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Delivered Vehicles -->
    <div class="col-6 col-xl-2">
        <div class="card border-0 shadow-sm h-100" style="border-radius:12px;border-left:4px solid #0284c7 !important">
            <div class="card-body p-3">
                <div class="text-muted small fw-semibold text-uppercase mb-1" style="letter-spacing:.05em">Delivered Cars</div>
                <div class="fw-bold fs-3 text-info"><?= number_format((float)$deliveredSales['units']) ?></div>
                <div class="text-muted small mt-1">Delivered Value:</div>
                <div class="fw-semibold text-dark small"><?= money((float)$deliveredSales['revenue']) ?></div>
            </div>
        </div>
    </div>

    <!-- Workshop Performance -->
    <div class="col-6 col-xl-3">
        <div class="card border-0 shadow-sm h-100" style="border-radius:12px;border-left:4px solid #d97706 !important">
            <div class="card-body p-3">
                <div class="text-muted small fw-semibold text-uppercase mb-1" style="letter-spacing:.05em">Workshop Jobs</div>
                <div class="d-flex align-items-baseline gap-2">
                    <span class="fw-bold fs-3 text-warning"><?= (int)$wsData['completed_jobs'] ?></span>
                    <span class="text-muted small">/ <?= (int)$wsData['total_jobs'] ?> completed</span>
                </div>
                <div class="progress mt-2" style="height:5px">
                    <?php $wsPct = (int)$wsData['total_jobs'] > 0 ? round((int)$wsData['completed_jobs'] / (int)$wsData['total_jobs'] * 100) : 0; ?>
                    <div class="progress-bar bg-warning" style="width:<?= $wsPct ?>%"></div>
                </div>
                <div class="text-muted small mt-1"><?= (int)$wsData['active_jobs'] ?> active in-progress jobs</div>
            </div>
        </div>
    </div>

    <!-- CRM & Leads -->
    <div class="col-6 col-xl-3">
        <div class="card border-0 shadow-sm h-100" style="border-radius:12px;border-left:4px solid #9333ea !important">
            <div class="card-body p-3">
                <div class="text-muted small fw-semibold text-uppercase mb-1" style="letter-spacing:.05em">CRM Pipeline</div>
                <div class="d-flex align-items-baseline gap-2">
                    <span class="fw-bold fs-3 text-purple"><?= (int)$crmRow['open_leads'] ?></span>
                    <span class="text-muted small">active leads</span>
                </div>
                <div class="d-flex align-items-center justify-content-between mt-2" style="font-size:11.5px">
                    <span class="text-success fw-semibold">+<?= (int)$crmRow['new_in_period'] ?> new</span>
                    <span class="text-danger fw-semibold"><?= (int)$crmRow['overdue_followups'] ?> overdue</span>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ── Main Grid ──────────────────────────────────────────────────────────── -->
<div class="row g-4 mb-4">

    <!-- KPI Detailed Breakdown -->
    <div class="col-lg-8">
        <div class="card border-0 shadow-sm" style="border-radius:12px">
            <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center">
                <h6 class="fw-bold mb-0"><i class="fa fa-list-check me-2 text-primary"></i>Executive Breakdown — <?= e($label) ?></h6>
                <span class="badge bg-primary-subtle text-primary border border-primary-subtle">Comprehensive</span>
            </div>
            <div class="card-body p-4">

                <!-- Vehicle Sales & Landed COGS -->
                <div class="mb-4">
                    <h6 class="fw-bold text-dark mb-2" style="font-size:13.5px"><i class="fa fa-car me-2 text-success"></i>Vehicle Sales &amp; Cost Breakdown</h6>
                    <div class="row g-2">
                        <div class="col-sm-4">
                            <div class="p-3 rounded-3" style="background:#f0fdf4">
                                <div class="text-muted small">Vehicles Sold</div>
                                <div class="fw-bold text-success fs-5"><?= number_format((float)$sales['units']) ?> units</div>
                                <div class="text-muted" style="font-size:11px"><?= money((float)$sales['revenue']) ?> revenue</div>
                            </div>
                        </div>
                        <div class="col-sm-4">
                            <div class="p-3 rounded-3" style="background:#fef2f2">
                                <div class="text-muted small">Landed Cost (COGS)</div>
                                <div class="fw-bold text-danger fs-5"><?= money((float)$sales['cogs']) ?></div>
                                <div class="text-muted" style="font-size:11px">Purchase, duty, freight, workshop</div>
                            </div>
                        </div>
                        <div class="col-sm-4">
                            <div class="p-3 rounded-3" style="background:#faf5ff">
                                <div class="text-muted small">Realized Gross Profit</div>
                                <div class="fw-bold text-purple fs-5"><?= money((float)$sales['profit']) ?></div>
                                <div class="text-muted" style="font-size:11px"><?= $avgMargin ?>% average profit margin</div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Financial Collections & Inventory Health -->
                <div class="mb-4">
                    <h6 class="fw-bold text-dark mb-2" style="font-size:13.5px"><i class="fa fa-wallet me-2 text-primary"></i>Financial Receivables &amp; Stock Valuation</h6>
                    <div class="row g-2">
                        <div class="col-sm-6">
                            <div class="p-3 rounded-3" style="background:#eff6ff">
                                <div class="d-flex justify-content-between align-items-center mb-1">
                                    <span class="text-muted small">Invoiced &amp; Collections</span>
                                    <span class="badge bg-primary"><?= (float)$invoiceRow['total_invoiced'] > 0 ? round((float)$invoiceRow['total_collected'] / (float)$invoiceRow['total_invoiced'] * 100) : 0 ?>% collected</span>
                                </div>
                                <div class="fw-bold text-primary fs-6">Collected: <?= money((float)$invoiceRow['total_collected']) ?></div>
                                <div class="text-muted" style="font-size:11px">Total Invoiced: <?= money((float)$invoiceRow['total_invoiced']) ?></div>
                                <?php if ((float)$invoiceRow['overdue_balance'] > 0): ?>
                                <div class="text-danger mt-1 fw-semibold" style="font-size:11px"><i class="fa fa-exclamation-triangle me-1"></i>KES <?= number_format((float)$invoiceRow['overdue_balance']) ?> overdue (<?= (int)$invoiceRow['overdue_cnt'] ?> invoices)</div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="col-sm-6">
                            <div class="p-3 rounded-3" style="background:#f0f9ff">
                                <div class="text-muted small">Active Yard Stock</div>
                                <div class="fw-bold text-info fs-6"><?= (int)$invRow['total_stock'] ?> vehicles in yard</div>
                                <div class="text-muted" style="font-size:11px">Total Stock Value: <?= money((float)$invRow['stock_value']) ?></div>
                                <?php if ((int)$invRow['slow_movers'] > 0): ?>
                                <div class="text-danger mt-1 fw-semibold" style="font-size:11px"><i class="fa fa-clock me-1"></i><?= (int)$invRow['slow_movers'] ?> slow movers (&gt;90 days in yard)</div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Top Performers Spotlight -->
                <div>
                    <h6 class="fw-bold text-dark mb-2" style="font-size:13.5px"><i class="fa fa-trophy me-2 text-warning"></i>Period Top Performers</h6>
                    <div class="row g-2">
                        <div class="col-sm-6">
                            <div class="p-3 rounded-3" style="background:#fefce8;border:1px solid #fef08a">
                                <div class="text-muted small mb-1">Top Sales Representative</div>
                                <?php if ($topSales): ?>
                                <div class="fw-bold text-dark" style="font-size:14px">🏆 <?= e($topSales['name']) ?></div>
                                <div class="text-muted small"><?= (int)$topSales['units'] ?> unit(s) sold · <?= money((float)$topSales['revenue']) ?></div>
                                <?php else: ?>
                                <div class="text-muted small fst-italic">No sales recorded in period</div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="col-sm-6">
                            <div class="p-3 rounded-3" style="background:#f8fafc;border:1px solid #e2e8f0">
                                <div class="text-muted small mb-1">Top Selling Vehicle Model</div>
                                <?php if ($topModel): ?>
                                <div class="fw-bold text-dark" style="font-size:14px">🚗 <?= e($topModel['make'].' '.$topModel['model']) ?></div>
                                <div class="text-muted small"><?= (int)$topModel['units'] ?> unit(s) sold · <?= money((float)$topModel['revenue']) ?></div>
                                <?php else: ?>
                                <div class="text-muted small fst-italic">No sales recorded in period</div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </div>

    <!-- Email Dispatcher Panel -->
    <div class="col-lg-4">
        <div class="card border-0 shadow-sm" style="border-radius:12px">
            <div class="card-body p-4">
                <h6 class="fw-bold mb-3"><i class="fa fa-paper-plane me-2 text-success"></i>Send Executive Email Briefing</h6>

                <div class="mb-3">
                    <div class="text-muted small fw-semibold mb-2">Target Recipients</div>
                    <?php if ($recipientRows): ?>
                    <?php foreach ($recipientRows as $r): ?>
                    <div class="d-flex align-items-center gap-2 mb-1" style="font-size:12.5px">
                        <i class="fa fa-user-check text-success"></i>
                        <span class="fw-medium"><?= e($r['name']) ?></span>
                        <span class="text-muted small">&lt;<?= e($r['email']) ?>&gt;</span>
                    </div>
                    <?php endforeach; ?>
                    <?php else: ?>
                    <div class="alert alert-warning small py-2 mb-0">
                        <i class="fa fa-triangle-exclamation me-1"></i>
                        No active GM/Admin users with valid emails found.
                    </div>
                    <?php endif; ?>
                </div>

                <?php if ($recipientRows): ?>
                <form method="POST">
                    <input type="hidden" name="send_digest" value="1">
                    <?= csrfField() ?>
                    <button type="submit" class="btn btn-primary w-100 py-2 fw-semibold">
                        <i class="fa fa-paper-plane me-2"></i>Email Digest Report Now
                    </button>
                </form>
                <div class="text-muted small mt-2 text-center">Sends this executive KPI briefing for <?= e($label) ?></div>
                <?php endif; ?>

                <hr class="my-4">

                <div class="text-muted small">
                    <i class="fa fa-clock me-1"></i><strong>Automated Schedule:</strong>
                    Set up a weekly cron job to email this digest every Monday morning:
                    <code style="display:block;margin-top:6px;padding:8px;background:#f8fafc;border-radius:6px;font-size:11px;word-break:break-all">
                        0 7 * * 1 php <?= $_SERVER['DOCUMENT_ROOT'] ?>/modules/reports/cron_digest.php
                    </code>
                </div>
            </div>
        </div>
    </div>

</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>

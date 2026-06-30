<?php
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
hasRole(['super_admin','admin','general_manager']) || die('Access denied. GM / Admin only.');
$pageTitle = 'Weekly Executive Digest';
$db = getDB();
require_once __DIR__ . '/../../includes/mailer.php';

$sent  = false;
$error = '';

// ── Gather KPIs ───────────────────────────────────────────────────────────────
$weekStart = date('Y-m-d', strtotime('monday this week'));
$weekEnd   = date('Y-m-d');
$prevStart = date('Y-m-d', strtotime('monday last week'));
$prevEnd   = date('Y-m-d', strtotime('sunday last week'));
$today     = date('Y-m-d');

// Sales this week
$salesRow = $db->prepare("
    SELECT COUNT(*) AS units, COALESCE(SUM(sale_price),0) AS revenue,
           COALESCE(SUM(CASE WHEN cost_price IS NOT NULL THEN sale_price - cost_price ELSE 0 END),0) AS profit
    FROM car_sales
    WHERE status='active' AND sale_date BETWEEN ? AND ?
");
$salesRow->execute([$weekStart, $weekEnd]);
$sales = $salesRow->fetch(PDO::FETCH_ASSOC);

// Sales prev week (for comparison)
$prevRow = $db->prepare("SELECT COUNT(*) AS units, COALESCE(SUM(sale_price),0) AS revenue FROM car_sales WHERE status='active' AND sale_date BETWEEN ? AND ?");
$prevRow->execute([$prevStart, $prevEnd]);
$prevSales = $prevRow->fetch(PDO::FETCH_ASSOC);

// CRM leads — open + overdue follow-ups
try {
    $crmRow = $db->query("
        SELECT
            SUM(stage NOT IN ('closed_won','closed_lost')) AS open_leads,
            SUM(stage NOT IN ('closed_won','closed_lost') AND follow_up_date < CURDATE()) AS overdue_followups,
            SUM(DATE(created_at) >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)) AS new_this_week
        FROM crm_leads
    ")->fetch(PDO::FETCH_ASSOC);
} catch (\Throwable $_) {
    $crmRow = ['open_leads' => 0, 'overdue_followups' => 0, 'new_this_week' => 0];
}

// Inventory
$invRow = $db->query("
    SELECT
        COUNT(*) AS total_stock,
        SUM(DATEDIFF(NOW(), created_at) > 90) AS slow_movers,
        COALESCE(SUM(asking_price), 0) AS stock_value
    FROM cars WHERE car_type='inventory' AND status NOT IN ('sold','cancelled','delivered')
")->fetch(PDO::FETCH_ASSOC);

// Invoices — overdue
try {
    $invoiceRow = $db->query("
        SELECT COUNT(*) AS overdue_cnt, COALESCE(SUM(total - amount_paid),0) AS overdue_balance
        FROM invoices WHERE status NOT IN ('paid','cancelled') AND due_date < CURDATE()
    ")->fetch(PDO::FETCH_ASSOC);
} catch (\Throwable $_) {
    $invoiceRow = ['overdue_cnt' => 0, 'overdue_balance' => 0];
}

// Top salesperson this week
try {
    $topSales = $db->prepare("
        SELECT u.name, COUNT(cs.id) AS units, COALESCE(SUM(cs.sale_price),0) AS revenue
        FROM car_sales cs JOIN users u ON u.id = cs.sold_by
        WHERE cs.status='active' AND cs.sale_date BETWEEN ? AND ?
        GROUP BY u.id, u.name ORDER BY units DESC, revenue DESC LIMIT 1
    ");
    $topSales->execute([$weekStart, $weekEnd]);
    $topSales = $topSales->fetch(PDO::FETCH_ASSOC);
} catch (\Throwable $_) {
    $topSales = null;
}

// ── Recipients ───────────────────────────────────────────────────────────────
$recipientRows = $db->query("
    SELECT name, email FROM users
    WHERE role IN ('super_admin','admin','general_manager')
      AND status = 'active' AND email IS NOT NULL AND email != ''
")->fetchAll(PDO::FETCH_ASSOC);

// ── Handle send ───────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_digest'])) {
    verifyCsrf();
    $co   = getSetting('company_name', 'Mascardi Car Yard');
    $logo = BASE_URL . '/assets/img/logo.png';

    $revenueChange = (float)$prevSales['revenue'] > 0
        ? round(((float)$sales['revenue'] - (float)$prevSales['revenue']) / (float)$prevSales['revenue'] * 100, 1)
        : null;
    $revArrow = $revenueChange === null ? '' : ($revenueChange >= 0
        ? '<span style="color:#16a34a">&#8599; ' . abs($revenueChange) . '% vs last week</span>'
        : '<span style="color:#dc2626">&#8600; ' . abs($revenueChange) . '% vs last week</span>');

    $numFmt = fn($n) => number_format((float)$n);

    $html = '<!DOCTYPE html><html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>'
. '<body style="margin:0;padding:0;background:#f1f5f9;font-family:Arial,sans-serif">'
. '<table width="100%" cellpadding="0" cellspacing="0" style="background:#f1f5f9;padding:32px 0"><tr><td>'
. '<table width="600" align="center" cellpadding="0" cellspacing="0" style="background:#fff;border-radius:12px;overflow:hidden;max-width:600px">'

// Header
. '<tr><td style="background:#1e40af;padding:28px 32px;text-align:center">'
. '<div style="color:#fff;font-size:22px;font-weight:700">' . htmlspecialchars($co) . '</div>'
. '<div style="color:#bfdbfe;font-size:13px;margin-top:4px">Weekly Executive Summary — ' . $weekStart . ' to ' . $weekEnd . '</div>'
. '</td></tr>'

// Sales KPIs
. '<tr><td style="padding:28px 32px 0">'
. '<div style="font-size:11.5px;text-transform:uppercase;letter-spacing:.06em;color:#64748b;font-weight:600;margin-bottom:12px">This Week\'s Sales</div>'
. '<table width="100%" cellpadding="8" cellspacing="0"><tr>'
. '<td style="background:#f0fdf4;border-radius:10px;padding:16px;text-align:center">'
. '<div style="font-size:28px;font-weight:800;color:#16a34a">' . (int)$sales['units'] . '</div>'
. '<div style="font-size:11.5px;color:#64748b;margin-top:2px">Cars Sold</div></td>'
. '<td width="12"></td>'
. '<td style="background:#eff6ff;border-radius:10px;padding:16px;text-align:center">'
. '<div style="font-size:18px;font-weight:800;color:#1d4ed8">KES ' . $numFmt($sales['revenue']) . '</div>'
. '<div style="font-size:11.5px;color:#64748b;margin-top:2px">Revenue'
. ($revenueChange !== null ? (' <span style="color:' . ($revenueChange >= 0 ? '#16a34a' : '#dc2626') . '">' . ($revenueChange >= 0 ? '↑' : '↓') . abs($revenueChange) . '%</span>') : '') . '</div></td>'
. '<td width="12"></td>'
. '<td style="background:#faf5ff;border-radius:10px;padding:16px;text-align:center">'
. '<div style="font-size:18px;font-weight:800;color:#7c3aed">KES ' . $numFmt($sales['profit']) . '</div>'
. '<div style="font-size:11.5px;color:#64748b;margin-top:2px">Gross Profit</div></td>'
. '</tr></table>'
. '</td></tr>'

// CRM row
. '<tr><td style="padding:20px 32px 0">'
. '<div style="font-size:11.5px;text-transform:uppercase;letter-spacing:.06em;color:#64748b;font-weight:600;margin-bottom:12px">CRM Pipeline</div>'
. '<table width="100%" cellpadding="8" cellspacing="0"><tr>'
. '<td style="background:#fffbeb;border-radius:10px;padding:16px;text-align:center">'
. '<div style="font-size:28px;font-weight:800;color:#d97706">' . (int)$crmRow['open_leads'] . '</div>'
. '<div style="font-size:11.5px;color:#64748b;margin-top:2px">Open Leads</div></td>'
. '<td width="12"></td>'
. '<td style="background:#fef2f2;border-radius:10px;padding:16px;text-align:center">'
. '<div style="font-size:28px;font-weight:800;color:#dc2626">' . (int)$crmRow['overdue_followups'] . '</div>'
. '<div style="font-size:11.5px;color:#64748b;margin-top:2px">Overdue Follow-ups</div></td>'
. '<td width="12"></td>'
. '<td style="background:#f0fdf4;border-radius:10px;padding:16px;text-align:center">'
. '<div style="font-size:28px;font-weight:800;color:#16a34a">' . (int)$crmRow['new_this_week'] . '</div>'
. '<div style="font-size:11.5px;color:#64748b;margin-top:2px">New This Week</div></td>'
. '</tr></table>'
. '</td></tr>'

// Inventory + Overdue invoices row
. '<tr><td style="padding:20px 32px 0">'
. '<div style="font-size:11.5px;text-transform:uppercase;letter-spacing:.06em;color:#64748b;font-weight:600;margin-bottom:12px">Inventory &amp; Finance</div>'
. '<table width="100%" cellpadding="8" cellspacing="0"><tr>'
. '<td style="background:#f0f9ff;border-radius:10px;padding:16px;text-align:center">'
. '<div style="font-size:28px;font-weight:800;color:#0369a1">' . (int)$invRow['total_stock'] . '</div>'
. '<div style="font-size:11.5px;color:#64748b;margin-top:2px">Cars in Stock</div>'
. '<div style="font-size:11px;color:#' . ((int)$invRow['slow_movers'] > 0 ? 'dc2626' : '64748b') . '">' . (int)$invRow['slow_movers'] . ' slow movers (&gt;90d)</div>'
. '</td>'
. '<td width="12"></td>'
. '<td style="background:#fef2f2;border-radius:10px;padding:16px;text-align:center">'
. '<div style="font-size:28px;font-weight:800;color:#dc2626">' . (int)$invoiceRow['overdue_cnt'] . '</div>'
. '<div style="font-size:11.5px;color:#64748b;margin-top:2px">Overdue Invoices</div>'
. '<div style="font-size:11px;color:#dc2626">KES ' . $numFmt($invoiceRow['overdue_balance']) . ' outstanding</div>'
. '</td>'
. '</tr></table>'
. '</td></tr>'

// Top performer
. ($topSales ? (
    '<tr><td style="padding:20px 32px 0">'
    . '<div style="font-size:11.5px;text-transform:uppercase;letter-spacing:.06em;color:#64748b;font-weight:600;margin-bottom:10px">Top Performer This Week</div>'
    . '<div style="background:#fefce8;border-radius:10px;padding:16px;display:flex;align-items:center">'
    . '<span style="font-size:22px;margin-right:10px">🏆</span>'
    . '<div><div style="font-weight:700;font-size:15px">' . htmlspecialchars($topSales['name']) . '</div>'
    . '<div style="font-size:12px;color:#64748b">' . (int)$topSales['units'] . ' unit(s) — KES ' . $numFmt($topSales['revenue']) . '</div></div>'
    . '</div>'
    . '</td></tr>'
) : '')

// Footer
. '<tr><td style="padding:28px 32px;border-top:1px solid #f1f5f9;margin-top:20px">'
. '<div style="font-size:11.5px;color:#94a3b8;text-align:center">'
. 'Generated by ' . htmlspecialchars($co) . ' Management System on ' . date('d M Y H:i') . '<br>'
. '<a href="' . BASE_URL . '/modules/reports/" style="color:#2563eb">View Full Reports Dashboard</a>'
. '</div></td></tr>'

. '</table></td></tr></table>'
. '</body></html>';

    $subject = $co . ' — Weekly Executive Summary (' . date('d M Y', strtotime($weekStart)) . ')';
    $sendCount = 0;
    $errors    = [];
    foreach ($recipientRows as $rec) {
        $result = sendMail($rec['email'], $rec['name'], $subject, $html, 'weekly_digest', 0);
        if ($result['success']) $sendCount++;
        else $errors[] = $rec['email'] . ': ' . ($result['error'] ?? 'unknown error');
    }

    if ($sendCount > 0) {
        $sent = true;
        logActivity('send', 'weekly_digest', 0, "Weekly digest sent to {$sendCount} recipient(s)");
    } else {
        $error = 'Failed to send: ' . implode('; ', $errors);
    }
}

// Period nav vars (page has its own date range display, period selector is unused)
$period   = 'this_month';
$dateFrom = date('Y-m-01');
$dateTo   = date('Y-m-d');
$label    = 'Weekly Executive Digest';

include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/_nav.php';
?>

<?php if ($sent): ?>
<div class="alert alert-success d-flex align-items-center gap-2">
    <i class="fa fa-circle-check"></i>
    <strong>Digest sent!</strong> Emailed to <?= count($recipientRows) ?> recipient(s): <?= implode(', ', array_column($recipientRows, 'email')) ?>
</div>
<?php endif; ?>
<?php if ($error): ?>
<div class="alert alert-danger"><i class="fa fa-circle-exclamation me-2"></i><?= e($error) ?></div>
<?php endif; ?>

<div class="row g-4">

    <!-- KPI Preview -->
    <div class="col-lg-8">
        <div class="card border-0 shadow-sm" style="border-radius:12px">
            <div class="card-body p-4">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <div>
                        <h6 class="fw-bold mb-0"><i class="fa fa-envelope-open-text me-2 text-primary"></i>This Week's KPI Snapshot</h6>
                        <div class="text-muted small"><?= $weekStart ?> to <?= $weekEnd ?></div>
                    </div>
                    <span class="badge bg-primary-subtle text-primary border border-primary-subtle">Preview</span>
                </div>

                <!-- Sales -->
                <div class="text-muted small fw-semibold text-uppercase mb-2" style="letter-spacing:.04em">Sales</div>
                <div class="row g-2 mb-4">
                    <div class="col-4">
                        <div class="p-3 rounded-3 text-center" style="background:#f0fdf4">
                            <div style="font-size:28px;font-weight:800;color:#16a34a"><?= (int)$sales['units'] ?></div>
                            <div class="text-muted small">Cars Sold</div>
                        </div>
                    </div>
                    <div class="col-4">
                        <div class="p-3 rounded-3 text-center" style="background:#eff6ff">
                            <div style="font-size:18px;font-weight:800;color:#1d4ed8"><?= money((float)$sales['revenue']) ?></div>
                            <div class="text-muted small">Revenue</div>
                        </div>
                    </div>
                    <div class="col-4">
                        <div class="p-3 rounded-3 text-center" style="background:#faf5ff">
                            <div style="font-size:18px;font-weight:800;color:#7c3aed"><?= money((float)$sales['profit']) ?></div>
                            <div class="text-muted small">Gross Profit</div>
                        </div>
                    </div>
                </div>

                <!-- CRM -->
                <div class="text-muted small fw-semibold text-uppercase mb-2" style="letter-spacing:.04em">CRM Pipeline</div>
                <div class="row g-2 mb-4">
                    <div class="col-4">
                        <div class="p-3 rounded-3 text-center" style="background:#fffbeb">
                            <div style="font-size:28px;font-weight:800;color:#d97706"><?= (int)$crmRow['open_leads'] ?></div>
                            <div class="text-muted small">Open Leads</div>
                        </div>
                    </div>
                    <div class="col-4">
                        <div class="p-3 rounded-3 text-center" style="background:#fef2f2">
                            <div style="font-size:28px;font-weight:800;color:#dc2626"><?= (int)$crmRow['overdue_followups'] ?></div>
                            <div class="text-muted small">Overdue Follow-ups</div>
                        </div>
                    </div>
                    <div class="col-4">
                        <div class="p-3 rounded-3 text-center" style="background:#f0fdf4">
                            <div style="font-size:28px;font-weight:800;color:#16a34a"><?= (int)$crmRow['new_this_week'] ?></div>
                            <div class="text-muted small">New This Week</div>
                        </div>
                    </div>
                </div>

                <!-- Inventory + Invoices -->
                <div class="text-muted small fw-semibold text-uppercase mb-2" style="letter-spacing:.04em">Inventory &amp; Finance</div>
                <div class="row g-2">
                    <div class="col-6">
                        <div class="p-3 rounded-3" style="background:#f0f9ff">
                            <div class="d-flex align-items-center gap-2">
                                <i class="fa fa-car text-primary"></i>
                                <div>
                                    <div class="fw-bold" style="font-size:18px;color:#0369a1"><?= (int)$invRow['total_stock'] ?></div>
                                    <div class="text-muted small">Cars in Stock</div>
                                </div>
                            </div>
                            <?php if ((int)$invRow['slow_movers'] > 0): ?>
                            <div class="text-danger small mt-1"><i class="fa fa-triangle-exclamation me-1"></i><?= (int)$invRow['slow_movers'] ?> slow movers (&gt;90d)</div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="p-3 rounded-3" style="background:#fef2f2">
                            <div class="d-flex align-items-center gap-2">
                                <i class="fa fa-file-invoice-dollar text-danger"></i>
                                <div>
                                    <div class="fw-bold" style="font-size:18px;color:#dc2626"><?= (int)$invoiceRow['overdue_cnt'] ?></div>
                                    <div class="text-muted small">Overdue Invoices</div>
                                </div>
                            </div>
                            <div class="text-danger small mt-1">KES <?= number_format((float)$invoiceRow['overdue_balance']) ?> outstanding</div>
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </div>

    <!-- Send panel -->
    <div class="col-lg-4">
        <div class="card border-0 shadow-sm" style="border-radius:12px">
            <div class="card-body p-4">
                <h6 class="fw-bold mb-3"><i class="fa fa-paper-plane me-2 text-success"></i>Send Digest</h6>

                <div class="mb-3">
                    <div class="text-muted small fw-semibold mb-2">Recipients</div>
                    <?php if ($recipientRows): ?>
                    <?php foreach ($recipientRows as $r): ?>
                    <div class="d-flex align-items-center gap-2 mb-1" style="font-size:13px">
                        <i class="fa fa-envelope text-muted"></i>
                        <span class="fw-medium"><?= e($r['name']) ?></span>
                        <span class="text-muted">&lt;<?= e($r['email']) ?>&gt;</span>
                    </div>
                    <?php endforeach; ?>
                    <?php else: ?>
                    <div class="alert alert-warning small py-2 mb-0">
                        <i class="fa fa-triangle-exclamation me-1"></i>
                        No active GM/Admin users with email addresses found.
                    </div>
                    <?php endif; ?>
                </div>

                <?php if ($recipientRows): ?>
                <form method="POST">
                    <input type="hidden" name="send_digest" value="1">
                    <?= csrfField() ?>
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="fa fa-paper-plane me-2"></i>Send Now to <?= count($recipientRows) ?> Recipient(s)
                    </button>
                </form>
                <div class="text-muted small mt-2 text-center">Sends the above KPI summary via email</div>
                <?php endif; ?>

                <hr class="my-3">

                <div class="text-muted small">
                    <i class="fa fa-clock me-1"></i><strong>Schedule tip:</strong>
                    Set up a weekly cron job to call this endpoint automatically:
                    <code style="display:block;margin-top:6px;padding:8px;background:#f8fafc;border-radius:6px;font-size:11px;word-break:break-all">
                        0 7 * * 1 php <?= $_SERVER['DOCUMENT_ROOT'] ?>/modules/reports/cron_digest.php
                    </code>
                </div>
            </div>
        </div>

        <?php if ($topSales): ?>
        <div class="card border-0 shadow-sm mt-3" style="border-radius:12px">
            <div class="card-body p-4">
                <div class="text-muted small fw-semibold text-uppercase mb-2" style="letter-spacing:.04em">Top Performer This Week</div>
                <div class="d-flex align-items-center gap-3">
                    <span style="font-size:28px">🏆</span>
                    <div>
                        <div class="fw-bold" style="font-size:15px"><?= e($topSales['name']) ?></div>
                        <div class="text-muted small"><?= (int)$topSales['units'] ?> unit(s) — <?= money((float)$topSales['revenue']) ?></div>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>

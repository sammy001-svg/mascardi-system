<?php
/**
 * Cron-callable weekly digest sender.
 * Run via cron (no session needed):
 *   0 7 * * 1 php /path/to/cron_digest.php
 *
 * Sends the executive KPI summary to all GM/Admin users with email addresses.
 */
define('RUNNING_CRON', true);
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/mailer.php';

$db        = getDB();
$today     = date('Y-m-d');
$weekStart = date('Y-m-d', strtotime('monday this week'));
$weekEnd   = date('Y-m-d', strtotime('sunday this week'));
$prevStart = date('Y-m-d', strtotime('monday last week'));
$prevEnd   = date('Y-m-d', strtotime('sunday last week'));
$co        = getSetting('company_name', 'Mascardi Car Yard');

// Sales this week
$salesRow = $db->prepare("
    SELECT COUNT(*) AS units, COALESCE(SUM(sale_price),0) AS revenue,
           COALESCE(SUM(CASE WHEN cost_price IS NOT NULL THEN sale_price - cost_price ELSE 0 END),0) AS profit
    FROM car_sales WHERE status='active' AND sale_date BETWEEN ? AND ?
");
$salesRow->execute([$weekStart, $weekEnd]);
$sales = $salesRow->fetch(PDO::FETCH_ASSOC);

$prevRow = $db->prepare("SELECT COALESCE(SUM(sale_price),0) AS revenue FROM car_sales WHERE status='active' AND sale_date BETWEEN ? AND ?");
$prevRow->execute([$prevStart, $prevEnd]);
$prevRev = (float)$prevRow->fetchColumn();

$revenueChange = $prevRev > 0 ? round(((float)$sales['revenue'] - $prevRev) / $prevRev * 100, 1) : null;

try {
    $crmRow = $db->query("
        SELECT SUM(stage NOT IN ('closed_won','closed_lost')) AS open_leads,
               SUM(stage NOT IN ('closed_won','closed_lost') AND follow_up_date < CURDATE()) AS overdue_followups,
               SUM(DATE(created_at) >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)) AS new_this_week
        FROM crm_leads
    ")->fetch(PDO::FETCH_ASSOC);
} catch (\Throwable $_) {
    $crmRow = ['open_leads' => 0, 'overdue_followups' => 0, 'new_this_week' => 0];
}

$invRow = $db->query("
    SELECT COUNT(*) AS total_stock,
           SUM(DATEDIFF(NOW(), created_at) > 90) AS slow_movers,
           COALESCE(SUM(asking_price), 0) AS stock_value
    FROM cars WHERE car_type='inventory' AND status NOT IN ('sold','cancelled','delivered')
")->fetch(PDO::FETCH_ASSOC);

try {
    $invoiceRow = $db->query("
        SELECT COUNT(*) AS overdue_cnt, COALESCE(SUM(total - amount_paid),0) AS overdue_balance
        FROM invoices WHERE status NOT IN ('paid','cancelled') AND due_date < CURDATE()
    ")->fetch(PDO::FETCH_ASSOC);
} catch (\Throwable $_) {
    $invoiceRow = ['overdue_cnt' => 0, 'overdue_balance' => 0];
}

$numFmt = fn($n) => number_format((float)$n);

$html = '<!DOCTYPE html><html><head><meta charset="UTF-8"></head>'
. '<body style="margin:0;padding:0;background:#f1f5f9;font-family:Arial,sans-serif">'
. '<table width="100%" cellpadding="0" cellspacing="0" style="background:#f1f5f9;padding:32px 0"><tr><td>'
. '<table width="600" align="center" cellpadding="0" cellspacing="0" style="background:#fff;border-radius:12px;overflow:hidden;max-width:600px">'
. '<tr><td style="background:#1e40af;padding:28px 32px;text-align:center">'
. '<div style="color:#fff;font-size:22px;font-weight:700">' . htmlspecialchars($co) . '</div>'
. '<div style="color:#bfdbfe;font-size:13px;margin-top:4px">Weekly Executive Summary — ' . $weekStart . ' to ' . $weekEnd . '</div>'
. '</td></tr>'
. '<tr><td style="padding:28px 32px 0">'
. '<div style="font-size:11px;text-transform:uppercase;letter-spacing:.06em;color:#64748b;font-weight:600;margin-bottom:12px">Sales This Week</div>'
. '<table width="100%" cellpadding="8" cellspacing="0"><tr>'
. '<td style="background:#f0fdf4;border-radius:10px;padding:16px;text-align:center"><div style="font-size:28px;font-weight:800;color:#16a34a">' . (int)$sales['units'] . '</div><div style="font-size:11.5px;color:#64748b">Cars Sold</div></td>'
. '<td width="12"></td>'
. '<td style="background:#eff6ff;border-radius:10px;padding:16px;text-align:center"><div style="font-size:18px;font-weight:800;color:#1d4ed8">KES ' . $numFmt($sales['revenue']) . '</div><div style="font-size:11.5px;color:#64748b">Revenue' . ($revenueChange !== null ? ' <span style="color:' . ($revenueChange >= 0 ? '#16a34a' : '#dc2626') . '">' . ($revenueChange >= 0 ? '↑' : '↓') . abs($revenueChange) . '%</span>' : '') . '</div></td>'
. '<td width="12"></td>'
. '<td style="background:#faf5ff;border-radius:10px;padding:16px;text-align:center"><div style="font-size:18px;font-weight:800;color:#7c3aed">KES ' . $numFmt($sales['profit']) . '</div><div style="font-size:11.5px;color:#64748b">Gross Profit</div></td>'
. '</tr></table></td></tr>'
. '<tr><td style="padding:20px 32px 0">'
. '<div style="font-size:11px;text-transform:uppercase;letter-spacing:.06em;color:#64748b;font-weight:600;margin-bottom:12px">CRM Pipeline</div>'
. '<table width="100%" cellpadding="8" cellspacing="0"><tr>'
. '<td style="background:#fffbeb;border-radius:10px;padding:16px;text-align:center"><div style="font-size:28px;font-weight:800;color:#d97706">' . (int)$crmRow['open_leads'] . '</div><div style="font-size:11.5px;color:#64748b">Open Leads</div></td>'
. '<td width="12"></td>'
. '<td style="background:#fef2f2;border-radius:10px;padding:16px;text-align:center"><div style="font-size:28px;font-weight:800;color:#dc2626">' . (int)$crmRow['overdue_followups'] . '</div><div style="font-size:11.5px;color:#64748b">Overdue Follow-ups</div></td>'
. '<td width="12"></td>'
. '<td style="background:#f0fdf4;border-radius:10px;padding:16px;text-align:center"><div style="font-size:28px;font-weight:800;color:#16a34a">' . (int)$crmRow['new_this_week'] . '</div><div style="font-size:11.5px;color:#64748b">New This Week</div></td>'
. '</tr></table></td></tr>'
. '<tr><td style="padding:20px 32px 0">'
. '<div style="font-size:11px;text-transform:uppercase;letter-spacing:.06em;color:#64748b;font-weight:600;margin-bottom:12px">Inventory &amp; Finance</div>'
. '<table width="100%" cellpadding="8" cellspacing="0"><tr>'
. '<td style="background:#f0f9ff;border-radius:10px;padding:16px;text-align:center"><div style="font-size:28px;font-weight:800;color:#0369a1">' . (int)$invRow['total_stock'] . '</div><div style="font-size:11.5px;color:#64748b">Cars in Stock</div>' . ((int)$invRow['slow_movers'] > 0 ? '<div style="font-size:11px;color:#dc2626">' . (int)$invRow['slow_movers'] . ' slow movers</div>' : '') . '</td>'
. '<td width="12"></td>'
. '<td style="background:#fef2f2;border-radius:10px;padding:16px;text-align:center"><div style="font-size:28px;font-weight:800;color:#dc2626">' . (int)$invoiceRow['overdue_cnt'] . '</div><div style="font-size:11.5px;color:#64748b">Overdue Invoices</div><div style="font-size:11px;color:#dc2626">KES ' . $numFmt($invoiceRow['overdue_balance']) . '</div></td>'
. '</tr></table></td></tr>'
. '<tr><td style="padding:28px 32px;border-top:1px solid #f1f5f9">'
. '<div style="font-size:11.5px;color:#94a3b8;text-align:center">Generated by ' . htmlspecialchars($co) . ' on ' . date('d M Y H:i') . '</div>'
. '</td></tr>'
. '</table></td></tr></table></body></html>';

$subject   = $co . ' — Weekly Summary (' . date('d M Y', strtotime($weekStart)) . ')';
$recipients = $db->query("
    SELECT name, email FROM users
    WHERE role IN ('super_admin','admin','general_manager')
      AND status = 'active' AND email IS NOT NULL AND email != ''
")->fetchAll(PDO::FETCH_ASSOC);

$sent = 0;
foreach ($recipients as $rec) {
    $result = sendMail($rec['email'], $rec['name'], $subject, $html, 'weekly_digest', 0);
    if ($result['success']) $sent++;
    else error_log('[digest] Failed to send to ' . $rec['email'] . ': ' . ($result['error'] ?? '?'));
}

echo "[" . date('Y-m-d H:i:s') . "] Weekly digest sent to {$sent}/" . count($recipients) . " recipients.\n";
logActivity('send', 'weekly_digest', 0, "Cron digest sent to {$sent} recipient(s)");

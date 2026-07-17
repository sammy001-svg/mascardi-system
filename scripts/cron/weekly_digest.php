<?php
/**
 * Mascardi Car Yard — Weekly Digest Cron Job
 *
 * Automatically sends the weekly executive summary email every Monday.
 * Reuses the same KPI data and HTML template from modules/reports/weekly_digest.php
 *
 * Run via Windows Task Scheduler:
 *   Program:  C:\xampp\php\php.exe
 *   Arguments: "C:\Mascardi System\mascardi-system\scripts\cron\weekly_digest.php"
 *   Schedule:  Weekly, Monday at 07:00 AM
 *
 * Or Linux cron:
 *   0 7 * * 1 /usr/bin/php /var/www/mascardi/scripts/cron/weekly_digest.php
 */

define('CRON_RUN', true);
// Stub $_SERVER vars needed by BASE_URL detection when running in CLI
if (PHP_SAPI === 'cli') {
    $_SERVER['HTTP_HOST']   = 'localhost';
    $_SERVER['SCRIPT_NAME'] = '/scripts/cron/weekly_digest.php';
    $_SERVER['REQUEST_METHOD'] = 'GET';
}

require_once __DIR__ . '/../../includes/functions.php';

$startTime = microtime(true);
$jobName   = 'weekly_digest';
$db        = getDB();
$sent      = 0;
$errors    = [];

// ── KPI Gathering (mirrors weekly_digest.php) ─────────────────────────────────
$weekStart = date('Y-m-d', strtotime('monday this week'));
$weekEnd   = date('Y-m-d');
$prevStart = date('Y-m-d', strtotime('monday last week'));
$prevEnd   = date('Y-m-d', strtotime('sunday last week'));

try {
    $salesRow = $db->prepare("
        SELECT COUNT(*) AS units, COALESCE(SUM(sale_price),0) AS revenue,
               COALESCE(SUM(CASE WHEN cost_price IS NOT NULL THEN sale_price - cost_price ELSE 0 END),0) AS profit
        FROM car_sales WHERE status='active' AND sale_date BETWEEN ? AND ?
    ");
    $salesRow->execute([$weekStart, $weekEnd]);
    $sales = $salesRow->fetch(PDO::FETCH_ASSOC);

    $prevRow = $db->prepare("SELECT COALESCE(SUM(sale_price),0) AS revenue FROM car_sales WHERE status='active' AND sale_date BETWEEN ? AND ?");
    $prevRow->execute([$prevStart, $prevEnd]);
    $prevSales = $prevRow->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $sales = ['units' => 0, 'revenue' => 0, 'profit' => 0];
    $prevSales = ['revenue' => 0];
}

try {
    $crmRow = $db->query("
        SELECT SUM(stage NOT IN ('closed_won','closed_lost')) AS open_leads,
               SUM(stage NOT IN ('closed_won','closed_lost') AND follow_up_date < CURDATE()) AS overdue_followups,
               SUM(DATE(created_at) >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)) AS new_this_week
        FROM crm_leads
    ")->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $_) {
    $crmRow = ['open_leads' => 0, 'overdue_followups' => 0, 'new_this_week' => 0];
}

try {
    $invRow = $db->query("
        SELECT COUNT(*) AS total_stock,
               SUM(DATEDIFF(NOW(), created_at) > 90) AS slow_movers,
               COALESCE(SUM(asking_price), 0) AS stock_value
        FROM cars WHERE car_type='inventory' AND status NOT IN ('sold','cancelled','delivered')
    ")->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $_) {
    $invRow = ['total_stock' => 0, 'slow_movers' => 0, 'stock_value' => 0];
}

try {
    $invoiceRow = $db->query("
        SELECT COUNT(*) AS overdue_cnt, COALESCE(SUM(total - amount_paid),0) AS overdue_balance
        FROM invoices WHERE status NOT IN ('paid','cancelled') AND due_date < CURDATE()
    ")->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $_) {
    $invoiceRow = ['overdue_cnt' => 0, 'overdue_balance' => 0];
}

try {
    $topSales = $db->prepare("
        SELECT u.name, COUNT(cs.id) AS units, COALESCE(SUM(cs.sale_price),0) AS revenue
        FROM car_sales cs JOIN users u ON u.id = cs.sold_by
        WHERE cs.status='active' AND cs.sale_date BETWEEN ? AND ?
        GROUP BY u.id, u.name ORDER BY units DESC, revenue DESC LIMIT 1
    ");
    $topSales->execute([$weekStart, $weekEnd]);
    $topSales = $topSales->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $_) {
    $topSales = null;
}

// ── Recipients ────────────────────────────────────────────────────────────────
$recipients = $db->query("
    SELECT name, email FROM users
    WHERE role IN ('super_admin','admin','general_manager')
      AND status = 'active' AND email IS NOT NULL AND email != ''
")->fetchAll(PDO::FETCH_ASSOC);

if (empty($recipients)) {
    $ms = (int)((microtime(true) - $startTime) * 1000);
    $db->prepare("INSERT INTO cron_runs (job_name, status, duration_ms, records, message) VALUES (?,?,?,?,?)")
       ->execute([$jobName, 'skipped', $ms, 0, 'No active recipients found']);
    echo "[" . date('Y-m-d H:i:s') . "] weekly_digest: skipped — no recipients\n";
    exit;
}

// ── Build HTML ────────────────────────────────────────────────────────────────
$co       = getSetting('company_name', 'Mascardi Car Yard');
$numFmt   = fn($n) => number_format((float)$n);
$revChange = (float)$prevSales['revenue'] > 0
    ? round(((float)$sales['revenue'] - (float)$prevSales['revenue']) / (float)$prevSales['revenue'] * 100, 1)
    : null;

$html = '<!DOCTYPE html><html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>'
. '<body style="margin:0;padding:0;background:#f1f5f9;font-family:Arial,sans-serif">'
. '<table width="100%" cellpadding="0" cellspacing="0" style="background:#f1f5f9;padding:32px 0"><tr><td>'
. '<table width="600" align="center" cellpadding="0" cellspacing="0" style="background:#fff;border-radius:12px;overflow:hidden;max-width:600px">'

// Header
. '<tr><td style="background:#1e40af;padding:28px 32px;text-align:center">'
. '<div style="color:#fff;font-size:22px;font-weight:700">' . htmlspecialchars($co) . '</div>'
. '<div style="color:#bfdbfe;font-size:13px;margin-top:4px">Weekly Executive Summary — ' . $weekStart . ' to ' . $weekEnd . '</div>'
. '</td></tr>'

// Sales
. '<tr><td style="padding:28px 32px 0">'
. '<div style="font-size:11.5px;text-transform:uppercase;letter-spacing:.06em;color:#64748b;font-weight:600;margin-bottom:12px">This Week\'s Sales</div>'
. '<table width="100%" cellpadding="8" cellspacing="0"><tr>'
. '<td style="background:#f0fdf4;border-radius:10px;padding:16px;text-align:center"><div style="font-size:28px;font-weight:800;color:#16a34a">' . (int)$sales['units'] . '</div><div style="font-size:11.5px;color:#64748b;margin-top:2px">Cars Sold</div></td>'
. '<td width="12"></td>'
. '<td style="background:#eff6ff;border-radius:10px;padding:16px;text-align:center"><div style="font-size:18px;font-weight:800;color:#1d4ed8">KES ' . $numFmt($sales['revenue']) . '</div><div style="font-size:11.5px;color:#64748b;margin-top:2px">Revenue' . ($revChange !== null ? ' <span style="color:' . ($revChange >= 0 ? '#16a34a' : '#dc2626') . '">' . ($revChange >= 0 ? '↑' : '↓') . abs($revChange) . '%</span>' : '') . '</div></td>'
. '<td width="12"></td>'
. '<td style="background:#faf5ff;border-radius:10px;padding:16px;text-align:center"><div style="font-size:18px;font-weight:800;color:#7c3aed">KES ' . $numFmt($sales['profit']) . '</div><div style="font-size:11.5px;color:#64748b;margin-top:2px">Gross Profit</div></td>'
. '</tr></table></td></tr>'

// CRM
. '<tr><td style="padding:20px 32px 0">'
. '<div style="font-size:11.5px;text-transform:uppercase;letter-spacing:.06em;color:#64748b;font-weight:600;margin-bottom:12px">CRM Pipeline</div>'
. '<table width="100%" cellpadding="8" cellspacing="0"><tr>'
. '<td style="background:#fffbeb;border-radius:10px;padding:16px;text-align:center"><div style="font-size:28px;font-weight:800;color:#d97706">' . (int)$crmRow['open_leads'] . '</div><div style="font-size:11.5px;color:#64748b">Open Leads</div></td>'
. '<td width="12"></td>'
. '<td style="background:#fef2f2;border-radius:10px;padding:16px;text-align:center"><div style="font-size:28px;font-weight:800;color:#dc2626">' . (int)$crmRow['overdue_followups'] . '</div><div style="font-size:11.5px;color:#64748b">Overdue Follow-ups</div></td>'
. '<td width="12"></td>'
. '<td style="background:#f0fdf4;border-radius:10px;padding:16px;text-align:center"><div style="font-size:28px;font-weight:800;color:#16a34a">' . (int)$crmRow['new_this_week'] . '</div><div style="font-size:11.5px;color:#64748b">New This Week</div></td>'
. '</tr></table></td></tr>'

// Inventory + Finance
. '<tr><td style="padding:20px 32px 0">'
. '<div style="font-size:11.5px;text-transform:uppercase;letter-spacing:.06em;color:#64748b;font-weight:600;margin-bottom:12px">Inventory &amp; Finance</div>'
. '<table width="100%" cellpadding="8" cellspacing="0"><tr>'
. '<td style="background:#f0f9ff;border-radius:10px;padding:16px;text-align:center"><div style="font-size:28px;font-weight:800;color:#0369a1">' . (int)$invRow['total_stock'] . '</div><div style="font-size:11.5px;color:#64748b">Cars in Stock</div><div style="font-size:11px;color:#' . ((int)$invRow['slow_movers'] > 0 ? 'dc2626' : '64748b') . '">' . (int)$invRow['slow_movers'] . ' slow movers</div></td>'
. '<td width="12"></td>'
. '<td style="background:#fef2f2;border-radius:10px;padding:16px;text-align:center"><div style="font-size:28px;font-weight:800;color:#dc2626">' . (int)$invoiceRow['overdue_cnt'] . '</div><div style="font-size:11.5px;color:#64748b">Overdue Invoices</div><div style="font-size:11px;color:#dc2626">KES ' . $numFmt($invoiceRow['overdue_balance']) . ' outstanding</div></td>'
. '</tr></table></td></tr>'

// Top performer
. ($topSales ? (
    '<tr><td style="padding:20px 32px 0">'
    . '<div style="font-size:11.5px;text-transform:uppercase;letter-spacing:.06em;color:#64748b;font-weight:600;margin-bottom:10px">Top Performer This Week</div>'
    . '<div style="background:#fefce8;border-radius:10px;padding:16px">'
    . '🏆 <strong>' . htmlspecialchars($topSales['name']) . '</strong> — ' . (int)$topSales['units'] . ' unit(s), KES ' . $numFmt($topSales['revenue'])
    . '</div></td></tr>'
) : '')

// Footer
. '<tr><td style="padding:28px 32px;border-top:1px solid #f1f5f9;margin-top:20px">'
. '<div style="font-size:11.5px;color:#94a3b8;text-align:center">'
. 'Generated by ' . htmlspecialchars($co) . ' Management System on ' . date('d M Y H:i') . '<br>'
. '<a href="' . BASE_URL . '/modules/reports/" style="color:#2563eb">View Full Reports Dashboard</a>'
. '</div></td></tr>'
. '</table></td></tr></table></body></html>';

// ── Send ──────────────────────────────────────────────────────────────────────
$subject = $co . ' — Weekly Executive Summary (' . date('d M Y', strtotime($weekStart)) . ')';
$headers = "From: noreply@mascardi.co.ke\r\nContent-Type: text/html; charset=UTF-8\r\nX-Mailer: Mascardi-Cron/9";

foreach ($recipients as $rec) {
    $ok = @mail($rec['email'], $subject, $html, $headers);
    if ($ok) {
        $sent++;
    } else {
        $errors[] = $rec['email'];
    }
    // Log to email_logs if table exists
    try {
        $db->prepare("INSERT INTO email_logs (recipient, subject, body, type, status, created_at) VALUES (?,?,?,?,?,NOW())")
           ->execute([$rec['email'], $subject, $html, 'weekly_digest', $ok ? 'sent' : 'failed']);
    } catch (Throwable $_) {}
}

// ── Cron log ──────────────────────────────────────────────────────────────────
$ms      = (int)((microtime(true) - $startTime) * 1000);
$status  = empty($errors) ? 'success' : ($sent > 0 ? 'success' : 'error');
$message = "Sent to {$sent}/" . count($recipients) . " recipient(s)."
         . (empty($errors) ? '' : " Failed: " . implode(', ', $errors));

try {
    $db->prepare("INSERT INTO cron_runs (job_name, status, duration_ms, records, message) VALUES (?,?,?,?,?)")
       ->execute([$jobName, $status, $ms, $sent, $message]);
} catch (Throwable $_) {}

if (PHP_SAPI === 'cli') {
    echo "[" . date('Y-m-d H:i:s') . "] weekly_digest: {$message} ({$ms}ms)\n";
}

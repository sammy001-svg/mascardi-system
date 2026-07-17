<?php
/**
 * Mascardi Car Yard — Daily Alerts Cron Job
 *
 * Sends alerts for:
 *   1. Overdue workshop jobs (past end_date, not completed)
 *   2. Low inventory items (quantity <= reorder_level)
 *   3. Pending payment reminders (invoices unpaid > 7 days)
 *
 * Run via Windows Task Scheduler or cron:
 *   C:\xampp\php\php.exe "C:\Mascardi System\mascardi-system\scripts\cron\daily_alerts.php"
 *
 * Or set up in XAMPP Task Scheduler (see README.md in this folder).
 */

define('CRON_RUN', true);
require_once __DIR__ . '/../../includes/functions.php';

$startTime = microtime(true);
$jobName   = 'daily_alerts';
$db        = getDB();
$sent      = 0;
$errors    = [];

// ── Helper: log a cron run ────────────────────────────────────────────────────
function cronLog(PDO $db, string $job, string $status, int $records, string $message, int $ms): void {
    try {
        $db->prepare("INSERT INTO cron_runs (job_name, status, duration_ms, records, message) VALUES (?, ?, ?, ?, ?)")
           ->execute([$job, $status, $ms, $records, $message]);
    } catch (Throwable $e) {
        error_log('[Cron] DB log failed: ' . $e->getMessage());
    }
}

// ── Helper: log to email_logs table ──────────────────────────────────────────
function logEmailAlert(PDO $db, string $type, string $recipient, string $subject, string $body, string $status = 'sent'): void {
    try {
        $db->prepare("INSERT INTO email_logs (recipient, subject, body, type, status, created_at) VALUES (?, ?, ?, ?, ?, NOW())")
           ->execute([$recipient, $subject, $body, $type, $status]);
    } catch (Throwable $e) {
        // email_logs table columns may differ — silently continue
    }
}

// ── Helper: send email via PHP mail() ────────────────────────────────────────
function sendAlert(string $to, string $subject, string $body): bool {
    $headers = "From: noreply@mascardi.co.ke\r\nContent-Type: text/html; charset=UTF-8\r\nX-Mailer: Mascardi-Cron/9";
    return @mail($to, $subject, $body, $headers);
}

// ── 1. Overdue Workshop Jobs ──────────────────────────────────────────────────
try {
    $overdueJobs = $db->query("
        SELECT j.job_number, j.end_date, j.priority, c.make, c.model, c.chassis_number,
               m.name AS mechanic_name
        FROM workshop_jobs j
        JOIN cars c ON c.id = j.car_id
        LEFT JOIN mechanics m ON m.id = j.mechanic_id
        WHERE j.status NOT IN ('completed','cancelled')
          AND j.end_date < CURDATE()
          AND j.end_date IS NOT NULL
        ORDER BY j.end_date ASC
        LIMIT 50
    ")->fetchAll();

    if ($overdueJobs) {
        // Get workshop manager email(s)
        $managers = $db->query("
            SELECT email FROM users
            WHERE role IN ('admin','workshop_manager','super_admin')
              AND status = 'active'
              AND email IS NOT NULL AND email != ''
            LIMIT 5
        ")->fetchAll(PDO::FETCH_COLUMN);

        $rows = '';
        foreach ($overdueJobs as $j) {
            $daysLate = (int)ceil((time() - strtotime($j['end_date'])) / 86400);
            $rows .= "<tr>
                <td style='padding:8px 12px;border-bottom:1px solid #f1f5f9;font-weight:600'>{$j['job_number']}</td>
                <td style='padding:8px 12px;border-bottom:1px solid #f1f5f9'>{$j['make']} {$j['model']}</td>
                <td style='padding:8px 12px;border-bottom:1px solid #f1f5f9;font-family:monospace;font-size:12px'>{$j['chassis_number']}</td>
                <td style='padding:8px 12px;border-bottom:1px solid #f1f5f9'>" . htmlspecialchars($j['mechanic_name'] ?? '—') . "</td>
                <td style='padding:8px 12px;border-bottom:1px solid #f1f5f9;color:#dc2626;font-weight:700'>{$daysLate} days late</td>
            </tr>";
        }

        $subject = '⚠️ ' . count($overdueJobs) . ' Overdue Workshop Job(s) — ' . date('d M Y');
        $body = "
        <div style='font-family:Inter,sans-serif;max-width:700px;margin:0 auto'>
        <div style='background:#1e293b;padding:20px 24px;border-radius:8px 8px 0 0'>
            <h2 style='color:#fff;margin:0;font-size:18px'>⚠️ Overdue Workshop Jobs</h2>
            <p style='color:rgba(255,255,255,.65);margin:4px 0 0;font-size:13px'>" . date('l, d F Y') . "</p>
        </div>
        <div style='background:#fff;padding:24px;border:1px solid #e2e8f0;border-radius:0 0 8px 8px'>
            <p style='color:#374151;margin:0 0 16px'>" . count($overdueJobs) . " job(s) are past their due date and still open:</p>
            <table style='width:100%;border-collapse:collapse;font-size:13px'>
                <thead>
                    <tr style='background:#f8fafc;'>
                        <th style='padding:8px 12px;text-align:left;font-size:11px;color:#64748b;text-transform:uppercase;letter-spacing:.05em'>Job #</th>
                        <th style='padding:8px 12px;text-align:left;font-size:11px;color:#64748b;text-transform:uppercase;letter-spacing:.05em'>Vehicle</th>
                        <th style='padding:8px 12px;text-align:left;font-size:11px;color:#64748b;text-transform:uppercase;letter-spacing:.05em'>Chassis</th>
                        <th style='padding:8px 12px;text-align:left;font-size:11px;color:#64748b;text-transform:uppercase;letter-spacing:.05em'>Mechanic</th>
                        <th style='padding:8px 12px;text-align:left;font-size:11px;color:#64748b;text-transform:uppercase;letter-spacing:.05em'>Overdue By</th>
                    </tr>
                </thead>
                <tbody>{$rows}</tbody>
            </table>
            <p style='margin:20px 0 0;font-size:13px;color:#64748b'>
                <a href='" . getSetting('base_url', 'http://localhost:8001') . "/modules/jobs/index.php' style='color:#2563eb'>→ View all jobs in system</a>
            </p>
        </div>
        </div>";

        foreach ($managers as $email) {
            $ok = sendAlert($email, $subject, $body);
            logEmailAlert($db, 'overdue_jobs_alert', $email, $subject, $body, $ok ? 'sent' : 'failed');
            if ($ok) $sent++;
        }
    }
} catch (Throwable $e) {
    $errors[] = 'Overdue jobs: ' . $e->getMessage();
}

// ── 2. Low Inventory Alert ─────────────────────────────────────────────────────
try {
    $lowStock = $db->query("
        SELECT part_name, category, quantity, reorder_level, unit
        FROM inventory
        WHERE quantity <= reorder_level
        ORDER BY (quantity / GREATEST(reorder_level, 1)) ASC
        LIMIT 30
    ")->fetchAll();

    if ($lowStock) {
        $managers = $db->query("
            SELECT email FROM users
            WHERE role IN ('admin','workshop_manager','super_admin')
              AND status = 'active' AND email IS NOT NULL AND email != ''
            LIMIT 5
        ")->fetchAll(PDO::FETCH_COLUMN);

        $rows = '';
        foreach ($lowStock as $item) {
            $pct = $item['reorder_level'] > 0 ? round($item['quantity'] / $item['reorder_level'] * 100) : 0;
            $color = $item['quantity'] == 0 ? '#dc2626' : '#d97706';
            $rows .= "<tr>
                <td style='padding:8px 12px;border-bottom:1px solid #f1f5f9;font-weight:600'>" . htmlspecialchars($item['part_name']) . "</td>
                <td style='padding:8px 12px;border-bottom:1px solid #f1f5f9;color:#64748b'>" . htmlspecialchars($item['category'] ?? '—') . "</td>
                <td style='padding:8px 12px;border-bottom:1px solid #f1f5f9;font-weight:700;color:{$color}'>{$item['quantity']} {$item['unit']}</td>
                <td style='padding:8px 12px;border-bottom:1px solid #f1f5f9;color:#64748b'>{$item['reorder_level']} {$item['unit']}</td>
            </tr>";
        }

        $subject = '📦 ' . count($lowStock) . ' Low-Stock Part(s) — ' . date('d M Y');
        $body = "
        <div style='font-family:Inter,sans-serif;max-width:700px;margin:0 auto'>
        <div style='background:#d97706;padding:20px 24px;border-radius:8px 8px 0 0'>
            <h2 style='color:#fff;margin:0;font-size:18px'>📦 Low Inventory Alert</h2>
            <p style='color:rgba(255,255,255,.8);margin:4px 0 0;font-size:13px'>" . date('l, d F Y') . "</p>
        </div>
        <div style='background:#fff;padding:24px;border:1px solid #e2e8f0;border-radius:0 0 8px 8px'>
            <p style='color:#374151;margin:0 0 16px'>" . count($lowStock) . " part(s) are at or below reorder level:</p>
            <table style='width:100%;border-collapse:collapse;font-size:13px'>
                <thead>
                    <tr style='background:#f8fafc'>
                        <th style='padding:8px 12px;text-align:left;font-size:11px;color:#64748b;text-transform:uppercase'>Part Name</th>
                        <th style='padding:8px 12px;text-align:left;font-size:11px;color:#64748b;text-transform:uppercase'>Category</th>
                        <th style='padding:8px 12px;text-align:left;font-size:11px;color:#64748b;text-transform:uppercase'>In Stock</th>
                        <th style='padding:8px 12px;text-align:left;font-size:11px;color:#64748b;text-transform:uppercase'>Reorder At</th>
                    </tr>
                </thead>
                <tbody>{$rows}</tbody>
            </table>
            <p style='margin:20px 0 0;font-size:13px;color:#64748b'>
                <a href='" . getSetting('base_url', 'http://localhost:8001') . "/modules/inventory/index.php' style='color:#2563eb'>→ Manage inventory →</a>
            </p>
        </div>
        </div>";

        foreach ($managers as $email) {
            $ok = sendAlert($email, $subject, $body);
            logEmailAlert($db, 'low_stock_alert', $email, $subject, $body, $ok ? 'sent' : 'failed');
            if ($ok) $sent++;
        }
    }
} catch (Throwable $e) {
    $errors[] = 'Low stock: ' . $e->getMessage();
}

// ── 3. Payment Reminders (unpaid invoices > 7 days) ──────────────────────────
try {
    $unpaidInvoices = $db->query("
        SELECT i.invoice_number, i.total, i.amount_paid,
               (i.total - i.amount_paid) AS balance,
               i.due_date, i.created_at,
               DATEDIFF(CURDATE(), i.created_at) AS days_old,
               c.name AS client_name, c.email AS client_email, c.phone AS client_phone
        FROM invoices i
        LEFT JOIN clients c ON c.id = i.client_id
        WHERE i.status IN ('unpaid','partial')
          AND DATEDIFF(CURDATE(), i.created_at) >= 7
        ORDER BY days_old DESC
        LIMIT 20
    ")->fetchAll();

    if ($unpaidInvoices) {
        // Notify finance/sales officers
        $officers = $db->query("
            SELECT email FROM users
            WHERE role IN ('admin','sales_officer','super_admin')
              AND status = 'active' AND email IS NOT NULL AND email != ''
            LIMIT 5
        ")->fetchAll(PDO::FETCH_COLUMN);

        $rows = '';
        foreach ($unpaidInvoices as $inv) {
            $rows .= "<tr>
                <td style='padding:8px 12px;border-bottom:1px solid #f1f5f9;font-weight:600'>{$inv['invoice_number']}</td>
                <td style='padding:8px 12px;border-bottom:1px solid #f1f5f9'>" . htmlspecialchars($inv['client_name'] ?? '—') . "</td>
                <td style='padding:8px 12px;border-bottom:1px solid #f1f5f9;color:#16a34a;font-weight:600'>KES " . number_format((float)$inv['balance'], 2) . "</td>
                <td style='padding:8px 12px;border-bottom:1px solid #f1f5f9;color:#dc2626'>{$inv['days_old']} days</td>
            </tr>";
        }

        $totalBalance = array_sum(array_column($unpaidInvoices, 'balance'));
        $subject = '💰 ' . count($unpaidInvoices) . ' Unpaid Invoice(s) — KES ' . number_format($totalBalance, 0) . ' outstanding';
        $body = "
        <div style='font-family:Inter,sans-serif;max-width:700px;margin:0 auto'>
        <div style='background:#dc2626;padding:20px 24px;border-radius:8px 8px 0 0'>
            <h2 style='color:#fff;margin:0;font-size:18px'>💰 Payment Reminders</h2>
            <p style='color:rgba(255,255,255,.8);margin:4px 0 0;font-size:13px'>" . date('l, d F Y') . " — Total Outstanding: KES " . number_format($totalBalance, 2) . "</p>
        </div>
        <div style='background:#fff;padding:24px;border:1px solid #e2e8f0;border-radius:0 0 8px 8px'>
            <table style='width:100%;border-collapse:collapse;font-size:13px'>
                <thead>
                    <tr style='background:#f8fafc'>
                        <th style='padding:8px 12px;text-align:left;font-size:11px;color:#64748b;text-transform:uppercase'>Invoice #</th>
                        <th style='padding:8px 12px;text-align:left;font-size:11px;color:#64748b;text-transform:uppercase'>Client</th>
                        <th style='padding:8px 12px;text-align:left;font-size:11px;color:#64748b;text-transform:uppercase'>Balance</th>
                        <th style='padding:8px 12px;text-align:left;font-size:11px;color:#64748b;text-transform:uppercase'>Age</th>
                    </tr>
                </thead>
                <tbody>{$rows}</tbody>
            </table>
            <p style='margin:20px 0 0;font-size:13px;color:#64748b'>
                <a href='" . getSetting('base_url', 'http://localhost:8001') . "/modules/invoices/index.php?status=unpaid' style='color:#2563eb'>→ View all unpaid invoices</a>
            </p>
        </div>
        </div>";

        foreach ($officers as $email) {
            $ok = sendAlert($email, $subject, $body);
            logEmailAlert($db, 'payment_reminder', $email, $subject, $body, $ok ? 'sent' : 'failed');
            if ($ok) $sent++;
        }
    }
} catch (Throwable $e) {
    $errors[] = 'Payment reminders: ' . $e->getMessage();
}

// ── Finalize & log ─────────────────────────────────────────────────────────────
$ms      = (int)((microtime(true) - $startTime) * 1000);
$status  = empty($errors) ? 'success' : 'error';
$message = empty($errors)
    ? "Sent {$sent} alert email(s) successfully."
    : "Sent {$sent} email(s) with errors: " . implode('; ', $errors);

cronLog($db, $jobName, $status, $sent, $message, $ms);

// CLI output
if (PHP_SAPI === 'cli') {
    echo "[" . date('Y-m-d H:i:s') . "] daily_alerts: {$message} ({$ms}ms)\n";
    if ($errors) {
        foreach ($errors as $err) echo "  ERROR: {$err}\n";
    }
}

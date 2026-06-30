<?php
/**
 * Cron: Document Expiry Reminders
 * Run daily: 0 8 * * * php /path/to/cron_doc_expiry.php
 *
 * Sends email to admin/super_admin/workshop_manager when car documents
 * are expired or expiring within the next 30 days.
 */
define('RUNNING_CRON', true);
require_once __DIR__ . '/../../includes/functions.php';

$db = getDB();

// Collect expiring / expired docs
try {
    $docs = $db->query("
        SELECT cd.id, cd.doc_type, cd.title, cd.expiry_date,
               c.make, c.model, c.chassis_number, c.registration_number
        FROM car_documents cd
        JOIN cars c ON c.id = cd.car_id
        WHERE cd.expiry_date IS NOT NULL
          AND cd.expiry_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)
        ORDER BY cd.expiry_date ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (\Throwable $e) {
    echo "[ERROR] Could not query car_documents: " . $e->getMessage() . "\n";
    exit(1);
}

if (empty($docs)) {
    echo "[OK] No expiring or expired documents. Nothing to send.\n";
    exit(0);
}

$expired = array_filter($docs, fn($d) => $d['expiry_date'] < date('Y-m-d'));
$soon    = array_filter($docs, fn($d) => $d['expiry_date'] >= date('Y-m-d'));

// Recipients
try {
    $recipients = $db->query("
        SELECT name, email FROM users
        WHERE role IN ('super_admin','admin','workshop_manager')
          AND status = 'active'
          AND email IS NOT NULL AND email != ''
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (\Throwable $e) {
    echo "[ERROR] Could not query recipients: " . $e->getMessage() . "\n";
    exit(1);
}

if (empty($recipients)) {
    echo "[OK] No recipients configured.\n";
    exit(0);
}

$co   = getSetting('company_name', 'Mascardi Car Yard');
$base = BASE_URL;

// Build HTML table rows
$tableRows = '';
$docTypeLabels = [
    'logbook'           => 'Logbook',
    'import_entry'      => 'Import Entry',
    'ntsa_inspection'   => 'NTSA Inspection',
    'ntsa_registration' => 'NTSA Registration',
    'insurance'         => 'Insurance',
    'duty_clearance'    => 'Duty Clearance',
    'purchase_invoice'  => 'Purchase Invoice',
    'other'             => 'Other',
];

foreach ($docs as $d) {
    $daysLeft = (int)(((strtotime($d['expiry_date']) - time()) / 86400));
    $isExpired = $daysLeft < 0;

    $statusColor  = $isExpired ? '#dc2626' : ($daysLeft <= 7 ? '#d97706' : '#ca8a04');
    $statusLabel  = $isExpired
        ? 'EXPIRED (' . abs($daysLeft) . 'd ago)'
        : "Expires in {$daysLeft}d";
    $dtLabel = $docTypeLabels[$d['doc_type']] ?? ucfirst($d['doc_type']);
    $vehicle = htmlspecialchars($d['make'] . ' ' . $d['model']);
    $chassis = htmlspecialchars($d['chassis_number']);
    $reg     = $d['registration_number'] ? htmlspecialchars($d['registration_number']) : '—';
    $docTitle = htmlspecialchars($d['title']);
    $expDate = date('d M Y', strtotime($d['expiry_date']));

    $tableRows .= "
    <tr>
        <td style='padding:8px 12px;border-bottom:1px solid #e2e8f0'>
            <div style='font-weight:600;font-size:13px'>{$vehicle}</div>
            <div style='font-size:11px;color:#64748b'>{$chassis} | Reg: {$reg}</div>
        </td>
        <td style='padding:8px 12px;border-bottom:1px solid #e2e8f0;font-size:13px'>{$docTitle}</td>
        <td style='padding:8px 12px;border-bottom:1px solid #e2e8f0;font-size:12px;color:#64748b'>{$dtLabel}</td>
        <td style='padding:8px 12px;border-bottom:1px solid #e2e8f0;font-size:12px'>{$expDate}</td>
        <td style='padding:8px 12px;border-bottom:1px solid #e2e8f0'>
            <span style='display:inline-block;padding:2px 8px;border-radius:12px;background:{$statusColor}18;color:{$statusColor};font-size:11px;font-weight:700'>{$statusLabel}</span>
        </td>
    </tr>";
}

$expiredCount = count($expired);
$soonCount    = count($soon);
$total        = count($docs);

$subject = "[{$co}] Document Expiry Alert — {$total} document" . ($total > 1 ? 's' : '') . " require attention";

$html = "
<!DOCTYPE html>
<html><head><meta charset='UTF-8'></head>
<body style='margin:0;padding:0;background:#f1f5f9;font-family:Inter,Arial,sans-serif'>
<div style='max-width:680px;margin:32px auto;background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 4px 16px rgba(0,0,0,.08)'>

  <!-- Header -->
  <div style='background:linear-gradient(120deg,#0f172a 0%,#1e3a8a 55%,#2563eb 100%);padding:28px 32px'>
    <div style='color:#fff;font-size:20px;font-weight:800;margin-bottom:4px'>{$co}</div>
    <div style='color:rgba(255,255,255,.6);font-size:13px'>Document Expiry Alert &mdash; " . date('d F Y') . "</div>
  </div>

  <!-- Summary pills -->
  <div style='padding:20px 32px 0;display:flex;gap:12px;flex-wrap:wrap'>
    " . ($expiredCount > 0 ? "
    <div style='background:#fef2f2;border:1px solid #fecaca;border-radius:8px;padding:12px 20px;text-align:center;min-width:120px'>
      <div style='font-size:28px;font-weight:800;color:#dc2626'>{$expiredCount}</div>
      <div style='font-size:11px;color:#64748b;text-transform:uppercase;letter-spacing:.05em'>Expired</div>
    </div>" : "") . "
    " . ($soonCount > 0 ? "
    <div style='background:#fffbeb;border:1px solid #fde68a;border-radius:8px;padding:12px 20px;text-align:center;min-width:120px'>
      <div style='font-size:28px;font-weight:800;color:#d97706'>{$soonCount}</div>
      <div style='font-size:11px;color:#64748b;text-transform:uppercase;letter-spacing:.05em'>Expiring Soon</div>
    </div>" : "") . "
  </div>

  <!-- Table -->
  <div style='padding:20px 32px'>
    <p style='font-size:14px;color:#374151;margin-bottom:16px'>
      The following car documents require immediate attention:
    </p>
    <table width='100%' style='border-collapse:collapse;font-size:13px'>
      <thead>
        <tr style='background:#f8fafc'>
          <th style='padding:8px 12px;text-align:left;font-size:11px;font-weight:600;color:#64748b;text-transform:uppercase;letter-spacing:.05em;border-bottom:2px solid #e2e8f0'>Vehicle</th>
          <th style='padding:8px 12px;text-align:left;font-size:11px;font-weight:600;color:#64748b;text-transform:uppercase;letter-spacing:.05em;border-bottom:2px solid #e2e8f0'>Document</th>
          <th style='padding:8px 12px;text-align:left;font-size:11px;font-weight:600;color:#64748b;text-transform:uppercase;letter-spacing:.05em;border-bottom:2px solid #e2e8f0'>Type</th>
          <th style='padding:8px 12px;text-align:left;font-size:11px;font-weight:600;color:#64748b;text-transform:uppercase;letter-spacing:.05em;border-bottom:2px solid #e2e8f0'>Expiry</th>
          <th style='padding:8px 12px;text-align:left;font-size:11px;font-weight:600;color:#64748b;text-transform:uppercase;letter-spacing:.05em;border-bottom:2px solid #e2e8f0'>Status</th>
        </tr>
      </thead>
      <tbody>
        {$tableRows}
      </tbody>
    </table>
  </div>

  <!-- CTA -->
  <div style='padding:0 32px 28px'>
    <a href='{$base}/modules/car_documents/index.php?expiry=expired'
       style='display:inline-block;background:#2563eb;color:#fff;padding:11px 22px;border-radius:8px;font-size:13px;font-weight:600;text-decoration:none'>
      View All Documents &rarr;
    </a>
  </div>

  <!-- Footer -->
  <div style='padding:16px 32px;background:#f8fafc;border-top:1px solid #e2e8f0;font-size:11px;color:#94a3b8;text-align:center'>
    Automated alert from {$co} Management System &mdash; " . date('d F Y, H:i') . "
  </div>
</div>
</body></html>";

$sent = 0;
foreach ($recipients as $r) {
    try {
        sendMail($r['email'], $r['name'], $subject, $html, 'doc_expiry_alert', 0);
        echo "[OK] Sent to {$r['name']} <{$r['email']}>\n";
        $sent++;
    } catch (\Throwable $e) {
        echo "[ERROR] Failed for {$r['email']}: " . $e->getMessage() . "\n";
    }
}

echo "[DONE] Sent to {$sent} recipient(s). {$expiredCount} expired, {$soonCount} expiring soon.\n";

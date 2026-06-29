<?php
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
canAccess('crm') || redirect(BASE_URL . '/index.php');

$db = getDB();
$me = authUser();

// Ensure extended columns exist (same migrations as view_lead.php)
try { $db->exec("ALTER TABLE cars ADD COLUMN entry_number VARCHAR(100) NULL DEFAULT NULL"); } catch (\Throwable $_) {}
try { $db->exec("ALTER TABLE cars ADD COLUMN chassis_number VARCHAR(100) NULL DEFAULT NULL"); } catch (\Throwable $_) {}
try { $db->exec("ALTER TABLE crm_test_drives ADD COLUMN driver_id_no VARCHAR(50) NULL"); } catch (\Throwable $_) {}
try { $db->exec("ALTER TABLE crm_test_drives ADD COLUMN kd_number VARCHAR(50) NULL"); } catch (\Throwable $_) {}
try { $db->exec("ALTER TABLE crm_test_drives ADD COLUMN chassis_number VARCHAR(100) NULL"); } catch (\Throwable $_) {}
try { $db->exec("ALTER TABLE crm_test_drives ADD COLUMN entry_number VARCHAR(100) NULL"); } catch (\Throwable $_) {}

$tdId = (int)($_GET['id'] ?? 0);
if (!$tdId) {
    setFlash('error', 'No test drive specified.');
    redirect(BASE_URL . '/modules/crm/leads.php');
}

// Load test drive + lead + car
$stmt = $db->prepare("
    SELECT
        td.id              AS td_id,
        td.scheduled_date,
        td.scheduled_time,
        td.duration_minutes,
        td.notes           AS td_notes,
        td.driver_id_no,
        td.kd_number,
        td.chassis_number  AS td_chassis,
        td.entry_number    AS td_entry,
        td.status          AS td_status,

        l.id               AS lead_id,
        l.name             AS lead_name,
        l.phone            AS lead_phone,
        l.email            AS lead_email,
        l.stage            AS lead_stage,

        c.id               AS car_id,
        c.make,
        c.model,
        c.year,
        c.color,
        c.registration_number,
        COALESCE(td.chassis_number, c.chassis_number, '') AS chassis_number,
        COALESCE(td.entry_number,   c.entry_number,   '') AS entry_number

    FROM crm_test_drives td
    JOIN crm_leads l ON l.id = td.lead_id
    LEFT JOIN cars c ON c.id = td.car_id
    WHERE td.id = ?
    LIMIT 1
");
try {
    $stmt->execute([$tdId]);
    $td = $stmt->fetch();
} catch (\Throwable $e) {
    error_log('test_drive_slip query error: ' . $e->getMessage());
    setFlash('error', 'Could not load test drive slip. ' . $e->getMessage());
    redirect(BASE_URL . '/modules/crm/leads.php');
}

if (!$td) {
    setFlash('error', 'Test drive not found.');
    redirect(BASE_URL . '/modules/crm/leads.php');
}

// CRM agents may only view slips for their own leads
if ($me['role'] === 'customer_relations') {
    $check = $db->prepare("SELECT assigned_to FROM crm_leads WHERE id = ?");
    $check->execute([$td['lead_id']]);
    $assigned = (int)($check->fetchColumn() ?: 0);
    if ($assigned !== (int)$me['id']) {
        setFlash('error', 'Access denied.');
        redirect(BASE_URL . '/modules/crm/my_dashboard.php');
    }
}

// Booking reference
$ref = 'TD-' . date('Y', strtotime($td['scheduled_date'])) . '-' . str_pad($tdId, 5, '0', STR_PAD_LEFT);

// Company details from settings (fall back to defaults)
$companyName    = getSetting('company_name',    'Mascardi Luxury Cars');
$companyPhone   = getSetting('company_phone',   '');
$companyEmail   = getSetting('company_email',   '');
$companyAddress = getSetting('company_address', 'Nairobi, Kenya');
$companyLogo    = BASE_URL . '/assets/img/logo.png';

function e2($v): string { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Test Drive Slip — <?= e2($ref) ?></title>
<style>
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

  body {
    font-family: 'Segoe UI', Arial, sans-serif;
    font-size: 13px;
    color: #1a1a1a;
    background: #f0f2f5;
  }

  /* ── Print controls (screen only) ── */
  .print-controls {
    background: #1e293b;
    color: #fff;
    padding: 12px 24px;
    display: flex;
    align-items: center;
    gap: 12px;
    position: sticky;
    top: 0;
    z-index: 100;
    print-color-adjust: exact;
  }
  .print-controls h6 { flex: 1; font-size: 14px; font-weight: 600; }
  .print-controls a, .print-controls button {
    padding: 7px 16px;
    border-radius: 6px;
    font-size: 12px;
    font-weight: 600;
    cursor: pointer;
    border: none;
    text-decoration: none;
  }
  .btn-print  { background: #3b82f6; color: #fff; }
  .btn-back   { background: transparent; color: #94a3b8; border: 1px solid #475569 !important; }

  /* ── Slip paper ── */
  .slip-wrap {
    max-width: 800px;
    margin: 32px auto;
    background: #fff;
    border-radius: 10px;
    box-shadow: 0 4px 24px rgba(0,0,0,.12);
    overflow: hidden;
  }

  /* Header band */
  .slip-header {
    background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%);
    color: #fff;
    padding: 28px 32px 20px;
    display: flex;
    align-items: center;
    gap: 20px;
  }
  .slip-header img { height: 56px; width: auto; border-radius: 6px; background: #fff; padding: 4px; }
  .slip-header-text h1 { font-size: 20px; font-weight: 700; letter-spacing: .02em; }
  .slip-header-text p  { font-size: 11px; color: #94a3b8; margin-top: 2px; }

  /* Ref band */
  .slip-ref {
    background: #f8fafc;
    border-bottom: 1px solid #e2e8f0;
    padding: 12px 32px;
    display: flex;
    align-items: center;
    justify-content: space-between;
  }
  .slip-ref .ref-badge {
    background: #1e293b;
    color: #f8fafc;
    font-size: 13px;
    font-weight: 700;
    padding: 4px 14px;
    border-radius: 20px;
    letter-spacing: .04em;
  }
  .slip-ref .ref-meta { font-size: 11px; color: #64748b; text-align: right; line-height: 1.6; }

  /* Body */
  .slip-body { padding: 24px 32px 32px; }

  /* Section */
  .section { margin-bottom: 20px; }
  .section-title {
    font-size: 10px;
    font-weight: 700;
    letter-spacing: .1em;
    text-transform: uppercase;
    color: #64748b;
    border-bottom: 1px solid #e2e8f0;
    padding-bottom: 6px;
    margin-bottom: 12px;
  }

  /* Grid */
  .info-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px 24px; }
  .info-grid.cols-3 { grid-template-columns: 1fr 1fr 1fr; }
  .info-item label { display: block; font-size: 10px; font-weight: 600; color: #94a3b8; text-transform: uppercase; letter-spacing: .06em; margin-bottom: 2px; }
  .info-item span  { font-size: 13px; font-weight: 500; color: #1e293b; }
  .info-item.full  { grid-column: 1 / -1; }

  /* Status badge */
  .status-badge {
    display: inline-block;
    padding: 2px 10px;
    border-radius: 12px;
    font-size: 11px;
    font-weight: 700;
    text-transform: capitalize;
    background: #dcfce7; color: #15803d;
  }
  .status-badge.scheduled { background: #dbeafe; color: #1d4ed8; }
  .status-badge.cancelled { background: #fee2e2; color: #b91c1c; }
  .status-badge.completed { background: #dcfce7; color: #15803d; }

  /* Signature section */
  .sig-section {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 32px;
    margin-top: 28px;
    padding-top: 20px;
    border-top: 1px dashed #cbd5e1;
  }
  .sig-box { }
  .sig-box .sig-label { font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: .08em; color: #64748b; margin-bottom: 40px; }
  .sig-box .sig-line  { border-bottom: 1px solid #1e293b; margin-bottom: 4px; }
  .sig-box .sig-name  { font-size: 10px; color: #64748b; }

  /* Footer */
  .slip-footer {
    background: #f8fafc;
    border-top: 1px solid #e2e8f0;
    padding: 12px 32px;
    text-align: center;
    font-size: 10px;
    color: #94a3b8;
  }

  /* ── Print styles ── */
  @media print {
    body { background: #fff; }
    .print-controls { display: none !important; }
    .slip-wrap { margin: 0; border-radius: 0; box-shadow: none; max-width: 100%; }
    .slip-header { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    .ref-badge { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    @page { margin: 8mm 10mm; }
  }
</style>
</head>
<body>

<!-- ── Screen controls ── -->
<div class="print-controls">
  <h6>Test Drive Slip — <?= e2($ref) ?></h6>
  <a href="<?= BASE_URL ?>/modules/crm/view_lead.php?id=<?= $td['lead_id'] ?>" class="btn-back">← Back to Lead</a>
  <button class="btn-print" onclick="window.print()">&#128424; Print / Save PDF</button>
</div>

<!-- ── Slip ── -->
<div class="slip-wrap">

  <!-- Header -->
  <div class="slip-header">
    <img src="<?= e2($companyLogo) ?>" alt="Logo" onerror="this.style.display='none'">
    <div class="slip-header-text">
      <h1><?= e2($companyName) ?></h1>
      <p>
        <?php if ($companyPhone): ?><?= e2($companyPhone) ?>&nbsp;&nbsp;|&nbsp;&nbsp;<?php endif; ?>
        <?php if ($companyEmail): ?><?= e2($companyEmail) ?>&nbsp;&nbsp;|&nbsp;&nbsp;<?php endif; ?>
        <?= e2($companyAddress) ?>
      </p>
    </div>
  </div>

  <!-- Reference band -->
  <div class="slip-ref">
    <span class="ref-badge"><?= e2($ref) ?></span>
    <div class="ref-meta">
      <strong>TEST DRIVE BOOKING SLIP</strong><br>
      Printed: <?= date('d M Y, H:i') ?>
    </div>
  </div>

  <!-- Body -->
  <div class="slip-body">

    <!-- ── Booking details ── -->
    <div class="section">
      <div class="section-title">Booking Details</div>
      <div class="info-grid cols-3">
        <div class="info-item">
          <label>Booking Ref</label>
          <span><?= e2($ref) ?></span>
        </div>
        <div class="info-item">
          <label>Date &amp; Time</label>
          <span><?= e2(date('d M Y', strtotime($td['scheduled_date'])) . ', ' . date('h:i A', strtotime($td['scheduled_time']))) ?></span>
        </div>
        <div class="info-item">
          <label>Duration</label>
          <span>
            <?php
            $dur = (int)$td['duration_minutes'];
            if ($dur >= 60) echo floor($dur/60) . 'h' . ($dur % 60 ? ' ' . ($dur%60) . 'min' : '');
            else echo $dur . ' min';
            ?>
          </span>
        </div>
        <div class="info-item">
          <label>Status</label>
          <span>
            <span class="status-badge <?= e2($td['td_status'] ?? 'scheduled') ?>">
              <?= e2(ucfirst($td['td_status'] ?? 'scheduled')) ?>
            </span>
          </span>
        </div>
        <?php if ($td['td_notes']): ?>
        <div class="info-item full">
          <label>Notes</label>
          <span><?= e2($td['td_notes']) ?></span>
        </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- ── Customer / Lead details ── -->
    <div class="section">
      <div class="section-title">Customer Details</div>
      <div class="info-grid">
        <div class="info-item">
          <label>Full Name</label>
          <span><?= e2($td['lead_name']) ?></span>
        </div>
        <div class="info-item">
          <label>Phone</label>
          <span><?= e2($td['lead_phone'] ?: '—') ?></span>
        </div>
        <div class="info-item">
          <label>Email</label>
          <span><?= e2($td['lead_email'] ?: '—') ?></span>
        </div>
        <div class="info-item">
          <label>Lead Stage</label>
          <span><?= e2(ucwords(str_replace('_', ' ', $td['lead_stage'] ?? ''))) ?></span>
        </div>
      </div>
    </div>

    <!-- ── Vehicle details ── -->
    <div class="section">
      <div class="section-title">Vehicle Details</div>
      <?php if ($td['car_id']): ?>
      <div class="info-grid cols-3">
        <div class="info-item">
          <label>Make / Model</label>
          <span><?= e2(trim(($td['make'] ?? '') . ' ' . ($td['model'] ?? ''))) ?></span>
        </div>
        <div class="info-item">
          <label>Year</label>
          <span><?= e2($td['year'] ?? '—') ?></span>
        </div>
        <div class="info-item">
          <label>Color</label>
          <span><?= e2($td['color'] ?? '—') ?></span>
        </div>
        <div class="info-item">
          <label>Registration</label>
          <span><?= e2($td['registration_number'] ?: '—') ?></span>
        </div>
        <div class="info-item">
          <label>Chassis Number</label>
          <span><?= e2($td['chassis_number'] ?: '—') ?></span>
        </div>
        <div class="info-item">
          <label>Entry / IDF Number</label>
          <span><?= e2($td['entry_number'] ?: '—') ?></span>
        </div>
      </div>
      <?php else: ?>
      <p style="color:#64748b;font-size:12px;">No specific vehicle was assigned to this test drive.</p>
      <?php endif; ?>
    </div>

    <!-- ── Driver details ── -->
    <div class="section">
      <div class="section-title">Driver Details</div>
      <div class="info-grid">
        <div class="info-item">
          <label>National ID No</label>
          <span><?= e2($td['driver_id_no'] ?: '—') ?></span>
        </div>
        <div class="info-item">
          <label>KD Number (Driver's Licence)</label>
          <span><?= e2($td['kd_number'] ?: '—') ?></span>
        </div>
      </div>
    </div>

    <!-- ── Signatures ── -->
    <div class="sig-section">
      <div class="sig-box">
        <div class="sig-label">Customer Signature</div>
        <div class="sig-line"></div>
        <div class="sig-name"><?= e2($td['lead_name']) ?></div>
      </div>
      <div class="sig-box">
        <div class="sig-label">Authorised by (<?= e2($companyName) ?>)</div>
        <div class="sig-line"></div>
        <div class="sig-name">Name &amp; Designation</div>
      </div>
    </div>

  </div><!-- /slip-body -->

  <div class="slip-footer">
    This slip is computer-generated and is valid as proof of a scheduled test drive.
    &nbsp;|&nbsp; <?= e2($companyName) ?> &nbsp;|&nbsp; <?= e2($companyAddress) ?>
  </div>

</div><!-- /slip-wrap -->

</body>
</html>

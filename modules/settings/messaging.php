<?php
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
requireRole('admin');

$pageTitle = 'Messaging & Alerts';
$db        = getDB();

// ── Inline sms_log migration ──────────────────────────────────────────────────
try { $db->exec("CREATE TABLE IF NOT EXISTS sms_log (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    channel    VARCHAR(20) DEFAULT 'sms',
    phone      VARCHAR(30) NOT NULL,
    message    TEXT,
    ref_type   VARCHAR(50),
    ref_id     INT DEFAULT 0,
    status     VARCHAR(20) DEFAULT 'sent',
    response   TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ref (ref_type, ref_id),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); } catch (\Throwable $_) {}

// ── Defaults ──────────────────────────────────────────────────────────────────
$credKeys = [
    'at_api_key'     => '',
    'at_username'    => '',
    'at_sender_id'   => '',
    'twilio_sid'     => '',
    'twilio_token'   => '',
    'twilio_wa_from' => '',
];
$ruleEvents = [
    'sale'         => 'Vehicle Sale Confirmed',
    'job_complete' => 'Workshop Job Completed',
    'payment'      => 'Payment Received',
    'booking'      => 'Service Booking Confirmed',
];
$ruleChannels = ['sms', 'whatsapp'];

$rows     = $db->query("SELECT setting_key, setting_value FROM settings")->fetchAll();
$settings = array_column($rows, 'setting_value', 'setting_key');

$stmt = $db->prepare("INSERT IGNORE INTO settings (setting_key, setting_value) VALUES (?,?)");
foreach ($credKeys as $k => $v) {
    if (!array_key_exists($k, $settings)) { $stmt->execute([$k, $v]); $settings[$k] = $v; }
}
foreach ($ruleEvents as $ev => $_) {
    foreach ($ruleChannels as $ch) {
        $key = "alert_{$ch}_{$ev}";
        if (!array_key_exists($key, $settings)) { $stmt->execute([$key, '0']); $settings[$key] = '0'; }
    }
}

$activeTab = $_GET['tab'] ?? 'credentials';

// ── POST: save credentials ────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_credentials') {
    $uStmt = $db->prepare("INSERT INTO settings (setting_key,setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)");
    $updates = [
        'at_username'    => trim($_POST['at_username']    ?? ''),
        'at_sender_id'   => trim($_POST['at_sender_id']   ?? ''),
        'twilio_sid'     => trim($_POST['twilio_sid']     ?? ''),
        'twilio_wa_from' => trim($_POST['twilio_wa_from'] ?? ''),
    ];
    // Never overwrite secrets with blank — only update if provided
    if (!empty($_POST['at_api_key']))    $updates['at_api_key']    = trim($_POST['at_api_key']);
    if (!empty($_POST['twilio_token'])) $updates['twilio_token'] = trim($_POST['twilio_token']);
    foreach ($updates as $k => $v) { $uStmt->execute([$k, $v]); $settings[$k] = $v; }
    setFlash('success', 'API credentials saved.');
    redirect(BASE_URL . '/modules/settings/messaging.php?tab=credentials');
}

// ── POST: save alert rules ────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_rules') {
    $uStmt = $db->prepare("INSERT INTO settings (setting_key,setting_value) VALUES (?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)");
    foreach ($ruleEvents as $ev => $_) {
        foreach ($ruleChannels as $ch) {
            $key = "alert_{$ch}_{$ev}";
            $val = isset($_POST[$key]) ? '1' : '0';
            $uStmt->execute([$key, $val]);
            $settings[$key] = $val;
        }
    }
    setFlash('success', 'Alert rules saved.');
    redirect(BASE_URL . '/modules/settings/messaging.php?tab=rules');
}

// ── POST: test SMS ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'test_sms') {
    header('Content-Type: application/json');
    require_once __DIR__ . '/../../includes/sms.php';
    $phone = trim($_POST['phone'] ?? '');
    if (!$phone) { echo json_encode(['ok' => false, 'error' => 'Phone number required']); exit; }
    $co     = getSetting('company_name', 'Mascardi');
    $result = sendSms($phone, "Test SMS from {$co} Management System. If you received this, SMS is configured correctly.", 'test', 0);
    echo json_encode($result);
    exit;
}

// ── POST: test WhatsApp ───────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'test_whatsapp') {
    header('Content-Type: application/json');
    require_once __DIR__ . '/../../includes/whatsapp.php';
    $phone = trim($_POST['phone'] ?? '');
    if (!$phone) { echo json_encode(['ok' => false, 'error' => 'Phone number required']); exit; }
    $co     = getSetting('company_name', 'Mascardi');
    $result = sendWhatsApp($phone, "Test WhatsApp message from *{$co}* Management System. If you received this, WhatsApp is configured correctly.", 'test', 0);
    echo json_encode($result);
    exit;
}

// ── Recent SMS log ────────────────────────────────────────────────────────────
$logPage   = max(1, (int)($_GET['lpage'] ?? 1));
$logPer    = 30;
$logOffset = ($logPage - 1) * $logPer;
try {
    $logTotal = (int)$db->query("SELECT COUNT(*) FROM sms_log")->fetchColumn();
    $logs     = $db->query("SELECT * FROM sms_log ORDER BY created_at DESC LIMIT $logPer OFFSET $logOffset")->fetchAll();
} catch (\Throwable $_) {
    $logTotal = 0; $logs = [];
}

$smsOk = !empty($settings['at_api_key']) && !empty($settings['at_username']);
$waOk  = !empty($settings['twilio_sid']) && !empty($settings['twilio_token']) && !empty($settings['twilio_wa_from']);

include __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h5 class="mb-1"><i class="fa fa-comment-sms me-2 text-primary"></i>Messaging & Alerts</h5>
        <div class="text-muted small">Configure SMS and WhatsApp integrations and alert channel rules</div>
    </div>
    <a href="index.php" class="btn btn-sm btn-outline-secondary">
        <i class="fa fa-arrow-left me-1"></i>Back to Settings
    </a>
</div>

<!-- Status row -->
<div class="row g-3 mb-4">
    <div class="col-auto">
        <div class="d-flex align-items-center gap-2 px-3 py-2 rounded border" style="font-size:13px">
            <span class="badge bg-<?= $smsOk ? 'success' : 'secondary' ?>" style="font-size:10px">
                <?= $smsOk ? 'Active' : 'Not Configured' ?>
            </span>
            <i class="fa fa-comment-sms"></i> SMS via Africa's Talking
        </div>
    </div>
    <div class="col-auto">
        <div class="d-flex align-items-center gap-2 px-3 py-2 rounded border" style="font-size:13px">
            <span class="badge bg-<?= $waOk ? 'success' : 'secondary' ?>" style="font-size:10px">
                <?= $waOk ? 'Active' : 'Not Configured' ?>
            </span>
            <i class="fa fa-brands fa-whatsapp" style="color:#25d366"></i> WhatsApp via Twilio
        </div>
    </div>
    <div class="col-auto">
        <div class="d-flex align-items-center gap-2 px-3 py-2 rounded border" style="font-size:13px">
            <span class="badge bg-info" style="font-size:10px"><?= number_format($logTotal) ?></span>
            Messages sent total
        </div>
    </div>
</div>

<!-- Tabs -->
<ul class="nav nav-tabs mb-4">
    <?php $tabs = [
        'credentials' => ['fa-key',         'API Credentials'],
        'rules'       => ['fa-sliders',      'Alert Rules'],
        'log'         => ['fa-list-check',   'Message Log'],
    ]; foreach ($tabs as $tid => [$icon, $lbl]): ?>
    <li class="nav-item">
        <a class="nav-link <?= $activeTab === $tid ? 'active' : '' ?>"
           href="?tab=<?= $tid ?>">
            <i class="fa <?= $icon ?> me-1"></i><?= $lbl ?>
        </a>
    </li>
    <?php endforeach; ?>
</ul>

<div class="tab-content">

<!-- ══ CREDENTIALS ═══════════════════════════════════════════════════════════ -->
<?php if ($activeTab === 'credentials'): ?>
<form method="POST">
<input type="hidden" name="action" value="save_credentials">

<div class="row g-4">

    <!-- Africa's Talking -->
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header d-flex align-items-center gap-2">
                <i class="fa fa-comment-sms text-primary"></i>
                <span class="fw-semibold">Africa's Talking — SMS</span>
                <span class="badge bg-<?= $smsOk ? 'success' : 'secondary' ?> ms-auto" style="font-size:10px">
                    <?= $smsOk ? 'Configured' : 'Not Set' ?>
                </span>
            </div>
            <div class="card-body">
                <div class="alert alert-info py-2 small mb-3">
                    <i class="fa fa-info-circle me-1"></i>
                    Get credentials at <strong>africastalking.com</strong> → Account → API Keys.
                    Use a <strong>Sandbox</strong> account for testing first.
                </div>
                <div class="mb-3">
                    <label class="form-label">API Key <span class="text-danger">*</span></label>
                    <input type="password" name="at_api_key" class="form-control font-monospace"
                           placeholder="<?= $smsOk ? '•••••••• (unchanged if blank)' : 'atsk_xxxxxxxx' ?>"
                           autocomplete="new-password">
                    <?php if ($smsOk): ?>
                    <div class="form-text text-success"><i class="fa fa-check-circle me-1"></i>API key is saved. Leave blank to keep current.</div>
                    <?php endif; ?>
                </div>
                <div class="mb-3">
                    <label class="form-label">Username <span class="text-danger">*</span></label>
                    <input type="text" name="at_username" class="form-control"
                           placeholder="sandbox or your AT username"
                           value="<?= e($settings['at_username'] ?? '') ?>">
                    <div class="form-text">Use <code>sandbox</code> for testing.</div>
                </div>
                <div class="mb-3">
                    <label class="form-label">Sender ID <span class="text-muted">(optional)</span></label>
                    <input type="text" name="at_sender_id" class="form-control"
                           placeholder="MASCARDI or leave blank for shared"
                           value="<?= e($settings['at_sender_id'] ?? '') ?>">
                    <div class="form-text">Alphanumeric, max 11 chars. Must be registered with AT.</div>
                </div>

                <!-- Test SMS -->
                <div class="border rounded p-3 bg-light">
                    <div class="fw-semibold small mb-2"><i class="fa fa-flask me-1"></i>Test SMS</div>
                    <p class="text-muted small mb-2">Save credentials first, then test.</p>
                    <div class="input-group input-group-sm">
                        <input type="text" id="testSmsPhone" class="form-control" placeholder="+254712345678">
                        <button type="button" class="btn btn-outline-primary" id="btnTestSms">Send Test</button>
                    </div>
                    <div id="testSmsResult" class="mt-2 small"></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Twilio WhatsApp -->
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header d-flex align-items-center gap-2">
                <i class="fa fa-brands fa-whatsapp" style="color:#25d366"></i>
                <span class="fw-semibold">Twilio — WhatsApp</span>
                <span class="badge bg-<?= $waOk ? 'success' : 'secondary' ?> ms-auto" style="font-size:10px">
                    <?= $waOk ? 'Configured' : 'Not Set' ?>
                </span>
            </div>
            <div class="card-body">
                <div class="alert alert-info py-2 small mb-3">
                    <i class="fa fa-info-circle me-1"></i>
                    Get credentials at <strong>twilio.com</strong> → Console → Account Info.
                    Enable the <strong>WhatsApp Sandbox</strong> under Messaging for testing.
                </div>
                <div class="mb-3">
                    <label class="form-label">Account SID <span class="text-danger">*</span></label>
                    <input type="text" name="twilio_sid" class="form-control font-monospace"
                           placeholder="ACxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx"
                           value="<?= e($settings['twilio_sid'] ?? '') ?>">
                </div>
                <div class="mb-3">
                    <label class="form-label">Auth Token <span class="text-danger">*</span></label>
                    <div class="input-group">
                        <input type="password" name="twilio_token" id="twilioTokenInput" class="form-control font-monospace"
                               placeholder="<?= $waOk ? '•••••••• (unchanged if blank)' : 'Your auth token' ?>"
                               autocomplete="new-password">
                        <button type="button" class="btn btn-outline-secondary"
                                onclick="var f=document.getElementById('twilioTokenInput');f.type=f.type==='text'?'password':'text'">
                            <i class="fa fa-eye"></i>
                        </button>
                    </div>
                    <?php if ($waOk): ?>
                    <div class="form-text text-success"><i class="fa fa-check-circle me-1"></i>Token saved. Leave blank to keep current.</div>
                    <?php endif; ?>
                </div>
                <div class="mb-3">
                    <label class="form-label">WhatsApp From Number <span class="text-danger">*</span></label>
                    <input type="text" name="twilio_wa_from" class="form-control"
                           placeholder="+14155238886 or whatsapp:+14155238886"
                           value="<?= e($settings['twilio_wa_from'] ?? '') ?>">
                    <div class="form-text">Twilio sandbox number or your approved WhatsApp Business number.</div>
                </div>

                <!-- Test WhatsApp -->
                <div class="border rounded p-3 bg-light">
                    <div class="fw-semibold small mb-2"><i class="fa fa-flask me-1"></i>Test WhatsApp</div>
                    <p class="text-muted small mb-2">Save credentials first, then test. For sandbox, the recipient must join first.</p>
                    <div class="input-group input-group-sm">
                        <input type="text" id="testWaPhone" class="form-control" placeholder="+254712345678">
                        <button type="button" class="btn btn-outline-success" id="btnTestWa">Send Test</button>
                    </div>
                    <div id="testWaResult" class="mt-2 small"></div>
                </div>
            </div>
        </div>
    </div>

</div>
<div class="mt-4">
    <button type="submit" class="btn btn-primary px-5"><i class="fa fa-save me-2"></i>Save Credentials</button>
</div>
</form>

<!-- ══ RULES ══════════════════════════════════════════════════════════════════ -->
<?php elseif ($activeTab === 'rules'): ?>

<div class="row g-4">
    <div class="col-lg-8">
        <form method="POST">
        <input type="hidden" name="action" value="save_rules">
        <div class="card">
            <div class="card-header fw-semibold">
                <i class="fa fa-sliders me-2 text-primary"></i>Alert Channel Rules
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                <table class="table table-bordered align-middle mb-0" style="font-size:13.5px">
                    <thead class="table-light">
                        <tr>
                            <th class="ps-3" style="width:40%">Event</th>
                            <th class="text-center" style="width:20%">
                                <i class="fa fa-bell me-1 text-primary"></i>In-App
                            </th>
                            <th class="text-center" style="width:20%">
                                <i class="fa fa-comment-sms me-1 text-info"></i>SMS
                                <?php if (!$smsOk): ?><span class="badge bg-secondary ms-1" style="font-size:9px">Not set</span><?php endif; ?>
                            </th>
                            <th class="text-center" style="width:20%">
                                <i class="fa fa-brands fa-whatsapp me-1" style="color:#25d366"></i>WhatsApp
                                <?php if (!$waOk): ?><span class="badge bg-secondary ms-1" style="font-size:9px">Not set</span><?php endif; ?>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($ruleEvents as $ev => $label): ?>
                        <tr>
                            <td class="ps-3">
                                <div class="fw-medium"><?= $label ?></div>
                                <div class="text-muted small">
                                    <?php echo match($ev) {
                                        'sale'         => 'Sends to buyer phone number',
                                        'job_complete' => 'Sends to vehicle last known owner',
                                        'payment'      => 'Sends to client on file',
                                        'booking'      => 'Sends to client phone number',
                                        default        => '',
                                    }; ?>
                                </div>
                            </td>
                            <td class="text-center">
                                <span class="badge bg-success-subtle text-success border border-success-subtle">
                                    <i class="fa fa-check me-1"></i>Always On
                                </span>
                            </td>
                            <td class="text-center">
                                <div class="form-check form-switch d-inline-block">
                                    <input class="form-check-input" type="checkbox"
                                           name="alert_sms_<?= $ev ?>"
                                           id="sms_<?= $ev ?>"
                                           <?= !$smsOk ? 'disabled title="Configure SMS credentials first"' : '' ?>
                                           <?= ($settings["alert_sms_{$ev}"] ?? '0') === '1' ? 'checked' : '' ?>>
                                </div>
                            </td>
                            <td class="text-center">
                                <div class="form-check form-switch d-inline-block">
                                    <input class="form-check-input" type="checkbox"
                                           name="alert_whatsapp_<?= $ev ?>"
                                           id="wa_<?= $ev ?>"
                                           <?= !$waOk ? 'disabled title="Configure WhatsApp credentials first"' : '' ?>
                                           <?= ($settings["alert_whatsapp_{$ev}"] ?? '0') === '1' ? 'checked' : '' ?>>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
            </div>
            <div class="card-footer bg-white d-flex align-items-center justify-content-between">
                <span class="text-muted small">In-App alerts are always enabled and cannot be disabled.</span>
                <button type="submit" class="btn btn-primary btn-sm px-4">
                    <i class="fa fa-save me-1"></i>Save Rules
                </button>
            </div>
        </div>
        </form>
    </div>
    <div class="col-lg-4">
        <div class="card">
            <div class="card-header fw-semibold"><i class="fa fa-circle-info me-2 text-primary"></i>How It Works</div>
            <div class="card-body" style="font-size:13px">
                <p class="text-muted">When a key event occurs in the system:</p>
                <ul class="text-muted ps-3" style="line-height:2">
                    <li><strong>In-App</strong> — always fires; visible in the bell menu and notification centre</li>
                    <li><strong>SMS</strong> — sends a text message to the customer's registered phone via Africa's Talking</li>
                    <li><strong>WhatsApp</strong> — sends a WhatsApp message to the customer via Twilio</li>
                </ul>
                <div class="alert alert-warning py-2 small mb-0">
                    <i class="fa fa-triangle-exclamation me-1"></i>
                    SMS and WhatsApp rules only fire if the customer's phone number is on file.
                </div>
            </div>
        </div>
        <?php if (!$smsOk || !$waOk): ?>
        <div class="card mt-3">
            <div class="card-body py-3 small text-muted">
                <i class="fa fa-lock me-1"></i>
                SMS/WhatsApp toggles are disabled until you
                <a href="?tab=credentials">configure the API credentials</a>.
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- ══ LOG ════════════════════════════════════════════════════════════════════ -->
<?php elseif ($activeTab === 'log'): ?>

<div class="card">
    <div class="card-header d-flex align-items-center gap-2">
        <i class="fa fa-list-check me-1 text-primary"></i>
        <span class="fw-semibold">Message Log</span>
        <span class="badge bg-secondary ms-auto"><?= number_format($logTotal) ?> total</span>
    </div>
    <div class="card-body p-0">
    <?php if (empty($logs)): ?>
        <div class="text-center py-5 text-muted">
            <i class="fa fa-comment-slash fa-2x mb-2 d-block opacity-25"></i>
            No messages sent yet.
        </div>
    <?php else: ?>
        <table class="table table-hover align-middle mb-0" style="font-size:13px">
            <thead>
                <tr>
                    <th class="ps-3">Date / Time</th>
                    <th>Channel</th>
                    <th>Phone</th>
                    <th>Message</th>
                    <th>Reference</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($logs as $l): ?>
                <tr>
                    <td class="ps-3 text-muted small"><?= fmtDate($l['created_at'], 'd M Y H:i') ?></td>
                    <td>
                        <?php if ($l['channel'] === 'whatsapp'): ?>
                        <span class="badge bg-success-subtle text-success border border-success-subtle">
                            <i class="fa fa-brands fa-whatsapp me-1"></i>WhatsApp
                        </span>
                        <?php else: ?>
                        <span class="badge bg-info-subtle text-info border border-info-subtle">
                            <i class="fa fa-comment-sms me-1"></i>SMS
                        </span>
                        <?php endif; ?>
                    </td>
                    <td class="font-monospace small"><?= e($l['phone']) ?></td>
                    <td>
                        <div style="max-width:280px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"
                             title="<?= e($l['message']) ?>">
                            <?= e($l['message']) ?>
                        </div>
                    </td>
                    <td class="text-muted small">
                        <?= $l['ref_type'] ? e($l['ref_type']) . ($l['ref_id'] ? ' #' . $l['ref_id'] : '') : '—' ?>
                    </td>
                    <td>
                        <span class="badge bg-<?= $l['status'] === 'sent' ? 'success' : 'danger' ?>">
                            <?= ucfirst($l['status']) ?>
                        </span>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <!-- Pagination -->
        <?php if ($logTotal > $logPer):
            $logPages = (int)ceil($logTotal / $logPer);
        ?>
        <div class="card-footer bg-white border-top d-flex align-items-center justify-content-between py-2 px-3">
            <small class="text-muted">
                Showing <?= number_format($logOffset + 1) ?>–<?= number_format(min($logOffset + $logPer, $logTotal)) ?>
                of <?= number_format($logTotal) ?>
            </small>
            <div class="d-flex gap-1">
                <?php if ($logPage > 1): ?>
                <a href="?tab=log&lpage=<?= $logPage - 1 ?>" class="btn btn-sm btn-outline-secondary"><i class="fa fa-chevron-left"></i></a>
                <?php endif; ?>
                <?php for ($p = max(1, $logPage - 2); $p <= min($logPages, $logPage + 2); $p++): ?>
                <a href="?tab=log&lpage=<?= $p ?>" class="btn btn-sm <?= $p === $logPage ? 'btn-primary' : 'btn-outline-secondary' ?>"><?= $p ?></a>
                <?php endfor; ?>
                <?php if ($logPage < $logPages): ?>
                <a href="?tab=log&lpage=<?= $logPage + 1 ?>" class="btn btn-sm btn-outline-secondary"><i class="fa fa-chevron-right"></i></a>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    <?php endif; ?>
    </div>
</div>

<?php endif; ?>
</div><!-- /tab-content -->

<?php $extraJs = <<<'JS'
<script>
// Test SMS
document.getElementById('btnTestSms')?.addEventListener('click', function() {
    const phone  = document.getElementById('testSmsPhone').value.trim();
    const result = document.getElementById('testSmsResult');
    if (!phone) { result.innerHTML = '<span class="text-danger">Enter a phone number.</span>'; return; }
    this.disabled = true;
    result.innerHTML = '<i class="fa fa-spinner fa-spin me-1"></i>Sending...';
    fetch('', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: new URLSearchParams({action: 'test_sms', phone})
    })
    .then(r => r.json())
    .then(d => {
        result.innerHTML = d.ok
            ? '<span class="text-success"><i class="fa fa-check-circle me-1"></i>Sent successfully!</span>'
            : '<span class="text-danger"><i class="fa fa-times-circle me-1"></i>' + (d.error || 'Failed') + '</span>';
    })
    .catch(() => { result.innerHTML = '<span class="text-danger">Request failed.</span>'; })
    .finally(() => { this.disabled = false; });
});

// Test WhatsApp
document.getElementById('btnTestWa')?.addEventListener('click', function() {
    const phone  = document.getElementById('testWaPhone').value.trim();
    const result = document.getElementById('testWaResult');
    if (!phone) { result.innerHTML = '<span class="text-danger">Enter a phone number.</span>'; return; }
    this.disabled = true;
    result.innerHTML = '<i class="fa fa-spinner fa-spin me-1"></i>Sending...';
    fetch('', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: new URLSearchParams({action: 'test_whatsapp', phone})
    })
    .then(r => r.json())
    .then(d => {
        result.innerHTML = d.ok
            ? '<span class="text-success"><i class="fa fa-check-circle me-1"></i>Sent successfully!</span>'
            : '<span class="text-danger"><i class="fa fa-times-circle me-1"></i>' + (d.error || 'Failed') + '</span>';
    })
    .catch(() => { result.innerHTML = '<span class="text-danger">Request failed.</span>'; })
    .finally(() => { this.disabled = false; });
});
</script>
JS;
?>

<?php include __DIR__ . '/../../includes/footer.php'; ?>

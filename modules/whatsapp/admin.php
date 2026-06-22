<?php
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
hasRole(['admin', 'general_manager']) || redirect(BASE_URL . '/index.php');

$db = getDB();

// ── Auto-migrations ───────────────────────────────────────────────────────────
$migrations = [
    "CREATE TABLE IF NOT EXISTS wa_config (
        id INT AUTO_INCREMENT PRIMARY KEY,
        instance_id VARCHAR(50) NOT NULL DEFAULT '',
        api_token VARCHAR(100) NOT NULL DEFAULT '',
        is_connected TINYINT(1) DEFAULT 0,
        phone_number VARCHAR(30) NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )",
    "CREATE TABLE IF NOT EXISTS wa_conversations (
        id INT AUTO_INCREMENT PRIMARY KEY,
        chat_id VARCHAR(50) NOT NULL,
        contact_name VARCHAR(150) NULL,
        contact_phone VARCHAR(30) NULL,
        client_id INT NULL,
        last_message TEXT NULL,
        last_message_at TIMESTAMP NULL,
        unread_count INT DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_chat_id (chat_id)
    )",
    "CREATE TABLE IF NOT EXISTS wa_messages (
        id INT AUTO_INCREMENT PRIMARY KEY,
        conversation_id INT NOT NULL,
        message_id VARCHAR(100) NULL,
        direction ENUM('in','out') DEFAULT 'out',
        type ENUM('text','image','document','audio','video','other') DEFAULT 'text',
        body TEXT NULL,
        media_url VARCHAR(500) NULL,
        sent_by INT NULL,
        sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        is_read TINYINT(1) DEFAULT 0,
        UNIQUE KEY uniq_msg_id (message_id)
    )",
];
foreach ($migrations as $sql) { try { $db->exec($sql); } catch (\Throwable $_) {} }

// ── Green API helper ──────────────────────────────────────────────────────────
function gaGet(string $iid, string $token, string $method): array {
    $ch = curl_init("https://api.greenapi.com/waInstance{$iid}/{$method}/{$token}");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 12,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if (!$body) return ['_error' => 'No response', '_code' => $code];
    return json_decode($body, true) ?: ['_raw' => $body, '_code' => $code];
}

// ── POST handlers ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save_config') {
        $iid   = trim($_POST['instance_id'] ?? '');
        $token = trim($_POST['api_token']   ?? '');
        if ($iid && $token) {
            $db->prepare(
                "INSERT INTO wa_config (id, instance_id, api_token)
                 VALUES (1, ?, ?)
                 ON DUPLICATE KEY UPDATE instance_id = VALUES(instance_id), api_token = VALUES(api_token)"
            )->execute([$iid, $token]);
            setFlash('success', 'Credentials saved. Scan the QR code below to connect.');
        } else {
            setFlash('error', 'Instance ID and API Token are required.');
        }
        redirect(BASE_URL . '/modules/whatsapp/admin.php');
    }

    if ($action === 'logout_wa') {
        $cfg = $db->query("SELECT * FROM wa_config LIMIT 1")->fetch();
        if ($cfg && $cfg['instance_id']) {
            gaGet($cfg['instance_id'], $cfg['api_token'], 'logout');
        }
        $db->exec("UPDATE wa_config SET is_connected = 0, phone_number = NULL");
        setFlash('success', 'WhatsApp logged out.');
        redirect(BASE_URL . '/modules/whatsapp/admin.php');
    }

    if ($action === 'reboot_wa') {
        $cfg = $db->query("SELECT * FROM wa_config LIMIT 1")->fetch();
        if ($cfg && $cfg['instance_id']) {
            gaGet($cfg['instance_id'], $cfg['api_token'], 'reboot');
            setFlash('success', 'Instance rebooting. Wait 15 seconds then refresh.');
        }
        redirect(BASE_URL . '/modules/whatsapp/admin.php');
    }
}

// ── Load config + live state ──────────────────────────────────────────────────
$config       = $db->query("SELECT * FROM wa_config LIMIT 1")->fetch() ?: [];
$iid          = $config['instance_id'] ?? '';
$token        = $config['api_token']   ?? '';
$isConfigured = $iid && $token;
$liveState    = null;
$qrData       = null;

if ($isConfigured) {
    $stateResp = gaGet($iid, $token, 'getStateInstance');
    $liveState = $stateResp['stateInstance'] ?? null;

    $isConnected = $liveState === 'authorized' ? 1 : 0;
    $db->prepare("UPDATE wa_config SET is_connected = ? WHERE id = 1")->execute([$isConnected]);

    if ($liveState !== 'authorized') {
        $qrData = gaGet($iid, $token, 'qr');
    }
}

// ── Stats ─────────────────────────────────────────────────────────────────────
$statConvs  = 0;
$statOut    = 0;
$statIn     = 0;
try {
    $statConvs = (int)$db->query("SELECT COUNT(*) FROM wa_conversations")->fetchColumn();
    $statOut   = (int)$db->query("SELECT COUNT(*) FROM wa_messages WHERE direction='out' AND DATE(sent_at)=CURDATE()")->fetchColumn();
    $statIn    = (int)$db->query("SELECT COUNT(*) FROM wa_messages WHERE direction='in'  AND DATE(sent_at)=CURDATE()")->fetchColumn();
} catch (\Throwable $_) {}

$pageTitle = 'WhatsApp Setup';
include __DIR__ . '/../../includes/header.php';
?>

<!-- Page header -->
<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <h5 class="mb-0">
            <i class="fab fa-whatsapp me-2" style="color:#25d366"></i>WhatsApp Setup
        </h5>
        <div class="text-muted small mt-1">Connect a WhatsApp account via Green API</div>
    </div>
    <a href="<?= BASE_URL ?>/modules/whatsapp/index.php" class="btn btn-outline-secondary btn-sm">
        <i class="fa fa-inbox me-1"></i>Open Inbox
    </a>
</div>

<!-- Connection status banner -->
<?php if (!$isConfigured): ?>
<div class="alert alert-warning d-flex align-items-center gap-3 mb-4">
    <i class="fa fa-triangle-exclamation fa-lg"></i>
    <div><strong>Not configured.</strong> Enter your Green API credentials below to get started.</div>
</div>
<?php elseif ($liveState === 'authorized'): ?>
<div class="alert alert-success d-flex align-items-center gap-3 mb-4">
    <i class="fab fa-whatsapp fa-lg"></i>
    <div class="flex-grow-1">
        <strong>WhatsApp Connected</strong>
        <?php if ($config['phone_number']): ?> — <?= e($config['phone_number']) ?><?php endif; ?>
    </div>
    <form method="POST" class="d-inline">
        <input type="hidden" name="action" value="logout_wa">
        <button class="btn btn-sm btn-outline-danger" onclick="return confirm('Disconnect WhatsApp?')">
            <i class="fa fa-right-from-bracket me-1"></i>Disconnect
        </button>
    </form>
    <form method="POST" class="d-inline ms-1">
        <input type="hidden" name="action" value="reboot_wa">
        <button class="btn btn-sm btn-outline-secondary">
            <i class="fa fa-rotate-right me-1"></i>Reboot
        </button>
    </form>
</div>
<?php elseif ($liveState === 'blocked'): ?>
<div class="alert alert-danger mb-4"><i class="fa fa-ban me-2"></i><strong>Account blocked.</strong> This WhatsApp account has been blocked by WhatsApp.</div>
<?php elseif ($liveState === 'starting'): ?>
<div class="alert alert-info mb-4"><i class="fa fa-spinner fa-spin me-2"></i>Instance is starting up. Please wait and refresh in a moment.</div>
<?php elseif ($isConfigured): ?>
<div class="alert alert-warning d-flex align-items-center gap-3 mb-4">
    <i class="fab fa-whatsapp fa-lg"></i>
    <div><strong>Not connected.</strong> Scan the QR code on the right to link your WhatsApp account.</div>
</div>
<?php endif; ?>

<div class="row g-4">

    <!-- Left: Credentials + instructions -->
    <div class="col-lg-7">

        <!-- How to get started -->
        <div class="card mb-4">
            <div class="card-header d-flex justify-content-between align-items-center py-2" style="cursor:pointer"
                 data-bs-toggle="collapse" data-bs-target="#setupHelp">
                <span class="fw-semibold small"><i class="fa fa-circle-info me-2 text-primary"></i>How to get started with Green API</span>
                <i class="fa fa-chevron-down" id="setupHelpChevron" style="font-size:11px;transition:transform .2s"></i>
            </div>
            <div class="collapse" id="setupHelp">
                <div class="card-body" style="font-size:13.5px">
                    <ol class="mb-0 ps-3">
                        <li class="mb-2">Go to <strong>green-api.com</strong> and create a free account.</li>
                        <li class="mb-2">In the dashboard, click <strong>Create Instance</strong> and choose the <em>Developer</em> plan (free tier: 1,500 messages/day).</li>
                        <li class="mb-2">Copy your <strong>Instance ID</strong> and <strong>API Token</strong> from the instance panel.</li>
                        <li class="mb-2">Paste them in the form below and click <strong>Save Credentials</strong>.</li>
                        <li>A QR code will appear on the right. Open WhatsApp on your phone → <strong>Linked Devices → Link a Device</strong> and scan it.</li>
                    </ol>
                </div>
            </div>
        </div>

        <!-- Credentials form -->
        <div class="card mb-4">
            <div class="card-header fw-semibold py-2">
                <i class="fa fa-key me-2 text-warning"></i>API Credentials
            </div>
            <div class="card-body">
                <form method="POST">
                    <input type="hidden" name="action" value="save_config">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Instance ID <span class="text-danger">*</span></label>
                        <input type="text" name="instance_id" class="form-control"
                               value="<?= e($iid) ?>" placeholder="e.g. 1234567890" required>
                        <div class="form-text">Found in your Green API dashboard → Instance panel</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">API Token <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <input type="password" name="api_token" id="apiTokenInput" class="form-control"
                                   value="<?= e($token) ?>" placeholder="Your API token" required>
                            <button type="button" class="btn btn-outline-secondary" id="toggleToken">
                                <i class="fa fa-eye" id="toggleTokenIcon"></i>
                            </button>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary">
                        <i class="fa fa-save me-1"></i>Save Credentials
                    </button>
                </form>
            </div>
        </div>

        <!-- Stats (only when connected) -->
        <?php if ($liveState === 'authorized'): ?>
        <div class="row g-3 mb-4">
            <div class="col-sm-4">
                <div class="card text-center py-3">
                    <div class="fs-3 fw-bold text-primary"><?= $statConvs ?></div>
                    <div class="text-muted small">Conversations</div>
                </div>
            </div>
            <div class="col-sm-4">
                <div class="card text-center py-3">
                    <div class="fs-3 fw-bold text-success"><?= $statOut ?></div>
                    <div class="text-muted small">Sent Today</div>
                </div>
            </div>
            <div class="col-sm-4">
                <div class="card text-center py-3">
                    <div class="fs-3 fw-bold text-info"><?= $statIn ?></div>
                    <div class="text-muted small">Received Today</div>
                </div>
            </div>
        </div>

        <!-- Import conversations card -->
        <div class="card" id="importCard">
            <div class="card-header fw-semibold py-2">
                <i class="fa fa-cloud-download-alt me-2 text-success"></i>Import Existing Conversations
            </div>
            <div class="card-body">
                <p class="text-muted small mb-3">
                    Pull all existing WhatsApp conversations into the inbox so your team can see them immediately.
                    <strong>Import with history</strong> also fetches the last 30 messages per conversation
                    (top 100 most recent chats).
                </p>
                <div class="d-flex gap-2 flex-wrap align-items-center">
                    <button id="btnImport" class="btn btn-success btn-sm" onclick="startImport(false)">
                        <i class="fa fa-download me-1"></i>Import Conversations
                    </button>
                    <button id="btnImportHistory" class="btn btn-outline-secondary btn-sm" onclick="startImport(true)">
                        <i class="fa fa-clock-rotate-left me-1"></i>Import with Message History
                    </button>
                </div>

                <!-- Progress -->
                <div id="importProgress" class="mt-3" style="display:none">
                    <div class="d-flex align-items-center gap-2">
                        <div class="spinner-border spinner-border-sm text-success" role="status"></div>
                        <span id="importStatus" class="text-muted small">Importing conversations…</span>
                    </div>
                </div>

                <!-- Result -->
                <div id="importResult" class="mt-3"></div>
            </div>
        </div>
        <?php endif; ?>

    </div>

    <!-- Right: QR code -->
    <div class="col-lg-5">
        <div class="card h-100">
            <div class="card-header fw-semibold py-2">
                <i class="fa fa-qrcode me-2"></i>Scan to Connect
            </div>
            <div class="card-body text-center d-flex flex-column align-items-center justify-content-center" style="min-height:300px">

                <?php if (!$isConfigured): ?>
                    <i class="fa fa-lock fa-3x text-muted mb-3"></i>
                    <p class="text-muted mb-0">Save your credentials first</p>

                <?php elseif ($liveState === 'authorized'): ?>
                    <div style="width:80px;height:80px;border-radius:50%;background:#dcfce7;display:flex;align-items:center;justify-content:center;margin-bottom:16px">
                        <i class="fab fa-whatsapp fa-2x" style="color:#16a34a"></i>
                    </div>
                    <p class="fw-semibold text-success mb-1">WhatsApp is connected!</p>
                    <p class="text-muted small">No need to scan a QR code.</p>

                <?php elseif (isset($qrData['type']) && $qrData['type'] === 'qrCode'): ?>
                    <p class="text-muted small mb-3">Open WhatsApp → Linked Devices → Link a Device</p>
                    <img src="data:image/png;base64,<?= e($qrData['message']) ?>"
                         alt="QR Code" style="width:220px;height:220px;border:4px solid #f0f0f0;border-radius:8px">
                    <p class="text-muted small mt-3 mb-1">QR code expires in ~60 seconds</p>
                    <div id="countdownWrap" class="text-muted small">
                        Refreshing in <span id="countdown">30</span>s
                    </div>

                <?php elseif (isset($qrData['type']) && $qrData['type'] === 'alreadyLogged'): ?>
                    <p class="text-success">Already logged in — refreshing state…</p>

                <?php else: ?>
                    <i class="fa fa-circle-exclamation fa-2x text-warning mb-3"></i>
                    <p class="text-muted mb-2">QR code unavailable.</p>
                    <p class="text-muted small">Try rebooting the instance, wait 15 seconds, then refresh.</p>
                    <form method="POST">
                        <input type="hidden" name="action" value="reboot_wa">
                        <button class="btn btn-sm btn-outline-primary">
                            <i class="fa fa-rotate-right me-1"></i>Reboot Instance
                        </button>
                    </form>
                <?php endif; ?>

                <?php if ($isConfigured && $liveState !== 'authorized'): ?>
                <div class="mt-4">
                    <a href="<?= BASE_URL ?>/modules/whatsapp/admin.php" class="btn btn-sm btn-outline-secondary">
                        <i class="fa fa-arrows-rotate me-1"></i>Refresh
                    </a>
                </div>
                <?php endif; ?>

            </div>
        </div>
    </div>
</div>

<script>
// Show/hide API token
document.getElementById('toggleToken').addEventListener('click', function () {
    var inp  = document.getElementById('apiTokenInput');
    var icon = document.getElementById('toggleTokenIcon');
    if (inp.type === 'password') { inp.type = 'text';     icon.className = 'fa fa-eye-slash'; }
    else                         { inp.type = 'password'; icon.className = 'fa fa-eye'; }
});

// Collapse chevron
(function () {
    var el  = document.getElementById('setupHelp');
    var chv = document.getElementById('setupHelpChevron');
    if (!el || !chv) return;
    el.addEventListener('show.bs.collapse',   function () { chv.style.transform = 'rotate(180deg)'; });
    el.addEventListener('hidden.bs.collapse', function () { chv.style.transform = ''; });
}());

// QR countdown + auto-reload
<?php if ($isConfigured && $liveState !== 'authorized'): ?>
(function () {
    var n   = 30;
    var el  = document.getElementById('countdown');
    if (!el) return;
    var t = setInterval(function () {
        n--;
        el.textContent = n;
        if (n <= 0) { clearInterval(t); location.reload(); }
    }, 1000);
}());
<?php endif; ?>

<?php if ($liveState === 'authorized'): ?>
// Import conversations
var BASE_URL_WA = '<?= BASE_URL ?>';

function startImport(withHistory) {
    var btnImport  = document.getElementById('btnImport');
    var btnHistory = document.getElementById('btnImportHistory');
    var progress   = document.getElementById('importProgress');
    var status     = document.getElementById('importStatus');
    var result     = document.getElementById('importResult');

    btnImport.disabled  = true;
    btnHistory.disabled = true;
    progress.style.display = '';
    result.innerHTML = '';

    status.textContent = withHistory
        ? 'Importing conversations and message history… this may take a minute.'
        : 'Importing conversations…';

    var url = BASE_URL_WA + '/modules/whatsapp/api/import.php' + (withHistory ? '?history=1' : '');
    fetch(url)
        .then(function (r) { return r.json(); })
        .then(function (d) {
            progress.style.display = 'none';
            btnImport.disabled  = false;
            btnHistory.disabled = false;

            if (d.success) {
                var parts = [];
                if (d.new_convs > 0)     parts.push(d.new_convs     + ' new');
                if (d.updated_convs > 0) parts.push(d.updated_convs + ' updated');
                var convSummary = d.total_chats + ' conversation' + (d.total_chats !== 1 ? 's' : '')
                                + (parts.length ? ' (' + parts.join(', ') + ')' : '');
                var msgSummary  = d.with_history ? ', ' + d.imported_msgs + ' messages imported' : '';
                result.innerHTML = '<div class="alert alert-success py-2 mb-0" style="font-size:13px">'
                    + '<i class="fa fa-check-circle me-2"></i>'
                    + '<strong>' + convSummary + '</strong>' + msgSummary + '.'
                    + ' <a href="' + BASE_URL_WA + '/modules/whatsapp/index.php" class="alert-link ms-1">Open Inbox →</a>'
                    + '</div>';
            } else {
                result.innerHTML = '<div class="alert alert-danger py-2 mb-0 small">'
                    + '<i class="fa fa-triangle-exclamation me-2"></i>'
                    + (d.error || 'Import failed. Please try again.')
                    + '</div>';
            }
        })
        .catch(function () {
            progress.style.display = 'none';
            btnImport.disabled  = false;
            btnHistory.disabled = false;
            result.innerHTML = '<div class="alert alert-danger py-2 mb-0 small">'
                + '<i class="fa fa-wifi me-2"></i>Network error. Please try again.'
                + '</div>';
        });
}

// Auto-import if WhatsApp was just connected and inbox is empty
<?php if ($statConvs === 0): ?>
window.addEventListener('load', function () {
    setTimeout(function () {
        if (document.getElementById('btnImport')) startImport(false);
    }, 800);
});
<?php endif; ?>

<?php endif; ?>
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>

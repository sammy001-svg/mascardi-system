<?php
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();

$db = getDB();

// Ensure tables exist (silently)
foreach ([
    "CREATE TABLE IF NOT EXISTS wa_config (id INT AUTO_INCREMENT PRIMARY KEY, instance_id VARCHAR(50) NOT NULL DEFAULT '', api_token VARCHAR(100) NOT NULL DEFAULT '', is_connected TINYINT(1) DEFAULT 0, phone_number VARCHAR(30) NULL, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP)",
    "CREATE TABLE IF NOT EXISTS wa_conversations (id INT AUTO_INCREMENT PRIMARY KEY, chat_id VARCHAR(50) NOT NULL, contact_name VARCHAR(150) NULL, contact_phone VARCHAR(30) NULL, client_id INT NULL, last_message TEXT NULL, last_message_at TIMESTAMP NULL, unread_count INT DEFAULT 0, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, UNIQUE KEY uniq_chat_id (chat_id))",
    "CREATE TABLE IF NOT EXISTS wa_messages (id INT AUTO_INCREMENT PRIMARY KEY, conversation_id INT NOT NULL, message_id VARCHAR(100) NULL, direction ENUM('in','out') DEFAULT 'out', type ENUM('text','image','document','audio','video','other') DEFAULT 'text', body TEXT NULL, media_url VARCHAR(500) NULL, sent_by INT NULL, sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, is_read TINYINT(1) DEFAULT 0, UNIQUE KEY uniq_msg_id (message_id))",
] as $sql) { try { $db->exec($sql); } catch (\Throwable $_) {} }

$waConfig    = $db->query("SELECT * FROM wa_config LIMIT 1")->fetch() ?: [];
$waConnected = (bool)($waConfig['is_connected'] ?? false);

try {
    $conversations = $db->query("
        SELECT wc.*,
               cl.name AS client_full_name,
               (SELECT body     FROM wa_messages WHERE conversation_id = wc.id ORDER BY sent_at DESC LIMIT 1) AS last_msg,
               (SELECT sent_at  FROM wa_messages WHERE conversation_id = wc.id ORDER BY sent_at DESC LIMIT 1) AS last_msg_at,
               (SELECT direction FROM wa_messages WHERE conversation_id = wc.id ORDER BY sent_at DESC LIMIT 1) AS last_direction
        FROM wa_conversations wc
        LEFT JOIN clients cl ON cl.id = wc.client_id
        ORDER BY COALESCE(wc.last_message_at, wc.created_at) DESC
        LIMIT 200
    ")->fetchAll();
} catch (\Throwable $_) { $conversations = []; }

$totalUnread = (int)($db->query("SELECT COALESCE(SUM(unread_count),0) FROM wa_conversations")->fetchColumn() ?: 0);

// Relative time helper
function relTime(?string $dt): string {
    if (!$dt) return '';
    $ts   = strtotime($dt);
    $diff = time() - $ts;
    if ($diff < 60)     return 'Just now';
    if ($diff < 3600)   return floor($diff / 60) . 'm ago';
    if (date('Y-m-d', $ts) === date('Y-m-d')) return date('H:i', $ts);
    if ($diff < 86400 * 2) return 'Yesterday';
    if ($diff < 86400 * 7) return date('D', $ts);
    return date('d M', $ts);
}

// Avatar colour from name
function avatarColor(string $name): string {
    $colors = ['#2563eb','#16a34a','#dc2626','#9333ea','#f59e0b','#0891b2','#db2777','#65a30d'];
    return $colors[crc32($name) % count($colors)];
}

$pageTitle = 'WhatsApp Inbox';
include __DIR__ . '/../../includes/header.php';
?>

<style>
.conv-list  { list-style:none;margin:0;padding:0 }
.conv-item  { display:flex;align-items:center;gap:12px;padding:12px 16px;border-bottom:1px solid var(--border);cursor:pointer;transition:background .12s;text-decoration:none;color:inherit }
.conv-item:hover { background:var(--surface-alt);text-decoration:none;color:inherit }
.conv-item.unread .conv-name { font-weight:700 }
.conv-item.unread .conv-preview { color:var(--text) }
.conv-avatar { width:44px;height:44px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:17px;font-weight:700;color:#fff;flex-shrink:0 }
.conv-body   { flex:1;min-width:0 }
.conv-name   { font-size:14px;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis }
.conv-preview{ font-size:12.5px;color:var(--text-muted);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;margin-top:1px }
.conv-meta   { text-align:right;flex-shrink:0;display:flex;flex-direction:column;align-items:flex-end;gap:4px }
.conv-time   { font-size:11px;color:var(--text-muted);white-space:nowrap }
.conv-badge  { background:#25d366;color:#fff;border-radius:10px;font-size:10px;font-weight:700;padding:1px 6px;min-width:18px;text-align:center }
</style>

<!-- Header -->
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div class="d-flex align-items-center gap-2">
        <h5 class="mb-0"><i class="fab fa-whatsapp me-2" style="color:#25d366"></i>WhatsApp Inbox</h5>
        <?php if ($totalUnread > 0): ?>
        <span class="badge" style="background:#25d366"><?= $totalUnread ?></span>
        <?php endif; ?>
        <span id="waStatusDot" title="<?= $waConnected ? 'Connected' : 'Disconnected' ?>"
              style="width:9px;height:9px;border-radius:50%;background:<?= $waConnected ? '#16a34a' : '#ef4444' ?>;display:inline-block"></span>
    </div>
    <div class="d-flex gap-2">
        <?php if (hasRole(['admin','general_manager'])): ?>
        <a href="<?= BASE_URL ?>/modules/whatsapp/admin.php" class="btn btn-outline-secondary btn-sm">
            <i class="fa fa-gear me-1"></i>Setup
        </a>
        <?php endif; ?>
    </div>
</div>

<!-- Not-connected alert -->
<?php if (!$waConnected): ?>
<div class="alert alert-warning d-flex align-items-center gap-3 mb-3">
    <i class="fab fa-whatsapp fa-lg"></i>
    <div>
        WhatsApp is not connected.
        <?php if (hasRole(['admin','general_manager'])): ?>
        <a href="<?= BASE_URL ?>/modules/whatsapp/admin.php" class="alert-link">Go to Setup →</a>
        <?php else: ?>
        Please ask your administrator to connect WhatsApp.
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<!-- Search -->
<div class="mb-3">
    <input type="text" id="convSearch" class="form-control form-control-sm"
           placeholder="Search conversations by name or phone…" style="max-width:380px">
</div>

<!-- Conversations card -->
<div class="card">
    <?php if (empty($conversations)): ?>
    <div class="card-body text-center py-5">
        <i class="fab fa-whatsapp fa-3x mb-3" style="color:#25d366;opacity:.4"></i>
        <p class="fw-semibold text-muted mb-1">No conversations yet</p>
        <p class="text-muted small">Messages from clients will appear here automatically.</p>
    </div>
    <?php else: ?>
    <ul class="conv-list" id="convList">
        <?php foreach ($conversations as $c):
            $name      = $c['client_full_name'] ?: ($c['contact_name'] ?: ($c['contact_phone'] ?: $c['chat_id']));
            $initial   = strtoupper(mb_substr($name, 0, 1));
            $color     = avatarColor($name);
            $preview   = $c['last_msg'] ?? $c['last_message'] ?? '';
            $previewFmt = ($c['last_direction'] === 'out' ? 'You: ' : '') . mb_substr($preview, 0, 60);
            $timeStr   = relTime($c['last_msg_at'] ?? $c['last_message_at'] ?? $c['updated_at']);
            $hasUnread = (int)($c['unread_count'] ?? 0) > 0;
        ?>
        <li class="conv-item <?= $hasUnread ? 'unread' : '' ?>"
            onclick="location.href='<?= BASE_URL ?>/modules/whatsapp/chat.php?id=<?= $c['id'] ?>'"
            data-name="<?= e(strtolower($name)) ?>"
            data-phone="<?= e(strtolower($c['contact_phone'] ?? '')) ?>">
            <div class="conv-avatar" style="background:<?= $color ?>">
                <?= e($initial) ?>
            </div>
            <div class="conv-body">
                <div class="conv-name">
                    <?= e($name) ?>
                    <?php if ($c['client_id']): ?>
                    <i class="fa fa-user-check ms-1" style="font-size:10px;color:#2563eb" title="Linked to client"></i>
                    <?php endif; ?>
                </div>
                <div class="conv-preview"><?= e($previewFmt ?: 'No messages yet') ?></div>
            </div>
            <div class="conv-meta">
                <span class="conv-time"><?= e($timeStr) ?></span>
                <?php if ($hasUnread): ?>
                <span class="conv-badge"><?= (int)$c['unread_count'] ?></span>
                <?php endif; ?>
            </div>
        </li>
        <?php endforeach; ?>
    </ul>
    <?php endif; ?>
</div>

<script>
// Live search
document.getElementById('convSearch').addEventListener('input', function () {
    var q = this.value.toLowerCase().trim();
    document.querySelectorAll('#convList .conv-item').forEach(function (li) {
        var name  = li.getAttribute('data-name')  || '';
        var phone = li.getAttribute('data-phone') || '';
        li.style.display = (!q || name.includes(q) || phone.includes(q)) ? '' : 'none';
    });
});

// Poll status every 30s
(function () {
    function poll() {
        fetch('<?= BASE_URL ?>/modules/whatsapp/api/status.php')
            .then(function (r) { return r.json(); })
            .then(function (d) {
                var dot = document.getElementById('waStatusDot');
                if (dot) dot.style.background = d.connected ? '#16a34a' : '#ef4444';
            }).catch(function () {});
    }
    setInterval(poll, 30000);
}());
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>

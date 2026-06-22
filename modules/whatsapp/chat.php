<?php
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();

// Redirect to the SPA inbox (chat.php is kept for backwards compatibility)
$id = (int)($_GET['id'] ?? 0);
redirect(BASE_URL . '/modules/whatsapp/index.php' . ($id ? '?id=' . $id : ''));
exit;

// ── POST handlers ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'link_client') {
        $clientId = (int)($_POST['client_id'] ?? 0);
        if ($clientId) {
            $cl = $db->prepare("SELECT name, phone FROM clients WHERE id = ?");
            $cl->execute([$clientId]);
            $cl = $cl->fetch();
            $db->prepare("UPDATE wa_conversations SET client_id = ?, contact_name = COALESCE(contact_name, ?) WHERE id = ?")
               ->execute([$clientId, $cl['name'] ?? '', $id]);
            setFlash('success', 'Conversation linked to client.');
        }
        redirect(BASE_URL . '/modules/whatsapp/chat.php?id=' . $id);
    }

    if ($action === 'unlink_client') {
        $db->prepare("UPDATE wa_conversations SET client_id = NULL WHERE id = ?")->execute([$id]);
        setFlash('success', 'Client unlinked.');
        redirect(BASE_URL . '/modules/whatsapp/chat.php?id=' . $id);
    }

    if ($action === 'update_name') {
        $newName = trim($_POST['contact_name'] ?? '');
        if ($newName) {
            $db->prepare("UPDATE wa_conversations SET contact_name = ? WHERE id = ?")->execute([$newName, $id]);
        }
        redirect(BASE_URL . '/modules/whatsapp/chat.php?id=' . $id);
    }
}

// ── Load conversation ─────────────────────────────────────────────────────────
$conv = $db->prepare("SELECT * FROM wa_conversations WHERE id = ?");
$conv->execute([$id]);
$conv = $conv->fetch();
if (!$conv) { setFlash('error', 'Conversation not found.'); redirect(BASE_URL . '/modules/whatsapp/index.php'); }

// Load linked client
$client = null;
if ($conv['client_id']) {
    $cs = $db->prepare("SELECT * FROM clients WHERE id = ?");
    $cs->execute([$conv['client_id']]);
    $client = $cs->fetch() ?: null;
}

// All clients for linking dropdown
try {
    $allClients = $db->query("SELECT id, name, phone FROM clients ORDER BY name LIMIT 500")->fetchAll();
} catch (\Throwable $_) { $allClients = []; }

// Load messages
$db->prepare("UPDATE wa_conversations SET unread_count = 0 WHERE id = ?")->execute([$id]);
$db->prepare("UPDATE wa_messages SET is_read = 1 WHERE conversation_id = ? AND direction = 'in'")->execute([$id]);

$messages = $db->prepare("
    SELECT m.*, u.name AS agent_name
    FROM wa_messages m
    LEFT JOIN users u ON u.id = m.sent_by
    WHERE m.conversation_id = ?
    ORDER BY m.sent_at ASC
    LIMIT 150
");
$messages->execute([$id]);
$messages = $messages->fetchAll();
$lastMsgId = !empty($messages) ? max(array_column($messages, 'id')) : 0;

// Active CRM leads for this client
$crmLeads = [];
if ($conv['client_id']) {
    try {
        $ls = $db->prepare("SELECT id, stage, interested_in FROM crm_leads WHERE client_id = ? AND stage NOT IN ('lost','delivered') LIMIT 5");
        $ls->execute([$conv['client_id']]);
        $crmLeads = $ls->fetchAll();
    } catch (\Throwable $_) {}
}

$contactName = $conv['contact_name'] ?: $conv['contact_phone'] ?: $conv['chat_id'];
$pageTitle   = 'Chat — ' . $contactName;

$extraCss = '
<style>
.chat-shell { display:flex;gap:0;height:calc(100vh - 140px);min-height:400px }
.chat-main  { flex:1;display:flex;flex-direction:column;min-width:0;background:var(--surface);border-radius:12px;overflow:hidden;border:1px solid var(--border) }
.chat-topbar{ padding:12px 16px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:12px;background:var(--surface) }
.chat-avatar{ width:40px;height:40px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:16px;font-weight:700;color:#fff;flex-shrink:0 }
.messages-wrap{ flex:1;overflow-y:auto;padding:16px;display:flex;flex-direction:column;gap:2px;background:#e5ddd5 }
[data-theme="dark"] .messages-wrap { background:#0d1117 }
.msg-row-in  { display:flex;justify-content:flex-start }
.msg-row-out { display:flex;justify-content:flex-end }
.msg-bubble-in  { background:#fff;border-radius:0 10px 10px 10px;max-width:68%;padding:7px 12px;word-break:break-word;box-shadow:0 1px 2px rgba(0,0,0,.12) }
.msg-bubble-out { background:#dcf8c6;border-radius:10px 0 10px 10px;max-width:68%;padding:7px 12px;word-break:break-word;box-shadow:0 1px 2px rgba(0,0,0,.12) }
[data-theme="dark"] .msg-bubble-in  { background:#1e2a3a;color:#e2e8f0 }
[data-theme="dark"] .msg-bubble-out { background:#1a3a27;color:#e2e8f0 }
.msg-text   { font-size:13.5px;line-height:1.45 }
.msg-meta   { font-size:10px;color:#999;margin-top:3px }
.chat-input { padding:10px 14px;border-top:1px solid var(--border);background:var(--surface) }
.chat-sidebar{ width:280px;flex-shrink:0;display:flex;flex-direction:column;gap:12px;padding-left:16px }
@media(max-width:900px){ .chat-sidebar{ display:none } }
</style>
';
include __DIR__ . '/../../includes/header.php';

// avatar colour
$colors = ['#2563eb','#16a34a','#dc2626','#9333ea','#f59e0b','#0891b2','#db2777','#65a30d'];
$avatarColor = $colors[crc32($contactName) % count($colors)];

$stageColors = ['new'=>'secondary','hot'=>'danger','contacted'=>'info','qualified'=>'primary','proposal'=>'warning','negotiation'=>'purple','reserved'=>'purple','delivered'=>'success','lost'=>'dark'];
?>

<!-- Back link -->
<div class="mb-3">
    <a href="<?= BASE_URL ?>/modules/whatsapp/index.php" class="text-muted small text-decoration-none">
        <i class="fa fa-arrow-left me-1"></i>Back to Inbox
    </a>
</div>

<div class="chat-shell">

    <!-- ── Main chat panel ──────────────────────────────────────────────── -->
    <div class="chat-main">

        <!-- Chat top bar -->
        <div class="chat-topbar">
            <div class="chat-avatar" style="background:<?= $avatarColor ?>">
                <?= strtoupper(mb_substr($contactName, 0, 1)) ?>
            </div>
            <div class="flex-grow-1 min-w-0">
                <div class="fw-semibold" style="font-size:14px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">
                    <?= e($contactName) ?>
                    <?php if ($client): ?>
                    <span class="badge bg-primary ms-1" style="font-size:9px">Client</span>
                    <?php endif; ?>
                </div>
                <div class="text-muted" style="font-size:11px"><?= e($conv['contact_phone'] ?: $conv['chat_id']) ?></div>
            </div>
            <div class="d-flex gap-2">
                <button id="btnLoadHistory" class="btn btn-sm btn-outline-secondary" onclick="loadHistory()" title="Load message history from WhatsApp">
                    <i class="fa fa-clock-rotate-left me-1"></i><span class="d-none d-sm-inline">History</span>
                </button>
                <a href="https://wa.me/<?= preg_replace('/[^0-9]/', '', $conv['contact_phone'] ?? $conv['chat_id']) ?>"
                   target="_blank" class="btn btn-sm btn-outline-success" title="Open in WhatsApp">
                    <i class="fab fa-whatsapp"></i>
                </a>
                <button class="btn btn-sm btn-outline-secondary d-lg-none" type="button"
                        data-bs-toggle="modal" data-bs-target="#sidebarModal">
                    <i class="fa fa-circle-info"></i>
                </button>
            </div>
        </div>

        <!-- Messages -->
        <div class="messages-wrap" id="messagesContainer">
            <?php if (empty($messages)): ?>
            <div class="text-center text-muted py-4" style="font-size:13px">No messages yet. Send the first message below.</div>
            <?php endif; ?>

            <?php foreach ($messages as $msg):
                $isOut  = $msg['direction'] === 'out';
                $tsStr  = date('Y-m-d', strtotime($msg['sent_at'])) === date('Y-m-d')
                          ? date('H:i', strtotime($msg['sent_at']))
                          : date('d M H:i', strtotime($msg['sent_at']));
                $icons  = ['image'=>'🖼️','audio'=>'🎵','video'=>'🎥','document'=>'📄'];
            ?>
            <div class="msg-row-<?= $isOut ? 'out' : 'in' ?> mb-1" data-msg-id="<?= $msg['id'] ?>">
                <div>
                    <div class="msg-bubble-<?= $isOut ? 'out' : 'in' ?>">
                        <?php if ($msg['type'] !== 'text'): ?>
                        <div class="mb-1 small" style="opacity:.8">
                            <?= $icons[$msg['type']] ?? '📎' ?>
                            <?php if ($msg['media_url']): ?>
                            <a href="<?= e($msg['media_url']) ?>" target="_blank" class="ms-1">View</a>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                        <div class="msg-text"><?= nl2br(e($msg['body'] ?? '')) ?></div>
                    </div>
                    <div class="msg-meta <?= $isOut ? 'text-end' : '' ?>">
                        <?= $tsStr ?>
                        <?php if ($isOut && $msg['agent_name']): ?> · <?= e($msg['agent_name']) ?><?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- Input area -->
        <div class="chat-input">
            <form id="sendForm">
                <div class="input-group">
                    <textarea id="msgInput" rows="1" class="form-control"
                              placeholder="Type a message… (Enter to send, Shift+Enter for new line)"
                              style="resize:none;max-height:120px;font-size:13.5px"></textarea>
                    <button type="submit" class="btn btn-success" id="sendBtn" style="padding:6px 18px">
                        <i class="fa fa-paper-plane"></i>
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- ── Right sidebar ────────────────────────────────────────────────── -->
    <div class="chat-sidebar">

        <!-- Contact info card -->
        <div class="card">
            <div class="card-header fw-semibold py-2 small"><i class="fa fa-address-card me-2 text-muted"></i>Contact</div>
            <div class="card-body text-center pt-3 pb-2">
                <div class="mx-auto mb-2 d-flex align-items-center justify-content-center fw-bold"
                     style="width:56px;height:56px;border-radius:50%;background:<?= $avatarColor ?>;color:#fff;font-size:22px">
                    <?= strtoupper(mb_substr($contactName, 0, 1)) ?>
                </div>
                <!-- Editable name -->
                <div id="nameDisplay" class="fw-semibold mb-1" style="font-size:14px"><?= e($contactName) ?></div>
                <button class="btn btn-xs btn-outline-secondary mb-2" style="font-size:10px;padding:1px 8px"
                        onclick="document.getElementById('nameDisplay').style.display='none';document.getElementById('nameEditForm').style.display=''">
                    <i class="fa fa-pen me-1"></i>Edit name
                </button>
                <form method="POST" id="nameEditForm" style="display:none" class="mb-2">
                    <input type="hidden" name="action" value="update_name">
                    <div class="input-group input-group-sm">
                        <input type="text" name="contact_name" class="form-control" value="<?= e($conv['contact_name'] ?? '') ?>" placeholder="Display name">
                        <button type="submit" class="btn btn-primary btn-sm">Save</button>
                    </div>
                </form>
                <?php if ($conv['contact_phone']): ?>
                <div class="text-muted small mb-1">
                    <i class="fa fa-phone me-1"></i>
                    <span id="phoneNum"><?= e($conv['contact_phone']) ?></span>
                    <button type="button" class="btn btn-xs btn-link p-0 ms-1" style="font-size:10px"
                            onclick="navigator.clipboard.writeText('<?= e($conv['contact_phone']) ?>');this.innerHTML='<i class=\'fa fa-check\'></i>'">
                        <i class="fa fa-copy"></i>
                    </button>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Client link card -->
        <div class="card">
            <div class="card-header fw-semibold py-2 small"><i class="fa fa-user-check me-2 text-primary"></i>Linked Client</div>
            <div class="card-body py-2">
                <?php if ($client): ?>
                <div class="mb-2">
                    <div class="fw-semibold small"><?= e($client['name']) ?></div>
                    <?php if ($client['phone'] ?? null): ?>
                    <div class="text-muted" style="font-size:11px"><?= e($client['phone']) ?></div>
                    <?php endif; ?>
                    <?php if ($client['email'] ?? null): ?>
                    <div class="text-muted" style="font-size:11px"><?= e($client['email']) ?></div>
                    <?php endif; ?>
                </div>
                <div class="d-flex gap-1 flex-wrap">
                    <a href="<?= BASE_URL ?>/modules/clients/view.php?id=<?= $client['id'] ?>"
                       class="btn btn-xs btn-outline-primary" style="font-size:11px;padding:2px 8px">
                        <i class="fa fa-eye me-1"></i>View
                    </a>
                    <form method="POST" class="d-inline">
                        <input type="hidden" name="action" value="unlink_client">
                        <button class="btn btn-xs btn-outline-danger" style="font-size:11px;padding:2px 8px"
                                onclick="return confirm('Unlink this client?')">
                            <i class="fa fa-unlink me-1"></i>Unlink
                        </button>
                    </form>
                </div>
                <?php else: ?>
                <form method="POST">
                    <input type="hidden" name="action" value="link_client">
                    <label class="form-label small fw-semibold mb-1">Link to existing client:</label>
                    <select name="client_id" class="form-select form-select-sm select2-link mb-2" required>
                        <option value="">— Select client —</option>
                        <?php foreach ($allClients as $cl): ?>
                        <option value="<?= $cl['id'] ?>"><?= e($cl['name']) ?><?= $cl['phone'] ? ' (' . e($cl['phone']) . ')' : '' ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" class="btn btn-primary btn-sm w-100">
                        <i class="fa fa-link me-1"></i>Link Client
                    </button>
                </form>
                <?php endif; ?>
            </div>
        </div>

        <!-- CRM leads card -->
        <?php if ($conv['client_id']): ?>
        <div class="card">
            <div class="card-header fw-semibold py-2 small d-flex justify-content-between">
                <span><i class="fa fa-funnel-dollar me-2 text-success"></i>CRM Leads</span>
                <a href="<?= BASE_URL ?>/modules/crm/add_lead.php?client_id=<?= $conv['client_id'] ?>"
                   class="text-decoration-none" style="font-size:10px" title="New lead">
                    <i class="fa fa-plus"></i>
                </a>
            </div>
            <div class="card-body py-2">
                <?php if (empty($crmLeads)): ?>
                <div class="text-muted" style="font-size:12px">No active leads.</div>
                <?php else: ?>
                <?php foreach ($crmLeads as $lead):
                    $sc = $stageColors[$lead['stage']] ?? 'secondary';
                ?>
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <div class="small" style="font-size:12px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:160px">
                        <?= e($lead['interested_in'] ?: 'No vehicle') ?>
                    </div>
                    <div class="d-flex align-items-center gap-1">
                        <span class="badge bg-<?= $sc ?>" style="font-size:9px"><?= ucfirst(str_replace('_',' ',$lead['stage'])) ?></span>
                        <a href="<?= BASE_URL ?>/modules/crm/view_lead.php?id=<?= $lead['id'] ?>"
                           class="btn btn-xs btn-outline-secondary" style="font-size:9px;padding:1px 5px">
                            <i class="fa fa-eye"></i>
                        </a>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
                <a href="<?= BASE_URL ?>/modules/crm/add_lead.php?client_id=<?= $conv['client_id'] ?>"
                   class="btn btn-sm btn-outline-success w-100 mt-1" style="font-size:11px">
                    <i class="fa fa-plus me-1"></i>New Lead
                </a>
            </div>
        </div>
        <?php endif; ?>

    </div><!-- /chat-sidebar -->
</div><!-- /chat-shell -->

<!-- Mobile sidebar modal -->
<div class="modal fade" id="sidebarModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header py-2">
                <h6 class="modal-title"><?= e($contactName) ?></h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="text-muted small mb-1"><strong>Phone:</strong> <?= e($conv['contact_phone'] ?? $conv['chat_id']) ?></p>
                <?php if ($client): ?>
                <p class="text-muted small mb-1"><strong>Client:</strong> <?= e($client['name']) ?></p>
                <a href="<?= BASE_URL ?>/modules/clients/view.php?id=<?= $client['id'] ?>" class="btn btn-sm btn-outline-primary">View Client</a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
var convId    = <?= $id ?>;
var lastMsgId = <?= $lastMsgId ?>;
var BASE_URL  = '<?= BASE_URL ?>';
var myName    = '<?= addslashes($me['name']) ?>';

// Scroll to bottom
function scrollBottom() {
    var el = document.getElementById('messagesContainer');
    if (el) el.scrollTop = el.scrollHeight;
}
scrollBottom();

// Auto-grow textarea
var ta = document.getElementById('msgInput');
ta.addEventListener('input', function () {
    this.style.height = 'auto';
    this.style.height = Math.min(this.scrollHeight, 120) + 'px';
});
ta.addEventListener('keydown', function (e) {
    if (e.key === 'Enter' && !e.shiftKey) {
        e.preventDefault();
        document.getElementById('sendForm').dispatchEvent(new Event('submit'));
    }
});
ta.focus();

// Append a message bubble
function appendMessage(m) {
    var isOut   = m.direction === 'out';
    var tsStr   = (function(d) {
        var dt = new Date(d.replace(' ', 'T'));
        var now = new Date();
        if (dt.toDateString() === now.toDateString()) {
            return dt.getHours().toString().padStart(2,'0') + ':' + dt.getMinutes().toString().padStart(2,'0');
        }
        return dt.getDate() + ' ' + ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'][dt.getMonth()] + ' ' + dt.getHours().toString().padStart(2,'0') + ':' + dt.getMinutes().toString().padStart(2,'0');
    })(m.sent_at || new Date().toISOString());
    var metaHtml = tsStr + (isOut && m.agent_name ? ' · ' + m.agent_name : '');
    var bodyHtml = (m.body || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/\n/g,'<br>');
    var html = '<div class="msg-row-' + (isOut?'out':'in') + ' mb-1" data-msg-id="' + (m.id||'') + '">'
             + '<div>'
             + '<div class="msg-bubble-' + (isOut?'out':'in') + '">'
             + '<div class="msg-text">' + bodyHtml + '</div>'
             + '</div>'
             + '<div class="msg-meta ' + (isOut?'text-end':'') + '">' + metaHtml + '</div>'
             + '</div></div>';
    var mc = document.getElementById('messagesContainer');
    mc.insertAdjacentHTML('beforeend', html);
}

// Send
document.getElementById('sendForm').addEventListener('submit', function (e) {
    e.preventDefault();
    var msg = ta.value.trim();
    if (!msg) return;
    var btn = document.getElementById('sendBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i>';
    var fd = new FormData();
    fd.append('conversation_id', convId);
    fd.append('message', msg);
    fetch(BASE_URL + '/modules/whatsapp/api/send.php', { method: 'POST', body: fd })
        .then(function (r) { return r.json(); })
        .then(function (d) {
            if (d.success) {
                ta.value = '';
                ta.style.height = 'auto';
                appendMessage({ direction:'out', body: msg, sent_at: d.sent_at || new Date().toISOString(), agent_name: myName, id: d.message_id });
                if (d.message_id > lastMsgId) lastMsgId = d.message_id;
                scrollBottom();
            } else {
                alert(d.error || 'Failed to send message');
            }
            btn.disabled = false;
            btn.innerHTML = '<i class="fa fa-paper-plane"></i>';
        }).catch(function () {
            btn.disabled = false;
            btn.innerHTML = '<i class="fa fa-paper-plane"></i>';
            alert('Network error. Please try again.');
        });
});

// Poll for new messages
function pollMessages() {
    fetch(BASE_URL + '/modules/whatsapp/api/poll.php?conversation_id=' + convId + '&since_id=' + lastMsgId)
        .then(function (r) { return r.json(); })
        .then(function (d) {
            if (d.messages && d.messages.length > 0) {
                d.messages.forEach(function (m) {
                    if (m.id > lastMsgId) {
                        appendMessage(m);
                        lastMsgId = m.id;
                    }
                });
                scrollBottom();
            }
        }).catch(function () {});
}
setInterval(pollMessages, 5000);

// Load message history from WhatsApp (via Green API getChatHistory)
function loadHistory() {
    var btn = document.getElementById('btnLoadHistory');
    btn.disabled = true;
    btn.innerHTML = '<i class="fa fa-spinner fa-spin me-1"></i><span class="d-none d-sm-inline">Loading…</span>';

    fetch(BASE_URL + '/modules/whatsapp/api/history.php?conversation_id=' + convId)
        .then(function (r) { return r.json(); })
        .then(function (d) {
            if (d.success) {
                var mc = document.getElementById('messagesContainer');
                if (d.messages && d.messages.length > 0) {
                    // Re-render all messages returned from DB (includes newly imported history)
                    mc.innerHTML = '';
                    d.messages.forEach(function (m) { appendMessage(m); });
                    if (d.messages.length > 0) {
                        lastMsgId = Math.max.apply(null, d.messages.map(function (m) { return parseInt(m.id) || 0; }));
                    }
                    scrollBottom();
                    var label = d.imported > 0 ? d.imported + ' message' + (d.imported !== 1 ? 's' : '') + ' loaded' : 'Up to date';
                    btn.innerHTML = '<i class="fa fa-check me-1"></i><span class="d-none d-sm-inline">' + label + '</span>';
                } else {
                    btn.innerHTML = '<i class="fa fa-clock-rotate-left me-1"></i><span class="d-none d-sm-inline">No history</span>';
                }
                // Re-enable after 5 s so user can refresh again
                setTimeout(function () {
                    btn.disabled = false;
                    btn.innerHTML = '<i class="fa fa-clock-rotate-left me-1"></i><span class="d-none d-sm-inline">History</span>';
                }, 5000);
            } else {
                btn.disabled = false;
                btn.innerHTML = '<i class="fa fa-clock-rotate-left me-1"></i><span class="d-none d-sm-inline">History</span>';
                alert(d.error || 'Could not load history. Make sure WhatsApp is connected.');
            }
        })
        .catch(function () {
            btn.disabled = false;
            btn.innerHTML = '<i class="fa fa-clock-rotate-left me-1"></i><span class="d-none d-sm-inline">History</span>';
            alert('Network error. Please try again.');
        });
}

// Move modals to body
document.addEventListener('DOMContentLoaded', function () {
    var el = document.getElementById('sidebarModal');
    if (el && el.parentNode !== document.body) document.body.appendChild(el);
    scrollBottom();
});

// Init Select2 for client link
$(function () {
    if (typeof $.fn.select2 !== 'undefined') {
        $('.select2-link').select2({ theme: 'bootstrap-5', placeholder: '— Select client —', width: '100%' });
    }
});
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>

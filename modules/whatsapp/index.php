<?php
/**
 * WhatsApp — the shared team inbox.
 *
 * One number, one thread per customer, and whoever is on duty answering from
 * their own login. The point is that the conversation lives against the
 * customer's record instead of on a salesperson's handset, so the next person
 * to pick the deal up can read what was actually promised.
 *
 * What is deliberately here:
 *   · a thread can be assigned, so a shared inbox is not a room where everyone
 *     assumes somebody else replied
 *   · quick replies, because the same six sentences go out all day and retyping
 *     them is where the typos come from
 *   · documents — from a file, or straight out of the system's own records —
 *     which is the thing the old module could not do at all
 *   · the client and the lead one click away, because a conversation about a car
 *     is useless without the car
 */

require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/_wa.php';
requireLogin();

if (!waCanUse()) {
    setFlash('error', 'The WhatsApp inbox is not open to your account.');
    redirect(BASE_URL . '/index.php');
}

$db = getDB();
waMigrate($db);
$me = authUser();

$openId    = (int)($_GET['id'] ?? 0);
$templates = waTemplates($db);
$canSend   = waCanSend();
$connected = false;
$connNote  = '';

if (!waConfigured()) {
    $connNote = waCanAdmin()
        ? 'WhatsApp is not connected yet. Set it up so the team can start sending.'
        : 'WhatsApp is not connected yet. An administrator needs to link the company phone.';
} else {
    $s = waDriverStatus(false);
    $connected = $s['state'] === 'connected';
    if (!$connected) $connNote = $s['label'] . ' — ' . $s['detail'];
}

// Who a thread can be handed to: the people who could actually answer it.
$agents = [];
try {
    $agents = $db->query("SELECT id, name, role FROM users
                           WHERE status = 'active'
                             AND role IN ('super_admin','admin','general_manager','manager','supervisor',
                                          'sales_manager','sales_officer','sales_person',
                                          'customer_relations','receptionist','workshop_manager')
                        ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
} catch (\Throwable $e) { error_log('wa agents: ' . $e->getMessage()); }

$pageTitle = 'WhatsApp Inbox';
include __DIR__ . '/../../includes/header.php';
?>
<style>
.wi-wrap{display:flex;flex-direction:column;height:calc(100vh - 150px);min-height:520px}
.wi-top{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;margin-bottom:12px}
.wi-top h1{font-size:20px;font-weight:700;color:var(--text);margin:0;display:flex;align-items:center;gap:10px}
.wi-top h1 i{width:36px;height:36px;border-radius:10px;background:#dcfce7;display:flex;
    align-items:center;justify-content:center;font-size:16px;color:#16a34a}
.wi-live{font-size:11px;font-weight:700;padding:3px 10px;border-radius:20px}
.wi-live.on {background:#dcfce7;color:#15803d}
.wi-live.off{background:#fee2e2;color:#b91c1c}

.wi-banner{background:#fffbeb;border:1px solid #fde68a;color:#92400e;border-radius:var(--r-lg);
    padding:11px 16px;font-size:13px;margin-bottom:12px;display:flex;align-items:center;gap:10px}
.wi-banner a{color:#92400e;font-weight:700}

.wi-panes{flex:1;display:grid;grid-template-columns:330px 1fr;gap:14px;min-height:0}
@media(max-width:900px){.wi-panes{grid-template-columns:1fr}.wi-thread.hide-sm{display:none}
    .wi-list.hide-sm{display:none}}

.wi-card{background:var(--surface);border:1px solid var(--border);border-radius:var(--r-lg);
    box-shadow:var(--sh);display:flex;flex-direction:column;min-height:0;overflow:hidden}

/* ── conversation list ── */
.wi-search{padding:11px;border-bottom:1px solid var(--border);display:flex;gap:7px}
.wi-search input{flex:1;font-size:13px;border:1px solid var(--border);border-radius:8px;padding:7px 10px;
    background:var(--surface);color:var(--text)}
.wi-tabs{display:flex;gap:4px;padding:8px 11px;border-bottom:1px solid var(--border);flex-wrap:wrap}
.wi-tab{font-size:11.5px;font-weight:600;padding:4px 10px;border-radius:20px;cursor:pointer;
    background:var(--surface-alt);color:var(--text-2);border:1px solid transparent}
.wi-tab.on{background:var(--brand);color:#fff}
.wi-convs{flex:1;overflow-y:auto;min-height:0}
.wi-conv{display:flex;gap:10px;padding:11px 13px;border-bottom:1px solid var(--border);cursor:pointer}
.wi-conv:hover{background:var(--brand-soft)}
.wi-conv.on{background:var(--brand-soft);box-shadow:inset 3px 0 0 var(--brand)}
.wi-av{width:38px;height:38px;border-radius:50%;background:#16a34a;color:#fff;flex-shrink:0;
    display:flex;align-items:center;justify-content:center;font-weight:700;font-size:13px}
.wi-conv-b{flex:1;min-width:0}
.wi-conv-b .n{display:flex;justify-content:space-between;gap:8px;align-items:baseline}
.wi-conv-b b{font-size:13px;color:var(--text);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.wi-conv-b time{font-size:10.5px;color:var(--text-3);flex-shrink:0}
.wi-conv-b p{margin:2px 0 0;font-size:12px;color:var(--text-2);white-space:nowrap;overflow:hidden;
    text-overflow:ellipsis}
.wi-conv-b .meta{font-size:10.5px;color:var(--text-3);margin-top:2px}
.wi-pill{background:#16a34a;color:#fff;border-radius:10px;font-size:10px;font-weight:700;
    padding:1px 6px;min-width:18px;text-align:center}

/* ── thread ── */
.wi-thread{min-height:0}
.wi-th-head{padding:11px 15px;border-bottom:1px solid var(--border);display:flex;
    align-items:center;gap:11px;flex-wrap:wrap}
.wi-th-head .who{flex:1;min-width:0}
.wi-th-head b{font-size:14px;color:var(--text);display:block}
.wi-th-head span{font-size:11.5px;color:var(--text-3)}
.wi-msgs{flex:1;overflow-y:auto;padding:18px;min-height:0;
    background:var(--surface-alt);background-image:radial-gradient(var(--border) 1px,transparent 1px);
    background-size:22px 22px}
.wi-msg{max-width:74%;margin-bottom:11px;clear:both}
.wi-msg.out{margin-left:auto}
.wi-bub{padding:8px 12px;border-radius:12px;font-size:13.5px;line-height:1.5;white-space:pre-wrap;
    word-break:break-word;box-shadow:var(--sh-sm)}
.wi-msg.in  .wi-bub{background:var(--surface);color:var(--text);border-top-left-radius:3px}
.wi-msg.out .wi-bub{background:#d9fdd3;color:#111b21;border-top-right-radius:3px}
.wi-meta{font-size:10.5px;color:var(--text-3);margin-top:3px;display:flex;gap:6px;align-items:center}
.wi-msg.out .wi-meta{justify-content:flex-end}
.wi-fail{color:#b91c1c;font-weight:600}
.wi-file{display:flex;align-items:center;gap:9px;padding:8px 11px;border-radius:9px;
    background:rgba(0,0,0,.05);text-decoration:none;color:inherit;margin-bottom:5px}
.wi-file i{font-size:19px;opacity:.75}
.wi-file b{font-size:12.5px;display:block;word-break:break-all}
.wi-img{max-width:100%;border-radius:9px;display:block;margin-bottom:5px}
.wi-day{text-align:center;margin:14px 0}
.wi-day span{background:var(--surface);border:1px solid var(--border);border-radius:20px;
    padding:3px 12px;font-size:11px;color:var(--text-2)}

/* ── composer ── */
.wi-comp{border-top:1px solid var(--border);padding:11px 13px;background:var(--surface)}
.wi-chips{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:8px}
.wi-chip{font-size:11.5px;padding:4px 10px;border-radius:20px;border:1px solid var(--border);
    background:var(--surface-alt);color:var(--text-2);cursor:pointer}
.wi-chip:hover{border-color:var(--brand);color:var(--brand)}
.wi-row{display:flex;gap:8px;align-items:flex-end}
.wi-row textarea{flex:1;resize:none;font-size:13.5px;border:1px solid var(--border);border-radius:10px;
    padding:9px 12px;max-height:130px;background:var(--surface);color:var(--text)}
.wi-icon{width:38px;height:38px;border-radius:10px;border:1px solid var(--border);background:var(--surface);
    color:var(--text-2);cursor:pointer;flex-shrink:0}
.wi-icon:hover{border-color:var(--brand);color:var(--brand)}
.wi-send{width:38px;height:38px;border-radius:10px;border:0;background:#16a34a;color:#fff;
    cursor:pointer;flex-shrink:0}
.wi-send:disabled{opacity:.5;cursor:not-allowed}
.wi-att{display:flex;align-items:center;gap:9px;background:var(--brand-soft);border:1px solid var(--brand);
    border-radius:9px;padding:7px 11px;margin-bottom:8px;font-size:12.5px}
.wi-att b{flex:1;min-width:0;word-break:break-all}

.wi-empty{flex:1;display:flex;flex-direction:column;align-items:center;justify-content:center;
    color:var(--text-3);text-align:center;padding:30px}
.wi-empty i{font-size:42px;opacity:.28;margin-bottom:14px}

[data-theme="dark"] .wi-top h1 i{background:rgba(22,163,74,.18)}
[data-theme="dark"] .wi-msg.out .wi-bub{background:#075e54;color:#e9edef}
[data-theme="dark"] .wi-banner{background:rgba(217,119,6,.12);border-color:#92400e;color:#fcd34d}
[data-theme="dark"] .wi-banner a{color:#fcd34d}
[data-theme="dark"] .wi-file{background:rgba(255,255,255,.08)}
</style>

<div class="wi-wrap">
    <div class="wi-top">
        <h1><i class="fab fa-whatsapp"></i>WhatsApp Inbox
            <span class="wi-live <?= $connected ? 'on' : 'off' ?>" id="waLive">
                <?= $connected ? 'Connected' : 'Not connected' ?></span>
        </h1>
        <div class="d-flex gap-2">
            <?php if ($canSend): ?>
            <button class="btn btn-sm btn-success" onclick="waNewChat()">
                <i class="fa fa-comment-medical me-1"></i>New chat</button>
            <?php endif; ?>
            <?php if (waCanAdmin()): ?>
            <a href="<?= BASE_URL ?>/modules/whatsapp/connect.php" class="btn btn-sm btn-outline-secondary">
                <i class="fa fa-gear me-1"></i>Connection</a>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($connNote !== ''): ?>
    <div class="wi-banner">
        <i class="fa fa-triangle-exclamation"></i>
        <div><?= e($connNote) ?>
            <?php if (waCanAdmin()): ?>
            <a href="<?= BASE_URL ?>/modules/whatsapp/connect.php">Open the connection page</a>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <div class="wi-panes">
        <div class="wi-card wi-list" id="waList">
            <div class="wi-search">
                <input type="search" id="waQ" placeholder="Search name, number or message">
            </div>
            <?php if ($canSend): ?>
            <div id="waNewBar" style="display:none;padding:11px;border-bottom:1px solid var(--border);
                 background:var(--surface-alt)">
                <div style="display:flex;gap:7px">
                    <input type="tel" id="waNewPhone" placeholder="0712345678"
                           style="flex:1;min-width:0;font-size:13px;border:1px solid var(--border);
                                  border-radius:8px;padding:7px 10px;background:var(--surface);color:var(--text)">
                    <button class="btn btn-sm btn-success" id="waNewGo">Start</button>
                </div>
                <div id="waNewErr" style="color:#b91c1c;font-size:11.5px;margin-top:6px"></div>
                <div style="font-size:11px;color:var(--text-3);margin-top:4px">
                    0712345678, or with the country code as 254712345678.</div>
            </div>
            <?php endif; ?>
            <div class="wi-tabs">
                <span class="wi-tab on" data-f="open">Open</span>
                <span class="wi-tab" data-f="unread">Unread</span>
                <span class="wi-tab" data-f="mine">Mine</span>
                <span class="wi-tab" data-f="closed">Closed</span>
            </div>
            <div class="wi-convs" id="waConvs">
                <div class="wi-empty" style="padding:22px"><i class="fa fa-spinner fa-spin"></i>
                    <div style="font-size:12.5px">Loading…</div></div>
            </div>
        </div>

        <div class="wi-card wi-thread hide-sm" id="waThread">
            <div class="wi-empty" id="waNoThread">
                <i class="fab fa-whatsapp"></i>
                <b style="color:var(--text-2);font-size:14px">Pick a conversation</b>
                <div style="font-size:12.5px;margin-top:5px;max-width:320px">
                    Every message the yard exchanges with a customer is kept here, against
                    their record — not on somebody's phone.
                </div>
            </div>

            <div id="waPane" style="display:none;flex:1;flex-direction:column;min-height:0">
                <div class="wi-th-head">
                    <button class="wi-icon d-md-none" onclick="waBack()"><i class="fa fa-arrow-left"></i></button>
                    <div class="wi-av" id="waAv">?</div>
                    <div class="who">
                        <b id="waName">—</b>
                        <span id="waSub"></span>
                    </div>
                    <div class="d-flex gap-1 flex-wrap" id="waActions"></div>
                </div>

                <div class="wi-msgs" id="waMsgs"></div>

                <?php if ($canSend): ?>
                <div class="wi-comp">
                    <?php if ($templates): ?>
                    <div class="wi-chips" id="waChips">
                        <?php foreach ($templates as $t): ?>
                        <span class="wi-chip" data-body="<?= e($t['body']) ?>"><?= e($t['title']) ?></span>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>

                    <div class="wi-att" id="waAtt" style="display:none">
                        <i class="fa fa-paperclip"></i>
                        <b id="waAttName"></b>
                        <button class="btn btn-sm btn-link text-danger p-0" onclick="waClearAtt()">
                            <i class="fa fa-xmark"></i></button>
                    </div>

                    <div class="wi-row">
                        <button class="wi-icon" title="Attach a file" onclick="document.getElementById('waFile').click()">
                            <i class="fa fa-paperclip"></i></button>
                        <input type="file" id="waFile" style="display:none"
                               accept=".pdf,.jpg,.jpeg,.png,.webp,.doc,.docx,.xls,.xlsx,.csv,.txt,.mp4">
                        <textarea id="waBody" rows="1" placeholder="Write a message…"></textarea>
                        <button class="wi-send" id="waSend" title="Send"><i class="fa fa-paper-plane"></i></button>
                    </div>
                    <div id="waErr" style="display:none;color:#b91c1c;font-size:12px;margin-top:7px"></div>
                </div>
                <?php else: ?>
                <div class="wi-comp" style="color:var(--text-3);font-size:12.5px;text-align:center">
                    You can read this inbox but not send from it.
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    var BASE  = '<?= BASE_URL ?>';
    var CSRF  = '<?= csrfToken() ?>';
    var CANSEND = <?= $canSend ? 'true' : 'false' ?>;
    var AGENTS = <?= json_encode(array_map(fn($a) => ['id' => (int)$a['id'], 'name' => $a['name']], $agents),
                                 JSON_UNESCAPED_UNICODE) ?>;

    var filter = 'open', q = '', cur = <?= $openId ?>, lastId = 0, busy = false, att = null;
    var elConvs = document.getElementById('waConvs'),
        elMsgs  = document.getElementById('waMsgs'),
        elPane  = document.getElementById('waPane'),
        elNo    = document.getElementById('waNoThread');

    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }
    function initials(n) {
        var p = String(n || '?').trim().split(/\s+/);
        return ((p[0] || '?')[0] + (p.length > 1 ? p[p.length - 1][0] : '')).toUpperCase();
    }
    function when(s) {
        if (!s) return '';
        var d = new Date(String(s).replace(' ', 'T')), now = new Date();
        if (isNaN(d)) return '';
        if (d.toDateString() === now.toDateString()) {
            return d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
        }
        var y = new Date(now); y.setDate(y.getDate() - 1);
        if (d.toDateString() === y.toDateString()) return 'Yesterday';
        return d.toLocaleDateString([], { day: 'numeric', month: 'short' });
    }

    // ── the list ──
    function drawConvs(list) {
        if (!list.length) {
            elConvs.innerHTML = '<div class="wi-empty" style="padding:26px"><i class="fa fa-inbox"></i>'
                + '<div style="font-size:12.5px">Nothing here yet.</div></div>';
            return;
        }
        elConvs.innerHTML = list.map(function (c) {
            return '<div class="wi-conv' + (c.id === cur ? ' on' : '') + '" data-id="' + c.id + '">'
                 + '<div class="wi-av">' + esc(initials(c.name)) + '</div>'
                 + '<div class="wi-conv-b">'
                 +   '<div class="n"><b>' + esc(c.name) + '</b><time>' + esc(when(c.at)) + '</time></div>'
                 +   '<p>' + esc(c.preview || 'No messages yet') + '</p>'
                 +   '<div class="meta">'
                 +     (c.agent ? '<i class="fa fa-user-check"></i> ' + esc(c.agent) : '<span style="opacity:.7">Unassigned</span>')
                 +     (c.stage ? ' · ' + esc(c.stage) : '')
                 +   '</div>'
                 + '</div>'
                 + (c.unread > 0 ? '<span class="wi-pill">' + c.unread + '</span>' : '')
                 + '</div>';
        }).join('');
        Array.prototype.forEach.call(elConvs.querySelectorAll('.wi-conv'), function (el) {
            el.addEventListener('click', function () { open(parseInt(el.dataset.id, 10)); });
        });
    }

    // ── the thread ──
    function drawMsgs(msgs, append) {
        if (!append) elMsgs.innerHTML = '';
        var lastDay = elMsgs.dataset.day || '';
        var html = '';
        msgs.forEach(function (m) {
            var d = new Date(String(m.at).replace(' ', 'T'));
            var day = isNaN(d) ? '' : d.toDateString();
            if (day && day !== lastDay) {
                html += '<div class="wi-day"><span>' + esc(
                    day === new Date().toDateString() ? 'Today'
                    : d.toLocaleDateString([], { weekday: 'short', day: 'numeric', month: 'short' })) + '</span></div>';
                lastDay = day;
            }
            var body = '';
            if (m.file_url && m.type === 'image') {
                body += '<img class="wi-img" src="' + esc(m.file_url) + '" alt="' + esc(m.file_name || 'image') + '">';
            } else if (m.file_url || m.file_name) {
                body += '<a class="wi-file" href="' + esc(m.file_url || '#') + '" target="_blank" rel="noopener">'
                      + '<i class="fa fa-file-lines"></i><b>' + esc(m.file_name || 'Attachment') + '</b>'
                      + '<i class="fa fa-download" style="font-size:13px"></i></a>';
            }
            if (m.body) body += esc(m.body);
            if (!body) body = '<em style="opacity:.6">' + esc(m.type) + '</em>';

            html += '<div class="wi-msg ' + (m.direction === 'out' ? 'out' : 'in') + '">'
                  + '<div class="wi-bub">' + body + '</div>'
                  + '<div class="wi-meta">'
                  +   (m.direction === 'out' && m.sender ? esc(m.sender) + ' · ' : '')
                  +   esc(when(m.at))
                  +   (m.status === 'failed'
                        ? ' · <span class="wi-fail" title="' + esc(m.error) + '">not delivered</span>'
                        : (m.direction === 'out' ? ' · <i class="fa fa-check"></i>' : ''))
                  + '</div></div>';
            if (m.id > lastId) lastId = m.id;
        });
        elMsgs.dataset.day = lastDay;
        elMsgs.insertAdjacentHTML('beforeend', html);
        elMsgs.scrollTop = elMsgs.scrollHeight;
    }

    function drawHead(c) {
        document.getElementById('waAv').textContent = initials(c.name);
        document.getElementById('waName').textContent = c.name || c.phone;
        var bits = [];
        if (c.phone) bits.push('+' + c.phone);
        document.getElementById('waSub').textContent = bits.join(' · ');

        var a = document.getElementById('waActions');
        var h = '';
        if (c.client_id) h += '<a class="btn btn-sm btn-outline-secondary" target="_blank" href="'
            + BASE + '/modules/clients/view.php?id=' + c.client_id + '"><i class="fa fa-user me-1"></i>Client</a>';
        if (c.lead_id) h += '<a class="btn btn-sm btn-outline-secondary" target="_blank" href="'
            + BASE + '/modules/crm/view_lead.php?id=' + c.lead_id + '"><i class="fa fa-user-plus me-1"></i>Lead</a>';
        if (CANSEND) {
            h += '<select class="form-select form-select-sm" style="width:auto" id="waAssign">'
               + '<option value="0">Unassigned</option>'
               + AGENTS.map(function (u) {
                     return '<option value="' + u.id + '"' + (u.id === c.assigned_to ? ' selected' : '') + '>'
                          + esc(u.name) + '</option>'; }).join('')
               + '</select>';
            h += '<button class="btn btn-sm btn-outline-secondary" id="waClose">'
               + (c.status === 'closed' ? '<i class="fa fa-rotate-left me-1"></i>Reopen'
                                        : '<i class="fa fa-check me-1"></i>Close') + '</button>';
        }
        a.innerHTML = h;

        var sel = document.getElementById('waAssign');
        if (sel) sel.addEventListener('change', function () {
            post('assign.php', { conversation_id: cur, action: 'assign', user_id: sel.value }, load);
        });
        var cb = document.getElementById('waClose');
        if (cb) cb.addEventListener('click', function () {
            post('assign.php', { conversation_id: cur, action: c.status === 'closed' ? 'reopen' : 'close' },
                 function () { load(); open(cur); });
        });
    }

    function post(file, data, done) {
        var fd = new FormData();
        fd.append('csrf_token', CSRF);
        Object.keys(data).forEach(function (k) { fd.append(k, data[k]); });
        fetch(BASE + '/modules/whatsapp/api/' + file, { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (d) { done && done(d); })
            .catch(function () { done && done({ ok: false, error: 'The request did not go through.' }); });
    }

    // ── loading ──
    function load(cb) {
        var u = BASE + '/modules/whatsapp/api/convs.php?status='
              + (filter === 'closed' ? 'closed' : (filter === 'open' ? 'open' : 'all'))
              + (filter === 'unread' ? '&unread=1' : '')
              + (filter === 'mine' ? '&mine=1' : '')
              + (q ? '&q=' + encodeURIComponent(q) : '');
        fetch(u, { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (!d || !d.ok) return;
                drawConvs(d.conversations || []);
                var b = document.getElementById('waUnreadBadge');
                if (b) b.textContent = d.unread || '';
                cb && cb();
            })
            .catch(function () {});
    }

    function open(id) {
        if (!id) return;
        cur = id; lastId = 0;
        elMsgs.dataset.day = '';
        elNo.style.display = 'none';
        elPane.style.display = 'flex';
        document.getElementById('waList').classList.add('hide-sm');
        document.getElementById('waThread').classList.remove('hide-sm');

        fetch(BASE + '/modules/whatsapp/api/convs.php?id=' + id, { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (!d || !d.ok || !d.conversation) return;
                drawHead(d.conversation);
                drawMsgs(d.messages || [], false);
                drawConvs(d.conversations || []);
            })
            .catch(function () {});
        if (history.replaceState) history.replaceState(null, '', '?id=' + id);
    }

    window.waBack = function () {
        document.getElementById('waList').classList.remove('hide-sm');
        document.getElementById('waThread').classList.add('hide-sm');
    };

    // ── sending ──
    var body = document.getElementById('waBody'),
        send = document.getElementById('waSend'),
        errB = document.getElementById('waErr');

    function showErr(m) {
        if (!errB) return;
        errB.textContent = m; errB.style.display = m ? '' : 'none';
    }

    function doSend() {
        if (!cur || busy) return;
        var text = (body && body.value || '').trim();
        if (!text && !att) return;
        busy = true; if (send) send.disabled = true; showErr('');

        var fd = new FormData();
        fd.append('csrf_token', CSRF);
        fd.append('conversation_id', cur);
        fd.append('body', text);
        if (att) { fd.append('kind', 'file'); fd.append('file', att); }
        else     { fd.append('kind', 'text'); }

        fetch(BASE + '/modules/whatsapp/api/send.php', { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                busy = false; if (send) send.disabled = false;
                // Whether it worked or not the message is in the thread, so the
                // thread is reloaded either way — a failure people can see is
                // worth more than a toast they can dismiss.
                if (body) body.value = ''; waClearAtt(); autoGrow();
                if (d && !d.ok) showErr(d.error || 'That did not send.');
                open(cur); load();
            })
            .catch(function () {
                busy = false; if (send) send.disabled = false;
                showErr('That did not send — check the connection.');
            });
    }

    window.waClearAtt = function () {
        att = null;
        var a = document.getElementById('waAtt');
        if (a) a.style.display = 'none';
        var f = document.getElementById('waFile');
        if (f) f.value = '';
    };

    var fileEl = document.getElementById('waFile');
    if (fileEl) fileEl.addEventListener('change', function () {
        if (!fileEl.files || !fileEl.files[0]) return;
        att = fileEl.files[0];
        document.getElementById('waAttName').textContent = att.name;
        document.getElementById('waAtt').style.display = '';
    });

    function autoGrow() {
        if (!body) return;
        body.style.height = 'auto';
        body.style.height = Math.min(body.scrollHeight, 130) + 'px';
    }
    if (body) {
        body.addEventListener('input', autoGrow);
        body.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); doSend(); }
        });
    }
    if (send) send.addEventListener('click', doSend);

    Array.prototype.forEach.call(document.querySelectorAll('.wi-chip'), function (c) {
        c.addEventListener('click', function () {
            if (!body) return;
            body.value = (body.value ? body.value + ' ' : '') + c.dataset.body;
            autoGrow(); body.focus();
        });
    });

    // Starting a chat is its own thing, with its own endpoint. It used to go
    // through send.php with an empty body, on the theory that the refusal would
    // still carry the conversation id back — it does not, so the thread was
    // created and the page then announced "There is nothing to send" instead of
    // opening it.
    window.waNewChat = function () {
        var bar = document.getElementById('waNewBar');
        if (!bar) return;
        bar.style.display = bar.style.display === 'none' ? '' : 'none';
        if (bar.style.display !== 'none') {
            document.getElementById('waNewErr').textContent = '';
            var f = document.getElementById('waNewPhone');
            f.value = ''; f.focus();
        }
    };

    function startChat() {
        var input = document.getElementById('waNewPhone'),
            err   = document.getElementById('waNewErr'),
            btn   = document.getElementById('waNewGo'),
            phone = (input.value || '').trim();
        if (!phone) { err.textContent = 'Enter a phone number.'; return; }

        btn.disabled = true;
        err.textContent = '';
        var fd = new FormData();
        fd.append('csrf_token', CSRF);
        fd.append('phone', phone);

        fetch(BASE + '/modules/whatsapp/api/open.php',
              { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                btn.disabled = false;
                if (!d || !d.ok || !d.conversation_id) {
                    err.textContent = (d && d.error) || 'That number could not be used.';
                    return;
                }
                document.getElementById('waNewBar').style.display = 'none';
                load(function () { open(d.conversation_id); });
            })
            .catch(function () {
                btn.disabled = false;
                err.textContent = 'That did not go through. Try again.';
            });
    }

    var goBtn = document.getElementById('waNewGo');
    if (goBtn) goBtn.addEventListener('click', startChat);
    var newPhone = document.getElementById('waNewPhone');
    if (newPhone) newPhone.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') { e.preventDefault(); startChat(); }
    });

    // ── filters and search ──
    Array.prototype.forEach.call(document.querySelectorAll('.wi-tab'), function (t) {
        t.addEventListener('click', function () {
            document.querySelectorAll('.wi-tab').forEach(function (x) { x.classList.remove('on'); });
            t.classList.add('on'); filter = t.dataset.f; load();
        });
    });
    var qEl = document.getElementById('waQ'), qT = null;
    if (qEl) qEl.addEventListener('input', function () {
        clearTimeout(qT);
        qT = setTimeout(function () { q = qEl.value.trim(); load(); }, 300);
    });

    // ── keeping up ──
    load(function () { if (cur) open(cur); });
    setInterval(function () {
        if (!cur) { load(); return; }
        // Only what is new, so an open thread does not flicker every few seconds.
        fetch(BASE + '/modules/whatsapp/api/convs.php?id=' + cur + '&after=' + lastId,
              { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (!d || !d.ok) return;
                if (d.messages && d.messages.length) drawMsgs(d.messages, true);
                drawConvs(d.conversations || []);
            })
            .catch(function () {});
    }, 8000);
}());
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>

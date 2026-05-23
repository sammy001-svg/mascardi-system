<?php
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
canAccess('chat') || die('Access denied.');
$pageTitle = 'Chat';
$me = authUser();
$db = getDB();

// All users except self for new chat
$users = $db->prepare("SELECT id, name, role FROM users WHERE id != ? AND status='active' ORDER BY name");
$users->execute([$me['id']]);
$users = $users->fetchAll(PDO::FETCH_ASSOC);

// Extra styles/scripts injected into header
$extraCss = '<style>
/* ── Chat layout overrides ───────────────────────────────────── */
.page-content { padding: 0 !important; }
.chat-wrap {
    display: flex;
    height: calc(100vh - 56px);   /* subtract topbar */
    background: #f0f2f5;
    overflow: hidden;
}

/* ── Left sidebar ─────────────────────────────────────────────── */
.chat-sidebar {
    width: 340px;
    min-width: 340px;
    background: #fff;
    border-right: 1px solid #e9edef;
    display: flex;
    flex-direction: column;
}
.chat-sidebar-header {
    padding: 12px 16px;
    background: #f0f2f5;
    border-bottom: 1px solid #e9edef;
    display: flex;
    align-items: center;
    justify-content: space-between;
}
.chat-sidebar-header h6 { margin: 0; font-weight: 700; font-size: 18px; }
.chat-search { padding: 8px 12px; background: #fff; border-bottom: 1px solid #f0f2f5; }
.chat-search input {
    width: 100%;
    border: none;
    background: #f0f2f5;
    border-radius: 8px;
    padding: 8px 14px;
    font-size: 13px;
    outline: none;
}
.conv-list { flex: 1; overflow-y: auto; }
.conv-item {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 12px 16px;
    cursor: pointer;
    border-bottom: 1px solid #f0f2f5;
    transition: background .1s;
}
.conv-item:hover, .conv-item.active { background: #f0f2f5; }
.conv-avatar {
    width: 46px; height: 46px; border-radius: 50%;
    background: #128c7e;
    color: #fff;
    display: flex; align-items: center; justify-content: center;
    font-weight: 700; font-size: 16px;
    flex-shrink: 0;
}
.conv-info { flex: 1; min-width: 0; }
.conv-name { font-weight: 600; font-size: 14px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.conv-preview { font-size: 12px; color: #667781; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.conv-meta { display: flex; flex-direction: column; align-items: flex-end; gap: 4px; }
.conv-time { font-size: 11px; color: #667781; }
.conv-unread { background: #25d366; color: #fff; border-radius: 50%; width: 18px; height: 18px; font-size: 11px; display: flex; align-items: center; justify-content: center; font-weight: 700; }

/* ── Main chat area ───────────────────────────────────────────── */
.chat-main {
    flex: 1;
    display: flex;
    flex-direction: column;
    background: #efeae2;
    position: relative;
}
.chat-main-empty {
    flex: 1;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    color: #667781;
}
.chat-header {
    background: #f0f2f5;
    border-bottom: 1px solid #e9edef;
    padding: 10px 16px;
    display: flex;
    align-items: center;
    gap: 12px;
}
.chat-header-info { flex: 1; }
.chat-header-info .name { font-weight: 700; font-size: 15px; }
.chat-header-info .status { font-size: 12px; color: #667781; }
.chat-header-actions { display: flex; gap: 8px; }
.chat-header-actions button {
    background: none; border: none; color: #54656f;
    font-size: 18px; padding: 6px; border-radius: 50%; cursor: pointer;
    transition: background .15s;
}
.chat-header-actions button:hover { background: #e9edef; }

/* ── Message area ─────────────────────────────────────────────── */
.chat-messages {
    flex: 1;
    overflow-y: auto;
    padding: 16px;
    display: flex;
    flex-direction: column;
    gap: 4px;
    background-image: url("data:image/svg+xml,%3Csvg xmlns=\'http://www.w3.org/2000/svg\' width=\'200\' height=\'200\'%3E%3C/svg%3E");
}
.msg-bubble-wrap {
    display: flex;
    margin: 2px 0;
}
.msg-bubble-wrap.sent { justify-content: flex-end; }
.msg-bubble-wrap.received { justify-content: flex-start; }
.msg-bubble {
    max-width: 65%;
    padding: 6px 10px 20px 10px;
    border-radius: 8px;
    font-size: 13.5px;
    line-height: 1.4;
    position: relative;
    word-wrap: break-word;
    box-shadow: 0 1px 1px rgba(0,0,0,.1);
}
.msg-bubble-wrap.sent .msg-bubble {
    background: #d9fdd3;
    border-top-right-radius: 0;
}
.msg-bubble-wrap.received .msg-bubble {
    background: #fff;
    border-top-left-radius: 0;
}
.msg-sender { font-size: 12px; font-weight: 700; color: #128c7e; margin-bottom: 2px; }
.msg-meta {
    position: absolute;
    bottom: 4px; right: 8px;
    font-size: 10px; color: #667781;
    display: flex; align-items: center; gap: 3px;
}
.msg-tick { color: #53bdeb; }

/* ── File bubble ──────────────────────────────────────────────── */
.msg-file {
    display: flex; align-items: center; gap: 10px;
    background: rgba(0,0,0,.05); border-radius: 6px;
    padding: 8px 10px; margin-bottom: 4px;
    min-width: 200px;
}
.msg-file .file-icon { font-size: 28px; color: #128c7e; }
.msg-file .file-info .name { font-size: 12.5px; font-weight: 600; word-break: break-all; }
.msg-file .file-info .size { font-size: 11px; color: #667781; }
.msg-image img { max-width: 240px; border-radius: 6px; cursor: pointer; }

/* ── Voice note ───────────────────────────────────────────────── */
.msg-voice {
    display: flex; align-items: center; gap: 10px;
    min-width: 220px; padding: 4px 0;
}
.msg-voice .play-btn {
    width: 38px; height: 38px; border-radius: 50%;
    background: #128c7e; color: #fff; border: none;
    display: flex; align-items: center; justify-content: center;
    cursor: pointer; font-size: 14px; flex-shrink: 0;
}
.msg-voice .waveform {
    flex: 1; height: 30px; background: linear-gradient(to right, #128c7e 0%, #128c7e var(--prog, 0%), #c8d6d4 var(--prog, 0%), #c8d6d4 100%);
    border-radius: 4px; cursor: pointer;
}
.msg-voice .duration { font-size: 11px; color: #667781; min-width: 30px; }

/* ── Day divider ──────────────────────────────────────────────── */
.day-divider {
    text-align: center; margin: 12px 0;
}
.day-divider span {
    background: #e1f2fb; color: #54656f;
    font-size: 11.5px; font-weight: 500;
    padding: 4px 10px; border-radius: 8px;
}

/* ── Input bar ────────────────────────────────────────────────── */
.chat-input-bar {
    background: #f0f2f5;
    border-top: 1px solid #e9edef;
    padding: 8px 12px;
    display: flex;
    align-items: flex-end;
    gap: 8px;
}
.chat-input-actions { display: flex; gap: 4px; }
.chat-input-actions button, .chat-send-btn, .chat-record-btn {
    background: none; border: none; color: #54656f; font-size: 22px;
    padding: 6px; border-radius: 50%; cursor: pointer; transition: background .15s;
    display: flex; align-items: center; justify-content: center;
    flex-shrink: 0;
}
.chat-input-actions button:hover, .chat-send-btn:hover { background: #e9edef; }
.chat-text-wrap {
    flex: 1;
    background: #fff;
    border-radius: 10px;
    padding: 8px 12px;
    max-height: 120px;
    overflow-y: auto;
    font-size: 14px;
    outline: none;
    line-height: 1.4;
}
.chat-text-wrap:empty::before { content: attr(data-placeholder); color: #aaa; }
.chat-send-btn { background: #128c7e !important; color: #fff !important; font-size: 18px !important; width: 42px; height: 42px; border-radius: 50% !important; flex-shrink: 0; }
.chat-send-btn:hover { background: #0f7268 !important; }
.chat-record-btn.recording { background: #fee2e2 !important; color: #dc2626 !important; animation: pulse 1s infinite; }
@keyframes pulse { 0%,100%{opacity:1} 50%{opacity:.5} }

/* ── Recording indicator ──────────────────────────────────────── */
.recording-bar {
    display: none;
    align-items: center;
    gap: 10px;
    flex: 1;
    background: #fff;
    border-radius: 10px;
    padding: 10px 14px;
    color: #dc2626;
    font-size: 13px;
    font-weight: 500;
}
.recording-bar.active { display: flex; }
.rec-dot { width: 10px; height: 10px; border-radius: 50%; background: #dc2626; animation: pulse 1s infinite; }

/* ── Call overlay ─────────────────────────────────────────────── */
.call-overlay {
    display: none;
    position: fixed; inset: 0; z-index: 9999;
    background: rgba(0,0,0,.85);
    flex-direction: column;
    align-items: center;
    justify-content: center;
    color: #fff;
}
.call-overlay.active { display: flex; }
.call-avatar-lg {
    width: 96px; height: 96px; border-radius: 50%;
    background: #128c7e; font-size: 36px; font-weight: 700;
    display: flex; align-items: center; justify-content: center;
    margin-bottom: 16px;
}
.call-status { font-size: 14px; color: rgba(255,255,255,.7); margin-bottom: 32px; }
.call-controls { display: flex; gap: 20px; }
.call-btn {
    width: 60px; height: 60px; border-radius: 50%; border: none;
    font-size: 22px; cursor: pointer; display: flex; align-items: center; justify-content: center;
    transition: transform .15s;
}
.call-btn:hover { transform: scale(1.1); }
.btn-hangup { background: #dc2626; color: #fff; }
.btn-accept { background: #16a34a; color: #fff; }
.btn-mute   { background: rgba(255,255,255,.2); color: #fff; }
.btn-cam    { background: rgba(255,255,255,.2); color: #fff; }
video.local-vid {
    position: fixed; bottom: 100px; right: 20px;
    width: 140px; height: 105px; border-radius: 10px;
    object-fit: cover; z-index: 10000; border: 2px solid #fff;
    display: none;
}
video.remote-vid { width: 100%; max-height: 60vh; border-radius: 12px; background: #111; display:none; }
.call-timer { font-size: 18px; font-weight: 600; letter-spacing: 1px; margin-bottom: 16px; }

/* ── New chat modal ───────────────────────────────────────────── */
.user-pick-item {
    display: flex; align-items: center; gap: 10px;
    padding: 10px 14px; cursor: pointer; border-radius: 8px;
    transition: background .1s;
}
.user-pick-item:hover { background: #f0f2f5; }
.user-pick-avatar {
    width: 38px; height: 38px; border-radius: 50%;
    background: #128c7e; color: #fff; font-weight: 700;
    display: flex; align-items: center; justify-content: center;
    font-size: 14px; flex-shrink: 0;
}

@media (max-width: 768px) {
    .chat-sidebar { width: 100%; min-width: 100%; display: none; }
    .chat-sidebar.mobile-show { display: flex; }
    .chat-main { width: 100%; }
}
</style>';

include __DIR__ . '/../../includes/header.php';
?>

<div class="chat-wrap">

    <!-- ── LEFT: Conversation list ──────────────────────────────── -->
    <div class="chat-sidebar" id="chatSidebar">
        <div class="chat-sidebar-header">
            <h6>Messages</h6>
            <div class="d-flex gap-2">
                <button class="btn btn-sm btn-outline-secondary rounded-circle p-1" data-bs-toggle="modal" data-bs-target="#newChatModal" title="New Chat">
                    <i class="fa fa-pen-to-square fa-sm"></i>
                </button>
            </div>
        </div>
        <div class="chat-search">
            <input type="text" id="convSearch" placeholder="Search or start new chat">
        </div>
        <div class="conv-list" id="convList">
            <div class="text-center text-muted py-5 small"><i class="fa fa-spinner fa-spin"></i> Loading…</div>
        </div>
    </div>

    <!-- ── RIGHT: Main chat area ────────────────────────────────── -->
    <div class="chat-main" id="chatMain">
        <div class="chat-main-empty" id="chatEmpty">
            <i class="fa fa-comments fa-3x mb-3 opacity-25"></i>
            <div class="fw-semibold mb-1">Mascardi Chat</div>
            <div class="small opacity-75">Select a conversation or start a new one</div>
        </div>
        <!-- Active chat (hidden until conversation selected) -->
        <div id="chatActive" style="display:none;flex-direction:column;height:100%;">
            <div class="chat-header" id="chatHeader">
                <div class="conv-avatar" id="hdrAvatar">–</div>
                <div class="chat-header-info">
                    <div class="name" id="hdrName">—</div>
                    <div class="status" id="hdrStatus"></div>
                </div>
                <div class="chat-header-actions">
                    <button onclick="ChatApp.initiateCall('audio')" title="Voice Call"><i class="fa fa-phone"></i></button>
                    <button onclick="ChatApp.initiateCall('video')" title="Video Call"><i class="fa fa-video"></i></button>
                </div>
            </div>
            <div class="chat-messages" id="chatMessages"></div>
            <div class="chat-input-bar">
                <div class="chat-input-actions">
                    <button title="Attach File" onclick="document.getElementById('fileInput').click()"><i class="fa fa-paperclip"></i></button>
                    <input type="file" id="fileInput" style="display:none" multiple>
                </div>
                <div class="recording-bar" id="recordingBar">
                    <div class="rec-dot"></div>
                    <span>Recording… <span id="recTimer">0:00</span></span>
                    <button class="btn btn-sm btn-outline-danger ms-auto" onclick="ChatApp.cancelRecording()"><i class="fa fa-xmark"></i></button>
                </div>
                <div contenteditable="true" id="msgInput" class="chat-text-wrap"
                     data-placeholder="Type a message…"
                     onkeydown="if(event.key==='Enter'&&!event.shiftKey){event.preventDefault();ChatApp.sendText();}"></div>
                <button class="chat-record-btn" id="recordBtn"
                        onmousedown="ChatApp.startRecording()"
                        onmouseup="ChatApp.stopRecording()"
                        ontouchstart="ChatApp.startRecording()"
                        ontouchend="ChatApp.stopRecording()"
                        title="Hold to record voice note">
                    <i class="fa fa-microphone"></i>
                </button>
                <button class="chat-send-btn" onclick="ChatApp.sendText()" title="Send">
                    <i class="fa fa-paper-plane"></i>
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Call overlay -->
<div class="call-overlay" id="callOverlay">
    <video class="remote-vid" id="remoteVideo" autoplay playsinline></video>
    <div class="call-avatar-lg" id="callAvatar">–</div>
    <div class="fw-bold fs-4 mb-1" id="callName">—</div>
    <div class="call-status" id="callStatus">Calling…</div>
    <div class="call-timer d-none" id="callTimer">0:00</div>
    <div class="call-controls">
        <button class="call-btn btn-mute" id="muteBtn" onclick="ChatApp.toggleMute()" title="Mute"><i class="fa fa-microphone"></i></button>
        <button class="call-btn btn-cam d-none" id="camBtn" onclick="ChatApp.toggleCamera()" title="Camera"><i class="fa fa-video"></i></button>
        <button class="call-btn btn-hangup" onclick="ChatApp.hangup()" title="End"><i class="fa fa-phone-slash"></i></button>
        <button class="call-btn btn-accept d-none" id="acceptBtn" onclick="ChatApp.acceptCall()" title="Accept"><i class="fa fa-phone"></i></button>
    </div>
</div>
<video class="local-vid" id="localVideo" autoplay muted playsinline></video>

<!-- Incoming call sound (silent oscillator — just triggers browser) -->

<!-- New Chat Modal -->
<div class="modal fade" id="newChatModal" tabindex="-1">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <div class="modal-header">
                <h6 class="modal-title"><i class="fa fa-pen-to-square me-2"></i>New Chat</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-2">
                <input type="text" id="userSearch" class="form-control form-control-sm mb-2" placeholder="Search users…">
                <div id="userList">
                    <?php foreach ($users as $u):
                        $init = strtoupper(substr($u['name'],0,1));
                        $roleLabels = ['admin'=>'Admin','workshop_manager'=>'Workshop Mgr','sales_person'=>'Sales Person','sales_officer'=>'Sales Officer'];
                        $rLabel = $roleLabels[$u['role']] ?? ucfirst($u['role']);
                    ?>
                    <div class="user-pick-item" data-uid="<?= $u['id'] ?>" data-name="<?= e($u['name']) ?>" onclick="ChatApp.startDirect(<?= $u['id'] ?>, '<?= e($u['name']) ?>')">
                        <div class="user-pick-avatar"><?= $init ?></div>
                        <div>
                            <div class="fw-semibold" style="font-size:13px"><?= e($u['name']) ?></div>
                            <div class="text-muted" style="font-size:11px"><?= $rLabel ?></div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
const ME = { id: <?= (int)$me['id'] ?>, name: <?= json_encode($me['name']) ?> };
const BASE = <?= json_encode(BASE_URL) ?>;
const API  = BASE + '/modules/chat/api/';

const ChatApp = {
    convId      : null,
    lastMsgId   : 0,
    pollTimer   : null,
    callPollTimer: null,
    mediaRecorder: null,
    audioChunks : [],
    recTimerInt : null,
    recSeconds  : 0,
    localStream : null,
    remoteStream: null,
    peerConn    : null,
    activeCallId: null,
    callTimerInt: null,
    isMuted     : false,
    isCamOff    : false,
    isIncoming  : false,
    iceCandidates: [],

    // ── Conversation list ─────────────────────────────────────────
    async loadConversations() {
        const r = await fetch(API + 'conversations.php');
        const data = await r.json();
        const list = document.getElementById('convList');
        if (!data.length) {
            list.innerHTML = '<div class="text-center text-muted py-5 small">No conversations yet.<br>Start one with the pencil icon above.</div>';
            return;
        }
        list.innerHTML = data.map(c => this._convHtml(c)).join('');
    },

    _convHtml(c) {
        const init = c.name.charAt(0).toUpperCase();
        const unread = c.unread > 0 ? `<div class="conv-unread">${c.unread}</div>` : '';
        const preview = c.last_type === 'voice' ? '🎙 Voice note'
                      : c.last_type === 'file'  ? '📎 ' + (c.last_msg || 'File')
                      : c.last_type === 'image' ? '🖼 Photo'
                      : (c.last_msg || '');
        const active = this.convId == c.id ? 'active' : '';
        return `<div class="conv-item ${active}" onclick="ChatApp.openConv(${c.id},'${e(c.name)}')">
            <div class="conv-avatar">${init}</div>
            <div class="conv-info">
                <div class="conv-name">${e(c.name)}</div>
                <div class="conv-preview">${e(preview.substring(0,60))}</div>
            </div>
            <div class="conv-meta">
                <div class="conv-time">${c.last_at || ''}</div>
                ${unread}
            </div>
        </div>`;
    },

    async openConv(convId, name) {
        this.convId = convId;
        this.lastMsgId = 0;
        clearInterval(this.pollTimer);

        document.getElementById('chatEmpty').style.display = 'none';
        const active = document.getElementById('chatActive');
        active.style.display = 'flex';

        const init = name.charAt(0).toUpperCase();
        document.getElementById('hdrAvatar').textContent = init;
        document.getElementById('hdrName').textContent   = name;
        document.getElementById('chatMessages').innerHTML = '<div class="text-center text-muted py-5 small"><i class="fa fa-spinner fa-spin"></i></div>';

        // Mark sidebar item active
        document.querySelectorAll('.conv-item').forEach(el => el.classList.remove('active'));
        document.querySelectorAll('.conv-item').forEach(el => {
            if (el.onclick?.toString().includes(convId)) el.classList.add('active');
        });

        await this.fetchMessages(true);
        this.pollTimer = setInterval(() => this.fetchMessages(false), 2000);
    },

    async fetchMessages(initial) {
        const r = await fetch(`${API}messages.php?conv_id=${this.convId}&after=${this.lastMsgId}`);
        const msgs = await r.json();
        if (!msgs.length) return;

        const box = document.getElementById('chatMessages');
        if (initial) box.innerHTML = '';

        let lastDay = box.dataset.lastDay || '';
        msgs.forEach(m => {
            const day = m.day;
            if (day !== lastDay) {
                box.insertAdjacentHTML('beforeend', `<div class="day-divider"><span>${day}</span></div>`);
                lastDay = day;
            }
            box.insertAdjacentHTML('beforeend', this._msgHtml(m));
            this.lastMsgId = Math.max(this.lastMsgId, m.id);
        });
        box.dataset.lastDay = lastDay;
        box.scrollTop = box.scrollHeight;

        // Update unread
        if (!initial) this.loadConversations();
    },

    _msgHtml(m) {
        const sent = m.sender_id == ME.id;
        const cls  = sent ? 'sent' : 'received';
        const tick  = sent ? '<span class="msg-tick"><i class="fa fa-check-double"></i></span>' : '';
        let body = '';

        if (m.type === 'text') {
            body = `<span>${e(m.content)}</span>`;
        } else if (m.type === 'voice') {
            const src = `${BASE}/uploads/chat/${e(m.file_path)}`;
            const dur = m.duration ? this._fmtDur(m.duration) : '—';
            body = `<div class="msg-voice">
                <button class="play-btn" onclick="ChatApp.playVoice(this,'${src}')"><i class="fa fa-play"></i></button>
                <div class="waveform" style="--prog:0%"></div>
                <span class="duration">${dur}</span>
            </div>`;
        } else if (m.type === 'image') {
            const src = `${BASE}/uploads/chat/${e(m.file_path)}`;
            body = `<div class="msg-image"><img src="${src}" onclick="window.open('${src}','_blank')" loading="lazy"></div>`;
        } else if (m.type === 'file') {
            const src = `${BASE}/uploads/chat/${e(m.file_path)}`;
            const size = m.file_size ? this._fmtSize(m.file_size) : '';
            const icon = this._fileIcon(m.mime_type || '');
            body = `<a href="${src}" download="${e(m.file_name)}" class="text-decoration-none text-dark">
                <div class="msg-file">
                    <div class="file-icon"><i class="fa ${icon}"></i></div>
                    <div class="file-info">
                        <div class="name">${e(m.file_name)}</div>
                        <div class="size">${size}</div>
                    </div>
                    <i class="fa fa-download text-muted ms-2"></i>
                </div></a>`;
        } else if (m.type === 'call') {
            const ico = m.content?.includes('video') ? 'fa-video' : 'fa-phone';
            body = `<span class="text-muted"><i class="fa ${ico} me-1"></i>${e(m.content)}</span>`;
        } else if (m.type === 'system') {
            return `<div class="day-divider"><span>${e(m.content)}</span></div>`;
        }

        const sender = (!sent && m.conv_type === 'group') ? `<div class="msg-sender">${e(m.sender_name)}</div>` : '';
        return `<div class="msg-bubble-wrap ${cls}">
            <div class="msg-bubble">
                ${sender}${body}
                <div class="msg-meta">${m.time} ${tick}</div>
            </div>
        </div>`;
    },

    // ── Send text ─────────────────────────────────────────────────
    async sendText() {
        if (!this.convId) return;
        const el = document.getElementById('msgInput');
        const text = el.innerText.trim();
        if (!text) return;
        el.innerText = '';
        const fd = new FormData();
        fd.append('conv_id', this.convId);
        fd.append('type', 'text');
        fd.append('content', text);
        await fetch(API + 'send.php', { method: 'POST', body: fd });
        await this.fetchMessages(false);
    },

    // ── Start direct chat ─────────────────────────────────────────
    async startDirect(userId, name) {
        bootstrap.Modal.getInstance(document.getElementById('newChatModal'))?.hide();
        const fd = new FormData();
        fd.append('user_id', userId);
        const r = await fetch(API + 'conversations.php', { method: 'POST', body: fd });
        const d = await r.json();
        if (d.conv_id) {
            await this.loadConversations();
            this.openConv(d.conv_id, name);
        }
    },

    // ── File upload ───────────────────────────────────────────────
    async uploadFile(file) {
        if (!this.convId) return;
        const fd = new FormData();
        fd.append('conv_id', this.convId);
        fd.append('file', file);
        await fetch(API + 'upload.php', { method: 'POST', body: fd });
        await this.fetchMessages(false);
    },

    // ── Voice recording ───────────────────────────────────────────
    async startRecording() {
        if (this.mediaRecorder) return;
        try {
            const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
            this.audioChunks = [];
            this.mediaRecorder = new MediaRecorder(stream);
            this.mediaRecorder.ondataavailable = e => this.audioChunks.push(e.data);
            this.mediaRecorder.start();

            document.getElementById('recordBtn').classList.add('recording');
            document.getElementById('msgInput').style.display = 'none';
            document.getElementById('recordingBar').classList.add('active');

            this.recSeconds = 0;
            this.recTimerInt = setInterval(() => {
                this.recSeconds++;
                document.getElementById('recTimer').textContent = this._fmtDur(this.recSeconds);
            }, 1000);
        } catch (err) {
            alert('Microphone access denied. Please allow microphone access.');
        }
    },

    async stopRecording() {
        if (!this.mediaRecorder) return;
        const dur = this.recSeconds;
        clearInterval(this.recTimerInt);
        document.getElementById('recordBtn').classList.remove('recording');
        document.getElementById('msgInput').style.display = '';
        document.getElementById('recordingBar').classList.remove('active');

        this.mediaRecorder.stop();
        this.mediaRecorder.onstop = async () => {
            const blob = new Blob(this.audioChunks, { type: 'audio/webm' });
            this.mediaRecorder.stream.getTracks().forEach(t => t.stop());
            this.mediaRecorder = null;

            if (dur < 1) return; // too short
            const fd = new FormData();
            fd.append('conv_id', this.convId);
            fd.append('voice', blob, 'voice.webm');
            fd.append('duration', dur);
            await fetch(API + 'upload.php', { method: 'POST', body: fd });
            await this.fetchMessages(false);
        };
    },

    cancelRecording() {
        if (!this.mediaRecorder) return;
        clearInterval(this.recTimerInt);
        this.mediaRecorder.stream.getTracks().forEach(t => t.stop());
        this.mediaRecorder = null;
        document.getElementById('recordBtn').classList.remove('recording');
        document.getElementById('msgInput').style.display = '';
        document.getElementById('recordingBar').classList.remove('active');
    },

    // ── Voice playback ─────────────────────────────────────────────
    _currentAudio: null,
    playVoice(btn, src) {
        if (this._currentAudio) { this._currentAudio.pause(); this._currentAudio = null; }
        const wrap = btn.closest('.msg-voice');
        const wf   = wrap.querySelector('.waveform');
        const audio = new Audio(src);
        this._currentAudio = audio;
        btn.innerHTML = '<i class="fa fa-pause"></i>';
        audio.addEventListener('timeupdate', () => {
            const pct = (audio.currentTime / (audio.duration || 1)) * 100;
            wf.style.setProperty('--prog', pct + '%');
        });
        audio.addEventListener('ended', () => {
            btn.innerHTML = '<i class="fa fa-play"></i>';
            wf.style.setProperty('--prog', '0%');
            this._currentAudio = null;
        });
        audio.play();
        btn.onclick = () => { audio.pause(); btn.innerHTML = '<i class="fa fa-play"></i>'; btn.onclick = () => ChatApp.playVoice(btn, src); };
    },

    // ── WebRTC Call ───────────────────────────────────────────────
    _stun: { iceServers: [{ urls: 'stun:stun.l.google.com:19302' }, { urls: 'stun:stun1.l.google.com:19302' }] },

    async initiateCall(type) {
        if (!this.convId) return;
        this.iceCandidates = [];
        const name = document.getElementById('hdrName').textContent;
        this._showCallOverlay(name, type, false);
        document.getElementById('callStatus').textContent = 'Calling…';

        this.localStream = await navigator.mediaDevices.getUserMedia({ audio: true, video: type === 'video' });
        if (type === 'video') {
            const lv = document.getElementById('localVideo');
            lv.srcObject = this.localStream; lv.style.display = 'block';
        }

        this.peerConn = new RTCPeerConnection(this._stun);
        this.localStream.getTracks().forEach(t => this.peerConn.addTrack(t, this.localStream));
        this.peerConn.onicecandidate = e => { if (e.candidate) this.iceCandidates.push(e.candidate); };
        this.peerConn.ontrack = e => {
            this.remoteStream = e.streams[0];
            const rv = document.getElementById('remoteVideo');
            rv.srcObject = this.remoteStream;
            if (type === 'video') rv.style.display = 'block';
        };

        const offer = await this.peerConn.createOffer();
        await this.peerConn.setLocalDescription(offer);

        const fd = new FormData();
        fd.append('action', 'initiate');
        fd.append('conv_id', this.convId);
        fd.append('call_type', type);
        fd.append('offer_sdp', JSON.stringify(offer));
        const r = await fetch(API + 'call.php', { method: 'POST', body: fd });
        const d = await r.json();
        this.activeCallId = d.call_id;

        // Poll for answer
        this.callPollTimer = setInterval(() => this._pollCallStatus(), 2000);
    },

    async _pollCallStatus() {
        if (!this.activeCallId) return;
        const r = await fetch(`${API}call.php?action=status&call_id=${this.activeCallId}`);
        const d = await r.json();

        if (d.status === 'active' && d.answer_sdp && this.peerConn?.signalingState !== 'stable') {
            const answer = JSON.parse(d.answer_sdp);
            await this.peerConn.setRemoteDescription(answer);
            // Send ICE candidates
            const fd = new FormData();
            fd.append('action', 'ice'); fd.append('call_id', this.activeCallId);
            fd.append('role', 'caller'); fd.append('ice', JSON.stringify(this.iceCandidates));
            await fetch(API + 'call.php', { method: 'POST', body: fd });
            // Receive callee ICE
            if (d.callee_ice) {
                JSON.parse(d.callee_ice).forEach(c => this.peerConn.addIceCandidate(new RTCIceCandidate(c)));
            }
            document.getElementById('callStatus').textContent = 'Connected';
            this._startCallTimer();
        } else if (d.status === 'rejected' || d.status === 'missed') {
            this._endCallCleanup();
            document.getElementById('callStatus').textContent = d.status === 'rejected' ? 'Call rejected' : 'No answer';
            setTimeout(() => this._hideCallOverlay(), 2500);
        } else if (d.status === 'ended') {
            this._endCallCleanup();
            this._hideCallOverlay();
        }
    },

    async _handleIncomingCall(callData) {
        if (this.activeCallId) return; // already in call
        this.activeCallId  = callData.id;
        this.isIncoming    = true;
        this.iceCandidates = [];
        document.getElementById('acceptBtn').classList.remove('d-none');
        this._showCallOverlay(callData.caller_name, callData.call_type, true);
        document.getElementById('callStatus').textContent = `Incoming ${callData.call_type} call…`;
    },

    async acceptCall() {
        const r = await fetch(`${API}call.php?action=status&call_id=${this.activeCallId}`);
        const d = await r.json();
        if (!d.offer_sdp) return;

        this.localStream = await navigator.mediaDevices.getUserMedia({ audio: true, video: d.call_type === 'video' });
        if (d.call_type === 'video') { const lv = document.getElementById('localVideo'); lv.srcObject = this.localStream; lv.style.display = 'block'; }

        this.peerConn = new RTCPeerConnection(this._stun);
        this.localStream.getTracks().forEach(t => this.peerConn.addTrack(t, this.localStream));
        this.peerConn.onicecandidate = e => { if (e.candidate) this.iceCandidates.push(e.candidate); };
        this.peerConn.ontrack = e => {
            const rv = document.getElementById('remoteVideo');
            rv.srcObject = e.streams[0];
            if (d.call_type === 'video') rv.style.display = 'block';
        };

        const offer = JSON.parse(d.offer_sdp);
        await this.peerConn.setRemoteDescription(new RTCSessionDescription(offer));
        const answer = await this.peerConn.createAnswer();
        await this.peerConn.setLocalDescription(answer);

        const fd = new FormData();
        fd.append('action', 'answer'); fd.append('call_id', this.activeCallId);
        fd.append('answer_sdp', JSON.stringify(answer));
        await fetch(API + 'call.php', { method: 'POST', body: fd });

        document.getElementById('acceptBtn').classList.add('d-none');
        document.getElementById('callStatus').textContent = 'Connected';
        this._startCallTimer();

        // Poll for caller ICE
        setTimeout(async () => {
            const r2 = await fetch(`${API}call.php?action=status&call_id=${this.activeCallId}`);
            const d2 = await r2.json();
            if (d2.caller_ice) { JSON.parse(d2.caller_ice).forEach(c => this.peerConn.addIceCandidate(new RTCIceCandidate(c))); }
            // Send callee ICE
            const fd2 = new FormData();
            fd2.append('action','ice'); fd2.append('call_id', this.activeCallId);
            fd2.append('role','callee'); fd2.append('ice', JSON.stringify(this.iceCandidates));
            await fetch(API + 'call.php', { method: 'POST', body: fd2 });
        }, 2000);
    },

    async hangup() {
        if (this.activeCallId) {
            const fd = new FormData();
            fd.append('action', 'end'); fd.append('call_id', this.activeCallId);
            await fetch(API + 'call.php', { method: 'POST', body: fd });
        }
        this._endCallCleanup();
        this._hideCallOverlay();
    },

    toggleMute() {
        this.isMuted = !this.isMuted;
        this.localStream?.getAudioTracks().forEach(t => t.enabled = !this.isMuted);
        const btn = document.getElementById('muteBtn');
        btn.innerHTML = this.isMuted ? '<i class="fa fa-microphone-slash"></i>' : '<i class="fa fa-microphone"></i>';
        btn.style.background = this.isMuted ? '#dc2626' : 'rgba(255,255,255,.2)';
    },

    toggleCamera() {
        this.isCamOff = !this.isCamOff;
        this.localStream?.getVideoTracks().forEach(t => t.enabled = !this.isCamOff);
        const btn = document.getElementById('camBtn');
        btn.innerHTML = this.isCamOff ? '<i class="fa fa-video-slash"></i>' : '<i class="fa fa-video"></i>';
    },

    _showCallOverlay(name, type, incoming) {
        document.getElementById('callOverlay').classList.add('active');
        document.getElementById('callAvatar').textContent = name.charAt(0).toUpperCase();
        document.getElementById('callName').textContent   = name;
        if (type === 'video') document.getElementById('camBtn').classList.remove('d-none');
    },
    _hideCallOverlay() {
        document.getElementById('callOverlay').classList.remove('active');
        document.getElementById('remoteVideo').style.display = 'none';
        document.getElementById('localVideo').style.display  = 'none';
        document.getElementById('callTimer').classList.add('d-none');
        document.getElementById('camBtn').classList.add('d-none');
        document.getElementById('acceptBtn').classList.add('d-none');
        this.isIncoming = false;
    },
    _endCallCleanup() {
        clearInterval(this.callPollTimer);
        clearInterval(this.callTimerInt);
        this.peerConn?.close(); this.peerConn = null;
        this.localStream?.getTracks().forEach(t => t.stop()); this.localStream = null;
        this.activeCallId = null;
    },
    _startCallTimer() {
        let s = 0;
        const el = document.getElementById('callTimer');
        el.classList.remove('d-none');
        this.callTimerInt = setInterval(() => { s++; el.textContent = this._fmtDur(s); }, 1000);
    },

    // ── Incoming call poll (separate from message poll) ──────────
    async _checkIncomingCall() {
        if (this.activeCallId) return;
        const r = await fetch(`${API}call.php?action=incoming`);
        const d = await r.json();
        if (d.call_id) this._handleIncomingCall(d);
    },

    // ── Helpers ───────────────────────────────────────────────────
    _fmtDur(s) { return Math.floor(s/60)+':'+(String(s%60).padStart(2,'0')); },
    _fmtSize(b) {
        if (b > 1048576) return (b/1048576).toFixed(1)+' MB';
        if (b > 1024)    return (b/1024).toFixed(0)+' KB';
        return b + ' B';
    },
    _fileIcon(mime) {
        if (mime.includes('pdf'))   return 'fa-file-pdf text-danger';
        if (mime.includes('word'))  return 'fa-file-word text-primary';
        if (mime.includes('excel') || mime.includes('sheet')) return 'fa-file-excel text-success';
        if (mime.includes('zip') || mime.includes('rar'))     return 'fa-file-zipper text-warning';
        if (mime.includes('image')) return 'fa-file-image text-info';
        return 'fa-file text-muted';
    },

    init() {
        this.loadConversations();
        // Poll incoming calls every 5s
        setInterval(() => this._checkIncomingCall(), 5000);
        // Attach file input
        document.getElementById('fileInput').addEventListener('change', async (ev) => {
            for (const f of ev.target.files) await this.uploadFile(f);
            ev.target.value = '';
        });
        // Conversation search filter
        document.getElementById('convSearch').addEventListener('input', function() {
            const q = this.value.toLowerCase();
            document.querySelectorAll('.conv-item').forEach(el => {
                el.style.display = el.querySelector('.conv-name').textContent.toLowerCase().includes(q) ? '' : 'none';
            });
        });
        // User search in modal
        document.getElementById('userSearch').addEventListener('input', function() {
            const q = this.value.toLowerCase();
            document.querySelectorAll('.user-pick-item').forEach(el => {
                el.style.display = el.dataset.name.toLowerCase().includes(q) ? '' : 'none';
            });
        });
    }
};

function e(str) {
    const d = document.createElement('div');
    d.textContent = str;
    return d.innerHTML;
}

ChatApp.init();
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>

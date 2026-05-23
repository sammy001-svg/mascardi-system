<?php
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
canAccess('chat') || die('Access denied.');

$pageTitle = 'Chat';
$me  = authUser();
$db  = getDB();

// All active users except self, for the New Chat modal
$stmt = $db->prepare("SELECT id, name AS full_name, role FROM users WHERE id != ? AND status = 'active' ORDER BY name ASC");
$stmt->execute([$me['id']]);
$allUsers = $stmt->fetchAll(PDO::FETCH_ASSOC);

$roleLabels = [
    'admin'            => 'Admin',
    'workshop_manager' => 'Workshop Manager',
    'sales_person'     => 'Sales Person',
    'sales_officer'    => 'Sales Officer',
    'manager'          => 'Manager',
    'mechanic'         => 'Mechanic',
];

// Colours for avatars – deterministic by user id
$avatarColors = ['#075e54','#128c7e','#25d366','#34b7f1','#e9c46a','#2a9d8f','#e76f51','#264653'];

// Pass CSRF token to JS for AJAX posts
$csrf = csrfToken();

$extraCss = <<<CSS
/* ─── Override page padding so chat fills the shell ─────────────────── */
.page-content          { padding: 0 !important; overflow: hidden; }
.app-content           { height: calc(100vh - 56px); overflow: hidden; }   /* 56px = topbar */

/* ─── Root wrapper ────────────────────────────────────────────────────  */
.chat-root {
    display: flex;
    height: 100%;
    background: #f0f2f5;
    font-family: 'Inter', 'Segoe UI', sans-serif;
}

/* ═══════════════════════════════════════════════════════════════════════
   LEFT PANEL – Conversation list
═══════════════════════════════════════════════════════════════════════ */
.chat-panel-left {
    width: 360px;
    min-width: 360px;
    display: flex;
    flex-direction: column;
    background: #fff;
    border-right: 1px solid #e9edef;
    position: relative;
    z-index: 2;
}

/* Header */
.cl-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 13px 16px 12px;
    background: #f0f2f5;
    border-bottom: 1px solid #e9edef;
}
.cl-header-title {
    font-size: 18px;
    font-weight: 700;
    color: #111b21;
    margin: 0;
}
.cl-header-actions { display: flex; gap: 4px; }
.cl-icon-btn {
    width: 36px; height: 36px;
    background: none; border: none; border-radius: 50%;
    color: #54656f; font-size: 17px;
    display: flex; align-items: center; justify-content: center;
    cursor: pointer; transition: background .15s;
}
.cl-icon-btn:hover { background: #e9edef; }

/* Search */
.cl-search {
    padding: 8px 12px;
    background: #fff;
    border-bottom: 1px solid #f0f2f5;
}
.cl-search-input {
    width: 100%;
    background: #f0f2f5;
    border: none;
    border-radius: 8px;
    padding: 7px 14px 7px 36px;
    font-size: 13.5px;
    outline: none;
    color: #111b21;
}
.cl-search-wrap { position: relative; }
.cl-search-icon {
    position: absolute;
    left: 11px; top: 50%; transform: translateY(-50%);
    color: #8696a0; font-size: 13px; pointer-events: none;
}

/* Conversation list */
.conv-list { flex: 1; overflow-y: auto; }
.conv-list::-webkit-scrollbar { width: 4px; }
.conv-list::-webkit-scrollbar-thumb { background: #c9d0d7; border-radius: 4px; }

.conv-item {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 10px 16px;
    cursor: pointer;
    border-bottom: 1px solid #f0f2f5;
    transition: background .12s;
    position: relative;
}
.conv-item:hover  { background: #f5f6f6; }
.conv-item.active { background: #f0f2f5; }

.conv-avatar {
    width: 48px; height: 48px; border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-weight: 700; font-size: 18px; color: #fff;
    flex-shrink: 0; letter-spacing: 0.5px;
}
.conv-body { flex: 1; min-width: 0; }
.conv-top  { display: flex; align-items: baseline; justify-content: space-between; margin-bottom: 2px; }
.conv-name {
    font-weight: 600; font-size: 14.5px; color: #111b21;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
    flex: 1; margin-right: 6px;
}
.conv-time { font-size: 11px; color: #8696a0; flex-shrink: 0; }
.conv-bottom { display: flex; align-items: center; justify-content: space-between; }
.conv-preview {
    font-size: 12.5px; color: #667781;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
    flex: 1; margin-right: 6px;
}
.conv-unread {
    min-width: 20px; height: 20px; padding: 0 5px;
    background: #25d366; color: #fff;
    border-radius: 10px; font-size: 11px; font-weight: 700;
    display: flex; align-items: center; justify-content: center;
    flex-shrink: 0;
}

.conv-empty {
    padding: 40px 20px; text-align: center;
    color: #8696a0; font-size: 13.5px;
}
.conv-empty i { font-size: 40px; opacity: .25; display: block; margin-bottom: 12px; }

/* ═══════════════════════════════════════════════════════════════════════
   RIGHT PANEL – Active chat
═══════════════════════════════════════════════════════════════════════ */
.chat-panel-right {
    flex: 1;
    display: flex;
    flex-direction: column;
    min-width: 0;
    position: relative;
}

/* Empty / welcome state */
.chat-welcome {
    flex: 1;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    color: #8696a0;
    background: #f0f2f5;
    gap: 12px;
}
.chat-welcome-icon {
    width: 80px; height: 80px; border-radius: 50%;
    background: #e9edef;
    display: flex; align-items: center; justify-content: center;
    font-size: 32px; color: #aebac1;
}
.chat-welcome h6 { color: #3b4a54; font-size: 22px; font-weight: 300; margin: 0; }
.chat-welcome p  { font-size: 13.5px; margin: 0; }

/* Active pane */
.chat-active {
    display: none;
    flex-direction: column;
    height: 100%;
}
.chat-active.visible { display: flex; }

/* Chat header */
.chat-header {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 10px 16px;
    background: #f0f2f5;
    border-bottom: 1px solid #e9edef;
    flex-shrink: 0;
}
.chat-header-avatar {
    width: 42px; height: 42px; border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-weight: 700; font-size: 16px; color: #fff;
    flex-shrink: 0; cursor: pointer;
}
.chat-header-info { flex: 1; min-width: 0; }
.chat-header-name { font-weight: 700; font-size: 15px; color: #111b21; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.chat-header-sub  { font-size: 12px; color: #667781; }
.chat-header-btns { display: flex; gap: 2px; }
.chat-header-btns .cl-icon-btn { font-size: 18px; }
/* back button for mobile */
.chat-back-btn { display: none; }

/* Messages area */
.chat-messages-area {
    flex: 1;
    overflow-y: auto;
    padding: 16px;
    background: #efeae2;
    display: flex;
    flex-direction: column;
    gap: 2px;
    /* WhatsApp-style tiled background */
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='400' height='400' opacity='0.04'%3E%3Cpath d='M50 50 L350 50 L350 350 L50 350Z' fill='none' stroke='%23000' stroke-width='1'/%3E%3C/svg%3E");
}
.chat-messages-area::-webkit-scrollbar { width: 5px; }
.chat-messages-area::-webkit-scrollbar-thumb { background: #c9d0d7; border-radius: 5px; }

/* Day divider */
.msg-day {
    display: flex; align-items: center; justify-content: center;
    margin: 10px 0 8px;
}
.msg-day span {
    background: #fff; color: #54656f;
    font-size: 12px; font-weight: 500; padding: 4px 12px;
    border-radius: 8px; box-shadow: 0 1px 2px rgba(0,0,0,.1);
}

/* Bubble wrapper */
.msg-row { display: flex; margin: 1px 0; }
.msg-row.sent     { justify-content: flex-end; }
.msg-row.received { justify-content: flex-start; }

/* Bubble */
.msg-bubble {
    max-width: 62%;
    padding: 6px 9px 22px 9px;
    border-radius: 8px;
    font-size: 14px; line-height: 1.45;
    position: relative;
    word-wrap: break-word;
    box-shadow: 0 1px 2px rgba(0,0,0,.12);
}
.msg-row.sent .msg-bubble {
    background: #d9fdd3;
    border-top-right-radius: 2px;
}
.msg-row.received .msg-bubble {
    background: #fff;
    border-top-left-radius: 2px;
}

/* Bubble tail via ::before pseudo */
.msg-row.sent .msg-bubble::before {
    content: ''; position: absolute; top: 0; right: -8px;
    border: 8px solid transparent;
    border-top-color: #d9fdd3; border-right: none; border-left: none;
}
.msg-row.received .msg-bubble::before {
    content: ''; position: absolute; top: 0; left: -8px;
    border: 8px solid transparent;
    border-top-color: #fff; border-left: none; border-right: none;
}

/* Sender name (group chats) */
.msg-sender-name { font-size: 12.5px; font-weight: 700; color: #128c7e; margin-bottom: 3px; }

/* Timestamp + tick */
.msg-footer {
    position: absolute; bottom: 4px; right: 8px;
    display: flex; align-items: center; gap: 3px;
    font-size: 11px; color: #8696a0;
}
.msg-tick { color: #53bdeb; font-size: 11px; }

/* Text content */
.msg-text { white-space: pre-wrap; }

/* Image bubble */
.msg-img {
    max-width: 260px; border-radius: 6px;
    cursor: zoom-in; display: block;
    margin-bottom: 4px;
}

/* File bubble */
.msg-file-wrap {
    display: flex; align-items: center; gap: 10px;
    background: rgba(0,0,0,.05); border-radius: 6px;
    padding: 9px 11px; min-width: 210px; margin-bottom: 4px;
    text-decoration: none; color: inherit;
}
.msg-file-icon { font-size: 30px; flex-shrink: 0; }
.msg-file-name { font-size: 13px; font-weight: 600; word-break: break-all; color: #111b21; }
.msg-file-size { font-size: 11.5px; color: #667781; }
.msg-file-dl   { color: #8696a0; font-size: 15px; margin-left: auto; flex-shrink: 0; }

/* Voice note bubble */
.msg-voice-wrap {
    display: flex; align-items: center; gap: 10px;
    min-width: 230px; padding: 2px 0 6px;
}
.msg-voice-btn {
    width: 40px; height: 40px; border-radius: 50%;
    background: #128c7e; color: #fff; border: none;
    display: flex; align-items: center; justify-content: center;
    cursor: pointer; font-size: 15px; flex-shrink: 0;
    transition: background .15s;
}
.msg-voice-btn:hover { background: #0f7268; }
.msg-voice-waveform {
    flex: 1; height: 28px; border-radius: 4px; cursor: pointer;
    background: linear-gradient(to right,
        #128c7e 0%, #128c7e var(--prog,0%),
        rgba(0,0,0,.15) var(--prog,0%), rgba(0,0,0,.15) 100%);
}
.msg-voice-dur { font-size: 12px; color: #667781; min-width: 34px; text-align: right; }

/* Call/system row */
.msg-row-call {
    display: flex; justify-content: center; margin: 6px 0;
}
.msg-row-call .msg-call-chip {
    background: rgba(0,0,0,.06); border-radius: 8px;
    padding: 5px 14px; font-size: 12.5px; color: #54656f;
}

/* ═══════════════════════════════════════════════════════════════════════
   INPUT BAR
═══════════════════════════════════════════════════════════════════════ */
.chat-input-bar {
    display: flex;
    align-items: flex-end;
    gap: 8px;
    padding: 8px 12px 10px;
    background: #f0f2f5;
    border-top: 1px solid #e9edef;
    flex-shrink: 0;
}

/* Icon buttons (attach, emoji, mic) */
.input-icon-btn {
    background: none; border: none;
    color: #54656f; font-size: 22px;
    width: 40px; height: 40px; border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    cursor: pointer; flex-shrink: 0; transition: background .15s;
}
.input-icon-btn:hover { background: #e9edef; }

/* Text area wrapper */
.input-text-wrap {
    flex: 1;
    display: flex;
    flex-direction: column;
    position: relative;
}

/* Editable div */
.input-text {
    background: #fff;
    border-radius: 10px;
    padding: 9px 14px;
    font-size: 14.5px; line-height: 1.45;
    min-height: 42px; max-height: 130px;
    overflow-y: auto; outline: none;
    color: #111b21;
    word-wrap: break-word;
}
.input-text:empty::before {
    content: attr(data-ph);
    color: #8696a0;
    pointer-events: none;
}

/* Recording state replaces text area */
.rec-bar {
    display: none;
    background: #fff; border-radius: 10px;
    padding: 9px 14px; align-items: center; gap: 10px;
    min-height: 42px;
}
.rec-bar.on { display: flex; }
.rec-dot {
    width: 10px; height: 10px; border-radius: 50%;
    background: #dc2626; flex-shrink: 0;
    animation: blink 1s step-start infinite;
}
@keyframes blink { 50%{opacity:0} }
.rec-label { font-size: 13.5px; color: #dc2626; font-weight: 500; }
.rec-timer { font-size: 13.5px; color: #111b21; font-weight: 600; margin-left: 4px; }
.rec-cancel { margin-left: auto; background: none; border: none; color: #8696a0; font-size: 20px; cursor: pointer; padding: 0 4px; }
.rec-cancel:hover { color: #dc2626; }

/* Send / mic FAB */
.input-send-btn {
    width: 44px; height: 44px; border-radius: 50%; border: none;
    background: #128c7e; color: #fff; font-size: 17px;
    display: flex; align-items: center; justify-content: center;
    cursor: pointer; flex-shrink: 0;
    transition: background .15s, transform .1s;
}
.input-send-btn:hover  { background: #0f7268; }
.input-send-btn:active { transform: scale(.92); }
.input-send-btn.mic-mode { background: #128c7e; }
.input-send-btn.mic-mode.recording { background: #dc2626; }

/* ═══════════════════════════════════════════════════════════════════════
   CALL OVERLAY (full-screen)
═══════════════════════════════════════════════════════════════════════ */
.call-overlay {
    position: fixed; inset: 0; z-index: 9990;
    background: #1f2c34;
    display: none; flex-direction: column;
    align-items: center; justify-content: center;
    color: #fff;
}
.call-overlay.on { display: flex; }

/* Remote video (fills when active) */
.call-remote-video {
    position: absolute; inset: 0;
    width: 100%; height: 100%;
    object-fit: cover; display: none;
    background: #000;
}
/* Local PiP */
.call-local-video {
    position: absolute; bottom: 130px; right: 20px;
    width: 130px; border-radius: 10px; border: 2px solid #fff;
    object-fit: cover; display: none; z-index: 2;
}

/* Overlay content (sits above video) */
.call-content {
    position: relative; z-index: 3;
    display: flex; flex-direction: column; align-items: center;
    gap: 0;
}
.call-avatar {
    width: 90px; height: 90px; border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-size: 34px; font-weight: 700; color: #fff;
    margin-bottom: 16px;
}
.call-name   { font-size: 24px; font-weight: 600; margin-bottom: 6px; }
.call-status { font-size: 14px; color: rgba(255,255,255,.65); margin-bottom: 4px; }
.call-timer  { font-size: 20px; font-weight: 600; letter-spacing: 1px; min-height: 28px; margin-bottom: 32px; }

.call-btns { display: flex; gap: 24px; align-items: center; }
.call-btn {
    width: 62px; height: 62px; border-radius: 50%; border: none;
    font-size: 22px; cursor: pointer;
    display: flex; align-items: center; justify-content: center;
    transition: transform .15s, opacity .15s;
}
.call-btn:hover  { opacity: .85; }
.call-btn:active { transform: scale(.9); }
.call-btn-mute   { background: rgba(255,255,255,.2); color: #fff; }
.call-btn-cam    { background: rgba(255,255,255,.2); color: #fff; }
.call-btn-end    { background: #e53935; color: #fff; }
.call-btn-accept { background: #43a047; color: #fff; }
.call-btn-mute.off { background: #e0e0e0; color: #333; }

/* ═══════════════════════════════════════════════════════════════════════
   IMAGE LIGHTBOX
═══════════════════════════════════════════════════════════════════════ */
.img-lightbox {
    position: fixed; inset: 0; z-index: 9995;
    background: rgba(0,0,0,.92);
    display: none; align-items: center; justify-content: center;
    flex-direction: column; gap: 12px;
}
.img-lightbox.on { display: flex; }
.img-lightbox img { max-width: 92vw; max-height: 85vh; border-radius: 6px; object-fit: contain; }
.img-lightbox-close {
    position: absolute; top: 16px; right: 20px;
    color: #fff; font-size: 28px; cursor: pointer;
    background: none; border: none; line-height: 1;
}

/* ═══════════════════════════════════════════════════════════════════════
   NEW CHAT MODAL – user picker
═══════════════════════════════════════════════════════════════════════ */
.user-pick-list { max-height: 360px; overflow-y: auto; }
.user-pick-item {
    display: flex; align-items: center; gap: 12px;
    padding: 10px 14px; cursor: pointer; border-radius: 8px;
    transition: background .12s;
}
.user-pick-item:hover { background: #f0f2f5; }
.user-pick-avatar {
    width: 42px; height: 42px; border-radius: 50%;
    color: #fff; font-weight: 700; font-size: 15px;
    display: flex; align-items: center; justify-content: center;
    flex-shrink: 0;
}
.user-pick-name { font-size: 14px; font-weight: 600; color: #111b21; }
.user-pick-role { font-size: 12px; color: #667781; }

/* ═══════════════════════════════════════════════════════════════════════
   RESPONSIVE – mobile
═══════════════════════════════════════════════════════════════════════ */
@media (max-width: 767px) {
    .chat-panel-left  { width: 100%; min-width: 100%; }
    .chat-panel-right { position: absolute; inset: 0; z-index: 5; }
    .chat-panel-right.mobile-hidden { display: none; }
    .chat-back-btn { display: flex !important; }
}
CSS;

include __DIR__ . '/../../includes/header.php';
?>

<div class="chat-root" id="chatRoot">

    <!-- ══════════════════════════════════════════════════════════
         LEFT PANEL – conversation list
    ══════════════════════════════════════════════════════════ -->
    <div class="chat-panel-left" id="chatPanelLeft">

        <div class="cl-header">
            <h6 class="cl-header-title">Messages</h6>
            <div class="cl-header-actions">
                <button class="cl-icon-btn" title="New chat"
                        data-bs-toggle="modal" data-bs-target="#newChatModal">
                    <i class="fa fa-pen-to-square"></i>
                </button>
            </div>
        </div>

        <div class="cl-search">
            <div class="cl-search-wrap">
                <i class="fa fa-magnifying-glass cl-search-icon"></i>
                <input type="text" class="cl-search-input" id="convSearch"
                       placeholder="Search conversations…" autocomplete="off">
            </div>
        </div>

        <div class="conv-list" id="convList">
            <div class="conv-empty">
                <i class="fa fa-spinner fa-spin"></i>
                Loading conversations…
            </div>
        </div>

    </div><!-- /chat-panel-left -->

    <!-- ══════════════════════════════════════════════════════════
         RIGHT PANEL – active chat
    ══════════════════════════════════════════════════════════ -->
    <div class="chat-panel-right mobile-hidden" id="chatPanelRight">

        <!-- Welcome screen (no conversation selected) -->
        <div class="chat-welcome" id="chatWelcome">
            <div class="chat-welcome-icon"><i class="fa fa-comments"></i></div>
            <h6>Mascardi Chat</h6>
            <p>Select a conversation or start a new one</p>
        </div>

        <!-- Active conversation pane -->
        <div class="chat-active" id="chatActive">

            <!-- Header -->
            <div class="chat-header" id="chatHeader">
                <button class="cl-icon-btn chat-back-btn" id="chatBackBtn" title="Back">
                    <i class="fa fa-arrow-left"></i>
                </button>
                <div class="chat-header-avatar" id="hdrAvatar"
                     style="background:#128c7e">–</div>
                <div class="chat-header-info">
                    <div class="chat-header-name" id="hdrName">—</div>
                    <div class="chat-header-sub"  id="hdrSub"></div>
                </div>
                <div class="chat-header-btns">
                    <button class="cl-icon-btn" title="Voice call"
                            onclick="Chat.call('audio')" id="btnCallAudio">
                        <i class="fa fa-phone"></i>
                    </button>
                    <button class="cl-icon-btn" title="Video call"
                            onclick="Chat.call('video')" id="btnCallVideo">
                        <i class="fa fa-video"></i>
                    </button>
                </div>
            </div>

            <!-- Messages scroll area -->
            <div class="chat-messages-area" id="msgArea"></div>

            <!-- Input bar -->
            <div class="chat-input-bar">

                <!-- Attach -->
                <button class="input-icon-btn" title="Attach file"
                        onclick="document.getElementById('fileIn').click()">
                    <i class="fa fa-paperclip"></i>
                </button>
                <input type="file" id="fileIn" style="display:none" multiple
                       accept="image/*,.pdf,.doc,.docx,.xls,.xlsx,.zip,.rar,.txt,.csv">

                <!-- Text / recording bar -->
                <div class="input-text-wrap">
                    <div class="input-text" id="msgInput"
                         contenteditable="true" data-ph="Type a message…"
                         role="textbox" aria-multiline="true"
                         onkeydown="Chat.onKey(event)"></div>
                    <div class="rec-bar" id="recBar">
                        <div class="rec-dot"></div>
                        <span class="rec-label">Recording</span>
                        <span class="rec-timer" id="recTimer">0:00</span>
                        <button class="rec-cancel" onclick="Chat.cancelRec()" title="Cancel">
                            <i class="fa fa-xmark"></i>
                        </button>
                    </div>
                </div>

                <!-- Send / microphone -->
                <button class="input-send-btn mic-mode" id="sendBtn"
                        onclick="Chat.sendOrStopRec()">
                    <i class="fa fa-microphone" id="sendIcon"></i>
                </button>

            </div><!-- /chat-input-bar -->

        </div><!-- /chat-active -->

    </div><!-- /chat-panel-right -->

</div><!-- /chat-root -->

<!-- ══════════════════════════════════════════════════════════════════
     CALL OVERLAY
══════════════════════════════════════════════════════════════════ -->
<div class="call-overlay" id="callOverlay">
    <video class="call-remote-video" id="remoteVid" autoplay playsinline></video>
    <video class="call-local-video"  id="localVid"  autoplay muted playsinline></video>

    <div class="call-content">
        <div class="call-avatar" id="callAvatar" style="background:#128c7e">–</div>
        <div class="call-name"   id="callName">—</div>
        <div class="call-status" id="callStatusTxt">Calling…</div>
        <div class="call-timer"  id="callTimer"></div>
        <div class="call-btns">
            <button class="call-btn call-btn-mute" id="btnMute"
                    onclick="Chat.toggleMute()" title="Mute">
                <i class="fa fa-microphone"></i>
            </button>
            <button class="call-btn call-btn-cam d-none" id="btnCam"
                    onclick="Chat.toggleCam()" title="Camera">
                <i class="fa fa-video"></i>
            </button>
            <button class="call-btn call-btn-end" onclick="Chat.hangup()" title="End call">
                <i class="fa fa-phone-slash"></i>
            </button>
            <button class="call-btn call-btn-accept d-none" id="btnAccept"
                    onclick="Chat.acceptCall()" title="Accept">
                <i class="fa fa-phone"></i>
            </button>
        </div>
    </div>
</div>

<!-- Image lightbox -->
<div class="img-lightbox" id="imgLightbox" onclick="Chat.closeLightbox()">
    <button class="img-lightbox-close" onclick="Chat.closeLightbox()">
        <i class="fa fa-xmark"></i>
    </button>
    <img id="lbImg" src="" alt="Preview">
</div>

<!-- ══════════════════════════════════════════════════════════════════
     NEW CHAT MODAL
══════════════════════════════════════════════════════════════════ -->
<div class="modal fade" id="newChatModal" tabindex="-1" aria-label="New Chat">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <div class="modal-header py-2">
                <h6 class="modal-title fw-semibold">
                    <i class="fa fa-pen-to-square me-2 text-muted"></i>New Chat
                </h6>
                <button type="button" class="btn-close btn-close-sm"
                        data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-2">
                <input type="text" id="userSearch" class="form-control form-control-sm mb-2"
                       placeholder="Search people…" autocomplete="off">
                <div class="user-pick-list" id="userPickList">
                    <?php foreach ($allUsers as $u):
                        $init  = mb_strtoupper(mb_substr($u['full_name'], 0, 1));
                        $color = $avatarColors[$u['id'] % count($avatarColors)];
                        $rl    = $roleLabels[$u['role']] ?? ucfirst($u['role']);
                    ?>
                    <div class="user-pick-item"
                         data-name="<?= e($u['full_name']) ?>"
                         onclick="Chat.startDirect(<?= (int)$u['id'] ?>, <?= json_encode($u['full_name']) ?>, '<?= e($color) ?>')">
                        <div class="user-pick-avatar"
                             style="background:<?= $color ?>"><?= e($init) ?></div>
                        <div>
                            <div class="user-pick-name"><?= e($u['full_name']) ?></div>
                            <div class="user-pick-role"><?= e($rl) ?></div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    <?php if (empty($allUsers)): ?>
                    <div class="text-muted small text-center py-3">No other users found.</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
/* ════════════════════════════════════════════════════════════════════════
   Chat application
════════════════════════════════════════════════════════════════════════ */
const ME = {
    id:   <?= (int)$me['id'] ?>,
    name: <?= json_encode($me['full_name'] ?? $me['name'] ?? 'Me') ?>
};
const BASE_URL  = <?= json_encode(rtrim(BASE_URL, '/')) ?>;
const API_BASE  = BASE_URL + '/modules/chat/api/';
const CSRF      = <?= json_encode($csrf) ?>;

// Avatar colours (server-side palette, index by user id)
const AVATAR_COLORS = <?= json_encode($avatarColors) ?>;
function avatarColor(id) { return AVATAR_COLORS[id % AVATAR_COLORS.length]; }
function initials(name)  { return (name || '?').charAt(0).toUpperCase(); }

// ─── Utility helpers ─────────────────────────────────────────────────────
function esc(str) {
    const d = document.createElement('div');
    d.textContent = str ?? '';
    return d.innerHTML;
}
function fmtDur(s) {
    return Math.floor(s / 60) + ':' + String(s % 60).padStart(2, '0');
}
function fmtSize(b) {
    if (b > 1048576) return (b / 1048576).toFixed(1) + ' MB';
    if (b > 1024)    return (b / 1024).toFixed(0) + ' KB';
    return b + ' B';
}
function fmtTime(iso) {
    if (!iso) return '';
    const d = new Date(iso.replace(' ', 'T'));
    if (isNaN(d)) return '';
    return d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', hour12: false });
}
function fmtDay(iso) {
    if (!iso) return '';
    const d   = new Date(iso.replace(' ', 'T'));
    if (isNaN(d)) return '';
    const now = new Date();
    const diff = Math.floor((now - d) / 86400000);
    if (diff === 0) return 'Today';
    if (diff === 1) return 'Yesterday';
    return d.toLocaleDateString([], { day: 'numeric', month: 'short', year: 'numeric' });
}
function fileIcon(mime) {
    mime = mime || '';
    if (mime.includes('pdf'))                                return ['fa-file-pdf',   'text-danger'];
    if (mime.includes('word') || mime.includes('document'))  return ['fa-file-word',  'text-primary'];
    if (mime.includes('excel') || mime.includes('sheet'))    return ['fa-file-excel', 'text-success'];
    if (mime.includes('zip')  || mime.includes('rar'))       return ['fa-file-zipper','text-warning'];
    if (mime.includes('image'))                              return ['fa-file-image',  'text-info'];
    if (mime.includes('audio'))                              return ['fa-file-audio',  'text-secondary'];
    return ['fa-file', 'text-muted'];
}

// ─── AJAX helpers ─────────────────────────────────────────────────────────
async function apiGet(endpoint, params = {}) {
    const qs = new URLSearchParams(params).toString();
    const r  = await fetch(API_BASE + endpoint + (qs ? '?' + qs : ''));
    return r.json();
}
async function apiPost(endpoint, body = {}) {
    const r = await fetch(API_BASE + endpoint, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': CSRF,
        },
        body: JSON.stringify(body),
    });
    return r.json();
}
async function apiUpload(endpoint, fd) {
    fd.append('csrf_token', CSRF);
    const r = await fetch(API_BASE + endpoint, { method: 'POST', body: fd });
    return r.json();
}

/* ════════════════════════════════════════════════════════════════════════
   Main Chat object
════════════════════════════════════════════════════════════════════════ */
const Chat = {
    convId      : null,
    convName    : '',
    convColor   : '#128c7e',
    calleeId    : null,
    lastMsgId   : 0,
    lastDay     : '',
    pollTimer   : null,
    callPoll    : null,
    isRecording : false,
    mediaRec    : null,
    audioChunks : [],
    recTimerInt : null,
    recSecs     : 0,
    localStream : null,
    peerConn    : null,
    activeCallId: null,
    callTimerInt: null,
    isMuted     : false,
    isCamOff    : false,
    isIncoming  : false,
    pendingIce  : [],

    STUN: { iceServers: [
        { urls: 'stun:stun.l.google.com:19302' },
        { urls: 'stun:stun1.l.google.com:19302' },
    ]},

    /* ── Conversation list ─────────────────────────────────────── */
    async loadConvs() {
        try {
            const data = await apiGet('conversations.php');
            const convs = data.conversations || [];
            const list  = document.getElementById('convList');
            if (!convs.length) {
                list.innerHTML = `<div class="conv-empty">
                    <i class="fa fa-message"></i>
                    No conversations yet.<br>
                    <span style="font-size:12px">Tap the pencil icon to start one.</span>
                </div>`;
                return;
            }
            list.innerHTML = convs.map(c => this._convHtml(c)).join('');
            // Re-apply active state
            if (this.convId) {
                const el = list.querySelector(`[data-cid="${this.convId}"]`);
                if (el) el.classList.add('active');
            }
        } catch (e) {
            console.error('loadConvs:', e);
        }
    },

    _convHtml(c) {
        const color   = avatarColor(c.other_user_id || c.id);
        const init    = initials(c.display_name);
        const time    = c.last_msg_at ? fmtTime(c.last_msg_at) : '';
        const preview = esc((c.last_preview || '').substring(0, 55));
        const unread  = (c.unread_count > 0)
            ? `<div class="conv-unread">${c.unread_count > 99 ? '99+' : c.unread_count}</div>` : '';
        const active  = (this.convId == c.id) ? 'active' : '';
        return `<div class="conv-item ${active}" data-cid="${c.id}"
                     onclick="Chat.openConv(${c.id}, ${JSON.stringify(c.display_name)}, '${color}', ${c.other_user_id || 0})">
            <div class="conv-avatar" style="background:${color}">${esc(init)}</div>
            <div class="conv-body">
                <div class="conv-top">
                    <span class="conv-name">${esc(c.display_name)}</span>
                    <span class="conv-time">${time}</span>
                </div>
                <div class="conv-bottom">
                    <span class="conv-preview">${preview}</span>
                    ${unread}
                </div>
            </div>
        </div>`;
    },

    /* ── Open a conversation ───────────────────────────────────── */
    async openConv(convId, name, color, calleeId) {
        this.convId    = convId;
        this.convName  = name;
        this.convColor = color || '#128c7e';
        this.calleeId  = calleeId || null;
        this.lastMsgId = 0;
        this.lastDay   = '';

        // Update header
        document.getElementById('hdrAvatar').textContent    = initials(name);
        document.getElementById('hdrAvatar').style.background = this.convColor;
        document.getElementById('hdrName').textContent      = name;
        document.getElementById('hdrSub').textContent       = '';

        // Show call buttons only for direct chats
        document.getElementById('btnCallAudio').style.display = calleeId ? '' : 'none';
        document.getElementById('btnCallVideo').style.display = calleeId ? '' : 'none';

        // Show right panel, hide left on mobile
        const right = document.getElementById('chatPanelRight');
        right.classList.remove('mobile-hidden');
        document.getElementById('chatWelcome').style.display = 'none';
        const active = document.getElementById('chatActive');
        active.classList.add('visible');

        // Mark sidebar item
        document.querySelectorAll('.conv-item').forEach(el => el.classList.remove('active'));
        const item = document.querySelector(`[data-cid="${convId}"]`);
        if (item) item.classList.add('active');

        // Load messages
        const area = document.getElementById('msgArea');
        area.innerHTML = `<div class="text-center text-muted py-4" style="font-size:13px">
            <i class="fa fa-spinner fa-spin"></i>&nbsp; Loading…</div>`;

        clearInterval(this.pollTimer);
        await this._fetchMsgs(true);
        this.pollTimer = setInterval(() => this._fetchMsgs(false), 2000);

        // Focus input
        document.getElementById('msgInput').focus();
    },

    /* ── Fetch & render messages (polling) ─────────────────────── */
    async _fetchMsgs(initial) {
        try {
            const data = await apiGet('messages.php', {
                conversation_id: this.convId,
                after: this.lastMsgId,
            });
            const msgs = data.messages || [];
            if (!msgs.length) {
                if (initial) {
                    document.getElementById('msgArea').innerHTML =
                        `<div class="text-center text-muted py-5" style="font-size:13px">
                            No messages yet. Say hello! 👋</div>`;
                }
                return;
            }

            const area = document.getElementById('msgArea');
            if (initial) area.innerHTML = '';

            msgs.forEach(m => {
                const day = fmtDay(m.created_at);
                if (day && day !== this.lastDay) {
                    area.insertAdjacentHTML('beforeend',
                        `<div class="msg-day"><span>${esc(day)}</span></div>`);
                    this.lastDay = day;
                }
                area.insertAdjacentHTML('beforeend', this._msgHtml(m));
                this.lastMsgId = Math.max(this.lastMsgId, parseInt(m.id));
            });

            // Auto-scroll to bottom on initial load or if already near bottom
            if (initial || this._nearBottom(area)) {
                area.scrollTop = area.scrollHeight;
            }

            // Refresh unread badges quietly
            if (!initial) this.loadConvs();
        } catch (e) {
            console.error('_fetchMsgs:', e);
        }
    },

    _nearBottom(el) {
        return el.scrollHeight - el.scrollTop - el.clientHeight < 120;
    },

    /* ── Render a single message bubble ──────────────────────── */
    _msgHtml(m) {
        const sent = (parseInt(m.sender_id) === ME.id);
        const cls  = sent ? 'sent' : 'received';
        const tick = sent ? `<span class="msg-tick"><i class="fa fa-check-double"></i></span>` : '';
        const time = fmtTime(m.created_at);

        // System / call rows get a centered chip
        if (m.type === 'system' || m.type === 'call') {
            const ico = m.type === 'call'
                ? (m.content?.includes('video') ? 'fa-video' : 'fa-phone')
                : 'fa-info-circle';
            return `<div class="msg-row-call">
                <div class="msg-call-chip">
                    <i class="fa ${ico} me-1"></i>${esc(m.content)}
                </div>
            </div>`;
        }

        let body = '';

        if (m.type === 'image' && m.file_url) {
            body = `<img class="msg-img" src="${esc(m.file_url)}"
                         alt="${esc(m.file_name || 'Image')}" loading="lazy"
                         onclick="Chat.lightbox('${esc(m.file_url)}')">`;
        } else if (m.type === 'voice' && m.file_url) {
            const dur = m.duration ? fmtDur(m.duration) : '—';
            const uid = 'v' + m.id;
            body = `<div class="msg-voice-wrap">
                <button class="msg-voice-btn" id="pb${uid}"
                        onclick="Chat.playVoice('${esc(m.file_url)}','${uid}')">
                    <i class="fa fa-play"></i>
                </button>
                <div class="msg-voice-waveform" id="wf${uid}" style="--prog:0%"></div>
                <span class="msg-voice-dur" id="dr${uid}">${dur}</span>
            </div>`;
        } else if (m.type === 'file' && m.file_url) {
            const [ico, col] = fileIcon(m.mime_type);
            const sz = m.file_size ? fmtSize(m.file_size) : '';
            body = `<a class="msg-file-wrap" href="${esc(m.file_url)}"
                       download="${esc(m.file_name || 'file')}" target="_blank">
                <i class="fa ${ico} ${col} msg-file-icon"></i>
                <div style="flex:1;min-width:0">
                    <div class="msg-file-name">${esc(m.file_name || 'File')}</div>
                    ${sz ? `<div class="msg-file-size">${sz}</div>` : ''}
                </div>
                <i class="fa fa-download msg-file-dl"></i>
            </a>`;
        } else {
            // text (or fallback)
            body = `<span class="msg-text">${esc(m.content || '')}</span>`;
        }

        return `<div class="msg-row ${cls}">
            <div class="msg-bubble">
                ${body}
                <div class="msg-footer">${time} ${tick}</div>
            </div>
        </div>`;
    },

    /* ── Send text ───────────────────────────────────────────── */
    async sendText() {
        if (!this.convId) return;
        const el   = document.getElementById('msgInput');
        const text = el.innerText.trim();
        if (!text) return;
        el.innerText = '';
        this._setSendMode('mic');
        try {
            await apiPost('send.php', {
                conversation_id: this.convId,
                content: text,
            });
            await this._fetchMsgs(false);
        } catch (e) {
            console.error('sendText:', e);
        }
    },

    /* ── Send or stop recording (unified send button) ────────── */
    sendOrStopRec() {
        if (this.isRecording) {
            this.stopRec();
        } else {
            const text = document.getElementById('msgInput').innerText.trim();
            if (text) {
                this.sendText();
            } else {
                this.startRec();
            }
        }
    },

    /* ── Key handler for input ───────────────────────────────── */
    onKey(event) {
        const text = document.getElementById('msgInput').innerText.trim();
        this._setSendMode(text ? 'send' : 'mic');
        if (event.key === 'Enter' && !event.shiftKey) {
            event.preventDefault();
            this.sendText();
        }
    },

    _setSendMode(mode) {
        const btn  = document.getElementById('sendBtn');
        const icon = document.getElementById('sendIcon');
        if (mode === 'send') {
            icon.className = 'fa fa-paper-plane';
            btn.title = 'Send message';
        } else {
            icon.className = 'fa fa-microphone';
            btn.title = 'Record voice note';
        }
    },

    /* ── Voice recording ─────────────────────────────────────── */
    async startRec() {
        if (!this.convId || this.isRecording) return;
        try {
            const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
            this.audioChunks = [];
            this.mediaRec = new MediaRecorder(stream, { mimeType: this._bestMime() });
            this.mediaRec.ondataavailable = ev => this.audioChunks.push(ev.data);
            this.mediaRec.start(100);
            this.isRecording = true;

            document.getElementById('msgInput').style.display = 'none';
            document.getElementById('recBar').classList.add('on');
            const btn = document.getElementById('sendBtn');
            btn.classList.add('recording');
            document.getElementById('sendIcon').className = 'fa fa-stop';
            btn.title = 'Stop recording';

            this.recSecs = 0;
            document.getElementById('recTimer').textContent = '0:00';
            this.recTimerInt = setInterval(() => {
                this.recSecs++;
                document.getElementById('recTimer').textContent = fmtDur(this.recSecs);
            }, 1000);
        } catch (err) {
            alert('Microphone access denied. Please allow microphone access in your browser.');
        }
    },

    _bestMime() {
        const types = ['audio/webm;codecs=opus', 'audio/webm', 'audio/ogg;codecs=opus', 'audio/mp4'];
        for (const t of types) { if (MediaRecorder.isTypeSupported(t)) return t; }
        return '';
    },

    async stopRec() {
        if (!this.isRecording || !this.mediaRec) return;
        const dur = this.recSecs;
        clearInterval(this.recTimerInt);
        this.isRecording = false;

        document.getElementById('msgInput').style.display = '';
        document.getElementById('recBar').classList.remove('on');
        const btn = document.getElementById('sendBtn');
        btn.classList.remove('recording');
        this._setSendMode('mic');

        return new Promise(resolve => {
            this.mediaRec.onstop = async () => {
                const mimeType = this.mediaRec.mimeType || 'audio/webm';
                const ext      = mimeType.includes('ogg') ? 'ogg' : 'webm';
                const blob     = new Blob(this.audioChunks, { type: mimeType });
                this.mediaRec.stream.getTracks().forEach(t => t.stop());
                this.mediaRec = null;

                if (dur < 1 || !this.convId) { resolve(); return; }

                const fd = new FormData();
                fd.append('conversation_id', this.convId);
                fd.append('file', blob, `voice.${ext}`);
                fd.append('voice', '1');
                fd.append('duration', dur);
                try {
                    await apiUpload('upload.php', fd);
                    await this._fetchMsgs(false);
                } catch (e) {
                    console.error('stopRec upload:', e);
                }
                resolve();
            };
            this.mediaRec.stop();
        });
    },

    cancelRec() {
        if (!this.isRecording || !this.mediaRec) return;
        clearInterval(this.recTimerInt);
        this.isRecording = false;
        this.mediaRec.stream.getTracks().forEach(t => t.stop());
        this.mediaRec = null;
        document.getElementById('msgInput').style.display = '';
        document.getElementById('recBar').classList.remove('on');
        const btn = document.getElementById('sendBtn');
        btn.classList.remove('recording');
        this._setSendMode('mic');
    },

    /* ── File upload ─────────────────────────────────────────── */
    async uploadFile(file) {
        if (!this.convId) return;
        const fd = new FormData();
        fd.append('conversation_id', this.convId);
        fd.append('file', file, file.name);
        try {
            await apiUpload('upload.php', fd);
            await this._fetchMsgs(false);
        } catch (e) {
            console.error('uploadFile:', e);
        }
    },

    /* ── Voice playback ──────────────────────────────────────── */
    _audio: null,
    playVoice(src, uid) {
        // Stop any currently playing audio
        if (this._audio) {
            this._audio.pause();
            this._audio = null;
            // Reset all play buttons
            document.querySelectorAll('.msg-voice-btn i').forEach(i => i.className = 'fa fa-play');
            document.querySelectorAll('.msg-voice-waveform').forEach(w => w.style.setProperty('--prog','0%'));
        }

        const pbBtn = document.getElementById('pb' + uid);
        const wf    = document.getElementById('wf' + uid);
        const dr    = document.getElementById('dr' + uid);

        const audio = new Audio(src);
        this._audio = audio;
        pbBtn.innerHTML = '<i class="fa fa-pause"></i>';

        audio.addEventListener('timeupdate', () => {
            const pct = audio.duration ? (audio.currentTime / audio.duration * 100) : 0;
            wf.style.setProperty('--prog', pct.toFixed(1) + '%');
            if (dr) dr.textContent = fmtDur(Math.floor(audio.currentTime));
        });
        audio.addEventListener('ended', () => {
            pbBtn.innerHTML = '<i class="fa fa-play"></i>';
            wf.style.setProperty('--prog', '0%');
            this._audio = null;
        });
        audio.play().catch(() => {});

        pbBtn.onclick = () => {
            audio.pause();
            pbBtn.innerHTML = '<i class="fa fa-play"></i>';
            pbBtn.onclick = () => Chat.playVoice(src, uid);
            this._audio = null;
        };
    },

    /* ── Start direct conversation ───────────────────────────── */
    async startDirect(userId, name, color) {
        const modal = bootstrap.Modal.getInstance(document.getElementById('newChatModal'));
        if (modal) modal.hide();
        try {
            const d = await apiPost('conversations.php', { user_id: userId });
            if (d.conversation_id) {
                await this.loadConvs();
                // Find user's id from the response to get callee_id
                await this.openConv(d.conversation_id, name, color, userId);
            }
        } catch (e) {
            console.error('startDirect:', e);
        }
    },

    /* ── Image lightbox ──────────────────────────────────────── */
    lightbox(src) {
        document.getElementById('lbImg').src = src;
        document.getElementById('imgLightbox').classList.add('on');
    },
    closeLightbox() {
        document.getElementById('imgLightbox').classList.remove('on');
        document.getElementById('lbImg').src = '';
    },

    /* ══════════════════════════════════════════════════════════════
       WebRTC CALL
    ══════════════════════════════════════════════════════════════ */
    async call(type) {
        if (!this.convId || !this.calleeId) return;
        this.pendingIce = [];
        const name  = this.convName;
        const color = this.convColor;

        this._showOverlay(name, color, type, false);
        document.getElementById('callStatusTxt').textContent = 'Calling…';

        try {
            this.localStream = await navigator.mediaDevices.getUserMedia({
                audio: true,
                video: type === 'video',
            });
            if (type === 'video') {
                const lv = document.getElementById('localVid');
                lv.srcObject = this.localStream;
                lv.style.display = 'block';
                document.getElementById('btnCam').classList.remove('d-none');
            }

            this.peerConn = new RTCPeerConnection(this.STUN);
            this.localStream.getTracks().forEach(t => this.peerConn.addTrack(t, this.localStream));

            this.peerConn.onicecandidate = ev => {
                if (ev.candidate) this.pendingIce.push(ev.candidate.toJSON());
            };
            this.peerConn.ontrack = ev => {
                const rv = document.getElementById('remoteVid');
                rv.srcObject = ev.streams[0];
                if (type === 'video') rv.style.display = 'block';
            };

            const offer = await this.peerConn.createOffer();
            await this.peerConn.setLocalDescription(offer);

            const d = await apiPost('call.php', {
                action:          'initiate',
                conversation_id: this.convId,
                callee_id:       this.calleeId,
                call_type:       type,
                offer_sdp:       JSON.stringify(offer),
            });
            if (!d.call_id) throw new Error('No call_id returned');
            this.activeCallId = d.call_id;

            this.callPoll = setInterval(() => this._pollCall(), 2000);
        } catch (err) {
            console.error('call:', err);
            this._hideOverlay();
            alert('Could not start call: ' + err.message);
        }
    },

    async _pollCall() {
        if (!this.activeCallId) return;
        try {
            const d = await apiPost('call.php', {
                action:  'status',
                call_id: this.activeCallId,
            });
            if (d.status === 'active' && d.answer_sdp &&
                this.peerConn?.signalingState !== 'stable') {
                clearInterval(this.callPoll);
                const answer = JSON.parse(d.answer_sdp);
                await this.peerConn.setRemoteDescription(new RTCSessionDescription(answer));

                // Add remote ICE candidates
                (d.callee_ice || []).forEach(c => {
                    try { this.peerConn.addIceCandidate(new RTCIceCandidate(c)); } catch {}
                });

                // Send our ICE candidates
                await apiPost('call.php', {
                    action:     'ice',
                    call_id:    this.activeCallId,
                    candidates: this.pendingIce,
                });

                document.getElementById('callStatusTxt').textContent = 'Connected';
                this._startTimer();
            } else if (d.status === 'rejected') {
                this._endCleanup();
                document.getElementById('callStatusTxt').textContent = 'Call rejected';
                setTimeout(() => this._hideOverlay(), 2500);
            } else if (d.status === 'missed') {
                this._endCleanup();
                document.getElementById('callStatusTxt').textContent = 'No answer';
                setTimeout(() => this._hideOverlay(), 2500);
            } else if (d.status === 'ended') {
                this._endCleanup();
                this._hideOverlay();
            }
        } catch (e) {
            console.error('_pollCall:', e);
        }
    },

    async acceptCall() {
        try {
            const d = await apiPost('call.php', {
                action:  'status',
                call_id: this.activeCallId,
            });
            if (!d.offer_sdp) return;

            this.localStream = await navigator.mediaDevices.getUserMedia({
                audio: true,
                video: d.call_type === 'video',
            });
            if (d.call_type === 'video') {
                const lv = document.getElementById('localVid');
                lv.srcObject = this.localStream;
                lv.style.display = 'block';
            }

            this.peerConn = new RTCPeerConnection(this.STUN);
            this.localStream.getTracks().forEach(t => this.peerConn.addTrack(t, this.localStream));
            this.peerConn.onicecandidate = ev => {
                if (ev.candidate) this.pendingIce.push(ev.candidate.toJSON());
            };
            this.peerConn.ontrack = ev => {
                const rv = document.getElementById('remoteVid');
                rv.srcObject = ev.streams[0];
                if (d.call_type === 'video') rv.style.display = 'block';
            };

            const offer = JSON.parse(d.offer_sdp);
            await this.peerConn.setRemoteDescription(new RTCSessionDescription(offer));
            const answer = await this.peerConn.createAnswer();
            await this.peerConn.setLocalDescription(answer);

            await apiPost('call.php', {
                action:     'answer',
                call_id:    this.activeCallId,
                answer_sdp: JSON.stringify(answer),
            });

            // Add caller's ICE after a short delay
            setTimeout(async () => {
                try {
                    const d2 = await apiPost('call.php', { action: 'status', call_id: this.activeCallId });
                    (d2.caller_ice || []).forEach(c => {
                        try { this.peerConn.addIceCandidate(new RTCIceCandidate(c)); } catch {}
                    });
                    await apiPost('call.php', {
                        action:     'ice',
                        call_id:    this.activeCallId,
                        candidates: this.pendingIce,
                    });
                } catch {}
            }, 1500);

            document.getElementById('btnAccept').classList.add('d-none');
            document.getElementById('callStatusTxt').textContent = 'Connected';
            this._startTimer();
        } catch (err) {
            console.error('acceptCall:', err);
        }
    },

    async hangup() {
        if (this.activeCallId) {
            try {
                await apiPost('call.php', { action: 'end', call_id: this.activeCallId });
            } catch {}
        }
        this._endCleanup();
        this._hideOverlay();
    },

    toggleMute() {
        this.isMuted = !this.isMuted;
        this.localStream?.getAudioTracks().forEach(t => t.enabled = !this.isMuted);
        const btn = document.getElementById('btnMute');
        btn.innerHTML = this.isMuted
            ? '<i class="fa fa-microphone-slash"></i>'
            : '<i class="fa fa-microphone"></i>';
        btn.classList.toggle('off', this.isMuted);
    },

    toggleCam() {
        this.isCamOff = !this.isCamOff;
        this.localStream?.getVideoTracks().forEach(t => t.enabled = !this.isCamOff);
        const btn = document.getElementById('btnCam');
        btn.innerHTML = this.isCamOff
            ? '<i class="fa fa-video-slash"></i>'
            : '<i class="fa fa-video"></i>';
        btn.classList.toggle('off', this.isCamOff);
    },

    _showOverlay(name, color, type, incoming) {
        const ov = document.getElementById('callOverlay');
        ov.classList.add('on');
        document.getElementById('callAvatar').textContent          = initials(name);
        document.getElementById('callAvatar').style.background     = color || '#128c7e';
        document.getElementById('callName').textContent            = name;
        document.getElementById('callTimer').textContent           = '';
        document.getElementById('btnAccept').classList.toggle('d-none', !incoming);
        document.getElementById('btnCam').classList.toggle('d-none', type !== 'video');
        // Reset mute
        this.isMuted = false;
        document.getElementById('btnMute').innerHTML = '<i class="fa fa-microphone"></i>';
        document.getElementById('btnMute').classList.remove('off');
    },

    _hideOverlay() {
        document.getElementById('callOverlay').classList.remove('on');
        document.getElementById('remoteVid').style.display = 'none';
        document.getElementById('remoteVid').srcObject     = null;
        document.getElementById('localVid').style.display  = 'none';
        document.getElementById('localVid').srcObject      = null;
        document.getElementById('btnAccept').classList.add('d-none');
        document.getElementById('btnCam').classList.add('d-none');
    },

    _endCleanup() {
        clearInterval(this.callPoll);
        clearInterval(this.callTimerInt);
        this.peerConn?.close();
        this.peerConn     = null;
        this.localStream?.getTracks().forEach(t => t.stop());
        this.localStream  = null;
        this.activeCallId = null;
        this.isMuted      = false;
        this.isCamOff     = false;
        this.isIncoming   = false;
        this.pendingIce   = [];
    },

    _startTimer() {
        let s = 0;
        const el = document.getElementById('callTimer');
        this.callTimerInt = setInterval(() => {
            s++;
            el.textContent = fmtDur(s);
        }, 1000);
    },

    /* ── Poll for incoming calls ─────────────────────────────── */
    async _checkIncoming() {
        if (this.activeCallId) return;
        try {
            const d = await apiPost('call.php', { action: 'incoming' });
            if (d.incoming && d.call && !this.activeCallId) {
                const c = d.call;
                this.activeCallId = parseInt(c.id);
                this.isIncoming   = true;
                this.pendingIce   = [];
                this._showOverlay(c.caller_name, avatarColor(c.caller_id), c.call_type, true);
                document.getElementById('callStatusTxt').textContent =
                    `Incoming ${c.call_type} call…`;
            }
        } catch {}
    },

    /* ── Init ────────────────────────────────────────────────── */
    init() {
        // Load conversation list
        this.loadConvs();

        // Poll for incoming calls every 5s
        setInterval(() => this._checkIncoming(), 5000);

        // File input handler
        document.getElementById('fileIn').addEventListener('change', async (ev) => {
            for (const f of Array.from(ev.target.files)) {
                await this.uploadFile(f);
            }
            ev.target.value = '';
        });

        // Conversation search filter
        document.getElementById('convSearch').addEventListener('input', function () {
            const q = this.value.toLowerCase().trim();
            document.querySelectorAll('.conv-item').forEach(el => {
                const name = el.querySelector('.conv-name')?.textContent.toLowerCase() || '';
                el.style.display = !q || name.includes(q) ? '' : 'none';
            });
        });

        // User search in new chat modal
        document.getElementById('userSearch').addEventListener('input', function () {
            const q = this.value.toLowerCase().trim();
            document.querySelectorAll('.user-pick-item').forEach(el => {
                const name = (el.dataset.name || '').toLowerCase();
                el.style.display = !q || name.includes(q) ? '' : 'none';
            });
        });

        // Track input content for mic/send toggle
        document.getElementById('msgInput').addEventListener('input', () => {
            const text = document.getElementById('msgInput').innerText.trim();
            this._setSendMode(text ? 'send' : 'mic');
        });

        // Mobile back button
        document.getElementById('chatBackBtn').addEventListener('click', () => {
            document.getElementById('chatPanelRight').classList.add('mobile-hidden');
            clearInterval(this.pollTimer);
        });

        // Lightbox ESC key
        document.addEventListener('keydown', ev => {
            if (ev.key === 'Escape') {
                this.closeLightbox();
                if (this.activeCallId) this.hangup();
            }
        });
    },
};

Chat.init();
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>

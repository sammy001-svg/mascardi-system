<?php
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
canAccess('chat') || die('Access denied.');

$pageTitle = 'Chat';
$me      = authUser();   // keys: id, name, username, role
$db      = getDB();
$palette = ['#075e54','#128c7e','#e76f51','#2a9d8f','#264653','#e9c46a'];

$roleLabels = [
    'admin'            => 'Admin',
    'workshop_manager' => 'Workshop Manager',
    'sales_person'     => 'Sales Person',
    'sales_officer'    => 'Sales Officer',
    'manager'          => 'Manager',
    'mechanic'         => 'Mechanic',
];

// All active users except self — for New Chat modal
$stmt = $db->prepare("SELECT id, name, role FROM users WHERE id != ? AND status='active' ORDER BY name ASC");
$stmt->execute([$me['id']]);
$allUsers = $stmt->fetchAll(PDO::FETCH_ASSOC);

// header.php has NO $extraCss hook — we output <style> after include instead
include __DIR__ . '/../../includes/header.php';
?>
<style>
/* ──────────────────────────────────────────────────────────────
   CHAT MODULE  –  styles injected after header (valid HTML5)
   header.php closes </head> at line 22 with no $extraCss hook.
────────────────────────────────────────────────────────────── */

/* Zero-out the page-body padding so chat fills the shell */
.page-body { padding: 0 !important; overflow: hidden !important; }

/* ── Root two-panel shell ────────────────────────────────── */
.chat-root {
    display: flex !important;          /* force flex always */
    flex-direction: row;
    height: calc(100vh - 60px);        /* topbar = 60px (style.css line 273) */
    background: #f0f2f5;
    overflow: hidden;
    font-family: 'Inter','Segoe UI',sans-serif;
}

/* ── Left panel ──────────────────────────────────────────── */
.cp-left {
    width: 360px; min-width: 360px;
    display: flex; flex-direction: column;
    background: #fff;
    border-right: 1px solid #e9edef;
}
.cp-header {
    display: flex; align-items: center; justify-content: space-between;
    padding: 14px 16px;
    background: #f0f2f5;
    border-bottom: 1px solid #e9edef;
    flex-shrink: 0;
}
.cp-header h5 { margin: 0; font-size: 18px; font-weight: 700; color: #111b21; }
.cp-search { padding: 8px 12px; flex-shrink: 0; }
.cp-si { position: relative; }
.cp-si input {
    width: 100%; padding: 8px 14px 8px 36px;
    background: #f0f2f5; border: none; border-radius: 8px;
    font-size: 13.5px; outline: none; color: #111b21;
}
.cp-si i {
    position: absolute; left: 11px; top: 50%; transform: translateY(-50%);
    color: #8696a0; font-size: 13px; pointer-events: none;
}
.conv-list { flex: 1; overflow-y: auto; }
.conv-list::-webkit-scrollbar { width: 4px; }
.conv-list::-webkit-scrollbar-thumb { background: #ccc; border-radius: 4px; }
.conv-empty { padding: 48px 20px; text-align: center; color: #8696a0; font-size: 13.5px; }
.conv-empty i { font-size: 38px; opacity: .25; display: block; margin-bottom: 10px; }

/* Conversation items */
.conv-item {
    display: flex; align-items: center; gap: 12px;
    padding: 10px 16px; cursor: pointer;
    border-bottom: 1px solid #f5f6f6;
    transition: background .12s;
}
.conv-item:hover  { background: #f5f6f6; }
.conv-item.active { background: #f0f2f5; }
.cv-av {
    width: 48px; height: 48px; border-radius: 50%; flex-shrink: 0;
    display: flex; align-items: center; justify-content: center;
    color: #fff; font-weight: 700; font-size: 18px;
}
.cv-body { flex: 1; min-width: 0; }
.cv-r1, .cv-r2 { display: flex; align-items: baseline; gap: 6px; }
.cv-name {
    flex: 1; font-size: 14.5px; font-weight: 600; color: #111b21;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.cv-time { font-size: 11px; color: #8696a0; flex-shrink: 0; }
.cv-prev {
    flex: 1; font-size: 12.5px; color: #667781;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.cv-unread {
    min-width: 20px; height: 20px; padding: 0 5px;
    background: #25d366; color: #fff; border-radius: 10px;
    font-size: 11px; font-weight: 700;
    display: flex; align-items: center; justify-content: center;
}

/* ── Right panel ─────────────────────────────────────────── */
.cp-right { flex: 1; display: flex; flex-direction: column; min-width: 0; }
.chat-welcome {
    flex: 1; display: flex; flex-direction: column;
    align-items: center; justify-content: center;
    gap: 14px; background: #f0f2f5; color: #8696a0;
}
.chat-welcome-ico {
    width: 80px; height: 80px; border-radius: 50%;
    background: #e9edef; display: flex; align-items: center; justify-content: center;
    font-size: 32px; color: #aebac1;
}
.chat-welcome h6 { font-size: 22px; font-weight: 300; color: #3b4a54; margin: 0; }
.chat-welcome p  { font-size: 13.5px; margin: 0; }

/* Active chat */
.chat-active { display: flex; flex-direction: column; height: 100%; }

/* Chat header */
.ch-hdr {
    display: flex; align-items: center; gap: 12px;
    padding: 10px 16px;
    background: #f0f2f5; border-bottom: 1px solid #e9edef;
    flex-shrink: 0;
}
.ch-av {
    width: 42px; height: 42px; border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    color: #fff; font-weight: 700; font-size: 16px; flex-shrink: 0;
}
.ch-info { flex: 1; min-width: 0; }
.ch-name { font-size: 15px; font-weight: 700; color: #111b21; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.ch-sub  { font-size: 12px; color: #667781; }

/* Messages */
.chat-msgs {
    flex: 1; overflow-y: auto; padding: 14px 16px;
    display: flex; flex-direction: column; gap: 1px;
    background: #efeae2;
}
.chat-msgs::-webkit-scrollbar { width: 5px; }
.chat-msgs::-webkit-scrollbar-thumb { background: #c9d0d7; border-radius: 5px; }
.day-sep { display: flex; justify-content: center; margin: 8px 0; }
.day-sep span {
    background: #fff; color: #54656f; font-size: 12px; font-weight: 500;
    padding: 4px 12px; border-radius: 8px; box-shadow: 0 1px 2px rgba(0,0,0,.1);
}
.msg-row { display: flex; margin: 2px 0; }
.msg-row.s { justify-content: flex-end; }
.msg-row.r { justify-content: flex-start; }
.bubble {
    max-width: 62%; padding: 6px 9px 22px;
    border-radius: 8px; position: relative;
    word-wrap: break-word; font-size: 14px; line-height: 1.45;
    box-shadow: 0 1px 2px rgba(0,0,0,.12);
}
.msg-row.s .bubble { background: #d9fdd3; border-top-right-radius: 2px; }
.msg-row.r .bubble { background: #fff;     border-top-left-radius: 2px; }
.msg-row.s .bubble::before {
    content:''; position:absolute; top:0; right:-8px;
    border:8px solid transparent; border-top-color:#d9fdd3;
    border-right:none; border-left:none;
}
.msg-row.r .bubble::before {
    content:''; position:absolute; top:0; left:-8px;
    border:8px solid transparent; border-top-color:#fff;
    border-left:none; border-right:none;
}
.b-meta {
    position: absolute; bottom: 4px; right: 8px;
    display: flex; align-items: center; gap: 3px;
    font-size: 11px; color: #8696a0;
}
.b-tick { color: #53bdeb; }
.b-text { white-space: pre-wrap; }
.b-img  { max-width: 260px; border-radius: 6px; cursor: zoom-in; display: block; margin-bottom: 4px; }
.b-file {
    display: flex; align-items: center; gap: 10px;
    background: rgba(0,0,0,.05); border-radius: 6px;
    padding: 9px 11px; min-width: 200px; margin-bottom: 4px;
    text-decoration: none; color: inherit;
}
.b-file-ico { font-size: 28px; flex-shrink: 0; }
.b-file-nm  { font-size: 13px; font-weight: 600; word-break: break-all; color: #111b21; }
.b-file-sz  { font-size: 11.5px; color: #667781; }
.b-file-dl  { color: #8696a0; font-size: 15px; margin-left: auto; flex-shrink: 0; }
.b-voice    { display: flex; align-items: center; gap: 10px; min-width: 230px; padding: 2px 0 4px; }
.b-play {
    width: 40px; height: 40px; border-radius: 50%;
    background: #128c7e; color: #fff; border: none;
    display: flex; align-items: center; justify-content: center;
    cursor: pointer; font-size: 15px; flex-shrink: 0;
}
.b-play:hover { background: #0f7268; }
.b-wf {
    flex: 1; height: 28px; border-radius: 4px; cursor: pointer;
    background: linear-gradient(to right,
        #128c7e 0%, #128c7e var(--p,0%),
        rgba(0,0,0,.15) var(--p,0%), rgba(0,0,0,.15) 100%);
}
.b-dur { font-size: 12px; color: #667781; min-width: 34px; text-align: right; }
.chip-row { display: flex; justify-content: center; margin: 4px 0; }
.msg-chip {
    background: rgba(0,0,0,.06); border-radius: 8px;
    padding: 5px 14px; font-size: 12.5px; color: #54656f;
}

/* ── Input bar ───────────────────────────────────────────── */
.chat-bar {
    display: flex; align-items: flex-end; gap: 8px;
    padding: 8px 12px 10px;
    background: #f0f2f5; border-top: 1px solid #e9edef;
    flex-shrink: 0;
}
.bar-ic {
    background: none; border: none; color: #54656f;
    width: 40px; height: 40px; border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-size: 22px; cursor: pointer; flex-shrink: 0;
    transition: background .15s;
}
.bar-ic:hover { background: #e9edef; }
.bar-center { flex: 1; display: flex; flex-direction: column; }
.bar-input {
    background: #fff; border-radius: 10px;
    padding: 9px 14px; font-size: 14.5px; line-height: 1.45;
    min-height: 42px; max-height: 130px; overflow-y: auto;
    outline: none; color: #111b21; word-break: break-word;
}
.bar-input:empty::before { content: attr(data-ph); color: #8696a0; pointer-events: none; }
.rec-bar {
    /* hidden by default — toggled via JS classList */
    align-items: center; gap: 10px;
    background: #fff; border-radius: 10px;
    padding: 9px 14px; min-height: 42px;
}
.rec-dot {
    width: 10px; height: 10px; border-radius: 50%;
    background: #dc2626; flex-shrink: 0;
    animation: blink 1s step-start infinite;
}
@keyframes blink { 50% { opacity: 0; } }
.rec-lbl  { font-size: 13px; color: #dc2626; font-weight: 500; }
.rec-time { font-size: 13px; color: #111b21; font-weight: 600; }
.rec-cancel {
    margin-left: auto; background: none; border: none;
    color: #8696a0; font-size: 20px; cursor: pointer; line-height: 1;
}
.rec-cancel:hover { color: #dc2626; }
.bar-send {
    width: 44px; height: 44px; border-radius: 50%; border: none;
    background: #128c7e; color: #fff; font-size: 18px;
    display: flex; align-items: center; justify-content: center;
    cursor: pointer; flex-shrink: 0; transition: background .15s;
}
.bar-send:hover  { background: #0f7268; }
.bar-send:active { transform: scale(.92); }
.bar-send.rec-on { background: #dc2626; }

/* ── Shared icon button ──────────────────────────────────── */
.ic-btn {
    width: 36px; height: 36px; border-radius: 50%;
    background: none; border: none; color: #54656f;
    display: flex; align-items: center; justify-content: center;
    font-size: 17px; cursor: pointer; flex-shrink: 0;
    transition: background .15s;
}
.ic-btn:hover { background: #e9edef; }
.ch-actions { display: flex; gap: 2px; }
.ch-actions .ic-btn { font-size: 19px; }

/* ── Call overlay ────────────────────────────────────────── */
.call-ov {
    position: fixed; inset: 0; z-index: 9990;
    background: #1f2c34;
    flex-direction: column; align-items: center; justify-content: center;
    color: #fff;
    /* display controlled by JS — starts hidden */
}
.call-rv {
    position: absolute; inset: 0; width: 100%; height: 100%;
    object-fit: cover; background: #000;
}
.call-lv {
    position: absolute; bottom: 130px; right: 20px;
    width: 130px; border-radius: 10px; border: 2px solid #fff;
    object-fit: cover; z-index: 2;
}
.call-body {
    position: relative; z-index: 3;
    display: flex; flex-direction: column; align-items: center;
}
.call-avatar {
    width: 90px; height: 90px; border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-size: 34px; font-weight: 700; color: #fff; margin-bottom: 16px;
}
.call-name   { font-size: 24px; font-weight: 600; margin-bottom: 6px; }
.call-stat   { font-size: 14px; color: rgba(255,255,255,.65); margin-bottom: 4px; }
.call-timer  { font-size: 20px; font-weight: 600; letter-spacing: 1px; min-height: 28px; margin-bottom: 32px; }
.call-btns   { display: flex; gap: 24px; }
.call-btn {
    width: 62px; height: 62px; border-radius: 50%; border: none;
    font-size: 22px; cursor: pointer; color: #fff;
    display: flex; align-items: center; justify-content: center;
    transition: opacity .15s;
}
.call-btn:hover { opacity: .85; }
.cb-mute   { background: rgba(255,255,255,.2); }
.cb-cam    { background: rgba(255,255,255,.2); }
.cb-end    { background: #e53935; }
.cb-accept { background: #43a047; }
.cb-mute.muted { background: #e0e0e0; color: #333; }

/* ── Lightbox ────────────────────────────────────────────── */
.lightbox {
    position: fixed; inset: 0; z-index: 9995;
    background: rgba(0,0,0,.92);
    align-items: center; justify-content: center;
    /* display controlled via JS */
}
.lightbox img { max-width: 92vw; max-height: 88vh; border-radius: 6px; object-fit: contain; }
.lb-close {
    position: absolute; top: 16px; right: 20px;
    color: #fff; font-size: 28px; cursor: pointer;
    background: none; border: none; line-height: 1;
}

/* ── New chat modal user picker ──────────────────────────── */
.up-list { max-height: 360px; overflow-y: auto; }
.up-item {
    display: flex; align-items: center; gap: 12px;
    padding: 10px 12px; cursor: pointer; border-radius: 8px;
    transition: background .12s;
}
.up-item:hover { background: #f0f2f5; }
.up-av {
    width: 42px; height: 42px; border-radius: 50%;
    color: #fff; font-weight: 700; font-size: 15px;
    display: flex; align-items: center; justify-content: center; flex-shrink: 0;
}
.up-name { font-size: 14px; font-weight: 600; color: #111b21; }
.up-role { font-size: 12px; color: #667781; }

/* ── Mobile ──────────────────────────────────────────────── */
@media (max-width: 767px) {
    .cp-left  { width: 100%; min-width: 100%; }
    .cp-right { position: absolute; inset: 0; z-index: 5; }
    .ch-back-mob { display: flex !important; }
}
</style>

<!-- ══════════════════════════════════════════════════════════
     HTML  —  Chat root
══════════════════════════════════════════════════════════════ -->
<div class="chat-root">

    <!-- ── LEFT: conversation list ─────────────────────── -->
    <div class="cp-left">
        <div class="cp-header">
            <h5>Messages</h5>
            <button class="ic-btn" id="btnNewChat" title="New chat">
                <i class="fa fa-pen-to-square"></i>
            </button>
        </div>
        <div class="cp-search">
            <div class="cp-si">
                <i class="fa fa-magnifying-glass"></i>
                <input type="text" id="convSearch" placeholder="Search conversations…" autocomplete="off">
            </div>
        </div>
        <div class="conv-list" id="convList">
            <div class="conv-empty"><i class="fa fa-spinner fa-spin"></i>Loading…</div>
        </div>
    </div>

    <!-- ── RIGHT: active chat ──────────────────────────── -->
    <!-- Starts hidden; shown when a conversation is opened -->
    <div class="cp-right" id="cpRight" style="display:none">

        <!-- Welcome placeholder -->
        <div class="chat-welcome" id="chatWelcome">
            <div class="chat-welcome-ico"><i class="fa fa-comments"></i></div>
            <h6>Mascardi Chat</h6>
            <p>Select a conversation or start a new one</p>
        </div>

        <!-- Active conversation — hidden until openConv() -->
        <div class="chat-active" id="chatActive" style="display:none">

            <div class="ch-hdr">
                <!-- Back arrow (mobile only) -->
                <button class="ic-btn ch-back-mob" id="chBack"
                        style="display:none" title="Back">
                    <i class="fa fa-arrow-left"></i>
                </button>
                <div class="ch-av" id="chAv" style="background:#128c7e">–</div>
                <div class="ch-info">
                    <div class="ch-name" id="chName">—</div>
                    <div class="ch-sub"  id="chSub"></div>
                </div>
                <div class="ch-actions">
                    <button class="ic-btn" id="btnCallA" title="Voice call" style="display:none">
                        <i class="fa fa-phone"></i>
                    </button>
                    <button class="ic-btn" id="btnCallV" title="Video call" style="display:none">
                        <i class="fa fa-video"></i>
                    </button>
                </div>
            </div>

            <div class="chat-msgs" id="chatMsgs"></div>

            <div class="chat-bar">
                <button class="bar-ic" id="btnAttach" title="Attach file">
                    <i class="fa fa-paperclip"></i>
                </button>
                <input type="file" id="fileIn" style="display:none" multiple
                       accept="image/*,.pdf,.doc,.docx,.xls,.xlsx,.zip,.rar,.txt,.csv">

                <div class="bar-center">
                    <div class="bar-input" id="msgIn"
                         contenteditable="true" data-ph="Type a message…"
                         role="textbox" aria-multiline="true"></div>
                    <!-- Recording bar — hidden by default -->
                    <div class="rec-bar" id="recBar" style="display:none">
                        <div class="rec-dot"></div>
                        <span class="rec-lbl">Recording</span>
                        <span class="rec-time" id="recTime">0:00</span>
                        <button class="rec-cancel" id="recCancel" title="Cancel">
                            <i class="fa fa-xmark"></i>
                        </button>
                    </div>
                </div>

                <button class="bar-send" id="sendBtn" title="Record voice note">
                    <i class="fa fa-microphone" id="sendIco"></i>
                </button>
            </div>

        </div><!-- /chatActive -->
    </div><!-- /cpRight -->

</div><!-- /chat-root -->

<!-- ══════════════════════════════════════════════════════════
     CALL OVERLAY  —  hidden until a call starts
══════════════════════════════════════════════════════════════ -->
<div class="call-ov" id="callOv" style="display:none">
    <video class="call-rv" id="remoteVid" autoplay playsinline style="display:none"></video>
    <video class="call-lv" id="localVid"  autoplay muted playsinline style="display:none"></video>
    <div class="call-body">
        <div class="call-avatar" id="callAv" style="background:#128c7e">–</div>
        <div class="call-name"   id="callName">—</div>
        <div class="call-stat"   id="callStat">Calling…</div>
        <div class="call-timer"  id="callTimer"></div>
        <div class="call-btns">
            <button class="call-btn cb-mute" id="btnMute"   title="Mute"><i class="fa fa-microphone"></i></button>
            <button class="call-btn cb-cam"  id="btnCam"    title="Camera" style="display:none"><i class="fa fa-video"></i></button>
            <button class="call-btn cb-end"  id="btnEnd"    title="End call"><i class="fa fa-phone-slash"></i></button>
            <button class="call-btn cb-accept" id="btnAccept" title="Accept" style="display:none"><i class="fa fa-phone"></i></button>
        </div>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════
     IMAGE LIGHTBOX  —  hidden until an image is clicked
══════════════════════════════════════════════════════════════ -->
<div class="lightbox" id="lightbox" style="display:none">
    <button class="lb-close" id="lbClose"><i class="fa fa-xmark"></i></button>
    <img id="lbImg" src="" alt="">
</div>

<!-- ══════════════════════════════════════════════════════════
     NEW CHAT MODAL
══════════════════════════════════════════════════════════════ -->
<div class="modal fade" id="newChatModal" tabindex="-1">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <div class="modal-header py-2">
                <h6 class="modal-title fw-semibold">
                    <i class="fa fa-pen-to-square me-2 text-muted"></i>New Chat
                </h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-2">
                <input type="text" id="userSearch"
                       class="form-control form-control-sm mb-2"
                       placeholder="Search people…" autocomplete="off">
                <div class="up-list" id="upList">
                <?php foreach ($allUsers as $u):
                    $init  = mb_strtoupper(mb_substr($u['name'], 0, 1));
                    $color = $palette[$u['id'] % count($palette)];
                    $rl    = $roleLabels[$u['role']] ?? ucfirst($u['role']);
                ?>
                    <div class="up-item"
                         data-uid="<?= (int)$u['id'] ?>"
                         data-uname="<?= e($u['name']) ?>"
                         data-ucolor="<?= e($color) ?>">
                        <div class="up-av" style="background:<?= $color ?>"><?= e($init) ?></div>
                        <div>
                            <div class="up-name"><?= e($u['name']) ?></div>
                            <div class="up-role"><?= e($rl) ?></div>
                        </div>
                    </div>
                <?php endforeach; ?>
                <?php if (empty($allUsers)): ?>
                    <p class="text-muted small text-center py-3 mb-0">No other users found.</p>
                <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
/* ════════════════════════════════════════════════════════════
   Helpers
════════════════════════════════════════════════════════════ */
const ME      = { id: <?= (int)$me['id'] ?>, name: <?= json_encode($me['name']) ?> };
const BASE    = <?= json_encode(rtrim(BASE_URL, '/')) ?>;
const API     = BASE + '/modules/chat/api/';
const PAL     = <?= json_encode($palette) ?>;

function esc(s) {
    const d = document.createElement('div');
    d.textContent = s == null ? '' : String(s);
    return d.innerHTML;
}
function avatarColor(id) { return PAL[parseInt(id) % PAL.length]; }
function initials(n)     { return (n || '?').charAt(0).toUpperCase(); }
function fmtDur(s)  { return Math.floor(s/60)+':'+String(s%60).padStart(2,'0'); }
function fmtSize(b) {
    return b>1048576 ? (b/1048576).toFixed(1)+' MB'
         : b>1024    ? (b/1024).toFixed(0)+' KB'
         : b+' B';
}
function fmtTime(iso) {
    if (!iso) return '';
    const d = new Date(String(iso).replace(' ','T'));
    return isNaN(d) ? '' : d.toLocaleTimeString([],{hour:'2-digit',minute:'2-digit',hour12:false});
}
function fmtDay(iso) {
    if (!iso) return '';
    const d    = new Date(String(iso).replace(' ','T'));
    if (isNaN(d)) return '';
    const diff = Math.floor((Date.now()-d)/86400000);
    if (diff===0) return 'Today';
    if (diff===1) return 'Yesterday';
    return d.toLocaleDateString([],{day:'numeric',month:'short',year:'numeric'});
}
function fileIcon(mime) {
    mime = mime||'';
    if (mime.includes('pdf'))                               return ['fa-file-pdf','text-danger'];
    if (mime.includes('word')||mime.includes('document'))   return ['fa-file-word','text-primary'];
    if (mime.includes('excel')||mime.includes('sheet'))     return ['fa-file-excel','text-success'];
    if (mime.includes('zip')||mime.includes('rar'))         return ['fa-file-zipper','text-warning'];
    if (mime.includes('image'))                             return ['fa-file-image','text-info'];
    if (mime.includes('audio'))                             return ['fa-file-audio','text-secondary'];
    return ['fa-file','text-muted'];
}
/* footer.php already patches window.fetch to add X-CSRF-Token to every POST */
async function apiGet(ep,p){
    const qs=p?'?'+new URLSearchParams(p):'';
    const r=await fetch(API+ep+qs);
    return r.json();
}
async function apiPost(ep,body){
    const r=await fetch(API+ep,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(body)});
    return r.json();
}
async function apiUpload(ep,fd){
    const r=await fetch(API+ep,{method:'POST',body:fd});
    return r.json();
}
function show(el){ el.style.display=''; }
function hide(el){ el.style.display='none'; }
function flex(el){ el.style.display='flex'; }
function $(id)   { return document.getElementById(id); }

/* ════════════════════════════════════════════════════════════
   Chat object
════════════════════════════════════════════════════════════ */
const Chat = {
    convId:0, convName:'', convColor:'#128c7e', calleeId:null,
    lastMsgId:0, lastDay:'',
    pollTimer:null,
    isRecording:false, mediaRec:null, audioChunks:[], recTimerInt:null, recSecs:0,
    curAudio:null,
    localStream:null, peerConn:null, callPoll:null, activeCallId:null,
    callTimerInt:null, isMuted:false, isCamOff:false, pendingIce:[],

    STUN:{iceServers:[{urls:'stun:stun.l.google.com:19302'},{urls:'stun:stun1.l.google.com:19302'}]},

    /* ── Conversation list ─────────────────────────────── */
    async loadConvs(){
        try{
            const d=await apiGet('conversations.php');
            const convs=d.conversations||[];
            const list=$('convList');
            if(!convs.length){
                list.innerHTML=`<div class="conv-empty"><i class="fa fa-message"></i>No conversations yet.<br><small>Tap the pencil icon to start one.</small></div>`;
                return;
            }
            list.innerHTML=convs.map(c=>this._convHtml(c)).join('');
            if(this.convId)list.querySelector(`[data-cid="${this.convId}"]`)?.classList.add('active');
        }catch(e){console.error('loadConvs',e);}
    },

    _convHtml(c){
        const color=avatarColor(c.other_user_id||c.id);
        const time =c.last_msg_at?fmtTime(c.last_msg_at):'';
        const prev =esc((c.last_preview||'').substring(0,55));
        const badge=c.unread_count>0?`<div class="cv-unread">${c.unread_count>99?'99+':c.unread_count}</div>`:'';
        const act  =this.convId==c.id?'active':'';
        return `<div class="conv-item ${act}"
                     data-cid="${c.id}"
                     data-cname="${esc(c.display_name)}"
                     data-ccolor="${color}"
                     data-callee="${c.other_user_id||0}">
            <div class="cv-av" style="background:${color}">${esc(initials(c.display_name))}</div>
            <div class="cv-body">
                <div class="cv-r1"><span class="cv-name">${esc(c.display_name)}</span><span class="cv-time">${time}</span></div>
                <div class="cv-r2"><span class="cv-prev">${prev}</span>${badge}</div>
            </div>
        </div>`;
    },

    /* ── Open conversation ─────────────────────────────── */
    async openConv(cid,cname,ccolor,callee){
        this.convId   =parseInt(cid)||0;
        this.convName =cname;
        this.convColor=ccolor||'#128c7e';
        this.calleeId =parseInt(callee)||null;
        this.lastMsgId=0; this.lastDay='';

        // Update header
        const av=$('chAv');
        av.textContent=initials(cname); av.style.background=this.convColor;
        $('chName').textContent=cname; $('chSub').textContent='';

        // Show/hide call buttons
        const showCall=!!(this.calleeId);
        $('btnCallA').style.display=showCall?'':'none';
        $('btnCallV').style.display=showCall?'':'none';

        // Show right panel
        const rp=$('cpRight');
        rp.style.display='flex'; rp.style.flexDirection='column';
        hide($('chatWelcome'));
        const ca=$('chatActive');
        ca.style.display='flex'; ca.style.flexDirection='column';

        // Sidebar active state
        document.querySelectorAll('.conv-item').forEach(e=>e.classList.remove('active'));
        document.querySelector(`[data-cid="${this.convId}"]`)?.classList.add('active');

        // Load messages
        $('chatMsgs').innerHTML=`<div class="text-center text-muted py-5" style="font-size:13px"><i class="fa fa-spinner fa-spin me-1"></i>Loading…</div>`;
        clearInterval(this.pollTimer);
        await this._fetchMsgs(true);
        this.pollTimer=setInterval(()=>this._fetchMsgs(false),2000);
        $('msgIn').focus();
    },

    /* ── Fetch messages ────────────────────────────────── */
    async _fetchMsgs(initial){
        try{
            const d=await apiGet('messages.php',{conversation_id:this.convId,after:this.lastMsgId});
            const msgs=d.messages||[];
            const box=$('chatMsgs');
            if(!msgs.length){
                if(initial)box.innerHTML=`<div class="text-center text-muted py-5" style="font-size:13px">No messages yet — say hello! 👋</div>`;
                return;
            }
            const atBtm=initial||(box.scrollHeight-box.scrollTop-box.clientHeight<120);
            if(initial)box.innerHTML='';
            msgs.forEach(m=>{
                const day=fmtDay(m.created_at);
                if(day&&day!==this.lastDay){
                    box.insertAdjacentHTML('beforeend',`<div class="day-sep"><span>${esc(day)}</span></div>`);
                    this.lastDay=day;
                }
                box.insertAdjacentHTML('beforeend',this._msgHtml(m));
                this.lastMsgId=Math.max(this.lastMsgId,parseInt(m.id));
            });
            if(atBtm)box.scrollTop=box.scrollHeight;
            if(!initial)this.loadConvs();
        }catch(e){console.error('_fetchMsgs',e);}
    },

    _msgHtml(m){
        const sent=parseInt(m.sender_id)===ME.id;
        const tick=sent?`<span class="b-tick"><i class="fa fa-check-double"></i></span>`:'';
        const time=fmtTime(m.created_at);

        if(m.type==='call'||m.type==='system'){
            const ico=m.type==='call'?(m.content?.includes('video')?'fa-video':'fa-phone'):'fa-circle-info';
            return `<div class="chip-row"><div class="msg-chip"><i class="fa ${ico} me-1"></i>${esc(m.content)}</div></div>`;
        }

        let body='';
        if(m.type==='image'&&m.file_url){
            body=`<img class="b-img" src="${esc(m.file_url)}" alt="${esc(m.file_name||'')}" loading="lazy" data-src="${esc(m.file_url)}">`;
        }else if(m.type==='voice'&&m.file_url){
            const dur=m.duration?fmtDur(parseInt(m.duration)):'0:00';
            const vid='v'+m.id;
            body=`<div class="b-voice">
                <button class="b-play" data-vid="${vid}" data-src="${esc(m.file_url)}"><i class="fa fa-play"></i></button>
                <div class="b-wf" id="wf${vid}" style="--p:0%"></div>
                <span class="b-dur" id="dr${vid}">${esc(dur)}</span>
            </div>`;
        }else if(m.type==='file'&&m.file_url){
            const[ico,col]=fileIcon(m.mime_type);
            const sz=m.file_size?fmtSize(parseInt(m.file_size)):'';
            body=`<a class="b-file" href="${esc(m.file_url)}" download="${esc(m.file_name||'file')}" target="_blank">
                <i class="fa ${ico} ${col} b-file-ico"></i>
                <div style="flex:1;min-width:0">
                    <div class="b-file-nm">${esc(m.file_name||'File')}</div>
                    ${sz?`<div class="b-file-sz">${sz}</div>`:''}
                </div>
                <i class="fa fa-download b-file-dl"></i>
            </a>`;
        }else{
            body=`<span class="b-text">${esc(m.content||'')}</span>`;
        }
        return `<div class="msg-row ${sent?'s':'r'}"><div class="bubble">${body}<div class="b-meta">${time} ${tick}</div></div></div>`;
    },

    /* ── Send text ─────────────────────────────────────── */
    async sendText(){
        if(!this.convId)return;
        const el=$('msgIn');
        const text=(el.innerText||'').trim();
        if(!text)return;
        el.innerText=''; this._syncBtn();
        try{await apiPost('send.php',{conversation_id:this.convId,content:text});await this._fetchMsgs(false);}
        catch(e){console.error('sendText',e);}
    },

    /* ── Start direct conv ─────────────────────────────── */
    async startDirect(uid,uname,ucolor){
        bootstrap.Modal.getInstance($('newChatModal'))?.hide();
        try{
            const d=await apiPost('conversations.php',{user_id:parseInt(uid)});
            if(d.conversation_id){await this.loadConvs();await this.openConv(d.conversation_id,uname,ucolor,uid);}
        }catch(e){console.error('startDirect',e);}
    },

    /* ── File upload ───────────────────────────────────── */
    async uploadFile(file){
        if(!this.convId)return;
        const fd=new FormData();
        fd.append('conversation_id',this.convId);
        fd.append('file',file,file.name);
        try{await apiUpload('upload.php',fd);await this._fetchMsgs(false);}
        catch(e){console.error('uploadFile',e);}
    },

    /* ── Voice recording ───────────────────────────────── */
    _bestMime(){
        for(const t of['audio/webm;codecs=opus','audio/webm','audio/ogg;codecs=opus','audio/mp4'])
            if(MediaRecorder.isTypeSupported(t))return t;
        return '';
    },
    async startRec(){
        if(!this.convId||this.isRecording)return;
        try{
            const stream=await navigator.mediaDevices.getUserMedia({audio:true});
            this.audioChunks=[];
            this.mediaRec=new MediaRecorder(stream,{mimeType:this._bestMime()});
            this.mediaRec.ondataavailable=ev=>this.audioChunks.push(ev.data);
            this.mediaRec.start(100);
            this.isRecording=true;
            hide($('msgIn')); $('recBar').style.display='flex';
            $('sendBtn').classList.add('rec-on');
            $('sendIco').className='fa fa-stop';
            this.recSecs=0; $('recTime').textContent='0:00';
            this.recTimerInt=setInterval(()=>{this.recSecs++;$('recTime').textContent=fmtDur(this.recSecs);},1000);
        }catch(e){alert('Microphone access denied.');}
    },
    async stopRec(){
        if(!this.isRecording||!this.mediaRec)return;
        const dur=this.recSecs;
        clearInterval(this.recTimerInt); this.isRecording=false;
        show($('msgIn')); hide($('recBar'));
        $('sendBtn').classList.remove('rec-on'); this._syncBtn();
        return new Promise(resolve=>{
            this.mediaRec.onstop=async()=>{
                const mime=this.mediaRec.mimeType||'audio/webm';
                const blob=new Blob(this.audioChunks,{type:mime});
                this.mediaRec.stream.getTracks().forEach(t=>t.stop());
                this.mediaRec=null;
                if(dur<1||!this.convId){resolve();return;}
                try{
                    const fd=new FormData();
                    fd.append('conversation_id',this.convId);
                    fd.append('file',blob,'voice.'+(mime.includes('ogg')?'ogg':'webm'));
                    fd.append('voice','1'); fd.append('duration',dur);
                    await apiUpload('upload.php',fd);
                    await this._fetchMsgs(false);
                }catch(e){console.error('stopRec',e);}
                resolve();
            };
            this.mediaRec.stop();
        });
    },
    cancelRec(){
        if(!this.isRecording||!this.mediaRec)return;
        clearInterval(this.recTimerInt); this.isRecording=false;
        this.mediaRec.stream.getTracks().forEach(t=>t.stop()); this.mediaRec=null;
        show($('msgIn')); hide($('recBar'));
        $('sendBtn').classList.remove('rec-on'); this._syncBtn();
    },

    _syncBtn(){
        const text=($('msgIn').innerText||'').trim();
        if(this.isRecording){ $('sendIco').className='fa fa-stop'; $('sendBtn').title='Stop recording'; }
        else if(text)        { $('sendIco').className='fa fa-paper-plane'; $('sendBtn').title='Send'; }
        else                 { $('sendIco').className='fa fa-microphone';  $('sendBtn').title='Record voice note'; }
    },
    onSend(){
        if(this.isRecording){this.stopRec();return;}
        if(($('msgIn').innerText||'').trim())this.sendText(); else this.startRec();
    },

    /* ── Voice playback ────────────────────────────────── */
    playVoice(src,vid){
        if(this.curAudio){this.curAudio.pause();this.curAudio=null;
            document.querySelectorAll('.b-play i').forEach(i=>i.className='fa fa-play');
            document.querySelectorAll('.b-wf').forEach(w=>w.style.setProperty('--p','0%'));
        }
        const btn=document.querySelector(`[data-vid="${vid}"]`);
        const wf=$('wf'+vid), dr=$('dr'+vid);
        const audio=new Audio(src);
        this.curAudio=audio;
        if(btn)btn.innerHTML='<i class="fa fa-pause"></i>';
        audio.addEventListener('timeupdate',()=>{
            const pct=audio.duration?(audio.currentTime/audio.duration*100):0;
            if(wf)wf.style.setProperty('--p',pct.toFixed(1)+'%');
            if(dr)dr.textContent=fmtDur(Math.floor(audio.currentTime));
        });
        audio.addEventListener('ended',()=>{
            if(btn)btn.innerHTML='<i class="fa fa-play"></i>';
            if(wf)wf.style.setProperty('--p','0%');
            if(btn)btn.onclick=null; this.curAudio=null;
        });
        audio.play().catch(()=>{});
        if(btn)btn.onclick=()=>{audio.pause();btn.innerHTML='<i class="fa fa-play"></i>';btn.onclick=null;this.curAudio=null;};
    },

    /* ── WebRTC ────────────────────────────────────────── */
    async call(type){
        if(!this.convId||!this.calleeId)return;
        this.pendingIce=[];
        this._showCall(this.convName,this.convColor,type,false);
        $('callStat').textContent='Calling…';
        try{
            this.localStream=await navigator.mediaDevices.getUserMedia({audio:true,video:type==='video'});
            if(type==='video'){const lv=$('localVid');lv.srcObject=this.localStream;show(lv);show($('btnCam'));}
            this.peerConn=new RTCPeerConnection(this.STUN);
            this.localStream.getTracks().forEach(t=>this.peerConn.addTrack(t,this.localStream));
            this.peerConn.onicecandidate=ev=>{if(ev.candidate)this.pendingIce.push(ev.candidate.toJSON());};
            this.peerConn.ontrack=ev=>{const rv=$('remoteVid');rv.srcObject=ev.streams[0];if(type==='video')show(rv);};
            const offer=await this.peerConn.createOffer();
            await this.peerConn.setLocalDescription(offer);
            const d=await apiPost('call.php',{action:'initiate',conversation_id:this.convId,callee_id:this.calleeId,call_type:type,offer_sdp:JSON.stringify(offer)});
            if(!d.call_id)throw new Error('No call_id');
            this.activeCallId=d.call_id;
            this.callPoll=setInterval(()=>this._pollCall(),2000);
        }catch(err){this._hideCall();alert('Could not start call: '+err.message);}
    },
    async _pollCall(){
        if(!this.activeCallId)return;
        try{
            const d=await apiPost('call.php',{action:'status',call_id:this.activeCallId});
            if(d.status==='active'&&d.answer_sdp&&this.peerConn?.signalingState!=='stable'){
                clearInterval(this.callPoll);
                await this.peerConn.setRemoteDescription(new RTCSessionDescription(JSON.parse(d.answer_sdp)));
                (d.callee_ice||[]).forEach(c=>{try{this.peerConn.addIceCandidate(new RTCIceCandidate(c));}catch{}});
                await apiPost('call.php',{action:'ice',call_id:this.activeCallId,candidates:this.pendingIce});
                $('callStat').textContent='Connected'; this._startCallTimer();
            }else if(d.status==='rejected'){this._endCall();$('callStat').textContent='Call rejected';setTimeout(()=>this._hideCall(),2500);}
            else if(d.status==='missed'){this._endCall();$('callStat').textContent='No answer';setTimeout(()=>this._hideCall(),2500);}
            else if(d.status==='ended'){this._endCall();this._hideCall();}
        }catch{}
    },
    async acceptCall(){
        try{
            const d=await apiPost('call.php',{action:'status',call_id:this.activeCallId});
            if(!d.offer_sdp)return;
            this.localStream=await navigator.mediaDevices.getUserMedia({audio:true,video:d.call_type==='video'});
            if(d.call_type==='video'){const lv=$('localVid');lv.srcObject=this.localStream;show(lv);}
            this.peerConn=new RTCPeerConnection(this.STUN);
            this.localStream.getTracks().forEach(t=>this.peerConn.addTrack(t,this.localStream));
            this.peerConn.onicecandidate=ev=>{if(ev.candidate)this.pendingIce.push(ev.candidate.toJSON());};
            this.peerConn.ontrack=ev=>{const rv=$('remoteVid');rv.srcObject=ev.streams[0];if(d.call_type==='video')show(rv);};
            await this.peerConn.setRemoteDescription(new RTCSessionDescription(JSON.parse(d.offer_sdp)));
            const ans=await this.peerConn.createAnswer();
            await this.peerConn.setLocalDescription(ans);
            await apiPost('call.php',{action:'answer',call_id:this.activeCallId,answer_sdp:JSON.stringify(ans)});
            hide($('btnAccept')); $('callStat').textContent='Connected'; this._startCallTimer();
            setTimeout(async()=>{
                try{
                    const d2=await apiPost('call.php',{action:'status',call_id:this.activeCallId});
                    (d2.caller_ice||[]).forEach(c=>{try{this.peerConn.addIceCandidate(new RTCIceCandidate(c));}catch{}});
                    await apiPost('call.php',{action:'ice',call_id:this.activeCallId,candidates:this.pendingIce});
                }catch{}
            },1500);
        }catch(e){console.error('acceptCall',e);}
    },
    async hangup(){
        if(this.activeCallId)try{await apiPost('call.php',{action:'end',call_id:this.activeCallId});}catch{}
        this._endCall(); this._hideCall();
    },
    toggleMute(){
        this.isMuted=!this.isMuted;
        this.localStream?.getAudioTracks().forEach(t=>t.enabled=!this.isMuted);
        $('btnMute').innerHTML=this.isMuted?'<i class="fa fa-microphone-slash"></i>':'<i class="fa fa-microphone"></i>';
        $('btnMute').classList.toggle('muted',this.isMuted);
    },
    toggleCam(){
        this.isCamOff=!this.isCamOff;
        this.localStream?.getVideoTracks().forEach(t=>t.enabled=!this.isCamOff);
        $('btnCam').innerHTML=this.isCamOff?'<i class="fa fa-video-slash"></i>':'<i class="fa fa-video"></i>';
    },
    _showCall(name,color,type,incoming){
        const ov=$('callOv'); ov.style.display='flex'; ov.style.flexDirection='column'; ov.style.alignItems='center'; ov.style.justifyContent='center';
        $('callAv').textContent=initials(name); $('callAv').style.background=color||'#128c7e';
        $('callName').textContent=name; $('callTimer').textContent='';
        incoming?show($('btnAccept')):hide($('btnAccept'));
        type==='video'?show($('btnCam')):hide($('btnCam'));
        this.isMuted=false; $('btnMute').innerHTML='<i class="fa fa-microphone"></i>'; $('btnMute').classList.remove('muted');
    },
    _hideCall(){
        hide($('callOv'));
        ['remoteVid','localVid'].forEach(id=>{const v=$(id);v.srcObject=null;hide(v);});
        hide($('btnAccept')); hide($('btnCam'));
    },
    _endCall(){
        clearInterval(this.callPoll); clearInterval(this.callTimerInt);
        this.peerConn?.close(); this.peerConn=null;
        this.localStream?.getTracks().forEach(t=>t.stop()); this.localStream=null;
        this.activeCallId=null; this.isMuted=false; this.isCamOff=false; this.pendingIce=[];
    },
    _startCallTimer(){
        let s=0; const el=$('callTimer');
        this.callTimerInt=setInterval(()=>{el.textContent=fmtDur(++s);},1000);
    },
    async _checkIncoming(){
        if(this.activeCallId)return;
        try{
            const d=await apiPost('call.php',{action:'incoming'});
            if(d.incoming&&d.call&&!this.activeCallId){
                const c=d.call; this.activeCallId=parseInt(c.id); this.pendingIce=[];
                this._showCall(c.caller_name,avatarColor(c.caller_id),c.call_type,true);
                $('callStat').textContent=`Incoming ${c.call_type} call…`;
            }
        }catch{}
    },

    /* ── Init ──────────────────────────────────────────── */
    init(){
        this.loadConvs();
        setInterval(()=>this._checkIncoming(),5000);

        // Conversation clicks (delegated)
        $('convList').addEventListener('click',e=>{
            const item=e.target.closest('.conv-item');
            if(item)this.openConv(item.dataset.cid,item.dataset.cname,item.dataset.ccolor,item.dataset.callee);
        });

        // New chat button
        $('btnNewChat').addEventListener('click',()=>new bootstrap.Modal($('newChatModal')).show());

        // User picker (delegated)
        $('upList').addEventListener('click',e=>{
            const item=e.target.closest('.up-item');
            if(item)this.startDirect(item.dataset.uid,item.dataset.uname,item.dataset.ucolor);
        });

        // File attach
        $('btnAttach').addEventListener('click',()=>$('fileIn').click());
        $('fileIn').addEventListener('change',async ev=>{
            for(const f of Array.from(ev.target.files))await this.uploadFile(f);
            ev.target.value='';
        });

        // Send button
        $('sendBtn').addEventListener('click',()=>this.onSend());

        // Text input
        $('msgIn').addEventListener('keydown',ev=>{
            if(ev.key==='Enter'&&!ev.shiftKey){ev.preventDefault();this.sendText();}
        });
        $('msgIn').addEventListener('input',()=>this._syncBtn());

        // Recording cancel
        $('recCancel').addEventListener('click',()=>this.cancelRec());

        // Call buttons
        $('btnCallA').addEventListener('click',()=>this.call('audio'));
        $('btnCallV').addEventListener('click',()=>this.call('video'));
        $('btnEnd').addEventListener('click',()=>this.hangup());
        $('btnMute').addEventListener('click',()=>this.toggleMute());
        $('btnCam').addEventListener('click',()=>this.toggleCam());
        $('btnAccept').addEventListener('click',()=>this.acceptCall());

        // Mobile back
        $('chBack').addEventListener('click',()=>{
            hide($('cpRight')); clearInterval(this.pollTimer);
        });

        // Image + voice (delegated on messages area)
        $('chatMsgs').addEventListener('click',e=>{
            const img=e.target.closest('.b-img');
            if(img){
                $('lbImg').src=img.dataset.src||img.src;
                $('lightbox').style.display='flex';
                return;
            }
            const pb=e.target.closest('.b-play');
            if(pb&&pb.dataset.src&&!pb.onclick)this.playVoice(pb.dataset.src,pb.dataset.vid);
        });

        // Lightbox close
        $('lbClose').addEventListener('click',()=>{hide($('lightbox'));$('lbImg').src='';});
        $('lightbox').addEventListener('click',e=>{
            if(e.target===e.currentTarget){hide($('lightbox'));$('lbImg').src='';}
        });

        // Conversation search
        $('convSearch').addEventListener('input',function(){
            const q=this.value.toLowerCase();
            document.querySelectorAll('.conv-item').forEach(el=>{
                el.style.display=!q||(el.dataset.cname||'').toLowerCase().includes(q)?'':'none';
            });
        });

        // User search
        $('userSearch').addEventListener('input',function(){
            const q=this.value.toLowerCase();
            document.querySelectorAll('.up-item').forEach(el=>{
                el.style.display=!q||(el.dataset.uname||'').toLowerCase().includes(q)?'':'none';
            });
        });

        // ESC
        document.addEventListener('keydown',ev=>{
            if(ev.key!=='Escape')return;
            hide($('lightbox'));
            if(this.activeCallId)this.hangup();
        });
    },
};

Chat.init();
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>

<?php
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
canAccess('chat') || die('Access denied.');

$pageTitle = 'Chat';
$me      = authUser();
$db      = getDB();
$palette = ['#075e54','#128c7e','#e76f51','#2a9d8f','#264653','#e9c46a'];
$roleLabels = [
    'admin'=>'Admin','workshop_manager'=>'Workshop Manager',
    'sales_person'=>'Sales Person','sales_officer'=>'Sales Officer',
    'manager'=>'Manager','mechanic'=>'Mechanic',
];

$stmt = $db->prepare("SELECT id, name, role FROM users WHERE id != ? AND status='active' ORDER BY name ASC");
$stmt->execute([$me['id']]);
$allUsers = $stmt->fetchAll(PDO::FETCH_ASSOC);

include __DIR__ . '/../../includes/header.php';
?>
<style>
/* â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
   CHAT MODULE — injected after header (header.php has no extraCss hook)
â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â• */
.page-body { padding: 0 !important; overflow: hidden !important; }

/* Chat page: strip ALL stacking-context-creating properties from page-body
   so Bootstrap's backdrop (appended to <body>) cannot paint over the modal.
   transform, filter, will-change:transform, opacity<1, isolation — any of
   these on an ancestor would trap the modal inside a nested context.        */
.page-body {
    padding: 0 !important;
    overflow: hidden !important;
    animation: none !important;
    transform: none !important;
    filter: none !important;
    will-change: auto !important;
    isolation: auto !important;
    opacity: 1 !important;
}

/* Force the modal and its backdrop to the top of the global stacking order.
   This works even if the modal element was not yet moved out of page-body.  */
#newChatModal {
    z-index: 1055 !important;
}
body > .modal-backdrop,
.modal-backdrop {
    z-index: 1049 !important;
}


/* â”€â”€ Root shell â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ */
.chat-root {
    display: flex !important;
    height: calc(100vh - 60px);   /* topbar is exactly 60px */
    background: #f0f2f5;
    overflow: hidden;
    font-family: 'Inter','Segoe UI',sans-serif;
    font-size: 14px;
}

/* â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
   LEFT PANEL â€“ conversation list
â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â• */
.cp-left {
    width: 360px; min-width: 360px;
    display: flex; flex-direction: column;
    background: #fff;
    border-right: 1px solid #e9edef;
}
.cp-hdr {
    display: flex; align-items: center; justify-content: space-between;
    padding: 13px 16px;
    background: #f0f2f5;
    border-bottom: 1px solid #e9edef;
    flex-shrink: 0;
}
.cp-hdr h5 { margin: 0; font-size: 18px; font-weight: 700; color: #111b21; }
.cp-search { padding: 8px 12px; flex-shrink: 0; }
.cp-si { position: relative; }
.cp-si input {
    width: 100%; padding: 8px 14px 8px 36px;
    background: #f0f2f5; border: none; border-radius: 8px;
    font-size: 13.5px; outline: none; color: #111b21;
    transition: background .15s;
}
.cp-si input:focus { background: #e9edef; }
.cp-si i {
    position: absolute; left: 11px; top: 50%; transform: translateY(-50%);
    color: #8696a0; font-size: 13px; pointer-events: none;
}
.conv-list { flex: 1; overflow-y: auto; }
.conv-list::-webkit-scrollbar { width: 4px; }
.conv-list::-webkit-scrollbar-thumb { background: #ddd; border-radius: 4px; }

.conv-item {
    display: flex; align-items: center; gap: 12px;
    padding: 10px 16px; cursor: pointer;
    border-bottom: 1px solid #f5f6f6;
    transition: background .12s;
    user-select: none;
}
.conv-item:hover  { background: #f5f6f6; }
.conv-item.active { background: #f0f2f5; }
.cv-av {
    width: 48px; height: 48px; border-radius: 50%; flex-shrink: 0;
    display: flex; align-items: center; justify-content: center;
    color: #fff; font-weight: 700; font-size: 19px;
    text-transform: uppercase;
}
.cv-body { flex: 1; min-width: 0; }
.cv-r1, .cv-r2 { display: flex; align-items: baseline; gap: 4px; margin-bottom: 2px; }
.cv-name {
    flex: 1; font-size: 14.5px; font-weight: 600; color: #111b21;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.cv-time { font-size: 11px; color: #8696a0; flex-shrink: 0; white-space: nowrap; }
.cv-prev {
    flex: 1; font-size: 12.5px; color: #667781;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.cv-unread {
    min-width: 20px; height: 20px; padding: 0 6px;
    background: #25d366; color: #fff; border-radius: 10px;
    font-size: 11px; font-weight: 700;
    display: inline-flex; align-items: center; justify-content: center;
    flex-shrink: 0;
}
.conv-empty { padding: 48px 20px; text-align: center; color: #8696a0; font-size: 13px; line-height: 1.7; }
.conv-empty .ce-icon {
    width: 72px; height: 72px; border-radius: 50%;
    background: #f0f2f5; display: flex; align-items: center; justify-content: center;
    margin: 0 auto 16px; font-size: 28px; color: #aebac1;
}

/* â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
   RIGHT PANEL â€“ active chat
â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â• */
.cp-right { flex: 1; display: flex; flex-direction: column; min-width: 0; }

/* Welcome */
.chat-welcome {
    flex: 1; display: flex; flex-direction: column;
    align-items: center; justify-content: center;
    gap: 12px; background: #f0f2f5; color: #8696a0;
    text-align: center;
}
.cw-icon {
    width: 88px; height: 88px; border-radius: 50%;
    background: #e9edef;
    display: flex; align-items: center; justify-content: center;
    font-size: 36px; color: #aebac1; margin-bottom: 4px;
}
.chat-welcome h6 { font-size: 22px; font-weight: 300; color: #3b4a54; margin: 0; }
.chat-welcome p  { font-size: 13px; margin: 0; max-width: 260px; line-height: 1.6; }

/* Active pane */
.chat-active { display: flex; flex-direction: column; height: 100%; position: relative; }

/* Header */
.ch-hdr {
    display: flex; align-items: center; gap: 12px;
    padding: 10px 16px;
    background: #f0f2f5; border-bottom: 1px solid #e9edef;
    flex-shrink: 0; z-index: 2;
}
.ch-av {
    width: 42px; height: 42px; border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    color: #fff; font-weight: 700; font-size: 17px; flex-shrink: 0;
    cursor: pointer;
}
.ch-info { flex: 1; min-width: 0; }
.ch-name { font-size: 15px; font-weight: 700; color: #111b21; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.ch-sub  { font-size: 12px; color: #667781; }
.ch-acts { display: flex; gap: 2px; }

/* â”€â”€ Messages area â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ */
.chat-msgs {
    flex: 1; overflow-y: auto; padding: 12px 16px 8px;
    display: flex; flex-direction: column;
    background: #efeae2;
    /* subtle dot pattern */
    background-image: radial-gradient(circle, rgba(0,0,0,.04) 1px, transparent 1px);
    background-size: 20px 20px;
    scroll-behavior: smooth;
}
.chat-msgs::-webkit-scrollbar { width: 5px; }
.chat-msgs::-webkit-scrollbar-thumb { background: #c9d0d7; border-radius: 5px; }

/* Day separator */
.day-sep { display: flex; justify-content: center; margin: 10px 0 6px; }
.day-sep span {
    background: rgba(225,245,254,.96); color: #54656f;
    font-size: 11.5px; font-weight: 500; padding: 4px 12px;
    border-radius: 7px; box-shadow: 0 1px 2px rgba(0,0,0,.08);
}

/* Message rows */
.msg-row { display: flex; margin-bottom: 2px; align-items: flex-end; gap: 6px; }
.msg-row.s { justify-content: flex-end; }
.msg-row.r { justify-content: flex-start; }
/* Extra breathing room at the START of a group (first message from this sender) */
.msg-row.group-start { margin-top: 8px; }

/* Mini avatar for received messages in groups */
.msg-av {
    width: 28px; height: 28px; border-radius: 50%; flex-shrink: 0;
    display: flex; align-items: center; justify-content: center;
    color: #fff; font-weight: 700; font-size: 11px;
    align-self: flex-end; margin-bottom: 2px;
}
.msg-av-ph { width: 28px; flex-shrink: 0; } /* placeholder for grouped rows */

/* Bubble */
.bubble {
    max-width: 60%; min-width: 80px;
    padding: 6px 10px 20px;
    border-radius: 10px; position: relative;
    word-wrap: break-word; word-break: break-word;
    line-height: 1.5;
    box-shadow: 0 1px 2px rgba(0,0,0,.13);
    transition: box-shadow .15s;
}
.bubble:hover { box-shadow: 0 2px 6px rgba(0,0,0,.16); }

/* Sent bubble */
.msg-row.s .bubble {
    background: #d9fdd3;
    border-radius: 10px 2px 10px 10px;
}
/* Received bubble */
.msg-row.r .bubble {
    background: #fff;
    border-radius: 2px 10px 10px 10px;
}
/* Grouped bubbles (not first in group) — no sharp corner change needed */
.msg-row.s.group-mid .bubble,
.msg-row.s.group-end .bubble { border-radius: 10px 10px 2px 10px; }
.msg-row.r.group-mid .bubble,
.msg-row.r.group-end .bubble { border-radius: 10px 10px 10px 2px; }

/* Sender name inside bubble (group chats / received) */
.b-sender { font-size: 12px; font-weight: 700; margin-bottom: 3px; }

/* Meta row (time + tick) */
.b-meta {
    position: absolute; bottom: 4px; right: 8px;
    display: flex; align-items: center; gap: 3px;
    font-size: 11px; color: #8696a0; white-space: nowrap;
}
.b-tick { color: #53bdeb; font-size: 11px; }
/* Hide meta on non-last grouped messages — show only on last */
.msg-row:not(.group-last):not(.group-solo) .b-meta { display: none; }
.msg-row:not(.group-last):not(.group-solo) .bubble  { padding-bottom: 8px; }

/* Text */
.b-text { white-space: pre-wrap; color: #111b21; }

/* Image */
.b-img {
    max-width: 260px; width: 100%; border-radius: 8px;
    cursor: zoom-in; display: block; margin-bottom: 4px;
    object-fit: cover;
}

/* File */
.b-file {
    display: flex; align-items: center; gap: 10px;
    background: rgba(0,0,0,.05); border-radius: 8px;
    padding: 9px 11px; min-width: 200px; margin-bottom: 4px;
    text-decoration: none; color: inherit;
    transition: background .12s;
}
.b-file:hover { background: rgba(0,0,0,.08); }
.b-file-ico { font-size: 28px; flex-shrink: 0; }
.b-file-nm  { font-size: 13px; font-weight: 600; color: #111b21; word-break: break-all; }
.b-file-sz  { font-size: 11px; color: #667781; margin-top: 1px; }
.b-file-dl  { color: #8696a0; font-size: 15px; margin-left: auto; flex-shrink: 0; }

/* Voice note */
.b-voice { display: flex; align-items: center; gap: 10px; min-width: 220px; padding: 2px 0 4px; }
.b-play {
    width: 38px; height: 38px; border-radius: 50%;
    background: #128c7e; color: #fff; border: none;
    display: flex; align-items: center; justify-content: center;
    cursor: pointer; font-size: 14px; flex-shrink: 0;
    transition: background .15s;
}
.b-play:hover { background: #0f7268; }
.b-wf {
    flex: 1; height: 26px; border-radius: 4px; cursor: pointer;
    background: linear-gradient(to right,
        #128c7e 0%, #128c7e var(--p,0%),
        rgba(0,0,0,.14) var(--p,0%), rgba(0,0,0,.14) 100%);
}
.b-dur { font-size: 11.5px; color: #667781; min-width: 34px; text-align: right; }

/* Call / system */
.chip-row { display: flex; justify-content: center; margin: 6px 0; }
.msg-chip {
    background: rgba(225,245,254,.9);
    border-radius: 7px;
    padding: 5px 14px; font-size: 12px; color: #54656f;
    box-shadow: 0 1px 2px rgba(0,0,0,.07);
}

/* â”€â”€ Scroll-to-bottom FAB â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ */
.scroll-fab {
    position: absolute; bottom: 80px; right: 20px; z-index: 10;
    width: 42px; height: 42px; border-radius: 50%;
    background: #fff; border: none;
    box-shadow: 0 2px 8px rgba(0,0,0,.25);
    display: flex; align-items: center; justify-content: center;
    cursor: pointer; color: #54656f; font-size: 18px;
    transition: opacity .2s, transform .2s;
}
.scroll-fab:hover { background: #f5f5f5; transform: scale(1.08); }
.scroll-fab .sfab-badge {
    position: absolute; top: -4px; right: -4px;
    min-width: 18px; height: 18px; padding: 0 4px;
    background: #25d366; color: #fff;
    border-radius: 9px; font-size: 10px; font-weight: 700;
    display: flex; align-items: center; justify-content: center;
}

/* â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
   INPUT AREA
â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â• */
.chat-input-wrap {
    position: relative;
    flex-shrink: 0;
    background: #f0f2f5;
    border-top: 1px solid #e9edef;
}

/* Emoji picker */
.emoji-picker {
    position: absolute; bottom: 100%; left: 0; right: 0;
    background: #fff;
    border-top: 1px solid #e9edef;
    padding: 10px 12px 6px;
    display: flex; flex-wrap: wrap; gap: 4px;
    max-height: 180px; overflow-y: auto;
}
.emoji-picker button {
    background: none; border: none;
    font-size: 22px; line-height: 1; padding: 4px;
    cursor: pointer; border-radius: 6px;
    transition: background .1s;
}
.emoji-picker button:hover { background: #f0f2f5; }

/* Bar */
.chat-bar {
    display: flex; align-items: flex-end; gap: 8px;
    padding: 8px 12px 10px;
}
.bar-ic {
    background: none; border: none; color: #54656f;
    width: 40px; height: 40px; border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-size: 22px; cursor: pointer; flex-shrink: 0;
    transition: background .15s;
}
.bar-ic:hover { background: #e9edef; }
.bar-ic.active-emoji { color: #128c7e; background: #e0f7f4; }

.bar-center { flex: 1; display: flex; flex-direction: column; }
.bar-input {
    background: #fff; border-radius: 10px;
    padding: 9px 14px; font-size: 14.5px; line-height: 1.45;
    min-height: 42px; max-height: 130px; overflow-y: auto;
    outline: none; color: #111b21; word-break: break-word;
    border: 1.5px solid transparent;
    transition: border-color .15s;
}
.bar-input:focus { border-color: #25d366; }
.bar-input:empty::before { content: attr(data-ph); color: #8696a0; pointer-events: none; }

/* Recording bar */
.rec-bar {
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
    cursor: pointer; flex-shrink: 0;
    transition: background .15s, transform .1s;
}
.bar-send:hover  { background: #0f7268; }
.bar-send:active { transform: scale(.9); }
.bar-send.rec-on { background: #dc2626; }

/* â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
   CALL OVERLAY
â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â• */
.call-ov {
    position: fixed; inset: 0; z-index: 9990;
    background: #1f2c34;
    flex-direction: column; align-items: center; justify-content: center;
    color: #fff;
}
.call-rv { position:absolute;inset:0;width:100%;height:100%;object-fit:cover;background:#000; }
.call-lv { position:absolute;bottom:130px;right:20px;width:130px;border-radius:10px;border:2px solid #fff;object-fit:cover;z-index:2; }
.call-body { position:relative;z-index:3;display:flex;flex-direction:column;align-items:center; }
.call-avatar { width:90px;height:90px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:34px;font-weight:700;color:#fff;margin-bottom:16px; }
.call-name   { font-size:24px;font-weight:600;margin-bottom:6px; }
.call-stat   { font-size:14px;color:rgba(255,255,255,.65);margin-bottom:4px; }
.call-timer  { font-size:20px;font-weight:600;letter-spacing:1px;min-height:28px;margin-bottom:32px; }
.call-btns   { display:flex;gap:24px; }
.call-btn    { width:62px;height:62px;border-radius:50%;border:none;font-size:22px;cursor:pointer;color:#fff;display:flex;align-items:center;justify-content:center;transition:opacity .15s; }
.call-btn:hover { opacity:.85; }
.cb-mute   { background:rgba(255,255,255,.2); }
.cb-cam    { background:rgba(255,255,255,.2); }
.cb-end    { background:#e53935; }
.cb-accept { background:#43a047; }
.cb-mute.muted { background:#e0e0e0;color:#333; }

/* â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
   LIGHTBOX
â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â• */
.lightbox { position:fixed;inset:0;z-index:9995;background:rgba(0,0,0,.92);align-items:center;justify-content:center; }
.lightbox img { max-width:92vw;max-height:88vh;border-radius:6px;object-fit:contain; }
.lb-close { position:absolute;top:16px;right:20px;color:#fff;font-size:28px;cursor:pointer;background:none;border:none;line-height:1; }

/* â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
   NEW CHAT MODAL
â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â• */
.up-list { max-height: 360px; overflow-y: auto; }
.up-item {
    display: flex; align-items: center; gap: 12px;
    padding: 10px 12px; cursor: pointer; border-radius: 8px;
    transition: background .12s;
}
.up-item:hover { background: #f0f2f5; }
.up-av { width:42px;height:42px;border-radius:50%;color:#fff;font-weight:700;font-size:15px;display:flex;align-items:center;justify-content:center;flex-shrink:0; }
.up-name { font-size:14px;font-weight:600;color:#111b21; }
.up-role { font-size:12px;color:#667781; }

/* â”€â”€ Shared icon button â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ */
.ic-btn { width:36px;height:36px;border-radius:50%;background:none;border:none;color:#54656f;display:flex;align-items:center;justify-content:center;font-size:17px;cursor:pointer;flex-shrink:0;transition:background .15s; }
.ic-btn:hover { background:#e9edef; }
.ch-acts .ic-btn { font-size:19px; }

/* â”€â”€ Mobile â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ */
@media (max-width:767px) {
    .cp-left  { width:100%;min-width:100%; }
    .cp-right { position:absolute;inset:0;z-index:5; }
    .ch-back-mob { display:flex !important; }
    .bubble { max-width: 78%; }
}

/* ── Phase 2: Group chats · Reply · Delete ───────────────────────────────── */

/* Group avatar wrapper (for badge) */
.cv-av-wrap { position:relative; flex-shrink:0; }
.cv-grp-ic {
    position:absolute; bottom:-2px; right:-2px;
    width:16px; height:16px; border-radius:50%;
    background:#128c7e; border:2px solid #fff;
    display:flex; align-items:center; justify-content:center;
    font-size:7px; color:#fff;
}

/* Message action bar (Reply / Delete / Copy — revealed on bubble hover) */
.msg-row { position:relative; }
.msg-actions {
    position:absolute; top:50%; transform:translateY(-50%);
    display:none; gap:2px; align-items:center;
    background:rgba(255,255,255,.96);
    border-radius:20px; padding:3px 6px;
    box-shadow:0 1px 6px rgba(0,0,0,.2);
    z-index:5; white-space:nowrap;
}
.msg-row.s .msg-actions { right:calc(100% + 6px); left:auto; }
.msg-row.r .msg-actions { left:calc(100% + 6px); right:auto; }
.msg-row:hover .msg-actions { display:flex; }
.msg-act {
    background:none; border:none; color:#54656f;
    width:28px; height:28px; border-radius:50%;
    display:flex; align-items:center; justify-content:center;
    cursor:pointer; font-size:12px;
    transition:background .1s, color .1s;
}
.msg-act:hover { background:#f0f2f5; color:#111b21; }
.msg-act.del:hover { color:#dc2626; }

/* Reply preview block inside a bubble */
.reply-prev {
    background:rgba(0,0,0,.07); border-left:3px solid #128c7e;
    border-radius:4px; padding:5px 10px; margin-bottom:6px;
    cursor:pointer; transition:background .12s;
    max-width:100%;
}
.reply-prev:hover { background:rgba(0,0,0,.11); }
.rp-name  { font-size:11.5px; font-weight:700; color:#128c7e; margin-bottom:2px; }
.rp-text  { font-size:12px; color:#667781; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; max-width:220px; }
.msg-row.s .reply-prev { border-color:#0d7a6d; }
.msg-row.s .rp-name    { color:#0d7a6d; }

/* Reply bar (shown above the input area when replying to a message) */
.reply-bar { padding:8px 12px 4px; background:#f0f2f5; }
.reply-bar-inner {
    display:flex; align-items:center; gap:10px;
    background:#fff; border-left:4px solid #128c7e;
    border-radius:6px; padding:7px 12px;
}
.reply-bar-meta { flex:1; min-width:0; }
.reply-bar-name { font-size:12px; font-weight:700; color:#128c7e; margin-bottom:1px; }
.reply-bar-text { font-size:12.5px; color:#667781; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.reply-bar-cls  { background:none; border:none; color:#8696a0; font-size:18px; cursor:pointer; line-height:1; flex-shrink:0; }
.reply-bar-cls:hover { color:#111b21; }

/* Modal tabs (Direct / Group) */
.nc-tabs { display:flex; gap:4px; margin-bottom:10px; }
.nc-tab  { flex:1; padding:8px; border:none; border-radius:8px; font-size:13px; font-weight:600; cursor:pointer; background:#f0f2f5; color:#54656f; transition:background .15s,color .15s; font-family:inherit; }
.nc-tab.active { background:#128c7e; color:#fff; }

/* Group info modal member row */
.gi-member { display:flex; align-items:center; gap:12px; padding:9px 4px; border-bottom:1px solid #f5f6f6; }
.gi-member:last-child { border-bottom:none; }
.gi-member-info { flex:1; min-width:0; }
.gi-member-name { font-size:14px; font-weight:600; color:#111b21; }
.gi-member-role { font-size:12px; color:#667781; }

/* ── Phase 3: Load More · Typing Indicators · In-chat Search ────────────── */

/* Load older messages */
.load-more-wrap { text-align:center; padding:12px 0 6px; flex-shrink:0; }
.load-more-btn  { background:#fff; border:1.5px solid #e9edef; border-radius:20px; padding:7px 20px; font-size:12.5px; color:#54656f; cursor:pointer; font-family:inherit; transition:background .12s,box-shadow .12s; }
.load-more-btn:hover    { background:#f5f6f6; box-shadow:0 2px 8px rgba(0,0,0,.1); }
.load-more-btn:disabled { opacity:.5; cursor:wait; }

/* Typing indicator (in chat header) */
.ch-typing { font-size:12px; color:#128c7e; overflow:hidden; max-height:0; transition:max-height .2s; }
.ch-typing.visible { max-height:20px; }
.typing-dot { display:inline-block; width:4px; height:4px; border-radius:50%; background:#128c7e; margin:0 1.5px; vertical-align:middle; animation:tdot 1.2s ease-in-out infinite; }
.typing-dot:nth-child(2) { animation-delay:.2s; }
.typing-dot:nth-child(3) { animation-delay:.4s; }
@keyframes tdot { 0%,60%,100%{transform:translateY(0)} 30%{transform:translateY(-5px)} }

/* In-chat search bar */
.chat-search-bar { background:#fff; border-bottom:1px solid #e9edef; padding:8px 12px; flex-shrink:0; }
.csb-inner { display:flex; align-items:center; gap:6px; background:#f0f2f5; border-radius:10px; padding:6px 10px; }
.csb-icon  { color:#8696a0; font-size:13px; flex-shrink:0; }
.csb-input { flex:1; border:none; background:none; outline:none; font-size:13.5px; color:#111b21; font-family:inherit; }
.csb-input::placeholder { color:#8696a0; }
.csb-count { font-size:12px; color:#8696a0; white-space:nowrap; flex-shrink:0; min-width:52px; text-align:right; }
.csb-nav { background:none; border:none; color:#54656f; width:28px; height:28px; border-radius:50%; cursor:pointer; display:flex; align-items:center; justify-content:center; font-size:12px; flex-shrink:0; transition:background .1s; }
.csb-nav:hover    { background:#e9edef; }
.csb-nav:disabled { opacity:.3; cursor:default; }
.csb-cls { background:none; border:none; color:#8696a0; font-size:16px; cursor:pointer; padding:0 2px; flex-shrink:0; transition:color .1s; line-height:1; }
.csb-cls:hover    { color:#111b21; }
mark.sh        { background:#ffd666; color:#111b21; border-radius:2px; padding:0 1px; }
mark.sh.active { background:#f59e0b; outline:2px solid rgba(245,158,11,.5); border-radius:2px; }

/* ── Phase 4: Reactions · Read receipts · Online status ─────────────────── */

/* Column wrapper so reactions sit below the bubble */
.msg-bubble-col { display:flex; flex-direction:column; max-width:60%; min-width:0; }
.msg-row.s .msg-bubble-col { align-items:flex-end; }
.msg-row.r .msg-bubble-col { align-items:flex-start; }
/* Remove max-width from .bubble itself (now on the col wrapper) */
.bubble { max-width:100%; }

/* Reaction pills (below bubble) */
.msg-reactions { display:flex; flex-wrap:wrap; gap:3px; margin-top:3px; padding:0 2px; }
.reaction-pill {
    display:inline-flex; align-items:center; gap:4px;
    background:rgba(255,255,255,.92); border:1.5px solid #e9edef;
    border-radius:12px; padding:2px 7px; font-size:13px;
    cursor:pointer; user-select:none;
    transition:border-color .12s, background .12s;
    box-shadow:0 1px 3px rgba(0,0,0,.1);
    line-height:1.3;
}
.reaction-pill.mine  { border-color:#128c7e; background:rgba(18,140,126,.07); }
.reaction-pill:hover { background:#f0f2f5; }
.reaction-count { font-size:11px; color:#667781; font-weight:700; }

/* Quick-react bar (shown on bubble hover, inside msg-actions) */
.quick-react { display:flex; gap:2px; align-items:center; padding-right:4px; border-right:1px solid #e9edef; margin-right:2px; }
.qr-btn { background:none; border:none; font-size:17px; cursor:pointer; padding:2px 3px; border-radius:6px; transition:transform .1s, background .1s; line-height:1; }
.qr-btn:hover { transform:scale(1.3); background:rgba(0,0,0,.04); }

/* Read receipt ticks */
.b-tick-sent { color:#8696a0; }   /* gray double = sent/delivered */
.b-tick-read { color:#53bdeb; }   /* blue double = read by others */

/* Online dot on conversation list */
.cv-av-wrap { position:relative; flex-shrink:0; }
.online-dot {
    position:absolute; bottom:0; right:0;
    width:11px; height:11px; border-radius:50%;
    background:#25d366; border:2px solid #fff;
    pointer-events:none;
}
/* Online badge in chat header sub-line */
.ch-online { color:#25d366; font-size:12px; font-weight:600; }
.ch-lastseen { font-size:12px; color:#667781; }
</style>

<!-- â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
     LAYOUT
â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â• -->
<div class="chat-root">

    <!-- LEFT: Conversation list -->
    <div class="cp-left">
        <div class="cp-hdr">
            <h5>Messages</h5>
            <button class="ic-btn" id="btnNewChat" title="New conversation">
                <i class="fa fa-pen-to-square"></i>
            </button>
        </div>
        <div class="cp-search">
            <div class="cp-si">
                <i class="fa fa-magnifying-glass"></i>
                <input type="text" id="convSearch" placeholder="Search conversations…" autocomplete="off">
            </div>
        </div>
        <div class="conv-list" id="convList"></div>
    </div>

    <!-- RIGHT: Active chat -->
    <div class="cp-right" id="cpRight" style="display:flex;flex-direction:column">

        <!-- Welcome -->
        <div class="chat-welcome" id="chatWelcome">
            <div class="cw-icon"><i class="fa fa-comments"></i></div>
            <h6>Mascardi Chat</h6>
            <p>Select a conversation from the list, or start a new one below.</p>
            <button class="btn btn-success btn-sm px-4 mt-2"
                    onclick="bootstrap.Modal.getOrCreateInstance(document.getElementById('newChatModal')).show()">
                <i class="fa fa-pen-to-square me-2"></i>New Conversation
            </button>
        </div>

        <!-- Active conversation -->
        <div class="chat-active" id="chatActive" style="display:none">

            <!-- Header -->
            <div class="ch-hdr">
                <button class="ic-btn ch-back-mob" id="chBack" style="display:none" title="Back">
                    <i class="fa fa-arrow-left"></i>
                </button>
                <div class="ch-av" id="chAv" style="background:#128c7e">â€“</div>
                <div class="ch-info">
                    <div class="ch-name" id="chName">—</div>
                    <div class="ch-sub"  id="chSub"></div>
                    <div class="ch-typing" id="chTyping"><span id="chTypingName"></span><span class="typing-dot"></span><span class="typing-dot"></span><span class="typing-dot"></span></div>
                </div>
                <div class="ch-acts">
                    <button class="ic-btn" id="btnSearch" title="Search messages"><i class="fa fa-magnifying-glass"></i></button>
                    <button class="ic-btn" id="btnGroupInfo" title="Group info" style="display:none"><i class="fa fa-users"></i></button>
                    <button class="ic-btn" id="btnCallA" title="Voice call" style="display:none"><i class="fa fa-phone"></i></button>
                    <button class="ic-btn" id="btnCallV" title="Video call" style="display:none"><i class="fa fa-video"></i></button>
                </div>
            </div>

            <!-- In-chat search bar -->
            <div class="chat-search-bar" id="chatSearchBar" style="display:none">
                <div class="csb-inner">
                    <i class="fa fa-magnifying-glass csb-icon"></i>
                    <input type="text" id="searchInput" class="csb-input" placeholder="Search in conversation..." autocomplete="off">
                    <span class="csb-count" id="searchCount"></span>
                    <button class="csb-nav" id="searchPrev" title="Previous result"><i class="fa fa-chevron-up"></i></button>
                    <button class="csb-nav" id="searchNext" title="Next result"><i class="fa fa-chevron-down"></i></button>
                    <button class="csb-cls" id="searchClose" title="Close search"><i class="fa fa-xmark"></i></button>
                </div>
            </div>

            <!-- Messages -->
            <div class="chat-msgs" id="chatMsgs"></div>

            <!-- Scroll-to-bottom FAB -->
            <button class="scroll-fab" id="scrollFab" style="display:none" title="Scroll to latest">
                <i class="fa fa-chevron-down"></i>
                <div class="sfab-badge" id="fabBadge" style="display:none"></div>
            </button>

            <!-- Input wrap (emoji picker + bar) -->
            <div class="chat-input-wrap">

                <!-- Reply bar (shown when replying to a message) -->
                <div class="reply-bar" id="replyBar" style="display:none">
                    <div class="reply-bar-inner">
                        <div class="reply-bar-meta">
                            <div class="reply-bar-name" id="replyBarName"></div>
                            <div class="reply-bar-text" id="replyBarText"></div>
                        </div>
                        <button class="reply-bar-cls" id="replyBarClose" title="Cancel reply"><i class="fa fa-xmark"></i></button>
                    </div>
                </div>

                <!-- Emoji picker (hidden by default) -->
                <div class="emoji-picker" id="emojiPicker" style="display:none"></div>

                <div class="chat-bar">
                    <!-- Emoji toggle -->
                    <button class="bar-ic" id="btnEmoji" title="Emoji">
                        <i class="fa fa-face-smile"></i>
                    </button>
                    <!-- Attach -->
                    <button class="bar-ic" id="btnAttach" title="Attach file">
                        <i class="fa fa-paperclip"></i>
                    </button>
                    <input type="file" id="fileIn" style="display:none" multiple
                           accept="image/*,.pdf,.doc,.docx,.xls,.xlsx,.zip,.rar,.txt,.csv">

                    <div class="bar-center">
                        <div class="bar-input" id="msgIn"
                             contenteditable="true" data-ph="Type a message…"
                             role="textbox" aria-multiline="true"></div>
                        <div class="rec-bar" id="recBar" style="display:none">
                            <div class="rec-dot"></div>
                            <span class="rec-lbl">Recording</span>
                            <span class="rec-time" id="recTime">0:00</span>
                            <button class="rec-cancel" id="recCancel" title="Cancel">
                                <i class="fa fa-xmark"></i>
                            </button>
                        </div>
                    </div>

                    <!-- Voice note button — visible when input is empty, hidden when typing -->
                    <button class="bar-ic" id="btnVoice" title="Voice note" style="flex-shrink:0">
                        <i class="fa fa-microphone" id="sendIco"></i>
                    </button>

                    <!-- Send button — always visible, paper-plane -->
                    <button class="bar-send" id="sendBtn" title="Send message">
                        <i class="fa fa-paper-plane"></i>
                    </button>
                </div>
            </div>

        </div><!-- /chatActive -->
    </div><!-- /cpRight -->

</div><!-- /chat-root -->

<!-- CALL OVERLAY -->
<div class="call-ov" id="callOv" style="display:none">
    <video class="call-rv" id="remoteVid" autoplay playsinline style="display:none"></video>
    <video class="call-lv" id="localVid"  autoplay muted playsinline style="display:none"></video>
    <div class="call-body">
        <div class=”call-avatar” id=”callAv” style=”background:#128c7e”>?</div>
        <div class=”call-name”  id=”callName”></div>
        <div class="call-stat"  id="callStat">Calling…</div>
        <div class="call-timer" id="callTimer"></div>
        <div class="call-btns">
            <button class="call-btn cb-mute"   id="btnMute"  ><i class="fa fa-microphone"></i></button>
            <button class="call-btn cb-cam"    id="btnCam"   style="display:none"><i class="fa fa-video"></i></button>
            <button class="call-btn cb-end"    id="btnEnd"   ><i class="fa fa-phone-slash"></i></button>
            <button class="call-btn cb-accept" id="btnAccept" style="display:none"><i class="fa fa-phone"></i></button>
        </div>
    </div>
</div>

<!-- LIGHTBOX -->
<div class="lightbox" id="lightbox" style="display:none">
    <button class="lb-close" id="lbClose"><i class="fa fa-xmark"></i></button>
    <img id="lbImg" src="" alt="">
</div>

<!-- #newChatModal is rendered AFTER footer.php (direct child of body) to avoid
     the page-body CSS stacking context caused by its animation/transform. -->

<script>
/* â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
   Constants & helpers
â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â• */
const ME   = { id: <?= (int)$me['id'] ?>, name: <?= json_encode($me['name']) ?> };
const BASE = <?= json_encode(rtrim(BASE_URL, '/')) ?>;
const API  = BASE + '/modules/chat/api/';
const PAL  = <?= json_encode($palette) ?>;

function esc(s) {
    const d = document.createElement('div');
    d.textContent = s == null ? '' : String(s);
    return d.innerHTML;
}
function el(id) { return document.getElementById(id); }
function show(el,d=''){  el.style.display = d || ''; }
function hide(el)      { el.style.display = 'none'; }
function flex(el)      { el.style.display = 'flex'; }
function avatarColor(id) { return PAL[Math.abs(parseInt(id)) % PAL.length]; }
function initials(n)  { return (n||'?').charAt(0).toUpperCase(); }
function fmtDur(s)    { return Math.floor(s/60)+':'+String(s%60).padStart(2,'0'); }
function fmtSize(b)   {
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
    const d = new Date(String(iso).replace(' ','T'));
    if (isNaN(d)) return '';
    const diff = Math.floor((Date.now()-d)/86400000);
    if (diff===0) return 'Today';
    if (diff===1) return 'Yesterday';
    return d.toLocaleDateString([],{weekday:'long',day:'numeric',month:'long'});
}
function _fmtLastSeen(secs) {
    if (secs == null) return '';
    if (secs < 60)   return 'just now';
    if (secs < 3600) return Math.floor(secs/60) + 'm ago';
    if (secs < 86400) return Math.floor(secs/3600) + 'h ago';
    return Math.floor(secs/86400) + 'd ago';
}
function fileIcon(mime) {
    mime = mime||'';
    if (mime.includes('pdf'))                                return ['fa-file-pdf','text-danger'];
    if (mime.includes('word')||mime.includes('document'))    return ['fa-file-word','text-primary'];
    if (mime.includes('excel')||mime.includes('sheet'))     return ['fa-file-excel','text-success'];
    if (mime.includes('zip')||mime.includes('rar'))         return ['fa-file-zipper','text-warning'];
    if (mime.includes('image'))                              return ['fa-file-image','text-info'];
    if (mime.includes('audio'))                              return ['fa-file-audio','text-secondary'];
    return ['fa-file','text-muted'];
}
// footer.php patches window.fetch to add X-CSRF-Token to every POST
async function apiGet(ep,p){
    const r = await fetch(API+ep+(p?'?'+new URLSearchParams(p):''));
    const txt = await r.text();
    try { return JSON.parse(txt); } catch { return {}; }
}
async function apiPost(ep,b){
    const r = await fetch(API+ep,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(b)});
    const txt = await r.text();
    try { return JSON.parse(txt); } catch { return {}; }
}
async function apiUpload(ep,fd){
    const r = await fetch(API+ep,{method:'POST',body:fd});
    const txt = await r.text();
    try { return JSON.parse(txt); } catch { return {}; }
}

/* â”€â”€ Ping sound via Web Audio API (no external file needed) â”€ */
function pingSound() {
    try {
        const ctx = new (window.AudioContext||window.webkitAudioContext)();
        const osc = ctx.createOscillator();
        const gain = ctx.createGain();
        osc.connect(gain); gain.connect(ctx.destination);
        osc.frequency.value = 880;
        osc.type = 'sine';
        gain.gain.setValueAtTime(0, ctx.currentTime);
        gain.gain.linearRampToValueAtTime(0.18, ctx.currentTime+0.01);
        gain.gain.linearRampToValueAtTime(0,    ctx.currentTime+0.25);
        osc.start(ctx.currentTime);
        osc.stop(ctx.currentTime+0.25);
    } catch {}
}

/* â”€â”€ Browser Notification API â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ */
function requestNotifPerm() {
    if ('Notification' in window && Notification.permission === 'default') {
        Notification.requestPermission().catch(()=>{});
    }
}
function pushNotification(title, body) {
    if (!('Notification' in window) || Notification.permission !== 'granted') return;
    if (document.hasFocus()) return; // only notify when on another tab
    try {
        const n = new Notification(title, {
            body, icon: BASE+'/assets/images/favicon.png',
            tag: 'mascardi-chat',
        });
        n.onclick = () => { window.focus(); n.close(); };
        setTimeout(() => n.close(), 6000);
    } catch {}
}

/* â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
   Emoji picker data
â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â• */
const EMOJIS = [
    'ðŸ˜€','ðŸ˜‚','ðŸ¤£','ðŸ˜Š','ðŸ˜','ðŸ¥°','ðŸ˜Ž','ðŸ¤”','ðŸ˜…','ðŸ˜­','ðŸ˜±','ðŸ¥³',
    'ðŸ˜†','ðŸ˜œ','ðŸ˜‡','ðŸ˜´','ðŸ˜¡','ðŸ¤©','ðŸ¥º','ðŸ˜','ðŸ˜’','ðŸ™„','ðŸ¤—','ðŸ˜¬',
    'ðŸ‘','ðŸ‘Ž','ðŸ‘','ðŸ™','ðŸ¤','ðŸ’ª','ðŸ‘‹','âœŒï¸','ðŸ«¶','â¤ï¸','ðŸ’”','ðŸ”¥',
    'âœ¨','ðŸ’¯','ðŸŽ‰','ðŸŽŠ','ðŸŽ¯','ðŸš€','ðŸ’¡','â­','ðŸŒŸ','âœ…','âŒ','âš¡',
    'ðŸ˜‹','ðŸ¤­','ðŸ«¡','ðŸ« ','ðŸ˜®','ðŸ˜¯','ðŸ˜²','ðŸ¤¯','ðŸ¥´','ðŸ˜µ','ðŸ¤¤','ðŸ˜ª',
];

/* â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•
   Chat application
â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â•â• */
const Chat = window.Chat = {
    convId:0, convName:'', convColor:'#128c7e', calleeId:null,
    convType:'direct',      // 'direct' | 'group'
    lastMsgId:0, lastDay:'',
    readMin:0,              // min last_read_msg_id from other participants (read receipts)
    otherOnline:false, otherLastSeenDiff:null,  // online status for direct chats
    pollTimer:null,
    isRecording:false, mediaRec:null, audioChunks:[], recTimerInt:null, recSecs:0,
    curAudio:null,
    localStream:null, peerConn:null, callPoll:null,
    activeCallId:null, callTimerInt:null,
    isMuted:false, isCamOff:false, pendingIce:[],
    emojiOpen:false,
    scrollUnread:0,         // messages arrived while scrolled up
    prevSenderId:null,      // for message grouping
    prevMsgTs:null,
    replyTo:null,           // { id, sender_name, type, content, file_name } | null
    // Phase 3
    oldestMsgId:Infinity,   // for load-more pagination
    hasMoreMsgs:false,
    typingTimer:null,       // debounce handle for sending typing signal
    typingLastSent:0,       // timestamp of last typing POST
    searchOpen:false,
    searchMatches:[],       // array of <mark> elements
    searchIdx:-1,

    STUN:{iceServers:[{urls:'stun:stun.l.google.com:19302'},{urls:'stun:stun1.l.google.com:19302'}]},

    /* â”€â”€ Build emoji picker â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ */
    buildEmojiPicker() {
        const picker = el('emojiPicker');
        picker.innerHTML = EMOJIS.map(e =>
            `<button title="${e}" data-emoji="${e}">${e}</button>`
        ).join('');
        picker.addEventListener('click', ev => {
            const btn = ev.target.closest('[data-emoji]');
            if (!btn) return;
            this.insertEmoji(btn.dataset.emoji);
        });
    },
    insertEmoji(emoji) {
        const input = el('msgIn');
        input.focus();
        // Insert at cursor position
        const sel = window.getSelection();
        if (sel.rangeCount) {
            const range = sel.getRangeAt(0);
            range.deleteContents();
            range.insertNode(document.createTextNode(emoji));
            range.collapse(false);
            sel.removeAllRanges(); sel.addRange(range);
        } else {
            input.innerText += emoji;
        }
        this._syncBtn();
    },
    toggleEmoji() {
        this.emojiOpen = !this.emojiOpen;
        const picker = el('emojiPicker');
        this.emojiOpen ? show(picker,'flex') : hide(picker);
        el('btnEmoji').classList.toggle('active-emoji', this.emojiOpen);
    },

    /* â”€â”€ Load conversations â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ */
    async loadConvs() {
        try {
            const d = await apiGet('conversations.php');
            const convs = d.conversations || [];
            const list = el('convList');
            if (!convs.length) {
                list.innerHTML = `<div class="conv-empty">
                    <div class="ce-icon"><i class="fa fa-message"></i></div>
                    No conversations yet.<br>
                    <small style="display:block;margin:8px 0 12px">Search for a colleague below to start chatting.</small>
                    <button class="btn btn-success btn-sm px-4"
                        onclick="bootstrap.Modal.getOrCreateInstance(document.getElementById('newChatModal')).show()">
                        <i class="fa fa-pen-to-square me-1"></i> New Conversation
                    </button>
                </div>`;
                return;
            }
            list.innerHTML = convs.map(c => this._convHtml(c)).join('');
            if (this.convId)
                list.querySelector(`[data-cid="${this.convId}"]`)?.classList.add('active');

            // Update sidebar badge
            const total = convs.reduce((s,c)=>s+(parseInt(c.unread_count)||0),0);
            const badge = document.getElementById('chatNavBadge');
            if (badge) {
                if (total > 0) { badge.textContent = total>99?'99+':total; show(badge); }
                else hide(badge);
            }
        } catch(e) {
            console.error('loadConvs', e);
            el('convList').innerHTML = `<div class="conv-empty">
                <div class="ce-icon"><i class="fa fa-triangle-exclamation"></i></div>
                Could not load conversations.<br>
                <small>Make sure the database migration has been run.</small>
            </div>`;
        }
    },

    _convHtml(c) {
        const isGroup = c.type === 'group';
        const color   = avatarColor(c.other_user_id || c.id);
        const time    = c.last_msg_at ? fmtTime(c.last_msg_at) : '';
        const preview = esc((c.last_preview||'').substring(0,55));
        const badge   = c.unread_count > 0
            ? `<div class="cv-unread">${c.unread_count>99?'99+':c.unread_count}</div>` : '';
        const active    = this.convId == c.id ? 'active' : '';
        const timeStyle = c.unread_count > 0 ? 'color:#25d366;font-weight:700' : '';
        const isOnline  = !isGroup && c.other_online;
        const onlineDot = isOnline ? `<div class="online-dot"></div>` : '';
        const avatarEl  = isGroup
            ? `<div class="cv-av-wrap"><div class="cv-av" style="background:${color};font-size:14px"><i class="fa fa-users"></i><div class="cv-grp-ic"><i class="fa fa-users" style="font-size:6px"></i></div></div></div>`
            : `<div class="cv-av-wrap"><div class="cv-av" style="background:${color}">${esc(initials(c.display_name))}</div>${onlineDot}</div>`;
        return `<div class="conv-item ${active}"
                     data-cid="${c.id}"
                     data-cname="${esc(c.display_name)}"
                     data-ccolor="${color}"
                     data-callee="${c.other_user_id||0}"
                     data-ctype="${esc(c.type||'direct')}"
                     data-online="${isOnline ? '1' : '0'}"
                     data-lsd="${c.other_last_seen_diff != null ? c.other_last_seen_diff : ''}"
                     >
            ${avatarEl}
            <div class="cv-body">
                <div class="cv-r1">
                    <span class="cv-name">${esc(c.display_name)}</span>
                    <span class="cv-time" style="${timeStyle}">${time}</span>
                </div>
                <div class="cv-r2">
                    <span class="cv-prev">${preview || '<em style="opacity:.5">No messages yet</em>'}</span>
                    ${badge}
                </div>
            </div>
        </div>`;
    },

    /* â”€â”€ Open conversation â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ */
    async openConv(cid, cname, ccolor, callee, ctype, extra) {
        this.convId    = parseInt(cid)||0;
        this.convName  = cname;
        this.convColor = ccolor || '#128c7e';
        this.calleeId  = parseInt(callee)||null;
        this.convType  = ctype || 'direct';
        this.lastMsgId = 0;
        this.lastDay   = '';
        this.readMin   = 0;
        this.prevSenderId = null;
        this.prevMsgTs    = null;
        this.scrollUnread  = 0;
        this.oldestMsgId   = Infinity;
        this.hasMoreMsgs   = false;
        this.otherOnline   = !!(extra && extra.online);
        this.otherLastSeenDiff = (extra && extra.lastSeenDiff != null) ? extra.lastSeenDiff : null;
        this.clearReply();
        this.clearSearch();

        // Update header
        const isGrp = this.convType === 'group';
        if (isGrp) {
            el('chAv').innerHTML = '<i class="fa fa-users"></i>';
        } else {
            el('chAv').textContent = initials(cname);
        }
        el('chAv').style.background = this.convColor;
        el('chName').textContent = cname;
        // Online / last seen sub-line for direct chats
        if (!isGrp) {
            if (this.otherOnline) {
                el('chSub').innerHTML = `<span class="ch-online"><i class="fa fa-circle" style="font-size:7px;vertical-align:middle"></i> Online</span>`;
            } else if (this.otherLastSeenDiff !== null) {
                el('chSub').innerHTML = `<span class="ch-lastseen">Last seen ${_fmtLastSeen(this.otherLastSeenDiff)}</span>`;
            } else {
                el('chSub').textContent = '';
            }
        } else {
            el('chSub').textContent = 'Group conversation';
        }

        // Group info button for groups; call buttons for direct only
        el('btnGroupInfo').style.display = isGrp ? '' : 'none';
        const sc = !isGrp && !!this.calleeId;
        el('btnCallA').style.display = sc ? '' : 'none';
        el('btnCallV').style.display = sc ? '' : 'none';

        // Show right panel
        const rp = el('cpRight');
        rp.style.display = 'flex'; rp.style.flexDirection = 'column';
        hide(el('chatWelcome'));
        const ca = el('chatActive');
        ca.style.display = 'flex'; ca.style.flexDirection = 'column';

        // Sidebar active state
        document.querySelectorAll('.conv-item').forEach(e=>e.classList.remove('active'));
        document.querySelector(`[data-cid="${this.convId}"]`)?.classList.add('active');

        // Reset scroll FAB
        hide(el('scrollFab'));

        el('chatMsgs').innerHTML = `<div style="text-align:center;color:#8696a0;padding:40px 0;font-size:13px">
            <i class="fa fa-spinner fa-spin me-1"></i> Loading messages…</div>`;

        clearInterval(this.pollTimer);
        await this._fetchMsgs(true);
        this.pollTimer = setInterval(()=>this._fetchMsgs(false), 2000);
        el('msgIn').focus();
        // Close emoji picker if open
        if (this.emojiOpen) this.toggleEmoji();
    },

    /* â”€â”€ Fetch & render messages â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ */
    async _fetchMsgs(initial) {
        try {
            const params = initial
                ? { conversation_id: this.convId, initial: 1 }
                : { conversation_id: this.convId, after: this.lastMsgId };

            // Fire messages fetch and (on polls) typing check in parallel
            const [d, td] = await Promise.all([
                apiGet(‘messages.php’, params),
                initial ? Promise.resolve({ typing: [] }) : apiGet(‘typing.php’, { conversation_id: this.convId }),
            ]);

            // ── Typing indicator ───────────────────────────────────────────
            if (!initial) this._updateTyping(td.typing || []);

            const msgs = d.messages || [];
            // Update read_min for tick rendering
            if (typeof d.read_min === ‘number’) this.readMin = d.read_min;
            const box  = el(‘chatMsgs’);

            if (!msgs.length) {
                if (initial) box.innerHTML = `<div style=”text-align:center;color:#8696a0;padding:60px 0;font-size:13px”>No messages yet — say hello 👋</div>`;
                return;
            }

            const atBottom = initial || (box.scrollHeight - box.scrollTop - box.clientHeight < 80);

            if (initial) {
                box.innerHTML = ‘’;
                this.prevSenderId = null; this.prevMsgTs = null; this.lastDay = ‘’;
                // ── Load-more button ───────────────────────────────────────
                if (d.has_more) {
                    this.hasMoreMsgs = true;
                    box.insertAdjacentHTML(‘afterbegin’, `<div class=”load-more-wrap” id=”loadMoreWrap”>
                        <button class=”load-more-btn” id=”loadMoreBtn” onclick=”Chat.loadMore()”>
                            <i class=”fa fa-clock-rotate-left me-1”></i>Load older messages
                        </button>
                    </div>`);
                } else {
                    this.hasMoreMsgs = false;
                }
            }

            let newFromOthers = 0;
            msgs.forEach(m => {
                const day = fmtDay(m.created_at);
                if (day && day !== this.lastDay) {
                    box.insertAdjacentHTML(‘beforeend’, `<div class=”day-sep”><span>${esc(day)}</span></div>`);
                    this.lastDay = day; this.prevSenderId = null; this.prevMsgTs = null;
                }
                box.insertAdjacentHTML(‘beforeend’, this._msgHtml(m));
                const mid = parseInt(m.id);
                this.lastMsgId    = Math.max(this.lastMsgId,    mid);
                this.oldestMsgId  = Math.min(this.oldestMsgId,  mid);
                if (parseInt(m.sender_id) !== ME.id) newFromOthers++;
            });

            if (atBottom) {
                box.scrollTop = box.scrollHeight;
            } else if (!initial && newFromOthers > 0) {
                this.scrollUnread += newFromOthers;
                show(el(‘scrollFab’), ‘flex’);
                const fb = el(‘fabBadge’);
                fb.textContent = this.scrollUnread > 99 ? ‘99+’ : this.scrollUnread;
                show(fb);
                pingSound();
                if (msgs.length) {
                    const last = msgs[msgs.length-1];
                    if (parseInt(last.sender_id) !== ME.id) {
                        pushNotification(last.sender_name || ‘New message’, last.content || ‘📎 Attachment’);
                    }
                }
            }

            if (initial && !document.hasFocus() && newFromOthers > 0) pingSound();
            if (!initial) {
                this.loadConvs();
                // Re-run search if open so new messages are included in highlights
                if (this.searchOpen && el('searchInput').value) {
                    this.doSearch(el('searchInput').value);
                }
            }
        } catch(e) { console.error(‘_fetchMsgs’, e); }
    },

    /* ── Load more (older) messages ────────────────────────────────────────── */
    async loadMore() {
        const btn = el(‘loadMoreBtn’);
        if (btn) btn.disabled = true;
        const box = el(‘chatMsgs’);
        const prevScrollHeight = box.scrollHeight;

        try {
            const d    = await apiGet(‘messages.php’, { conversation_id: this.convId, before: this.oldestMsgId });
            const msgs = d.messages || [];

            // Remove the load-more wrap
            el(‘loadMoreWrap’)?.remove();

            if (!msgs.length) return;

            // Temporarily reset grouping state to render old messages cleanly
            const savedSender = this.prevSenderId;
            const savedTs     = this.prevMsgTs;
            const savedDay    = this.lastDay;
            this.prevSenderId = null; this.prevMsgTs = null; this.lastDay = ‘’;

            let html = ‘’;
            msgs.forEach(m => {
                const day = fmtDay(m.created_at);
                if (day && day !== this.lastDay) {
                    html += `<div class=”day-sep”><span>${esc(day)}</span></div>`;
                    this.lastDay = day;
                }
                html += this._msgHtml(m);
                this.oldestMsgId = Math.min(this.oldestMsgId, parseInt(m.id));
            });

            // Restore current-messages grouping state
            this.prevSenderId = savedSender;
            this.prevMsgTs    = savedTs;
            this.lastDay      = savedDay;

            // If still more messages, prepend a new load-more button
            let prefix = ‘’;
            if (d.has_more) {
                prefix = `<div class=”load-more-wrap” id=”loadMoreWrap”>
                    <button class=”load-more-btn” id=”loadMoreBtn” onclick=”Chat.loadMore()”>
                        <i class=”fa fa-clock-rotate-left me-1”></i>Load older messages
                    </button>
                </div>`;
            }
            // Insert before first child (keeps the new button at top if needed)
            box.insertAdjacentHTML(‘afterbegin’, html);
            if (prefix) box.insertAdjacentHTML(‘afterbegin’, prefix);

            // Preserve scroll position (prevent jump to top)
            box.scrollTop = box.scrollHeight - prevScrollHeight;
        } catch(e) { console.error(‘loadMore’, e); if (btn) btn.disabled = false; }
    },

    /* ── Typing indicator ───────────────────────────────────────────────────── */
    sendTyping() {
        if (!this.convId) return;
        const now = Date.now();
        if (now - this.typingLastSent < 3000) return; // throttle: at most once per 3 s
        this.typingLastSent = now;
        apiPost(‘typing.php’, { conversation_id: this.convId }).catch(()=>{});
    },
    _updateTyping(typers) {
        const wrap = el(‘chTyping’);
        const nameEl = el(‘chTypingName’);
        if (!typers || !typers.length) {
            wrap.classList.remove(‘visible’);
            return;
        }
        const names = typers.map(t => t.name.split(‘ ‘)[0]); // first name only
        nameEl.textContent = names.join(‘, ‘) + (names.length === 1 ? ‘ is typing ‘ : ‘ are typing ‘);
        wrap.classList.add(‘visible’);
    },

    /* â”€â”€ Render a single message â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ */
    _msgHtml(m) {
        const sent    = parseInt(m.sender_id) === ME.id;
        const sid     = parseInt(m.sender_id);
        const ts      = m.created_at ? new Date(String(m.created_at).replace(' ','T')) : null;
        const tick    = sent ? `<span class="b-tick"><i class="fa fa-check-double"></i></span>` : '';
        const time    = fmtTime(m.created_at);

        // Call / system — centered chip, no grouping
        if (m.type==='call'||m.type==='system') {
            this.prevSenderId = null; this.prevMsgTs = null;
            const ico = m.type==='call'?(m.content?.includes('video')?'fa-video':'fa-phone'):'fa-circle-info';
            return `<div class="chip-row"><div class="msg-chip"><i class="fa ${ico} me-1"></i>${esc(m.content)}</div></div>`;
        }

        // Grouping logic
        const sameGroup = sid === this.prevSenderId &&
                          ts && this.prevMsgTs &&
                          (ts - this.prevMsgTs) < 3*60*1000; // within 3 minutes

        // We'll patch the previous row's class when this row is added
        // Simple approach: mark current row, update prev row via DOM
        let groupClass = 'group-solo';
        if (sameGroup) {
            // Find and update the last bubble in the DOM to be group-mid/group-start
            const prevRows = el('chatMsgs').querySelectorAll('.msg-row:not(.chip-row)');
            const lastRow  = prevRows[prevRows.length-1];
            if (lastRow) {
                lastRow.classList.remove('group-solo','group-end');
                lastRow.classList.add(lastRow.classList.contains('group-start') ? 'group-start' : 'group-mid');
                // The current one will be group-end
            }
            groupClass = 'group-end';
        } else {
            groupClass = 'group-start group-solo';
        }

        this.prevSenderId = sid;
        this.prevMsgTs    = ts;

        // Avatar for received messages
        const color = avatarColor(sid);
        const avHtml = !sent && groupClass.includes('group-start')
            ? `<div class="msg-av" style="background:${color}" title="${esc(m.sender_name||'')}">${esc(initials(m.sender_name||'?'))}</div>`
            : (!sent ? `<div class="msg-av-ph"></div>` : '');

        // Reply preview (if this message is a reply)
        let replyBlock = '';
        if (m.reply_to_id) {
            const rname = esc(m.reply_to_sender_name || 'Unknown');
            let rtext = '';
            if (m.reply_to_type === 'image')  rtext = '📷 Photo';
            else if (m.reply_to_type === 'voice') rtext = '🎤 Voice note';
            else if (m.reply_to_type === 'file')  rtext = '📎 ' + esc(m.reply_to_file_name || 'File');
            else rtext = esc((m.reply_to_content || '').substring(0, 80));
            replyBlock = `<div class="reply-prev" data-scroll="${m.reply_to_id}">
                <div class="rp-name">${rname}</div>
                <div class="rp-text">${rtext}</div>
            </div>`;
        }

        // Sender name inside bubble for group received messages
        const senderLabel = (!sent && this.convType === 'group' && groupClass.includes('group-start'))
            ? `<div class="b-sender" style="color:${color}">${esc(m.sender_name||'')}</div>` : '';

        // Bubble content
        let body = '';
        if (m.type==='image' && m.file_url) {
            body = `<img class="b-img" src="${esc(m.file_url)}" alt="${esc(m.file_name||'Image')}" loading="lazy" data-src="${esc(m.file_url)}">`;
        } else if (m.type==='voice' && m.file_url) {
            const dur = m.duration ? fmtDur(parseInt(m.duration)) : '0:00';
            const vid = 'v'+m.id;
            body = `<div class="b-voice">
                <button class="b-play" data-vid="${vid}" data-src="${esc(m.file_url)}"><i class="fa fa-play"></i></button>
                <div class="b-wf" id="wf${vid}" style="--p:0%"></div>
                <span class="b-dur" id="dr${vid}">${esc(dur)}</span>
            </div>`;
        } else if (m.type==='file' && m.file_url) {
            const [ico,col] = fileIcon(m.mime_type);
            const sz = m.file_size ? fmtSize(parseInt(m.file_size)) : '';
            body = `<a class="b-file" href="${esc(m.file_url)}" download="${esc(m.file_name||'file')}" target="_blank">
                <i class="fa ${ico} ${col} b-file-ico"></i>
                <div style="flex:1;min-width:0">
                    <div class="b-file-nm">${esc(m.file_name||'File')}</div>
                    ${sz?`<div class="b-file-sz">${sz}</div>`:''}
                </div>
                <i class="fa fa-download b-file-dl"></i>
            </a>`;
        } else {
            body = `<span class="b-text">${esc(m.content||'')}</span>`;
        }

        // Real read receipt tick
        const isRead = sent && (parseInt(m.id) <= this.readMin);
        const tickHtml = sent
            ? (isRead
                ? `<span class="b-tick b-tick-read"><i class="fa fa-check-double"></i></span>`
                : `<span class="b-tick b-tick-sent"><i class="fa fa-check-double"></i></span>`)
            : '';

        // Reaction pills (below bubble)
        const reactPills = this._reactHtml(m.reactions || [], m.id);

        // Quick-react bar (inside action menu on hover)
        const quickReact = `<div class="quick-react">
            ${['👍','❤️','😂','😮','😢','✅'].map(e =>
                `<button class="qr-btn" data-act="react" data-mid="${m.id}" data-em="${e}" title="${e}">${e}</button>`
            ).join('')}
        </div>`;

        // Action bar (Reply, Delete for own messages, Copy)
        const delBtn = sent
            ? `<button class="msg-act del" data-act="delete" data-mid="${m.id}" title="Delete"><i class="fa fa-trash"></i></button>` : '';
        const copyBtn = (m.type === 'text' || !m.type)
            ? `<button class="msg-act" data-act="copy" data-mid="${m.id}" title="Copy"><i class="fa fa-copy"></i></button>` : '';
        const actionBar = `<div class="msg-actions">
            ${quickReact}
            <button class="msg-act" data-act="reply" data-mid="${m.id}" title="Reply"><i class="fa fa-reply"></i></button>
            ${copyBtn}${delBtn}
        </div>`;

        return `<div class="msg-row ${sent?'s':'r'} ${groupClass}" data-mid="${m.id}">
            ${avHtml}
            <div class="msg-bubble-col">
                <div class="bubble">
                    ${senderLabel}${replyBlock}
                    ${body}
                    <div class="b-meta">${time} ${tickHtml}</div>
                </div>
                ${reactPills}
            </div>
            ${actionBar}
        </div>`;
    },

    /* â”€â”€ Send text â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ */
    async sendText() {
        if (!this.convId) return;
        const msgEl = el('msgIn');
        const text  = (msgEl.innerText||'').trim();
        if (!text) return;
        msgEl.innerText = ''; this._syncBtn();
        if (this.emojiOpen) this.toggleEmoji();
        const payload = { conversation_id: this.convId, content: text };
        if (this.replyTo) payload.reply_to_id = this.replyTo.id;
        this.clearReply();
        try {
            await apiPost('send.php', payload);
            await this._fetchMsgs(false);
            const box = el('chatMsgs');
            box.scrollTop = box.scrollHeight;
        } catch(e) { console.error('sendText',e); }
    },

    /* â”€â”€ Start direct conversation â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ */
    _chatErr(msgsBox, icon, title, sub) {
        if (!msgsBox) return;
        msgsBox.innerHTML = `<div style="text-align:center;padding:48px 24px">
            <i class="fa ${esc(icon)} fa-2x mb-3 d-block" style="color:#dc2626;opacity:.7"></i>
            <div style="font-weight:600;color:#111b21;margin-bottom:6px">${esc(title)}</div>
            <div style="font-size:13px;color:#667781">${esc(sub)}</div>
        </div>`;
    },
    async startDirect(uid, uname, ucolor) {
        // Show loading state right away — before the modal finishes closing
        const msgsBox = el('chatMsgs');
        hide(el('chatWelcome'));
        const ca = el('chatActive');
        if (ca) { ca.style.display = 'flex'; ca.style.flexDirection = 'column'; }
        if (msgsBox) msgsBox.innerHTML = `<div style="text-align:center;color:#8696a0;padding:48px 0">
            <i class="fa fa-spinner fa-spin fa-lg me-2"></i>Opening conversation…</div>`;

        const modalEl = el('newChatModal');
        const modal   = modalEl ? bootstrap.Modal.getOrCreateInstance(modalEl) : null;

        // Wait for the modal close animation before focusing the chat input
        const hidden = modalEl
            ? new Promise(res => {
                const t = setTimeout(res, 700);
                modalEl.addEventListener('hidden.bs.modal', () => { clearTimeout(t); res(); }, { once: true });
              })
            : Promise.resolve();

        if (modal) modal.hide();

        try {
            const d = await apiPost('conversations.php', { user_id: parseInt(uid) });
            await hidden;

            if (d.conversation_id) {
                await this.loadConvs();
                await this.openConv(d.conversation_id, uname, ucolor, uid);
            } else {
                this._chatErr(msgsBox, 'fa-comment-slash',
                    d.error || 'Could not open conversation',
                    'Please try again. If the problem persists, contact your administrator.');
            }
        } catch(e) {
            console.error('startDirect', e);
            await hidden;
            this._chatErr(msgsBox, 'fa-wifi',
                'Connection error',
                'Check your network connection and try again.');
        }
    },

    /* â”€â”€ File upload â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ */
    async uploadFile(file) {
        if (!this.convId) return;
        const fd = new FormData();
        fd.append('conversation_id', this.convId);
        fd.append('file', file, file.name);
        try {
            await apiUpload('upload.php', fd);
            await this._fetchMsgs(false);
            el('chatMsgs').scrollTop = el('chatMsgs').scrollHeight;
        } catch(e) { console.error('uploadFile',e); }
    },

    /* â”€â”€ Voice recording â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ */
    _bestMime() {
        const t = ['audio/webm;codecs=opus','audio/webm','audio/ogg;codecs=opus','audio/mp4'];
        for (const m of t) if (MediaRecorder.isTypeSupported(m)) return m;
        return '';
    },
    async startRec() {
        if (!this.convId || this.isRecording) return;
        try {
            const stream = await navigator.mediaDevices.getUserMedia({audio:true});
            this.audioChunks = [];
            this.mediaRec = new MediaRecorder(stream, {mimeType:this._bestMime()});
            this.mediaRec.ondataavailable = ev => this.audioChunks.push(ev.data);
            this.mediaRec.start(100);
            this.isRecording = true;
            hide(el('msgIn')); el('recBar').style.display='flex';
            el('sendBtn').classList.add('rec-on');
            this._syncBtn();
            this.recSecs=0; el('recTime').textContent='0:00';
            this.recTimerInt = setInterval(()=>{
                this.recSecs++;
                el('recTime').textContent = fmtDur(this.recSecs);
            },1000);
        } catch(e) { alert('Microphone access denied. Please allow it in your browser settings.'); }
    },
    async stopRec() {
        if (!this.isRecording||!this.mediaRec) return;
        const dur = this.recSecs;
        clearInterval(this.recTimerInt); this.isRecording=false;
        show(el('msgIn')); hide(el('recBar'));
        el('sendBtn').classList.remove('rec-on'); this._syncBtn();
        return new Promise(resolve => {
            this.mediaRec.onstop = async () => {
                const mime = this.mediaRec.mimeType||'audio/webm';
                const blob = new Blob(this.audioChunks, {type:mime});
                this.mediaRec.stream.getTracks().forEach(t=>t.stop());
                this.mediaRec = null;
                if (dur<1||!this.convId) { resolve(); return; }
                try {
                    const fd = new FormData();
                    fd.append('conversation_id', this.convId);
                    fd.append('file', blob, 'voice.'+(mime.includes('ogg')?'ogg':'webm'));
                    fd.append('voice','1'); fd.append('duration', dur);
                    await apiUpload('upload.php', fd);
                    await this._fetchMsgs(false);
                    el('chatMsgs').scrollTop = el('chatMsgs').scrollHeight;
                } catch(e) { console.error('stopRec',e); }
                resolve();
            };
            this.mediaRec.stop();
        });
    },
    cancelRec() {
        if (!this.isRecording||!this.mediaRec) return;
        clearInterval(this.recTimerInt); this.isRecording=false;
        this.mediaRec.stream.getTracks().forEach(t=>t.stop()); this.mediaRec=null;
        show(el('msgIn')); hide(el('recBar'));
        el('sendBtn').classList.remove('rec-on'); this._syncBtn();
    },

    _syncBtn() {
        const text = (el('msgIn').innerText||'').trim();
        const voiceBtn = el('btnVoice');
        const sendIEl  = el('sendBtn') ? el('sendBtn').querySelector('i') : null;
        if (this.isRecording) {
            if (el('sendIco')) el('sendIco').className = 'fa fa-stop';
            if (voiceBtn) voiceBtn.style.display = 'none';
            if (sendIEl)  sendIEl.className = 'fa fa-stop';
        } else {
            if (el('sendIco')) el('sendIco').className = 'fa fa-microphone';
            if (voiceBtn) voiceBtn.style.display = text ? 'none' : '';
            if (sendIEl)  sendIEl.className = 'fa fa-paper-plane';
        }
    },
    onSend() {
        if (this.isRecording) { this.stopRec(); return; }
        this.sendText();
    },

    /* â”€â”€ Voice playback â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ */
    playVoice(src, vid) {
        if (this.curAudio) {
            this.curAudio.pause(); this.curAudio=null;
            document.querySelectorAll('.b-play i').forEach(i=>i.className='fa fa-play');
            document.querySelectorAll('.b-wf').forEach(w=>w.style.setProperty('--p','0%'));
        }
        const btn=document.querySelector(`[data-vid="${vid}"]`);
        const wf=el('wf'+vid), dr=el('dr'+vid);
        const audio=new Audio(src); this.curAudio=audio;
        if (btn) btn.innerHTML='<i class="fa fa-pause"></i>';
        audio.addEventListener('timeupdate',()=>{
            const pct=audio.duration?(audio.currentTime/audio.duration*100):0;
            if (wf) wf.style.setProperty('--p',pct.toFixed(1)+'%');
            if (dr) dr.textContent=fmtDur(Math.floor(audio.currentTime));
        });
        audio.addEventListener('ended',()=>{
            if (btn) btn.innerHTML='<i class="fa fa-play"></i>';
            if (wf) wf.style.setProperty('--p','0%');
            if (btn) btn.onclick=null; this.curAudio=null;
        });
        audio.play().catch(()=>{});
        if (btn) btn.onclick=()=>{ audio.pause(); btn.innerHTML='<i class="fa fa-play"></i>'; btn.onclick=null; this.curAudio=null; };
    },

    /* â”€â”€ WebRTC Calls â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ */
    async call(type) {
        if (!this.convId||!this.calleeId) return;
        this.pendingIce=[];
        this._showCall(this.convName, this.convColor, type, false);
        el('callStat').textContent='Calling…';
        try {
            this.localStream=await navigator.mediaDevices.getUserMedia({audio:true,video:type==='video'});
            if (type==='video') { const lv=el('localVid'); lv.srcObject=this.localStream; show(lv); show(el('btnCam')); }
            this.peerConn=new RTCPeerConnection(this.STUN);
            this.localStream.getTracks().forEach(t=>this.peerConn.addTrack(t,this.localStream));
            this.peerConn.onicecandidate=ev=>{ if(ev.candidate) this.pendingIce.push(ev.candidate.toJSON()); };
            this.peerConn.ontrack=ev=>{ const rv=el('remoteVid'); rv.srcObject=ev.streams[0]; if(type==='video') show(rv); };
            const offer=await this.peerConn.createOffer();
            await this.peerConn.setLocalDescription(offer);
            const d=await apiPost('call.php',{action:'initiate',conversation_id:this.convId,callee_id:this.calleeId,call_type:type,offer_sdp:JSON.stringify(offer)});
            if (!d.call_id) throw new Error('No call_id');
            this.activeCallId=d.call_id;
            this.callPoll=setInterval(()=>this._pollCall(),2000);
        } catch(err) { this._hideCall(); alert('Could not start call: '+err.message); }
    },
    async _pollCall() {
        if (!this.activeCallId) return;
        try {
            const d=await apiPost('call.php',{action:'status',call_id:this.activeCallId});
            if (d.status==='active'&&d.answer_sdp&&this.peerConn?.signalingState!=='stable') {
                clearInterval(this.callPoll);
                await this.peerConn.setRemoteDescription(new RTCSessionDescription(JSON.parse(d.answer_sdp)));
                (d.callee_ice||[]).forEach(c=>{try{this.peerConn.addIceCandidate(new RTCIceCandidate(c));}catch{}});
                await apiPost('call.php',{action:'ice',call_id:this.activeCallId,candidates:this.pendingIce});
                el('callStat').textContent='Connected'; this._startCallTimer();
            } else if (d.status==='rejected') { this._endCall(); el('callStat').textContent='Call rejected'; setTimeout(()=>this._hideCall(),2500); }
            else if (d.status==='missed')     { this._endCall(); el('callStat').textContent='No answer';    setTimeout(()=>this._hideCall(),2500); }
            else if (d.status==='ended')      { this._endCall(); this._hideCall(); }
        } catch {}
    },
    async acceptCall() {
        try {
            const d=await apiPost('call.php',{action:'status',call_id:this.activeCallId});
            if (!d.offer_sdp) return;
            this.localStream=await navigator.mediaDevices.getUserMedia({audio:true,video:d.call_type==='video'});
            if (d.call_type==='video') { const lv=el('localVid'); lv.srcObject=this.localStream; show(lv); }
            this.peerConn=new RTCPeerConnection(this.STUN);
            this.localStream.getTracks().forEach(t=>this.peerConn.addTrack(t,this.localStream));
            this.peerConn.onicecandidate=ev=>{ if(ev.candidate) this.pendingIce.push(ev.candidate.toJSON()); };
            this.peerConn.ontrack=ev=>{ const rv=el('remoteVid'); rv.srcObject=ev.streams[0]; if(d.call_type==='video') show(rv); };
            await this.peerConn.setRemoteDescription(new RTCSessionDescription(JSON.parse(d.offer_sdp)));
            const ans=await this.peerConn.createAnswer();
            await this.peerConn.setLocalDescription(ans);
            await apiPost('call.php',{action:'answer',call_id:this.activeCallId,answer_sdp:JSON.stringify(ans)});
            hide(el('btnAccept')); el('callStat').textContent='Connected'; this._startCallTimer();
            setTimeout(async()=>{
                try {
                    const d2=await apiPost('call.php',{action:'status',call_id:this.activeCallId});
                    (d2.caller_ice||[]).forEach(c=>{try{this.peerConn.addIceCandidate(new RTCIceCandidate(c));}catch{}});
                    await apiPost('call.php',{action:'ice',call_id:this.activeCallId,candidates:this.pendingIce});
                } catch {}
            },1500);
        } catch(e) { console.error('acceptCall',e); }
    },
    async hangup() {
        if (this.activeCallId) try { await apiPost('call.php',{action:'end',call_id:this.activeCallId}); } catch {}
        this._endCall(); this._hideCall();
    },
    toggleMute() {
        this.isMuted=!this.isMuted;
        this.localStream?.getAudioTracks().forEach(t=>t.enabled=!this.isMuted);
        el('btnMute').innerHTML=this.isMuted?'<i class="fa fa-microphone-slash"></i>':'<i class="fa fa-microphone"></i>';
        el('btnMute').classList.toggle('muted',this.isMuted);
    },
    toggleCam() {
        this.isCamOff=!this.isCamOff;
        this.localStream?.getVideoTracks().forEach(t=>t.enabled=!this.isCamOff);
        el('btnCam').innerHTML=this.isCamOff?'<i class="fa fa-video-slash"></i>':'<i class="fa fa-video"></i>';
    },
    _showCall(name,color,type,incoming) {
        const ov=el('callOv'); ov.style.display='flex'; ov.style.flexDirection='column'; ov.style.alignItems='center'; ov.style.justifyContent='center';
        el('callAv').textContent=initials(name); el('callAv').style.background=color||'#128c7e';
        el('callName').textContent=name; el('callTimer').textContent='';
        incoming?show(el('btnAccept')):hide(el('btnAccept'));
        type==='video'?show(el('btnCam')):hide(el('btnCam'));
        this.isMuted=false; el('btnMute').innerHTML='<i class="fa fa-microphone"></i>'; el('btnMute').classList.remove('muted');
    },
    _hideCall() {
        hide(el('callOv'));
        ['remoteVid','localVid'].forEach(id=>{const v=el(id);if(v){v.srcObject=null;hide(v);}});
        hide(el('btnAccept')); hide(el('btnCam'));
    },
    _endCall() {
        clearInterval(this.callPoll); clearInterval(this.callTimerInt);
        this.peerConn?.close(); this.peerConn=null;
        this.localStream?.getTracks().forEach(t=>t.stop()); this.localStream=null;
        this.activeCallId=null; this.isMuted=false; this.isCamOff=false; this.pendingIce=[];
    },
    _startCallTimer() {
        let s=0; const timerEl=el('callTimer');
        this.callTimerInt=setInterval(()=>{ timerEl.textContent=fmtDur(++s); },1000);
    },
    /* ── Reply ──────────────────────────────────────────────────────────────── */
    setReply(m) {
        this.replyTo = m;
        let preview = '';
        if (m.type === 'image')  preview = '📷 Photo';
        else if (m.type === 'voice') preview = '🎤 Voice note';
        else if (m.type === 'file')  preview = '📎 ' + (m.file_name || 'File');
        else preview = (m.content || '').substring(0, 100);
        el('replyBarName').textContent = m.sender_name || 'You';
        el('replyBarText').textContent = preview;
        el('replyBar').style.display = '';
        el('msgIn').focus();
    },
    clearReply() {
        this.replyTo = null;
        el('replyBar').style.display = 'none';
        el('replyBarName').textContent = '';
        el('replyBarText').textContent = '';
    },

    /* ── Reactions ──────────────────────────────────────────────────────────── */
    _reactHtml(reactions, msgId) {
        if (!reactions || !reactions.length) return '';
        const pills = reactions.map(r =>
            `<span class="reaction-pill${r.m ? ' mine' : ''}"
                   data-act="react" data-mid="${msgId}" data-em="${esc(r.e)}"
                   title="${esc(r.u)}">${r.e}${r.n > 1 ? ` <span class="reaction-count">${r.n}</span>` : ''}</span>`
        ).join('');
        return `<div class="msg-reactions">${pills}</div>`;
    },
    async react(msgId, emoji) {
        try {
            await apiPost('react.php', { message_id: parseInt(msgId), emoji });
            // Refresh messages to update reaction state
            await this._fetchMsgs(false);
        } catch(e) { console.error('react', e); }
    },

    /* ── Delete message ─────────────────────────────────────────────────────── */
    async deleteMsg(msgId) {
        if (!confirm('Delete this message for everyone?')) return;
        try {
            const d = await apiPost('delete.php', { message_id: parseInt(msgId), conversation_id: this.convId });
            if (d.ok) {
                const row = document.querySelector(`.msg-row[data-mid="${msgId}"]`);
                if (row) row.remove();
            }
        } catch(e) { console.error('deleteMsg', e); }
    },

    /* ── Group: create ─────────────────────────────────────────────────────── */
    _grpErr(msg) {
        const box = el('grpError');
        if (!box) return;
        if (msg) { box.textContent = msg; box.style.display = ''; }
        else { box.style.display = 'none'; }
    },
    async createGroup() {
        this._grpErr('');
        const name = (el('grpName').value || '').trim();
        if (!name) {
            this._grpErr('Please enter a group name.');
            el('grpName').focus();
            return;
        }
        const checked = Array.from(document.querySelectorAll('.grp-chk:checked'));
        const memberIds = checked.map(c => parseInt(c.value));
        if (!memberIds.length) {
            this._grpErr('Please select at least one member.');
            return;
        }

        const btn = el('grpCreateBtn');
        const origHtml = btn ? btn.innerHTML : '';
        if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fa fa-spinner fa-spin me-1"></i>Creating…'; }

        try {
            const d = await apiPost('conversations.php', { action:'create_group', name, member_ids: memberIds });
            if (d.conversation_id) {
                bootstrap.Modal.getOrCreateInstance(el('newChatModal')).hide();
                el('grpName').value = '';
                document.querySelectorAll('.grp-chk:checked').forEach(c => c.checked = false);
                el('grpSelCount').textContent = '0 members selected';
                this._grpErr('');
                this.switchNewChatTab('direct');
                await this.loadConvs();
                await this.openConv(d.conversation_id, name, avatarColor(d.conversation_id), null, 'group');
            } else {
                this._grpErr(d.error || 'Could not create group. Please try again.');
            }
        } catch(e) {
            console.error('createGroup', e);
            this._grpErr('Connection error. Please check your network and try again.');
        } finally {
            if (btn) { btn.disabled = false; btn.innerHTML = origHtml; }
        }
    },

    /* ── Group: info panel ──────────────────────────────────────────────────── */
    async openGroupInfo() {
        try {
            const d = await apiGet('group.php', { conversation_id: this.convId });
            if (d.error) { alert(d.error); return; }

            el('giGroupName').textContent = d.group.name || 'Group';
            el('giAvatar').textContent    = initials(d.group.name || 'G');
            el('giMemberCount').textContent = d.members.length + ' member' + (d.members.length !== 1 ? 's' : '');
            el('giRenameInput').value = d.group.name || '';

            // Show rename + add rows to creator/admin only
            const canManage = d.is_creator;
            el('giRenameRow').style.display = canManage ? '' : 'none';
            el('giAddRow').style.display    = canManage ? '' : 'none';

            const roleMap = {admin:'Admin',workshop_manager:'Workshop Mgr',sales_person:'Sales',sales_officer:'Sales Officer',mechanic:'Mechanic',manager:'Manager'};
            el('giMemberList').innerHTML = d.members.map(m => {
                const isMe = m.id == d.my_id;
                const rl   = roleMap[m.role] || m.role;
                const canRemove = canManage && !isMe;
                const rmBtn = canRemove
                    ? `<button class="ic-btn" onclick="Chat.removeMember(${m.id})" title="Remove"><i class="fa fa-user-minus" style="color:#dc2626"></i></button>` : '';
                return `<div class="gi-member">
                    <div style="width:36px;height:36px;border-radius:50%;background:${avatarColor(m.id)};display:flex;align-items:center;justify-content:center;color:#fff;font-weight:700;font-size:14px;flex-shrink:0">${esc(initials(m.name))}</div>
                    <div class="gi-member-info">
                        <div class="gi-member-name">${esc(m.name)}${isMe ? ' <span style="color:#128c7e;font-size:12px">(you)</span>' : ''}</div>
                        <div class="gi-member-role">${esc(rl)}</div>
                    </div>
                    ${rmBtn}
                </div>`;
            }).join('');

            bootstrap.Modal.getOrCreateInstance(el('groupInfoModal')).show();
        } catch(e) { console.error('openGroupInfo', e); }
    },
    async addGroupMember() {
        const uid = parseInt(el('giAddSelect').value);
        if (!uid) return;
        try {
            const d = await apiPost('group.php', { action:'add_member', conversation_id: this.convId, user_id: uid });
            if (d.ok) { this.openGroupInfo(); this.loadConvs(); }
            else alert(d.error || 'Could not add member.');
        } catch(e) { console.error('addGroupMember', e); }
    },
    async removeMember(uid) {
        if (!confirm('Remove this member from the group?')) return;
        try {
            const d = await apiPost('group.php', { action:'remove_member', conversation_id: this.convId, user_id: uid });
            if (d.ok) { this.openGroupInfo(); this.loadConvs(); }
            else alert(d.error || 'Could not remove member.');
        } catch(e) { console.error('removeMember', e); }
    },
    async renameGroup() {
        const name = (el('giRenameInput').value || '').trim();
        if (!name) return;
        try {
            const d = await apiPost('group.php', { action:'rename', conversation_id: this.convId, name });
            if (d.ok) {
                this.convName = name;
                el('chName').textContent = name;
                bootstrap.Modal.getOrCreateInstance(el('groupInfoModal')).hide();
                this.loadConvs();
                await this._fetchMsgs(false);
            } else alert(d.error || 'Could not rename group.');
        } catch(e) { console.error('renameGroup', e); }
    },
    async leaveGroup() {
        if (!confirm('Leave this group? You will no longer receive messages.')) return;
        try {
            const d = await apiPost('group.php', { action:'leave', conversation_id: this.convId });
            if (d.ok) {
                bootstrap.Modal.getOrCreateInstance(el('groupInfoModal')).hide();
                clearInterval(this.pollTimer);
                this.convId = 0;
                hide(el('chatActive'));
                show(el('chatWelcome'));
                await this.loadConvs();
            } else alert(d.error || 'Could not leave group.');
        } catch(e) { console.error('leaveGroup', e); }
    },

    /* ── In-chat search ─────────────────────────────────────────────────────── */
    toggleSearch() {
        this.searchOpen = !this.searchOpen;
        const bar = el('chatSearchBar');
        if (this.searchOpen) {
            bar.style.display = '';
            el('searchInput').focus();
            el('searchInput').select();
        } else {
            bar.style.display = 'none';
            this.clearSearch();
        }
    },
    doSearch(q) {
        // Clear any existing highlights first
        el('chatMsgs').querySelectorAll('mark.sh').forEach(mark => {
            const parent = mark.parentNode;
            if (parent) {
                parent.replaceChild(document.createTextNode(mark.textContent), mark);
                parent.normalize();
            }
        });
        this.searchMatches = [];
        this.searchIdx = -1;

        if (!q || !q.trim()) { this._updateSearchCount(); return; }

        const safeQ   = q.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
        const re      = new RegExp('(' + safeQ + ')', 'gi');

        // Search only in text bubbles (safe — content is already HTML-escaped)
        el('chatMsgs').querySelectorAll('.b-text').forEach(textEl => {
            if (!textEl.textContent.toLowerCase().includes(q.toLowerCase())) return;
            textEl.innerHTML = textEl.innerHTML.replace(re, '<mark class="sh">$1</mark>');
            textEl.querySelectorAll('mark.sh').forEach(m => this.searchMatches.push(m));
        });

        if (this.searchMatches.length > 0) {
            this.searchIdx = 0;
            this._activateSearchMatch();
        }
        this._updateSearchCount();
    },
    searchNav(dir) {
        if (!this.searchMatches.length) return;
        this.searchMatches[this.searchIdx]?.classList.remove('active');
        this.searchIdx = (this.searchIdx + dir + this.searchMatches.length) % this.searchMatches.length;
        this._activateSearchMatch();
        this._updateSearchCount();
    },
    _activateSearchMatch() {
        const m = this.searchMatches[this.searchIdx];
        if (!m) return;
        m.classList.add('active');
        m.scrollIntoView({ behavior: 'smooth', block: 'center' });
    },
    _updateSearchCount() {
        const count = el('searchCount');
        const prev  = el('searchPrev');
        const next  = el('searchNext');
        if (!this.searchMatches.length) {
            count.textContent = el('searchInput').value ? '0 results' : '';
        } else {
            count.textContent = `${this.searchIdx + 1} / ${this.searchMatches.length}`;
        }
        if (prev) prev.disabled = this.searchMatches.length === 0;
        if (next) next.disabled = this.searchMatches.length === 0;
    },
    clearSearch() {
        if (!this.searchOpen) return;
        // Remove highlights
        el('chatMsgs')?.querySelectorAll('mark.sh').forEach(mark => {
            const parent = mark.parentNode;
            if (parent) {
                parent.replaceChild(document.createTextNode(mark.textContent), mark);
                parent.normalize();
            }
        });
        this.searchMatches = [];
        this.searchIdx = -1;
        const inp = el('searchInput');
        if (inp) inp.value = '';
        this._updateSearchCount?.();
        // Hide bar and flip flag
        const bar = el('chatSearchBar');
        if (bar) bar.style.display = 'none';
        this.searchOpen = false;
    },

    /* ── New chat modal tab switch ──────────────────────────────────────────── */
    switchNewChatTab(tab) {
        const isDirect = tab === 'direct';
        el('tabDirect').classList.toggle('active', isDirect);
        el('tabGroup').classList.toggle('active', !isDirect);
        el('ncDirect').style.display = isDirect ? '' : 'none';
        el('ncGroup').style.display  = isDirect ? 'none' : '';
        this._grpErr('');
    },

    async _checkIncoming() {
        if (this.activeCallId) return;
        try {
            const d=await apiPost('call.php',{action:'incoming'});
            if (d.incoming&&d.call&&!this.activeCallId) {
                const c=d.call; this.activeCallId=parseInt(c.id); this.pendingIce=[];
                this._showCall(c.caller_name, avatarColor(c.caller_id), c.call_type, true);
                el('callStat').textContent=`Incoming ${c.call_type} call…`;
                pingSound();
            }
        } catch {}
    },

    /* â”€â”€ Init â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ */
    init() {
        // .page-body has a CSS animation that keeps transform:translateY(0) applied,
        // creating a stacking context. Bootstrap's backdrop (appended to body after
        // .page-body) would paint over the modal inside that context. Moving the
        // modal to <body> puts it outside the stacking context so it layers correctly.
        // #newChatModal is already a direct child of <body> (rendered after footer.php).

        this.buildEmojiPicker();
        this.loadConvs();
        requestNotifPerm();
        setInterval(()=>this._checkIncoming(), 5000);

        // Conversation clicks
        el('convList').addEventListener('click', e=>{
            const item=e.target.closest('.conv-item');
            if (item) this.openConv(item.dataset.cid,item.dataset.cname,item.dataset.ccolor,item.dataset.callee,item.dataset.ctype,
                { online: item.dataset.online==='1', lastSeenDiff: item.dataset.lsd ? parseInt(item.dataset.lsd) : null });
        });

        // New chat
        el('btnNewChat').addEventListener('click',()=>bootstrap.Modal.getOrCreateInstance(el('newChatModal')).show());

        // User picker
        el('upList').addEventListener('click', e=>{
            const item=e.target.closest('.up-item');
            if (item) this.startDirect(item.dataset.uid,item.dataset.uname,item.dataset.ucolor);
        });

        // Emoji toggle
        el('btnEmoji').addEventListener('click',()=>this.toggleEmoji());

        // File attach
        el('btnAttach').addEventListener('click',()=>el('fileIn').click());
        el('fileIn').addEventListener('change',async ev=>{
            for (const f of Array.from(ev.target.files)) await this.uploadFile(f);
            ev.target.value='';
        });

        // Send button (paper-plane — always visible)
        el('sendBtn').addEventListener('click',()=>this.onSend());

        // Voice button (mic — visible only when input is empty)
        el('btnVoice').addEventListener('click',()=>this.startRec());

        // Text input
        el('msgIn').addEventListener('keydown', ev=>{
            if (ev.key==='Enter'&&!ev.shiftKey) { ev.preventDefault(); this.sendText(); }
        });
        el('msgIn').addEventListener('input',()=>{ this._syncBtn(); this.sendTyping(); });
        el('msgIn').addEventListener('keyup',()=>this._syncBtn());

        // Close emoji if click outside
        document.addEventListener('click', e=>{
            if (this.emojiOpen && !e.target.closest('#emojiPicker') && !e.target.closest('#btnEmoji'))
                this.toggleEmoji();
        });

        // Cancel recording
        el('recCancel').addEventListener('click',()=>this.cancelRec());

        // Call buttons
        el('btnCallA').addEventListener('click',()=>this.call('audio'));
        el('btnCallV').addEventListener('click',()=>this.call('video'));
        el('btnEnd').addEventListener('click',()=>this.hangup());
        el('btnMute').addEventListener('click',()=>this.toggleMute());
        el('btnCam').addEventListener('click',()=>this.toggleCam());
        el('btnAccept').addEventListener('click',()=>this.acceptCall());

        // Group info
        el('btnGroupInfo').addEventListener('click',()=>this.openGroupInfo());

        // Reply bar close
        el('replyBarClose').addEventListener('click',()=>this.clearReply());

        // Search toggle + input + nav
        el('btnSearch').addEventListener('click',()=>this.toggleSearch());
        el('searchClose').addEventListener('click',()=>this.clearSearch());
        el('searchPrev').addEventListener('click',()=>this.searchNav(-1));
        el('searchNext').addEventListener('click',()=>this.searchNav(1));
        el('searchInput').addEventListener('input', e=>this.doSearch(e.target.value));
        el('searchInput').addEventListener('keydown', ev=>{
            if (ev.key === 'Enter') { ev.shiftKey ? this.searchNav(-1) : this.searchNav(1); }
            if (ev.key === 'Escape') this.clearSearch();
        });

        // Mobile back
        el('chBack').addEventListener('click',()=>{
            hide(el('cpRight')); clearInterval(this.pollTimer);
        });

        // Scroll-to-bottom FAB
        el('chatMsgs').addEventListener('scroll', ()=>{
            const box=el('chatMsgs');
            const nearBottom = box.scrollHeight-box.scrollTop-box.clientHeight < 80;
            if (nearBottom) {
                hide(el('scrollFab'));
                this.scrollUnread=0; hide(el('fabBadge'));
            } else {
                // show FAB only if there are unread
            }
        });
        el('scrollFab').addEventListener('click',()=>{
            el('chatMsgs').scrollTop=el('chatMsgs').scrollHeight;
            hide(el('scrollFab')); this.scrollUnread=0; hide(el('fabBadge'));
        });

        // Messages area delegation (images, voice, action buttons, reply-preview scroll)
        el('chatMsgs').addEventListener('click', e=>{
            // Lightbox
            const img=e.target.closest('.b-img');
            if (img) { el('lbImg').src=img.dataset.src||img.src; el('lightbox').style.display='flex'; return; }
            // Voice play
            const pb=e.target.closest('.b-play');
            if (pb&&pb.dataset.src&&!pb.onclick) { this.playVoice(pb.dataset.src,pb.dataset.vid); return; }
            // Quick-react buttons and reaction pills
            const qr = e.target.closest('[data-act="react"]');
            if (qr) { this.react(qr.dataset.mid, qr.dataset.em); return; }

            // Message action bar
            const act=e.target.closest('.msg-act');
            if (act) {
                const mid = act.dataset.mid;
                const row = act.closest('.msg-row');
                if (act.dataset.act === 'reply' && row) {
                    // Build a message object from DOM to pass to setReply
                    const bubble = row.querySelector('.bubble');
                    const textEl = bubble?.querySelector('.b-text');
                    const isSent = row.classList.contains('s');
                    const senderName = isSent ? ME.name
                        : (row.querySelector('.b-sender')?.textContent || row.querySelector('.msg-av')?.title || 'Unknown');
                    const type = bubble?.querySelector('.b-img') ? 'image'
                               : bubble?.querySelector('.b-voice') ? 'voice'
                               : bubble?.querySelector('.b-file') ? 'file' : 'text';
                    const content = textEl?.textContent || '';
                    const fname = bubble?.querySelector('.b-file-nm')?.textContent || '';
                    this.setReply({ id: parseInt(mid), sender_name: senderName, type, content, file_name: fname });
                } else if (act.dataset.act === 'delete') {
                    this.deleteMsg(mid);
                } else if (act.dataset.act === 'copy') {
                    const bubble = row?.querySelector('.b-text');
                    if (bubble) navigator.clipboard?.writeText(bubble.textContent).catch(()=>{});
                }
                return;
            }
            // Reply preview scroll to original message
            const rp=e.target.closest('.reply-prev');
            if (rp && rp.dataset.scroll) {
                const target = el('chatMsgs').querySelector(`[data-mid="${rp.dataset.scroll}"]`);
                if (target) target.scrollIntoView({ behavior:'smooth', block:'center' });
            }
        });

        // Lightbox
        el('lbClose').addEventListener('click',()=>{ hide(el('lightbox')); el('lbImg').src=''; });
        el('lightbox').addEventListener('click', e=>{ if(e.target===e.currentTarget){hide(el('lightbox'));el('lbImg').src='';} });

        // Searches
        el('convSearch').addEventListener('input',function(){
            const q=this.value.toLowerCase();
            document.querySelectorAll('.conv-item').forEach(el=>{
                el.style.display=!q||(el.dataset.cname||'').toLowerCase().includes(q)?'':'none';
            });
        });
        el('userSearch').addEventListener('input',function(){
            const q=this.value.toLowerCase();
            document.querySelectorAll('#upList .up-item').forEach(el=>{
                el.style.display=!q||(el.dataset.uname||'').toLowerCase().includes(q)?'':'none';
            });
        });

        // Group tab: member search + live selected count
        el('grpSearch').addEventListener('input',function(){
            const q=this.value.toLowerCase();
            document.querySelectorAll('#grpUserList .up-item').forEach(item=>{
                item.style.display=!q||(item.dataset.uname||'').toLowerCase().includes(q)?'':'none';
            });
        });
        el('grpUserList').addEventListener('change',()=>{
            const n=document.querySelectorAll('.grp-chk:checked').length;
            el('grpSelCount').textContent = n + ' member' + (n!==1?'s':'') + ' selected';
        });

        // ESC
        document.addEventListener('keydown', ev=>{
            if (ev.key!=='Escape') return;
            hide(el('lightbox'));
            if (this.activeCallId) this.hangup();
        });
    },
};

document.addEventListener('DOMContentLoaded', () => Chat.init());
</script>

<?php
// NEW CHAT MODAL — rendered as direct child of <body> (via footer.php $extraModal hook)
// This places it OUTSIDE the .page-body stacking context, fixing the black-overlay bug.
ob_start(); ?>
<!-- â”€â”€ NEW CHAT MODAL â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€â”€ -->
<div class="modal fade" id="newChatModal" tabindex="-1">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <div class="modal-header py-2 border-bottom-0 pb-0">
                <div>
                    <h6 class="modal-title fw-bold mb-0">New Conversation</h6>
                    <p class="text-muted mb-0" style="font-size:12px">Direct message or create a group</p>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-2">
                <!-- Tabs -->
                <div class="px-2 pb-1">
                    <div class="nc-tabs">
                        <button class="nc-tab active" id="tabDirect" onclick="Chat.switchNewChatTab('direct')">
                            <i class="fa fa-user me-1"></i>Direct
                        </button>
                        <button class="nc-tab" id="tabGroup" onclick="Chat.switchNewChatTab('group')">
                            <i class="fa fa-users me-1"></i>New Group
                        </button>
                    </div>
                </div>
                <!-- Direct tab -->
                <div id="ncDirect">
                <div class="px-2 pb-2">
                    <div style="position:relative">
                        <i class="fa fa-magnifying-glass" style="position:absolute;left:10px;top:50%;transform:translateY(-50%);color:#8696a0;font-size:13px;pointer-events:none"></i>
                        <input type="text" id="userSearch"
                               class="form-control form-control-sm"
                               style="padding-left:32px;background:#f0f2f5;border:none;border-radius:8px"
                               placeholder="Search people..." autocomplete="off">
                    </div>
                </div>
                <div class="up-list" id="upList">
                <?php foreach ($allUsers as $u):
                    $init  = mb_strtoupper(mb_substr($u['name'], 0, 1));
                    $color = $palette[$u['id'] % count($palette)];
                    $rl    = $roleLabels[$u['role']] ?? ucfirst($u['role']);
                ?>
                    <div class="up-item"
                         data-uid="<?= (int)$u['id'] ?>"
                         data-uname="<?= e($u['name']) ?>"
                         data-ucolor="<?= e($color) ?>"
                         style="cursor:pointer">
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
                </div><!-- /ncDirect -->

                <!-- Group tab -->
                <div id="ncGroup" style="display:none;padding:0 8px 8px">
                    <div class="mb-3">
                        <label class="form-label fw-semibold" style="font-size:12px">Group Name</label>
                        <input type="text" id="grpName" class="form-control form-control-sm"
                               placeholder="e.g. Workshop Team" maxlength="80">
                    </div>
                    <div class="mb-2">
                        <label class="form-label fw-semibold" style="font-size:12px">Select Members</label>
                        <div style="position:relative;margin-bottom:6px">
                            <i class="fa fa-magnifying-glass" style="position:absolute;left:10px;top:50%;transform:translateY(-50%);color:#8696a0;font-size:13px;pointer-events:none"></i>
                            <input type="text" id="grpSearch" class="form-control form-control-sm"
                                   style="padding-left:32px;background:#f0f2f5;border:none;border-radius:8px"
                                   placeholder="Search..." autocomplete="off">
                        </div>
                        <div class="up-list" id="grpUserList" style="max-height:200px">
                        <?php foreach ($allUsers as $u):
                            $init  = mb_strtoupper(mb_substr($u['name'], 0, 1));
                            $color = $palette[$u['id'] % count($palette)];
                            $rl    = $roleLabels[$u['role']] ?? ucfirst($u['role']);
                        ?>
                            <label class="up-item" style="cursor:pointer;margin:0" data-uname="<?= e($u['name']) ?>">
                                <input type="checkbox" class="grp-chk me-2" value="<?= (int)$u['id'] ?>" style="flex-shrink:0;width:15px;height:15px">
                                <div class="up-av" style="background:<?= $color ?>"><?= e($init) ?></div>
                                <div>
                                    <div class="up-name"><?= e($u['name']) ?></div>
                                    <div class="up-role"><?= e($rl) ?></div>
                                </div>
                            </label>
                        <?php endforeach; ?>
                        </div>
                    </div>
                    <div id="grpSelCount" style="font-size:12px;color:#667781;margin-bottom:6px">0 members selected</div>
                    <div id="grpError" style="display:none;font-size:12px;color:#dc2626;margin-bottom:8px;padding:6px 10px;background:#fef2f2;border-radius:6px;border:1px solid #fecaca"></div>
                    <button id="grpCreateBtn" class="btn btn-success btn-sm w-100" onclick="Chat.createGroup()">
                        <i class="fa fa-users me-1"></i>Create Group
                    </button>
                </div>

            </div>
        </div>
    </div>
</div>

<!-- ── GROUP INFO MODAL ──────────────────────────────────────────────────── -->
<div class="modal fade" id="groupInfoModal" tabindex="-1">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <div class="modal-header py-2">
                <div style="display:flex;align-items:center;gap:10px">
                    <div id="giAvatar" style="width:40px;height:40px;border-radius:50%;background:#128c7e;display:flex;align-items:center;justify-content:center;color:#fff;font-weight:700;font-size:15px;flex-shrink:0"></div>
                    <div>
                        <h6 class="modal-title fw-bold mb-0" id="giGroupName">Group</h6>
                        <div class="text-muted" id="giMemberCount" style="font-size:12px"></div>
                    </div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-3">
                <div id="giRenameRow" style="display:none;margin-bottom:14px">
                    <label class="form-label fw-semibold" style="font-size:12px">Rename Group</label>
                    <div style="display:flex;gap:8px">
                        <input type="text" id="giRenameInput" class="form-control form-control-sm" placeholder="New name...">
                        <button class="btn btn-sm btn-outline-success" onclick="Chat.renameGroup()">Save</button>
                    </div>
                </div>
                <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:1px;color:#8696a0;margin-bottom:8px">Members</div>
                <div id="giMemberList" style="max-height:260px;overflow-y:auto"></div>
                <div id="giAddRow" style="display:none;margin-top:14px">
                    <label class="form-label fw-semibold" style="font-size:12px">Add Member</label>
                    <div style="display:flex;gap:8px">
                        <select id="giAddSelect" class="form-select form-select-sm">
                            <option value="">— Select user —</option>
                            <?php foreach ($allUsers as $u): ?>
                            <option value="<?= (int)$u['id'] ?>"><?= e($u['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button class="btn btn-sm btn-success" onclick="Chat.addGroupMember()"><i class="fa fa-plus"></i></button>
                    </div>
                </div>
            </div>
            <div class="modal-footer py-2">
                <button class="btn btn-sm btn-outline-danger ms-auto" onclick="Chat.leaveGroup()">
                    <i class="fa fa-door-open me-1"></i>Leave Group
                </button>
            </div>
        </div>
    </div>
</div>

<script>
// Direct-chat opening is handled entirely by Chat.startDirect() which is wired
// in Chat.init() via the upList click listener. No duplicate handler needed here.
</script>
<?php $extraModal = ob_get_clean(); ?>

<?php include __DIR__ . '/../../includes/footer.php'; ?>



<?php
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();

$db  = getDB();
$me  = authUser();

// Ensure tables exist silently
foreach ([
    "CREATE TABLE IF NOT EXISTS wa_config (id INT AUTO_INCREMENT PRIMARY KEY, instance_id VARCHAR(50) NOT NULL DEFAULT '', api_token VARCHAR(100) NOT NULL DEFAULT '', is_connected TINYINT(1) DEFAULT 0, phone_number VARCHAR(30) NULL, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP)",
    "CREATE TABLE IF NOT EXISTS wa_conversations (id INT AUTO_INCREMENT PRIMARY KEY, chat_id VARCHAR(50) NOT NULL, contact_name VARCHAR(150) NULL, contact_phone VARCHAR(30) NULL, client_id INT NULL, last_message TEXT NULL, last_message_at TIMESTAMP NULL, unread_count INT DEFAULT 0, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, UNIQUE KEY uniq_chat_id (chat_id))",
    "CREATE TABLE IF NOT EXISTS wa_messages (id INT AUTO_INCREMENT PRIMARY KEY, conversation_id INT NOT NULL, message_id VARCHAR(100) NULL, direction ENUM('in','out') DEFAULT 'out', type ENUM('text','image','document','audio','video','other') DEFAULT 'text', body TEXT NULL, media_url VARCHAR(500) NULL, sent_by INT NULL, sent_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, is_read TINYINT(1) DEFAULT 0, UNIQUE KEY uniq_msg_id (message_id))",
] as $sql) { try { $db->exec($sql); } catch (\Throwable $_) {} }

$waConfig    = $db->query("SELECT * FROM wa_config LIMIT 1")->fetch() ?: [];
$waConnected = (bool)($waConfig['is_connected'] ?? false);

// Initial conversation list (PHP-rendered for fast first paint; JS refreshes it)
try {
    $conversations = $db->query("
        SELECT wc.*,
               cl.name AS client_full_name,
               (SELECT body      FROM wa_messages WHERE conversation_id = wc.id ORDER BY sent_at DESC LIMIT 1) AS last_msg,
               (SELECT sent_at   FROM wa_messages WHERE conversation_id = wc.id ORDER BY sent_at DESC LIMIT 1) AS last_msg_at,
               (SELECT direction FROM wa_messages WHERE conversation_id = wc.id ORDER BY sent_at DESC LIMIT 1) AS last_msg_dir
        FROM wa_conversations wc
        LEFT JOIN clients cl ON cl.id = wc.client_id
        ORDER BY COALESCE(
            (SELECT sent_at FROM wa_messages WHERE conversation_id = wc.id ORDER BY sent_at DESC LIMIT 1),
            wc.last_message_at, wc.created_at
        ) DESC
        LIMIT 300
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (\Throwable $_) { $conversations = []; }

// Relative time helper
function waRelTime(?string $dt): string {
    if (!$dt) return '';
    $ts   = strtotime($dt);
    $diff = time() - $ts;
    if ($diff < 60)          return 'Just now';
    if ($diff < 3600)        return floor($diff / 60) . 'm';
    if (date('Y-m-d', $ts) === date('Y-m-d')) return date('H:i', $ts);
    if ($diff < 86400 * 2)   return 'Yesterday';
    if ($diff < 86400 * 7)   return date('D', $ts);
    return date('d/m/y', $ts);
}

// Avatar colour (deterministic)
function waAvatarColor(string $s): string {
    $p = ['#2563eb','#16a34a','#dc2626','#9333ea','#f59e0b','#0891b2','#db2777','#65a30d'];
    return $p[abs(crc32($s)) % count($p)];
}

$pageTitle = 'WhatsApp';
include __DIR__ . '/../../includes/header.php';
?>
<style>
/* ═══════════════════════════════════════════════════════════════
   WhatsApp Web exact UI
═══════════════════════════════════════════════════════════════ */

/* override page-body padding so the WA root can touch the edges */
.page-body { padding: 0 !important; }

.wa-root {
    display: flex;
    height: calc(100vh - 60px);
    overflow: hidden;
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', 'Helvetica Neue', Arial, sans-serif;
}

/* ── Left panel ───────────────────────────────────────────── */
.wa-left {
    width: 360px;
    min-width: 360px;
    display: flex;
    flex-direction: column;
    background: #111b21;
    border-right: 1px solid #222d35;
    overflow: hidden;
    position: relative;
    z-index: 1;
}
@media (max-width: 768px) {
    .wa-left  { width: 100%; min-width: 0; }
    .wa-right { display: none; position: absolute; inset: 0; z-index: 2; }
    .wa-root.chat-open .wa-left  { display: none; }
    .wa-root.chat-open .wa-right { display: flex; }
}

/* Left header */
.wa-lhdr {
    background: #202c33;
    padding: 10px 16px;
    display: flex;
    align-items: center;
    gap: 12px;
    flex-shrink: 0;
}
.wa-lhdr-av {
    width: 40px; height: 40px;
    border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-size: 16px; font-weight: 700; color: #fff; flex-shrink: 0;
    cursor: pointer;
}
.wa-lhdr-title {
    flex: 1;
    font-size: 17px; font-weight: 600;
    color: #e9edef;
}
.wa-lhdr-actions { display: flex; gap: 4px; }
.wa-icon-btn {
    width: 36px; height: 36px;
    border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    background: none; border: none; cursor: pointer;
    color: #aebac1; font-size: 16px;
    transition: background .15s;
}
.wa-icon-btn:hover { background: rgba(255,255,255,.08); }

/* Search */
.wa-search-wrap {
    padding: 6px 12px 8px;
    background: #111b21;
    flex-shrink: 0;
}
.wa-search-inner {
    display: flex; align-items: center; gap: 8px;
    background: #2a3942;
    border-radius: 8px;
    padding: 6px 12px;
}
.wa-search-inner i { color: #8696a0; font-size: 14px; flex-shrink: 0; }
.wa-search-inner input {
    background: none; border: none; outline: none;
    color: #e9edef; font-size: 14px; flex: 1;
}
.wa-search-inner input::placeholder { color: #8696a0; }

/* Conversation list */
.wa-conv-list {
    flex: 1; overflow-y: auto; overflow-x: hidden;
}
.wa-conv-list::-webkit-scrollbar { width: 5px; }
.wa-conv-list::-webkit-scrollbar-track { background: transparent; }
.wa-conv-list::-webkit-scrollbar-thumb { background: #374045; border-radius: 3px; }

.wa-conv-item {
    display: flex; align-items: center; gap: 13px;
    padding: 10px 16px;
    border-bottom: 1px solid rgba(134,150,160,.1);
    cursor: pointer;
    transition: background .08s;
    min-width: 0;
    position: relative;
}
.wa-conv-item:hover  { background: #202c33; }
.wa-conv-item.active { background: #2a3942; }

.wa-conv-av {
    width: 49px; height: 49px;
    border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-size: 20px; font-weight: 600; color: #fff;
    flex-shrink: 0;
}

.wa-conv-body { flex: 1; min-width: 0; }
.wa-conv-row1 {
    display: flex; justify-content: space-between; align-items: baseline;
    margin-bottom: 3px;
}
.wa-conv-name {
    font-size: 15px; font-weight: 400; color: #e9edef;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.wa-conv-time { font-size: 11.5px; color: #8696a0; white-space: nowrap; flex-shrink: 0; margin-left: 4px; }
.wa-conv-time.unread { color: #00a884; }

.wa-conv-row2 { display: flex; align-items: center; gap: 4px; }
.wa-conv-preview {
    font-size: 13.5px; color: #8696a0;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
    flex: 1;
}
.wa-conv-badge {
    background: #00a884; color: #fff;
    border-radius: 50%; min-width: 20px; height: 20px;
    display: flex; align-items: center; justify-content: center;
    font-size: 11px; font-weight: 700;
    flex-shrink: 0; padding: 0 5px;
}
.wa-ticks { color: #8696a0; font-size: 12px; flex-shrink: 0; }
.wa-ticks.read { color: #53bdeb; }

.wa-conv-empty {
    padding: 40px 24px;
    text-align: center;
    color: #8696a0;
    font-size: 13.5px;
    line-height: 1.6;
}

/* ── Right panel ──────────────────────────────────────────── */
.wa-right {
    flex: 1;
    display: flex;
    flex-direction: column;
    min-width: 0;
    position: relative;
}

/* Welcome screen */
.wa-welcome {
    flex: 1;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    background: #f0f2f5;
    user-select: none;
}
[data-theme="dark"] .wa-welcome { background: #222e35; }
.wa-welcome-icon {
    width: 200px; height: 200px;
    border-radius: 50%;
    background: #d9fdd3;
    display: flex; align-items: center; justify-content: center;
    margin-bottom: 32px;
    opacity: .85;
}
.wa-welcome-icon i { font-size: 88px; color: #00a884; }
.wa-welcome h3 { font-size: 30px; font-weight: 300; color: #41525d; margin-bottom: 12px; }
[data-theme="dark"] .wa-welcome h3 { color: #e9edef; }
.wa-welcome p { font-size: 14px; color: #667781; max-width: 400px; text-align: center; line-height: 1.6; }
[data-theme="dark"] .wa-welcome p { color: #8696a0; }
.wa-welcome-lock { margin-top: 32px; font-size: 13px; color: #8696a0; display: flex; align-items: center; gap: 6px; }

/* Active chat layout */
.wa-chat {
    flex: 1;
    display: none;
    flex-direction: column;
    min-width: 0;
    height: 100%;
}

/* Chat header */
.wa-chat-hdr {
    background: #202c33;
    padding: 10px 16px;
    display: flex;
    align-items: center;
    gap: 12px;
    flex-shrink: 0;
    cursor: pointer;
}
.wa-chat-hdr-av {
    width: 40px; height: 40px;
    border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-size: 16px; font-weight: 700; color: #fff; flex-shrink: 0;
}
.wa-chat-hdr-info { flex: 1; min-width: 0; }
.wa-chat-hdr-name {
    font-size: 15px; font-weight: 600; color: #e9edef;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.wa-chat-hdr-sub { font-size: 12px; color: #8696a0; }
.wa-chat-hdr-actions { display: flex; gap: 4px; }

/* Messages area */
.wa-msgs-wrap {
    flex: 1;
    overflow-y: auto;
    overflow-x: hidden;
    padding: 12px 6%;
    display: flex;
    flex-direction: column;
    gap: 1px;
    /* WhatsApp Web chat background */
    background-color: #efeae2;
    background-image: url("data:image/svg+xml,%3Csvg width='60' height='60' viewBox='0 0 60 60' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='none' fill-rule='evenodd'%3E%3Cg fill='%23b8c5cb' fill-opacity='0.12'%3E%3Cpath d='M36 34v-4h-2v4h-4v2h4v4h2v-4h4v-2h-4zm0-30V0h-2v4h-4v2h4v4h2V6h4V4h-4zM6 34v-4H4v4H0v2h4v4h2v-4h4v-2H6zM6 4V0H4v4H0v2h4v4h2V6h4V4H6z'/%3E%3C/g%3E%3C/g%3E%3C/svg%3E");
}
[data-theme="dark"] .wa-msgs-wrap {
    background-color: #0d1117;
    background-image: none;
}
.wa-msgs-wrap::-webkit-scrollbar { width: 6px; }
.wa-msgs-wrap::-webkit-scrollbar-thumb { background: rgba(0,0,0,.2); border-radius: 3px; }

/* Day separator */
.wa-day-sep {
    display: flex; align-items: center; justify-content: center;
    margin: 12px 0 6px;
}
.wa-day-sep span {
    background: #d1f4cc;
    color: #54656f;
    font-size: 12px; font-weight: 500;
    border-radius: 8px;
    padding: 4px 10px;
    box-shadow: 0 1px 1px rgba(0,0,0,.12);
}
[data-theme="dark"] .wa-day-sep span { background: #202c33; color: #8696a0; }

/* Message rows */
.wa-msg-row { display: flex; margin: 1px 0; }
.wa-msg-row-in  { justify-content: flex-start; }
.wa-msg-row-out { justify-content: flex-end; }

/* Bubbles */
.wa-bbl {
    max-width: min(65%, 520px);
    padding: 6px 7px 8px 9px;
    box-shadow: 0 1px .5px rgba(0,0,0,.15);
    word-break: break-word;
    position: relative;
}
.wa-bbl-in {
    background: #fff;
    border-radius: 0 7.5px 7.5px 7.5px;
    margin-left: 4px;
}
.wa-bbl-out {
    background: #d9fdd3;
    border-radius: 7.5px 0 7.5px 7.5px;
    margin-right: 4px;
}
[data-theme="dark"] .wa-bbl-in  { background: #202c33; }
[data-theme="dark"] .wa-bbl-out { background: #005c4b; }

/* Bubble tails */
.wa-bbl-in::before {
    content: '';
    position: absolute;
    top: 0; left: -6px;
    width: 0; height: 0;
    border-style: solid;
    border-width: 7px 7px 0 0;
    border-color: #fff transparent transparent transparent;
}
.wa-bbl-out::after {
    content: '';
    position: absolute;
    top: 0; right: -6px;
    width: 0; height: 0;
    border-style: solid;
    border-width: 7px 0 0 7px;
    border-color: #d9fdd3 transparent transparent transparent;
}
[data-theme="dark"] .wa-bbl-in::before  { border-color: #202c33 transparent transparent transparent; }
[data-theme="dark"] .wa-bbl-out::after  { border-color: #005c4b transparent transparent transparent; }

.wa-msg-text {
    font-size: 14.2px;
    line-height: 1.45;
    color: #111b21;
}
[data-theme="dark"] .wa-msg-text { color: #e9edef; }
.wa-msg-media-tag {
    font-size: 12.5px; color: #667781; margin-bottom: 3px;
    display: flex; align-items: center; gap: 5px;
}
.wa-msg-meta {
    display: flex;
    justify-content: flex-end;
    align-items: center;
    gap: 3px;
    margin-top: 2px;
    float: right;
    margin-left: 8px;
}
.wa-msg-time { font-size: 11px; color: #667781; white-space: nowrap; }
[data-theme="dark"] .wa-msg-time { color: #8696a0; }
.wa-check { font-size: 11px; }
.wa-check-grey { color: #667781; }
.wa-check-blue  { color: #53bdeb; }

/* No-messages placeholder */
.wa-msgs-placeholder {
    flex: 1;
    display: flex; align-items: center; justify-content: center;
    flex-direction: column; gap: 8px;
    color: #8696a0; font-size: 14px;
}

/* Loading state */
.wa-msgs-loading {
    display: flex; align-items: center; justify-content: center;
    padding: 40px 0; color: #8696a0; font-size: 14px; gap: 10px;
}

/* Input bar */
.wa-input-bar {
    background: #202c33;
    padding: 8px 16px;
    display: flex;
    align-items: flex-end;
    gap: 8px;
    flex-shrink: 0;
}
.wa-input-wrap {
    flex: 1;
    background: #2a3942;
    border-radius: 8px;
    padding: 8px 12px;
    display: flex;
    align-items: flex-end;
    min-height: 42px;
}
.wa-input-wrap textarea {
    background: none; border: none; outline: none;
    resize: none; flex: 1;
    font-size: 15px; line-height: 1.5;
    color: #e9edef; max-height: 140px;
    font-family: inherit;
}
.wa-input-wrap textarea::placeholder { color: #8696a0; }
.wa-send-btn {
    width: 52px; height: 52px;
    border-radius: 50%;
    background: #00a884;
    border: none; cursor: pointer;
    display: flex; align-items: center; justify-content: center;
    color: #fff; font-size: 20px;
    flex-shrink: 0;
    transition: background .15s;
}
.wa-send-btn:hover { background: #008069; }
.wa-send-btn:disabled { background: #374045; cursor: default; }

/* Mobile back button */
.wa-back-btn { display: none; }
@media (max-width: 768px) {
    .wa-back-btn { display: flex; }
}

/* Toast notifications */
@keyframes waToastIn {
    from { opacity: 0; transform: translateY(12px); }
    to   { opacity: 1; transform: translateY(0); }
}
.wa-toast {
    background: #202c33;
    border-left: 3px solid #00a884;
    border-radius: 8px;
    padding: 10px 14px;
    max-width: 320px;
    box-shadow: 0 4px 16px rgba(0,0,0,.45);
    cursor: pointer;
    pointer-events: all;
    display: flex;
    gap: 10px;
    align-items: flex-start;
    animation: waToastIn .2s ease;
    transition: opacity .3s;
}
.wa-toast-av {
    width: 36px; height: 36px;
    border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    color: #fff; font-weight: 700; font-size: 14px; flex-shrink: 0;
}
.wa-toast-name  { color: #e9edef; font-weight: 600; font-size: 13px; margin-bottom: 2px; }
.wa-toast-prev  { color: #8696a0; font-size: 12px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 250px; }

/* Disconnected banner */
.wa-disc-banner {
    background: #2a3942;
    color: #e9edef;
    font-size: 12.5px;
    text-align: center;
    padding: 8px 16px;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    flex-shrink: 0;
}
</style>

<div class="wa-root" id="waRoot">

    <!-- ══ LEFT PANEL ══════════════════════════════════════════════ -->
    <div class="wa-left" id="waLeft">

        <!-- Header -->
        <div class="wa-lhdr">
            <?php
            $myInitial = strtoupper(mb_substr($me['name'], 0, 1));
            $myColor   = waAvatarColor($me['name']);
            ?>
            <div class="wa-lhdr-av" style="background:<?= $myColor ?>" title="<?= e($me['name']) ?>">
                <?= $myInitial ?>
            </div>
            <div class="wa-lhdr-title">WhatsApp</div>
            <div class="wa-lhdr-actions">
                <?php if (hasRole(['admin','general_manager'])): ?>
                <a href="<?= BASE_URL ?>/modules/whatsapp/admin.php" class="wa-icon-btn" title="Settings">
                    <i class="fa fa-gear"></i>
                </a>
                <?php endif; ?>
                <button class="wa-icon-btn" id="btnRefreshConvs" title="Refresh conversations" onclick="WA.loadConvs()">
                    <i class="fa fa-arrows-rotate"></i>
                </button>
            </div>
        </div>

        <!-- Search -->
        <div class="wa-search-wrap">
            <div class="wa-search-inner">
                <i class="fa fa-magnifying-glass"></i>
                <input type="text" id="waSearch" placeholder="Search or start new chat" autocomplete="off">
            </div>
        </div>

        <!-- Not-connected banner -->
        <?php if (!$waConnected): ?>
        <div class="wa-disc-banner">
            <i class="fa fa-circle-exclamation" style="color:#f97316"></i>
            WhatsApp disconnected —
            <?php if (hasRole(['admin','general_manager'])): ?>
            <a href="<?= BASE_URL ?>/modules/whatsapp/admin.php" style="color:#00a884">Setup</a>
            <?php else: ?>
            ask your admin to reconnect.
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- Conversation list -->
        <div class="wa-conv-list" id="waConvList">
            <?php if (empty($conversations)): ?>
            <div class="wa-conv-empty">
                <i class="fab fa-whatsapp" style="font-size:40px;color:#374045;display:block;margin-bottom:10px"></i>
                No conversations yet.<br>
                <?php if ($waConnected): ?>
                <a href="<?= BASE_URL ?>/modules/whatsapp/admin.php" style="color:#00a884;text-decoration:none;font-size:12px">
                    Import existing conversations →
                </a>
                <?php endif; ?>
            </div>
            <?php else: ?>
            <?php foreach ($conversations as $c):
                $name    = $c['client_full_name'] ?: ($c['contact_name'] ?: ($c['contact_phone'] ?: $c['chat_id']));
                $initial = strtoupper(mb_substr($name, 0, 1));
                $color   = waAvatarColor($name);
                $preview = $c['last_msg'] ?? $c['last_message'] ?? '';
                $previewTrunc = mb_substr($preview, 0, 45) . (mb_strlen($preview) > 45 ? '…' : '');
                $timeStr = waRelTime($c['last_msg_at'] ?? $c['last_message_at'] ?? $c['updated_at'] ?? null);
                $unread  = (int)($c['unread_count'] ?? 0);
                $isOut   = ($c['last_msg_dir'] ?? '') === 'out';
            ?>
            <div class="wa-conv-item"
                 data-id="<?= (int)$c['id'] ?>"
                 data-name="<?= e($name) ?>"
                 data-color="<?= e($color) ?>"
                 data-phone="<?= e($c['contact_phone'] ?? '') ?>"
                 data-chatid="<?= e($c['chat_id']) ?>"
                 onclick="WA.openChat(<?= (int)$c['id'] ?>)">
                <div class="wa-conv-av" style="background:<?= $color ?>">
                    <?= e($initial) ?>
                </div>
                <div class="wa-conv-body">
                    <div class="wa-conv-row1">
                        <span class="wa-conv-name"><?= e($name) ?></span>
                        <span class="wa-conv-time <?= $unread ? 'unread' : '' ?>"><?= e($timeStr) ?></span>
                    </div>
                    <div class="wa-conv-row2">
                        <?php if ($isOut): ?>
                        <span class="wa-ticks"><i class="fa fa-check-double"></i></span>
                        <?php endif; ?>
                        <span class="wa-conv-preview"><?= e($previewTrunc ?: 'Tap to view') ?></span>
                        <?php if ($unread): ?>
                        <span class="wa-conv-badge"><?= $unread > 99 ? '99+' : $unread ?></span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>

    </div><!-- /wa-left -->


    <!-- ══ RIGHT PANEL ════════════════════════════════════════════ -->
    <div class="wa-right" id="waRight">

        <!-- Welcome (no conversation selected) -->
        <div class="wa-welcome" id="waWelcome">
            <div class="wa-welcome-icon">
                <i class="fab fa-whatsapp"></i>
            </div>
            <h3>Mascardi WhatsApp Inbox</h3>
            <p>Select a conversation on the left to start messaging your clients, or import existing conversations from WhatsApp.</p>
            <div class="wa-welcome-lock">
                <i class="fa fa-lock"></i> End-to-end encrypted by WhatsApp
            </div>
        </div>

        <!-- Active chat (shown when a conversation is opened) -->
        <div class="wa-chat" id="waChat">

            <!-- Chat header -->
            <div class="wa-chat-hdr" id="waChatHdr">
                <!-- Back button (mobile) -->
                <button class="wa-icon-btn wa-back-btn" onclick="WA.closeChat()" title="Back">
                    <i class="fa fa-arrow-left"></i>
                </button>
                <div class="wa-chat-hdr-av" id="waChatAv"></div>
                <div class="wa-chat-hdr-info">
                    <div class="wa-chat-hdr-name" id="waChatName"></div>
                    <div class="wa-chat-hdr-sub"  id="waChatSub"></div>
                </div>
                <div class="wa-chat-hdr-actions">
                    <button class="wa-icon-btn" id="btnLoadHistChat" onclick="WA.loadHistory()" title="Load message history from WhatsApp">
                        <i class="fa fa-clock-rotate-left"></i>
                    </button>
                    <a id="waOpenWaBtn" href="#" target="_blank" class="wa-icon-btn" title="Open in WhatsApp app">
                        <i class="fab fa-whatsapp"></i>
                    </a>
                    <a id="waChatViewBtn" href="#" class="wa-icon-btn" title="View full conversation page">
                        <i class="fa fa-arrow-up-right-from-square"></i>
                    </a>
                </div>
            </div>

            <!-- Messages -->
            <div class="wa-msgs-wrap" id="waMsgsWrap">
                <div class="wa-msgs-loading" id="waMsgsLoading" style="display:none">
                    <i class="fa fa-spinner fa-spin"></i> Loading messages…
                </div>
                <div id="waMsgs"></div>
            </div>

            <!-- Input bar -->
            <div class="wa-input-bar">
                <div class="wa-input-wrap">
                    <textarea id="waInput" rows="1" placeholder="Type a message"></textarea>
                </div>
                <button class="wa-send-btn" id="waSendBtn" title="Send">
                    <i class="fa fa-paper-plane" style="font-size:17px"></i>
                </button>
            </div>

        </div><!-- /wa-chat -->

    </div><!-- /wa-right -->
</div><!-- /wa-root -->

<!-- Toast notification container (fixed, bottom-left of chat area) -->
<div id="waToastContainer"
     style="position:fixed;bottom:20px;left:380px;z-index:9999;
            display:flex;flex-direction:column;gap:8px;pointer-events:none;
            max-width:340px"></div>

<script>
var BASE     = <?= json_encode(rtrim(BASE_URL, '/')) ?>;
var ME_NAME  = <?= json_encode($me['name']) ?>;
var WA_CONN  = <?= $waConnected ? 'true' : 'false' ?>;

var WA = {
    convId:       0,
    convChatId:   '',
    convName:     '',
    convColor:    '#2563eb',
    convPhone:    '',
    lastMsgId:    0,
    chatPollTimer: null,
    convTimer:    null,

    /* ── Init ─────────────────────────────────────────────── */
    init() {
        this.bindSearch();
        this.bindSend();

        // Auto-open from URL ?id=
        var urlId = parseInt(new URLSearchParams(location.search).get('id') || 0);
        if (urlId) this.openChat(urlId);

        // Refresh conversation list every 15 s
        this.convTimer = setInterval(() => this.loadConvs(), 15000);

        // Global Green API poll (even when no chat open) every 12 s
        setInterval(() => this.globalPoll(), 12000);
    },

    /* ── Load / render conversation list ─────────────────── */
    async loadConvs() {
        try {
            const d = await fetch(BASE + '/modules/whatsapp/api/convs.php').then(r => r.json());
            this.renderConvs(d.conversations || []);
        } catch(e) {}
    },

    renderConvs(convs) {
        const list = document.getElementById('waConvList');
        if (!convs.length) {
            list.innerHTML = '<div class="wa-conv-empty"><i class="fab fa-whatsapp" style="font-size:40px;color:#374045;display:block;margin-bottom:10px"></i>No conversations yet.</div>';
            return;
        }
        list.innerHTML = convs.map(c => {
            var name    = c.client_full_name || c.contact_name || c.contact_phone || c.chat_id;
            var initial = (name || '?').charAt(0).toUpperCase();
            var color   = this.avatarColor(name || '');
            var preview = c.last_msg || c.last_message || '';
            if (preview.length > 45) preview = preview.substring(0, 45) + '…';
            var timeStr = this.relTime(c.last_msg_at || c.last_message_at || c.updated_at);
            var unread  = parseInt(c.unread_count) || 0;
            var isOut   = c.last_msg_dir === 'out';
            var active  = this.convId == c.id ? ' active' : '';
            var ticks   = isOut ? '<span class="wa-ticks"><i class="fa fa-check-double"></i></span>' : '';
            var badge   = unread ? '<span class="wa-conv-badge">' + (unread > 99 ? '99+' : unread) + '</span>' : '';
            return '<div class="wa-conv-item' + active + '" data-id="' + c.id + '" data-name="' + this.esc(name) + '" data-color="' + color + '" data-phone="' + this.esc(c.contact_phone || '') + '" data-chatid="' + this.esc(c.chat_id) + '" onclick="WA.openChat(' + parseInt(c.id) + ')">'
                + '<div class="wa-conv-av" style="background:' + color + '">' + initial + '</div>'
                + '<div class="wa-conv-body">'
                + '<div class="wa-conv-row1"><span class="wa-conv-name">' + this.esc(name) + '</span>'
                + '<span class="wa-conv-time' + (unread ? ' unread' : '') + '">' + timeStr + '</span></div>'
                + '<div class="wa-conv-row2">' + ticks + '<span class="wa-conv-preview">' + this.esc(preview || 'Tap to view') + '</span>' + badge + '</div>'
                + '</div></div>';
        }).join('');
    },

    /* ── Open a conversation ──────────────────────────────── */
    openChat(id) {
        id = parseInt(id);
        if (!id) return;

        // Get conv data from rendered list
        var item = document.querySelector('[data-id="' + id + '"]');
        this.convId    = id;
        this.convName  = item ? item.dataset.name  : 'Conversation';
        this.convColor = item ? item.dataset.color : '#2563eb';
        this.convPhone = item ? item.dataset.phone : '';
        this.convChatId= item ? item.dataset.chatid : '';
        this.lastMsgId = 0;

        // Mobile: show right panel
        document.getElementById('waRoot').classList.add('chat-open');

        // Update active state in conv list
        document.querySelectorAll('.wa-conv-item').forEach(el => el.classList.remove('active'));
        if (item) item.classList.add('active');
        // Clear unread badge immediately
        if (item) { var b = item.querySelector('.wa-conv-badge'); if (b) b.remove(); }
        if (item) { var t = item.querySelector('.wa-conv-time'); if (t) t.classList.remove('unread'); }

        // Show chat panel
        document.getElementById('waWelcome').style.display = 'none';
        var chat = document.getElementById('waChat');
        chat.style.display = 'flex';

        // Set chat header
        var av = document.getElementById('waChatAv');
        av.textContent = this.convName.charAt(0).toUpperCase();
        av.style.background = this.convColor;
        document.getElementById('waChatName').textContent = this.convName;
        document.getElementById('waChatSub').textContent  = this.convPhone || this.convChatId.replace(/@.*/, '');
        // Set Open-in-WhatsApp link
        var phone = (this.convPhone || this.convChatId.replace(/@.*/, '')).replace(/\D/g, '');
        document.getElementById('waOpenWaBtn').href = 'https://wa.me/' + phone;
        document.getElementById('waChatViewBtn').href = BASE + '/modules/whatsapp/chat.php?id=' + id;

        // Update URL without page reload
        history.pushState({ id }, '', '?id=' + id);

        // Load messages
        this.fetchMessages();

        // Start chat poll
        clearInterval(this.chatPollTimer);
        this.chatPollTimer = setInterval(() => this.pollChat(), 5000);

        // Focus input
        setTimeout(() => { var inp = document.getElementById('waInput'); if (inp) inp.focus(); }, 100);
    },

    closeChat() {
        document.getElementById('waRoot').classList.remove('chat-open');
        clearInterval(this.chatPollTimer);
        this.convId = 0;
        history.pushState({}, '', location.pathname);
    },

    /* ── Fetch messages (initial load) ───────────────────── */
    async fetchMessages() {
        var box  = document.getElementById('waMsgs');
        var wrap = document.getElementById('waMsgsWrap');
        box.innerHTML = '';
        document.getElementById('waMsgsLoading').style.display = 'flex';

        try {
            const d = await fetch(BASE + '/modules/whatsapp/api/messages.php?conversation_id=' + this.convId).then(r => r.json());
            document.getElementById('waMsgsLoading').style.display = 'none';
            var msgs = d.messages || [];
            if (!msgs.length) {
                box.innerHTML = '<div style="display:flex;align-items:center;justify-content:center;flex-direction:column;gap:8px;padding:48px 0;color:#8696a0;font-size:14px"><i class="fab fa-whatsapp" style="font-size:36px;opacity:.3"></i>No messages yet. Say hello!</div>';
                return;
            }
            this.renderMessages(msgs);
        } catch(e) {
            document.getElementById('waMsgsLoading').style.display = 'none';
            box.innerHTML = '<div style="text-align:center;padding:40px;color:#8696a0;font-size:14px"><i class="fa fa-wifi-slash fa-2x mb-2 d-block" style="opacity:.4"></i>Could not load messages.</div>';
        }
    },

    renderMessages(msgs) {
        var box = document.getElementById('waMsgs');
        box.innerHTML = '';
        var lastDay = '';
        msgs.forEach(m => {
            var day = this.fmtDay(m.sent_at);
            if (day !== lastDay) {
                box.insertAdjacentHTML('beforeend', '<div class="wa-day-sep"><span>' + day + '</span></div>');
                lastDay = day;
            }
            box.insertAdjacentHTML('beforeend', this.msgHtml(m));
            var mid = parseInt(m.id) || 0;
            if (mid > this.lastMsgId) this.lastMsgId = mid;
        });
        this.scrollToBottom();
    },

    appendMessage(m) {
        var box = document.getElementById('waMsgs');
        var day = this.fmtDay(m.sent_at || new Date().toISOString());
        // Check if we need a new day separator
        var lastSep = box.querySelector('.wa-day-sep:last-of-type');
        var lastDay = lastSep ? lastSep.querySelector('span').textContent : '';
        if (day !== lastDay) {
            box.insertAdjacentHTML('beforeend', '<div class="wa-day-sep"><span>' + day + '</span></div>');
        }
        box.insertAdjacentHTML('beforeend', this.msgHtml(m));
        var mid = parseInt(m.id) || 0;
        if (mid > this.lastMsgId) this.lastMsgId = mid;

        // Auto-scroll if near bottom
        var wrap = document.getElementById('waMsgsWrap');
        var nearBottom = wrap.scrollHeight - wrap.scrollTop - wrap.clientHeight < 120;
        if (nearBottom) this.scrollToBottom();
    },

    scrollToBottom() {
        var wrap = document.getElementById('waMsgsWrap');
        if (wrap) wrap.scrollTop = wrap.scrollHeight;
    },

    msgHtml(m) {
        var isOut  = m.direction === 'out';
        var time   = this.fmtTime(m.sent_at);
        var type   = m.type || 'text';
        var body   = m.body || '';
        var mediaTag = '';

        if (type !== 'text') {
            var icons = { image: '🖼️', audio: '🎵', video: '🎥', document: '📄' };
            var icon  = icons[type] || '📎';
            var mediaLink = m.media_url ? ' <a href="' + this.esc(m.media_url) + '" target="_blank" style="color:#00a884;font-size:11px">View</a>' : '';
            mediaTag = '<div class="wa-msg-media-tag">' + icon + ' ' + type.charAt(0).toUpperCase() + type.slice(1) + mediaLink + '</div>';
        }

        var bodyHtml = this.esc(body).replace(/\n/g, '<br>');
        var ticks = '';
        if (isOut) {
            ticks = '<span class="wa-check wa-check-grey"><i class="fa fa-check-double"></i></span>';
        }

        return '<div class="wa-msg-row wa-msg-row-' + (isOut ? 'out' : 'in') + '">'
            + '<div class="wa-bbl wa-bbl-' + (isOut ? 'out' : 'in') + '">'
            + mediaTag
            + '<span class="wa-msg-text">' + bodyHtml + '</span>'
            + '<div class="wa-msg-meta">'
            + '<span class="wa-msg-time">' + time + '</span>'
            + ticks
            + '</div>'
            + '</div></div>';
    },

    /* ── Poll for new messages in current chat ────────────── */
    async pollChat() {
        if (!this.convId) return;
        try {
            const d = await fetch(BASE + '/modules/whatsapp/api/poll.php?conversation_id=' + this.convId + '&since_id=' + this.lastMsgId).then(r => r.json());

            // Append any new messages that arrived for the open conversation
            if (d.messages && d.messages.length > 0) {
                var hadIncoming = false;
                d.messages.forEach(m => {
                    this.appendMessage(m);
                    if (m.direction === 'in') hadIncoming = true;
                });
                if (hadIncoming) this.playPing();
            }

            // If ANY new message arrived (for ANY conversation) refresh the list
            if (d.new > 0) {
                this.loadConvs();
                // Show toast for messages that arrived in OTHER conversations
                if (d.notifications) {
                    d.notifications.forEach(n => {
                        if (n.conv_id != this.convId) this.showToast(n.name, n.preview, n.conv_id);
                    });
                }
            }
        } catch(e) {}
    },

    /* ── Global poll (when no chat open) ─────────────────── */
    async globalPoll() {
        if (this.convId) return; // chatPoll handles it when a conv is open
        try {
            const d = await fetch(BASE + '/modules/whatsapp/api/poll.php?conversation_id=0&since_id=0').then(r => r.json());
            if (d.new > 0) {
                this.loadConvs();
                this.playPing();
                if (d.notifications) {
                    d.notifications.forEach(n => this.showToast(n.name, n.preview, n.conv_id));
                }
            }
        } catch(e) {}
    },

    /* ── Soft notification sound (Web Audio API) ─────────── */
    playPing() {
        try {
            var ctx  = new (window.AudioContext || window.webkitAudioContext)();
            var osc  = ctx.createOscillator();
            var gain = ctx.createGain();
            osc.connect(gain);
            gain.connect(ctx.destination);
            osc.type = 'sine';
            osc.frequency.setValueAtTime(880, ctx.currentTime);
            osc.frequency.exponentialRampToValueAtTime(660, ctx.currentTime + 0.12);
            gain.gain.setValueAtTime(0.35, ctx.currentTime);
            gain.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + 0.4);
            osc.start(ctx.currentTime);
            osc.stop(ctx.currentTime + 0.4);
            setTimeout(() => { try { ctx.close(); } catch(_) {} }, 600);
        } catch(_) {}
    },

    /* ── Toast notification for background conversations ──── */
    showToast(name, preview, convId) {
        var container = document.getElementById('waToastContainer');
        if (!container) return;
        var color = this.avatarColor(name || '');
        var initial = (name || '?').charAt(0).toUpperCase();

        var toast = document.createElement('div');
        toast.className = 'wa-toast';
        toast.innerHTML = '<div class="wa-toast-av" style="background:' + color + '">' + initial + '</div>'
            + '<div style="min-width:0;flex:1">'
            + '<div class="wa-toast-name">' + this.esc(name) + '</div>'
            + '<div class="wa-toast-prev">' + this.esc((preview || '').substring(0, 55)) + '</div>'
            + '</div>'
            + '<button onclick="event.stopPropagation();this.closest(\'.wa-toast\').remove()" '
            + 'style="background:none;border:none;color:#8696a0;cursor:pointer;padding:0 0 0 6px;font-size:14px;line-height:1">✕</button>';

        toast.addEventListener('click', () => { this.openChat(parseInt(convId)); toast.remove(); });
        container.appendChild(toast);

        // Auto-dismiss after 6 s
        setTimeout(() => {
            toast.style.opacity = '0';
            setTimeout(() => { try { toast.remove(); } catch(_) {} }, 320);
        }, 6000);
    },

    /* ── Send message ─────────────────────────────────────── */
    async send() {
        var input = document.getElementById('waInput');
        var msg   = input.value.trim();
        if (!msg || !this.convId) return;

        var btn = document.getElementById('waSendBtn');
        btn.disabled = true;
        input.value = '';
        input.style.height = 'auto';

        // Optimistic render
        this.appendMessage({ direction: 'out', body: msg, sent_at: new Date().toISOString().replace('T',' ').substring(0,19), agent_name: ME_NAME });

        try {
            var fd = new FormData();
            fd.append('conversation_id', this.convId);
            fd.append('message', msg);
            const d = await fetch(BASE + '/modules/whatsapp/api/send.php', { method: 'POST', body: fd }).then(r => r.json());
            if (d.success && parseInt(d.message_id) > this.lastMsgId) {
                this.lastMsgId = parseInt(d.message_id);
            }
            if (!d.success) {
                this.appendMessage({ direction: 'in', body: '⚠ Send failed: ' + (d.error || 'unknown error'), sent_at: new Date().toISOString().replace('T',' ').substring(0,19) });
            }
        } catch(e) {
            this.appendMessage({ direction: 'in', body: '⚠ Network error. Check your connection.', sent_at: new Date().toISOString().replace('T',' ').substring(0,19) });
        }
        btn.disabled = false;
        input.focus();
    },

    /* ── Load message history from Green API ─────────────── */
    async loadHistory() {
        if (!this.convId) return;
        var btn = document.getElementById('btnLoadHistChat');
        btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i>';
        btn.disabled = true;
        try {
            const d = await fetch(BASE + '/modules/whatsapp/api/history.php?conversation_id=' + this.convId).then(r => r.json());
            if (d.success && d.messages && d.messages.length > 0) {
                this.renderMessages(d.messages);
                // Brief success flash
                btn.innerHTML = '<i class="fa fa-check"></i>';
                setTimeout(() => { btn.innerHTML = '<i class="fa fa-clock-rotate-left"></i>'; btn.disabled = false; }, 3000);
            } else {
                btn.innerHTML = '<i class="fa fa-clock-rotate-left"></i>';
                btn.disabled = false;
            }
        } catch(e) {
            btn.innerHTML = '<i class="fa fa-clock-rotate-left"></i>';
            btn.disabled = false;
        }
    },

    /* ── Bind events ──────────────────────────────────────── */
    bindSearch() {
        document.getElementById('waSearch').addEventListener('input', function () {
            var q = this.value.toLowerCase().trim();
            document.querySelectorAll('#waConvList .wa-conv-item').forEach(function (el) {
                var name  = (el.dataset.name  || '').toLowerCase();
                var phone = (el.dataset.phone || '').toLowerCase();
                el.style.display = (!q || name.includes(q) || phone.includes(q)) ? '' : 'none';
            });
        });
    },

    bindSend() {
        var input = document.getElementById('waInput');
        var self  = this;

        // Auto-grow
        input.addEventListener('input', function () {
            this.style.height = 'auto';
            this.style.height = Math.min(this.scrollHeight, 140) + 'px';
        });

        // Enter to send (Shift+Enter = newline)
        input.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); self.send(); }
        });

        document.getElementById('waSendBtn').addEventListener('click', () => this.send());
    },

    /* ── Utilities ────────────────────────────────────────── */
    esc(s) {
        return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    },

    avatarColor(name) {
        var p = ['#2563eb','#16a34a','#dc2626','#9333ea','#f59e0b','#0891b2','#db2777','#65a30d'];
        var h = 0;
        for (var i = 0; i < name.length; i++) h = ((h << 5) - h) + name.charCodeAt(i);
        return p[Math.abs(h) % p.length];
    },

    relTime(dt) {
        if (!dt) return '';
        var ts   = new Date(dt.replace(' ','T')).getTime();
        var diff = (Date.now() - ts) / 1000;
        if (diff < 60)    return 'Just now';
        if (diff < 3600)  return Math.floor(diff/60) + 'm';
        var d   = new Date(ts);
        var now = new Date();
        if (d.toDateString() === now.toDateString()) return d.getHours().toString().padStart(2,'0') + ':' + d.getMinutes().toString().padStart(2,'0');
        if (diff < 86400*2) return 'Yesterday';
        if (diff < 86400*7) return ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'][d.getDay()];
        return d.getDate() + '/' + (d.getMonth()+1) + '/' + String(d.getFullYear()).slice(-2);
    },

    fmtTime(dt) {
        if (!dt) return '';
        var d = new Date((dt||'').replace(' ','T'));
        return isNaN(d) ? '' : d.getHours().toString().padStart(2,'0') + ':' + d.getMinutes().toString().padStart(2,'0');
    },

    fmtDay(dt) {
        if (!dt) return 'Today';
        var d   = new Date((dt||'').replace(' ','T'));
        if (isNaN(d)) return 'Today';
        var now = new Date();
        var days = Math.floor((now.setHours(0,0,0,0) - d.setHours(0,0,0,0)) / 86400000);
        if (days === 0) return 'Today';
        if (days === 1) return 'Yesterday';
        if (days < 7)   return ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'][new Date(dt.replace(' ','T')).getDay()];
        var orig = new Date(dt.replace(' ','T'));
        return orig.getDate() + ' ' + ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'][orig.getMonth()] + ' ' + orig.getFullYear();
    },
};

document.addEventListener('DOMContentLoaded', function () { WA.init(); });
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>

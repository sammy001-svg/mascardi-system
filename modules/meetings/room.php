<?php
/**
 * Meetings — virtual room.
 *
 * Peer-to-peer video in the browser. Media flows directly between participants;
 * this server only relays the WebRTC handshake (modules/meetings/api/signal.php).
 * There is no media server to run, which is what makes it possible at all here —
 * but it also sets the limits, which are stated plainly in the UI rather than
 * discovered when a meeting fails:
 *
 *   • Full mesh — each person connects to every other. Fine for a handful of
 *     people; it will strain a laptop well before a dozen.
 *   • STUN only, no TURN relay. That covers most office and home networks, but
 *     a strict corporate firewall or symmetric NAT can block the direct path,
 *     and there is no fallback route to fall back to.
 *   • Browsers only expose camera and microphone on HTTPS (localhost aside), so
 *     the page checks that up front instead of failing silently.
 */
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/_bootstrap.php';
requireLogin();
canAccess('meetings') || redirect(BASE_URL . '/index.php');

$db = getDB();
meetingsMigrate($db);

$me   = authUser();
$meId = (int)$me['id'];
$id   = (int)($_GET['id'] ?? 0);

$st = $db->prepare("SELECT * FROM meetings WHERE id = ?");
$st->execute([$id]);
$meeting = $st->fetch(PDO::FETCH_ASSOC);
if (!$meeting) { setFlash('error', 'That meeting no longer exists.'); redirect(BASE_URL . '/modules/meetings/index.php'); }
if (!meetingCanView($db, $meeting, $meId)) {
    setFlash('error', 'You were not invited to that meeting.');
    redirect(BASE_URL . '/modules/meetings/index.php');
}
if ($meeting['meeting_type'] === 'physical') {
    setFlash('error', 'That meeting is in person — it has no video room.');
    redirect(BASE_URL . '/modules/meetings/view.php?id=' . $id);
}

$agenda = [];
try {
    $s = $db->prepare("SELECT id, position, title FROM meeting_agenda_items WHERE meeting_id=? ORDER BY position, id");
    $s->execute([$id]);
    $agenda = $s->fetchAll(PDO::FETCH_ASSOC);
} catch (\Throwable $_) {}

$canEdit   = meetingCanEdit($db, $meeting, $meId);
$pageTitle = 'Room — ' . $meeting['title'];
include __DIR__ . '/../../includes/header.php';
?>
<style>
.rm-wrap{ display:grid; grid-template-columns:minmax(0,1fr) 300px; gap:14px; align-items:start; }
@media(max-width:992px){ .rm-wrap{ grid-template-columns:minmax(0,1fr); } }

.rm-stage{ background:#0b1120; border:1px solid var(--border); border-radius:var(--r-lg); overflow:hidden; position:relative; }
.rm-grid{ display:grid; gap:8px; padding:8px; grid-template-columns:repeat(auto-fit,minmax(240px,1fr)); }
.rm-tile{ position:relative; background:#111827; border-radius:var(--r); overflow:hidden; aspect-ratio:16/10; }
.rm-tile video{ width:100%; height:100%; object-fit:cover; background:#111827; display:block; }
.rm-tile.speaking{ outline:2px solid #16a34a; outline-offset:-2px; }

/* A shared screen is never cropped. `cover` fills the tile by cutting off the
   edges, which is fine for a face but loses the sides of a slide or the toolbar
   of whatever is being demonstrated — the parts a presentation is being shown
   for. Letterboxing is the correct trade here. */
.rm-tile.sharing video{ object-fit:contain; background:#000; }
.rm-tile.sharing{ outline:2px solid var(--brand); outline-offset:-2px; }

/* ── Expand / spotlight ──────────────────────────────────────────────────────
   Kept out of the way until wanted: the controls fade in on hover, and stay
   permanently visible on a tile that is presenting so the option is findable
   without hunting for it. On touch there is no hover, so they are always on. */
.rm-acts{ position:absolute; right:6px; bottom:6px; display:flex; gap:5px;
    opacity:0; transition:opacity .14s; }
.rm-tile:hover .rm-acts,
.rm-tile.sharing .rm-acts,
.rm-tile.focus .rm-acts{ opacity:1; }
@media(hover:none){ .rm-acts{ opacity:1; } }
.rm-act{ width:29px; height:29px; border-radius:7px; border:0; cursor:pointer;
    background:rgba(0,0,0,.66); color:#fff; font-size:12px; line-height:1;
    display:flex; align-items:center; justify-content:center; }
.rm-act:hover{ background:rgba(0,0,0,.85); }

/* Spotlight: the focused tile takes the top of the stage at full width, and
   everyone else drops to a thumbnail row beneath it. */
.rm-grid.focus-mode{ grid-template-columns:repeat(auto-fit,minmax(104px,1fr));
    grid-auto-rows:76px; align-content:start; }
.rm-grid.focus-mode .rm-tile{ aspect-ratio:auto; height:76px; }
.rm-grid.focus-mode .rm-tile video{ object-fit:cover; }
.rm-grid.focus-mode .rm-tile.focus{ grid-column:1/-1; order:-1;
    height:min(68vh,620px); aspect-ratio:auto; }
.rm-grid.focus-mode .rm-tile.focus video{ object-fit:contain; }
.rm-grid.focus-mode .rm-tile:not(.focus) .rm-name{ font-size:10px; padding:2px 6px; left:4px; bottom:4px; }
.rm-grid.focus-mode .rm-tile:not(.focus) .rm-acts{ display:none; }
.rm-grid.focus-mode .rm-tile:not(.focus) .rm-avatar{ font-size:19px; }

/* True fullscreen — the video fills the screen with nothing else on it. */
.rm-tile:fullscreen{ aspect-ratio:auto; width:100vw; height:100vh; border-radius:0; background:#000; }
.rm-tile:fullscreen video{ object-fit:contain; }

.rm-presenting{ position:absolute; left:8px; top:8px; background:var(--brand); color:#fff;
    font-size:10.5px; font-weight:700; padding:3px 9px; border-radius:20px;
    display:flex; align-items:center; gap:5px; }
.rm-name{ position:absolute; left:8px; bottom:8px; background:rgba(0,0,0,.62); color:#fff;
    font-size:11.5px; font-weight:600; padding:3px 9px; border-radius:20px; display:flex; align-items:center; gap:6px; }
.rm-name i{ font-size:10px; }
.rm-avatar{ position:absolute; inset:0; display:flex; align-items:center; justify-content:center;
    font-size:34px; font-weight:800; color:#fff; }
.rm-badge{ position:absolute; right:8px; top:8px; background:rgba(0,0,0,.62); color:#fff;
    font-size:10.5px; font-weight:700; padding:3px 9px; border-radius:20px; }

.rm-bar{ display:flex; align-items:center; justify-content:center; gap:10px; padding:12px;
    background:var(--surface); border-top:1px solid var(--border); flex-wrap:wrap; }
.rm-btn{ width:46px; height:46px; border-radius:50%; border:1px solid var(--border); background:var(--surface-alt);
    color:var(--text); font-size:16px; display:flex; align-items:center; justify-content:center; cursor:pointer; transition:.12s; }
.rm-btn:hover{ border-color:var(--brand); }
.rm-btn.off{ background:#dc2626; border-color:#dc2626; color:#fff; }
.rm-btn.on{ background:var(--brand); border-color:var(--brand); color:#fff; }
.rm-leave{ width:auto; padding:0 18px; border-radius:24px; background:#dc2626; border-color:#dc2626; color:#fff; font-size:13px; font-weight:700; gap:7px; }

.rm-card{ background:var(--surface); border:1px solid var(--border); border-radius:var(--r-lg); box-shadow:var(--sh-sm); margin-bottom:14px; overflow:hidden; }
.rm-card-head{ padding:11px 14px; border-bottom:1px solid var(--border); background:var(--surface-alt);
    font-size:12.5px; font-weight:700; color:var(--text); display:flex; align-items:center; justify-content:space-between; gap:8px; }
.rm-card-head i{ color:var(--brand); margin-right:6px; }
.rm-card-body{ padding:12px 14px; }
.rm-p{ display:flex; align-items:center; gap:9px; padding:7px 0; border-bottom:1px solid var(--border); font-size:12.5px; }
.rm-p:last-child{ border-bottom:0; }
.rm-p-av{ width:28px; height:28px; border-radius:50%; flex:0 0 28px; color:#fff; font-size:10.5px; font-weight:800;
    display:flex; align-items:center; justify-content:center; }
.rm-ag{ font-size:12.5px; color:var(--text); padding:6px 0; border-bottom:1px solid var(--border); display:flex; gap:8px; }
.rm-ag:last-child{ border-bottom:0; }
.rm-ag span:first-child{ color:var(--brand); font-weight:800; }
.rm-note{ font-size:11.5px; color:var(--text-2,#64748b); line-height:1.6; }
#rmAlert{ margin-bottom:14px; }
</style>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div>
        <h1 style="font-size:19px;font-weight:800;letter-spacing:-.4px;margin:0;color:var(--text)">
            <i class="fa fa-video me-2" style="color:var(--brand)"></i><?= e($meeting['title']) ?>
        </h1>
        <div class="small text-muted"><?= date('D j M Y, H:i', strtotime($meeting['scheduled_start'])) ?>
            &middot; room <code><?= e($meeting['room_code']) ?></code></div>
    </div>
    <a href="<?= BASE_URL ?>/modules/meetings/view.php?id=<?= $id ?>" class="btn btn-sm btn-outline-secondary">
        <i class="fa fa-arrow-left me-1"></i>Meeting page
    </a>
</div>

<div id="rmAlert"></div>

<div class="rm-wrap">
    <div class="rm-stage">
        <div class="rm-grid" id="rmGrid">
            <div class="rm-tile" id="tile-self">
                <video id="selfVideo" autoplay playsinline muted></video>
                <div class="rm-avatar" id="selfAvatar" style="display:none;background:<?= meetingAvatarColor($meId) ?>">
                    <?= e(meetingInitials($me['name'])) ?>
                </div>
                <div class="rm-name"><i class="fa fa-microphone" id="selfMicIcon"></i><?= e($me['name']) ?> (you)</div>
                <div class="rm-presenting" style="display:none"><i class="fa fa-display"></i>Presenting</div>
                <div class="rm-acts">
                    <button type="button" class="rm-act" data-act="focus"
                            title="Expand — fill the stage with this view"><i class="fa fa-expand"></i></button>
                    <button type="button" class="rm-act" data-act="full"
                            title="Fullscreen"><i class="fa fa-up-right-and-down-left-from-center"></i></button>
                </div>
            </div>
        </div>
        <div class="rm-bar">
            <button class="rm-btn" id="btnMic"   title="Mute / unmute"><i class="fa fa-microphone"></i></button>
            <button class="rm-btn" id="btnCam"   title="Camera on / off"><i class="fa fa-video"></i></button>
            <button class="rm-btn" id="btnShare" title="Share your screen"><i class="fa fa-display"></i></button>
            <button class="rm-btn rm-leave" id="btnLeave"><i class="fa fa-phone-slash"></i>Leave</button>
        </div>
    </div>

    <div>
        <div class="rm-card">
            <div class="rm-card-head">
                <span><i class="fa fa-users"></i>In the room</span>
                <span id="rmCount" class="text-muted">1</span>
            </div>
            <div class="rm-card-body" id="rmPeople">
                <div class="rm-p">
                    <span class="rm-p-av" style="background:<?= meetingAvatarColor($meId) ?>"><?= e(meetingInitials($me['name'])) ?></span>
                    <span style="flex:1"><?= e($me['name']) ?> (you)</span>
                </div>
            </div>
        </div>

        <?php if ($agenda): ?>
        <div class="rm-card">
            <div class="rm-card-head"><span><i class="fa fa-list-ol"></i>Agenda</span></div>
            <div class="rm-card-body">
                <?php foreach ($agenda as $n => $a): ?>
                <div class="rm-ag"><span><?= $n + 1 ?>.</span><span><?= e($a['title']) ?></span></div>
                <?php endforeach; ?>
                <?php if ($canEdit): ?>
                <a href="<?= BASE_URL ?>/modules/meetings/view.php?id=<?= $id ?>#minutes"
                   target="_blank" class="btn btn-xs btn-outline-primary w-100 mt-2">
                    <i class="fa fa-pen me-1"></i>Take minutes in a new tab
                </a>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <div class="rm-card">
            <div class="rm-card-head"><span><i class="fa fa-circle-info"></i>About this room</span></div>
            <div class="rm-card-body">
                <p class="rm-note mb-0">
                    Video and audio go straight between participants — they are not recorded or
                    routed through the server. Best for small groups; a large meeting is better
                    held on a dedicated conferencing service. If someone cannot connect, a
                    restrictive network is the usual cause.
                </p>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    'use strict';

    var MEETING_ID = <?= (int)$id ?>;
    var ME         = { id: <?= $meId ?>, name: <?= json_encode($me['name']) ?> };
    var API        = <?= json_encode(BASE_URL . '/modules/meetings/api/signal.php') ?>;
    var CSRF       = <?= json_encode(csrfToken()) ?>;
    var COLORS     = <?= json_encode(array_map('meetingAvatarColor', range(0, 9))) ?>;

    var pcs = {};        // user_id -> RTCPeerConnection
    var localStream = null, screenStream = null;
    var micOn = true, camOn = true, sharing = false;
    var pollTimer = null, joined = false;

    var alertBox = document.getElementById('rmAlert');
    function notify(msg, kind) {
        alertBox.innerHTML = '<div class="alert alert-' + (kind || 'warning') + ' py-2 mb-0 small">' + msg + '</div>';
    }
    function clearNotify() { alertBox.innerHTML = ''; }

    function initials(n) {
        var p = String(n || '').trim().split(/\s+/).filter(Boolean);
        if (!p.length) return '?';
        return (p[0][0] + (p.length > 1 ? p[p.length - 1][0] : '')).toUpperCase();
    }
    function colorFor(id) { return COLORS[Math.abs(id) % COLORS.length]; }

    // ── Signalling transport ────────────────────────────────────────────────
    function post(action, payload) {
        return fetch(API + '?action=' + action + '&meeting_id=' + MEETING_ID, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
            body: JSON.stringify(Object.assign({ meeting_id: MEETING_ID, csrf_token: CSRF }, payload || {}))
        }).then(function (r) { return r.json(); });
    }
    function send(to, kind, payload) { return post('send', { to: to, kind: kind, payload: payload }); }

    // ── Peer connections ────────────────────────────────────────────────────
    // STUN only. A TURN relay would be needed for networks that block the
    // direct path; there is no free one to point at, so a failure here is
    // surfaced to the user rather than left as a black tile.
    var RTC_CONFIG = { iceServers: [
        { urls: ['stun:stun.l.google.com:19302', 'stun:stun1.l.google.com:19302'] }
    ]};

    function tileFor(userId, name) {
        var el = document.getElementById('tile-' + userId);
        if (el) return el;
        el = document.createElement('div');
        el.className = 'rm-tile';
        el.id = 'tile-' + userId;
        el.innerHTML =
            '<video autoplay playsinline></video>' +
            '<div class="rm-avatar" style="background:' + colorFor(userId) + '">' + initials(name) + '</div>' +
            '<div class="rm-name"><i class="fa fa-microphone"></i>' + escapeHtml(name) + '</div>' +
            '<div class="rm-presenting" style="display:none"><i class="fa fa-display"></i>Presenting</div>' +
            '<div class="rm-badge" style="display:none">Connecting…</div>' +
            tileActionsHtml();
        document.getElementById('rmGrid').appendChild(el);
        return el;
    }
    function escapeHtml(s) {
        return String(s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }
    function dropTile(userId) {
        var el = document.getElementById('tile-' + userId);
        if (!el) return;
        if (el.classList.contains('focus')) setFocus(null);   // don't leave an empty spotlight
        el.remove();
    }

    // ── Expand / spotlight / fullscreen ─────────────────────────────────────
    var grid       = document.getElementById('rmGrid');
    var focusedId  = null;    // tile id currently filling the stage
    var autoFocused = null;   // who we spotlighted on their behalf, so a manual
                              // choice is never overridden by the poller

    function tileActionsHtml() {
        return '<div class="rm-acts">' +
            '<button type="button" class="rm-act" data-act="focus" ' +
                'title="Expand — fill the stage with this view"><i class="fa fa-expand"></i></button>' +
            '<button type="button" class="rm-act" data-act="full" ' +
                'title="Fullscreen"><i class="fa fa-up-right-and-down-left-from-center"></i></button>' +
        '</div>';
    }

    function setFocus(tileId) {
        // Passing the already-focused tile collapses back to the even grid.
        if (tileId && tileId === focusedId) tileId = null;
        focusedId = tileId;

        Array.prototype.forEach.call(grid.querySelectorAll('.rm-tile'), function (t) {
            var on = (t.id === tileId);
            t.classList.toggle('focus', on);
            var b = t.querySelector('[data-act="focus"] i');
            if (b) b.className = on ? 'fa fa-compress' : 'fa fa-expand';
            var btn = t.querySelector('[data-act="focus"]');
            if (btn) btn.title = on ? 'Shrink back to the grid' : 'Expand — fill the stage with this view';
        });
        grid.classList.toggle('focus-mode', !!tileId);
        if (!tileId) autoFocused = null;
    }

    function toggleFullscreen(tile) {
        if (document.fullscreenElement) { document.exitFullscreen(); return; }
        // Older WebKit exposes this only on the video element.
        var target = tile.requestFullscreen ? tile : tile.querySelector('video');
        if (!target) return;
        var req = target.requestFullscreen || target.webkitRequestFullscreen || target.msRequestFullscreen;
        if (req) { try { req.call(target); } catch (e) {} }
    }

    // One delegated listener covers tiles that do not exist yet.
    grid.addEventListener('click', function (ev) {
        var btn = ev.target.closest ? ev.target.closest('.rm-act') : null;
        if (!btn) return;
        var tile = btn.closest('.rm-tile');
        if (!tile) return;
        ev.preventDefault();
        if (btn.dataset.act === 'focus')     setFocus(tile.id);
        else if (btn.dataset.act === 'full') toggleFullscreen(tile);
    });

    // Double-click anywhere on a tile is the familiar shortcut for expanding it.
    grid.addEventListener('dblclick', function (ev) {
        var tile = ev.target.closest ? ev.target.closest('.rm-tile') : null;
        if (tile) setFocus(tile.id);
    });

    // Escape leaves the spotlight. Fullscreen has its own Escape handling, so
    // only act when we are not in it.
    document.addEventListener('keydown', function (ev) {
        if (ev.key === 'Escape' && focusedId && !document.fullscreenElement) setFocus(null);
    });

    /**
     * Marks a tile as presenting and, the first time it starts, expands it for
     * everyone. Manual choices win: if the viewer has focused something else, or
     * deliberately collapsed the spotlight, we leave it alone.
     */
    function setSharing(tileId, isSharing) {
        var tile = document.getElementById(tileId);
        if (!tile) return;
        var was = tile.classList.contains('sharing');
        if (was === !!isSharing) return;

        tile.classList.toggle('sharing', !!isSharing);
        var badge = tile.querySelector('.rm-presenting');
        if (badge) badge.style.display = isSharing ? '' : 'none';

        // Auto-expand is for the people watching. The presenter is already
        // looking at whatever they are sharing, so filling their stage with a
        // mirror of it only gets in the way — they can still expand by hand.
        if (isSharing && !focusedId && tileId !== 'tile-self') {
            setFocus(tileId);
            autoFocused = tileId;
        } else if (!isSharing && autoFocused === tileId) {
            setFocus(null);
        }
    }

    function makePeer(userId, name) {
        if (pcs[userId]) return pcs[userId];

        var pc = new RTCPeerConnection(RTC_CONFIG);
        pcs[userId] = pc;

        if (localStream) localStream.getTracks().forEach(function (t) { pc.addTrack(t, localStream); });

        pc.onicecandidate = function (e) {
            if (e.candidate) send(userId, 'ice', e.candidate.toJSON());
        };

        pc.ontrack = function (e) {
            var tile = tileFor(userId, name);
            var v = tile.querySelector('video');
            if (v.srcObject !== e.streams[0]) v.srcObject = e.streams[0];
            tile.querySelector('.rm-avatar').style.display = 'none';
            tile.querySelector('.rm-badge').style.display = 'none';
        };

        pc.onconnectionstatechange = function () {
            var tile = document.getElementById('tile-' + userId);
            if (!tile) return;
            var badge = tile.querySelector('.rm-badge');
            if (pc.connectionState === 'failed') {
                // Nearly always a network that blocks peer-to-peer traffic.
                badge.textContent = 'Could not connect';
                badge.style.display = '';
                notify('Could not establish a direct connection to ' + escapeHtml(name) +
                       '. This usually means a firewall is blocking peer-to-peer video.', 'warning');
            } else if (pc.connectionState === 'connected') {
                badge.style.display = 'none';
            }
        };

        return pc;
    }

    async function offerTo(userId, name) {
        var pc = makePeer(userId, name);
        tileFor(userId, name).querySelector('.rm-badge').style.display = '';
        var offer = await pc.createOffer();
        await pc.setLocalDescription(offer);
        await send(userId, 'offer', { sdp: pc.localDescription });
    }

    async function handleSignal(sig) {
        var from = sig.from, p = sig.payload;

        if (sig.kind === 'bye') {
            if (pcs[from]) { try { pcs[from].close(); } catch (e) {} delete pcs[from]; }
            dropTile(from);
            return;
        }

        var name = (rosterNames[from] || 'Participant');
        var pc = makePeer(from, name);

        try {
            if (sig.kind === 'offer') {
                await pc.setRemoteDescription(new RTCSessionDescription(p.sdp));
                var answer = await pc.createAnswer();
                await pc.setLocalDescription(answer);
                await send(from, 'answer', { sdp: pc.localDescription });
            } else if (sig.kind === 'answer') {
                if (pc.signalingState !== 'stable') {
                    await pc.setRemoteDescription(new RTCSessionDescription(p.sdp));
                }
            } else if (sig.kind === 'ice') {
                // Candidates can arrive before the description they belong to.
                if (pc.remoteDescription && pc.remoteDescription.type) {
                    await pc.addIceCandidate(new RTCIceCandidate(p));
                } else {
                    (pendingIce[from] = pendingIce[from] || []).push(p);
                }
            }

            if (pc.remoteDescription && pendingIce[from]) {
                for (var i = 0; i < pendingIce[from].length; i++) {
                    try { await pc.addIceCandidate(new RTCIceCandidate(pendingIce[from][i])); } catch (e) {}
                }
                delete pendingIce[from];
            }
        } catch (e) {
            console.error('signal ' + sig.kind + ' from ' + from, e);
        }
    }

    var pendingIce  = {};
    var rosterNames = {};

    // ── Roster ──────────────────────────────────────────────────────────────
    function renderRoster(peers) {
        document.getElementById('rmCount').textContent = peers.length + 1;
        var html = '<div class="rm-p"><span class="rm-p-av" style="background:' + colorFor(ME.id) + '">' +
                   initials(ME.name) + '</span><span style="flex:1">' + escapeHtml(ME.name) + ' (you)</span>' +
                   (micOn ? '' : '<i class="fa fa-microphone-slash text-danger"></i>') + '</div>';
        peers.forEach(function (p) {
            rosterNames[p.user_id] = p.name;
            html += '<div class="rm-p"><span class="rm-p-av" style="background:' + colorFor(p.user_id) + '">' +
                    initials(p.name) + '</span><span style="flex:1">' + escapeHtml(p.name) + '</span>' +
                    (p.sharing ? '<i class="fa fa-display text-primary" title="Sharing screen"></i>' : '') +
                    (p.mic_on ? '' : '<i class="fa fa-microphone-slash text-danger"></i>') + '</div>';
        });
        document.getElementById('rmPeople').innerHTML = html;

        // Reflect who is presenting onto the tiles. The roster is the only place
        // a remote screen share is announced — the incoming track looks the same
        // either way — so the badge, the uncropped scaling and the auto-expand
        // all hang off this.
        peers.forEach(function (p) { setSharing('tile-' + p.user_id, !!p.sharing); });

        // Someone whose heartbeat stopped is gone even without a goodbye.
        var live = {};
        peers.forEach(function (p) { live[p.user_id] = 1; });
        Object.keys(pcs).forEach(function (uid) {
            if (!live[uid]) {
                try { pcs[uid].close(); } catch (e) {}
                delete pcs[uid];
                dropTile(uid);
            }
        });
    }

    // ── Media ───────────────────────────────────────────────────────────────
    async function startMedia() {
        // getUserMedia is unavailable on plain HTTP, so say so rather than
        // letting the room open with a permanently blank stage.
        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
            notify('Your browser will not share a camera or microphone on an insecure connection. ' +
                   'Open the system over <strong>https</strong> to use the video room.', 'danger');
            return false;
        }
        try {
            localStream = await navigator.mediaDevices.getUserMedia({ video: true, audio: true });
        } catch (e) {
            try {
                localStream = await navigator.mediaDevices.getUserMedia({ audio: true });
                camOn = false;
                document.getElementById('btnCam').classList.add('off');
                document.getElementById('selfVideo').style.display = 'none';
                document.getElementById('selfAvatar').style.display = 'flex';
                notify('No camera available — you have joined with audio only.', 'info');
            } catch (e2) {
                notify('Could not access your microphone or camera. Check the browser permission prompt, ' +
                       'then reload this page.', 'danger');
                return false;
            }
        }
        document.getElementById('selfVideo').srcObject = localStream;
        return true;
    }

    function replaceOutgoingVideo(track) {
        Object.keys(pcs).forEach(function (uid) {
            var sender = pcs[uid].getSenders().find(function (s) { return s.track && s.track.kind === 'video'; });
            if (sender) sender.replaceTrack(track);
        });
    }

    // ── Controls ────────────────────────────────────────────────────────────
    document.getElementById('btnMic').addEventListener('click', function () {
        if (!localStream) return;
        micOn = !micOn;
        localStream.getAudioTracks().forEach(function (t) { t.enabled = micOn; });
        this.classList.toggle('off', !micOn);
        this.innerHTML = '<i class="fa fa-microphone' + (micOn ? '' : '-slash') + '"></i>';
        document.getElementById('selfMicIcon').className = 'fa fa-microphone' + (micOn ? '' : '-slash');
        post('state', { mic_on: micOn, cam_on: camOn, sharing: sharing });
    });

    document.getElementById('btnCam').addEventListener('click', function () {
        if (!localStream) return;
        camOn = !camOn;
        localStream.getVideoTracks().forEach(function (t) { t.enabled = camOn; });
        this.classList.toggle('off', !camOn);
        this.innerHTML = '<i class="fa fa-video' + (camOn ? '' : '-slash') + '"></i>';
        document.getElementById('selfVideo').style.display = camOn ? '' : 'none';
        document.getElementById('selfAvatar').style.display = camOn ? 'none' : 'flex';
        post('state', { mic_on: micOn, cam_on: camOn, sharing: sharing });
    });

    document.getElementById('btnShare').addEventListener('click', async function () {
        if (!navigator.mediaDevices || !navigator.mediaDevices.getDisplayMedia) {
            notify('Screen sharing is not available in this browser.', 'info');
            return;
        }
        if (sharing) { stopShare(); return; }
        try {
            screenStream = await navigator.mediaDevices.getDisplayMedia({ video: true });
            var track = screenStream.getVideoTracks()[0];
            replaceOutgoingVideo(track);
            document.getElementById('selfVideo').srcObject = screenStream;
            document.getElementById('selfVideo').style.display = '';
            document.getElementById('selfAvatar').style.display = 'none';
            sharing = true;
            this.classList.add('on');
            setSharing('tile-self', true);
            post('state', { mic_on: micOn, cam_on: camOn, sharing: true });
            // The browser's own "stop sharing" bar must put the camera back.
            track.onended = stopShare;
        } catch (e) { /* the picker was dismissed */ }
    });

    function stopShare() {
        if (screenStream) { screenStream.getTracks().forEach(function (t) { t.stop(); }); screenStream = null; }
        sharing = false;
        document.getElementById('btnShare').classList.remove('on');
        setSharing('tile-self', false);
        var cam = localStream && localStream.getVideoTracks()[0];
        if (cam) replaceOutgoingVideo(cam);
        document.getElementById('selfVideo').srcObject = localStream;
        document.getElementById('selfVideo').style.display = camOn ? '' : 'none';
        document.getElementById('selfAvatar').style.display = camOn ? 'none' : 'flex';
        post('state', { mic_on: micOn, cam_on: camOn, sharing: false });
    }

    function leave(redirect) {
        if (pollTimer) clearInterval(pollTimer);
        Object.keys(pcs).forEach(function (uid) { try { pcs[uid].close(); } catch (e) {} });
        pcs = {};
        if (localStream)  localStream.getTracks().forEach(function (t) { t.stop(); });
        if (screenStream) screenStream.getTracks().forEach(function (t) { t.stop(); });
        // keepalive lets this survive the page unloading.
        try {
            fetch(API + '?action=leave&meeting_id=' + MEETING_ID, {
                method: 'POST', keepalive: true,
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
                body: JSON.stringify({ meeting_id: MEETING_ID, csrf_token: CSRF })
            });
        } catch (e) {}
        if (redirect) location.href = <?= json_encode(BASE_URL . '/modules/meetings/view.php?id=' . $id) ?>;
    }

    document.getElementById('btnLeave').addEventListener('click', function () { leave(true); });
    window.addEventListener('pagehide', function () { if (joined) leave(false); });

    // ── Join + poll loop ────────────────────────────────────────────────────
    async function start() {
        var ok = await startMedia();
        if (!ok) return;

        var d = await post('join', {});
        if (!d || !d.ok) { notify('Could not join the room.', 'danger'); return; }
        joined = true;
        clearNotify();

        (d.peers || []).forEach(function (p) {
            rosterNames[p.user_id] = p.name;
            offerTo(p.user_id, p.name);
        });
        renderRoster(d.peers || []);

        // 2s keeps the handshake responsive; once connected the media path is
        // direct, so this loop only carries roster changes and later signals.
        pollTimer = setInterval(poll, 2000);
        poll();
    }

    async function poll() {
        try {
            var r = await fetch(API + '?action=poll&meeting_id=' + MEETING_ID);
            var d = await r.json();
            if (!d || !d.ok) return;
            for (var i = 0; i < (d.signals || []).length; i++) await handleSignal(d.signals[i]);
            renderRoster(d.peers || []);
        } catch (e) { /* transient — the next tick retries */ }
    }

    start();
}());
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>

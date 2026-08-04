<?php
/**
 * Call Centre — the agent's softphone.
 *
 * Audio runs browser-to-provider over WebRTC; this page is the keypad, the
 * call state and the record-keeping around it. The provider's client library
 * is loaded from their CDN — if it cannot be reached the page says so plainly
 * rather than presenting a dial pad that silently does nothing.
 */
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/_bootstrap.php';
requireLogin();
canAccess('callcenter') || redirect(BASE_URL . '/index.php');

$db = getDB();
ccMigrate($db);

$me   = authUser();
$meId = (int)$me['id'];
$cfg  = ccConfig();
$ready = ccReady($cfg);

// Prefilled from a client or lead page ("call this person").
$preNumber = ccNormalizeNumber((string)($_GET['to'] ?? ''));
$preName   = trim((string)($_GET['name'] ?? ''));
$preLead   = (int)($_GET['lead_id'] ?? 0);

$recent = [];
try {
    $st = $db->prepare("SELECT c.*, u.name AS agent_name FROM call_logs c
                        LEFT JOIN users u ON u.id = c.agent_id
                        WHERE c.agent_id = ? ORDER BY c.id DESC LIMIT 12");
    $st->execute([$meId]);
    $recent = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (\Throwable $_) {}

$balance = $ready['ok'] ? ccBalance() : ['ok' => false];
$minutes = $ready['ok'] ? ccEstimatedMinutes($balance, $cfg) : null;

$pageTitle = 'Dialer';
include __DIR__ . '/../../includes/header.php';
?>
<style>
.dl-wrap{ display:grid; grid-template-columns:340px minmax(0,1fr); gap:16px; align-items:start; }
@media(max-width:900px){ .dl-wrap{ grid-template-columns:minmax(0,1fr); } }

.dl-card{ background:var(--surface); border:1px solid var(--border); border-radius:var(--r-lg);
    box-shadow:var(--sh-sm); overflow:hidden; margin-bottom:16px; }
.dl-head{ padding:12px 15px; border-bottom:1px solid var(--border); background:var(--surface-alt);
    display:flex; align-items:center; justify-content:space-between; gap:10px; }
.dl-title{ font-size:13px; font-weight:700; color:var(--text); margin:0; display:flex; align-items:center; gap:8px; }
.dl-title i{ color:var(--brand); }
.dl-body{ padding:15px; }

.dl-screen{ background:#0b1120; border-radius:var(--r); padding:16px; text-align:center; margin-bottom:14px; }
.dl-num{ font-size:22px; font-weight:800; letter-spacing:1px; color:#fff; min-height:28px; word-break:break-all; }
.dl-sub{ font-size:12px; color:#94a3b8; margin-top:5px; min-height:17px; }
.dl-timer{ font-size:13px; font-weight:700; color:#16a34a; margin-top:6px; min-height:18px; }

.dl-pad{ display:grid; grid-template-columns:repeat(3,1fr); gap:8px; margin-bottom:14px; }
.dl-key{ padding:13px 0; border-radius:var(--r); border:1px solid var(--border); background:var(--surface-alt);
    color:var(--text); font-size:17px; font-weight:700; cursor:pointer; transition:.1s; }
.dl-key:hover{ border-color:var(--brand); background:var(--brand-soft); }
.dl-key small{ display:block; font-size:8.5px; letter-spacing:1px; color:var(--text-2,#64748b); font-weight:600; }

.dl-actions{ display:flex; gap:8px; }
.dl-btn{ flex:1; padding:12px; border-radius:var(--r); border:0; font-size:13.5px; font-weight:700;
    cursor:pointer; display:flex; align-items:center; justify-content:center; gap:7px; }
.dl-call{ background:#16a34a; color:#fff; }
.dl-call:disabled{ background:#94a3b8; cursor:not-allowed; }
.dl-hang{ background:#dc2626; color:#fff; }
.dl-mute{ background:var(--surface-alt); color:var(--text); border:1px solid var(--border); flex:0 0 52px; }
.dl-mute.on{ background:#f59e0b; color:#fff; border-color:#f59e0b; }

.dl-state{ display:flex; align-items:center; gap:7px; font-size:12px; font-weight:600; }
.dl-dot{ width:9px; height:9px; border-radius:50%; }
.dl-row{ display:flex; align-items:center; gap:10px; padding:10px 15px; border-bottom:1px solid var(--border); }
.dl-row:last-child{ border-bottom:0; }
.dl-av{ width:32px; height:32px; border-radius:50%; flex:0 0 32px; color:#fff; font-size:11px; font-weight:800;
    display:flex; align-items:center; justify-content:center; }
.dl-tag{ font-size:10.5px; font-weight:700; padding:2px 9px; border-radius:20px; white-space:nowrap; }
.dl-empty{ text-align:center; padding:26px 14px; color:var(--text-2,#64748b); font-size:12.5px; }
.dl-empty i{ font-size:26px; opacity:.3; display:block; margin-bottom:8px; }

/* Incoming-call banner */
#dlIncoming{ display:none; position:fixed; right:20px; bottom:20px; z-index:1080; width:300px;
    background:var(--surface); border:1px solid var(--border); border-radius:var(--r-lg);
    box-shadow:var(--sh-lg); overflow:hidden; }
#dlIncoming .hd{ padding:11px 14px; background:#16a34a; color:#fff; font-size:12.5px; font-weight:700;
    display:flex; align-items:center; gap:8px; }
#dlIncoming .bd{ padding:14px; }
@keyframes dlPulse{ 0%,100%{opacity:1} 50%{opacity:.45} }
.dl-pulse{ animation:dlPulse 1s ease-in-out infinite; }
</style>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div>
        <h1 style="font-size:20px;font-weight:800;letter-spacing:-.4px;margin:0;color:var(--text)">
            <i class="fa fa-headset me-2" style="color:var(--brand)"></i>Dialer
        </h1>
        <div class="small text-muted">
            <?php if ($cfg['caller_id']): ?>
                Calls show as <strong><?= e(ccPrettyNumber($cfg['caller_id'])) ?></strong> — clients ring that number back.
            <?php else: ?>
                No shared number configured yet.
            <?php endif; ?>
        </div>
    </div>
    <div class="d-flex gap-2">
        <a href="<?= BASE_URL ?>/modules/callcenter/logs.php" class="btn btn-sm btn-outline-primary">
            <i class="fa fa-list me-1"></i>Call Log
        </a>
        <a href="<?= BASE_URL ?>/modules/callcenter/index.php" class="btn btn-sm btn-outline-secondary">
            <i class="fa fa-arrow-left me-1"></i>Overview
        </a>
    </div>
</div>

<?php if (!$ready['ok']): ?>
<div class="alert alert-warning">
    <strong><i class="fa fa-triangle-exclamation me-1"></i>Calls cannot be made yet.</strong>
    <div class="small mt-1">Still needed: <?= e(implode(', ', $ready['missing'])) ?>.</div>
    <?php if (canWrite('callcenter')): ?>
    <a href="<?= BASE_URL ?>/modules/callcenter/settings.php" class="btn btn-sm btn-warning mt-2">
        <i class="fa fa-gear me-1"></i>Open call-centre settings
    </a>
    <?php endif; ?>
</div>
<?php elseif (!empty($balance['ok']) && $balance['amount'] <= $cfg['low_balance']): ?>
<div class="alert alert-<?= $balance['amount'] <= 0 ? 'danger' : 'warning' ?> py-2 d-flex justify-content-between align-items-center flex-wrap gap-2">
    <span class="small">
        <i class="fa fa-coins me-1"></i>
        Airtime <?= $balance['amount'] <= 0 ? 'is exhausted' : 'is low' ?>:
        <strong><?= e($balance['currency']) ?> <?= number_format($balance['amount'], 2) ?></strong>
        <?= $minutes !== null ? ' — roughly ' . $minutes . ' minute' . ($minutes === 1 ? '' : 's') . ' left' : '' ?>.
    </span>
    <a href="<?= BASE_URL ?>/modules/callcenter/topup.php" class="btn btn-sm btn-dark">Top up</a>
</div>
<?php endif; ?>

<div id="dlAlert"></div>

<div class="dl-wrap">
    <!-- ── Softphone ──────────────────────────────────────────────────── -->
    <div>
        <div class="dl-card">
            <div class="dl-head">
                <h2 class="dl-title"><i class="fa fa-phone"></i>Softphone</h2>
                <span class="dl-state" id="dlState">
                    <span class="dl-dot" style="background:#94a3b8"></span><span>Connecting…</span>
                </span>
            </div>
            <div class="dl-body">
                <div class="dl-screen">
                    <div class="dl-num"  id="dlNumber"><?= e($preNumber ? ccPrettyNumber($preNumber) : '') ?></div>
                    <div class="dl-sub"  id="dlContact"><?= e($preName) ?></div>
                    <div class="dl-timer" id="dlTimer"></div>
                </div>

                <div class="dl-pad" id="dlPad">
                    <?php foreach ([['1',''],['2','ABC'],['3','DEF'],['4','GHI'],['5','JKL'],['6','MNO'],
                                    ['7','PQRS'],['8','TUV'],['9','WXYZ'],['*',''],['0','+'],['#','']] as [$k,$sub]): ?>
                    <button type="button" class="dl-key" data-k="<?= $k ?>"><?= $k ?><small><?= $sub ?: '&nbsp;' ?></small></button>
                    <?php endforeach; ?>
                </div>

                <div class="dl-actions mb-2">
                    <button class="dl-btn dl-mute" id="dlMute" title="Mute" disabled><i class="fa fa-microphone"></i></button>
                    <button class="dl-btn dl-call" id="dlCall" <?= $ready['ok'] ? '' : 'disabled' ?>>
                        <i class="fa fa-phone"></i>Call
                    </button>
                    <button class="dl-btn dl-hang" id="dlHang" style="display:none">
                        <i class="fa fa-phone-slash"></i>Hang up
                    </button>
                </div>
                <button type="button" class="btn btn-sm btn-outline-secondary w-100" id="dlBack">
                    <i class="fa fa-delete-left me-1"></i>Delete
                </button>
            </div>
        </div>

        <div class="dl-card">
            <div class="dl-head">
                <h2 class="dl-title"><i class="fa fa-user-clock"></i>My Status</h2>
            </div>
            <div class="dl-body">
                <select id="dlAgentState" class="form-select form-select-sm">
                    <option value="available">Available for calls</option>
                    <option value="paused">Paused — do not ring me</option>
                    <option value="offline">Offline</option>
                </select>
                <div class="form-text" style="font-size:11px">
                    Inbound calls only ring agents marked available with this page open.
                </div>
            </div>
        </div>
    </div>

    <!-- ── Right column ───────────────────────────────────────────────── -->
    <div>
        <div class="dl-card">
            <div class="dl-head">
                <h2 class="dl-title"><i class="fa fa-users"></i>Agents Online</h2>
                <span class="small text-muted" id="dlAgentCount">—</span>
            </div>
            <div id="dlAgents"><div class="dl-empty"><i class="fa fa-headset"></i>Loading…</div></div>
        </div>

        <div class="dl-card">
            <div class="dl-head">
                <h2 class="dl-title"><i class="fa fa-clock-rotate-left"></i>My Recent Calls</h2>
                <a href="<?= BASE_URL ?>/modules/callcenter/logs.php" class="btn btn-xs btn-outline-primary">All</a>
            </div>
            <?php if (!$recent): ?>
                <div class="dl-empty"><i class="fa fa-phone-slash"></i>No calls yet.</div>
            <?php else: foreach ($recent as $c):
                [$sl, $sc] = ccCallStatuses()[$c['status']] ?? ['—', '#64748b'];
                $other = $c['direction'] === 'outbound' ? $c['to_number'] : $c['from_number'];
            ?>
            <div class="dl-row">
                <span class="dl-av" style="background:<?= ccAvatarColor((int)$c['id']) ?>">
                    <i class="fa fa-arrow-<?= $c['direction'] === 'outbound' ? 'up' : 'down' ?>"></i>
                </span>
                <div style="flex:1;min-width:0">
                    <div style="font-size:13px;font-weight:600;color:var(--text)"><?= e(ccPrettyNumber($other)) ?></div>
                    <div class="text-muted" style="font-size:11.5px">
                        <?= date('j M, H:i', strtotime($c['created_at'])) ?>
                        <?= (int)$c['duration_sec'] > 0 ? ' · ' . e(ccFormatDuration((int)$c['duration_sec'])) : '' ?>
                    </div>
                </div>
                <span class="dl-tag" style="background:<?= $sc ?>1f;color:<?= $sc ?>"><?= $sl ?></span>
                <button class="btn btn-xs btn-outline-success dl-redial" data-n="<?= e($other) ?>" title="Call back">
                    <i class="fa fa-phone"></i>
                </button>
            </div>
            <?php endforeach; endif; ?>
        </div>
    </div>
</div>

<!-- Incoming-call banner -->
<div id="dlIncoming">
    <div class="hd"><i class="fa fa-phone-volume dl-pulse"></i><span>Incoming call</span></div>
    <div class="bd">
        <div style="font-size:16px;font-weight:800;color:var(--text)" id="dlIncNum">—</div>
        <div class="text-muted" style="font-size:12px" id="dlIncName"></div>
        <div class="d-flex gap-2 mt-3">
            <button class="btn btn-success btn-sm flex-fill" id="dlAnswer"><i class="fa fa-phone me-1"></i>Answer</button>
            <button class="btn btn-outline-danger btn-sm flex-fill" id="dlReject">Decline</button>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/africastalking-client@latest/dist/africastalking.min.js"
        onerror="window.__atLoadFailed = true"></script>
<script>
(function () {
    'use strict';

    var API      = <?= json_encode(BASE_URL . '/modules/callcenter/api') ?>;
    var CSRF     = <?= json_encode(csrfToken()) ?>;
    var READY    = <?= $ready['ok'] ? 'true' : 'false' ?>;
    var CALLER   = <?= json_encode($cfg['caller_id']) ?>;

    var atClient = null, activeCall = null, callId = null;
    var muted = false, timerInt = null, seconds = 0, incoming = null;

    var $ = function (id) { return document.getElementById(id); };

    function alertBox(msg, kind) {
        $('dlAlert').innerHTML = '<div class="alert alert-' + (kind || 'warning') +
            ' py-2 small">' + msg + '</div>';
    }
    function clearAlert() { $('dlAlert').innerHTML = ''; }

    function setState(label, color) {
        $('dlState').innerHTML = '<span class="dl-dot" style="background:' + color + '"></span><span>' + label + '</span>';
    }

    function post(action, payload) {
        return fetch(API + '/call.php?action=' + action, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
            body: JSON.stringify(Object.assign({ csrf_token: CSRF }, payload || {}))
        }).then(function (r) { return r.json(); }).catch(function () { return { ok: false }; });
    }

    // ── Keypad ──────────────────────────────────────────────────────────────
    var typed = <?= json_encode($preNumber) ?> || '';
    function renderNumber() {
        $('dlNumber').textContent = typed || '';
        $('dlCall').disabled = !READY || typed.replace(/\D/g, '').length < 7 || activeCall !== null;
    }
    $('dlPad').addEventListener('click', function (e) {
        var b = e.target.closest('.dl-key'); if (!b) return;
        if (activeCall) { sendTone(b.dataset.k); return; }
        typed += b.dataset.k; renderNumber();
    });
    $('dlBack').addEventListener('click', function () { typed = typed.slice(0, -1); renderNumber(); });
    document.addEventListener('keydown', function (e) {
        if (/^[0-9*#+]$/.test(e.key) && document.activeElement.tagName !== 'INPUT') { typed += e.key; renderNumber(); }
        if (e.key === 'Backspace' && document.activeElement.tagName !== 'INPUT') { typed = typed.slice(0,-1); renderNumber(); }
    });
    document.querySelectorAll('.dl-redial').forEach(function (b) {
        b.addEventListener('click', function () { typed = b.dataset.n; renderNumber(); });
    });

    function sendTone(k) { try { if (atClient && atClient.sendDtmf) atClient.sendDtmf(k); } catch (e) {} }

    // ── Timer ───────────────────────────────────────────────────────────────
    function startTimer() {
        seconds = 0;
        timerInt = setInterval(function () {
            seconds++;
            var m = Math.floor(seconds / 60), s = seconds % 60;
            $('dlTimer').textContent = m + ':' + (s < 10 ? '0' : '') + s;
        }, 1000);
    }
    function stopTimer() { clearInterval(timerInt); $('dlTimer').textContent = ''; }

    function inCallUi(on) {
        $('dlCall').style.display = on ? 'none' : '';
        $('dlHang').style.display = on ? '' : 'none';
        $('dlMute').disabled = !on;
        if (!on) { muted = false; $('dlMute').classList.remove('on'); $('dlMute').innerHTML = '<i class="fa fa-microphone"></i>'; }
    }

    // ── Provider client ─────────────────────────────────────────────────────
    function connect() {
        if (!READY) { setState('Not configured', '#dc2626'); return; }

        if (window.__atLoadFailed || typeof window.Africastalking === 'undefined') {
            setState('Offline', '#dc2626');
            alertBox('The calling library could not be loaded. Check this device has internet access, ' +
                     'then reload. Calls cannot be placed until it loads.', 'danger');
            return;
        }

        fetch(API + '/token.php').then(function (r) { return r.json(); }).then(function (d) {
            if (!d.ok) {
                setState('Offline', '#dc2626');
                alertBox('Could not sign in to the phone service. ' + (d.error || ''), 'danger');
                return;
            }
            try {
                atClient = new window.Africastalking.Client(d.token);

                atClient.on('ready',      function () { setState('Ready', '#16a34a'); clearAlert(); });
                atClient.on('notready',   function () { setState('Not ready', '#f59e0b'); });
                atClient.on('offline',    function () { setState('Offline', '#dc2626'); });
                atClient.on('callaccepted', function () {
                    setState('In call', '#16a34a'); startTimer();
                    if (callId) post('update', { call_id: callId, status: 'active' });
                });
                atClient.on('hangup', function (e) {
                    setState('Ready', '#16a34a'); stopTimer(); inCallUi(false);
                    if (callId) post('update', { call_id: callId, status: 'completed',
                                                 hangup_cause: (e && e.reason) || '' });
                    activeCall = null; callId = null; renderNumber();
                });
                atClient.on('incomingcall', function (e) {
                    incoming = e;
                    $('dlIncNum').textContent  = (e && e.from) || 'Unknown';
                    $('dlIncName').textContent = '';
                    $('dlIncoming').style.display = 'block';
                });
            } catch (err) {
                setState('Offline', '#dc2626');
                alertBox('The phone client failed to start: ' + err.message, 'danger');
            }
        }).catch(function () {
            setState('Offline', '#dc2626');
            alertBox('Could not reach the server to sign in to the phone service.', 'danger');
        });
    }

    // ── Place a call ────────────────────────────────────────────────────────
    $('dlCall').addEventListener('click', function () {
        if (!atClient) { alertBox('The phone is not connected yet.', 'warning'); return; }

        post('start', { to: typed, lead_id: <?= (int)$preLead ?> }).then(function (d) {
            if (!d.ok) { alertBox(d.error || 'Could not start the call.', d.no_credit ? 'danger' : 'warning'); return; }
            callId = d.call_id;
            $('dlNumber').textContent  = d.display;
            $('dlContact').textContent = d.contact || '';
            setState('Calling…', '#f59e0b');
            inCallUi(true);
            try {
                activeCall = atClient.call(d.to);
            } catch (err) {
                alertBox('The call could not be placed: ' + err.message, 'danger');
                inCallUi(false);
                post('update', { call_id: callId, status: 'failed', hangup_cause: err.message });
                callId = null;
            }
        });
    });

    $('dlHang').addEventListener('click', function () {
        try { if (atClient) atClient.hangup(); } catch (e) {}
        stopTimer(); inCallUi(false); setState('Ready', '#16a34a');
        if (callId) post('update', { call_id: callId, status: seconds > 0 ? 'completed' : 'no_answer' });
        activeCall = null; callId = null; renderNumber();
    });

    $('dlMute').addEventListener('click', function () {
        muted = !muted;
        try { if (atClient) muted ? atClient.mute() : atClient.unmute(); } catch (e) {}
        this.classList.toggle('on', muted);
        this.innerHTML = '<i class="fa fa-microphone' + (muted ? '-slash' : '') + '"></i>';
    });

    $('dlAnswer').addEventListener('click', function () {
        try { if (atClient) atClient.answer(); } catch (e) {}
        $('dlIncoming').style.display = 'none';
        setState('In call', '#16a34a'); startTimer(); inCallUi(true);
    });
    $('dlReject').addEventListener('click', function () {
        try { if (atClient) atClient.hangup(); } catch (e) {}
        $('dlIncoming').style.display = 'none';
    });

    // ── Presence + poll ─────────────────────────────────────────────────────
    $('dlAgentState').addEventListener('change', function () { post('state', { state: this.value }); });

    function renderAgents(list) {
        $('dlAgentCount').textContent = list.length;
        if (!list.length) { $('dlAgents').innerHTML = '<div class="dl-empty"><i class="fa fa-headset"></i>Nobody signed in.</div>'; return; }
        var colors = { available: '#16a34a', busy: '#f59e0b', paused: '#64748b', offline: '#94a3b8' };
        $('dlAgents').innerHTML = list.map(function (a) {
            var c = colors[a.state] || '#94a3b8';
            return '<div class="dl-row"><span class="dl-dot" style="background:' + c + '"></span>' +
                   '<span style="flex:1;font-size:13px;color:var(--text)">' + esc(a.name) + '</span>' +
                   '<span class="text-muted" style="font-size:11.5px">' + (a.calls_today || 0) + ' today</span></div>';
        }).join('');
    }
    function esc(s) { return String(s).replace(/[&<>"']/g, function (c) {
        return { '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[c]; }); }

    function poll() {
        if (document.hidden) return;
        fetch(API + '/call.php?action=status').then(function (r) { return r.json(); }).then(function (d) {
            if (!d || !d.ok) return;
            renderAgents(d.agents || []);
            // A call the router assigned to me — shown even if the WebRTC event
            // was missed, so a call-back is never silently dropped.
            if (d.incoming && !activeCall && $('dlIncoming').style.display !== 'block') {
                $('dlIncNum').textContent  = d.incoming.display || '';
                $('dlIncName').textContent = d.incoming.contact || '';
                $('dlIncoming').style.display = 'block';
            }
            if (d.low_credit) {
                alertBox('Airtime is running low' + (d.minutes != null ? ' — about ' + d.minutes + ' minutes left' : '') +
                         '. <a href="<?= BASE_URL ?>/modules/callcenter/topup.php">Top up</a>.', 'warning');
            }
        }).catch(function () {});
    }

    renderNumber();
    connect();
    poll();
    setInterval(poll, 5000);

    window.addEventListener('pagehide', function () {
        try {
            fetch(API + '/call.php?action=state', {
                method: 'POST', keepalive: true,
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
                body: JSON.stringify({ state: 'offline', csrf_token: CSRF })
            });
        } catch (e) {}
    });
}());
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>

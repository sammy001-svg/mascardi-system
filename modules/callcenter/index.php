<?php
/**
 * Call Centre — overview.
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
$balance = $ready['ok'] ? ccBalance() : ['ok' => false];
$minutes = $ready['ok'] ? ccEstimatedMinutes($balance, $cfg) : null;

$stats = ['today' => 0, 'answered' => 0, 'missed' => 0, 'talk' => 0, 'inbound' => 0, 'outbound' => 0];
try {
    $r = $db->query("SELECT
            COUNT(*) today,
            COALESCE(SUM(status='completed'),0) answered,
            COALESCE(SUM(status IN ('missed','no_answer')),0) missed,
            COALESCE(SUM(duration_sec),0) talk,
            COALESCE(SUM(direction='inbound'),0) inbound,
            COALESCE(SUM(direction='outbound'),0) outbound
          FROM call_logs WHERE DATE(created_at) = CURDATE()")->fetch(PDO::FETCH_ASSOC) ?: [];
    foreach ($stats as $k => $_) $stats[$k] = (int)($r[$k] ?? 0);
} catch (\Throwable $_) {}

$agents = [];
try {
    $agents = $db->query("SELECT a.*, u.name FROM call_agents a JOIN users u ON u.id = a.user_id
                          WHERE a.last_seen > DATE_SUB(NOW(), INTERVAL 5 MINUTE)
                          ORDER BY FIELD(a.state,'busy','available','paused','offline'), u.name")
                 ->fetchAll(PDO::FETCH_ASSOC);
} catch (\Throwable $_) {}

$recent = [];
try {
    $recent = $db->query("SELECT c.*, u.name AS agent_name, cl.name AS client_name
                          FROM call_logs c
                          LEFT JOIN users u ON u.id = c.agent_id
                          LEFT JOIN clients cl ON cl.id = c.client_id
                          ORDER BY c.id DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
} catch (\Throwable $_) {}

$missedOpen = 0;
try {
    $missedOpen = (int)$db->query("SELECT COUNT(*) FROM call_logs
        WHERE direction='inbound' AND status IN ('missed','no_answer') AND DATE(created_at)=CURDATE()")->fetchColumn();
} catch (\Throwable $_) {}

$pageTitle = 'Call Centre';
include __DIR__ . '/../../includes/header.php';
?>
<style>
.cc-hero{ background:linear-gradient(135deg,#1e1b4b 0%,#4338ca 55%,#6366f1 100%);
    border-radius:var(--r-xl); padding:24px 28px; color:#fff; margin-bottom:18px; }
.cc-hero h1{ font-size:22px; font-weight:800; letter-spacing:-.5px; margin:0 0 3px; color:#fff; }
.cc-hero p{ font-size:13px; opacity:.8; margin:0; }
.cc-kpis{ display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:12px; margin-top:20px; }
@media(max-width:900px){ .cc-kpis{ grid-template-columns:repeat(2,minmax(0,1fr)); } }
.cc-kpi{ background:rgba(255,255,255,.12); border:1px solid rgba(255,255,255,.2); border-radius:var(--r); padding:12px 14px; }
.cc-kpi-v{ font-size:21px; font-weight:900; color:#fff; line-height:1.15; }
.cc-kpi-l{ font-size:10.5px; opacity:.75; text-transform:uppercase; letter-spacing:.6px; margin-top:4px; }

.cc-grid{ display:grid; grid-template-columns:repeat(auto-fit,minmax(320px,1fr)); gap:16px; }
.cc-card{ background:var(--surface); border:1px solid var(--border); border-radius:var(--r-lg);
    box-shadow:var(--sh-sm); overflow:hidden; margin-bottom:16px; }
.cc-head{ padding:12px 16px; border-bottom:1px solid var(--border); background:var(--surface-alt);
    display:flex; align-items:center; justify-content:space-between; gap:10px; }
.cc-title{ font-size:13.5px; font-weight:700; color:var(--text); margin:0; display:flex; align-items:center; gap:8px; }
.cc-title i{ color:var(--brand); }
.cc-row{ display:flex; align-items:center; gap:11px; padding:11px 16px; border-bottom:1px solid var(--border); }
.cc-row:last-child{ border-bottom:0; }
.cc-av{ width:32px; height:32px; border-radius:50%; flex:0 0 32px; color:#fff; font-size:11px; font-weight:800;
    display:flex; align-items:center; justify-content:center; }
.cc-tag{ font-size:10.5px; font-weight:700; padding:2px 9px; border-radius:20px; white-space:nowrap; }
.cc-dot{ width:9px; height:9px; border-radius:50%; flex:0 0 9px; }
.cc-empty{ text-align:center; padding:32px 16px; color:var(--text-2,#64748b); font-size:13px; }
.cc-empty i{ font-size:28px; opacity:.3; display:block; margin-bottom:9px; }
.cc-links{ display:grid; grid-template-columns:repeat(auto-fit,minmax(150px,1fr)); gap:10px; }
.cc-link{ display:flex; align-items:center; gap:10px; padding:13px 14px; text-decoration:none;
    background:var(--surface); border:1px solid var(--border); border-radius:var(--r); color:var(--text); transition:.12s; }
.cc-link:hover{ transform:translateY(-2px); box-shadow:var(--sh); border-color:var(--brand); color:var(--text); }
.cc-link-i{ width:36px; height:36px; border-radius:9px; flex:0 0 36px; background:var(--brand-soft); color:var(--brand);
    display:flex; align-items:center; justify-content:center; font-size:15px; }
</style>

<div class="cc-hero">
    <div class="d-flex justify-content-between align-items-start gap-3 flex-wrap">
        <div>
            <h1><i class="fa fa-headset me-2"></i>Call Centre</h1>
            <p>
                <?php if ($cfg['caller_id']): ?>
                    One shared number — <strong><?= e(ccPrettyNumber($cfg['caller_id'])) ?></strong> — for every agent.
                <?php else: ?>
                    No shared number configured yet.
                <?php endif; ?>
            </p>
        </div>
        <a href="<?= BASE_URL ?>/modules/callcenter/dialer.php" class="btn btn-light btn-sm">
            <i class="fa fa-phone me-1"></i>Open Dialer
        </a>
    </div>

    <div class="cc-kpis">
        <div class="cc-kpi">
            <div class="cc-kpi-v"><?= $stats['today'] ?></div>
            <div class="cc-kpi-l">Calls Today</div>
        </div>
        <div class="cc-kpi">
            <div class="cc-kpi-v"><?= $stats['answered'] ?></div>
            <div class="cc-kpi-l">Answered</div>
        </div>
        <div class="cc-kpi">
            <div class="cc-kpi-v" style="<?= $stats['missed'] ? 'color:#fecaca' : '' ?>"><?= $stats['missed'] ?></div>
            <div class="cc-kpi-l">Missed</div>
        </div>
        <div class="cc-kpi">
            <div class="cc-kpi-v" style="font-size:<?= !empty($balance['ok']) && $balance['amount'] >= 100000 ? '16' : '21' ?>px">
                <?= !empty($balance['ok']) ? e($balance['currency']) . ' ' . number_format($balance['amount'], 0) : '—' ?>
            </div>
            <div class="cc-kpi-l"><?= $minutes !== null ? '~' . number_format($minutes) . ' min left' : 'Airtime' ?></div>
        </div>
    </div>
</div>

<?php if (!$ready['ok']): ?>
<div class="alert alert-warning d-flex justify-content-between align-items-center flex-wrap gap-2">
    <span class="small">
        <i class="fa fa-triangle-exclamation me-1"></i>
        Calls cannot be made or received yet — still needed: <strong><?= e(implode(', ', $ready['missing'])) ?></strong>.
    </span>
    <?php if (canWrite('callcenter')): ?>
    <a href="<?= BASE_URL ?>/modules/callcenter/settings.php" class="btn btn-sm btn-warning">
        <i class="fa fa-gear me-1"></i>Set it up
    </a>
    <?php endif; ?>
</div>
<?php elseif (!empty($balance['ok']) && $balance['amount'] <= $cfg['low_balance']): ?>
<div class="alert alert-<?= $balance['amount'] <= 0 ? 'danger' : 'warning' ?> d-flex justify-content-between align-items-center flex-wrap gap-2">
    <span class="small">
        <i class="fa fa-coins me-1"></i>
        <?= $balance['amount'] <= 0
            ? 'Airtime is exhausted — calls will not connect.'
            : 'Airtime is low: ' . e($balance['currency']) . ' ' . number_format($balance['amount'], 2) ?>
        <?= $minutes !== null ? ' (about ' . $minutes . ' minutes left)' : '' ?>
    </span>
    <a href="<?= BASE_URL ?>/modules/callcenter/topup.php" class="btn btn-sm btn-dark">Top up</a>
</div>
<?php endif; ?>

<?php if ($missedOpen): ?>
<div class="alert alert-danger py-2 d-flex justify-content-between align-items-center flex-wrap gap-2">
    <span class="small">
        <i class="fa fa-phone-slash me-1"></i>
        <strong><?= $missedOpen ?></strong> missed inbound call<?= $missedOpen === 1 ? '' : 's' ?> today — nobody was
        available to answer.
    </span>
    <a href="<?= BASE_URL ?>/modules/callcenter/logs.php?status=missed" class="btn btn-sm btn-danger">Review</a>
</div>
<?php endif; ?>

<div class="cc-grid">
    <div class="cc-card">
        <div class="cc-head">
            <h2 class="cc-title"><i class="fa fa-users"></i>Agents</h2>
            <span class="small text-muted"><?= count($agents) ?> signed in</span>
        </div>
        <?php if (!$agents): ?>
            <div class="cc-empty">
                <i class="fa fa-headset"></i>
                Nobody is signed in to take calls.<br>
                <span class="small">Agents appear here when they open the dialer.</span>
            </div>
        <?php else: foreach ($agents as $a):
            [$sl, $sc] = ccAgentStates()[$a['state']] ?? ['Offline', '#94a3b8'];
        ?>
        <div class="cc-row">
            <span class="cc-dot" style="background:<?= $sc ?>"></span>
            <span class="cc-av" style="background:<?= ccAvatarColor((int)$a['user_id']) ?>"><?= e(ccInitials($a['name'])) ?></span>
            <div style="flex:1;min-width:0">
                <div style="font-size:13px;font-weight:600;color:var(--text)"><?= e($a['name']) ?></div>
                <div class="text-muted" style="font-size:11.5px"><?= (int)$a['calls_today'] ?> call<?= (int)$a['calls_today'] === 1 ? '' : 's' ?> today</div>
            </div>
            <span class="cc-tag" style="background:<?= $sc ?>1f;color:<?= $sc ?>"><?= $sl ?></span>
        </div>
        <?php endforeach; endif; ?>
    </div>

    <div class="cc-card">
        <div class="cc-head">
            <h2 class="cc-title"><i class="fa fa-clock-rotate-left"></i>Recent Calls</h2>
            <a href="<?= BASE_URL ?>/modules/callcenter/logs.php" class="btn btn-xs btn-outline-primary">All</a>
        </div>
        <?php if (!$recent): ?>
            <div class="cc-empty"><i class="fa fa-phone-slash"></i>No calls yet.</div>
        <?php else: foreach ($recent as $c):
            [$sl, $sc] = ccCallStatuses()[$c['status']] ?? ['—', '#64748b'];
            $other = $c['direction'] === 'outbound' ? $c['to_number'] : $c['from_number'];
        ?>
        <div class="cc-row">
            <span class="cc-av" style="background:<?= $c['direction'] === 'inbound' ? '#0891b2' : '#4f46e5' ?>">
                <i class="fa fa-arrow-<?= $c['direction'] === 'outbound' ? 'up' : 'down' ?>"></i>
            </span>
            <div style="flex:1;min-width:0">
                <div style="font-size:13px;font-weight:600;color:var(--text)">
                    <?= e($c['client_name'] ?: ccPrettyNumber($other)) ?>
                </div>
                <div class="text-muted" style="font-size:11.5px">
                    <?= date('j M, H:i', strtotime($c['created_at'])) ?>
                    <?= $c['agent_name'] ? ' · ' . e($c['agent_name']) : '' ?>
                    <?= (int)$c['duration_sec'] > 0 ? ' · ' . e(ccFormatDuration((int)$c['duration_sec'])) : '' ?>
                </div>
            </div>
            <span class="cc-tag" style="background:<?= $sc ?>1f;color:<?= $sc ?>"><?= $sl ?></span>
        </div>
        <?php endforeach; endif; ?>
    </div>
</div>

<div class="cc-card">
    <div class="cc-head"><h2 class="cc-title"><i class="fa fa-bolt"></i>Call Centre</h2></div>
    <div style="padding:16px">
        <div class="cc-links">
            <a class="cc-link" href="<?= BASE_URL ?>/modules/callcenter/dialer.php">
                <span class="cc-link-i"><i class="fa fa-phone"></i></span>
                <span><span style="font-size:13px;font-weight:700;display:block">Dialer</span>
                <span style="font-size:11px;color:var(--text-2,#64748b)">Call from your laptop</span></span>
            </a>
            <a class="cc-link" href="<?= BASE_URL ?>/modules/callcenter/logs.php">
                <span class="cc-link-i"><i class="fa fa-list"></i></span>
                <span><span style="font-size:13px;font-weight:700;display:block">Call Log</span>
                <span style="font-size:11px;color:var(--text-2,#64748b)">Every call, in and out</span></span>
            </a>
            <a class="cc-link" href="<?= BASE_URL ?>/modules/callcenter/topup.php">
                <span class="cc-link-i"><i class="fa fa-coins"></i></span>
                <span><span style="font-size:13px;font-weight:700;display:block">Airtime</span>
                <span style="font-size:11px;color:var(--text-2,#64748b)">Balance &amp; top-ups</span></span>
            </a>
            <?php if (canWrite('callcenter')): ?>
            <a class="cc-link" href="<?= BASE_URL ?>/modules/callcenter/settings.php">
                <span class="cc-link-i"><i class="fa fa-gear"></i></span>
                <span><span style="font-size:13px;font-weight:700;display:block">Settings</span>
                <span style="font-size:11px;color:var(--text-2,#64748b)">Number &amp; provider</span></span>
            </a>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>

<?php
/**
 * Call Centre — call log.
 */
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/_bootstrap.php';
requireLogin();
canAccess('callcenter') || redirect(BASE_URL . '/index.php');

$db = getDB();
ccMigrate($db);

$me   = authUser();
$meId = (int)$me['id'];

$fDir    = $_GET['dir'] ?? '';
$fStatus = $_GET['status'] ?? '';
$fAgent  = (int)($_GET['agent'] ?? 0);
$fSearch = trim($_GET['q'] ?? '');
$mineOnly= !empty($_GET['mine']);

$where = ['1=1']; $params = [];
if (in_array($fDir, ['inbound','outbound'], true)) { $where[] = 'c.direction = ?'; $params[] = $fDir; }
if (isset(ccCallStatuses()[$fStatus]))             { $where[] = 'c.status = ?';    $params[] = $fStatus; }
elseif ($fStatus === 'missed')                     { $where[] = "c.status IN ('missed','no_answer')"; $fStatus = 'missed'; }
else                                                { $fStatus = ''; }
if ($mineOnly) { $fAgent = $meId; }
if ($fAgent)   { $where[] = 'c.agent_id = ?'; $params[] = $fAgent; }
if ($fSearch !== '') {
    $where[] = '(c.from_number LIKE ? OR c.to_number LIKE ? OR cl.name LIKE ? OR c.notes LIKE ?)';
    $like = '%' . $fSearch . '%';
    array_push($params, $like, $like, $like, $like);
}

$rows = [];
try {
    $st = $db->prepare("SELECT c.*, u.name AS agent_name, cl.name AS client_name, l.name AS lead_name
                        FROM call_logs c
                        LEFT JOIN users u ON u.id = c.agent_id
                        LEFT JOIN clients cl ON cl.id = c.client_id
                        LEFT JOIN crm_leads l ON l.id = c.lead_id
                        WHERE " . implode(' AND ', $where) . "
                        ORDER BY c.id DESC LIMIT 300");
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (\Throwable $e) { error_log('cc/logs: ' . $e->getMessage()); }

$agents = [];
try {
    $agents = $db->query("SELECT DISTINCT u.id, u.name FROM call_logs c
                          JOIN users u ON u.id = c.agent_id ORDER BY u.name")->fetchAll(PDO::FETCH_ASSOC);
} catch (\Throwable $_) {}

$sum = ['calls' => count($rows), 'talk' => 0, 'cost' => 0.0];
foreach ($rows as $r) { $sum['talk'] += (int)$r['duration_sec']; $sum['cost'] += (float)$r['cost_amount']; }

$pageTitle = 'Call Log';
include __DIR__ . '/../../includes/header.php';
?>
<style>
.lg-chips{ display:flex; gap:8px; flex-wrap:wrap; margin-bottom:14px; }
.lg-chip{ padding:6px 14px; border-radius:20px; font-size:12.5px; font-weight:600; text-decoration:none;
    border:1px solid var(--border); background:var(--surface); color:var(--text); transition:.12s; }
.lg-chip:hover{ border-color:var(--brand); color:var(--brand); }
.lg-chip.on{ background:var(--brand); border-color:var(--brand); color:#fff; }
.lg-card{ background:var(--surface); border:1px solid var(--border); border-radius:var(--r-lg);
    box-shadow:var(--sh-sm); overflow:hidden; }
.lg-row{ display:flex; align-items:center; gap:12px; padding:12px 16px; border-bottom:1px solid var(--border); }
.lg-row:last-child{ border-bottom:0; }
.lg-ic{ width:34px; height:34px; border-radius:50%; flex:0 0 34px; color:#fff; font-size:12px;
    display:flex; align-items:center; justify-content:center; }
.lg-tag{ font-size:10.5px; font-weight:700; padding:2px 9px; border-radius:20px; white-space:nowrap; }
.lg-empty{ text-align:center; padding:44px 20px; color:var(--text-2,#64748b); }
.lg-empty i{ font-size:32px; opacity:.3; display:block; margin-bottom:11px; }
.lg-stats{ display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:12px; margin-bottom:14px; }
.lg-stat{ background:var(--surface); border:1px solid var(--border); border-radius:var(--r-lg); padding:13px 15px; box-shadow:var(--sh-sm); }
.lg-stat-v{ font-size:20px; font-weight:900; color:var(--text); }
.lg-stat-l{ font-size:10.5px; text-transform:uppercase; letter-spacing:.6px; color:var(--text-2,#64748b); margin-top:4px; }
</style>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h1 style="font-size:20px;font-weight:800;letter-spacing:-.4px;margin:0;color:var(--text)">
        <i class="fa fa-list me-2" style="color:var(--brand)"></i>Call Log
    </h1>
    <div class="d-flex gap-2">
        <a href="<?= BASE_URL ?>/modules/callcenter/dialer.php" class="btn btn-sm btn-primary">
            <i class="fa fa-phone me-1"></i>Dialer
        </a>
        <a href="<?= BASE_URL ?>/modules/callcenter/index.php" class="btn btn-sm btn-outline-secondary">
            <i class="fa fa-arrow-left me-1"></i>Back
        </a>
    </div>
</div>

<div class="lg-stats">
    <div class="lg-stat"><div class="lg-stat-v"><?= number_format($sum['calls']) ?></div><div class="lg-stat-l">Calls shown</div></div>
    <div class="lg-stat"><div class="lg-stat-v"><?= ccFormatDuration($sum['talk']) ?></div><div class="lg-stat-l">Total talk time</div></div>
    <div class="lg-stat"><div class="lg-stat-v"><?= number_format($sum['cost'], 2) ?></div><div class="lg-stat-l">Airtime spent</div></div>
</div>

<div class="lg-chips">
    <?php
    $base = array_filter(['agent' => $fAgent ?: null, 'q' => $fSearch ?: null]);
    foreach ([['', '', 'All'], ['dir','outbound','Outgoing'], ['dir','inbound','Incoming'],
              ['status','missed','Missed'], ['status','completed','Answered']] as [$k,$v,$lbl]):
        $on = ($k === 'dir' && $fDir === $v) || ($k === 'status' && $fStatus === $v)
              || ($k === '' && !$fDir && !$fStatus);
        $q = $base; if ($k) $q[$k] = $v;
    ?>
    <a class="lg-chip <?= $on ? 'on' : '' ?>" href="?<?= http_build_query($q) ?>"><?= $lbl ?></a>
    <?php endforeach; ?>
    <a class="lg-chip <?= $fAgent === $meId ? 'on' : '' ?>"
       href="?<?= http_build_query(array_filter(['mine'=>1,'dir'=>$fDir ?: null,'status'=>$fStatus ?: null])) ?>">Mine</a>
</div>

<form method="GET" class="d-flex gap-2 align-items-center mb-3 flex-wrap">
    <?php if ($fDir): ?><input type="hidden" name="dir" value="<?= e($fDir) ?>"><?php endif; ?>
    <?php if ($fStatus): ?><input type="hidden" name="status" value="<?= e($fStatus) ?>"><?php endif; ?>
    <input type="text" name="q" value="<?= e($fSearch) ?>" class="form-control form-control-sm"
           style="width:240px" placeholder="Search number, client, note…">
    <select name="agent" class="form-select form-select-sm" style="width:200px" onchange="this.form.submit()">
        <option value="">All agents</option>
        <?php foreach ($agents as $a): ?>
        <option value="<?= (int)$a['id'] ?>" <?= $fAgent === (int)$a['id'] ? 'selected' : '' ?>>
            <?= e($a['name']) ?><?= (int)$a['id'] === $meId ? ' (you)' : '' ?>
        </option>
        <?php endforeach; ?>
    </select>
    <button class="btn btn-sm btn-outline-secondary"><i class="fa fa-magnifying-glass"></i></button>
    <?php if ($fDir || $fStatus || $fAgent || $fSearch !== ''): ?>
    <a href="?" class="btn btn-sm btn-outline-secondary">Clear</a>
    <?php endif; ?>
</form>

<div class="lg-card">
    <?php if (!$rows): ?>
        <div class="lg-empty"><i class="fa fa-phone-slash"></i>No calls match.</div>
    <?php else: foreach ($rows as $c):
        [$sl, $sc] = ccCallStatuses()[$c['status']] ?? ['—', '#64748b'];
        $out   = $c['direction'] === 'outbound';
        $other = $out ? $c['to_number'] : $c['from_number'];
        $who   = $c['client_name'] ?: $c['lead_name'];
    ?>
    <div class="lg-row">
        <span class="lg-ic" style="background:<?= $out ? '#4f46e5' : '#0891b2' ?>">
            <i class="fa fa-arrow-<?= $out ? 'up' : 'down' ?>"></i>
        </span>
        <div style="flex:1;min-width:0">
            <div style="font-size:13.5px;font-weight:700;color:var(--text)">
                <?= e($who ?: ccPrettyNumber($other)) ?>
                <?php if ($who): ?><span class="text-muted fw-normal" style="font-size:12px"> · <?= e(ccPrettyNumber($other)) ?></span><?php endif; ?>
            </div>
            <div class="text-muted" style="font-size:11.5px">
                <?= date('j M Y, H:i', strtotime($c['created_at'])) ?>
                <?= $c['agent_name'] ? ' · ' . e($c['agent_name']) : ' · unassigned' ?>
                <?= (int)$c['duration_sec'] > 0 ? ' · ' . e(ccFormatDuration((int)$c['duration_sec'])) : '' ?>
                <?= $c['cost_amount'] !== null ? ' · ' . e($c['cost_currency'] ?: 'KES') . ' ' . number_format((float)$c['cost_amount'], 2) : '' ?>
            </div>
            <?php if ($c['notes']): ?>
            <div class="text-muted" style="font-size:11.5px;font-style:italic">“<?= e($c['notes']) ?>”</div>
            <?php endif; ?>
        </div>
        <span class="lg-tag" style="background:<?= $sc ?>1f;color:<?= $sc ?>"><?= $sl ?></span>
        <div class="d-flex gap-1">
            <?php if ($c['recording_url']): ?>
            <a href="<?= e($c['recording_url']) ?>" target="_blank" rel="noopener"
               class="btn btn-xs btn-outline-primary" title="Play recording"><i class="fa fa-play"></i></a>
            <?php endif; ?>
            <?php if ($c['client_id']): ?>
            <a href="<?= BASE_URL ?>/modules/clients/view.php?id=<?= (int)$c['client_id'] ?>"
               class="btn btn-xs btn-outline-secondary" title="Client"><i class="fa fa-user"></i></a>
            <?php elseif ($c['lead_id']): ?>
            <a href="<?= BASE_URL ?>/modules/crm/view_lead.php?id=<?= (int)$c['lead_id'] ?>"
               class="btn btn-xs btn-outline-secondary" title="Lead"><i class="fa fa-filter"></i></a>
            <?php endif; ?>
            <a href="<?= BASE_URL ?>/modules/callcenter/dialer.php?to=<?= urlencode((string)$other) ?>"
               class="btn btn-xs btn-outline-success" title="Call back"><i class="fa fa-phone"></i></a>
        </div>
    </div>
    <?php endforeach; endif; ?>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>

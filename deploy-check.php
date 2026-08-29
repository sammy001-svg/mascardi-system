<?php
/**
 * Deployment self-check.
 *
 * Answers, in one page, the two questions that explain almost every "I pushed it
 * but it still does not work":
 *
 *   1. Is the code on this server actually the current code? cPanel's "Update
 *      from Remote" only pulls into the repository folder — publishing to
 *      public_html is a separate step, so a repo that is up to date and a site
 *      that is stale look identical from the Git page.
 *
 *   2. Did the database catch up? Schema changes run on first page load, not at
 *      deploy time, so a feature can be fully deployed and still do nothing until
 *      the page that owns its migration has been opened once.
 *
 * Super admin only: it reports file paths, schema state and configuration, which
 * is exactly the sort of thing not to hand to an anonymous visitor.
 *
 * Safe to leave in place, but there is no reason to keep it once the deployment
 * settles — delete it when you are done.
 */
require_once __DIR__ . '/includes/functions.php';
requireLogin();
if (!isSuperAdmin() && authRole() !== 'admin') {
    http_response_code(403);
    exit('Only a super admin can run the deployment check.');
}

$db = null;
try { $db = getDB(); } catch (\Throwable $e) { /* reported below */ }

/**
 * Files that only exist in recent work, with a string that must appear inside
 * them. Presence of the file proves the deploy ran; the marker proves it
 * published the current version rather than an older one.
 */
$expected = [
    'Visitors book — officer chooser' => [
        'visitorbook/assign.php', 'Who will attend to'],
    'Visitors book — rotation'        => [
        'modules/visitors/_bootstrap.php', "ORDER BY COALESCE(MAX(v.created_at)"],
    'Visitors book — auto-allocate net' => [
        'modules/visitors/_bootstrap.php', 'visitorSweepUnassigned'],
    'Visitors book — desk heartbeat'  => [
        'visitorbook/api/ping.php', 'visitorDeskTouch'],
    'Visitors book — returning lookup' => [
        'visitorbook/api/lookup.php', 'visitorLookupByPhone'],
    'Visitors book — sign out'        => [
        'visitorbook/checkout.php', 'visitorOpenVisitByPhone'],
    'Visitors book — location step'   => [
        'visitorbook/location.php', 'Which desk is this'],
    'Reservation cancellation'        => [
        'modules/reservations/_bootstrap.php', 'reservationCancel'],
    'Kiosk login seed'                => [
        'database/seed_visitor_book.php', 'visitor_book'],
    'Deployment config'               => [
        '.cpanel.yml', 'DEPLOYPATH'],
    'Carl — greeting fix'             => [
        'modules/carl/_bootstrap.php', 'bestScore >= 3.5'],
    'Carl — reservations & documents' => [
        'modules/carl/_tasks.php', 'carlCreateReservation'],
    'Carl — conversational layer'     => [
        'modules/carl/_agent.php', 'carlConverse'],
    'Admin seed guarded'              => [
        'database/seed_admin.php', 'seedAdminConfirmPage'],
];

/** settings key => version this build expects. */
$schemas = [
    'visitors_schema_version'     => '5',
    'reservations_schema_version' => '1',
    'meetings_schema_version'     => '2',
    'credit_schema_version'       => '2',
];

$tables = ['visitors', 'visitor_kiosk_sessions', 'reservation_cancellations',
           'meeting_series', 'meeting_reminders', 'credit_agreements'];

$columns = [
    'visitors' => ['location_id', 'checked_out_at', 'assigned_to'],
    'users'    => ['profile_image', 'location_id'],
    'cars'     => ['entry_number'],
];

function badge(bool $ok, string $good = 'OK', string $bad = 'MISSING'): string {
    return $ok
        ? '<span class="b ok">' . $good . '</span>'
        : '<span class="b no">' . $bad . '</span>';
}
$fails = 0;
?><!DOCTYPE html>
<html lang="en"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Deployment check</title>
<style>
body{font-family:"Segoe UI",system-ui,sans-serif;background:#0d1219;color:#e8eaed;margin:0;padding:26px}
.w{max-width:900px;margin:0 auto}
h1{font-size:21px;margin:0 0 4px}
.sub{color:#9aa4b2;font-size:13px;margin:0 0 22px}
h2{font-size:14px;margin:26px 0 10px;color:#c084fc;letter-spacing:.04em;text-transform:uppercase}
table{width:100%;border-collapse:collapse;font-size:13.5px}
td{padding:7px 10px;border-bottom:1px solid #222b38;vertical-align:top}
td:first-child{color:#cbd5e1}
.b{font-size:11px;font-weight:700;padding:2px 9px;border-radius:20px;white-space:nowrap}
.ok{background:#132a1c;color:#4ade80}
.no{background:#3a1418;color:#f87171}
.warn{background:#2a2210;color:#fcd34d}
.m{font-family:ui-monospace,Consolas,monospace;font-size:12px;color:#9aa4b2}
.box{background:#151c26;border:1px solid #2a3442;border-radius:12px;padding:18px;margin-bottom:18px}
.verdict{font-size:15px;font-weight:700;padding:14px 18px;border-radius:12px;margin:0 0 20px}
.vok{background:#132a1c;color:#4ade80;border:1px solid #1e5f37}
.vno{background:#3a1418;color:#f87171;border:1px solid #7f2230}
code{background:#0b1119;padding:2px 6px;border-radius:4px;font-size:12px}
</style></head><body><div class="w">

<h1>Deployment check</h1>
<p class="sub">
    Run on <?= htmlspecialchars($_SERVER['HTTP_HOST'] ?? 'this server') ?>
    &middot; <?= date('D j M Y, H:i') ?>
    &middot; document root <span class="m"><?= htmlspecialchars(BASE_PATH) ?></span>
</p>

<?php
// ── 1. Is the deployed code current? ─────────────────────────────────────────
$fileRows = '';
$fileFails = 0;
foreach ($expected as $label => [$path, $marker]) {
    $full    = BASE_PATH . '/' . $path;
    $exists  = is_file($full);
    $current = $exists && str_contains((string)@file_get_contents($full), $marker);
    if (!$current) { $fileFails++; $fails++; }
    $state = !$exists  ? badge(false, '', 'FILE NOT DEPLOYED')
           : (!$current ? badge(false, '', 'OLD VERSION')
           : badge(true));
    $fileRows .= '<tr><td>' . htmlspecialchars($label) . '<div class="m">' . htmlspecialchars($path)
               . '</div></td><td style="text-align:right">' . $state . '</td></tr>';
}
?>
<div class="verdict <?= $fileFails ? 'vno' : 'vok' ?>">
    <?php if ($fileFails): ?>
    <?= $fileFails ?> of <?= count($expected) ?> checks failed — the code on this server is NOT current.
    Nothing built recently will work until the deploy step runs.
    <?php else: ?>
    The code on this server is current. All <?= count($expected) ?> file checks passed.
    <?php endif; ?>
</div>

<h2>1. Deployed code</h2>
<div class="box"><table><?= $fileRows ?></table></div>

<?php if ($fileFails): ?>
<div class="box">
    <strong style="color:#fcd34d">What to do</strong>
    <p style="font-size:13.5px;line-height:1.7;color:#cbd5e1;margin:10px 0 0">
        In cPanel → <strong>Git Version Control</strong> → Manage → <em>Pull or Deploy</em>:
        press <strong>Update from Remote</strong>, then <strong>Deploy HEAD Commit</strong>.
        The second button is the one that copies the code into <code>public_html</code> —
        pulling alone only updates the repository folder, which is why the page can say
        “up-to-date” while the site stays on old code.
    </p>
</div>
<?php endif; ?>


<h2>2. Does Carl actually understand a greeting?</h2>
<?php
// File markers prove which bytes are on disk. This proves what the running
// server does with them, which is the question actually being asked — and it
// catches the case where the files are current but PHP is still serving a
// cached compile of the old ones.
$carlRows = '';
$carlFails = 0;
$carlLoaded = false;

$carlBootstrap = __DIR__ . '/modules/carl/_bootstrap.php';
if (is_file($carlBootstrap)) {
    try {
        require_once $carlBootstrap;
        $carlLoaded = function_exists('carlMatchSkill');
    } catch (\Throwable $e) {
        $carlLoaded = false;
    }
}

if ($carlLoaded) {
    // Greetings must reach chitchat; the rest must not be swallowed by it.
    $probes = [
        ['Hallow?',      'chitchat',   'greeting'],
        ['Hallo',        'chitchat',   'greeting'],
        ['Hello?',       'chitchat',   'greeting'],
        ['Hi',           'chitchat',   'greeting'],
        ['Good morning', 'chitchat',   'greeting'],
        ['Habari',       'chitchat',   'greeting (Swahili)'],
        ['Thanks',       'chitchat',   'courtesy'],
        ['history',      null,         'must NOT be read as "hi"'],
        ['how many leads do we have', 'leads', 'a real question'],
    ];
    foreach ($probes as [$say, $want, $note]) {
        $got = carlMatchSkill($say);
        $ok  = $got === $want;
        if (!$ok) $carlFails++;
        $carlRows .= '<tr><td><code>' . htmlspecialchars($say) . '</code>'
                   . '<div class="m">' . htmlspecialchars($note) . '</div></td>'
                   . '<td class="m">' . htmlspecialchars($got ?? 'not understood') . '</td>'
                   . '<td style="text-align:right">' . badge($ok, 'OK', 'WRONG') . '</td></tr>';
    }
} else {
    $carlRows = '<tr><td colspan="3" class="m">Carl is not installed on this server — '
              . 'modules/carl/_bootstrap.php is missing. The deploy has not run.</td></tr>';
    $carlFails = 1;
}
?>
<div class="verdict <?= $carlFails ? 'vno' : 'vok' ?>">
<?php if (!$carlLoaded): ?>
    Carl is not on this server at all.
<?php elseif ($carlFails): ?>
    <?= $carlFails ?> of <?= count($probes) ?> failed — this server is running the OLD matcher.
    The files may be in place, but what is executing is out of date. Check OPcache below.
<?php else: ?>
    Carl understands greetings correctly on this server. If she still answers badly in the
    browser, the problem is the API key or the browser cache, not the code.
<?php endif; ?>
</div>
<div class="box"><table><?= $carlRows ?></table></div>

<h2>3. PHP OPcache</h2>
<?php
// The quiet cause of "I deployed it and nothing changed". OPcache compiles PHP
// once and reuses it; if validate_timestamps is off, or revalidate_freq is long,
// new files sit on disk while the old compile keeps being served.
$oc = function_exists('opcache_get_status') ? @opcache_get_status(false) : null;
$ocOn = is_array($oc) && !empty($oc['opcache_enabled']);
$validate = (bool)ini_get('opcache.validate_timestamps');
$freq = (int)ini_get('opcache.revalidate_freq');
$stale = $ocOn && (!$validate || $freq > 60);
?>
<div class="box"><table>
    <tr><td>OPcache running</td><td style="text-align:right"><?= badge(true, $ocOn ? 'YES' : 'NO', '') ?></td></tr>
    <?php if ($ocOn): ?>
    <tr><td>Notices changed files
        <div class="m">opcache.validate_timestamps</div></td>
        <td style="text-align:right"><?= badge($validate, 'YES', 'NO — new code is ignored') ?></td></tr>
    <tr><td>How often it re-checks
        <div class="m">opcache.revalidate_freq — seconds</div></td>
        <td style="text-align:right"><?= badge($freq <= 60, $freq . 's', $freq . 's — too long') ?></td></tr>
    <?php endif; ?>
</table>
<?php if ($stale): ?>
    <p style="font-size:13.5px;line-height:1.7;color:#fcd34d;margin:12px 0 0">
        This is very likely your problem. PHP is serving a cached compile of the old files.
        Clear it in cPanel — <strong>Select PHP Version → Options</strong>, toggle any setting
        to force a restart — or ask your host to reload PHP. Touching the files
        (<code>find . -name '*.php' -exec touch {} +</code> over SSH) also works when
        timestamps are being checked.
    </p>
<?php elseif ($ocOn): ?>
    <p class="m" style="margin:12px 0 0">OPcache is on but re-checks files promptly, so it is
    not what is holding the old code in place.</p>
<?php else: ?>
    <p class="m" style="margin:12px 0 0">Not enabled, so it cannot be masking a deploy.</p>
<?php endif; ?>
</div>

<h2>4. Carl's connection to Claude</h2>
<?php
$carlKey   = trim((string)getSetting('anthropic_api_key', ''));
$carlModel = trim((string)getSetting('anthropic_model', ''));
$hasAgent  = is_file(__DIR__ . '/modules/carl/_agent.php');
?>
<div class="box"><table>
    <tr><td>API key saved
        <div class="m">Settings → anthropic_api_key</div></td>
        <td style="text-align:right"><?= badge($carlKey !== '',
            $carlKey !== '' ? substr($carlKey, 0, 10) . '…' : '', 'NOT SET') ?></td></tr>
    <tr><td>Model
        <div class="m">blank means the default, claude-opus-5</div></td>
        <td class="m" style="text-align:right"><?= htmlspecialchars($carlModel ?: 'claude-opus-5 (default)') ?></td></tr>
    <tr><td>Conversational layer deployed
        <div class="m">modules/carl/_agent.php — lets Claude actually talk</div></td>
        <td style="text-align:right"><?= badge($hasAgent) ?></td></tr>
</table>
<p class="m" style="margin:12px 0 0">
    Without a key Carl still answers from your own data, but only in her fixed phrasings.
    <a href="<?= BASE_URL ?>/modules/carl/test.php" style="color:#c084fc">Run the connection test</a>
    to see exactly what the API says.
</p>
</div>


<h2>5. Database</h2>
<div class="box">
<?php if (!$db): ?>
    <p style="color:#f87171">Could not connect to the database.</p>
<?php else: ?>
<table>
<?php
foreach ($schemas as $key => $want) {
    $have = '';
    try {
        $s = $db->prepare("SELECT setting_value FROM settings WHERE setting_key = ?");
        $s->execute([$key]); $have = (string)$s->fetchColumn();
    } catch (\Throwable $_) {}
    $ok = ($have === $want);
    if (!$ok) $fails++;
    echo '<tr><td>' . htmlspecialchars($key)
       . '<div class="m">expects ' . htmlspecialchars($want)
       . ', has ' . htmlspecialchars($have !== '' ? $have : 'nothing') . '</div></td>'
       . '<td style="text-align:right">'
       . ($ok ? badge(true) : '<span class="b warn">NOT MIGRATED</span>') . '</td></tr>';
}
foreach ($tables as $t) {
    $ok = false;
    try { $ok = (bool)$db->query("SHOW TABLES LIKE " . $db->quote($t))->fetch(); } catch (\Throwable $_) {}
    if (!$ok) $fails++;
    echo '<tr><td>table <span class="m">' . htmlspecialchars($t) . '</span></td>'
       . '<td style="text-align:right">' . badge($ok) . '</td></tr>';
}
foreach ($columns as $tbl => $cols) {
    foreach ($cols as $c) {
        $ok = false;
        try { $ok = (bool)$db->query("SHOW COLUMNS FROM `{$tbl}` LIKE " . $db->quote($c))->fetch(); }
        catch (\Throwable $_) {}
        if (!$ok) $fails++;
        echo '<tr><td>column <span class="m">' . htmlspecialchars($tbl . '.' . $c) . '</span></td>'
           . '<td style="text-align:right">' . badge($ok) . '</td></tr>';
    }
}
?>
</table>
<p class="sub" style="margin:14px 0 0">
    Schema changes run when the page that owns them is first opened, not at deploy time.
    Anything showing NOT MIGRATED usually just needs its module visited once —
    Visitors, Reservations, Meetings.
</p>
<?php endif; ?>
</div>

<h2>6. Configuration</h2>
<div class="box"><table>
<?php
// Things that are deployed correctly but still need setting up before they do
// anything visible — the second most common cause of "it is not working".
$kioskRole = false; $kioskUser = 0; $officers = 0; $locs = 0; $cron = '';
if ($db) {
    try {
        $col = $db->query("SHOW COLUMNS FROM users LIKE 'role'")->fetch(PDO::FETCH_ASSOC);
        $kioskRole = $col && (stripos($col['Type'], 'visitor_book') !== false
                              || stripos($col['Type'], 'varchar') !== false);
        $kioskUser = (int)$db->query("SELECT COUNT(*) FROM users WHERE role='visitor_book'")->fetchColumn();
        $officers  = (int)$db->query("SELECT COUNT(*) FROM users WHERE role='customer_relations' AND status='active'")->fetchColumn();
        $locs      = (int)$db->query("SELECT COUNT(*) FROM locations WHERE status='active' OR status IS NULL")->fetchColumn();
        $cron      = (string)$db->query("SELECT MAX(ran_at) FROM cron_runs WHERE job_name='meeting_reminders'")->fetchColumn();
    } catch (\Throwable $_) {}
}
$smtp = trim((string)getSetting('smtp_host', '')) !== ''
     && trim((string)getSetting('smtp_from_email', '')) !== '';

$rows = [
    ['users.role accepts visitor_book', $kioskRole, 'Run database/seed_visitor_book.php'],
    ['Kiosk login exists', $kioskUser > 0, 'Run database/seed_visitor_book.php'],
    ['Active customer relations officers', $officers > 0,
        'Rotation has nobody to rotate between — add officers, or they fall back to sales roles'],
    ['Locations configured', $locs > 0,
        'Optional. Without them the location step is skipped and walk-ins are not tied to a branch'],
    ['SMTP configured', $smtp, 'Settings → Email. Without it, emails log as failed; in-system notices still work'],
    ['Meeting reminders cron has run', $cron !== '',
        'Add the every-5-minutes cron — see scripts/cron/README.md'],
];
foreach ($rows as [$label, $ok, $hint]) {
    echo '<tr><td>' . htmlspecialchars($label)
       . ($ok ? '' : '<div class="m">' . htmlspecialchars($hint) . '</div>')
       . '</td><td style="text-align:right">'
       . ($ok ? badge(true) : '<span class="b warn">NOT SET UP</span>') . '</td></tr>';
}
if ($officers > 0) {
    echo '<tr><td>Officers in the rotation<div class="m">' . $officers . ' active</div></td>'
       . '<td style="text-align:right">' . badge(true, (string)$officers) . '</td></tr>';
}
?>
</table></div>

<p class="sub">
    Delete <code>deploy-check.php</code> from the server once the deployment is settled.
</p>
</div></body></html>

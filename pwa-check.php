<?php
/**
 * PWA Diagnostic — admin-only page that checks every Chrome installability
 * requirement and shows exactly what might be blocking beforeinstallprompt.
 *
 * Visit: /pwa-check.php  (requires login + admin role)
 */
require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/database.php';
requireLogin();
if (!hasRole('admin')) {
    http_response_code(403);
    exit('<h2>Admins only</h2>');
}

$base = rtrim(BASE_URL, '/');

// ── Server-side checks ─────────────────────────────────────────────────────

$checks = [];

// 1. HTTPS
$isHttps = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
        || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443);
$checks[] = [
    'name'   => 'HTTPS',
    'ok'     => $isHttps,
    'detail' => $isHttps
        ? 'Served over HTTPS ✓'
        : 'NOT served over HTTPS — Chrome requires HTTPS for PWA install. Current: '
          . ($isHttps ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? '?'),
];

// 2. manifest.php reachable and valid JSON
$manifestUrl  = $base . '/manifest.php';
$manifestOk   = false;
$manifestNote = '';
$ctx = stream_context_create(['http' => ['timeout' => 5, 'ignore_errors' => true]]);
$raw = @file_get_contents($manifestUrl, false, $ctx);
if ($raw === false) {
    $manifestNote = 'Could not fetch manifest — check server or CORS';
} else {
    $json = @json_decode($raw, true);
    if (!$json) {
        $manifestNote = 'Manifest returned invalid JSON. First 300 chars: ' . htmlspecialchars(substr($raw, 0, 300));
    } else {
        $missing = [];
        if (empty($json['name']) && empty($json['short_name'])) $missing[] = 'name / short_name';
        if (empty($json['start_url']))  $missing[] = 'start_url';
        if (empty($json['display']))    $missing[] = 'display';
        if (!in_array($json['display'] ?? '', ['standalone','fullscreen','minimal-ui'])) $missing[] = 'display must be standalone/fullscreen/minimal-ui (got: ' . ($json['display'] ?? '') . ')';
        $has192 = false; $has512 = false;
        foreach ($json['icons'] ?? [] as $ic) {
            if (strpos($ic['sizes'] ?? '', '192') !== false) $has192 = true;
            if (strpos($ic['sizes'] ?? '', '512') !== false) $has512 = true;
        }
        if (!$has192) $missing[] = '192×192 icon';
        if (!$has512) $missing[] = '512×512 icon';

        if ($missing) {
            $manifestNote = 'Missing required fields: ' . implode(', ', $missing);
        } else {
            $manifestOk   = true;
            $manifestNote = 'Valid — name: "' . htmlspecialchars($json['name'] ?? $json['short_name'])
                          . '", start_url: ' . htmlspecialchars($json['start_url'])
                          . ', display: ' . $json['display']
                          . ', icons: ' . count($json['icons'] ?? []);
        }
    }
}
$checks[] = ['name' => 'Manifest JSON', 'ok' => $manifestOk, 'detail' => $manifestNote];

// 3. manifest Content-Type header
$manifestCT = '';
foreach ($http_response_header ?? [] as $h) {
    if (stripos($h, 'content-type:') === 0) { $manifestCT = $h; break; }
}
$ctOk = stripos($manifestCT, 'application/manifest+json') !== false
     || stripos($manifestCT, 'application/json') !== false;
$checks[] = [
    'name'   => 'Manifest Content-Type',
    'ok'     => $ctOk,
    'detail' => $manifestCT ?: 'No Content-Type header found',
];

// 4. icon-192 reachable + valid PNG
function checkIcon(string $url, int $expectedSize): array {
    $ctx = stream_context_create(['http' => ['timeout' => 5, 'ignore_errors' => true]]);
    $data = @file_get_contents($url, false, $ctx);
    if ($data === false || strlen($data) < 50) return [false, 'Could not fetch icon at ' . $url];
    // PNG signature
    if (substr($data, 0, 8) !== "\x89PNG\r\n\x1a\n") return [false, 'Not a valid PNG (bad signature)'];
    // Width / Height from IHDR
    $w = unpack('N', substr($data, 16, 4))[1];
    $h = unpack('N', substr($data, 20, 4))[1];
    if ($w !== $expectedSize || $h !== $expectedSize) {
        return [false, "PNG is {$w}×{$h} — expected {$expectedSize}×{$expectedSize}"];
    }
    return [true, "Valid PNG {$w}×{$h}, " . strlen($data) . ' bytes'];
}
[$i192ok, $i192note] = checkIcon($base . '/assets/images/icons/icon-192.png', 192);
[$i512ok, $i512note] = checkIcon($base . '/assets/images/icons/icon-512.png', 512);
$checks[] = ['name' => 'Icon 192×192 PNG', 'ok' => $i192ok, 'detail' => $i192note];
$checks[] = ['name' => 'Icon 512×512 PNG', 'ok' => $i512ok, 'detail' => $i512note];

// 5. sw.js reachable
$swUrl  = $base . '/sw.js';
$swData = @file_get_contents($swUrl, false, $ctx);
$swOk   = $swData !== false && strlen($swData) > 50;
$checks[] = [
    'name'   => 'sw.js reachable',
    'ok'     => $swOk,
    'detail' => $swOk
        ? 'Fetched successfully (' . strlen($swData) . ' bytes)'
        : 'Could not fetch ' . $swUrl,
];

// 6. sw.js has a fetch handler
$swHasFetch = $swOk && preg_match('/addEventListener\s*\(\s*[\'"]fetch[\'"]/', $swData);
$checks[] = [
    'name'   => 'sw.js fetch handler',
    'ok'     => (bool)$swHasFetch,
    'detail' => $swHasFetch ? 'fetch event handler found ✓' : 'No fetch handler found — Chrome requires one',
];

// 7. offline.php reachable
$offlineUrl  = $base . '/offline.php';
$offlineData = @file_get_contents($offlineUrl, false, $ctx);
$offlineOk   = $offlineData !== false;
$checks[] = [
    'name'   => 'offline.php reachable',
    'ok'     => $offlineOk,
    'detail' => $offlineOk ? 'Reachable ✓' : 'Not reachable — SW install may fail',
];

$allOk = !in_array(false, array_column($checks, 'ok'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>PWA Diagnostic</title>
<style>
body{font-family:system-ui,sans-serif;margin:0;padding:24px;background:#f1f5f9;color:#1e293b}
h1{margin:0 0 6px;font-size:22px}
.subtitle{color:#64748b;font-size:13px;margin:0 0 24px}
.card{background:#fff;border-radius:12px;padding:20px 24px;margin-bottom:16px;box-shadow:0 1px 4px rgba(0,0,0,.08)}
.check{display:flex;gap:12px;align-items:flex-start;padding:10px 0;border-bottom:1px solid #f1f5f9}
.check:last-child{border-bottom:none}
.badge{min-width:22px;height:22px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:700;flex-shrink:0;margin-top:1px}
.ok{background:#dcfce7;color:#16a34a}.fail{background:#fee2e2;color:#dc2626}
.check-name{font-weight:600;font-size:14px;flex:1}
.check-detail{font-size:12px;color:#64748b;margin-top:3px;line-height:1.5}
.summary{font-size:15px;font-weight:600;padding:12px 16px;border-radius:8px;margin-bottom:20px}
.sum-ok{background:#dcfce7;color:#15803d}.sum-fail{background:#fee2e2;color:#b91c1c}
.section-title{font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#94a3b8;margin:0 0 10px}
pre{background:#0f172a;color:#e2e8f0;border-radius:8px;padding:16px;font-size:12px;overflow-x:auto;line-height:1.6}
.tip{background:#eff6ff;border-left:3px solid #2563eb;padding:12px 16px;border-radius:0 8px 8px 0;font-size:13px;line-height:1.6;margin-top:16px}
</style>
</head>
<body>
<h1>PWA Diagnostic</h1>
<p class="subtitle">Checks every Chrome installability requirement — open DevTools &rarr; Console to see <code>[PWA]</code> logs.</p>

<div class="<?= $allOk ? 'summary sum-ok' : 'summary sum-fail' ?>">
    <?= $allOk ? '✓ All server-side checks passed — if Chrome still won\'t prompt, see browser checks below.' : '✗ One or more server-side checks failed — fix these first.' ?>
</div>

<div class="card">
    <div class="section-title">Server-side Checks</div>
    <?php foreach ($checks as $c): ?>
    <div class="check">
        <span class="badge <?= $c['ok'] ? 'ok' : 'fail' ?>"><?= $c['ok'] ? '✓' : '✗' ?></span>
        <div>
            <div class="check-name"><?= htmlspecialchars($c['name']) ?></div>
            <div class="check-detail"><?= $c['detail'] ?></div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<div class="card">
    <div class="section-title">Browser-side Checks (open DevTools)</div>
    <p style="font-size:13px;margin:0 0 12px">Open Chrome DevTools (F12) and check:</p>
    <ul style="font-size:13px;line-height:2;margin:0;padding-left:20px">
        <li><strong>Application &rarr; Manifest</strong> — should show no red errors; all icons should preview</li>
        <li><strong>Application &rarr; Service Workers</strong> — status should be "activated and running" (not "waiting to activate")</li>
        <li><strong>Console</strong> — look for <code>[PWA] beforeinstallprompt fired ✓</code> message</li>
        <li><strong>Application &rarr; Manifest &rarr; "Add to homescreen"</strong> link — Chrome's own installability audit</li>
    </ul>
    <div class="tip">
        <strong>If the Console never shows <code>[PWA] beforeinstallprompt fired ✓</code>:</strong><br>
        Go to <code>chrome://flags/#bypass-app-banner-engagement-checks</code> and enable it,
        then reload this page. If the event fires, Chrome's engagement heuristic was blocking it
        (normal on first visit — just browse the site for 30+ seconds on a second visit).
    </div>
</div>

<div class="card">
    <div class="section-title">Manifest Content (live fetch)</div>
    <pre><?= htmlspecialchars(json_encode(json_decode($raw ?? '{}'), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) ?></pre>
</div>

<div class="card">
    <div class="section-title">Useful Links</div>
    <ul style="font-size:13px;line-height:2;margin:0;padding-left:20px">
        <li><a href="<?= $base ?>/manifest.php" target="_blank">manifest.php (raw)</a></li>
        <li><a href="<?= $base ?>/sw.js" target="_blank">sw.js (raw)</a></li>
        <li><a href="<?= $base ?>/assets/images/icons/icon-192.png" target="_blank">icon-192.png</a></li>
        <li><a href="<?= $base ?>/assets/images/icons/icon-512.png" target="_blank">icon-512.png</a></li>
    </ul>
</div>

<!-- Browser-side JS checks -->
<div class="card" id="jsCheckCard">
    <div class="section-title">Live JavaScript Status</div>
    <div id="jsChecks" style="font-size:13px;line-height:1.8;color:#64748b">Checking…</div>
</div>

<script>
(function () {
    var out = document.getElementById('jsChecks');
    var lines = [];

    function row(ok, label, detail) {
        lines.push('<div style="display:flex;gap:10px;align-items:baseline">'
            + '<span style="min-width:18px;font-size:11px;font-weight:700;color:' + (ok ? '#16a34a' : '#dc2626') + '">' + (ok ? '✓' : '✗') + '</span>'
            + '<span><strong>' + label + '</strong>' + (detail ? ' — <span style="color:#64748b">' + detail + '</span>' : '') + '</span>'
            + '</div>');
        out.innerHTML = lines.join('');
    }

    row(location.protocol === 'https:', 'Protocol', location.protocol);
    row('serviceWorker' in navigator, 'Service Worker API', ('serviceWorker' in navigator) ? 'available' : 'not available in this browser');
    row(window.matchMedia('(display-mode: standalone)').matches === false, 'Not already installed', window.matchMedia('(display-mode: standalone)').matches ? 'App is running as standalone (already installed)' : 'Running in browser (not yet installed)');
    row(!!window.__pwaBeforeInstall || false, 'beforeinstallprompt (early capture)', window.__pwaBeforeInstall ? 'captured ✓' : 'not fired yet — check Console after full page load');

    if ('serviceWorker' in navigator) {
        navigator.serviceWorker.getRegistration().then(function (reg) {
            if (!reg) {
                row(false, 'Service Worker registration', 'No SW registered for this scope');
            } else {
                var state = reg.active ? reg.active.state : (reg.installing ? 'installing' : 'waiting');
                row(reg.active && reg.active.state === 'activated', 'Service Worker state', state + ' | scope: ' + reg.scope);
            }
        });
    }
})();
</script>
</body>
</html>

<?php
/**
 * Carl — connection test.
 *
 * Whichever service is configured, this makes one real call and shows exactly
 * what came back. The model layer swallows every failure into a silent fallback,
 * which is right for someone at the desk and useless for working out why Carl is
 * answering plainly: a wrong key, a retired model id, an unfunded account and a
 * blocked outbound connection all look identical from her panel.
 *
 * Super admin only — it reports configuration and the raw API response.
 * Delete this file once the connection is settled.
 */
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/_skills.php';
require_once __DIR__ . '/_ai.php';
requireLogin();
if (!isSuperAdmin() && authRole() !== 'admin') {
    http_response_code(403);
    exit('Only a super admin can run the connection test.');
}

$provider = carlAiProvider();
$model    = carlAiModel();
$gKey     = trim((string)getSetting('google_api_key', ''));
$aKey     = trim((string)getSetting('anthropic_api_key', ''));
$key      = $provider === 'google' ? $gKey : $aKey;

// ── One real call ────────────────────────────────────────────────────────────
$live = null;
if ($key !== '') {
    $t0 = microtime(true);

    if ($provider === 'google') {
        $payload = json_encode([
            'systemInstruction' => ['parts' => [['text' => 'You are a test.']]],
            'contents' => [['role' => 'user', 'parts' => [['text' => 'Reply with exactly: CARL OK']]]],
            'generationConfig' => ['maxOutputTokens' => 32],
        ]);
        $url = 'https://generativelanguage.googleapis.com/v1beta/models/'
             . rawurlencode($model) . ':generateContent';
        $headers = ['Content-Type: application/json', 'x-goog-api-key: ' . $key,
                    'Content-Length: ' . strlen($payload)];
    } else {
        $payload = json_encode([
            'model' => $model, 'max_tokens' => 32,
            'messages' => [['role' => 'user', 'content' => 'Reply with exactly: CARL OK']],
        ]);
        $url = 'https://api.anthropic.com/v1/messages';
        $headers = ['Content-Type: application/json', 'x-api-key: ' . $key,
                    'anthropic-version: 2023-06-01', 'Content-Length: ' . strlen($payload)];
    }

    $ctx = stream_context_create(['http' => [
        'method' => 'POST', 'header' => implode("\r\n", $headers),
        'content' => $payload, 'timeout' => 25, 'ignore_errors' => true,
    ]]);
    $raw = @file_get_contents($url, false, $ctx);
    $ms  = (int)round((microtime(true) - $t0) * 1000);

    $status = 0;
    foreach ($http_response_header ?? [] as $h) {
        if (preg_match('~^HTTP/\S+\s+(\d{3})~', $h, $m)) { $status = (int)$m[1]; break; }
    }
    $live = ['status' => $status, 'ms' => $ms, 'raw' => $raw,
             'json' => $raw !== false ? json_decode($raw, true) : null];
}

$err    = $live['json']['error'] ?? null;
$spoke  = $live && $live['status'] === 200 && !$err;
$errMsg = strtolower((string)($err['message'] ?? ''));

/** What the failure actually means, in words worth acting on. */
function carlDiagnose(string $m, string $provider): string
{
    if ($m === '') return '';
    if (str_contains($m, 'leaked')) {
        return 'Google has disabled this key because it was published somewhere it could be '
             . 'read — a chat, a screenshot, a commit. It cannot be re-enabled. Create a new '
             . 'key at aistudio.google.com/apikey and enter it here in Settings rather than '
             . 'sending it to anyone.';
    }
    if (str_contains($m, 'api key not valid') || str_contains($m, 'api_key_invalid')
        || str_contains($m, 'authentication') || str_contains($m, 'invalid x-api-key')) {
        return 'The key was rejected. Check it was pasted whole, and that it belongs to the '
             . ($provider === 'google' ? 'Google project you think it does.' : 'right Anthropic organisation.');
    }
    if (str_contains($m, 'credit balance') || str_contains($m, 'purchase credits') || str_contains($m, 'billing')) {
        return 'Nothing is wrong with the key or the code — the account has no credit. '
             . 'Top it up and Carl picks up on her next message.';
    }
    if (str_contains($m, 'quota') || str_contains($m, 'rate limit') || str_contains($m, 'resource_exhausted')) {
        return 'The free quota for this minute or day is used up. It resets on its own; '
             . 'if it keeps happening, a lighter model such as Flash Lite will go further.';
    }
    if (str_contains($m, 'not found') || str_contains($m, 'is not supported')) {
        return 'The model name was rejected. Pick a different one in Settings — model names '
             . 'are retired periodically and an old one fails every call.';
    }
    if (str_contains($m, 'permission') || str_contains($m, 'forbidden')) {
        return 'The key is valid but not permitted to use this model or API. In Google AI Studio, '
             . 'check the Generative Language API is enabled for the project.';
    }
    return 'See the message above.';
}

function tb(bool $ok, string $good = 'OK', string $bad = 'PROBLEM'): string {
    return '<span class="b ' . ($ok ? 'ok' : 'no') . '">' . ($ok ? $good : $bad) . '</span>';
}
?><!DOCTYPE html>
<html lang="en"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Carl — connection test</title>
<style>
body{font-family:"Segoe UI",system-ui,sans-serif;background:#0d1219;color:#e8eaed;margin:0;padding:26px}
.w{max-width:860px;margin:0 auto}
h1{font-size:21px;margin:0 0 4px}.sub{color:#9aa4b2;font-size:13px;margin:0 0 22px}
h2{font-size:13px;margin:24px 0 10px;color:#c084fc;letter-spacing:.05em;text-transform:uppercase}
.box{background:#151c26;border:1px solid #2a3442;border-radius:12px;padding:18px;margin-bottom:16px}
table{width:100%;border-collapse:collapse;font-size:13.5px}
td{padding:8px 10px;border-bottom:1px solid #222b38;vertical-align:top}
td:first-child{color:#cbd5e1}
.m{font-family:ui-monospace,Consolas,monospace;font-size:12px;color:#9aa4b2}
.b{font-size:11px;font-weight:700;padding:2px 9px;border-radius:20px;white-space:nowrap}
.ok{background:#132a1c;color:#4ade80}.no{background:#3a1418;color:#f87171}
.verdict{font-size:15px;font-weight:700;padding:14px 18px;border-radius:12px;margin:0 0 20px;line-height:1.6}
.vok{background:#132a1c;color:#4ade80;border:1px solid #1e5f37}
.vno{background:#3a1418;color:#f87171;border:1px solid #7f2230}
pre{background:#0b1119;border:1px solid #222b38;border-radius:8px;padding:14px;overflow:auto;
    font-size:12px;color:#cbd5e1;max-height:300px;white-space:pre-wrap;word-break:break-word}
code{background:#0b1119;padding:2px 6px;border-radius:4px;font-size:12px}
a{color:#c084fc}
</style></head><body><div class="w">

<h1>Carl — connection test</h1>
<p class="sub">One real call to whichever service is configured, with whatever came back shown in full.</p>

<div class="verdict <?= $spoke ? 'vok' : 'vno' ?>">
<?php if ($key === ''): ?>
    No key is saved for <?= htmlspecialchars($provider === 'google' ? 'Google AI Studio' : 'Anthropic') ?>,
    so Carl is answering offline. She still handles greetings, figures, the pipeline, the workshop,
    visitors, revenue and every task from your own data — a key only adds free-form conversation.
<?php elseif ($spoke): ?>
    Working. <?= htmlspecialchars($model) ?> replied in <?= (int)$live['ms'] ?>ms.
    Carl will use it for anything the offline matcher cannot answer.
<?php else: ?>
    The call failed, so Carl is falling back to offline answers.
    <?= htmlspecialchars(carlDiagnose($errMsg, $provider)) ?>
<?php endif; ?>
</div>

<h2>Configuration</h2>
<div class="box"><table>
    <tr><td>Service in use
        <div class="m">Settings → Carl AI → which service should Carl use</div></td>
        <td style="text-align:right" class="m"><?= htmlspecialchars($provider === 'google' ? 'Google AI Studio' : 'Anthropic') ?></td></tr>
    <tr><td>Model</td><td style="text-align:right" class="m"><?= htmlspecialchars($model) ?></td></tr>
    <tr><td>Google key saved</td><td style="text-align:right">
        <?= tb($gKey !== '', strlen($gKey) . ' chars', 'not set') ?></td></tr>
    <tr><td>Anthropic key saved</td><td style="text-align:right">
        <?= tb($aKey !== '', strlen($aKey) . ' chars', 'not set') ?></td></tr>
    <tr><td>Outbound HTTPS possible
        <div class="m">shared hosting sometimes blocks it</div></td>
        <td style="text-align:right"><?= tb(function_exists('curl_init') || (bool)ini_get('allow_url_fopen')) ?></td></tr>
</table></div>

<?php if ($live !== null): ?>
<h2>Live call</h2>
<div class="box">
    <table>
        <tr><td>HTTP status</td><td style="text-align:right">
            <?= $live['status'] ?: 'no response' ?> <?= tb($live['status'] === 200) ?></td></tr>
        <tr><td>Round trip</td><td style="text-align:right" class="m"><?= (int)$live['ms'] ?> ms</td></tr>
    </table>
    <?php if ($live['raw'] === false): ?>
        <p class="m" style="margin-top:12px">
            The request did not complete at all. That is the host blocking outbound HTTPS,
            or a firewall in front of it — not a problem with the key.
        </p>
    <?php else: ?>
        <p class="m" style="margin:14px 0 6px">Raw response</p>
        <pre><?= htmlspecialchars(substr((string)$live['raw'], 0, 3500)) ?></pre>
    <?php endif; ?>
</div>
<?php endif; ?>

<h2>Worth knowing</h2>
<div class="box">
    <p style="font-size:13.5px;line-height:1.7;color:#cbd5e1;margin:0">
        Carl answers roughly nine questions in ten from your own database without calling any
        service at all — that is deliberate, so a key lasts. The service is used for phrasing
        her vocabulary does not cover, follow-up questions that only make sense in context,
        and questions asking for judgement rather than a figure.
    </p>
</div>

<p class="sub">Delete <code>modules/carl/connection.php</code> once the connection is settled.</p>
</div></body></html>

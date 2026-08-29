<?php
/**
 * Carl — connection test.
 *
 * The LLM layer swallows every failure into a silent fallback, which is right
 * for a user-facing assistant and useless for working out why she is not
 * talking. A wrong key, a retired model id, and a firewall that blocks outbound
 * HTTPS all look identical from the panel: Carl simply answers offline.
 *
 * This page makes one real call and shows exactly what came back, so the cause
 * is visible instead of inferred. Super admin only — it reveals configuration
 * and the raw API response.
 */
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/_llm.php';
requireLogin();
if (!isSuperAdmin() && authRole() !== 'admin') {
    http_response_code(403);
    exit('Only a super admin can run the Carl connection test.');
}

$key   = trim((string)getSetting('anthropic_api_key', ''));
$model = carlLlmModel();
$setModel = trim((string)getSetting('anthropic_model', ''));

// ── The checks ───────────────────────────────────────────────────────────────
$rows = [];
$rows[] = ['API key saved', $key !== '',
           $key !== '' ? substr($key, 0, 10) . '… (' . strlen($key) . ' chars)' : 'not set',
           'Settings → add anthropic_api_key'];
$rows[] = ['Key looks like an Anthropic key', str_starts_with($key, 'sk-ant-'),
           $key === '' ? '—' : (str_starts_with($key, 'sk-ant-') ? 'sk-ant-…' : 'does NOT start with sk-ant-'),
           'Anthropic keys begin sk-ant-. Check it was pasted whole.'];
$rows[] = ['Model in use', true, $model,
           $setModel !== '' && $setModel !== $model
               ? 'anthropic_model is "' . $setModel . '", which is not allowed — falling back'
               : ''];
$rows[] = ['Outbound HTTPS possible', function_exists('curl_init') || (bool)ini_get('allow_url_fopen'),
           (function_exists('curl_init') ? 'curl ' : '') . (ini_get('allow_url_fopen') ? 'allow_url_fopen' : ''),
           'Shared hosting sometimes blocks outbound connections.'];

// ── The live call ────────────────────────────────────────────────────────────
$live = null;
if ($key !== '') {
    $payload = json_encode([
        'model'      => $model,
        'max_tokens' => 64,
        'messages'   => [['role' => 'user', 'content' => 'Reply with exactly: CARL OK']],
    ]);
    $ctx = stream_context_create(['http' => [
        'method'  => 'POST',
        'header'  => implode("\r\n", [
            'Content-Type: application/json',
            'x-api-key: ' . $key,
            'anthropic-version: 2023-06-01',
            'Content-Length: ' . strlen($payload),
        ]),
        'content'       => $payload,
        'timeout'       => 20,
        'ignore_errors' => true,   // so a 4xx body is readable rather than thrown away
    ]]);
    $t0  = microtime(true);
    $raw = @file_get_contents('https://api.anthropic.com/v1/messages', false, $ctx);
    $ms  = (int)round((microtime(true) - $t0) * 1000);

    $status = 0;
    foreach ($http_response_header ?? [] as $h) {
        if (preg_match('~^HTTP/\S+\s+(\d{3})~', $h, $m)) { $status = (int)$m[1]; break; }
    }
    $decoded = $raw !== false ? json_decode($raw, true) : null;
    $live = ['status' => $status, 'ms' => $ms, 'raw' => $raw, 'json' => $decoded];
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
.verdict{font-size:15px;font-weight:700;padding:14px 18px;border-radius:12px;margin:0 0 20px}
.vok{background:#132a1c;color:#4ade80;border:1px solid #1e5f37}
.vno{background:#3a1418;color:#f87171;border:1px solid #7f2230}
pre{background:#0b1119;border:1px solid #222b38;border-radius:8px;padding:14px;overflow:auto;
    font-size:12px;color:#cbd5e1;max-height:320px;white-space:pre-wrap;word-break:break-word}
code{background:#0b1119;padding:2px 6px;border-radius:4px;font-size:12px}
</style></head><body><div class="w">

<h1>Carl — connection test</h1>
<p class="sub">One real call to the Anthropic API, with whatever came back shown in full.</p>

<?php
$spoke = $live && $live['status'] === 200 && !isset($live['json']['error']);
?>
<div class="verdict <?= $spoke ? 'vok' : 'vno' ?>">
<?php if ($key === ''): ?>
    No API key is saved, so Carl is running offline. She still answers from your own data —
    greetings, briefings, figures and tasks all work — but she cannot handle free-form questions.
<?php elseif ($spoke): ?>
    The connection works. Carl replied in <?= (int)$live['ms'] ?>ms using <?= htmlspecialchars($model) ?>.
<?php else: ?>
    The API call failed, so Carl silently falls back to offline answers.
    The exact reason is in the response below.
<?php endif; ?>
</div>

<h2>Configuration</h2>
<div class="box"><table>
<?php foreach ($rows as [$label, $ok, $detail, $hint]): ?>
    <tr>
        <td><?= htmlspecialchars($label) ?>
            <div class="m"><?= htmlspecialchars((string)$detail) ?></div>
            <?php if (!$ok && $hint !== ''): ?><div class="m"><?= htmlspecialchars($hint) ?></div><?php endif; ?>
            <?php if ($ok && $hint !== ''): ?><div class="m"><?= htmlspecialchars($hint) ?></div><?php endif; ?>
        </td>
        <td style="text-align:right"><?= tb((bool)$ok) ?></td>
    </tr>
<?php endforeach; ?>
</table></div>

<?php if ($live !== null): ?>
<h2>Live call</h2>
<div class="box">
    <table>
        <tr><td>HTTP status</td><td style="text-align:right">
            <?= $live['status'] ?: 'no response' ?> <?= tb($live['status'] === 200) ?></td></tr>
        <tr><td>Round trip</td><td style="text-align:right" class="m"><?= (int)$live['ms'] ?> ms</td></tr>
        <?php if (isset($live['json']['error'])): ?>
        <tr><td>Error type</td><td style="text-align:right" class="m">
            <?= htmlspecialchars((string)($live['json']['error']['type'] ?? '')) ?></td></tr>
        <?php endif; ?>
    </table>

    <?php if ($live['raw'] === false): ?>
    <p class="m" style="margin-top:12px">
        The request did not complete at all. That is usually the host blocking outbound HTTPS,
        or a firewall in front of it — not a problem with the key.
    </p>
    <?php else: ?>
    <p class="m" style="margin:14px 0 6px">Raw response</p>
    <pre><?= htmlspecialchars(substr((string)$live['raw'], 0, 4000)) ?></pre>
    <?php endif; ?>

    <?php if (isset($live['json']['error'])): ?>
    <p class="m" style="margin-top:12px">
        <?php
        $t = (string)($live['json']['error']['type'] ?? '');
        $m = strtolower((string)($live['json']['error']['message'] ?? ''));
        // Billing arrives as invalid_request_error, which reads like a code fault
        // and is not one — the key is fine and the account simply has no credit.
        if (str_contains($m, 'credit balance') || str_contains($m, 'billing')
            || str_contains($m, 'quota') || str_contains($m, 'purchase credits')) {
            echo '<strong>Nothing is wrong with the key or the code.</strong> The key authenticated '
               . 'and Anthropic\x27s billing declined the call — the API balance is empty.<br><br>'
               . '<strong>A Claude Pro or Max subscription does not pay for this.</strong> '
               . 'claude.ai and the API are separate products with separate billing: a subscription '
               . 'covers chat on claude.ai and Claude Code, while anything calling api.anthropic.com '
               . 'draws on a prepaid API balance that starts at zero. Having tokens on the '
               . 'subscription puts nothing into it.<br><br>'
               . 'Go to <strong>console.anthropic.com</strong> (not claude.ai) &rarr; Plans &amp; Billing '
               . '&rarr; buy credits. Check the organisation switcher top-left matches the org that '
               . 'issued this key, and if you use Workspaces, that the workspace spend limit is not '
               . 'zero — a zero limit gives this same error even when the org has credit.<br><br>'
               . 'Carl picks up on her next message. No redeploy needed.';
        } else {
            echo match ($t) {
                'authentication_error' => 'The key was rejected. Check it is complete, active, and from the right organisation.',
                'not_found_error'      => 'The model id was rejected. Set anthropic_model to one of: claude-opus-5, claude-sonnet-5, claude-haiku-4-5.',
                'permission_error'     => 'The key is valid but not permitted to use this model.',
                'rate_limit_error'     => 'Rate limited. Wait and retry.',
                'invalid_request_error'=> 'The request was rejected — see the message above.',
                default                => 'See the message above.',
            };
        }
        ?>
    </p>
    <?php endif; ?>
</div>
<?php endif; ?>

<h2>Worth knowing</h2>
<div class="box">
    <p style="font-size:13.5px;line-height:1.7;color:#cbd5e1;margin:0">
        Carl does not need this connection to be useful. Greetings, the daily briefing, stock,
        pipeline, visitors, revenue, what-needs-attention and adding a lead are all answered from
        your own database and work with no key at all. The key only adds free-form understanding —
        questions phrased in ways her vocabulary does not cover.
    </p>
</div>

<p class="sub">Delete <code>modules/carl/test.php</code> once the connection is settled.</p>
</div></body></html>

<?php
/**
 * WhatsApp — the connection, set up once by an administrator.
 *
 * The whole point of this page is that it is used rarely and by one person. An
 * admin links the company phone here, and from that moment every member of
 * staff with WhatsApp rights sends and receives through the same number, from
 * their own login, with the whole thread kept against the customer's record.
 *
 * It is also where the connection is diagnosed when something stops, so it
 * states plainly what is wrong and what to do rather than showing a red dot.
 */

require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/_wa.php';
requireLogin();

if (!waCanAdmin()) {
    setFlash('error', 'Setting up WhatsApp is an administrator job.');
    redirect(BASE_URL . '/modules/whatsapp/index.php');
}

$db = getDB();
waMigrate($db);
$me = authUser();

/**
 * The webhook address carries its own secret.
 *
 * Generated once, on first view, so the endpoint is never briefly open: an
 * unauthenticated URL that writes into the inbox would let anyone who found it
 * invent a message from a customer. Regenerating it here would silently break
 * a working connection, so it is made once and kept.
 */
$hookSecret = trim((string)getSetting('wa_webhook_secret', ''));
if ($hookSecret === '') {
    $hookSecret = bin2hex(random_bytes(24));
    try {
        $db->prepare("INSERT INTO settings (setting_key, setting_value)
                      VALUES ('wa_webhook_secret', ?)
                      ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")
           ->execute([$hookSecret]);
    } catch (\Throwable $e) { error_log('wa webhook secret: ' . $e->getMessage()); }
}
// Two forms of the same thing. The plain one is what the provider is given,
// with the secret travelling as its webhook token in an Authorization header;
// the one carrying ?k= is kept for providers that cannot send a header.
$plainHook  = rtrim(BASE_URL, '/') . '/modules/whatsapp/api/receive.php';
$webhookUrl = $plainHook . '?k=' . $hookSecret;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';
    $back   = BASE_URL . '/modules/whatsapp/connect.php';

    if ($action === 'save') {
        $provider = ($_POST['provider'] ?? 'green') === 'cloud' ? 'cloud' : 'green';
        $vals = ['wa_provider' => $provider];

        if ($provider === 'green') {
            $vals['wa_green_instance'] = trim($_POST['green_instance'] ?? '');
            $vals['wa_green_host']     = rtrim(trim($_POST['green_host'] ?? '') ?: 'https://api.greenapi.com', '/');
            // A blank token means "leave it alone" — the form never shows the
            // saved one back, so submitting the page must not wipe it.
            if (trim($_POST['green_token'] ?? '') !== '') {
                $vals['wa_green_token'] = trim($_POST['green_token']);
            }
        } else {
            $vals['wa_cloud_phone_id'] = trim($_POST['cloud_phone_id'] ?? '');
            $vals['wa_cloud_waba_id']  = trim($_POST['cloud_waba_id'] ?? '');
            if (trim($_POST['cloud_token'] ?? '') !== '') {
                $vals['wa_cloud_token'] = trim($_POST['cloud_token']);
            }
        }
        $vals['wa_country_code'] = preg_replace('/\D+/', '', $_POST['country_code'] ?? '254') ?: '254';

        try {
            $st = $db->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?,?)
                                ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
            foreach ($vals as $k => $v) $st->execute([$k, $v]);
            // The credentials themselves are never written to the log — only that
            // somebody changed them, and who.
            logActivity('update', 'settings', 0,
                'WhatsApp connection settings changed by ' . $me['name'] . ' (' . waProviderLabel($provider) . ')');
            setFlash('success', 'Saved. Check the connection below.');
        } catch (\Throwable $e) {
            error_log('wa connect save: ' . $e->getMessage());
            setFlash('error', 'Those settings could not be saved.');
        }
        redirect($back);
    }

    if ($action === 'fix_webhook') {
        // The secret goes BOTH in the address and as the provider's webhook
        // token. Apache on shared hosting commonly strips the Authorization
        // header unless a rewrite rule puts it back, and betting the whole
        // inbox on a header that may not survive the hop is not a bet worth
        // taking — the query string always arrives.
        $r = waDriverSetWebhook($webhookUrl, $hookSecret);
        logActivity('update', 'settings', 0,
            'WhatsApp receiving address repaired by ' . $me['name']
            . ($r['ok'] ? '.' : ' — failed: ' . $r['error']));
        setFlash($r['ok'] ? 'success' : 'error',
            $r['ok'] ? 'Done. The provider now delivers messages to this system.'
                     : ($r['error'] ?: 'The provider would not accept the address.'));
        redirect($back . '#receiving');
    }

    if ($action === 'logout') {
        $r = waDriverLogout();
        logActivity('update', 'settings', 0, 'WhatsApp phone unlinked by ' . $me['name'] . '.');
        setFlash($r['ok'] ? 'success' : 'error',
            $r['ok'] ? 'The phone has been unlinked. Scan again to connect a different one.'
                     : ($r['error'] ?: 'The phone could not be unlinked.'));
        redirect($back);
    }

    if ($action === 'template') {
        $id    = (int)($_POST['template_id'] ?? 0);
        $title = trim($_POST['title'] ?? '');
        $body  = trim($_POST['body'] ?? '');
        if ($title === '' || $body === '') {
            setFlash('error', 'A quick reply needs both a name and a message.');
        } else {
            try {
                if ($id > 0) {
                    $db->prepare("UPDATE wa_templates SET title=?, body=? WHERE id=?")
                       ->execute([mb_substr($title, 0, 80), $body, $id]);
                } else {
                    $db->prepare("INSERT INTO wa_templates (title, body, created_by) VALUES (?,?,?)")
                       ->execute([mb_substr($title, 0, 80), $body, (int)$me['id']]);
                }
                setFlash('success', 'Quick reply saved.');
            } catch (\Throwable $e) { setFlash('error', 'That could not be saved.'); }
        }
        redirect($back . '#replies');
    }

    if ($action === 'template_delete' && waCanAdmin()) {
        try { $db->prepare("DELETE FROM wa_templates WHERE id=?")->execute([(int)($_POST['template_id'] ?? 0)]); }
        catch (\Throwable $_) {}
        setFlash('success', 'Quick reply removed.');
        redirect($back . '#replies');
    }
}

$cfg       = waConfig();
$provider  = waProvider();
$status    = waConfigured() ? waDriverStatus(false)
                            : ['state' => 'unconfigured', 'label' => 'Not set up', 'qr' => null,
                               'phone' => '', 'detail' => 'Enter the connection details below to begin.'];
$templates = waTemplates($db);

// Sending and receiving fail independently, and only one of them is visible
// from the status band above. This is the other half.
$hook = waConfigured() ? waDriverWebhook()
                       : ['known' => false, 'url' => '', 'token_set' => false,
                          'incoming' => false, 'error' => ''];
$hookPointsHere = $hook['known'] && $hook['url'] !== ''
    && str_starts_with(rtrim($hook['url'], '/'), rtrim($plainHook, '/'));
$hookOk = $hookPointsHere && $hook['incoming'] && ($hook['token_set'] || str_contains($hook['url'], 'k='));

$lastIn = null;
try {
    $lastIn = $db->query("SELECT sent_at FROM wa_messages WHERE direction='in'
                            ORDER BY id DESC LIMIT 1")->fetchColumn() ?: null;
} catch (\Throwable $_) {}

$tone = match ($status['state']) {
    'connected' => 'ok',
    'scan', 'starting' => 'wait',
    default     => 'bad',
};

$pageTitle = 'WhatsApp Connection';
include __DIR__ . '/../../includes/header.php';
?>
<style>
.wc-head{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:20px}
.wc-head h1{font-size:21px;font-weight:700;color:var(--text);margin:0;display:flex;align-items:center;gap:10px}
.wc-head h1 i{width:38px;height:38px;border-radius:10px;background:#dcfce7;display:flex;
    align-items:center;justify-content:center;font-size:17px;color:#16a34a}

.wc-card{background:var(--surface);border:1px solid var(--border);border-radius:var(--r-lg);
    box-shadow:var(--sh);margin-bottom:20px;overflow:hidden}
.wc-card>header{padding:14px 18px;border-bottom:1px solid var(--border);display:flex;
    align-items:center;justify-content:space-between;gap:10px}
.wc-card>header h2{font-size:14px;font-weight:700;margin:0;color:var(--text);display:flex;align-items:center;gap:8px}
.wc-body{padding:18px}

/* The status band. Colour is never the only signal — every state says its name. */
.wc-state{display:flex;align-items:center;gap:14px;padding:16px 18px;border-radius:var(--r-lg);
    border:1px solid;margin-bottom:6px}
.wc-state .dot{width:11px;height:11px;border-radius:50%;flex-shrink:0}
.wc-state b{display:block;font-size:14px}
.wc-state span{font-size:12.5px;opacity:.9}
.wc-state.ok  {background:#f0fdf4;border-color:#bbf7d0;color:#14532d}
.wc-state.ok   .dot{background:#16a34a;box-shadow:0 0 0 4px rgba(22,163,74,.18)}
.wc-state.wait{background:#fffbeb;border-color:#fde68a;color:#78350f}
.wc-state.wait .dot{background:#d97706;box-shadow:0 0 0 4px rgba(217,119,6,.18)}
.wc-state.bad {background:#fef2f2;border-color:#fecaca;color:#7f1d1d}
.wc-state.bad  .dot{background:#dc2626;box-shadow:0 0 0 4px rgba(220,38,38,.18)}

.wc-qr{display:flex;gap:26px;align-items:flex-start;flex-wrap:wrap}
.wc-qr-box{width:250px;height:250px;border:1px solid var(--border);border-radius:14px;background:#fff;
    display:flex;align-items:center;justify-content:center;flex-shrink:0;position:relative;overflow:hidden}
.wc-qr-box img{width:100%;height:100%;object-fit:contain;padding:10px}
.wc-qr-box .ph{text-align:center;color:var(--text-3);font-size:12.5px;padding:18px}
.wc-steps{flex:1;min-width:260px}
.wc-steps ol{margin:0;padding-left:20px;font-size:13.5px;color:var(--text-2);line-height:2}
.wc-steps ol b{color:var(--text)}

.wc-field{margin-bottom:14px}
.wc-field label{display:block;font-size:12.5px;font-weight:600;color:var(--text);margin-bottom:5px}
.wc-field .hint{font-size:11.5px;color:var(--text-3);margin-top:4px}
.wc-pick{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:12px;margin-bottom:18px}
.wc-opt{border:2px solid var(--border);border-radius:12px;padding:14px;cursor:pointer;transition:.15s;background:var(--surface)}
.wc-opt:hover{border-color:var(--brand)}
.wc-opt.on{border-color:var(--brand);background:var(--brand-soft)}
.wc-opt b{display:block;font-size:13.5px;color:var(--text);margin-bottom:3px}
.wc-opt span{font-size:11.5px;color:var(--text-2);line-height:1.5;display:block}
.wc-opt input{margin-right:7px}

.wc-hook{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
.wc-hook code{flex:1;min-width:240px;background:var(--surface-alt);border:1px solid var(--border);
    border-radius:8px;padding:9px 12px;font-size:12px;color:var(--text);word-break:break-all}

.wc-tpl{display:flex;gap:12px;align-items:flex-start;padding:12px 0;border-top:1px solid var(--border)}
.wc-tpl:first-child{border-top:0}
.wc-tpl-b{flex:1;min-width:0}
.wc-tpl-b b{font-size:13px;color:var(--text);display:block}
.wc-tpl-b p{font-size:12.5px;color:var(--text-2);margin:3px 0 0;white-space:pre-wrap}

[data-theme="dark"] .wc-state.ok  {background:rgba(22,163,74,.12);border-color:#166534;color:#86efac}
[data-theme="dark"] .wc-state.wait{background:rgba(217,119,6,.12);border-color:#92400e;color:#fcd34d}
[data-theme="dark"] .wc-state.bad {background:rgba(220,38,38,.12);border-color:#991b1b;color:#fca5a5}
[data-theme="dark"] .wc-head h1 i{background:rgba(22,163,74,.18)}
[data-theme="dark"] .wc-qr-box{background:#fff}
</style>

<div class="wc-head">
    <h1><i class="fab fa-whatsapp"></i>WhatsApp Connection</h1>
    <a href="<?= BASE_URL ?>/modules/whatsapp/index.php" class="btn btn-sm btn-outline-secondary">
        <i class="fa fa-inbox me-1"></i>Open the inbox</a>
</div>

<div class="wc-card">
    <header>
        <h2><i class="fa fa-plug" style="color:#16a34a"></i>Status</h2>
        <span style="font-size:11.5px;color:var(--text-3)"><?= e(waProviderLabel()) ?></span>
    </header>
    <div class="wc-body">
        <div class="wc-state <?= $tone ?>" id="waState">
            <span class="dot"></span>
            <div>
                <b id="waStateLabel"><?= e($status['label']) ?><?=
                    $status['phone'] ? ' — ' . e($status['phone']) : '' ?></b>
                <span id="waStateDetail"><?= e($status['detail']) ?></span>
            </div>
        </div>

        <?php if ($provider === 'green' && waConfigured()): ?>
        <div class="wc-qr" style="margin-top:18px" id="waQrWrap">
            <div class="wc-qr-box" id="waQrBox">
                <div class="ph" id="waQrPh">
                    <?php if ($status['state'] === 'connected'): ?>
                        <i class="fa fa-circle-check" style="font-size:30px;color:#16a34a"></i>
                        <div style="margin-top:10px">Already linked.<br>No code is needed.</div>
                    <?php else: ?>
                        <i class="fa fa-spinner fa-spin" style="font-size:22px"></i>
                        <div style="margin-top:10px">Fetching the code…</div>
                    <?php endif; ?>
                </div>
            </div>
            <div class="wc-steps">
                <b style="font-size:13.5px;color:var(--text)">Linking the company phone</b>
                <ol>
                    <li>Open <b>WhatsApp</b> on the company phone.</li>
                    <li>Tap <b>Settings → Linked devices</b>.</li>
                    <li>Tap <b>Link a device</b>.</li>
                    <li>Point it at the code on the left.</li>
                </ol>
                <p style="font-size:12px;color:var(--text-3);margin-top:10px">
                    The code changes every few seconds and refreshes itself — there is no need
                    to reload the page. Once it is linked, everyone with WhatsApp rights can
                    send from their own login and the phone can go back in a drawer.
                </p>
                <?php if ($status['state'] === 'connected'): ?>
                <form method="post" class="mt-2"
                      onsubmit="return confirm('Unlink the phone? Nobody will be able to send until it is scanned again.')">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="logout">
                    <button class="btn btn-sm btn-outline-danger">
                        <i class="fa fa-link-slash me-1"></i>Unlink this phone</button>
                </form>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<div class="wc-card" id="receiving">
    <header>
        <h2><i class="fa fa-inbox" style="color:#2563eb"></i>Receiving</h2>
        <span style="font-size:11.5px;color:var(--text-3)">
            <?= $lastIn ? 'Last message in ' . e(fmtDate($lastIn, 'd M Y, H:i')) : 'Nothing received yet' ?></span>
    </header>
    <div class="wc-body">
        <?php // Being linked is what lets the yard SEND. Receiving is a separate
              // setting on the provider's side, and a connection can look perfect
              // while every incoming message is thrown away. ?>
        <div class="wc-state <?= $hookOk ? 'ok' : 'bad' ?>" style="margin-bottom:14px">
            <span class="dot"></span>
            <div>
                <b><?= $hookOk ? 'Messages are being delivered here'
                               : 'Messages are NOT reaching this system' ?></b>
                <span>
                <?php if (!waConfigured()): ?>
                    Set the connection up first.
                <?php elseif (!$hook['known']): ?>
                    <?= e($hook['error'] ?: 'The provider would not say where it is sending.') ?>
                <?php elseif ($hook['url'] === ''): ?>
                    The provider has no address at all, so replies go nowhere.
                <?php elseif (!$hookPointsHere): ?>
                    The provider is sending to <code><?= e($hook['url']) ?></code>, which is not this system.
                <?php elseif (!$hook['incoming']): ?>
                    The address is right, but incoming messages are switched off at the provider.
                <?php elseif (!$hook['token_set']): ?>
                    The address is right but carries no token, so this system refuses the calls.
                <?php else: ?>
                    The provider is pointed here and incoming messages are switched on.
                <?php endif; ?>
                </span>
            </div>
        </div>

        <?php if (waConfigured() && !$hookOk && waProvider() === 'green'): ?>
        <form method="post" class="d-flex gap-2 align-items-center flex-wrap">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="fix_webhook">
            <button class="btn btn-primary btn-sm">
                <i class="fa fa-wrench me-1"></i>Point the provider at this system</button>
            <span style="font-size:12px;color:var(--text-2)">
                Sets the address and the token at the provider, and switches incoming
                messages on. Nothing on the phone changes and no re-scan is needed.</span>
        </form>
        <?php endif; ?>

        <div style="margin-top:14px">
            <label style="display:block;font-size:12.5px;font-weight:600;margin-bottom:5px">
                The address to set by hand, if you prefer</label>
            <div class="wc-hook">
                <code id="waHook"><?= e($webhookUrl) ?></code>
                <button type="button" class="btn btn-sm btn-outline-secondary" onclick="waCopyHook(this)">
                    <i class="fa fa-copy me-1"></i>Copy</button>
            </div>
            <div class="hint" style="font-size:11.5px;color:var(--text-3);margin-top:4px">
                The secret on the end is what proves a caller really is the provider —
                without it anyone who found this address could invent a message from a customer.
            </div>
        </div>
    </div>
</div>
<form method="post">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="save">
    <div class="wc-card">
        <header><h2><i class="fa fa-sliders" style="color:#2563eb"></i>How this system reaches WhatsApp</h2></header>
        <div class="wc-body">
            <div class="wc-pick">
                <label class="wc-opt <?= $provider === 'green' ? 'on' : '' ?>" id="optGreen">
                    <input type="radio" name="provider" value="green" <?= $provider === 'green' ? 'checked' : '' ?>>
                    <b>Scan a QR code</b>
                    <span>Uses the number the yard already has, with its existing chats.
                          Live the moment it is scanned. Not sanctioned by Meta, so the
                          number carries some risk, and the bridge is a paid service.</span>
                </label>
                <label class="wc-opt <?= $provider === 'cloud' ? 'on' : '' ?>" id="optCloud">
                    <input type="radio" name="provider" value="cloud" <?= $provider === 'cloud' ? 'checked' : '' ?>>
                    <b>Official Meta API</b>
                    <span>A number verified with Meta. No ban risk and real delivery
                          receipts, but it cannot also be used in the WhatsApp app, and a
                          conversation can only be opened with an approved template.</span>
                </label>
            </div>

            <div id="paneGreen" style="<?= $provider === 'green' ? '' : 'display:none' ?>">
                <div class="row g-3">
                    <div class="col-md-4 wc-field">
                        <label>Instance ID</label>
                        <input type="text" name="green_instance" class="form-control"
                               value="<?= e($cfg['instance'] ?? '') ?>" placeholder="e.g. 1101234567">
                        <div class="hint">From the bridge provider's console.</div>
                    </div>
                    <div class="col-md-4 wc-field">
                        <label>API token</label>
                        <input type="password" name="green_token" class="form-control" autocomplete="new-password"
                               placeholder="<?= ($cfg['token'] ?? '') !== '' ? 'Saved — leave blank to keep it' : 'Paste the token' ?>">
                        <div class="hint">Never shown again once saved. Leave blank to keep the current one.</div>
                    </div>
                    <div class="col-md-4 wc-field">
                        <label>API host</label>
                        <input type="text" name="green_host" class="form-control"
                               value="<?= e($cfg['host'] ?? 'https://api.greenapi.com') ?>">
                        <div class="hint">Change only if your provider gave you a different address.</div>
                    </div>
                </div>
            </div>

            <div id="paneCloud" style="<?= $provider === 'cloud' ? '' : 'display:none' ?>">
                <div class="row g-3">
                    <div class="col-md-4 wc-field">
                        <label>Phone number ID</label>
                        <input type="text" name="cloud_phone_id" class="form-control"
                               value="<?= e($cfg['phone_id'] ?? '') ?>">
                        <div class="hint">From Meta → WhatsApp → API setup.</div>
                    </div>
                    <div class="col-md-4 wc-field">
                        <label>Permanent access token</label>
                        <input type="password" name="cloud_token" class="form-control" autocomplete="new-password"
                               placeholder="<?= ($cfg['token'] ?? '') !== '' ? 'Saved — leave blank to keep it' : 'Paste the token' ?>">
                        <div class="hint">Use a system-user token, not the temporary one.</div>
                    </div>
                    <div class="col-md-4 wc-field">
                        <label>Business account ID</label>
                        <input type="text" name="cloud_waba_id" class="form-control"
                               value="<?= e($cfg['waba_id'] ?? '') ?>">
                        <div class="hint">Optional — used for message templates.</div>
                    </div>
                </div>
            </div>

            <div class="row g-3">
                <div class="col-md-4 wc-field">
                    <label>Country code</label>
                    <input type="text" name="country_code" class="form-control"
                           value="<?= e(getSetting('wa_country_code', '254')) ?>">
                    <div class="hint">Used to turn 07… numbers into full international ones.</div>
                </div>
            </div>

            <button class="btn btn-primary"><i class="fa fa-floppy-disk me-1"></i>Save connection</button>
        </div>
    </div>
</form>

<div class="wc-card" id="replies">
    <header>
        <h2><i class="fa fa-bolt" style="color:#d97706"></i>Quick replies</h2>
        <span style="font-size:11.5px;color:var(--text-3)">
            <?= count($templates) ?> saved · {name} {company} {agent} {phone} are filled in automatically</span>
    </header>
    <div class="wc-body">
        <form method="post" class="row g-2 align-items-end mb-3">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="template">
            <div class="col-md-3">
                <label class="form-label small fw-semibold mb-1">Name</label>
                <input type="text" name="title" class="form-control form-control-sm" maxlength="80"
                       placeholder="e.g. Directions" required>
            </div>
            <div class="col-md-7">
                <label class="form-label small fw-semibold mb-1">Message</label>
                <input type="text" name="body" class="form-control form-control-sm"
                       placeholder="Hi {name}, we are on Mombasa Road opposite…" required>
            </div>
            <div class="col-md-2">
                <button class="btn btn-sm btn-primary w-100"><i class="fa fa-plus me-1"></i>Add</button>
            </div>
        </form>

        <?php if (!$templates): ?>
            <div style="color:var(--text-3);font-size:13px;text-align:center;padding:14px">
                No quick replies yet. The six sentences the yard sends every day belong here.
            </div>
        <?php else: foreach ($templates as $t): ?>
        <div class="wc-tpl">
            <div class="wc-tpl-b">
                <b><?= e($t['title']) ?></b>
                <p><?= e($t['body']) ?></p>
            </div>
            <form method="post" onsubmit="return confirm('Remove this quick reply?')">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="template_delete">
                <input type="hidden" name="template_id" value="<?= (int)$t['id'] ?>">
                <button class="btn btn-sm btn-outline-danger"><i class="fa fa-trash"></i></button>
            </form>
        </div>
        <?php endforeach; endif; ?>
    </div>
</div>

<script>
(function () {
    // Provider panes
    var g = document.getElementById('optGreen'), c = document.getElementById('optCloud');
    function sync() {
        var green = document.querySelector('input[name=provider][value=green]').checked;
        document.getElementById('paneGreen').style.display = green ? '' : 'none';
        document.getElementById('paneCloud').style.display = green ? 'none' : '';
        g.classList.toggle('on', green); c.classList.toggle('on', !green);
    }
    [g, c].forEach(function (el) { el && el.addEventListener('change', sync); });

    window.waCopyHook = function (btn) {
        var t = document.getElementById('waHook').textContent.trim();
        navigator.clipboard.writeText(t).then(function () {
            var o = btn.innerHTML;
            btn.innerHTML = '<i class="fa fa-check me-1"></i>Copied';
            setTimeout(function () { btn.innerHTML = o; }, 1600);
        });
    };

    // The QR rotates every few seconds, so the page follows it rather than
    // making somebody reload and wonder why the camera will not catch it.
    var box = document.getElementById('waQrBox');
    if (!box) return;
    var stop = false;

    function paint(s) {
        var band  = document.getElementById('waState');
        var label = document.getElementById('waStateLabel');
        var det   = document.getElementById('waStateDetail');
        if (band) {
            band.className = 'wc-state ' + (s.state === 'connected' ? 'ok'
                          : (s.state === 'scan' || s.state === 'starting') ? 'wait' : 'bad');
        }
        if (label) label.textContent = s.label + (s.phone ? ' — ' + s.phone : '');
        if (det)   det.textContent   = s.detail || '';

        if (s.state === 'connected') {
            box.innerHTML = '<div class="ph"><i class="fa fa-circle-check" style="font-size:30px;color:#16a34a"></i>'
                          + '<div style="margin-top:10px">Linked. The team can send.</div></div>';
            stop = true;
            // Show the unlink button without making them hunt for it.
            setTimeout(function () { location.reload(); }, 1200);
            return;
        }
        if (s.qr) {
            box.innerHTML = '<img alt="WhatsApp linking code" src="data:image/png;base64,' + s.qr + '">';
        } else if (!box.querySelector('img')) {
            box.innerHTML = '<div class="ph"><i class="fa fa-spinner fa-spin" style="font-size:22px"></i>'
                          + '<div style="margin-top:10px">' + (s.detail || 'Waiting…') + '</div></div>';
        }
    }

    function tick() {
        if (stop) return;
        fetch('<?= BASE_URL ?>/modules/whatsapp/api/status.php?qr=1', { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (d) { if (d && d.ok) paint(d); })
            .catch(function () { /* a dropped poll is not worth a message */ })
            .finally(function () { if (!stop) setTimeout(tick, 6000); });
    }
    tick();
}());
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>

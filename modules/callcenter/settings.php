<?php
/**
 * Call Centre — configuration.
 *
 * The shared number, the provider link, and the thresholds that decide when
 * the system starts warning about airtime.
 */
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/_bootstrap.php';
requireLogin();
canWrite('callcenter') || redirect(BASE_URL . '/modules/callcenter/index.php');

$db = getDB();
ccMigrate($db);
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    if (($_POST['action'] ?? '') === 'save') {
        $callerId = trim($_POST['cc_caller_id'] ?? '');
        $norm     = $callerId === '' ? '' : ccNormalizeNumber($callerId);
        if ($callerId !== '' && $norm === '') {
            $errors[] = 'The shared number is not a valid phone number.';
        }

        if (!$errors) {
            foreach ([
                'cc_caller_id'    => $norm,
                'cc_enabled'      => empty($_POST['cc_enabled']) ? '0' : '1',
                'cc_record_calls' => empty($_POST['cc_record_calls']) ? '0' : '1',
                'cc_low_balance'  => (string)max(0, (float)($_POST['cc_low_balance'] ?? 500)),
                'cc_rate_per_min' => (string)max(0, (float)($_POST['cc_rate_per_min'] ?? 3.5)),
                'cc_ring_seconds' => (string)max(10, (int)($_POST['cc_ring_seconds'] ?? 25)),
                'cc_token_url'    => trim($_POST['cc_token_url'] ?? ''),
                'cc_voice_url'    => trim($_POST['cc_voice_url'] ?? ''),
                'cc_balance_url'  => trim($_POST['cc_balance_url'] ?? ''),
                'cc_callback_key' => trim($_POST['cc_callback_key'] ?? ''),
            ] as $k => $v) {
                if ($v !== '') ccSetSetting($db, $k, $v);
                elseif (in_array($k, ['cc_caller_id','cc_callback_key'], true)) ccSetSetting($db, $k, '');
            }
            logActivity('update', 'callcenter', 0, 'Updated call-centre settings');
            setFlash('success', 'Call-centre settings saved.');
            redirect(BASE_URL . '/modules/callcenter/settings.php');
        }
    }

    if (($_POST['action'] ?? '') === 'test') {
        $bal = ccBalance(true);
        if (!empty($bal['ok'])) {
            setFlash('success', 'Connected. Account balance is ' . $bal['currency'] . ' ' . number_format($bal['amount'], 2) . '.');
        } else {
            setFlash('error', 'Could not reach the provider: ' . ($bal['error'] ?? 'unknown error'));
        }
        redirect(BASE_URL . '/modules/callcenter/settings.php');
    }
}

$cfg     = ccConfig();
$ready   = ccReady($cfg);
$balance = ccBalance();

$pageTitle = 'Call Centre Settings';
include __DIR__ . '/../../includes/header.php';
?>
<style>
.cs-card{ background:var(--surface); border:1px solid var(--border); border-radius:var(--r-lg);
    box-shadow:var(--sh-sm); margin-bottom:16px; overflow:hidden; }
.cs-head{ padding:12px 16px; border-bottom:1px solid var(--border); background:var(--surface-alt);
    display:flex; align-items:center; justify-content:space-between; gap:10px; }
.cs-title{ font-size:13.5px; font-weight:700; color:var(--text); margin:0; display:flex; align-items:center; gap:8px; }
.cs-title i{ color:var(--brand); }
.cs-body{ padding:16px; }
.form-label{ font-size:12px; font-weight:600; color:var(--text); margin-bottom:4px; }
.cs-url{ display:flex; align-items:center; gap:8px; background:var(--surface-alt); border:1px solid var(--border);
    border-radius:var(--r); padding:9px 11px; font-family:ui-monospace,Menlo,Consolas,monospace;
    font-size:12px; color:var(--text); word-break:break-all; }
.cs-steps{ counter-reset:s; list-style:none; padding:0; margin:0; }
.cs-steps li{ counter-increment:s; position:relative; padding:0 0 13px 32px; font-size:13px; color:var(--text); line-height:1.6; }
.cs-steps li::before{ content:counter(s); position:absolute; left:0; top:1px; width:22px; height:22px; border-radius:50%;
    background:var(--brand); color:#fff; font-size:11px; font-weight:800; display:flex; align-items:center; justify-content:center; }
</style>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h1 style="font-size:20px;font-weight:800;letter-spacing:-.4px;margin:0;color:var(--text)">
        <i class="fa fa-gear me-2" style="color:var(--brand)"></i>Call Centre Settings
    </h1>
    <a href="<?= BASE_URL ?>/modules/callcenter/index.php" class="btn btn-sm btn-outline-secondary">
        <i class="fa fa-arrow-left me-1"></i>Back
    </a>
</div>

<?php if ($errors): ?>
<div class="alert alert-danger py-2"><ul class="mb-0 small ps-3"><?php foreach ($errors as $e): ?><li><?= e($e) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<?php if (!$ready['ok']): ?>
<div class="alert alert-warning py-2 small">
    <i class="fa fa-triangle-exclamation me-1"></i>
    Calls cannot be made yet — still needed: <strong><?= e(implode(', ', $ready['missing'])) ?></strong>.
</div>
<?php endif; ?>

<div class="row g-3">
    <div class="col-lg-7">
        <form method="POST">
            <?= csrfField() ?><input type="hidden" name="action" value="save">

            <div class="cs-card">
                <div class="cs-head"><h2 class="cs-title"><i class="fa fa-phone"></i>The Shared Number</h2></div>
                <div class="cs-body">
                    <div class="row g-3">
                        <div class="col-md-7">
                            <label class="form-label">Call-centre number <span class="text-danger">*</span></label>
                            <input type="text" name="cc_caller_id" class="form-control form-control-sm"
                                   value="<?= e($cfg['caller_id']) ?>" placeholder="+254 20 000 0000">
                            <div class="form-text" style="font-size:11px">
                                Every agent calls out from this number, and clients ring it back.
                                It must be a voice-enabled number bought on your Africa's Talking account.
                            </div>
                        </div>
                        <div class="col-md-5">
                            <label class="form-label">Ring each agent for</label>
                            <div class="input-group input-group-sm">
                                <input type="number" name="cc_ring_seconds" min="10" max="120"
                                       class="form-control" value="<?= (int)$cfg['ring_seconds'] ?>">
                                <span class="input-group-text">seconds</span>
                            </div>
                        </div>
                        <div class="col-12">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="cc_enabled" id="ccEn" <?= $cfg['enabled'] ? 'checked' : '' ?>>
                                <label class="form-check-label small" for="ccEn">
                                    <strong>Call centre is live</strong> — agents can dial and inbound calls are answered
                                </label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="cc_record_calls" id="ccRec" <?= $cfg['record_calls'] ? 'checked' : '' ?>>
                                <label class="form-check-label small" for="ccRec">
                                    Record calls
                                    <span class="text-muted">— tell callers they are being recorded; it is a legal requirement in Kenya</span>
                                </label>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="cs-card">
                <div class="cs-head"><h2 class="cs-title"><i class="fa fa-coins"></i>Airtime Warnings</h2></div>
                <div class="cs-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Warn when balance drops below</label>
                            <div class="input-group input-group-sm">
                                <span class="input-group-text">KES</span>
                                <input type="number" name="cc_low_balance" min="0" step="50"
                                       class="form-control" value="<?= (float)$cfg['low_balance'] ?>">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Average cost per minute</label>
                            <div class="input-group input-group-sm">
                                <span class="input-group-text">KES</span>
                                <input type="number" name="cc_rate_per_min" min="0" step="0.5"
                                       class="form-control" value="<?= (float)$cfg['rate_per_min'] ?>">
                            </div>
                            <div class="form-text" style="font-size:11px">Only used to estimate minutes remaining.</div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="cs-card">
                <div class="cs-head">
                    <h2 class="cs-title"><i class="fa fa-plug"></i>Provider Endpoints</h2>
                    <span class="small text-muted">Change only if the provider moves them</span>
                </div>
                <div class="cs-body">
                    <div class="row g-2">
                        <div class="col-12">
                            <label class="form-label">Capability token URL</label>
                            <input type="text" name="cc_token_url" class="form-control form-control-sm font-monospace"
                                   value="<?= e($cfg['token_url']) ?>">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Voice API URL</label>
                            <input type="text" name="cc_voice_url" class="form-control form-control-sm font-monospace"
                                   value="<?= e($cfg['voice_url']) ?>">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Callback key <span class="text-muted fw-normal">(optional)</span></label>
                            <input type="text" name="cc_callback_key" class="form-control form-control-sm font-monospace"
                                   value="<?= e(getSetting('cc_callback_key', '')) ?>" placeholder="Blank = endpoint is open">
                            <div class="form-text" style="font-size:11px">
                                Adds <code>?key=…</code> to the callback URL below. The endpoint is public by
                                necessity; a key stops anyone who finds the address inventing call records.
                            </div>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Balance URL</label>
                            <input type="text" name="cc_balance_url" class="form-control form-control-sm font-monospace"
                                   value="<?= e($cfg['balance_url']) ?>">
                        </div>
                    </div>
                    <div class="form-text mt-2" style="font-size:11px">
                        API key and username come from
                        <a href="<?= BASE_URL ?>/modules/settings/messaging.php">Settings → Messaging</a>
                        — the same Africa's Talking account used for SMS.
                    </div>
                </div>
            </div>

            <div class="d-flex gap-2 mb-4">
                <button class="btn btn-primary"><i class="fa fa-floppy-disk me-1"></i>Save settings</button>
            </div>
        </form>

        <form method="POST" class="mb-4">
            <?= csrfField() ?><input type="hidden" name="action" value="test">
            <button class="btn btn-outline-primary btn-sm"><i class="fa fa-vial me-1"></i>Test the provider connection</button>
        </form>
    </div>

    <div class="col-lg-5">
        <div class="cs-card">
            <div class="cs-head"><h2 class="cs-title"><i class="fa fa-link"></i>Point the Provider Here</h2></div>
            <div class="cs-body">
                <p class="small text-muted">
                    Inbound calls only work once Africa's Talking knows where to ask for instructions.
                    Set this as the Voice callback URL for your number:
                </p>
                <div class="cs-url mb-3">
                    <i class="fa fa-link" style="color:var(--brand)"></i>
                    <span id="cbUrl"><?= e(ccCallbackUrl()) ?><?php $__k = getSetting('cc_callback_key',''); echo $__k !== '' ? '?key=' . e($__k) : ''; ?></span>
                    <button type="button" class="btn btn-xs btn-outline-secondary ms-auto" onclick="
                        navigator.clipboard.writeText(document.getElementById('cbUrl').textContent.trim());
                        this.innerHTML='<i class=&quot;fa fa-check&quot;></i>';">
                        <i class="fa fa-copy"></i>
                    </button>
                </div>
                <ol class="cs-steps">
                    <li>Sign in at <strong>africastalking.com</strong> and open <strong>Voice</strong>.</li>
                    <li>Buy a voice-enabled phone number if you have not already — that is the shared number above.</li>
                    <li>On that number, set the <strong>callback URL</strong> to the address above.</li>
                    <li>Enable <strong>WebRTC / browser calling</strong> on the account so agents can talk from their laptops.</li>
                    <li>Top up the account, then switch <strong>Call centre is live</strong> on.</li>
                </ol>
                <div class="alert alert-secondary py-2 small mb-0">
                    <i class="fa fa-circle-info me-1"></i>
                    This URL must be reachable from the internet over <strong>https</strong>. Browsers also
                    refuse microphone access on plain http, so agents could not talk either way.
                </div>
            </div>
        </div>

        <div class="cs-card">
            <div class="cs-head"><h2 class="cs-title"><i class="fa fa-wallet"></i>Account</h2></div>
            <div class="cs-body">
                <?php if (!empty($balance['ok'])): ?>
                <div style="font-size:26px;font-weight:900;color:var(--text)">
                    <?= e($balance['currency']) ?> <?= number_format($balance['amount'], 2) ?>
                </div>
                <div class="small text-muted">
                    <?php $mins = ccEstimatedMinutes($balance, $cfg); ?>
                    <?= $mins !== null ? 'about ' . number_format($mins) . ' minutes of calling' : 'live balance' ?>
                </div>
                <?php else: ?>
                <div class="text-muted small">
                    <i class="fa fa-circle-exclamation me-1"></i>
                    Balance unavailable — <?= e($balance['error'] ?? 'credentials not set') ?>
                </div>
                <?php endif; ?>
                <a href="<?= BASE_URL ?>/modules/callcenter/topup.php" class="btn btn-sm btn-outline-primary w-100 mt-3">
                    <i class="fa fa-coins me-1"></i>Airtime &amp; top-ups
                </a>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>

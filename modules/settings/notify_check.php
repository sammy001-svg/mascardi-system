<?php
/**
 * Notification health check — the walkthrough, run from inside the system.
 *
 * Notifications fail quietly by design: nothing is allowed to break the page
 * that was recording a deposit just because a message could not go out. That is
 * the right trade, and it has a cost — when messages stop arriving there is
 * nothing on screen to say why, and every link in the chain looks identical
 * from the outside.
 *
 * There are eight links. WhatsApp has to be connected; the number has to be
 * usable; the switch for that event has to be on; the customer has to have a
 * phone on file; the document link needs a signing key and a public address to
 * live at; the provider has to accept the message; and the provider has to be
 * able to reach us back. A failure in any one of them produces the same
 * symptom, which is silence.
 *
 * So this page checks each one, says which link is broken, and — because a
 * green tick on a page is not the same as a message on somebody's phone — will
 * send a real one through the real path and show exactly what came back.
 */

require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/dispatch.php';
require_once __DIR__ . '/../../includes/whatsapp.php';
require_once __DIR__ . '/../whatsapp/_drivers.php';
require_once __DIR__ . '/../whatsapp/_tools.php';
require_once __DIR__ . '/../whatsapp/_auto.php';
requireLogin();
requireRole('admin');

$pageTitle = 'Notification Health Check';
$db        = getDB();
dispatchMigrate($db);
try { waMigrate($db); } catch (\Throwable $e) {}

// ── A real send, through the real path ──────────────────────────────────────
$testResult = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'test_send') {
    verifyCsrf();
    $phone = trim($_POST['phone'] ?? '');
    $event = in_array($_POST['event'] ?? '', dispatchClientEvents(), true) ? $_POST['event'] : 'reservation';

    if ($phone === '') {
        $testResult = ['ok' => false, 'why' => 'Enter a phone number first.'];
    } elseif (!dispatchOn($event, 'client')) {
        $testResult = ['ok' => false, 'why' => 'Customer messages for "' . e(dispatchEvents()[$event])
                     . '" are switched off, so nothing would be sent. Turn it on under '
                     . 'Messaging &amp; Alerts → Documents &amp; Updates.'];
    } else {
        // Deliberately the same call the real events make, so a pass here means
        // the real thing works rather than that a test harness works.
        $co = getSetting('company_name', 'Mascardi');
        $ok = dispatchToClient($phone, $event,
            "This is a test message from {$co}.\n\n"
            . "If you can read this, WhatsApp notifications are working. "
            . "No action is needed.");

        if ($ok) {
            $testResult = ['ok' => true, 'why' => 'Sent. It should arrive on ' . e($phone)
                         . ' within a few seconds, and it is now in the WhatsApp inbox.'];
        } else {
            // The reason is on the message row the attempt just wrote.
            $err = '';
            try {
                $st = $db->prepare("SELECT error FROM wa_messages
                                     WHERE direction = 'out' AND status = 'failed'
                                  ORDER BY id DESC LIMIT 1");
                $st->execute();
                $err = (string)($st->fetchColumn() ?: '');
            } catch (\Throwable $e) {}
            $testResult = ['ok' => false, 'why' => 'It did not send.'
                         . ($err !== '' ? ' The provider said: ' . e($err) : '')
                         . ' The checks below should say why.'];
        }
    }
}

// ── Making Karl answer, now, and reporting what happened ────────────────────
//
// "He does not respond" has six possible causes and they are indistinguishable
// from outside. This runs the real waAutoRespond() against a real waiting
// conversation and prints its own account of what it did, which turns the
// question into a one-click answer rather than an afternoon of inference.
$karlRun = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'karl_now') {
    verifyCsrf();
    try {
        $target = (int)($_POST['conversation_id'] ?? 0);
        if ($target <= 0) {
            $target = (int)($db->query("
                SELECT c.id FROM wa_conversations c
                  JOIN wa_messages m ON m.id = (SELECT MAX(m2.id) FROM wa_messages m2
                                                 WHERE m2.conversation_id = c.id)
                 WHERE c.status='open' AND m.direction='in'
              ORDER BY m.sent_at DESC LIMIT 1")->fetchColumn() ?: 0);
        }

        if ($target <= 0) {
            $karlRun = ['ok' => false, 'why' => 'No conversation is waiting on an answer, so '
                      . 'there is nothing for him to reply to. Have somebody send a WhatsApp '
                      . 'message to the yard and run this again.'];
        } else {
            // The switch is stepped over on purpose. Left in place it is the
            // only answer this button can ever give on an install where it is
            // off, which hides whatever else is wrong until somebody switches
            // it on and finds the next fault waiting.
            $was = waAutoConfig()['enabled'];
            $r   = waAutoRespond($db, $target, true);

            $note = $was ? '' : ' Note that automatic replies are switched off, so he would '
                              . 'not have done this on his own — but everything else about the '
                              . 'attempt is real.';

            $karlRun = $r['sent']
                ? ['ok' => true,  'why' => 'He replied: ' . $r['why'] . '. The message is in the '
                                         . 'thread and has gone to the customer.' . $note]
                : ['ok' => false, 'why' => 'He did not reply — ' . rtrim($r['why'], '.') . '.' . $note];
        }
    } catch (\Throwable $e) {
        $karlRun = ['ok' => false, 'why' => 'It stopped with an error: ' . $e->getMessage()];
    }
}

// ── The checks ──────────────────────────────────────────────────────────────
$checks = [];
$add = function (string $name, string $state, string $detail, string $fix = '') use (&$checks) {
    $checks[] = ['name' => $name, 'state' => $state, 'detail' => $detail, 'fix' => $fix];
};

// 1. Credentials
$cfg = waConfig();
if (!waConfigured()) {
    $add('WhatsApp connection', 'fail',
        'No credentials are saved, so nothing can be sent at all.',
        BASE_URL . '/modules/whatsapp/connect.php');
} else {
    $add('WhatsApp connection', 'pass',
        waProviderLabel() . ' is configured.');
}

// 2. Is the link actually live? Asked of the provider, not of the settings.
if (waConfigured()) {
    try {
        $st = waDriverStatus(false);
        $live = ($st['state'] ?? '') === 'connected';
        $add('The phone is linked', $live ? 'pass' : 'fail',
            $live
              ? 'The provider reports the number as connected'
                . (!empty($st['phone']) ? ' (' . e($st['phone']) . ').' : '.')
              : 'The provider says: ' . e($st['label'] ?? $st['state'] ?? 'unknown')
                . '. Saved credentials are not the same as a linked phone — this is the '
                . 'one that stops messages even when everything else looks right.',
            $live ? '' : BASE_URL . '/modules/whatsapp/connect.php');
    } catch (\Throwable $e) {
        $add('The phone is linked', 'warn',
            'Could not reach the provider to ask: ' . e($e->getMessage()));
    }
}

// 3. Where customers' replies come back to
try {
    $hook   = waConfigured() ? waDriverWebhook() : ['known' => false, 'url' => '', 'incoming' => false, 'error' => ''];
    $expect = rtrim(BASE_URL, '/') . '/modules/whatsapp/api/receive.php';

    if (!$hook['known']) {
        $add('Replies come back to us', 'warn',
            'Could not read the setting from the provider'
            . (!empty($hook['error']) ? ': ' . e($hook['error']) : '.')
            . ' Notifications will still go OUT; this only governs what comes back.',
            BASE_URL . '/modules/whatsapp/connect.php');
    } elseif (trim((string)$hook['url']) === '') {
        $add('Replies come back to us', 'fail',
            'No address is set with the provider, so anything a customer writes back is '
            . 'dropped — including replies to these notifications.',
            BASE_URL . '/modules/whatsapp/connect.php#receiving');
    } elseif (!str_starts_with((string)$hook['url'], $expect)) {
        $add('Replies come back to us', 'fail',
            'The provider posts to <code>' . e($hook['url']) . '</code>, which is not this '
            . 'system. It should start <code>' . e($expect) . '</code>.',
            BASE_URL . '/modules/whatsapp/connect.php#receiving');
    } elseif (!$hook['incoming']) {
        $add('Replies come back to us', 'fail',
            'The address is right but incoming messages are switched off at the provider.',
            BASE_URL . '/modules/whatsapp/connect.php#receiving');
    } else {
        $add('Replies come back to us', 'pass', 'The provider posts to this system.');
    }
} catch (\Throwable $e) {
    $add('Replies come back to us', 'warn', 'Could not check: ' . e($e->getMessage()));
}

// 4. The address the customer's document link will point at
$base = rtrim(BASE_URL, '/');
$host = $_SERVER['HTTP_HOST'] ?? '';
if ($base === '' || str_contains($base, 'localhost') || str_contains($base, '127.0.0.1')) {
    $add('Document links have a public address', 'fail',
        'BASE_URL is <code>' . e($base ?: '(empty)') . '</code>. Every link sent to a '
        . 'customer is built from it, so they would all be unopenable.');
} elseif ($host !== '' && !str_contains($base, $host)) {
    $add('Document links have a public address', 'warn',
        'BASE_URL is <code>' . e($base) . '</code> but you are reading this on <code>'
        . e($host) . '</code>. Links will point at the former.');
} else {
    $add('Document links have a public address', 'pass', e($base));
}

// 5. The key that signs them
$signKey = (defined('APP_KEY') && APP_KEY !== '') ? 'APP_KEY'
         : (getSetting('wa_webhook_secret', '') !== '' ? 'the webhook secret'
         : (getSetting('wa_green_token', '') !== '' ? 'the provider token' : ''));
if ($signKey === '') {
    $add('Document links are signed', 'fail',
        'There is no key to sign links with, so every link falls back to a shared '
        . 'default — which is the same as not signing them.');
} else {
    $add('Document links are signed', 'pass', 'Signed with ' . $signKey . '.');
}

// 6. Which events will actually speak to a customer
$onEvents = $offEvents = [];
foreach (dispatchClientEvents() as $ev) {
    $label = dispatchEvents()[$ev] ?? $ev;
    if (dispatchOn($ev, 'client')) { $onEvents[] = $label; } else { $offEvents[] = $label; }
}
if (!$onEvents) {
    $add('Events that message the customer', 'fail',
        'Every one is switched off, so nothing will ever be sent.',
        BASE_URL . '/modules/settings/messaging.php?tab=notify');
} else {
    $add('Events that message the customer', $offEvents ? 'warn' : 'pass',
        'On: ' . e(implode(', ', $onEvents)) . '.'
        . ($offEvents ? ' Off: ' . e(implode(', ', $offEvents)) . '.' : ''),
        $offEvents ? BASE_URL . '/modules/settings/messaging.php?tab=notify' : '');
}

// 7. Whether the team can be reached
try {
    $staff = (int)$db->query("SELECT COUNT(*) FROM users WHERE status='active'")->fetchColumn();
    $withPhone = (int)$db->query("SELECT COUNT(*) FROM users
                                   WHERE status='active' AND phone IS NOT NULL AND phone <> ''")->fetchColumn();
    $anyStaffWa = false;
    foreach (array_keys(dispatchEvents()) as $ev) if (dispatchOn($ev, 'whatsapp')) { $anyStaffWa = true; break; }
    if (!$anyStaffWa) {
        $add('The team on WhatsApp', 'off', 'Switched off for every event — nothing to check.');
    } elseif ($withPhone === 0) {
        $add('The team on WhatsApp', 'fail',
            'It is switched on, but none of the ' . $staff . ' active staff have a phone number '
            . 'on their user record, so it has nowhere to send.',
            BASE_URL . '/modules/users/index.php');
    } else {
        $add('The team on WhatsApp', $withPhone < $staff ? 'warn' : 'pass',
            $withPhone . ' of ' . $staff . ' active staff have a phone number.',
            $withPhone < $staff ? BASE_URL . '/modules/users/index.php' : '');
    }
} catch (\Throwable $e) {
    $add('The team on WhatsApp', 'warn', 'Could not check: ' . e($e->getMessage()));
}

// 8. Email
$emailOk = getSetting('smtp_host', '') !== '' && getSetting('smtp_from_email', '') !== '';
$anyMail = false;
foreach (array_keys(dispatchEvents()) as $ev) if (dispatchOn($ev, 'email')) { $anyMail = true; break; }
if (!$anyMail) {
    $add('The team by email', 'off', 'Switched off for every event — nothing to check.');
} else {
    $add('The team by email', $emailOk ? 'pass' : 'fail',
        $emailOk ? 'SMTP is configured.' : 'It is switched on but SMTP is not configured.',
        $emailOk ? '' : BASE_URL . '/modules/settings/index.php?tab=email');
}

// ── What has actually been attempted ────────────────────────────────────────
// The checks above describe how things are set up. This is what happened, which
// is the part that settles an argument.
$recent = [];
$failed24 = $sent24 = 0;
try {
    $st = $db->query("
        SELECT m.id, m.status, m.error, m.sent_at, m.body,
               c.contact_phone, c.contact_name
          FROM wa_messages m
          JOIN wa_conversations c ON c.id = m.conversation_id
         WHERE m.direction = 'out' AND m.sent_by IS NULL
      ORDER BY m.id DESC LIMIT 15");
    $recent = $st->fetchAll(PDO::FETCH_ASSOC);

    $sent24 = (int)$db->query("SELECT COUNT(*) FROM wa_messages
                                WHERE direction='out' AND sent_by IS NULL AND status='sent'
                                  AND sent_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)")->fetchColumn();
    $failed24 = (int)$db->query("SELECT COUNT(*) FROM wa_messages
                                  WHERE direction='out' AND sent_by IS NULL AND status='failed'
                                    AND sent_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)")->fetchColumn();
} catch (\Throwable $e) {}

// ── Karl answering customers ────────────────────────────────────────────────
//
// Separate from the chain above because it fails for its own reasons, and
// because the decision is already written down: waAutoDecide() returns why it
// said no. Asking it about a real waiting conversation and printing the answer
// verbatim beats any amount of inference from the outside.
$karl = ['on' => false, 'why' => '', 'thread' => '', 'inbound24' => 0, 'inbound7' => 0,
         'ai' => false, 'hours' => false, 'sweep' => '', 'waiting' => 0];
try {
    $ac = waAutoConfig();
    $karl['on']    = $ac['enabled'];
    $karl['hours'] = waWithinHours($db, $ac);
    $karl['ai']    = function_exists('carlAiAvailable') && carlAiAvailable();

    // Is anything arriving at all? If not, nothing else about Karl matters —
    // he cannot answer a message that never reached this system, and the same
    // silence explains an import that brought back only our own messages.
    $karl['inbound24'] = (int)$db->query("SELECT COUNT(*) FROM wa_messages
                                           WHERE direction='in'
                                             AND sent_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)")->fetchColumn();
    $karl['inbound7']  = (int)$db->query("SELECT COUNT(*) FROM wa_messages
                                           WHERE direction='in'
                                             AND sent_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)")->fetchColumn();

    $last = (int)($db->query("SELECT setting_value FROM settings
                               WHERE setting_key='wa_auto_last_sweep'")->fetchColumn() ?: 0);
    $karl['sweep'] = $last > 0
        ? (int)(time() - $last) . ' seconds ago'
        : 'never — no sweep has run on this install';

    // The newest thread actually waiting on an answer, and the verdict on it.
    $row = $db->query("
        SELECT c.id, c.contact_name, c.contact_phone
          FROM wa_conversations c
          JOIN wa_messages m ON m.id = (SELECT MAX(m2.id) FROM wa_messages m2
                                         WHERE m2.conversation_id = c.id)
         WHERE c.status='open' AND m.direction='in'
      ORDER BY m.sent_at DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);

    $karl['waiting'] = (int)$db->query("
        SELECT COUNT(*) FROM wa_conversations c
          JOIN wa_messages m ON m.id = (SELECT MAX(m2.id) FROM wa_messages m2
                                         WHERE m2.conversation_id = c.id)
         WHERE c.status='open' AND m.direction='in'
           AND m.sent_at >= DATE_SUB(NOW(), INTERVAL 2 DAY)")->fetchColumn();

    if ($row) {
        $karl['thread'] = (string)($row['contact_name'] ?: $row['contact_phone']);
        $d = waAutoDecide($db, (int)$row['id']);
        $karl['why'] = ($d['allow'] ? 'He would reply: ' : 'He would stay quiet: ') . $d['why'];
    } else {
        $karl['why'] = 'No conversation is waiting on an answer right now, so there is '
                     . 'nothing for him to decide about.';
    }
} catch (\Throwable $e) {
    $karl['why'] = 'Could not check: ' . $e->getMessage();
}

$fails = count(array_filter($checks, fn($c) => $c['state'] === 'fail'));
$warns = count(array_filter($checks, fn($c) => $c['state'] === 'warn'));

include __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <h5 class="mb-1"><i class="fa fa-stethoscope me-2 text-primary"></i>Notification Health Check</h5>
        <p class="text-muted small mb-0">
            Every link in the chain between something happening here and a message arriving there.
        </p>
    </div>
    <div class="d-flex gap-2">
        <a href="<?= BASE_URL ?>/modules/settings/messaging.php?tab=notify" class="btn btn-outline-secondary btn-sm">
            <i class="fa fa-sliders me-1"></i>Settings
        </a>
        <a href="<?= BASE_URL ?>/modules/settings/notify_check.php" class="btn btn-outline-primary btn-sm">
            <i class="fa fa-rotate me-1"></i>Re-run
        </a>
    </div>
</div>

<?php if ($testResult): ?>
<div class="alert alert-<?= $testResult['ok'] ? 'success' : 'danger' ?> d-flex align-items-start gap-2">
    <i class="fa fa-<?= $testResult['ok'] ? 'circle-check' : 'circle-exclamation' ?> mt-1"></i>
    <div><?= $testResult['why'] ?></div>
</div>
<?php endif; ?>

<div class="alert alert-<?= $fails ? 'danger' : ($warns ? 'warning' : 'success') ?> d-flex align-items-start gap-2">
    <i class="fa fa-<?= $fails ? 'circle-exclamation' : ($warns ? 'triangle-exclamation' : 'circle-check') ?> mt-1"></i>
    <div>
        <?php if ($fails): ?>
            <strong><?= $fails ?> thing<?= $fails === 1 ? '' : 's' ?> will stop messages from arriving.</strong>
            Each one is marked below with what to do about it.
        <?php elseif ($warns): ?>
            <strong>Nothing is broken, but <?= $warns ?> thing<?= $warns === 1 ? ' is' : 's are' ?> worth a look.</strong>
        <?php else: ?>
            <strong>Every check passed.</strong> If a customer still did not receive something,
            send yourself a real test below — that is the only check that proves delivery.
        <?php endif; ?>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header fw-semibold d-flex justify-content-between align-items-center">
        <span><i class="fa fa-robot me-2" style="color:#0891b2"></i>Karl answering customers on WhatsApp</span>
        <span class="badge bg-<?= $karl['on'] ? 'success' : 'secondary' ?>-subtle
                     text-<?= $karl['on'] ? 'success' : 'secondary' ?>">
            <?= $karl['on'] ? 'Switched on' : 'Switched off' ?>
        </span>
    </div>
    <div class="card-body">
        <?php if (!$karl['on']): ?>
        <div class="alert alert-warning py-2 small mb-3">
            <i class="fa fa-power-off me-1"></i>
            <strong>Automatic replies are switched off.</strong> Nothing below matters until
            they are on — he will never answer anybody.
            <a href="<?= BASE_URL ?>/modules/whatsapp/connect.php#autoreply">Switch them on</a>.
        </div>
        <?php endif; ?>

        <?php if ($karl['inbound7'] === 0): ?>
        <div class="alert alert-danger py-2 small mb-3">
            <i class="fa fa-inbox me-1"></i>
            <strong>No customer message has arrived in seven days.</strong> Karl cannot answer
            what never reaches this system, and nothing else here will help until it does.
            This is the receiving side of WhatsApp — the same fault that makes an import bring
            back only our own messages.
            <a href="<?= BASE_URL ?>/modules/whatsapp/connect.php#receiving">Check receiving</a>.
        </div>
        <?php endif; ?>

        <div class="row g-3 mb-3" style="font-size:13px">
            <div class="col-6 col-md-3">
                <div class="text-muted small">Customer messages</div>
                <div class="fw-semibold"><?= $karl['inbound24'] ?> today · <?= $karl['inbound7'] ?> this week</div>
            </div>
            <div class="col-6 col-md-3">
                <div class="text-muted small">Waiting on a reply</div>
                <div class="fw-semibold"><?= $karl['waiting'] ?></div>
            </div>
            <div class="col-6 col-md-3">
                <div class="text-muted small">Yard is</div>
                <div class="fw-semibold"><?= $karl['hours'] ? 'open — he waits his grace period first'
                                                            : 'closed — he answers straight away' ?></div>
            </div>
            <div class="col-6 col-md-3">
                <div class="text-muted small">Last sweep</div>
                <div class="fw-semibold"><?= e($karl['sweep']) ?></div>
            </div>
        </div>

        <?php if (!$karl['ai']): ?>
        <div class="alert alert-warning py-2 small mb-3">
            <i class="fa fa-brain me-1"></i>
            No AI is reachable, so Karl can only send the fixed acknowledgement — and he sends
            that once per conversation rather than repeating it at every message.
            <a href="<?= BASE_URL ?>/modules/settings/index.php?tab=carl">AI settings</a>.
        </div>
        <?php endif; ?>

        <div class="p-3 rounded" style="background:var(--bg,#f8fafc);font-size:13px">
            <div class="text-muted small mb-1">
                <?= $karl['thread'] !== ''
                      ? 'Asked about the newest waiting conversation (' . e($karl['thread']) . '):'
                      : 'His own verdict right now:' ?>
            </div>
            <div class="fw-medium"><?= e($karl['why']) ?></div>
        </div>

        <?php if ($karlRun): ?>
        <div class="alert alert-<?= $karlRun['ok'] ? 'success' : 'danger' ?> py-2 small mt-3 mb-0">
            <i class="fa fa-<?= $karlRun['ok'] ? 'circle-check' : 'circle-exclamation' ?> me-1"></i>
            <?= e($karlRun['why']) ?>
        </div>
        <?php endif; ?>

        <form method="POST" class="mt-3">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="karl_now">
            <button class="btn btn-sm btn-outline-primary">
                <i class="fa fa-play me-1"></i>Make him answer now
            </button>
            <span class="text-muted ms-2" style="font-size:11.5px">
                Runs the real reply on the newest waiting conversation and says what happened.
                <strong>This sends a real WhatsApp message to that customer</strong>, and works
                even while automatic replies are switched off.
            </span>
        </form>

        <div class="text-muted mt-3" style="font-size:11.5px">
            During opening hours a reply is triggered by a grace period expiring, which is not
            an event anything sends — it is picked up by a sweep that rides on the staff unread
            badge, so somebody has to be signed in. A cron job calling
            <code>cron_auto.php</code> removes that dependency.
        </div>
    </div>
</div>

<div class="row g-4">
    <div class="col-lg-7">
        <div class="card">
            <div class="card-header fw-semibold"><i class="fa fa-list-check me-2 text-primary"></i>The chain</div>
            <div class="list-group list-group-flush">
                <?php foreach ($checks as $c):
                    [$icon, $colour] = match ($c['state']) {
                        'pass' => ['fa-circle-check',        '#16a34a'],
                        'fail' => ['fa-circle-xmark',        '#dc2626'],
                        'warn' => ['fa-triangle-exclamation','#d97706'],
                        default=> ['fa-circle-minus',        '#94a3b8'],
                    }; ?>
                <div class="list-group-item d-flex gap-3 align-items-start">
                    <i class="fa <?= $icon ?> mt-1" style="color:<?= $colour ?>;font-size:15px"></i>
                    <div class="flex-grow-1">
                        <div class="fw-medium" style="font-size:14px"><?= e($c['name']) ?></div>
                        <div class="text-muted" style="font-size:12.5px"><?= $c['detail'] ?></div>
                        <?php if ($c['fix']): ?>
                        <a href="<?= e($c['fix']) ?>" class="small">Fix this →</a>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <div class="col-lg-5">
        <div class="card">
            <div class="card-header fw-semibold"><i class="fa fa-paper-plane me-2 text-primary"></i>Send a real one</div>
            <div class="card-body">
                <p class="text-muted small">
                    Goes through exactly the same code a real reservation does. A green tick above
                    says the wiring is right; this says the message arrived.
                </p>
                <form method="POST">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="test_send">
                    <div class="mb-2">
                        <label class="form-label small">Send to</label>
                        <input type="tel" name="phone" class="form-control form-control-sm"
                               placeholder="07xx xxx xxx" value="<?= e($_POST['phone'] ?? '') ?>" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small">As which event</label>
                        <select name="event" class="form-select form-select-sm">
                            <?php foreach (dispatchClientEvents() as $ev): ?>
                            <option value="<?= e($ev) ?>" <?= ($_POST['event'] ?? '') === $ev ? 'selected' : '' ?>>
                                <?= e(dispatchEvents()[$ev] ?? $ev) ?>
                                <?= dispatchOn($ev, 'client') ? '' : ' — switched off' ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button class="btn btn-primary btn-sm w-100">
                        <i class="fa fa-paper-plane me-1"></i>Send the test
                    </button>
                </form>
            </div>
        </div>

        <div class="card mt-3">
            <div class="card-header fw-semibold d-flex justify-content-between align-items-center">
                <span><i class="fa fa-clock-rotate-left me-2 text-primary"></i>Last 24 hours</span>
                <span class="small">
                    <span class="text-success"><?= $sent24 ?> sent</span>
                    <?php if ($failed24): ?>
                        · <span class="text-danger"><?= $failed24 ?> failed</span>
                    <?php endif; ?>
                </span>
            </div>
            <div class="card-body py-2 px-0">
                <?php if (!$recent): ?>
                    <p class="text-muted small px-3 my-2">
                        The system has never tried to message a customer. If events have happened
                        since this was switched on, that points at the switches rather than at
                        WhatsApp.
                    </p>
                <?php else: ?>
                <div class="list-group list-group-flush" style="font-size:12.5px">
                    <?php foreach ($recent as $m): ?>
                    <div class="list-group-item py-2">
                        <div class="d-flex justify-content-between gap-2">
                            <span class="fw-medium"><?= e($m['contact_name'] ?: $m['contact_phone']) ?></span>
                            <span class="badge bg-<?= $m['status'] === 'sent' ? 'success' :
                                ($m['status'] === 'failed' ? 'danger' : 'secondary') ?>-subtle
                                text-<?= $m['status'] === 'sent' ? 'success' :
                                ($m['status'] === 'failed' ? 'danger' : 'secondary') ?>">
                                <?= e($m['status']) ?>
                            </span>
                        </div>
                        <div class="text-muted"><?= e(mb_substr(str_replace("\n", ' ', (string)$m['body']), 0, 70)) ?></div>
                        <?php if (!empty($m['error'])): ?>
                        <div class="text-danger" style="font-size:11.5px"><?= e($m['error']) ?></div>
                        <?php endif; ?>
                        <div class="text-muted" style="font-size:11px"><?= e($m['sent_at']) ?></div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>

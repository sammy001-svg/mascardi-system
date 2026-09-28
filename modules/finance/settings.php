<?php
/**
 * How the credit reminders behave.
 *
 * Kept on its own page rather than buried in the system settings, because the
 * person who decides when a buyer gets chased is the finance manager, not an
 * administrator — and these are the switches they will actually want to move
 * after watching the first month's reminders go out.
 */

require_once __DIR__ . '/_credit.php';
requireLogin();

// Narrower than the book itself on purpose. Reading who owes what is daily
// finance work; deciding whether the company emails its customers at all, and
// how often, is policy — and canWrite('installments') would have handed that
// switch to every cashier.
if (!hasRole('finance_manager')) {
    setFlash('danger', 'You do not have permission to change the reminder settings.');
    redirect(BASE_URL . '/modules/finance/receivables.php');
}

$db = getDB();
creditMigrate($db);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $cc = trim((string)($_POST['credit_remind_cc'] ?? ''));
    if ($cc !== '' && !filter_var($cc, FILTER_VALIDATE_EMAIL)) $cc = '';

    $vals = [
        'credit_remind_enabled'        => !empty($_POST['credit_remind_enabled']) ? '1' : '0',
        'credit_remind_days_before'    => (string)max(1, min(30, (int)($_POST['credit_remind_days_before'] ?? 3))),
        'credit_remind_on_due'         => !empty($_POST['credit_remind_on_due']) ? '1' : '0',
        'credit_remind_overdue_every'  => (string)max(1, min(30, (int)($_POST['credit_remind_overdue_every'] ?? 7))),
        'credit_remind_overdue_max'    => (string)max(0, min(12, (int)($_POST['credit_remind_overdue_max'] ?? 4))),
        'credit_receipt_email'         => !empty($_POST['credit_receipt_email']) ? '1' : '0',
        'credit_payment_instructions'  => mb_substr(trim((string)($_POST['credit_payment_instructions'] ?? '')), 0, 1000),
        'credit_remind_cc'             => $cc,
    ];

    try {
        $st = $db->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?,?)
                            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
        foreach ($vals as $k => $v) $st->execute([$k, $v]);
        logActivity('update', 'settings', 0, 'Credit reminder settings changed.');
        setFlash('success', 'Reminder settings saved.');
    } catch (\Throwable $e) {
        setFlash('danger', 'Those could not be saved: ' . $e->getMessage());
    }
    redirect(BASE_URL . '/modules/finance/settings.php');
}

$cfg = creditReminderConfig();

// What the settings mean in practice, counted from the live book.
$reachable = (int)(finRowsSafe($db, "SELECT COUNT(*) AS n FROM credit_agreements a
                                LEFT JOIN crm_leads l ON l.id = a.lead_id
                                LEFT JOIN clients cl ON cl.id = COALESCE(a.client_id, l.client_id)
                                    WHERE a.status IN ('active','defaulted') AND a.reminders_enabled = 1
                                      AND COALESCE(NULLIF(cl.email,''), l.email) LIKE '%@%'")[0]['n'] ?? 0);
$muted     = (int)(finRowsSafe($db, "SELECT COUNT(*) AS n FROM credit_agreements
                                    WHERE status IN ('active','defaulted') AND reminders_enabled = 0")[0]['n'] ?? 0);
$noEmail   = (int)(finRowsSafe($db, "SELECT COUNT(*) AS n FROM credit_agreements a
                                LEFT JOIN crm_leads l ON l.id = a.lead_id
                                LEFT JOIN clients cl ON cl.id = COALESCE(a.client_id, l.client_id)
                                    WHERE a.status IN ('active','defaulted')
                                      AND COALESCE(NULLIF(cl.email,''), l.email) NOT LIKE '%@%'")[0]['n'] ?? 0);

$smtpOk    = trim((string)getSetting('smtp_host', '')) !== ''
          && trim((string)getSetting('smtp_from_email', '')) !== '';
$lastSweep = (int)getSetting('credit_last_sweep', '0');

$pageTitle = 'Reminder settings';
include __DIR__ . '/../../includes/header.php';
?>
<?php include __DIR__ . '/_style.php'; ?>

<div class="fin">

    <div class="fin-filters">
        <div>
            <h5 class="mb-1" style="color:var(--fin-ink)">
                <i class="fa fa-bell me-2" style="color:var(--fin-in)"></i>Payment reminders
            </h5>
            <div class="fin-asat">When a buyer on credit hears from us</div>
        </div>
        <a class="btn btn-outline-secondary btn-sm" href="<?= BASE_URL ?>/modules/finance/receivables.php">
            <i class="fa fa-arrow-left me-1"></i>The book
        </a>
    </div>

    <?php if (!$smtpOk): ?>
    <div class="fin-note critical mb-3">
        <i class="fa fa-circle-exclamation"></i>
        <div>
            <strong>Email is not set up.</strong> No reminder or receipt can go out until the
            company's SMTP server is configured.
            <?php if (hasRole('admin')): ?>
            <a href="<?= BASE_URL ?>/modules/settings/index.php?tab=email">Set it up</a>.
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <div class="fin-grid2">
        <div class="fin-card">
            <header><h2>When reminders go out</h2></header>
            <div class="fin-body">
                <form method="post">
                    <?= csrfField() ?>

                    <div class="form-check form-switch mb-4">
                        <input class="form-check-input" type="checkbox" name="credit_remind_enabled"
                               id="en" <?= $cfg['enabled'] ? 'checked' : '' ?>>
                        <label class="form-check-label" for="en">
                            <strong>Send reminders automatically</strong>
                        </label>
                        <div class="form-text">
                            Off, nothing is sent on its own; reminders can still be sent by hand from
                            an account.
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small">Remind this many days before the due date</label>
                        <input type="number" name="credit_remind_days_before" class="form-control"
                               min="1" max="30" value="<?= (int)$cfg['before'] ?>">
                        <div class="form-text">One reminder, that many days ahead.</div>
                    </div>

                    <div class="form-check form-switch mb-4">
                        <input class="form-check-input" type="checkbox" name="credit_remind_on_due"
                               id="od" <?= $cfg['on_due'] ? 'checked' : '' ?>>
                        <label class="form-check-label small" for="od">Also remind on the day itself</label>
                    </div>

                    <div class="row g-2 mb-4">
                        <div class="col-6">
                            <label class="form-label small">Once late, remind every</label>
                            <div class="input-group">
                                <input type="number" name="credit_remind_overdue_every" class="form-control"
                                       min="1" max="30" value="<?= (int)$cfg['every'] ?>">
                                <span class="input-group-text">days</span>
                            </div>
                        </div>
                        <div class="col-6">
                            <label class="form-label small">At most this many times</label>
                            <input type="number" name="credit_remind_overdue_max" class="form-control"
                                   min="0" max="12" value="<?= (int)$cfg['max_overdue'] ?>">
                        </div>
                        <div class="form-text">
                            After that the emails stop and somebody has to pick up the phone. An
                            automated email a month into a missed payment is not collection, it is noise.
                        </div>
                    </div>

                    <div class="form-check form-switch mb-4">
                        <input class="form-check-input" type="checkbox" name="credit_receipt_email"
                               id="rc" <?= $cfg['receipts'] ? 'checked' : '' ?>>
                        <label class="form-check-label small" for="rc">
                            Email a confirmation whenever a payment is recorded
                        </label>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small">How to pay</label>
                        <textarea name="credit_payment_instructions" class="form-control" rows="4"
                                  placeholder="Paybill 123456, account = your registration number&#10;Or bank: …"><?= e($cfg['how_to_pay']) ?></textarea>
                        <div class="form-text">Printed on every reminder. Left blank, the line is left out.</div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small">Copy reminders to</label>
                        <input type="email" name="credit_remind_cc" class="form-control"
                               value="<?= e($cfg['cc']) ?>" placeholder="accounts@yourcompany.co.ke">
                        <div class="form-text">So the team sees exactly what the customer saw. Optional.</div>
                    </div>

                    <button class="btn btn-primary"><i class="fa fa-save me-1"></i>Save</button>
                </form>
            </div>
        </div>

        <div class="fin-stack">
            <div class="fin-card">
                <header><h2>Who this reaches</h2></header>
                <div class="fin-body" style="font-size:13px">
                    <p class="mb-2"><strong><?= $reachable ?></strong> open account<?= $reachable === 1 ? '' : 's' ?>
                        can be reminded — reminders on, and an email address on file.</p>
                    <?php if ($noEmail > 0): ?>
                    <p class="mb-2" style="color:var(--fin-warning)">
                        <i class="fa fa-triangle-exclamation me-1"></i>
                        <strong><?= $noEmail ?></strong> ha<?= $noEmail === 1 ? 's' : 've' ?> no email address,
                        so nothing can reach them. Add one to the client record.
                    </p>
                    <?php endif; ?>
                    <?php if ($muted > 0): ?>
                    <p class="mb-0" style="color:var(--fin-muted)">
                        <i class="fa fa-bell-slash me-1"></i>
                        <?= $muted ?> ha<?= $muted === 1 ? 's' : 've' ?> reminders switched off on the account itself.
                    </p>
                    <?php endif; ?>
                </div>
            </div>

            <div class="fin-card">
                <header><h2>How it runs</h2></header>
                <div class="fin-body" style="font-size:13px;color:var(--fin-ink-2)">
                    <p>
                        Reminders are sent by a sweep that rides on the notification poll every
                        signed-in browser already makes, so nothing needs a cron job — but it does
                        mean somebody has to be signed in. A scheduled call to
                        <code>cron_reminders.php</code> removes that dependency and is worth setting
                        up if the yard is ever empty on a due date.
                    </p>
                    <p class="mb-0">
                        Last sweep:
                        <?= $lastSweep > 0
                            ? e(date('j M Y, H:i', $lastSweep))
                            : 'never — no sweep has run on this install yet' ?>.
                    </p>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>

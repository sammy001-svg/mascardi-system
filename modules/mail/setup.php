<?php
/**
 * Connecting your own mailbox.
 *
 * The only screen in the module that takes a password, and the only one that
 * writes to mail_accounts. It works on the signed-in person's row and no
 * other — there is no id in the form and none is read.
 */

require_once __DIR__ . '/_page.php';
requireLogin();

use Mascardi\Mail\Mailbox;

$me  = authUser();
$uid = (int)$me['id'];
$db  = getDB();
mailMigrate($db);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'disconnect') {
        Mailbox::disconnect($uid);
        logActivity('delete', 'mail_accounts', $uid, 'Disconnected their own mailbox.');
        setFlash('success', 'Your mailbox is disconnected. The saved password has been deleted from '
                          . 'the system; your mail itself is untouched on the server.');
        redirect(BASE_URL . '/modules/mail/setup.php');
    }

    if ($action === 'connect') {
        if (!mailServerReady()) {
            setFlash('danger', 'The mail server has not been set up yet. An administrator can fill it '
                             . 'in under Settings → Email server.');
            redirect(BASE_URL . '/modules/mail/setup.php');
        }

        $r = Mailbox::connectAccount(
            $uid,
            (string)($_POST['email']        ?? ''),
            (string)($_POST['password']     ?? ''),
            (string)($_POST['display_name'] ?? ''),
            (string)($_POST['signature']    ?? '')
        );

        if (!$r['ok']) {
            setFlash('danger', $r['error']);
            redirect(BASE_URL . '/modules/mail/setup.php');
        }

        logActivity('update', 'mail_accounts', $uid, 'Connected their own mailbox.');
        setFlash('success', 'Your mailbox is connected.');
        redirect(BASE_URL . '/modules/mail/index.php');
    }
}

$account     = Mailbox::accountFor($uid);
$serverReady = mailServerReady();
$pageTitle   = 'Your mailbox';

include __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <h5 class="mb-1"><i class="fa fa-envelope me-2 text-primary"></i>Your mailbox</h5>
        <p class="text-muted small mb-0">
            Read and send your own company email from inside the system.
        </p>
    </div>
    <?php if ($account && $serverReady): ?>
    <a href="<?= BASE_URL ?>/modules/mail/index.php" class="btn btn-primary btn-sm">
        <i class="fa fa-inbox me-1"></i>Open mailbox
    </a>
    <?php endif; ?>
</div>

<?php if (!$serverReady): ?>
<div class="alert alert-warning d-flex align-items-start gap-2">
    <i class="fa fa-triangle-exclamation mt-1"></i>
    <div>
        <strong>The mail server has not been set up yet.</strong>
        Nobody can connect a mailbox until an administrator fills in the company's IMAP and SMTP
        server under <a href="<?= BASE_URL ?>/modules/settings/index.php?tab=mailserver">Settings → Email server</a>.
    </div>
</div>
<?php endif; ?>

<div class="row g-4">
    <div class="col-lg-7">
        <div class="card">
            <div class="card-header fw-semibold">
                <i class="fa fa-plug me-2 text-primary"></i>
                <?= $account ? 'Your mailbox' : 'Connect your mailbox' ?>
            </div>
            <div class="card-body">
                <?php if ($account && !empty($account['last_error'])): ?>
                <div class="alert alert-danger py-2 small">
                    <i class="fa fa-circle-exclamation me-1"></i>
                    Last time we tried: <?= e((string)$account['last_error']) ?>
                </div>
                <?php endif; ?>

                <form method="POST" autocomplete="off">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="connect">

                    <div class="mb-3">
                        <label class="form-label">Email address</label>
                        <input type="email" name="email" class="form-control" required
                               value="<?= e((string)($account['email'] ?? $me['email'] ?? '')) ?>"
                               placeholder="you@yourcompany.co.ke">
                        <div class="form-text">Your company address, exactly as it is on the mail server.</div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Mailbox password</label>
                        <input type="password" name="password" class="form-control"
                               autocomplete="new-password"
                               placeholder="<?= $account ? 'Leave blank to keep the saved password' : '' ?>">
                        <div class="form-text">
                            The password for the mailbox itself, not your password for this system.
                            <?= $account ? 'Leave it blank unless it has changed.' : '' ?>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Name shown on your mail</label>
                        <input type="text" name="display_name" class="form-control" maxlength="120"
                               value="<?= e((string)($account['display_name'] ?? $me['name'] ?? '')) ?>">
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Signature</label>
                        <textarea name="signature" class="form-control" rows="4" maxlength="2000"
                                  placeholder="Jane Doe&#10;Sales, Mascardi&#10;+254 …"><?= e((string)($account['signature'] ?? '')) ?></textarea>
                        <div class="form-text">Added to the bottom of messages you send from here.</div>
                    </div>

                    <button class="btn btn-primary" <?= $serverReady ? '' : 'disabled' ?>>
                        <i class="fa fa-check me-1"></i><?= $account ? 'Save' : 'Connect' ?>
                    </button>
                    <span class="text-muted small ms-2">
                        We sign in to the mail server once to check it works before saving anything.
                    </span>
                </form>
            </div>

            <?php if ($account): ?>
            <div class="card-footer bg-white d-flex justify-content-between align-items-center flex-wrap gap-2">
                <span class="text-muted small">
                    Connected<?= !empty($account['last_ok_at'])
                        ? ' · last worked ' . e(fmtDate((string)$account['last_ok_at'], 'j M Y H:i')) : '' ?>
                </span>
                <form method="POST" onsubmit="return confirm('Disconnect this mailbox? Your mail stays on the server; only the saved password is removed from this system.');">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="disconnect">
                    <button class="btn btn-outline-danger btn-sm">
                        <i class="fa fa-link-slash me-1"></i>Disconnect
                    </button>
                </form>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="col-lg-5">
        <div class="card">
            <div class="card-header fw-semibold">
                <i class="fa fa-shield-halved me-2 text-primary"></i>Who can read this
            </div>
            <div class="card-body" style="font-size:13px">
                <p class="text-muted mb-2">
                    Only you. There is no screen anywhere in this system that opens somebody else's
                    mailbox — not for an administrator either. Every page here is built from your own
                    sign-in and takes no account to open as.
                </p>
                <p class="text-muted mb-2">
                    An administrator who genuinely needs a colleague's mail can reset that mailbox's
                    password in cPanel, which the colleague will notice. There is deliberately no
                    quiet way in.
                </p>
                <div class="alert alert-secondary py-2 small mb-0">
                    <i class="fa fa-key me-1"></i>
                    The password is stored encrypted rather than hashed, because the mail server is
                    handed it on every request — that is simply how IMAP works. The key is kept in a
                    file outside the database.
                </div>
            </div>
        </div>

        <div class="card mt-3">
            <div class="card-header fw-semibold">
                <i class="fa fa-circle-info me-2 text-primary"></i>Where your mail lives
            </div>
            <div class="card-body" style="font-size:13px">
                <p class="text-muted mb-0">
                    Nowhere here. Messages stay on the mail server, and this reads and writes them
                    there. Something you read here is read on your phone too, and something you
                    delete here is gone there. Nothing is copied into this system's database.
                </p>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>

<?php
/**
 * Manage your connected mailboxes.
 *
 * Lists all accounts (up to MAIL_MAX_ACCOUNTS), lets you add a new one,
 * edit/update an existing one, set one as the default, or disconnect any.
 *
 * The signed-in person's own accounts only — no user id parameter is accepted
 * from the request; ownership is always proved against the session uid.
 */

require_once __DIR__ . '/_page.php';
requireLogin();

use Mascardi\Mail\Mailbox;

$me  = authUser();
$uid = (int)$me['id'];
$db  = getDB();
mailMigrate($db);

// ── POST actions ─────────────────────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'disconnect') {
        $aid = (int)($_POST['account_id'] ?? 0);
        Mailbox::disconnect($uid, $aid);
        logActivity('delete', 'mail_accounts', $aid, 'Disconnected an email account.');
        // Clear session if it was the active account.
        if (isset($_SESSION['mail_account_id']) && (int)$_SESSION['mail_account_id'] === $aid) {
            unset($_SESSION['mail_account_id']);
        }
        setFlash('success', 'Mailbox disconnected. Your mail stays on the server; '
                          . 'only the saved password was removed from this system.');
        redirect(BASE_URL . '/modules/mail/setup.php');
    }

    if ($action === 'set_default') {
        $aid = (int)($_POST['account_id'] ?? 0);
        Mailbox::setDefault($uid, $aid);
        $_SESSION['mail_account_id'] = $aid;
        setFlash('success', 'Default account updated.');
        redirect(BASE_URL . '/modules/mail/setup.php');
    }

    if ($action === 'connect') {
        if (!mailServerReady()) {
            setFlash('danger', 'The mail server has not been set up yet. An administrator can fill it '
                             . 'in under Settings → Email server.');
            redirect(BASE_URL . '/modules/mail/setup.php');
        }

        $accountId = !empty($_POST['account_id']) ? (int)$_POST['account_id'] : null;

        $r = Mailbox::connectAccount(
            $uid,
            (string)($_POST['email']         ?? ''),
            (string)($_POST['password']      ?? ''),
            (string)($_POST['display_name']  ?? ''),
            (string)($_POST['signature']     ?? ''),
            (string)($_POST['account_label'] ?? ''),
            $accountId
        );

        if (!$r['ok']) {
            setFlash('danger', $r['error']);
            $redir = BASE_URL . '/modules/mail/setup.php';
            if ($accountId) $redir .= '?edit=' . $accountId;
            redirect($redir);
        }

        logActivity('update', 'mail_accounts', $accountId, $accountId ? 'Updated an email account.' : 'Connected a new email account.');

        if (!$accountId) {
            setFlash('success', 'Mailbox connected.');
            // If this is the only account now, make it the active one.
            $accounts = Mailbox::accountsFor($uid);
            if (count($accounts) === 1) {
                $_SESSION['mail_account_id'] = (int)$accounts[0]['id'];
                redirect(BASE_URL . '/modules/mail/index.php');
            }
        } else {
            setFlash('success', 'Mailbox updated.');
        }

        redirect(BASE_URL . '/modules/mail/setup.php');
    }
}

// ── Page data ─────────────────────────────────────────────────────────────────

$accounts    = Mailbox::accountsFor($uid);
$serverReady = mailServerReady();
$pageTitle   = 'Mailboxes';

// Are we editing an existing account?
$editId      = (int)($_GET['edit'] ?? 0);
$editAccount = null;
if ($editId) {
    foreach ($accounts as $a) {
        if ((int)$a['id'] === $editId) { $editAccount = $a; break; }
    }
    // If not found for this user, ignore the param.
    if (!$editAccount) $editId = 0;
}

$canAdd = count($accounts) < MAIL_MAX_ACCOUNTS;

include __DIR__ . '/../../includes/header.php';
?>

<style>
.ma-card{border-left:4px solid var(--border,#e2e8f0);transition:border-color .15s}
.ma-card.default-account{border-left-color:#0f6b5c}
.ma-avatar{width:40px;height:40px;border-radius:50%;background:linear-gradient(135deg,#0f6b5c,#1a9b82);
    display:flex;align-items:center;justify-content:center;color:#fff;font-weight:700;font-size:14px;flex-shrink:0}
</style>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <h5 class="mb-1"><i class="fa fa-envelope me-2 text-primary"></i>Mailboxes</h5>
        <p class="text-muted small mb-0">
            Read and send your company email from inside the system.
            <?php if ($accounts): ?>
            <a href="<?= BASE_URL ?>/modules/mail/index.php" class="ms-2">Open mail →</a>
            <?php endif; ?>
        </p>
    </div>
    <?php if ($canAdd && !$editId): ?>
    <a href="#addForm" class="btn btn-primary btn-sm">
        <i class="fa fa-plus me-1"></i>Add mailbox
    </a>
    <?php endif; ?>
</div>

<?php if (!$serverReady): ?>
<div class="alert alert-warning d-flex align-items-start gap-2 mb-4">
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

        <?php if (!$accounts && !$editId): ?>
        <!-- Empty state -->
        <div class="card text-center py-5 px-4">
            <div class="mb-3"><i class="fa fa-inbox fa-3x text-muted opacity-50"></i></div>
            <h6 class="fw-semibold">No mailboxes connected yet</h6>
            <p class="text-muted small mb-0">Connect your company email address below to read and send from inside the system.</p>
        </div>
        <?php else: ?>

        <!-- Existing accounts list -->
        <?php foreach ($accounts as $acc):
            $isDefault = (int)$acc['is_default'] === 1;
            $isEditing = $editId && (int)$acc['id'] === $editId;
            $initials  = strtoupper(substr($acc['email'], 0, 1));
        ?>
        <div class="card mb-3 ma-card <?= $isDefault ? 'default-account' : '' ?>" id="acc-<?= (int)$acc['id'] ?>">
            <div class="card-body">
                <div class="d-flex align-items-start gap-3">
                    <div class="ma-avatar"><?= e($initials) ?></div>
                    <div class="flex-grow-1 min-w-0">
                        <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
                            <div>
                                <div class="fw-semibold">
                                    <?= e(trim($acc['account_label'] ?? '') ?: $acc['email']) ?>
                                    <?php if ($isDefault): ?>
                                    <span class="badge bg-success ms-1" style="font-size:10px">Default</span>
                                    <?php endif; ?>
                                </div>
                                <?php if (trim($acc['account_label'] ?? '') !== ''): ?>
                                <div class="small text-muted"><?= e($acc['email']) ?></div>
                                <?php endif; ?>
                                <?php if ($acc['display_name']): ?>
                                <div class="small text-muted">Sending as: <?= e($acc['display_name']) ?></div>
                                <?php endif; ?>
                                <div class="small text-muted mt-1">
                                    <?php if ($acc['last_ok_at']): ?>
                                    <i class="fa fa-circle-check text-success me-1"></i>
                                    Last connected <?= e(fmtDate($acc['last_ok_at'], 'j M Y H:i')) ?>
                                    <?php endif; ?>
                                    <?php if ($acc['last_error']): ?>
                                    <span class="text-danger"><i class="fa fa-circle-exclamation me-1"></i><?= e($acc['last_error']) ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="d-flex gap-1 flex-wrap">
                                <a href="<?= BASE_URL ?>/modules/mail/index.php" class="btn btn-outline-primary btn-sm"
                                   onclick="<?php if ((int)($acc['id']) !== (int)($_SESSION['mail_account_id'] ?? 0)): ?>this.href='<?= BASE_URL ?>/modules/mail/setup.php?switch=<?= (int)$acc['id'] ?>'<?php endif; ?>">
                                    <i class="fa fa-inbox me-1"></i>Open
                                </a>
                                <a href="?edit=<?= (int)$acc['id'] ?>#editForm" class="btn btn-outline-secondary btn-sm">
                                    <i class="fa fa-pen"></i>
                                </a>
                                <?php if (!$isDefault && count($accounts) > 1): ?>
                                <form method="POST">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="action" value="set_default">
                                    <input type="hidden" name="account_id" value="<?= (int)$acc['id'] ?>">
                                    <button class="btn btn-outline-secondary btn-sm" title="Make default">
                                        <i class="fa fa-star"></i>
                                    </button>
                                </form>
                                <?php endif; ?>
                                <form method="POST" onsubmit="return confirm('Disconnect <?= e(addslashes($acc['email'])) ?>? Your mail stays on the server; only the saved password is removed.');">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="action" value="disconnect">
                                    <input type="hidden" name="account_id" value="<?= (int)$acc['id'] ?>">
                                    <button class="btn btn-outline-danger btn-sm" title="Disconnect">
                                        <i class="fa fa-link-slash"></i>
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>

                <?php if ($isEditing): ?>
                <!-- Inline edit form for this account -->
                <hr class="mt-3">
                <div id="editForm">
                    <h6 class="fw-semibold mb-3"><i class="fa fa-pen me-2 text-primary"></i>Update this mailbox</h6>
                    <?php if ($acc['last_error']): ?>
                    <div class="alert alert-danger py-2 small">
                        <i class="fa fa-circle-exclamation me-1"></i>
                        Last error: <?= e($acc['last_error']) ?>
                    </div>
                    <?php endif; ?>
                    <?= include_form($acc, $serverReady) ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>

        <?php if ($canAdd && !$editId): ?>
        <!-- Add new account form -->
        <div class="card" id="addForm">
            <div class="card-header fw-semibold">
                <i class="fa fa-plus me-2 text-primary"></i>
                <?= $accounts ? 'Add another mailbox' : 'Connect your mailbox' ?>
            </div>
            <div class="card-body">
                <?= include_form(null, $serverReady) ?>
            </div>
        </div>
        <?php elseif (!$canAdd && !$editId): ?>
        <div class="alert alert-secondary small">
            <i class="fa fa-circle-info me-1"></i>
            You have reached the limit of <?= MAIL_MAX_ACCOUNTS ?> connected mailboxes. Disconnect one to add another.
        </div>
        <?php endif; ?>

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
                    Passwords are stored encrypted rather than hashed, because the mail server is
                    handed them on every request — that is simply how IMAP works. The key is kept in a
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

        <?php if (count($accounts) > 1): ?>
        <div class="card mt-3">
            <div class="card-header fw-semibold">
                <i class="fa fa-envelope-open me-2 text-primary"></i>Multiple mailboxes
            </div>
            <div class="card-body" style="font-size:13px">
                <p class="text-muted mb-2">
                    You have <?= count($accounts) ?> mailbox<?= count($accounts) !== 1 ? 'es' : '' ?> connected.
                    You can switch between them from the mail inbox page.
                </p>
                <p class="text-muted mb-0">
                    The <strong>default</strong> mailbox is used when you first open your mail.
                    Star another to make it the default.
                </p>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php

// Handle ?switch= param — a small endpoint to change the active account and redirect.
// This is GET-based but validated; the session stores the result, not the param.
if (isset($_GET['switch'])) {
    $switchId = (int)$_GET['switch'];
    mailSwitchAccount($switchId);
    redirect(BASE_URL . '/modules/mail/index.php');
}

/**
 * Render the connect/update form as a string.
 * Returns an HTML string; do NOT echo inside the function.
 */
function include_form(?array $account, bool $serverReady): string
{
    global $me;
    $isEdit = $account !== null;
    $aid    = $isEdit ? (int)$account['id'] : 0;
    ob_start();
    ?>
    <form method="POST" autocomplete="off">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="connect">
        <?php if ($isEdit): ?>
        <input type="hidden" name="account_id" value="<?= $aid ?>">
        <?php endif; ?>

        <div class="row g-3">
            <div class="col-md-6">
                <label class="form-label">Email address</label>
                <input type="email" name="email" class="form-control" required
                       value="<?= e((string)($account['email'] ?? $me['email'] ?? '')) ?>"
                       placeholder="you@yourcompany.co.ke">
                <div class="form-text">Your company address, exactly as it is on the mail server.</div>
            </div>
            <div class="col-md-6">
                <label class="form-label">Account label <span class="text-muted fw-normal">(optional)</span></label>
                <input type="text" name="account_label" class="form-control" maxlength="80"
                       value="<?= e((string)($account['account_label'] ?? '')) ?>"
                       placeholder="e.g. Work, Sales, Support">
                <div class="form-text">Shown in the account switcher.</div>
            </div>
        </div>

        <div class="mb-3 mt-3">
            <label class="form-label">Mailbox password</label>
            <input type="password" name="password" class="form-control"
                   autocomplete="new-password"
                   placeholder="<?= $isEdit ? 'Leave blank to keep the saved password' : '' ?>">
            <div class="form-text">
                The password for the mailbox itself, not your password for this system.
                <?= $isEdit ? 'Leave it blank unless it has changed.' : '' ?>
            </div>
        </div>

        <div class="row g-3 mb-3">
            <div class="col-md-6">
                <label class="form-label">Name shown on your mail</label>
                <input type="text" name="display_name" class="form-control" maxlength="120"
                       value="<?= e((string)($account['display_name'] ?? $me['name'] ?? '')) ?>">
            </div>
        </div>

        <div class="mb-3">
            <label class="form-label">Signature</label>
            <textarea name="signature" class="form-control" rows="4" maxlength="2000"
                      placeholder="Jane Doe&#10;Sales, Mascardi&#10;+254 …"><?= e((string)($account['signature'] ?? '')) ?></textarea>
            <div class="form-text">Added to the bottom of messages you send from here.</div>
        </div>

        <div class="d-flex align-items-center gap-2 flex-wrap">
            <button class="btn btn-primary" <?= $serverReady ? '' : 'disabled' ?>>
                <i class="fa fa-check me-1"></i><?= $isEdit ? 'Save changes' : 'Connect mailbox' ?>
            </button>
            <?php if ($isEdit): ?>
            <a href="<?= BASE_URL ?>/modules/mail/setup.php" class="btn btn-outline-secondary">Cancel</a>
            <?php endif; ?>
            <span class="text-muted small">
                We sign in to the mail server once to check it works before saving anything.
            </span>
        </div>
    </form>
    <?php
    return (string)ob_get_clean();
}

include __DIR__ . '/../../includes/footer.php';
?>

<?php
/**
 * The mailbox: folders, the list, and the message being read.
 *
 * One screen rather than three, because that is how mail is read — pick a
 * folder, run an eye down the list, open one, act on it, and stay where you
 * were. Everything is a link or a form post, so it works with the back button
 * and on a phone.
 *
 * Bulk actions post to action.php. The reading pane is an iframe onto body.php,
 * which is sandboxed and carries a policy of its own: a message is a stranger's
 * HTML and gets none of this page's origin.
 */

require_once __DIR__ . '/_page.php';
requireLogin();

use Mascardi\Mail\AuthFailed;
use Mascardi\Mail\HtmlSanitizer;
use Mascardi\Mail\Mailbox;

const MAIL_PER_PAGE = 30;

$box    = mailBox();
$folder = mailFolderParam();
$search = trim((string)($_GET['q'] ?? ''));
$page   = max(1, (int)($_GET['page'] ?? 1));
$uid    = (int)($_GET['uid'] ?? 0);

$failed = null;

try {
    $folders = $box->folders();
    $list    = $box->messages($folder, $page, MAIL_PER_PAGE, $search);
    $message = $uid > 0 ? $box->message($folder, $uid) : null;
    $box->noteResult(null);
} catch (AuthFailed) {
    mailServerRefused();
} catch (\Throwable $e) {
    $box->noteResult($e->getMessage());
    $failed  = $e->getMessage();
    $folders = [];
    $list    = ['total' => 0, 'messages' => []];
    $message = null;
}

// Opening a message changed its unread state after the list and the folder
// counts were read. Reflect that here rather than making another round trip.
if ($message) {
    foreach ($list['messages'] as &$m) {
        if ($m['uid'] === $uid && !$m['seen']) {
            $m['seen'] = true;
            foreach ($folders as &$f) {
                if ($f['name'] === $folder && $f['unseen'] > 0) $f['unseen']--;
            }
            unset($f);
        }
    }
    unset($m);
}

$box->close();

$pages     = max(1, (int)ceil($list['total'] / MAIL_PER_PAGE));
$pageTitle = 'Mail';

// Load all accounts for the switcher — after box->close() so we reuse the DB.
$allAccounts = Mailbox::accountsFor((int)authUser()['id']);
$activeAccId = (int)($_SESSION['mail_account_id'] ?? $box->id());

include __DIR__ . '/../../includes/header.php';
?>

<style>
/* ── Layout ── */
.mb-wrap{display:grid;grid-template-columns:200px minmax(0,1fr);gap:16px;align-items:start}
@media (max-width:991px){.mb-wrap{grid-template-columns:1fr}}

/* When a message is open the right column becomes its own split:
   list on the left (~360px), message on the right. */
.mb-content{display:flex;flex-direction:column;gap:12px;min-width:0}
.mb-split{display:grid;grid-template-columns:minmax(260px,360px) minmax(0,1fr);gap:12px;align-items:start}
@media (max-width:900px){.mb-split{grid-template-columns:1fr}}

/* ── Folder sidebar ── */
.mb-fold a{
    display:flex;justify-content:space-between;align-items:center;gap:8px;
    padding:7px 11px;border-radius:8px;font-size:13.5px;
    color:var(--text,#334155);text-decoration:none;transition:background .13s}
.mb-fold a:hover{background:var(--surface-alt,#f1f5f9)}
.mb-fold a.on{background:#0f6b5c;color:#fff;font-weight:600}

/* ── Message rows ── */
.mb-row{
    display:grid;grid-template-columns:28px minmax(0,1fr) auto;
    gap:10px;align-items:center;padding:9px 12px;
    border-bottom:1px solid var(--border,#e2e8f0);font-size:13.5px;
    transition:background .1s;cursor:pointer}
.mb-row:hover{background:var(--surface-alt,#f8fafc)}
/* Unread — a subtle tinted bg that works in both modes */
.mb-row.unseen{background:color-mix(in srgb,var(--surface,#fff) 85%,#0f6b5c 15%)}
[data-theme="dark"] .mb-row.unseen{background:color-mix(in srgb,var(--surface,#1e293b) 80%,#0f6b5c 20%)}
.mb-row.unseen .mb-sub,.mb-row.unseen .mb-from{font-weight:700}
/* Active/open row gets a green left accent */
.mb-row.open{background:var(--surface-alt,#f8fafc);box-shadow:inset 3px 0 0 #0f6b5c}
[data-theme="dark"] .mb-row.open{background:color-mix(in srgb,var(--surface,#1e293b) 75%,#0f6b5c 25%)}

/* ── Text colours — always follow the theme ── */
.mb-from{color:var(--text,#0f172a)}
.mb-sub {color:var(--text,#0f172a)}
.mb-pre {color:var(--text-3,#94a3b8)}
.mb-when{color:var(--text-3,#94a3b8);font-size:12px;white-space:nowrap}
.mb-trunc{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}

/* ── Message iframe ── */
.mb-body{
    width:100%;height:60vh;min-height:360px;
    border:none;border-radius:0 0 var(--r-xl,14px) var(--r-xl,14px);
    background:transparent;
    display:block}

/* ── Attachment pills ── */
.mb-file{
    display:inline-flex;align-items:center;gap:6px;
    border:1px solid var(--border,#e2e8f0);border-radius:8px;
    padding:5px 10px;font-size:12.5px;text-decoration:none;
    margin:0 6px 6px 0;color:var(--text,#0f172a);
    background:var(--surface-alt,#f8fafc);transition:background .13s}
.mb-file:hover{background:var(--border,#e2e8f0)}

/* ── Pagination footer — inherit theme ── */
.mb-pager{
    background:var(--surface,#fff);
    border-top:1px solid var(--border,#e2e8f0);
    border-radius:0 0 var(--r-xl,14px) var(--r-xl,14px);
    display:flex;justify-content:space-between;align-items:center;
    padding:10px 16px}

/* ── Account switcher pill ── */
.mb-acct-badge{
    display:inline-flex;align-items:center;gap:6px;padding:3px 10px 3px 6px;
    background:var(--surface-alt,#f1f5f9);border-radius:20px;font-size:12.5px;
    cursor:pointer;border:1px solid var(--border,#e2e8f0);transition:background .15s}
.mb-acct-badge:hover{background:var(--border,#e2e8f0)}
.mb-acct-dot{width:8px;height:8px;border-radius:50%;background:#0f6b5c;flex-shrink:0}
</style>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div>
        <h5 class="mb-1"><i class="fa fa-envelope me-2 text-primary"></i>Mail</h5>
        <div class="d-flex align-items-center gap-2 flex-wrap">
            <?php if (count($allAccounts) > 1): ?>
            <!-- Account switcher dropdown -->
            <div class="dropdown">
                <button class="mb-acct-badge dropdown-toggle border-0 bg-transparent p-0" style="font-size:12.5px;background:var(--surface-alt,#f1f5f9)!important;padding:3px 10px!important;border-radius:20px!important;border:1px solid var(--border,#e2e8f0)!important"
                        type="button" data-bs-toggle="dropdown" id="acctSwitcher">
                    <span class="mb-acct-dot"></span>
                    <?= e($box->label()) ?>
                    <?php if ($box->isDefault()): ?><span class="text-muted">(default)</span><?php endif; ?>
                </button>
                <ul class="dropdown-menu dropdown-menu-start" style="min-width:220px">
                    <?php foreach ($allAccounts as $acct): ?>
                    <?php $isActive = (int)$acct['id'] === $activeAccId; ?>
                    <li>
                        <a class="dropdown-item d-flex align-items-center gap-2 <?= $isActive ? 'active' : '' ?>"
                           href="<?= BASE_URL ?>/modules/mail/setup.php?switch=<?= (int)$acct['id'] ?>">
                            <i class="fa <?= $isActive ? 'fa-circle-check text-success' : 'fa-circle' ?> fa-sm"></i>
                            <div class="min-w-0">
                                <div class="mb-trunc" style="max-width:160px"><?= e(trim($acct['account_label'] ?? '') ?: $acct['email']) ?></div>
                                <?php if (trim($acct['account_label'] ?? '') !== ''): ?>
                                <div class="text-muted" style="font-size:11px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:160px"><?= e($acct['email']) ?></div>
                                <?php endif; ?>
                            </div>
                            <?php if ((int)$acct['is_default']): ?>
                            <span class="badge bg-light text-dark ms-auto" style="font-size:10px">default</span>
                            <?php endif; ?>
                        </a>
                    </li>
                    <?php endforeach; ?>
                    <li><hr class="dropdown-divider"></li>
                    <li><a class="dropdown-item small" href="<?= BASE_URL ?>/modules/mail/setup.php"><i class="fa fa-gear me-2"></i>Manage mailboxes</a></li>
                </ul>
            </div>
            <?php else: ?>
            <span class="text-muted small"><?= e($box->email()) ?></span>
            <?php endif; ?>
        </div>
    </div>
    <div class="d-flex gap-2">
        <a href="<?= BASE_URL ?>/modules/mail/setup.php" class="btn btn-outline-secondary btn-sm">
            <i class="fa fa-gear me-1"></i>Mailboxes
        </a>
        <a href="<?= BASE_URL ?>/modules/mail/compose.php" class="btn btn-primary btn-sm">
            <i class="fa fa-pen me-1"></i>New message
        </a>
    </div>
</div>

<?php if ($failed !== null): ?>
<div class="alert alert-danger d-flex align-items-start gap-2">
    <i class="fa fa-circle-exclamation mt-1"></i>
    <div>
        <strong>The mail server could not be reached.</strong><br>
        <span class="small"><?= e($failed) ?></span>
    </div>
</div>
<?php endif; ?<div class="mb-wrap">

    <!-- Folder sidebar -->
    <div class="mb-fold">
        <?php foreach ($folders as $fo): ?>
        <a class="<?= $fo['name'] === $folder ? 'on' : '' ?>"
           href="<?= e(mailUrl(['folder' => $fo['name']])) ?>">
            <span class="mb-trunc"><?= e($fo['label']) ?></span>
            <?php if (!empty($fo['unseen'])): ?>
            <span class="badge <?= $fo['name'] === $folder ? 'bg-light text-dark' : 'bg-primary' ?>"><?= (int)$fo['unseen'] ?></span>
            <?php endif; ?>
        </a>
        <?php endforeach; ?>
    </div>

    <!-- Right column: search + list (+ message beside list when open) -->
    <div class="mb-content">

        <!-- Search -->
        <form method="get" class="d-flex gap-2">
            <input type="hidden" name="folder" value="<?= e($folder) ?>">
            <input type="search" name="q" class="form-control form-control-sm" value="<?= e($search) ?>"
                   placeholder="Search this folder — sender, subject or words in the message">
            <button class="btn btn-outline-secondary btn-sm"><i class="fa fa-magnifying-glass"></i></button>
            <?php if ($search !== ''): ?>
            <a class="btn btn-outline-secondary btn-sm" href="<?= e(mailUrl(['folder' => $folder])) ?>">Clear</a>
            <?php endif; ?>
        </form>

        <!-- Split: list on left, message on right (only when a message is open) -->
        <?php if ($message): ?>
        <div class="mb-split">
        <?php endif; ?>

            <!-- The list -->
            <div>
            <div class="card" id="mbList">
                <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2 py-2">
                    <span class="small text-muted">
                        <?= $list['total'] ?> message<?= $list['total'] === 1 ? '' : 's' ?>
                        <?= $search !== '' ? 'matching &ldquo;' . e($search) . '&rdquo;' : 'in ' . e($folder) ?>
                    </span>
                    <div class="d-flex gap-1" id="mbBulkBar" hidden>
                        <button class="btn btn-outline-secondary btn-sm" form="mbBulk" name="do" value="read">Mark read</button>
                        <button class="btn btn-outline-secondary btn-sm" form="mbBulk" name="do" value="unread">Mark unread</button>
                        <button class="btn btn-outline-secondary btn-sm" form="mbBulk" name="do" value="flag">Flag</button>
                        <button class="btn btn-outline-danger btn-sm" form="mbBulk" name="do" value="delete">Delete</button>
                    </div>
                </div>

                <form method="post" id="mbBulk" action="<?= BASE_URL ?>/modules/mail/action.php">
                    <?= csrfField() ?>
                    <input type="hidden" name="folder" value="<?= e($folder) ?>">
                </form>

                <?php if (!$list['messages']): ?>
                    <div class="card-body text-muted small">
                        <?= $search !== '' ? 'Nothing in this folder matches that.' : 'This folder is empty.' ?>
                    </div>
                <?php else: ?>
                    <?php foreach ($list['messages'] as $row):
                        $isOpen = $row['uid'] === $uid;
                        $who    = $folder === 'INBOX' || ($row['from']['email'] ?? '') !== $box->email()
                                    ? mailWho($row['from'])
                                    : 'To: ' . implode(', ', array_map('mailWho', $row['to'] ?: []));
                    ?>
                    <div class="mb-row <?= $row['seen'] ? '' : 'unseen' ?> <?= $isOpen ? 'open' : '' ?>">
                        <input type="checkbox" class="form-check-input mb-pick" form="mbBulk"
                               name="uids[]" value="<?= (int)$row['uid'] ?>">
                        <a class="text-decoration-none" style="min-width:0"
                           href="<?= e(mailUrl(['folder' => $folder, 'q' => $search, 'page' => $page, 'uid' => $row['uid']])) ?>">
                            <div class="mb-from mb-trunc"><?= e($who ?: '(unknown sender)') ?></div>
                            <div class="mb-sub mb-trunc">
                                <?php if ($row['flagged']): ?><i class="fa fa-flag text-danger me-1"></i><?php endif; ?>
                                <?php if ($row['answered']): ?><i class="fa fa-reply text-muted me-1"></i><?php endif; ?>
                                <?= e($row['subject']) ?>
                            </div>
                        </a>
                        <div class="text-end">
                            <?php if ($row['has_attachments']): ?>
                            <i class="fa fa-paperclip text-muted me-1" title="Has attachments"></i>
                            <?php endif; ?>
                            <span class="mb-when"><?= e(mailWhen($row['date'])) ?></span>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>

                <?php if ($pages > 1): ?>
                <div class="mb-pager">
                    <a class="btn btn-outline-secondary btn-sm <?= $page <= 1 ? 'disabled' : '' ?>"
                       href="<?= e(mailUrl(['folder' => $folder, 'q' => $search, 'page' => $page - 1])) ?>">Newer</a>
                    <span class="small text-muted">Page <?= $page ?> of <?= $pages ?></span>
                    <a class="btn btn-outline-secondary btn-sm <?= $page >= $pages ? 'disabled' : '' ?>"
                       href="<?= e(mailUrl(['folder' => $folder, 'q' => $search, 'page' => $page + 1])) ?>">Older</a>
                </div>
                <?php endif; ?>
            </div>
            </div><!-- /list wrapper -->

            <!-- The message (only when open — appears in right pane of split) -->
            <?php if ($message):
                $base    = BASE_URL . '/modules/mail/';
                $qs      = 'folder=' . rawurlencode($folder) . '&uid=' . (int)$message['uid'];
                $held    = $message['html'] !== '' ? HtmlSanitizer::clean($message['html'], false)['blocked'] : 0;
                $files   = array_filter($message['attachments'], static fn ($a) => !$a['inline']);
            ?>
            <div class="card" id="mbMessage">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-start gap-2 flex-wrap">
                        <div style="min-width:0">
                            <h6 class="mb-1"><?= e($message['subject'] !== '' ? $message['subject'] : '(no subject)') ?></h6>
                            <div class="small text-muted">
                                <strong><?= e(mailWho($message['from'])) ?></strong>
                                &lt;<?= e((string)($message['from']['email'] ?? '')) ?>&gt;
                                <?= $message['date'] ? ' · ' . e(date('D, j M Y H:i', $message['date'])) : '' ?>
                            </div>
                            <?php if ($message['to']): ?>
                            <div class="small text-muted">
                                To: <?= e(implode(', ', array_map('mailWho', $message['to']))) ?>
                            </div>
                            <?php endif; ?>
                        </div>
                        <div class="d-flex gap-1 flex-wrap">
                            <a class="btn btn-outline-secondary btn-sm"
                               href="<?= $base ?>compose.php?mode=reply&amp;<?= $qs ?>"><i class="fa fa-reply me-1"></i>Reply</a>
                            <a class="btn btn-outline-secondary btn-sm"
                               href="<?= $base ?>compose.php?mode=all&amp;<?= $qs ?>"><i class="fa fa-reply-all me-1"></i>Reply all</a>
                            <a class="btn btn-outline-secondary btn-sm"
                               href="<?= $base ?>compose.php?mode=forward&amp;<?= $qs ?>"><i class="fa fa-share me-1"></i>Forward</a>
                            <form method="post" action="<?= $base ?>action.php" class="d-flex gap-1">
                                <?= csrfField() ?>
                                <input type="hidden" name="folder" value="<?= e($folder) ?>">
                                <input type="hidden" name="uids[]" value="<?= (int)$message['uid'] ?>">
                                <input type="hidden" name="stay" value="1">
                                <button class="btn btn-outline-secondary btn-sm" name="do" value="unread">Mark unread</button>
                                <button class="btn btn-outline-danger btn-sm" name="do" value="delete">
                                    <i class="fa fa-trash"></i>
                                </button>
                            </form>
                        </div>
                    </div>

                    <?php if ($files): ?>
                    <div class="mt-3">
                        <?php foreach ($message['attachments'] as $i => $a): if ($a['inline']) continue; ?>
                        <a class="mb-file" href="<?= $base ?>attachment.php?<?= $qs ?>&amp;index=<?= (int)$i ?>">
                            <i class="fa fa-paperclip"></i>
                            <span class="mb-trunc" style="max-width:200px"><?= e($a['name']) ?></span>
                            <span class="text-muted"><?= e(mailSize((int)$a['size'])) ?></span>
                        </a>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>

                    <?php if ($held > 0): ?>
                    <div class="alert alert-secondary py-2 small mt-3 mb-0 d-flex justify-content-between align-items-center gap-2">
                        <span>
                            <i class="fa fa-image me-1"></i>
                            <?= $held ?> image<?= $held === 1 ? '' : 's' ?> held back. Loading them tells the
                            sender you opened this.
                        </span>
                        <button class="btn btn-outline-secondary btn-sm" id="mbShowImages">Show images</button>
                    </div>
                    <?php endif; ?>

                    <iframe class="mb-body mt-3" id="mbBody"
                            sandbox="allow-popups allow-popups-to-escape-sandbox"
                            referrerpolicy="no-referrer"
                            src="<?= $base ?>body.php?<?= $qs ?>"
                            data-images-src="<?= $base ?>body.php?<?= $qs ?>&amp;images=1"
                            title="Message"></iframe>
                </div>
            </div>
            <?php endif; ?>

        <?php if ($message): ?>
        </div><!-- /mb-split -->
        <?php endif; ?>

    </div><!-- /mb-content -->
</div><!-- /mb-wrap -->
iv>
</div>

<script>
(function () {
    // Bulk action bar — only show when at least one row is checked.
    var picks = document.querySelectorAll('.mb-pick'),
        bar   = document.getElementById('mbBulkBar');

    function sync() {
        var any = false;
        picks.forEach(function (p) { if (p.checked) any = true; });
        if (bar) bar.hidden = !any;
    }
    picks.forEach(function (p) { p.addEventListener('change', sync); });
    sync();

    // Show images in the iframe.
    var btn = document.getElementById('mbShowImages'),
        fr  = document.getElementById('mbBody');
    if (btn && fr) {
        btn.addEventListener('click', function () {
            fr.src = fr.getAttribute('data-images-src');
            btn.closest('.alert').remove();
        });
    }

    // Auto-size the iframe to its content so there is no inner scroll bar.
    if (fr) {
        fr.addEventListener('load', function () {
            try {
                var h = fr.contentDocument.body.scrollHeight;
                fr.style.height = Math.max(200, h + 32) + 'px';
            } catch (e) { /* cross-origin body.php is same-origin, but guard anyway */ }
        });
    }

    // When the page loads with a message open, scroll the open row into
    // view inside the list — the list is now a fixed-width column, and
    // on desktop the page itself does not scroll past the top, so a short
    // smooth scroll to the message card is still useful on mobile.
    var openRow = document.querySelector('.mb-row.open');
    var msgCard = document.getElementById('mbMessage');
    if (openRow) {
        openRow.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
    }
    if (msgCard && window.innerWidth < 900) {
        // On mobile the split stacks vertically — scroll the message into view.
        setTimeout(function () {
            msgCard.scrollIntoView({ block: 'start', behavior: 'smooth' });
        }, 120);
    }
}());
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>

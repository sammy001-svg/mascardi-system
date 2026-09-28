<?php
/**
 * Writing one — new, a reply, a reply to everybody, or a forward.
 *
 * Compose and send are the same page, because a send that fails has to come
 * back with everything still typed in it. Attachments are the exception: they
 * live in the upload temp files and are gone by the next request, so those
 * have to be chosen again and the message says so plainly.
 */

require_once __DIR__ . '/_page.php';
requireLogin();

$box   = mailBox();
$maxMb = max(1, (int)getSetting('mail_max_attach_mb', '20'));

// ── Sending ──────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $in = [
        'to'          => (string)($_POST['to']          ?? ''),
        'cc'          => (string)($_POST['cc']          ?? ''),
        'bcc'         => (string)($_POST['bcc']         ?? ''),
        'subject'     => (string)($_POST['subject']     ?? ''),
        'text'        => (string)($_POST['text']        ?? ''),
        'in_reply_to' => (string)($_POST['in_reply_to'] ?? ''),
        'references'  => (string)($_POST['references']  ?? ''),
    ];

    $attachments = [];
    $total       = 0;
    $maxBytes    = $maxMb * 1024 * 1024;

    // Files chosen on the form.
    $files = $_FILES['attachments'] ?? null;

    if ($files && is_array($files['name'])) {
        foreach ($files['name'] as $i => $name) {
            if (($files['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) continue;

            if ($files['error'][$i] !== UPLOAD_ERR_OK || !is_uploaded_file($files['tmp_name'][$i])) {
                mailKeepDraft($in, 'The file "' . $name . '" did not upload. It may be larger than '
                                 . 'the server allows.');
            }

            $data   = (string)file_get_contents($files['tmp_name'][$i]);
            $total += strlen($data);
            $mime   = (new finfo(FILEINFO_MIME_TYPE))->buffer($data) ?: 'application/octet-stream';

            $attachments[] = ['name' => basename((string)$name), 'mime' => $mime, 'data' => $data];
        }
    }

    // Attachments carried over when forwarding, fetched fresh from the original
    // rather than trusted from the form — otherwise the form would be saying
    // which bytes to send.
    $fwdUid = (int)($_POST['fwd_uid'] ?? 0);
    $keep   = array_map('intval', (array)($_POST['fwd_keep'] ?? []));

    if ($fwdUid > 0 && $keep) {
        try {
            $orig = $box->message((string)($_POST['fwd_folder'] ?? 'INBOX'), $fwdUid);

            foreach ($orig['attachments'] ?? [] as $i => $a) {
                if (in_array($i, $keep, true) && !$a['inline']) {
                    $attachments[] = ['name' => $a['name'], 'mime' => $a['mime'], 'data' => $a['data']];
                    $total += $a['size'];
                }
            }
        } catch (\Throwable $e) {
            mailKeepDraft($in, 'The original message could not be read to forward its attachments.');
        }
    }

    if ($total > $maxBytes) {
        mailKeepDraft($in, 'Attachments come to ' . round($total / 1048576, 1) . 'MB; the limit is '
                         . $maxMb . 'MB.');
    }

    $in['attachments'] = $attachments;
    $result = $box->send($in);
    $box->close();

    if (!$result['ok']) {
        mailKeepDraft($in, $result['error']);
    }

    if (isset($result['warning'])) {
        setFlash('warning', $result['warning']);
    } else {
        setFlash('success', 'Sent.');
    }

    redirect(mailUrl());
}

// ── Writing ──────────────────────────────────────────────────────────────────
$mode  = (string)($_GET['mode'] ?? '');
$draft = ['to' => (string)($_GET['to'] ?? ''), 'cc' => '', 'bcc' => '', 'subject' => '', 'text' => '',
          'in_reply_to' => '', 'references' => '', 'fwd_uid' => 0, 'fwd_folder' => '', 'fwd_files' => []];

if (in_array($mode, ['reply', 'all', 'forward'], true)) {
    $folder = mailFolderParam();
    $uid    = (int)($_GET['uid'] ?? 0);

    try {
        $orig = $uid > 0 ? $box->message($folder, $uid) : null;
    } catch (\Throwable $e) {
        $orig = null;
    }

    if ($orig) {
        $draft = mailPrefill($draft, $orig, $mode, $box->email(), $folder, $uid);
    }
}

$box->close();

// What was typed before a failed send comes back, rather than being lost.
if (!empty($_SESSION['mail_draft']) && is_array($_SESSION['mail_draft'])) {
    $draft = array_merge($draft, $_SESSION['mail_draft']);
    unset($_SESSION['mail_draft']);
}

$heading = match ($mode) {
    'reply', 'all' => 'Reply',
    'forward'      => 'Forward',
    default        => 'New message',
};

$pageTitle = $heading;
include __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div>
        <h5 class="mb-1"><i class="fa fa-pen me-2 text-primary"></i><?= e($heading) ?></h5>
        <p class="text-muted small mb-0">From <?= e($box->email()) ?></p>
    </div>
    <a href="<?= e(mailUrl()) ?>" class="btn btn-outline-secondary btn-sm">Discard</a>
</div>

<form method="post" enctype="multipart/form-data" class="card">
    <?= csrfField() ?>
    <input type="hidden" name="in_reply_to" value="<?= e((string)$draft['in_reply_to']) ?>">
    <input type="hidden" name="references"  value="<?= e((string)$draft['references']) ?>">
    <input type="hidden" name="fwd_uid"     value="<?= (int)$draft['fwd_uid'] ?>">
    <input type="hidden" name="fwd_folder"  value="<?= e((string)$draft['fwd_folder']) ?>">

    <div class="card-body">
        <div class="mb-3">
            <label class="form-label small">To</label>
            <input type="text" name="to" class="form-control" required
                   value="<?= e((string)$draft['to']) ?>"
                   placeholder="jane@example.com, Bob &lt;bob@example.org&gt;">
            <div class="form-text">Separate several with commas.</div>
        </div>

        <div class="row g-3 mb-3">
            <div class="col-md-6">
                <label class="form-label small">Cc</label>
                <input type="text" name="cc" class="form-control" value="<?= e((string)$draft['cc']) ?>">
            </div>
            <div class="col-md-6">
                <label class="form-label small">Bcc</label>
                <input type="text" name="bcc" class="form-control" value="<?= e((string)$draft['bcc']) ?>">
                <div class="form-text">Nobody else sees these addresses.</div>
            </div>
        </div>

        <div class="mb-3">
            <label class="form-label small">Subject</label>
            <input type="text" name="subject" class="form-control" value="<?= e((string)$draft['subject']) ?>">
        </div>

        <div class="mb-3">
            <label class="form-label small">Message</label>
            <textarea name="text" class="form-control" rows="14"
                      style="font:14px/1.6 ui-monospace,Menlo,Consolas,monospace"><?= e((string)$draft['text']) ?></textarea>
            <?php if ($box->signature() !== ''): ?>
            <div class="form-text">Your signature is added when it sends.</div>
            <?php endif; ?>
        </div>

        <?php if (!empty($draft['fwd_files'])): ?>
        <div class="mb-3">
            <label class="form-label small">Carry over from the original</label>
            <?php foreach ($draft['fwd_files'] as $f): ?>
            <div class="form-check">
                <input class="form-check-input" type="checkbox" name="fwd_keep[]"
                       value="<?= (int)$f['index'] ?>" id="fwd<?= (int)$f['index'] ?>" checked>
                <label class="form-check-label small" for="fwd<?= (int)$f['index'] ?>">
                    <?= e($f['name']) ?> <span class="text-muted">· <?= e(mailSize((int)$f['size'])) ?></span>
                </label>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <div class="mb-0">
            <label class="form-label small">Attach files</label>
            <input type="file" name="attachments[]" class="form-control" multiple>
            <div class="form-text">Up to <?= (int)$maxMb ?>MB in total.</div>
        </div>
    </div>

    <div class="card-footer bg-white d-flex justify-content-between align-items-center">
        <span class="text-muted small">A copy is kept in your Sent folder.</span>
        <button class="btn btn-primary"><i class="fa fa-paper-plane me-1"></i>Send</button>
    </div>
</form>

<?php include __DIR__ . '/../../includes/footer.php'; ?>

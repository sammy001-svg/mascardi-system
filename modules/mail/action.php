<?php
/**
 * Read, unread, flag, delete, move — on one message or on a selection.
 *
 * Every action names a folder and a list of uids and nothing else. There is no
 * account in the request, so the only mailbox any of this can reach is the one
 * belonging to whoever is signed in.
 */

require_once __DIR__ . '/_page.php';
requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect(mailUrl());
}
verifyCsrf();

$box    = mailBox();
$folder = mailFolderParam();
$uids   = array_values(array_filter(array_map('intval', (array)($_POST['uids'] ?? []))));
$do     = (string)($_POST['do'] ?? '');
$back   = mailUrl(['folder' => $folder]);

if (!$uids) {
    setFlash('warning', 'Nothing was selected.');
    redirect($back);
}

try {
    match ($do) {
        'read'   => $box->setSeen($folder, $uids, true),
        'unread' => $box->setSeen($folder, $uids, false),
        'flag'   => $box->setFlagged($folder, $uids, true),
        'unflag' => $box->setFlagged($folder, $uids, false),
        'delete' => $box->delete($folder, $uids),
        'move'   => $box->move($folder, $uids, (string)($_POST['to'] ?? '')),
        default  => null,
    };
    $box->close();
} catch (\Throwable $e) {
    setFlash('danger', 'That did not work: ' . $e->getMessage());
    redirect($back);
}

// The badge counts unread mail; it has just changed.
unset($_SESSION['mail_unread']);

$done = [
    'read'   => 'Marked read.',
    'unread' => 'Marked unread.',
    'flag'   => 'Flagged.',
    'unflag' => 'Flag removed.',
    'delete' => count($uids) === 1 ? 'Deleted.' : count($uids) . ' deleted.',
    'move'   => 'Moved.',
];
setFlash('success', $done[$do] ?? 'Done.');

// Flagging from the reading pane leaves the message open. Marking it unread
// does not: reopening it would only mark it read again.
if (in_array($do, ['flag', 'unflag'], true) && count($uids) === 1 && !empty($_POST['stay'])) {
    $back = mailUrl(['folder' => $folder, 'uid' => $uids[0]]);
}

redirect($back);

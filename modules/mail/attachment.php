<?php
/**
 * One attachment, downloaded.
 *
 * Always a download, never shown in the page. An attachment is a stranger's
 * file, and an HTML or SVG one opened inline would run with this system's
 * origin — which is the whole attack. So the type is deliberately not honoured
 * and nosniff stops the browser from guessing a better one.
 */

require_once __DIR__ . '/_page.php';
requireLogin();

$box    = mailBox();
$folder = mailFolderParam();
$uid    = (int)($_GET['uid']   ?? 0);
$index  = (int)($_GET['index'] ?? -1);

try {
    $m = $uid > 0 ? $box->message($folder, $uid) : null;
} catch (\Throwable $e) {
    $m = null;
}

$box->close();

$a = $m['attachments'][$index] ?? null;

if (!$a) {
    setFlash('danger', 'That attachment could not be found.');
    redirect(mailUrl(['folder' => $folder, 'uid' => $uid]));
}

$name = preg_replace('/[^\w.\- ()]+/u', '_', (string)$a['name']) ?: 'attachment';

header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . $name . '"; '
     . "filename*=UTF-8''" . rawurlencode((string)$a['name']));
header('Content-Length: ' . strlen((string)$a['data']));
header('X-Content-Type-Options: nosniff');

echo $a['data'];

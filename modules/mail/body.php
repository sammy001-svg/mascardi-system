<?php
/**
 * The body of one message, as its own page, for the reading pane's iframe.
 *
 * A message is a stranger's HTML. Three things stand between it and this
 * system, and each is there because the others can fail:
 *
 *   The sanitizer strips script, event handlers, forms and anything that
 *   fetches. That is the first defence and the one doing most of the work.
 *
 *   A Content-Security-Policy of its own allows no script and no network
 *   request at all — except the images the reader has chosen to load. So a
 *   tag the sanitizer somehow missed still cannot reach anybody.
 *
 *   The frame is sandboxed, so even a script that ran would run with no
 *   origin: no cookies, no session, nothing of this system to steal.
 *
 * Remote images are held back until asked for, because loading one tells the
 * sender their message was opened, and by whom.
 */

require_once __DIR__ . '/_page.php';
requireLogin();

use Mascardi\Mail\HtmlSanitizer;

$box         = mailBox();
$folder      = mailFolderParam();
$uid         = (int)($_GET['uid'] ?? 0);
$allowImages = ($_GET['images'] ?? '') === '1';

try {
    $m = $uid > 0 ? $box->message($folder, $uid) : null;
} catch (\Throwable $e) {
    $m = null;
}

$box->close();

$inner   = '<p style="color:#888">This message could not be loaded.</p>';
$blocked = 0;

if ($m) {
    if ($m['html'] !== '') {
        $cids = [];
        foreach ($m['attachments'] as $a) {
            // Inline images travel inside the message itself, so showing them
            // makes no request to anybody and they are never held back.
            if ($a['cid'] !== '' && str_starts_with($a['mime'], 'image/') && $a['size'] < 2000000) {
                $cids[strtolower($a['cid'])] = 'data:' . $a['mime'] . ';base64,' . base64_encode($a['data']);
            }
        }

        $clean   = HtmlSanitizer::clean($m['html'], $allowImages, $cids);
        $inner   = $clean['html'];
        $blocked = $clean['blocked'];
    } else {
        $inner = HtmlSanitizer::fromText($m['text']);
    }
}

header_remove('Content-Security-Policy');
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; font-src data:; "
     . 'img-src data:' . ($allowImages ? ' https: http:' : '') . "; base-uri 'none'; form-action 'none'");
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: no-referrer');
header('Content-Type: text/html; charset=utf-8');
header('X-Blocked-Images: ' . $blocked);

echo '<!doctype html><html><head><meta charset="utf-8"><base target="_blank">'
   . '<style>body{margin:0;padding:18px 20px;font:14px/1.6 -apple-system,Segoe UI,Roboto,Arial,sans-serif;'
   . 'color:#1f2937;background:#fff;word-wrap:break-word}img{max-width:100%;height:auto}'
   . 'blockquote{margin:0 0 0 .6em;padding-left:.8em;border-left:3px solid #d1d5db;color:#4b5563}'
   . 'pre{white-space:pre-wrap}table{max-width:100%}</style></head><body>'
   . $inner
   . '</body></html>';

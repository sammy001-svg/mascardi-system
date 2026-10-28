<?php
/**
 * Build the display sizes for car photographs that predate the picture
 * pipeline, and report what this server can actually do.
 *
 * The site works without this: a photo with no derivatives is served as its
 * master, which is correct but heavier than it needs to be. Running this makes
 * the pages lighter and lets the browser pick a width per screen.
 *
 * Run it from the cPanel terminal, which has no time limit to worry about:
 *
 *     php scripts/build_image_sizes.php            build what is missing
 *     php scripts/build_image_sizes.php --check    report only, write nothing
 *
 * Or open it in a browser signed in as a super admin.
 *
 * What it cannot do
 * -----------------
 * The pipeline this replaces overwrote each upload with a 1920x1200 copy, so
 * for photographs already on the site that is the most detail there is. They
 * will be served correctly and sharpened, and they will stop being enlarged
 * from a 480px thumbnail, which is the visible problem -- but a photograph
 * cannot be given back detail that was discarded. Re-uploading the originals
 * for the cars that matter most is the only way to recover it, and from now on
 * each upload keeps a 2560px master.
 */

$isCli = (PHP_SAPI === 'cli');
$check = $isCli && in_array('--check', $argv ?? [], true);

require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/images.php';

if (!$isCli) {
    requireLogin();
    if (!isSuperAdmin()) {
        http_response_code(403);
        exit('Only a super admin can run this, or use the cPanel terminal: '
           . 'php scripts/build_image_sizes.php');
    }
    header('Content-Type: text/plain; charset=utf-8');
    @ob_end_flush();
    $check = isset($_GET['check']);
}

$say = static function (string $line = ''): void {
    echo $line . "\n";
    @flush();
};

$say('WHAT THIS SERVER CAN DO');
$say('  image library      ' . imgEngine()
    . (imgEngine() === 'imagick' ? '  (Lanczos + unsharp: the better of the two)' : '')
    . (imgEngine() === 'gd'      ? '  (adequate; Imagick would resize better)'     : '')
    . (imgEngine() === 'none'    ? '  *** nothing installed: no resizing is possible at all ***' : ''));
$say('  WebP               ' . (imgWebpOk() ? 'yes  (about 30% smaller at the same quality)'
                                            : 'no   (JPEG only; pages will be heavier)'));
$say('  memory_limit       ' . ini_get('memory_limit'));
$say('  upload_max_filesize ' . ini_get('upload_max_filesize'));
$say('  widths built        ' . implode(', ', imgLadder()) . '  (plus the master)');
$say('  master box          ' . implode(' x ', imgMasterBox()));
$say();

if (imgEngine() === 'none') {
    $say('Nothing can be built without GD or Imagick. Ask the host to enable the');
    $say('GD extension -- it is standard on cPanel and usually one checkbox in');
    $say('"Select PHP Version" -> Extensions. Until then the site serves each');
    $say('photograph at its full size, which is correct but heavy.');
    exit(1);
}

$dir = dirname(__DIR__) . '/uploads/cars';
if (!is_dir($dir)) { $say('No uploads/cars directory yet; nothing to do.'); exit(0); }

$files = array_values(array_filter(scandir($dir) ?: [], static function ($f) use ($dir) {
    return is_file($dir . '/' . $f)
        && preg_match('/\.(jpe?g|png|webp|gif)$/i', $f);
}));

$say('WHAT IS ON DISK');
$say('  ' . count($files) . ' photograph(s) in uploads/cars');

$small = $made = $skipped = 0;
$widths = [];
foreach ($files as $i => $f) {
    $info = @getimagesize($dir . '/' . $f);
    if (!$info) { $skipped++; continue; }
    $w = (int)$info[0];
    $widths[] = $w;
    // 1600 is what the gallery frame needs on a 2x screen. Anything under that
    // is a photograph that will still look soft however it is served, because
    // the detail is not there to serve.
    if ($w < 1600) $small++;

    if (!$check) {
        $r = imgDerive('cars', $f);
        $made += $r['made'];
        if ($r['skipped'] !== '') {
            $say('  skipped ' . $f . ' - ' . $r['skipped']);
            $skipped++;
        }
        if ((($i + 1) % 25) === 0) $say('  ... ' . ($i + 1) . ' of ' . count($files));
    }
}

if ($widths) {
    sort($widths);
    $say('  narrowest ' . $widths[0] . 'px, widest ' . end($widths) . 'px, median '
        . $widths[intdiv(count($widths), 2)] . 'px');
    $say('  ' . $small . ' of ' . count($widths)
        . ' are under 1600px wide, which is what the gallery needs on a 2x screen');
    if ($small > 0) {
        $say('     -> those will be served correctly but cannot look sharp;');
        $say('        re-upload the originals for the cars that matter most.');
    }
}
$say();

if ($check) {
    $say('Report only. Run without --check to build the missing sizes.');
} else {
    $say('BUILT');
    $say('  ' . $made . ' file(s) written, ' . $skipped . ' skipped');
    $say('  Nothing was overwritten: only missing sizes are created, so this is');
    $say('  safe to run again at any time.');
}

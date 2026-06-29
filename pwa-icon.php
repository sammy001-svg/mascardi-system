<?php
/**
 * PWA icon generator — PUBLIC resource, no auth/session required.
 *
 * Generates a branded PNG icon at the requested size and caches it
 * as a static file so the web server can serve it directly on future requests.
 *
 * Three rendering paths (best → fallback):
 *   1. GD extension   — full car silhouette
 *   2. Pure PHP PNG   — blue rounded square + M lettermark (no GD needed)
 *   3. Should never reach here; both paths above always produce output
 *
 * Chrome requires genuine 192 px and 512 px PNGs in the manifest for
 * beforeinstallprompt to fire — no SVG, no redirect, no corruption.
 */

// ── Safety: buffer everything so no stray byte can corrupt the PNG ────────
ob_start();

$s = max(16, min(512, (int)($_GET['s'] ?? 192)));

// ── Serve static cached file if it exists ────────────────────────────────
$cacheDir  = __DIR__ . '/assets/images/icons';
$cacheFile = $cacheDir . '/icon-' . $s . '.png';

if (file_exists($cacheFile) && filesize($cacheFile) > 100) {
    ob_end_clean();
    header('Content-Type: image/png');
    header('Cache-Control: public, max-age=604800');
    header('X-Content-Type-Options: nosniff');
    readfile($cacheFile);
    exit;
}

// ── Generate PNG ──────────────────────────────────────────────────────────
$pngData = null;

/* Path 1: GD ---------------------------------------------------------------- */
if (function_exists('imagecreatetruecolor')) {
    $img = imagecreatetruecolor($s, $s);

    imagealphablending($img, false);
    imagesavealpha($img, true);
    $transparent = imagecolorallocatealpha($img, 0, 0, 0, 127);
    imagefill($img, 0, 0, $transparent);
    imagealphablending($img, true);

    $blue     = imagecolorallocate($img, 37,  99,  235);
    $darkBlue = imagecolorallocate($img, 30,  58,  138);
    $white    = imagecolorallocate($img, 255, 255, 255);
    $lgray    = imagecolorallocate($img, 219, 234, 254);

    // Rounded-square background
    $r = (int)($s * 0.18);
    imagefilledrectangle($img, $r, 0, $s - $r, $s, $blue);
    imagefilledrectangle($img, 0, $r, $s, $s - $r, $blue);
    imagefilledellipse($img, $r,      $r,      $r * 2, $r * 2, $blue);
    imagefilledellipse($img, $s - $r, $r,      $r * 2, $r * 2, $blue);
    imagefilledellipse($img, $r,      $s - $r, $r * 2, $r * 2, $blue);
    imagefilledellipse($img, $s - $r, $s - $r, $r * 2, $r * 2, $blue);

    // Car silhouette
    $u  = max(1, (int)($s / 20));
    $cx = (int)($s / 2);
    $cy = (int)($s / 2);

    imagefilledrectangle($img, $cx - 8*$u, $cy - $u,   $cx + 8*$u, $cy + 4*$u, $white);
    imagefilledpolygon($img, [
        $cx - 5*$u, $cy - $u,
        $cx - 6*$u, $cy - 5*$u,
        $cx + 6*$u, $cy - 5*$u,
        $cx + 5*$u, $cy - $u,
    ], 4, $white);
    imagefilledrectangle($img, $cx - 5*$u, $cy - 4*$u, $cx - $u,   $cy - 2*$u, $lgray);
    imagefilledrectangle($img, $cx + $u,   $cy - 4*$u, $cx + 5*$u, $cy - 2*$u, $lgray);
    imagefilledellipse($img, $cx - 5*$u, $cy + 4*$u, 4*$u, 4*$u, $white);
    imagefilledellipse($img, $cx - 5*$u, $cy + 4*$u, 2*$u, 2*$u, $darkBlue);
    imagefilledellipse($img, $cx + 5*$u, $cy + 4*$u, 4*$u, 4*$u, $white);
    imagefilledellipse($img, $cx + 5*$u, $cy + 4*$u, 2*$u, 2*$u, $darkBlue);

    // Capture PNG bytes without sending to output
    ob_start();
    imagepng($img);
    $pngData = ob_get_clean();
    imagedestroy($img);
}

/* Path 2: Pure PHP PNG (no GD) ---------------------------------------------- */
if (!$pngData) {
    $pngData = buildPNG($s, $s);
}

// ── Cache to static file for future requests ─────────────────────────────
if ($pngData && is_dir($cacheDir) && is_writable($cacheDir)) {
    file_put_contents($cacheFile, $pngData);
}

// ── Output ────────────────────────────────────────────────────────────────
ob_end_clean(); // discard any stray output accumulated above
header('Content-Type: image/png');
header('Cache-Control: public, max-age=604800');
header('X-Content-Type-Options: nosniff');
echo $pngData;
exit;

// ─────────────────────────────────────────────────────────────────────────
// Pure-PHP PNG builder — works without any PHP extensions
// ─────────────────────────────────────────────────────────────────────────

function pngChunk(string $type, string $data): string {
    $chunk = $type . $data;
    return pack('N', strlen($data)) . $chunk . pack('N', crc32($chunk));
}

function buildPNG(int $w, int $h): string {
    $bg_r = 37;  $bg_g = 99;  $bg_b = 235;  // #2563eb brand blue
    $wh_r = 255; $wh_g = 255; $wh_b = 255;  // white

    $corner = (int)($w * 0.18);

    $raw = '';
    for ($y = 0; $y < $h; $y++) {
        $raw .= "\x00"; // None filter
        for ($x = 0; $x < $w; $x++) {
            // Rounded corner clipping
            $inCorner = false;
            if ($x < $corner && $y < $corner) {
                $dx = $corner - $x; $dy = $corner - $y;
                $inCorner = ($dx*$dx + $dy*$dy) > ($corner*$corner);
            } elseif ($x > $w - 1 - $corner && $y < $corner) {
                $dx = $x - ($w - 1 - $corner); $dy = $corner - $y;
                $inCorner = ($dx*$dx + $dy*$dy) > ($corner*$corner);
            } elseif ($x < $corner && $y > $h - 1 - $corner) {
                $dx = $corner - $x; $dy = $y - ($h - 1 - $corner);
                $inCorner = ($dx*$dx + $dy*$dy) > ($corner*$corner);
            } elseif ($x > $w - 1 - $corner && $y > $h - 1 - $corner) {
                $dx = $x - ($w - 1 - $corner); $dy = $y - ($h - 1 - $corner);
                $inCorner = ($dx*$dx + $dy*$dy) > ($corner*$corner);
            }

            if ($inCorner) { $raw .= "\x00\x00\x00\x00"; continue; }

            // White "M" lettermark, centre 50% of icon
            $margin = (int)($w * 0.25);
            $lw     = max(1, (int)($w * 0.07));
            $top    = $margin; $bot = $h - $margin;
            $left   = $margin; $right = $w - $margin;
            $mid_x  = (int)($w / 2);
            $mid_y  = (int)($h * 0.55);

            $isM = false;
            if ($x >= $left && $x < $left + $lw && $y >= $top && $y <= $bot)     $isM = true;
            if ($x >= $right - $lw && $x < $right && $y >= $top && $y <= $bot)   $isM = true;
            if (!$isM && ($slope_x = $mid_x - $left) > 0) {
                $t = ($y - $top) / max(1, $mid_y - $top);
                $ex = $left + (int)($t * $slope_x);
                if ($x >= $ex && $x < $ex + $lw && $y >= $top && $y <= $mid_y)   $isM = true;
            }
            if (!$isM && ($slope_x = $right - $mid_x) > 0) {
                $t = ($y - $top) / max(1, $mid_y - $top);
                $ex = $right - (int)($t * $slope_x);
                if ($x >= $ex - $lw && $x < $ex && $y >= $top && $y <= $mid_y)   $isM = true;
            }

            if ($isM) { $raw .= chr($wh_r).chr($wh_g).chr($wh_b)."\xff"; }
            else      { $raw .= chr($bg_r).chr($bg_g).chr($bg_b)."\xff"; }
        }
    }

    $png  = "\x89PNG\r\n\x1a\n";
    $png .= pngChunk('IHDR', pack('NNCCCCC', $w, $h, 8, 6, 0, 0, 0));
    $png .= pngChunk('IDAT', gzcompress($raw, 6));
    $png .= pngChunk('IEND', '');
    return $png;
}

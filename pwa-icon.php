<?php
/**
 * PWA icon generator — serves a valid PNG at the requested size.
 * Supports three rendering paths (best to worst quality):
 *   1. GD (imagecreatetruecolor) — full car silhouette
 *   2. Pure PHP PNG writer        — solid blue rounded square (no GD needed)
 *   3. SVG redirect               — last resort
 *
 * Chrome/Android require genuine 192px + 512px PNG icons in the manifest
 * for beforeinstallprompt to fire.  The pure-PHP fallback ensures this
 * works even on hosts where the GD extension is disabled.
 */
require_once __DIR__ . '/config/app.php';

$s = max(16, min(512, (int)($_GET['s'] ?? 192)));

header('Content-Type: image/png');
header('Cache-Control: public, max-age=604800');
header('X-Content-Type-Options: nosniff');

/* ── Path 1: GD available ─────────────────────────────────────────────── */
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

    imagepng($img);
    imagedestroy($img);
    exit;
}

/* ── Path 2: Pure PHP PNG — no GD needed ─────────────────────────────── */
// Builds a minimal but fully valid PNG from raw bytes.
// Icon: blue rounded-square background + white "M" lettermark.

function pngChunk(string $type, string $data): string {
    $chunk = $type . $data;
    return pack('N', strlen($data)) . $chunk . pack('N', crc32($chunk));
}

function buildPNG(int $w, int $h): string {
    // Each pixel: RGBA (4 bytes, colour type 6)
    $bg_r = 37;  $bg_g = 99;  $bg_b = 235;  // #2563eb brand blue
    $wh_r = 255; $wh_g = 255; $wh_b = 255;  // white

    // Pre-compute rounded-corner radius (18 % of size)
    $corner = (int)($w * 0.18);

    // Build raw scanlines (filter byte 0 + RGBA per pixel)
    $raw = '';
    for ($y = 0; $y < $h; $y++) {
        $raw .= "\x00"; // None filter
        for ($x = 0; $x < $w; $x++) {
            // Determine if this pixel is inside the rounded square
            $inCorner = false;
            if ($x < $corner && $y < $corner) {
                $dx = $corner - $x; $dy = $corner - $y;
                $inCorner = ($dx * $dx + $dy * $dy) > ($corner * $corner);
            } elseif ($x > $w - 1 - $corner && $y < $corner) {
                $dx = $x - ($w - 1 - $corner); $dy = $corner - $y;
                $inCorner = ($dx * $dx + $dy * $dy) > ($corner * $corner);
            } elseif ($x < $corner && $y > $h - 1 - $corner) {
                $dx = $corner - $x; $dy = $y - ($h - 1 - $corner);
                $inCorner = ($dx * $dx + $dy * $dy) > ($corner * $corner);
            } elseif ($x > $w - 1 - $corner && $y > $h - 1 - $corner) {
                $dx = $x - ($w - 1 - $corner); $dy = $y - ($h - 1 - $corner);
                $inCorner = ($dx * $dx + $dy * $dy) > ($corner * $corner);
            }

            if ($inCorner) {
                // Transparent
                $raw .= "\x00\x00\x00\x00";
                continue;
            }

            // White "M" lettermark occupying centre 50% of icon
            $margin  = (int)($w * 0.25);
            $lw      = max(1, (int)($w * 0.07)); // stroke width
            $top     = $margin;
            $bot     = $h - $margin;
            $left    = $margin;
            $right   = $w - $margin;
            $mid_x   = (int)($w / 2);
            $mid_y   = (int)($h * 0.55);

            $isM = false;
            // Left vertical bar
            if ($x >= $left && $x < $left + $lw && $y >= $top && $y <= $bot) $isM = true;
            // Right vertical bar
            if ($x >= $right - $lw && $x < $right && $y >= $top && $y <= $bot) $isM = true;
            // Left diagonal (top-left to centre-top)
            if (!$isM) {
                $slope_x = $mid_x - $left;
                $slope_y = $mid_y - $top;
                if ($slope_x > 0) {
                    $t = ($y - $top) / max(1, $slope_y);
                    $expectedX = $left + (int)($t * $slope_x);
                    if ($x >= $expectedX && $x < $expectedX + $lw && $y >= $top && $y <= $mid_y) $isM = true;
                }
            }
            // Right diagonal (top-right to centre-top)
            if (!$isM) {
                $slope_x = $right - $mid_x;
                $slope_y = $mid_y - $top;
                if ($slope_x > 0) {
                    $t = ($y - $top) / max(1, $slope_y);
                    $expectedX = $right - (int)($t * $slope_x);
                    if ($x >= $expectedX - $lw && $x < $expectedX && $y >= $top && $y <= $mid_y) $isM = true;
                }
            }

            if ($isM) {
                $raw .= chr($wh_r) . chr($wh_g) . chr($wh_b) . "\xff";
            } else {
                $raw .= chr($bg_r) . chr($bg_g) . chr($bg_b) . "\xff";
            }
        }
    }

    $png  = "\x89PNG\r\n\x1a\n";
    $png .= pngChunk('IHDR', pack('NNCCCCC', $w, $h, 8, 6, 0, 0, 0)); // colour type 6 = RGBA
    $png .= pngChunk('IDAT', gzcompress($raw, 6));
    $png .= pngChunk('IEND', '');
    return $png;
}

echo buildPNG($s, $s);
exit;

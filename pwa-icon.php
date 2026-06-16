<?php
/**
 * PWA icon generator — serves a PNG at the requested size.
 * Required for Chrome/Android installability (manifest must list 192px + 512px PNG icons).
 * No auth needed; browsers fetch this before the user logs in.
 */
require_once __DIR__ . '/config/app.php';

$s = max(16, min(512, (int)($_GET['s'] ?? 192)));

// If GD is unavailable, redirect to the SVG fallback
if (!function_exists('imagecreatetruecolor')) {
    header('Location: ' . rtrim(BASE_URL, '/') . '/assets/images/icons/icon.svg');
    exit;
}

header('Content-Type: image/png');
header('Cache-Control: public, max-age=604800'); // 7 days
header('X-Content-Type-Options: nosniff');

$img = imagecreatetruecolor($s, $s);

// Transparent background for rounded-corner trick
imagealphablending($img, false);
imagesavealpha($img, true);
$transparent = imagecolorallocatealpha($img, 0, 0, 0, 127);
imagefill($img, 0, 0, $transparent);
imagealphablending($img, true);

// Brand blue
$blue  = imagecolorallocate($img, 37,  99,  235);  // #2563eb
$white = imagecolorallocate($img, 255, 255, 255);
$lgray = imagecolorallocate($img, 219, 234, 254);  // #dbeafe

// Rounded-square background
$r = (int)($s * 0.18); // corner radius
imagefilledrectangle($img, $r, 0, $s - $r, $s, $blue);
imagefilledrectangle($img, 0, $r, $s, $s - $r, $blue);
imagefilledellipse($img, $r,       $r,       $r * 2, $r * 2, $blue);
imagefilledellipse($img, $s - $r,  $r,       $r * 2, $r * 2, $blue);
imagefilledellipse($img, $r,       $s - $r,  $r * 2, $r * 2, $blue);
imagefilledellipse($img, $s - $r,  $s - $r,  $r * 2, $r * 2, $blue);

// Car silhouette (scale everything to icon size)
$u  = (int)($s / 20); // 1 unit
$cx = (int)($s / 2);
$cy = (int)($s / 2);

// Body
imagefilledrectangle($img, $cx - 8*$u, $cy - $u, $cx + 8*$u, $cy + 4*$u, $white);

// Roof trapezoid
imagefilledpolygon($img, [
    $cx - 5*$u, $cy - $u,
    $cx - 6*$u, $cy - 5*$u,
    $cx + 6*$u, $cy - 5*$u,
    $cx + 5*$u, $cy - $u,
], 4, $white);

// Windows
imagefilledrectangle($img, $cx - 5*$u, $cy - 4*$u, $cx - $u,  $cy - 2*$u, $lgray);
imagefilledrectangle($img, $cx + $u,   $cy - 4*$u, $cx + 5*$u, $cy - 2*$u, $lgray);

// Wheels (dark rim)
$darkBlue = imagecolorallocate($img, 30, 58, 138); // #1e3a8a
imagefilledellipse($img, $cx - 5*$u, $cy + 4*$u, 4*$u, 4*$u, $white);
imagefilledellipse($img, $cx - 5*$u, $cy + 4*$u, 2*$u, 2*$u, $darkBlue);
imagefilledellipse($img, $cx + 5*$u, $cy + 4*$u, 4*$u, 4*$u, $white);
imagefilledellipse($img, $cx + 5*$u, $cy + 4*$u, 2*$u, 2*$u, $darkBlue);

imagepng($img);
imagedestroy($img);

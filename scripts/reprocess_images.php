<?php
/**
 * Batch image reprocessor
 * Generates missing thumbnails and re-compresses oversized originals
 * for existing uploads.
 *
 * Run from CLI:  php scripts/reprocess_images.php
 * Or via browser with ?secret=YOURKEY (set REPROCESS_SECRET below)
 *
 * Dry-run mode:  php scripts/reprocess_images.php --dry-run
 */

define('REPROCESS_SECRET', 'mascardi_reprocess_2024');
define('MAX_W', 1920);
define('MAX_H', 1200);
define('THUMB_W', 480);
define('THUMB_H', 360);
define('QUALITY_FULL', 82);
define('QUALITY_THUMB', 75);

$isCli = (PHP_SAPI === 'cli');
$isDry = $isCli && in_array('--dry-run', $argv ?? []);

if (!$isCli) {
    $secret = $_GET['secret'] ?? '';
    if ($secret !== REPROCESS_SECRET) {
        http_response_code(403);
        exit('Forbidden. Pass ?secret=YOURKEY');
    }
    header('Content-Type: text/plain; charset=utf-8');
    // Stream output immediately
    @ob_end_flush();
}

if (!extension_loaded('gd')) {
    die("GD extension not available — cannot process images.\n");
}

// Locate uploads root relative to this script
$uploadsRoot = dirname(__DIR__) . '/uploads';
if (!is_dir($uploadsRoot)) {
    die("uploads/ directory not found at: $uploadsRoot\n");
}

$stats = ['scanned' => 0, 'thumb_created' => 0, 'full_compressed' => 0, 'skipped' => 0, 'errors' => 0];
$imgExts = ['jpg', 'jpeg', 'png', 'webp', 'gif'];

out("=== Mascardi Image Reprocessor" . ($isDry ? " [DRY RUN]" : "") . " ===\n");
out("Uploads root: $uploadsRoot\n\n");

// Walk all subdirectories one level deep (cars/, documents/, etc.)
foreach (glob($uploadsRoot . '/*', GLOB_ONLYDIR) as $subDir) {
    if (basename($subDir) === 'thumbs') continue; // skip thumbs dirs at root level
    $thumbDir = $subDir . '/thumbs';

    out("Processing: " . basename($subDir) . "/\n");

    $files = glob($subDir . '/*.*') ?: [];
    foreach ($files as $filePath) {
        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        if (!in_array($ext, $imgExts)) continue;

        $filename = basename($filePath);
        $thumbPath = $thumbDir . '/' . $filename;
        $stats['scanned']++;

        // Load image
        $img = imgLoad($filePath, $ext);
        if (!$img) {
            out("  ERROR loading: $filename\n");
            $stats['errors']++;
            continue;
        }

        $origW = imagesx($img);
        $origH = imagesy($img);

        // --- Full image: compress if oversized or > 500 KB ---
        $fileSize = filesize($filePath);
        $needsResize = ($origW > MAX_W || $origH > MAX_H);
        $needsCompress = ($fileSize > 512000); // > 500 KB

        if ($needsResize || $needsCompress) {
            [$newW, $newH] = imgFit($origW, $origH, MAX_W, MAX_H);
            $resized = imagecreatetruecolor($newW, $newH);
            imgAlpha($resized, $ext);
            imagecopyresampled($resized, $img, 0, 0, 0, 0, $newW, $newH, $origW, $origH);
            if (!$isDry) {
                imgSave($resized, $filePath, $ext, QUALITY_FULL);
            }
            imagedestroy($resized);
            out("  [FULL] " . ($needsResize ? "resized {$origW}x{$origH}→{$newW}x{$newH}" : "compressed") . ": $filename\n");
            $stats['full_compressed']++;
        }

        // --- Thumbnail: create if missing ---
        if (!file_exists($thumbPath)) {
            [$tw, $th] = imgFit($origW, $origH, THUMB_W, THUMB_H);
            $thumb = imagecreatetruecolor($tw, $th);
            imgAlpha($thumb, $ext);
            imagecopyresampled($thumb, $img, 0, 0, 0, 0, $tw, $th, $origW, $origH);
            if (!$isDry) {
                if (!is_dir($thumbDir)) mkdir($thumbDir, 0755, true);
                imgSave($thumb, $thumbPath, $ext, QUALITY_THUMB);
            }
            imagedestroy($thumb);
            out("  [THUMB] created: $filename ({$tw}x{$th})\n");
            $stats['thumb_created']++;
        } else {
            $stats['skipped']++;
        }

        imagedestroy($img);
    }
}

out("\n=== Done" . ($isDry ? " (DRY RUN — no files written)" : "") . " ===\n");
out("Scanned:          {$stats['scanned']}\n");
out("Thumbs created:   {$stats['thumb_created']}\n");
out("Fulls compressed: {$stats['full_compressed']}\n");
out("Already done:     {$stats['skipped']}\n");
out("Errors:           {$stats['errors']}\n");

// ── Helpers ────────────────────────────────────────────────────────────────

function out(string $msg): void {
    echo $msg;
    if (PHP_SAPI !== 'cli') flush();
}

function imgLoad(string $path, string $ext) {
    switch ($ext) {
        case 'jpg': case 'jpeg': return @imagecreatefromjpeg($path);
        case 'png':  return @imagecreatefrompng($path);
        case 'webp': return function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : false;
        case 'gif':  return @imagecreatefromgif($path);
    }
    return false;
}

function imgSave($img, string $path, string $ext, int $quality): void {
    switch ($ext) {
        case 'jpg': case 'jpeg': imagejpeg($img, $path, $quality); break;
        case 'png':  imagepng($img, $path, (int)round((100 - $quality) / 10)); break;
        case 'webp': if (function_exists('imagewebp')) imagewebp($img, $path, $quality); break;
        case 'gif':  imagegif($img, $path); break;
    }
}

function imgAlpha($img, string $ext): void {
    if (in_array($ext, ['png', 'webp', 'gif'])) {
        imagealphablending($img, false);
        imagesavealpha($img, true);
        $transparent = imagecolorallocatealpha($img, 0, 0, 0, 127);
        imagefilledrectangle($img, 0, 0, imagesx($img), imagesy($img), $transparent);
        imagealphablending($img, true);
    }
}

function imgFit(int $w, int $h, int $maxW, int $maxH): array {
    if ($w <= $maxW && $h <= $maxH) return [$w, $h];
    $ratio = min($maxW / $w, $maxH / $h);
    return [max(1, (int)round($w * $ratio)), max(1, (int)round($h * $ratio))];
}

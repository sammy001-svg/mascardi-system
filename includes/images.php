<?php
/**
 * Picture quality on the public website.
 *
 * What was wrong
 * --------------
 * Every uploaded photo was downscaled in place to 1920x1200 at JPEG quality 82
 * and that single file was then served to everything -- a 400px listing card, a
 * 800px gallery frame and the full-screen lightbox alike.
 *
 * The lightbox is where that shows. It is sized 90vw x 80vh, so on a 1512px
 * laptop at 2x it asks for roughly 2700 device pixels across and on a 4K panel
 * nearer 3700. Handing it 1920 means the browser enlarges the photo by between
 * 1.4 and 1.9 times, and enlargement is exactly what "pixelated" looks like --
 * at the one moment a buyer is leaning in to study the paint and the wheels.
 *
 * At the other end the listing cards render at about 403px wide and were each
 * downloading that same 1920px file: slow to appear, which reads as poor
 * quality too, and nine of them on a page.
 *
 * What this does
 * --------------
 * Keeps one generous master and derives the sizes a browser actually wants:
 *
 *   base file   2560x1600  q88   the master, and what the lightbox uses
 *   w1440       1440 wide  q88   the gallery frame on a 2x screen
 *   w960         960 wide  q88   cards on a 2x screen, gallery on 1x
 *   w480         480 wide  q85   cards on 1x, and the thumbnail strip
 *
 * plus a WebP of each, which is roughly 30% smaller at the same visual quality
 * -- the headroom that pays for the extra pixels. Templates hand the browser
 * the whole set with a `sizes` hint and it picks; nobody downloads more than
 * their screen can show.
 *
 * Why 2560 and not the untouched original
 * ---------------------------------------
 * A 12MP phone photo is 8MB, and twenty-four of them per car on shared hosting
 * is a quota problem rather than a quality one. 2560x1600 is more than any
 * display in use can show, and it is the largest thing anything here asks for,
 * so it is the master. Everything else is derived from it and can be rebuilt.
 *
 * Note on what cannot be undone
 * -----------------------------
 * The old pipeline overwrote the uploaded file with its 1920px version, so for
 * photos already on the site that is all there is -- they can be sharpened and
 * served more intelligently, but the detail is gone and only re-uploading
 * brings it back. Everything from here on keeps its 2560px master.
 */

if (!function_exists('imgLadder')) {

/** The widths a browser may be offered, narrowest first. */
function imgLadder(): array
{
    return [480, 960, 1440];
}

/** The master: bigger than any display asks for, small enough to store. */
function imgMasterBox(): array
{
    return [2560, 1600];
}

/**
 * Which library is available, if any.
 *
 * Imagick is preferred because its Lanczos filter is markedly better at
 * downscaling a photograph than anything GD offers, and because it can sharpen
 * afterwards properly. GD is the fallback. Neither means images are left
 * exactly as uploaded, which is the old behaviour and no worse than it.
 */
function imgEngine(): string
{
    static $e = null;
    if ($e !== null) return $e;
    if (class_exists('Imagick')) return $e = 'imagick';
    if (function_exists('imagecreatetruecolor')) return $e = 'gd';
    return $e = 'none';
}

/** Is WebP something this server can write? */
function imgWebpOk(): bool
{
    static $ok = null;
    if ($ok !== null) return $ok;
    if (imgEngine() === 'imagick') {
        try { return $ok = (bool)Imagick::queryFormats('WEBP'); }
        catch (\Throwable $_) { return $ok = false; }
    }
    return $ok = function_exists('imagewebp');
}

/** Where a derivative of this file lives on disk. */
function imgVariantPath(string $subDir, string $filename, int $width, bool $webp = false): string
{
    $name = $webp ? preg_replace('/\.[^.]+$/', '', $filename) . '.webp' : $filename;
    return BASE_PATH . '/uploads/' . $subDir . '/w' . $width . '/' . $name;
}

/** And its public URL. */
function imgVariantUrl(string $subDir, string $filename, int $width, bool $webp = false): string
{
    $name = $webp ? preg_replace('/\.[^.]+$/', '', $filename) . '.webp' : $filename;
    return BASE_URL . '/uploads/' . $subDir . '/w' . $width . '/' . $name;
}

/**
 * Enough memory to hold this image twice over?
 *
 * GD works on uncompressed bitmaps: a 12MP photo is about 48MB as a buffer, and
 * source plus destination can exceed a 128MB limit on its own. Running out
 * leaves a white page and a half-written file, so an image too big for the
 * limit is skipped and logged rather than attempted.
 */
function imgMemoryOk(int $w, int $h): bool
{
    $limit = trim((string)ini_get('memory_limit'));
    if ($limit === '' || $limit === '-1') return true;
    $bytes = (int)$limit;
    if (stripos($limit, 'g') !== false)      $bytes *= 1024 * 1024 * 1024;
    elseif (stripos($limit, 'm') !== false)   $bytes *= 1024 * 1024;
    elseif (stripos($limit, 'k') !== false)   $bytes *= 1024;
    // Four bytes a pixel, source and destination, and a little headroom.
    $need = ($w * $h * 4 * 2) + (8 * 1024 * 1024);
    return $need < ($bytes - memory_get_usage(true));
}

/** Fit inside a box without distorting or enlarging. */
function imgFitBox(int $w, int $h, int $maxW, int $maxH): array
{
    if ($w <= 0 || $h <= 0) return [$w, $h];
    $r = min($maxW / $w, $maxH / $h, 1);
    return [max(1, (int)round($w * $r)), max(1, (int)round($h * $r))];
}

/**
 * Build every missing derivative of one uploaded image.
 *
 * Safe to call repeatedly: it only writes what is absent, so it doubles as the
 * backfill for photos uploaded before any of this existed.
 *
 * @return array{made:int,skipped:string}
 */
function imgDerive(string $subDir, string $filename): array
{
    if ($filename === '') return ['made' => 0, 'skipped' => 'no filename'];
    $src = BASE_PATH . '/uploads/' . $subDir . '/' . $filename;
    if (!is_file($src)) return ['made' => 0, 'skipped' => 'source missing'];
    if (imgEngine() === 'none') return ['made' => 0, 'skipped' => 'no image library'];

    $info = @getimagesize($src);
    if (!$info) return ['made' => 0, 'skipped' => 'not an image'];
    [$ow, $oh] = $info;
    if (!imgMemoryOk($ow, $oh)) {
        error_log("imgDerive: {$filename} is {$ow}x{$oh}, too large for memory_limit");
        return ['made' => 0, 'skipped' => 'too large for memory_limit'];
    }

    $made = 0;
    foreach (imgLadder() as $w) {
        if ($w > $ow) continue;                       // never enlarge
        $q = $w <= 480 ? 85 : 88;
        $jpg = imgVariantPath($subDir, $filename, $w, false);
        if (!is_file($jpg) && imgWrite($src, $jpg, $w, $q, false)) $made++;
        if (imgWebpOk()) {
            $wp = imgVariantPath($subDir, $filename, $w, true);
            if (!is_file($wp) && imgWrite($src, $wp, $w, 82, true)) $made++;
        }
    }
    // A WebP of the master too, for the lightbox.
    if (imgWebpOk()) {
        $masterWebp = preg_replace('/\.[^.]+$/', '', $src) . '.webp';
        if (!is_file($masterWebp) && imgWrite($src, $masterWebp, $ow, 82, true)) $made++;
    }
    return ['made' => $made, 'skipped' => ''];
}

/**
 * Write one resized copy.
 *
 * Downscaling a photograph softens it -- that is arithmetic, not a bug -- so
 * every copy is sharpened afterwards by an amount scaled to how far it was
 * reduced. Skipping that step is most of why the old images looked flat.
 */
function imgWrite(string $src, string $dest, int $targetW, int $quality, bool $webp): bool
{
    $dir = dirname($dest);
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
        error_log('imgWrite: cannot create ' . $dir);
        return false;
    }

    try {
        if (imgEngine() === 'imagick') {
            $im = new Imagick($src);
            $im->setImageOrientation($im->getImageOrientation());
            try { $im->autoOrientImage(); } catch (\Throwable $_) {}
            $ow = $im->getImageWidth();
            $oh = $im->getImageHeight();
            $th = max(1, (int)round($oh * ($targetW / max(1, $ow))));
            if ($targetW < $ow) {
                // Lanczos: the reason Imagick is preferred here.
                $im->resizeImage($targetW, $th, Imagick::FILTER_LANCZOS, 1);
                $im->unsharpMaskImage(0, 0.6, 0.8, 0.02);
            }
            $im->stripImage();                        // drop EXIF bulk, keep pixels
            $im->setImageFormat($webp ? 'webp' : strtolower(pathinfo($dest, PATHINFO_EXTENSION)));
            $im->setImageCompressionQuality($quality);
            if (!$webp) $im->setInterlaceScheme(Imagick::INTERLACE_PLANE);  // progressive JPEG
            $ok = $im->writeImage($dest);
            $im->clear();
            return (bool)$ok;
        }

        // ── GD ────────────────────────────────────────────────────────────
        $ext = strtolower(pathinfo($src, PATHINFO_EXTENSION));
        $img = match ($ext) {
            'jpg', 'jpeg' => @imagecreatefromjpeg($src),
            'png'         => @imagecreatefrompng($src),
            'webp'        => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($src) : false,
            'gif'         => @imagecreatefromgif($src),
            default       => false,
        };
        if (!$img) return false;

        $ow = imagesx($img);
        $oh = imagesy($img);
        $tw = min($targetW, $ow);
        $th = max(1, (int)round($oh * ($tw / max(1, $ow))));

        $dst = imagecreatetruecolor($tw, $th);
        if ($webp || in_array($ext, ['png', 'gif'], true)) {
            imagealphablending($dst, false);
            imagesavealpha($dst, true);
            imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127));
        }
        imagecopyresampled($dst, $img, 0, 0, 0, 0, $tw, $th, $ow, $oh);

        // Sharpen in proportion to the reduction. GD has no unsharp mask, so
        // this is a light convolution -- enough to put back the edge the
        // resample took off, gentle enough not to ring around chrome.
        if ($tw < $ow) {
            $amount = $tw / $ow < 0.5 ? 0.8 : 0.45;
            $c = -$amount;
            $centre = 1 + (4 * $amount);
            @imageconvolution($dst, [[0, $c, 0], [$c, $centre, $c], [0, $c, 0]], 1, 0);
        }

        $ok = $webp
            ? (function_exists('imagewebp') && imagewebp($dst, $dest, $quality))
            : match ($ext) {
                'png'  => imagepng($dst, $dest, 6),
                'gif'  => imagegif($dst, $dest),
                'webp' => function_exists('imagewebp') && imagewebp($dst, $dest, $quality),
                default => imagejpeg($dst, $dest, $quality),
            };
        imagedestroy($dst);
        imagedestroy($img);
        return (bool)$ok;
    } catch (\Throwable $e) {
        error_log('imgWrite: ' . $e->getMessage());
        return false;
    }
}

/**
 * The srcset for an image, listing only sizes that are actually on disk.
 *
 * Nothing is promised that cannot be served: a photo whose derivatives have not
 * been built yet simply gets a one-entry set pointing at the file itself, which
 * is what the site did before any of this.
 */
function imgSrcset(string $subDir, string $filename, bool $webp = false): string
{
    if ($filename === '') return '';
    $out = [];
    foreach (imgLadder() as $w) {
        if (is_file(imgVariantPath($subDir, $filename, $w, $webp))) {
            $out[] = imgVariantUrl($subDir, $filename, $w, $webp) . ' ' . $w . 'w';
        }
    }
    // The master, at whatever width it really is.
    $master = BASE_PATH . '/uploads/' . $subDir . '/' . $filename;
    if ($webp) {
        $mw = preg_replace('/\.[^.]+$/', '', $master) . '.webp';
        if (is_file($mw)) {
            $info = @getimagesize($mw);
            if ($info) {
                $out[] = BASE_URL . '/uploads/' . $subDir . '/'
                       . preg_replace('/\.[^.]+$/', '', $filename) . '.webp'
                       . ' ' . (int)$info[0] . 'w';
            }
        }
    } elseif (is_file($master)) {
        $info = @getimagesize($master);
        if ($info) {
            $out[] = BASE_URL . '/uploads/' . $subDir . '/' . $filename . ' ' . (int)$info[0] . 'w';
        }
    }
    return implode(', ', $out);
}

/**
 * The best copy available, for the lightbox.
 *
 * Prefers the WebP master when the browser took it, because at the same file
 * size it carries more detail -- and detail is the entire point of the
 * full-screen view.
 */
function imgBestUrl(string $subDir, string $filename): string
{
    if ($filename === '') return '';
    return BASE_URL . '/uploads/' . $subDir . '/' . $filename;
}

/** A sensible fallback src: the middle of the ladder, or the file itself. */
function imgFallbackUrl(string $subDir, string $filename, int $prefer = 960): string
{
    if ($filename === '') return '';
    if (is_file(imgVariantPath($subDir, $filename, $prefer, false))) {
        return imgVariantUrl($subDir, $filename, $prefer, false);
    }
    return BASE_URL . '/uploads/' . $subDir . '/' . $filename;
}

} // function_exists('imgLadder')

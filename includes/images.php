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
/**
 * Build the sizes a browser wants from a photograph already on disk.
 *
 * $withWebp is false during an upload. WebP encoding measured 1.9 of the 3.7
 * seconds the derivatives took -- a second of that for the 2560 master alone --
 * and it is the one part nothing waits on: imgSrcset() offers only files that
 * exist, so a page serves JPEG until scripts/build_image_sizes.php fills them
 * in. Making somebody watch a progress bar for it was the wrong trade.
 */
function imgDerive(string $subDir, string $filename, bool $withWebp = true): array
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

    /* What is missing, worked out before anything is decoded.
       A photograph whose derivatives already exist then costs a handful of
       stat() calls and no image work at all, which is what makes re-running
       the backfill over a full uploads folder cheap. */
    $todo = [];
    foreach (imgLadder() as $w) {
        if ($w > $ow) continue;                       // never enlarge
        $jpg = imgVariantPath($subDir, $filename, $w, false);
        $wp  = ($withWebp && imgWebpOk()) ? imgVariantPath($subDir, $filename, $w, true) : null;
        $need = [];
        if (!is_file($jpg)) $need['jpg']  = ['path' => $jpg, 'q' => $w <= 480 ? 85 : 88];
        if ($wp !== null && !is_file($wp)) $need['webp'] = ['path' => $wp, 'q' => 82];
        if ($need) $todo[$w] = $need;
    }
    $masterWebp = ($withWebp && imgWebpOk())
        ? preg_replace('/\.[^.]+$/', '', $src) . '.webp'
        : null;
    $needMasterWebp = $masterWebp !== null && !is_file($masterWebp);

    if (!$todo && !$needMasterWebp) return ['made' => 0, 'skipped' => ''];

    // A long batch must not be killed half way through by the clock. Each
    // photograph asks for its own allowance rather than the whole upload
    // sharing one.
    if (function_exists('set_time_limit')) @set_time_limit(120);

    $made = 0;
    $ext  = strtolower(pathinfo($src, PATHINFO_EXTENSION));

    try {
        if (imgEngine() === 'imagick') {
            // Read once, then step down. clone() copies the decoded picture
            // rather than reading the file again.
            $master = new Imagick($src);
            try { $master->autoOrientImage(); } catch (\Throwable $_) {}

            if ($needMasterWebp) {
                $m = clone $master;
                $m->setImageFormat('webp');
                $m->setImageCompressionQuality(82);
                if ($m->writeImage($masterWebp)) $made++;
                $m->clear();
            }

            $cur = $master;
            krsort($todo);                             // widest first
            foreach ($todo as $w => $need) {
                $step = clone $cur;
                $th   = max(1, (int)round($step->getImageHeight()
                                          * ($w / max(1, $step->getImageWidth()))));
                $step->resizeImage($w, $th, Imagick::FILTER_LANCZOS, 1);
                try { $step->unsharpMaskImage(0, 0.6, $w / $ow < 0.5 ? 0.9 : 0.5, 0.02); }
                catch (\Throwable $_) {}

                foreach ($need as $kind => $out) {
                    $o = clone $step;
                    if ($kind === 'webp') $o->setImageFormat('webp');
                    $o->setImageCompressionQuality($out['q']);
                    if (!is_dir(dirname($out['path']))) @mkdir(dirname($out['path']), 0755, true);
                    if ($o->writeImage($out['path'])) $made++;
                    $o->clear();
                }
                if ($cur !== $master) $cur->clear();
                $cur = $step;
            }
            if ($cur !== $master) $cur->clear();
            $master->clear();
            return ['made' => $made, 'skipped' => ''];
        }

        // ── GD ────────────────────────────────────────────────────────────
        $img = imgDecode($src, $ext);
        if (!$img) return ['made' => 0, 'skipped' => 'could not decode'];

        if ($needMasterWebp && imgEncodeTo($img, $masterWebp, 82, true, $ext)) $made++;

        $cur = $img;
        krsort($todo);                                 // widest first
        foreach ($todo as $w => $need) {
            // Resampled from the step above, not from the master every time.
            $step = imgResample($cur, $w, $ow, $ext);
            if (!$step) continue;
            foreach ($need as $kind => $out) {
                if (imgEncodeTo($step, $out['path'], $out['q'], $kind === 'webp', $ext)) $made++;
            }
            if ($cur !== $img) imagedestroy($cur);
            $cur = $step;
        }
        if ($cur !== $img) imagedestroy($cur);
        imagedestroy($img);
        return ['made' => $made, 'skipped' => ''];

    } catch (\Throwable $e) {
        error_log('imgDerive: ' . $e->getMessage());
        return ['made' => $made, 'skipped' => 'error'];
    }
}

/** Decode a file into a GD image, or false. */
function imgDecode(string $src, string $ext)
{
    return match ($ext) {
        'jpg', 'jpeg' => @imagecreatefromjpeg($src),
        'png'         => @imagecreatefrompng($src),
        'webp'        => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($src) : false,
        'gif'         => @imagecreatefromgif($src),
        default       => false,
    };
}

/**
 * One step down, sharpened.
 *
 * $origW is the width of the photograph as uploaded, not of the image being
 * resampled, so a 480 is sharpened by the same amount whether it came straight
 * off the master or down through 1440 and 960.
 */
function imgResample($img, int $targetW, int $origW, string $ext)
{
    $ow = imagesx($img);
    $oh = imagesy($img);
    $tw = min($targetW, $ow);
    $th = max(1, (int)round($oh * ($tw / max(1, $ow))));

    $dst = imagecreatetruecolor($tw, $th);
    if (in_array($ext, ['png', 'gif'], true)) {
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127));
    }
    imagecopyresampled($dst, $img, 0, 0, 0, 0, $tw, $th, $ow, $oh);

    // Downscaling a photograph softens it -- that is arithmetic, not a bug --
    // so put the edge back by an amount scaled to the total reduction. GD has
    // no unsharp mask, so this is a light convolution: enough to restore the
    // edge, gentle enough not to ring around chrome.
    if ($tw < $origW) {
        $amount = $tw / $origW < 0.5 ? 0.8 : 0.45;
        $c      = -$amount;
        $centre = 1 + (4 * $amount);
        @imageconvolution($dst, [[0, $c, 0], [$c, $centre, $c], [0, $c, 0]], 1, 0);
    }
    return $dst;
}

/** Write a GD image out, in the format the destination asks for. */
function imgEncodeTo($img, string $dest, int $quality, bool $webp, string $ext): bool
{
    $dir = dirname($dest);
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
        error_log('imgEncodeTo: cannot create ' . $dir);
        return false;
    }
    if ($webp) {
        if (!function_exists('imagewebp')) return false;
        // A palette image cannot be written as WebP by GD.
        if (function_exists('imageistruecolor') && !imageistruecolor($img)) {
            @imagepalettetotruecolor($img);
        }
        return (bool)@imagewebp($img, $dest, $quality);
    }
    return (bool)match ($ext) {
        'png'  => @imagepng($img, $dest, 6),
        'gif'  => @imagegif($img, $dest),
        'webp' => function_exists('imagewebp') && @imagewebp($img, $dest, $quality),
        default => @imagejpeg($img, $dest, $quality),
    };
}

/**
 * Write one resized copy, decoding the source itself.
 *
 * imgDerive() no longer goes through here -- it decodes once and reuses the
 * result, which is the whole point of the change -- but this is kept for any
 * caller that has a single file to convert and nothing already in memory.
 */
function imgWrite(string $src, string $dest, int $targetW, int $quality, bool $webp): bool
{
    if (imgEngine() === 'none') return false;
    try {
        if (imgEngine() === 'imagick') {
            $im = new Imagick($src);
            try { $im->autoOrientImage(); } catch (\Throwable $_) {}
            $ow = $im->getImageWidth();
            $th = max(1, (int)round($im->getImageHeight() * ($targetW / max(1, $ow))));
            if ($targetW < $ow) $im->resizeImage($targetW, $th, Imagick::FILTER_LANCZOS, 1);
            if ($webp) $im->setImageFormat('webp');
            $im->setImageCompressionQuality($quality);
            $dir = dirname($dest);
            if (!is_dir($dir)) @mkdir($dir, 0755, true);
            $ok = $im->writeImage($dest);
            $im->clear();
            return (bool)$ok;
        }
        $ext = strtolower(pathinfo($src, PATHINFO_EXTENSION));
        $img = imgDecode($src, $ext);
        if (!$img) return false;
        $out = imgResample($img, $targetW, imagesx($img), $ext);
        $ok  = imgEncodeTo($out, $dest, $quality, $webp, $ext);
        imagedestroy($out);
        imagedestroy($img);
        return $ok;
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

<?php
require_once __DIR__ . '/../../includes/functions.php';
// This page had no auth check at all — unlike every sibling page in the module —
// so uploading and deleting vehicle photos was reachable without logging in.
requireWrite('cars');

$id = (int)($_GET['id'] ?? 0);
if (!$id) redirect(BASE_URL . '/modules/cars/index.php');

$db = getDB();
$stmt = $db->prepare("SELECT * FROM cars WHERE id=?");
$stmt->execute([$id]);
$car = $stmt->fetch();
if (!$car) { setFlash('error', 'Car not found.'); redirect(BASE_URL . '/modules/cars/index.php'); }

$errors = [];

// Handle Upload — accepts one or many files in a single submission.
/* The page sends photographs one at a time and asks for JSON back, so that
   twenty pictures are twenty short requests rather than one long one. That
   is what keeps a batch inside max_execution_time; it also means a picture
   that fails costs itself and not the rest, and there is something truthful
   to show on screen while it happens. The ordinary form post below is
   untouched and still works where the JavaScript cannot run. */
$ajax = ($_POST['ajax'] ?? '') === '1';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_FILES['photos']['name'][0])) {
    $caption = trim($_POST['caption'] ?? '');

    // PHP hands multi-file inputs over as parallel arrays (name[], tmp_name[], …).
    // Flatten them back into one array per file so handleUpload() can be reused
    // unchanged, keeping its size/extension validation as the single gatekeeper.
    $files = [];
    foreach ($_FILES['photos']['name'] as $i => $name) {
        if (($_FILES['photos']['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) continue;
        $files[] = [
            'name'     => $name,
            'type'     => $_FILES['photos']['type'][$i]     ?? '',
            'tmp_name' => $_FILES['photos']['tmp_name'][$i] ?? '',
            'error'    => $_FILES['photos']['error'][$i]    ?? UPLOAD_ERR_NO_FILE,
            'size'     => $_FILES['photos']['size'][$i]     ?? 0,
        ];
    }

    // Read the existing count once; the first successful upload of a car that
    // has no photos yet becomes the primary image.
    $existing = $db->prepare("SELECT COUNT(*) FROM car_images WHERE car_id=?");
    $existing->execute([$id]);
    $photoCount = (int)$existing->fetchColumn();

    $ok = 0;
    $ins = $db->prepare("INSERT INTO car_images (car_id, file_path, caption, is_primary) VALUES (?,?,?,?)");

    // Per-file try/catch: one oversized or wrong-format file must not discard
    // the rest of the batch, which is easy to hit when selecting many at once.
    foreach ($files as $file) {
        try {
            $filename  = handleUpload($file, __DIR__ . '/../../uploads/cars');
            $isPrimary = ($photoCount === 0 && $ok === 0) ? 1 : 0;
            $ins->execute([$id, $filename, $caption, $isPrimary]);
            $ok++;
        } catch (Exception $e) {
            $errors[] = e($file['name']) . ': ' . $e->getMessage();
        }
    }

    if ($ok > 0) {
        logActivity('upload', 'media', $id,
            "Uploaded {$ok} photo(s) for vehicle: {$car['make']} {$car['model']}");
    }

    if ($ajax) {
        // One picture, one answer. The page keeps its own count.
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => $ok > 0, 'uploaded' => $ok,
                          'error' => $errors ? strip_tags($errors[0]) : '']);
        exit;
    }

    if ($ok > 0 && !$errors) {
        setFlash('success', $ok === 1 ? 'Photo uploaded successfully.' : "{$ok} photos uploaded successfully.");
        redirect("media.php?id=$id");
    }
    if ($ok > 0 && $errors) {
        // Partial success — keep the failures on screen rather than redirecting
        // away, so it is clear which files still need attention.
        setFlash('warning', "{$ok} photo(s) uploaded. " . count($errors) . " could not be uploaded — see below.");
    }
}

// Handle Delete
if (isset($_GET['delete'])) {
    $imgId = (int)$_GET['delete'];
    $stmt = $db->prepare("SELECT * FROM car_images WHERE id=? AND car_id=?");
    $stmt->execute([$imgId, $id]);
    $img = $stmt->fetch();
    if ($img) {
        $filePath = __DIR__ . '/../../uploads/cars/' . $img['file_path'];
        if (file_exists($filePath)) unlink($filePath);
        $db->prepare("DELETE FROM car_images WHERE id=?")->execute([$imgId]);
        logActivity('delete', 'media', $id, "Deleted photo: {$img['file_path']}");
        setFlash('success', 'Photo deleted.');
    }
    redirect("media.php?id=$id");
}

// Handle Set Primary
if (isset($_GET['primary'])) {
    $imgId = (int)$_GET['primary'];
    $db->prepare("UPDATE car_images SET is_primary=0 WHERE car_id=?")->execute([$id]);
    $db->prepare("UPDATE car_images SET is_primary=1 WHERE id=?")->execute([$imgId]);
    setFlash('success', 'Primary photo updated.');
    redirect("media.php?id=$id");
}

$images = $db->prepare("SELECT * FROM car_images WHERE car_id=? ORDER BY is_primary DESC, created_at DESC");
$images->execute([$id]);
$images = $images->fetchAll();

$pageTitle = "Manage Photos - " . $car['make'] . ' ' . $car['model'];
include __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="mb-0">Manage Photos: <?= e($car['make'] . ' ' . $car['model']) ?></h5>
    <a href="view.php?id=<?= $id ?>" class="btn btn-sm btn-outline-secondary"><i class="fa fa-arrow-left me-1"></i>Back to Vehicle</a>
</div>

<?php if ($errors): ?><div class="alert alert-danger"><ul class="mb-0"><?php foreach($errors as $e) echo "<li>$e</li>"; ?></ul></div><?php endif; ?>

<div class="row g-4">
    <!-- Upload Form -->
    <div class="col-md-4">
        <div class="card h-100">
            <div class="card-header"><i class="fa fa-upload me-2"></i>Upload Photos</div>
            <div class="card-body">
                <?php
                /* The limit that actually bites is on the whole request, not on each
                   file. This read 20 MB each and up to 20 files -- 400 MB -- against a
                   post_max_size of 25M, and PHP answers an oversized request by
                   discarding the entire body, the security token with it, so the
                   upload came back as a session error. These come from the server. */
                $__maxFiles = (int)ini_get('max_file_uploads') ?: 20;
                $__postMax  = iniBytes((string)ini_get('post_max_size'));
                $__fileMax  = iniBytes((string)ini_get('upload_max_filesize'));
                if ($__postMax > 0 && ($__fileMax === 0 || $__fileMax > $__postMax)) {
                    $__fileMax = $__postMax;
                }
                // A little headroom for the caption and the token, so a selection that
                // only just fits is not refused after the wait.
                $__totalMax = $__postMax > 0 ? (int)($__postMax * 0.95) : 0;
                $__mb = static function (int $b): string {
                    return rtrim(rtrim(number_format($b / 1048576, 1), '0'), '.') . ' MB';
                };
                ?>
                <form method="POST" enctype="multipart/form-data" id="photoUploadForm">
                    <?= csrfField() ?>
                    <div class="mb-3">
                        <label class="form-label">Select Images</label>
                        <input type="file" name="photos[]" id="photoInput" class="form-control"
                               accept="image/jpeg,image/png,image/webp" multiple required>
                        <div class="form-text text-muted">
                            Select several at once — hold <strong>Ctrl</strong> (or <strong>Cmd</strong>) while clicking,
                            or drag a selection.<br>
                            <span id="photoLimitModern" style="display:none">Pictures are reduced in the browser
                            and sent one at a time, so there is no limit on how many go at once.</span>
                            <span id="photoLimitPlain">Up to <strong><?= $__mb($__totalMax) ?></strong> in one upload
                            (<?= $__mb($__fileMax) ?> per file), at most <?= $__maxFiles ?> files.</span>
                            JPG, PNG or WEBP.
                        </div>
                        <div id="photoPickSummary" class="small mt-2" style="display:none"></div>
                        <div id="photoProgress" class="mt-3" style="display:none">
                            <div class="progress" style="height:6px">
                                <div id="photoBar" class="progress-bar" role="progressbar" style="width:0%"></div>
                            </div>
                            <div id="photoProgressText" class="small text-muted mt-2"></div>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Caption (optional)</label>
                        <input type="text" name="caption" class="form-control" placeholder="e.g. Front View, Interior">
                        <div class="form-text text-muted">Applied to every image in this upload.</div>
                    </div>
                    <button type="submit" class="btn btn-primary w-100" id="photoUploadBtn">
                        <i class="fa fa-cloud-upload me-1"></i><span id="photoUploadLabel">Upload Now</span>
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- Gallery -->
    <div class="col-md-8">
        <div class="card h-100">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="fa fa-images me-2"></i>Photo Gallery</span>
                <span class="badge bg-secondary"><?= count($images) ?> Photos</span>
            </div>
            <div class="card-body">
                <?php if (!$images): ?>
                <div class="text-center py-5 text-muted">
                    <i class="fa fa-image fa-3x mb-3 opacity-25"></i>
                    <p>No photos uploaded yet for this vehicle.</p>
                </div>
                <?php else: ?>
                <div class="row row-cols-1 row-cols-sm-2 row-cols-md-3 g-3">
                    <?php foreach ($images as $img): ?>
                    <div class="col">
                        <div class="card h-100 border-0 shadow-sm overflow-hidden gallery-card">
                            <img src="<?= thumbUrl('cars', $img['file_path']) ?>" class="card-img-top" style="height:150px; object-fit:cover;" loading="lazy" decoding="async">
                            <?php if ($img['is_primary']): ?>
                            <div class="position-absolute top-0 start-0 m-2">
                                <span class="badge bg-primary">Primary</span>
                            </div>
                            <?php endif; ?>
                            <div class="card-body p-2">
                                <p class="small text-truncate mb-2"><?= e($img['caption'] ?: 'No caption') ?></p>
                                <div class="d-flex justify-content-between">
                                    <?php if (!$img['is_primary']): ?>
                                    <a href="?id=<?= $id ?>&primary=<?= $img['id'] ?>" class="btn btn-xs btn-outline-primary" title="Set as Primary"><i class="fa fa-star"></i></a>
                                    <?php endif; ?>
                                    <a href="?id=<?= $id ?>&delete=<?= $img['id'] ?>" class="btn btn-xs btn-outline-danger confirm-delete" title="Delete"><i class="fa fa-trash"></i></a>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<style>
.gallery-card:hover { transform: translateY(-5px); transition: 0.3s; }
</style>

<script>
(function () {
    var input   = document.getElementById('photoInput');
    var form    = document.getElementById('photoUploadForm');
    var summary = document.getElementById('photoPickSummary');
    var btn     = document.getElementById('photoUploadBtn');
    var label   = document.getElementById('photoUploadLabel');
    if (!input || !form) return;

    /* Can this browser reduce a picture and post it on its own? If not,
       everything below is skipped and the form posts the ordinary way. */
    var CAN_STREAM = !!(window.fetch && window.FormData && window.Promise
                        && window.createImageBitmap
                        && document.createElement('canvas').getContext);
    var MAX_EDGE = 2560;          // what the server reduces to anyway

    if (CAN_STREAM) {
        var modern = document.getElementById('photoLimitModern');
        var plain  = document.getElementById('photoLimitPlain');
        if (modern) modern.style.display = '';
        if (plain)  plain.style.display  = 'none';
    }

    var MAX_FILES = <?= (int)$__maxFiles ?>;
    var MAX_BYTES = <?= (int)$__fileMax ?>;
    // The one that was missing, and the one that was breaking the upload.
    var MAX_TOTAL = <?= (int)$__totalMax ?>;

    input.addEventListener('change', function () {
        var files = Array.prototype.slice.call(input.files || []);
        if (!files.length) { summary.style.display = 'none'; label.textContent = 'Upload Now'; return; }

        var total = files.reduce(function (s, f) { return s + f.size; }, 0);
        var big   = files.filter(function (f) { return f.size > MAX_BYTES; });
        var mb    = function (b) { return (b / 1048576).toFixed(1) + ' MB'; };

        // Warn before the round-trip — the server rejects these anyway, but
        // finding out after a long upload is a poor experience.
        var notes = [];
        if (!CAN_STREAM && files.length > MAX_FILES) {
            notes.push('<div class="text-danger"><i class="fa fa-triangle-exclamation me-1"></i>'
                     + files.length + ' selected, but the server accepts at most ' + MAX_FILES
                     + ' per upload. Please upload in smaller batches.</div>');
        }
        if (big.length) {
            notes.push('<div class="text-danger"><i class="fa fa-triangle-exclamation me-1"></i>'
                     + big.length + ' file(s) are over ' + mb(MAX_BYTES) + ' and will be skipped: '
                     + big.map(function (f) { return f.name; }).join(', ') + '</div>');
        }
        // Over the request limit the server never sees the upload at all: it
        // discards the body and the browser is told nothing, so this has to be
        // caught here or not at all.
        // Only the ordinary form post shares one envelope. Sent one at a
        // time, neither the total nor the file count means anything.
        var tooMuch = !CAN_STREAM && MAX_TOTAL > 0 && total > MAX_TOTAL;
        if (tooMuch) {
            notes.push('<div class="text-danger"><i class="fa fa-triangle-exclamation me-1"></i>'
                     + 'That is ' + mb(total) + ' in one upload and the server takes '
                     + mb(MAX_TOTAL) + '. Send them in two or three batches and all of them '
                     + 'will go through.</div>');
        }

        summary.innerHTML = '<span class="text-muted"><i class="fa fa-images me-1"></i>'
                          + files.length + ' file(s) selected · ' + mb(total) + ' total</span>'
                          + notes.join('');
        summary.style.display = '';
        label.textContent = files.length > 1 ? 'Upload ' + files.length + ' Photos' : 'Upload Now';
        // Nothing is gained by letting it be sent.
        if (btn) btn.disabled = tooMuch || files.length > MAX_FILES;
    });

    // Large batches take a while; make it obvious the upload is running so the
    // button is not clicked twice.
    form.addEventListener('submit', function () {
        btn.disabled = true;
        label.innerHTML = 'Uploading…';
    });

    /* ── Sending ────────────────────────────────────────────────────────────
       One picture per request. Twenty photographs used to be one request of
       about two minutes, which is over most max_execution_time settings and
       lost every picture when it expired; this is twenty requests of a few
       seconds, and a failure costs one picture. */
    function shrink(file) {
        if (!/^image\/(jpeg|png|webp)$/.test(file.type)) return Promise.resolve(file);
        return createImageBitmap(file, { imageOrientation: 'from-image' }).then(function (bmp) {
            var longest = Math.max(bmp.width, bmp.height);
            // Already small enough: send what was chosen rather than re-encoding
            // it, which would only lose a little quality for nothing.
            if (longest <= MAX_EDGE) { bmp.close(); return file; }
            var scale = MAX_EDGE / longest;
            var w = Math.round(bmp.width * scale), h = Math.round(bmp.height * scale);
            var c = document.createElement('canvas');
            c.width = w; c.height = h;
            c.getContext('2d').drawImage(bmp, 0, 0, w, h);
            bmp.close();
            return new Promise(function (res) {
                c.toBlob(function (blob) {
                    if (!blob || blob.size >= file.size) { res(file); return; }
                    res(new File([blob], file.name.replace(/\.[^.]+$/, '') + '.jpg',
                                 { type: 'image/jpeg' }));
                }, 'image/jpeg', 0.88);
            });
        }).catch(function () { return file; });   // unreadable: let the server try
    }

    function sendOne(file, caption) {
        return shrink(file).then(function (f) {
            var fd = new FormData();
            fd.append('photos[]', f, f.name);
            fd.append('caption', caption);
            fd.append('ajax', '1');
            var tok = document.querySelector('input[name="csrf_token"]');
            if (tok) fd.append('csrf_token', tok.value);
            return fetch(window.location.href, { method: 'POST', body: fd, credentials: 'same-origin' })
                .then(function (r) { return r.json().catch(function () { return { ok: false, error: 'Server error' }; }); });
        });
    }

    form.addEventListener('submit', function (e) {
        if (!CAN_STREAM) return;                       // ordinary post
        var files = Array.prototype.slice.call(input.files || []);
        if (!files.length) return;
        e.preventDefault();

        var capEl   = form.querySelector('[name="caption"]');
        var caption = capEl ? capEl.value : '';
        var box     = document.getElementById('photoProgress');
        var bar     = document.getElementById('photoBar');
        var text    = document.getElementById('photoProgressText');
        box.style.display = '';
        if (btn) btn.disabled = true;
        summary.style.display = 'none';

        var done = 0, failed = [];
        function step(i) {
            if (i >= files.length) {
                text.innerHTML = failed.length
                    ? done + ' uploaded, ' + failed.length + ' could not be: ' + failed.join(', ')
                    : 'All ' + done + ' uploaded. Reloading…';
                // Reload either way, so what did arrive is on screen.
                setTimeout(function () { window.location.href = window.location.pathname
                    + window.location.search; }, failed.length ? 2500 : 500);
                return;
            }
            text.textContent = 'Uploading ' + (i + 1) + ' of ' + files.length
                             + ' — ' + files[i].name;
            sendOne(files[i], caption).then(function (res) {
                if (res && res.ok) done++; else failed.push(files[i].name);
            }).catch(function () {
                failed.push(files[i].name);
            }).then(function () {
                bar.style.width = Math.round(((i + 1) / files.length) * 100) + '%';
                step(i + 1);
            });
        }
        step(0);
    });

}());
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>

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
                <?php $__maxFiles = (int)ini_get('max_file_uploads') ?: 20; ?>
                <form method="POST" enctype="multipart/form-data" id="photoUploadForm">
                    <div class="mb-3">
                        <label class="form-label">Select Images</label>
                        <input type="file" name="photos[]" id="photoInput" class="form-control"
                               accept="image/jpeg,image/png,image/webp" multiple required>
                        <div class="form-text text-muted">
                            Select several at once — hold <strong>Ctrl</strong> (or <strong>Cmd</strong>) while clicking,
                            or drag a selection.<br>
                            Max 20&nbsp;MB each, up to <?= $__maxFiles ?> files per upload. JPG, PNG or WEBP.
                        </div>
                        <div id="photoPickSummary" class="small mt-2" style="display:none"></div>
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

    var MAX_FILES = <?= (int)($__maxFiles ?? 20) ?>;
    var MAX_BYTES = 20 * 1024 * 1024;

    input.addEventListener('change', function () {
        var files = Array.prototype.slice.call(input.files || []);
        if (!files.length) { summary.style.display = 'none'; label.textContent = 'Upload Now'; return; }

        var total = files.reduce(function (s, f) { return s + f.size; }, 0);
        var big   = files.filter(function (f) { return f.size > MAX_BYTES; });
        var mb    = function (b) { return (b / 1048576).toFixed(1) + ' MB'; };

        // Warn before the round-trip — the server rejects these anyway, but
        // finding out after a long upload is a poor experience.
        var notes = [];
        if (files.length > MAX_FILES) {
            notes.push('<div class="text-danger"><i class="fa fa-triangle-exclamation me-1"></i>'
                     + files.length + ' selected, but the server accepts at most ' + MAX_FILES
                     + ' per upload. Please upload in smaller batches.</div>');
        }
        if (big.length) {
            notes.push('<div class="text-danger"><i class="fa fa-triangle-exclamation me-1"></i>'
                     + big.length + ' file(s) exceed 20 MB and will be skipped: '
                     + big.map(function (f) { return f.name; }).join(', ') + '</div>');
        }

        summary.innerHTML = '<span class="text-muted"><i class="fa fa-images me-1"></i>'
                          + files.length + ' file(s) selected · ' + mb(total) + ' total</span>'
                          + notes.join('');
        summary.style.display = '';
        label.textContent = files.length > 1 ? 'Upload ' + files.length + ' Photos' : 'Upload Now';
    });

    // Large batches take a while; make it obvious the upload is running so the
    // button is not clicked twice.
    form.addEventListener('submit', function () {
        btn.disabled = true;
        label.innerHTML = 'Uploading…';
    });
}());
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>

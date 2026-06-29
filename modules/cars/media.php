<?php
require_once __DIR__ . '/../../includes/functions.php';
$id = (int)($_GET['id'] ?? 0);
if (!$id) redirect(BASE_URL . '/modules/cars/index.php');

$db = getDB();
$stmt = $db->prepare("SELECT * FROM cars WHERE id=?");
$stmt->execute([$id]);
$car = $stmt->fetch();
if (!$car) { setFlash('error', 'Car not found.'); redirect(BASE_URL . '/modules/cars/index.php'); }

$errors = [];

// Handle Upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['photo'])) {
    try {
        $filename = handleUpload($_FILES['photo'], __DIR__ . '/../../uploads/cars');
        $caption = trim($_POST['caption'] ?? '');
        
        // If it's the first photo, make it primary
        $existing = $db->prepare("SELECT COUNT(*) FROM car_images WHERE car_id=?");
        $existing->execute([$id]);
        $isPrimary = $existing->fetchColumn() == 0 ? 1 : 0;

        $db->prepare("INSERT INTO car_images (car_id, file_path, caption, is_primary) VALUES (?,?,?,?)")
           ->execute([$id, $filename, $caption, $isPrimary]);
        
        logActivity('upload', 'media', $id, "Uploaded photo for vehicle: {$car['make']} {$car['model']} ($filename)");
        setFlash('success', 'Photo uploaded successfully.');
        redirect("media.php?id=$id");
    } catch (Exception $e) {
        $errors[] = $e->getMessage();
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
            <div class="card-header"><i class="fa fa-upload me-2"></i>Upload Photo</div>
            <div class="card-body">
                <form method="POST" enctype="multipart/form-data">
                    <div class="mb-3">
                        <label class="form-label">Select Image</label>
                        <input type="file" name="photo" class="form-control" accept="image/*" required>
                        <div class="form-text text-muted">Max 20MB. Allowed: JPG, PNG, WEBP.</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Caption (optional)</label>
                        <input type="text" name="caption" class="form-control" placeholder="e.g. Front View, Interior">
                    </div>
                    <button type="submit" class="btn btn-primary w-100"><i class="fa fa-cloud-upload me-1"></i>Upload Now</button>
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

<?php include __DIR__ . '/../../includes/footer.php'; ?>

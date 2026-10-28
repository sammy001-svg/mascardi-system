<?php
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/_geo.php';
requireRole(['admin', 'manager']);
$db = getDB();
locationGeoMigrate($db);

$id = (int)($_GET['id'] ?? 0);
$stmt = $db->prepare("SELECT * FROM locations WHERE id = ?");
$stmt->execute([$id]);
$location = $stmt->fetch();

if (!$location) {
    setFlash('error', 'Location not found.');
    redirect('index.php');
}

$pageTitle = 'Edit Location: ' . $location['name'];

// Valid parent options: top-level locations only, excluding self
$parentOptions = $db->prepare("
    SELECT id, name FROM locations
    WHERE id <> ? AND (parent_id IS NULL OR parent_id = 0)
    ORDER BY name ASC
");
$parentOptions->execute([$id]);
$parentOptions = $parentOptions->fetchAll();

// Does this location have sub-locations? If so, it can't become a sub-location itself.
$hasChildren = (bool)$db->prepare("SELECT COUNT(*) FROM locations WHERE parent_id = ?")->execute([$id])
              && (int)$db->query("SELECT COUNT(*) FROM locations WHERE parent_id = {$id}")->fetchColumn() > 0;

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name     = trim($_POST['name']       ?? '');
    $type     = $_POST['type']            ?? 'yard';
    $address  = trim($_POST['address']    ?? '');
    $phone    = trim($_POST['phone']      ?? '');
    $status   = $_POST['status']          ?? 'active';
    $parentId = (int)($_POST['parent_id'] ?? 0) ?: null;
    // Stored only as a usable pair. Half a coordinate is not a place, and a
    // blank field arrives as '' rather than null.
    $lat      = trim((string)($_POST['latitude']  ?? ''));
    $lng      = trim((string)($_POST['longitude'] ?? ''));
    $hasGeo   = locationGeoValid($lat, $lng);
    if (!$hasGeo && ($lat !== '' || $lng !== '')) {
        $errors[] = 'A map pin needs both a latitude and a longitude, within range and not 0,0. Leave both empty for no pin.';
    }

    if (!$name) {
        $errors[] = 'Location name is required.';
    }
    if ($parentId && $hasChildren) {
        $errors[] = 'This location has sub-locations and cannot itself become a sub-location. Remove its sub-locations first.';
        $parentId = null;
    }
    if ($parentId && $parentId === $id) {
        $errors[] = 'A location cannot be its own parent.';
        $parentId = null;
    }

    if (!$errors) {
        $db->prepare("
            UPDATE locations
            SET name=?, type=?, address=?, phone=?, status=?, parent_id=?,
                latitude=?, longitude=?
            WHERE id=?
        ")->execute([$name, $type, $address, $phone, $status, $parentId,
                     $hasGeo ? (float)$lat : null, $hasGeo ? (float)$lng : null, $id]);

        logActivity('update', 'locations', $id, 'Updated location: ' . $name);
        setFlash('success', 'Location updated successfully.');
        redirect('index.php');
    }
}

// Populate form from POST on validation error, otherwise from DB
$form = $errors ? $_POST : $location;
include __DIR__ . '/../../includes/header.php';
?>

<div class="mb-4">
    <a href="index.php" class="btn btn-xs btn-outline-secondary mb-3">
        <i class="fa fa-arrow-left me-1"></i>Back to Locations
    </a>
    <h5 class="mb-0">
        <i class="fa fa-pen me-2 text-primary"></i>Edit Location: <?= e($location['name']) ?>
    </h5>
    <?php if (!empty($location['parent_id'])): ?>
    <div class="text-muted small mt-1">
        <?php
        $pRow = $db->prepare("SELECT name FROM locations WHERE id=?");
        $pRow->execute([$location['parent_id']]);
        $pName = $pRow->fetchColumn();
        ?>
        <i class="fa fa-sitemap me-1"></i>Sub-location of <strong><?= e($pName ?: 'Unknown') ?></strong>
    </div>
    <?php endif; ?>
</div>

<?php foreach ($errors as $err): ?>
<div class="alert alert-danger py-2"><?= $err ?></div>
<?php endforeach; ?>

<div class="row">
    <div class="col-lg-6 col-md-8">
        <div class="card shadow-sm border-0">
            <div class="card-body p-4">
                <form method="POST">

                    <!-- Parent Location -->
                    <div class="mb-4 pb-4 border-bottom">
                        <label class="form-label fw-semibold">Parent Location</label>
                        <?php if ($hasChildren): ?>
                        <div class="alert alert-info py-2 mb-2" style="font-size:13px">
                            <i class="fa fa-info-circle me-1"></i>
                            This location has sub-locations — it cannot be moved under another location.
                        </div>
                        <input type="hidden" name="parent_id" value="">
                        <?php else: ?>
                        <select name="parent_id" class="form-select">
                            <option value="">— Top-level location (no parent) —</option>
                            <?php foreach ($parentOptions as $p): ?>
                            <option value="<?= $p['id'] ?>"
                                <?= ((int)($form['parent_id'] ?? 0) === (int)$p['id']) ? 'selected' : '' ?>>
                                <?= e($p['name']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text">Change or clear the parent to move this location in the hierarchy.</div>
                        <?php endif; ?>
                    </div>

                    <!-- Name -->
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Location Name <span class="text-danger">*</span></label>
                        <input type="text" name="name" class="form-control"
                               value="<?= e($form['name'] ?? '') ?>" required>
                    </div>

                    <!-- Type -->
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Type</label>
                        <select name="type" class="form-select">
                            <?php
                            $types = ['yard' => 'Storage', 'showroom' => 'Showroom', 'port' => 'Port', 'office' => 'Office'];
                            foreach ($types as $v => $label): ?>
                            <option value="<?= $v ?>" <?= ($form['type'] ?? '') === $v ? 'selected' : '' ?>>
                                <?= $label ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Phone -->
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Phone Number</label>
                        <input type="text" name="phone" class="form-control"
                               value="<?= e($form['phone'] ?? '') ?>" placeholder="+254 …">
                    </div>

                    <!-- Status -->
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Status</label>
                        <select name="status" class="form-select">
                            <option value="active"   <?= ($form['status'] ?? '') === 'active'   ? 'selected' : '' ?>>Active</option>
                            <option value="inactive" <?= ($form['status'] ?? '') === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                        </select>
                    </div>

                    <!-- Address -->
                    <div class="mb-4">
                        <label class="form-label fw-semibold">Address / Description</label>
                        <textarea name="address" class="form-control" rows="3"><?= e($form['address'] ?? '') ?></textarea>
                    </div>

                    <?php /* A pin, not a geocoded address. The addresses in this table
                             read like "off Mombasa Road, opposite the Shell", which no
                             geocoder resolves, and one that could would want an API key
                             for a figure that changes about once a year. Blank is fine:
                             the map is simply not drawn, and the address still shows. */ ?>
                    <div class="mb-4">
                        <label class="form-label fw-semibold">Map pin <span class="text-muted fw-normal small">(optional)</span></label>
                        <div class="row g-2">
                            <div class="col-sm-6">
                                <input type="number" step="any" name="latitude" class="form-control"
                                       value="<?= e((string)($form['latitude'] ?? '')) ?>" placeholder="Latitude, e.g. -1.319170">
                            </div>
                            <div class="col-sm-6">
                                <input type="number" step="any" name="longitude" class="form-control"
                                       value="<?= e((string)($form['longitude'] ?? '')) ?>" placeholder="Longitude, e.g. 36.825100">
                            </div>
                        </div>
                        <div class="form-text">
                            Shown as a map to customers looking at a vehicle kept here. To find the
                            numbers: open the place in Google Maps, right-click the exact spot and
                            click the latitude/longitude it offers, then paste. Leave both empty and
                            customers are given the address only.
                        </div>
                    </div>

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary flex-fill">
                            <i class="fa fa-save me-1"></i>Update Location
                        </button>
                        <a href="index.php" class="btn btn-outline-secondary">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>

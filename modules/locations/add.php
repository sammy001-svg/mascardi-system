<?php
require_once __DIR__ . '/../../includes/functions.php';
requireRole(['admin', 'manager']);
$pageTitle = 'Add Location';
$db = getDB();

// Pre-selected parent from index "+" button
$preParentId = (int)($_GET['parent_id'] ?? 0);

// Fetch top-level locations only as parent options (no infinite nesting)
$parentOptions = $db->query("
    SELECT id, name FROM locations
    WHERE (parent_id IS NULL OR parent_id = 0) AND status = 'active'
    ORDER BY name ASC
")->fetchAll();

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name     = trim($_POST['name']      ?? '');
    $type     = $_POST['type']           ?? 'yard';
    $address  = trim($_POST['address']   ?? '');
    $phone    = trim($_POST['phone']     ?? '');
    $parentId = (int)($_POST['parent_id'] ?? 0) ?: null;

    if (!$name) {
        $errors[] = 'Location name is required.';
    }

    if (!$errors) {
        $db->prepare("
            INSERT INTO locations (name, type, address, phone, parent_id)
            VALUES (?, ?, ?, ?, ?)
        ")->execute([$name, $type, $address, $phone, $parentId]);
        $newId = (int)$db->lastInsertId();

        logActivity('create', 'locations', $newId, 'Added location: ' . $name . ($parentId ? ' (sub-location)' : ''));
        setFlash('success', 'Location <strong>' . htmlspecialchars($name) . '</strong> added successfully.');
        redirect('index.php');
    }
}

$post = $_POST;
include __DIR__ . '/../../includes/header.php';
?>

<div class="mb-4">
    <a href="index.php" class="btn btn-xs btn-outline-secondary mb-3">
        <i class="fa fa-arrow-left me-1"></i>Back to Locations
    </a>
    <h5 class="mb-0">
        <i class="fa fa-plus-circle me-2 text-primary"></i>
        <?= $preParentId ? 'Add Sub-location' : 'Add New Location' ?>
    </h5>
    <?php if ($preParentId):
        $pRow = array_filter($parentOptions, fn($p) => $p['id'] == $preParentId);
        $pRow = reset($pRow);
    ?>
    <div class="text-muted small mt-1">
        <i class="fa fa-sitemap me-1"></i>Sub-location of <strong><?= $pRow ? e($pRow['name']) : "Location #{$preParentId}" ?></strong>
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
                    <?php if ($parentOptions): ?>
                    <!-- Parent Location -->
                    <div class="mb-4 pb-4 border-bottom">
                        <label class="form-label fw-semibold">
                            Parent Location
                            <span class="text-muted fw-normal small ms-1">(optional — leave blank for top-level)</span>
                        </label>
                        <select name="parent_id" class="form-select">
                            <option value="">— Top-level location (no parent) —</option>
                            <?php foreach ($parentOptions as $p): ?>
                            <option value="<?= $p['id'] ?>"
                                <?= ((int)($post['parent_id'] ?? $preParentId) === (int)$p['id']) ? 'selected' : '' ?>>
                                <?= e($p['name']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text">Selecting a parent makes this a sub-location (e.g. "Bay A" under "Main Yard").</div>
                    </div>
                    <?php endif; ?>

                    <!-- Name -->
                    <div class="mb-3">
                        <label class="form-label fw-semibold">
                            Location Name <span class="text-danger">*</span>
                        </label>
                        <input type="text" name="name" class="form-control"
                               value="<?= e($post['name'] ?? '') ?>"
                               placeholder="<?= $preParentId ? 'e.g. Section A, Bay 1, North Wing…' : 'e.g. Nairobi HQ, Mombasa Yard…' ?>"
                               required autofocus>
                    </div>

                    <!-- Type -->
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Type</label>
                        <select name="type" class="form-select">
                            <?php
                            $types = ['yard' => 'Yard / Storage', 'showroom' => 'Showroom', 'port' => 'Port', 'office' => 'Office'];
                            foreach ($types as $v => $label): ?>
                            <option value="<?= $v ?>" <?= ($post['type'] ?? 'yard') === $v ? 'selected' : '' ?>>
                                <?= $label ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Phone -->
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Phone Number</label>
                        <input type="text" name="phone" class="form-control"
                               value="<?= e($post['phone'] ?? '') ?>"
                               placeholder="+254 …">
                    </div>

                    <!-- Address -->
                    <div class="mb-4">
                        <label class="form-label fw-semibold">Address / Description</label>
                        <textarea name="address" class="form-control" rows="3"
                                  placeholder="Physical location details, landmarks…"><?= e($post['address'] ?? '') ?></textarea>
                    </div>

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary flex-fill">
                            <i class="fa fa-save me-1"></i>Save Location
                        </button>
                        <a href="index.php" class="btn btn-outline-secondary">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>

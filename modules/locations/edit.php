<?php
require_once __DIR__ . '/../../includes/functions.php';
requireRole(['admin', 'manager']);
$db = getDB();

$id = (int)($_GET['id'] ?? 0);
$stmt = $db->prepare("SELECT * FROM locations WHERE id=?");
$stmt->execute([$id]);
$location = $stmt->fetch();

if (!$location) {
    setFlash('error', 'Location not found.');
    redirect('index.php');
}

$pageTitle = 'Edit Location: ' . $location['name'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name    = trim($_POST['name'] ?? '');
    $type    = $_POST['type'] ?? 'yard';
    $address = trim($_POST['address'] ?? '');
    $phone   = trim($_POST['phone'] ?? '');
    $status  = $_POST['status'] ?? 'active';

    if (!$name) {
        setFlash('error', 'Location name is required.');
    } else {
        $stmt = $db->prepare("UPDATE locations SET name=?, type=?, address=?, phone=?, status=? WHERE id=?");
        $stmt->execute([$name, $type, $address, $phone, $status, $id]);
        
        logActivity('update', 'locations', $id, "Updated location: $name");
        setFlash('success', 'Location updated successfully.');
        redirect('index.php');
    }
}

include __DIR__ . '/../../includes/header.php';
?>

<div class="mb-4">
    <a href="index.php" class="btn btn-xs btn-outline-secondary mb-2"><i class="fa fa-arrow-left me-1"></i>Back to List</a>
    <h5><i class="fa fa-edit me-2 text-primary"></i>Edit Location: <?= e($location['name']) ?></h5>
</div>

<div class="row">
    <div class="col-md-6">
        <div class="card shadow-sm border-0">
            <div class="card-body p-4">
                <form method="POST">
                    <div class="mb-3">
                        <label class="form-label">Location Name <span class="text-danger">*</span></label>
                        <input type="text" name="name" class="form-control" value="<?= e($location['name']) ?>" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Type</label>
                        <select name="type" class="form-select">
                            <option value="yard" <?= $location['type'] === 'yard' ? 'selected' : '' ?>>Yard / Storage</option>
                            <option value="showroom" <?= $location['type'] === 'showroom' ? 'selected' : '' ?>>Showroom</option>
                            <option value="port" <?= $location['type'] === 'port' ? 'selected' : '' ?>>Port</option>
                            <option value="office" <?= $location['type'] === 'office' ? 'selected' : '' ?>>Office</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Phone Number</label>
                        <input type="text" name="phone" class="form-control" value="<?= e($location['phone']) ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Status</label>
                        <select name="status" class="form-select">
                            <option value="active" <?= $location['status'] === 'active' ? 'selected' : '' ?>>Active</option>
                            <option value="inactive" <?= $location['status'] === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                        </select>
                    </div>
                    <div class="mb-4">
                        <label class="form-label">Address</label>
                        <textarea name="address" class="form-control" rows="3"><?= e($location['address']) ?></textarea>
                    </div>
                    <div class="d-grid">
                        <button type="submit" class="btn btn-primary">Update Location</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>

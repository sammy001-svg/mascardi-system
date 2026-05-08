<?php
require_once __DIR__ . '/../../includes/functions.php';
requireRole(['admin', 'manager']);
$pageTitle = 'Add Location';
$db = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name    = trim($_POST['name'] ?? '');
    $type    = $_POST['type'] ?? 'yard';
    $address = trim($_POST['address'] ?? '');
    $phone   = trim($_POST['phone'] ?? '');

    if (!$name) {
        setFlash('error', 'Location name is required.');
    } else {
        $stmt = $db->prepare("INSERT INTO locations (name, type, address, phone) VALUES (?, ?, ?, ?)");
        $stmt->execute([$name, $type, $address, $phone]);
        $newId = $db->lastInsertId();
        
        logActivity('create', 'locations', $newId, "Added new location: $name");
        setFlash('success', 'Location added successfully.');
        redirect('index.php');
    }
}

include __DIR__ . '/../../includes/header.php';
?>

<div class="mb-4">
    <a href="index.php" class="btn btn-xs btn-outline-secondary mb-2"><i class="fa fa-arrow-left me-1"></i>Back to List</a>
    <h5><i class="fa fa-plus-circle me-2 text-primary"></i>Add New Location</h5>
</div>

<div class="row">
    <div class="col-md-6">
        <div class="card shadow-sm border-0">
            <div class="card-body p-4">
                <form method="POST">
                    <div class="mb-3">
                        <label class="form-label">Location Name <span class="text-danger">*</span></label>
                        <input type="text" name="name" class="form-control" placeholder="e.g. Nairobi HQ" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Type</label>
                        <select name="type" class="form-select">
                            <option value="yard">Yard / Storage</option>
                            <option value="showroom">Showroom</option>
                            <option value="port">Port</option>
                            <option value="office">Office</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Phone Number</label>
                        <input type="text" name="phone" class="form-control" placeholder="+254 ...">
                    </div>
                    <div class="mb-4">
                        <label class="form-label">Address</label>
                        <textarea name="address" class="form-control" rows="3" placeholder="Physical location details..."></textarea>
                    </div>
                    <div class="d-grid">
                        <button type="submit" class="btn btn-primary">Save Location</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>

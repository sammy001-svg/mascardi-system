<?php
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
canWrite('car_documents') || redirect(BASE_URL . '/index.php');

$pageTitle = 'Upload Document';
$db     = getDB();
$errors = [];

// Pre-select car from GET
$preCarId = (int)($_GET['car_id'] ?? 0);
$preCar   = null;
if ($preCarId) {
    $s = $db->prepare("SELECT id, make, model, chassis_number FROM cars WHERE id=?");
    $s->execute([$preCarId]);
    $preCar = $s->fetch();
}

$docTypes = [
    'logbook'           => 'Logbook',
    'import_entry'      => 'Import Entry Declaration',
    'ntsa_inspection'   => 'NTSA Inspection Certificate',
    'ntsa_registration' => 'NTSA Registration Certificate',
    'insurance'         => 'Insurance Certificate',
    'duty_clearance'    => 'Customs Duty Clearance',
    'purchase_invoice'  => 'Purchase Invoice',
    'other'             => 'Other',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $carId      = (int)($_POST['car_id']      ?? 0);
    $docType    = trim($_POST['doc_type']     ?? '');
    $title      = trim($_POST['title']        ?? '');
    $expiryDate = trim($_POST['expiry_date']  ?? '') ?: null;
    $notes      = trim($_POST['notes']        ?? '') ?: null;
    $back       = $_POST['back'] ?? BASE_URL . '/modules/car_documents/index.php';

    if (!$carId)                             $errors[] = 'Please select a vehicle.';
    if (!$docType || !isset($docTypes[$docType])) $errors[] = 'Please select a document type.';
    if (!$title)                             $errors[] = 'Document title is required.';
    if (empty($_FILES['document']['name']))  $errors[] = 'Please select a file to upload.';

    if (empty($errors)) {
        $file    = $_FILES['document'];
        $origName = basename($file['name']);
        $ext      = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
        $allowed  = ['pdf','jpg','jpeg','png','gif','webp','doc','docx','xls','xlsx','csv','txt'];

        if (!in_array($ext, $allowed)) {
            $errors[] = 'File type not allowed. Accepted: ' . implode(', ', $allowed);
        } elseif ($file['size'] > 10 * 1024 * 1024) {
            $errors[] = 'File too large. Maximum size is 10 MB.';
        } elseif ($file['error'] !== UPLOAD_ERR_OK) {
            $errors[] = 'Upload failed. Please try again.';
        }
    }

    if (empty($errors)) {
        $fileName = date('Ymd_His') . '_' . uniqid() . '.' . $ext;
        $destDir  = BASE_PATH . '/uploads/car_docs/';
        $destPath = $destDir . $fileName;

        if (!is_dir($destDir)) mkdir($destDir, 0755, true);

        if (!move_uploaded_file($file['tmp_name'], $destPath)) {
            $errors[] = 'Could not save file. Check server write permissions.';
        } else {
            try {
                $db->prepare("
                    INSERT INTO car_documents
                        (car_id, doc_type, title, file_path, file_name, file_size, mime_type, expiry_date, notes, uploaded_by)
                    VALUES (?,?,?,?,?,?,?,?,?,?)
                ")->execute([
                    $carId, $docType, $title,
                    $fileName, $origName,
                    $file['size'],
                    mime_content_type($destPath) ?: null,
                    $expiryDate, $notes,
                    authUser()['id'],
                ]);
                logActivity('create', 'car_documents', (int)$db->lastInsertId(), "Uploaded: $title ($docType)");
                setFlash('success', 'Document uploaded successfully.');
                redirect(strpos($back, BASE_URL) === 0
                    ? $back
                    : BASE_URL . '/modules/cars/view.php?id=' . $carId . '#documents');
            } catch (\Throwable $e) {
                @unlink($destPath);
                $errors[] = 'Database error: ' . $e->getMessage();
            }
        }
    }
}

// Car list for selector
$cars = $db->query("SELECT id, make, model, chassis_number FROM cars ORDER BY make, model")->fetchAll();

$back = $_GET['back'] ?? $_POST['back'] ?? BASE_URL . '/modules/car_documents/index.php';

include __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="mb-0"><i class="fa fa-upload me-2 text-primary"></i>Upload Car Document</h5>
    <a href="<?= e($back) ?>" class="btn btn-sm btn-outline-secondary">
        <i class="fa fa-arrow-left me-1"></i>Back
    </a>
</div>

<?php if ($errors): ?>
<div class="alert alert-danger">
    <ul class="mb-0"><?php foreach ($errors as $err) echo '<li>' . e($err) . '</li>'; ?></ul>
</div>
<?php endif; ?>

<div class="card">
    <div class="card-body">
        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="back" value="<?= e($back) ?>">
            <div class="row g-3">

                <!-- Vehicle -->
                <div class="col-md-6">
                    <label class="form-label fw-semibold">Vehicle <span class="text-danger">*</span></label>
                    <?php if ($preCar): ?>
                    <input type="hidden" name="car_id" value="<?= $preCar['id'] ?>">
                    <input type="text" class="form-control" readonly
                           value="<?= e($preCar['make'] . ' ' . $preCar['model'] . ' — ' . $preCar['chassis_number']) ?>">
                    <?php else: ?>
                    <select name="car_id" class="form-select select2" required>
                        <option value="">— Select vehicle —</option>
                        <?php foreach ($cars as $c): ?>
                        <option value="<?= $c['id'] ?>" <?= (isset($_POST['car_id']) && $_POST['car_id'] == $c['id']) ? 'selected' : '' ?>>
                            <?= e($c['make'] . ' ' . $c['model'] . ' — ' . $c['chassis_number']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <?php endif; ?>
                </div>

                <!-- Document Type -->
                <div class="col-md-6">
                    <label class="form-label fw-semibold">Document Type <span class="text-danger">*</span></label>
                    <select name="doc_type" class="form-select" required>
                        <option value="">— Select type —</option>
                        <?php foreach ($docTypes as $key => $label): ?>
                        <option value="<?= $key ?>" <?= (($_POST['doc_type'] ?? '') === $key) ? 'selected' : '' ?>>
                            <?= $label ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Title -->
                <div class="col-md-8">
                    <label class="form-label fw-semibold">Document Title <span class="text-danger">*</span></label>
                    <input type="text" name="title" class="form-control"
                           value="<?= e($_POST['title'] ?? '') ?>"
                           placeholder="e.g. NTSA Inspection Certificate — Jan 2025"
                           required>
                </div>

                <!-- Expiry Date -->
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Expiry Date <span class="text-muted">(optional)</span></label>
                    <input type="date" name="expiry_date" class="form-control"
                           value="<?= e($_POST['expiry_date'] ?? '') ?>">
                    <div class="form-text">Leave blank if the document does not expire.</div>
                </div>

                <!-- File -->
                <div class="col-12">
                    <label class="form-label fw-semibold">File <span class="text-danger">*</span></label>
                    <input type="file" name="document" class="form-control" required
                           accept=".pdf,.jpg,.jpeg,.png,.gif,.webp,.doc,.docx,.xls,.xlsx,.csv,.txt">
                    <div class="form-text">PDF, images (JPG, PNG), Word, Excel — max 10 MB.</div>
                </div>

                <!-- Notes -->
                <div class="col-12">
                    <label class="form-label fw-semibold">Notes <span class="text-muted">(optional)</span></label>
                    <textarea name="notes" class="form-control" rows="2"
                              placeholder="Any additional notes about this document…"><?= e($_POST['notes'] ?? '') ?></textarea>
                </div>

                <div class="col-12">
                    <button type="submit" class="btn btn-primary">
                        <i class="fa fa-upload me-1"></i>Upload Document
                    </button>
                    <a href="<?= e($back) ?>" class="btn btn-outline-secondary ms-2">Cancel</a>
                </div>
            </div>
        </form>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>

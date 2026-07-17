<?php
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
hasRole(['admin','general_manager','hr_manager','workshop_manager']) || die('Access denied.');

$db = getDB();
$user = authUser();

$entities = [
    'cars'      => ['make', 'model', 'year', 'chassis_number', 'registration_number', 'color'],
    'clients'   => ['name', 'phone', 'email', 'id_number'],
    'inventory' => ['part_number', 'part_name', 'category', 'cost_price', 'selling_price', 'quantity_in_stock', 'supplier_id']
];

$errors = [];
$successCount = 0;
$skippedCount = 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csv_file'])) {
    $type = $_POST['entity_type'] ?? '';
    if (!isset($entities[$type])) die('Invalid entity type.');

    $file = $_FILES['csv_file'];
    if ($file['error'] === UPLOAD_ERR_OK && is_uploaded_file($file['tmp_name'])) {
        $handle = fopen($file['tmp_name'], 'r');
        $header = fgetcsv($handle);
        
        if ($header) {
            // Strip BOM from header if exists
            $header[0] = preg_replace('/[\x00-\x1F\x80-\xFF]/', '', $header[0]);
            $header = array_map('trim', $header);
            
            // Expected columns check (loose check: just ensure they are in the header)
            $missing = array_diff($entities[$type], $header);
            if (!empty($missing)) {
                $errors[] = "Missing required columns: " . implode(', ', $missing);
            } else {
                $db->beginTransaction();
                try {
                    $rowIdx = 2; // Line 2 (header is line 1)
                    while (($data = fgetcsv($handle)) !== false) {
                        $row = array_combine($header, $data);
                        
                        if ($type === 'clients') {
                            $name  = trim($row['name'] ?? '');
                            $phone = trim($row['phone'] ?? '');
                            if (!$name) { $skippedCount++; $rowIdx++; continue; }
                            
                            $stmt = $db->prepare("INSERT INTO clients (name, phone, email, id_number) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE phone=VALUES(phone), email=VALUES(email)");
                            $stmt->execute([$name, $phone, $row['email']??'', $row['id_number']??'']);
                            $successCount++;
                        }
                        elseif ($type === 'cars') {
                            $chassis = trim($row['chassis_number'] ?? '');
                            $make    = trim($row['make'] ?? '');
                            if (!$chassis || !$make) { $skippedCount++; $rowIdx++; continue; }
                            
                            $stmt = $db->prepare("INSERT IGNORE INTO cars (chassis_number, registration_number, make, model, year, color) VALUES (?,?,?,?,?,?)");
                            $stmt->execute([$chassis, $row['registration_number']??'', $make, $row['model']??'', $row['year']??date('Y'), $row['color']??'']);
                            if ($stmt->rowCount() > 0) $successCount++; else $skippedCount++;
                        }
                        elseif ($type === 'inventory') {
                            $pno   = trim($row['part_number'] ?? '');
                            $pname = trim($row['part_name'] ?? '');
                            if (!$pno || !$pname) { $skippedCount++; $rowIdx++; continue; }
                            
                            $stmt = $db->prepare("INSERT INTO inventory (part_number, part_name, category, cost_price, selling_price, quantity_in_stock, supplier_id) 
                                                  VALUES (?,?,?,?,?,?,?)
                                                  ON DUPLICATE KEY UPDATE quantity_in_stock = quantity_in_stock + VALUES(quantity_in_stock), selling_price=VALUES(selling_price)");
                            $stmt->execute([
                                $pno, $pname, $row['category']??'General', 
                                (float)($row['cost_price']??0), (float)($row['selling_price']??0), 
                                (int)($row['quantity_in_stock']??0), (int)($row['supplier_id']??0) ?: null
                            ]);
                            $successCount++;
                        }
                        $rowIdx++;
                    }
                    
                    // Log the import
                    $log = $db->prepare("INSERT INTO import_logs (imported_by, entity_type, file_name, rows_imported, rows_skipped) VALUES (?,?,?,?,?)");
                    $log->execute([$user['id'], $type, $file['name'], $successCount, $skippedCount]);
                    
                    $db->commit();
                    setFlash('success', "Import completed. Imported: $successCount, Skipped: $skippedCount.");
                    redirect('import.php');
                    
                } catch (\Throwable $e) {
                    $db->rollBack();
                    $errors[] = "Database error: " . $e->getMessage();
                }
            }
        } else {
            $errors[] = "Empty or invalid CSV file.";
        }
        fclose($handle);
    } else {
        $errors[] = "File upload failed.";
    }
}

// Fetch recent imports
$recentLogs = $db->query("SELECT l.*, u.name as user_name FROM import_logs l JOIN users u ON u.id=l.imported_by ORDER BY l.created_at DESC LIMIT 10")->fetchAll();

$pageTitle = 'Data Imports';
include __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <h5 class="mb-1"><i class="fa fa-file-import me-2 text-primary"></i>Bulk Data Imports</h5>
        <div class="text-muted small">Upload CSV files to securely populate database tables.</div>
    </div>
    <div class="d-flex gap-2">
        <a href="export.php" class="btn btn-sm btn-outline-secondary"><i class="fa fa-file-export me-1"></i>Go to Exports</a>
    </div>
</div>

<?php if ($errors): ?>
<div class="alert alert-danger">
    <ul class="mb-0">
        <?php foreach ($errors as $e) echo "<li>".e($e)."</li>"; ?>
    </ul>
</div>
<?php endif; ?>

<div class="row">
    <div class="col-lg-6">
        <div class="card mb-4">
            <div class="card-header fw-semibold"><i class="fa fa-upload me-2"></i>Upload CSV File</div>
            <div class="card-body">
                <form method="POST" enctype="multipart/form-data">
                    <div class="mb-3">
                        <label class="form-label">Data Type <span class="text-danger">*</span></label>
                        <select name="entity_type" id="entitySelect" class="form-select" required onchange="showTemplate()">
                            <option value="">— Select Data Type —</option>
                            <option value="cars">Cars (Inventory & Client Vehicles)</option>
                            <option value="clients">Clients & Leads</option>
                            <option value="inventory">Parts Inventory</option>
                        </select>
                    </div>
                    
                    <div class="mb-4" id="templateBox" style="display:none;background:#f8fafc;padding:12px;border-radius:8px;font-size:12px">
                        <strong class="text-dark d-block mb-1">Required CSV Columns:</strong>
                        <code id="templateCols" class="text-primary"></code>
                        <div class="mt-2 text-muted" style="font-size:11px">
                            <i class="fa fa-info-circle me-1"></i>Ensure your CSV header matches exactly. The order does not matter.
                        </div>
                    </div>

                    <div class="mb-4">
                        <label class="form-label">Select File (.csv) <span class="text-danger">*</span></label>
                        <input type="file" name="csv_file" class="form-control" accept=".csv" required>
                    </div>
                    
                    <button type="submit" class="btn btn-primary w-100"><i class="fa fa-cloud-arrow-up me-2"></i>Start Import</button>
                </form>
            </div>
        </div>
    </div>
    
    <div class="col-lg-6">
        <div class="card">
            <div class="card-header fw-semibold"><i class="fa fa-clock-rotate-left me-2"></i>Recent Imports</div>
            <div class="card-body p-0">
                <?php if ($recentLogs): ?>
                <table class="table table-hover mb-0" style="font-size:13px">
                    <thead><tr><th class="ps-3">Type</th><th>File</th><th>Imported</th><th class="text-danger">Skipped</th><th class="pe-3">User</th></tr></thead>
                    <tbody>
                    <?php foreach ($recentLogs as $log): ?>
                    <tr>
                        <td class="ps-3"><span class="badge bg-secondary"><?= ucfirst($log['entity_type']) ?></span></td>
                        <td class="text-truncate" style="max-width:150px" title="<?= e($log['file_name']) ?>"><?= e($log['file_name']) ?></td>
                        <td class="text-success fw-bold"><?= $log['rows_imported'] ?></td>
                        <td class="text-danger fw-bold"><?= $log['rows_skipped'] ?></td>
                        <td class="pe-3 small text-muted"><?= e($log['user_name']) ?><br><?= fmtDate($log['created_at'], 'd M H:i') ?></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php else: ?>
                <p class="text-muted p-4 text-center mb-0">No imports performed yet.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
const reqCols = <?= json_encode($entities) ?>;
function showTemplate() {
    const type = document.getElementById('entitySelect').value;
    const box = document.getElementById('templateBox');
    const label = document.getElementById('templateCols');
    if (type && reqCols[type]) {
        label.textContent = reqCols[type].join(', ');
        box.style.display = 'block';
    } else {
        box.style.display = 'none';
    }
}
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>

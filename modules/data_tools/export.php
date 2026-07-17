<?php
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
hasRole(['admin','general_manager','hr_manager','workshop_manager']) || die('Access denied.');

$db = getDB();

if (isset($_GET['export'])) {
    $type = $_GET['export'];
    
    $query = '';
    $filename = "export_{$type}_" . date('Ymd_His') . ".csv";
    
    if ($type === 'cars') {
        $query = "SELECT id, chassis_number, registration_number, make, model, year, color, transmission, fuel_type, body_type, status FROM cars ORDER BY created_at DESC";
    } elseif ($type === 'clients') {
        $query = "SELECT id, name, phone, email, id_number, status, created_at FROM clients ORDER BY created_at DESC";
    } elseif ($type === 'inventory') {
        $query = "SELECT id, part_number, part_name, category, cost_price, selling_price, quantity_in_stock, min_stock_level, location, status FROM inventory ORDER BY part_name ASC";
    } elseif ($type === 'jobs') {
        $query = "SELECT j.job_number, c.chassis_number, cl.name as client_name, j.status, j.total_cost, j.started_at, j.completed_at 
                  FROM workshop_jobs j LEFT JOIN cars c ON c.id=j.car_id LEFT JOIN clients cl ON cl.id=j.client_id ORDER BY j.created_at DESC";
    }
    
    if ($query) {
        $stmt = $db->query($query);
        
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        
        $output = fopen('php://output', 'w');
        
        // Write BOM for Excel UTF-8 support
        fputs($output, $bom =(chr(0xEF) . chr(0xBB) . chr(0xBF)));
        
        $isHeader = true;
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            if ($isHeader) {
                fputcsv($output, array_keys($row));
                $isHeader = false;
            }
            fputcsv($output, array_values($row));
        }
        fclose($output);
        exit;
    }
}

$pageTitle = 'Data Exports';
include __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <h5 class="mb-1"><i class="fa fa-file-export me-2 text-primary"></i>Bulk Data Exports</h5>
        <div class="text-muted small">Download database tables as CSV files for backup or external reporting.</div>
    </div>
    <div class="d-flex gap-2">
        <a href="import.php" class="btn btn-sm btn-outline-secondary"><i class="fa fa-file-import me-1"></i>Go to Imports</a>
    </div>
</div>

<div class="row g-4">
    <div class="col-md-6 col-lg-3">
        <div class="card h-100 border-primary" style="border-top: 4px solid #3b82f6">
            <div class="card-body text-center py-4">
                <i class="fa fa-car fa-3x text-primary mb-3"></i>
                <h5 class="fw-bold">Cars Directory</h5>
                <p class="text-muted small mb-4">Export all vehicles including inventory, client cars, and fleet assets.</p>
                <a href="?export=cars" class="btn btn-primary w-100"><i class="fa fa-download me-2"></i>Download CSV</a>
            </div>
        </div>
    </div>
    <div class="col-md-6 col-lg-3">
        <div class="card h-100" style="border-top: 4px solid #10b981">
            <div class="card-body text-center py-4">
                <i class="fa fa-users fa-3x text-success mb-3"></i>
                <h5 class="fw-bold">Client Roster</h5>
                <p class="text-muted small mb-4">Export the complete client list including contacts and lead records.</p>
                <a href="?export=clients" class="btn btn-success w-100"><i class="fa fa-download me-2"></i>Download CSV</a>
            </div>
        </div>
    </div>
    <div class="col-md-6 col-lg-3">
        <div class="card h-100" style="border-top: 4px solid #f59e0b">
            <div class="card-body text-center py-4">
                <i class="fa fa-boxes-stacked fa-3x text-warning mb-3"></i>
                <h5 class="fw-bold">Parts Inventory</h5>
                <p class="text-muted small mb-4">Export all spare parts, stock levels, and cost prices.</p>
                <a href="?export=inventory" class="btn btn-warning w-100 text-dark"><i class="fa fa-download me-2"></i>Download CSV</a>
            </div>
        </div>
    </div>
    <div class="col-md-6 col-lg-3">
        <div class="card h-100" style="border-top: 4px solid #8b5cf6">
            <div class="card-body text-center py-4">
                <i class="fa fa-toolbox fa-3x text-purple mb-3" style="color:#8b5cf6"></i>
                <h5 class="fw-bold">Workshop Jobs</h5>
                <p class="text-muted small mb-4">Export history of all job cards, status, and billed totals.</p>
                <a href="?export=jobs" class="btn text-white w-100" style="background:#8b5cf6"><i class="fa fa-download me-2"></i>Download CSV</a>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>

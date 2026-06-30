<?php
/**
 * Cars — Server-side DataTables endpoint
 * Returns paginated, sorted, filtered JSON for modules/cars/index.php
 */
require_once __DIR__ . '/../../../includes/functions.php';
requireLogin();

if (!canAccess('cars')) {
    http_response_code(403);
    echo json_encode(['error' => 'Access denied']);
    exit;
}

header('Content-Type: application/json; charset=utf-8');

$db = getDB();

// ── Add indexes silently on first run (no-op if they already exist) ──────────
try { $db->exec("ALTER TABLE cars ADD INDEX idx_cars_type   (car_type)"); }      catch (\Throwable $_) {}
try { $db->exec("ALTER TABLE cars ADD INDEX idx_cars_status (status)");  }       catch (\Throwable $_) {}
try { $db->exec("ALTER TABLE car_images ADD INDEX idx_ci_primary (car_id, is_primary)"); } catch (\Throwable $_) {}

// ── Params ───────────────────────────────────────────────────────────────────
$section = in_array($_GET['section'] ?? '', ['inventory', 'client', 'workshop'])
         ? $_GET['section'] : 'inventory';

$draw           = (int)($_GET['draw']   ?? 1);
$start          = (int)($_GET['start']  ?? 0);
$length         = min(100, max(10, (int)($_GET['length'] ?? 25)));
$search         = trim($_GET['search']['value'] ?? '');
$oCol           = (int)(($_GET['order'][0]['column'] ?? 99));
$oDir           = (($_GET['order'][0]['dir'] ?? 'desc') === 'asc') ? 'ASC' : 'DESC';
$filterMake     = trim($_GET['filter_make']     ?? '');
$filterLocation = (int)($_GET['filter_location'] ?? 0);

// Map column index → SQL expression (only orderable columns)
$colMap = [
    0 => 'c.make',
    1 => 'c.car_type',
    2 => 'c.chassis_number',
    3 => 'l.name',
    4 => 'c.asking_price',
    5 => 'c.status',
];
$orderSQL = isset($colMap[$oCol]) ? "{$colMap[$oCol]} {$oDir}" : 'c.created_at DESC';

// ── Base WHERE (section filter) ───────────────────────────────────────────────
if ($section === 'workshop') {
    $baseWhere = "c.status = 'in_workshop'";
} elseif ($section === 'inventory') {
    $baseWhere = "c.car_type = 'inventory'";
} else {
    $baseWhere = "c.car_type = 'client'";
}

// ── Search filter ─────────────────────────────────────────────────────────────
$searchWhere  = '';
$searchParams = [];
if ($search !== '') {
    $searchWhere = " AND (c.make LIKE ? OR c.model LIKE ? OR c.chassis_number LIKE ?
                        OR c.registration_number LIKE ? OR c.owner_name LIKE ? OR c.color LIKE ?)";
    $s = "%{$search}%";
    $searchParams = [$s, $s, $s, $s, $s, $s];
}

// ── Inventory dropdown filters ────────────────────────────────────────────────
$filterWhere  = '';
$filterParams = [];
if ($filterMake !== '') {
    $filterWhere   .= ' AND c.make = ?';
    $filterParams[] = $filterMake;
}
if ($filterLocation > 0) {
    $filterWhere   .= ' AND c.location_id = ?';
    $filterParams[] = $filterLocation;
}

$fullWhere = $baseWhere . $searchWhere . $filterWhere;

// ── Counts ───────────────────────────────────────────────────────────────────
$totalRecords = (int)$db->query("SELECT COUNT(*) FROM cars c WHERE {$baseWhere}")->fetchColumn();

$allFilterParams = array_merge($searchParams, $filterParams);
if ($allFilterParams) {
    $cntStmt = $db->prepare(
        "SELECT COUNT(*) FROM cars c
         LEFT JOIN locations l ON l.id = c.location_id
         WHERE {$fullWhere}"
    );
    $cntStmt->execute($allFilterParams);
    $filteredRecords = (int)$cntStmt->fetchColumn();
} else {
    $filteredRecords = $totalRecords;
}

// ── Data query ───────────────────────────────────────────────────────────────
// Uses a correlated subquery for primary image (indexed on car_id + is_primary)
// and LEFT JOIN for location. Only fetches columns actually needed.
$sql = "
    SELECT c.id,
           c.make, c.model, c.year, c.color,
           c.registration_number, c.chassis_number,
           c.car_type, c.owner_name,
           IFNULL(c.asking_price, 0) AS asking_price,
           c.status,
           IFNULL(l.name, '') AS location_name,
           (SELECT ci.file_path FROM car_images ci
            WHERE ci.car_id = c.id AND ci.is_primary = 1 LIMIT 1) AS primary_image
    FROM cars c
    LEFT JOIN locations l ON l.id = c.location_id
    WHERE {$fullWhere}
    ORDER BY {$orderSQL}
    LIMIT ? OFFSET ?
";

$stmt = $db->prepare($sql);
$stmt->execute(array_merge($allFilterParams, [$length, $start]));
$rows = $stmt->fetchAll();

// ── Pre-compute permissions once (not per-row) ────────────────────────────────
$canWrite = canWrite('cars');
$canDel   = canEditDelete();

// ── Build HTML rows ───────────────────────────────────────────────────────────
$data = [];
foreach ($rows as $car) {

    // Vehicle thumbnail + name
    if ($car['primary_image']) {
        $imgUrl = thumbUrl('cars', $car['primary_image']);
        $img = '<img src="' . e($imgUrl) . '"'
             . ' style="width:50px;height:40px;object-fit:cover"'
             . ' class="rounded border shadow-sm" loading="lazy" decoding="async"'
             . ' width="50" height="40">';
    } else {
        $img = '<div class="bg-light rounded border d-flex align-items-center'
             . ' justify-content-center text-muted" style="width:50px;height:40px;font-size:10px">NO IMG</div>';
    }
    $vehicle = '<div class="d-flex align-items-center gap-2">' . $img
             . '<div>'
             . '<div class="fw-bold">'   . e($car['make'] . ' ' . $car['model']) . '</div>'
             . '<div class="text-muted small">' . e((string)$car['year']) . ' &bull; '
             . e($car['registration_number'] ?: 'No Reg') . '</div>'
             . '</div></div>';

    // Type / owner badge
    if ($car['car_type'] === 'client') {
        $type = '<span class="badge bg-info text-dark">CLIENT</span>';
        if (!empty($car['owner_name'])) {
            $type .= '<div class="small text-muted">' . e($car['owner_name']) . '</div>';
        }
    } else {
        $type = '<span class="badge bg-primary">INVENTORY</span>';
    }

    // Chassis
    $chassis = '<code class="small">' . e($car['chassis_number'] ?? '') . '</code>';

    // Location
    $location = '<span class="small text-muted">'
              . '<i class="fa fa-location-dot me-1"></i>'
              . e($car['location_name'] ?: '—') . '</span>';

    // Price
    $p     = (float)$car['asking_price'];
    $price = $p > 0
           ? '<span class="fw-semibold text-nowrap">KES ' . number_format($p, 2) . '</span>'
           : '<span class="text-muted">—</span>';

    // Status
    $status = statusBadge($car['status']);

    // Action buttons
    $acts = '<div class="d-flex gap-1">';
    if ($section === 'workshop') {
        $acts .= '<a href="' . BASE_URL . '/modules/cars/workshop.php?id=' . $car['id']
               . '" class="btn btn-xs btn-warning">'
               . '<i class="fa fa-screwdriver-wrench me-1"></i>Progress</a>';
    } else {
        $acts .= '<a href="' . BASE_URL . '/modules/cars/view.php?id=' . $car['id']
               . '" class="btn btn-xs btn-outline-primary" title="View">'
               . '<i class="fa fa-eye"></i></a>';
    }
    if ($canWrite) {
        $acts .= '<a href="' . BASE_URL . '/modules/cars/edit.php?id=' . $car['id']
               . '" class="btn btn-xs btn-outline-secondary" title="Edit">'
               . '<i class="fa fa-pen"></i></a>';
    }
    if ($canDel) {
        $acts .= '<a href="' . BASE_URL . '/modules/cars/delete.php?id=' . $car['id']
               . '" class="btn btn-xs btn-outline-danger confirm-delete" title="Delete">'
               . '<i class="fa fa-trash"></i></a>';
    }
    $acts .= '</div>';

    $data[] = [$vehicle, $type, $chassis, $location, $price, $status, $acts];
}

echo json_encode([
    'draw'            => $draw,
    'recordsTotal'    => $totalRecords,
    'recordsFiltered' => $filteredRecords,
    'data'            => $data,
]);

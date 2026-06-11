<?php
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
canAccess('imports') || die('Access denied.');
$db   = getDB();
$user = authUser();

// ── Inline migrations ──────────────────────────────────────────────────────────
try { $db->exec("CREATE TABLE IF NOT EXISTS car_shipments (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    ref             VARCHAR(30)  NOT NULL,
    name            VARCHAR(200) NULL,
    origin_country  VARCHAR(100) NULL DEFAULT 'Japan',
    shipping_line   VARCHAR(150) NULL,
    bl_number       VARCHAR(100) NULL,
    vessel_name     VARCHAR(150) NULL,
    etd             DATE         NULL,
    eta             DATE         NULL,
    actual_arrival  DATE         NULL,
    status          ENUM('pending','at_sea','arrived_port','customs','cleared','closed') NOT NULL DEFAULT 'pending',
    notes           TEXT         NULL,
    created_by      INT          NULL,
    created_at      TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); } catch(\Throwable $e) {}

try { $db->exec("CREATE TABLE IF NOT EXISTS car_imports (
    id                   INT AUTO_INCREMENT PRIMARY KEY,
    ref                  VARCHAR(30)  NOT NULL UNIQUE,
    car_id               INT          NULL,
    shipment_id          INT          NULL,
    make                 VARCHAR(100) NOT NULL DEFAULT '',
    model                VARCHAR(100) NOT NULL DEFAULT '',
    year                 INT          NULL,
    color                VARCHAR(60)  NULL,
    chassis_number       VARCHAR(100) NULL,
    engine_number        VARCHAR(100) NULL,
    body_type            VARCHAR(60)  NULL,
    transmission         VARCHAR(40)  NULL DEFAULT 'automatic',
    fuel_type            VARCHAR(40)  NULL DEFAULT 'petrol',
    engine_cc            INT          NULL,
    mileage              INT          NULL,
    supplier_id          INT          NULL,
    supplier_name        VARCHAR(200) NULL,
    auction_ref          VARCHAR(100) NULL,
    purchase_currency    VARCHAR(10)  NOT NULL DEFAULT 'JPY',
    purchase_price       DECIMAL(15,2) NULL,
    exchange_rate        DECIMAL(12,6) NOT NULL DEFAULT 1.000000,
    purchase_price_kes   DECIMAL(15,2) NULL,
    purchase_date        DATE         NULL,
    idf_number           VARCHAR(100) NULL,
    idf_date             DATE         NULL,
    stage                ENUM('purchased','in_transit_sea','arrived_port','customs','cleared','in_transit_road','arrived_yard','intake','completed') NOT NULL DEFAULT 'purchased',
    purchased_at         DATE         NULL,
    shipped_at           DATE         NULL,
    arrived_port_at      DATE         NULL,
    customs_start_at     DATE         NULL,
    cleared_at           DATE         NULL,
    dispatched_road_at   DATE         NULL,
    arrived_yard_at      DATE         NULL,
    intake_at            DATE         NULL,
    completed_at         DATE         NULL,
    notes                TEXT         NULL,
    created_by           INT          NULL,
    created_at           TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    updated_at           TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); } catch(\Throwable $e) {}

try { $db->exec("CREATE TABLE IF NOT EXISTS import_costs (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    import_id    INT          NOT NULL,
    cost_type    VARCHAR(60)  NOT NULL DEFAULT 'other',
    amount       DECIMAL(15,2) NOT NULL DEFAULT 0,
    currency     VARCHAR(10)  NOT NULL DEFAULT 'KES',
    exchange_rate DECIMAL(12,6) NOT NULL DEFAULT 1.000000,
    amount_kes   DECIMAL(15,2) NOT NULL DEFAULT 0,
    description  VARCHAR(255) NULL,
    receipt_ref  VARCHAR(100) NULL,
    paid_at      DATE         NULL,
    created_by   INT          NULL,
    created_at   TIMESTAMP    DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"); } catch(\Throwable $e) {}

// ── Stage config ───────────────────────────────────────────────────────────────
$stages = [
    'purchased'        => ['label'=>'Purchased',       'color'=>'secondary', 'icon'=>'fa-file-invoice-dollar'],
    'in_transit_sea'   => ['label'=>'At Sea',          'color'=>'info',      'icon'=>'fa-ship'],
    'arrived_port'     => ['label'=>'Arrived Port',    'color'=>'primary',   'icon'=>'fa-anchor'],
    'customs'          => ['label'=>'Customs',         'color'=>'warning',   'icon'=>'fa-stamp'],
    'cleared'          => ['label'=>'Cleared',         'color'=>'success',   'icon'=>'fa-circle-check'],
    'in_transit_road'  => ['label'=>'Road Transit',    'color'=>'info',      'icon'=>'fa-truck'],
    'arrived_yard'     => ['label'=>'Arrived Yard',    'color'=>'success',   'icon'=>'fa-warehouse'],
    'intake'           => ['label'=>'In Intake',       'color'=>'primary',   'icon'=>'fa-clipboard-check'],
    'completed'        => ['label'=>'Completed',       'color'=>'dark',      'icon'=>'fa-flag-checkered'],
];

// ── Stats ──────────────────────────────────────────────────────────────────────
try {
    $stats = $db->query("
        SELECT
            COUNT(*)                                                         AS total,
            SUM(stage NOT IN ('completed'))                                  AS active,
            SUM(stage = 'customs')                                           AS in_customs,
            SUM(stage IN ('in_transit_sea','in_transit_road'))               AS in_transit,
            SUM(stage = 'arrived_yard')                                      AS at_yard,
            SUM(stage = 'completed')                                         AS completed,
            SUM(car_id IS NULL AND stage IN ('arrived_yard','intake'))       AS needs_car_record
        FROM car_imports
    ")->fetch();
} catch(\Throwable $e) { $stats = []; }

// ── Stage counts ───────────────────────────────────────────────────────────────
try {
    $stageCounts = $db->query("SELECT stage, COUNT(*) AS cnt FROM car_imports WHERE stage != 'completed' GROUP BY stage")->fetchAll();
    $stageMap = []; foreach($stageCounts as $r) $stageMap[$r['stage']] = $r['cnt'];
} catch(\Throwable $e) { $stageMap = []; }

// ── Filters ────────────────────────────────────────────────────────────────────
$filterStage  = $_GET['stage']  ?? '';
$filterMake   = trim($_GET['make']  ?? '');
$search       = trim($_GET['q']     ?? '');
$showAll      = isset($_GET['all']);

$where  = ['1=1']; $params = [];
if ($filterStage) { $where[] = 'i.stage=?'; $params[] = $filterStage; }
elseif (!$showAll) { $where[] = "i.stage != 'completed'"; }
if ($filterMake)  { $where[] = 'i.make=?'; $params[] = $filterMake; }
if ($search) {
    $where[] = '(i.ref LIKE ? OR i.chassis_number LIKE ? OR i.make LIKE ? OR i.model LIKE ? OR i.supplier_name LIKE ?)';
    $params  = array_merge($params, ["%$search%","%$search%","%$search%","%$search%","%$search%"]);
}

try {
    $stmt = $db->prepare("
        SELECT i.*,
               s.ref AS ship_ref, s.vessel_name, s.eta,
               COALESCE((SELECT SUM(ic.amount_kes) FROM import_costs ic WHERE ic.import_id=i.id),0) AS total_landed_kes,
               DATEDIFF(NOW(), i.created_at) AS days_in_pipeline
        FROM car_imports i
        LEFT JOIN car_shipments s ON s.id = i.shipment_id
        WHERE ".implode(' AND ',$where)."
        ORDER BY i.created_at DESC
    ");
    $stmt->execute($params);
    $imports = $stmt->fetchAll();
} catch(\Throwable $e) { $imports = []; }

// Distinct makes for filter
try {
    $makes = $db->query("SELECT DISTINCT make FROM car_imports WHERE make!='' ORDER BY make")->fetchAll(PDO::FETCH_COLUMN);
} catch(\Throwable $e) { $makes = []; }

$pageTitle = 'Import Pipeline';
include __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div>
        <h5 class="mb-1"><i class="fa fa-ship me-2 text-primary"></i>Import Pipeline</h5>
        <div class="text-muted small">Track vehicles from purchase through to yard intake</div>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <?php if(canWrite('imports')): ?>
        <a href="shipment_add.php" class="btn btn-sm btn-outline-secondary"><i class="fa fa-boxes-stacked me-1"></i>New Shipment</a>
        <a href="add.php" class="btn btn-sm btn-primary"><i class="fa fa-plus me-1"></i>New Import</a>
        <?php endif; ?>
    </div>
</div>

<!-- Stats row -->
<div class="row g-3 mb-4">
    <?php foreach([
        ['Active Imports',      $stats['active']          ?? 0, 'text-primary',   'fa-ship'],
        ['In Customs',          $stats['in_customs']      ?? 0, 'text-warning',   'fa-stamp'],
        ['In Transit',          $stats['in_transit']      ?? 0, 'text-info',      'fa-truck'],
        ['At Yard (Pending)',   $stats['at_yard']         ?? 0, 'text-success',   'fa-warehouse'],
        ['Needs Car Record',    $stats['needs_car_record']?? 0, 'text-danger',    'fa-triangle-exclamation'],
        ['Completed',           $stats['completed']       ?? 0, 'text-secondary', 'fa-flag-checkered'],
    ] as [$lbl,$val,$cls,$ico]): ?>
    <div class="col-6 col-md-4 col-lg-2">
        <div class="card text-center h-100">
            <div class="card-body py-3">
                <i class="fa <?= $ico ?> fa-lg <?= $cls ?> mb-1"></i>
                <div class="fs-4 fw-bold <?= $cls ?>"><?= $val ?></div>
                <div class="text-muted small"><?= $lbl ?></div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<!-- Stage pipeline bar -->
<div class="card mb-4">
    <div class="card-body py-2">
        <div class="d-flex align-items-center gap-1 flex-wrap" style="font-size:12px">
            <a href="?<?= http_build_query(array_merge($_GET,['stage'=>''])) ?>"
               class="badge <?= !$filterStage ? 'bg-dark' : 'bg-light text-dark border' ?> text-decoration-none py-2 px-3">
               All Active (<?= array_sum($stageMap) ?>)
            </a>
            <?php foreach($stages as $key => $s): if($key === 'completed') continue; ?>
            <i class="fa fa-chevron-right text-muted" style="font-size:9px"></i>
            <a href="?<?= http_build_query(array_merge($_GET,['stage'=>$key])) ?>"
               class="badge bg-<?= $s['color'] ?> text-decoration-none py-2 px-3 <?= $filterStage===$key ? 'opacity-100' : 'opacity-75' ?>">
               <i class="fa <?= $s['icon'] ?> me-1"></i><?= $s['label'] ?> (<?= $stageMap[$key] ?? 0 ?>)
            </a>
            <?php endforeach; ?>
            <i class="fa fa-chevron-right text-muted" style="font-size:9px"></i>
            <a href="?all=1&<?= http_build_query(array_merge($_GET,['stage'=>'completed'])) ?>"
               class="badge bg-dark opacity-75 text-decoration-none py-2 px-3">
               <i class="fa fa-flag-checkered me-1"></i>Completed (<?= $stats['completed'] ?? 0 ?>)
            </a>
        </div>
    </div>
</div>

<!-- Search / Filter bar -->
<div class="card mb-3">
    <div class="card-body py-2">
        <form method="GET" class="d-flex gap-2 flex-wrap align-items-center">
            <?php if($filterStage): ?><input type="hidden" name="stage" value="<?= e($filterStage) ?>"><?php endif; ?>
            <?php if($showAll): ?><input type="hidden" name="all" value="1"><?php endif; ?>
            <input type="text" name="q" class="form-control form-control-sm" style="width:220px"
                   placeholder="Ref, chassis, make, supplier…" value="<?= e($search) ?>">
            <select name="make" class="form-select form-select-sm select2" style="width:160px">
                <option value="">All Makes</option>
                <?php foreach($makes as $mk): ?><option value="<?= e($mk) ?>" <?= $filterMake===$mk?'selected':'' ?>><?= e($mk) ?></option><?php endforeach; ?>
            </select>
            <button class="btn btn-sm btn-outline-primary">Filter</button>
            <?php if($search||$filterMake): ?><a href="?<?= $filterStage?"stage=$filterStage":'' ?>" class="btn btn-sm btn-outline-secondary">Clear</a><?php endif; ?>
        </form>
    </div>
</div>

<!-- Imports table -->
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="fa fa-list me-2"></i>Imports (<?= count($imports) ?>)</span>
        <?php if(!$showAll && !$filterStage): ?>
        <a href="?all=1" class="btn btn-xs btn-outline-secondary" style="font-size:11px">Show Completed</a>
        <?php endif; ?>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover dataTable mb-0" style="font-size:13px">
                <thead style="background:#f8fafc">
                    <tr>
                        <th class="ps-3">Ref</th>
                        <th>Vehicle</th>
                        <th>Chassis</th>
                        <th>Supplier / Auction</th>
                        <th>Shipment</th>
                        <th>Stage</th>
                        <th>Days</th>
                        <th>Landed Cost (KES)</th>
                        <th class="pe-3"></th>
                    </tr>
                </thead>
                <tbody>
                <?php if($imports): foreach($imports as $imp): ?>
                <tr>
                    <td class="ps-3 fw-bold"><a href="view.php?id=<?= $imp['id'] ?>"><?= e($imp['ref']) ?></a></td>
                    <td>
                        <div class="fw-semibold"><?= e($imp['make'].' '.$imp['model']) ?></div>
                        <div class="text-muted small"><?= $imp['year'] ?> · <?= e(ucfirst($imp['transmission'] ?? '')) ?> · <?= e(ucfirst($imp['fuel_type'] ?? '')) ?></div>
                    </td>
                    <td class="font-monospace small"><?= $imp['chassis_number'] ? e($imp['chassis_number']) : '<span class="text-muted">—</span>' ?></td>
                    <td class="small"><?= $imp['supplier_name'] ? e($imp['supplier_name']) : '<span class="text-muted">—</span>' ?>
                        <?php if($imp['auction_ref']): ?><br><span class="text-muted"><?= e($imp['auction_ref']) ?></span><?php endif; ?>
                    </td>
                    <td class="small">
                        <?php if($imp['ship_ref']): ?>
                        <a href="shipment_view.php?id=<?= $imp['shipment_id'] ?>"><?= e($imp['ship_ref']) ?></a>
                        <?php if($imp['vessel_name']): ?><br><span class="text-muted"><?= e($imp['vessel_name']) ?></span><?php endif; ?>
                        <?php else: ?><span class="text-muted">—</span><?php endif; ?>
                    </td>
                    <td>
                        <?php $s = $stages[$imp['stage']] ?? ['label'=>$imp['stage'],'color'=>'secondary','icon'=>'fa-circle']; ?>
                        <span class="badge bg-<?= $s['color'] ?>">
                            <i class="fa <?= $s['icon'] ?> me-1"></i><?= $s['label'] ?>
                        </span>
                        <?php if($imp['car_id']): ?>
                        <br><a href="../cars/view.php?id=<?= $imp['car_id'] ?>" class="text-muted small"><i class="fa fa-car me-1"></i>Car record</a>
                        <?php elseif(in_array($imp['stage'],['arrived_yard','intake'])): ?>
                        <br><span class="badge bg-danger" style="font-size:10px">No car record</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-center">
                        <span class="badge <?= $imp['days_in_pipeline'] > 60 ? 'bg-danger' : ($imp['days_in_pipeline'] > 30 ? 'bg-warning text-dark' : 'bg-light text-dark border') ?>">
                            <?= $imp['days_in_pipeline'] ?>d
                        </span>
                    </td>
                    <td class="fw-semibold"><?= $imp['total_landed_kes'] > 0 ? 'KES '.number_format($imp['total_landed_kes']) : '<span class="text-muted small">—</span>' ?></td>
                    <td class="pe-3">
                        <a href="view.php?id=<?= $imp['id'] ?>" class="btn btn-xs btn-outline-primary">View</a>
                    </td>
                </tr>
                <?php endforeach; else: ?>
                <tr><td colspan="9" class="text-center text-muted py-4">No imports found.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>

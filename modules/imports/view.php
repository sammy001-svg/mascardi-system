<?php
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
canAccess('imports') || die('Access denied.');
$db   = getDB();
$user = authUser();
$id   = (int)($_GET['id'] ?? 0);

$imp = $db->prepare("
    SELECT i.*, s.ref AS ship_ref, s.vessel_name, s.eta, s.status AS ship_status,
           s.bl_number, s.origin_country
    FROM car_imports i
    LEFT JOIN car_shipments s ON s.id = i.shipment_id
    WHERE i.id = ?
"); $imp->execute([$id]); $imp = $imp->fetch();
if (!$imp) { setFlash('error','Import not found.'); redirect(BASE_URL.'/modules/imports/index.php'); }

$stages = [
    'purchased'        => ['label'=>'Purchased',       'color'=>'secondary','icon'=>'fa-file-invoice-dollar', 'next'=>'in_transit_sea',  'next_label'=>'Mark as Shipped',        'next_field'=>'shipped_at'],
    'in_transit_sea'   => ['label'=>'At Sea',          'color'=>'info',     'icon'=>'fa-ship',               'next'=>'arrived_port',    'next_label'=>'Mark Port Arrival',      'next_field'=>'arrived_port_at'],
    'arrived_port'     => ['label'=>'Arrived Port',    'color'=>'primary',  'icon'=>'fa-anchor',             'next'=>'customs',         'next_label'=>'Start Customs Process',  'next_field'=>'customs_start_at'],
    'customs'          => ['label'=>'In Customs',      'color'=>'warning',  'icon'=>'fa-stamp',              'next'=>'cleared',         'next_label'=>'Mark Customs Cleared',   'next_field'=>'cleared_at'],
    'cleared'          => ['label'=>'Cleared',         'color'=>'success',  'icon'=>'fa-circle-check',       'next'=>'in_transit_road', 'next_label'=>'Dispatch Road Transit',  'next_field'=>'dispatched_road_at'],
    'in_transit_road'  => ['label'=>'Road Transit',    'color'=>'info',     'icon'=>'fa-truck',              'next'=>'arrived_yard',    'next_label'=>'Mark Arrived at Yard',   'next_field'=>'arrived_yard_at'],
    'arrived_yard'     => ['label'=>'Arrived Yard',    'color'=>'success',  'icon'=>'fa-warehouse',          'next'=>'intake',          'next_label'=>'Start Intake',           'next_field'=>'intake_at'],
    'intake'           => ['label'=>'In Intake',       'color'=>'primary',  'icon'=>'fa-clipboard-check',   'next'=>'completed',       'next_label'=>'Mark Completed',         'next_field'=>'completed_at'],
    'completed'        => ['label'=>'Completed',       'color'=>'dark',     'icon'=>'fa-flag-checkered',    'next'=>null,              'next_label'=>null,                     'next_field'=>null],
];
$stageOrder = array_keys($stages);

// Costs
$costs = $db->prepare("SELECT * FROM import_costs WHERE import_id=? ORDER BY created_at ASC");
$costs->execute([$id]); $costs = $costs->fetchAll();
$totalKes = array_sum(array_column($costs,'amount_kes'));
$purchaseKes = (float)($imp['purchase_price_kes'] ?? 0);
$landedTotal = $purchaseKes + $totalKes;

$costTypes = [
    'freight'           => 'Freight / Shipping',
    'marine_insurance'  => 'Marine Insurance',
    'cif_value'         => 'CIF Value',
    'import_duty'       => 'Import Duty',
    'excise_duty'       => 'Excise Duty',
    'vat'               => 'VAT at Customs',
    'idf_fee'           => 'IDF Fee',
    'port_charges'      => 'Port Charges / Wharfage',
    'inspection'        => 'Pre-Shipment Inspection',
    'clearing_agent'    => 'Clearing Agent Fees',
    'inland_transport'  => 'Inland Transport (Mombasa→Yard)',
    'other'             => 'Other',
];

// POST handler
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // ── Advance stage ──────────────────────────────────────────────────────────
    if ($action === 'advance_stage' && canWrite('imports')) {
        $cur = $imp['stage'];
        $nextStage = $stages[$cur]['next'] ?? null;
        if ($nextStage) {
            $field = $stages[$cur]['next_field'];
            $date  = $_POST['stage_date'] ?? date('Y-m-d');
            $db->prepare("UPDATE car_imports SET stage=?, {$field}=?, updated_at=NOW() WHERE id=?")
               ->execute([$nextStage, $date, $id]);
            logActivity('update','imports',$id,"Stage advanced to {$nextStage}");
            setFlash('success', "Import moved to: ".$stages[$nextStage]['label']);
        }
        redirect(BASE_URL.'/modules/imports/view.php?id='.$id);
    }

    // ── Add cost ───────────────────────────────────────────────────────────────
    if ($action === 'add_cost' && canWrite('imports')) {
        $costType  = $_POST['cost_type']    ?? 'other';
        $amount    = (float)($_POST['amount']   ?? 0);
        $currency  = $_POST['cost_currency']?? 'KES';
        $rate      = (float)($_POST['cost_rate']?? 1.0);
        $amtKes    = round($amount * $rate, 2);
        $desc      = trim($_POST['cost_desc']   ?? '');
        $receipt   = trim($_POST['receipt_ref'] ?? '');
        $paidAt    = ($_POST['paid_at'] ?? '') ?: null;
        if ($amount > 0) {
            $db->prepare("INSERT INTO import_costs (import_id,cost_type,amount,currency,exchange_rate,amount_kes,description,receipt_ref,paid_at,created_by)
                VALUES (?,?,?,?,?,?,?,?,?,?)")
               ->execute([$id,$costType,$amount,$currency,$rate,$amtKes,$desc,$receipt,$paidAt,$user['id']]);
            logActivity('create','imports',$id,"Cost added: $costType ".number_format($amtKes));
            setFlash('success','Cost added.');
        }
        redirect(BASE_URL.'/modules/imports/view.php?id='.$id.'#costs');
    }

    // ── Delete cost ────────────────────────────────────────────────────────────
    if ($action === 'delete_cost' && canWrite('imports')) {
        $cid = (int)($_POST['cost_id'] ?? 0);
        if ($cid) $db->prepare("DELETE FROM import_costs WHERE id=? AND import_id=?")->execute([$cid,$id]);
        setFlash('success','Cost removed.');
        redirect(BASE_URL.'/modules/imports/view.php?id='.$id.'#costs');
    }

    // ── Create car record from import ──────────────────────────────────────────
    if ($action === 'create_car' && canWrite('imports') && canWrite('cars')) {
        if ($imp['car_id']) { setFlash('error','Car record already exists.'); redirect(BASE_URL.'/modules/imports/view.php?id='.$id); }
        $locId = (int)($_POST['location_id'] ?? 1);
        $db->beginTransaction();
        try {
            // Insert car
            $db->prepare("INSERT INTO cars (chassis_number,engine_number,make,model,year,color,body_type,transmission,fuel_type,engine_cc,mileage,car_type,status,location_id,notes,created_at)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,'inventory','arrived',?,?,NOW())")
               ->execute([
                   $imp['chassis_number'],$imp['engine_number'],$imp['make'],$imp['model'],
                   $imp['year'],$imp['color'],$imp['body_type'],$imp['transmission'],$imp['fuel_type'],
                   $imp['engine_cc'],$imp['mileage'],$locId,
                   "Imported via ".$imp['ref'].($imp['notes']?"\n".$imp['notes']:'')
               ]);
            $carId = $db->lastInsertId();

            // Insert / update car_costs (map import cost types to car_costs columns)
            $freight=0; $marine=0; $port=0; $duty=0; $clearing=0; $transport=0; $other=0;
            foreach($costs as $c) {
                switch($c['cost_type']) {
                    case 'freight':          $freight  += $c['amount_kes']; break;
                    case 'marine_insurance': $marine   += $c['amount_kes']; break;
                    case 'port_charges':     $port     += $c['amount_kes']; break;
                    case 'import_duty':
                    case 'excise_duty':
                    case 'vat':
                    case 'idf_fee':
                    case 'cif_value':        $duty     += $c['amount_kes']; break;
                    case 'clearing_agent':   $clearing += $c['amount_kes']; break;
                    case 'inland_transport': $transport+= $c['amount_kes']; break;
                    default:                 $other    += $c['amount_kes']; break;
                }
            }
            $db->prepare("INSERT INTO car_costs
                (car_id,purchase_price,freight,marine_insurance,port_charges,duty_tax,clearing_fees,transport_to_yard,other_costs,currency,notes,recorded_by)
                VALUES (?,?,?,?,?,?,?,?,?,'KES',?,?)
                ON DUPLICATE KEY UPDATE
                purchase_price=VALUES(purchase_price),freight=VALUES(freight),marine_insurance=VALUES(marine_insurance),
                port_charges=VALUES(port_charges),duty_tax=VALUES(duty_tax),clearing_fees=VALUES(clearing_fees),
                transport_to_yard=VALUES(transport_to_yard),other_costs=VALUES(other_costs),notes=VALUES(notes)")
               ->execute([$carId,$purchaseKes,$freight,$marine,$port,$duty,$clearing,$transport,$other,
                          "Costs from import ".$imp['ref'],$user['id']]);

            // Link import → car
            $db->prepare("UPDATE car_imports SET car_id=?, stage='intake', intake_at=NOW() WHERE id=?")->execute([$carId,$id]);
            $db->commit();
            logActivity('create','cars',$carId,"Car created from import ".$imp['ref']);
            setFlash('success','Car record created. Costs transferred to car costing sheet.');
            redirect(BASE_URL.'/modules/cars/view.php?id='.$carId);
        } catch(\Throwable $e) {
            $db->rollBack();
            setFlash('error','Failed to create car: '.$e->getMessage());
            redirect(BASE_URL.'/modules/imports/view.php?id='.$id);
        }
    }
}

$cur = $imp['stage'];
$curStageInfo  = $stages[$cur];
$locations     = $db->query("SELECT id,name FROM locations ORDER BY name")->fetchAll();

$pageTitle = $imp['ref'].' — Import';
include __DIR__ . '/../../includes/header.php';
?>

<!-- Header -->
<div class="d-flex justify-content-between align-items-start mb-3 flex-wrap gap-2">
    <div>
        <h5 class="mb-1">
            <i class="fa <?= $curStageInfo['icon'] ?> me-2 text-<?= $curStageInfo['color'] ?>"></i>
            <?= e($imp['ref']) ?> — <?= e($imp['make'].' '.$imp['model']) ?>
            <span class="badge bg-<?= $curStageInfo['color'] ?> ms-2"><?= $curStageInfo['label'] ?></span>
        </h5>
        <div class="text-muted small">
            <?= $imp['year'] ?> · <?= e(ucfirst($imp['transmission'] ?? '')) ?> · <?= e(ucfirst($imp['fuel_type'] ?? '')) ?>
            <?php if($imp['chassis_number']): ?> · <span class="font-monospace"><?= e($imp['chassis_number']) ?></span><?php endif; ?>
        </div>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <?php if(canWrite('imports')): ?>
        <a href="edit.php?id=<?= $id ?>" class="btn btn-sm btn-outline-secondary"><i class="fa fa-pen me-1"></i>Edit</a>
        <?php endif; ?>
        <a href="index.php" class="btn btn-sm btn-outline-secondary"><i class="fa fa-arrow-left me-1"></i>Back</a>
    </div>
</div>

<!-- Stage stepper -->
<div class="card mb-4">
    <div class="card-body py-3">
        <div class="d-flex align-items-center gap-0 overflow-auto" style="min-width:600px">
            <?php foreach($stageOrder as $i => $sk):
                $s    = $stages[$sk];
                $done = array_search($sk,$stageOrder) < array_search($cur,$stageOrder);
                $active = $sk === $cur;
                $col  = $done ? 'success' : ($active ? $s['color'] : 'light');
                $tcol = $done ? 'white' : ($active ? 'white' : 'muted');
            ?>
            <div class="text-center flex-fill" style="min-width:80px">
                <div class="d-flex align-items-center">
                    <?php if($i > 0): ?><div style="flex:1;height:2px;background:<?= $done ? '#198754' : '#dee2e6' ?>"></div><?php endif; ?>
                    <div class="rounded-circle bg-<?= $col ?> d-inline-flex align-items-center justify-content-center mx-auto"
                         style="width:34px;height:34px;flex-shrink:0;<?= $active ? 'box-shadow:0 0 0 3px rgba(var(--bs-'.$s['color'].'-rgb),.25)' : '' ?>">
                        <i class="fa <?= $s['icon'] ?> text-<?= $tcol ?>" style="font-size:13px"></i>
                    </div>
                    <?php if($i < count($stageOrder)-1): ?><div style="flex:1;height:2px;background:<?= $done ? '#198754' : '#dee2e6' ?>"></div><?php endif; ?>
                </div>
                <div class="mt-1" style="font-size:10px;font-weight:<?= $active ? '700' : '500' ?>;color:<?= $active ? 'inherit' : '#94a3b8' ?>">
                    <?= $s['label'] ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<div class="row g-4">
    <!-- Left column: details + stage action -->
    <div class="col-lg-7">

        <!-- Stage advance -->
        <?php if($curStageInfo['next'] && canWrite('imports')): ?>
        <div class="card mb-4" style="border-top:3px solid var(--bs-<?= $curStageInfo['color'] ?>)">
            <div class="card-body">
                <div class="d-flex align-items-center gap-3 flex-wrap">
                    <i class="fa <?= $stages[$curStageInfo['next']]['icon'] ?> fa-2x text-<?= $stages[$curStageInfo['next']]['color'] ?>"></i>
                    <div class="flex-fill">
                        <div class="fw-bold">Next Step: <?= $stages[$curStageInfo['next']]['label'] ?></div>
                        <div class="text-muted small">Advance this import to the next stage of the pipeline</div>
                    </div>
                    <form method="POST" class="d-flex gap-2 align-items-center flex-wrap">
                        <input type="hidden" name="action" value="advance_stage">
                        <div>
                            <label class="form-label small mb-1">Date</label>
                            <input type="date" name="stage_date" class="form-control form-control-sm" value="<?= date('Y-m-d') ?>">
                        </div>
                        <button class="btn btn-<?= $stages[$curStageInfo['next']]['color'] ?> align-self-end">
                            <i class="fa fa-arrow-right me-1"></i><?= $curStageInfo['next_label'] ?>
                        </button>
                    </form>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Create Car Record -->
        <?php if(in_array($cur,['arrived_yard','intake','completed']) && !$imp['car_id'] && canWrite('cars') && canWrite('imports')): ?>
        <div class="card mb-4" style="border-top:3px solid #198754">
            <div class="card-header bg-success bg-opacity-10 fw-semibold text-success">
                <i class="fa fa-car me-2"></i>Create Car Record
            </div>
            <div class="card-body">
                <p class="text-muted small mb-3">This import has arrived. Create a car record in the system to assign for assessment, workshop, and eventually sale.</p>
                <form method="POST" class="d-flex gap-3 align-items-end flex-wrap">
                    <input type="hidden" name="action" value="create_car">
                    <div>
                        <label class="form-label small mb-1">Assign to Location</label>
                        <select name="location_id" class="form-select form-select-sm" style="width:200px">
                            <?php foreach($locations as $loc): ?>
                            <option value="<?= $loc['id'] ?>"><?= e($loc['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button class="btn btn-success" onclick="return confirm('Create car record from this import?')">
                        <i class="fa fa-plus me-1"></i>Create Car Record &amp; Transfer Costs
                    </button>
                </form>
            </div>
        </div>
        <?php elseif($imp['car_id']): ?>
        <div class="alert alert-success d-flex align-items-center gap-2 mb-4">
            <i class="fa fa-circle-check"></i>
            <span>Car record created: <a href="../cars/view.php?id=<?= $imp['car_id'] ?>" class="fw-bold">View Car</a></span>
        </div>
        <?php endif; ?>

        <!-- Vehicle details -->
        <div class="card mb-4">
            <div class="card-header fw-semibold"><i class="fa fa-car me-2"></i>Vehicle Details</div>
            <div class="card-body">
                <div class="row g-2" style="font-size:13px">
                    <?php foreach([
                        ['Make',         $imp['make']],
                        ['Model',        $imp['model']],
                        ['Year',         $imp['year']],
                        ['Color',        $imp['color']],
                        ['Body Type',    $imp['body_type']],
                        ['Transmission', ucfirst($imp['transmission']??'')],
                        ['Fuel Type',    ucfirst($imp['fuel_type']??'')],
                        ['Engine CC',    $imp['engine_cc'] ? number_format($imp['engine_cc']).'cc' : null],
                        ['Mileage',      $imp['mileage']   ? number_format($imp['mileage']).' km'  : null],
                        ['Chassis No.',  $imp['chassis_number']],
                        ['Engine No.',   $imp['engine_number']],
                    ] as [$lbl,$val]): if(!$val) continue; ?>
                    <div class="col-6"><span class="text-muted"><?= $lbl ?>:</span></div>
                    <div class="col-6 fw-semibold"><?= e($val) ?></div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <!-- Purchase details -->
        <div class="card mb-4">
            <div class="card-header fw-semibold"><i class="fa fa-file-invoice-dollar me-2 text-success"></i>Purchase Details</div>
            <div class="card-body">
                <div class="row g-2" style="font-size:13px">
                    <?php foreach([
                        ['Supplier',        $imp['supplier_name']],
                        ['Auction Ref',     $imp['auction_ref']],
                        ['Purchase Date',   $imp['purchase_date'] ? fmtDate($imp['purchase_date'],'d M Y') : null],
                        ['Purchase Price',  $imp['purchase_price']  ? number_format($imp['purchase_price'],2).' '.$imp['purchase_currency'] : null],
                        ['Exchange Rate',   $imp['exchange_rate']   ? '1 '.$imp['purchase_currency'].' = KES '.number_format($imp['exchange_rate'],4) : null],
                        ['Purchase (KES)',  $imp['purchase_price_kes'] ? 'KES '.number_format($imp['purchase_price_kes']) : null],
                        ['IDF Number',      $imp['idf_number']],
                        ['IDF Date',        $imp['idf_date'] ? fmtDate($imp['idf_date'],'d M Y') : null],
                    ] as [$lbl,$val]): if(!$val) continue; ?>
                    <div class="col-6"><span class="text-muted"><?= $lbl ?>:</span></div>
                    <div class="col-6 fw-semibold"><?= e($val) ?></div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <!-- Shipment -->
        <?php if($imp['ship_ref']): ?>
        <div class="card mb-4">
            <div class="card-header fw-semibold"><i class="fa fa-boxes-stacked me-2"></i>Shipment</div>
            <div class="card-body" style="font-size:13px">
                <div class="row g-2">
                    <div class="col-6 text-muted">Ref:</div><div class="col-6 fw-semibold"><a href="shipment_view.php?id=<?= $imp['shipment_id'] ?>"><?= e($imp['ship_ref']) ?></a></div>
                    <?php if($imp['vessel_name']): ?><div class="col-6 text-muted">Vessel:</div><div class="col-6"><?= e($imp['vessel_name']) ?></div><?php endif; ?>
                    <?php if($imp['bl_number']): ?><div class="col-6 text-muted">B/L No.:</div><div class="col-6 font-monospace"><?= e($imp['bl_number']) ?></div><?php endif; ?>
                    <?php if($imp['origin_country']): ?><div class="col-6 text-muted">Origin:</div><div class="col-6"><?= e($imp['origin_country']) ?></div><?php endif; ?>
                    <?php if($imp['eta']): ?><div class="col-6 text-muted">ETA Mombasa:</div><div class="col-6"><?= fmtDate($imp['eta'],'d M Y') ?></div><?php endif; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Stage timeline -->
        <div class="card">
            <div class="card-header fw-semibold"><i class="fa fa-timeline me-2"></i>Stage Timeline</div>
            <div class="card-body p-0">
                <?php foreach($stageOrder as $sk):
                    $sdt = null;
                    switch($sk) {
                        case 'purchased':       $sdt = $imp['purchased_at'];       break;
                        case 'in_transit_sea':  $sdt = $imp['shipped_at'];         break;
                        case 'arrived_port':    $sdt = $imp['arrived_port_at'];    break;
                        case 'customs':         $sdt = $imp['customs_start_at'];   break;
                        case 'cleared':         $sdt = $imp['cleared_at'];         break;
                        case 'in_transit_road': $sdt = $imp['dispatched_road_at']; break;
                        case 'arrived_yard':    $sdt = $imp['arrived_yard_at'];    break;
                        case 'intake':          $sdt = $imp['intake_at'];          break;
                        case 'completed':       $sdt = $imp['completed_at'];       break;
                    }
                    $s    = $stages[$sk];
                    $done = array_search($sk,$stageOrder) <= array_search($cur,$stageOrder);
                ?>
                <div class="d-flex align-items-center gap-3 px-3 py-2 border-bottom" style="font-size:13px">
                    <i class="fa <?= $s['icon'] ?> text-<?= $done ? $s['color'] : 'secondary' ?>" style="width:18px"></i>
                    <div class="flex-fill">
                        <span class="fw-semibold <?= !$done ? 'text-muted' : '' ?>"><?= $s['label'] ?></span>
                    </div>
                    <div class="text-muted small">
                        <?= $sdt ? fmtDate($sdt,'d M Y') : ($done ? 'Date not recorded' : '—') ?>
                    </div>
                    <?php if($done && !$sdt): ?><span class="badge bg-light text-warning border" style="font-size:10px">No date</span><?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- Right column: Costs -->
    <div class="col-lg-5">

        <!-- Landed cost summary -->
        <div class="card mb-4" style="border-top:3px solid #0d6efd">
            <div class="card-header fw-semibold"><i class="fa fa-calculator me-2 text-primary"></i>Landed Cost Summary</div>
            <div class="card-body">
                <div class="d-flex justify-content-between small mb-2">
                    <span class="text-muted">Purchase Price (KES)</span>
                    <span class="fw-semibold">KES <?= number_format($purchaseKes, 2) ?></span>
                </div>
                <?php foreach($costs as $c): ?>
                <div class="d-flex justify-content-between small mb-1">
                    <span class="text-muted"><?= e($costTypes[$c['cost_type']] ?? $c['cost_type']) ?></span>
                    <span>KES <?= number_format($c['amount_kes'], 2) ?></span>
                </div>
                <?php endforeach; ?>
                <hr class="my-2">
                <div class="d-flex justify-content-between">
                    <span class="fw-bold">Total Landed Cost</span>
                    <span class="fw-bold text-primary fs-5">KES <?= number_format($landedTotal, 2) ?></span>
                </div>
            </div>
        </div>

        <!-- Cost records -->
        <div class="card mb-4" id="costs">
            <div class="card-header fw-semibold"><i class="fa fa-receipt me-2"></i>Import Costs (<?= count($costs) ?>)</div>
            <?php if($costs): ?>
            <div class="card-body p-0">
                <table class="table table-sm mb-0" style="font-size:12px">
                    <thead style="background:#f8fafc"><tr><th class="ps-3">Type</th><th>Amount</th><th>KES</th><th class="pe-2"></th></tr></thead>
                    <tbody>
                    <?php foreach($costs as $c): ?>
                    <tr>
                        <td class="ps-3">
                            <div class="fw-semibold"><?= e($costTypes[$c['cost_type']] ?? $c['cost_type']) ?></div>
                            <?php if($c['description']): ?><div class="text-muted"><?= e($c['description']) ?></div><?php endif; ?>
                        </td>
                        <td><?= number_format($c['amount'],2) ?> <?= e($c['currency']) ?></td>
                        <td class="fw-semibold">KES <?= number_format($c['amount_kes'],2) ?></td>
                        <td class="pe-2">
                            <?php if(canWrite('imports')): ?>
                            <form method="POST" onsubmit="return confirm('Remove this cost?')">
                                <input type="hidden" name="action" value="delete_cost">
                                <input type="hidden" name="cost_id" value="<?= $c['id'] ?>">
                                <button class="btn btn-xs btn-outline-danger"><i class="fa fa-trash"></i></button>
                            </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>

        <!-- Add cost form -->
        <?php if(canWrite('imports')): ?>
        <div class="card" style="border-top:3px solid #198754">
            <div class="card-header fw-semibold text-success"><i class="fa fa-plus me-2"></i>Add Import Cost</div>
            <div class="card-body">
                <form method="POST" class="row g-2">
                    <input type="hidden" name="action" value="add_cost">
                    <div class="col-12">
                        <label class="form-label small">Cost Type</label>
                        <select name="cost_type" class="form-select form-select-sm" required>
                            <?php foreach($costTypes as $k=>$l): ?><option value="<?= $k ?>"><?= $l ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-6">
                        <label class="form-label small">Currency</label>
                        <select name="cost_currency" class="form-select form-select-sm" id="costCurr">
                            <?php foreach(['KES'=>'KES','USD'=>'USD','JPY'=>'JPY','GBP'=>'GBP','AED'=>'AED','EUR'=>'EUR'] as $c=>$l): ?>
                            <option value="<?= $c ?>" <?= $c==='KES'?'selected':'' ?>><?= $l ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-6">
                        <label class="form-label small">Amount</label>
                        <input type="number" name="amount" id="costAmt" class="form-control form-control-sm" step="0.01" placeholder="0.00" required>
                    </div>
                    <div class="col-6">
                        <label class="form-label small">Rate → KES</label>
                        <input type="number" name="cost_rate" id="costRate" class="form-control form-control-sm" value="1" step="0.0001">
                    </div>
                    <div class="col-6">
                        <label class="form-label small">= KES</label>
                        <input type="text" id="costKesPreview" class="form-control form-control-sm" readonly style="background:#f8fafc">
                    </div>
                    <div class="col-12">
                        <label class="form-label small">Description</label>
                        <input type="text" name="cost_desc" class="form-control form-control-sm" placeholder="Optional description">
                    </div>
                    <div class="col-sm-6">
                        <label class="form-label small">Receipt / Ref</label>
                        <input type="text" name="receipt_ref" class="form-control form-control-sm" placeholder="Receipt no.">
                    </div>
                    <div class="col-sm-6">
                        <label class="form-label small">Paid Date</label>
                        <input type="date" name="paid_at" class="form-control form-control-sm">
                    </div>
                    <div class="col-12">
                        <button type="submit" class="btn btn-success w-100 btn-sm"><i class="fa fa-save me-1"></i>Add Cost</button>
                    </div>
                </form>
            </div>
        </div>
        <?php endif; ?>

    </div>
</div>

<script>
const costRates = { KES:1, USD:130, JPY:0.65, GBP:165, AED:35.4, EUR:142 };
function updateCostKes() {
    const amt  = parseFloat(document.getElementById('costAmt')?.value)  || 0;
    const rate = parseFloat(document.getElementById('costRate')?.value) || 1;
    const el   = document.getElementById('costKesPreview');
    if(el) el.value = 'KES '+(amt*rate).toLocaleString('en-KE',{minimumFractionDigits:2,maximumFractionDigits:2});
}
document.getElementById('costCurr')?.addEventListener('change', function() {
    const rate = costRates[this.value] || 1;
    document.getElementById('costRate').value = rate;
    updateCostKes();
});
document.getElementById('costAmt')?.addEventListener('input', updateCostKes);
document.getElementById('costRate')?.addEventListener('input', updateCostKes);
</script>
<?php include __DIR__ . '/../../includes/footer.php'; ?>

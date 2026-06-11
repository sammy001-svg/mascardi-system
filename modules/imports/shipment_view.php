<?php
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
canAccess('imports') || die('Access denied.');
$db   = getDB();
$user = authUser();
$id   = (int)($_GET['id'] ?? 0);

$ship = $db->prepare("SELECT * FROM car_shipments WHERE id=?");
$ship->execute([$id]); $ship = $ship->fetch();
if (!$ship) { setFlash('error','Shipment not found.'); redirect(BASE_URL.'/modules/imports/index.php'); }

$stages = [
    'purchased'       =>['label'=>'Purchased',    'color'=>'secondary','icon'=>'fa-file-invoice-dollar'],
    'in_transit_sea'  =>['label'=>'At Sea',       'color'=>'info',     'icon'=>'fa-ship'],
    'arrived_port'    =>['label'=>'Arrived Port', 'color'=>'primary',  'icon'=>'fa-anchor'],
    'customs'         =>['label'=>'Customs',      'color'=>'warning',  'icon'=>'fa-stamp'],
    'cleared'         =>['label'=>'Cleared',      'color'=>'success',  'icon'=>'fa-circle-check'],
    'in_transit_road' =>['label'=>'Road Transit', 'color'=>'info',     'icon'=>'fa-truck'],
    'arrived_yard'    =>['label'=>'Arrived Yard', 'color'=>'success',  'icon'=>'fa-warehouse'],
    'intake'          =>['label'=>'In Intake',    'color'=>'primary',  'icon'=>'fa-clipboard-check'],
    'completed'       =>['label'=>'Completed',    'color'=>'dark',     'icon'=>'fa-flag-checkered'],
];

$shipStatuses = ['pending','at_sea','arrived_port','customs','cleared','closed'];

// POST: update shipment status or actual arrival
if ($_SERVER['REQUEST_METHOD'] === 'POST' && canWrite('imports')) {
    $action = $_POST['action'] ?? '';
    if ($action === 'update_status') {
        $newStatus     = $_POST['status']         ?? $ship['status'];
        $actualArrival = ($_POST['actual_arrival']?? '') ?: null;
        $db->prepare("UPDATE car_shipments SET status=?, actual_arrival=?, updated_at=NOW() WHERE id=?")
           ->execute([$newStatus, $actualArrival, $id]);
        setFlash('success','Shipment updated.');
    }
    redirect(BASE_URL.'/modules/imports/shipment_view.php?id='.$id);
}

// Cars in this shipment
$cars = $db->prepare("
    SELECT i.*,
           COALESCE((SELECT SUM(ic.amount_kes) FROM import_costs ic WHERE ic.import_id=i.id),0) AS import_costs_kes
    FROM car_imports i WHERE i.shipment_id=? ORDER BY i.created_at ASC
");
$cars->execute([$id]); $cars = $cars->fetchAll();
$totalLanded = array_sum(array_column($cars,'purchase_price_kes')) + array_sum(array_column($cars,'import_costs_kes'));

$statusColors = ['pending'=>'secondary','at_sea'=>'info','arrived_port'=>'primary','customs'=>'warning','cleared'=>'success','closed'=>'dark'];
$statusLabels = ['pending'=>'Pending','at_sea'=>'At Sea','arrived_port'=>'Arrived Port','customs'=>'In Customs','cleared'=>'Cleared','closed'=>'Closed'];

$pageTitle = $ship['ref'].' — Shipment';
include __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div>
        <h5 class="mb-1">
            <i class="fa fa-ship me-2 text-primary"></i><?= e($ship['ref']) ?>
            <?php if($ship['name']): ?><span class="text-muted fw-normal">— <?= e($ship['name']) ?></span><?php endif; ?>
            <span class="badge bg-<?= $statusColors[$ship['status']] ?? 'secondary' ?> ms-2"><?= $statusLabels[$ship['status']] ?? $ship['status'] ?></span>
        </h5>
        <div class="text-muted small">
            <?= e($ship['origin_country']) ?>
            <?php if($ship['vessel_name']): ?> · <?= e($ship['vessel_name']) ?><?php endif; ?>
            <?php if($ship['bl_number']): ?> · B/L: <span class="font-monospace"><?= e($ship['bl_number']) ?></span><?php endif; ?>
        </div>
    </div>
    <a href="index.php" class="btn btn-sm btn-outline-secondary"><i class="fa fa-arrow-left me-1"></i>Back</a>
</div>

<div class="row g-4 mb-4">
    <!-- Shipment info -->
    <div class="col-lg-4">
        <div class="card h-100">
            <div class="card-header fw-semibold"><i class="fa fa-info-circle me-2"></i>Shipment Info</div>
            <div class="card-body" style="font-size:13px">
                <div class="row g-2">
                    <?php foreach([
                        ['Ref',              $ship['ref']],
                        ['Origin',           $ship['origin_country']],
                        ['Shipping Line',    $ship['shipping_line']],
                        ['Vessel',           $ship['vessel_name']],
                        ['B/L Number',       $ship['bl_number']],
                        ['ETD',              $ship['etd']  ? fmtDate($ship['etd'],'d M Y')  : null],
                        ['ETA Mombasa',      $ship['eta']  ? fmtDate($ship['eta'],'d M Y')  : null],
                        ['Actual Arrival',   $ship['actual_arrival'] ? fmtDate($ship['actual_arrival'],'d M Y') : 'Not yet arrived'],
                    ] as [$lbl,$val]): if(!$val) continue; ?>
                    <div class="col-5 text-muted"><?= $lbl ?>:</div>
                    <div class="col-7 fw-semibold"><?= e($val) ?></div>
                    <?php endforeach; ?>
                </div>
                <?php if($ship['notes']): ?>
                <hr class="my-2"><div class="text-muted small"><?= nl2br(e($ship['notes'])) ?></div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Stats -->
    <div class="col-lg-4">
        <div class="card h-100">
            <div class="card-header fw-semibold"><i class="fa fa-chart-bar me-2"></i>Summary</div>
            <div class="card-body">
                <div class="row g-3 text-center">
                    <div class="col-6">
                        <div class="fs-3 fw-bold text-primary"><?= count($cars) ?></div>
                        <div class="text-muted small">Total Vehicles</div>
                    </div>
                    <div class="col-6">
                        <div class="fs-3 fw-bold text-success"><?= count(array_filter($cars, fn($c)=>$c['stage']==='completed')) ?></div>
                        <div class="text-muted small">Completed</div>
                    </div>
                    <div class="col-6">
                        <div class="fs-3 fw-bold text-info"><?= count(array_filter($cars, fn($c)=>in_array($c['stage'],['in_transit_sea','in_transit_road']))) ?></div>
                        <div class="text-muted small">In Transit</div>
                    </div>
                    <div class="col-6">
                        <div class="fs-3 fw-bold text-warning"><?= count(array_filter($cars, fn($c)=>$c['stage']==='customs')) ?></div>
                        <div class="text-muted small">In Customs</div>
                    </div>
                </div>
                <hr class="my-2">
                <div class="d-flex justify-content-between small">
                    <span class="text-muted">Total Landed (KES)</span>
                    <span class="fw-bold">KES <?= number_format($totalLanded) ?></span>
                </div>
            </div>
        </div>
    </div>

    <!-- Update status -->
    <?php if(canWrite('imports')): ?>
    <div class="col-lg-4">
        <div class="card h-100">
            <div class="card-header fw-semibold"><i class="fa fa-arrows-rotate me-2"></i>Update Shipment Status</div>
            <div class="card-body">
                <form method="POST" class="row g-3">
                    <input type="hidden" name="action" value="update_status">
                    <div class="col-12">
                        <label class="form-label small">Status</label>
                        <select name="status" class="form-select form-select-sm">
                            <?php foreach($shipStatuses as $s): ?>
                            <option value="<?= $s ?>" <?= $ship['status']===$s?'selected':'' ?>><?= $statusLabels[$s] ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12">
                        <label class="form-label small">Actual Arrival Date</label>
                        <input type="date" name="actual_arrival" class="form-control form-control-sm" value="<?= e($ship['actual_arrival']) ?>">
                    </div>
                    <div class="col-12">
                        <button type="submit" class="btn btn-sm btn-primary w-100"><i class="fa fa-save me-1"></i>Update</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- Vehicles in this shipment -->
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="fa fa-cars me-2"></i>Vehicles in this Shipment (<?= count($cars) ?>)</span>
        <?php if(canWrite('imports')): ?>
        <a href="add.php?shipment_id=<?= $id ?>" class="btn btn-sm btn-outline-primary"><i class="fa fa-plus me-1"></i>Add Vehicle</a>
        <?php endif; ?>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0" style="font-size:13px">
                <thead style="background:#f8fafc">
                    <tr>
                        <th class="ps-3">Ref</th>
                        <th>Vehicle</th>
                        <th>Chassis</th>
                        <th>Stage</th>
                        <th>Purchase (KES)</th>
                        <th>Import Costs</th>
                        <th>Landed Total</th>
                        <th class="pe-3"></th>
                    </tr>
                </thead>
                <tbody>
                <?php if($cars): foreach($cars as $c):
                    $s = $stages[$c['stage']] ?? ['label'=>$c['stage'],'color'=>'secondary','icon'=>'fa-circle'];
                    $landed = ($c['purchase_price_kes']??0) + ($c['import_costs_kes']??0);
                ?>
                <tr>
                    <td class="ps-3 fw-bold"><a href="view.php?id=<?= $c['id'] ?>"><?= e($c['ref']) ?></a></td>
                    <td>
                        <div class="fw-semibold"><?= e($c['make'].' '.$c['model']) ?></div>
                        <div class="text-muted small"><?= $c['year'] ?></div>
                    </td>
                    <td class="font-monospace small"><?= $c['chassis_number'] ? e($c['chassis_number']) : '—' ?></td>
                    <td><span class="badge bg-<?= $s['color'] ?>"><i class="fa <?= $s['icon'] ?> me-1"></i><?= $s['label'] ?></span></td>
                    <td><?= $c['purchase_price_kes'] ? 'KES '.number_format($c['purchase_price_kes']) : '—' ?></td>
                    <td><?= $c['import_costs_kes'] > 0 ? 'KES '.number_format($c['import_costs_kes']) : '—' ?></td>
                    <td class="fw-semibold"><?= $landed > 0 ? 'KES '.number_format($landed) : '—' ?></td>
                    <td class="pe-3"><a href="view.php?id=<?= $c['id'] ?>" class="btn btn-xs btn-outline-primary">View</a></td>
                </tr>
                <?php endforeach; else: ?>
                <tr><td colspan="8" class="text-center text-muted py-4">No vehicles in this shipment yet. <a href="add.php?shipment_id=<?= $id ?>">Add one</a></td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>

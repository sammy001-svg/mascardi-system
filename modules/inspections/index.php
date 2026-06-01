<?php
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
canAccess('inspections') || redirect(BASE_URL . '/index.php');

$pageTitle = 'Inspection Checklists';
$db = getDB();

$filterType   = $_GET['type']   ?? '';
$filterStatus = $_GET['status'] ?? '';

$where  = ['1=1'];
$params = [];
if ($filterType)   { $where[] = 'cl.checklist_type = ?'; $params[] = $filterType; }
if ($filterStatus) { $where[] = 'cl.status = ?';         $params[] = $filterStatus; }
$whereStr = implode(' AND ', $where);

try {
    $checklists = $db->prepare("
        SELECT cl.*, c.make, c.model, c.year, c.chassis_number, c.registration_number,
               cs.sale_number, cs.buyer_name,
               u.name AS inspector_name,
               a.name AS approved_by_name,
               COUNT(i.id)               AS total_items,
               SUM(i.result = 'ok')      AS ok_count,
               SUM(i.result = 'fail')    AS fail_count,
               SUM(i.result = 'na')      AS na_count
        FROM inspection_checklists cl
        JOIN cars c ON c.id = cl.car_id
        LEFT JOIN car_sales cs ON cs.id = cl.sale_id
        LEFT JOIN users u ON u.id = cl.inspector_id
        LEFT JOIN users a ON a.id = cl.approved_by
        LEFT JOIN inspection_items i ON i.checklist_id = cl.id
        WHERE $whereStr
        GROUP BY cl.id
        ORDER BY cl.created_at DESC
    ");
    $checklists->execute($params);
    $checklists = $checklists->fetchAll();
} catch (\Throwable $e) { $checklists = []; }

$typeLabels   = ['pre_delivery'=>'Pre-Delivery','incoming'=>'Incoming','pre_sale'=>'Pre-Sale'];
$statusColors = ['draft'=>'secondary','submitted'=>'primary','approved'=>'success','failed'=>'danger'];

include __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="mb-0"><i class="fa fa-clipboard-check me-2 text-primary"></i>Inspection Checklists</h5>
    <?php if (canWrite('inspections')): ?>
    <a href="create.php" class="btn btn-sm btn-primary"><i class="fa fa-plus me-1"></i>New Checklist</a>
    <?php endif; ?>
</div>

<!-- Filter tabs -->
<div class="d-flex gap-2 mb-3 flex-wrap">
    <div class="d-flex gap-1">
        <a href="?" class="btn btn-sm <?= !$filterStatus?'btn-primary':'btn-outline-secondary' ?>">All</a>
        <a href="?status=draft"     class="btn btn-sm <?= $filterStatus==='draft'    ?'btn-secondary':'btn-outline-secondary' ?>">Draft</a>
        <a href="?status=submitted" class="btn btn-sm <?= $filterStatus==='submitted'?'btn-primary'  :'btn-outline-secondary' ?>">Submitted</a>
        <a href="?status=approved"  class="btn btn-sm <?= $filterStatus==='approved' ?'btn-success'  :'btn-outline-secondary' ?>">Approved</a>
        <a href="?status=failed"    class="btn btn-sm <?= $filterStatus==='failed'   ?'btn-danger'   :'btn-outline-secondary' ?>">Failed</a>
    </div>
    <div class="vr"></div>
    <div class="d-flex gap-1">
        <a href="?" class="btn btn-sm <?= !$filterType?'btn-secondary':'btn-outline-secondary' ?>">All Types</a>
        <?php foreach ($typeLabels as $k => $lbl): ?>
        <a href="?type=<?= $k ?>&status=<?= $filterStatus ?>"
           class="btn btn-sm <?= $filterType===$k?'btn-info':'btn-outline-secondary' ?>"><?= $lbl ?></a>
        <?php endforeach; ?>
    </div>
</div>

<div class="card">
    <div class="card-body p-0">
        <table class="table table-hover datatable mb-0" style="font-size:13.5px">
            <thead>
                <tr>
                    <th class="ps-3">Vehicle</th>
                    <th>Type</th>
                    <th>Sale / Buyer</th>
                    <th>Inspector</th>
                    <th>Progress</th>
                    <th>Fails</th>
                    <th>Status</th>
                    <th>Date</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($checklists)): ?>
            <tr><td colspan="9" class="text-center py-5 text-muted">
                <i class="fa fa-clipboard fa-2x mb-2 d-block opacity-25"></i>No checklists found
            </td></tr>
            <?php endif; ?>
            <?php foreach ($checklists as $cl):
                $pct = $cl['total_items'] > 0
                    ? round(($cl['ok_count'] + $cl['na_count']) / $cl['total_items'] * 100)
                    : 0;
                $barColor = $cl['fail_count'] > 0 ? 'danger' : ($pct >= 100 ? 'success' : 'primary');
            ?>
            <tr>
                <td class="ps-3">
                    <a href="<?= BASE_URL ?>/modules/cars/view.php?id=<?= $cl['car_id'] ?>"
                       class="fw-semibold text-decoration-none">
                        <?= e($cl['make'].' '.$cl['model'].' '.$cl['year']) ?>
                    </a>
                    <div class="text-muted" style="font-size:11px">
                        <code><?= e($cl['chassis_number']) ?></code>
                    </div>
                </td>
                <td>
                    <span class="badge bg-info-subtle text-info border border-info-subtle" style="font-size:11px">
                        <?= $typeLabels[$cl['checklist_type']] ?? $cl['checklist_type'] ?>
                    </span>
                </td>
                <td class="small">
                    <?php if ($cl['sale_number']): ?>
                    <div class="fw-medium"><?= e($cl['buyer_name']) ?></div>
                    <div class="text-muted"><?= e($cl['sale_number']) ?></div>
                    <?php else: ?>
                    <span class="text-muted">—</span>
                    <?php endif; ?>
                </td>
                <td class="small text-muted"><?= e($cl['inspector_name'] ?? '—') ?></td>
                <td style="min-width:100px">
                    <div class="d-flex align-items-center gap-1">
                        <div class="progress flex-grow-1" style="height:5px">
                            <div class="progress-bar bg-<?= $barColor ?>" style="width:<?= $pct ?>%"></div>
                        </div>
                        <span class="text-muted" style="font-size:10px"><?= $pct ?>%</span>
                    </div>
                    <div class="text-muted" style="font-size:10px"><?= (int)$cl['ok_count'] ?> ok / <?= (int)$cl['total_items'] ?> items</div>
                </td>
                <td>
                    <?php if ($cl['fail_count'] > 0): ?>
                    <span class="badge bg-danger"><?= $cl['fail_count'] ?> fail<?= $cl['fail_count']>1?'s':'' ?></span>
                    <?php else: ?>
                    <span class="text-muted small">—</span>
                    <?php endif; ?>
                </td>
                <td>
                    <span class="badge bg-<?= $statusColors[$cl['status']] ?? 'secondary' ?>">
                        <?= ucfirst($cl['status']) ?>
                    </span>
                </td>
                <td class="small text-muted"><?= fmtDate($cl['created_at'],'d M Y') ?></td>
                <td class="pe-3">
                    <div class="d-flex gap-1">
                        <a href="view.php?id=<?= $cl['id'] ?>" class="btn btn-xs btn-outline-primary">View</a>
                        <a href="print.php?id=<?= $cl['id'] ?>" target="_blank" class="btn btn-xs btn-outline-secondary">
                            <i class="fa fa-print"></i>
                        </a>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>

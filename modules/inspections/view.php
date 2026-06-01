<?php
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
canAccess('inspections') || redirect(BASE_URL . '/index.php');

$pageTitle = 'Inspection Checklist';
$db  = getDB();
$id  = (int)($_GET['id'] ?? 0);
if (!$id) redirect(BASE_URL . '/modules/inspections/index.php');

$cl = $db->prepare("
    SELECT cl.*, c.make, c.model, c.year, c.chassis_number, c.registration_number,
           cs.sale_number, cs.buyer_name,
           u.name AS inspector_name,
           a.name AS approved_by_name
    FROM inspection_checklists cl
    JOIN cars c ON c.id = cl.car_id
    LEFT JOIN car_sales cs ON cs.id = cl.sale_id
    LEFT JOIN users u ON u.id = cl.inspector_id
    LEFT JOIN users a ON a.id = cl.approved_by
    WHERE cl.id = ?
");
$cl->execute([$id]); $cl = $cl->fetch();
if (!$cl) { setFlash('error','Checklist not found.'); redirect(BASE_URL.'/modules/inspections/index.php'); }

$pageTitle = 'Inspection — ' . $cl['make'] . ' ' . $cl['model'];

// Load items grouped by category
$items = $db->prepare("SELECT * FROM inspection_items WHERE checklist_id=? ORDER BY sort_order ASC");
$items->execute([$id]); $items = $items->fetchAll();

$grouped = [];
foreach ($items as $item) $grouped[$item['category']][] = $item;

$totalItems   = count($items);
$okCount      = count(array_filter($items, fn($i)=>$i['result']==='ok'));
$failCount    = count(array_filter($items, fn($i)=>$i['result']==='fail'));
$naCount      = count(array_filter($items, fn($i)=>$i['result']==='na'));
$pendingCount = count(array_filter($items, fn($i)=>$i['result']==='pending'));
$pctDone      = $totalItems > 0 ? round(($okCount+$failCount+$naCount)/$totalItems*100) : 0;

// ── POST handlers ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && canWrite('inspections')) {
    $action = $_POST['action'] ?? '';

    if ($action === 'save_items') {
        $results = $_POST['result']  ?? [];
        $notes   = $_POST['item_notes'] ?? [];
        foreach ($results as $itemId => $res) {
            $db->prepare("UPDATE inspection_items SET result=?, notes=? WHERE id=? AND checklist_id=?")
               ->execute([$res, $notes[$itemId] ?? null, (int)$itemId, $id]);
        }
        // Auto-set status
        $fails = $db->prepare("SELECT COUNT(*) FROM inspection_items WHERE checklist_id=? AND result='fail'");
        $fails->execute([$id]);
        $newStatus = (int)$fails->fetchColumn() > 0 ? 'failed' : ($pendingCount === 0 ? 'submitted' : 'draft');
        $db->prepare("UPDATE inspection_checklists SET status=?,updated_at=NOW() WHERE id=?")->execute([$newStatus,$id]);
        setFlash('success','Checklist saved.');
        redirect(BASE_URL.'/modules/inspections/view.php?id='.$id);
    }

    if ($action === 'approve' && hasRole(['admin','manager','workshop_manager'])) {
        $failsQ = $db->prepare("SELECT COUNT(*) FROM inspection_items WHERE checklist_id=? AND result='fail'");
        $failsQ->execute([$id]);
        if ((int)$failsQ->fetchColumn() > 0) {
            setFlash('error','Cannot approve — there are failed items. Resolve them first.');
        } else {
            $db->prepare("UPDATE inspection_checklists SET status='approved',approved_by=?,approved_at=NOW(),updated_at=NOW() WHERE id=?")
               ->execute([authUser()['id'],$id]);
            setFlash('success','Checklist approved.');
        }
        redirect(BASE_URL.'/modules/inspections/view.php?id='.$id);
    }

    if ($action === 'notes') {
        $db->prepare("UPDATE inspection_checklists SET overall_notes=?,updated_at=NOW() WHERE id=?")
           ->execute([trim($_POST['overall_notes']??''), $id]);
        redirect(BASE_URL.'/modules/inspections/view.php?id='.$id);
    }
}

$typeLabels   = ['pre_delivery'=>'Pre-Delivery','incoming'=>'Incoming','pre_sale'=>'Pre-Sale'];
$statusColors = ['draft'=>'secondary','submitted'=>'primary','approved'=>'success','failed'=>'danger'];
$resultColors = ['ok'=>'success','fail'=>'danger','na'=>'secondary','pending'=>'light'];
$resultIcons  = ['ok'=>'fa-circle-check','fail'=>'fa-circle-xmark','na'=>'fa-minus-circle','pending'=>'fa-circle'];

include __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div>
        <h5 class="mb-1">
            <i class="fa fa-clipboard-check me-2 text-primary"></i>
            <?= e($cl['make'].' '.$cl['model'].' '.$cl['year']) ?>
        </h5>
        <div class="d-flex gap-2 align-items-center flex-wrap">
            <span class="badge bg-info-subtle text-info border border-info-subtle">
                <?= $typeLabels[$cl['checklist_type']] ?? $cl['checklist_type'] ?>
            </span>
            <span class="badge bg-<?= $statusColors[$cl['status']] ?? 'secondary' ?>">
                <?= ucfirst($cl['status']) ?>
            </span>
            <span class="text-muted small"><code><?= e($cl['chassis_number']) ?></code></span>
            <?php if ($cl['sale_number']): ?>
            <span class="text-muted small">· <?= e($cl['sale_number']) ?> / <?= e($cl['buyer_name']) ?></span>
            <?php endif; ?>
        </div>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <?php if (canWrite('inspections') && in_array($cl['status'],['submitted']) && hasRole(['admin','manager','workshop_manager'])): ?>
        <form method="POST" class="d-inline">
            <input type="hidden" name="action" value="approve">
            <button class="btn btn-sm btn-success">
                <i class="fa fa-circle-check me-1"></i>Approve
            </button>
        </form>
        <?php endif; ?>
        <a href="print.php?id=<?= $id ?>" target="_blank" class="btn btn-sm btn-outline-secondary">
            <i class="fa fa-print me-1"></i>Print
        </a>
        <a href="index.php" class="btn btn-sm btn-outline-secondary">
            <i class="fa fa-arrow-left me-1"></i>Back
        </a>
    </div>
</div>

<!-- Progress bar -->
<div class="card mb-4">
    <div class="card-body py-3">
        <div class="row g-3 align-items-center">
            <div class="col-md-6">
                <div class="d-flex justify-content-between small text-muted mb-1">
                    <span>Completion</span>
                    <span><?= $okCount+$failCount+$naCount ?>/<?= $totalItems ?> items reviewed — <?= $pctDone ?>%</span>
                </div>
                <div class="progress" style="height:10px">
                    <div class="progress-bar bg-<?= $failCount>0?'danger':($pctDone>=100?'success':'primary') ?>"
                         style="width:<?= $pctDone ?>%"></div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="d-flex gap-3 justify-content-md-end">
                    <div class="text-center">
                        <div class="fw-bold text-success fs-5"><?= $okCount ?></div>
                        <div class="text-muted small">OK</div>
                    </div>
                    <div class="text-center">
                        <div class="fw-bold text-danger fs-5"><?= $failCount ?></div>
                        <div class="text-muted small">Fail</div>
                    </div>
                    <div class="text-center">
                        <div class="fw-bold text-secondary fs-5"><?= $naCount ?></div>
                        <div class="text-muted small">N/A</div>
                    </div>
                    <div class="text-center">
                        <div class="fw-bold text-muted fs-5"><?= $pendingCount ?></div>
                        <div class="text-muted small">Pending</div>
                    </div>
                </div>
            </div>
        </div>
        <?php if ($cl['approved_by']): ?>
        <div class="alert alert-success py-2 px-3 mb-0 mt-3 small">
            <i class="fa fa-circle-check me-1"></i>
            Approved by <strong><?= e($cl['approved_by_name']) ?></strong> on <?= fmtDate($cl['approved_at'],'d M Y H:i') ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Checklist items form -->
<?php if (canWrite('inspections') && in_array($cl['status'],['draft','submitted'])): ?>
<form method="POST" id="checklistForm">
    <input type="hidden" name="action" value="save_items">
<?php endif; ?>

<?php foreach ($grouped as $category => $catItems): ?>
<div class="card mb-3">
    <div class="card-header fw-semibold d-flex justify-content-between align-items-center" style="background:#f8fafc">
        <span><i class="fa fa-folder me-2 text-primary"></i><?= e($category) ?></span>
        <?php
        $catOk   = count(array_filter($catItems, fn($i)=>$i['result']==='ok'));
        $catFail = count(array_filter($catItems, fn($i)=>$i['result']==='fail'));
        ?>
        <div class="d-flex gap-1">
            <span class="badge bg-success"><?= $catOk ?> ok</span>
            <?php if ($catFail>0): ?><span class="badge bg-danger"><?= $catFail ?> fail</span><?php endif; ?>
            <span class="badge bg-light text-dark border"><?= count($catItems) ?> items</span>
        </div>
    </div>
    <div class="card-body p-0">
        <table class="table table-hover mb-0" style="font-size:13.5px">
            <thead class="table-light">
                <tr>
                    <th class="ps-3" style="width:50%">Item</th>
                    <th style="width:200px">Result</th>
                    <th>Notes</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($catItems as $item): ?>
            <tr class="<?= $item['result']==='fail'?'table-danger':($item['result']==='ok'?'table-success bg-opacity-10':'') ?>">
                <td class="ps-3 fw-medium"><?= e($item['item']) ?></td>
                <td>
                    <?php if (canWrite('inspections') && in_array($cl['status'],['draft','submitted'])): ?>
                    <div class="d-flex gap-1">
                        <?php foreach (['ok'=>['OK','btn-success'],'fail'=>['FAIL','btn-danger'],'na'=>['N/A','btn-secondary']] as $val=>[$lbl,$cls]): ?>
                        <label class="btn btn-xs <?= $item['result']===$val?$cls:'btn-outline-'.explode('-',$cls)[1] ?> mb-0"
                               style="font-size:11px;padding:2px 8px">
                            <input type="radio" name="result[<?= $item['id'] ?>]"
                                   value="<?= $val ?>" <?= $item['result']===$val?'checked':'' ?>
                                   class="d-none" onchange="this.closest('tr').className=this.value==='fail'?'table-danger':(this.value==='ok'?'table-success bg-opacity-10':'')">
                            <?= $lbl ?>
                        </label>
                        <?php endforeach; ?>
                    </div>
                    <?php else: ?>
                    <span class="badge bg-<?= $resultColors[$item['result']] ?? 'secondary' ?> text-<?= $item['result']==='ok'?'white':'dark' ?>">
                        <i class="fa <?= $resultIcons[$item['result']] ?? 'fa-circle' ?> me-1"></i>
                        <?= strtoupper($item['result']) ?>
                    </span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if (canWrite('inspections') && in_array($cl['status'],['draft','submitted'])): ?>
                    <input type="text" name="item_notes[<?= $item['id'] ?>]"
                           class="form-control form-control-sm"
                           value="<?= e($item['notes'] ?? '') ?>"
                           placeholder="Notes (optional)">
                    <?php else: ?>
                    <span class="text-muted small"><?= e($item['notes'] ?? '—') ?></span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endforeach; ?>

<?php if (canWrite('inspections') && in_array($cl['status'],['draft','submitted'])): ?>
<!-- Overall notes -->
<div class="card mb-4">
    <div class="card-body">
        <label class="form-label fw-semibold">Overall Notes & Observations</label>
        <textarea name="overall_notes" class="form-control" rows="3"
                  placeholder="General findings, recommendations, items to follow up…"><?= e($cl['overall_notes'] ?? '') ?></textarea>
    </div>
</div>

<div class="d-flex gap-2 mb-4">
    <button type="submit" class="btn btn-primary px-4">
        <i class="fa fa-save me-1"></i>Save Checklist
    </button>
    <a href="print.php?id=<?= $id ?>" target="_blank" class="btn btn-outline-secondary">
        <i class="fa fa-print me-1"></i>Print / Export
    </a>
</div>
</form>
<?php elseif ($cl['overall_notes']): ?>
<div class="card mb-4">
    <div class="card-header fw-semibold">Overall Notes</div>
    <div class="card-body text-muted"><?= nl2br(e($cl['overall_notes'])) ?></div>
</div>
<?php endif; ?>

<script>
// Quick-select all items in a category to OK
document.querySelectorAll('.card-header').forEach(function(hdr) {
    if (!hdr.querySelector('.badge')) return;
    var btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'btn btn-xs btn-outline-success ms-2';
    btn.style.fontSize = '10px';
    btn.innerHTML = 'All OK';
    btn.onclick = function() {
        var card = hdr.closest('.card');
        card.querySelectorAll('input[type=radio][value=ok]').forEach(function(r){
            r.checked = true;
            r.closest('tr').className = 'table-success bg-opacity-10';
            // Update button styles
            r.closest('td').querySelectorAll('label').forEach(function(l,i){
                var classes = [['btn-success','btn-outline-success'],['btn-danger','btn-outline-danger'],['btn-secondary','btn-outline-secondary']];
                l.classList.remove(classes[i][0],classes[i][1]);
                l.classList.add(i===0?classes[i][0]:classes[i][1]);
            });
        });
    };
    hdr.querySelector('.d-flex').prepend(btn);
});
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>

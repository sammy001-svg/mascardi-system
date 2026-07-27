<?php
/**
 * Shared "Delivery Protocol — awaiting your action" banner.
 * Include after setting $dpPendingItems = getPendingDeliveryProtocolActions($role);
 */
if (!empty($dpPendingItems)):
    $__dpCount = count($dpPendingItems);
?>
<div class="card mb-4" style="border-color:#f59e0b;border-width:2px">
    <div class="card-body" style="background:#fffbeb">
        <div class="d-flex align-items-center gap-2 mb-2">
            <i class="fa fa-truck-fast" style="font-size:20px;color:#d97706"></i>
            <div class="fw-bold" style="color:#92400e">
                <?= $__dpCount ?> delivery protocol item<?= $__dpCount !== 1 ? 's' : '' ?> awaiting your action
            </div>
        </div>
        <?php foreach ($dpPendingItems as $__i => $__it): ?>
        <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 py-2"
             style="<?= $__i < $__dpCount - 1 ? 'border-bottom:1px solid #fde68a' : '' ?>">
            <div>
                <a href="<?= e($__it['link']) ?>" class="fw-semibold text-decoration-none" style="color:#92400e"><?= e($__it['lead_name']) ?></a>
                <span class="badge bg-warning text-dark ms-1" style="font-size:10.5px"><?= e($__it['step']) ?></span>
                <div class="small" style="color:#b45309"><?= e($__it['message']) ?></div>
            </div>
            <a href="<?= e($__it['link']) ?>" class="btn btn-sm btn-outline-secondary">
                <i class="fa fa-arrow-right me-1"></i>Open
            </a>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

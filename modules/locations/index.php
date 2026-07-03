<?php
require_once __DIR__ . '/../../includes/functions.php';
requireRole(['admin', 'manager']);
$pageTitle = 'Locations & Yards';
$db = getDB();

// Auto-migrate: add parent_id column if not exists
try { $db->exec("ALTER TABLE locations ADD COLUMN parent_id INT NULL DEFAULT NULL"); } catch (\Throwable $_) {}
try { $db->exec("ALTER TABLE locations ADD INDEX idx_loc_parent (parent_id)"); } catch (\Throwable $_) {}

// Status toggle
if (isset($_GET['toggle'])) {
    $id = (int)$_GET['toggle'];
    $db->prepare("UPDATE locations SET status = IF(status='active','inactive','active') WHERE id=?")->execute([$id]);
    logActivity('update', 'locations', $id, 'Toggled location status');
    setFlash('success', 'Location status updated.');
    redirect('index.php');
}

// Fetch all with car count — ordered so parent rows come before their children
$allLocs = $db->query("
    SELECT l.*,
           (SELECT COUNT(*) FROM cars WHERE location_id = l.id) AS car_count
    FROM   locations l
    ORDER  BY COALESCE(l.parent_id, l.id) ASC,
              (l.parent_id IS NOT NULL) ASC,
              l.name ASC
")->fetchAll();

// Organise into parent → children map
$parents     = [];
$childrenMap = [];
foreach ($allLocs as $loc) {
    if (!empty($loc['parent_id'])) {
        $childrenMap[(int)$loc['parent_id']][] = $loc;
    } else {
        $parents[] = $loc;
    }
}

$typeIcons = [
    'yard'     => 'fa-warehouse',
    'showroom' => 'fa-car-side',
    'port'     => 'fa-anchor',
    'office'   => 'fa-building',
];

include __DIR__ . '/../../includes/header.php';
?>

<style>
.loc-parent-row   { background: #fff; }
.loc-sub-row      { background: #f8fafc; }
.loc-icon-wrap    { width:34px;height:34px;border-radius:9px;display:flex;align-items:center;justify-content:center;flex-shrink:0 }
.loc-sub-icon     { width:28px;height:28px;border-radius:7px;display:flex;align-items:center;justify-content:center;flex-shrink:0 }
.loc-connector    { color:#cbd5e1;font-size:16px;margin-right:6px;line-height:1 }
</style>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h5 class="mb-0"><i class="fa fa-map-location-dot me-2 text-primary"></i>Locations &amp; Yards</h5>
        <div class="text-muted small mt-1"><?= count($parents) ?> location<?= count($parents) !== 1 ? 's' : '' ?>
            <?php $totalSubs = array_sum(array_map('count', $childrenMap)); ?>
            <?php if ($totalSubs): ?>&nbsp;&bull;&nbsp;<?= $totalSubs ?> sub-location<?= $totalSubs !== 1 ? 's' : '' ?><?php endif; ?>
        </div>
    </div>
    <a href="add.php" class="btn btn-primary btn-sm">
        <i class="fa fa-plus me-1"></i>Add Location
    </a>
</div>

<div class="card shadow-sm border-0">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead>
                    <tr style="background:#f1f5f9;font-size:11px;text-transform:uppercase;letter-spacing:.05em;color:#64748b">
                        <th class="ps-4 fw-semibold">Location Name</th>
                        <th class="fw-semibold">Type</th>
                        <th class="fw-semibold">Address</th>
                        <th class="fw-semibold text-center">Vehicles</th>
                        <th class="fw-semibold">Status</th>
                        <th class="fw-semibold text-end pe-4">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$parents): ?>
                    <tr>
                        <td colspan="6" class="text-center text-muted py-5">
                            <i class="fa fa-map-location-dot fa-2x mb-2 d-block opacity-25"></i>
                            No locations yet.
                            <a href="add.php" class="ms-1">Add your first location</a>
                        </td>
                    </tr>
                <?php endif; ?>

                <?php foreach ($parents as $l):
                    $subs    = $childrenMap[$l['id']] ?? [];
                    $icon    = $typeIcons[$l['type']] ?? 'fa-map-marker-alt';
                    $subCars = array_sum(array_column($subs, 'car_count'));
                    $total   = $l['car_count'] + $subCars;
                    $canDel  = ($l['car_count'] == 0 && empty($subs));
                ?>

                <!-- ── Parent location ───────────────────────────────────── -->
                <tr class="loc-parent-row border-bottom">
                    <td class="ps-4">
                        <div class="d-flex align-items-center gap-2">
                            <div class="loc-icon-wrap" style="background:#eff6ff">
                                <i class="fa <?= $icon ?> text-primary" style="font-size:14px"></i>
                            </div>
                            <div>
                                <div class="fw-bold text-dark" style="font-size:14px"><?= e($l['name']) ?></div>
                                <?php if ($subs): ?>
                                <div style="font-size:11px;color:#94a3b8">
                                    <i class="fa fa-sitemap me-1"></i><?= count($subs) ?> sub-location<?= count($subs) !== 1 ? 's' : '' ?>
                                </div>
                                <?php elseif ($l['address']): ?>
                                <div class="text-muted" style="font-size:11px"><?= e(mb_strimwidth($l['address'], 0, 40, '…')) ?></div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </td>
                    <td>
                        <span class="badge bg-light text-secondary border" style="font-size:11px">
                            <i class="fa <?= $icon ?> me-1"></i><?= ucfirst($l['type']) ?>
                        </span>
                    </td>
                    <td class="text-muted small"><?= e($l['address'] ?: '—') ?></td>
                    <td class="text-center">
                        <?php if ($total > 0): ?>
                        <span class="badge bg-primary rounded-pill"><?= $total ?></span>
                        <?php if ($subCars && $l['car_count']): ?>
                        <div style="font-size:10px;color:#94a3b8"><?= $l['car_count'] ?> here</div>
                        <?php endif; ?>
                        <?php else: ?>
                        <span class="text-muted small">—</span>
                        <?php endif; ?>
                    </td>
                    <td><?= statusBadge($l['status']) ?></td>
                    <td class="text-end pe-4">
                        <div class="d-flex gap-1 justify-content-end align-items-center">
                            <a href="add.php?parent_id=<?= $l['id'] ?>"
                               class="btn btn-xs btn-outline-primary" title="Add sub-location">
                                <i class="fa fa-plus"></i>
                            </a>
                            <a href="edit.php?id=<?= $l['id'] ?>"
                               class="btn btn-xs btn-outline-secondary" title="Edit">
                                <i class="fa fa-pen"></i>
                            </a>
                            <a href="?toggle=<?= $l['id'] ?>"
                               class="btn btn-xs btn-outline-<?= $l['status'] === 'active' ? 'warning' : 'success' ?>"
                               title="<?= $l['status'] === 'active' ? 'Deactivate' : 'Activate' ?>">
                                <i class="fa <?= $l['status'] === 'active' ? 'fa-ban' : 'fa-check' ?>"></i>
                            </a>
                            <?php if ($canDel): ?>
                            <a href="delete.php?id=<?= $l['id'] ?>"
                               class="btn btn-xs btn-outline-danger confirm-delete" title="Delete">
                                <i class="fa fa-trash"></i>
                            </a>
                            <?php else: ?>
                            <span class="btn btn-xs btn-outline-danger disabled opacity-25" title="<?= $subs ? 'Has sub-locations' : 'Has vehicles' ?>">
                                <i class="fa fa-trash"></i>
                            </span>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>

                <!-- ── Sub-location rows ─────────────────────────────────── -->
                <?php foreach ($subs as $i => $sub):
                    $subIcon = $typeIcons[$sub['type']] ?? 'fa-map-marker-alt';
                    $isLast  = ($i === count($subs) - 1);
                ?>
                <tr class="loc-sub-row <?= $isLast ? 'border-bottom' : '' ?>">
                    <td class="ps-4">
                        <div class="d-flex align-items-center" style="padding-left:44px">
                            <span class="loc-connector"><?= $isLast ? '└' : '├' ?></span>
                            <div class="loc-sub-icon me-2" style="background:#eef2ff">
                                <i class="fa <?= $subIcon ?>" style="font-size:11px;color:#4f46e5"></i>
                            </div>
                            <div class="fw-semibold" style="font-size:13px;color:#334155"><?= e($sub['name']) ?></div>
                        </div>
                    </td>
                    <td>
                        <span class="badge bg-light text-secondary border" style="font-size:10px">
                            <i class="fa <?= $subIcon ?> me-1"></i><?= ucfirst($sub['type']) ?>
                        </span>
                    </td>
                    <td class="text-muted" style="font-size:12px"><?= e($sub['address'] ?: '—') ?></td>
                    <td class="text-center">
                        <?php if ($sub['car_count'] > 0): ?>
                        <span class="badge bg-light text-primary border"><?= $sub['car_count'] ?></span>
                        <?php else: ?>
                        <span class="text-muted small">—</span>
                        <?php endif; ?>
                    </td>
                    <td><?= statusBadge($sub['status']) ?></td>
                    <td class="text-end pe-4">
                        <div class="d-flex gap-1 justify-content-end">
                            <a href="edit.php?id=<?= $sub['id'] ?>"
                               class="btn btn-xs btn-outline-secondary" title="Edit">
                                <i class="fa fa-pen"></i>
                            </a>
                            <a href="?toggle=<?= $sub['id'] ?>"
                               class="btn btn-xs btn-outline-<?= $sub['status'] === 'active' ? 'warning' : 'success' ?>"
                               title="<?= $sub['status'] === 'active' ? 'Deactivate' : 'Activate' ?>">
                                <i class="fa <?= $sub['status'] === 'active' ? 'fa-ban' : 'fa-check' ?>"></i>
                            </a>
                            <?php if ($sub['car_count'] == 0): ?>
                            <a href="delete.php?id=<?= $sub['id'] ?>"
                               class="btn btn-xs btn-outline-danger confirm-delete" title="Delete">
                                <i class="fa fa-trash"></i>
                            </a>
                            <?php else: ?>
                            <span class="btn btn-xs btn-outline-danger disabled opacity-25" title="Has vehicles">
                                <i class="fa fa-trash"></i>
                            </span>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>

                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>

<?php
require_once __DIR__ . '/../../includes/functions.php';
requireRole(['admin', 'manager']);
$pageTitle = 'Locations & Yards';
$db = getDB();
// Super Admin may force-delete a location that still has vehicles or
// sub-locations attached; everyone else only gets the safe (empty) delete.
$isSuperAdmin = isSuperAdmin();

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
/* Theme variables, not fixed hex. These are class rules, so the app-wide
   dark-mode overrides in style.css never applied to them — those selectors
   match inline style attributes ([style*="background:#fff"]). The rows
   therefore stayed white in dark mode while the global theme switched the
   text to a light colour, leaving the location details unreadable. */
.loc-parent-row   { background: var(--surface); }
.loc-sub-row      { background: var(--surface-alt); }
.loc-icon-wrap    { width:34px;height:34px;border-radius:9px;display:flex;align-items:center;justify-content:center;flex-shrink:0 }
.loc-sub-icon     { width:28px;height:28px;border-radius:7px;display:flex;align-items:center;justify-content:center;flex-shrink:0 }
.loc-connector    { color:var(--text-3);font-size:16px;margin-right:6px;line-height:1 }
.loc-name         { color:var(--text); }
.loc-muted        { color:var(--text-3); }
.loc-head-row     { background:var(--surface-alt); color:var(--text-2); }
/* Tinted icon chips: translucent so they read on either theme. */
.loc-icon-wrap    { background:rgba(59,130,246,.12); }
.loc-sub-icon     { background:rgba(99,102,241,.14); }
.loc-sub-icon i   { color:#6366f1; }   /* indigo-500 reads on both themes */
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
                    <tr class="loc-head-row" style="font-size:11px;text-transform:uppercase;letter-spacing:.05em">
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
                            <div class="loc-icon-wrap">
                                <i class="fa <?= $icon ?> text-primary" style="font-size:14px"></i>
                            </div>
                            <div>
                                <div class="fw-bold text-dark" style="font-size:14px"><?= e($l['name']) ?></div>
                                <?php if ($subs): ?>
                                <div class="loc-muted" style="font-size:11px">
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
                        <div class="loc-muted" style="font-size:10px"><?= $l['car_count'] ?> here</div>
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
                            <?php elseif ($isSuperAdmin): ?>
                            <?php
                                // Force delete. Nothing is destroyed — the FKs are SET NULL,
                                // so vehicles/staff are unassigned and sub-locations promoted
                                // to top level. Spell that out before they commit.
                                $warn = [];
                                if ($l['car_count']) $warn[] = $l['car_count'] . ' vehicle(s) will become unassigned';
                                if ($subs)           $warn[] = count($subs) . ' sub-location(s) will be promoted to top level';
                            ?>
                            <a href="delete.php?id=<?= $l['id'] ?>&force=1"
                               class="btn btn-xs btn-danger"
                               title="Force delete (Super Admin)"
                               onclick="return confirm('Delete &quot;<?= e($l['name']) ?>&quot;?\n\n<?= e(implode('.\n', $warn)) ?>.\n\nNo vehicle or staff records are deleted — they are only unassigned.\n\nContinue?')">
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
                            <div class="loc-sub-icon me-2">
                                <i class="fa <?= $subIcon ?>" style="font-size:11px"></i>
                            </div>
                            <div class="fw-semibold loc-name" style="font-size:13px"><?= e($sub['name']) ?></div>
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
                            <?php elseif ($isSuperAdmin): ?>
                            <a href="delete.php?id=<?= $sub['id'] ?>&force=1"
                               class="btn btn-xs btn-danger" title="Force delete (Super Admin)"
                               onclick="return confirm('Delete sub-location &quot;<?= e($sub['name']) ?>&quot;?\n\n<?= (int)$sub['car_count'] ?> vehicle(s) will become unassigned.\n\nNo vehicle records are deleted — they are only unassigned.\n\nContinue?')">
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

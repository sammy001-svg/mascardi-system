<?php
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
canAccess('issues') || die('Access denied.');
$pageTitle = 'Issues Dashboard';
$db = getDB();

// ── Overall stats ────────────────────────────────────────────────────────────
$stats = $db->query("
    SELECT
        COUNT(*)                                                           AS total,
        SUM(CASE WHEN is_resolved=0 THEN 1 ELSE 0 END)                    AS open_count,
        SUM(CASE WHEN is_resolved=1 THEN 1 ELSE 0 END)                    AS resolved_count,
        SUM(CASE WHEN is_resolved=0 AND `condition`='major_damage' THEN 1 ELSE 0 END)  AS critical_count
    FROM assessment_items
    WHERE `condition` != 'good'
")->fetch();

// ── By category ──────────────────────────────────────────────────────────────
$focusCategories = ['Exterior','Wheels & Tyres','Interior','Electronics','Engine & Mechanical'];

$byCategory = $db->query("
    SELECT
        part_category,
        COUNT(*)                                                  AS total,
        SUM(CASE WHEN is_resolved=0 THEN 1 ELSE 0 END)           AS open_count,
        SUM(CASE WHEN is_resolved=1 THEN 1 ELSE 0 END)           AS resolved_count,
        SUM(CASE WHEN `condition`='major_damage'  AND is_resolved=0 THEN 1 ELSE 0 END) AS major,
        SUM(CASE WHEN `condition`='missing'       AND is_resolved=0 THEN 1 ELSE 0 END) AS missing
    FROM assessment_items
    WHERE `condition` != 'good'
    GROUP BY part_category
    ORDER BY FIELD(part_category,'Exterior','Wheels & Tyres','Interior','Electronics','Engine & Mechanical') DESC, open_count DESC
")->fetchAll(PDO::FETCH_ASSOC);

// ── By vehicle (open issues only) ────────────────────────────────────────────
$byVehicle = $db->query("
    SELECT
        c.id AS car_id, c.make, c.model, c.year, c.chassis_number, c.registration_number,
        COUNT(ai.id)                                                           AS total,
        SUM(CASE WHEN ai.is_resolved=0 THEN 1 ELSE 0 END)                     AS open_count,
        SUM(CASE WHEN ai.is_resolved=1 THEN 1 ELSE 0 END)                     AS resolved_count,
        SUM(CASE WHEN ai.is_resolved=0 AND ai.condition='major_damage'  THEN 1 ELSE 0 END) AS critical,
        SUM(CASE WHEN ai.is_resolved=0 AND ai.condition='minor_damage'  THEN 1 ELSE 0 END) AS minor,
        SUM(CASE WHEN ai.is_resolved=0 AND ai.condition='needs_service' THEN 1 ELSE 0 END) AS service,
        SUM(CASE WHEN ai.is_resolved=0 AND ai.condition='missing'       THEN 1 ELSE 0 END) AS missing
    FROM assessment_items ai
    JOIN car_assessments ca ON ca.id = ai.assessment_id
    JOIN cars c ON c.id = ca.car_id
    WHERE ai.condition != 'good'
    GROUP BY c.id, c.make, c.model, c.year, c.chassis_number, c.registration_number
    ORDER BY open_count DESC
")->fetchAll(PDO::FETCH_ASSOC);

$catIcons = [
    'Exterior'             => 'fa-car-side',
    'Wheels & Tyres'       => 'fa-circle-dot',
    'Interior'             => 'fa-couch',
    'Electronics'          => 'fa-microchip',
    'Engine & Mechanical'  => 'fa-gears',
    'Lighting'             => 'fa-lightbulb',
    'Documents'            => 'fa-file-lines',
];

include __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h5 class="mb-1"><i class="fa fa-triangle-exclamation me-2 text-warning"></i>Issues Dashboard</h5>
        <div class="text-muted small"><?= $stats['open_count'] ?> open issue<?= $stats['open_count'] != 1 ? 's' : '' ?> across <?= count($byVehicle) ?> vehicle<?= count($byVehicle) != 1 ? 's' : '' ?></div>
    </div>
</div>

<!-- ── Stat cards ─────────────────────────────────────────────────────────── -->
<div class="row g-3 mb-4">
    <div class="col-6 col-xl-3">
        <div class="card h-100 border-0 shadow-sm">
            <div class="card-body d-flex align-items-center gap-3">
                <div style="width:46px;height:46px;border-radius:12px;background:#fef3c7;color:#d97706;display:flex;align-items:center;justify-content:center;flex-shrink:0">
                    <i class="fa fa-circle-exclamation fa-lg"></i>
                </div>
                <div>
                    <div style="font-size:26px;font-weight:700;line-height:1"><?= $stats['total'] ?></div>
                    <div class="text-muted small">Total Issues</div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-xl-3">
        <div class="card h-100 border-0 shadow-sm">
            <div class="card-body d-flex align-items-center gap-3">
                <div style="width:46px;height:46px;border-radius:12px;background:#fee2e2;color:#dc2626;display:flex;align-items:center;justify-content:center;flex-shrink:0">
                    <i class="fa fa-lock-open fa-lg"></i>
                </div>
                <div>
                    <div style="font-size:26px;font-weight:700;line-height:1;color:#dc2626"><?= $stats['open_count'] ?></div>
                    <div class="text-muted small">Open</div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-xl-3">
        <div class="card h-100 border-0 shadow-sm">
            <div class="card-body d-flex align-items-center gap-3">
                <div style="width:46px;height:46px;border-radius:12px;background:#dcfce7;color:#16a34a;display:flex;align-items:center;justify-content:center;flex-shrink:0">
                    <i class="fa fa-circle-check fa-lg"></i>
                </div>
                <div>
                    <div style="font-size:26px;font-weight:700;line-height:1;color:#16a34a"><?= $stats['resolved_count'] ?></div>
                    <div class="text-muted small">Resolved</div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-xl-3">
        <div class="card h-100 border-0 shadow-sm">
            <div class="card-body d-flex align-items-center gap-3">
                <div style="width:46px;height:46px;border-radius:12px;background:#fce7f3;color:#db2777;display:flex;align-items:center;justify-content:center;flex-shrink:0">
                    <i class="fa fa-skull-crossbones fa-lg"></i>
                </div>
                <div>
                    <div style="font-size:26px;font-weight:700;line-height:1;color:#db2777"><?= $stats['critical_count'] ?></div>
                    <div class="text-muted small">Critical / Major</div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ── Category Breakdown ─────────────────────────────────────────────────── -->
<div class="row g-4 mb-4">
    <div class="col-lg-5">
        <div class="card h-100">
            <div class="card-header fw-semibold"><i class="fa fa-layer-group me-2 text-primary"></i>By Category</div>
            <div class="card-body">
                <?php
                // Build a map from query results
                $catMap = [];
                foreach ($byCategory as $row) $catMap[$row['part_category']] = $row;

                // Show focus categories first, then others
                $orderedCats = array_merge(
                    array_filter($focusCategories, fn($c) => isset($catMap[$c])),
                    array_filter(array_keys($catMap), fn($c) => !in_array($c, $focusCategories))
                );
                $maxOpen = max(1, max(array_column($byCategory, 'open_count')));
                ?>
                <?php foreach ($orderedCats as $cat):
                    $row   = $catMap[$cat];
                    $pct   = round(($row['open_count'] / $maxOpen) * 100);
                    $rPct  = $row['total'] > 0 ? round(($row['resolved_count'] / $row['total']) * 100) : 0;
                    $icon  = $catIcons[$cat] ?? 'fa-box';
                    $isFocus = in_array($cat, $focusCategories);
                ?>
                <div class="mb-3 <?= $isFocus ? '' : 'opacity-75' ?>">
                    <div class="d-flex justify-content-between align-items-center mb-1">
                        <div class="d-flex align-items-center gap-2" style="font-size:13px;font-weight:<?= $isFocus?'600':'400' ?>">
                            <i class="fa <?= $icon ?> text-muted" style="width:14px;text-align:center"></i>
                            <?= e($cat) ?>
                            <?php if ($row['major'] > 0): ?><span class="badge bg-danger ms-1" style="font-size:10px"><?= $row['major'] ?> major</span><?php endif; ?>
                            <?php if ($row['missing'] > 0): ?><span class="badge bg-dark ms-1" style="font-size:10px"><?= $row['missing'] ?> missing</span><?php endif; ?>
                        </div>
                        <div class="d-flex gap-2" style="font-size:12px">
                            <span class="text-danger fw-semibold"><?= $row['open_count'] ?> open</span>
                            <span class="text-muted">/</span>
                            <span class="text-success"><?= $row['resolved_count'] ?> fixed</span>
                        </div>
                    </div>
                    <div class="progress" style="height:7px;border-radius:4px;background:#f1f5f9">
                        <div class="progress-bar bg-success" style="width:<?= $rPct ?>%" title="<?= $rPct ?>% resolved"></div>
                        <div class="progress-bar bg-danger opacity-75" style="width:<?= 100-$rPct ?>%" title="<?= 100-$rPct ?>% open"></div>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php if (empty($orderedCats)): ?>
                <div class="text-center text-muted py-4"><i class="fa fa-circle-check fa-2x mb-2 d-block text-success"></i>No issues recorded.</div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- ── Vehicles with Issues ───────────────────────────────────────────── -->
    <div class="col-lg-7">
        <div class="card h-100">
            <div class="card-header fw-semibold d-flex justify-content-between align-items-center">
                <span><i class="fa fa-car me-2 text-primary"></i>Issues by Vehicle</span>
                <small class="text-muted fw-normal"><?= count(array_filter($byVehicle, fn($v) => $v['open_count'] > 0)) ?> vehicles with open issues</small>
            </div>
            <div class="card-body p-0">
                <table class="table table-hover mb-0" style="font-size:13px">
                    <thead style="background:#f8fafc;font-size:11px;color:#64748b;text-transform:uppercase;letter-spacing:.04em">
                        <tr>
                            <th class="ps-3 py-2">Vehicle</th>
                            <th class="py-2 text-center">Open</th>
                            <th class="py-2 text-center">Resolved</th>
                            <th class="py-2 text-center">Condition</th>
                            <th class="py-2"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($byVehicle as $v): ?>
                        <tr>
                            <td class="ps-3 py-2">
                                <div class="fw-semibold"><?= e($v['make'].' '.$v['model'].' '.$v['year']) ?></div>
                                <div class="text-muted" style="font-size:11px">
                                    <?= $v['registration_number'] ? '<span class="badge bg-dark me-1">'.e($v['registration_number']).'</span>' : '' ?>
                                    <code style="font-size:10px"><?= e($v['chassis_number']) ?></code>
                                </div>
                            </td>
                            <td class="py-2 text-center">
                                <?php if ($v['open_count'] > 0): ?>
                                <span class="badge bg-danger rounded-pill"><?= $v['open_count'] ?></span>
                                <?php else: ?>
                                <span class="text-success"><i class="fa fa-check"></i></span>
                                <?php endif; ?>
                            </td>
                            <td class="py-2 text-center text-success fw-semibold"><?= $v['resolved_count'] ?></td>
                            <td class="py-2 text-center" style="font-size:11px">
                                <?php if ($v['critical']): ?><span class="badge bg-danger me-1"><?= $v['critical'] ?> major</span><?php endif; ?>
                                <?php if ($v['missing']): ?><span class="badge bg-dark me-1"><?= $v['missing'] ?> missing</span><?php endif; ?>
                                <?php if ($v['service']): ?><span class="badge bg-primary me-1"><?= $v['service'] ?> service</span><?php endif; ?>
                                <?php if ($v['minor']): ?><span class="badge bg-warning text-dark"><?= $v['minor'] ?> minor</span><?php endif; ?>
                            </td>
                            <td class="py-2 pe-3 text-end">
                                <a href="vehicle.php?car_id=<?= $v['car_id'] ?>" class="btn btn-xs btn-outline-primary">
                                    <i class="fa fa-eye me-1"></i>View
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($byVehicle)): ?>
                        <tr><td colspan="5" class="text-center py-5 text-muted">
                            <i class="fa fa-circle-check fa-2x mb-2 d-block text-success"></i>No issues found.
                        </td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>

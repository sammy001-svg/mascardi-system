<?php
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
canAccess('car_documents') || redirect(BASE_URL . '/index.php');

$pageTitle = 'Car Documents';
$db        = getDB();

$filterType = $_GET['type']   ?? '';
$filterExp  = $_GET['expiry'] ?? '';   // expired | soon | ok

// ── Build query ───────────────────────────────────────────────────────────────
$where  = ['1=1'];
$params = [];
if ($filterType) { $where[] = 'cd.doc_type = ?'; $params[] = $filterType; }
if ($filterExp === 'expired') {
    $where[] = 'cd.expiry_date IS NOT NULL AND cd.expiry_date < CURDATE()';
} elseif ($filterExp === 'soon') {
    $where[] = 'cd.expiry_date IS NOT NULL AND cd.expiry_date >= CURDATE() AND cd.expiry_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)';
} elseif ($filterExp === 'ok') {
    $where[] = '(cd.expiry_date IS NULL OR cd.expiry_date > DATE_ADD(CURDATE(), INTERVAL 30 DAY))';
}
$whereStr = implode(' AND ', $where);

try {
    $docs = $db->prepare("
        SELECT cd.*, c.make, c.model, c.chassis_number, c.registration_number,
               u.name AS uploaded_by_name
        FROM car_documents cd
        JOIN cars c ON c.id = cd.car_id
        LEFT JOIN users u ON u.id = cd.uploaded_by
        WHERE $whereStr
        ORDER BY cd.created_at DESC
    ");
    $docs->execute($params);
    $docs = $docs->fetchAll();

    // Summary counts for alert bar
    $expired = (int)$db->query("SELECT COUNT(*) FROM car_documents WHERE expiry_date IS NOT NULL AND expiry_date < CURDATE()")->fetchColumn();
    $soon    = (int)$db->query("SELECT COUNT(*) FROM car_documents WHERE expiry_date IS NOT NULL AND expiry_date >= CURDATE() AND expiry_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)")->fetchColumn();
} catch (\Throwable $e) {
    $docs = []; $expired = 0; $soon = 0;
}

$docTypes = [
    'logbook'          => ['label' => 'Logbook',               'icon' => 'fa-book',                  'color' => 'primary'],
    'import_entry'     => ['label' => 'Import Entry',           'icon' => 'fa-file-import',           'color' => 'info'],
    'ntsa_inspection'  => ['label' => 'NTSA Inspection',        'icon' => 'fa-clipboard-check',       'color' => 'success'],
    'ntsa_registration'=> ['label' => 'NTSA Registration',      'icon' => 'fa-id-card',               'color' => 'success'],
    'insurance'        => ['label' => 'Insurance',              'icon' => 'fa-shield-halved',         'color' => 'warning'],
    'duty_clearance'   => ['label' => 'Duty Clearance',         'icon' => 'fa-stamp',                 'color' => 'secondary'],
    'purchase_invoice' => ['label' => 'Purchase Invoice',       'icon' => 'fa-file-invoice',          'color' => 'dark'],
    'other'            => ['label' => 'Other',                  'icon' => 'fa-file',                  'color' => 'secondary'],
];

function expiryStatus(?string $date): array {
    if (!$date) return ['', '', ''];
    $days = (int)((strtotime($date) - time()) / 86400);
    if ($days < 0)   return ['danger',  'fa-circle-xmark',   'Expired'];
    if ($days <= 30) return ['warning', 'fa-triangle-exclamation', "Expires in {$days}d"];
    return ['success', 'fa-circle-check', 'Valid'];
}

include __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="mb-0"><i class="fa fa-folder-open me-2 text-primary"></i>Car Documents</h5>
    <?php if (canWrite('car_documents')): ?>
    <a href="upload.php" class="btn btn-primary btn-sm"><i class="fa fa-upload me-1"></i>Upload Document</a>
    <?php endif; ?>
</div>

<?php if ($expired || $soon): ?>
<div class="row g-3 mb-3">
    <?php if ($expired): ?>
    <div class="col-auto">
        <a href="?expiry=expired" class="text-decoration-none">
            <div class="alert alert-danger py-2 px-3 mb-0 d-flex align-items-center gap-2">
                <i class="fa fa-circle-xmark"></i>
                <span><strong><?= $expired ?></strong> expired document<?= $expired > 1 ? 's' : '' ?></span>
            </div>
        </a>
    </div>
    <?php endif; ?>
    <?php if ($soon): ?>
    <div class="col-auto">
        <a href="?expiry=soon" class="text-decoration-none">
            <div class="alert alert-warning py-2 px-3 mb-0 d-flex align-items-center gap-2">
                <i class="fa fa-triangle-exclamation"></i>
                <span><strong><?= $soon ?></strong> expiring within 30 days</span>
            </div>
        </a>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- Filters -->
<div class="card mb-3">
    <div class="card-body py-2 px-3">
        <div class="d-flex flex-wrap gap-2 align-items-center">
            <div class="d-flex gap-1 flex-wrap">
                <a href="?" class="btn btn-sm <?= !$filterType && !$filterExp ? 'btn-secondary' : 'btn-outline-secondary' ?>">All</a>
                <?php foreach ($docTypes as $key => $dt): ?>
                <a href="?type=<?= $key ?><?= $filterExp ? '&expiry='.$filterExp : '' ?>"
                   class="btn btn-sm <?= $filterType === $key ? 'btn-'.$dt['color'] : 'btn-outline-secondary' ?>">
                    <i class="fa <?= $dt['icon'] ?> me-1"></i><?= $dt['label'] ?>
                </a>
                <?php endforeach; ?>
            </div>
            <div class="vr mx-1"></div>
            <div class="d-flex gap-1">
                <a href="?<?= $filterType ? 'type='.$filterType.'&' : '' ?>expiry=expired"
                   class="btn btn-sm <?= $filterExp === 'expired' ? 'btn-danger' : 'btn-outline-danger' ?>">Expired</a>
                <a href="?<?= $filterType ? 'type='.$filterType.'&' : '' ?>expiry=soon"
                   class="btn btn-sm <?= $filterExp === 'soon' ? 'btn-warning' : 'btn-outline-warning' ?>">Expiring Soon</a>
            </div>
        </div>
    </div>
</div>

<!-- Documents table -->
<div class="card">
    <div class="card-body p-0">
        <table class="table table-hover align-middle mb-0 datatable" style="font-size:13.5px">
            <thead>
                <tr>
                    <th class="ps-3">Vehicle</th>
                    <th>Document</th>
                    <th>Type</th>
                    <th>Expiry</th>
                    <th>Uploaded</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($docs)): ?>
                <tr><td colspan="6" class="text-center py-5 text-muted">
                    <i class="fa fa-folder-open fa-2x mb-2 d-block opacity-25"></i>No documents found
                </td></tr>
            <?php else: ?>
            <?php foreach ($docs as $d):
                $dt = $docTypes[$d['doc_type']] ?? $docTypes['other'];
                [$ec, $ei, $el] = expiryStatus($d['expiry_date']);
            ?>
                <tr>
                    <td class="ps-3">
                        <a href="<?= BASE_URL ?>/modules/cars/view.php?id=<?= $d['car_id'] ?>"
                           class="fw-semibold text-decoration-none">
                            <?= e($d['make'] . ' ' . $d['model']) ?>
                        </a>
                        <div class="text-muted small"><?= e($d['chassis_number']) ?></div>
                    </td>
                    <td>
                        <div class="fw-medium"><?= e($d['title']) ?></div>
                        <?php if ($d['notes']): ?>
                        <div class="text-muted small text-truncate" style="max-width:220px"
                             title="<?= e($d['notes']) ?>"><?= e($d['notes']) ?></div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <span class="badge bg-<?= $dt['color'] ?>-subtle text-<?= $dt['color'] ?> border border-<?= $dt['color'] ?>-subtle"
                              style="font-size:11px">
                            <i class="fa <?= $dt['icon'] ?> me-1"></i><?= $dt['label'] ?>
                        </span>
                    </td>
                    <td>
                        <?php if ($d['expiry_date']): ?>
                        <span class="badge bg-<?= $ec ?>">
                            <i class="fa <?= $ei ?> me-1"></i><?= $el ?>
                        </span>
                        <div class="text-muted small mt-1"><?= fmtDate($d['expiry_date'], 'd M Y') ?></div>
                        <?php else: ?>
                        <span class="text-muted small">—</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="small"><?= e($d['uploaded_by_name'] ?? 'System') ?></div>
                        <div class="text-muted small"><?= fmtDate($d['created_at'], 'd M Y') ?></div>
                    </td>
                    <td>
                        <div class="d-flex gap-1">
                            <a href="download.php?id=<?= $d['id'] ?>"
                               class="btn btn-xs btn-outline-primary" title="Download">
                                <i class="fa fa-download"></i>
                            </a>
                            <a href="download.php?id=<?= $d['id'] ?>&view=1"
                               class="btn btn-xs btn-outline-secondary" target="_blank" title="View">
                                <i class="fa fa-eye"></i>
                            </a>
                            <?php if (canWrite('car_documents')): ?>
                            <form method="POST" action="delete.php" class="d-inline"
                                  onsubmit="return confirm('Delete this document permanently?')">
                                <input type="hidden" name="id" value="<?= $d['id'] ?>">
                                <input type="hidden" name="redirect" value="<?= e($_SERVER['REQUEST_URI']) ?>">
                                <button class="btn btn-xs btn-outline-danger" title="Delete">
                                    <i class="fa fa-trash"></i>
                                </button>
                            </form>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>

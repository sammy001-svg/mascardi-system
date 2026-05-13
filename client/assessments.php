<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

$pageTitle = 'Vehicle Assessments';
requireClientLogin();
$cl = clientAuth();
$cid = $cl['id'];
$db = getDB();

// Fetch assessments for cars belonging to this client
$stmt = $db->prepare("
    SELECT qa.*, 
           c.make, c.model, c.year, c.registration_number
    FROM quick_assessments qa
    JOIN cars c ON c.id = qa.car_id
    WHERE c.client_id = ?
    ORDER BY qa.assessment_date DESC, qa.created_at DESC
");
$stmt->execute([$cid]);
$assessments = $stmt->fetchAll();

include __DIR__ . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="fw-700 mb-1">Vehicle Health Checks</h4>
        <p class="text-muted small mb-0">Review the health and condition reports for your vehicles.</p>
    </div>
</div>

<div class="card">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0" style="font-size:13.5px">
                <thead class="bg-light">
                    <tr>
                        <th class="ps-4 border-0 text-muted small fw-600">REF #</th>
                        <th class="border-0 text-muted small fw-600">DATE</th>
                        <th class="border-0 text-muted small fw-600">VEHICLE</th>
                        <th class="border-0 text-muted small fw-600 text-center">CONDITION</th>
                        <th class="pe-4 border-0 text-end text-muted small fw-600">ACTION</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($assessments): ?>
                        <?php foreach ($assessments as $a): 
                            $badgeClass = [
                                'good' => 'success',
                                'fair' => 'warning',
                                'needs_attention' => 'primary',
                                'critical' => 'danger'
                            ][$a['overall_condition']] ?? 'secondary';
                            $conditionText = str_replace('_', ' ', $a['overall_condition']);
                        ?>
                        <tr>
                            <td class="ps-4 fw-bold text-primary">QA-<?= e($a['assessment_number']) ?></td>
                            <td><?= fmtDate($a['assessment_date']) ?></td>
                            <td>
                                <div class="fw-semibold"><?= e($a['make'] . ' ' . $a['model']) ?></div>
                                <div class="text-muted small"><?= e($a['registration_number'] ?: 'No Reg') ?></div>
                            </td>
                            <td class="text-center">
                                <span class="badge bg-<?= $badgeClass ?>-subtle text-<?= $badgeClass ?> border border-<?= $badgeClass ?>-subtle text-uppercase px-2" style="font-size:10px">
                                    <?= e($conditionText) ?>
                                </span>
                            </td>
                            <td class="pe-4 text-end">
                                <a href="<?= BASE_URL ?>/modules/quick_assessments/print.php?id=<?= $a['id'] ?>" target="_blank" class="btn btn-sm btn-outline-primary fw-600">
                                    <i class="fa fa-file-pdf me-1"></i>View / Print
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="5" class="text-center py-5 text-muted">
                                <i class="fa fa-list-check fa-3x mb-3 opacity-25"></i>
                                <p>No vehicle assessments found yet.</p>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>

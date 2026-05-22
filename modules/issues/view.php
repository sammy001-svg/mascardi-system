<?php
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
canAccess('issues') || die('Access denied.');
$id = (int)($_GET['id'] ?? 0);
if (!$id) redirect(BASE_URL . '/modules/issues/index.php');
$db   = getDB();
$user = authUser();

$issue = $db->prepare("
    SELECT ci.*, c.make, c.model, c.year, c.chassis_number, c.registration_number,
           m.name AS mechanic_name
    FROM car_issues ci
    JOIN cars c ON c.id = ci.car_id
    LEFT JOIN mechanics m ON m.id = ci.assigned_to
    WHERE ci.id = ?
");
$issue->execute([$id]); $issue = $issue->fetch();
if (!$issue) { setFlash('error', 'Issue not found.'); redirect(BASE_URL . '/modules/issues/index.php'); }

// Handle update POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && canWrite('issues')) {
    $action = $_POST['action'] ?? '';

    if ($action === 'update') {
        $status       = $_POST['status'] ?? $issue['status'];
        $assignedTo   = $_POST['assigned_to'] ?: null;
        $resNotes     = trim($_POST['resolution_notes'] ?? '');

        $db->prepare("UPDATE car_issues SET status=?, assigned_to=?, resolution_notes=?, updated_at=NOW() WHERE id=?")
           ->execute([$status, $assignedTo, $resNotes ?: $issue['resolution_notes'], $id]);

        // Mark resolved timestamp when status moves to resolved/closed
        if (in_array($status, ['resolved', 'closed']) && !$issue['resolved_at']) {
            $db->prepare("UPDATE car_issues SET resolved_by=?, resolved_at=NOW() WHERE id=?")
               ->execute([$user['name'], $id]);
        }
        // Clear resolved fields if re-opened
        if (in_array($status, ['open', 'in_progress']) && $issue['resolved_at']) {
            $db->prepare("UPDATE car_issues SET resolved_by=NULL, resolved_at=NULL WHERE id=?")->execute([$id]);
        }

        logActivity('update', 'issues', $id, "Updated issue {$issue['issue_number']}: status → {$status}");
        setFlash('success', 'Issue updated.');
        redirect(BASE_URL . '/modules/issues/view.php?id=' . $id);
    }

    if ($action === 'delete' && hasRole('admin')) {
        $db->prepare("DELETE FROM car_issues WHERE id=?")->execute([$id]);
        logActivity('delete', 'issues', $id, "Deleted issue {$issue['issue_number']}");
        setFlash('success', 'Issue deleted.');
        redirect(BASE_URL . '/modules/issues/index.php');
    }
}

// Re-fetch after update
$issue = $db->prepare("
    SELECT ci.*, c.make, c.model, c.year, c.chassis_number, c.registration_number,
           m.name AS mechanic_name
    FROM car_issues ci
    JOIN cars c ON c.id = ci.car_id
    LEFT JOIN mechanics m ON m.id = ci.assigned_to
    WHERE ci.id = ?
");
$issue->execute([$id]); $issue = $issue->fetch();

$mechanics = $db->query("SELECT id, name FROM mechanics WHERE status='active' ORDER BY name")->fetchAll();

$severityMeta = [
    'low'      => ['secondary', 'fa-arrow-down',     'Low'],
    'medium'   => ['warning',   'fa-minus',           'Medium'],
    'high'     => ['orange',    'fa-arrow-up',        'High'],
    'critical' => ['danger',    'fa-skull-crossbones','Critical'],
];
$statusMeta = [
    'open'        => ['danger',  'fa-circle-xmark',  'Open'],
    'in_progress' => ['primary', 'fa-spinner',       'In Progress'],
    'resolved'    => ['success', 'fa-circle-check',  'Resolved'],
    'closed'      => ['dark',    'fa-lock',           'Closed'],
];

[$sevClass, $sevIcon, $sevLabel] = $severityMeta[$issue['severity']] ?? ['secondary','fa-circle','Unknown'];
[$stClass, $stIcon, $stLabel]    = $statusMeta[$issue['status']] ?? ['secondary','fa-circle','Unknown'];

$pageTitle = $issue['issue_number'] . ' — ' . $issue['title'];
include __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div>
        <h5 class="mb-1">
            <i class="fa fa-triangle-exclamation me-2 text-warning"></i>
            <?= e($issue['issue_number']) ?> — <?= e($issue['title']) ?>
        </h5>
        <div class="d-flex align-items-center gap-2 text-muted small flex-wrap">
            <span class="badge bg-<?= $stClass ?>"><i class="fa <?= $stIcon ?> me-1"></i><?= $stLabel ?></span>
            <span class="badge bg-<?= $sevClass === 'orange' ? 'warning' : $sevClass ?>"><i class="fa <?= $sevIcon ?> me-1"></i><?= $sevLabel ?></span>
            <span><i class="fa fa-tag me-1"></i><?= e($issue['category']) ?></span>
            <span><i class="fa fa-clock me-1"></i>Reported <?= fmtDate($issue['reported_at'], 'd M Y H:i') ?></span>
        </div>
    </div>
    <div class="d-flex gap-2">
        <?php if (canWrite('issues')): ?>
        <a href="add.php?car_id=<?= $issue['car_id'] ?>" class="btn btn-sm btn-warning">
            <i class="fa fa-plus me-1"></i>New Issue
        </a>
        <?php endif; ?>
        <a href="index.php" class="btn btn-sm btn-outline-secondary"><i class="fa fa-arrow-left me-1"></i>Back</a>
    </div>
</div>

<div class="row g-4">
    <!-- Left: details -->
    <div class="col-lg-5">
        <!-- Issue Summary -->
        <div class="card mb-3" style="border-top:3px solid <?= $sevClass==='danger'?'#dc2626':($sevClass==='orange'?'#ea580c':($sevClass==='warning'?'#d97706':'#64748b')) ?>">
            <div class="card-header"><i class="fa fa-triangle-exclamation me-2 text-warning"></i>Issue Summary</div>
            <div class="card-body">
                <dl class="row mb-0" style="font-size:13.5px">
                    <dt class="col-5 text-muted">Issue #</dt><dd class="col-7 fw-bold"><?= e($issue['issue_number']) ?></dd>
                    <dt class="col-5 text-muted">Status</dt><dd class="col-7"><span class="badge bg-<?= $stClass ?>"><?= $stLabel ?></span></dd>
                    <dt class="col-5 text-muted">Severity</dt><dd class="col-7"><span class="badge bg-<?= $sevClass === 'orange' ? 'warning' : $sevClass ?>"><?= $sevLabel ?></span></dd>
                    <dt class="col-5 text-muted">Category</dt><dd class="col-7"><?= e($issue['category']) ?></dd>
                    <dt class="col-5 text-muted">Reported By</dt><dd class="col-7"><?= e($issue['reported_by'] ?? '—') ?></dd>
                    <dt class="col-5 text-muted">Reported On</dt><dd class="col-7"><?= fmtDate($issue['reported_at'], 'd M Y H:i') ?></dd>
                    <?php if ($issue['assigned_to']): ?>
                    <dt class="col-5 text-muted">Assigned To</dt><dd class="col-7 fw-semibold"><?= e($issue['mechanic_name']) ?></dd>
                    <?php endif; ?>
                    <?php if ($issue['resolved_at']): ?>
                    <dt class="col-5 text-muted">Resolved By</dt><dd class="col-7 text-success fw-semibold"><?= e($issue['resolved_by'] ?? '—') ?></dd>
                    <dt class="col-5 text-muted">Resolved On</dt><dd class="col-7 text-success"><?= fmtDate($issue['resolved_at'], 'd M Y H:i') ?></dd>
                    <?php endif; ?>
                </dl>
                <?php if ($issue['description']): ?>
                <hr>
                <div class="small text-muted" style="white-space:pre-wrap"><?= e($issue['description']) ?></div>
                <?php endif; ?>
                <?php if ($issue['resolution_notes']): ?>
                <hr>
                <div class="small fw-semibold mb-1 text-success"><i class="fa fa-check-circle me-1"></i>Resolution Notes</div>
                <div class="small text-muted" style="white-space:pre-wrap"><?= e($issue['resolution_notes']) ?></div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Vehicle -->
        <div class="card">
            <div class="card-header"><i class="fa fa-car me-2"></i>Vehicle</div>
            <div class="card-body">
                <dl class="row mb-0" style="font-size:13.5px">
                    <dt class="col-5 text-muted">Vehicle</dt>
                    <dd class="col-7 fw-semibold"><?= e($issue['make'].' '.$issue['model'].' '.$issue['year']) ?></dd>
                    <dt class="col-5 text-muted">Reg. No.</dt>
                    <dd class="col-7">
                        <?= $issue['registration_number']
                            ? '<span class="badge bg-dark">'.e($issue['registration_number']).'</span>'
                            : '—' ?>
                    </dd>
                    <dt class="col-5 text-muted">Chassis</dt>
                    <dd class="col-7"><code style="font-size:11px"><?= e($issue['chassis_number']) ?></code></dd>
                </dl>
                <div class="mt-3 d-flex gap-2">
                    <a href="<?= BASE_URL ?>/modules/cars/view.php?id=<?= $issue['car_id'] ?>" class="btn btn-sm btn-outline-primary">
                        <i class="fa fa-car me-1"></i>Vehicle Profile
                    </a>
                    <a href="vehicle.php?car_id=<?= $issue['car_id'] ?>" class="btn btn-sm btn-outline-secondary">
                        <i class="fa fa-list me-1"></i>All Issues
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- Right: update form -->
    <div class="col-lg-7">
        <?php if (canWrite('issues') && !in_array($issue['status'], ['closed'])): ?>
        <div class="card mb-3" style="border-top:3px solid #2563eb">
            <div class="card-header fw-semibold"><i class="fa fa-pen me-2"></i>Update Issue</div>
            <div class="card-body">
                <form method="POST">
                    <input type="hidden" name="action" value="update">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Status</label>
                            <select name="status" class="form-select">
                                <?php foreach (['open'=>'Open','in_progress'=>'In Progress','resolved'=>'Resolved','closed'=>'Closed'] as $v=>$l): ?>
                                <option value="<?= $v ?>" <?= $issue['status']===$v?'selected':'' ?>><?= $l ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Assign to Mechanic</label>
                            <select name="assigned_to" class="form-select select2">
                                <option value="">— Unassigned —</option>
                                <?php foreach ($mechanics as $m): ?>
                                <option value="<?= $m['id'] ?>" <?= $issue['assigned_to']==$m['id']?'selected':'' ?>><?= e($m['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Resolution Notes</label>
                            <textarea name="resolution_notes" class="form-control" rows="3"
                                      placeholder="Describe what was done to resolve this issue…"><?= e($issue['resolution_notes'] ?? '') ?></textarea>
                        </div>
                        <div class="col-12">
                            <button type="submit" class="btn btn-primary"><i class="fa fa-save me-1"></i>Save Update</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
        <?php elseif ($issue['status'] === 'closed'): ?>
        <div class="alert alert-secondary"><i class="fa fa-lock me-2"></i>This issue is closed.</div>
        <?php endif; ?>

        <!-- Other open issues on this vehicle -->
        <?php
        $others = $db->prepare("SELECT id, issue_number, title, severity, status FROM car_issues WHERE car_id=? AND id!=? AND status NOT IN ('closed') ORDER BY id DESC LIMIT 5");
        $others->execute([$issue['car_id'], $id]); $others = $others->fetchAll();
        if ($others):
        ?>
        <div class="card">
            <div class="card-header"><i class="fa fa-list me-2"></i>Other Open Issues on this Vehicle</div>
            <div class="card-body p-0">
                <table class="table table-sm table-hover mb-0" style="font-size:13px">
                    <tbody>
                        <?php foreach ($others as $o):
                            [$oSevClass] = $severityMeta[$o['severity']] ?? ['secondary'];
                            [$oStClass,,  $oStLabel] = $statusMeta[$o['status']] ?? ['secondary','','Unknown'];
                        ?>
                        <tr>
                            <td class="ps-3 py-2 fw-semibold text-muted small"><?= e($o['issue_number']) ?></td>
                            <td class="py-2"><?= e($o['title']) ?></td>
                            <td class="py-2"><span class="badge bg-<?= $oSevClass==='orange'?'warning':$oSevClass ?>"><?= ucfirst($o['severity']) ?></span></td>
                            <td class="py-2"><span class="badge bg-<?= $oStClass ?>"><?= $oStLabel ?></span></td>
                            <td class="py-2 pe-3 text-end">
                                <a href="view.php?id=<?= $o['id'] ?>" class="btn btn-xs btn-outline-primary">View</a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <?php if (hasRole('admin') && $issue['status'] === 'closed'): ?>
        <div class="card border-danger mt-3">
            <div class="card-header text-danger"><i class="fa fa-trash me-2"></i>Danger Zone</div>
            <div class="card-body">
                <form method="POST" onsubmit="return confirm('Permanently delete this issue?')">
                    <input type="hidden" name="action" value="delete">
                    <button type="submit" class="btn btn-sm btn-outline-danger"><i class="fa fa-trash me-1"></i>Delete Issue</button>
                </form>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>

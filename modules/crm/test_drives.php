<?php
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
canAccess('crm') || redirect(BASE_URL . '/index.php');

$db  = getDB();
$me  = authUser();
$uid = (int)$me['id'];
$isCrmAgent = ($me['role'] === 'customer_relations');

// ── Auto-migrations ───────────────────────────────────────────────────────────
try {
    $db->exec("CREATE TABLE IF NOT EXISTS crm_test_drives (
        id INT AUTO_INCREMENT PRIMARY KEY,
        lead_id INT NOT NULL,
        car_id INT NULL,
        scheduled_date DATE NOT NULL,
        scheduled_time TIME NOT NULL,
        duration_minutes INT DEFAULT 60,
        status ENUM('scheduled','completed','no_show','cancelled') DEFAULT 'scheduled',
        notes TEXT NULL,
        outcome TEXT NULL,
        created_by INT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )");
} catch (\Throwable $_) {}

try {
    $db->exec("CREATE TABLE IF NOT EXISTS crm_activities (
        id INT AUTO_INCREMENT PRIMARY KEY,
        lead_id INT NOT NULL,
        type VARCHAR(50) NOT NULL DEFAULT 'note',
        summary TEXT NULL,
        outcome TEXT NULL,
        created_by INT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
} catch (\Throwable $_) {}

// ── POST handlers ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // ── Schedule test drive ───────────────────────────────────────────────────
    if ($action === 'schedule' && canWrite('crm')) {
        $leadId        = (int)($_POST['lead_id']          ?? 0);
        $carId         = (int)($_POST['car_id']           ?? 0) ?: null;
        $scheduledDate = trim($_POST['scheduled_date']    ?? '');
        $scheduledTime = trim($_POST['scheduled_time']    ?? '');
        $duration      = (int)($_POST['duration_minutes'] ?? 60);
        $notes         = trim($_POST['notes']             ?? '') ?: null;

        $errors = [];
        if (!$leadId) {
            $errors[] = 'Please select a lead.';
        } else {
            // Verify lead exists and belongs to agent (if CRM agent)
            $chk = $db->prepare($isCrmAgent
                ? "SELECT id FROM crm_leads WHERE id = ? AND assigned_to = ?"
                : "SELECT id FROM crm_leads WHERE id = ?"
            );
            $isCrmAgent ? $chk->execute([$leadId, $uid]) : $chk->execute([$leadId]);
            if (!$chk->fetchColumn()) {
                $errors[] = 'Lead not found or you do not have access to it.';
            }
        }
        if (!$scheduledDate) $errors[] = 'Scheduled date is required.';
        if (!$scheduledTime) $errors[] = 'Scheduled time is required.';

        if (empty($errors)) {
            try {
                $stmt = $db->prepare("
                    INSERT INTO crm_test_drives
                        (lead_id, car_id, scheduled_date, scheduled_time, duration_minutes, notes, created_by)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([$leadId, $carId, $scheduledDate, $scheduledTime, $duration, $notes, $uid]);

                // Log activity on the lead
                $db->prepare("
                    INSERT INTO crm_activities (lead_id, type, summary, created_by)
                    VALUES (?, 'test_drive', ?, ?)
                ")->execute([$leadId, "Test drive scheduled for $scheduledDate at $scheduledTime", $uid]);

                logActivity('create', 'crm_test_drives', (int)$db->lastInsertId(),
                    "Test drive scheduled for lead #$leadId on $scheduledDate");
                setFlash('success', 'Test drive scheduled successfully.');
            } catch (\Throwable $e) {
                setFlash('danger', 'Error scheduling test drive: ' . $e->getMessage());
            }
        } else {
            setFlash('danger', implode(' ', $errors));
        }
        redirect(BASE_URL . '/modules/crm/test_drives.php');
    }

    // ── Update status (complete / outcome) ────────────────────────────────────
    if ($action === 'update_status') {
        $tdId    = (int)($_POST['td_id']  ?? 0);
        $status  = $_POST['status']       ?? '';
        $outcome = trim($_POST['outcome'] ?? '') ?: null;

        $validStatuses = ['scheduled', 'completed', 'no_show', 'cancelled'];
        if (!$tdId || !in_array($status, $validStatuses, true)) {
            setFlash('danger', 'Invalid request.');
            redirect(BASE_URL . '/modules/crm/test_drives.php');
        }

        // Verify ownership
        $ownerCheck = $isCrmAgent
            ? "SELECT td.lead_id FROM crm_test_drives td JOIN crm_leads l ON l.id = td.lead_id WHERE td.id = ? AND l.assigned_to = ?"
            : "SELECT td.lead_id FROM crm_test_drives td WHERE td.id = ?";
        $chk = $db->prepare($ownerCheck);
        $isCrmAgent ? $chk->execute([$tdId, $uid]) : $chk->execute([$tdId]);
        $leadId = $chk->fetchColumn();

        if (!$leadId) {
            setFlash('danger', 'Test drive not found or access denied.');
            redirect(BASE_URL . '/modules/crm/test_drives.php');
        }

        try {
            $db->prepare("UPDATE crm_test_drives SET status = ?, outcome = ?, updated_at = NOW() WHERE id = ?")
               ->execute([$status, $outcome, $tdId]);

            if ($status === 'completed') {
                $db->prepare("
                    INSERT INTO crm_activities (lead_id, type, summary, outcome, created_by)
                    VALUES (?, 'test_drive', 'Test drive completed', ?, ?)
                ")->execute([$leadId, $outcome, $uid]);
            }

            logActivity('update', 'crm_test_drives', $tdId, "Status updated to: $status");
            setFlash('success', 'Test drive updated successfully.');
        } catch (\Throwable $e) {
            setFlash('danger', 'Error updating test drive: ' . $e->getMessage());
        }
        redirect(BASE_URL . '/modules/crm/test_drives.php');
    }

    // ── Cancel test drive ─────────────────────────────────────────────────────
    if ($action === 'cancel') {
        $tdId = (int)($_POST['td_id'] ?? 0);
        if (!$tdId) {
            setFlash('danger', 'Invalid request.');
            redirect(BASE_URL . '/modules/crm/test_drives.php');
        }

        $ownerCheck = $isCrmAgent
            ? "SELECT td.id FROM crm_test_drives td JOIN crm_leads l ON l.id = td.lead_id WHERE td.id = ? AND l.assigned_to = ?"
            : "SELECT td.id FROM crm_test_drives td WHERE td.id = ?";
        $chk = $db->prepare($ownerCheck);
        $isCrmAgent ? $chk->execute([$tdId, $uid]) : $chk->execute([$tdId]);

        if (!$chk->fetchColumn()) {
            setFlash('danger', 'Test drive not found or access denied.');
            redirect(BASE_URL . '/modules/crm/test_drives.php');
        }

        try {
            $db->prepare("UPDATE crm_test_drives SET status = 'cancelled', updated_at = NOW() WHERE id = ?")
               ->execute([$tdId]);
            logActivity('update', 'crm_test_drives', $tdId, 'Test drive cancelled');
            setFlash('success', 'Test drive cancelled.');
        } catch (\Throwable $e) {
            setFlash('danger', 'Error cancelling test drive: ' . $e->getMessage());
        }
        redirect(BASE_URL . '/modules/crm/test_drives.php');
    }
}

// ── Data isolation clause ─────────────────────────────────────────────────────
$ownerJoin = $isCrmAgent ? "AND l.assigned_to = {$uid}" : '';

// ── Load test drives ──────────────────────────────────────────────────────────
$activeTab = $_GET['tab'] ?? 'all';
$today     = date('Y-m-d');

try {
    $allDrives = $db->query("
        SELECT td.*,
               l.name AS lead_name, l.phone AS lead_phone,
               c.make, c.model, c.year, c.registration_number,
               u.name AS agent_name
        FROM crm_test_drives td
        JOIN crm_leads l ON l.id = td.lead_id
        LEFT JOIN cars c ON c.id = td.car_id
        LEFT JOIN users u ON u.id = td.created_by
        WHERE 1=1 {$ownerJoin}
        ORDER BY td.scheduled_date ASC, td.scheduled_time ASC
    ")->fetchAll();
} catch (\Throwable $e) {
    $allDrives = [];
}

// ── Tab counts ────────────────────────────────────────────────────────────────
$tabCounts = ['all' => 0, 'today' => 0, 'upcoming' => 0, 'completed' => 0, 'other' => 0];
foreach ($allDrives as $td) {
    $tabCounts['all']++;
    if ($td['scheduled_date'] === $today && $td['status'] === 'scheduled') $tabCounts['today']++;
    if ($td['scheduled_date'] > $today  && $td['status'] === 'scheduled')  $tabCounts['upcoming']++;
    if ($td['status'] === 'completed')                                      $tabCounts['completed']++;
    if (in_array($td['status'], ['no_show', 'cancelled']))                  $tabCounts['other']++;
}

// Filter drives for active tab
$drives = array_filter($allDrives, function ($td) use ($activeTab, $today) {
    if ($activeTab === 'today')     return $td['scheduled_date'] === $today && $td['status'] === 'scheduled';
    if ($activeTab === 'upcoming')  return $td['scheduled_date'] > $today   && $td['status'] === 'scheduled';
    if ($activeTab === 'completed') return $td['status'] === 'completed';
    if ($activeTab === 'other')     return in_array($td['status'], ['no_show', 'cancelled']);
    return true; // 'all'
});

// ── Summary stats ─────────────────────────────────────────────────────────────
$statToday = 0;
$statWeek  = 0;
$statMonth = 0;
$weekEnd   = date('Y-m-d', strtotime('+6 days'));
foreach ($allDrives as $td) {
    if ($td['scheduled_date'] === $today)                                          $statToday++;
    if ($td['scheduled_date'] >= $today && $td['scheduled_date'] <= $weekEnd)     $statWeek++;
    if ($td['status'] === 'completed'
        && date('Y-m', strtotime($td['scheduled_date'])) === date('Y-m'))         $statMonth++;
}

// ── Forms: active CRM leads + available cars ──────────────────────────────────
try {
    $leadsStmt = $db->prepare($isCrmAgent
        ? "SELECT id, name, phone FROM crm_leads WHERE stage NOT IN ('lost','delivered') AND assigned_to = ? ORDER BY name"
        : "SELECT id, name, phone FROM crm_leads WHERE stage NOT IN ('lost','delivered') ORDER BY name"
    );
    $isCrmAgent ? $leadsStmt->execute([$uid]) : $leadsStmt->execute([]);
    $formLeads = $leadsStmt->fetchAll();
} catch (\Throwable $e) {
    $formLeads = [];
}

try {
    $formCars = $db->query("
        SELECT id, make, model, year, registration_number
        FROM cars
        WHERE status IN ('completed','arrived')
        ORDER BY make, model
    ")->fetchAll();
} catch (\Throwable $e) {
    $formCars = [];
}

$pageTitle = 'Test Drives';

$statusBadgeMap = [
    'scheduled' => 'primary',
    'completed' => 'success',
    'no_show'   => 'danger',
    'cancelled' => 'secondary',
];
$statusLabelMap = [
    'scheduled' => 'Scheduled',
    'completed' => 'Completed',
    'no_show'   => 'No-show',
    'cancelled' => 'Cancelled',
];

include __DIR__ . '/../../includes/header.php';
?>

<!-- ── Page Header ───────────────────────────────────────────────────────────── -->
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h5 class="mb-0">
        <i class="fa fa-car-side me-2 text-primary"></i>Test Drives
        <span class="badge bg-secondary ms-1"><?= count($allDrives) ?></span>
    </h5>
    <?php if (canWrite('crm')): ?>
    <button type="button" class="btn btn-primary btn-sm"
            data-bs-toggle="modal" data-bs-target="#scheduleTdModal">
        <i class="fa fa-plus me-1"></i>Schedule Test Drive
    </button>
    <?php endif; ?>
</div>

<!-- ── Summary Stats ─────────────────────────────────────────────────────────── -->
<div class="row g-3 mb-3">
    <div class="col-sm-4">
        <div class="card text-center py-3">
            <div class="fs-2 fw-bold text-primary"><?= $statToday ?></div>
            <div class="text-muted small">Today's Drives</div>
        </div>
    </div>
    <div class="col-sm-4">
        <div class="card text-center py-3">
            <div class="fs-2 fw-bold text-info"><?= $statWeek ?></div>
            <div class="text-muted small">This Week</div>
        </div>
    </div>
    <div class="col-sm-4">
        <div class="card text-center py-3">
            <div class="fs-2 fw-bold text-success"><?= $statMonth ?></div>
            <div class="text-muted small">Completed This Month</div>
        </div>
    </div>
</div>

<!-- ── Filter Tabs ───────────────────────────────────────────────────────────── -->
<ul class="nav nav-tabs mb-3">
    <?php
    $tabs = [
        'all'       => ['All',                  $tabCounts['all']],
        'today'     => ['Today',                $tabCounts['today']],
        'upcoming'  => ['Upcoming',             $tabCounts['upcoming']],
        'completed' => ['Completed',            $tabCounts['completed']],
        'other'     => ['No-show / Cancelled',  $tabCounts['other']],
    ];
    foreach ($tabs as $tabKey => [$tabLabel, $tabCount]):
        $isActive = ($activeTab === $tabKey);
    ?>
    <li class="nav-item">
        <a class="nav-link <?= $isActive ? 'active' : '' ?>" href="?tab=<?= $tabKey ?>">
            <?= $tabLabel ?>
            <?php if ($tabCount > 0): ?>
            <span class="badge ms-1 <?= $isActive ? 'bg-white text-dark' : 'bg-secondary' ?>"
                  style="font-size:10px"><?= $tabCount ?></span>
            <?php endif; ?>
        </a>
    </li>
    <?php endforeach; ?>
</ul>

<!-- ── Test Drives Table ─────────────────────────────────────────────────────── -->
<div class="card">
    <div class="card-body p-0">
        <?php if (empty($drives)): ?>
        <div class="text-center py-5 text-muted">
            <i class="fa fa-car-side fa-3x mb-3 opacity-25 d-block"></i>
            <div class="fw-semibold mb-2">
                <?php
                $emptyMsg = [
                    'all'       => 'No test drives scheduled yet.',
                    'today'     => 'No test drives scheduled for today.',
                    'upcoming'  => 'No upcoming test drives.',
                    'completed' => 'No completed test drives.',
                    'other'     => 'No no-show or cancelled drives.',
                ];
                echo $emptyMsg[$activeTab] ?? 'No test drives found.';
                ?>
            </div>
            <?php if ($activeTab === 'all' && canWrite('crm')): ?>
            <button class="btn btn-primary btn-sm"
                    data-bs-toggle="modal" data-bs-target="#scheduleTdModal">
                <i class="fa fa-plus me-1"></i>Schedule First Test Drive
            </button>
            <?php endif; ?>
        </div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover mb-0" style="font-size:13.5px">
                <thead class="table-light">
                    <tr>
                        <th class="ps-3">Date / Time</th>
                        <th>Lead</th>
                        <th>Car</th>
                        <th>Duration</th>
                        <th>Status</th>
                        <?php if (!$isCrmAgent): ?><th>Agent</th><?php endif; ?>
                        <th class="pe-3">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($drives as $td):
                    $statusColor = $statusBadgeMap[$td['status']] ?? 'secondary';
                    $statusLabel = $statusLabelMap[$td['status']] ?? ucfirst($td['status']);
                    $isPast      = $td['scheduled_date'] < $today;
                    $carStr = ($td['make'] && $td['model'])
                        ? e($td['year'] . ' ' . $td['make'] . ' ' . $td['model'])
                        : '';
                ?>
                <tr class="<?= ($td['status'] === 'scheduled' && $isPast) ? 'table-warning' : '' ?>">
                    <td class="ps-3">
                        <div class="fw-semibold"><?= fmtDate($td['scheduled_date'], 'd M Y') ?></div>
                        <div class="text-muted small"><?= date('g:i A', strtotime($td['scheduled_time'])) ?></div>
                    </td>
                    <td>
                        <a href="view_lead.php?id=<?= (int)$td['lead_id'] ?>"
                           class="text-decoration-none fw-semibold">
                            <?= e($td['lead_name']) ?>
                        </a>
                        <?php if ($td['lead_phone']): ?>
                        <div class="text-muted small"><?= e($td['lead_phone']) ?></div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($carStr): ?>
                        <div><?= $carStr ?></div>
                        <?php if ($td['registration_number']): ?>
                        <div class="text-muted small"><?= e($td['registration_number']) ?></div>
                        <?php endif; ?>
                        <?php else: ?>
                        <span class="text-muted">—</span>
                        <?php endif; ?>
                    </td>
                    <td class="small"><?= (int)$td['duration_minutes'] ?> min</td>
                    <td>
                        <span class="badge bg-<?= $statusColor ?>" style="font-size:11px">
                            <?= $statusLabel ?>
                        </span>
                    </td>
                    <?php if (!$isCrmAgent): ?>
                    <td class="small text-muted"><?= e($td['agent_name'] ?? '—') ?></td>
                    <?php endif; ?>
                    <td class="pe-3">
                        <?php if ($td['status'] === 'scheduled'): ?>
                        <div class="d-flex gap-1 flex-wrap">
                            <!-- Mark Complete -->
                            <button type="button" class="btn btn-xs btn-success"
                                    onclick="openOutcomeModal(<?= (int)$td['id'] ?>)"
                                    title="Mark Complete">
                                <i class="fa fa-check me-1"></i>Complete
                            </button>
                            <!-- No-show -->
                            <form method="POST" class="d-inline"
                                  onsubmit="return confirm('Mark this test drive as no-show?')">
                                <input type="hidden" name="action"  value="update_status">
                                <input type="hidden" name="td_id"   value="<?= (int)$td['id'] ?>">
                                <input type="hidden" name="status"  value="no_show">
                                <button type="submit" class="btn btn-xs btn-warning" title="No-show">
                                    <i class="fa fa-user-slash me-1"></i>No-show
                                </button>
                            </form>
                            <!-- Cancel -->
                            <form method="POST" class="d-inline"
                                  onsubmit="return confirm('Cancel this test drive?')">
                                <input type="hidden" name="action" value="cancel">
                                <input type="hidden" name="td_id"  value="<?= (int)$td['id'] ?>">
                                <button type="submit" class="btn btn-xs btn-outline-danger" title="Cancel">
                                    <i class="fa fa-times"></i>
                                </button>
                            </form>
                        </div>
                        <?php elseif (in_array($td['status'], ['completed', 'no_show'])): ?>
                        <?php if ($td['outcome']): ?>
                        <span class="text-muted small d-block"
                              style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"
                              title="<?= e($td['outcome']) ?>">
                            <i class="fa fa-comment-dots me-1"></i><?= e(mb_strimwidth($td['outcome'], 0, 45, '…')) ?>
                        </span>
                        <?php else: ?>
                        <span class="text-muted small">No outcome recorded</span>
                        <?php endif; ?>
                        <?php else: ?>
                        <span class="text-muted small">Cancelled</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════════════════ -->
<!-- Modal: Schedule Test Drive                                                  -->
<!-- ═══════════════════════════════════════════════════════════════════════════ -->
<?php if (canWrite('crm')): ?>
<div class="modal fade" id="scheduleTdModal" tabindex="-1"
     aria-labelledby="scheduleTdLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="action" value="schedule">
                <div class="modal-header">
                    <h5 class="modal-title" id="scheduleTdLabel">
                        <i class="fa fa-calendar-plus me-2 text-primary"></i>Schedule Test Drive
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <!-- Lead -->
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">
                                Lead <span class="text-danger">*</span>
                            </label>
                            <select name="lead_id" class="form-select select2-td-lead" required>
                                <option value="">— Select Lead —</option>
                                <?php foreach ($formLeads as $fl): ?>
                                <option value="<?= (int)$fl['id'] ?>">
                                    <?= e($fl['name']) ?><?= $fl['phone'] ? ' (' . e($fl['phone']) . ')' : '' ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <!-- Car -->
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">
                                Car <span class="text-muted fw-normal">(optional)</span>
                            </label>
                            <select name="car_id" class="form-select select2-td-car">
                                <option value="">— No specific car —</option>
                                <?php foreach ($formCars as $fc): ?>
                                <option value="<?= (int)$fc['id'] ?>">
                                    <?= e($fc['year'] . ' ' . $fc['make'] . ' ' . $fc['model']) ?>
                                    <?= $fc['registration_number'] ? ' — ' . e($fc['registration_number']) : '' ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <!-- Date -->
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">
                                Date <span class="text-danger">*</span>
                            </label>
                            <input type="date" name="scheduled_date" class="form-control"
                                   min="<?= date('Y-m-d') ?>" required>
                        </div>
                        <!-- Time -->
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">
                                Time <span class="text-danger">*</span>
                            </label>
                            <input type="time" name="scheduled_time" class="form-control" required>
                        </div>
                        <!-- Duration -->
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">Duration</label>
                            <select name="duration_minutes" class="form-select">
                                <option value="30">30 minutes</option>
                                <option value="60" selected>60 minutes</option>
                                <option value="90">90 minutes</option>
                                <option value="120">120 minutes</option>
                            </select>
                        </div>
                        <!-- Notes -->
                        <div class="col-12">
                            <label class="form-label fw-semibold">Notes</label>
                            <textarea name="notes" class="form-control" rows="3"
                                      placeholder="Any special requirements or notes for this test drive…"></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fa fa-calendar-check me-1"></i>Schedule Test Drive
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ═══════════════════════════════════════════════════════════════════════════ -->
<!-- Modal: Mark Outcome (Complete)                                              -->
<!-- ═══════════════════════════════════════════════════════════════════════════ -->
<div class="modal fade" id="outcomeModal" tabindex="-1"
     aria-labelledby="outcomeModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" id="outcomeForm">
                <input type="hidden" name="action" value="update_status">
                <input type="hidden" name="status" value="completed">
                <input type="hidden" name="td_id"  id="outcomeTdId">
                <div class="modal-header">
                    <h5 class="modal-title" id="outcomeModalLabel">
                        <i class="fa fa-check-circle me-2 text-success"></i>Mark Test Drive Completed
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">
                            Outcome / Notes <span class="text-danger">*</span>
                        </label>
                        <textarea name="outcome" id="outcomeText" class="form-control" rows="4"
                                  placeholder="Describe how the test drive went, any feedback from the customer…"
                                  required></textarea>
                    </div>
                    <div class="alert alert-info py-2 mb-0" style="font-size:13px">
                        <i class="fa fa-info-circle me-1"></i>
                        This outcome will be logged as a CRM activity on the lead.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success">
                        <i class="fa fa-check me-1"></i>Mark as Completed
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// Move modals to <body> to escape the page-body animation stacking context
document.addEventListener('DOMContentLoaded', function () {
    ['scheduleTdModal', 'outcomeModal'].forEach(function (id) {
        var el = document.getElementById(id);
        if (el && el.parentNode !== document.body) document.body.appendChild(el);
    });
});

// Open outcome modal and pre-fill the test drive ID
function openOutcomeModal(tdId) {
    document.getElementById('outcomeTdId').value = tdId;
    document.getElementById('outcomeText').value  = '';
    bootstrap.Modal.getOrCreateInstance(document.getElementById('outcomeModal')).show();
}

// Init Select2 inside schedule modal when it opens
document.addEventListener('DOMContentLoaded', function () {
    var schedModal = document.getElementById('scheduleTdModal');
    if (!schedModal) return;
    schedModal.addEventListener('shown.bs.modal', function () {
        if (typeof $.fn.select2 === 'undefined') return;
        $('.select2-td-lead', schedModal).select2({
            theme: 'bootstrap-5',
            placeholder: '— Select Lead —',
            dropdownParent: schedModal,
            width: '100%'
        });
        $('.select2-td-car', schedModal).select2({
            theme: 'bootstrap-5',
            placeholder: '— No specific car —',
            dropdownParent: schedModal,
            width: '100%',
            allowClear: true
        });
    });
});
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>

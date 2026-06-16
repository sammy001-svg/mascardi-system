<?php
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
canAccess('crm') || redirect(BASE_URL . '/index.php');

$db = getDB();

// Auto-migration: add client_id column to crm_activities if it doesn't exist
try {
    $db->exec("ALTER TABLE crm_activities ADD COLUMN client_id INT NULL AFTER lead_id");
} catch (\Throwable $_) {}

$clientId = (int)($_GET['client_id'] ?? 0);
if (!$clientId) {
    setFlash('error', 'No client specified.');
    redirect(BASE_URL . '/modules/crm/leads.php');
}

$me  = authUser();
$uid = (int)$me['id'];

// Load client
$clientStmt = $db->prepare("SELECT * FROM clients WHERE id = ?");
$clientStmt->execute([$clientId]);
$client = $clientStmt->fetch();

if (!$client) {
    setFlash('error', 'Client not found.');
    redirect(BASE_URL . '/modules/crm/leads.php');
}

$pageTitle = 'History — ' . $client['name'];

// ── POST handler: log an activity against this client ──────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'log_activity_client') {
        if (!canWrite('crm')) {
            setFlash('error', 'You do not have permission to log activities.');
            redirect(BASE_URL . '/modules/crm/client_history.php?client_id=' . $clientId);
        }

        $type      = $_POST['type']                    ?? 'note';
        $summary   = trim($_POST['summary']            ?? '');
        $outcome   = trim($_POST['outcome']            ?? '') ?: null;
        $followUp  = trim($_POST['follow_up_date']     ?? '') ?: null;
        $leadId    = (int)($_POST['lead_id']           ?? 0) ?: null;

        if ($summary) {
            $db->prepare("INSERT INTO crm_activities (client_id, lead_id, type, summary, outcome, follow_up_date, created_by) VALUES (?,?,?,?,?,?,?)")
               ->execute([$clientId, $leadId, $type, $summary, $outcome, $followUp, $uid]);
            setFlash('success', 'Activity logged successfully.');
        } else {
            setFlash('error', 'Summary is required.');
        }
        redirect(BASE_URL . '/modules/crm/client_history.php?client_id=' . $clientId);
    }
}

// Load all activities for this client (direct or via linked leads)
$activitiesStmt = $db->prepare("
    SELECT a.*, u.name AS by_name, l.name AS lead_name
    FROM crm_activities a
    LEFT JOIN users u ON u.id = a.created_by
    LEFT JOIN crm_leads l ON l.id = a.lead_id
    WHERE a.client_id = ?
       OR a.lead_id IN (SELECT id FROM crm_leads WHERE client_id = ?)
    ORDER BY a.created_at DESC
");
$activitiesStmt->execute([$clientId, $clientId]);
$activities = $activitiesStmt->fetchAll();

// Load linked leads
$leadsStmt = $db->prepare("SELECT id, name, stage FROM crm_leads WHERE client_id = ?");
$leadsStmt->execute([$clientId]);
$linkedLeads = $leadsStmt->fetchAll();

$activityTypes = [
    'call'       => ['Call',       'fa-phone',        'text-success'],
    'whatsapp'   => ['WhatsApp',   'fa-comment-dots', 'text-success'],
    'email'      => ['Email',      'fa-envelope',     'text-primary'],
    'visit'      => ['Visit',      'fa-location-dot', 'text-warning'],
    'test_drive' => ['Test Drive', 'fa-car-side',     'text-purple'],
    'meeting'    => ['Meeting',    'fa-users',        'text-info'],
    'note'       => ['Note',       'fa-note-sticky',  'text-secondary'],
];

$stages = [
    'hot'       => 'danger',
    'lukewarm'  => 'warning',
    'cold'      => 'info',
    'lost'      => 'secondary',
    'reserved'  => 'purple',
    'delivered' => 'success',
];

include __DIR__ . '/../../includes/header.php';
?>

<!-- Page header -->
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div>
        <h5 class="mb-1">
            <i class="fa fa-clock-rotate-left me-2 text-primary"></i>Communication History
            <span class="text-muted fw-normal">— <?= e($client['name']) ?></span>
        </h5>
        <span class="badge bg-secondary">
            <i class="fa fa-comments me-1"></i><?= count($activities) ?> <?= count($activities) === 1 ? 'activity' : 'activities' ?>
        </span>
    </div>
    <div class="d-flex gap-2 flex-wrap align-items-center">
        <!-- Back button: to single lead if only one, otherwise to leads list -->
        <?php if (count($linkedLeads) === 1): ?>
        <a href="<?= BASE_URL ?>/modules/crm/view_lead.php?id=<?= $linkedLeads[0]['id'] ?>"
           class="btn btn-sm btn-outline-secondary">
            <i class="fa fa-arrow-left me-1"></i>Back to Lead
        </a>
        <?php else: ?>
        <a href="<?= BASE_URL ?>/modules/crm/leads.php" class="btn btn-sm btn-outline-secondary">
            <i class="fa fa-arrow-left me-1"></i>All Leads
        </a>
        <?php endif; ?>
        <a href="<?= BASE_URL ?>/modules/clients/view.php?id=<?= $clientId ?>"
           class="btn btn-sm btn-outline-primary">
            <i class="fa fa-user me-1"></i>View Client
        </a>
        <?php if (canWrite('crm')): ?>
        <button type="button" class="btn btn-sm btn-success" id="showLogFormBtn">
            <i class="fa fa-plus me-1"></i>Log Interaction
        </button>
        <?php endif; ?>
    </div>
</div>

<!-- Linked leads summary (if multiple) -->
<?php if (count($linkedLeads) > 1): ?>
<div class="card mb-3">
    <div class="card-header fw-semibold py-2">
        <i class="fa fa-list me-2 text-primary"></i>Linked Leads
    </div>
    <div class="card-body py-2 d-flex flex-wrap gap-2">
        <?php foreach ($linkedLeads as $ll): ?>
        <a href="<?= BASE_URL ?>/modules/crm/view_lead.php?id=<?= $ll['id'] ?>"
           class="badge bg-<?= $stages[$ll['stage']] ?? 'secondary' ?> text-decoration-none" style="font-size:13px">
            <i class="fa fa-user me-1"></i><?= e($ll['name']) ?>
            <span class="ms-1 opacity-75">(<?= ucfirst($ll['stage']) ?>)</span>
        </a>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<?php if (canWrite('crm')): ?>
<!-- Log Interaction Form (collapsible) -->
<div class="card mb-4" id="logFormCard" style="display:none; border-top: 3px solid #16a34a">
    <div class="card-header fw-semibold d-flex justify-content-between align-items-center">
        <span><i class="fa fa-plus-circle me-2 text-success"></i>Log Interaction</span>
        <button type="button" class="btn btn-sm btn-link text-muted p-0" id="hideLogFormBtn">
            <i class="fa fa-xmark"></i>
        </button>
    </div>
    <div class="card-body">
        <form method="POST">
            <input type="hidden" name="action" value="log_activity_client">
            <input type="hidden" name="client_id" value="<?= $clientId ?>">
            <?php if (count($linkedLeads) === 1): ?>
            <input type="hidden" name="lead_id" value="<?= $linkedLeads[0]['id'] ?>">
            <?php endif; ?>
            <div class="row g-3">
                <div class="col-md-3">
                    <label class="form-label small fw-semibold">Type</label>
                    <select name="type" class="form-select form-select-sm">
                        <?php foreach ($activityTypes as $k => [$lbl, $ico, $cls]): ?>
                        <option value="<?= $k ?>"><?= $lbl ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label small fw-semibold">Summary <span class="text-danger">*</span></label>
                    <input type="text" name="summary" class="form-control form-control-sm" required
                           placeholder="e.g. Called client, discussed pricing…">
                </div>
                <div class="col-md-3">
                    <label class="form-label small fw-semibold">Follow-up Date</label>
                    <input type="date" name="follow_up_date" class="form-control form-control-sm">
                </div>
                <?php if (count($linkedLeads) > 1): ?>
                <div class="col-md-4">
                    <label class="form-label small fw-semibold">Link to Lead (optional)</label>
                    <select name="lead_id" class="form-select form-select-sm">
                        <option value="">— None —</option>
                        <?php foreach ($linkedLeads as $ll): ?>
                        <option value="<?= $ll['id'] ?>"><?= e($ll['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                <div class="col-12">
                    <label class="form-label small fw-semibold">Outcome / Notes</label>
                    <textarea name="outcome" class="form-control form-control-sm" rows="2"
                              placeholder="What was the outcome? Any next steps?"></textarea>
                </div>
                <div class="col-12">
                    <button class="btn btn-sm btn-success">
                        <i class="fa fa-check me-1"></i>Log Activity
                    </button>
                    <button type="button" class="btn btn-sm btn-outline-secondary ms-2" id="hideLogFormBtn2">
                        Cancel
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- Activity Timeline -->
<div class="card">
    <div class="card-header fw-semibold">
        <i class="fa fa-clock-rotate-left me-2 text-primary"></i>Activity Timeline
        <span class="badge bg-secondary ms-1"><?= count($activities) ?></span>
    </div>

    <?php if (empty($activities)): ?>
    <div class="card-body text-center py-5 text-muted">
        <i class="fa fa-comments fa-3x mb-3 d-block opacity-25"></i>
        <p class="mb-1 fw-semibold">No interactions recorded yet</p>
        <p class="small mb-0">Use the "Log Interaction" button above to record the first activity for this client.</p>
    </div>

    <?php else: ?>
    <div class="card-body p-0">
        <div class="timeline ps-3 pe-3 pt-3">
        <?php foreach ($activities as $act):
            [$aLabel, $aIcon, $aClass] = $activityTypes[$act['type']] ?? ['Note', 'fa-note-sticky', 'text-secondary'];
            // Special handling for test_drive purple color
            $iconStyle = ($act['type'] === 'test_drive') ? 'style="color:#9333ea"' : '';
            $iconClass = ($act['type'] === 'test_drive') ? '' : $aClass;
        ?>
        <div class="d-flex gap-3 mb-3">
            <!-- Icon circle -->
            <div class="flex-shrink-0" style="width:34px;height:34px;border-radius:50%;background:#f1f5f9;display:flex;align-items:center;justify-content:center;margin-top:2px">
                <i class="fa <?= $aIcon ?> <?= $iconClass ?>" <?= $iconStyle ?>></i>
            </div>
            <!-- Content -->
            <div class="flex-grow-1 border-bottom pb-3">
                <div class="d-flex justify-content-between align-items-start flex-wrap gap-1">
                    <div>
                        <span class="badge bg-light text-dark border me-1" style="font-size:10px"><?= $aLabel ?></span>
                        <span class="fw-medium" style="font-size:13.5px"><?= e($act['summary']) ?></span>
                        <?php if (!empty($act['lead_name'])): ?>
                        <span class="badge bg-info text-dark ms-1" style="font-size:10px">
                            <i class="fa fa-user me-1"></i><?= e($act['lead_name']) ?>
                        </span>
                        <?php endif; ?>
                    </div>
                    <span class="text-muted small flex-shrink-0"><?= fmtDate($act['created_at'], 'd M Y, H:i') ?></span>
                </div>

                <?php if ($act['outcome']): ?>
                <div class="text-muted mt-1" style="font-size:12.5px"><?= nl2br(e($act['outcome'])) ?></div>
                <?php endif; ?>

                <?php if ($act['follow_up_date']): ?>
                <div class="mt-1">
                    <span class="badge bg-warning text-dark" style="font-size:10px">
                        <i class="fa fa-calendar me-1"></i>Follow-up: <?= fmtDate($act['follow_up_date'], 'd M Y') ?>
                    </span>
                </div>
                <?php endif; ?>

                <div class="text-muted mt-1" style="font-size:11px">
                    By <?= e($act['by_name'] ?? 'System') ?>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php
$extraJs = <<<'ENDJS'
<script>
(function () {
    var logCard    = document.getElementById('logFormCard');
    var showBtn    = document.getElementById('showLogFormBtn');
    var hideBtn1   = document.getElementById('hideLogFormBtn');
    var hideBtn2   = document.getElementById('hideLogFormBtn2');

    function showForm() {
        if (!logCard) return;
        logCard.style.display = '';
        logCard.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
    function hideForm() {
        if (!logCard) return;
        logCard.style.display = 'none';
    }

    showBtn  && showBtn.addEventListener('click',  showForm);
    hideBtn1 && hideBtn1.addEventListener('click', hideForm);
    hideBtn2 && hideBtn2.addEventListener('click', hideForm);
}());
</script>
ENDJS;
include __DIR__ . '/../../includes/footer.php';
?>

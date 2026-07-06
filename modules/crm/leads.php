<?php
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
canAccess('crm') || redirect(BASE_URL . '/index.php');

$db  = getDB();
$me  = authUser();
$uid = (int)$me['id'];

// Data isolation: CRM agents see only their own leads; supervisors see leads for their location
$isCrmAgent   = ($me['role'] === 'customer_relations');
$isSupervisor = ($me['role'] === 'supervisor');
$supLocId     = $isSupervisor ? supervisorLocationId() : null;
$pageTitle    = $isCrmAgent ? 'My Leads' : ($isSupervisor ? 'Location Leads' : 'All Leads');

// ─── SINGLE LEAD DELETE (super_admin only) ───────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_lead') {
    if ($me['role'] !== 'super_admin') {
        setFlash('danger', 'Only Super Admin can delete leads.');
        redirect('leads.php');
    }
    $delId = (int)($_POST['lead_id'] ?? 0);
    if (!$delId) { setFlash('warning', 'Invalid lead.'); redirect('leads.php'); }

    try {
        $db->prepare("DELETE FROM crm_activities  WHERE lead_id = ?")->execute([$delId]);
        $db->prepare("DELETE FROM crm_test_drives WHERE lead_id = ?")->execute([$delId]);
        $db->prepare("DELETE FROM crm_leads        WHERE id      = ?")->execute([$delId]);
        setFlash('success', 'Lead deleted successfully.');
    } catch (\Throwable $e) {
        setFlash('danger', 'Delete failed: ' . $e->getMessage());
    }
    redirect('leads.php');
}
// ─── END DELETE HANDLER ───────────────────────────────────────────────────────

// ─── BULK ACTION POST HANDLER ────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['bulk_action'])) {
    $bulkAction = $_POST['bulk_action'];
    $leadIds    = isset($_POST['lead_ids']) && is_array($_POST['lead_ids'])
                    ? array_map('intval', $_POST['lead_ids'])
                    : [];

    if (empty($leadIds)) {
        setFlash('warning', 'No leads selected.');
        redirect($_SERVER['HTTP_REFERER'] ?? 'leads.php');
    }

    // Build per-ID placeholders
    $placeholders = implode(',', array_fill(0, count($leadIds), '?'));

    // Restrict bulk updates to owned/location-scoped leads
    if ($isCrmAgent) {
        $ownerClause = " AND assigned_to = $uid";
    } elseif ($isSupervisor) {
        $ownerClause = $supLocId
            ? " AND assigned_to IN (SELECT id FROM users WHERE location_id = $supLocId)"
            : " AND 1 = 0";
    } else {
        $ownerClause = '';
    }

    if ($bulkAction === 'bulk_reassign') {
        if ($isCrmAgent) {
            setFlash('danger', 'You do not have permission to reassign leads.');
            redirect('leads.php');
        }
        $reassignTo = (int)($_POST['reassign_to'] ?? 0);
        if (!$reassignTo) {
            setFlash('warning', 'Please select a staff member to assign to.');
            redirect('leads.php');
        }
        try {
            $stmt = $db->prepare("UPDATE crm_leads SET assigned_to = ?, updated_at = NOW() WHERE id IN ($placeholders)");
            $stmt->execute(array_merge([$reassignTo], $leadIds));
            setFlash('success', count($leadIds) . ' lead(s) reassigned successfully.');
        } catch (\Throwable $e) {
            setFlash('danger', 'Reassign failed: ' . $e->getMessage());
        }
        redirect('leads.php');
    }

    if ($bulkAction === 'bulk_stage') {
        $validStages = ['hot','lukewarm','cold','lost','reserved','delivered'];
        $newStage    = $_POST['bulk_stage'] ?? '';
        if (!in_array($newStage, $validStages, true)) {
            setFlash('warning', 'Please select a valid stage.');
            redirect('leads.php');
        }
        try {
            $stmt = $db->prepare("UPDATE crm_leads SET stage = ?, updated_at = NOW() WHERE id IN ($placeholders)$ownerClause");
            $stmt->execute(array_merge([$newStage], $leadIds));
            setFlash('success', count($leadIds) . ' lead(s) updated to stage "' . htmlspecialchars($newStage) . '".');
        } catch (\Throwable $e) {
            setFlash('danger', 'Stage update failed: ' . $e->getMessage());
        }
        redirect('leads.php');
    }

    if ($bulkAction === 'bulk_followup') {
        $newDate = $_POST['bulk_followup_date'] ?? '';
        if (!$newDate || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $newDate)) {
            setFlash('warning', 'Please select a valid follow-up date.');
            redirect('leads.php');
        }
        try {
            $stmt = $db->prepare("UPDATE crm_leads SET follow_up_date = ?, updated_at = NOW() WHERE id IN ($placeholders)$ownerClause");
            $stmt->execute(array_merge([$newDate], $leadIds));
            setFlash('success', count($leadIds) . ' lead(s) follow-up date set to ' . htmlspecialchars($newDate) . '.');
        } catch (\Throwable $e) {
            setFlash('danger', 'Follow-up update failed: ' . $e->getMessage());
        }
        redirect('leads.php');
    }

    if ($bulkAction === 'bulk_export') {
        // Source labels needed for export output
        $exportSources = [
            'walk_in'    => 'Walk-in',    'referral'  => 'Referral',
            'facebook'   => 'Facebook',   'instagram' => 'Instagram',
            'website'    => 'Website',    'phone_call'=> 'Phone Call',
            'whatsapp'   => 'WhatsApp',   'other'     => 'Other',
        ];
        try {
            $extraOwner = $isCrmAgent ? " AND l.assigned_to = $uid" : '';
            $stmt = $db->prepare("
                SELECT l.name, l.phone, l.email, l.source, l.campaign, l.stage,
                       l.interested_in, l.budget, l.follow_up_date, l.created_at,
                       u.name AS assigned_name
                FROM crm_leads l
                LEFT JOIN users u ON u.id = l.assigned_to
                WHERE l.id IN ($placeholders)$extraOwner
                ORDER BY l.created_at DESC
            ");
            $stmt->execute($leadIds);
            $exportLeads = $stmt->fetchAll();
        } catch (\Throwable $e) {
            $exportLeads = [];
        }

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="leads_export_' . date('Ymd_His') . '.csv"');
        header('Pragma: no-cache');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['Name','Phone','Email','Source','Campaign','Stage','Interested In','Budget','Follow-up Date','Assigned To','Added Date']);
        foreach ($exportLeads as $row) {
            fputcsv($out, [
                $row['name'],
                $row['phone'],
                $row['email'],
                $exportSources[$row['source']] ?? $row['source'],
                $row['campaign'] ?? '',
                $row['stage'],
                $row['interested_in'],
                $row['budget'],
                $row['follow_up_date'],
                $row['assigned_name'],
                $row['created_at'],
            ]);
        }
        fclose($out);
        exit;
    }

    // Unknown bulk action — redirect cleanly
    redirect('leads.php');
}
// ─── END BULK ACTION HANDLER ─────────────────────────────────────────────────

// Inline migrations — silent no-op if columns already exist
try { $db->exec("ALTER TABLE crm_leads ADD COLUMN campaign VARCHAR(150) NULL DEFAULT NULL"); } catch (\Throwable $_) {}
try { $db->exec("ALTER TABLE crm_leads ADD COLUMN last_notified_date DATE NULL DEFAULT NULL"); } catch (\Throwable $_) {}

$filterStage    = $_GET['stage']    ?? '';
$filterSource   = $_GET['source']   ?? '';
$filterCampaign = trim($_GET['campaign'] ?? '');
$filterUser     = $isCrmAgent ? $uid : (int)($_GET['assigned'] ?? 0);
$search         = trim($_GET['q'] ?? '');
$sortBy         = in_array($_GET['sort'] ?? '', ['score_desc','score_asc','updated','overdue']) ? $_GET['sort'] : 'overdue';

$where  = ['1=1'];
$params = [];

// CRM agents are always locked to their own leads
if ($isCrmAgent) {
    $where[] = 'l.assigned_to = ?';
    $params[] = $uid;
}

// Supervisors see only leads assigned to users at their location
if ($isSupervisor) {
    if ($supLocId) {
        $where[] = 'l.assigned_to IN (SELECT id FROM users WHERE location_id = ?)';
        $params[] = $supLocId;
    } else {
        $where[] = '1 = 0'; // no location assigned — show nothing until admin assigns one
    }
}

if ($filterStage)                { $where[] = 'l.stage = ?';       $params[] = $filterStage; }
if ($filterSource)               { $where[] = 'l.source = ?';      $params[] = $filterSource; }
if ($filterCampaign)             { $where[] = 'l.campaign = ?';    $params[] = $filterCampaign; }
if (!$isCrmAgent && $filterUser) { $where[] = 'l.assigned_to = ?'; $params[] = $filterUser; }
if ($search) {
    $where[]  = '(l.name LIKE ? OR l.phone LIKE ? OR l.email LIKE ? OR l.interested_in LIKE ? OR l.campaign LIKE ?)';
    $params   = array_merge($params, ["%$search%","%$search%","%$search%","%$search%","%$search%"]);
}

$whereStr = implode(' AND ', $where);

$orderSQL = match($sortBy) {
    'score_desc' => 'l.lead_score DESC, l.updated_at DESC',
    'score_asc'  => 'l.lead_score ASC,  l.updated_at DESC',
    'updated'    => 'l.updated_at DESC',
    default      => 'CASE WHEN l.follow_up_date < CURDATE() AND l.stage NOT IN (\'lost\',\'delivered\') THEN 0 ELSE 1 END, l.updated_at DESC',
};

try {
    $leads = $db->prepare("
        SELECT l.*, u.name AS assigned_name
        FROM crm_leads l
        LEFT JOIN users u ON u.id = l.assigned_to
        WHERE $whereStr
        ORDER BY $orderSQL
    ");
    $leads->execute($params);
    $leads = $leads->fetchAll();

    // Staff filter: CRM agents see none; supervisors see only their location's users; managers see all
    if ($isCrmAgent) {
        $salesUsers = [];
    } elseif ($isSupervisor && $supLocId) {
        $salesUsers = $db->query("SELECT id, name FROM users WHERE status='active' AND location_id = $supLocId ORDER BY name")->fetchAll();
    } else {
        $salesUsers = $db->query("SELECT id, name FROM users WHERE status='active' ORDER BY name")->fetchAll();
    }

    // Distinct campaigns for the campaign filter dropdown
    $campaignOptions = [];
    try {
        if ($isCrmAgent) {
            $campaignScope = "AND assigned_to = $uid";
        } elseif ($isSupervisor && $supLocId) {
            $campaignScope = "AND assigned_to IN (SELECT id FROM users WHERE location_id = $supLocId)";
        } else {
            $campaignScope = '';
        }
        $campaignOptions = $db->query("
            SELECT DISTINCT campaign FROM crm_leads
            WHERE campaign IS NOT NULL AND campaign <> '' $campaignScope
            ORDER BY campaign ASC
        ")->fetchAll(PDO::FETCH_COLUMN);
    } catch (\Throwable $_) {}
} catch (\Throwable $e) {
    $leads = []; $salesUsers = []; $campaignOptions = [];
}

// ── Stale lead detection ──────────────────────────────────────────────────────
$staleLeadIds = [];
if ($leads) {
    $ids = implode(',', array_map(fn($l) => (int)$l['id'], $leads));
    try {
        $staleRows = $db->query("
            SELECT l.id FROM crm_leads l
            WHERE l.id IN ({$ids})
              AND l.stage NOT IN ('lost','delivered')
              AND l.created_at < DATE_SUB(NOW(), INTERVAL 7 DAY)
              AND NOT EXISTS (
                  SELECT 1 FROM crm_activities a
                  WHERE a.lead_id = l.id
                    AND a.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
              )
        ")->fetchAll(PDO::FETCH_COLUMN);
        $staleLeadIds = array_flip($staleRows);
    } catch (\Throwable $_) {}
}

$stages = [
    'hot'       => ['Hot',       'danger'],
    'lukewarm'  => ['Lukewarm',  'warning'],
    'cold'      => ['Cold',      'info'],
    'lost'      => ['Lost',      'secondary'],
    'reserved'  => ['Reserved',  'purple'],
    'delivered' => ['Delivered', 'success'],
];

// ── Duplicate scan (admin / super_admin only, once per session) ───────────────
$systemDuplicates = [];
if (in_array($me['role'], ['admin','super_admin']) && empty($_GET['skip_dup_scan'])) {
    try {
        // Find leads that share a normalised phone suffix (last 9 digits) — most reliable duplicate signal
        $dupRows = $db->query("
            SELECT a.id AS id_a, a.name AS name_a, a.phone AS phone_a, a.stage AS stage_a,
                   b.id AS id_b, b.name AS name_b, b.phone AS phone_b, b.stage AS stage_b
            FROM crm_leads a
            JOIN crm_leads b ON b.id > a.id
            WHERE a.phone IS NOT NULL AND a.phone <> ''
              AND b.phone IS NOT NULL AND b.phone <> ''
              AND LENGTH(REGEXP_REPLACE(a.phone,'[^0-9]','')) >= 7
              AND RIGHT(REGEXP_REPLACE(a.phone,'[^0-9]',''),9)
                  = RIGHT(REGEXP_REPLACE(b.phone,'[^0-9]',''),9)
            LIMIT 20
        ")->fetchAll();
        $systemDuplicates = $dupRows;
    } catch (\Throwable $_) {
        // REGEXP_REPLACE not available (MySQL < 8) — fall back to exact phone match
        try {
            $dupRows = $db->query("
                SELECT a.id AS id_a, a.name AS name_a, a.phone AS phone_a, a.stage AS stage_a,
                       b.id AS id_b, b.name AS name_b, b.phone AS phone_b, b.stage AS stage_b
                FROM crm_leads a
                JOIN crm_leads b ON b.id > a.id
                WHERE a.phone IS NOT NULL AND a.phone <> ''
                  AND a.phone = b.phone
                LIMIT 20
            ")->fetchAll();
            $systemDuplicates = $dupRows;
        } catch (\Throwable $_) {}
    }

    // Send in-app notifications to admin + super_admin for each duplicate pair (once)
    if ($systemDuplicates) {
        require_once __DIR__ . '/../../includes/notifications.php';
        foreach ($systemDuplicates as $pair) {
            notifyRoles(['admin','super_admin'], 'warning',
                'Duplicate Leads Detected',
                '"' . $pair['name_a'] . '" (#' . $pair['id_a'] . ') and "'
                    . $pair['name_b'] . '" (#' . $pair['id_b'] . ') have the same phone number. Please delete one.',
                BASE_URL . '/modules/crm/leads.php'
            );
        }
    }
}

$sources = [
    'walk_in'    => 'Walk-in',    'referral'  => 'Referral',
    'facebook'   => 'Facebook',   'instagram' => 'Instagram',
    'website'    => 'Website',    'phone_call'=> 'Phone Call',
    'whatsapp'   => 'WhatsApp',   'other'     => 'Other',
];

include __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="mb-0">
        <i class="fa fa-users me-2 text-primary"></i>
        <?= $isCrmAgent ? 'My Leads' : 'All Leads' ?>
        <span class="badge bg-secondary ms-1"><?= count($leads) ?></span>
    </h5>
    <div class="d-flex gap-2">
        <?php if ($isCrmAgent): ?>
        <a href="my_dashboard.php" class="btn btn-sm btn-outline-secondary"><i class="fa fa-gauge-high me-1"></i>Dashboard</a>
        <?php endif; ?>
        <a href="index.php" class="btn btn-sm btn-outline-secondary"><i class="fa fa-columns me-1"></i>Pipeline</a>
        <?php if (!empty($staleLeadIds)): ?>
        <a href="my_tasks.php?tab=stale" class="btn btn-sm btn-outline-warning">
            <i class="fa fa-hourglass-half me-1"></i>Going Cold <span class="badge bg-warning text-dark"><?= count($staleLeadIds) ?></span>
        </a>
        <?php endif; ?>
        <?php if (canWrite('crm')): ?>
        <a href="import_leads.php" class="btn btn-sm btn-outline-success"><i class="fa fa-file-import me-1"></i>Import</a>
        <a href="add_lead.php" class="btn btn-sm btn-primary"><i class="fa fa-plus me-1"></i>New Lead</a>
        <?php endif; ?>
    </div>
</div>

<?php if (!empty($systemDuplicates)): ?>
<!-- ── Duplicate leads alert (admin / super_admin) ─────────────────────── -->
<div class="alert alert-warning border-warning mb-3 shadow-sm" id="dupSystemAlert">
    <div class="d-flex align-items-start gap-2">
        <i class="fa fa-triangle-exclamation fa-lg mt-1 text-warning flex-shrink-0"></i>
        <div class="flex-grow-1">
            <strong><?= count($systemDuplicates) ?> duplicate lead pair<?= count($systemDuplicates) > 1 ? 's' : '' ?> found in the system.</strong>
            <p class="mb-2 mt-1" style="font-size:13px">
                The following leads share the same phone number. Please review and delete the unwanted entry.
            </p>
            <div class="table-responsive">
                <table class="table table-sm table-bordered mb-0" style="font-size:12.5px;background:#fff">
                    <thead class="table-light">
                        <tr><th>#</th><th>Lead A</th><th>Phone</th><th>Stage</th><th></th><th>Lead B</th><th>Phone</th><th>Stage</th><th></th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($systemDuplicates as $i => $pair): ?>
                    <tr>
                        <td class="text-muted"><?= $i + 1 ?></td>
                        <td class="fw-semibold"><?= e($pair['name_a']) ?> <small class="text-muted">#<?= $pair['id_a'] ?></small></td>
                        <td><?= e($pair['phone_a']) ?></td>
                        <td><span class="badge bg-secondary"><?= ucfirst($pair['stage_a']) ?></span></td>
                        <td><a href="view_lead.php?id=<?= $pair['id_a'] ?>" class="btn btn-xs btn-outline-primary" target="_blank">View</a></td>
                        <td class="fw-semibold"><?= e($pair['name_b']) ?> <small class="text-muted">#<?= $pair['id_b'] ?></small></td>
                        <td><?= e($pair['phone_b']) ?></td>
                        <td><span class="badge bg-secondary"><?= ucfirst($pair['stage_b']) ?></span></td>
                        <td><a href="view_lead.php?id=<?= $pair['id_b'] ?>" class="btn btn-xs btn-outline-primary" target="_blank">View</a></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <button type="button" class="btn-close" onclick="document.getElementById('dupSystemAlert').remove()"></button>
    </div>
</div>
<?php endif; ?>

<!-- Filters -->
<div class="card mb-3">
    <div class="card-body py-2">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-sm-3">
                <input type="text" name="q" class="form-control form-control-sm"
                       placeholder="Search name, phone, car…" value="<?= e($search) ?>">
            </div>
            <div class="col-sm-2">
                <select name="stage" class="form-select form-select-sm">
                    <option value="">All Stages</option>
                    <?php foreach ($stages as $k => [$lbl, $c]): ?>
                    <option value="<?= $k ?>" <?= $filterStage === $k ? 'selected' : '' ?>><?= $lbl ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-sm-2">
                <select name="source" class="form-select form-select-sm">
                    <option value="">All Sources</option>
                    <?php foreach ($sources as $k => $lbl): ?>
                    <option value="<?= $k ?>" <?= $filterSource === $k ? 'selected' : '' ?>><?= $lbl ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php if ($campaignOptions): ?>
            <div class="col-sm-2">
                <select name="campaign" class="form-select form-select-sm">
                    <option value="">All Campaigns</option>
                    <?php foreach ($campaignOptions as $c): ?>
                    <option value="<?= e($c) ?>" <?= $filterCampaign === $c ? 'selected' : '' ?>><?= e($c) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
            <?php if (!$isCrmAgent): ?>
            <div class="col-sm-2">
                <select name="assigned" class="form-select form-select-sm">
                    <option value="">All Staff</option>
                    <?php foreach ($salesUsers as $u): ?>
                    <option value="<?= $u['id'] ?>" <?= $filterUser === (int)$u['id'] ? 'selected' : '' ?>><?= e($u['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
            <div class="col-sm-2">
                <select name="sort" class="form-select form-select-sm">
                    <option value="overdue"    <?= $sortBy === 'overdue'    ? 'selected' : '' ?>>Overdue First</option>
                    <option value="score_desc" <?= $sortBy === 'score_desc' ? 'selected' : '' ?>>Score: High → Low</option>
                    <option value="score_asc"  <?= $sortBy === 'score_asc'  ? 'selected' : '' ?>>Score: Low → High</option>
                    <option value="updated"    <?= $sortBy === 'updated'    ? 'selected' : '' ?>>Recently Updated</option>
                </select>
            </div>
            <div class="col-auto d-flex gap-2">
                <button class="btn btn-sm btn-primary"><i class="fa fa-filter me-1"></i>Filter</button>
                <a href="leads.php" class="btn btn-sm btn-outline-secondary">Clear</a>
            </div>
        </form>
    </div>
</div>

<!-- Bulk Action Bar (hidden until at least one lead is checked) -->
<div id="bulkBar" style="display:none" class="card mb-2 border-primary">
    <div class="card-body py-2 d-flex align-items-center gap-3 flex-wrap">
        <span id="bulkCount" class="fw-semibold text-primary"></span>
        <form method="POST" id="bulkForm">
            <input type="hidden" name="bulk_action" id="bulkActionInput">
            <!-- hidden lead_ids[] inputs are appended by JS at submit time -->
            <div class="d-flex gap-2 flex-wrap align-items-center">
                <!-- Reassign (managers only) -->
                <?php if (!$isCrmAgent): ?>
                <select name="reassign_to" id="reassignTo" class="form-select form-select-sm" style="width:150px">
                    <option value="">— Assign to —</option>
                    <?php foreach ($salesUsers as $u): ?>
                    <option value="<?= $u['id'] ?>"><?= e($u['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="button" class="btn btn-sm btn-outline-primary" onclick="submitBulk('bulk_reassign')">
                    <i class="fa fa-user-tag me-1"></i>Reassign
                </button>
                <?php endif; ?>
                <!-- Set Stage -->
                <select name="bulk_stage" id="bulkStage" class="form-select form-select-sm" style="width:140px">
                    <option value="">— Set Stage —</option>
                    <?php foreach ($stages as $k => [$lbl, $c]): ?>
                    <option value="<?= $k ?>"><?= $lbl ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="button" class="btn btn-sm btn-outline-warning" onclick="submitBulk('bulk_stage')">
                    <i class="fa fa-tags me-1"></i>Set Stage
                </button>
                <!-- Set Follow-up -->
                <input type="date" name="bulk_followup_date" id="bulkFollowup"
                       class="form-control form-control-sm" style="width:150px"
                       min="<?= date('Y-m-d') ?>">
                <button type="button" class="btn btn-sm btn-outline-info" onclick="submitBulk('bulk_followup')">
                    <i class="fa fa-calendar me-1"></i>Set Follow-up
                </button>
                <!-- Export CSV -->
                <button type="button" class="btn btn-sm btn-outline-success" onclick="submitBulk('bulk_export')">
                    <i class="fa fa-file-csv me-1"></i>Export CSV
                </button>
                <!-- Clear selection -->
                <button type="button" class="btn btn-sm btn-outline-secondary" onclick="clearBulk()">Clear</button>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-body p-0">
        <table class="table table-hover datatable mb-0" style="font-size:13.5px">
            <thead>
                <tr>
                    <th style="width:36px" class="ps-3">
                        <input type="checkbox" id="selectAll">
                    </th>
                    <th>Name</th>
                    <th>Contact</th>
                    <th>Interested In</th>
                    <th>Budget</th>
                    <th>Source / Campaign</th>
                    <th>Stage</th>
                    <th>Score</th>
                    <th>Follow-up</th>
                    <?php if (!$isCrmAgent): ?><th>Assigned</th><?php endif; ?>
                    <th>Added</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($leads as $l):
                [$stageLabel, $stageColor] = $stages[$l['stage']] ?? ['Unknown','secondary'];
                $isOverdue = $l['follow_up_date'] && $l['follow_up_date'] < date('Y-m-d')
                             && !in_array($l['stage'], ['lost','delivered']);

                // ── Lead Score — use stored DB value (updated when lead is viewed) ──
                $score = (int)($l['lead_score'] ?? 0);
                $scoreColor = $score >= 70 ? 'success' : ($score >= 50 ? 'primary' : ($score >= 35 ? 'warning' : ($score >= 20 ? 'info' : 'secondary')));

                // ── WhatsApp link ─────────────────────────────────────────
                $waNum = '';
                $waMsg = '';
                if (!empty($l['phone'])) {
                    $waNum = preg_replace('/[^0-9]/', '', $l['phone']);
                    if (str_starts_with($l['phone'], '0')) {
                        $waNum = '254' . substr($waNum, 1);
                    }
                    $waMsg = rawurlencode(
                        "Hello {$l['name']}! Following up on your interest"
                        . ($l['interested_in'] ? " in the {$l['interested_in']}" : '')
                        . ". When is a good time to connect?"
                    );
                }
            ?>
            <tr class="<?= $isOverdue ? 'table-danger' : '' ?>">
                <td class="ps-3">
                    <input type="checkbox" class="lead-check" value="<?= $l['id'] ?>">
                </td>
                <td>
                    <a href="view_lead.php?id=<?= $l['id'] ?>" class="fw-semibold text-decoration-none">
                        <?= e($l['name']) ?>
                    </a>
                    <?php if ($l['client_id']): ?>
                    <span class="badge bg-success-subtle text-success border border-success-subtle ms-1" style="font-size:10px">Client</span>
                    <?php endif; ?>
                    <?php if (isset($staleLeadIds[$l['id']])): ?>
                    <span class="badge ms-1" style="background:#f59e0b;color:#fff;font-size:9px;padding:2px 5px" title="No activity in 7+ days">
                        <i class="fa fa-hourglass-half"></i> Cold
                    </span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ($l['phone']): ?>
                    <div class="small"><i class="fa fa-phone me-1 text-muted"></i><?= e($l['phone']) ?></div>
                    <a href="https://wa.me/<?= $waNum ?>?text=<?= $waMsg ?>" target="_blank"
                       class="btn btn-xs btn-success mt-1" style="font-size:10px;padding:1px 6px"
                       title="WhatsApp <?= e($l['phone']) ?>">
                        <i class="fab fa-whatsapp"></i>
                    </a>
                    <?php endif; ?>
                    <?php if ($l['email']): ?>
                    <div class="small text-muted"><?= e($l['email']) ?></div>
                    <?php endif; ?>
                </td>
                <td class="small text-muted" style="max-width:160px">
                    <div class="text-truncate"><?= e($l['interested_in'] ?: '—') ?></div>
                </td>
                <td class="small"><?= $l['budget'] ? money((float)$l['budget']) : '—' ?></td>
                <td>
                    <span class="badge bg-light text-dark border" style="font-size:11px"><?= e($sources[$l['source']] ?? $l['source']) ?></span>
                    <?php if (!empty($l['campaign'])): ?>
                    <div class="mt-1"><span class="badge bg-info-subtle text-info border border-info-subtle" style="font-size:10px"><i class="fa fa-bullhorn me-1"></i><?= e($l['campaign']) ?></span></div>
                    <?php endif; ?>
                </td>
                <td><span class="badge bg-<?= $stageColor ?>" style="font-size:11px"><?= $stageLabel ?></span></td>
                <td>
                    <div style="display:flex;align-items:center;gap:4px;min-width:60px">
                        <div class="progress flex-grow-1" style="height:6px">
                            <div class="progress-bar bg-<?= $scoreColor ?>" style="width:<?= round($score / 60 * 100) ?>%"></div>
                        </div>
                        <small class="text-muted"><?= $score ?></small>
                    </div>
                </td>
                <td>
                    <?php if ($l['follow_up_date']): ?>
                    <span class="badge <?= $isOverdue ? 'bg-danger' : ($l['follow_up_date'] === date('Y-m-d') ? 'bg-warning text-dark' : 'bg-light text-dark border') ?>" style="font-size:11px">
                        <?= fmtDate($l['follow_up_date'], 'd M Y') ?>
                    </span>
                    <?php else: ?>
                    <span class="text-muted">—</span>
                    <?php endif; ?>
                </td>
                <?php if (!$isCrmAgent): ?><td class="small text-muted"><?= e($l['assigned_name'] ?? '—') ?></td><?php endif; ?>
                <td class="small text-muted"><?= fmtDate($l['created_at'], 'd M') ?></td>
                <td class="pe-3">
                    <div class="d-flex gap-1">
                        <a href="view_lead.php?id=<?= $l['id'] ?>" class="btn btn-xs btn-outline-primary">View</a>
                        <?php if ($me['role'] === 'super_admin'): ?>
                        <form method="POST" class="delete-lead-form" data-name="<?= e($l['name']) ?>">
                            <input type="hidden" name="action"  value="delete_lead">
                            <input type="hidden" name="lead_id" value="<?= $l['id'] ?>">
                            <button type="submit" class="btn btn-xs btn-outline-danger" title="Delete lead">
                                <i class="fa fa-trash"></i>
                            </button>
                        </form>
                        <?php endif; ?>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
(function () {
    var checks    = document.querySelectorAll('.lead-check');
    var bar       = document.getElementById('bulkBar');
    var countEl   = document.getElementById('bulkCount');
    var selectAll = document.getElementById('selectAll');

    function updateBar() {
        var checked = document.querySelectorAll('.lead-check:checked');
        if (checked.length > 0) {
            bar.style.display = '';
            countEl.textContent = checked.length + ' lead' + (checked.length > 1 ? 's' : '') + ' selected';
        } else {
            bar.style.display = 'none';
        }
    }

    checks.forEach(function (c) { c.addEventListener('change', updateBar); });

    if (selectAll) {
        selectAll.addEventListener('change', function () {
            checks.forEach(function (c) { c.checked = selectAll.checked; });
            updateBar();
        });
    }

    window.submitBulk = function (action) {
        var checked = document.querySelectorAll('.lead-check:checked');
        if (!checked.length) return;
        var form = document.getElementById('bulkForm');
        document.getElementById('bulkActionInput').value = action;
        // Remove previously appended hidden inputs
        form.querySelectorAll('.bulk-id').forEach(function (el) { el.remove(); });
        checked.forEach(function (c) {
            var inp       = document.createElement('input');
            inp.type      = 'hidden';
            inp.name      = 'lead_ids[]';
            inp.value     = c.value;
            inp.className = 'bulk-id';
            form.appendChild(inp);
        });
        form.method = 'POST';
        form.submit();
    };

    window.clearBulk = function () {
        checks.forEach(function (c) { c.checked = false; });
        if (selectAll) selectAll.checked = false;
        updateBar();
    };
}());

// Delete confirmation
document.querySelectorAll('.delete-lead-form').forEach(function (form) {
    form.addEventListener('submit', function (e) {
        e.preventDefault();
        var name = form.dataset.name || 'this lead';
        if (confirm('Permanently delete "' + name + '" and all their activities?\n\nThis cannot be undone.')) {
            form.submit();
        }
    });
});
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>

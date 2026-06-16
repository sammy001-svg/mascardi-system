<?php
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
canAccess('crm') || redirect(BASE_URL . '/index.php');

// Managers and admins only — CRM agents go to their own dashboard
if (authRole() === 'customer_relations') {
    redirect(BASE_URL . '/modules/crm/my_dashboard.php');
}

$db        = getDB();
$me        = authUser();
$pageTitle = 'Monthly Targets';

// ── Auto-migration ────────────────────────────────────────────────────────────
try {
    $db->exec("CREATE TABLE IF NOT EXISTS crm_targets (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        month VARCHAR(7) NOT NULL COMMENT 'YYYY-MM format',
        target_deliveries INT DEFAULT 0,
        target_activities INT DEFAULT 0,
        target_calls INT DEFAULT 0,
        target_new_leads INT DEFAULT 0,
        set_by INT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY unique_user_month (user_id, month)
    )");
} catch (\Throwable $_) {}

// ── Month selection ───────────────────────────────────────────────────────────
$selectedMonth = $_GET['month'] ?? date('Y-m');
// Validate YYYY-MM format
if (!preg_match('/^\d{4}-\d{2}$/', $selectedMonth)) {
    $selectedMonth = date('Y-m');
}
$monthLabel = date('F Y', strtotime($selectedMonth . '-01'));
$prevMonth  = date('Y-m', strtotime($selectedMonth . '-01 -1 month'));
$nextMonth  = date('Y-m', strtotime($selectedMonth . '-01 +1 month'));

// ── POST: save targets ────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_targets') {
    $targets = $_POST['targets'] ?? [];
    $setBy   = (int)$me['id'];
    $month   = $selectedMonth;

    if (is_array($targets)) {
        $stmt = $db->prepare("
            INSERT INTO crm_targets (user_id, month, target_deliveries, target_activities, target_calls, target_new_leads, set_by)
            VALUES (?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                target_deliveries = VALUES(target_deliveries),
                target_activities = VALUES(target_activities),
                target_calls      = VALUES(target_calls),
                target_new_leads  = VALUES(target_new_leads),
                set_by            = VALUES(set_by),
                updated_at        = CURRENT_TIMESTAMP
        ");
        foreach ($targets as $userId => $vals) {
            $userId = (int)$userId;
            if ($userId <= 0) continue;
            $stmt->execute([
                $userId,
                $month,
                max(0, (int)($vals['target_deliveries'] ?? 0)),
                max(0, (int)($vals['target_activities'] ?? 0)),
                max(0, (int)($vals['target_calls']      ?? 0)),
                max(0, (int)($vals['target_new_leads']  ?? 0)),
                $setBy,
            ]);
        }
    }

    logActivity('update', 'crm_targets', null, "Targets set for {$month}");
    setFlash('success', "Targets saved for {$monthLabel}.");
    redirect(BASE_URL . '/modules/crm/targets.php?month=' . urlencode($month));
}

// ── Load active CRM agents ────────────────────────────────────────────────────
$agents = $db->query(
    "SELECT id, name FROM users WHERE role='customer_relations' AND status='active' ORDER BY name"
)->fetchAll();

// ── Load existing targets for this month ─────────────────────────────────────
$targetStmt = $db->prepare("SELECT * FROM crm_targets WHERE month = ?");
$targetStmt->execute([$selectedMonth]);
$targetMap = [];
foreach ($targetStmt->fetchAll() as $row) {
    $targetMap[(int)$row['user_id']] = $row;
}

// ── Load actuals for each agent ───────────────────────────────────────────────
$actualMap = [];
foreach ($agents as $agent) {
    $uid = (int)$agent['id'];

    // Deliveries: leads converted to 'delivered' this month
    $stDeliveries = $db->prepare(
        "SELECT COUNT(*) FROM crm_leads
         WHERE assigned_to = ? AND stage = 'delivered'
           AND DATE_FORMAT(converted_at,'%Y-%m') = ?"
    );
    $stDeliveries->execute([$uid, $selectedMonth]);
    $actualDeliveries = (int)$stDeliveries->fetchColumn();

    // All activities logged this month by agent
    $stActivities = $db->prepare(
        "SELECT COUNT(*) FROM crm_activities
         WHERE created_by = ? AND DATE_FORMAT(created_at,'%Y-%m') = ?"
    );
    $stActivities->execute([$uid, $selectedMonth]);
    $actualActivities = (int)$stActivities->fetchColumn();

    // Calls/WhatsApp activities this month
    $stCalls = $db->prepare(
        "SELECT COUNT(*) FROM crm_activities
         WHERE created_by = ? AND type IN ('call','whatsapp')
           AND DATE_FORMAT(created_at,'%Y-%m') = ?"
    );
    $stCalls->execute([$uid, $selectedMonth]);
    $actualCalls = (int)$stCalls->fetchColumn();

    // New leads assigned this month
    $stLeads = $db->prepare(
        "SELECT COUNT(*) FROM crm_leads
         WHERE assigned_to = ? AND DATE_FORMAT(created_at,'%Y-%m') = ?"
    );
    $stLeads->execute([$uid, $selectedMonth]);
    $actualNewLeads = (int)$stLeads->fetchColumn();

    $actualMap[$uid] = [
        'deliveries' => $actualDeliveries,
        'activities' => $actualActivities,
        'calls'      => $actualCalls,
        'new_leads'  => $actualNewLeads,
    ];
}

// ── Helper functions ──────────────────────────────────────────────────────────
function progressColor(int $actual, int $target): string {
    if ($target <= 0) return 'secondary';
    $pct = ($actual / $target) * 100;
    if ($pct >= 100) return 'success';
    if ($pct >= 70)  return 'warning';
    return 'danger';
}

function progressPct(int $actual, int $target): int {
    if ($target <= 0) return 0;
    return min(100, (int)round(($actual / $target) * 100));
}

include __DIR__ . '/../../includes/header.php';
?>

<style>
.agent-avatar {
    width: 42px; height: 42px;
    border-radius: 12px;
    background: linear-gradient(135deg, #2563eb, #1d4ed8);
    color: #fff;
    display: flex; align-items: center; justify-content: center;
    font-size: 16px; font-weight: 800;
    flex-shrink: 0;
}
.metric-label {
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: .04em;
    color: var(--text-3, #64748b);
    margin-bottom: 4px;
}
.metric-input {
    text-align: center;
    font-weight: 700;
    font-size: 15px;
}
.progress-text {
    font-size: 11px;
    font-weight: 600;
    color: var(--text-3, #64748b);
    margin-top: 3px;
    text-align: center;
}
.agent-card {
    border: 1px solid var(--border, #e2e8f0);
    border-radius: 14px;
    overflow: hidden;
    margin-bottom: 16px;
    background: var(--surface, #fff);
    box-shadow: 0 1px 4px rgba(0,0,0,.05);
}
.agent-card-header {
    background: linear-gradient(to right, #f8fafc, #eff6ff);
    border-bottom: 1px solid var(--border, #e2e8f0);
    padding: 14px 20px;
    display: flex; align-items: center; gap: 12px;
}
.agent-card-body { padding: 20px; }
.month-nav-btn {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 7px 14px;
    background: var(--surface, #fff);
    border: 1px solid var(--border, #e2e8f0);
    border-radius: 8px;
    font-size: 13px; font-weight: 600;
    color: var(--text, #0f172a);
    text-decoration: none;
    transition: background .15s, border-color .15s;
}
.month-nav-btn:hover {
    background: #eff6ff;
    border-color: #bfdbfe;
    color: #1d4ed8;
    text-decoration: none;
}
</style>

<!-- Page header + month navigation -->
<div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
    <div>
        <h4 class="mb-1 fw-bold">
            <i class="fa fa-bullseye me-2 text-primary"></i>Monthly Targets
        </h4>
        <div class="text-muted" style="font-size:13px">
            Set and track performance targets for CRM agents
        </div>
    </div>
    <div class="d-flex align-items-center gap-2 flex-wrap">
        <a href="?month=<?= urlencode($prevMonth) ?>" class="month-nav-btn">
            <i class="fa fa-chevron-left"></i> Prev
        </a>
        <span class="fw-bold px-2" style="font-size:15px"><?= e($monthLabel) ?></span>
        <a href="?month=<?= urlencode($nextMonth) ?>" class="month-nav-btn">
            Next <i class="fa fa-chevron-right"></i>
        </a>
    </div>
</div>

<!-- Info callout -->
<div class="d-flex align-items-start gap-3 mb-4 px-4 py-3"
     style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:12px;color:#1e40af">
    <i class="fa fa-circle-info fa-lg mt-1" style="flex-shrink:0"></i>
    <p class="mb-0" style="font-size:13.5px;line-height:1.5">
        Set monthly targets for each CRM agent. Agents see their own progress on their dashboard.
        Progress bars are calculated from live activity data for <strong><?= e($monthLabel) ?></strong>.
    </p>
</div>

<?php if (empty($agents)): ?>
<!-- Empty state -->
<div class="text-center py-5" style="background:var(--surface,#fff);border:1px dashed #cbd5e1;border-radius:14px">
    <i class="fa fa-users fa-3x text-muted mb-3 d-block opacity-25"></i>
    <h5 class="fw-semibold text-muted">No Active CRM Agents</h5>
    <p class="text-muted mb-0" style="font-size:13.5px">
        There are no active users with the <code>customer_relations</code> role.
        Add CRM agents in User Management to set targets here.
    </p>
</div>

<?php else: ?>

<form method="POST" action="?month=<?= urlencode($selectedMonth) ?>">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="save_targets">

    <!-- Save button (top) -->
    <div class="d-flex justify-content-end mb-3">
        <button type="submit" class="btn btn-primary fw-semibold px-4">
            <i class="fa fa-floppy-disk me-2"></i>Save Targets for <?= e($monthLabel) ?>
        </button>
    </div>

    <?php
    $metrics = [
        ['key' => 'deliveries', 'field' => 'target_deliveries', 'label' => 'Deliveries', 'icon' => 'fa-truck',       'color' => '#16a34a'],
        ['key' => 'activities', 'field' => 'target_activities', 'label' => 'Activities',  'icon' => 'fa-clipboard',   'color' => '#2563eb'],
        ['key' => 'calls',      'field' => 'target_calls',      'label' => 'Calls',       'icon' => 'fa-phone',       'color' => '#0891b2'],
        ['key' => 'new_leads',  'field' => 'target_new_leads',  'label' => 'New Leads',   'icon' => 'fa-user-plus',   'color' => '#9333ea'],
    ];

    foreach ($agents as $agent):
        $uid      = (int)$agent['id'];
        $saved    = $targetMap[$uid] ?? [];
        $actuals  = $actualMap[$uid] ?? ['deliveries' => 0, 'activities' => 0, 'calls' => 0, 'new_leads' => 0];
        $words    = array_filter(explode(' ', trim($agent['name'])));
        $initials = implode('', array_map(fn($w) => strtoupper($w[0]), array_slice($words, 0, 2)));
    ?>
    <div class="agent-card">
        <div class="agent-card-header">
            <div class="agent-avatar"><?= e($initials) ?></div>
            <div>
                <div class="fw-bold" style="font-size:15px"><?= e($agent['name']) ?></div>
                <div style="font-size:12px;color:#64748b">Customer Relations Agent</div>
            </div>
            <?php if (!empty($saved)): ?>
            <div class="ms-auto">
                <span class="badge" style="background:#dcfce7;color:#166534;font-size:11px;padding:5px 10px">
                    <i class="fa fa-check me-1"></i>Targets set
                </span>
            </div>
            <?php endif; ?>
        </div>
        <div class="agent-card-body">
            <div class="row g-4">
                <?php foreach ($metrics as $m):
                    $target = (int)($saved[$m['field']] ?? 0);
                    $actual = (int)($actuals[$m['key']] ?? 0);
                    $pct    = progressPct($actual, $target);
                    $color  = progressColor($actual, $target);
                ?>
                <div class="col-6 col-lg-3">
                    <div class="metric-label">
                        <i class="fa <?= $m['icon'] ?> me-1" style="color:<?= $m['color'] ?>"></i>
                        <?= $m['label'] ?>
                    </div>
                    <div class="input-group input-group-sm mb-2">
                        <span class="input-group-text" style="font-size:11px;font-weight:600">Target</span>
                        <input
                            type="number"
                            class="form-control metric-input"
                            name="targets[<?= $uid ?>][<?= $m['field'] ?>]"
                            value="<?= $target ?>"
                            min="0"
                            placeholder="0"
                        >
                    </div>
                    <?php if ($target > 0): ?>
                    <div class="progress mb-1" style="height:7px;border-radius:4px">
                        <div class="progress-bar bg-<?= $color ?>"
                             role="progressbar"
                             style="width:<?= $pct ?>%"
                             aria-valuenow="<?= $pct ?>"
                             aria-valuemin="0"
                             aria-valuemax="100">
                        </div>
                    </div>
                    <div class="progress-text">
                        <?= $actual ?> / <?= $target ?>
                        <?php if ($pct >= 100): ?>
                        <span class="text-success ms-1"><i class="fa fa-check-circle"></i></span>
                        <?php endif; ?>
                    </div>
                    <?php else: ?>
                    <div class="progress mb-1" style="height:7px;border-radius:4px">
                        <div class="progress-bar bg-secondary" role="progressbar" style="width:0%"></div>
                    </div>
                    <div class="progress-text text-muted">Actual: <?= $actual ?></div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <?php endforeach; ?>

    <!-- Save button (bottom, full-width) -->
    <div class="d-grid mt-1 mb-4">
        <button type="submit" class="btn btn-primary btn-lg fw-semibold">
            <i class="fa fa-floppy-disk me-2"></i>Save All Targets for <?= e($monthLabel) ?>
        </button>
    </div>
</form>

<?php endif; ?>

<?php include __DIR__ . '/../../includes/footer.php'; ?>

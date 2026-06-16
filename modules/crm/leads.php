<?php
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
canAccess('crm') || redirect(BASE_URL . '/index.php');

$db  = getDB();
$me  = authUser();
$uid = (int)$me['id'];

// Data isolation: CRM agents see only their own leads
$isCrmAgent = ($me['role'] === 'customer_relations');
$pageTitle  = $isCrmAgent ? 'My Leads' : 'All Leads';

$filterStage  = $_GET['stage']  ?? '';
$filterSource = $_GET['source'] ?? '';
$filterUser   = $isCrmAgent ? $uid : (int)($_GET['assigned'] ?? 0);
$search       = trim($_GET['q'] ?? '');

$where  = ['1=1'];
$params = [];

// CRM agents are always locked to their own leads
if ($isCrmAgent) {
    $where[] = 'l.assigned_to = ?';
    $params[] = $uid;
}

if ($filterStage)            { $where[] = 'l.stage = ?';       $params[] = $filterStage; }
if ($filterSource)           { $where[] = 'l.source = ?';      $params[] = $filterSource; }
if (!$isCrmAgent && $filterUser) { $where[] = 'l.assigned_to = ?'; $params[] = $filterUser; }
if ($search) {
    $where[]  = '(l.name LIKE ? OR l.phone LIKE ? OR l.email LIKE ? OR l.interested_in LIKE ?)';
    $params   = array_merge($params, ["%$search%","%$search%","%$search%","%$search%"]);
}

$whereStr = implode(' AND ', $where);

try {
    $leads = $db->prepare("
        SELECT l.*, u.name AS assigned_name
        FROM crm_leads l
        LEFT JOIN users u ON u.id = l.assigned_to
        WHERE $whereStr
        ORDER BY
            CASE WHEN l.follow_up_date < CURDATE() AND l.stage NOT IN ('lost','delivered') THEN 0 ELSE 1 END,
            l.updated_at DESC
    ");
    $leads->execute($params);
    $leads = $leads->fetchAll();

    // Managers see all staff in filter; CRM agents don't get the staff filter
    $salesUsers = $isCrmAgent ? [] : $db->query("SELECT id, name FROM users WHERE status='active' ORDER BY name")->fetchAll();
} catch (\Throwable $e) {
    $leads = []; $salesUsers = [];
}

$stages = [
    'hot'       => ['Hot',       'danger'],
    'lukewarm'  => ['Lukewarm',  'warning'],
    'cold'      => ['Cold',      'info'],
    'lost'      => ['Lost',      'secondary'],
    'reserved'  => ['Reserved',  'purple'],
    'delivered' => ['Delivered', 'success'],
];

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
        <?php if (canWrite('crm')): ?>
        <a href="add_lead.php" class="btn btn-sm btn-primary"><i class="fa fa-plus me-1"></i>New Lead</a>
        <?php endif; ?>
    </div>
</div>

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
            <div class="col-auto d-flex gap-2">
                <button class="btn btn-sm btn-primary"><i class="fa fa-filter me-1"></i>Filter</button>
                <a href="leads.php" class="btn btn-sm btn-outline-secondary">Clear</a>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-body p-0">
        <table class="table table-hover datatable mb-0" style="font-size:13.5px">
            <thead>
                <tr>
                    <th class="ps-3">Name</th>
                    <th>Contact</th>
                    <th>Interested In</th>
                    <th>Budget</th>
                    <th>Source</th>
                    <th>Stage</th>
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
            ?>
            <tr class="<?= $isOverdue ? 'table-danger' : '' ?>">
                <td class="ps-3">
                    <a href="view_lead.php?id=<?= $l['id'] ?>" class="fw-semibold text-decoration-none">
                        <?= e($l['name']) ?>
                    </a>
                    <?php if ($l['client_id']): ?>
                    <span class="badge bg-success-subtle text-success border border-success-subtle ms-1" style="font-size:10px">Client</span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ($l['phone']): ?>
                    <div class="small"><i class="fa fa-phone me-1 text-muted"></i><?= e($l['phone']) ?></div>
                    <?php endif; ?>
                    <?php if ($l['email']): ?>
                    <div class="small text-muted"><?= e($l['email']) ?></div>
                    <?php endif; ?>
                </td>
                <td class="small text-muted" style="max-width:160px">
                    <div class="text-truncate"><?= e($l['interested_in'] ?: '—') ?></div>
                </td>
                <td class="small"><?= $l['budget'] ? money((float)$l['budget']) : '—' ?></td>
                <td><span class="badge bg-light text-dark border" style="font-size:11px"><?= e($sources[$l['source']] ?? $l['source']) ?></span></td>
                <td><span class="badge bg-<?= $stageColor ?>" style="font-size:11px"><?= $stageLabel ?></span></td>
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
                    <a href="view_lead.php?id=<?= $l['id'] ?>" class="btn btn-xs btn-outline-primary">View</a>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>

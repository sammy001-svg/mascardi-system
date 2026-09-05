<?php
/**
 * Import Orders — customer vehicles being brought in to order.
 *
 * The counterpart to Delivered Cars, and built the same way: a lead moved to the
 * 'import_order' stage on its own page appears here, so the orders can be seen
 * together instead of one lead at a time.
 *
 * Deliberately NOT the same thing as modules/imports, which tracks stock we buy
 * at auction and ship on our own account. These are customer orders — somebody
 * has paid a deposit against a specific vehicle we do not yet have — and the
 * question they raise is different: what did we promise, when did we promise it,
 * and is that date now behind us.
 */
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
canAccess('crm') || redirect(BASE_URL . '/index.php');

$db  = getDB();
$me  = authUser();
$uid = (int)$me['id'];
$isCrmAgent = ($me['role'] === 'customer_relations');
$canFilter  = in_array($me['role'], ['admin', 'super_admin', 'general_manager'], true);

// Same inline migrations the lead page and receipts rely on, so this page works
// even when it is the first one opened after a deploy.
foreach ([
    "ALTER TABLE crm_leads ADD COLUMN import_vehicle_details TEXT          NULL DEFAULT NULL",
    "ALTER TABLE crm_leads ADD COLUMN expected_arrival_date  DATE          NULL DEFAULT NULL",
    "ALTER TABLE crm_leads ADD COLUMN agreed_sale_price      DECIMAL(15,2) NULL DEFAULT NULL",
    "ALTER TABLE crm_leads ADD COLUMN deposit_amount         DECIMAL(15,2) NULL DEFAULT NULL",
] as $_sql) { try { $db->exec($_sql); } catch (\Throwable $_) {} }

// ── Filters ──────────────────────────────────────────────────────────────────
$filterAgent  = $canFilter ? (int)($_GET['agent'] ?? 0) : 0;
$filterSearch = trim($_GET['q'] ?? '');
$filterMonth  = trim($_GET['month'] ?? '');          // YYYY-MM, on expected arrival
$filterWhen   = trim($_GET['when'] ?? '');           // overdue | soon | unscheduled

$where  = ["l.stage = 'import_order'"];
$params = [];

// A CR agent sees their own orders, as they do on Delivered Cars.
if ($isCrmAgent)  { $where[] = "l.assigned_to = $uid"; }
if ($filterAgent) { $where[] = 'l.assigned_to = ?'; $params[] = $filterAgent; }
if ($filterMonth) { $where[] = "DATE_FORMAT(l.expected_arrival_date, '%Y-%m') = ?"; $params[] = $filterMonth; }

// Dates compared in SQL throughout: PHP runs UTC on this host and MySQL runs
// EAT, so "overdue" worked out in PHP is three hours adrift.
if ($filterWhen === 'overdue') {
    $where[] = "l.expected_arrival_date IS NOT NULL AND l.expected_arrival_date < CURDATE()";
} elseif ($filterWhen === 'soon') {
    $where[] = "l.expected_arrival_date IS NOT NULL
                AND l.expected_arrival_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)";
} elseif ($filterWhen === 'unscheduled') {
    $where[] = "l.expected_arrival_date IS NULL";
}

if ($filterSearch) {
    $s = '%' . $filterSearch . '%';
    $where[] = '(l.name LIKE ? OR l.phone LIKE ? OR cl.name LIKE ? OR l.import_vehicle_details LIKE ?)';
    array_push($params, $s, $s, $s, $s);
}
$whereSQL = implode(' AND ', $where);

// ── The orders ───────────────────────────────────────────────────────────────
$stmt = $db->prepare("
    SELECT
        l.id                     AS lead_id,
        l.name                   AS lead_name,
        l.phone                  AS lead_phone,
        l.email                  AS lead_email,
        l.import_vehicle_details,
        l.expected_arrival_date,
        l.deposit_amount,
        l.deposit_date,
        l.agreed_sale_price,
        l.due_date,
        l.updated_at,
        DATEDIFF(l.expected_arrival_date, CURDATE()) AS days_away,
        cl.name  AS client_name,
        cl.phone AS client_phone,
        u.id     AS agent_id,
        u.name   AS agent_name
      FROM crm_leads l
 LEFT JOIN clients cl ON cl.id = l.client_id
 LEFT JOIN users   u  ON u.id  = l.assigned_to
     WHERE $whereSQL
  ORDER BY l.expected_arrival_date IS NULL, l.expected_arrival_date ASC, l.updated_at DESC
");
$stmt->execute($params);
$orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ── Figures, unfiltered so they describe the whole book ─────────────────────
$scope = $isCrmAgent ? "AND l.assigned_to = $uid" : '';
$stats = $db->query("
    SELECT
        COUNT(*)                                              AS total,
        COALESCE(SUM(l.deposit_amount), 0)                    AS deposits_held,
        COALESCE(SUM(l.agreed_sale_price), 0)                 AS order_value,
        COUNT(CASE WHEN l.expected_arrival_date IS NOT NULL
                    AND l.expected_arrival_date < CURDATE() THEN 1 END)  AS overdue,
        COUNT(CASE WHEN DATE_FORMAT(l.expected_arrival_date, '%Y-%m')
                     = DATE_FORMAT(CURDATE(), '%Y-%m') THEN 1 END)       AS this_month,
        COUNT(CASE WHEN l.expected_arrival_date IS NULL THEN 1 END)      AS unscheduled
      FROM crm_leads l
     WHERE l.stage = 'import_order' $scope
")->fetch(PDO::FETCH_ASSOC);

$agents = [];
if ($canFilter) {
    $agents = $db->query("
        SELECT DISTINCT u.id, u.name
          FROM crm_leads l JOIN users u ON u.id = l.assigned_to
         WHERE l.stage = 'import_order'
      ORDER BY u.name
    ")->fetchAll(PDO::FETCH_ASSOC);
}

$pageTitle = 'Import Orders';
include __DIR__ . '/../../includes/header.php';
?>
<style>
.io-banner{
    background:linear-gradient(135deg,#0c1e3e 0%,#1d4ed8 55%,#3b82f6 100%);
    border-radius:16px; padding:28px 32px 24px; margin-bottom:24px;
    color:#fff; position:relative; overflow:hidden;
}
.io-banner-title{ font-size:22px; font-weight:800; letter-spacing:-.4px; margin-bottom:2px; }
.io-banner-sub{ font-size:13px; opacity:.75; margin-bottom:24px; }
.io-stat-row{ display:grid; grid-template-columns:repeat(4,1fr); gap:12px; }
@media(max-width:768px){ .io-stat-row{ grid-template-columns:repeat(2,1fr); } }
.io-stat{ background:rgba(255,255,255,.12); border:1px solid rgba(255,255,255,.18);
    border-radius:12px; padding:14px 16px; }
.io-stat-val{ font-size:22px; font-weight:900; line-height:1; letter-spacing:-.5px; color:#fff; }
.io-stat-lbl{ font-size:11px; opacity:.7; margin-top:5px; text-transform:uppercase; letter-spacing:.6px; }
.io-stat-icon{ font-size:18px; opacity:.5; float:right; margin-top:-2px; }
.io-stat.warn{ background:rgba(251,191,36,.22); border-color:rgba(251,191,36,.45); }

.filter-bar{ background:var(--surface,#fff); border:1px solid var(--border,#e2e8f0);
    border-radius:12px; padding:16px 20px; margin-bottom:24px;
    display:flex; flex-wrap:wrap; gap:10px; align-items:flex-end; }
.filter-bar .filter-group{ display:flex; flex-direction:column; gap:4px; }
.filter-bar label{ font-size:11px; font-weight:700; text-transform:uppercase;
    letter-spacing:.5px; color:var(--text-2,#64748b); }
.filter-bar select, .filter-bar input{ border:1px solid var(--border,#e2e8f0); border-radius:8px;
    padding:7px 12px; font-size:13px; background:var(--surface-alt,#f8fafc); min-width:150px; }

.io-when{ font-size:11px; font-weight:700; padding:2px 9px; border-radius:20px; white-space:nowrap; }
.io-late{ background:#fee2e2; color:#b91c1c; }
.io-soon{ background:#fef3c7; color:#92400e; }
.io-ok{   background:#dcfce7; color:#166534; }
.io-none{ background:#f1f5f9; color:#64748b; }
.io-vehicle{ font-size:12.5px; color:var(--text-2,#64748b); max-width:340px; }
</style>

<div class="io-banner">
    <div class="io-banner-title"><i class="fa fa-ship me-2"></i>Import Orders</div>
    <div class="io-banner-sub">
        Vehicles being brought in to a customer's order. A lead moved to Import Order on its
        own page appears here.
    </div>
    <div class="io-stat-row">
        <div class="io-stat">
            <i class="fa fa-ship io-stat-icon"></i>
            <div class="io-stat-val"><?= (int)$stats['total'] ?></div>
            <div class="io-stat-lbl">Open orders</div>
        </div>
        <div class="io-stat">
            <i class="fa fa-hand-holding-dollar io-stat-icon"></i>
            <div class="io-stat-val" style="font-size:17px"><?= money((float)$stats['deposits_held']) ?></div>
            <div class="io-stat-lbl">Deposits held</div>
        </div>
        <div class="io-stat">
            <i class="fa fa-calendar-check io-stat-icon"></i>
            <div class="io-stat-val"><?= (int)$stats['this_month'] ?></div>
            <div class="io-stat-lbl">Due this month</div>
        </div>
        <div class="io-stat <?= (int)$stats['overdue'] > 0 ? 'warn' : '' ?>">
            <i class="fa fa-triangle-exclamation io-stat-icon"></i>
            <div class="io-stat-val"><?= (int)$stats['overdue'] ?></div>
            <div class="io-stat-lbl">Past the promised date</div>
        </div>
    </div>
</div>

<form method="get" class="filter-bar">
    <div class="filter-group">
        <label>Search</label>
        <input type="text" name="q" value="<?= e($filterSearch) ?>" placeholder="Customer or vehicle">
    </div>
    <div class="filter-group">
        <label>Arrival</label>
        <select name="when">
            <option value="">Any</option>
            <option value="overdue"     <?= $filterWhen === 'overdue' ? 'selected' : '' ?>>Past the promised date</option>
            <option value="soon"        <?= $filterWhen === 'soon' ? 'selected' : '' ?>>Due within 30 days</option>
            <option value="unscheduled" <?= $filterWhen === 'unscheduled' ? 'selected' : '' ?>>No date promised</option>
        </select>
    </div>
    <div class="filter-group">
        <label>Month due</label>
        <input type="month" name="month" value="<?= e($filterMonth) ?>">
    </div>
    <?php if ($canFilter && $agents): ?>
    <div class="filter-group">
        <label>Handled by</label>
        <select name="agent">
            <option value="0">Everyone</option>
            <?php foreach ($agents as $a): ?>
                <option value="<?= (int)$a['id'] ?>" <?= $filterAgent === (int)$a['id'] ? 'selected' : '' ?>>
                    <?= e($a['name']) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    <?php endif; ?>
    <div class="filter-group">
        <label>&nbsp;</label>
        <div class="d-flex gap-2">
            <button class="btn btn-primary btn-sm"><i class="fa fa-filter me-1"></i>Apply</button>
            <a href="index.php" class="btn btn-outline-secondary btn-sm">Clear</a>
        </div>
    </div>
</form>

<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
        <span><i class="fa fa-list me-2"></i><?= count($orders) ?> order<?= count($orders) === 1 ? '' : 's' ?></span>
        <?php if ((int)$stats['unscheduled'] > 0): ?>
            <a href="?when=unscheduled" class="badge bg-secondary text-decoration-none">
                <?= (int)$stats['unscheduled'] ?> with no date promised
            </a>
        <?php endif; ?>
    </div>

    <?php if (!$orders): ?>
        <div class="card-body text-center text-muted py-5">
            <i class="fa fa-ship fa-2x mb-3 d-block opacity-25"></i>
            <?php if ($filterSearch || $filterWhen || $filterMonth || $filterAgent): ?>
                No import orders match that. <a href="index.php">Clear the filters</a>.
            <?php else: ?>
                No import orders yet. One appears here when a lead is moved to
                <strong>Import Order</strong> on its own page.
            <?php endif; ?>
        </div>
    <?php else: ?>
    <div class="table-responsive">
        <table class="table table-hover mb-0" style="font-size:13px">
            <thead class="table-light">
                <tr>
                    <th class="ps-3">Customer</th>
                    <th>Vehicle ordered</th>
                    <th style="width:150px">Expected</th>
                    <th class="text-end" style="width:120px">Deposit</th>
                    <th class="text-end" style="width:130px">Agreed price</th>
                    <th style="width:150px">Handled by</th>
                    <th class="text-center pe-3" style="width:120px">Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($orders as $o):
                $days = $o['expected_arrival_date'] !== null ? (int)$o['days_away'] : null;
                if ($days === null)      { $cls = 'io-none'; $txt = 'No date promised'; }
                elseif ($days < 0)       { $cls = 'io-late'; $txt = abs($days) . ' days late'; }
                elseif ($days <= 30)     { $cls = 'io-soon'; $txt = $days === 0 ? 'Due today' : 'in ' . $days . ' days'; }
                else                     { $cls = 'io-ok';   $txt = 'in ' . $days . ' days'; }
            ?>
                <tr>
                    <td class="ps-3">
                        <div class="fw-semibold"><?= e($o['client_name'] ?: $o['lead_name']) ?></div>
                        <div class="text-muted small"><?= e($o['client_phone'] ?: $o['lead_phone'] ?: '—') ?></div>
                    </td>
                    <td class="io-vehicle">
                        <?= e($o['import_vehicle_details'] ?: 'Not specified') ?>
                    </td>
                    <td>
                        <?php if ($o['expected_arrival_date']): ?>
                            <div><?= fmtDate($o['expected_arrival_date']) ?></div>
                        <?php endif; ?>
                        <span class="io-when <?= $cls ?>"><?= e($txt) ?></span>
                    </td>
                    <td class="text-end"><?= $o['deposit_amount'] ? money((float)$o['deposit_amount']) : '—' ?></td>
                    <td class="text-end"><?= $o['agreed_sale_price'] ? money((float)$o['agreed_sale_price']) : '—' ?></td>
                    <td class="small text-muted"><?= e($o['agent_name'] ?: 'Unassigned') ?></td>
                    <td class="text-center pe-3">
                        <div class="d-flex gap-1 justify-content-center">
                            <a href="<?= BASE_URL ?>/modules/crm/view_lead.php?id=<?= (int)$o['lead_id'] ?>"
                               class="btn btn-xs btn-outline-primary" title="Open the lead">
                                <i class="fa fa-eye"></i></a>
                            <a href="<?= BASE_URL ?>/modules/crm/deposit_receipt.php?lead_id=<?= (int)$o['lead_id'] ?>"
                               target="_blank" rel="noopener"
                               class="btn btn-xs btn-outline-secondary" title="Deposit receipt">
                                <i class="fa fa-receipt"></i></a>
                            <a href="<?= BASE_URL ?>/modules/crm/proforma.php?lead_id=<?= (int)$o['lead_id'] ?>"
                               target="_blank" rel="noopener"
                               class="btn btn-xs btn-outline-secondary" title="Proforma invoice">
                                <i class="fa fa-file-invoice"></i></a>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>

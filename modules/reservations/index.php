<?php
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
canAccess('crm') || redirect(BASE_URL . '/index.php');

$db  = getDB();
$me  = authUser();
$uid = (int)$me['id'];
$isCrmAgent   = ($me['role'] === 'customer_relations');
$canFilter    = in_array($me['role'], ['admin','super_admin','general_manager']);

foreach ([
    "ALTER TABLE crm_leads ADD COLUMN pinned_car_id     INT           NULL DEFAULT NULL",
    "ALTER TABLE crm_leads ADD COLUMN deposit_amount    DECIMAL(15,2) NULL DEFAULT NULL",
    "ALTER TABLE crm_leads ADD COLUMN deposit_date      DATE          NULL DEFAULT NULL",
    "ALTER TABLE crm_leads ADD COLUMN deposit_notes     TEXT          NULL DEFAULT NULL",
    "ALTER TABLE crm_leads ADD COLUMN agreed_sale_price DECIMAL(15,2) NULL DEFAULT NULL",
    "ALTER TABLE crm_leads ADD COLUMN due_date          DATE          NULL DEFAULT NULL",
    "ALTER TABLE clients   ADD COLUMN kra_pin           VARCHAR(20)   NULL",
    "ALTER TABLE clients   ADD COLUMN id_number         VARCHAR(30)   NULL",
] as $_sql) { try { $db->exec($_sql); } catch (\Throwable $_) {} }

// ── Filters ───────────────────────────────────────────────────────────────────
$filterMake   = trim($_GET['make']  ?? '');
$filterAgent  = $canFilter ? (int)($_GET['agent'] ?? 0) : 0;
$filterSearch = trim($_GET['q']    ?? '');

// ── Build WHERE ───────────────────────────────────────────────────────────────
$where  = ["l.stage = 'reserved'"];
$params = [];
if ($isCrmAgent)  { $where[] = "l.assigned_to = $uid"; }
if ($filterMake)  { $where[] = 'c.make = ?';         $params[] = $filterMake; }
if ($filterAgent) { $where[] = 'l.assigned_to = ?';  $params[] = $filterAgent; }
if ($filterSearch) {
    $s = '%' . $filterSearch . '%';
    $where[] = '(l.name LIKE ? OR cl.name LIKE ? OR c.make LIKE ? OR c.model LIKE ?)';
    array_push($params, $s, $s, $s, $s);
}
$whereSQL = implode(' AND ', $where);

// ── Main query ────────────────────────────────────────────────────────────────
$stmt = $db->prepare("
    SELECT
        l.id                AS lead_id,
        l.name              AS lead_name,
        l.phone             AS lead_phone,
        l.email             AS lead_email,
        l.deposit_amount,
        l.deposit_date,
        l.deposit_notes,
        l.agreed_sale_price,
        l.due_date,
        l.updated_at,
        DATEDIFF(NOW(), l.updated_at) AS days_held,
        c.id                AS car_id,
        c.make, c.model, c.year, c.color,
        c.chassis_number, c.registration_number,
        c.asking_price, c.offer_price,
        cl.name             AS client_name,
        cl.phone            AS client_phone,
        cl.email            AS client_email,
        cl.id_number        AS client_id_no,
        u.id                AS agent_id,
        u.name              AS agent_name,
        (SELECT ci.file_path FROM car_images ci
         WHERE ci.car_id = c.id AND ci.is_primary = 1 LIMIT 1) AS primary_image
    FROM crm_leads l
    LEFT JOIN cars    c  ON c.id  = l.pinned_car_id
    LEFT JOIN clients cl ON cl.id = l.client_id
    LEFT JOIN users   u  ON u.id  = l.assigned_to
    WHERE $whereSQL
    ORDER BY l.updated_at DESC
");
$stmt->execute($params);
$reservations = $stmt->fetchAll();

// ── Dashboard stats (unfiltered totals for the current user scope) ─────────────
$scopeWhere = $isCrmAgent ? "AND l.assigned_to = $uid" : '';
$stats = $db->query("
    SELECT
        COUNT(*)                                             AS total,
        COALESCE(SUM(l.deposit_amount),0)                   AS total_deposits,
        COALESCE(SUM(
            GREATEST(0, COALESCE(l.agreed_sale_price,
                COALESCE(c.offer_price, c.asking_price, 0)) - COALESCE(l.deposit_amount,0))
        ),0)                                                 AS total_balance,
        COUNT(DISTINCT l.assigned_to)                       AS agent_count
    FROM crm_leads l
    LEFT JOIN cars c ON c.id = l.pinned_car_id
    WHERE l.stage = 'reserved' $scopeWhere
")->fetch();

// ── Filter dropdowns ──────────────────────────────────────────────────────────
$makesList = $db->query("
    SELECT DISTINCT c.make FROM crm_leads l
    LEFT JOIN cars c ON c.id = l.pinned_car_id
    WHERE l.stage = 'reserved' AND c.make IS NOT NULL AND c.make != '' $scopeWhere
    ORDER BY c.make
")->fetchAll(PDO::FETCH_COLUMN);

$agentsList = $canFilter ? $db->query("
    SELECT DISTINCT u.id, u.name FROM crm_leads l
    JOIN users u ON u.id = l.assigned_to
    WHERE l.stage = 'reserved'
    ORDER BY u.name
")->fetchAll() : [];

$total         = (int)$stats['total'];
$totalDeposits = (float)$stats['total_deposits'];
$totalBalance  = (float)$stats['total_balance'];
$agentCount    = (int)$stats['agent_count'];
$filtered      = count($reservations);
$isFiltered    = $filterMake || $filterAgent || $filterSearch;

$pageTitle = 'Reservations';
include __DIR__ . '/../../includes/header.php';
?>
<style>
/* ── Dashboard banner ─────────────────────────────────────────────────────── */
.res-banner {
    background: linear-gradient(135deg,#1e1b4b 0%,#3730a3 55%,#7c3aed 100%);
    border-radius: 16px;
    padding: 28px 32px 24px;
    margin-bottom: 24px;
    color: #fff;
    position: relative;
    overflow: hidden;
}
.res-banner::before {
    content:'';
    position:absolute;inset:0;
    background:url("data:image/svg+xml,%3Csvg width='60' height='60' viewBox='0 0 60 60' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='none' fill-rule='evenodd'%3E%3Cg fill='%23ffffff' fill-opacity='0.04'%3E%3Cpath d='M36 34v-4h-2v4h-4v2h4v4h2v-4h4v-2h-4zm0-30V0h-2v4h-4v2h4v4h2V6h4V4h-4zM6 34v-4H4v4H0v2h4v4h2v-4h4v-2H6zM6 4V0H4v4H0v2h4v4h2V6h4V4H6z'/%3E%3C/g%3E%3C/g%3E%3C/svg%3E");
}
.res-banner-title {
    font-size: 22px; font-weight: 800;
    letter-spacing: -.4px; margin-bottom: 2px;
}
.res-banner-sub { font-size: 13px; opacity: .75; margin-bottom: 24px; }
.res-stat-row {
    display: grid;
    grid-template-columns: repeat(4,1fr);
    gap: 12px;
}
@media(max-width:768px){ .res-stat-row { grid-template-columns: repeat(2,1fr); } }
@media(max-width:480px){ .res-stat-row { grid-template-columns: 1fr 1fr; } }
.res-stat {
    background: rgba(255,255,255,.12);
    border: 1px solid rgba(255,255,255,.18);
    border-radius: 12px;
    padding: 14px 16px;
    backdrop-filter: blur(4px);
}
.res-stat-val {
    font-size: 22px; font-weight: 900; line-height: 1;
    letter-spacing: -.5px; color: #fff;
}
.res-stat-lbl { font-size: 11px; opacity: .7; margin-top: 5px; text-transform: uppercase; letter-spacing: .6px; }
.res-stat-icon { font-size: 18px; opacity: .5; float: right; margin-top: -2px; }

/* ── Filter bar ───────────────────────────────────────────────────────────── */
.filter-bar {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 12px;
    padding: 16px 20px;
    margin-bottom: 24px;
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    align-items: flex-end;
}
.filter-bar .filter-group { display:flex; flex-direction:column; gap:4px; }
.filter-bar label { font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.5px; color:#64748b; }
.filter-bar select, .filter-bar input[type=text] {
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    padding: 7px 12px;
    font-size: 13px;
    color: #1e293b;
    background: #f8fafc;
    min-width: 150px;
    outline: none;
    transition: border-color .15s;
}
.filter-bar select:focus, .filter-bar input[type=text]:focus { border-color:#7c3aed; background:#fff; }
.filter-bar .filter-actions { margin-left: auto; display:flex; gap:8px; align-items:flex-end; }

/* ── Results bar ──────────────────────────────────────────────────────────── */
.results-bar {
    display:flex; align-items:center; gap:10px;
    margin-bottom:16px; flex-wrap:wrap;
}
.results-count { font-size:13px; font-weight:600; color:#374151; }
.active-filter-chip {
    display:inline-flex; align-items:center; gap:5px;
    background:#ede9fe; color:#5b21b6;
    font-size:11px; font-weight:700;
    padding:3px 10px; border-radius:20px;
}

/* ── Card grid ────────────────────────────────────────────────────────────── */
.res-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(400px,1fr));
    gap: 18px;
}
@media(max-width:500px){ .res-grid { grid-template-columns:1fr; } }

.res-card {
    background: #fff;
    border: 1px solid #e2e8f0;
    border-radius: 16px;
    overflow: hidden;
    box-shadow: 0 1px 8px rgba(0,0,0,.06);
    display: flex;
    flex-direction: column;
    transition: box-shadow .2s, transform .2s;
}
.res-card:hover { box-shadow: 0 6px 24px rgba(0,0,0,.10); transform: translateY(-2px); }

/* urgency left border */
.res-card.urgency-ok     { border-left: 4px solid #7c3aed; }
.res-card.urgency-soon   { border-left: 4px solid #f59e0b; }
.res-card.urgency-overdue{ border-left: 4px solid #dc2626; }

.res-card-top { display:flex; align-items:stretch; }
.res-img-wrap {
    width: 148px; flex-shrink: 0;
    position: relative; overflow: hidden;
    background: #f1f5f9;
}
.res-img-wrap img { width:100%; height:100%; object-fit:cover; display:block; }
.res-img-wrap .no-img {
    width:100%; height:100%; min-height:130px;
    display:flex; align-items:center; justify-content:center;
    font-size:38px; color:#cbd5e1;
}
.res-img-badge {
    position:absolute; top:8px; left:8px;
    background:#7c3aed; color:#fff;
    font-size:9.5px; font-weight:700;
    padding:3px 8px; border-radius:20px; letter-spacing:.3px;
}
.res-car-info { flex:1; padding:14px 16px 12px; }
.res-car-make { font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.7px; color:#7c3aed; margin-bottom:2px; }
.res-car-name { font-size:15px; font-weight:800; color:#0f172a; letter-spacing:-.3px; line-height:1.25; }
.res-car-meta { font-size:11.5px; color:#64748b; margin-top:3px; }
.res-car-price { font-size:17px; font-weight:900; color:#1d4ed8; margin-top:6px; letter-spacing:-.3px; }

.res-divider { height:1px; background:#f1f5f9; margin:0; }

.res-section { padding:12px 16px; }
.res-section-label {
    font-size:9.5px; font-weight:700; text-transform:uppercase;
    letter-spacing:.7px; color:#94a3b8; margin-bottom:8px;
}
.res-fields { display:grid; grid-template-columns:1fr 1fr; gap:6px 16px; }
.res-field-lbl { font-size:10.5px; color:#94a3b8; font-weight:600; }
.res-field-val { font-size:12.5px; color:#0f172a; font-weight:600; margin-top:1px; }

.res-pay-row {
    display:grid; grid-template-columns:1fr 1fr 1fr;
    gap:8px; padding:12px 16px;
    background:#fafafa; border-top:1px solid #f1f5f9;
}
.res-pay-tile {
    text-align:center; padding:8px 6px;
    border-radius:8px;
}
.res-pay-tile.deposit  { background:#f0fdf4; }
.res-pay-tile.balance  { background:#fff7ed; }
.res-pay-tile.due      { background:#eff6ff; }
.res-pay-tile-amt { font-size:13px; font-weight:800; line-height:1.2; }
.res-pay-tile-amt.green  { color:#15803d; }
.res-pay-tile-amt.orange { color:#c2410c; }
.res-pay-tile-amt.blue   { color:#1d4ed8; }
.res-pay-tile-lbl { font-size:9.5px; color:#94a3b8; font-weight:600; text-transform:uppercase; letter-spacing:.4px; margin-top:3px; }

.res-actions {
    display:flex; flex-wrap:wrap; gap:5px;
    padding:10px 16px;
    border-top:1px solid #f1f5f9;
    background:#f8fafc;
}
</style>

<!-- ── Dashboard banner ─────────────────────────────────────────────────────── -->
<div class="res-banner">
    <div class="res-banner-title"><i class="fa fa-bookmark me-2"></i>Reservations</div>
    <div class="res-banner-sub">
        <?php if ($isCrmAgent): ?>
        Your reserved vehicles — active holds requiring follow-up
        <?php else: ?>
        All active vehicle reservations across the sales floor
        <?php endif; ?>
    </div>
    <div class="res-stat-row">
        <div class="res-stat">
            <span class="res-stat-icon"><i class="fa fa-bookmark"></i></span>
            <div class="res-stat-val"><?= $total ?></div>
            <div class="res-stat-lbl">Reserved</div>
        </div>
        <div class="res-stat">
            <span class="res-stat-icon"><i class="fa fa-money-bill-wave"></i></span>
            <div class="res-stat-val" style="font-size:<?= strlen('KES '.number_format($totalDeposits,0)) > 12 ? '14' : '18' ?>px">
                KES <?= number_format($totalDeposits, 0) ?>
            </div>
            <div class="res-stat-lbl">Deposits Collected</div>
        </div>
        <div class="res-stat">
            <span class="res-stat-icon"><i class="fa fa-hourglass-half"></i></span>
            <div class="res-stat-val" style="font-size:<?= strlen('KES '.number_format($totalBalance,0)) > 12 ? '14' : '18' ?>px">
                KES <?= number_format($totalBalance, 0) ?>
            </div>
            <div class="res-stat-lbl">Balance Outstanding</div>
        </div>
        <div class="res-stat">
            <span class="res-stat-icon"><i class="fa fa-users"></i></span>
            <div class="res-stat-val"><?= $agentCount ?></div>
            <div class="res-stat-lbl"><?= $agentCount === 1 ? 'Agent' : 'Agents' ?> Active</div>
        </div>
    </div>
</div>

<!-- ── Filter bar ──────────────────────────────────────────────────────────── -->
<form method="GET" class="filter-bar">
    <div class="filter-group">
        <label>Search</label>
        <input type="text" name="q" value="<?= e($filterSearch) ?>" placeholder="Buyer name, make, model…">
    </div>
    <?php if ($makesList): ?>
    <div class="filter-group">
        <label>Make</label>
        <select name="make">
            <option value="">All Makes</option>
            <?php foreach ($makesList as $mk): ?>
            <option value="<?= e($mk) ?>" <?= $filterMake === $mk ? 'selected' : '' ?>><?= e($mk) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <?php endif; ?>
    <?php if ($canFilter && $agentsList): ?>
    <div class="filter-group">
        <label>Sales Agent</label>
        <select name="agent">
            <option value="">All Agents</option>
            <?php foreach ($agentsList as $ag): ?>
            <option value="<?= $ag['id'] ?>" <?= $filterAgent === (int)$ag['id'] ? 'selected' : '' ?>><?= e($ag['name']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <?php endif; ?>
    <div class="filter-actions">
        <button type="submit" class="btn btn-sm" style="background:#7c3aed;color:#fff;border-radius:8px;padding:7px 16px;font-size:13px">
            <i class="fa fa-filter me-1"></i>Filter
        </button>
        <?php if ($isFiltered): ?>
        <a href="?" class="btn btn-sm btn-outline-secondary" style="border-radius:8px;padding:7px 14px;font-size:13px">
            <i class="fa fa-xmark me-1"></i>Clear
        </a>
        <?php endif; ?>
    </div>
</form>

<!-- ── Results bar ─────────────────────────────────────────────────────────── -->
<div class="results-bar">
    <span class="results-count">
        <?= $filtered ?> reservation<?= $filtered !== 1 ? 's' : '' ?>
        <?= $isFiltered ? ' matching filters' : '' ?>
    </span>
    <?php if ($filterMake): ?>
    <span class="active-filter-chip"><i class="fa fa-car" style="font-size:9px"></i><?= e($filterMake) ?></span>
    <?php endif; ?>
    <?php if ($filterAgent && $canFilter): ?>
    <?php $agName = ''; foreach ($agentsList as $ag) { if ((int)$ag['id'] === $filterAgent) { $agName = $ag['name']; break; } } ?>
    <span class="active-filter-chip"><i class="fa fa-user" style="font-size:9px"></i><?= e($agName) ?></span>
    <?php endif; ?>
    <?php if ($filterSearch): ?>
    <span class="active-filter-chip"><i class="fa fa-magnifying-glass" style="font-size:9px"></i><?= e($filterSearch) ?></span>
    <?php endif; ?>
</div>

<?php if (!$reservations): ?>
<div class="card border-0 shadow-sm">
    <div class="card-body text-center py-5">
        <div style="font-size:52px;color:#e2e8f0;margin-bottom:16px"><i class="fa fa-bookmark"></i></div>
        <h5 class="fw-bold mb-1 text-muted">
            <?= $isFiltered ? 'No reservations match your filters' : 'No Reservations Yet' ?>
        </h5>
        <p class="text-muted mb-3" style="font-size:13.5px">
            <?= $isFiltered ? 'Try adjusting or clearing your filters.' : 'When a lead is reserved and linked to a vehicle, it will appear here.' ?>
        </p>
        <?php if ($isFiltered): ?>
        <a href="?" class="btn btn-outline-secondary btn-sm">Clear Filters</a>
        <?php else: ?>
        <a href="<?= BASE_URL ?>/modules/crm/leads.php" class="btn btn-primary btn-sm">
            <i class="fa fa-user-plus me-1"></i>Go to Leads
        </a>
        <?php endif; ?>
    </div>
</div>
<?php else: ?>
<div class="res-grid">
<?php foreach ($reservations as $r):
    $buyerName   = $r['client_name']  ?: $r['lead_name']  ?: '—';
    $buyerPhone  = $r['client_phone'] ?: $r['lead_phone'] ?: '';
    $buyerEmail  = $r['client_email'] ?: $r['lead_email'] ?: '';
    $buyerIdNo   = $r['client_id_no'] ?? '';
    $carTitle    = $r['car_id']
                 ? trim(($r['year']??'').' '.($r['make']??'').' '.($r['model']??''))
                 : '—';
    $deposit  = (float)($r['deposit_amount']    ?? 0);
    $agreed   = (float)($r['agreed_sale_price'] ?? 0)
             ?: ((float)($r['offer_price']  ?? 0) ?: (float)($r['asking_price'] ?? 0));
    $balance  = max(0, $agreed - $deposit);
    $depFmt   = $r['deposit_date'] ? (new DateTime($r['deposit_date']))->format('d M Y') : '—';
    $dueFmt   = $r['due_date']     ? (new DateTime($r['due_date']))->format('d M Y')     : '—';
    $imgUrl   = $r['primary_image'] ? thumbUrl('cars', $r['primary_image'])              : null;
    $daysHeld = (int)($r['days_held'] ?? 0);

    // Urgency class based on due date
    $urgency = 'urgency-ok';
    if ($r['due_date']) {
        $daysUntilDue = (int)((new DateTime($r['due_date']))->diff(new DateTime())->days);
        $isPast       = new DateTime($r['due_date']) < new DateTime();
        if ($isPast)                  $urgency = 'urgency-overdue';
        elseif ($daysUntilDue <= 7)   $urgency = 'urgency-soon';
    }
?>
<div class="res-card <?= $urgency ?>">

    <!-- Top: image + car info -->
    <div class="res-card-top">
        <div class="res-img-wrap">
            <?php if ($imgUrl): ?>
            <img src="<?= e($imgUrl) ?>" alt="<?= e($carTitle) ?>" loading="lazy">
            <?php else: ?>
            <div class="no-img"><i class="fa fa-car-side"></i></div>
            <?php endif; ?>
            <span class="res-img-badge"><i class="fa fa-bookmark me-1"></i>Reserved</span>
        </div>
        <div class="res-car-info">
            <?php if ($r['make']): ?>
            <div class="res-car-make"><?= e($r['make']) ?></div>
            <?php endif; ?>
            <div class="res-car-name"><?= e($carTitle) ?></div>
            <div class="res-car-meta">
                <?php
                $meta = [];
                if ($r['color'])               $meta[] = e(ucfirst($r['color']));
                if ($r['registration_number']) $meta[] = e($r['registration_number']);
                if ($r['chassis_number'])      $meta[] = '<i class="fa fa-barcode" style="font-size:10px"></i> '.e($r['chassis_number']);
                echo implode(' &bull; ', $meta);
                ?>
            </div>
            <?php if ($agreed > 0): ?>
            <div class="res-car-price">KES <?= number_format($agreed) ?></div>
            <?php endif; ?>
            <div style="font-size:11px;color:#94a3b8;margin-top:5px">
                <?= $daysHeld ?> day<?= $daysHeld !== 1 ? 's' : '' ?> on hold
                <?php if ($urgency === 'urgency-overdue'): ?>
                <span style="color:#dc2626;font-weight:700;margin-left:6px"><i class="fa fa-circle-exclamation me-1"></i>Overdue</span>
                <?php elseif ($urgency === 'urgency-soon'): ?>
                <span style="color:#d97706;font-weight:700;margin-left:6px"><i class="fa fa-clock me-1"></i>Due soon</span>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="res-divider"></div>

    <!-- Buyer + Agent -->
    <div class="res-section">
        <div class="res-section-label"><i class="fa fa-user me-1"></i>Buyer</div>
        <div class="res-fields">
            <div>
                <div class="res-field-lbl">Name</div>
                <div class="res-field-val"><?= e($buyerName) ?></div>
            </div>
            <?php if ($buyerPhone): ?>
            <div>
                <div class="res-field-lbl">Phone</div>
                <div class="res-field-val">
                    <a href="tel:<?= e($buyerPhone) ?>" class="text-decoration-none text-dark"><?= e($buyerPhone) ?></a>
                </div>
            </div>
            <?php endif; ?>
            <?php if ($buyerIdNo): ?>
            <div>
                <div class="res-field-lbl">ID / Passport</div>
                <div class="res-field-val"><?= e($buyerIdNo) ?></div>
            </div>
            <?php endif; ?>
            <?php if ($r['agent_name']): ?>
            <div>
                <div class="res-field-lbl">Sales Agent</div>
                <div class="res-field-val" style="color:#7c3aed"><?= e($r['agent_name']) ?></div>
            </div>
            <?php endif; ?>
        </div>
        <?php if ($r['deposit_notes']): ?>
        <div style="margin-top:8px;padding:7px 10px;background:#fafafa;border-radius:7px;font-size:11.5px;color:#64748b;border:1px solid #f1f5f9">
            <i class="fa fa-note-sticky me-1 text-muted"></i><?= e($r['deposit_notes']) ?>
        </div>
        <?php endif; ?>
    </div>

    <!-- Payment tiles -->
    <div class="res-pay-row">
        <div class="res-pay-tile deposit">
            <div class="res-pay-tile-amt green">KES <?= number_format($deposit) ?></div>
            <div class="res-pay-tile-lbl">Deposit<br><span style="font-weight:400;opacity:.75"><?= $depFmt ?></span></div>
        </div>
        <div class="res-pay-tile balance">
            <div class="res-pay-tile-amt orange">KES <?= number_format($balance) ?></div>
            <div class="res-pay-tile-lbl">Balance</div>
        </div>
        <div class="res-pay-tile due">
            <div class="res-pay-tile-amt blue" style="font-size:12px"><?= $dueFmt ?></div>
            <div class="res-pay-tile-lbl">Due Date</div>
        </div>
    </div>

    <!-- Actions -->
    <div class="res-actions">
        <a href="<?= BASE_URL ?>/modules/crm/view_lead.php?id=<?= $r['lead_id'] ?>"
           class="btn btn-sm btn-outline-secondary">
            <i class="fa fa-eye me-1"></i>Lead
        </a>
        <?php if ($r['car_id']): ?>
        <a href="<?= BASE_URL ?>/modules/crm/proforma.php?lead_id=<?= $r['lead_id'] ?>"
           class="btn btn-sm btn-outline-primary" target="_blank">
            <i class="fa fa-file-invoice me-1"></i>Proforma
        </a>
        <a href="<?= BASE_URL ?>/modules/crm/sales_agreement.php?lead_id=<?= $r['lead_id'] ?>"
           class="btn btn-sm btn-outline-dark" target="_blank">
            <i class="fa fa-file-signature me-1"></i>Agreement
        </a>
        <a href="<?= BASE_URL ?>/modules/crm/deposit_receipt.php?lead_id=<?= $r['lead_id'] ?>"
           class="btn btn-sm" style="background:#7c3aed;color:#fff;border:1px solid #7c3aed" target="_blank">
            <i class="fa fa-receipt me-1"></i>Deposit Rcpt
        </a>
        <a href="<?= BASE_URL ?>/modules/crm/sales_receipt.php?lead_id=<?= $r['lead_id'] ?>"
           class="btn btn-sm btn-outline-info" target="_blank">
            <i class="fa fa-file-invoice-dollar me-1"></i>Sales Rcpt
        </a>
        <?php endif; ?>
    </div>

</div>
<?php endforeach; ?>
</div>
<?php endif; ?>

<?php include __DIR__ . '/../../includes/footer.php'; ?>

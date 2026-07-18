<?php
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
canAccess('crm') || redirect(BASE_URL . '/index.php');

$db  = getDB();
$me  = authUser();
$uid = (int)$me['id'];
$isCrmAgent = ($me['role'] === 'customer_relations');
$canFilter  = in_array($me['role'], ['admin','super_admin','general_manager']);

foreach ([
    "ALTER TABLE crm_leads ADD COLUMN delivered_at           DATETIME      NULL DEFAULT NULL",
    "ALTER TABLE crm_leads ADD COLUMN agreed_sale_price      DECIMAL(15,2) NULL DEFAULT NULL",
    "ALTER TABLE crm_leads ADD COLUMN deposit_amount         DECIMAL(15,2) NULL DEFAULT NULL",
    "ALTER TABLE crm_leads ADD COLUMN import_vehicle_details TEXT          NULL DEFAULT NULL",
    "ALTER TABLE clients   ADD COLUMN id_number              VARCHAR(30)   NULL",
] as $_sql) { try { $db->exec($_sql); } catch (\Throwable $_) {} }

// ── Filters ───────────────────────────────────────────────────────────────────
$filterMake   = trim($_GET['make']  ?? '');
$filterAgent  = $canFilter ? (int)($_GET['agent'] ?? 0) : 0;
$filterSearch = trim($_GET['q']    ?? '');
$filterMonth  = trim($_GET['month'] ?? '');   // YYYY-MM

// ── Build WHERE ───────────────────────────────────────────────────────────────
$where  = ["l.stage = 'delivered'"];
$params = [];
if ($isCrmAgent)   { $where[] = "l.assigned_to = $uid"; }
if ($filterMake)   { $where[] = 'c.make = ?';         $params[] = $filterMake; }
if ($filterAgent)  { $where[] = 'l.assigned_to = ?';  $params[] = $filterAgent; }
if ($filterMonth)  {
    $where[] = 'DATE_FORMAT(COALESCE(l.delivered_at, l.converted_at, l.updated_at), \'%Y-%m\') = ?';
    $params[] = $filterMonth;
}
if ($filterSearch) {
    $s = '%' . $filterSearch . '%';
    $where[] = '(l.name LIKE ? OR cl.name LIKE ? OR c.make LIKE ? OR c.model LIKE ? OR l.import_vehicle_details LIKE ?)';
    array_push($params, $s, $s, $s, $s, $s);
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
        l.agreed_sale_price,
        l.delivered_at,
        l.converted_at,
        l.import_vehicle_details,
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
    ORDER BY COALESCE(l.delivered_at, l.converted_at, l.updated_at) DESC
");
$stmt->execute($params);
$deliveries = $stmt->fetchAll();

// ── Dashboard stats (unfiltered, scoped to agent if needed) ───────────────────
$scopeWhere = $isCrmAgent ? "AND l.assigned_to = $uid" : '';
$stats = $db->query("
    SELECT
        COUNT(*)                                                                       AS total,
        COALESCE(SUM(COALESCE(l.agreed_sale_price, c.offer_price, c.asking_price, 0)), 0) AS total_revenue,
        COUNT(CASE WHEN DATE_FORMAT(COALESCE(l.delivered_at,l.converted_at),'%Y-%m')
                        = DATE_FORMAT(NOW(),'%Y-%m') THEN 1 END)                     AS this_month,
        COALESCE(AVG(NULLIF(COALESCE(l.agreed_sale_price, c.offer_price, c.asking_price, 0),0)),0) AS avg_sale
    FROM crm_leads l
    LEFT JOIN cars c ON c.id = l.pinned_car_id
    WHERE l.stage = 'delivered' $scopeWhere
")->fetch();

// ── Filter dropdowns ──────────────────────────────────────────────────────────
$makesList = $db->query("
    SELECT DISTINCT c.make FROM crm_leads l
    LEFT JOIN cars c ON c.id = l.pinned_car_id
    WHERE l.stage = 'delivered' AND c.make IS NOT NULL AND c.make != '' $scopeWhere
    ORDER BY c.make
")->fetchAll(PDO::FETCH_COLUMN);

$agentsList = $canFilter ? $db->query("
    SELECT DISTINCT u.id, u.name FROM crm_leads l
    JOIN users u ON u.id = l.assigned_to
    WHERE l.stage = 'delivered'
    ORDER BY u.name
")->fetchAll() : [];

// Month options: last 12 months
$monthOptions = [];
for ($i = 0; $i < 12; $i++) {
    $dt = new DateTime("first day of -$i months");
    $monthOptions[$dt->format('Y-m')] = $dt->format('M Y');
}

$total      = (int)$stats['total'];
$totalRev   = (float)$stats['total_revenue'];
$thisMonth  = (int)$stats['this_month'];
$avgSale    = (float)$stats['avg_sale'];
$filtered   = count($deliveries);
$isFiltered = $filterMake || $filterAgent || $filterSearch || $filterMonth;

$pageTitle = 'Delivered Cars';
include __DIR__ . '/../../includes/header.php';
?>
<style>
/* ── Dashboard banner ─────────────────────────────────────────────────────── */
.dlv-banner {
    background: linear-gradient(135deg,#052e16 0%,#166534 55%,#16a34a 100%);
    border-radius: 16px;
    padding: 28px 32px 24px;
    margin-bottom: 24px;
    color: #fff;
    position: relative;
    overflow: hidden;
}
.dlv-banner::before {
    content:'';
    position:absolute;inset:0;
    background:url("data:image/svg+xml,%3Csvg width='60' height='60' viewBox='0 0 60 60' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='none' fill-rule='evenodd'%3E%3Cg fill='%23ffffff' fill-opacity='0.04'%3E%3Cpath d='M36 34v-4h-2v4h-4v2h4v4h2v-4h4v-2h-4zm0-30V0h-2v4h-4v2h4v4h2V6h4V4h-4zM6 34v-4H4v4H0v2h4v4h2v-4h4v-2H6zM6 4V0H4v4H0v2h4v4h2V6h4V4H6z'/%3E%3C/g%3E%3C/g%3E%3C/svg%3E");
}
.dlv-banner-title { font-size:22px; font-weight:800; letter-spacing:-.4px; margin-bottom:2px; }
.dlv-banner-sub   { font-size:13px; opacity:.75; margin-bottom:24px; }
.dlv-stat-row {
    display:grid; grid-template-columns:repeat(4,1fr); gap:12px;
}
@media(max-width:768px){ .dlv-stat-row { grid-template-columns:repeat(2,1fr); } }
.dlv-stat {
    background:rgba(255,255,255,.12);
    border:1px solid rgba(255,255,255,.18);
    border-radius:12px; padding:14px 16px;
    backdrop-filter:blur(4px);
}
.dlv-stat-val { font-size:22px; font-weight:900; line-height:1; letter-spacing:-.5px; color:#fff; }
.dlv-stat-lbl { font-size:11px; opacity:.7; margin-top:5px; text-transform:uppercase; letter-spacing:.6px; }
.dlv-stat-icon { font-size:18px; opacity:.5; float:right; margin-top:-2px; }

/* ── Filter bar ───────────────────────────────────────────────────────────── */
.filter-bar {
    background:#fff; border:1px solid #e2e8f0; border-radius:12px;
    padding:16px 20px; margin-bottom:24px;
    display:flex; flex-wrap:wrap; gap:10px; align-items:flex-end;
}
.filter-bar .filter-group { display:flex; flex-direction:column; gap:4px; }
.filter-bar label { font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.5px; color:#64748b; }
.filter-bar select, .filter-bar input[type=text] {
    border:1px solid #e2e8f0; border-radius:8px;
    padding:7px 12px; font-size:13px; color:#1e293b;
    background:#f8fafc; min-width:150px; outline:none; transition:border-color .15s;
}
.filter-bar select:focus, .filter-bar input[type=text]:focus { border-color:#16a34a; background:#fff; }
.filter-bar .filter-actions { margin-left:auto; display:flex; gap:8px; align-items:flex-end; }

/* ── Results bar ──────────────────────────────────────────────────────────── */
.results-bar { display:flex; align-items:center; gap:10px; margin-bottom:16px; flex-wrap:wrap; }
.results-count { font-size:13px; font-weight:600; color:#374151; }
.active-filter-chip {
    display:inline-flex; align-items:center; gap:5px;
    background:#dcfce7; color:#15803d;
    font-size:11px; font-weight:700;
    padding:3px 10px; border-radius:20px;
}

/* ── Card grid ────────────────────────────────────────────────────────────── */
.dlv-grid {
    display:grid;
    grid-template-columns:repeat(auto-fill, minmax(400px,1fr));
    gap:18px;
}
@media(max-width:500px){ .dlv-grid { grid-template-columns:1fr; } }

.dlv-card {
    background:#fff;
    border:1px solid #e2e8f0;
    border-left:4px solid #16a34a;
    border-radius:16px;
    overflow:hidden;
    box-shadow:0 1px 8px rgba(0,0,0,.06);
    display:flex;
    flex-direction:column;
    transition:box-shadow .2s, transform .2s;
}
.dlv-card:hover { box-shadow:0 6px 24px rgba(0,0,0,.10); transform:translateY(-2px); }

.dlv-card-top { display:flex; align-items:stretch; }
.dlv-img-wrap {
    width:148px; flex-shrink:0;
    position:relative; overflow:hidden;
    background:#f1f5f9;
}
.dlv-img-wrap img { width:100%; height:100%; object-fit:cover; display:block; }
.dlv-img-wrap .no-img {
    width:100%; height:100%; min-height:130px;
    display:flex; align-items:center; justify-content:center;
    font-size:38px; color:#cbd5e1;
}
.dlv-img-badge {
    position:absolute; top:8px; left:8px;
    background:#16a34a; color:#fff;
    font-size:9.5px; font-weight:700;
    padding:3px 8px; border-radius:20px; letter-spacing:.3px;
}
.dlv-car-info { flex:1; padding:14px 16px 12px; }
.dlv-car-make { font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.7px; color:#16a34a; margin-bottom:2px; }
.dlv-car-name { font-size:15px; font-weight:800; color:#0f172a; letter-spacing:-.3px; line-height:1.25; }
.dlv-car-meta { font-size:11.5px; color:#64748b; margin-top:3px; }
.dlv-delivery-date {
    display:inline-flex; align-items:center; gap:6px;
    margin-top:8px; padding:5px 10px;
    background:#f0fdf4; border:1px solid #bbf7d0;
    border-radius:8px; font-size:12px; font-weight:700; color:#15803d;
}
.dlv-price { font-size:17px; font-weight:900; color:#1d4ed8; margin-top:6px; letter-spacing:-.3px; }

.dlv-divider { height:1px; background:#f1f5f9; margin:0; }

.dlv-section { padding:12px 16px; }
.dlv-section-label {
    font-size:9.5px; font-weight:700; text-transform:uppercase;
    letter-spacing:.7px; color:#94a3b8; margin-bottom:8px;
}
.dlv-fields { display:grid; grid-template-columns:1fr 1fr; gap:6px 16px; }
.dlv-field-lbl { font-size:10.5px; color:#94a3b8; font-weight:600; }
.dlv-field-val { font-size:12.5px; color:#0f172a; font-weight:600; margin-top:1px; }

.dlv-actions {
    display:flex; flex-wrap:wrap; gap:5px;
    padding:10px 16px;
    border-top:1px solid #f1f5f9;
    background:#f8fafc;
    margin-top:auto;
}

/* ── Dark mode ────────────────────────────────────────────────────────────── */
[data-theme="dark"] .filter-bar { background: var(--surface); border-color: var(--border); }
[data-theme="dark"] .filter-bar label { color: var(--text-3); }
[data-theme="dark"] .filter-bar select,
[data-theme="dark"] .filter-bar input[type=text] {
    background: var(--surface-alt); border-color: var(--border); color: var(--text);
}
[data-theme="dark"] .filter-bar select:focus,
[data-theme="dark"] .filter-bar input[type=text]:focus { background: var(--surface); border-color:#16a34a; }
[data-theme="dark"] .results-count { color: var(--text); }
[data-theme="dark"] .active-filter-chip { background: rgba(34,197,94,.16); color:#4ade80; }
[data-theme="dark"] .dlv-card {
    background: var(--surface);
    border-color: var(--border);
    box-shadow: 0 12px 32px rgba(0,0,0,.5), inset 0 1px 0 rgba(255,255,255,.04);
}
[data-theme="dark"] .dlv-card:hover {
    box-shadow:
        0 24px 56px rgba(0,0,0,.6),
        0 0 44px rgba(34,197,94,.32),
        0 0 34px rgba(59,130,246,.20),
        0 0 28px rgba(239,68,68,.14);
}
[data-theme="dark"] .dlv-img-wrap { background: var(--surface-alt); }
[data-theme="dark"] .dlv-img-wrap .no-img { color: #3b4f76; }
[data-theme="dark"] .dlv-car-name { color: var(--text); }
[data-theme="dark"] .dlv-delivery-date { background: rgba(34,197,94,.12); border-color: rgba(34,197,94,.3); color:#4ade80; }
[data-theme="dark"] .dlv-price { color: #7cabf8; }
[data-theme="dark"] .dlv-divider { background: var(--border); }
[data-theme="dark"] .dlv-field-val { color: var(--text); }
[data-theme="dark"] .dlv-actions { background: var(--surface-alt); border-top-color: var(--border); }
</style>

<!-- ── Dashboard banner ─────────────────────────────────────────────────────── -->
<div class="dlv-banner">
    <div class="dlv-banner-title"><i class="fa fa-truck me-2"></i>Delivered Cars</div>
    <div class="dlv-banner-sub">
        <?php if ($isCrmAgent): ?>
        Vehicles you have successfully delivered to buyers
        <?php else: ?>
        Complete record of all vehicles delivered to buyers
        <?php endif; ?>
    </div>
    <div class="dlv-stat-row">
        <div class="dlv-stat">
            <span class="dlv-stat-icon"><i class="fa fa-truck"></i></span>
            <div class="dlv-stat-val"><?= $total ?></div>
            <div class="dlv-stat-lbl">Total Delivered</div>
        </div>
        <div class="dlv-stat">
            <span class="dlv-stat-icon"><i class="fa fa-chart-line"></i></span>
            <div class="dlv-stat-val" style="font-size:<?= strlen('KES '.number_format($totalRev,0)) > 12 ? '13' : '18' ?>px">
                KES <?= number_format($totalRev, 0) ?>
            </div>
            <div class="dlv-stat-lbl">Total Revenue</div>
        </div>
        <div class="dlv-stat">
            <span class="dlv-stat-icon"><i class="fa fa-calendar"></i></span>
            <div class="dlv-stat-val"><?= $thisMonth ?></div>
            <div class="dlv-stat-lbl">This Month</div>
        </div>
        <div class="dlv-stat">
            <span class="dlv-stat-icon"><i class="fa fa-tag"></i></span>
            <div class="dlv-stat-val" style="font-size:<?= strlen('KES '.number_format($avgSale,0)) > 12 ? '13' : '18' ?>px">
                <?= $avgSale > 0 ? 'KES '.number_format($avgSale, 0) : '—' ?>
            </div>
            <div class="dlv-stat-lbl">Avg. Sale Price</div>
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
    <div class="filter-group">
        <label>Month</label>
        <select name="month">
            <option value="">All Time</option>
            <?php foreach ($monthOptions as $val => $label): ?>
            <option value="<?= $val ?>" <?= $filterMonth === $val ? 'selected' : '' ?>><?= $label ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="filter-actions">
        <button type="submit" class="btn btn-sm" style="background:#16a34a;color:#fff;border-radius:8px;padding:7px 16px;font-size:13px">
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
        <?= $filtered ?> vehicle<?= $filtered !== 1 ? 's' : '' ?>
        <?= $isFiltered ? ' matching filters' : '' ?>
    </span>
    <?php if ($filterMake): ?>
    <span class="active-filter-chip"><i class="fa fa-car" style="font-size:9px"></i><?= e($filterMake) ?></span>
    <?php endif; ?>
    <?php if ($filterAgent && $canFilter): ?>
    <?php $agName = ''; foreach ($agentsList as $ag) { if ((int)$ag['id'] === $filterAgent) { $agName = $ag['name']; break; } } ?>
    <span class="active-filter-chip"><i class="fa fa-user" style="font-size:9px"></i><?= e($agName) ?></span>
    <?php endif; ?>
    <?php if ($filterMonth): ?>
    <span class="active-filter-chip"><i class="fa fa-calendar" style="font-size:9px"></i><?= e($monthOptions[$filterMonth] ?? $filterMonth) ?></span>
    <?php endif; ?>
    <?php if ($filterSearch): ?>
    <span class="active-filter-chip"><i class="fa fa-magnifying-glass" style="font-size:9px"></i><?= e($filterSearch) ?></span>
    <?php endif; ?>
</div>

<?php if (!$deliveries): ?>
<div class="card border-0 shadow-sm">
    <div class="card-body text-center py-5">
        <div style="font-size:52px;color:#e2e8f0;margin-bottom:16px"><i class="fa fa-truck"></i></div>
        <h5 class="fw-bold mb-1 text-muted">
            <?= $isFiltered ? 'No deliveries match your filters' : 'No Delivered Cars Yet' ?>
        </h5>
        <p class="text-muted mb-3" style="font-size:13.5px">
            <?= $isFiltered ? 'Try adjusting or clearing your filters.' : 'When a lead is marked as Delivered, the vehicle will appear here.' ?>
        </p>
        <?php if ($isFiltered): ?>
        <a href="?" class="btn btn-outline-secondary btn-sm">Clear Filters</a>
        <?php else: ?>
        <a href="<?= BASE_URL ?>/modules/crm/leads.php" class="btn btn-primary btn-sm">
            <i class="fa fa-users me-1"></i>Go to Leads
        </a>
        <?php endif; ?>
    </div>
</div>
<?php else: ?>
<div class="dlv-grid">
<?php foreach ($deliveries as $d):
    $buyerName  = $d['client_name']  ?: $d['lead_name']  ?: '—';
    $buyerPhone = $d['client_phone'] ?: $d['lead_phone'] ?: '';
    $buyerEmail = $d['client_email'] ?: $d['lead_email'] ?: '';
    $buyerIdNo  = $d['client_id_no'] ?? '';

    if ($d['import_vehicle_details']) {
        $carMake  = '';
        $carTitle = $d['import_vehicle_details'];
        $carSub   = 'Import Order';
    } elseif ($d['car_id']) {
        $carMake  = $d['make'] ?? '';
        $carTitle = trim(($d['year']??'').' '.($d['make']??'').' '.($d['model']??''));
        $parts = [];
        if ($d['color'])               $parts[] = ucfirst($d['color']);
        if ($d['registration_number']) $parts[] = $d['registration_number'];
        $carSub = implode(' · ', $parts);
    } else {
        $carMake  = '';
        $carTitle = '—';
        $carSub   = '';
    }

    $agreedPrice = (float)($d['agreed_sale_price'] ?? 0)
                ?: ((float)($d['offer_price'] ?? 0) ?: (float)($d['asking_price'] ?? 0));

    $dlvDate = $d['delivered_at'] ?? $d['converted_at'] ?? null;
    $dlvFmt  = $dlvDate ? (new DateTime(substr($dlvDate,0,10)))->format('d M Y') : '—';

    $imgUrl = $d['primary_image'] ? thumbUrl('cars', $d['primary_image']) : null;
?>
<div class="dlv-card">

    <!-- Top: image + car info -->
    <div class="dlv-card-top">
        <div class="dlv-img-wrap">
            <?php if ($imgUrl): ?>
            <img src="<?= e($imgUrl) ?>" alt="<?= e($carTitle) ?>" loading="lazy">
            <?php else: ?>
            <div class="no-img"><i class="fa fa-truck"></i></div>
            <?php endif; ?>
            <span class="dlv-img-badge"><i class="fa fa-truck me-1"></i>Delivered</span>
        </div>
        <div class="dlv-car-info">
            <?php if ($carMake): ?>
            <div class="dlv-car-make"><?= e($carMake) ?></div>
            <?php endif; ?>
            <div class="dlv-car-name"><?= e($carTitle) ?></div>
            <?php if ($carSub): ?>
            <div class="dlv-car-meta"><?= e($carSub) ?></div>
            <?php endif; ?>
            <?php if ($d['chassis_number']): ?>
            <div class="dlv-car-meta" style="margin-top:2px">
                <i class="fa fa-barcode me-1" style="font-size:10px"></i><?= e($d['chassis_number']) ?>
            </div>
            <?php endif; ?>
            <div class="dlv-delivery-date">
                <i class="fa fa-calendar-check"></i><?= $dlvFmt ?>
            </div>
            <?php if ($agreedPrice > 0): ?>
            <div class="dlv-price">KES <?= number_format($agreedPrice) ?></div>
            <?php endif; ?>
        </div>
    </div>

    <div class="dlv-divider"></div>

    <!-- Buyer + Agent -->
    <div class="dlv-section">
        <div class="dlv-section-label"><i class="fa fa-user me-1"></i>Buyer</div>
        <div class="dlv-fields">
            <div>
                <div class="dlv-field-lbl">Name</div>
                <div class="dlv-field-val"><?= e($buyerName) ?></div>
            </div>
            <?php if ($buyerPhone): ?>
            <div>
                <div class="dlv-field-lbl">Phone</div>
                <div class="dlv-field-val">
                    <a href="tel:<?= e($buyerPhone) ?>" class="text-decoration-none text-dark"><?= e($buyerPhone) ?></a>
                </div>
            </div>
            <?php endif; ?>
            <?php if ($buyerIdNo): ?>
            <div>
                <div class="dlv-field-lbl">ID / Passport</div>
                <div class="dlv-field-val"><?= e($buyerIdNo) ?></div>
            </div>
            <?php endif; ?>
            <?php if ($d['agent_name']): ?>
            <div>
                <div class="dlv-field-lbl">Sales Agent</div>
                <div class="dlv-field-val" style="color:#16a34a"><?= e($d['agent_name']) ?></div>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Actions -->
    <div class="dlv-actions">
        <a href="<?= BASE_URL ?>/modules/crm/view_lead.php?id=<?= $d['lead_id'] ?>"
           class="btn btn-sm btn-outline-secondary">
            <i class="fa fa-eye me-1"></i>Lead
        </a>
        <a href="<?= BASE_URL ?>/modules/crm/delivery_note.php?lead_id=<?= $d['lead_id'] ?>"
           class="btn btn-sm" style="background:#16a34a;color:#fff;border:1px solid #16a34a" target="_blank">
            <i class="fa fa-truck me-1"></i>Delivery Note
        </a>
        <a href="<?= BASE_URL ?>/modules/crm/sales_receipt.php?lead_id=<?= $d['lead_id'] ?>"
           class="btn btn-sm btn-outline-info" target="_blank">
            <i class="fa fa-file-invoice-dollar me-1"></i>Sales Rcpt
        </a>
        <a href="<?= BASE_URL ?>/modules/crm/proforma.php?lead_id=<?= $d['lead_id'] ?>"
           class="btn btn-sm btn-outline-primary" target="_blank">
            <i class="fa fa-file-invoice me-1"></i>Proforma
        </a>
        <?php if ($d['car_id']): ?>
        <a href="<?= BASE_URL ?>/modules/cars/view.php?id=<?= $d['car_id'] ?>"
           class="btn btn-sm btn-outline-dark" target="_blank">
            <i class="fa fa-car me-1"></i>Car
        </a>
        <?php endif; ?>
    </div>

</div>
<?php endforeach; ?>
</div>
<?php endif; ?>

<?php include __DIR__ . '/../../includes/footer.php'; ?>

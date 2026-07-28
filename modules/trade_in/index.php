<?php
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/_bootstrap.php';
requireLogin();
canAccess('trade_in') || redirect(BASE_URL . '/index.php');

$db = getDB();
tradeInMigrate($db);

$types    = consignmentTypes();
$statuses = consignmentStatuses();

// ── Vehicles marked consignment in Cars but with no deal record ──────────────
// This module lists from `consignments`. Changing a vehicle's type in the Cars
// module only sets cars.car_type, so those vehicles showed on the Cars tab but
// never here — leaving nowhere to enter the owner and commission details.
$missingDeals = [];
try {
    $missingDeals = $db->query("
        SELECT c.id, c.make, c.model, c.year, c.chassis_number, c.registration_number,
               c.car_type, c.owner_name, c.owner_phone, c.client_id, c.asking_price
        FROM cars c
        LEFT JOIN consignments cs ON cs.car_id = c.id
        WHERE c.car_type IN ('trade_in','sale_on_behalf')
          AND cs.id IS NULL
        ORDER BY c.id DESC
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (\Throwable $_) { $missingDeals = []; }

if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && ($_POST['action'] ?? '') === 'open_missing_deals'
    && $missingDeals && canWrite('trade_in')) {
    verifyCsrf();
    $made = 0;
    try {
        foreach ($missingDeals as $c) {
            $ref = consignmentNextRef($db, $c['car_type']);
            $db->prepare("INSERT INTO consignments
                    (car_id, deal_type, reference, owner_name, owner_phone, client_id,
                     listing_price, commission_type, commission_value, agreement_date,
                     status, notes, created_by)
                 VALUES (?,?,?,?,?,?,?, 'percent', 0, CURDATE(), 'active', ?, ?)")
               ->execute([
                   (int)$c['id'], $c['car_type'], $ref,
                   trim((string)$c['owner_name']) !== '' ? $c['owner_name'] : 'To be completed',
                   $c['owner_phone'] ?: null,
                   $c['client_id'] ?: null,
                   $c['asking_price'],
                   'Opened from the Cars module — owner and commission details still need completing.',
                   (int)(authUser()['id'] ?? 0),
               ]);
            $made++;
        }
        logActivity('create', 'trade_in', 0, "Opened {$made} consignment record(s) for vehicles already marked as consignment");
        setFlash('success', $made . ' record(s) opened. Complete the owner and commission details on each.');
    } catch (\Throwable $e) {
        error_log('trade_in open_missing_deals: ' . $e->getMessage());
        setFlash('error', 'Could not open records: ' . $e->getMessage());
    }
    redirect(BASE_URL . '/modules/trade_in/index.php');
}

// ── Tab / filters ─────────────────────────────────────────────────────────────
$tab = $_GET['tab'] ?? 'sale_on_behalf';
if (!isset($types[$tab])) $tab = 'sale_on_behalf';

$filterStatus = $_GET['status'] ?? '';
$filterSearch = trim($_GET['q'] ?? '');
if (!isset($statuses[$filterStatus])) $filterStatus = '';

$where  = ['cs.deal_type = ?'];
$params = [$tab];
if ($filterStatus) { $where[] = 'cs.status = ?'; $params[] = $filterStatus; }
if ($filterSearch) {
    $where[] = '(cs.owner_name LIKE ? OR cs.owner_phone LIKE ? OR cs.reference LIKE ?
                 OR c.make LIKE ? OR c.model LIKE ? OR c.registration_number LIKE ? OR c.chassis_number LIKE ?)';
    $like = "%{$filterSearch}%";
    $params = array_merge($params, [$like, $like, $like, $like, $like, $like, $like]);
}

try {
    $stmt = $db->prepare("
        SELECT cs.*,
               c.make, c.model, c.year, c.color, c.chassis_number, c.registration_number,
               c.body_type, c.mileage, c.asking_price, c.offer_price, c.show_on_website,
               c.status AS car_status,
               (SELECT file_path FROM car_images WHERE car_id = cs.car_id AND is_primary = 1 LIMIT 1) AS primary_image
        FROM consignments cs
        JOIN cars c ON c.id = cs.car_id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY FIELD(cs.status,'active','sold','withdrawn','expired'), cs.created_at DESC
    ");
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (\Throwable $e) {
    $rows = [];
}

// ── Tab counts + headline stats (per deal type) ───────────────────────────────
$counts = ['sale_on_behalf' => 0, 'trade_in' => 0];
try {
    foreach ($db->query("SELECT deal_type, COUNT(*) n FROM consignments GROUP BY deal_type") as $r) {
        $counts[$r['deal_type']] = (int)$r['n'];
    }
} catch (\Throwable $_) {}

$statActive = $statSold = 0;
$statValue  = $statCommission = $statOwed = 0.0;
foreach ($rows as $r) {
    if ($r['status'] === 'active') {
        $statActive++;
        $statValue += (float)($r['listing_price'] ?: 0);
    }
    if ($r['status'] === 'sold') {
        $statSold++;
        $statCommission += (float)($r['commission_amount'] ?: 0);
        $statOwed       += max(0, (float)($r['payout_amount'] ?: 0) - (float)($r['payout_paid'] ?: 0));
    }
}

$isFiltered = $filterStatus !== '' || $filterSearch !== '';
$pageTitle  = 'Trade-In & Sale on Behalf';
include __DIR__ . '/../../includes/header.php';
?>
<style>
.ti-banner{
    background:linear-gradient(135deg,#0c4a6e 0%,#0369a1 55%,#0ea5e9 100%);
    border-radius:16px; padding:28px 32px 24px; margin-bottom:24px; color:#fff;
    position:relative; overflow:hidden;
}
.ti-banner.trade{ background:linear-gradient(135deg,#78350f 0%,#b45309 55%,#f59e0b 100%); }
.ti-banner-title{ font-size:22px; font-weight:800; letter-spacing:-.4px; margin-bottom:2px; }
.ti-banner-sub{ font-size:13px; opacity:.78; margin-bottom:24px; }
.ti-stat-row{ display:grid; grid-template-columns:repeat(4,1fr); gap:12px; }
@media(max-width:768px){ .ti-stat-row{ grid-template-columns:repeat(2,1fr); } }
.ti-stat{
    background:rgba(255,255,255,.12); border:1px solid rgba(255,255,255,.18);
    border-radius:12px; padding:14px 16px;
}
.ti-stat-val{ font-size:20px; font-weight:900; line-height:1.15; letter-spacing:-.5px; color:#fff; }
.ti-stat-lbl{ font-size:11px; opacity:.72; margin-top:5px; text-transform:uppercase; letter-spacing:.6px; }
.ti-stat-icon{ font-size:18px; opacity:.5; float:right; margin-top:-2px; }

.filter-bar{
    background:#fff; border:1px solid #e2e8f0; border-radius:12px;
    padding:16px 20px; margin-bottom:20px; display:flex; flex-wrap:wrap; gap:10px; align-items:flex-end;
}
.filter-bar label{ font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.5px; color:#64748b; }
.filter-bar select, .filter-bar input[type=text]{
    border:1px solid #e2e8f0; border-radius:8px; padding:7px 12px;
    font-size:13px; color:#1e293b; background:#f8fafc; min-width:170px; outline:none;
}
.filter-group{ display:flex; flex-direction:column; gap:4px; }
.filter-actions{ margin-left:auto; display:flex; gap:8px; align-items:flex-end; }

.ti-grid{ display:grid; grid-template-columns:repeat(auto-fill,minmax(400px,1fr)); gap:18px; }
@media(max-width:500px){ .ti-grid{ grid-template-columns:1fr; } }
.ti-card{
    background:#fff; border:1px solid #e2e8f0; border-radius:16px; overflow:hidden;
    box-shadow:0 1px 8px rgba(0,0,0,.06); display:flex; flex-direction:column;
    transition:box-shadow .2s, transform .2s;
}
.ti-card:hover{ box-shadow:0 6px 24px rgba(0,0,0,.10); transform:translateY(-2px); }
.ti-card-top{ display:flex; align-items:stretch; }
.ti-img{ width:148px; flex-shrink:0; position:relative; overflow:hidden; background:#f1f5f9; }
.ti-img img{ width:100%; height:100%; object-fit:cover; display:block; }
.ti-img .no-img{ width:100%; height:100%; min-height:130px; display:flex; align-items:center; justify-content:center; font-size:36px; color:#cbd5e1; }
.ti-img-badge{
    position:absolute; top:8px; left:8px; color:#fff;
    font-size:9.5px; font-weight:700; padding:3px 8px; border-radius:20px; letter-spacing:.3px;
}
.ti-info{ flex:1; padding:14px 16px 12px; min-width:0; }
.ti-ref{ font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.7px; margin-bottom:2px; }
.ti-name{ font-size:15px; font-weight:800; color:#0f172a; letter-spacing:-.3px; line-height:1.25; }
.ti-meta{ font-size:11.5px; color:#64748b; margin-top:3px; }
.ti-price{ font-size:17px; font-weight:900; color:#1d4ed8; margin-top:6px; letter-spacing:-.3px; }
.ti-divider{ height:1px; background:#f1f5f9; }
.ti-section{ padding:12px 16px; }
.ti-section-label{ font-size:9.5px; font-weight:700; text-transform:uppercase; letter-spacing:.7px; color:#94a3b8; margin-bottom:8px; }
.ti-fields{ display:grid; grid-template-columns:1fr 1fr; gap:6px 16px; }
.ti-lbl{ font-size:10.5px; color:#94a3b8; font-weight:600; }
.ti-val{ font-size:12.5px; color:#0f172a; font-weight:600; margin-top:1px; }
.ti-money-row{ display:grid; grid-template-columns:1fr 1fr 1fr; gap:8px; padding:12px 16px; background:#fafafa; border-top:1px solid #f1f5f9; }
.ti-tile{ text-align:center; padding:8px 6px; border-radius:8px; }
.ti-tile.listing{ background:#eff6ff; }
.ti-tile.comm   { background:#f0fdf4; }
.ti-tile.payout { background:#fff7ed; }
.ti-tile-amt{ font-size:13px; font-weight:800; line-height:1.2; }
.ti-tile-lbl{ font-size:9.5px; color:#94a3b8; font-weight:600; text-transform:uppercase; letter-spacing:.4px; margin-top:3px; }
.ti-actions{ display:flex; flex-wrap:wrap; gap:5px; padding:10px 16px; border-top:1px solid #f1f5f9; background:#f8fafc; margin-top:auto; }

/* ── Dark mode ─────────────────────────────────────────────────────────────── */
[data-theme="dark"] .filter-bar{ background:var(--surface); border-color:var(--border); }
[data-theme="dark"] .filter-bar label{ color:var(--text-3); }
[data-theme="dark"] .filter-bar select,
[data-theme="dark"] .filter-bar input[type=text]{ background:var(--surface-alt); border-color:var(--border); color:var(--text); }
[data-theme="dark"] .ti-card{
    background:var(--surface); border-color:var(--border);
    box-shadow:0 12px 32px rgba(0,0,0,.5), inset 0 1px 0 rgba(255,255,255,.04);
}
[data-theme="dark"] .ti-card:hover{
    box-shadow:0 24px 56px rgba(0,0,0,.6), 0 0 44px rgba(14,165,233,.28), 0 0 30px rgba(59,130,246,.18);
}
[data-theme="dark"] .ti-img{ background:var(--surface-alt); }
[data-theme="dark"] .ti-img .no-img{ color:#3b4f76; }
[data-theme="dark"] .ti-name{ color:var(--text); }
[data-theme="dark"] .ti-price{ color:#7cabf8; }
[data-theme="dark"] .ti-divider{ background:var(--border); }
[data-theme="dark"] .ti-val{ color:var(--text); }
[data-theme="dark"] .ti-money-row{ background:var(--surface-alt); border-top-color:var(--border); }
[data-theme="dark"] .ti-tile.listing{ background:rgba(59,130,246,.12); }
[data-theme="dark"] .ti-tile.comm   { background:rgba(34,197,94,.12); }
[data-theme="dark"] .ti-tile.payout { background:rgba(249,115,22,.12); }
[data-theme="dark"] .ti-actions{ background:var(--surface-alt); border-top-color:var(--border); }
</style>

<?php if (!tradeInEnumReady($db)): ?>
<div class="alert alert-danger">
    <i class="fa fa-triangle-exclamation me-1"></i>
    <strong>Database not ready.</strong>
    The <code>cars.car_type</code> column could not be widened automatically, so new
    trade-in / sale-on-behalf vehicles cannot be saved. Ask your database administrator
    to run:
    <pre class="mt-2 mb-0" style="font-size:11.5px;white-space:pre-wrap">ALTER TABLE cars MODIFY COLUMN car_type
  ENUM('inventory','client','trade_in','sale_on_behalf') DEFAULT 'inventory';</pre>
</div>
<?php endif; ?>

<!-- ── Banner ──────────────────────────────────────────────────────────────── -->
<div class="ti-banner <?= $tab === 'trade_in' ? 'trade' : '' ?>">
    <div class="ti-banner-title">
        <i class="fa <?= $types[$tab]['icon'] ?> me-2"></i><?= $types[$tab]['label'] ?>
    </div>
    <div class="ti-banner-sub">
        <?= $tab === 'trade_in'
            ? 'Vehicles taken in part-exchange against a purchase'
            : 'Customer vehicles we market and sell on their behalf for a commission' ?>
    </div>
    <div class="ti-stat-row">
        <div class="ti-stat">
            <span class="ti-stat-icon"><i class="fa fa-car"></i></span>
            <div class="ti-stat-val"><?= $statActive ?></div>
            <div class="ti-stat-lbl">Active</div>
        </div>
        <div class="ti-stat">
            <span class="ti-stat-icon"><i class="fa fa-money-bill-wave"></i></span>
            <div class="ti-stat-val" style="font-size:<?= strlen(number_format($statValue,0)) > 9 ? '15' : '18' ?>px">
                KES <?= number_format($statValue, 0) ?>
            </div>
            <div class="ti-stat-lbl">Listed Value</div>
        </div>
        <div class="ti-stat">
            <span class="ti-stat-icon"><i class="fa fa-percent"></i></span>
            <div class="ti-stat-val" style="font-size:<?= strlen(number_format($statCommission,0)) > 9 ? '15' : '18' ?>px">
                KES <?= number_format($statCommission, 0) ?>
            </div>
            <div class="ti-stat-lbl">Commission Earned</div>
        </div>
        <div class="ti-stat">
            <span class="ti-stat-icon"><i class="fa fa-hand-holding-dollar"></i></span>
            <div class="ti-stat-val" style="font-size:<?= strlen(number_format($statOwed,0)) > 9 ? '15' : '18' ?>px">
                KES <?= number_format($statOwed, 0) ?>
            </div>
            <div class="ti-stat-lbl">Owed to Owners</div>
        </div>
    </div>
</div>

<?php if ($missingDeals): ?>
<div class="alert alert-warning py-2">
    <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
        <span class="small">
            <i class="fa fa-triangle-exclamation me-1"></i>
            <strong><?= count($missingDeals) ?></strong> vehicle<?= count($missingDeals) !== 1 ? 's are' : ' is' ?>
            marked as Trade-In / Sale on Behalf in the Cars module but <?= count($missingDeals) !== 1 ? 'have' : 'has' ?>
            no deal record here, so the owner and commission details cannot be entered.
        </span>
        <?php if (canWrite('trade_in')): ?>
        <form method="POST" class="d-inline"
              onsubmit="return confirm('Open <?= count($missingDeals) ?> record(s)?\n\nEach vehicle gets a deal you can then complete with owner and commission details.\n\nNothing is deleted.')">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="open_missing_deals">
            <button type="submit" class="btn btn-sm btn-warning">
                <i class="fa fa-plus me-1"></i>Open records for them
            </button>
        </form>
        <?php endif; ?>
    </div>
    <div class="small text-muted mt-1">
        <?php foreach (array_slice($missingDeals, 0, 5) as $md): ?>
            <span class="me-2">• <?= e(trim($md['year'].' '.$md['make'].' '.$md['model'])) ?>
                (<?= e($md['registration_number'] ?: $md['chassis_number']) ?>)</span>
        <?php endforeach; ?>
        <?php if (count($missingDeals) > 5): ?><span>and <?= count($missingDeals) - 5 ?> more…</span><?php endif; ?>
    </div>
</div>
<?php endif; ?>

<!-- ── Tabs + add ──────────────────────────────────────────────────────────── -->
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div class="d-flex gap-2 flex-wrap">
        <?php foreach ($types as $key => $t): ?>
        <a href="?tab=<?= $key ?>"
           class="btn btn-sm <?= $tab === $key ? 'btn-primary' : 'btn-outline-secondary' ?>">
            <i class="fa <?= $t['icon'] ?> me-1"></i><?= $t['label'] ?>
            <span class="badge <?= $tab === $key ? 'bg-white text-primary' : 'bg-secondary' ?> ms-1"><?= $counts[$key] ?></span>
        </a>
        <?php endforeach; ?>
    </div>
    <?php if (canWrite('trade_in')): ?>
    <a href="add.php?type=<?= $tab ?>" class="btn btn-primary btn-sm">
        <i class="fa fa-plus me-1"></i>New <?= $types[$tab]['label'] ?>
    </a>
    <?php endif; ?>
</div>

<!-- ── Filters ─────────────────────────────────────────────────────────────── -->
<form method="GET" class="filter-bar">
    <input type="hidden" name="tab" value="<?= e($tab) ?>">
    <div class="filter-group">
        <label>Search</label>
        <input type="text" name="q" value="<?= e($filterSearch) ?>" placeholder="Owner, reg no, make, ref…">
    </div>
    <div class="filter-group">
        <label>Status</label>
        <select name="status">
            <option value="">All statuses</option>
            <?php foreach ($statuses as $k => $s): ?>
            <option value="<?= $k ?>" <?= $filterStatus === $k ? 'selected' : '' ?>><?= $s['label'] ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="filter-actions">
        <button type="submit" class="btn btn-sm btn-primary" style="border-radius:8px;padding:7px 16px">
            <i class="fa fa-filter me-1"></i>Filter
        </button>
        <?php if ($isFiltered): ?>
        <a href="?tab=<?= $tab ?>" class="btn btn-sm btn-outline-secondary" style="border-radius:8px;padding:7px 16px">Clear</a>
        <?php endif; ?>
    </div>
</form>

<div class="d-flex align-items-center gap-2 mb-3 flex-wrap">
    <span class="fw-semibold" style="font-size:13px"><?= count($rows) ?> record<?= count($rows) === 1 ? '' : 's' ?></span>
    <?php if ($isFiltered): ?>
    <span class="badge bg-primary-subtle text-primary border border-primary-subtle" style="font-size:11px">Filtered</span>
    <?php endif; ?>
</div>

<!-- ── Listing ─────────────────────────────────────────────────────────────── -->
<?php if (empty($rows)): ?>
<div class="card">
    <div class="card-body text-center py-5">
        <div style="font-size:48px;color:#cbd5e1;margin-bottom:14px">
            <i class="fa <?= $types[$tab]['icon'] ?>"></i>
        </div>
        <h6 class="fw-bold mb-1">No <?= strtolower($types[$tab]['label']) ?> records<?= $isFiltered ? ' match your filters' : ' yet' ?></h6>
        <p class="text-muted small mb-3">
            <?= $tab === 'trade_in'
                ? 'Record a vehicle taken in part-exchange to track its valuation and allowance.'
                : 'Register a customer vehicle to market it on their behalf and track your commission.' ?>
        </p>
        <?php if (canWrite('trade_in') && !$isFiltered): ?>
        <a href="add.php?type=<?= $tab ?>" class="btn btn-primary btn-sm">
            <i class="fa fa-plus me-1"></i>Add the first one
        </a>
        <?php endif; ?>
    </div>
</div>
<?php else: ?>
<div class="ti-grid">
    <?php foreach ($rows as $r):
        $st        = $statuses[$r['status']] ?? $statuses['active'];
        $img       = $r['primary_image'] ? thumbUrl('cars', $r['primary_image']) : null;
        $commission = $r['status'] === 'sold' ? (float)($r['commission_amount'] ?: 0) : consignmentCommission($r);
        $payout     = $r['status'] === 'sold' ? (float)($r['payout_amount'] ?: 0)     : consignmentPayout($r);
        $outstanding = max(0, $payout - (float)($r['payout_paid'] ?: 0));
        $expiring   = $r['status'] === 'active' && $r['expiry_date']
                      && strtotime($r['expiry_date']) < strtotime('+14 days');
    ?>
    <div class="ti-card">
        <div class="ti-card-top">
            <div class="ti-img">
                <span class="ti-img-badge" style="background:<?= $st['color'] ?>"><?= strtoupper($st['label']) ?></span>
                <?php if ($img): ?>
                    <img src="<?= e($img) ?>" alt="<?= e($r['make'] . ' ' . $r['model']) ?>" loading="lazy">
                <?php else: ?>
                    <div class="no-img"><i class="fa fa-car-side"></i></div>
                <?php endif; ?>
            </div>
            <div class="ti-info">
                <div class="ti-ref" style="color:<?= $types[$tab]['color'] ?>">
                    <?= e($r['reference'] ?: $types[$tab]['label']) ?>
                </div>
                <div class="ti-name"><?= e($r['year'] . ' ' . $r['make'] . ' ' . $r['model']) ?></div>
                <div class="ti-meta">
                    <?= e($r['registration_number'] ?: $r['chassis_number']) ?>
                    <?= $r['mileage'] ? ' · ' . number_format((int)$r['mileage']) . ' km' : '' ?>
                </div>
                <div class="ti-price">
                    <?php if ($tab === 'trade_in'): ?>
                        KES <?= number_format((float)($r['trade_in_value'] ?: 0), 0) ?>
                        <span class="text-muted fw-normal" style="font-size:11px">allowance</span>
                    <?php else: ?>
                        KES <?= number_format((float)($r['listing_price'] ?: 0), 0) ?>
                    <?php endif; ?>
                </div>
                <?php if ($r['show_on_website'] && $tab === 'sale_on_behalf' && $r['status'] === 'active'): ?>
                <span class="badge bg-success-subtle text-success border border-success-subtle mt-1" style="font-size:10px">
                    <i class="fa fa-globe me-1"></i>Live on website
                </span>
                <?php endif; ?>
            </div>
        </div>

        <div class="ti-divider"></div>
        <div class="ti-section">
            <div class="ti-section-label">Vehicle Owner</div>
            <div class="ti-fields">
                <div>
                    <div class="ti-lbl">Name</div>
                    <div class="ti-val"><?= e($r['owner_name']) ?></div>
                </div>
                <div>
                    <div class="ti-lbl">Phone</div>
                    <div class="ti-val"><?= e($r['owner_phone'] ?: '—') ?></div>
                </div>
                <?php if ($tab === 'sale_on_behalf'): ?>
                <div>
                    <div class="ti-lbl">Agreement</div>
                    <div class="ti-val"><?= $r['agreement_date'] ? fmtDate($r['agreement_date']) : '—' ?></div>
                </div>
                <div>
                    <div class="ti-lbl">Expires</div>
                    <div class="ti-val <?= $expiring ? 'text-danger' : '' ?>">
                        <?= $r['expiry_date'] ? fmtDate($r['expiry_date']) : '—' ?>
                        <?php if ($expiring): ?><i class="fa fa-triangle-exclamation ms-1"></i><?php endif; ?>
                    </div>
                </div>
                <?php else: ?>
                <div>
                    <div class="ti-lbl">Valuation</div>
                    <div class="ti-val">KES <?= number_format((float)($r['valuation_amount'] ?: 0), 0) ?></div>
                </div>
                <div>
                    <div class="ti-lbl">Received</div>
                    <div class="ti-val"><?= $r['agreement_date'] ? fmtDate($r['agreement_date']) : '—' ?></div>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($tab === 'sale_on_behalf'): ?>
        <div class="ti-money-row">
            <div class="ti-tile listing">
                <div class="ti-tile-amt" style="color:#1d4ed8">
                    <?= number_format($r['status'] === 'sold' ? (float)$r['sold_price'] : (float)($r['listing_price'] ?: 0), 0) ?>
                </div>
                <div class="ti-tile-lbl"><?= $r['status'] === 'sold' ? 'Sold For' : 'Listed' ?></div>
            </div>
            <div class="ti-tile comm">
                <div class="ti-tile-amt" style="color:#15803d"><?= number_format($commission, 0) ?></div>
                <div class="ti-tile-lbl">Commission</div>
            </div>
            <div class="ti-tile payout">
                <div class="ti-tile-amt" style="color:#c2410c"><?= number_format($r['status'] === 'sold' ? $outstanding : $payout, 0) ?></div>
                <div class="ti-tile-lbl"><?= $r['status'] === 'sold' ? 'Owner Due' : 'Owner Gets' ?></div>
            </div>
        </div>
        <?php endif; ?>

        <div class="ti-actions">
            <a href="view.php?id=<?= (int)$r['id'] ?>" class="btn btn-sm btn-primary">
                <i class="fa fa-eye me-1"></i>View
            </a>
            <a href="<?= BASE_URL ?>/modules/cars/view.php?id=<?= (int)$r['car_id'] ?>" class="btn btn-sm btn-outline-secondary">
                <i class="fa fa-car me-1"></i>Vehicle
            </a>
            <?php if (canWrite('trade_in')): ?>
            <a href="edit.php?id=<?= (int)$r['id'] ?>" class="btn btn-sm btn-outline-secondary">
                <i class="fa fa-pen"></i>
            </a>
            <?php endif; ?>
            <?php if ($tab === 'sale_on_behalf' && $r['status'] === 'active' && $r['show_on_website']): ?>
            <a href="<?= BASE_URL ?>/showroom/view.php?id=<?= (int)$r['car_id'] ?>" target="_blank"
               class="btn btn-sm btn-outline-success ms-auto" title="View public listing">
                <i class="fa fa-globe"></i>
            </a>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php include __DIR__ . '/../../includes/footer.php'; ?>

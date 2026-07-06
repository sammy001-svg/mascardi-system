<?php
require_once __DIR__ . '/../../includes/functions.php';
requireRole('supervisor');
$pageTitle = 'Supervisor Dashboard';
$db  = getDB();
$me  = authUser();
$locId = supervisorLocationId();

if (!$locId) {
    include __DIR__ . '/../../includes/header.php';
    echo '<div class="alert alert-warning m-4"><i class="fa fa-triangle-exclamation me-2"></i>
          No location has been assigned to your account. Please contact your administrator.</div>';
    include __DIR__ . '/../../includes/footer.php';
    exit;
}

// ── Location ────────────────────────────────────────────────────────────────
$locStmt = $db->prepare("SELECT * FROM locations WHERE id=?");
$locStmt->execute([$locId]);
$location = $locStmt->fetch();

// ── Fleet by status ─────────────────────────────────────────────────────────
$carsStmt = $db->prepare("SELECT status, COUNT(*) AS cnt FROM cars WHERE location_id=? GROUP BY status");
$carsStmt->execute([$locId]);
$carsByStatus = [];
$totalCars = 0;
foreach ($carsStmt->fetchAll() as $r) {
    $carsByStatus[$r['status']] = (int)$r['cnt'];
    $totalCars += (int)$r['cnt'];
}
$inWorkshop    = $carsByStatus['in_workshop'] ?? 0;
$availableCars = ($carsByStatus['completed'] ?? 0) + ($carsByStatus['arrived'] ?? 0);

// ── Revenue this month + last month trend ────────────────────────────────────
$revMonth = 0; $revLastMonth = 0;
try {
    $rmq = $db->prepare("SELECT COALESCE(SUM(i.total),0) FROM invoices i
        LEFT JOIN cars c ON c.id=i.car_id
        WHERE (c.location_id=? OR i.location_id=?) AND i.status='paid'
          AND MONTH(i.created_at)=MONTH(NOW()) AND YEAR(i.created_at)=YEAR(NOW())");
    $rmq->execute([$locId, $locId]);
    $revMonth = (float)$rmq->fetchColumn();

    $lm = date('Y-m', strtotime('-1 month'));
    $rlq = $db->prepare("SELECT COALESCE(SUM(i.total),0) FROM invoices i
        LEFT JOIN cars c ON c.id=i.car_id
        WHERE (c.location_id=? OR i.location_id=?) AND i.status='paid'
          AND DATE_FORMAT(i.created_at,'%Y-%m')=?");
    $rlq->execute([$locId, $locId, $lm]);
    $revLastMonth = (float)$rlq->fetchColumn();
} catch (\Throwable $_) {}
$revTrend = ($revLastMonth > 0) ? round((($revMonth - $revLastMonth) / $revLastMonth) * 100, 1) : null;

// ── Cars sold this month ─────────────────────────────────────────────────────
$carsSoldMonth = 0;
try {
    $csq = $db->prepare("SELECT COUNT(*) FROM car_sales cs
        JOIN cars c ON c.id=cs.car_id
        WHERE c.location_id=? AND cs.status='active'
          AND MONTH(cs.sale_date)=MONTH(NOW()) AND YEAR(cs.sale_date)=YEAR(NOW())");
    $csq->execute([$locId]);
    $carsSoldMonth = (int)$csq->fetchColumn();
} catch (\Throwable $_) {
    // fallback: count status=sold/delivered updated this month
    try {
        $csq2 = $db->prepare("SELECT COUNT(*) FROM cars WHERE location_id=? AND status IN ('sold','delivered')
            AND MONTH(updated_at)=MONTH(NOW()) AND YEAR(updated_at)=YEAR(NOW())");
        $csq2->execute([$locId]);
        $carsSoldMonth = (int)$csq2->fetchColumn();
    } catch (\Throwable $_) {}
}

// ── Pending bookings ─────────────────────────────────────────────────────────
$pendingBookings = 0; $bookingsToday = 0;
try {
    $pbq = $db->prepare("SELECT COUNT(*) FROM service_bookings sb
        LEFT JOIN cars c ON c.id=sb.car_id
        WHERE (c.location_id=? OR sb.location_id=?) AND sb.status='pending'");
    $pbq->execute([$locId, $locId]);
    $pendingBookings = (int)$pbq->fetchColumn();

    $btq = $db->prepare("SELECT COUNT(*) FROM service_bookings sb
        LEFT JOIN cars c ON c.id=sb.car_id
        WHERE (c.location_id=? OR sb.location_id=?) AND sb.preferred_date=CURDATE()");
    $btq->execute([$locId, $locId]);
    $bookingsToday = (int)$btq->fetchColumn();
} catch (\Throwable $_) {}

// ── Total CRM leads / clients at this location ───────────────────────────────
$totalLeads = 0;
try {
    $clq = $db->prepare("SELECT COUNT(*) FROM crm_leads
        WHERE assigned_to IN (SELECT id FROM users WHERE location_id=?)");
    $clq->execute([$locId]);
    $totalLeads = (int)$clq->fetchColumn();
} catch (\Throwable $_) {}

// ── Team count ───────────────────────────────────────────────────────────────
$teamCount = 0;
try {
    $tq = $db->prepare("SELECT COUNT(*) FROM users WHERE location_id=? AND status='active' AND id != ?");
    $tq->execute([$locId, $me['id']]);
    $teamCount = (int)$tq->fetchColumn();
} catch (\Throwable $_) {}

// ── Recent cars ──────────────────────────────────────────────────────────────
$recentCars = $db->prepare("SELECT c.*,
    (SELECT ci.file_path FROM car_images ci WHERE ci.car_id=c.id AND ci.is_primary=1 LIMIT 1) AS thumb
    FROM cars c WHERE c.location_id=? ORDER BY c.updated_at DESC LIMIT 8");
$recentCars->execute([$locId]);
$recentCars = $recentCars->fetchAll();

// ── Upcoming bookings ────────────────────────────────────────────────────────
$upcomingBookings = [];
try {
    $ubq = $db->prepare("SELECT sb.*, c.make, c.model
        FROM service_bookings sb LEFT JOIN cars c ON c.id=sb.car_id
        WHERE (c.location_id=? OR sb.location_id=?)
          AND sb.status IN ('pending','confirmed')
          AND (sb.preferred_date IS NULL OR sb.preferred_date >= CURDATE())
        ORDER BY sb.preferred_date ASC, sb.created_at ASC LIMIT 6");
    $ubq->execute([$locId, $locId]);
    $upcomingBookings = $ubq->fetchAll();
} catch (\Throwable $_) {}

// ── Recent payments ──────────────────────────────────────────────────────────
$recentPayments = [];
try {
    $rpq = $db->prepare("SELECT p.*, c.make, c.model FROM payments p
        LEFT JOIN invoices i ON i.id=p.invoice_id
        LEFT JOIN cars c ON c.id=i.car_id
        WHERE (c.location_id=? OR i.location_id=?) AND p.status='confirmed'
        ORDER BY p.created_at DESC LIMIT 6");
    $rpq->execute([$locId, $locId]);
    $recentPayments = $rpq->fetchAll();
} catch (\Throwable $_) {}

// ── Revenue chart — last 6 months ────────────────────────────────────────────
$revChartLabels = []; $revChartData = [];
for ($i = 5; $i >= 0; $i--) {
    $ym = date('Y-m', strtotime("-{$i} months"));
    $revChartLabels[] = date('M', strtotime($ym . '-01'));
    try {
        $rs = $db->prepare("SELECT COALESCE(SUM(i.total),0) FROM invoices i
            LEFT JOIN cars c ON c.id=i.car_id
            WHERE (c.location_id=? OR i.location_id=?) AND i.status='paid'
              AND DATE_FORMAT(i.created_at,'%Y-%m')=?");
        $rs->execute([$locId, $locId, $ym]);
        $revChartData[] = (float)$rs->fetchColumn();
    } catch (\Throwable $_) { $revChartData[] = 0; }
}
$jsRevLabels = json_encode($revChartLabels);
$jsRevData   = json_encode($revChartData);

// ── Status order + meta ──────────────────────────────────────────────────────
$statusMeta = [
    'arrived'       => ['label' => 'Arrived',       'color' => '#0284c7'],
    'in_assessment' => ['label' => 'In Assessment',  'color' => '#8b5cf6'],
    'in_workshop'   => ['label' => 'In Workshop',    'color' => '#f59e0b'],
    'in_transit'    => ['label' => 'In Transit',     'color' => '#d97706'],
    'completed'     => ['label' => 'Ready for Sale', 'color' => '#10b981'],
    'reserved'      => ['label' => 'Reserved',       'color' => '#6366f1'],
    'sold'          => ['label' => 'Sold',           'color' => '#0f172a'],
    'delivered'     => ['label' => 'Delivered',      'color' => '#475569'],
];

// ── Chart.js script ──────────────────────────────────────────────────────────
$extraJs = <<<JS
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
(function(){
    var rc = document.getElementById('supRevChart');
    if (!rc) return;
    var ctx = rc.getContext('2d');
    var grad = ctx.createLinearGradient(0, 0, 0, 260);
    grad.addColorStop(0, 'rgba(6,182,212,0.28)');
    grad.addColorStop(1, 'rgba(6,182,212,0.0)');
    new Chart(rc, {
        type: 'line',
        data: {
            labels: {$jsRevLabels},
            datasets: [{
                data: {$jsRevData},
                fill: true,
                backgroundColor: grad,
                borderColor: '#06b6d4',
                borderWidth: 2.5,
                pointBackgroundColor: '#fff',
                pointBorderColor: '#06b6d4',
                pointBorderWidth: 2,
                pointRadius: 4,
                pointHoverRadius: 6,
                tension: 0.42
            }]
        },
        options: {
            responsive: true,
            plugins: {
                legend: { display: false },
                tooltip: {
                    backgroundColor: '#0d1b2a',
                    titleColor: '#94a3b8',
                    bodyColor: '#f1f5f9',
                    padding: 10,
                    callbacks: {
                        label: function(c) {
                            var v = c.raw;
                            return ' KES ' + (v >= 1e6 ? (v/1e6).toFixed(2)+'M' : v >= 1e3 ? (v/1e3).toFixed(1)+'K' : v.toFixed(0));
                        }
                    }
                }
            },
            scales: {
                x: { grid: { display: false }, ticks: { color: '#94a3b8' } },
                y: {
                    beginAtZero: true,
                    grid: { color: 'rgba(148,163,184,0.1)' },
                    ticks: {
                        color: '#94a3b8',
                        callback: function(v) {
                            return v >= 1e6 ? (v/1e6).toFixed(1)+'M' : v >= 1e3 ? (v/1e3).toFixed(0)+'K' : v;
                        }
                    }
                }
            }
        }
    });
}());
</script>
JS;

include __DIR__ . '/../../includes/header.php';
?>

<style>
/* ── Supervisor Dashboard Tokens ────────────────────────────────────────── */
:root {
    --sd-ink:    #0d1b2a;
    --sd-steel:  #1a2f4e;
    --sd-cyan:   #06b6d4;
    --sd-amber:  #f59e0b;
    --sd-green:  #10b981;
    --sd-purple: #8b5cf6;
    --sd-red:    #ef4444;
    --sd-muted:  #64748b;
    --sd-border: #e2e8f0;
    --sd-bg:     #f1f5f9;
    --sd-card:   #ffffff;
    --sd-section-label-color: #64748b;
}
@media (prefers-color-scheme: dark) {
    :root {
        --sd-bg:     #0a1120;
        --sd-card:   #111d30;
        --sd-border: #1e3050;
        --sd-muted:  #94a3b8;
        --sd-section-label-color: #94a3b8;
    }
}
:root[data-theme="dark"] {
    --sd-bg:     #0a1120;
    --sd-card:   #111d30;
    --sd-border: #1e3050;
    --sd-muted:  #94a3b8;
    --sd-section-label-color: #94a3b8;
}
:root[data-theme="light"] {
    --sd-bg:     #f1f5f9;
    --sd-card:   #ffffff;
    --sd-border: #e2e8f0;
    --sd-muted:  #64748b;
    --sd-section-label-color: #64748b;
}

/* ── Hero ────────────────────────────────────────────────────────────────── */
.sd-hero {
    background: linear-gradient(135deg, var(--sd-ink) 0%, var(--sd-steel) 100%);
    border-radius: 14px;
    padding: 28px 32px;
    margin-bottom: 28px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 24px;
    flex-wrap: wrap;
    position: relative;
    overflow: hidden;
}
.sd-hero::before {
    content: '';
    position: absolute;
    inset: 0;
    background: radial-gradient(ellipse 60% 80% at 80% 50%, rgba(6,182,212,0.10) 0%, transparent 70%);
    pointer-events: none;
}
.sd-hero-left { flex: 1; min-width: 220px; }
.sd-hero-eyebrow {
    font-size: 10.5px;
    font-weight: 600;
    letter-spacing: 0.16em;
    text-transform: uppercase;
    color: var(--sd-cyan);
    margin-bottom: 6px;
    display: flex;
    align-items: center;
    gap: 7px;
}
.sd-hero-title {
    font-size: 1.7rem;
    font-weight: 700;
    color: #f8fafc;
    line-height: 1.15;
    margin: 0 0 6px;
    text-wrap: balance;
}
.sd-hero-sub {
    font-size: 13px;
    color: #94a3b8;
    margin: 0;
}
.sd-hero-stats {
    display: flex;
    align-items: center;
    gap: 0;
    flex-wrap: wrap;
}
.sd-hero-stat {
    text-align: center;
    padding: 0 22px;
    border-right: 1px solid rgba(255,255,255,0.1);
}
.sd-hero-stat:last-child { border-right: none; }
.sd-hero-stat-val {
    font-size: 1.6rem;
    font-weight: 700;
    color: var(--sd-cyan);
    line-height: 1;
    font-variant-numeric: tabular-nums;
    letter-spacing: -0.02em;
}
.sd-hero-stat-lbl {
    font-size: 10.5px;
    color: #94a3b8;
    margin-top: 4px;
    letter-spacing: 0.05em;
    text-transform: uppercase;
    font-weight: 500;
}

/* ── Section label ───────────────────────────────────────────────────────── */
.sd-section {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 14px;
    margin-top: 4px;
}
.sd-section-label {
    font-size: 10px;
    font-weight: 700;
    letter-spacing: 0.16em;
    text-transform: uppercase;
    color: var(--sd-section-label-color);
    white-space: nowrap;
}
.sd-section-rule {
    flex: 1;
    height: 1px;
    background: var(--sd-border);
}

/* ── KPI Tiles ───────────────────────────────────────────────────────────── */
.sd-tile {
    background: var(--sd-card);
    border: 1px solid var(--sd-border);
    border-radius: 12px;
    padding: 0;
    overflow: hidden;
    transition: box-shadow 0.18s, transform 0.18s;
    text-decoration: none;
    display: block;
    color: inherit;
}
a.sd-tile:hover {
    box-shadow: 0 6px 24px rgba(0,0,0,0.09);
    transform: translateY(-2px);
    color: inherit;
    text-decoration: none;
}
.sd-tile-top {
    height: 4px;
    width: 100%;
}
.sd-tile-body {
    padding: 18px 20px 16px;
    display: flex;
    align-items: flex-start;
    gap: 14px;
}
.sd-tile-icon {
    width: 42px;
    height: 42px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 17px;
    flex-shrink: 0;
}
.sd-tile-info { flex: 1; min-width: 0; }
.sd-tile-label {
    font-size: 11px;
    font-weight: 600;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    color: var(--sd-muted);
    margin-bottom: 4px;
}
.sd-tile-value {
    font-size: 2.1rem;
    font-weight: 700;
    line-height: 1;
    font-variant-numeric: tabular-nums;
    letter-spacing: -0.03em;
    color: inherit;
}
.sd-tile-value-sm {
    font-size: 1.35rem;
    letter-spacing: -0.02em;
}
.sd-tile-sub {
    font-size: 11.5px;
    color: var(--sd-muted);
    margin-top: 5px;
}
.sd-trend {
    display: inline-flex;
    align-items: center;
    gap: 3px;
    font-size: 11px;
    font-weight: 600;
    padding: 2px 7px;
    border-radius: 20px;
}
.sd-trend-up   { background: #dcfce7; color: #15803d; }
.sd-trend-down { background: #fee2e2; color: #b91c1c; }
.sd-trend-flat { background: #f1f5f9; color: #64748b; }
@media (prefers-color-scheme: dark) {
    .sd-trend-up   { background: rgba(16,185,129,.18); color: #34d399; }
    .sd-trend-down { background: rgba(239,68,68,.18);  color: #f87171; }
    .sd-trend-flat { background: rgba(100,116,139,.18); color: #94a3b8; }
}
:root[data-theme="dark"] .sd-trend-up   { background: rgba(16,185,129,.18); color: #34d399; }
:root[data-theme="dark"] .sd-trend-down { background: rgba(239,68,68,.18);  color: #f87171; }
:root[data-theme="dark"] .sd-trend-flat { background: rgba(100,116,139,.18); color: #94a3b8; }
:root[data-theme="light"] .sd-trend-up   { background: #dcfce7; color: #15803d; }
:root[data-theme="light"] .sd-trend-down { background: #fee2e2; color: #b91c1c; }
:root[data-theme="light"] .sd-trend-flat { background: #f1f5f9; color: #64748b; }

/* ── Panel cards ─────────────────────────────────────────────────────────── */
.sd-panel {
    background: var(--sd-card);
    border: 1px solid var(--sd-border);
    border-radius: 12px;
    overflow: hidden;
    height: 100%;
}
.sd-panel-header {
    padding: 14px 20px;
    border-bottom: 1px solid var(--sd-border);
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
}
.sd-panel-title {
    font-size: 13px;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 8px;
    margin: 0;
}
.sd-panel-title i { color: var(--sd-cyan); font-size: 13px; }

/* ── Fleet status bars ───────────────────────────────────────────────────── */
.sd-fleet-bars { padding: 16px 20px; }
.sd-fleet-row {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 12px;
}
.sd-fleet-row:last-child { margin-bottom: 0; }
.sd-fleet-lbl {
    font-size: 12px;
    color: var(--sd-muted);
    width: 110px;
    flex-shrink: 0;
    font-weight: 500;
    white-space: nowrap;
}
.sd-fleet-track {
    flex: 1;
    height: 8px;
    background: var(--sd-border);
    border-radius: 99px;
    overflow: hidden;
}
.sd-fleet-fill {
    height: 100%;
    border-radius: 99px;
    transition: width 0.8s cubic-bezier(.22,.68,0,1.2);
}
.sd-fleet-cnt {
    font-size: 13px;
    font-weight: 700;
    font-variant-numeric: tabular-nums;
    width: 28px;
    text-align: right;
    flex-shrink: 0;
}

/* ── Booking timeline ────────────────────────────────────────────────────── */
.sd-timeline { padding: 4px 0; }
.sd-tl-item {
    display: flex;
    gap: 14px;
    padding: 12px 20px;
    border-bottom: 1px solid var(--sd-border);
    align-items: flex-start;
}
.sd-tl-item:last-child { border-bottom: none; }
.sd-tl-dot {
    width: 10px;
    height: 10px;
    border-radius: 50%;
    flex-shrink: 0;
    margin-top: 4px;
}
.sd-tl-body { flex: 1; min-width: 0; }
.sd-tl-name {
    font-size: 13.5px;
    font-weight: 600;
    margin-bottom: 2px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.sd-tl-meta { font-size: 11.5px; color: var(--sd-muted); }
.sd-tl-right { text-align: right; flex-shrink: 0; }
.sd-tl-date {
    font-size: 12px;
    font-weight: 600;
    color: var(--sd-cyan);
    white-space: nowrap;
}

/* ── Recent cars grid ────────────────────────────────────────────────────── */
.sd-cars-table th {
    font-size: 10.5px;
    font-weight: 700;
    letter-spacing: 0.1em;
    text-transform: uppercase;
    color: var(--sd-muted);
    border-bottom-width: 1px;
    padding: 10px 12px;
}
.sd-cars-table td { padding: 10px 12px; vertical-align: middle; }
.sd-cars-table tr:last-child td { border-bottom: 0; }
.sd-car-thumb {
    width: 44px;
    height: 36px;
    border-radius: 6px;
    object-fit: cover;
    border: 1px solid var(--sd-border);
}
.sd-car-thumb-ph {
    width: 44px;
    height: 36px;
    border-radius: 6px;
    background: var(--sd-border);
    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--sd-muted);
    font-size: 13px;
}

/* ── Payments table ──────────────────────────────────────────────────────── */
.sd-pay-table th {
    font-size: 10.5px;
    font-weight: 700;
    letter-spacing: 0.1em;
    text-transform: uppercase;
    color: var(--sd-muted);
    border-bottom-width: 1px;
    padding: 10px 14px;
    background: transparent;
}
.sd-pay-table td { padding: 11px 14px; vertical-align: middle; }
.sd-pay-table tr:last-child td { border-bottom: 0; }
.sd-method-badge {
    font-size: 10.5px;
    font-weight: 600;
    padding: 3px 8px;
    border-radius: 20px;
    background: rgba(6,182,212,0.1);
    color: #0891b2;
    letter-spacing: 0.04em;
    white-space: nowrap;
}
@media (prefers-color-scheme: dark) {
    .sd-method-badge { background: rgba(6,182,212,0.15); color: #22d3ee; }
}
:root[data-theme="dark"] .sd-method-badge { background: rgba(6,182,212,0.15); color: #22d3ee; }
:root[data-theme="light"] .sd-method-badge { background: rgba(6,182,212,0.1); color: #0891b2; }

/* ── Empty state ─────────────────────────────────────────────────────────── */
.sd-empty {
    text-align: center;
    padding: 40px 20px;
    color: var(--sd-muted);
    font-size: 13px;
}
.sd-empty i { font-size: 28px; margin-bottom: 10px; display: block; opacity: 0.3; }

@media (max-width: 575px) {
    .sd-hero { padding: 20px 18px; }
    .sd-hero-title { font-size: 1.3rem; }
    .sd-hero-stat { padding: 0 14px; }
    .sd-hero-stat-val { font-size: 1.3rem; }
    .sd-tile-value { font-size: 1.75rem; }
}
</style>

<?php
// ── Helper: trend badge ──────────────────────────────────────────────────────
function sdTrend(?float $pct): string {
    if ($pct === null) return '';
    $dir = $pct > 0 ? 'up' : ($pct < 0 ? 'down' : 'flat');
    $ico = $pct > 0 ? 'fa-arrow-trend-up' : ($pct < 0 ? 'fa-arrow-trend-down' : 'fa-arrow-right');
    return '<span class="sd-trend sd-trend-' . $dir . '">'
         . '<i class="fa ' . $ico . ' fa-xs"></i> ' . abs($pct) . '%</span>';
}
?>

<!-- ── Location Hero ─────────────────────────────────────────────────────── -->
<div class="sd-hero">
    <div class="sd-hero-left">
        <div class="sd-hero-eyebrow">
            <i class="fa fa-location-dot"></i>
            Location Command
        </div>
        <h1 class="sd-hero-title"><?= e($location['name'] ?? 'Your Location') ?></h1>
        <p class="sd-hero-sub">
            <?= date('l, d F Y') ?> &nbsp;&mdash;&nbsp;
            <strong style="color:#cbd5e1"><?= e($me['name']) ?></strong>
        </p>
    </div>
    <div class="sd-hero-stats">
        <div class="sd-hero-stat">
            <div class="sd-hero-stat-val"><?= $totalCars ?></div>
            <div class="sd-hero-stat-lbl">Total Fleet</div>
        </div>
        <div class="sd-hero-stat">
            <div class="sd-hero-stat-val" style="color:#10b981"><?= $availableCars ?></div>
            <div class="sd-hero-stat-lbl">Available</div>
        </div>
        <div class="sd-hero-stat">
            <div class="sd-hero-stat-val" style="color:#f59e0b"><?= $inWorkshop ?></div>
            <div class="sd-hero-stat-lbl">Workshop</div>
        </div>
        <div class="sd-hero-stat">
            <div class="sd-hero-stat-val" style="color:#a78bfa"><?= $teamCount ?></div>
            <div class="sd-hero-stat-lbl">Team</div>
        </div>
        <?php if ($bookingsToday > 0): ?>
        <div class="sd-hero-stat">
            <div class="sd-hero-stat-val" style="color:#fb923c"><?= $bookingsToday ?></div>
            <div class="sd-hero-stat-lbl">Today</div>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- ── KPI Section ───────────────────────────────────────────────────────── -->
<div class="sd-section">
    <span class="sd-section-label">Performance Overview</span>
    <div class="sd-section-rule"></div>
</div>

<div class="row g-3 mb-4">
    <!-- Total Fleet -->
    <div class="col-sm-6 col-xl-4">
        <a href="<?= BASE_URL ?>/modules/supervisor/cars.php" class="sd-tile">
            <div class="sd-tile-top" style="background:#2563eb"></div>
            <div class="sd-tile-body">
                <div class="sd-tile-icon" style="background:#dbeafe;color:#2563eb">
                    <i class="fa fa-car"></i>
                </div>
                <div class="sd-tile-info">
                    <div class="sd-tile-label">Total Fleet</div>
                    <div class="sd-tile-value" style="color:#2563eb"><?= $totalCars ?></div>
                    <div class="sd-tile-sub"><?= $availableCars ?> available &bull; <?= $inWorkshop ?> in workshop</div>
                </div>
            </div>
        </a>
    </div>

    <!-- In Workshop -->
    <div class="col-sm-6 col-xl-4">
        <a href="<?= BASE_URL ?>/modules/supervisor/cars.php?status=in_workshop" class="sd-tile">
            <div class="sd-tile-top" style="background:#f59e0b"></div>
            <div class="sd-tile-body">
                <div class="sd-tile-icon" style="background:#fef3c7;color:#d97706">
                    <i class="fa fa-screwdriver-wrench"></i>
                </div>
                <div class="sd-tile-info">
                    <div class="sd-tile-label">In Workshop</div>
                    <div class="sd-tile-value" style="color:#d97706"><?= $inWorkshop ?></div>
                    <div class="sd-tile-sub">
                        <?= ($carsByStatus['in_assessment'] ?? 0) ?> in assessment
                    </div>
                </div>
            </div>
        </a>
    </div>

    <!-- Revenue MTD -->
    <div class="col-sm-6 col-xl-4">
        <a href="<?= BASE_URL ?>/modules/supervisor/invoices.php" class="sd-tile">
            <div class="sd-tile-top" style="background:#10b981"></div>
            <div class="sd-tile-body">
                <div class="sd-tile-icon" style="background:#dcfce7;color:#059669">
                    <i class="fa fa-money-bill-wave"></i>
                </div>
                <div class="sd-tile-info">
                    <div class="sd-tile-label">Revenue (MTD)</div>
                    <div class="sd-tile-value sd-tile-value-sm" style="color:#059669">
                        <?= money($revMonth) ?>
                    </div>
                    <div class="sd-tile-sub">
                        <?= sdTrend($revTrend) ?>
                        <?php if ($revTrend === null): ?>
                        <span style="color:var(--sd-muted)">vs last month</span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </a>
    </div>

    <!-- Cars Sold MTD -->
    <div class="col-sm-6 col-xl-4">
        <a href="<?= BASE_URL ?>/modules/supervisor/cars.php?status=sold" class="sd-tile">
            <div class="sd-tile-top" style="background:#0f172a"></div>
            <div class="sd-tile-body">
                <div class="sd-tile-icon" style="background:#f1f5f9;color:#0f172a">
                    <i class="fa fa-tag"></i>
                </div>
                <div class="sd-tile-info">
                    <div class="sd-tile-label">Cars Sold (MTD)</div>
                    <div class="sd-tile-value"><?= $carsSoldMonth ?></div>
                    <div class="sd-tile-sub">
                        <?= ($carsByStatus['delivered'] ?? 0) ?> delivered total
                    </div>
                </div>
            </div>
        </a>
    </div>

    <!-- Pending Bookings -->
    <div class="col-sm-6 col-xl-4">
        <a href="<?= BASE_URL ?>/modules/supervisor/service_bookings.php" class="sd-tile">
            <div class="sd-tile-top" style="background:#06b6d4"></div>
            <div class="sd-tile-body">
                <div class="sd-tile-icon" style="background:#ecfeff;color:#0891b2">
                    <i class="fa fa-calendar-check"></i>
                </div>
                <div class="sd-tile-info">
                    <div class="sd-tile-label">Pending Bookings</div>
                    <div class="sd-tile-value" style="color:#0891b2"><?= $pendingBookings ?></div>
                    <div class="sd-tile-sub"><?= $bookingsToday ?> scheduled today</div>
                </div>
            </div>
        </a>
    </div>

    <!-- Total Clients / Leads -->
    <div class="col-sm-6 col-xl-4">
        <a href="<?= BASE_URL ?>/modules/crm/leads.php" class="sd-tile">
            <div class="sd-tile-top" style="background:#8b5cf6"></div>
            <div class="sd-tile-body">
                <div class="sd-tile-icon" style="background:#f5f3ff;color:#7c3aed">
                    <i class="fa fa-users"></i>
                </div>
                <div class="sd-tile-info">
                    <div class="sd-tile-label">CRM Leads</div>
                    <div class="sd-tile-value" style="color:#7c3aed"><?= $totalLeads ?></div>
                    <div class="sd-tile-sub">leads at this location</div>
                </div>
            </div>
        </a>
    </div>
</div>

<!-- ── Financial Chart + Fleet Status ───────────────────────────────────── -->
<div class="sd-section">
    <span class="sd-section-label">Financial Performance</span>
    <div class="sd-section-rule"></div>
</div>

<div class="row g-3 mb-4">
    <!-- Area chart -->
    <div class="col-lg-7">
        <div class="sd-panel">
            <div class="sd-panel-header">
                <p class="sd-panel-title">
                    <i class="fa fa-chart-area"></i>
                    Revenue — Last 6 Months
                </p>
                <a href="<?= BASE_URL ?>/modules/supervisor/reports.php" class="btn btn-xs btn-outline-secondary">Reports</a>
            </div>
            <div style="padding:16px 20px 14px">
                <canvas id="supRevChart" height="130"></canvas>
            </div>
        </div>
    </div>

    <!-- Fleet status horizontal bars -->
    <div class="col-lg-5">
        <div class="sd-panel">
            <div class="sd-panel-header">
                <p class="sd-panel-title">
                    <i class="fa fa-layer-group"></i>
                    Fleet Status Breakdown
                </p>
                <span class="badge" style="background:rgba(6,182,212,.12);color:var(--sd-cyan);font-size:11px">
                    <?= $totalCars ?> total
                </span>
            </div>
            <?php if ($totalCars > 0): ?>
            <div class="sd-fleet-bars">
                <?php foreach ($statusMeta as $key => $meta):
                    $cnt = $carsByStatus[$key] ?? 0;
                    if ($cnt === 0) continue;
                    $pct = round(($cnt / $totalCars) * 100);
                ?>
                <div class="sd-fleet-row">
                    <div class="sd-fleet-lbl"><?= $meta['label'] ?></div>
                    <div class="sd-fleet-track">
                        <div class="sd-fleet-fill" style="width:<?= $pct ?>%;background:<?= $meta['color'] ?>"></div>
                    </div>
                    <div class="sd-fleet-cnt" style="color:<?= $meta['color'] ?>"><?= $cnt ?></div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php else: ?>
            <div class="sd-empty">
                <i class="fa fa-car"></i>No cars at this location yet.
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- ── Recent Cars + Upcoming Bookings ──────────────────────────────────── -->
<div class="sd-section">
    <span class="sd-section-label">Operations</span>
    <div class="sd-section-rule"></div>
</div>

<div class="row g-3 mb-4">
    <!-- Recent Cars -->
    <div class="col-lg-7">
        <div class="sd-panel">
            <div class="sd-panel-header">
                <p class="sd-panel-title">
                    <i class="fa fa-car-side"></i>
                    Cars at <?= e($location['name'] ?? 'Location') ?>
                </p>
                <a href="<?= BASE_URL ?>/modules/supervisor/cars.php" class="btn btn-xs btn-outline-secondary">View All</a>
            </div>
            <?php if (!empty($recentCars)): ?>
            <div class="table-responsive">
                <table class="table table-hover mb-0 sd-cars-table">
                    <thead>
                        <tr>
                            <th class="ps-3" colspan="2">Vehicle</th>
                            <th>Reg / Chassis</th>
                            <th>Status</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recentCars as $car): ?>
                        <tr>
                            <td class="ps-3 pe-0" style="width:52px">
                                <?php if (!empty($car['thumb'])): ?>
                                <img src="<?= e(thumbUrl('cars', $car['thumb'])) ?>"
                                     class="sd-car-thumb" alt="">
                                <?php else: ?>
                                <div class="sd-car-thumb-ph">
                                    <i class="fa fa-car"></i>
                                </div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="fw-semibold small"><?= e(trim(($car['year'] ?? '') . ' ' . $car['make'] . ' ' . $car['model'])) ?></div>
                                <div class="text-muted" style="font-size:11px"><?= e($car['color'] ?: '—') ?></div>
                            </td>
                            <td>
                                <code style="font-size:10.5px"><?= e($car['registration_number'] ?: ($car['chassis_number'] ?: '—')) ?></code>
                            </td>
                            <td><?= statusBadge($car['status']) ?></td>
                            <td class="text-end pe-3">
                                <a href="<?= BASE_URL ?>/modules/cars/view.php?id=<?= $car['id'] ?>"
                                   class="btn btn-xs btn-outline-secondary">View</a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
            <div class="sd-empty">
                <i class="fa fa-car"></i>No cars at this location yet.
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Upcoming Bookings Timeline -->
    <div class="col-lg-5">
        <div class="sd-panel">
            <div class="sd-panel-header">
                <p class="sd-panel-title">
                    <i class="fa fa-calendar-days"></i>
                    Upcoming Bookings
                </p>
                <a href="<?= BASE_URL ?>/modules/supervisor/service_bookings.php" class="btn btn-xs btn-outline-secondary">All</a>
            </div>
            <?php if (!empty($upcomingBookings)): ?>
            <div class="sd-timeline">
                <?php foreach ($upcomingBookings as $bk):
                    $dotColor = $bk['status'] === 'confirmed' ? '#10b981' : '#f59e0b';
                    $dateStr  = $bk['preferred_date'] ? fmtDate($bk['preferred_date'], 'd M') : 'TBD';
                    $isToday  = ($bk['preferred_date'] === date('Y-m-d'));
                ?>
                <div class="sd-tl-item">
                    <div class="sd-tl-dot" style="background:<?= $dotColor ?>"></div>
                    <div class="sd-tl-body">
                        <div class="sd-tl-name"><?= e($bk['client_name'] ?? 'Client') ?></div>
                        <div class="sd-tl-meta">
                            <?php if (!empty($bk['make'])): ?>
                            <?= e($bk['make'] . ' ' . $bk['model']) ?> &bull;
                            <?php endif; ?>
                            <?= e(implode(', ', array_slice(explode(', ', $bk['service_type'] ?? 'Service'), 0, 2))) ?>
                        </div>
                    </div>
                    <div class="sd-tl-right">
                        <div class="sd-tl-date" style="<?= $isToday ? 'color:#f59e0b' : '' ?>">
                            <?= $isToday ? 'Today' : $dateStr ?>
                        </div>
                        <div style="margin-top:4px"><?= statusBadge($bk['status']) ?></div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php else: ?>
            <div class="sd-empty">
                <i class="fa fa-calendar-check"></i>No upcoming bookings.
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- ── Recent Payments ───────────────────────────────────────────────────── -->
<?php if (!empty($recentPayments)): ?>
<div class="sd-section">
    <span class="sd-section-label">Recent Payments</span>
    <div class="sd-section-rule"></div>
</div>

<div class="sd-panel mb-4">
    <div class="sd-panel-header">
        <p class="sd-panel-title">
            <i class="fa fa-money-bill-transfer"></i>
            Confirmed Payments
        </p>
        <a href="<?= BASE_URL ?>/modules/supervisor/invoices.php" class="btn btn-xs btn-outline-secondary">All Invoices</a>
    </div>
    <div class="table-responsive">
        <table class="table table-hover mb-0 sd-pay-table">
            <thead>
                <tr>
                    <th class="ps-4">Client</th>
                    <th>Vehicle</th>
                    <th>Method</th>
                    <th>Reference</th>
                    <th class="text-end">Amount</th>
                    <th class="text-end pe-4">Date</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($recentPayments as $pay): ?>
                <tr>
                    <td class="ps-4 fw-medium small"><?= e($pay['client_name'] ?: '—') ?></td>
                    <td class="small text-muted">
                        <?= (!empty($pay['make'])) ? e($pay['make'].' '.$pay['model']) : '—' ?>
                    </td>
                    <td>
                        <span class="sd-method-badge">
                            <?= ucwords(str_replace('_',' ',$pay['payment_method'] ?? 'cash')) ?>
                        </span>
                    </td>
                    <td class="small text-muted"><?= e($pay['reference_number'] ?: '—') ?></td>
                    <td class="text-end fw-bold" style="color:#10b981;font-variant-numeric:tabular-nums">
                        <?= money($pay['amount']) ?>
                    </td>
                    <td class="text-end pe-4 text-muted small">
                        <?= fmtDate($pay['created_at'], 'd M H:i') ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php include __DIR__ . '/../../includes/footer.php'; ?>

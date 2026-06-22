<?php
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
requireRole('admin');

$pageTitle = 'Workshop Dashboard';
$db = getDB();

// ── KPI Stats ──────────────────────────────────────────────────────────────
$s = [];
try {
    // Revenue from service-booking payments (confirmed)
    $s['total_revenue']     = (float)$db->query("
        SELECT COALESCE(SUM(amount),0) FROM payments
        WHERE service_booking_id IS NOT NULL AND status='confirmed'
    ")->fetchColumn();

    // Bookings today (by preferred_date or booking_date)
    $s['bookings_today']    = (int)$db->query("
        SELECT COUNT(*) FROM service_bookings
        WHERE (preferred_date = CURDATE() OR booking_date = CURDATE())
          AND status NOT IN ('cancelled')
    ")->fetchColumn();

    // Pending bookings
    $s['pending_bookings']  = (int)$db->query("
        SELECT COUNT(*) FROM service_bookings WHERE status='pending'
    ")->fetchColumn();

    // Low stock parts
    $s['low_stock']         = (int)$db->query("
        SELECT COUNT(*) FROM inventory WHERE quantity <= reorder_level
    ")->fetchColumn();

    // Active job cards
    $s['active_jobs']       = (int)$db->query("
        SELECT COUNT(*) FROM workshop_jobs
        WHERE status IN ('pending','in_progress','waiting_parts','on_hold')
    ")->fetchColumn();

    // Completed this month
    $s['completed_month']   = (int)$db->query("
        SELECT COUNT(*) FROM workshop_jobs
        WHERE status='completed'
          AND MONTH(updated_at)=MONTH(NOW()) AND YEAR(updated_at)=YEAR(NOW())
    ")->fetchColumn();

    // Revenue this month only (for month-on-month context)
    $s['revenue_month']     = (float)$db->query("
        SELECT COALESCE(SUM(amount),0) FROM payments
        WHERE service_booking_id IS NOT NULL AND status='confirmed'
          AND MONTH(payment_date)=MONTH(NOW()) AND YEAR(payment_date)=YEAR(NOW())
    ")->fetchColumn();

    // Overdue jobs
    $s['overdue_jobs']      = (int)$db->query("
        SELECT COUNT(*) FROM workshop_jobs
        WHERE status NOT IN ('completed','cancelled')
          AND end_date < CURDATE() AND end_date IS NOT NULL
    ")->fetchColumn();

} catch (\Throwable $_) {
    foreach (['total_revenue','bookings_today','pending_bookings','low_stock',
              'active_jobs','completed_month','revenue_month','overdue_jobs'] as $k) $s[$k] = 0;
}

// ── Recent Payments (service bookings) ────────────────────────────────────
try {
    $recentPayments = $db->query("
        SELECT p.id, p.payment_number, p.payment_date, p.amount, p.payment_method,
               p.status, p.client_name, p.reference_number,
               sb.booking_number, sb.service_type, sb.car_registration
        FROM payments p
        LEFT JOIN service_bookings sb ON sb.id = p.service_booking_id
        WHERE p.service_booking_id IS NOT NULL
        ORDER BY p.created_at DESC LIMIT 8
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (\Throwable $_) { $recentPayments = []; }

// ── Upcoming Bookings ──────────────────────────────────────────────────────
try {
    $upcomingBookings = $db->query("
        SELECT id, booking_number, client_name, client_phone,
               car_registration, car_make, car_model,
               service_type, preferred_date, preferred_time, status
        FROM service_bookings
        WHERE preferred_date >= CURDATE()
          AND status IN ('pending','confirmed')
        ORDER BY preferred_date ASC, preferred_time ASC
        LIMIT 8
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (\Throwable $_) { $upcomingBookings = []; }

// ── Pending Bookings list ─────────────────────────────────────────────────
try {
    $pendingBookings = $db->query("
        SELECT id, booking_number, client_name, client_phone,
               car_registration, car_make, car_model,
               service_type, booking_date, preferred_date, preferred_time, status
        FROM service_bookings
        WHERE status = 'pending'
        ORDER BY COALESCE(preferred_date, booking_date) ASC
        LIMIT 8
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (\Throwable $_) { $pendingBookings = []; }

// ── Low Stock Parts ────────────────────────────────────────────────────────
try {
    $lowStockItems = $db->query("
        SELECT part_name, part_number, quantity, reorder_level, unit, category
        FROM inventory WHERE quantity <= reorder_level
        ORDER BY (quantity / GREATEST(reorder_level,1)) ASC
        LIMIT 10
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (\Throwable $_) { $lowStockItems = []; }

// ── Overdue jobs detail ────────────────────────────────────────────────────
try {
    $overdueList = $db->query("
        SELECT wj.job_number, wj.end_date, wj.priority, wj.status,
               c.registration_number, c.make, c.model,
               m.name AS mechanic_name
        FROM workshop_jobs wj
        LEFT JOIN cars c ON c.id = wj.car_id
        LEFT JOIN mechanics m ON m.id = wj.mechanic_id
        WHERE wj.status NOT IN ('completed','cancelled')
          AND wj.end_date < CURDATE() AND wj.end_date IS NOT NULL
        ORDER BY wj.end_date ASC LIMIT 8
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (\Throwable $_) { $overdueList = []; }

// ── Monthly revenue trend (last 6 months, line chart) ─────────────────────
try {
    $revRows = $db->query("
        SELECT DATE_FORMAT(payment_date,'%b %Y') AS mo,
               DATE_FORMAT(payment_date,'%Y-%m') AS mo_key,
               SUM(amount) AS total
        FROM payments
        WHERE service_booking_id IS NOT NULL AND status='confirmed'
          AND payment_date >= DATE_SUB(CURDATE(), INTERVAL 5 MONTH)
        GROUP BY mo_key ORDER BY mo_key ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (\Throwable $_) { $revRows = []; }

// Fill any missing months so the line is complete
$revMap = [];
foreach ($revRows as $r) $revMap[$r['mo_key']] = ['label' => $r['mo'], 'total' => (float)$r['total']];
$revLabels = []; $revData = [];
for ($i = 5; $i >= 0; $i--) {
    $key = date('Y-m', strtotime("-$i months"));
    $lbl = date('M Y', strtotime("-$i months"));
    $revLabels[] = $revMap[$key]['label'] ?? $lbl;
    $revData[]   = round($revMap[$key]['total'] ?? 0, 2);
}

// ── Active job status (doughnut) ──────────────────────────────────────────
try {
    $jobStatuses = $db->query("
        SELECT status, COUNT(*) as cnt FROM workshop_jobs
        WHERE status NOT IN ('completed','cancelled') GROUP BY status
    ")->fetchAll(PDO::FETCH_KEY_PAIR);
} catch (\Throwable $_) { $jobStatuses = []; }
$donutLabels = ['Pending','In Progress','Waiting Parts','On Hold'];
$donutKeys   = ['pending','in_progress','waiting_parts','on_hold'];
$donutData   = array_map(fn($k) => (int)($jobStatuses[$k] ?? 0), $donutKeys);

// ── Chart payload ─────────────────────────────────────────────────────────
$chartPayload = json_encode([
    'donut'    => ['labels' => $donutLabels, 'data' => $donutData],
    'revenue'  => ['labels' => $revLabels,   'data' => $revData],
]);

$extraJs = '<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
(function(){
    var d = ' . $chartPayload . ';

    // Doughnut — active job status
    var dc = document.getElementById("donutJobStatus");
    if (dc && d.donut.data.some(function(v){return v>0;})) {
        new Chart(dc, {
            type:"doughnut",
            data:{
                labels:d.donut.labels,
                datasets:[{data:d.donut.data,backgroundColor:["#94a3b8","#2563eb","#f59e0b","#8b5cf6"],borderWidth:2,borderColor:"#fff",hoverOffset:6}]
            },
            options:{cutout:"68%",plugins:{legend:{position:"bottom",labels:{padding:16,font:{family:"Inter",size:12},usePointStyle:true,pointStyleWidth:8}}},maintainAspectRatio:false}
        });
    } else if (dc) {
        dc.parentElement.innerHTML = "<div style=\"height:220px;display:flex;align-items:center;justify-content:center;color:#94a3b8;font-size:13px\">No active jobs</div>";
    }

    // Line — monthly revenue
    var rc = document.getElementById("lineRevenue");
    if (rc) {
        new Chart(rc, {
            type:"line",
            data:{
                labels:d.revenue.labels,
                datasets:[{
                    label:"Revenue (KSh)",
                    data:d.revenue.data,
                    borderColor:"#2563eb",
                    backgroundColor:"rgba(37,99,235,.08)",
                    borderWidth:2,
                    pointRadius:4,
                    pointBackgroundColor:"#2563eb",
                    fill:true,
                    tension:.35
                }]
            },
            options:{
                responsive:true,maintainAspectRatio:false,
                plugins:{legend:{display:false}},
                scales:{
                    y:{beginAtZero:true,ticks:{precision:0,callback:function(v){return "KSh "+v.toLocaleString();},font:{family:"Inter",size:11}},grid:{color:"rgba(0,0,0,.05)"}},
                    x:{ticks:{font:{family:"Inter",size:11}},grid:{display:false}}
                }
            }
        });
    }
}());
</script>';

include __DIR__ . '/../../includes/header.php';

function fmtMoney($v) { return 'KSh ' . number_format($v, 0); }
?>

<style>
.admin-kpi-card{background:var(--surface);border-radius:var(--r-lg);padding:22px 20px;box-shadow:var(--sh);display:flex;align-items:center;gap:16px;transition:transform .15s,box-shadow .15s;border:1px solid var(--border)}
.admin-kpi-card:hover{transform:translateY(-2px);box-shadow:var(--sh-md)}
.kpi-icon-wrap{width:52px;height:52px;border-radius:12px;display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:20px}
.kpi-value{font-size:26px;font-weight:700;color:var(--text);line-height:1}
.kpi-value-sm{font-size:20px;font-weight:700;color:var(--text);line-height:1}
.kpi-label{font-size:12px;color:var(--text-2);font-weight:500;margin-top:3px}
.kpi-sub{font-size:11px;color:var(--text-3);margin-top:4px}
.section-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:16px}
.section-header h2{font-size:15px;font-weight:700;color:var(--text);margin:0}
.chart-card{background:var(--surface);border-radius:var(--r-lg);padding:22px;box-shadow:var(--sh);border:1px solid var(--border)}
.status-badge{display:inline-flex;align-items:center;gap:5px;padding:3px 9px;border-radius:20px;font-size:11px;font-weight:600;text-transform:capitalize}
.badge-pending{background:#f1f5f9;color:#475569}
.badge-confirmed{background:#dbeafe;color:#1d4ed8}
.badge-in_progress{background:#dbeafe;color:#1d4ed8}
.badge-waiting_parts{background:#fef3c7;color:#b45309}
.badge-completed{background:#dcfce7;color:#15803d}
.badge-cancelled{background:#fee2e2;color:#b91c1c}
.priority-dot{width:8px;height:8px;border-radius:50%;display:inline-block}
.priority-critical .priority-dot,.priority-high .priority-dot{background:#ef4444}
.priority-medium .priority-dot{background:#eab308}
.priority-low .priority-dot{background:#22c55e}
.dashboard-title-row{display:flex;align-items:center;justify-content:space-between;margin-bottom:28px;flex-wrap:wrap;gap:12px}
.dashboard-title-row h1{font-size:22px;font-weight:700;color:var(--text);margin:0;display:flex;align-items:center;gap:10px}
.dashboard-title-row h1 i{width:38px;height:38px;border-radius:10px;background:#fef3c7;display:flex;align-items:center;justify-content:center;font-size:17px;color:#f59e0b}
.live-badge{background:#dcfce7;color:#15803d;font-size:11px;font-weight:700;padding:3px 10px;border-radius:20px;letter-spacing:.3px}
.stock-bar-wrap{flex:1;background:#f1f5f9;border-radius:4px;height:6px;overflow:hidden;min-width:60px}
.stock-bar{height:100%;border-radius:4px;transition:width .4s}
.pm-badge-mpesa{background:#e8f5e9;color:#2e7d32}
.pm-badge-cash{background:#fff8e1;color:#f57f17}
.pm-badge-bank{background:#e3f2fd;color:#1565c0}
.pm-badge-cheque{background:#f3e5f5;color:#7b1fa2}
</style>

<!-- Page title row -->
<div class="dashboard-title-row">
    <h1>
        <i class="fa fa-screwdriver-wrench"></i>
        Workshop Dashboard
    </h1>
    <div class="d-flex align-items-center gap-3">
        <span class="live-badge"><i class="fa fa-circle-dot fa-xs me-1"></i>Live</span>
        <span style="font-size:12.5px;color:var(--text-2)">Updated <?= date('d M Y, H:i') ?></span>
        <button onclick="location.reload()" class="btn btn-sm btn-outline-secondary d-flex align-items-center gap-2">
            <i class="fa fa-rotate-right"></i> Refresh
        </button>
    </div>
</div>

<!-- ── KPI Row 1 ──────────────────────────────────────────────────────────── -->
<div class="row g-3 mb-3">

    <!-- Total Revenue -->
    <div class="col-xl-3 col-md-6">
        <div class="admin-kpi-card">
            <div class="kpi-icon-wrap" style="background:#dcfce7">
                <i class="fa fa-sack-dollar" style="color:#16a34a"></i>
            </div>
            <div style="min-width:0">
                <div class="kpi-value-sm text-truncate"><?= fmtMoney($s['total_revenue']) ?></div>
                <div class="kpi-label">Total Service Revenue</div>
                <div class="kpi-sub">This month: <?= fmtMoney($s['revenue_month']) ?></div>
            </div>
        </div>
    </div>

    <!-- Bookings Today -->
    <div class="col-xl-3 col-md-6">
        <div class="admin-kpi-card" style="<?= $s['bookings_today'] > 0 ? 'border-color:#93c5fd' : '' ?>">
            <div class="kpi-icon-wrap" style="background:#dbeafe">
                <i class="fa fa-calendar-day" style="color:#2563eb"></i>
            </div>
            <div>
                <div class="kpi-value"><?= $s['bookings_today'] ?></div>
                <div class="kpi-label">Bookings Today</div>
            </div>
        </div>
    </div>

    <!-- Pending Bookings -->
    <div class="col-xl-3 col-md-6">
        <div class="admin-kpi-card" style="<?= $s['pending_bookings'] > 0 ? 'border-color:#fcd34d' : '' ?>">
            <div class="kpi-icon-wrap" style="background:#fef3c7">
                <i class="fa fa-hourglass-half" style="color:#d97706"></i>
            </div>
            <div>
                <div class="kpi-value" style="<?= $s['pending_bookings'] > 0 ? 'color:#d97706' : '' ?>"><?= $s['pending_bookings'] ?></div>
                <div class="kpi-label">Pending Bookings</div>
            </div>
        </div>
    </div>

    <!-- Low Stock -->
    <div class="col-xl-3 col-md-6">
        <div class="admin-kpi-card" style="<?= $s['low_stock'] > 0 ? 'border-color:#fca5a5' : '' ?>">
            <div class="kpi-icon-wrap" style="background:#fee2e2">
                <i class="fa fa-battery-quarter" style="color:#dc2626"></i>
            </div>
            <div>
                <div class="kpi-value" style="<?= $s['low_stock'] > 0 ? 'color:#dc2626' : '' ?>"><?= $s['low_stock'] ?></div>
                <div class="kpi-label">Low Stock Parts</div>
            </div>
        </div>
    </div>
</div>

<!-- ── KPI Row 2 ──────────────────────────────────────────────────────────── -->
<div class="row g-3 mb-4">
    <div class="col-xl-3 col-md-6">
        <div class="admin-kpi-card">
            <div class="kpi-icon-wrap" style="background:#ede9fe">
                <i class="fa fa-toolbox" style="color:#7c3aed"></i>
            </div>
            <div>
                <div class="kpi-value"><?= $s['active_jobs'] ?></div>
                <div class="kpi-label">Active Job Cards</div>
            </div>
        </div>
    </div>
    <div class="col-xl-3 col-md-6">
        <div class="admin-kpi-card">
            <div class="kpi-icon-wrap" style="background:#dcfce7">
                <i class="fa fa-calendar-check" style="color:#16a34a"></i>
            </div>
            <div>
                <div class="kpi-value"><?= $s['completed_month'] ?></div>
                <div class="kpi-label">Jobs Completed This Month</div>
            </div>
        </div>
    </div>
    <div class="col-xl-3 col-md-6">
        <div class="admin-kpi-card" style="<?= $s['overdue_jobs'] > 0 ? 'border-color:#fca5a5' : '' ?>">
            <div class="kpi-icon-wrap" style="background:#fee2e2">
                <i class="fa fa-clock-rotate-left" style="color:#dc2626"></i>
            </div>
            <div>
                <div class="kpi-value" style="<?= $s['overdue_jobs'] > 0 ? 'color:#dc2626' : '' ?>"><?= $s['overdue_jobs'] ?></div>
                <div class="kpi-label">Overdue Jobs</div>
            </div>
        </div>
    </div>
    <div class="col-xl-3 col-md-6">
        <div class="admin-kpi-card">
            <div class="kpi-icon-wrap" style="background:#f0fdf4">
                <i class="fa fa-calendar-alt" style="color:#16a34a"></i>
            </div>
            <div>
                <div class="kpi-value"><?= count($upcomingBookings) ?></div>
                <div class="kpi-label">Upcoming Bookings</div>
            </div>
        </div>
    </div>
</div>

<!-- ── Charts Row ─────────────────────────────────────────────────────────── -->
<div class="row g-3 mb-4">
    <div class="col-xl-5">
        <div class="chart-card h-100">
            <div class="section-header mb-3">
                <h2><i class="fa fa-chart-pie me-2" style="color:#2563eb"></i>Active Job Status</h2>
                <span style="font-size:12px;color:var(--text-3)"><?= $s['active_jobs'] ?> active</span>
            </div>
            <div style="height:240px;position:relative">
                <canvas id="donutJobStatus"></canvas>
            </div>
        </div>
    </div>
    <div class="col-xl-7">
        <div class="chart-card h-100">
            <div class="section-header mb-3">
                <h2><i class="fa fa-chart-line me-2" style="color:#2563eb"></i>Monthly Service Revenue</h2>
                <span style="font-size:12px;color:var(--text-3)">Last 6 months</span>
            </div>
            <div style="height:240px;position:relative">
                <canvas id="lineRevenue"></canvas>
            </div>
        </div>
    </div>
</div>

<!-- ── Recent Payments + Upcoming Bookings ───────────────────────────────── -->
<div class="row g-3 mb-4">

    <!-- Recent Payments -->
    <div class="col-xl-6">
        <div class="chart-card h-100">
            <div class="section-header">
                <h2><i class="fa fa-money-bill-wave me-2" style="color:#16a34a"></i>Recent Payments</h2>
                <a href="<?= BASE_URL ?>/modules/payments/index.php" class="btn btn-sm btn-outline-primary">View All</a>
            </div>
            <div class="table-responsive">
                <table class="table mb-0" style="font-size:12.5px">
                    <thead>
                        <tr style="font-size:11px;text-transform:uppercase;letter-spacing:.4px;color:var(--text-3)">
                            <th class="ps-0">Client</th>
                            <th>Booking</th>
                            <th>Method</th>
                            <th class="text-end">Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($recentPayments)): ?>
                        <tr><td colspan="4" class="text-center py-4" style="color:var(--text-3)">
                            <i class="fa fa-money-bill-wave fa-2x mb-2 d-block" style="color:#e2e8f0"></i>No payments yet
                        </td></tr>
                        <?php else: foreach ($recentPayments as $p):
                            $pmClass = 'pm-badge-' . strtolower($p['payment_method'] ?? 'cash');
                        ?>
                        <tr>
                            <td class="ps-0">
                                <div class="fw-semibold"><?= e($p['client_name']) ?></div>
                                <div style="color:var(--text-3);font-size:11px"><?= date('d M', strtotime($p['payment_date'])) ?></div>
                            </td>
                            <td>
                                <div><?= e($p['booking_number'] ?? '—') ?></div>
                                <div style="color:var(--text-3);font-size:11px"><?= e($p['car_registration'] ?? '') ?></div>
                            </td>
                            <td>
                                <span class="status-badge <?= $pmClass ?>"><?= ucfirst($p['payment_method']) ?></span>
                            </td>
                            <td class="text-end fw-semibold" style="color:#16a34a">
                                <?= fmtMoney($p['amount']) ?>
                            </td>
                        </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Upcoming Bookings -->
    <div class="col-xl-6">
        <div class="chart-card h-100">
            <div class="section-header">
                <h2><i class="fa fa-calendar-days me-2" style="color:#2563eb"></i>Upcoming Bookings</h2>
                <a href="<?= BASE_URL ?>/modules/bookings/index.php" class="btn btn-sm btn-outline-primary">View All</a>
            </div>
            <div class="table-responsive">
                <table class="table mb-0" style="font-size:12.5px">
                    <thead>
                        <tr style="font-size:11px;text-transform:uppercase;letter-spacing:.4px;color:var(--text-3)">
                            <th class="ps-0">Client</th>
                            <th>Vehicle</th>
                            <th>Date / Time</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($upcomingBookings)): ?>
                        <tr><td colspan="4" class="text-center py-4" style="color:var(--text-3)">
                            <i class="fa fa-calendar-days fa-2x mb-2 d-block" style="color:#e2e8f0"></i>No upcoming bookings
                        </td></tr>
                        <?php else: foreach ($upcomingBookings as $b):
                            $isToday = $b['preferred_date'] === date('Y-m-d');
                        ?>
                        <tr>
                            <td class="ps-0">
                                <div class="fw-semibold"><?= e($b['client_name']) ?></div>
                                <div style="color:var(--text-3);font-size:11px"><?= e($b['client_phone']) ?></div>
                            </td>
                            <td>
                                <div><?= e($b['car_registration'] ?? '—') ?></div>
                                <div style="color:var(--text-3);font-size:11px"><?= e(trim(($b['car_make'] ?? '') . ' ' . ($b['car_model'] ?? ''))) ?: '—' ?></div>
                            </td>
                            <td>
                                <?php if ($isToday): ?>
                                <span class="fw-semibold" style="color:#2563eb">Today</span>
                                <?php else: ?>
                                <span><?= date('d M', strtotime($b['preferred_date'])) ?></span>
                                <?php endif; ?>
                                <?php if ($b['preferred_time']): ?>
                                <div style="color:var(--text-3);font-size:11px"><?= e($b['preferred_time']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="status-badge badge-<?= $b['status'] ?>"><?= ucfirst($b['status']) ?></span>
                            </td>
                        </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- ── Pending Bookings ───────────────────────────────────────────────────── -->
<?php if (!empty($pendingBookings)): ?>
<div class="chart-card mb-4" style="border-color:#fcd34d">
    <div class="section-header">
        <h2 style="color:#d97706"><i class="fa fa-hourglass-half me-2"></i>Pending Bookings — Awaiting Confirmation</h2>
        <span class="badge" style="background:#fef3c7;color:#b45309"><?= count($pendingBookings) ?> pending</span>
    </div>
    <div class="table-responsive">
        <table class="table mb-0" style="font-size:12.5px">
            <thead>
                <tr style="font-size:11px;text-transform:uppercase;letter-spacing:.4px;color:var(--text-3)">
                    <th class="ps-0">Ref #</th>
                    <th>Client</th>
                    <th>Vehicle</th>
                    <th>Service</th>
                    <th>Preferred Date</th>
                    <th>Time</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($pendingBookings as $b): ?>
                <tr>
                    <td class="ps-0">
                        <a href="<?= BASE_URL ?>/modules/bookings/view.php?id=<?= $b['id'] ?>"
                           style="color:var(--brand);text-decoration:none;font-weight:600">
                            <?= e($b['booking_number']) ?>
                        </a>
                    </td>
                    <td>
                        <div class="fw-semibold"><?= e($b['client_name']) ?></div>
                        <div style="color:var(--text-3);font-size:11px"><?= e($b['client_phone']) ?></div>
                    </td>
                    <td><?= e($b['car_registration'] ?? '—') ?><br>
                        <span style="color:var(--text-3);font-size:11px"><?= e(trim(($b['car_make'] ?? '') . ' ' . ($b['car_model'] ?? ''))) ?></span>
                    </td>
                    <td><?= e($b['service_type']) ?></td>
                    <td><?= $b['preferred_date'] ? date('d M Y', strtotime($b['preferred_date'])) : date('d M Y', strtotime($b['booking_date'])) ?></td>
                    <td><?= e($b['preferred_time'] ?? '—') ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<!-- ── Low Stock Parts ────────────────────────────────────────────────────── -->
<?php if (!empty($lowStockItems)): ?>
<div class="chart-card mb-4" style="border-color:#fca5a5">
    <div class="section-header">
        <h2 style="color:#dc2626"><i class="fa fa-battery-quarter me-2"></i>Low Stock Parts — Reorder Required</h2>
        <span class="badge bg-danger"><?= count($lowStockItems) ?> item<?= count($lowStockItems) !== 1 ? 's' : '' ?></span>
    </div>
    <div class="table-responsive">
        <table class="table mb-0" style="font-size:12.5px">
            <thead>
                <tr style="font-size:11px;text-transform:uppercase;letter-spacing:.4px;color:var(--text-3)">
                    <th class="ps-0">Part</th>
                    <th>Part #</th>
                    <th>Category</th>
                    <th>In Stock</th>
                    <th>Reorder At</th>
                    <th style="min-width:120px">Stock Level</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($lowStockItems as $item):
                    $reorder = max((float)$item['reorder_level'], 1);
                    $qty     = (float)$item['quantity'];
                    $pct     = min(100, round(($qty / $reorder) * 100));
                    $barColor = $qty <= 0 ? '#ef4444' : ($pct < 50 ? '#f97316' : '#eab308');
                ?>
                <tr>
                    <td class="ps-0 fw-semibold"><?= e($item['part_name']) ?></td>
                    <td style="color:var(--text-2)"><?= e($item['part_number'] ?? '—') ?></td>
                    <td style="color:var(--text-2)"><?= e($item['category'] ?? '—') ?></td>
                    <td>
                        <span style="font-weight:700;color:<?= $qty <= 0 ? '#dc2626' : '#d97706' ?>">
                            <?= rtrim(rtrim(number_format($qty, 2), '0'), '.') ?>
                        </span>
                        <span style="color:var(--text-3);font-size:11px"> <?= e($item['unit']) ?></span>
                    </td>
                    <td style="color:var(--text-2)"><?= rtrim(rtrim(number_format($reorder, 2), '0'), '.') ?> <?= e($item['unit']) ?></td>
                    <td>
                        <div class="d-flex align-items-center gap-2">
                            <div class="stock-bar-wrap">
                                <div class="stock-bar" style="width:<?= $pct ?>%;background:<?= $barColor ?>"></div>
                            </div>
                            <span style="font-size:11px;color:var(--text-2);white-space:nowrap"><?= $pct ?>%</span>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<!-- ── Overdue Jobs ───────────────────────────────────────────────────────── -->
<?php if (!empty($overdueList)): ?>
<div class="chart-card mb-4" style="border-color:#fca5a5">
    <div class="section-header">
        <h2 style="color:#dc2626"><i class="fa fa-circle-exclamation me-2"></i>Overdue Jobs — Require Attention</h2>
        <span class="badge bg-danger"><?= count($overdueList) ?> overdue</span>
    </div>
    <div class="table-responsive">
        <table class="table mb-0" style="font-size:12.5px">
            <thead>
                <tr style="font-size:11px;text-transform:uppercase;letter-spacing:.4px;color:var(--text-3)">
                    <th class="ps-0">Job #</th>
                    <th>Vehicle</th>
                    <th>Mechanic</th>
                    <th>Priority</th>
                    <th>Due Date</th>
                    <th>Days Overdue</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($overdueList as $job): ?>
                <tr>
                    <td class="ps-0 fw-semibold" style="color:#dc2626"><?= e($job['job_number'] ?? '—') ?></td>
                    <td>
                        <span class="fw-medium"><?= e($job['registration_number'] ?? '—') ?></span><br>
                        <span style="color:var(--text-2);font-size:11.5px"><?= e(($job['make'] ?? '') . ' ' . ($job['model'] ?? '')) ?></span>
                    </td>
                    <td><?= e($job['mechanic_name'] ?? 'Unassigned') ?></td>
                    <td>
                        <?php $pri = strtolower($job['priority'] ?? 'low'); ?>
                        <span class="d-flex align-items-center gap-2 priority-<?= $pri ?>">
                            <span class="priority-dot"></span><?= ucfirst($pri) ?>
                        </span>
                    </td>
                    <td style="color:#dc2626;font-weight:500"><?= $job['end_date'] ? date('d M Y', strtotime($job['end_date'])) : '—' ?></td>
                    <td>
                        <?php $days = $job['end_date'] ? (int)round((time() - strtotime($job['end_date'])) / 86400) : 0; ?>
                        <span class="badge bg-danger"><?= $days ?> day<?= $days !== 1 ? 's' : '' ?></span>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php include __DIR__ . '/../../includes/footer.php'; ?>

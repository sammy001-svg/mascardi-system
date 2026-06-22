<?php
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
requireRole('admin');

$pageTitle = 'Workshop Dashboard';
$db = getDB();

// ── KPI Stats ──────────────────────────────────────────────────────────────
$s = [];
try {
    $s['cars_in_workshop']  = (int)$db->query("SELECT COUNT(*) FROM cars WHERE status='in_workshop'")->fetchColumn();
    $s['active_jobs']       = (int)$db->query("SELECT COUNT(*) FROM workshop_jobs WHERE status IN ('pending','in_progress','waiting_parts','on_hold')")->fetchColumn();
    $s['completed_today']   = (int)$db->query("SELECT COUNT(*) FROM workshop_jobs WHERE status='completed' AND DATE(updated_at)=CURDATE()")->fetchColumn();
    $s['completed_month']   = (int)$db->query("SELECT COUNT(*) FROM workshop_jobs WHERE status='completed' AND MONTH(updated_at)=MONTH(NOW()) AND YEAR(updated_at)=YEAR(NOW())")->fetchColumn();
    $s['overdue_jobs']      = (int)$db->query("SELECT COUNT(*) FROM workshop_jobs WHERE status NOT IN ('completed','cancelled') AND end_date < CURDATE() AND end_date IS NOT NULL")->fetchColumn();
    $s['pending_parts']     = (int)$db->query("SELECT COUNT(*) FROM parts_requests WHERE status='pending'")->fetchColumn();
    $s['low_stock']         = (int)$db->query("SELECT COUNT(*) FROM inventory WHERE quantity <= reorder_level")->fetchColumn();
    $s['open_issues']       = (int)$db->query("SELECT COUNT(*) FROM car_issues WHERE status IN ('open','in_progress')")->fetchColumn();
} catch (\Throwable $_) {
    foreach (['cars_in_workshop','active_jobs','completed_today','completed_month','overdue_jobs','pending_parts','low_stock','open_issues'] as $k) $s[$k] = 0;
}

// ── Job status breakdown (doughnut chart) ─────────────────────────────────
try {
    $jobStatuses = $db->query("SELECT status, COUNT(*) as cnt FROM workshop_jobs WHERE status NOT IN ('completed','cancelled') GROUP BY status")->fetchAll(PDO::FETCH_KEY_PAIR);
} catch (\Throwable $_) { $jobStatuses = []; }

$donutLabels = ['Pending', 'In Progress', 'Waiting Parts', 'On Hold'];
$donutKeys   = ['pending', 'in_progress', 'waiting_parts', 'on_hold'];
$donutData   = array_map(fn($k) => (int)($jobStatuses[$k] ?? 0), $donutKeys);

// ── Jobs completed per week (last 8 weeks, bar chart) ─────────────────────
try {
    $weekRows = $db->query("
        SELECT DATE_FORMAT(DATE_SUB(updated_at, INTERVAL WEEKDAY(updated_at) DAY), '%d %b') AS week_start,
               COUNT(*) AS cnt
        FROM workshop_jobs
        WHERE status='completed' AND updated_at >= DATE_SUB(NOW(), INTERVAL 56 DAY)
        GROUP BY YEARWEEK(updated_at, 1)
        ORDER BY YEARWEEK(updated_at, 1) ASC
        LIMIT 8
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (\Throwable $_) { $weekRows = []; }

$weekLabels = array_column($weekRows, 'week_start');
$weekData   = array_map('intval', array_column($weekRows, 'cnt'));

// ── Recent jobs ────────────────────────────────────────────────────────────
try {
    $recentJobs = $db->query("
        SELECT wj.id, wj.job_number, wj.status, wj.priority, wj.start_date, wj.end_date, wj.created_at,
               c.registration_number, c.make, c.model,
               m.name AS mechanic_name
        FROM workshop_jobs wj
        LEFT JOIN cars c ON c.id = wj.car_id
        LEFT JOIN mechanics m ON m.id = wj.mechanic_id
        ORDER BY wj.created_at DESC LIMIT 12
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (\Throwable $_) { $recentJobs = []; }

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

// ── Chart.js data payload ─────────────────────────────────────────────────
$chartPayload = json_encode([
    'donut' => ['labels' => $donutLabels, 'data' => $donutData],
    'bar'   => ['labels' => $weekLabels,  'data'  => $weekData],
]);

$extraJs = '<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
(function(){
    var d = ' . $chartPayload . ';

    // Doughnut — active job status
    var dc = document.getElementById("donutJobStatus");
    if (dc && d.donut.data.some(function(v){return v>0;})) {
        new Chart(dc, {
            type: "doughnut",
            data: {
                labels: d.donut.labels,
                datasets: [{
                    data: d.donut.data,
                    backgroundColor: ["#94a3b8","#2563eb","#f59e0b","#8b5cf6"],
                    borderWidth: 2,
                    borderColor: "#fff",
                    hoverOffset: 6
                }]
            },
            options: {
                cutout: "68%",
                plugins: {
                    legend: { position: "bottom", labels: { padding: 16, font: { family: "Inter", size: 12 }, usePointStyle: true, pointStyleWidth: 8 } }
                },
                maintainAspectRatio: false
            }
        });
    } else if (dc) {
        dc.parentElement.innerHTML = "<div style=\"height:220px;display:flex;align-items:center;justify-content:center;color:#94a3b8;font-size:13px\"><i class=\"fa fa-circle-check fa-2x mb-2\" style=\"display:block;text-align:center;color:#10b981\"></i><br>No active jobs</div>";
    }

    // Bar — completions per week
    var bc = document.getElementById("barWeekly");
    if (bc) {
        new Chart(bc, {
            type: "bar",
            data: {
                labels: d.bar.labels,
                datasets: [{
                    label: "Jobs Completed",
                    data: d.bar.data,
                    backgroundColor: "rgba(37,99,235,.15)",
                    borderColor: "#2563eb",
                    borderWidth: 2,
                    borderRadius: 6,
                    borderSkipped: false
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: {
                    y: { beginAtZero: true, ticks: { precision: 0, font: { family: "Inter", size: 11 } }, grid: { color: "rgba(0,0,0,.05)" } },
                    x: { ticks: { font: { family: "Inter", size: 11 } }, grid: { display: false } }
                }
            }
        });
    }
}());
</script>';

include __DIR__ . '/../../includes/header.php';
?>

<style>
.admin-kpi-card{background:var(--surface);border-radius:var(--r-lg);padding:22px 20px;box-shadow:var(--sh);display:flex;align-items:center;gap:16px;transition:transform .15s,box-shadow .15s;border:1px solid var(--border)}
.admin-kpi-card:hover{transform:translateY(-2px);box-shadow:var(--sh-md)}
.kpi-icon-wrap{width:52px;height:52px;border-radius:12px;display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:20px}
.kpi-value{font-size:30px;font-weight:700;color:var(--text);line-height:1}
.kpi-label{font-size:12px;color:var(--text-2);font-weight:500;margin-top:3px}
.section-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:16px}
.section-header h2{font-size:15px;font-weight:700;color:var(--text);margin:0}
.chart-card{background:var(--surface);border-radius:var(--r-lg);padding:22px;box-shadow:var(--sh);border:1px solid var(--border)}
.status-badge{display:inline-flex;align-items:center;gap:5px;padding:3px 9px;border-radius:20px;font-size:11px;font-weight:600;text-transform:capitalize}
.badge-pending{background:#f1f5f9;color:#475569}
.badge-in_progress{background:#dbeafe;color:#1d4ed8}
.badge-waiting_parts{background:#fef3c7;color:#b45309}
.badge-on_hold{background:#ede9fe;color:#6d28d9}
.badge-completed{background:#dcfce7;color:#15803d}
.badge-cancelled{background:#fee2e2;color:#b91c1c}
.priority-dot{width:8px;height:8px;border-radius:50%;display:inline-block;flex-shrink:0}
.priority-critical .priority-dot{background:#ef4444}
.priority-high .priority-dot{background:#f97316}
.priority-medium .priority-dot{background:#eab308}
.priority-low .priority-dot{background:#22c55e}
.dashboard-title-row{display:flex;align-items:center;justify-content:space-between;margin-bottom:28px;flex-wrap:wrap;gap:12px}
.dashboard-title-row h1{font-size:22px;font-weight:700;color:var(--text);margin:0;display:flex;align-items:center;gap:10px}
.dashboard-title-row h1 i{width:38px;height:38px;border-radius:10px;background:#fef3c7;display:flex;align-items:center;justify-content:center;font-size:17px;color:#f59e0b}
.live-badge{background:#dcfce7;color:#15803d;font-size:11px;font-weight:700;padding:3px 10px;border-radius:20px;letter-spacing:.3px}
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

<!-- ── KPI Row 1 ─────────────────────────────────────────────────────────── -->
<div class="row g-3 mb-3">
    <div class="col-xl-3 col-md-6">
        <div class="admin-kpi-card">
            <div class="kpi-icon-wrap" style="background:#dbeafe">
                <i class="fa fa-garage" style="color:#2563eb"></i>
            </div>
            <div>
                <div class="kpi-value"><?= $s['cars_in_workshop'] ?></div>
                <div class="kpi-label">Cars in Workshop</div>
            </div>
        </div>
    </div>
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
                <i class="fa fa-circle-check" style="color:#16a34a"></i>
            </div>
            <div>
                <div class="kpi-value"><?= $s['completed_today'] ?></div>
                <div class="kpi-label">Completed Today</div>
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
                <div class="kpi-label">Completed This Month</div>
            </div>
        </div>
    </div>
</div>

<!-- ── KPI Row 2 ─────────────────────────────────────────────────────────── -->
<div class="row g-3 mb-4">
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
        <div class="admin-kpi-card" style="<?= $s['pending_parts'] > 0 ? 'border-color:#fcd34d' : '' ?>">
            <div class="kpi-icon-wrap" style="background:#fef3c7">
                <i class="fa fa-boxes-stacked" style="color:#d97706"></i>
            </div>
            <div>
                <div class="kpi-value"><?= $s['pending_parts'] ?></div>
                <div class="kpi-label">Pending Parts Requests</div>
            </div>
        </div>
    </div>
    <div class="col-xl-3 col-md-6">
        <div class="admin-kpi-card" style="<?= $s['open_issues'] > 0 ? 'border-color:#fca5a5' : '' ?>">
            <div class="kpi-icon-wrap" style="background:#fee2e2">
                <i class="fa fa-triangle-exclamation" style="color:#dc2626"></i>
            </div>
            <div>
                <div class="kpi-value" style="<?= $s['open_issues'] > 0 ? 'color:#dc2626' : '' ?>"><?= $s['open_issues'] ?></div>
                <div class="kpi-label">Open Car Issues</div>
            </div>
        </div>
    </div>
    <div class="col-xl-3 col-md-6">
        <div class="admin-kpi-card" style="<?= $s['low_stock'] > 0 ? 'border-color:#fcd34d' : '' ?>">
            <div class="kpi-icon-wrap" style="background:#fef3c7">
                <i class="fa fa-battery-quarter" style="color:#d97706"></i>
            </div>
            <div>
                <div class="kpi-value" style="<?= $s['low_stock'] > 0 ? 'color:#d97706' : '' ?>"><?= $s['low_stock'] ?></div>
                <div class="kpi-label">Low Stock Items</div>
            </div>
        </div>
    </div>
</div>

<!-- ── Charts Row ────────────────────────────────────────────────────────── -->
<div class="row g-3 mb-4">
    <div class="col-xl-5">
        <div class="chart-card h-100">
            <div class="section-header mb-3">
                <h2><i class="fa fa-chart-pie me-2" style="color:#2563eb"></i>Active Job Status</h2>
                <span style="font-size:12px;color:var(--text-3)"><?= $s['active_jobs'] ?> total active</span>
            </div>
            <div style="height:240px;position:relative">
                <canvas id="donutJobStatus"></canvas>
            </div>
        </div>
    </div>
    <div class="col-xl-7">
        <div class="chart-card h-100">
            <div class="section-header mb-3">
                <h2><i class="fa fa-chart-bar me-2" style="color:#2563eb"></i>Jobs Completed — Last 8 Weeks</h2>
            </div>
            <div style="height:240px;position:relative">
                <?php if (!empty($weekLabels)): ?>
                <canvas id="barWeekly"></canvas>
                <?php else: ?>
                <div style="height:100%;display:flex;align-items:center;justify-content:center;color:var(--text-3);font-size:13px">
                    <div class="text-center"><i class="fa fa-chart-bar fa-2x mb-2 d-block" style="color:#e2e8f0"></i>No completion data yet</div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- ── Recent Jobs Table ─────────────────────────────────────────────────── -->
<div class="chart-card mb-4">
    <div class="section-header">
        <h2><i class="fa fa-list-check me-2" style="color:#2563eb"></i>Recent Job Cards</h2>
        <a href="<?= BASE_URL ?>/modules/jobs/index.php" class="btn btn-sm btn-outline-primary">View All</a>
    </div>
    <div class="table-responsive">
        <table class="table table-hover mb-0" style="font-size:13px">
            <thead>
                <tr style="font-size:11px;text-transform:uppercase;letter-spacing:.4px;color:var(--text-3);border-bottom:2px solid var(--border)">
                    <th class="ps-0">Job #</th>
                    <th>Vehicle</th>
                    <th>Mechanic</th>
                    <th>Priority</th>
                    <th>Status</th>
                    <th>Start Date</th>
                    <th>Due Date</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($recentJobs)): ?>
                <tr><td colspan="7" class="text-center py-5" style="color:var(--text-3)">
                    <i class="fa fa-toolbox fa-2x mb-2 d-block"></i>No job cards yet
                </td></tr>
                <?php else: ?>
                <?php foreach ($recentJobs as $job): ?>
                <tr>
                    <td class="ps-0 fw-semibold">
                        <a href="<?= BASE_URL ?>/modules/jobs/view.php?id=<?= $job['id'] ?>"
                           style="color:var(--brand);text-decoration:none"><?= e($job['job_number'] ?? '—') ?></a>
                    </td>
                    <td>
                        <span class="fw-medium"><?= e($job['registration_number'] ?? '—') ?></span>
                        <br><span style="color:var(--text-2);font-size:11.5px"><?= e(($job['make'] ?? '') . ' ' . ($job['model'] ?? '')) ?></span>
                    </td>
                    <td><?= e($job['mechanic_name'] ?? 'Unassigned') ?></td>
                    <td>
                        <?php $pri = strtolower($job['priority'] ?? 'low'); ?>
                        <span class="d-flex align-items-center gap-2 priority-<?= $pri ?>">
                            <span class="priority-dot"></span>
                            <?= ucfirst($pri) ?>
                        </span>
                    </td>
                    <td>
                        <?php $st = strtolower($job['status'] ?? 'pending'); ?>
                        <span class="status-badge badge-<?= $st ?>"><?= ucwords(str_replace('_',' ',$st)) ?></span>
                    </td>
                    <td style="color:var(--text-2)"><?= $job['start_date'] ? date('d M Y', strtotime($job['start_date'])) : '—' ?></td>
                    <td style="color:<?= ($job['end_date'] && $job['end_date'] < date('Y-m-d') && !in_array($job['status'],['completed','cancelled'])) ? '#dc2626' : 'var(--text-2)' ?>">
                        <?= $job['end_date'] ? date('d M Y', strtotime($job['end_date'])) : '—' ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- ── Overdue Alerts ────────────────────────────────────────────────────── -->
<?php if (!empty($overdueList)): ?>
<div class="chart-card mb-4" style="border-color:#fca5a5">
    <div class="section-header">
        <h2 style="color:#dc2626"><i class="fa fa-circle-exclamation me-2"></i>Overdue Jobs — Require Attention</h2>
        <span class="badge bg-danger"><?= count($overdueList) ?> overdue</span>
    </div>
    <div class="table-responsive">
        <table class="table mb-0" style="font-size:13px">
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
                        <span class="fw-medium"><?= e($job['registration_number'] ?? '—') ?></span>
                        <br><span style="color:var(--text-2);font-size:11.5px"><?= e(($job['make'] ?? '') . ' ' . ($job['model'] ?? '')) ?></span>
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

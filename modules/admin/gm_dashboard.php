<?php
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();

// Allow general_manager, admin, and super_admin to view this dashboard
if (!in_array(authRole(), ['general_manager', 'admin', 'super_admin'])) {
    header('Location: ' . BASE_URL . '/index.php');
    exit;
}

$pageTitle = 'General Manager Dashboard';
$db   = getDB();
$user = authUser();

// ── KPI Stats ───────────────────────────────────────────────────────────────

// Revenue MTD
try {
    $revMTD = (float)$db->query("SELECT COALESCE(SUM(total),0) FROM invoices WHERE status='paid' AND MONTH(created_at)=MONTH(NOW()) AND YEAR(created_at)=YEAR(NOW())")->fetchColumn();
} catch (\Throwable $_) { $revMTD = 0; }

// Cars sold MTD
try {
    $carsSold = (int)$db->query("SELECT COUNT(*) FROM car_sales WHERE status='active' AND MONTH(sale_date)=MONTH(NOW()) AND YEAR(sale_date)=YEAR(NOW())")->fetchColumn();
} catch (\Throwable $_) { $carsSold = 0; }

// Active CRM pipeline
try {
    $activePipeline = (int)$db->query("SELECT COUNT(*) FROM crm_leads WHERE stage NOT IN ('lost','delivered')")->fetchColumn();
} catch (\Throwable $_) { $activePipeline = 0; }

// Open job cards
try {
    $openJobs = (int)$db->query("SELECT COUNT(*) FROM workshop_jobs WHERE status NOT IN ('completed','cancelled')")->fetchColumn();
} catch (\Throwable $_) { $openJobs = 0; }

// Fleet size
try {
    $totalCars = (int)$db->query("SELECT COUNT(*) FROM cars")->fetchColumn();
} catch (\Throwable $_) { $totalCars = 0; }

// In workshop
try {
    $inWorkshop = (int)$db->query("SELECT COUNT(*) FROM cars WHERE status='in_workshop'")->fetchColumn();
} catch (\Throwable $_) { $inWorkshop = 0; }

// Unpaid invoices
try {
    $unpaidInvoices = (int)$db->query("SELECT COUNT(*) FROM invoices WHERE status='unpaid'")->fetchColumn();
} catch (\Throwable $_) { $unpaidInvoices = 0; }

// Low stock parts
try {
    $lowStock = (int)$db->query("SELECT COUNT(*) FROM inventory WHERE quantity <= reorder_level")->fetchColumn();
} catch (\Throwable $_) { $lowStock = 0; }

// ── Revenue last 6 months for chart ─────────────────────────────────────────
$revLabels = [];
$revValues = [];
try {
    for ($i = 5; $i >= 0; $i--) {
        $ym = date('Y-m', strtotime("-{$i} months"));
        $revLabels[] = date('M Y', strtotime($ym . '-01'));
        $revValues[] = (float)$db->query("SELECT COALESCE(SUM(total),0) FROM invoices WHERE status='paid' AND DATE_FORMAT(created_at,'%Y-%m')='{$ym}'")->fetchColumn();
    }
} catch (\Throwable $_) {
    for ($i = 5; $i >= 0; $i--) {
        $revLabels[] = date('M Y', strtotime("-{$i} months"));
        $revValues[] = 0;
    }
}

// ── Fleet status doughnut ────────────────────────────────────────────────────
try {
    $fleetStatus = $db->query("SELECT status, COUNT(*) cnt FROM cars GROUP BY status ORDER BY cnt DESC")->fetchAll();
} catch (\Throwable $_) { $fleetStatus = []; }

// ── CRM stage distribution ───────────────────────────────────────────────────
try {
    $crmStages = $db->query("SELECT stage, COUNT(*) cnt FROM crm_leads WHERE stage NOT IN ('lost','delivered') GROUP BY stage ORDER BY cnt DESC")->fetchAll();
} catch (\Throwable $_) { $crmStages = []; }

// ── Overdue jobs (max 5) ─────────────────────────────────────────────────────
try {
    $overdueJobs = $db->query("
        SELECT j.id, j.job_number, j.end_date, j.priority, c.make, c.model
        FROM workshop_jobs j
        JOIN cars c ON c.id = j.car_id
        WHERE j.status NOT IN ('completed','cancelled')
          AND j.end_date < CURDATE()
          AND j.end_date IS NOT NULL
        ORDER BY j.end_date ASC
        LIMIT 5
    ")->fetchAll();
} catch (\Throwable $_) { $overdueJobs = []; }

// ── Recent confirmed payments (last 5) ───────────────────────────────────────
try {
    $recentPayments = $db->query("
        SELECT client_name, amount, payment_method, created_at
        FROM payments
        WHERE status = 'confirmed'
        ORDER BY created_at DESC
        LIMIT 5
    ")->fetchAll();
} catch (\Throwable $_) { $recentPayments = []; }

// ── Recent job activity (last 8) ─────────────────────────────────────────────
try {
    $recentJobs = $db->query("
        SELECT j.id, j.job_number, j.status, j.priority, j.updated_at, c.make, c.model
        FROM workshop_jobs j
        JOIN cars c ON c.id = j.car_id
        ORDER BY j.updated_at DESC
        LIMIT 8
    ")->fetchAll();
} catch (\Throwable $_) { $recentJobs = []; }

// ── Chart JSON payloads ──────────────────────────────────────────────────────
$chartRevLabels  = json_encode($revLabels);
$chartRevValues  = json_encode($revValues);

$fleetLabels = array_map(fn($r) => ucwords(str_replace('_', ' ', $r['status'])), $fleetStatus);
$fleetCounts = array_column($fleetStatus, 'cnt');
$chartFleetLabels = json_encode($fleetLabels);
$chartFleetCounts = json_encode($fleetCounts);

// ── Chart.js scripts (loaded via $extraJs before header) ────────────────────
$extraJs = '<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
(function () {
    // Revenue Bar Chart
    var rc = document.getElementById("gmRevenueChart");
    if (rc) {
        new Chart(rc, {
            type: "bar",
            data: {
                labels: ' . $chartRevLabels . ',
                datasets: [{
                    label: "Revenue (KES)",
                    data: ' . $chartRevValues . ',
                    backgroundColor: "rgba(37,99,235,0.75)",
                    borderColor: "#2563eb",
                    borderWidth: 1,
                    borderRadius: 5,
                    borderSkipped: false
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: function(ctx) {
                                var v = ctx.raw;
                                return " KES " + (v >= 1e6 ? (v/1e6).toFixed(2)+"M" : v >= 1e3 ? (v/1e3).toFixed(1)+"K" : v.toFixed(0));
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        grid: { color: "rgba(0,0,0,0.05)" },
                        ticks: {
                            font: { family: "Inter", size: 11 },
                            callback: function(v) {
                                return v >= 1e6 ? (v/1e6).toFixed(1)+"M" : v >= 1e3 ? (v/1e3).toFixed(0)+"K" : v;
                            }
                        }
                    },
                    x: { grid: { display: false }, ticks: { font: { family: "Inter", size: 11 } } }
                }
            }
        });
    }

    // Fleet Status Doughnut
    var fc = document.getElementById("gmFleetChart");
    if (fc) {
        var fleetLabels = ' . $chartFleetLabels . ';
        var fleetCounts = ' . $chartFleetCounts . ';
        var colorMap = {
            "In Transit":    "#d97706",
            "In Workshop":   "#db2777",
            "Completed":     "#16a34a",
            "Arrived":       "#0284c7",
            "In Assessment": "#7c3aed",
            "Sold":          "#0f172a",
            "Delivered":     "#475569",
            "Active":        "#2563eb"
        };
        new Chart(fc, {
            type: "doughnut",
            data: {
                labels: fleetLabels,
                datasets: [{
                    data: fleetCounts,
                    backgroundColor: fleetLabels.map(function(l) { return colorMap[l] || "#94a3b8"; }),
                    borderWidth: 2,
                    borderColor: "#ffffff",
                    hoverOffset: 6
                }]
            },
            options: {
                cutout: "64%",
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: "bottom",
                        labels: { font: { size: 11, family: "Inter" }, padding: 14, boxWidth: 12, usePointStyle: true }
                    }
                }
            }
        });
    }
}());
</script>';

include __DIR__ . '/../../includes/header.php';
?>

<style>
/* ── GM Dashboard scoped styles ─────────────────────────────────────────── */
.gm-kpi-card {
    background: var(--surface);
    border-radius: 12px;
    padding: 18px 20px;
    box-shadow: var(--sh);
    border: 1px solid var(--border);
    display: flex;
    align-items: center;
    gap: 14px;
    transition: transform .15s, box-shadow .15s;
    border-left-width: 4px !important;
    text-decoration: none;
    color: inherit;
}
.gm-kpi-card:hover { transform: translateY(-2px); box-shadow: var(--sh-md); color: inherit; }
.gm-kpi-icon {
    width: 48px; height: 48px; border-radius: 10px;
    display: flex; align-items: center; justify-content: center;
    flex-shrink: 0; font-size: 18px;
}
.gm-kpi-val  { font-size: 24px; font-weight: 700; color: var(--text); line-height: 1; }
.gm-kpi-val-sm { font-size: 17px; font-weight: 700; color: var(--text); line-height: 1; }
.gm-kpi-lbl  { font-size: 11.5px; color: var(--text-2); font-weight: 500; margin-top: 3px; }
.gm-section-card {
    background: var(--surface);
    border-radius: 12px;
    box-shadow: var(--sh);
    border: 1px solid var(--border);
}
.gm-section-hdr {
    display: flex; align-items: center; justify-content: space-between;
    padding: 14px 18px; border-bottom: 1px solid var(--border);
}
.gm-section-hdr-title {
    font-size: 12px; font-weight: 700; text-transform: uppercase;
    letter-spacing: .5px; color: var(--text-2);
    display: flex; align-items: center; gap: 8px;
}
.gm-section-body { padding: 16px 18px; }
.gm-welcome-bar {
    background: linear-gradient(135deg, #1e3a8a 0%, #2563eb 60%, #3b82f6 100%);
    border-radius: 14px;
    padding: 22px 28px;
    color: #fff;
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 16px;
    margin-bottom: 24px;
    box-shadow: 0 4px 18px rgba(37,99,235,0.3);
}
.gm-welcome-stats { display: flex; align-items: center; gap: 28px; flex-wrap: wrap; }
.gm-ws-item { text-align: center; }
.gm-ws-val  { font-size: 22px; font-weight: 700; line-height: 1; color: #fff; }
.gm-ws-lbl  { font-size: 11px; color: rgba(255,255,255,.75); margin-top: 2px; }
.gm-ws-div  { width: 1px; height: 36px; background: rgba(255,255,255,.25); }
.gm-stage-pill {
    display: inline-flex; align-items: center; justify-content: space-between;
    padding: 6px 12px; border-radius: 8px; margin-bottom: 6px; width: 100%;
    font-size: 13px; font-weight: 500;
}
.gm-nav-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(130px, 1fr)); gap: 12px; }
.gm-nav-tile {
    display: flex; flex-direction: column; align-items: center; justify-content: center;
    padding: 18px 12px; border-radius: 12px; text-decoration: none;
    background: var(--surface); border: 1px solid var(--border);
    transition: transform .15s, box-shadow .15s; gap: 10px; text-align: center;
}
.gm-nav-tile:hover { transform: translateY(-2px); box-shadow: var(--sh-md); }
.gm-nav-icon {
    width: 44px; height: 44px; border-radius: 10px;
    display: flex; align-items: center; justify-content: center; font-size: 18px;
}
.gm-nav-label { font-size: 12px; font-weight: 600; color: var(--text); }
</style>

<!-- ── Welcome Banner ─────────────────────────────────────────────────────── -->
<div class="gm-welcome-bar">
    <div>
        <h5 class="mb-1 fw-bold" style="font-size:18px">
            <i class="fa fa-briefcase me-2" style="opacity:.85"></i>Welcome, <?= e($user['name']) ?>
        </h5>
        <div style="font-size:13px;opacity:.85">
            <?= date('l, d F Y') ?>
            &nbsp;&bull;&nbsp;
            <span style="background:rgba(255,255,255,.2);padding:2px 10px;border-radius:20px;font-size:12px;font-weight:600">
                <?= ucwords(str_replace('_', ' ', $user['role'])) ?>
            </span>
        </div>
    </div>
    <div class="gm-welcome-stats">
        <div class="gm-ws-item">
            <div class="gm-ws-val"><?= $totalCars ?></div>
            <div class="gm-ws-lbl">Total Fleet</div>
        </div>
        <div class="gm-ws-div"></div>
        <div class="gm-ws-item">
            <div class="gm-ws-val"><?= $openJobs ?></div>
            <div class="gm-ws-lbl">Open Jobs</div>
        </div>
        <div class="gm-ws-div"></div>
        <div class="gm-ws-item">
            <div class="gm-ws-val" style="font-size:16px"><?= 'KES ' . ($revMTD >= 1e6 ? number_format($revMTD / 1e6, 2) . 'M' : number_format($revMTD / 1e3, 1) . 'K') ?></div>
            <div class="gm-ws-lbl">Revenue MTD</div>
        </div>
        <div class="gm-ws-div d-none d-sm-block"></div>
        <div class="gm-ws-item d-none d-sm-block">
            <button onclick="location.reload()" class="btn btn-sm" style="background:rgba(255,255,255,.15);color:#fff;border:1px solid rgba(255,255,255,.3);font-size:12px">
                <i class="fa fa-rotate-right me-1"></i>Refresh
            </button>
            <div class="gm-ws-lbl">Updated <?= date('H:i') ?></div>
        </div>
    </div>
</div>

<!-- ── KPI Cards Row 1 ────────────────────────────────────────────────────── -->
<div class="row g-3 mb-3">
    <div class="col-sm-6 col-xl-3">
        <a href="<?= BASE_URL ?>/modules/invoices/index.php?status=paid" class="gm-kpi-card" style="border-left-color:#16a34a">
            <div class="gm-kpi-icon" style="background:#dcfce7;color:#16a34a"><i class="fa fa-money-bill-wave"></i></div>
            <div>
                <div class="gm-kpi-val-sm"><?= money($revMTD) ?></div>
                <div class="gm-kpi-lbl">Revenue MTD</div>
            </div>
        </a>
    </div>
    <div class="col-sm-6 col-xl-3">
        <a href="<?= BASE_URL ?>/modules/sales/index.php" class="gm-kpi-card" style="border-left-color:#0f172a">
            <div class="gm-kpi-icon" style="background:#f1f5f9;color:#0f172a"><i class="fa fa-tag"></i></div>
            <div>
                <div class="gm-kpi-val"><?= $carsSold ?></div>
                <div class="gm-kpi-lbl">Cars Sold MTD</div>
            </div>
        </a>
    </div>
    <div class="col-sm-6 col-xl-3">
        <a href="<?= BASE_URL ?>/modules/crm/index.php" class="gm-kpi-card" style="border-left-color:#7c3aed">
            <div class="gm-kpi-icon" style="background:#ede9fe;color:#7c3aed"><i class="fa fa-funnel"></i></div>
            <div>
                <div class="gm-kpi-val"><?= $activePipeline ?></div>
                <div class="gm-kpi-lbl">Active Pipeline</div>
            </div>
        </a>
    </div>
    <div class="col-sm-6 col-xl-3">
        <a href="<?= BASE_URL ?>/modules/jobs/index.php" class="gm-kpi-card" style="border-left-color:#f59e0b">
            <div class="gm-kpi-icon" style="background:#fef3c7;color:#f59e0b"><i class="fa fa-clipboard-list"></i></div>
            <div>
                <div class="gm-kpi-val"><?= $openJobs ?></div>
                <div class="gm-kpi-lbl">Open Job Cards</div>
            </div>
        </a>
    </div>
</div>

<!-- ── KPI Cards Row 2 ────────────────────────────────────────────────────── -->
<div class="row g-3 mb-4">
    <div class="col-sm-6 col-xl-3">
        <a href="<?= BASE_URL ?>/modules/cars/index.php" class="gm-kpi-card" style="border-left-color:#2563eb">
            <div class="gm-kpi-icon" style="background:#dbeafe;color:#2563eb"><i class="fa fa-car"></i></div>
            <div>
                <div class="gm-kpi-val"><?= $totalCars ?></div>
                <div class="gm-kpi-lbl">Fleet Size</div>
            </div>
        </a>
    </div>
    <div class="col-sm-6 col-xl-3">
        <a href="<?= BASE_URL ?>/modules/cars/index.php?status=in_workshop" class="gm-kpi-card" style="border-left-color:#db2777">
            <div class="gm-kpi-icon" style="background:#fce7f3;color:#db2777"><i class="fa fa-screwdriver-wrench"></i></div>
            <div>
                <div class="gm-kpi-val"><?= $inWorkshop ?></div>
                <div class="gm-kpi-lbl">Cars in Workshop</div>
            </div>
        </a>
    </div>
    <div class="col-sm-6 col-xl-3">
        <a href="<?= BASE_URL ?>/modules/invoices/index.php?status=unpaid" class="gm-kpi-card" style="border-left-color:#dc2626">
            <div class="gm-kpi-icon" style="background:#fee2e2;color:#dc2626"><i class="fa fa-file-invoice-dollar"></i></div>
            <div>
                <div class="gm-kpi-val" style="<?= $unpaidInvoices > 0 ? 'color:#dc2626' : '' ?>"><?= $unpaidInvoices ?></div>
                <div class="gm-kpi-lbl">Unpaid Invoices</div>
            </div>
        </a>
    </div>
    <div class="col-sm-6 col-xl-3">
        <a href="<?= BASE_URL ?>/modules/inventory/index.php" class="gm-kpi-card" style="border-left-color:<?= $lowStock > 0 ? '#ea580c' : '#64748b' ?>">
            <div class="gm-kpi-icon" style="background:<?= $lowStock > 0 ? '#fff7ed' : '#f8fafc' ?>;color:<?= $lowStock > 0 ? '#ea580c' : '#64748b' ?>"><i class="fa fa-boxes-stacked"></i></div>
            <div>
                <div class="gm-kpi-val" style="<?= $lowStock > 0 ? 'color:#ea580c' : '' ?>"><?= $lowStock ?></div>
                <div class="gm-kpi-lbl">Low Stock Parts</div>
            </div>
        </a>
    </div>
</div>

<!-- ── Charts Row ─────────────────────────────────────────────────────────── -->
<div class="row g-3 mb-4">
    <!-- Revenue Bar Chart -->
    <div class="col-lg-8">
        <div class="gm-section-card h-100">
            <div class="gm-section-hdr">
                <span class="gm-section-hdr-title"><i class="fa fa-chart-column" style="color:#2563eb"></i>Revenue &mdash; Last 6 Months</span>
                <a href="<?= BASE_URL ?>/modules/invoices/index.php" class="btn btn-xs btn-outline-primary">All Invoices</a>
            </div>
            <div class="gm-section-body" style="height:240px;position:relative">
                <canvas id="gmRevenueChart"></canvas>
            </div>
        </div>
    </div>
    <!-- Fleet Status Doughnut -->
    <div class="col-lg-4">
        <div class="gm-section-card h-100">
            <div class="gm-section-hdr">
                <span class="gm-section-hdr-title"><i class="fa fa-chart-pie" style="color:#7c3aed"></i>Fleet Status</span>
                <a href="<?= BASE_URL ?>/modules/cars/index.php" class="btn btn-xs btn-outline-secondary">View Cars</a>
            </div>
            <div class="gm-section-body" style="height:240px;position:relative">
                <?php if (!empty($fleetStatus)): ?>
                <canvas id="gmFleetChart"></canvas>
                <?php else: ?>
                <div class="d-flex align-items-center justify-content-center h-100 text-muted small">No fleet data available.</div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- ── Operational Summary Row ───────────────────────────────────────────── -->
<div class="row g-3 mb-4">

    <!-- Col A: CRM Pipeline Snapshot -->
    <div class="col-lg-4">
        <div class="gm-section-card h-100">
            <div class="gm-section-hdr">
                <span class="gm-section-hdr-title"><i class="fa fa-people-arrows" style="color:#7c3aed"></i>CRM Pipeline</span>
                <a href="<?= BASE_URL ?>/modules/crm/index.php" class="btn btn-xs btn-outline-secondary">Open CRM</a>
            </div>
            <div class="gm-section-body">
                <?php if (empty($crmStages)): ?>
                <p class="text-muted small text-center py-3 mb-0">No active pipeline leads.</p>
                <?php else: ?>
                <?php
                $stageMeta = [
                    'hot'         => ['bg' => '#fee2e2', 'color' => '#b91c1c', 'icon' => 'fa-fire'],
                    'warm'        => ['bg' => '#fef3c7', 'color' => '#b45309', 'icon' => 'fa-sun'],
                    'lukewarm'    => ['bg' => '#fef9c3', 'color' => '#a16207', 'icon' => 'fa-temperature-half'],
                    'cold'        => ['bg' => '#cffafe', 'color' => '#0e7490', 'icon' => 'fa-snowflake'],
                    'new'         => ['bg' => '#dbeafe', 'color' => '#1d4ed8', 'icon' => 'fa-star'],
                    'contacted'   => ['bg' => '#e0f2fe', 'color' => '#0369a1', 'icon' => 'fa-phone'],
                    'reserved'    => ['bg' => '#f3e8ff', 'color' => '#7e22ce', 'icon' => 'fa-bookmark'],
                    'follow_up'   => ['bg' => '#d1fae5', 'color' => '#065f46', 'icon' => 'fa-rotate-right'],
                    'negotiation' => ['bg' => '#fde68a', 'color' => '#92400e', 'icon' => 'fa-handshake'],
                ];
                $totalPipeline = array_sum(array_column($crmStages, 'cnt'));
                foreach ($crmStages as $stage):
                    $slug = strtolower(str_replace([' ', '-'], '_', $stage['stage']));
                    $meta = $stageMeta[$slug] ?? ['bg' => '#f1f5f9', 'color' => '#475569', 'icon' => 'fa-circle'];
                    $pct  = $totalPipeline > 0 ? round(($stage['cnt'] / $totalPipeline) * 100) : 0;
                ?>
                <div class="gm-stage-pill" style="background:<?= $meta['bg'] ?>;color:<?= $meta['color'] ?>">
                    <span><i class="fa <?= $meta['icon'] ?> me-2" style="font-size:12px"></i><?= ucwords(str_replace('_', ' ', $stage['stage'])) ?></span>
                    <span>
                        <strong><?= $stage['cnt'] ?></strong>
                        <span style="opacity:.65;font-size:11px;margin-left:4px"><?= $pct ?>%</span>
                    </span>
                </div>
                <?php endforeach; ?>
                <div class="mt-3 pt-2 border-top" style="font-size:11.5px;color:var(--text-2)">
                    <i class="fa fa-sigma me-1"></i> <strong><?= $totalPipeline ?></strong> active leads in pipeline
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Col B: Workshop Snapshot — Overdue Jobs -->
    <div class="col-lg-4">
        <div class="gm-section-card h-100" style="<?= !empty($overdueJobs) ? 'border-top:3px solid #dc2626' : '' ?>">
            <div class="gm-section-hdr">
                <span class="gm-section-hdr-title" style="<?= !empty($overdueJobs) ? 'color:#dc2626' : '' ?>">
                    <i class="fa fa-clock" style="color:#dc2626"></i>Overdue Jobs
                </span>
                <a href="<?= BASE_URL ?>/modules/jobs/index.php" class="btn btn-xs btn-outline-danger">All Jobs</a>
            </div>
            <div class="gm-section-body p-0">
                <?php if (empty($overdueJobs)): ?>
                <div class="text-center text-success py-4 small">
                    <i class="fa fa-circle-check fa-2x mb-2 d-block"></i>No overdue jobs
                </div>
                <?php else: ?>
                <div class="list-group list-group-flush" style="font-size:12.5px">
                    <?php foreach ($overdueJobs as $j):
                        $days = (int)round((time() - strtotime($j['end_date'])) / 86400);
                        $priColor = in_array(strtolower($j['priority'] ?? ''), ['urgent','high','critical']) ? '#dc2626' : '#f59e0b';
                    ?>
                    <a href="<?= BASE_URL ?>/modules/jobs/view.php?id=<?= $j['id'] ?>" class="list-group-item list-group-item-action py-2 px-3">
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <div class="fw-semibold" style="color:#dc2626"><?= e($j['job_number']) ?></div>
                                <div class="text-muted" style="font-size:11px"><?= e($j['make'] . ' ' . $j['model']) ?></div>
                            </div>
                            <span class="badge bg-danger" style="font-size:10px;white-space:nowrap"><?= $days ?>d overdue</span>
                        </div>
                        <div class="mt-1" style="font-size:11px">
                            Due <strong><?= fmtDate($j['end_date'], 'd M Y') ?></strong>
                            &bull; Priority: <span style="color:<?= $priColor ?>;font-weight:600"><?= ucfirst($j['priority'] ?? 'normal') ?></span>
                        </div>
                    </a>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Col C: Finance Snapshot — Recent Payments -->
    <div class="col-lg-4">
        <div class="gm-section-card h-100">
            <div class="gm-section-hdr">
                <span class="gm-section-hdr-title"><i class="fa fa-money-bill-transfer" style="color:#16a34a"></i>Recent Payments</span>
                <a href="<?= BASE_URL ?>/modules/payments/index.php" class="btn btn-xs btn-outline-secondary">All Payments</a>
            </div>
            <div class="gm-section-body p-0">
                <?php if (empty($recentPayments)): ?>
                <div class="text-center text-muted py-4 small">No recent confirmed payments.</div>
                <?php else: ?>
                <div class="list-group list-group-flush" style="font-size:12.5px">
                    <?php foreach ($recentPayments as $p): ?>
                    <div class="list-group-item py-2 px-3">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <div class="fw-semibold"><?= e($p['client_name'] ?: '—') ?></div>
                                <div class="text-muted" style="font-size:11px">
                                    <?= ucwords(str_replace('_', ' ', $p['payment_method'] ?? '')) ?>
                                    &bull; <?= fmtDate($p['created_at'], 'd M H:i') ?>
                                </div>
                            </div>
                            <span class="fw-bold text-success" style="font-size:13px"><?= money((float)$p['amount']) ?></span>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- ── Recent Activity Feed ──────────────────────────────────────────────── -->
<div class="gm-section-card mb-4">
    <div class="gm-section-hdr">
        <span class="gm-section-hdr-title"><i class="fa fa-clock-rotate-left" style="color:#2563eb"></i>Recent Workshop Activity</span>
        <a href="<?= BASE_URL ?>/modules/jobs/index.php" class="btn btn-xs btn-outline-primary">All Jobs</a>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0" style="font-size:12.5px">
                <thead>
                    <tr style="font-size:11px;text-transform:uppercase;letter-spacing:.4px;color:var(--text-3)">
                        <th class="ps-3">Job #</th>
                        <th>Vehicle</th>
                        <th>Status</th>
                        <th>Priority</th>
                        <th class="text-end pe-3">Updated</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($recentJobs)): ?>
                    <tr><td colspan="5" class="text-center py-4 text-muted small">No job activity yet.</td></tr>
                    <?php else: foreach ($recentJobs as $j): ?>
                    <tr>
                        <td class="ps-3">
                            <a href="<?= BASE_URL ?>/modules/jobs/view.php?id=<?= $j['id'] ?>" class="fw-semibold text-decoration-none" style="color:var(--brand)">
                                <?= e($j['job_number']) ?>
                            </a>
                        </td>
                        <td class="fw-medium"><?= e($j['make'] . ' ' . $j['model']) ?></td>
                        <td><?= statusBadge($j['status']) ?></td>
                        <td>
                            <?php
                            $pri = strtolower($j['priority'] ?? 'normal');
                            $priColors = ['urgent' => '#dc2626', 'high' => '#dc2626', 'critical' => '#dc2626', 'medium' => '#d97706', 'low' => '#16a34a'];
                            $pc = $priColors[$pri] ?? '#64748b';
                            ?>
                            <span style="display:inline-flex;align-items:center;gap:5px;font-size:12px">
                                <span style="width:7px;height:7px;border-radius:50%;background:<?= $pc ?>;flex-shrink:0"></span>
                                <?= ucfirst($pri) ?>
                            </span>
                        </td>
                        <td class="text-end pe-3 text-muted"><?= fmtDate($j['updated_at'], 'd M H:i') ?></td>
                    </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- ── Quick Navigation Tiles ────────────────────────────────────────────── -->
<div class="gm-section-card mb-4">
    <div class="gm-section-hdr">
        <span class="gm-section-hdr-title"><i class="fa fa-grid-2" style="color:#2563eb"></i>Quick Navigation</span>
    </div>
    <div class="gm-section-body">
        <div class="gm-nav-grid">

            <?php if (canAccess('crm')): ?>
            <a href="<?= BASE_URL ?>/modules/crm/index.php" class="gm-nav-tile">
                <div class="gm-nav-icon" style="background:#ede9fe;color:#7c3aed"><i class="fa fa-people-arrows fa-lg"></i></div>
                <span class="gm-nav-label">CRM</span>
            </a>
            <?php endif; ?>

            <?php if (canAccess('cars')): ?>
            <a href="<?= BASE_URL ?>/modules/cars/index.php" class="gm-nav-tile">
                <div class="gm-nav-icon" style="background:#dbeafe;color:#2563eb"><i class="fa fa-car fa-lg"></i></div>
                <span class="gm-nav-label">Inventory</span>
            </a>
            <?php endif; ?>

            <?php if (canAccess('jobs')): ?>
            <a href="<?= BASE_URL ?>/modules/jobs/index.php" class="gm-nav-tile">
                <div class="gm-nav-icon" style="background:#fef3c7;color:#d97706"><i class="fa fa-toolbox fa-lg"></i></div>
                <span class="gm-nav-label">Jobs</span>
            </a>
            <?php endif; ?>

            <?php if (canAccess('reports')): ?>
            <a href="<?= BASE_URL ?>/modules/reports/index.php" class="gm-nav-tile">
                <div class="gm-nav-icon" style="background:#f0fdf4;color:#16a34a"><i class="fa fa-chart-bar fa-lg"></i></div>
                <span class="gm-nav-label">Reports</span>
            </a>
            <?php endif; ?>

            <?php if (canAccess('sales')): ?>
            <a href="<?= BASE_URL ?>/modules/sales/index.php" class="gm-nav-tile">
                <div class="gm-nav-icon" style="background:#f1f5f9;color:#0f172a"><i class="fa fa-tag fa-lg"></i></div>
                <span class="gm-nav-label">Sales</span>
            </a>
            <?php endif; ?>

            <?php if (canAccess('invoices')): ?>
            <a href="<?= BASE_URL ?>/modules/invoices/index.php" class="gm-nav-tile">
                <div class="gm-nav-icon" style="background:#fee2e2;color:#dc2626"><i class="fa fa-file-invoice-dollar fa-lg"></i></div>
                <span class="gm-nav-label">Finance</span>
            </a>
            <?php endif; ?>

            <?php if (canAccess('imports')): ?>
            <a href="<?= BASE_URL ?>/modules/imports/index.php" class="gm-nav-tile">
                <div class="gm-nav-icon" style="background:#e0f2fe;color:#0284c7"><i class="fa fa-ship fa-lg"></i></div>
                <span class="gm-nav-label">Imports</span>
            </a>
            <?php endif; ?>

            <?php if (canAccess('attendance')): ?>
            <a href="<?= BASE_URL ?>/modules/attendance/index.php" class="gm-nav-tile">
                <div class="gm-nav-icon" style="background:#f3e8ff;color:#9333ea"><i class="fa fa-user-clock fa-lg"></i></div>
                <span class="gm-nav-label">Attendance</span>
            </a>
            <?php endif; ?>

            <?php if (canAccess('payroll')): ?>
            <a href="<?= BASE_URL ?>/modules/payroll/index.php" class="gm-nav-tile">
                <div class="gm-nav-icon" style="background:#dcfce7;color:#15803d"><i class="fa fa-wallet fa-lg"></i></div>
                <span class="gm-nav-label">Payroll</span>
            </a>
            <?php endif; ?>

            <?php if (canAccess('inventory')): ?>
            <a href="<?= BASE_URL ?>/modules/inventory/index.php" class="gm-nav-tile">
                <div class="gm-nav-icon" style="background:#fff7ed;color:#ea580c"><i class="fa fa-boxes-stacked fa-lg"></i></div>
                <span class="gm-nav-label">Parts Stock</span>
            </a>
            <?php endif; ?>

            <?php if (canAccess('payments')): ?>
            <a href="<?= BASE_URL ?>/modules/payments/index.php" class="gm-nav-tile">
                <div class="gm-nav-icon" style="background:#d1fae5;color:#059669"><i class="fa fa-money-bill-transfer fa-lg"></i></div>
                <span class="gm-nav-label">Payments</span>
            </a>
            <?php endif; ?>

            <?php if (canAccess('clients')): ?>
            <a href="<?= BASE_URL ?>/modules/clients/index.php" class="gm-nav-tile">
                <div class="gm-nav-icon" style="background:#e0f2fe;color:#0369a1"><i class="fa fa-users fa-lg"></i></div>
                <span class="gm-nav-label">Clients</span>
            </a>
            <?php endif; ?>

        </div>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>

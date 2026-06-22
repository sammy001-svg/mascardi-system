<?php
require_once __DIR__ . '/../../includes/functions.php';
requireLogin();
requireRole('admin');

$pageTitle = 'Sales Dashboard';
$db = getDB();

// ── KPI Stats ──────────────────────────────────────────────────────────────
$s = [];
try {
    $s['revenue_month']      = (float)$db->query("SELECT COALESCE(SUM(amount),0) FROM payments WHERE status='confirmed' AND MONTH(payment_date)=MONTH(NOW()) AND YEAR(payment_date)=YEAR(NOW())")->fetchColumn();
    $s['cars_sold_month']    = (int)$db->query("SELECT COUNT(*) FROM car_sales WHERE MONTH(sale_date)=MONTH(NOW()) AND YEAR(sale_date)=YEAR(NOW())")->fetchColumn();
    $s['payments_today']     = (float)$db->query("SELECT COALESCE(SUM(amount),0) FROM payments WHERE status='confirmed' AND DATE(payment_date)=CURDATE()")->fetchColumn();
    $s['unpaid_invoices']    = (int)$db->query("SELECT COUNT(*) FROM invoices WHERE status IN ('unpaid','partial')")->fetchColumn();
    $s['active_leads']       = (int)$db->query("SELECT COUNT(*) FROM crm_leads WHERE status='active'")->fetchColumn();
    $s['quotes_pending']     = (int)$db->query("SELECT COUNT(*) FROM quotations WHERE status IN ('sent','draft')")->fetchColumn();
    $s['new_clients_month']  = (int)$db->query("SELECT COUNT(*) FROM clients WHERE MONTH(created_at)=MONTH(NOW()) AND YEAR(created_at)=YEAR(NOW())")->fetchColumn();
    $s['total_clients']      = (int)$db->query("SELECT COUNT(*) FROM clients")->fetchColumn();
} catch (\Throwable $_) {
    foreach (['revenue_month','cars_sold_month','payments_today','unpaid_invoices','active_leads','quotes_pending','new_clients_month','total_clients'] as $k) $s[$k] = 0;
}

// ── Monthly revenue trend (6 months, line chart) ──────────────────────────
try {
    $revRows = $db->query("
        SELECT DATE_FORMAT(payment_date,'%b %Y') AS label,
               YEAR(payment_date) AS yr, MONTH(payment_date) AS mo,
               COALESCE(SUM(amount),0) AS total
        FROM payments
        WHERE status='confirmed'
          AND payment_date >= DATE_FORMAT(DATE_SUB(NOW(), INTERVAL 5 MONTH),'%Y-%m-01')
        GROUP BY YEAR(payment_date), MONTH(payment_date)
        ORDER BY YEAR(payment_date), MONTH(payment_date) ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (\Throwable $_) { $revRows = []; }

// Fill in months with no data so the chart always shows 6 months
$revMap = [];
foreach ($revRows as $r) { $revMap[$r['yr'].'-'.$r['mo']] = ['label' => $r['label'], 'total' => (float)$r['total']]; }
$revLabels = []; $revData = [];
for ($i = 5; $i >= 0; $i--) {
    $ts = mktime(0, 0, 0, (int)date('n') - $i, 1);
    $key = date('Y', $ts) . '-' . (int)date('n', $ts);
    $revLabels[] = $revMap[$key]['label'] ?? date('M Y', $ts);
    $revData[]   = $revMap[$key]['total'] ?? 0;
}

// ── Sales pipeline stages ─────────────────────────────────────────────────
try {
    $pipeRows = $db->query("SELECT stage, COUNT(*) as cnt FROM crm_leads WHERE status='active' GROUP BY stage")->fetchAll(PDO::FETCH_KEY_PAIR);
} catch (\Throwable $_) { $pipeRows = []; }

$stageKeys   = ['qualified','interested','proposal','negotiation','delivered'];
$stageTitles = ['Qualified','Interested','Proposal','Negotiation','Delivered'];
$stageData   = array_map(fn($k) => (int)($pipeRows[$k] ?? 0), $stageKeys);

// ── Recent car sales ──────────────────────────────────────────────────────
try {
    $recentSales = $db->query("
        SELECT cs.id, cs.sale_number, cs.sale_date, cs.selling_price, cs.payment_status,
               c.registration_number, c.make, c.model,
               cl.name AS client_name
        FROM car_sales cs
        LEFT JOIN cars c ON c.id = cs.car_id
        LEFT JOIN clients cl ON cl.id = cs.client_id
        ORDER BY cs.sale_date DESC, cs.id DESC LIMIT 8
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (\Throwable $_) { $recentSales = []; }

// ── Recent payments ───────────────────────────────────────────────────────
try {
    $recentPayments = $db->query("
        SELECT p.id, p.payment_number, p.payment_date, p.amount, p.payment_method, p.status,
               cl.name AS client_name
        FROM payments p
        LEFT JOIN clients cl ON cl.id = p.client_id
        ORDER BY p.payment_date DESC, p.id DESC LIMIT 8
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (\Throwable $_) { $recentPayments = []; }

// ── Chart payload ─────────────────────────────────────────────────────────
$chartPayload = json_encode([
    'revenue' => ['labels' => $revLabels, 'data' => $revData],
    'pipeline'=> ['labels' => $stageTitles, 'data' => $stageData],
]);

$extraJs = '<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
(function(){
    var d = ' . $chartPayload . ';

    // Line — monthly revenue
    var lc = document.getElementById("lineRevenue");
    if (lc) {
        new Chart(lc, {
            type: "line",
            data: {
                labels: d.revenue.labels,
                datasets: [{
                    label: "Revenue (KES)",
                    data: d.revenue.data,
                    fill: true,
                    backgroundColor: "rgba(16,185,129,.08)",
                    borderColor: "#10b981",
                    borderWidth: 2.5,
                    tension: 0.4,
                    pointBackgroundColor: "#10b981",
                    pointBorderColor: "#fff",
                    pointBorderWidth: 2,
                    pointRadius: 5,
                    pointHoverRadius: 7
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
                                return " KES " + Number(ctx.raw).toLocaleString("en-KE", {minimumFractionDigits:0, maximumFractionDigits:0});
                            }
                        }
                    }
                },
                scales: {
                    y: { beginAtZero: true, ticks: { font:{family:"Inter",size:11}, callback: function(v){ return v>=1000000 ? (v/1000000)+"M" : v>=1000 ? (v/1000)+"K" : v; } }, grid: { color:"rgba(0,0,0,.05)" } },
                    x: { ticks: { font:{family:"Inter",size:11} }, grid: { display:false } }
                }
            }
        });
    }

    // Bar — pipeline stages
    var pc = document.getElementById("barPipeline");
    if (pc) {
        new Chart(pc, {
            type: "bar",
            data: {
                labels: d.pipeline.labels,
                datasets: [{
                    label: "Leads",
                    data: d.pipeline.data,
                    backgroundColor: ["#dbeafe","#e0f2fe","#fef3c7","#ede9fe","#dcfce7"],
                    borderColor:      ["#2563eb","#0284c7","#d97706","#7c3aed","#16a34a"],
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
                    y: { beginAtZero: true, ticks: { precision:0, font:{family:"Inter",size:11} }, grid:{ color:"rgba(0,0,0,.05)" } },
                    x: { ticks: { font:{family:"Inter",size:11} }, grid:{ display:false } }
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
.kpi-value{font-size:28px;font-weight:700;color:var(--text);line-height:1}
.kpi-label{font-size:12px;color:var(--text-2);font-weight:500;margin-top:3px}
.section-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:16px}
.section-header h2{font-size:15px;font-weight:700;color:var(--text);margin:0}
.chart-card{background:var(--surface);border-radius:var(--r-lg);padding:22px;box-shadow:var(--sh);border:1px solid var(--border)}
.dashboard-title-row{display:flex;align-items:center;justify-content:space-between;margin-bottom:28px;flex-wrap:wrap;gap:12px}
.dashboard-title-row h1{font-size:22px;font-weight:700;color:var(--text);margin:0;display:flex;align-items:center;gap:10px}
.dashboard-title-row h1 i{width:38px;height:38px;border-radius:10px;background:#d1fae5;display:flex;align-items:center;justify-content:center;font-size:17px;color:#10b981}
.live-badge{background:#dcfce7;color:#15803d;font-size:11px;font-weight:700;padding:3px 10px;border-radius:20px;letter-spacing:.3px}
.pay-method-badge{display:inline-flex;align-items:center;gap:5px;padding:3px 9px;border-radius:20px;font-size:11px;font-weight:600;text-transform:capitalize}
.pay-mpesa{background:#e8f5e9;color:#1b5e20}
.pay-bank{background:#e3f2fd;color:#0d47a1}
.pay-cash{background:#fff8e1;color:#e65100}
.pay-cheque{background:#f3e5f5;color:#4a148c}
.sale-status-paid{background:#dcfce7;color:#15803d;padding:2px 9px;border-radius:20px;font-size:11px;font-weight:600}
.sale-status-partial{background:#fef3c7;color:#b45309;padding:2px 9px;border-radius:20px;font-size:11px;font-weight:600}
.sale-status-unpaid{background:#fee2e2;color:#b91c1c;padding:2px 9px;border-radius:20px;font-size:11px;font-weight:600}
</style>

<!-- Page title row -->
<div class="dashboard-title-row">
    <h1>
        <i class="fa fa-chart-line"></i>
        Sales Dashboard
    </h1>
    <div class="d-flex align-items-center gap-3">
        <span class="live-badge"><i class="fa fa-circle-dot fa-xs me-1"></i>Live</span>
        <span style="font-size:12.5px;color:var(--text-2)"><?= date('F Y') ?> · Updated <?= date('H:i') ?></span>
        <button onclick="location.reload()" class="btn btn-sm btn-outline-secondary d-flex align-items:center gap-2">
            <i class="fa fa-rotate-right"></i> Refresh
        </button>
    </div>
</div>

<!-- ── KPI Row 1 ─────────────────────────────────────────────────────────── -->
<div class="row g-3 mb-3">
    <div class="col-xl-3 col-md-6">
        <div class="admin-kpi-card">
            <div class="kpi-icon-wrap" style="background:#d1fae5">
                <i class="fa fa-sack-dollar" style="color:#10b981"></i>
            </div>
            <div>
                <div class="kpi-value" style="font-size:22px"><?= money($s['revenue_month']) ?></div>
                <div class="kpi-label">Revenue This Month</div>
            </div>
        </div>
    </div>
    <div class="col-xl-3 col-md-6">
        <div class="admin-kpi-card">
            <div class="kpi-icon-wrap" style="background:#dbeafe">
                <i class="fa fa-car-side" style="color:#2563eb"></i>
            </div>
            <div>
                <div class="kpi-value"><?= $s['cars_sold_month'] ?></div>
                <div class="kpi-label">Cars Sold This Month</div>
            </div>
        </div>
    </div>
    <div class="col-xl-3 col-md-6">
        <div class="admin-kpi-card">
            <div class="kpi-icon-wrap" style="background:#d1fae5">
                <i class="fa fa-money-bill-trend-up" style="color:#10b981"></i>
            </div>
            <div>
                <div class="kpi-value" style="font-size:22px"><?= money($s['payments_today']) ?></div>
                <div class="kpi-label">Payments Today</div>
            </div>
        </div>
    </div>
    <div class="col-xl-3 col-md-6">
        <div class="admin-kpi-card" style="<?= $s['unpaid_invoices'] > 0 ? 'border-color:#fcd34d' : '' ?>">
            <div class="kpi-icon-wrap" style="background:#fef3c7">
                <i class="fa fa-file-invoice" style="color:#d97706"></i>
            </div>
            <div>
                <div class="kpi-value" style="<?= $s['unpaid_invoices'] > 0 ? 'color:#d97706' : '' ?>"><?= $s['unpaid_invoices'] ?></div>
                <div class="kpi-label">Unpaid Invoices</div>
            </div>
        </div>
    </div>
</div>

<!-- ── KPI Row 2 ─────────────────────────────────────────────────────────── -->
<div class="row g-3 mb-4">
    <div class="col-xl-3 col-md-6">
        <div class="admin-kpi-card">
            <div class="kpi-icon-wrap" style="background:#ede9fe">
                <i class="fa fa-user-tie" style="color:#7c3aed"></i>
            </div>
            <div>
                <div class="kpi-value"><?= $s['active_leads'] ?></div>
                <div class="kpi-label">Active Pipeline Leads</div>
            </div>
        </div>
    </div>
    <div class="col-xl-3 col-md-6">
        <div class="admin-kpi-card">
            <div class="kpi-icon-wrap" style="background:#e0f2fe">
                <i class="fa fa-file-lines" style="color:#0284c7"></i>
            </div>
            <div>
                <div class="kpi-value"><?= $s['quotes_pending'] ?></div>
                <div class="kpi-label">Quotations Pending</div>
            </div>
        </div>
    </div>
    <div class="col-xl-3 col-md-6">
        <div class="admin-kpi-card">
            <div class="kpi-icon-wrap" style="background:#d1fae5">
                <i class="fa fa-user-plus" style="color:#10b981"></i>
            </div>
            <div>
                <div class="kpi-value"><?= $s['new_clients_month'] ?></div>
                <div class="kpi-label">New Clients This Month</div>
            </div>
        </div>
    </div>
    <div class="col-xl-3 col-md-6">
        <div class="admin-kpi-card">
            <div class="kpi-icon-wrap" style="background:#f1f5f9">
                <i class="fa fa-users" style="color:#475569"></i>
            </div>
            <div>
                <div class="kpi-value"><?= number_format($s['total_clients']) ?></div>
                <div class="kpi-label">Total Clients</div>
            </div>
        </div>
    </div>
</div>

<!-- ── Charts Row ────────────────────────────────────────────────────────── -->
<div class="row g-3 mb-4">
    <div class="col-xl-7">
        <div class="chart-card h-100">
            <div class="section-header mb-3">
                <h2><i class="fa fa-chart-line me-2" style="color:#10b981"></i>Monthly Revenue — Last 6 Months</h2>
                <span style="font-size:12px;color:var(--text-3)"><?= money($s['revenue_month']) ?> this month</span>
            </div>
            <div style="height:240px;position:relative">
                <canvas id="lineRevenue"></canvas>
            </div>
        </div>
    </div>
    <div class="col-xl-5">
        <div class="chart-card h-100">
            <div class="section-header mb-3">
                <h2><i class="fa fa-filter me-2" style="color:#7c3aed"></i>Sales Pipeline</h2>
                <span style="font-size:12px;color:var(--text-3)"><?= $s['active_leads'] ?> active leads</span>
            </div>
            <div style="height:240px;position:relative">
                <?php if (array_sum($stageData) > 0): ?>
                <canvas id="barPipeline"></canvas>
                <?php else: ?>
                <div style="height:100%;display:flex;align-items:center;justify-content:center;color:var(--text-3);font-size:13px">
                    <div class="text-center"><i class="fa fa-filter fa-2x mb-2 d-block" style="color:#e2e8f0"></i>No active leads in pipeline</div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- ── Recent Sales + Recent Payments ───────────────────────────────────── -->
<div class="row g-3 mb-4">

    <!-- Recent Sales -->
    <div class="col-xl-7">
        <div class="chart-card h-100">
            <div class="section-header">
                <h2><i class="fa fa-tag me-2" style="color:#10b981"></i>Recent Car Sales</h2>
                <a href="<?= BASE_URL ?>/modules/sales/index.php" class="btn btn-sm btn-outline-primary">View All</a>
            </div>
            <div class="table-responsive">
                <table class="table table-hover mb-0" style="font-size:13px">
                    <thead>
                        <tr style="font-size:11px;text-transform:uppercase;letter-spacing:.4px;color:var(--text-3);border-bottom:2px solid var(--border)">
                            <th class="ps-0">Sale #</th>
                            <th>Vehicle</th>
                            <th>Client</th>
                            <th>Amount</th>
                            <th>Status</th>
                            <th>Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($recentSales)): ?>
                        <tr><td colspan="6" class="text-center py-4" style="color:var(--text-3)">
                            <i class="fa fa-car-side fa-2x mb-2 d-block"></i>No sales recorded yet
                        </td></tr>
                        <?php else: ?>
                        <?php foreach ($recentSales as $sale): ?>
                        <tr>
                            <td class="ps-0 fw-semibold" style="color:var(--brand)"><?= e($sale['sale_number'] ?? '—') ?></td>
                            <td>
                                <span class="fw-medium"><?= e($sale['registration_number'] ?? '—') ?></span>
                                <br><span style="color:var(--text-2);font-size:11.5px"><?= e(($sale['make'] ?? '') . ' ' . ($sale['model'] ?? '')) ?></span>
                            </td>
                            <td><?= e($sale['client_name'] ?? 'Walk-in') ?></td>
                            <td class="fw-semibold"><?= money($sale['selling_price'] ?? 0) ?></td>
                            <td>
                                <?php $ps = strtolower($sale['payment_status'] ?? 'unpaid'); ?>
                                <span class="sale-status-<?= $ps ?>"><?= ucfirst($ps) ?></span>
                            </td>
                            <td style="color:var(--text-2)"><?= $sale['sale_date'] ? date('d M Y', strtotime($sale['sale_date'])) : '—' ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Recent Payments -->
    <div class="col-xl-5">
        <div class="chart-card h-100">
            <div class="section-header">
                <h2><i class="fa fa-money-bill-wave me-2" style="color:#16a34a"></i>Recent Payments</h2>
                <a href="<?= BASE_URL ?>/modules/payments/index.php" class="btn btn-sm btn-outline-primary">View All</a>
            </div>
            <div class="table-responsive">
                <table class="table table-hover mb-0" style="font-size:13px">
                    <thead>
                        <tr style="font-size:11px;text-transform:uppercase;letter-spacing:.4px;color:var(--text-3);border-bottom:2px solid var(--border)">
                            <th class="ps-0">Client</th>
                            <th>Amount</th>
                            <th>Method</th>
                            <th>Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($recentPayments)): ?>
                        <tr><td colspan="4" class="text-center py-4" style="color:var(--text-3)">
                            <i class="fa fa-receipt fa-2x mb-2 d-block"></i>No payments recorded
                        </td></tr>
                        <?php else: ?>
                        <?php foreach ($recentPayments as $pay): ?>
                        <tr>
                            <td class="ps-0 fw-medium"><?= e($pay['client_name'] ?? '—') ?></td>
                            <td class="fw-semibold" style="color:#16a34a"><?= money($pay['amount'] ?? 0) ?></td>
                            <td>
                                <?php $m = strtolower($pay['payment_method'] ?? 'cash'); ?>
                                <span class="pay-method-badge pay-<?= $m ?>"><?= strtoupper($m) ?></span>
                            </td>
                            <td style="color:var(--text-2);font-size:12px"><?= $pay['payment_date'] ? date('d M', strtotime($pay['payment_date'])) : '—' ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>

<?php
require_once __DIR__ . '/../../includes/functions.php';
requireRole('supervisor');
$pageTitle = 'Location Reports';
$db    = getDB();
$locId = supervisorLocationId();

if (!$locId) { header('Location: ' . BASE_URL . '/modules/supervisor/dashboard.php'); exit; }

$location = $db->prepare("SELECT * FROM locations WHERE id=?");
$location->execute([$locId]);
$location = $location->fetch();
$locName = $location['name'] ?? 'Location';

// ── Revenue last 12 months ────────────────────────────────────────────────
$revLabels = $revData = [];
for ($i = 11; $i >= 0; $i--) {
    $ym = date('Y-m', strtotime("-{$i} months"));
    $revLabels[] = date('M Y', strtotime($ym . '-01'));
    try {
        $s = $db->prepare("SELECT COALESCE(SUM(i.total),0) FROM invoices i LEFT JOIN cars c ON c.id=i.car_id WHERE (c.location_id=? OR i.location_id=?) AND i.status='paid' AND DATE_FORMAT(i.created_at,'%Y-%m')=?");
        $s->execute([$locId, $locId, $ym]);
        $revData[] = (float)$s->fetchColumn();
    } catch (\Throwable $_) { $revData[] = 0; }
}

// ── Fleet by status ───────────────────────────────────────────────────────
$carsStmt = $db->prepare("SELECT status, COUNT(*) AS cnt FROM cars WHERE location_id=? GROUP BY status ORDER BY cnt DESC");
$carsStmt->execute([$locId]);
$carsByStatus = $carsStmt->fetchAll();

// ── Bookings per week (last 8 weeks) ──────────────────────────────────────
$bkLabels = $bkData = [];
try {
    for ($i = 7; $i >= 0; $i--) {
        $weekStart = date('Y-m-d', strtotime("monday -{$i} week"));
        $weekEnd   = date('Y-m-d', strtotime("sunday -{$i} week"));
        $bkLabels[] = date('d M', strtotime($weekStart));
        $s = $db->prepare("SELECT COUNT(*) FROM service_bookings sb LEFT JOIN cars c ON c.id=sb.car_id WHERE (c.location_id=? OR sb.location_id=?) AND sb.preferred_date BETWEEN ? AND ?");
        $s->execute([$locId, $locId, $weekStart, $weekEnd]);
        $bkData[] = (int)$s->fetchColumn();
    }
} catch (\Throwable $_) { $bkLabels = []; $bkData = []; }

// ── Quotation conversion ──────────────────────────────────────────────────
$quotStats = ['total' => 0, 'accepted' => 0, 'sent' => 0, 'draft' => 0, 'rejected' => 0, 'expired' => 0];
try {
    $s = $db->prepare("SELECT status, COUNT(*) AS cnt FROM quotations q LEFT JOIN cars c ON c.id=q.car_id WHERE (c.location_id=? OR q.location_id=?) GROUP BY status");
    $s->execute([$locId, $locId]);
    foreach ($s->fetchAll() as $r) { $quotStats[$r['status']] = (int)$r['cnt']; $quotStats['total'] += (int)$r['cnt']; }
} catch (\Throwable $_) {}
$convRate = $quotStats['total'] > 0 ? round($quotStats['accepted'] / $quotStats['total'] * 100, 1) : 0;

// ── Revenue summary ───────────────────────────────────────────────────────
try {
    $s = $db->prepare("SELECT COALESCE(SUM(i.total),0) FROM invoices i LEFT JOIN cars c ON c.id=i.car_id WHERE (c.location_id=? OR i.location_id=?) AND i.status='paid' AND MONTH(i.created_at)=MONTH(NOW()) AND YEAR(i.created_at)=YEAR(NOW())");
    $s->execute([$locId, $locId]); $revMTD = (float)$s->fetchColumn();

    $s = $db->prepare("SELECT COALESCE(SUM(i.total),0) FROM invoices i LEFT JOIN cars c ON c.id=i.car_id WHERE (c.location_id=? OR i.location_id=?) AND i.status='paid' AND YEAR(i.created_at)=YEAR(NOW())");
    $s->execute([$locId, $locId]); $revYTD = (float)$s->fetchColumn();

    $lm = date('Y-m', strtotime('-1 month'));
    $s = $db->prepare("SELECT COALESCE(SUM(i.total),0) FROM invoices i LEFT JOIN cars c ON c.id=i.car_id WHERE (c.location_id=? OR i.location_id=?) AND i.status='paid' AND DATE_FORMAT(i.created_at,'%Y-%m')=?");
    $s->execute([$locId, $locId, $lm]); $revLastMonth = (float)$s->fetchColumn();
    $revTrend = $revLastMonth > 0 ? round((($revMTD - $revLastMonth) / $revLastMonth) * 100, 1) : null;
} catch (\Throwable $_) { $revMTD = $revYTD = $revLastMonth = 0; $revTrend = null; }

$statusColors = ['in_transit'=>'#d97706','arrived'=>'#0284c7','in_assessment'=>'#7c3aed','in_workshop'=>'#db2777','completed'=>'#16a34a','sold'=>'#0f172a','delivered'=>'#475569','reserved'=>'#8b5cf6'];

$extraJs = <<<JS
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
(function(){
    var rc = document.getElementById('rptRevChart');
    if(rc){
        new Chart(rc,{type:'bar',data:{labels:<?= json_encode($revLabels) ?>,datasets:[{label:'Revenue (KES)',data:<?= json_encode($revData) ?>,backgroundColor:'rgba(34,211,238,.65)',borderColor:'#22d3ee',borderWidth:1,borderRadius:5}]},options:{responsive:true,plugins:{legend:{display:false},tooltip:{callbacks:{label:function(c){var v=c.raw;return' KES '+(v>=1e6?(v/1e6).toFixed(2)+'M':v>=1e3?(v/1e3).toFixed(1)+'K':v.toFixed(0));}}}},scales:{y:{beginAtZero:true,ticks:{callback:function(v){return v>=1e6?(v/1e6).toFixed(1)+'M':v>=1e3?(v/1e3).toFixed(0)+'K':v;}}}}}});
    }
    var sc = document.getElementById('rptStatusChart');
    if(sc){
        var labels=<?= json_encode(array_map(fn($r)=>ucwords(str_replace('_',' ',$r['status'])), $carsByStatus)) ?>;
        var data=<?= json_encode(array_column($carsByStatus,'cnt')) ?>;
        var colors=<?= json_encode(array_map(fn($r)=>$statusColors[$r['status']]??'#94a3b8', $carsByStatus)) ?>;
        new Chart(sc,{type:'doughnut',data:{labels:labels,datasets:[{data:data,backgroundColor:colors,borderWidth:2,borderColor:'#fff'}]},options:{cutout:'60%',plugins:{legend:{position:'right',labels:{font:{size:11},padding:10,boxWidth:12}}}}});
    }
    var bc = document.getElementById('rptBkChart');
    if(bc){
        new Chart(bc,{type:'line',data:{labels:<?= json_encode($bkLabels) ?>,datasets:[{label:'Bookings',data:<?= json_encode($bkData) ?>,borderColor:'#8b5cf6',backgroundColor:'rgba(139,92,246,.1)',tension:.35,fill:true,pointRadius:4,pointBackgroundColor:'#8b5cf6'}]},options:{responsive:true,plugins:{legend:{display:false}},scales:{y:{beginAtZero:true,ticks:{stepSize:1}}}}});
    }
}());
</script>
JS;

include __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
    <div>
        <h5 class="mb-0"><i class="fa fa-chart-line me-2 text-primary"></i>Location Reports — <span class="text-primary"><?= e($locName) ?></span></h5>
        <div class="text-muted small">Analytics scoped to your assigned location</div>
    </div>
    <a href="<?= BASE_URL ?>/modules/supervisor/dashboard.php" class="btn btn-sm btn-outline-secondary"><i class="fa fa-arrow-left me-1"></i>Dashboard</a>
</div>

<!-- Revenue Summary Cards -->
<div class="row g-3 mb-4">
    <div class="col-sm-4">
        <div class="card text-center">
            <div class="card-body py-4">
                <div class="text-muted small mb-1">Revenue This Month</div>
                <div class="fw-bold" style="font-size:1.5rem;color:#22d3ee"><?= money($revMTD) ?></div>
                <?php if ($revTrend !== null): ?>
                <span class="badge <?= $revTrend >= 0 ? 'bg-success' : 'bg-danger' ?> mt-1">
                    <i class="fa fa-arrow-<?= $revTrend >= 0 ? 'up' : 'down' ?> me-1"></i><?= abs($revTrend) ?>% vs last month
                </span>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-sm-4">
        <div class="card text-center">
            <div class="card-body py-4">
                <div class="text-muted small mb-1">Revenue Year-to-Date</div>
                <div class="fw-bold" style="font-size:1.5rem;color:#059669"><?= money($revYTD) ?></div>
            </div>
        </div>
    </div>
    <div class="col-sm-4">
        <div class="card text-center">
            <div class="card-body py-4">
                <div class="text-muted small mb-1">Quotation Conversion</div>
                <div class="fw-bold" style="font-size:1.5rem;color:#8b5cf6"><?= $convRate ?>%</div>
                <div class="text-muted" style="font-size:11px"><?= $quotStats['accepted'] ?> / <?= $quotStats['total'] ?> accepted</div>
            </div>
        </div>
    </div>
</div>

<!-- Revenue 12m Chart + Fleet Status -->
<div class="row g-4 mb-4">
    <div class="col-lg-8">
        <div class="card h-100">
            <div class="card-header"><i class="fa fa-chart-bar me-2"></i>Revenue — Last 12 Months</div>
            <div class="card-body"><canvas id="rptRevChart" height="120"></canvas></div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card h-100">
            <div class="card-header"><i class="fa fa-circle-half-stroke me-2"></i>Fleet Status</div>
            <div class="card-body d-flex align-items-center justify-content-center">
                <?php if (!empty($carsByStatus)): ?>
                <canvas id="rptStatusChart" height="200"></canvas>
                <?php else: ?>
                <div class="text-muted small text-center py-4">No cars at this location.</div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Service Bookings Trend + Quotation Breakdown -->
<div class="row g-4 mb-4">
    <div class="col-lg-7">
        <div class="card h-100">
            <div class="card-header"><i class="fa fa-calendar-check me-2"></i>Service Bookings — Last 8 Weeks</div>
            <div class="card-body">
                <?php if (!empty($bkLabels)): ?>
                <canvas id="rptBkChart" height="120"></canvas>
                <?php else: ?>
                <div class="text-muted small text-center py-4">No booking data available.</div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-lg-5">
        <div class="card h-100">
            <div class="card-header"><i class="fa fa-file-lines me-2"></i>Quotation Breakdown</div>
            <div class="card-body">
                <div class="d-flex flex-column gap-2">
                    <?php
                    $qStatCfg = [
                        'draft'    => ['secondary', 'Draft'],
                        'sent'     => ['primary',   'Sent / Active'],
                        'accepted' => ['success',   'Accepted / Won'],
                        'rejected' => ['danger',    'Rejected'],
                        'expired'  => ['warning',   'Expired'],
                    ];
                    foreach ($qStatCfg as $key => [$bc, $label]):
                        $cnt = $quotStats[$key] ?? 0;
                        $pct = $quotStats['total'] > 0 ? round($cnt / $quotStats['total'] * 100) : 0;
                    ?>
                    <div>
                        <div class="d-flex justify-content-between small mb-1">
                            <span><?= $label ?></span>
                            <span class="fw-semibold"><?= $cnt ?> <span class="text-muted fw-normal">(<?= $pct ?>%)</span></span>
                        </div>
                        <div class="progress" style="height:8px">
                            <div class="progress-bar bg-<?= $bc ?>" style="width:<?= $pct ?>%"></div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    <?php if ($quotStats['total'] === 0): ?>
                    <div class="text-muted small text-center py-3">No quotations found.</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Fleet Status Table -->
<div class="card mb-4">
    <div class="card-header"><i class="fa fa-table me-2"></i>Fleet Summary — <?= e($locName) ?></div>
    <div class="card-body p-0">
        <table class="table table-hover mb-0">
            <thead class="table-light"><tr><th class="ps-3">Status</th><th>Count</th><th>% of Fleet</th></tr></thead>
            <tbody>
                <?php
                $total = array_sum(array_column($carsByStatus, 'cnt'));
                foreach ($carsByStatus as $r):
                    $pct = $total > 0 ? round($r['cnt'] / $total * 100, 1) : 0;
                    $col = $statusColors[$r['status']] ?? '#94a3b8';
                ?>
                <tr>
                    <td class="ps-3">
                        <span class="d-inline-block me-2" style="width:10px;height:10px;border-radius:50%;background:<?= $col ?>"></span>
                        <?= ucwords(str_replace('_', ' ', $r['status'])) ?>
                    </td>
                    <td class="fw-semibold"><?= $r['cnt'] ?></td>
                    <td>
                        <div class="d-flex align-items-center gap-2">
                            <div class="progress flex-grow-1" style="height:6px;max-width:120px">
                                <div class="progress-bar" style="width:<?= $pct ?>%;background:<?= $col ?>"></div>
                            </div>
                            <span class="text-muted small"><?= $pct ?>%</span>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($carsByStatus)): ?>
                <tr><td colspan="3" class="text-center py-4 text-muted small">No cars at this location.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>

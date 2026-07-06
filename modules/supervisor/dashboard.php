<?php
require_once __DIR__ . '/../../includes/functions.php';
requireRole('supervisor');
$pageTitle = 'Supervisor Dashboard';
$db  = getDB();
$me  = authUser();
$locId = supervisorLocationId();

// If no location assigned, show a warning
if (!$locId) {
    include __DIR__ . '/../../includes/header.php';
    echo '<div class="alert alert-warning m-4"><i class="fa fa-triangle-exclamation me-2"></i>
          No location has been assigned to your account. Please contact your administrator.</div>';
    include __DIR__ . '/../../includes/footer.php';
    exit;
}

// Get location info
$location = $db->prepare("SELECT * FROM locations WHERE id=?");
$location->execute([$locId]);
$location = $location->fetch();

// ── KPI Stats ────────────────────────────────────────────────────────────────
$stats = [];

// Cars at location + sub-locations by status
$carsStmt = $db->prepare("SELECT status, COUNT(*) AS cnt FROM cars WHERE location_id IN (SELECT id FROM locations WHERE id=? OR parent_id=?) GROUP BY status");
$carsStmt->execute([$locId, $locId]);
$carsByStatus = [];
$totalCars = 0;
foreach ($carsStmt->fetchAll() as $r) {
    $carsByStatus[$r['status']] = (int)$r['cnt'];
    $totalCars += (int)$r['cnt'];
}

$stats['total_cars']       = $totalCars;
$stats['available_cars']   = ($carsByStatus['completed'] ?? 0) + ($carsByStatus['arrived'] ?? 0);
$stats['in_workshop']      = $carsByStatus['in_workshop'] ?? 0;
$stats['sold_cars']        = ($carsByStatus['sold'] ?? 0) + ($carsByStatus['delivered'] ?? 0);

// Service bookings — use intake_location_id as the location column on service_bookings
try {
    $sbStmt = $db->prepare("
        SELECT COUNT(*) FROM service_bookings sb
        LEFT JOIN cars c ON c.id = sb.car_id
        WHERE (c.location_id=? OR sb.intake_location_id=?) AND sb.status='pending'
    ");
    $sbStmt->execute([$locId, $locId]);
    $stats['pending_bookings'] = (int)$sbStmt->fetchColumn();

    $sbTodayStmt = $db->prepare("
        SELECT COUNT(*) FROM service_bookings sb
        LEFT JOIN cars c ON c.id = sb.car_id
        WHERE (c.location_id=? OR sb.intake_location_id=?) AND sb.preferred_date=CURDATE()
    ");
    $sbTodayStmt->execute([$locId, $locId]);
    $stats['bookings_today'] = (int)$sbTodayStmt->fetchColumn();
} catch (\Throwable $_) { $stats['pending_bookings'] = 0; $stats['bookings_today'] = 0; }

// Quick assessments today — scope via car location only
try {
    $qaStmt = $db->prepare("
        SELECT COUNT(*) FROM quick_assessments qa
        LEFT JOIN cars c ON c.id = qa.car_id
        WHERE c.location_id=? AND qa.assessment_date=CURDATE()
    ");
    $qaStmt->execute([$locId]);
    $stats['qa_today'] = (int)$qaStmt->fetchColumn();
} catch (\Throwable $_) { $stats['qa_today'] = 0; }

// Quotations — scope via car location only
try {
    $qStmt = $db->prepare("
        SELECT COUNT(*) FROM quotations q
        LEFT JOIN cars c ON c.id = q.car_id
        WHERE c.location_id=? AND q.status IN ('draft','sent')
    ");
    $qStmt->execute([$locId]);
    $stats['active_quotations'] = (int)$qStmt->fetchColumn();
} catch (\Throwable $_) { $stats['active_quotations'] = 0; }

// Invoices — scope via car location only
try {
    $invStmt = $db->prepare("
        SELECT COUNT(*) FROM invoices i
        LEFT JOIN cars c ON c.id = i.car_id
        WHERE c.location_id=? AND i.status='unpaid'
    ");
    $invStmt->execute([$locId]);
    $stats['unpaid_invoices'] = (int)$invStmt->fetchColumn();

    $revStmt = $db->prepare("
        SELECT COALESCE(SUM(i.total),0) FROM invoices i
        LEFT JOIN cars c ON c.id = i.car_id
        WHERE c.location_id=? AND i.status='paid'
          AND MONTH(i.created_at)=MONTH(NOW()) AND YEAR(i.created_at)=YEAR(NOW())
    ");
    $revStmt->execute([$locId]);
    $stats['revenue_month'] = (float)$revStmt->fetchColumn();
} catch (\Throwable $_) { $stats['unpaid_invoices'] = 0; $stats['revenue_month'] = 0; }

// Team count (users assigned to this location)
try {
    $teamStmt = $db->prepare("SELECT COUNT(*) FROM users WHERE location_id=? AND status='active' AND id != ?");
    $teamStmt->execute([$locId, $me['id']]);
    $stats['team_count'] = (int)$teamStmt->fetchColumn();
} catch (\Throwable $_) { $stats['team_count'] = 0; }

// Recent cars at location + sub-locations
$recentCars = $db->prepare("SELECT c.*, l.name AS loc_name FROM cars c LEFT JOIN locations l ON l.id=c.location_id WHERE c.location_id IN (SELECT id FROM locations WHERE id=? OR parent_id=?) ORDER BY c.updated_at DESC LIMIT 6");
$recentCars->execute([$locId, $locId]);
$recentCars = $recentCars->fetchAll();

// Upcoming bookings — use intake_location_id as the location column on service_bookings
try {
    $upcomingStmt = $db->prepare("
        SELECT sb.*, c.make, c.model
        FROM service_bookings sb
        LEFT JOIN cars c ON c.id = sb.car_id
        WHERE (c.location_id=? OR sb.intake_location_id=?)
          AND sb.status IN ('pending','confirmed')
          AND (sb.preferred_date IS NULL OR sb.preferred_date >= CURDATE())
        ORDER BY sb.preferred_date ASC, sb.created_at ASC
        LIMIT 5
    ");
    $upcomingStmt->execute([$locId, $locId]);
    $upcomingBookings = $upcomingStmt->fetchAll();
} catch (\Throwable $_) { $upcomingBookings = []; }

// Revenue last 6 months for chart
$revLabels = $revData = [];
for ($i = 5; $i >= 0; $i--) {
    $ym = date('Y-m', strtotime("-{$i} months"));
    $revLabels[] = date('M Y', strtotime($ym . '-01'));
    try {
        $rs = $db->prepare("
            SELECT COALESCE(SUM(i.total),0) FROM invoices i
            LEFT JOIN cars c ON c.id = i.car_id
            WHERE c.location_id=? AND i.status='paid'
              AND DATE_FORMAT(i.created_at,'%Y-%m')=?
        ");
        $rs->execute([$locId, $ym]);
        $revData[] = (float)$rs->fetchColumn();
    } catch (\Throwable $_) { $revData[] = 0; }
}

$chartRevLabels = json_encode($revLabels);
$chartRevData   = json_encode($revData);

// Fleet status chart
$statusColors = [
    'in_transit'    => '#d97706',
    'arrived'       => '#0284c7',
    'in_assessment' => '#7c3aed',
    'in_workshop'   => '#db2777',
    'completed'     => '#16a34a',
    'sold'          => '#0f172a',
    'delivered'     => '#475569',
    'reserved'      => '#8b5cf6',
];
$chartStatusLabels = json_encode(array_map(fn($s) => ucwords(str_replace('_', ' ', $s)), array_keys($carsByStatus)));
$chartStatusData   = json_encode(array_values($carsByStatus));
$chartStatusColors = json_encode(array_map(fn($s) => $statusColors[$s] ?? '#94a3b8', array_keys($carsByStatus)));

$extraJs = <<<JS
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
(function(){
    var rc = document.getElementById('supRevChart');
    if (rc) {
        new Chart(rc, {
            type: 'bar',
            data: {
                labels: {$chartRevLabels},
                datasets: [{ label: 'Revenue (KES)', data: {$chartRevData},
                    backgroundColor: 'rgba(34,211,238,0.7)', borderColor: '#22d3ee',
                    borderWidth: 1, borderRadius: 5 }]
            },
            options: { responsive: true, plugins: { legend: { display: false },
                tooltip: { callbacks: { label: function(c){ var v=c.raw; return ' KES '+(v>=1e6?(v/1e6).toFixed(2)+'M':v>=1e3?(v/1e3).toFixed(1)+'K':v.toFixed(0)); } } } },
                scales: { y: { beginAtZero: true, ticks: { callback: function(v){ return v>=1e6?(v/1e6).toFixed(1)+'M':v>=1e3?(v/1e3).toFixed(0)+'K':v; } } } }
            }
        });
    }
    var sc = document.getElementById('supStatusChart');
    if (sc) {
        new Chart(sc, {
            type: 'doughnut',
            data: {
                labels: {$chartStatusLabels},
                datasets: [{ data: {$chartStatusData}, backgroundColor: {$chartStatusColors}, borderWidth: 2, borderColor: '#fff' }]
            },
            options: { cutout: '60%', plugins: { legend: { position: 'bottom', labels: { font: { size: 11 }, padding: 10, boxWidth: 12 } } } }
        });
    }
}());
</script>
JS;

include __DIR__ . '/../../includes/header.php';
?>

<!-- Welcome Banner -->
<div class="welcome-banner mb-4" style="background:linear-gradient(135deg,#0f172a,#1e3a5f);border-radius:14px">
    <div class="welcome-text">
        <h5 class="mb-1" style="color:#fff">
            <i class="fa fa-location-dot me-2" style="color:#22d3ee"></i>
            <?= e($location['name'] ?? 'Your Location') ?>
        </h5>
        <p class="mb-0" style="color:#94a3b8"><?= date('l, d F Y') ?> &mdash;
            <span class="badge fw-semibold px-2 py-1" style="background:rgba(34,211,238,.2);color:#22d3ee;border:1px solid rgba(34,211,238,.3)">
                <i class="fa fa-user-shield me-1"></i>Supervisor
            </span>
        </p>
    </div>
    <div class="welcome-stats d-none d-md-flex align-items-center gap-4">
        <div class="text-center">
            <div class="welcome-stat-val" style="color:#22d3ee"><?= $stats['total_cars'] ?></div>
            <div class="welcome-stat-lbl" style="color:#94a3b8">Cars</div>
        </div>
        <div class="vr welcome-divider" style="border-color:rgba(255,255,255,.15)"></div>
        <div class="text-center">
            <div class="welcome-stat-val" style="color:#4ade80"><?= $stats['available_cars'] ?></div>
            <div class="welcome-stat-lbl" style="color:#94a3b8">Available</div>
        </div>
        <div class="vr welcome-divider" style="border-color:rgba(255,255,255,.15)"></div>
        <div class="text-center">
            <div class="welcome-stat-val" style="color:#fbbf24"><?= $stats['team_count'] ?></div>
            <div class="welcome-stat-lbl" style="color:#94a3b8">Team</div>
        </div>
    </div>
</div>

<!-- ── KPI Cards ────────────────────────────────────────────────────────────── -->
<div class="row g-3 mb-4">
    <div class="col-sm-6 col-xl-3">
        <a href="<?= BASE_URL ?>/modules/supervisor/cars.php" class="stat-card stat-card-link" style="border-left:4px solid #2563eb">
            <div class="stat-icon" style="background:#dbeafe;color:#2563eb"><i class="fa fa-car"></i></div>
            <div class="stat-info">
                <div class="stat-label">Total Cars</div>
                <div class="stat-value"><?= $stats['total_cars'] ?></div>
            </div>
        </a>
    </div>
    <div class="col-sm-6 col-xl-3">
        <a href="<?= BASE_URL ?>/modules/supervisor/cars.php?status=completed" class="stat-card stat-card-link" style="border-left:4px solid #16a34a">
            <div class="stat-icon" style="background:#dcfce7;color:#16a34a"><i class="fa fa-car-side"></i></div>
            <div class="stat-info">
                <div class="stat-label">Available for Sale</div>
                <div class="stat-value"><?= $stats['available_cars'] ?></div>
            </div>
        </a>
    </div>
    <div class="col-sm-6 col-xl-3">
        <a href="<?= BASE_URL ?>/modules/supervisor/service_bookings.php" class="stat-card stat-card-link" style="border-left:4px solid #f59e0b">
            <div class="stat-icon" style="background:#fef3c7;color:#f59e0b"><i class="fa fa-calendar-check"></i></div>
            <div class="stat-info">
                <div class="stat-label">Pending Bookings</div>
                <div class="stat-value"><?= $stats['pending_bookings'] ?></div>
            </div>
        </a>
    </div>
    <div class="col-sm-6 col-xl-3">
        <a href="<?= BASE_URL ?>/modules/supervisor/team.php" class="stat-card stat-card-link" style="border-left:4px solid #22d3ee">
            <div class="stat-icon" style="background:#ecfeff;color:#0891b2"><i class="fa fa-people-group"></i></div>
            <div class="stat-info">
                <div class="stat-label">Active Team</div>
                <div class="stat-value"><?= $stats['team_count'] ?></div>
            </div>
        </a>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-sm-6 col-xl-3">
        <a href="<?= BASE_URL ?>/modules/supervisor/quick_assessments.php" class="stat-card stat-card-link" style="border-left:4px solid #8b5cf6">
            <div class="stat-icon" style="background:#f5f3ff;color:#8b5cf6"><i class="fa fa-magnifying-glass-chart"></i></div>
            <div class="stat-info">
                <div class="stat-label">Assessments Today</div>
                <div class="stat-value"><?= $stats['qa_today'] ?></div>
            </div>
        </a>
    </div>
    <div class="col-sm-6 col-xl-3">
        <a href="<?= BASE_URL ?>/modules/supervisor/quotations.php" class="stat-card stat-card-link" style="border-left:4px solid #0284c7">
            <div class="stat-icon" style="background:#e0f2fe;color:#0284c7"><i class="fa fa-file-lines"></i></div>
            <div class="stat-info">
                <div class="stat-label">Active Quotations</div>
                <div class="stat-value"><?= $stats['active_quotations'] ?></div>
            </div>
        </a>
    </div>
    <div class="col-sm-6 col-xl-3">
        <a href="<?= BASE_URL ?>/modules/supervisor/invoices.php?status=unpaid" class="stat-card stat-card-link" style="border-left:4px solid #dc2626">
            <div class="stat-icon" style="background:#fee2e2;color:#dc2626"><i class="fa fa-file-invoice-dollar"></i></div>
            <div class="stat-info">
                <div class="stat-label">Unpaid Invoices</div>
                <div class="stat-value"><?= $stats['unpaid_invoices'] ?></div>
            </div>
        </a>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="stat-card" style="border-left:4px solid #059669">
            <div class="stat-icon" style="background:#ecfdf5;color:#059669"><i class="fa fa-money-bill-wave"></i></div>
            <div class="stat-info">
                <div class="stat-label">Revenue (MTD)</div>
                <div class="stat-value stat-value-sm"><?= money($stats['revenue_month']) ?></div>
            </div>
        </div>
    </div>
</div>

<!-- ── Charts + Tables ──────────────────────────────────────────────────────── -->
<div class="row g-4 mb-4">
    <div class="col-lg-7">
        <div class="card h-100">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="fa fa-chart-bar me-2"></i>Revenue — Last 6 Months</span>
                <a href="<?= BASE_URL ?>/modules/supervisor/reports.php" class="btn btn-xs btn-outline-primary">Reports</a>
            </div>
            <div class="card-body"><canvas id="supRevChart" height="120"></canvas></div>
        </div>
    </div>
    <div class="col-lg-5">
        <div class="card h-100">
            <div class="card-header"><i class="fa fa-circle-half-stroke me-2"></i>Fleet Status</div>
            <div class="card-body d-flex align-items-center justify-content-center pb-2">
                <?php if ($totalCars > 0): ?>
                <canvas id="supStatusChart" height="200"></canvas>
                <?php else: ?>
                <div class="text-center text-muted py-5">
                    <i class="fa fa-car fa-2x mb-2 d-block opacity-25"></i>No cars at this location yet.
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<div class="row g-4 mb-4">
    <!-- Recent Cars -->
    <div class="col-lg-7">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="fa fa-car me-2"></i>Cars at <?= e($location['name'] ?? 'Location') ?></span>
                <a href="<?= BASE_URL ?>/modules/supervisor/cars.php" class="btn btn-xs btn-outline-primary">View All</a>
            </div>
            <div class="card-body p-0">
                <table class="table table-hover mb-0">
                    <thead><tr>
                        <th class="ps-3">Vehicle</th>
                        <th>Reg / Chassis</th>
                        <th>Location</th>
                        <th>Status</th>
                        <th class="pe-3"></th>
                    </tr></thead>
                    <tbody>
                        <?php foreach ($recentCars as $car): ?>
                        <tr>
                            <td class="ps-3 fw-medium small"><?= e(trim(($car['year'] ?? '') . ' ' . $car['make'] . ' ' . $car['model'])) ?></td>
                            <td class="small text-muted"><code style="font-size:10px"><?= e($car['registration_number'] ?: ($car['chassis_number'] ?: '—')) ?></code></td>
                            <td class="small text-muted"><?= e($car['loc_name'] ?? '—') ?></td>
                            <td><?= statusBadge($car['status']) ?></td>
                            <td class="text-end pe-3"><a href="<?= BASE_URL ?>/modules/cars/view.php?id=<?= $car['id'] ?>" class="btn btn-xs btn-outline-secondary">View</a></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($recentCars)): ?>
                        <tr><td colspan="5" class="text-center py-4 text-muted small">No cars at this location yet.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Upcoming Bookings -->
    <div class="col-lg-5">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="fa fa-calendar-check me-2"></i>Upcoming Bookings</span>
                <a href="<?= BASE_URL ?>/modules/supervisor/service_bookings.php" class="btn btn-xs btn-outline-primary">All</a>
            </div>
            <div class="list-group list-group-flush">
                <?php foreach ($upcomingBookings as $bk): ?>
                <div class="list-group-item py-2">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="fw-semibold small"><?= e($bk['client_name']) ?></div>
                            <div class="text-muted" style="font-size:11px"><?= e(implode(', ', array_slice(explode(', ', $bk['service_type'] ?? ''), 0, 2))) ?></div>
                        </div>
                        <div class="text-end">
                            <?= statusBadge($bk['status']) ?>
                            <div class="text-muted mt-1" style="font-size:10px"><?= $bk['preferred_date'] ? fmtDate($bk['preferred_date'], 'd M') : '—' ?></div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php if (empty($upcomingBookings)): ?>
                <div class="list-group-item text-center text-muted small py-4">No upcoming bookings.</div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Car Status Summary Badges -->
<div class="card mb-4">
    <div class="card-header"><i class="fa fa-layer-group me-2"></i>Fleet Breakdown — <?= e($location['name'] ?? '') ?></div>
    <div class="card-body">
        <div class="d-flex flex-wrap gap-2">
            <?php
            $statusIcons = [
                'in_transit'    => ['fa-truck',                 '#d97706', '#fef3c7'],
                'arrived'       => ['fa-circle-check',          '#0284c7', '#e0f2fe'],
                'in_assessment' => ['fa-clipboard-check',       '#7c3aed', '#f5f3ff'],
                'in_workshop'   => ['fa-screwdriver-wrench',    '#db2777', '#fce7f3'],
                'completed'     => ['fa-car-side',              '#16a34a', '#dcfce7'],
                'sold'          => ['fa-tag',                   '#0f172a', '#f1f5f9'],
                'delivered'     => ['fa-check-double',          '#475569', '#f8fafc'],
                'reserved'      => ['fa-bookmark',              '#8b5cf6', '#f5f3ff'],
            ];
            foreach ($carsByStatus as $status => $cnt):
                [$ico, $col, $bg] = $statusIcons[$status] ?? ['fa-circle', '#64748b', '#f8fafc'];
            ?>
            <a href="<?= BASE_URL ?>/modules/supervisor/cars.php?status=<?= urlencode($status) ?>"
               class="d-flex align-items-center gap-2 rounded-3 px-3 py-2 text-decoration-none"
               style="background:<?= $bg ?>;border:1px solid <?= $col ?>30;min-width:130px">
                <i class="fa <?= $ico ?>" style="color:<?= $col ?>"></i>
                <div>
                    <div style="font-size:12px;font-weight:600;color:<?= $col ?>"><?= ucwords(str_replace('_', ' ', $status)) ?></div>
                    <div style="font-size:18px;font-weight:700;color:<?= $col ?>;line-height:1"><?= $cnt ?></div>
                </div>
            </a>
            <?php endforeach; ?>
            <?php if (empty($carsByStatus)): ?>
            <div class="text-muted small py-2">No cars at this location.</div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>

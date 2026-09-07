<?php
/**
 * The workshop board.
 *
 * The person running the floor was landing on the same dashboard as the super
 * admin: total fleet, revenue month to date, cars sold. All true, none of it
 * answerable by anyone holding a spanner. This page answers one question instead
 * — what needs doing next — and it answers it in the order trouble happens.
 *
 * So the ordering everywhere is by trouble, not by date or by number: overdue
 * first, then urgent, then everything else. A board that lists the calm work at
 * the top makes you scroll to find the fire.
 *
 * Every count is compared against CURDATE() inside the query rather than in PHP,
 * because PHP runs UTC here and the database runs EAT. Working out "overdue" in
 * PHP is three hours wrong, which for the first three hours of every morning
 * means a whole day wrong.
 */

require_once __DIR__ . '/../../includes/functions.php';
requireLogin();

// Everyone who runs the floor, and nobody who works it. The board carries every
// mechanic's workload side by side, which is a supervisor's view of the room —
// useful to the person allocating work, and surveillance to the person in it.
// Mechanics have their own dashboard showing the jobs that are theirs.
// authRole(), not hasRole(): hasRole() answers yes to every role when the user is
// an admin, which is right for granting and exactly wrong for excluding — it shut
// admins out of their own workshop.
if (!canAccess('jobs') || authRole() === 'mechanic') {
    setFlash('error', 'The workshop floor board is not open to your account.');
    redirect(BASE_URL . '/index.php');
}

$pageTitle = 'Workshop Floor';
$db   = getDB();
$user = authUser();

// A missing table must never take the whole board down. The workshop runs on
// several modules and any one of them can be mid-migration on a given install;
// the rest of the board is still worth showing.
$num = function (string $sql) use ($db): int {
    try { return (int)$db->query($sql)->fetchColumn(); }
    catch (\Throwable $e) { error_log('workshop board: ' . $e->getMessage()); return 0; }
};
$all = function (string $sql) use ($db): array {
    try { return $db->query($sql)->fetchAll(PDO::FETCH_ASSOC); }
    catch (\Throwable $e) { error_log('workshop board: ' . $e->getMessage()); return []; }
};

// ── The numbers ─────────────────────────────────────────────────────────────
$openSql = "j.status NOT IN ('completed','cancelled')";

$k = [
    'on_floor'      => $num("SELECT COUNT(*) FROM cars WHERE status = 'in_workshop'"),
    'open_jobs'     => $num("SELECT COUNT(*) FROM workshop_jobs j WHERE $openSql"),
    'overdue'       => $num("SELECT COUNT(*) FROM workshop_jobs j
                              WHERE $openSql AND j.end_date IS NOT NULL AND j.end_date < CURDATE()"),
    'waiting_parts' => $num("SELECT COUNT(*) FROM workshop_jobs j WHERE j.status = 'waiting_parts'"),
    'on_hold'       => $num("SELECT COUNT(*) FROM workshop_jobs j WHERE j.status = 'on_hold'"),
    'unassigned'    => $num("SELECT COUNT(*) FROM workshop_jobs j
                              WHERE $openSql AND (j.mechanic_id IS NULL OR j.mechanic_id = 0)"),
    'done_week'     => $num("SELECT COUNT(*) FROM workshop_jobs j
                              WHERE j.status = 'completed'
                                AND YEARWEEK(j.updated_at, 1) = YEARWEEK(CURDATE(), 1)"),
    'mechanics'     => $num("SELECT COUNT(*) FROM mechanics WHERE status = 'active'"),
    'parts_pending' => $num("SELECT COUNT(*) FROM parts_requests WHERE status = 'pending'"),
    'due_today'     => $num("SELECT COUNT(*) FROM service_bookings
                              WHERE preferred_date = CURDATE()
                                AND status IN ('pending','confirmed','in_progress')"),
    'booking_late'  => $num("SELECT COUNT(*) FROM service_bookings
                              WHERE preferred_date < CURDATE()
                                AND status IN ('pending','confirmed')"),
];

// The parts store and the fault log stop jobs as surely as a missing mechanic,
// so they are counted here rather than being a page someone remembers to open.
// Each is guarded on its own: car_issues and attendance_records are not on every
// install, and a board that dies because one table is missing is no board.
$k['low_stock']   = $num("SELECT COUNT(*) FROM inventory WHERE quantity <= reorder_level");
$k['out_stock']   = $num("SELECT COUNT(*) FROM inventory WHERE quantity <= 0");
$k['open_issues'] = $num("SELECT COUNT(*) FROM car_issues WHERE status NOT IN ('closed','resolved')");
$k['critical']    = $num("SELECT COUNT(*) FROM car_issues
                            WHERE status NOT IN ('closed','resolved') AND severity = 'critical'");
$k['in_today']    = $num("SELECT COUNT(*) FROM attendance_records
                            WHERE attendance_date = CURDATE() AND staff_type = 'mechanic'
                              AND status IN ('present','late','half_day')");

// How many mechanics are actually holding work, so "6 on duty" can say how many
// of them are free — which is the number you need when a car arrives.
$k['mechanics_busy'] = $num("SELECT COUNT(DISTINCT j.mechanic_id) FROM workshop_jobs j
                              WHERE $openSql AND j.mechanic_id IS NOT NULL AND j.mechanic_id > 0");
$k['mechanics_free'] = max(0, $k['mechanics'] - $k['mechanics_busy']);

// ── On the floor, worst first ───────────────────────────────────────────────
$floor = $all("
    SELECT j.id, j.job_number, j.status, j.priority, j.start_date, j.end_date, j.description,
           c.make, c.model, c.year, c.registration_number,
           m.name AS mechanic_name, m.specialization,
           DATEDIFF(CURDATE(), j.start_date) AS days_open,
           DATEDIFF(j.end_date, CURDATE())   AS days_left
      FROM workshop_jobs j
 LEFT JOIN cars      c ON c.id = j.car_id
 LEFT JOIN mechanics m ON m.id = j.mechanic_id
     WHERE j.status NOT IN ('completed','cancelled')
  ORDER BY (j.end_date IS NOT NULL AND j.end_date < CURDATE()) DESC,
           FIELD(j.priority, 'urgent', 'high', 'normal', 'low'),
           j.end_date IS NULL, j.end_date,
           j.start_date
     LIMIT 25
");

// ── Arriving: today, plus the week ahead, plus anything already missed ───────
$arriving = $all("
    SELECT id, booking_number, client_name, client_phone, service_type, status,
           car_make, car_model, car_registration, preferred_date, preferred_time,
           DATEDIFF(preferred_date, CURDATE()) AS days_away
      FROM service_bookings
     WHERE status IN ('pending','confirmed','in_progress')
       AND preferred_date IS NOT NULL
       AND preferred_date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)
  ORDER BY preferred_date, preferred_time
     LIMIT 12
");

// ── Who is carrying what ────────────────────────────────────────────────────
$crew = $all("
    SELECT m.id, m.name, m.specialization, m.phone,
           SUM(CASE WHEN j.status NOT IN ('completed','cancelled') THEN 1 ELSE 0 END) AS active_jobs,
           SUM(CASE WHEN j.status = 'waiting_parts' THEN 1 ELSE 0 END) AS stuck_jobs,
           SUM(CASE WHEN j.status = 'completed'
                     AND YEARWEEK(j.updated_at, 1) = YEARWEEK(CURDATE(), 1)
                    THEN 1 ELSE 0 END) AS done_week
      FROM mechanics m
 LEFT JOIN workshop_jobs j ON j.mechanic_id = m.id
     WHERE m.status = 'active'
  GROUP BY m.id, m.name, m.specialization, m.phone
  ORDER BY active_jobs DESC, m.name
     LIMIT 12
");
$busiest = 0;
foreach ($crew as $c) $busiest = max($busiest, (int)$c['active_jobs']);

// ── Parts nobody has approved yet ───────────────────────────────────────────
$parts = $all("
    SELECT id, request_number, car_make, car_model, car_registration, client_name,
           created_at, DATEDIFF(CURDATE(), DATE(created_at)) AS waiting_days
      FROM parts_requests
     WHERE status = 'pending'
  ORDER BY created_at
     LIMIT 8
");

// ── Parts that will stop a job ───────────────────────────────────────────────
$stock = $all("
    SELECT id, part_number, part_name, quantity, reorder_level, unit
      FROM inventory
     WHERE quantity <= reorder_level
  ORDER BY (quantity <= 0) DESC, (quantity / NULLIF(reorder_level,0)), part_name
     LIMIT 8
");

// ── Faults raised against vehicles, worst first ─────────────────────────────
$issues = $all("
    SELECT ci.id, ci.issue_number, ci.title, ci.severity, ci.status, ci.reported_at,
           c.make, c.model, c.registration_number,
           m.name AS mechanic_name,
           DATEDIFF(CURDATE(), DATE(ci.reported_at)) AS age_days
      FROM car_issues ci
 LEFT JOIN cars      c ON c.id = ci.car_id
 LEFT JOIN mechanics m ON m.id = ci.assigned_to
     WHERE ci.status NOT IN ('closed','resolved')
  ORDER BY FIELD(ci.severity,'critical','high','medium','low'), ci.reported_at
     LIMIT 8
");

// ── What actually needs a person, said in one line each ─────────────────────
$alerts = [];
if ($k['overdue'])
    $alerts[] = ['fa-clock', $k['overdue'] . ' ' . ($k['overdue'] === 1 ? 'job is' : 'jobs are')
                 . ' past the promised date', BASE_URL . '/modules/jobs/index.php'];
if ($k['unassigned'])
    $alerts[] = ['fa-user-slash', $k['unassigned'] . ' open '
                 . ($k['unassigned'] === 1 ? 'job has' : 'jobs have') . ' no mechanic on it',
                 BASE_URL . '/modules/jobs/index.php'];
if ($k['parts_pending'])
    $alerts[] = ['fa-file-invoice', $k['parts_pending'] . ' quote '
                 . ($k['parts_pending'] === 1 ? 'request is' : 'requests are') . ' waiting for approval',
                 BASE_URL . '/modules/parts_requests/index.php'];
if ($k['booking_late'])
    $alerts[] = ['fa-calendar-xmark', $k['booking_late'] . ' booked '
                 . ($k['booking_late'] === 1 ? 'vehicle' : 'vehicles')
                 . ' never came in — worth a call',
                 BASE_URL . '/modules/service_bookings/index.php'];

if ($k['out_stock'])
    $alerts[] = ['fa-boxes-stacked', $k['out_stock'] . ' parts '
                 . ($k['out_stock'] === 1 ? 'line has' : 'lines have') . ' run out completely',
                 BASE_URL . '/modules/inventory/index.php'];
if ($k['critical'])
    $alerts[] = ['fa-triangle-exclamation', $k['critical'] . ' critical '
                 . ($k['critical'] === 1 ? 'fault is' : 'faults are') . ' still open',
                 BASE_URL . '/modules/issues/index.php'];

$statusLabel = [
    'pending'       => 'Not started',
    'in_progress'   => 'In progress',
    'waiting_parts' => 'Waiting on parts',
    'on_hold'       => 'On hold',
    'confirmed'     => 'Confirmed',
];

include __DIR__ . '/../../includes/header.php';
?>
<style>
/* Matches the admin dashboards rather than inventing a second look — same card,
   same radius, same shadow tokens. Only the meters below are new. */
.wb-title{display:flex;align-items:center;justify-content:space-between;margin-bottom:24px;flex-wrap:wrap;gap:12px}
.wb-title h1{font-size:22px;font-weight:700;color:var(--text);margin:0;display:flex;align-items:center;gap:10px}
.wb-title h1 i{width:38px;height:38px;border-radius:10px;background:#fef3c7;display:flex;
    align-items:center;justify-content:center;font-size:17px;color:#f59e0b}
.wb-live{background:#dcfce7;color:#15803d;font-size:11px;font-weight:700;padding:3px 10px;border-radius:20px}

.wb-alerts{background:#fffbeb;border:1px solid #fde68a;border-radius:var(--r-lg);padding:14px 18px;margin-bottom:22px}
.wb-alert{display:flex;align-items:center;gap:10px;padding:6px 0;font-size:13.5px;color:#92400e;
    text-decoration:none;font-weight:600}
.wb-alert:hover{color:#78350f;text-decoration:underline}
.wb-alert i{width:18px;text-align:center;color:#d97706}

.wb-kpi{background:var(--surface);border:1px solid var(--border);border-radius:var(--r-lg);
    padding:16px 18px;box-shadow:var(--sh);height:100%}
.wb-kpi .v{font-size:26px;font-weight:700;color:var(--text);line-height:1}
.wb-kpi .k{font-size:12px;color:var(--text-2);font-weight:500;margin-top:4px}
.wb-kpi .s{font-size:11px;color:var(--text-3);margin-top:3px}
/* Status is never colour alone — each of these carries its own words underneath. */
.wb-kpi.bad  .v{color:#b91c1c}
.wb-kpi.warn .v{color:#b45309}
.wb-kpi.good .v{color:#15803d}

.wb-card{background:var(--surface);border:1px solid var(--border);border-radius:var(--r-lg);
    box-shadow:var(--sh);margin-bottom:22px;overflow:hidden}
.wb-card > header{display:flex;align-items:center;justify-content:space-between;
    padding:15px 18px;border-bottom:1px solid var(--border)}
.wb-card > header h2{font-size:14px;font-weight:700;color:var(--text);margin:0;
    display:flex;align-items:center;gap:8px}
.wb-card > header a{font-size:12px;font-weight:600;text-decoration:none;color:var(--brand)}
.wb-empty{padding:26px 18px;text-align:center;color:var(--text-3);font-size:13px}

.wb-table{width:100%;border-collapse:collapse;font-size:13px}
.wb-table th{text-align:left;font-size:11px;font-weight:600;color:var(--text-3);
    text-transform:uppercase;letter-spacing:.4px;padding:9px 18px;background:var(--surface-alt)}
.wb-table td{padding:11px 18px;border-top:1px solid var(--border);vertical-align:middle}
.wb-table tbody tr:hover{background:var(--brand-soft)}
.wb-table a{text-decoration:none;color:var(--text);font-weight:600}
.wb-table a:hover{color:var(--brand)}
.wb-sub{font-size:11.5px;color:var(--text-3);margin-top:2px}

.wb-pill{display:inline-flex;align-items:center;gap:5px;padding:3px 9px;border-radius:20px;
    font-size:11px;font-weight:600;white-space:nowrap}
.wb-pill.late{background:#fee2e2;color:#b91c1c}
.wb-pill.soon{background:#fef3c7;color:#b45309}
.wb-pill.ok  {background:#f1f5f9;color:#475569}
.wb-pill.live{background:#dbeafe;color:#1d4ed8}
.wb-pill.done{background:#dcfce7;color:#15803d}

/* Priority reads as a word first; the dot is a second cue, not the only one. */
.wb-prio{display:inline-flex;align-items:center;gap:6px;font-size:11.5px;font-weight:600;color:var(--text-2)}
.wb-prio i{font-size:7px}
.wb-prio.urgent{color:#b91c1c}
.wb-prio.high{color:#c2410c}
.wb-prio.normal{color:var(--text-3)}
.wb-prio.low{color:var(--text-3)}

/* Mechanic load. One hue, magnitude only — thin, rounded, anchored left, with
   the count written beside it so the bar never has to be read on its own. */
.wb-crew{padding:6px 0}
.wb-crew-row{display:flex;align-items:center;gap:12px;padding:10px 18px}
.wb-crew-row + .wb-crew-row{border-top:1px solid var(--border)}
.wb-crew-name{flex:1;min-width:0}
.wb-crew-name b{display:block;font-size:13px;font-weight:600;color:var(--text);
    white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.wb-meter{width:84px;height:6px;border-radius:3px;background:#e2e8f0;overflow:hidden;flex-shrink:0}
.wb-meter span{display:block;height:100%;border-radius:3px;background:var(--brand)}
.wb-meter.heavy span{background:#c2410c}
.wb-count{width:64px;text-align:right;font-size:12px;font-weight:700;color:var(--text);flex-shrink:0}
.wb-count small{display:block;font-weight:500;color:var(--text-3);font-size:10.5px}

/* The status pastels above are light-mode values. Dark mode is its own set of
   steps against the dark surface, not an automatic flip of the light ones — the
   older dashboards skip this and their badges glow on a slate page. */
[data-theme="dark"] .wb-alerts{background:rgba(180,83,9,.14);border-color:#78350f}
[data-theme="dark"] .wb-alert{color:#fcd34d}
[data-theme="dark"] .wb-alert:hover{color:#fde68a}
[data-theme="dark"] .wb-alert i{color:#f59e0b}
[data-theme="dark"] .wb-title h1 i{background:rgba(245,158,11,.18)}
[data-theme="dark"] .wb-live{background:rgba(22,163,74,.18);color:#4ade80}
[data-theme="dark"] .wb-kpi.bad  .v{color:#f87171}
[data-theme="dark"] .wb-kpi.warn .v{color:#fbbf24}
[data-theme="dark"] .wb-kpi.good .v{color:#4ade80}
[data-theme="dark"] .wb-pill.late{background:rgba(220,38,38,.18);color:#fca5a5}
[data-theme="dark"] .wb-pill.soon{background:rgba(217,119,6,.18);color:#fcd34d}
[data-theme="dark"] .wb-pill.ok  {background:rgba(148,163,184,.16);color:#cbd5e1}
[data-theme="dark"] .wb-pill.live{background:rgba(37,99,235,.22);color:#93c5fd}
[data-theme="dark"] .wb-pill.done{background:rgba(22,163,74,.18);color:#86efac}
[data-theme="dark"] .wb-prio.urgent{color:#f87171}
[data-theme="dark"] .wb-prio.high{color:#fb923c}
[data-theme="dark"] .wb-meter{background:#334155}
[data-theme="dark"] .wb-meter.heavy span{background:#fb923c}
</style>

<div class="wb-title">
    <h1><i class="fa fa-screwdriver-wrench"></i>Workshop Floor</h1>
    <div class="d-flex align-items-center gap-3">
        <span class="wb-live"><i class="fa fa-circle-dot fa-xs me-1"></i>Live</span>
        <span style="font-size:12.5px;color:var(--text-2)"><?= date('D d M Y, H:i') ?></span>
        <button onclick="location.reload()" class="btn btn-sm btn-outline-secondary">
            <i class="fa fa-rotate-right me-1"></i>Refresh</button>
    </div>
</div>

<?php if ($alerts): ?>
<div class="wb-alerts">
    <?php foreach ($alerts as [$icon, $text, $href]): ?>
    <a class="wb-alert" href="<?= $href ?>"><i class="fa <?= $icon ?>"></i><?= e($text) ?></a>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="row g-3 mb-1">
    <?php
    $tiles = [
        ['On the floor',   $k['on_floor'],      'vehicles in the workshop',                       ''],
        ['Open job cards', $k['open_jobs'],     $k['unassigned'] . ' with no mechanic',           ''],
        ['Past the date',  $k['overdue'],       'promised and not delivered',                     $k['overdue'] ? 'bad' : ''],
        ['Waiting on parts', $k['waiting_parts'], $k['on_hold'] . ' more on hold',                $k['waiting_parts'] ? 'warn' : ''],
        ['Due in today',   $k['due_today'],     $k['booking_late'] . ' already missed',           $k['due_today'] ? 'good' : ''],
        ['Parts running low', $k['low_stock'],  $k['out_stock'] . ' of them at zero',        $k['out_stock'] ? 'bad' : ($k['low_stock'] ? 'warn' : '')],
        ['Faults open',       $k['open_issues'], $k['critical'] . ' critical',               $k['critical'] ? 'bad' : ''],
        ['Finished this week', $k['done_week'], $k['mechanics_free'] . ' of ' . $k['mechanics'] . ' mechanics free', 'good'],
    ];
    foreach ($tiles as [$label, $value, $sub, $tone]): ?>
    <div class="col-6 col-lg-3">
        <div class="wb-kpi <?= $tone ?>">
            <div class="v"><?= (int)$value ?></div>
            <div class="k"><?= e($label) ?></div>
            <div class="s"><?= e($sub) ?></div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<div class="row g-3 mt-1">
    <div class="col-lg-8">

        <div class="wb-card">
            <header>
                <h2><i class="fa fa-toolbox" style="color:#f59e0b"></i>On the floor</h2>
                <a href="<?= BASE_URL ?>/modules/jobs/index.php">All job cards</a>
            </header>
            <?php if (!$floor): ?>
                <div class="wb-empty">No job cards are open. The floor is clear.</div>
            <?php else: ?>
            <div class="table-responsive">
            <table class="wb-table">
                <thead><tr>
                    <th>Job</th><th>Vehicle</th><th>Mechanic</th>
                    <th>Priority</th><th>Open</th><th>Due</th><th>Status</th>
                </tr></thead>
                <tbody>
                <?php foreach ($floor as $j):
                    $car = trim(($j['year'] ?? '') . ' ' . ($j['make'] ?? '') . ' ' . ($j['model'] ?? ''));
                    $late = $j['end_date'] !== null && (int)$j['days_left'] < 0;
                    $prio = in_array($j['priority'], ['urgent','high','normal','low'], true)
                          ? $j['priority'] : 'normal';
                ?>
                <tr>
                    <td><a href="<?= BASE_URL ?>/modules/jobs/view.php?id=<?= (int)$j['id'] ?>">
                        <?= e($j['job_number']) ?></a></td>
                    <td>
                        <?= e($car !== '' ? $car : 'Vehicle not linked') ?>
                        <?php if (!empty($j['registration_number'])): ?>
                        <div class="wb-sub"><?= e($j['registration_number']) ?></div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if (!empty($j['mechanic_name'])): ?>
                            <?= e($j['mechanic_name']) ?>
                            <?php if (!empty($j['specialization'])): ?>
                            <div class="wb-sub"><?= e($j['specialization']) ?></div>
                            <?php endif; ?>
                        <?php else: ?>
                            <span class="wb-pill late"><i class="fa fa-user-slash"></i>Unassigned</span>
                        <?php endif; ?>
                    </td>
                    <td><span class="wb-prio <?= $prio ?>"><i class="fa fa-circle"></i><?= ucfirst($prio) ?></span></td>
                    <td><?= $j['start_date'] ? (int)$j['days_open'] . 'd' : '—' ?></td>
                    <td>
                        <?php if ($j['end_date'] === null): ?>
                            <span class="wb-pill ok">No date</span>
                        <?php elseif ($late): ?>
                            <span class="wb-pill late"><i class="fa fa-clock"></i>
                                <?= abs((int)$j['days_left']) ?>d late</span>
                        <?php elseif ((int)$j['days_left'] === 0): ?>
                            <span class="wb-pill soon"><i class="fa fa-clock"></i>Today</span>
                        <?php elseif ((int)$j['days_left'] <= 2): ?>
                            <span class="wb-pill soon"><?= (int)$j['days_left'] ?>d left</span>
                        <?php else: ?>
                            <span class="wb-pill ok"><?= (int)$j['days_left'] ?>d left</span>
                        <?php endif; ?>
                    </td>
                    <td><span class="wb-pill <?= $j['status'] === 'in_progress' ? 'live'
                            : ($j['status'] === 'waiting_parts' ? 'soon' : 'ok') ?>">
                        <?= e($statusLabel[$j['status']] ?? ucfirst(str_replace('_', ' ', $j['status']))) ?>
                    </span></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <?php endif; ?>
        </div>

        <div class="wb-card">
            <header>
                <h2><i class="fa fa-calendar-check" style="color:#2563eb"></i>Coming in</h2>
                <a href="<?= BASE_URL ?>/modules/service_bookings/index.php">The diary</a>
            </header>
            <?php if (!$arriving): ?>
                <div class="wb-empty">Nothing is booked in for the next week.</div>
            <?php else: ?>
            <div class="table-responsive">
            <table class="wb-table">
                <thead><tr>
                    <th>When</th><th>Client</th><th>Vehicle</th><th>Service</th><th>Status</th>
                </tr></thead>
                <tbody>
                <?php foreach ($arriving as $b):
                    $days = (int)$b['days_away'];
                    $car  = trim(($b['car_make'] ?? '') . ' ' . ($b['car_model'] ?? ''));
                ?>
                <tr>
                    <td>
                        <?php if ($days < 0): ?>
                            <span class="wb-pill late"><i class="fa fa-calendar-xmark"></i>
                                <?= abs($days) ?>d missed</span>
                        <?php elseif ($days === 0): ?>
                            <span class="wb-pill soon"><i class="fa fa-star"></i>Today<?=
                                $b['preferred_time'] ? ' ' . e($b['preferred_time']) : '' ?></span>
                        <?php elseif ($days === 1): ?>
                            <span class="wb-pill ok">Tomorrow</span>
                        <?php else: ?>
                            <span class="wb-pill ok"><?= e(fmtDate($b['preferred_date'], 'D d M')) ?></span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <a href="<?= BASE_URL ?>/modules/service_bookings/view.php?id=<?= (int)$b['id'] ?>">
                            <?= e($b['client_name']) ?></a>
                        <div class="wb-sub"><?= e($b['client_phone']) ?></div>
                    </td>
                    <td>
                        <?= e($car !== '' ? $car : 'Not given') ?>
                        <?php if (!empty($b['car_registration'])): ?>
                        <div class="wb-sub"><?= e($b['car_registration']) ?></div>
                        <?php endif; ?>
                    </td>
                    <td><?= e($b['service_type']) ?></td>
                    <td><span class="wb-pill <?= $b['status'] === 'pending' ? 'soon' : 'live' ?>">
                        <?= e($statusLabel[$b['status']] ?? ucfirst(str_replace('_', ' ', $b['status']))) ?>
                    </span></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <?php endif; ?>
        </div>

    </div>

    <div class="col-lg-4">

        <div class="wb-card">
            <header>
                <h2><i class="fa fa-helmet-safety" style="color:#0891b2"></i>The crew<?php
                    // Only shown when somebody has actually marked the register today;
                    // "0 in" on a morning nobody has filled it in would be a lie.
                    if ($k['in_today'] > 0): ?>
                    <span class="wb-pill done"><?= (int)$k['in_today'] ?> in today</span>
                <?php endif; ?></h2>
                <a href="<?= BASE_URL ?>/modules/mechanics/index.php">Mechanics</a>
            </header>
            <?php if (!$crew): ?>
                <div class="wb-empty">No active mechanics on file.</div>
            <?php else: ?>
            <div class="wb-crew">
                <?php foreach ($crew as $c):
                    $load  = (int)$c['active_jobs'];
                    $pct   = $busiest > 0 ? max(6, (int)round($load / $busiest * 100)) : 0;
                    $heavy = $busiest >= 3 && $load === $busiest && $load > 1;
                ?>
                <div class="wb-crew-row">
                    <div class="wb-crew-name">
                        <b><?= e($c['name']) ?></b>
                        <div class="wb-sub"><?= e($c['specialization'] ?: 'General') ?><?=
                            (int)$c['stuck_jobs'] ? ' · ' . (int)$c['stuck_jobs'] . ' on parts' : '' ?></div>
                    </div>
                    <div class="wb-meter <?= $heavy ? 'heavy' : '' ?>">
                        <span style="width:<?= $load > 0 ? $pct : 0 ?>%"></span>
                    </div>
                    <div class="wb-count">
                        <?= $load ?: '—' ?>
                        <small><?= $load === 0 ? 'free'
                            : ((int)$c['done_week'] > 0 ? (int)$c['done_week'] . ' done this week' : 'open') ?></small>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>

        <div class="wb-card">
            <header>
                <h2><i class="fa fa-boxes-stacked" style="color:#d97706"></i>Parts running low</h2>
                <a href="<?= BASE_URL ?>/modules/inventory/index.php">Parts stock</a>
            </header>
            <?php if (!$stock): ?>
                <div class="wb-empty">Every part is above its reorder level.</div>
            <?php else: ?>
            <table class="wb-table">
                <tbody>
                <?php foreach ($stock as $it): $q = (int)$it['quantity']; ?>
                <tr>
                    <td>
                        <?= e($it['part_name']) ?>
                        <div class="wb-sub"><?= e($it['part_number'] ?: 'no part number') ?></div>
                    </td>
                    <td style="text-align:right;width:110px">
                        <span class="wb-pill <?= $q <= 0 ? 'late' : 'soon' ?>">
                            <?= $q <= 0 ? 'Out of stock'
                                : $q . ' ' . e($it['unit'] ?: 'left') ?></span>
                        <div class="wb-sub">reorder at <?= (int)$it['reorder_level'] ?></div>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>

        <div class="wb-card">
            <header>
                <h2><i class="fa fa-triangle-exclamation" style="color:#dc2626"></i>Faults raised</h2>
                <a href="<?= BASE_URL ?>/modules/issues/index.php">All issues</a>
            </header>
            <?php if (!$issues): ?>
                <div class="wb-empty">No faults are open against any vehicle.</div>
            <?php else: ?>
            <table class="wb-table">
                <tbody>
                <?php foreach ($issues as $is):
                    $car = trim(($is['make'] ?? '') . ' ' . ($is['model'] ?? ''));
                    $sev = in_array($is['severity'], ['critical','high','medium','low'], true)
                         ? $is['severity'] : 'medium';
                ?>
                <tr>
                    <td>
                        <a href="<?= BASE_URL ?>/modules/issues/index.php"><?= e($is['title']) ?></a>
                        <div class="wb-sub">
                            <?= e($car !== '' ? $car : 'no vehicle') ?><?=
                                !empty($is['registration_number']) ? ' · ' . e($is['registration_number']) : '' ?><?=
                                !empty($is['mechanic_name']) ? ' · ' . e($is['mechanic_name']) : ' · unassigned' ?>
                        </div>
                    </td>
                    <td style="text-align:right;width:96px">
                        <span class="wb-pill <?= in_array($sev, ['critical','high'], true) ? 'late' : 'ok' ?>">
                            <?= ucfirst($sev) ?></span>
                        <div class="wb-sub"><?= (int)$is['age_days'] ?>d old</div>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>

        <div class="wb-card">
            <header>
                <h2><i class="fa fa-file-invoice" style="color:#7c3aed"></i>Quotes to approve</h2>
                <a href="<?= BASE_URL ?>/modules/parts_requests/index.php">All requests</a>
            </header>
            <?php if (!$parts): ?>
                <div class="wb-empty">Nothing is waiting for approval.</div>
            <?php else: ?>
            <table class="wb-table">
                <tbody>
                <?php foreach ($parts as $p):
                    $car = trim(($p['car_make'] ?? '') . ' ' . ($p['car_model'] ?? ''));
                    $w   = (int)$p['waiting_days'];
                ?>
                <tr>
                    <td>
                        <a href="<?= BASE_URL ?>/modules/parts_requests/view.php?id=<?= (int)$p['id'] ?>">
                            <?= e($p['request_number']) ?></a>
                        <div class="wb-sub">
                            <?= e($car !== '' ? $car : ($p['client_name'] ?: 'No vehicle')) ?>
                            <?= !empty($p['car_registration']) ? ' · ' . e($p['car_registration']) : '' ?>
                        </div>
                    </td>
                    <td style="text-align:right;width:96px">
                        <span class="wb-pill <?= $w >= 3 ? 'late' : ($w >= 1 ? 'soon' : 'ok') ?>">
                            <?= $w <= 0 ? 'Today' : $w . 'd waiting' ?>
                        </span>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>

    </div>
</div>

<?php include __DIR__ . '/../../includes/footer.php'; ?>

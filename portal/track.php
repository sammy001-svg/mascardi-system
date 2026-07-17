<?php
/**
 * Public Service Tracker — No Login Required
 *
 * Clients can look up their service booking or car's workshop status
 * by entering their phone number or booking reference.
 *
 * URL: /portal/track.php
 *      /portal/track.php?ref=BK-0042
 *      /portal/track.php?phone=0712345678
 */

require_once __DIR__ . '/../includes/functions.php';
// No requireLogin() — this is a public page

$db         = getDB();
$companyName = getSetting('company_name', APP_NAME);
$logoUrl     = BASE_URL . '/Logo__Edit.webp';

$results   = [];
$query     = trim($_GET['q'] ?? '');
$searched  = false;
$error     = '';

if ($query !== '') {
    $searched = true;
    $sanitized = preg_replace('/[^A-Za-z0-9\-\s]/', '', $query);
    $phoneSanitized = preg_replace('/[^0-9+]/', '', $query);

    try {
        // Search by booking reference OR phone OR client name
        $stmt = $db->prepare("
            SELECT
                sb.id,
                sb.booking_number,
                sb.client_name,
                sb.client_phone,
                sb.service_type,
                sb.preferred_date,
                sb.confirmed_date,
                sb.status AS booking_status,
                sb.notes AS booking_notes,
                sb.created_at AS booked_at,
                c.make,
                c.model,
                c.year,
                c.chassis_number,
                c.status AS car_status,
                c.registration_number,
                -- Active job card for this booking (if any)
                (SELECT j.status FROM workshop_jobs j WHERE j.car_id = sb.car_id
                 AND j.status NOT IN ('cancelled') ORDER BY j.created_at DESC LIMIT 1) AS job_status,
                (SELECT j.job_number FROM workshop_jobs j WHERE j.car_id = sb.car_id
                 AND j.status NOT IN ('cancelled') ORDER BY j.created_at DESC LIMIT 1) AS job_number,
                (SELECT j.updated_at FROM workshop_jobs j WHERE j.car_id = sb.car_id
                 AND j.status NOT IN ('cancelled') ORDER BY j.created_at DESC LIMIT 1) AS job_updated
            FROM service_bookings sb
            LEFT JOIN cars c ON c.id = sb.car_id
            WHERE
                sb.booking_number = ?
                OR sb.client_phone LIKE ?
                OR (LENGTH(?) >= 3 AND sb.client_name LIKE ?)
            ORDER BY sb.created_at DESC
            LIMIT 10
        ");
        $stmt->execute([
            $sanitized,
            '%' . $phoneSanitized . '%',
            $phoneSanitized,
            '%' . $sanitized . '%',
        ]);
        $results = $stmt->fetchAll();
    } catch (Throwable $e) {
        $error = 'Sorry, a lookup error occurred. Please try again.';
        error_log('[track.php] ' . $e->getMessage());
    }
}

// ── Status helpers ─────────────────────────────────────────────────────────────
function trackingStep(string $bookingStatus, ?string $carStatus, ?string $jobStatus): int {
    // Returns 0-4 step index for the progress stepper
    if ($bookingStatus === 'cancelled') return -1;
    if ($bookingStatus === 'pending')   return 0;
    if ($bookingStatus === 'confirmed' && !$jobStatus) return 1;
    if ($jobStatus === 'in_progress')   return 2;
    if ($jobStatus === 'completed' || $carStatus === 'completed') return 3;
    if ($carStatus === 'delivered')     return 4;
    return 1;
}

function stepLabel(int $step): string {
    $labels = ['Booking Received', 'Booking Confirmed', 'In Workshop', 'Work Completed', 'Delivered'];
    return $labels[$step] ?? '';
}

$steps = ['Booking Received', 'Booking Confirmed', 'In Workshop', 'Work Completed', 'Delivered'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Track My Service — <?= e($companyName) ?></title>
<meta name="description" content="Track your vehicle service booking status at <?= e($companyName) ?>. Enter your phone number or booking reference to see real-time updates.">
<meta name="robots" content="noindex,nofollow">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
<style>
*, body { font-family: 'Inter', sans-serif; }
body    { background: #f1f5f9; min-height: 100vh; }

/* ── Navbar ── */
.track-nav {
    background: #0f172a;
    padding: .9rem 0;
    box-shadow: 0 2px 12px rgba(0,0,0,.2);
}
.track-nav .brand {
    color: #fff;
    font-weight: 800;
    font-size: 18px;
    text-decoration: none;
    display: flex;
    align-items: center;
    gap: .6rem;
}
.track-nav .brand img {
    height: 32px;
    object-fit: contain;
    filter: brightness(0) invert(1);
}
.track-nav .nav-pill {
    background: rgba(255,255,255,.1);
    color: rgba(255,255,255,.7);
    border-radius: 20px;
    padding: .3rem .85rem;
    font-size: 12.5px;
    font-weight: 500;
    border: 1px solid rgba(255,255,255,.15);
}

/* ── Hero ── */
.hero {
    background: linear-gradient(135deg, #0f172a 0%, #1e3a5f 60%, #1d4ed8 100%);
    padding: 3.5rem 0 2.5rem;
    color: #fff;
    text-align: center;
}
.hero h1 { font-size: 2rem; font-weight: 800; margin-bottom: .5rem; }
.hero p  { color: rgba(255,255,255,.75); font-size: 15px; margin-bottom: 0; }

/* ── Search box ── */
.search-card {
    background: #fff;
    border-radius: 16px;
    padding: 1.75rem 2rem;
    box-shadow: 0 4px 24px rgba(0,0,0,.12);
    margin-top: -2rem;
    position: relative;
    z-index: 10;
}
.search-input {
    font-size: 15px;
    border: 2px solid #e2e8f0;
    border-radius: 10px;
    padding: .75rem 1.1rem;
    transition: border-color .2s;
}
.search-input:focus { border-color: #2563eb; box-shadow: 0 0 0 3px rgba(37,99,235,.1); }
.btn-track {
    background: linear-gradient(135deg, #2563eb, #1d4ed8);
    color: #fff;
    border: none;
    border-radius: 10px;
    padding: .75rem 1.75rem;
    font-weight: 600;
    font-size: 15px;
    transition: transform .15s, box-shadow .15s;
}
.btn-track:hover { transform: translateY(-1px); box-shadow: 0 4px 16px rgba(37,99,235,.3); color: #fff; }

/* ── Result card ── */
.result-card {
    background: #fff;
    border-radius: 16px;
    box-shadow: 0 2px 12px rgba(0,0,0,.07);
    overflow: hidden;
    margin-bottom: 1.5rem;
    border: 1px solid #e2e8f0;
}
.result-card-header {
    background: #f8fafc;
    padding: 1rem 1.5rem;
    border-bottom: 1px solid #f1f5f9;
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: .5rem;
}
.booking-ref {
    font-weight: 700;
    font-size: 15px;
    color: #1e293b;
}
.booking-ref span {
    font-family: monospace;
    background: #eff6ff;
    color: #2563eb;
    padding: .2em .5em;
    border-radius: 6px;
    margin-left: .25rem;
}

/* ── Progress stepper ── */
.stepper {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    padding: 1.5rem 1.5rem 1rem;
    gap: .25rem;
}
.step {
    flex: 1;
    display: flex;
    flex-direction: column;
    align-items: center;
    text-align: center;
    position: relative;
}
.step:not(:last-child)::after {
    content: '';
    position: absolute;
    top: 16px;
    left: 50%;
    right: -50%;
    height: 2px;
    background: #e2e8f0;
    z-index: 0;
}
.step.done:not(:last-child)::after,
.step.current:not(:last-child)::after {
    background: linear-gradient(90deg, #2563eb, #e2e8f0);
}
.step-circle {
    width: 34px;
    height: 34px;
    border-radius: 50%;
    background: #e2e8f0;
    display: flex;
    align-items: center;
    justify-content: center;
    position: relative;
    z-index: 1;
    font-size: 13px;
    font-weight: 700;
    color: #94a3b8;
    border: 2px solid #e2e8f0;
    transition: all .25s;
}
.step.done   .step-circle { background: #16a34a; color: #fff; border-color: #16a34a; }
.step.current .step-circle { background: #2563eb; color: #fff; border-color: #2563eb; box-shadow: 0 0 0 4px rgba(37,99,235,.2); }
.step-label {
    font-size: 10.5px;
    font-weight: 500;
    color: #94a3b8;
    margin-top: 6px;
    line-height: 1.3;
}
.step.done   .step-label,
.step.current .step-label { color: #1e293b; font-weight: 600; }

/* ── Info rows ── */
.info-row  { padding: .6rem 1.5rem; display: flex; align-items: center; gap: .75rem; font-size: 13.5px; border-bottom: 1px solid #f8fafc; }
.info-row:last-child { border-bottom: none; }
.info-label { color: #64748b; font-weight: 500; min-width: 130px; }
.info-val   { color: #1e293b; font-weight: 600; }

/* ── Empty & tips ── */
.no-results { text-align: center; padding: 3rem 1rem; color: #64748b; }
.tip-card   { background: #eff6ff; border-radius: 12px; padding: 1rem 1.25rem; border: 1px solid #bfdbfe; margin-bottom: 1rem; }
.tip-card .tip-title { font-weight: 700; color: #1d4ed8; font-size: 13.5px; margin-bottom: .25rem; }
.tip-card .tip-body  { color: #374151; font-size: 13px; }

/* ── Footer ── */
.track-footer { background: #0f172a; color: rgba(255,255,255,.5); padding: 2rem 0; text-align: center; font-size: 12.5px; margin-top: 4rem; }
.track-footer a { color: rgba(255,255,255,.6); }

@media (max-width: 576px) {
    .hero h1       { font-size: 1.5rem; }
    .search-card   { padding: 1.25rem; border-radius: 12px; }
    .stepper       { padding: 1rem; gap: 0; }
    .step-circle   { width: 28px; height: 28px; font-size: 11px; }
    .step-label    { font-size: 9px; }
    .info-row      { flex-direction: column; align-items: flex-start; gap: .15rem; padding: .6rem 1rem; }
    .info-label    { min-width: auto; }
}
</style>
</head>
<body>

<!-- ── Navbar ── -->
<nav class="track-nav">
    <div class="container d-flex align-items-center justify-content-between">
        <a class="brand" href="<?= BASE_URL ?>/showroom/">
            <img src="<?= $logoUrl ?>" alt="<?= e($companyName) ?> logo" onerror="this.style.display='none'">
            <?= e($companyName) ?>
        </a>
        <span class="nav-pill"><i class="fa fa-search-location me-1"></i>Service Tracker</span>
    </div>
</nav>

<!-- ── Hero ── -->
<div class="hero">
    <div class="container">
        <h1><i class="fa fa-magnifying-glass-chart me-2"></i>Track Your Service</h1>
        <p>Enter your booking reference or phone number to see real-time updates on your vehicle.</p>
    </div>
</div>

<!-- ── Search Card ── -->
<div class="container" style="max-width:640px">
    <div class="search-card">
        <form method="GET" action="" id="trackForm">
            <div class="mb-3">
                <label class="form-label fw-semibold" for="q" style="font-size:14px">
                    <i class="fa fa-ticket me-1 text-primary"></i>Booking Reference or Phone Number
                </label>
                <input
                    type="text"
                    name="q"
                    id="q"
                    class="form-control search-input"
                    value="<?= e($query) ?>"
                    placeholder="e.g. BK-0042 or 0712 345 678"
                    autocomplete="off"
                    autofocus
                >
                <div class="form-text text-muted" style="font-size:12px">
                    <i class="fa fa-lock me-1"></i>Your information is kept private and secure.
                </div>
            </div>
            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-track flex-grow-1" id="searchBtn">
                    <i class="fa fa-magnifying-glass me-2"></i>Track Now
                </button>
                <?php if ($query): ?>
                <a href="<?= BASE_URL ?>/portal/track.php" class="btn btn-outline-secondary" style="border-radius:10px;padding:.75rem 1rem">
                    <i class="fa fa-xmark"></i>
                </a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <!-- ── Results ── -->
    <?php if ($searched): ?>
    <div class="mt-4">

        <?php if ($error): ?>
        <div class="alert alert-danger">
            <i class="fa fa-circle-exclamation me-2"></i><?= e($error) ?>
        </div>

        <?php elseif (empty($results)): ?>
        <div class="result-card">
            <div class="no-results">
                <i class="fa fa-car-burst fa-3x mb-3 d-block" style="color:#e2e8f0"></i>
                <div class="fw-semibold mb-1" style="font-size:16px">No bookings found</div>
                <p class="small text-muted mb-3">
                    We couldn't find a booking matching <strong>"<?= e($query) ?>"</strong>.<br>
                    Please double-check your reference or phone number.
                </p>
                <div class="tip-card text-start mx-auto" style="max-width:360px">
                    <div class="tip-title"><i class="fa fa-lightbulb me-1"></i>Tip</div>
                    <div class="tip-body">Try the booking reference on your confirmation SMS (e.g. <strong>BK-0042</strong>), or the phone number you used when making the booking.</div>
                </div>
            </div>
        </div>

        <?php else: ?>
        <div class="text-muted small mb-3 fw-semibold">
            <i class="fa fa-list-check me-1"></i><?= count($results) ?> booking(s) found
        </div>

        <?php foreach ($results as $r):
            $step   = trackingStep($r['booking_status'], $r['car_status'], $r['job_status']);
            $isCancelled = $step === -1;
        ?>
        <div class="result-card">
            <!-- Header -->
            <div class="result-card-header">
                <div>
                    <div class="booking-ref">
                        <i class="fa fa-ticket text-primary me-1"></i>Booking<span><?= e($r['booking_number']) ?></span>
                    </div>
                    <div class="text-muted" style="font-size:11.5px;margin-top:2px">
                        Booked on <?= fmtDate($r['booked_at'], 'd M Y') ?>
                    </div>
                </div>
                <div class="text-end">
                    <?= statusBadge($r['booking_status']) ?>
                    <?php if ($r['car_status'] && $r['car_status'] !== $r['booking_status']): ?>
                    <div class="mt-1"><?= statusBadge($r['car_status']) ?></div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Progress Stepper -->
            <?php if (!$isCancelled): ?>
            <div class="stepper">
                <?php foreach ($steps as $i => $lbl): ?>
                <?php
                    $cls = 'pending';
                    if ($i < $step)       $cls = 'done';
                    elseif ($i === $step) $cls = 'current';
                ?>
                <div class="step <?= $cls ?>">
                    <div class="step-circle">
                        <?php if ($i < $step): ?>
                            <i class="fa fa-check" style="font-size:12px"></i>
                        <?php else: ?>
                            <?= $i + 1 ?>
                        <?php endif; ?>
                    </div>
                    <div class="step-label"><?= $lbl ?></div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php else: ?>
            <div class="alert alert-danger m-3 py-2 small mb-0">
                <i class="fa fa-ban me-2"></i>This booking has been cancelled. Please contact us to rebook.
            </div>
            <?php endif; ?>

            <!-- Detail Rows -->
            <div style="padding-top:.5rem">
                <div class="info-row">
                    <i class="fa fa-user text-primary" style="width:16px"></i>
                    <span class="info-label">Client</span>
                    <span class="info-val"><?= e($r['client_name']) ?></span>
                </div>
                <div class="info-row">
                    <i class="fa fa-phone text-success" style="width:16px"></i>
                    <span class="info-label">Phone</span>
                    <span class="info-val"><?= e($r['client_phone'] ?? '—') ?></span>
                </div>
                <?php if ($r['make']): ?>
                <div class="info-row">
                    <i class="fa fa-car text-primary" style="width:16px"></i>
                    <span class="info-label">Vehicle</span>
                    <span class="info-val"><?= e(trim(($r['year'] ?? '') . ' ' . $r['make'] . ' ' . $r['model'])) ?></span>
                </div>
                <?php endif; ?>
                <?php if ($r['registration_number']): ?>
                <div class="info-row">
                    <i class="fa fa-hashtag text-secondary" style="width:16px"></i>
                    <span class="info-label">Registration</span>
                    <span class="info-val"><code><?= e($r['registration_number']) ?></code></span>
                </div>
                <?php endif; ?>
                <div class="info-row">
                    <i class="fa fa-wrench text-warning" style="width:16px"></i>
                    <span class="info-label">Services</span>
                    <span class="info-val" style="font-size:12.5px"><?= e($r['service_type'] ?? '—') ?></span>
                </div>
                <div class="info-row">
                    <i class="fa fa-calendar text-info" style="width:16px"></i>
                    <span class="info-label">Booked Date</span>
                    <span class="info-val"><?= fmtDate($r['confirmed_date'] ?? $r['preferred_date'], 'd M Y') ?></span>
                </div>
                <?php if ($r['job_number']): ?>
                <div class="info-row" style="background:#f0fdf4">
                    <i class="fa fa-clipboard-list text-success" style="width:16px"></i>
                    <span class="info-label">Job Card #</span>
                    <span class="info-val text-success"><?= e($r['job_number']) ?></span>
                </div>
                <?php if ($r['job_updated']): ?>
                <div class="info-row" style="background:#f0fdf4">
                    <i class="fa fa-clock text-success" style="width:16px"></i>
                    <span class="info-label">Last Updated</span>
                    <span class="info-val text-success"><?= fmtDate($r['job_updated'], 'd M Y, H:i') ?></span>
                </div>
                <?php endif; ?>
                <?php endif; ?>
            </div>

            <!-- Footer action -->
            <div style="padding:.75rem 1.5rem;border-top:1px solid #f1f5f9;background:#f8fafc;font-size:12.5px;color:#64748b;display:flex;justify-content:space-between;align-items:center">
                <span><i class="fa fa-info-circle me-1"></i>Questions? Call us or visit the workshop.</span>
                <?php if (getSetting('company_phone')): ?>
                <a href="tel:<?= e(getSetting('company_phone')) ?>" class="btn btn-sm btn-outline-success" style="border-radius:8px;font-size:12px">
                    <i class="fa fa-phone me-1"></i>Call Us
                </a>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- ── How-to tips (only when no search) ── -->
    <?php if (!$searched): ?>
    <div class="mt-4">
        <div class="tip-card">
            <div class="tip-title"><i class="fa fa-ticket me-1"></i>Using a Booking Reference</div>
            <div class="tip-body">Enter the reference from your booking confirmation SMS or email, e.g. <strong>BK-0042</strong></div>
        </div>
        <div class="tip-card">
            <div class="tip-title"><i class="fa fa-mobile-screen-button me-1"></i>Using Your Phone Number</div>
            <div class="tip-body">Enter the phone number you used when booking, e.g. <strong>0712 345 678</strong></div>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- ── Footer ── -->
<footer class="track-footer">
    <div class="container">
        <div style="margin-bottom:.5rem"><?= e($companyName) ?> &mdash; Service Tracking Portal</div>
        <div>
            <a href="<?= BASE_URL ?>/showroom/">Visit our Showroom</a>
            &nbsp;·&nbsp;
            <a href="<?= BASE_URL ?>/portal/">Client Login</a>
        </div>
    </div>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Show loading state while searching
document.getElementById('trackForm').addEventListener('submit', function() {
    var btn = document.getElementById('searchBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fa fa-spinner fa-spin me-2"></i>Searching…';
});
</script>
</body>
</html>

<?php
/**
 * Focused sidebar rendered for supervisor role.
 * Supervisor sees only their assigned location's data.
 * Included from sidebar.php via early-exit pattern.
 */
$__uri    = $_SERVER['REQUEST_URI'];
$__isDash = str_contains($__uri, '/modules/supervisor/dashboard');

// Load supervisor's location info
$__supLoc = null;
try {
    $__supLocId = supervisorLocationId();
    if ($__supLocId) {
        $__supLoc = getDB()->prepare("SELECT name FROM locations WHERE id=?");
        $__supLoc->execute([$__supLocId]);
        $__supLoc = $__supLoc->fetchColumn() ?: null;
    }
} catch (\Throwable $_) {}
?>
<div class="app-sidebar" id="sidebar">

    <!-- Brand -->
    <div class="sidebar-brand">
        <div class="brand-logo">
            <?php $__logo = getSetting('company_logo', ''); ?>
            <?php if ($__logo && file_exists(BASE_PATH . '/assets/images/' . $__logo)): ?>
            <img src="<?= BASE_URL ?>/assets/images/<?= e($__logo) ?>" alt="Logo"
                 style="height:32px;width:32px;object-fit:contain;border-radius:4px">
            <?php else: ?>
            <i class="fa fa-car-side" style="font-size:16px"></i>
            <?php endif; ?>
        </div>
        <div class="brand-text">
            <span class="brand-name"><?= e(getSetting('company_name', 'Mascardi')) ?></span>
            <span class="brand-sub" style="color:#22d3ee;font-size:9px;font-weight:700;letter-spacing:.7px;text-transform:uppercase">Supervisor Portal</span>
        </div>
    </div>

    <!-- Location Badge -->
    <?php if ($__supLoc): ?>
    <div style="padding:6px 14px 2px;margin:0 8px 4px;background:rgba(34,211,238,.12);border-radius:8px;border:1px solid rgba(34,211,238,.25)">
        <div style="font-size:9.5px;color:#94a3b8;text-transform:uppercase;letter-spacing:.06em;font-weight:600">Your Location</div>
        <div style="font-size:12.5px;color:#22d3ee;font-weight:700;margin-top:1px">
            <i class="fa fa-location-dot me-1" style="font-size:11px"></i><?= e($__supLoc) ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Navigation -->
    <nav class="sidebar-nav">

        <!-- ══ DASHBOARD ════════════════════════════════════════ -->
        <div class="nav-section">Overview</div>

        <a href="<?= BASE_URL ?>/modules/supervisor/dashboard.php"
           class="nav-item <?= $__isDash ? 'active' : '' ?>"
           data-label="Dashboard">
            <i class="fa fa-gauge-high"></i><span>Dashboard</span>
        </a>

        <!-- ══ FLEET ═════════════════════════════════════════════ -->
        <div class="nav-section">Fleet</div>

        <a href="<?= BASE_URL ?>/modules/supervisor/cars.php"
           class="nav-item <?= str_contains($__uri, '/modules/supervisor/cars') ? 'active' : '' ?>"
           data-label="My Location Cars"
           style="position:relative">
            <i class="fa fa-car"></i><span>Cars at Location</span>
            <?php
            try {
                $__locId = supervisorLocationId();
                if ($__locId) {
                    $__carCount = (int)getDB()->prepare("SELECT COUNT(*) FROM cars WHERE location_id=?")->execute([$__locId]) ? null : 0;
                    $__s = getDB()->prepare("SELECT COUNT(*) FROM cars WHERE location_id=? AND status NOT IN ('sold','delivered')");
                    $__s->execute([$__locId]);
                    $__carCount = (int)$__s->fetchColumn();
                    if ($__carCount > 0): ?>
                <span style="position:absolute;top:6px;right:8px;background:#2563eb;color:#fff;border-radius:10px;font-size:10px;font-weight:700;padding:1px 5px;min-width:16px;text-align:center;line-height:16px">
                    <?= $__carCount > 99 ? '99+' : $__carCount ?>
                </span>
            <?php endif; }} catch (\Throwable $_) {} ?>
        </a>

        <!-- ══ OPERATIONS ════════════════════════════════════════ -->
        <div class="nav-section">Operations</div>

        <a href="<?= BASE_URL ?>/modules/supervisor/service_bookings.php"
           class="nav-item <?= str_contains($__uri, '/modules/supervisor/service_bookings') ? 'active' : '' ?>"
           data-label="Service Bookings"
           style="position:relative">
            <i class="fa fa-calendar-check"></i><span>Service Bookings</span>
            <?php
            try {
                $__locId = supervisorLocationId();
                if ($__locId) {
                    $__s = getDB()->prepare("SELECT COUNT(*) FROM service_bookings sb LEFT JOIN cars c ON c.id=sb.car_id WHERE (c.location_id=? OR sb.location_id=?) AND sb.status='pending'");
                    $__s->execute([$__locId, $__locId]);
                    $__sbCount = (int)$__s->fetchColumn();
                    if ($__sbCount > 0): ?>
                <span style="position:absolute;top:6px;right:8px;background:#f59e0b;color:#fff;border-radius:10px;font-size:10px;font-weight:700;padding:1px 5px;min-width:16px;text-align:center;line-height:16px">
                    <?= $__sbCount > 99 ? '99+' : $__sbCount ?>
                </span>
            <?php endif; }} catch (\Throwable $_) {} ?>
        </a>

        <a href="<?= BASE_URL ?>/modules/supervisor/quick_assessments.php"
           class="nav-item <?= str_contains($__uri, '/modules/supervisor/quick_assessments') ? 'active' : '' ?>"
           data-label="Quick Assessments">
            <i class="fa fa-magnifying-glass-chart"></i><span>Quick Assessments</span>
        </a>

        <!-- ══ FINANCE ════════════════════════════════════════════ -->
        <div class="nav-section">Finance</div>

        <a href="<?= BASE_URL ?>/modules/supervisor/quotations.php"
           class="nav-item <?= str_contains($__uri, '/modules/supervisor/quotations') ? 'active' : '' ?>"
           data-label="Quotations">
            <i class="fa fa-file-lines"></i><span>Quotations</span>
        </a>

        <a href="<?= BASE_URL ?>/modules/supervisor/invoices.php"
           class="nav-item <?= str_contains($__uri, '/modules/supervisor/invoices') ? 'active' : '' ?>"
           data-label="Invoices">
            <i class="fa fa-file-invoice-dollar"></i><span>Invoices</span>
        </a>

        <!-- ══ TEAM ═══════════════════════════════════════════════ -->
        <div class="nav-section">Team</div>

        <a href="<?= BASE_URL ?>/modules/supervisor/team.php"
           class="nav-item <?= str_contains($__uri, '/modules/supervisor/team') ? 'active' : '' ?>"
           data-label="My Team">
            <i class="fa fa-people-group"></i><span>My Team</span>
        </a>

        <!-- ══ REPORTS ════════════════════════════════════════════ -->
        <div class="nav-section">Analytics</div>

        <a href="<?= BASE_URL ?>/modules/supervisor/reports.php"
           class="nav-item <?= str_contains($__uri, '/modules/supervisor/reports') ? 'active' : '' ?>"
           data-label="Reports">
            <i class="fa fa-chart-line"></i><span>Location Reports</span>
        </a>

        <!-- ══ ACCOUNT ════════════════════════════════════════════ -->
        <div class="nav-section">Account</div>

        <a href="<?= BASE_URL ?>/profile.php"
           class="nav-item <?= str_contains($__uri, '/profile.php') ? 'active' : '' ?>"
           data-label="My Profile">
            <i class="fa fa-user-circle"></i><span>My Profile</span>
        </a>

    </nav>

    <div class="sidebar-footer">
        <small class="text-muted" style="font-size:10.5px">v<?= APP_VERSION ?></small>
    </div>
</div>

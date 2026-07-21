<?php
/**
 * Focused sidebar rendered for supervisor role.
 * Cars, service bookings, quick assessments, team and location reports are
 * scoped to the supervisor's assigned location. Quotations, Invoices, and
 * Payments/Receipts are intentionally company-wide (all locations).
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
            <?php $__logo = companyLogo(); ?>
            <?php if ($__logo['exists']): ?>
            <img src="<?= e($__logo['url']) ?>" alt="Logo"
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

    <!-- Navigation ────────────────────────────────────────────────────────
         Grouped for the way a supervisor actually works through the day:
         Overview → Operations (my location, right now) → Customers →
         Finance → Sales → Team → Communication → Analytics → Account. -->
    <nav class="sidebar-nav">

        <!-- ══ OVERVIEW ═════════════════════════════════════════════ -->
        <div class="nav-section">Overview</div>

        <a href="<?= BASE_URL ?>/modules/supervisor/dashboard.php"
           class="nav-item <?= $__isDash ? 'active' : '' ?>"
           data-label="Dashboard">
            <i class="fa fa-gauge-high"></i><span>Dashboard</span>
        </a>

        <!-- ══ OPERATIONS (scoped to my location) ══════════════════ -->
        <div class="nav-section">Operations</div>

        <a href="<?= BASE_URL ?>/modules/supervisor/cars.php"
           class="nav-item <?= str_contains($__uri, '/modules/supervisor/cars') ? 'active' : '' ?>"
           data-label="My Location Cars"
           style="position:relative">
            <i class="fa fa-car"></i><span>Cars at Location</span>
            <?php
            try {
                $__locId = supervisorLocationId();
                if ($__locId) {
                    $__s = getDB()->prepare("SELECT COUNT(*) FROM cars WHERE location_id IN (SELECT id FROM locations WHERE id=? OR parent_id=?) AND status NOT IN ('sold','delivered')");
                    $__s->execute([$__locId, $__locId]);
                    $__carCount = (int)$__s->fetchColumn();
                    if ($__carCount > 0): ?>
                <span style="position:absolute;top:6px;right:8px;background:#2563eb;color:#fff;border-radius:10px;font-size:10px;font-weight:700;padding:1px 5px;min-width:16px;text-align:center;line-height:16px">
                    <?= $__carCount > 99 ? '99+' : $__carCount ?>
                </span>
            <?php endif; }} catch (\Throwable $_) {} ?>
        </a>

        <a href="<?= BASE_URL ?>/modules/supervisor/service_bookings.php"
           class="nav-item <?= str_contains($__uri, '/modules/supervisor/service_bookings') ? 'active' : '' ?>"
           data-label="Service Bookings"
           style="position:relative">
            <i class="fa fa-calendar-check"></i><span>Service Bookings</span>
            <?php
            try {
                $__locId = supervisorLocationId();
                if ($__locId) {
                    $__s = getDB()->prepare("SELECT COUNT(*) FROM service_bookings sb LEFT JOIN cars c ON c.id=sb.car_id WHERE (c.location_id=? OR sb.intake_location_id=?) AND sb.status='pending'");
                    $__s->execute([$__locId, $__locId]);
                    $__sbCount = (int)$__s->fetchColumn();
                    if ($__sbCount > 0): ?>
                <span style="position:absolute;top:6px;right:8px;background:#f59e0b;color:#fff;border-radius:10px;font-size:10px;font-weight:700;padding:1px 5px;min-width:16px;text-align:center;line-height:16px">
                    <?= $__sbCount > 99 ? '99+' : $__sbCount ?>
                </span>
            <?php endif; }} catch (\Throwable $_) {} ?>
        </a>

        <a href="<?= BASE_URL ?>/modules/supervisor/quick_assessments.php"
           class="nav-item <?= str_contains($__uri, '/modules/supervisor/quick_assessments') || str_contains($__uri, '/modules/quick_assessments/') ? 'active' : '' ?>"
           data-label="Quick Assessments">
            <i class="fa fa-magnifying-glass-chart"></i><span>Quick Assessments</span>
        </a>

        <!-- ══ CUSTOMERS ════════════════════════════════════════════ -->
        <?php if (canAccess('crm') || canAccess('clients')): ?>
        <div class="nav-section">Customers</div>

        <?php if (canAccess('crm')): ?>
        <a href="<?= BASE_URL ?>/modules/crm/leads.php"
           class="nav-item <?= (str_contains($__uri, '/modules/crm/leads') || str_contains($__uri, '/modules/crm/view_lead') || str_contains($__uri, '/modules/crm/add_lead') || str_contains($__uri, '/modules/crm/convert_lead')) ? 'active' : '' ?>"
           data-label="Leads"
           style="position:relative">
            <i class="fa fa-user-plus"></i><span>Leads</span>
            <?php
            try {
                $__locId2 = supervisorLocationId();
                if ($__locId2) {
                    $__s = getDB()->prepare("SELECT COUNT(*) FROM crm_leads l LEFT JOIN users u ON u.id=l.assigned_to WHERE u.location_id=? AND l.stage NOT IN ('lost','delivered')");
                    $__s->execute([$__locId2]);
                    $__leadCount = (int)$__s->fetchColumn();
                    if ($__leadCount > 0): ?>
                <span style="position:absolute;top:6px;right:8px;background:#8b5cf6;color:#fff;border-radius:10px;font-size:10px;font-weight:700;padding:1px 5px;min-width:16px;text-align:center;line-height:16px">
                    <?= $__leadCount > 99 ? '99+' : $__leadCount ?>
                </span>
            <?php endif; }} catch (\Throwable $_) {} ?>
        </a>

        <a href="<?= BASE_URL ?>/modules/crm/index.php"
           class="nav-item <?= str_contains($__uri, '/modules/crm/index') ? 'active' : '' ?>"
           data-label="CRM Dashboard">
            <i class="fa fa-chart-pie"></i><span>CRM Dashboard</span>
        </a>
        <?php endif; ?>

        <?php if (canAccess('clients')): ?>
        <a href="<?= BASE_URL ?>/modules/clients/index.php"
           class="nav-item <?= str_contains($__uri, '/modules/clients/') ? 'active' : '' ?>"
           data-label="Clients">
            <i class="fa fa-address-book"></i><span>Clients</span>
        </a>
        <?php endif; ?>
        <?php endif; ?>

        <!-- ══ FINANCE (company-wide) ══════════════════════════════ -->
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

        <?php if (canAccess('payments')): ?>
        <a href="<?= BASE_URL ?>/modules/payments/index.php"
           class="nav-item <?= str_contains($__uri, '/modules/payments/') ? 'active' : '' ?>"
           data-label="Payments">
            <i class="fa fa-receipt"></i><span>Payments &amp; Receipts</span>
        </a>
        <?php endif; ?>

        <!-- ══ SALES PIPELINE (shown when admin grants sales permission) ═ -->
        <?php if (canAccess('sales')): ?>
        <div class="nav-section">Sales</div>

        <a href="<?= BASE_URL ?>/modules/sales/index.php"
           class="nav-item <?= str_contains($__uri, '/modules/sales/') ? 'active' : '' ?>"
           data-label="Sales Pipeline">
            <i class="fa fa-chart-line"></i><span>Sales Pipeline</span>
        </a>
        <?php endif; ?>

        <!-- ══ TEAM ═══════════════════════════════════════════════ -->
        <div class="nav-section">Team</div>

        <a href="<?= BASE_URL ?>/modules/supervisor/team.php"
           class="nav-item <?= str_contains($__uri, '/modules/supervisor/team') ? 'active' : '' ?>"
           data-label="My Team">
            <i class="fa fa-people-group"></i><span>My Team</span>
        </a>

        <!-- ══ COMMUNICATION ════════════════════════════════════════ -->
        <?php if (canAccess('chat')): ?>
        <a href="<?= BASE_URL ?>/modules/chat/index.php"
           class="nav-item <?= str_contains($__uri, '/modules/chat/') ? 'active' : '' ?>"
           data-label="Team Chat"
           style="position:relative">
            <i class="fa fa-comments"></i><span>Team Chat</span>
            <span id="chatNavBadge" style="display:none;position:absolute;top:6px;right:8px;
                  background:#25d366;color:#fff;border-radius:10px;font-size:10px;
                  font-weight:700;padding:1px 5px;min-width:16px;text-align:center;line-height:16px"></span>
        </a>
        <script>
        (function(){
            var badge = document.getElementById('chatNavBadge');
            if (!badge) return;
            function poll(){
                fetch('<?= BASE_URL ?>/modules/chat/api/unread.php')
                    .then(function(r){ return r.json(); })
                    .then(function(d){
                        var n = d.count || 0;
                        if (n > 0) { badge.textContent = n > 99 ? '99+' : n; badge.style.display = ''; }
                        else { badge.style.display = 'none'; }
                    }).catch(function(){});
            }
            poll();
            setInterval(poll, 15000);
        }());
        </script>
        <?php endif; ?>

        <!-- ══ ANALYTICS ════════════════════════════════════════════ -->
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

<?php
/**
 * The workshop portal sidebar — rendered for workshop_manager.
 * Included from sidebar.php via the same early-exit pattern as the other portals.
 *
 * The workshop manager used to get the general staff menu, which ran to some
 * thirty items across Imports, Dispatch, Showroom Transfers, Key Handovers,
 * Payroll and the call centre. None of that is theirs. This menu is the workshop
 * and the things the workshop depends on, in the order a day actually runs:
 * what is on the floor, what is arriving, the vehicles, the checks, the parts,
 * the people.
 *
 * Every item is still wrapped in its canAccess() test even though the role's
 * permissions already match. The menu is not the permission — someone will edit
 * one of these lists one day, and a link that appears but dies on the far side
 * is worse than no link.
 */
$__uri  = $_SERVER['REQUEST_URI'];
$__is   = fn(string $p): string => str_contains($__uri, $p) ? 'active' : '';
$__isFloor = str_contains($__uri, '/modules/jobs/dashboard.php');

/** A small count on a menu item, or nothing at all if the table is not there. */
$__badge = function (string $sql): int {
    try { return (int)getDB()->query($sql)->fetchColumn(); }
    catch (\Throwable $e) { return 0; }
};
$__openJobs  = $__badge("SELECT COUNT(*) FROM workshop_jobs
                          WHERE status NOT IN ('completed','cancelled')");
$__todayIn   = $__badge("SELECT COUNT(*) FROM service_bookings
                          WHERE preferred_date = CURDATE()
                            AND status IN ('pending','confirmed','in_progress')");
$__quotes    = $__badge("SELECT COUNT(*) FROM parts_requests WHERE status = 'pending'");
$__lowStock  = $__badge("SELECT COUNT(*) FROM inventory WHERE quantity <= reorder_level");
$__unpaid    = $__badge("SELECT COUNT(*) FROM invoices WHERE status IN ('unpaid','partial')");
$__openQuote = $__badge("SELECT COUNT(*) FROM quotations WHERE status IN ('draft','sent')");
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
            <i class="fa fa-screwdriver-wrench" style="font-size:16px"></i>
            <?php endif; ?>
        </div>
        <div class="brand-text">
            <span class="brand-name"><?= e(getSetting('company_name', 'Mascardi')) ?></span>
            <span class="brand-sub">Workshop</span>
        </div>
    </div>

    <nav class="sidebar-nav">

        <!-- ══ THE FLOOR ═════════════════════════════════════════ -->
        <div class="nav-section">The Floor</div>

        <?php if (canAccess('jobs')): ?>
        <a href="<?= BASE_URL ?>/modules/jobs/dashboard.php"
           class="nav-item <?= $__isFloor ? 'active' : '' ?>"
           data-label="Workshop Floor">
            <i class="fa fa-gauge-high"></i><span>Workshop Floor</span>
        </a>

        <a href="<?= BASE_URL ?>/modules/jobs/index.php"
           class="nav-item <?= $__is('/modules/jobs/index.php') ?>"
           data-label="Job Cards" style="position:relative">
            <i class="fa fa-toolbox"></i><span>Job Cards</span>
            <?php if ($__openJobs > 0): ?>
            <span class="nav-count"><?= $__openJobs > 99 ? '99+' : $__openJobs ?></span>
            <?php endif; ?>
        </a>
        <?php endif; ?>

        <?php if (canAccess('issues')): ?>
        <a href="<?= BASE_URL ?>/modules/issues/index.php"
           class="nav-item <?= $__is('/modules/issues/') ?>"
           data-label="Issues">
            <i class="fa fa-triangle-exclamation"></i><span>Issues</span>
        </a>
        <?php endif; ?>

        <!-- ══ COMING IN ═════════════════════════════════════════ -->
        <div class="nav-section">Coming In</div>

        <?php if (canAccess('service_bookings')): ?>
        <a href="<?= BASE_URL ?>/modules/service_bookings/index.php"
           class="nav-item <?= $__is('/modules/service_bookings/') ?>"
           data-label="Service Bookings" style="position:relative">
            <i class="fa fa-calendar-check"></i><span>Service Bookings</span>
            <?php if ($__todayIn > 0): ?>
            <span class="nav-count nav-count-live"><?= $__todayIn ?></span>
            <?php endif; ?>
        </a>
        <?php endif; ?>

        <?php if (canAccess('quick_assessments')): ?>
        <a href="<?= BASE_URL ?>/modules/quick_assessments/index.php"
           class="nav-item <?= $__is('/modules/quick_assessments/') ?>"
           data-label="Quick Assessment">
            <i class="fa fa-clipboard-check"></i><span>Quick Assessment</span>
        </a>
        <?php endif; ?>

        <?php if (canAccess('assessments')): ?>
        <a href="<?= BASE_URL ?>/modules/assessments/index.php"
           class="nav-item <?= $__is('/modules/assessments/') ?>"
           data-label="Assessments">
            <i class="fa fa-magnifying-glass-chart"></i><span>Assessments</span>
        </a>
        <?php endif; ?>

        <?php if (canAccess('inspections')): ?>
        <a href="<?= BASE_URL ?>/modules/inspections/index.php"
           class="nav-item <?= $__is('/modules/inspections/') ?>"
           data-label="Inspections">
            <i class="fa fa-list-check"></i><span>Inspections</span>
        </a>
        <?php endif; ?>

        <!-- ══ VEHICLES ══════════════════════════════════════════ -->
        <div class="nav-section">Vehicles</div>

        <?php if (canAccess('cars')): ?>
        <a href="<?= BASE_URL ?>/modules/cars/index.php?section=workshop"
           class="nav-item <?= $__is('/modules/cars/') ?>"
           data-label="Vehicles">
            <i class="fa fa-car"></i><span>Vehicles</span>
        </a>
        <?php endif; ?>

        <?php if (canAccess('car_documents')): ?>
        <a href="<?= BASE_URL ?>/modules/car_documents/index.php"
           class="nav-item <?= $__is('/modules/car_documents/') ?>"
           data-label="Car Documents">
            <i class="fa fa-file-lines"></i><span>Car Documents</span>
        </a>
        <?php endif; ?>

        <!-- ══ PARTS ═════════════════════════════════════════════ -->
        <div class="nav-section">Parts</div>

        <?php if (canAccess('parts_requests')): ?>
        <a href="<?= BASE_URL ?>/modules/parts_requests/index.php"
           class="nav-item <?= $__is('/modules/parts_requests/') ?>"
           data-label="Quote Requests" style="position:relative">
            <i class="fa fa-file-invoice"></i><span>Quote Requests</span>
            <?php if ($__quotes > 0): ?>
            <span class="nav-count nav-count-warn"><?= $__quotes ?></span>
            <?php endif; ?>
        </a>
        <?php endif; ?>

        <?php if (canAccess('inventory')): ?>
        <a href="<?= BASE_URL ?>/modules/inventory/index.php"
           class="nav-item <?= $__is('/modules/inventory/') ?>"
           data-label="Parts Stock" style="position:relative">
            <i class="fa fa-boxes-stacked"></i><span>Parts Stock</span>
            <?php if ($__lowStock > 0): ?>
            <span class="nav-count nav-count-warn"><?= $__lowStock ?></span>
            <?php endif; ?>
        </a>
        <?php endif; ?>

        <?php if (canAccess('lpo')): ?>
        <a href="<?= BASE_URL ?>/modules/lpo/index.php"
           class="nav-item <?= $__is('/modules/lpo/') ?>"
           data-label="Purchase Orders">
            <i class="fa fa-file-import"></i><span>Purchase Orders</span>
        </a>
        <?php endif; ?>

        <!-- ══ CUSTOMERS & BILLING ═══════════════════════════════ -->
        <?php // "Quotations" here is the customer's quote for the repair. The
              // "Quote Requests" above it under Parts are the internal ones raised
              // against a job. Different things, near-identical names — the section
              // headings are what keeps them apart. ?>
        <div class="nav-section">Customers &amp; Billing</div>

        <?php if (canAccess('clients')): ?>
        <a href="<?= BASE_URL ?>/modules/clients/index.php"
           class="nav-item <?= $__is('/modules/clients/') ?>"
           data-label="Clients">
            <i class="fa fa-users"></i><span>Clients</span>
        </a>
        <?php endif; ?>

        <?php if (canAccess('quotations')): ?>
        <a href="<?= BASE_URL ?>/modules/quotations/index.php"
           class="nav-item <?= $__is('/modules/quotations/') ?>"
           data-label="Quotations" style="position:relative">
            <i class="fa fa-file-signature"></i><span>Quotations</span>
            <?php if ($__openQuote > 0): ?>
            <span class="nav-count"><?= $__openQuote > 99 ? '99+' : $__openQuote ?></span>
            <?php endif; ?>
        </a>
        <?php endif; ?>

        <?php if (canAccess('invoices')): ?>
        <a href="<?= BASE_URL ?>/modules/invoices/index.php"
           class="nav-item <?= $__is('/modules/invoices/') ?>"
           data-label="Invoices" style="position:relative">
            <i class="fa fa-file-invoice-dollar"></i><span>Invoices</span>
            <?php if ($__unpaid > 0): ?>
            <span class="nav-count nav-count-warn"><?= $__unpaid > 99 ? '99+' : $__unpaid ?></span>
            <?php endif; ?>
        </a>
        <?php endif; ?>

        <!-- ══ THE CREW ══════════════════════════════════════════ -->
        <div class="nav-section">The Crew</div>

        <?php if (canAccess('mechanics')): ?>
        <a href="<?= BASE_URL ?>/modules/mechanics/index.php"
           class="nav-item <?= $__is('/modules/mechanics/') ?>"
           data-label="Mechanics">
            <i class="fa fa-helmet-safety"></i><span>Mechanics</span>
        </a>
        <?php endif; ?>

        <?php if (canAccess('attendance')): ?>
        <a href="<?= BASE_URL ?>/modules/attendance/index.php"
           class="nav-item <?= $__is('/modules/attendance/index.php') ?>"
           data-label="Attendance">
            <i class="fa fa-calendar-days"></i><span>Attendance</span>
        </a>

        <a href="<?= BASE_URL ?>/modules/attendance/report.php"
           class="nav-item <?= $__is('/modules/attendance/report.php') ?>"
           data-label="Attendance Report">
            <i class="fa fa-chart-column"></i><span>Attendance Report</span>
        </a>
        <?php endif; ?>

        <?php if (canAccess('team')): ?>
        <a href="<?= BASE_URL ?>/modules/team/index.php"
           class="nav-item <?= $__is('/modules/team/index.php') ?>"
           data-label="Team Board">
            <i class="fa fa-users"></i><span>Team Board</span>
        </a>

        <a href="<?= BASE_URL ?>/modules/team/leave_calendar.php"
           class="nav-item <?= $__is('/modules/team/leave_calendar.php') ?>"
           data-label="Leave Calendar">
            <i class="fa fa-plane-departure"></i><span>Leave Calendar</span>
        </a>
        <?php endif; ?>

        <!-- ══ ELSEWHERE ═════════════════════════════════════════ -->
        <div class="nav-section">Elsewhere</div>

        <?php if (canAccess('reports')): ?>
        <a href="<?= BASE_URL ?>/modules/reports/index.php"
           class="nav-item <?= $__is('/modules/reports/') ?>"
           data-label="Reports">
            <i class="fa fa-chart-pie"></i><span>Reports</span>
        </a>
        <?php endif; ?>

        <?php if (canAccess('chat')): ?>
        <a href="<?= BASE_URL ?>/modules/chat/index.php"
           class="nav-item <?= $__is('/modules/chat/') ?>"
           data-label="Team Chat">
            <i class="fa fa-comments"></i><span>Team Chat</span>
        </a>
        <?php endif; ?>

        <?php if (canAccess('meetings')): ?>
        <a href="<?= BASE_URL ?>/modules/meetings/index.php"
           class="nav-item <?= $__is('/modules/meetings/index.php') ?>"
           data-label="Meetings">
            <i class="fa fa-handshake"></i><span>Meetings</span>
        </a>

        <a href="<?= BASE_URL ?>/modules/meetings/actions.php"
           class="nav-item <?= $__is('/modules/meetings/actions.php') ?>"
           data-label="My Deliverables">
            <i class="fa fa-list-ul"></i><span>My Deliverables</span>
        </a>
        <?php endif; ?>

        <a href="<?= BASE_URL ?>/profile.php"
           class="nav-item <?= $__is('/profile.php') ?>"
           data-label="My Settings">
            <i class="fa fa-user-gear"></i><span>My Settings</span>
        </a>

    </nav>

    <div class="sidebar-footer">
        <small class="text-muted" style="font-size:10.5px">v<?= APP_VERSION ?></small>
    </div>
</div>

<style>
/* The counts are the point of the menu: they say where the work has piled up
   before you click anything. Colour is never the only signal — the number is
   the message and the colour only says how urgently to read it. */
.nav-count{position:absolute;top:50%;right:10px;transform:translateY(-50%);
    background:#475569;color:#fff;border-radius:10px;font-size:10px;font-weight:700;
    padding:1px 6px;min-width:18px;text-align:center;line-height:15px}
.nav-count-live{background:#2563eb}
.nav-count-warn{background:#d97706}
</style>

<?php
$__uri   = $_SERVER['REQUEST_URI'];
$__isDash = !str_contains($__uri, '/modules/');

function isActive(string $path): string {
    global $__uri;
    return str_contains($__uri, $path) ? 'active' : '';
}

// Super Admin gets the full comprehensive sidebar (no early-exit; falls through below)
// Admin gets the simple focused portal sidebar
if (authRole() === 'admin') {
    include __DIR__ . '/sidebar_admin.php';
    return;
}

// Customer Relations Managers get a lean, focused sidebar
if (authRole() === 'customer_relations') {
    include __DIR__ . '/sidebar_crm.php';
    return;
}

// Supervisors get their own location-scoped sidebar
if (authRole() === 'supervisor') {
    include __DIR__ . '/sidebar_supervisor.php';
    return;
}
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
            <?php if (authRole() === 'super_admin'): ?>
            <span class="brand-sub" style="color:#f59e0b;font-size:9.5px;font-weight:700;letter-spacing:.8px;text-transform:uppercase">Super Admin</span>
            <?php else: ?>
            <span class="brand-sub">Car Yard System</span>
            <?php endif; ?>
        </div>
    </div>

    <!-- Navigation -->
    <nav class="sidebar-nav">

        <!-- Dashboard -->
        <a href="<?= BASE_URL ?>/index.php"
           class="nav-item <?= $__isDash ? 'active' : '' ?>"
           data-label="Dashboard">
            <i class="fa fa-gauge-high"></i><span>Dashboard</span>
        </a>

        <!-- ══ FLEET ══════════════════════════════════════════════ -->
        <?php if (canAccess('cars') || canAccess('mechanics') || canAccess('drivers') || canAccess('car_documents')): ?>
        <div class="nav-section">Fleet</div>

        <?php if (canAccess('cars')): ?>
        <a href="<?= BASE_URL ?>/modules/cars/index.php"
           class="nav-item <?= isActive('/modules/cars/') ?>"
           data-label="All Cars">
            <i class="fa fa-car"></i><span>All Cars</span>
        </a>
        <?php endif; ?>

        <?php if (canAccess('crm')): ?>
        <a href="<?= BASE_URL ?>/modules/reservations/index.php"
           class="nav-item <?= isActive('/modules/reservations/') ?>"
           data-label="Reservations"
           style="position:relative">
            <i class="fa fa-bookmark"></i><span>Reservations</span>
            <?php
            try {
                $__resCount = (int)getDB()->query("SELECT COUNT(*) FROM crm_leads WHERE stage='reserved'")->fetchColumn();
                if ($__resCount > 0): ?>
            <span style="position:absolute;top:6px;right:8px;background:#7c3aed;color:#fff;border-radius:10px;font-size:10px;font-weight:700;padding:1px 5px;min-width:16px;text-align:center;line-height:16px">
                <?= $__resCount > 99 ? '99+' : $__resCount ?>
            </span>
            <?php endif; } catch (\Throwable $e) {} ?>
        </a>
        <a href="<?= BASE_URL ?>/modules/delivered_cars/index.php"
           class="nav-item <?= isActive('/modules/delivered_cars/') ?>"
           data-label="Delivered Cars">
            <i class="fa fa-truck"></i><span>Delivered Cars</span>
        </a>
        <a href="<?= BASE_URL ?>/modules/import_orders/index.php"
           class="nav-item <?= isActive('/modules/import_orders/') ?>"
           data-label="Import Orders"
           style="position:relative">
            <i class="fa fa-ship"></i><span>Import Orders</span>
            <?php
            try {
                $__ioLate = (int)getDB()->query(
                    "SELECT COUNT(*) FROM crm_leads
                      WHERE stage = 'import_order'
                        AND expected_arrival_date IS NOT NULL
                        AND expected_arrival_date < CURDATE()"
                )->fetchColumn();
                if ($__ioLate > 0): ?>
            <span style="position:absolute;top:6px;right:8px;background:#dc2626;color:#fff;border-radius:10px;font-size:10px;font-weight:700;padding:1px 5px;min-width:16px;text-align:center;line-height:16px">
                <?= $__ioLate > 99 ? '99+' : $__ioLate ?>
            </span>
            <?php endif; } catch (\Throwable $e) {} ?>
        </a>

        <?php endif; ?>

        <?php if (canAccess('imports')): ?>
        <div class="nav-section">Import Pipeline</div>

        <a href="<?= BASE_URL ?>/modules/imports/index.php"
           class="nav-item <?= isActive('/modules/imports/') ?>"
           data-label="Import Pipeline"
           style="position:relative">
            <i class="fa fa-ship"></i><span>Pipeline</span>
            <?php
            try {
                $__impBadge = (int)getDB()->query("SELECT COUNT(*) FROM car_imports WHERE stage NOT IN ('completed')")->fetchColumn();
                if ($__impBadge > 0): ?>
            <span style="position:absolute;top:6px;right:8px;background:#2563eb;color:#fff;border-radius:10px;font-size:10px;font-weight:700;padding:1px 5px;min-width:16px;text-align:center;line-height:16px">
                <?= $__impBadge > 99 ? '99+' : $__impBadge ?>
            </span>
            <?php endif; } catch (\Throwable $e) {} ?>
        </a>

        <a href="<?= BASE_URL ?>/modules/imports/index.php?view=shipments"
           class="nav-item <?= (isActive('/modules/imports/shipment')) ? 'active' : '' ?>"
           data-label="Shipments">
            <i class="fa fa-boxes-stacked"></i><span>Shipments</span>
        </a>

        <?php endif; ?>

        <?php if (canAccess('trade_in')): ?>
        <a href="<?= BASE_URL ?>/modules/trade_in/index.php"
           class="nav-item <?= isActive('/modules/trade_in/') ?>"
           data-label="Trade-In &amp; Sale on Behalf"
           style="position:relative">
            <i class="fa fa-handshake"></i><span>Trade-In &amp; Sale on Behalf</span>
            <?php
            try {
                $__consignCount = (int)getDB()->query("SELECT COUNT(*) FROM consignments WHERE status='active'")->fetchColumn();
                if ($__consignCount > 0): ?>
            <span style="position:absolute;top:6px;right:8px;background:#0ea5e9;color:#fff;border-radius:10px;font-size:10px;font-weight:700;padding:1px 5px;min-width:16px;text-align:center;line-height:16px">
                <?= $__consignCount > 99 ? '99+' : $__consignCount ?>
            </span>
            <?php endif; } catch (\Throwable $e) {} ?>
        </a>
        <?php endif; ?>

        <?php if (canAccess('mechanics')): ?>
        <a href="<?= BASE_URL ?>/modules/mechanics/index.php"
           class="nav-item <?= isActive('/modules/mechanics/') ?>"
           data-label="Mechanics">
            <i class="fa fa-screwdriver-wrench"></i><span>Mechanics</span>
        </a>
        <?php endif; ?>

        <?php if (canAccess('drivers')): ?>
        <a href="<?= BASE_URL ?>/modules/drivers/index.php"
           class="nav-item <?= isActive('/modules/drivers/') ?>"
           data-label="Drivers">
            <i class="fa fa-id-card"></i><span>Drivers</span>
        </a>
        <?php endif; ?>

        <?php if (canAccess('car_documents')): ?>
        <a href="<?= BASE_URL ?>/modules/car_documents/index.php"
           class="nav-item <?= isActive('/modules/car_documents/') ?>"
           data-label="Car Documents">
            <i class="fa fa-folder-open"></i><span>Car Documents</span>
        </a>
        <?php endif; ?>
        <?php endif; ?>

        <!-- ══ DISPATCH & TEAM ════════════════════════════════════ -->
        <?php if (canAccess('dispatch') || canAccess('team')): ?>
        <?php // Roles with team but no dispatch (HR) would otherwise sit under a
              // heading for a section they have no items in. ?>
        <div class="nav-section"><?= canAccess('dispatch') ? 'Dispatch &amp; Team' : 'Team' ?></div>

        <?php if (canAccess('dispatch')): ?>
        <a href="<?= BASE_URL ?>/modules/dispatch/index.php"
           class="nav-item <?= isActive('/modules/dispatch/') ?>"
           data-label="Dispatch Board"
           style="position:relative">
            <i class="fa fa-map-location-dot"></i><span>Dispatch Board</span>
            <?php
            try {
                $__djCount = (int)getDB()->query("SELECT COUNT(*) FROM dispatch_jobs WHERE scheduled_date=CURDATE() AND status IN ('scheduled','en_route')")->fetchColumn();
                if ($__djCount > 0): ?>
            <span style="position:absolute;top:6px;right:8px;background:#2563eb;color:#fff;border-radius:10px;font-size:10px;font-weight:700;padding:1px 5px;min-width:16px;text-align:center;line-height:16px">
                <?= $__djCount > 99 ? '99+' : $__djCount ?>
            </span>
            <?php endif; } catch (Exception $e) {} ?>
        </a>
        <?php endif; ?>

        <?php if (canAccess('team')): ?>
        <a href="<?= BASE_URL ?>/modules/team/index.php"
           class="nav-item <?= isActive('/modules/team/index') ?>"
           data-label="Team Board"
           style="position:relative">
            <i class="fa fa-people-group"></i><span>Team Board</span>
            <?php
            try {
                $__pendLeave = (int)getDB()->query("SELECT COUNT(*) FROM leave_requests WHERE status='pending'")->fetchColumn();
                if ($__pendLeave > 0): ?>
            <span style="position:absolute;top:6px;right:8px;background:#d97706;color:#fff;border-radius:10px;font-size:10px;font-weight:700;padding:1px 5px;min-width:16px;text-align:center;line-height:16px">
                <?= $__pendLeave > 99 ? '99+' : $__pendLeave ?>
            </span>
            <?php endif; } catch (Exception $e) {} ?>
        </a>
        <a href="<?= BASE_URL ?>/modules/team/leave_calendar.php"
           class="nav-item <?= isActive('/modules/team/leave_calendar') ?>"
           data-label="Leave Calendar">
            <i class="fa fa-calendar-week"></i><span>Leave Calendar</span>
        </a>
        <?php endif; ?>

        <?php endif; ?>

        <!-- ══ IMPORTS ═══════════════════════════════════════════ -->
        <!-- ══ OPERATIONS ═════════════════════════════════════════ -->
        <?php if (canAccess('intake') || canAccess('assessments') || canAccess('quick_assessments') || canAccess('inspections') || canAccess('showroom_transfers') || canAccess('key_handovers')): ?>
        <div class="nav-section">Operations</div>

        <?php if (canAccess('intake')): ?>
        <a href="<?= BASE_URL ?>/modules/intake/index.php"
           class="nav-item <?= isActive('/modules/intake/') ?>"
           data-label="Mombasa Intake">
            <i class="fa fa-anchor"></i><span>Mombasa Intake</span>
        </a>
        <?php endif; ?>

        <?php if (canAccess('assessments')): ?>
        <a href="<?= BASE_URL ?>/modules/assessments/index.php"
           class="nav-item <?= isActive('/modules/assessments/') ?>"
           data-label="Assessments">
            <i class="fa fa-clipboard-check"></i><span>Assessments</span>
        </a>
        <?php endif; ?>

        <?php if (canAccess('quick_assessments')): ?>
        <a href="<?= BASE_URL ?>/modules/quick_assessments/index.php"
           class="nav-item <?= isActive('/modules/quick_assessments/') ?>"
           data-label="Quick Assessment">
            <i class="fa fa-magnifying-glass-chart"></i><span>Quick Assessment</span>
        </a>
        <?php endif; ?>

        <?php if (canAccess('inspections')): ?>
        <a href="<?= BASE_URL ?>/modules/inspections/index.php"
           class="nav-item <?= isActive('/modules/inspections/') ?>"
           data-label="Inspections">
            <i class="fa fa-clipboard-list"></i><span>Inspections</span>
        </a>
        <?php endif; ?>

        <?php if (canAccess('showroom_transfers')): ?>
        <a href="<?= BASE_URL ?>/modules/showroom_transfers/index.php"
           class="nav-item <?= isActive('/modules/showroom_transfers/') ?>"
           data-label="Showroom Transfers">
            <i class="fa fa-right-left"></i><span>Transfers</span>
            <?php
            try {
                $__stCount = (int)getDB()->query("SELECT COUNT(*) FROM showroom_transfers WHERE status='pending'")->fetchColumn();
                if ($__stCount > 0): ?>
            <span style="position:absolute;top:6px;right:8px;background:#2563eb;color:#fff;border-radius:10px;font-size:10px;font-weight:700;padding:1px 5px;min-width:16px;text-align:center;line-height:16px">
                <?= $__stCount > 99 ? '99+' : $__stCount ?>
            </span>
            <?php endif; } catch (Exception $e) {} ?>
        </a>
        <?php endif; ?>

        <?php if (canAccess('key_handovers')): ?>
        <a href="<?= BASE_URL ?>/modules/key_handovers/index.php"
           class="nav-item <?= isActive('/modules/key_handovers/') ?>"
           data-label="Key Handovers"
           style="position:relative">
            <i class="fa fa-key"></i><span>Key Runs</span>
            <?php
            try {
                $__khCount = (int)getDB()->query("SELECT COUNT(*) FROM key_handovers WHERE status='pending'")->fetchColumn();
                if ($__khCount > 0): ?>
            <span style="position:absolute;top:6px;right:8px;background:#d97706;color:#fff;border-radius:10px;font-size:10px;font-weight:700;padding:1px 5px;min-width:16px;text-align:center;line-height:16px">
                <?= $__khCount > 99 ? '99+' : $__khCount ?>
            </span>
            <?php endif; } catch (Exception $e) {} ?>
        </a>
        <?php endif; ?>

        <?php endif; ?>

        <!-- ══ WORKSHOP ═══════════════════════════════════════════ -->
        <?php if (canAccess('jobs') || canAccess('lpo') || canAccess('parts_requests') || canAccess('issues')): ?>
        <div class="nav-section">Workshop</div>

        <?php if (canAccess('jobs')): ?>
        <a href="<?= BASE_URL ?>/modules/jobs/index.php"
           class="nav-item <?= isActive('/modules/jobs/') ?>"
           data-label="Job Cards">
            <i class="fa fa-toolbox"></i><span>Job Cards</span>
        </a>
        <?php endif; ?>

        <?php if (canAccess('lpo')): ?>
        <a href="<?= BASE_URL ?>/modules/lpo/index.php"
           class="nav-item <?= isActive('/modules/lpo/') ?>"
           data-label="LPO">
            <i class="fa fa-file-import"></i><span>LPO</span>
        </a>
        <?php endif; ?>

        <?php if (canAccess('parts_requests')): ?>
        <a href="<?= BASE_URL ?>/modules/parts_requests/index.php"
           class="nav-item <?= isActive('/modules/parts_requests/') ?>"
           data-label="Quote Requests">
            <i class="fa fa-file-invoice"></i><span>Quote Requests</span>
        </a>
        <?php endif; ?>

        <?php if (canAccess('issues')): ?>
        <a href="<?= BASE_URL ?>/modules/issues/index.php"
           class="nav-item <?= isActive('/modules/issues/') ?>"
           data-label="Issues">
            <i class="fa fa-triangle-exclamation"></i><span>Issues</span>
        </a>
        <?php endif; ?>
        <?php endif; ?>

        <!-- ══ INVENTORY ══════════════════════════════════════════ -->
        <?php if (canAccess('inventory') || canAccess('suppliers')): ?>
        <div class="nav-section">Inventory</div>

        <?php if (canAccess('inventory')): ?>
        <a href="<?= BASE_URL ?>/modules/inventory/index.php"
           class="nav-item <?= isActive('/modules/inventory/') ?>"
           data-label="Parts Stock">
            <i class="fa fa-boxes-stacked"></i><span>Parts Stock</span>
        </a>
        <?php endif; ?>

        <?php if (canAccess('suppliers')): ?>
        <a href="<?= BASE_URL ?>/modules/suppliers/index.php"
           class="nav-item <?= isActive('/modules/suppliers/') ?>"
           data-label="Suppliers">
            <i class="fa fa-truck"></i><span>Suppliers</span>
        </a>
        <?php endif; ?>
        <?php endif; ?>

        <!-- ══ SALES & CRM ════════════════════════════════════════ -->
        <?php
        $__hasSales = canAccess('clients') || canAccess('service_bookings') || canAccess('crm')
                   || hasRole(['admin','general_manager','sales_manager','sales_officer','sales_person','customer_relations','receptionist']);
        ?>
        <?php if ($__hasSales): ?>
        <div class="nav-section">Sales &amp; CRM</div>

        <?php if (canAccess('clients')): ?>
        <a href="<?= BASE_URL ?>/modules/clients/index.php"
           class="nav-item <?= isActive('/modules/clients/') ?>"
           data-label="Clients">
            <i class="fa fa-users"></i><span>Clients</span>
        </a>
        <?php endif; ?>

        <?php if (canAccess('visitors')): ?>
        <a href="<?= BASE_URL ?>/modules/visitors/index.php"
           class="nav-item <?= isActive('/modules/visitors/') ?>"
           data-label="Visitors">
            <i class="fa fa-book-open-reader"></i><span>Visitors</span>
        </a>
        <?php endif; ?>

        <?php if (canAccess('service_bookings')): ?>
        <a href="<?= BASE_URL ?>/modules/service_bookings/index.php"
           class="nav-item <?= isActive('/modules/service_bookings/') ?>"
           data-label="Service Bookings">
            <i class="fa fa-calendar-check"></i><span>Service Bookings</span>
        </a>
        <?php endif; ?>

        <?php if (canAccess('crm')): ?>
        <a href="<?= BASE_URL ?>/modules/crm/index.php"
           class="nav-item <?= isActive('/modules/crm/index') ?>"
           data-label="Sales Pipeline">
            <i class="fa fa-filter"></i><span>Sales Pipeline</span>
        </a>
        <a href="<?= BASE_URL ?>/modules/crm/leads.php"
           class="nav-item <?= (isActive('/modules/crm/leads') || isActive('/modules/crm/view_lead') || isActive('/modules/crm/add_lead') || isActive('/modules/crm/import_leads') || isActive('/modules/crm/convert_lead')) ? 'active' : '' ?>"
           data-label="Leads">
            <i class="fa fa-user-plus"></i><span>Leads</span>
        </a>
        <?php if (hasRole(['admin','general_manager','sales_manager'])): ?>
        <a href="<?= BASE_URL ?>/modules/crm/team_performance.php"
           class="nav-item <?= isActive('/modules/crm/team_performance') ?>"
           data-label="Team Performance">
            <i class="fa fa-chart-bar"></i><span>Team Performance</span>
        </a>
        <a href="<?= BASE_URL ?>/modules/crm/targets.php"
           class="nav-item <?= isActive('/modules/crm/targets') ?>"
           data-label="CRM Targets">
            <i class="fa fa-bullseye"></i><span>CRM Targets</span>
        </a>
        <?php endif; ?>
        <?php endif; ?>

        <?php if (hasRole(['admin','general_manager','sales_manager','sales_officer','sales_person','customer_relations','receptionist'])): ?>
        <a href="<?= BASE_URL ?>/modules/showroom/index.php"
           <?php // isActive() is a substring match, so exclude messages.php or both links highlight ?>
           class="nav-item <?= (str_contains($__uri, '/modules/showroom/') && !str_contains($__uri, 'messages.php')) ? 'active' : '' ?>"
           data-label="Inquiries"
           style="position:relative">
            <i class="fa fa-inbox"></i><span>Inquiries</span>
            <?php
            try {
                $__inqCount = (int)getDB()->query("SELECT COUNT(*) FROM showroom_inquiries WHERE status='new'")->fetchColumn();
                if ($__inqCount > 0): ?>
            <span style="position:absolute;top:6px;right:8px;background:#ef4444;color:#fff;border-radius:10px;font-size:10px;font-weight:700;padding:1px 5px;min-width:16px;text-align:center;line-height:16px">
                <?= $__inqCount > 99 ? '99+' : $__inqCount ?>
            </span>
            <?php endif; } catch (Exception $e) {} ?>
        </a>
        <a href="<?= BASE_URL ?>/modules/showroom/messages.php"
           class="nav-item <?= isActive('/modules/showroom/messages.php') ?>"
           data-label="Messages"
           style="position:relative">
            <i class="fa fa-envelope-open-text"></i><span>Messages</span>
            <?php
            try {
                $__msgCount = (int)getDB()->query("SELECT COUNT(*) FROM contact_messages WHERE status='new'")->fetchColumn();
                if ($__msgCount > 0): ?>
            <span style="position:absolute;top:6px;right:8px;background:#ef4444;color:#fff;border-radius:10px;font-size:10px;font-weight:700;padding:1px 5px;min-width:16px;text-align:center;line-height:16px">
                <?= $__msgCount > 99 ? '99+' : $__msgCount ?>
            </span>
            <?php endif; } catch (Exception $e) {} ?>
        </a>
        <a href="<?= BASE_URL ?>/showroom/" target="_blank"
           class="nav-item"
           data-label="Public Showroom">
            <i class="fa fa-store"></i><span>Public Showroom</span>
            <i class="fa fa-external-link" style="font-size:10px;opacity:.4;margin-left:auto"></i>
        </a>
        <?php endif; ?>
        <?php endif; ?>

        <!-- ══ WHATSAPP ═══════════════════════════════════════════ -->
        <?php // Customer messaging — was ungated, so it appeared for every role
              // including HR, mechanics and drivers who have no customer contact. ?>
        <?php if (canAccess('crm') || canAccess('clients') || canAccess('cars')): ?>
        <div class="nav-section">WhatsApp</div>
        <a href="<?= BASE_URL ?>/modules/whatsapp/index.php"
           class="nav-item <?= isActive('/modules/whatsapp/') ?>"
           data-label="WhatsApp Inbox"
           style="position:relative">
            <i class="fab fa-whatsapp" style="color:#25d366"></i><span>Inbox</span>
            <?php
            try {
                $__waUnread = (int)getDB()->query("SELECT COALESCE(SUM(unread_count),0) FROM wa_conversations")->fetchColumn();
                if ($__waUnread > 0): ?>
            <span style="position:absolute;top:6px;right:8px;background:#25d366;color:#fff;border-radius:10px;font-size:10px;font-weight:700;padding:1px 5px;min-width:16px;text-align:center;line-height:16px">
                <?= $__waUnread > 99 ? '99+' : $__waUnread ?>
            </span>
            <?php endif; } catch (\Throwable $_) {} ?>
        </a>
        <?php if (hasRole(['admin','general_manager'])): ?>
        <a href="<?= BASE_URL ?>/modules/whatsapp/admin.php"
           class="nav-item <?= isActive('/modules/whatsapp/admin') ?>"
           data-label="WA Setup">
            <i class="fa fa-qrcode"></i><span>WA Setup</span>
        </a>
        <?php endif; ?>
        <?php endif; ?>

        <!-- ══ FINANCE ════════════════════════════════════════════ -->
        <?php if (canAccess('sales') || canAccess('payments') || canAccess('quotations') || canAccess('invoices')
               || canAccess('installments') || canAccess('car_costs') || canAccess('expenses')): ?>
        <div class="nav-section">Finance</div>

        <?php if (canAccess('sales')): ?>
        <a href="<?= BASE_URL ?>/modules/sales/index.php"
           class="nav-item <?= isActive('/modules/sales/') ?>"
           data-label="Sales">
            <i class="fa fa-tag"></i><span>Sales</span>
        </a>
        <?php endif; ?>

        <?php if (canAccess('payments')): ?>
        <a href="<?= BASE_URL ?>/modules/payments/index.php"
           class="nav-item <?= isActive('/modules/payments/') ?>"
           data-label="Payments">
            <i class="fa fa-money-bill-transfer"></i><span>Payments</span>
        </a>
        <?php endif; ?>

        <?php if (canAccess('quotations')): ?>
        <a href="<?= BASE_URL ?>/modules/quotations/index.php"
           class="nav-item <?= isActive('/modules/quotations/') ?>"
           data-label="Quotations">
            <i class="fa fa-file-lines"></i><span>Quotations</span>
        </a>
        <?php endif; ?>

        <?php if (canAccess('invoices')): ?>
        <a href="<?= BASE_URL ?>/modules/invoices/index.php"
           class="nav-item <?= isActive('/modules/invoices/') ?>"
           data-label="Invoices">
            <i class="fa fa-file-invoice-dollar"></i><span>Invoices</span>
        </a>
        <?php endif; ?>

        <?php if (canAccess('installments')): ?>
        <a href="<?= BASE_URL ?>/modules/installments/index.php"
           class="nav-item <?= isActive('/modules/installments/') ?>"
           data-label="Payment Plans">
            <i class="fa fa-calendar-dollar"></i><span>Payment Plans</span>
        </a>
        <?php endif; ?>

        <?php if (canAccess('car_costs')): ?>
        <a href="<?= BASE_URL ?>/modules/car_costs/index.php"
           class="nav-item <?= isActive('/modules/car_costs/') ?>"
           data-label="Import Costs">
            <i class="fa fa-calculator"></i><span>Import Costs</span>
        </a>
        <?php endif; ?>

        <?php if (canAccess('expenses')): ?>
        <a href="<?= BASE_URL ?>/modules/expenses/index.php"
           class="nav-item <?= isActive('/modules/expenses/') ?>"
           data-label="Expenses">
            <i class="fa fa-receipt"></i><span>Expenses</span>
        </a>
        <?php endif; ?>
        <?php endif; ?>

        <!-- ══ HR ═════════════════════════════════════════════════ -->
        <?php if (canAccess('hr') || canAccess('attendance') || canAccess('payroll')): ?>
        <div class="nav-section">Human Resources</div>

        <?php if (canAccess('hr')): ?>
        <a href="<?= BASE_URL ?>/modules/hr/index.php"
           <?php // isActive is a substring match — anchor on index.php so the
                 // dashboard doesn't stay lit on every other HR page ?>
           class="nav-item <?= isActive('/modules/hr/index') ?>"
           data-label="HR Dashboard">
            <i class="fa fa-gauge-high"></i><span>HR Dashboard</span>
        </a>
        <a href="<?= BASE_URL ?>/modules/hr/employees.php"
           class="nav-item <?= (str_contains($__uri, '/modules/hr/employee')) ? 'active' : '' ?>"
           data-label="Employees">
            <i class="fa fa-users"></i><span>Employees</span>
        </a>
        <a href="<?= BASE_URL ?>/modules/hr/attendance.php"
           class="nav-item <?= isActive('/modules/hr/attendance') ?>"
           data-label="Attendance">
            <i class="fa fa-calendar-days"></i><span>Attendance</span>
        </a>
        <a href="<?= BASE_URL ?>/modules/hr/biometric.php"
           class="nav-item <?= isActive('/modules/hr/biometric') ?>"
           data-label="Biometric Devices"
           style="position:relative">
            <i class="fa fa-fingerprint"></i><span>Biometric</span>
            <?php
            try {
                $__zkAlert = (int)getDB()->query(
                    "SELECT (SELECT COUNT(*) FROM zk_devices WHERE status='pending')
                          + (SELECT COUNT(DISTINCT device_pin) FROM zk_punches WHERE staff_id IS NULL)")->fetchColumn();
                if ($__zkAlert > 0): ?>
            <span style="position:absolute;top:6px;right:8px;background:#f59e0b;color:#fff;border-radius:10px;font-size:10px;font-weight:700;padding:1px 5px;min-width:16px;text-align:center;line-height:16px">
                <?= $__zkAlert > 99 ? '99+' : $__zkAlert ?>
            </span>
            <?php endif; } catch (\Throwable $_) {} ?>
        </a>
        <a href="<?= BASE_URL ?>/modules/hr/leave.php"
           class="nav-item <?= isActive('/modules/hr/leave') ?>"
           data-label="Leave">
            <i class="fa fa-plane-departure"></i><span>Leave</span>
        </a>
        <a href="<?= BASE_URL ?>/modules/hr/documents.php"
           class="nav-item <?= isActive('/modules/hr/documents') ?>"
           data-label="Documents">
            <i class="fa fa-folder-open"></i><span>Documents</span>
        </a>
        <?php elseif (canAccess('attendance')): ?>
        <?php // Roles with attendance but not HR (workshop/finance) keep the
              // plain register they already had. ?>
        <a href="<?= BASE_URL ?>/modules/attendance/index.php"
           class="nav-item <?= isActive('/modules/attendance/index') ?>"
           data-label="Attendance">
            <i class="fa fa-calendar-days"></i><span>Attendance</span>
        </a>
        <?php endif; ?>

        <?php if (canAccess('attendance')): ?>
        <a href="<?= BASE_URL ?>/modules/attendance/report.php"
           class="nav-item <?= isActive('/modules/attendance/report') ?>"
           data-label="Attendance Report">
            <i class="fa fa-chart-column"></i><span>Attendance Report</span>
        </a>
        <?php endif; ?>

        <?php if (canAccess('payroll')): ?>
        <a href="<?= BASE_URL ?>/modules/payroll/index.php"
           <?php // staff.php gets its own entry below, so exclude it here ?>
           class="nav-item <?= (str_contains($__uri, '/modules/payroll/') && !str_contains($__uri, 'staff.php')) ? 'active' : '' ?>"
           data-label="Payroll">
            <i class="fa fa-money-bill-wave"></i><span>Payroll</span>
        </a>
        <a href="<?= BASE_URL ?>/modules/payroll/staff.php"
           class="nav-item <?= isActive('/modules/payroll/staff') ?>"
           data-label="Salary Profiles">
            <i class="fa fa-wallet"></i><span>Salary Profiles</span>
        </a>
        <?php endif; ?>
        <?php endif; ?>

        <!-- ══ ANALYTICS ══════════════════════════════════════════ -->
        <?php if (canAccess('reports')): ?>
        <div class="nav-section">Analytics</div>
        <a href="<?= BASE_URL ?>/modules/reports/index.php"
           <?php // exclude kpi.php so both links don't highlight (isActive is a substring match) ?>
           class="nav-item <?= (str_contains($__uri, '/modules/reports/') && !str_contains($__uri, 'kpi.php')) ? 'active' : '' ?>"
           data-label="Reports">
            <i class="fa fa-chart-bar"></i><span>Reports</span>
        </a>
        <?php // Company KPI targets (revenue, cars sold, leads, jobs). The page was
              // fully built but linked from nowhere, so nobody could reach it.
              // Commercial targets — shown only to roles that own them. ?>
        <?php if (canAccess('sales') || canAccess('crm')): ?>
        <a href="<?= BASE_URL ?>/modules/reports/kpi.php"
           class="nav-item <?= isActive('/modules/reports/kpi.php') ?>"
           data-label="KPI Targets">
            <i class="fa fa-bullseye"></i><span>KPI Targets</span>
        </a>
        <?php endif; ?>
        <?php endif; ?>

        <!-- ══ COMMUNICATION ══════════════════════════════════════ -->
        <div class="nav-section">Communication</div>
        <?php if (canAccess('chat')): ?>
        <a href="<?= BASE_URL ?>/modules/chat/index.php"
           class="nav-item <?= isActive('/modules/chat/') ?>"
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
            // Subscribes to the shared poller in header.php rather than fetching
            // the same endpoint on a timer of its own.
            function render(n){
                if (n > 0) { badge.textContent = n > 99 ? '99+' : n; badge.style.display = ''; }
                else { badge.style.display = 'none'; }
            }
            if (window.mscChatUnread) window.mscChatUnread.subscribe(render);
        }());
        </script>
        <?php endif; ?>
        <?php // Meetings sits with the other ways people talk to each other.
              // Universal — see universalModules() in includes/auth.php. ?>
        <a href="<?= BASE_URL ?>/modules/meetings/index.php"
           class="nav-item <?= isActive('/modules/meetings/index') ?>"
           data-label="Meetings">
            <i class="fa fa-handshake-angle"></i><span>Meetings</span>
        </a>
        <a href="<?= BASE_URL ?>/modules/meetings/actions.php"
           class="nav-item <?= isActive('/modules/meetings/actions') ?>"
           data-label="My Deliverables"
           style="position:relative">
            <i class="fa fa-list-check"></i><span>My Deliverables</span>
            <?php
            try {
                $__mtDue = getDB()->prepare("SELECT COUNT(*) FROM meeting_actions
                    WHERE assigned_to = ? AND status IN ('pending','in_progress','blocked')");
                $__mtDue->execute([(int)(authUser()['id'] ?? 0)]);
                $__mtDue = (int)$__mtDue->fetchColumn();
                if ($__mtDue > 0): ?>
            <span style="position:absolute;top:6px;right:8px;background:#f59e0b;color:#fff;border-radius:10px;font-size:10px;font-weight:700;padding:1px 5px;min-width:16px;text-align:center;line-height:16px">
                <?= $__mtDue > 99 ? '99+' : $__mtDue ?>
            </span>
            <?php endif; } catch (\Throwable $_) {} ?>
        </a>

        <?php // Call centre — universal, like Meetings. See universalModules(). ?>
        <a href="<?= BASE_URL ?>/modules/callcenter/index.php"
           class="nav-item <?= (str_contains($__uri, '/modules/callcenter/') && !str_contains($__uri, 'dialer')) ? 'active' : '' ?>"
           data-label="Call Centre">
            <i class="fa fa-headset"></i><span>Call Centre</span>
        </a>
        <a href="<?= BASE_URL ?>/modules/callcenter/dialer.php"
           class="nav-item <?= isActive('/modules/callcenter/dialer') ?>"
           data-label="Dialer">
            <i class="fa fa-phone"></i><span>Dialer</span>
        </a>
        <?php if (canAccess('crm') || canAccess('clients') || canAccess('cars')): ?>
        <a href="<?= BASE_URL ?>/modules/whatsapp/index.php"
           class="nav-item <?= isActive('/modules/whatsapp/') ?>"
           data-label="WA Inbox"
           style="position:relative">
            <i class="fab fa-whatsapp"></i><span>WA Inbox</span>
            <span id="waNavBadge" style="display:none;position:absolute;top:6px;right:8px;
                  background:#00a884;color:#fff;border-radius:10px;font-size:10px;
                  font-weight:700;padding:1px 5px;min-width:16px;text-align:center;line-height:16px"></span>
        </a>
        <?php endif; ?>
        <script>
        (function(){
            var badge = document.getElementById('waNavBadge');
            if (!badge) return;
            function poll(){
                fetch('<?= BASE_URL ?>/modules/whatsapp/api/unread.php')
                    .then(function(r){ return r.json(); })
                    .then(function(d){
                        var n = d.count || 0;
                        if (n > 0) { badge.textContent = n > 99 ? '99+' : n; badge.style.display = ''; }
                        else { badge.style.display = 'none'; }
                    }).catch(function(){});
            }
            poll();
            setInterval(poll, 20000);
        }());
        </script>

        <!-- ══ ADMIN ══════════════════════════════════════════════ -->
        <?php if (hasRole('admin')): ?>
        <div class="nav-section">Administration</div>
        <a href="<?= BASE_URL ?>/modules/data_tools/import.php"
           class="nav-item <?= isActive('/modules/data_tools/') ?>"
           data-label="Data Tools">
            <i class="fa fa-database"></i><span>Import &amp; Export</span>
        </a>
        <a href="<?= BASE_URL ?>/modules/users/index.php"
           class="nav-item <?= isActive('/modules/users/') ?>"
           data-label="Users & Roles">
            <i class="fa fa-users-gear"></i><span>Users &amp; Roles</span>
        </a>
        <a href="<?= BASE_URL ?>/modules/locations/index.php"
           class="nav-item <?= isActive('/modules/locations/') ?>"
           data-label="Locations">
            <i class="fa fa-location-dot"></i><span>Locations</span>
        </a>
        <a href="<?= BASE_URL ?>/modules/audit/index.php"
           class="nav-item <?= isActive('/modules/audit/') ?>"
           data-label="Audit Log">
            <i class="fa fa-history"></i><span>Audit Log</span>
        </a>
        <a href="<?= BASE_URL ?>/modules/email_logs/index.php"
           class="nav-item <?= isActive('/modules/email_logs/') ?>"
           data-label="Email Logs">
            <i class="fa fa-envelope-open-text"></i><span>Email Logs</span>
        </a>
        <a href="<?= BASE_URL ?>/modules/settings/index.php"
           class="nav-item <?= isActive('/modules/settings/') ?>"
           data-label="Settings">
            <i class="fa fa-gear"></i><span>Settings</span>
        </a>
        <?php endif; ?>

    </nav>

    <div class="sidebar-footer">
        <small class="text-muted" style="font-size:10.5px">v<?= APP_VERSION ?></small>
    </div>
</div>

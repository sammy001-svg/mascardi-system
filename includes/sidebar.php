<?php
$__uri   = $_SERVER['REQUEST_URI'];
$__isDash = !str_contains($__uri, '/modules/');

function isActive(string $path): string {
    global $__uri;
    return str_contains($__uri, $path) ? 'active' : '';
}

// Customer Relations Managers get a lean, focused sidebar
if (hasRole('customer_relations')) {
    include __DIR__ . '/sidebar_crm.php';
    return;
}
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
            <span class="brand-sub">Car Yard System</span>
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
        <div class="nav-section">Dispatch &amp; Team</div>

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
           class="nav-item <?= isActive('/modules/team/') ?>"
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
        <?php endif; ?>

        <?php endif; ?>

        <!-- ══ IMPORTS ═══════════════════════════════════════════ -->
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
           class="nav-item <?= (isActive('/modules/crm/leads') || isActive('/modules/crm/view_lead') || isActive('/modules/crm/add_lead')) ? 'active' : '' ?>"
           data-label="Leads">
            <i class="fa fa-user-plus"></i><span>Leads</span>
        </a>
        <?php endif; ?>

        <?php if (hasRole(['admin','general_manager','sales_manager','sales_officer','sales_person','customer_relations','receptionist'])): ?>
        <a href="<?= BASE_URL ?>/modules/showroom/index.php"
           class="nav-item <?= isActive('/modules/showroom/') ?>"
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
        <a href="<?= BASE_URL ?>/showroom/" target="_blank"
           class="nav-item"
           data-label="Public Showroom">
            <i class="fa fa-store"></i><span>Public Showroom</span>
            <i class="fa fa-external-link" style="font-size:10px;opacity:.4;margin-left:auto"></i>
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
        <?php if (canAccess('attendance') || canAccess('payroll')): ?>
        <div class="nav-section">Human Resources</div>

        <?php if (canAccess('attendance')): ?>
        <a href="<?= BASE_URL ?>/modules/attendance/index.php"
           class="nav-item <?= isActive('/modules/attendance/') ?>"
           data-label="Attendance">
            <i class="fa fa-calendar-days"></i><span>Attendance</span>
        </a>
        <?php endif; ?>

        <?php if (canAccess('payroll')): ?>
        <a href="<?= BASE_URL ?>/modules/payroll/index.php"
           class="nav-item <?= isActive('/modules/payroll/') ?>"
           data-label="Payroll">
            <i class="fa fa-money-bill-wave"></i><span>Payroll</span>
        </a>
        <?php endif; ?>
        <?php endif; ?>

        <!-- ══ ANALYTICS ══════════════════════════════════════════ -->
        <?php if (canAccess('reports')): ?>
        <div class="nav-section">Analytics</div>
        <a href="<?= BASE_URL ?>/modules/reports/index.php"
           class="nav-item <?= isActive('/modules/reports/') ?>"
           data-label="Reports">
            <i class="fa fa-chart-bar"></i><span>Reports</span>
        </a>
        <?php endif; ?>

        <!-- ══ COMMUNICATION ══════════════════════════════════════ -->
        <?php if (canAccess('chat')): ?>
        <div class="nav-section">Communication</div>
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

        <!-- ══ ADMIN ══════════════════════════════════════════════ -->
        <?php if (hasRole('admin')): ?>
        <div class="nav-section">Administration</div>
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

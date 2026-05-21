<?php
$__uri = $_SERVER['REQUEST_URI'];
function isActive(string $path): string {
    global $__uri;
    return str_contains($__uri, $path) ? 'active' : '';
}
$__isDash = !str_contains($__uri, '/modules/');
?>
<div class="app-sidebar" id="sidebar">

    <!-- Brand -->
    <div class="sidebar-brand">
        <div class="brand-logo">
            <i class="fa fa-car-side" style="font-size:16px"></i>
        </div>
        <div class="brand-text">
            <span class="brand-name"><?= e(getSetting('company_name', 'Mascardi')) ?></span>
            <span class="brand-sub">Car Yard System</span>
        </div>
    </div>

    <!-- Navigation -->
    <nav class="sidebar-nav">

        <a href="<?= BASE_URL ?>/index.php"
           class="nav-item <?= $__isDash ? 'active' : '' ?>"
           data-label="Dashboard">
            <i class="fa fa-gauge-high"></i><span>Dashboard</span>
        </a>

        <!-- Fleet -->
        <?php if (canAccess('cars') || canAccess('mechanics')): ?>
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
        <?php endif; ?>

        <!-- Logistics -->
        <?php if (canAccess('intake') || canAccess('assessments')): ?>
        <div class="nav-section">Logistics</div>

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
        <?php endif; ?>

        <!-- Workshop -->
        <?php if (canAccess('jobs') || canAccess('parts_requests') || canAccess('issues') || canAccess('lpo')): ?>
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
           data-label="Part Requests">
            <i class="fa fa-hand-holding-box"></i><span>Part Requests</span>
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

        <!-- Inventory -->
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

        <!-- Clients -->
        <?php if (canAccess('clients') || canAccess('service_bookings') || canAccess('quick_assessments')): ?>
        <div class="nav-section">Clients</div>

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

        <?php if (canAccess('quick_assessments')): ?>
        <a href="<?= BASE_URL ?>/modules/quick_assessments/index.php"
           class="nav-item <?= isActive('/modules/quick_assessments/') ?>"
           data-label="Quick Assessment">
            <i class="fa fa-magnifying-glass-chart"></i><span>Quick Assessment</span>
        </a>
        <?php endif; ?>
        <?php endif; ?>

        <!-- Financial -->
        <?php if (canAccess('payments') || canAccess('quotations') || canAccess('invoices')): ?>
        <div class="nav-section">Financial</div>

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
        <?php endif; ?>

        <!-- Analytics -->
        <?php if (canAccess('reports')): ?>
        <div class="nav-section">Analytics</div>
        <a href="<?= BASE_URL ?>/modules/reports/index.php"
           class="nav-item <?= isActive('/modules/reports/') ?>"
           data-label="Reports">
            <i class="fa fa-chart-bar"></i><span>Reports</span>
        </a>
        <?php endif; ?>

        <!-- Admin -->
        <?php if (hasRole('admin')): ?>
        <div class="nav-section">Admin</div>
        <a href="<?= BASE_URL ?>/modules/users/index.php"
           class="nav-item <?= isActive('/modules/users/') ?>"
           data-label="Users">
            <i class="fa fa-users-gear"></i><span>Users</span>
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

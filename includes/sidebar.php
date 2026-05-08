<?php
$currentPath = $_SERVER['REQUEST_URI'];
function isActive(string $path): string {
    global $currentPath;
    return str_contains($currentPath, $path) ? 'active' : '';
}
?>
<div class="sidebar" id="sidebar">
    <div class="sidebar-brand">
        <div class="brand-logo">
            <i class="fa fa-car-side fa-lg"></i>
        </div>
        <div class="brand-text">
            <span class="brand-name">Mascardi</span>
            <span class="brand-sub">Car Yard System</span>
        </div>
    </div>

    <nav class="sidebar-nav">
        <a href="<?= BASE_URL ?>/index.php" class="nav-item <?= isActive('/index.php') && !str_contains($currentPath, '/modules/') ? 'active' : '' ?>">
            <i class="fa fa-gauge-high"></i><span>Dashboard</span>
        </a>

        <?php if (canAccess('cars') || canAccess('drivers') || canAccess('mechanics')): ?>
        <div class="nav-section">Cars & Fleet</div>
        <?php if (canAccess('cars')): ?>
        <a href="<?= BASE_URL ?>/modules/cars/index.php" class="nav-item <?= isActive('/modules/cars/') ?>">
            <i class="fa fa-car"></i><span>All Cars</span>
        </a>
        <?php endif; ?>
        <?php if (canAccess('drivers')): ?>
        <a href="<?= BASE_URL ?>/modules/drivers/index.php" class="nav-item <?= isActive('/modules/drivers/') ?>">
            <i class="fa fa-id-card"></i><span>Drivers</span>
        </a>
        <?php endif; ?>
        <?php if (canAccess('mechanics')): ?>
        <a href="<?= BASE_URL ?>/modules/mechanics/index.php" class="nav-item <?= isActive('/modules/mechanics/') ?>">
            <i class="fa fa-screwdriver-wrench"></i><span>Mechanics</span>
        </a>
        <?php endif; ?>
        <?php endif; ?>

        <?php if (canAccess('intake') || canAccess('assessments')): ?>
        <div class="nav-section">Logistics</div>
        <?php if (canAccess('intake')): ?>
        <a href="<?= BASE_URL ?>/modules/intake/index.php" class="nav-item <?= isActive('/modules/intake/') ?>">
            <i class="fa fa-anchor"></i><span>Mombasa Intake</span>
        </a>
        <?php endif; ?>
        <?php if (canAccess('assessments')): ?>
        <a href="<?= BASE_URL ?>/modules/assessments/index.php" class="nav-item <?= isActive('/modules/assessments/') ?>">
            <i class="fa fa-clipboard-check"></i><span>Arrival Assessment</span>
        </a>
        <?php endif; ?>
        <?php endif; ?>

        <?php if (canAccess('jobs') || canAccess('quotations') || canAccess('invoices') || canAccess('lpo')): ?>
        <div class="nav-section">Workshop</div>
        <?php if (canAccess('jobs')): ?>
        <a href="<?= BASE_URL ?>/modules/jobs/index.php" class="nav-item <?= isActive('/modules/jobs/') ?>">
            <i class="fa fa-toolbox"></i><span>Job Cards</span>
        </a>
        <?php endif; ?>
        <?php if (canAccess('quotations')): ?>
        <a href="<?= BASE_URL ?>/modules/quotations/index.php" class="nav-item <?= isActive('/modules/quotations/') ?>">
            <i class="fa fa-file-lines"></i><span>Quotations</span>
        </a>
        <?php endif; ?>
        <?php if (canAccess('invoices')): ?>
        <a href="<?= BASE_URL ?>/modules/invoices/index.php" class="nav-item <?= isActive('/modules/invoices/') ?>">
            <i class="fa fa-file-invoice-dollar"></i><span>Invoices</span>
        </a>
        <?php endif; ?>
        <?php if (canAccess('lpo')): ?>
        <a href="<?= BASE_URL ?>/modules/lpo/index.php" class="nav-item <?= isActive('/modules/lpo/') ?>">
            <i class="fa fa-file-import"></i><span>LPO</span>
        </a>
        <?php endif; ?>
        <?php endif; ?>

        <?php if (canAccess('inventory') || canAccess('suppliers')): ?>
        <div class="nav-section">Inventory</div>
        <?php if (canAccess('inventory')): ?>
        <a href="<?= BASE_URL ?>/modules/inventory/index.php" class="nav-item <?= isActive('/modules/inventory/') ?>">
            <i class="fa fa-boxes-stacked"></i><span>Parts Stock</span>
        </a>
        <?php endif; ?>
        <?php if (canAccess('suppliers')): ?>
        <a href="<?= BASE_URL ?>/modules/suppliers/index.php" class="nav-item <?= isActive('/modules/suppliers/') ?>">
            <i class="fa fa-truck"></i><span>Suppliers</span>
        </a>
        <?php endif; ?>
        <?php endif; ?>

        <?php if (canAccess('reports')): ?>
        <div class="nav-section">Analytics</div>
        <a href="<?= BASE_URL ?>/modules/reports/index.php" class="nav-item <?= isActive('/modules/reports/') ?>">
            <i class="fa fa-chart-bar"></i><span>Reports</span>
        </a>
        <?php endif; ?>

        <?php if (hasRole('admin')): ?>
        <div class="nav-section">Administration</div>
        <a href="<?= BASE_URL ?>/modules/users/index.php" class="nav-item <?= isActive('/modules/users/') ?>">
            <i class="fa fa-users-gear"></i><span>Users</span>
        </a>
        <a href="<?= BASE_URL ?>/modules/settings/index.php" class="nav-item <?= isActive('/modules/settings/') ?>">
            <i class="fa fa-gear"></i><span>Settings</span>
        </a>
        <?php endif; ?>
    </nav>

    <div class="sidebar-footer">
        <small class="text-muted">v<?= APP_VERSION ?></small>
    </div>
</div>

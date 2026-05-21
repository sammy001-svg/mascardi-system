<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= isset($pageTitle) ? e($pageTitle) . ' — ' : '' ?><?= e(getSetting('company_name', APP_NAME)) ?></title>
<!-- Inter font -->
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<!-- Bootstrap 5 -->
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<!-- Font Awesome 6 -->
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
<!-- DataTables -->
<link href="https://cdn.datatables.net/1.13.8/css/dataTables.bootstrap5.min.css" rel="stylesheet">
<!-- Select2 -->
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet">
<!-- Custom — cache-busted so changes always load -->
<link href="<?= BASE_URL ?>/assets/css/style.css?v=<?= @filemtime(BASE_PATH . '/assets/css/style.css') ?: time() ?>" rel="stylesheet">
<meta name="csrf-token" content="<?= csrfToken() ?>">
</head>
<body>
<?php requireLogin(); $__user = authUser(); ?>

<!-- ── App Shell ────────────────────────────────────────── -->
<div id="appShell" style="display:flex;min-height:100vh">

<?php include __DIR__ . '/sidebar.php'; ?>
<div class="sidebar-backdrop" id="sidebarBackdrop"></div>

<div class="main-wrap" style="flex:1;min-width:0;display:flex;flex-direction:column;background:#edf0f7">

<!-- ── Top Navbar ─────────────────────────────────────── -->
<header class="app-topbar">
    <div class="topbar-left">
        <button class="toggle-btn" id="sidebarToggle" title="Toggle menu">
            <i class="fa fa-bars"></i>
        </button>
        <span class="topbar-title"><?= isset($pageTitle) ? e($pageTitle) : 'Dashboard' ?></span>
    </div>

    <div class="topbar-right">
        <?php
        try { $__ls = getDashboardStats()['low_stock']; } catch (Exception $e) { $__ls = 0; }
        if ($__ls > 0): ?>
        <a href="<?= BASE_URL ?>/modules/inventory/index.php?filter=low_stock"
           class="topbar-alert d-none d-md-flex">
            <i class="fa fa-triangle-exclamation"></i> <?= $__ls ?> low stock
        </a>
        <?php endif; ?>

        <span class="topbar-date d-none d-lg-inline"><?= date('d M Y') ?></span>

        <!-- Dark mode toggle -->
        <button class="topbar-icon-btn" id="themeToggle" title="Toggle dark mode">
            <i class="fa fa-moon" id="themeIcon"></i>
        </button>

        <!-- User dropdown -->
        <div class="dropdown">
            <button type="button" class="topbar-user dropdown-toggle"
                    id="userDropdown" data-bs-toggle="dropdown" aria-expanded="false">
                <div class="topbar-avatar"><?= strtoupper(substr($__user['name'], 0, 1)) ?></div>
                <div class="d-none d-md-block">
                    <div class="topbar-username"><?= e($__user['name']) ?></div>
                    <div class="topbar-userrole"><?= ucwords(str_replace('_',' ',e($__user['role']))) ?></div>
                </div>
                <i class="fa fa-chevron-down topbar-chevron d-none d-md-inline ms-1"></i>
            </button>
            <ul class="dropdown-menu dropdown-menu-end topbar-dropdown" aria-labelledby="userDropdown">
                <li>
                    <div class="dd-user-info">
                        <div class="dd-user-name"><?= e($__user['name']) ?></div>
                        <div class="dd-user-role"><?= ucwords(str_replace('_',' ',e($__user['role']))) ?></div>
                    </div>
                </li>
                <?php if (hasRole('admin')): ?>
                <li><a class="dropdown-item" href="<?= BASE_URL ?>/modules/users/index.php">
                    <i class="fa fa-user-circle"></i>My Profile
                </a></li>
                <li><a class="dropdown-item" href="<?= BASE_URL ?>/modules/settings/index.php">
                    <i class="fa fa-gear"></i>System Settings
                </a></li>
                <?php else: ?>
                <li><a class="dropdown-item" href="<?= BASE_URL ?>/modules/users/index.php">
                    <i class="fa fa-user-circle"></i>My Profile
                </a></li>
                <?php endif; ?>
                <li><hr class="dropdown-divider"></li>
                <li><a class="dropdown-item item-danger" href="<?= BASE_URL ?>/logout.php">
                    <i class="fa fa-right-from-bracket"></i>Sign Out
                </a></li>
            </ul>
        </div>
    </div>
</header>

<!-- ── Flash ─────────────────────────────────────────── -->
<?php $__flash = getFlash(); if ($__flash): ?>
<div class="alert alert-<?= $__flash['type'] === 'error' ? 'danger' : $__flash['type'] ?> alert-dismissible fade show mx-4 mt-3 mb-0" role="alert" style="border-radius:10px;border:none">
    <i class="fa fa-<?= $__flash['type'] === 'success' ? 'check-circle' : 'exclamation-circle' ?> me-2"></i>
    <?= e($__flash['message']) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<!-- ── Page Content ───────────────────────────────────── -->
<div class="page-body">

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= isset($pageTitle) ? e($pageTitle) . ' — ' : '' ?><?= e(getSetting('company_name', APP_NAME)) ?></title>
<!-- Bootstrap 5 -->
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<!-- Font Awesome 6 -->
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
<!-- DataTables -->
<link href="https://cdn.datatables.net/1.13.8/css/dataTables.bootstrap5.min.css" rel="stylesheet">
<!-- Select2 -->
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet">
<!-- Custom -->
<link href="<?= BASE_URL ?>/assets/css/style.css" rel="stylesheet">
</head>
<body>
<?php requireLogin(); $__user = authUser(); ?>
<div class="wrapper d-flex">
<?php include __DIR__ . '/sidebar.php'; ?>
<div class="sidebar-backdrop" id="sidebarBackdrop"></div>
<div class="main-content flex-grow-1">

<!-- Top Navbar -->
<nav class="top-navbar navbar px-3 py-2 d-flex align-items-center justify-content-between">
    <div class="d-flex align-items-center gap-2">
        <button class="btn btn-sm sidebar-toggle" id="sidebarToggle">
            <i class="fa fa-bars"></i>
        </button>
        <span class="fw-semibold text-white ms-1"><?= isset($pageTitle) ? e($pageTitle) : '' ?></span>
    </div>
    <div class="d-flex align-items-center gap-3">
        <?php $ls = getDashboardStats(); ?>
        <?php if ($ls['low_stock'] > 0): ?>
        <a href="<?= BASE_URL ?>/modules/inventory/index.php?filter=low_stock" class="text-warning text-decoration-none small d-none d-md-flex align-items-center gap-1">
            <i class="fa fa-triangle-exclamation"></i>
            <span><?= $ls['low_stock'] ?> low stock</span>
        </a>
        <?php endif; ?>
        <span class="text-light small d-none d-md-inline"><?= date('d M Y') ?></span>
        <!-- User info -->
        <div class="d-flex align-items-center gap-2">
            <div class="user-avatar" title="<?= e($__user['role']) ?>">
                <?= strtoupper(substr($__user['name'], 0, 1)) ?>
            </div>
            <div class="d-none d-lg-block">
                <div class="text-white fw-medium" style="font-size:13px;line-height:1.2"><?= e($__user['name']) ?></div>
                <div class="text-white-50" style="font-size:11px"><?= ucfirst(e($__user['role'])) ?></div>
            </div>
            <a href="<?= BASE_URL ?>/logout.php" class="btn btn-sm btn-outline-light py-1 px-2 ms-1" title="Sign Out">
                <i class="fa fa-right-from-bracket"></i>
            </a>
        </div>
    </div>
</nav>

<!-- Flash message -->
<?php $flash = getFlash(); if ($flash): ?>
<div class="alert alert-<?= $flash['type'] === 'error' ? 'danger' : $flash['type'] ?> alert-dismissible fade show mx-4 mt-3" role="alert">
    <i class="fa fa-<?= $flash['type'] === 'success' ? 'check-circle' : 'exclamation-circle' ?> me-2"></i>
    <?= e($flash['message']) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>
<div class="page-content p-4">

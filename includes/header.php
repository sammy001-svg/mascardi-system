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
<div class="wrapper d-flex">
<?php include __DIR__ . '/sidebar.php'; ?>
<div class="main-content flex-grow-1">
<!-- Top Navbar -->
<nav class="top-navbar navbar px-4 py-2 d-flex align-items-center justify-content-between">
    <button class="btn btn-sm sidebar-toggle me-3" id="sidebarToggle">
        <i class="fa fa-bars"></i>
    </button>
    <span class="fw-semibold text-white"><?= isset($pageTitle) ? e($pageTitle) : '' ?></span>
    <div class="d-flex align-items-center gap-3">
        <?php $ls = getDashboardStats(); ?>
        <?php if ($ls['low_stock'] > 0): ?>
        <a href="<?= BASE_URL ?>/modules/inventory/index.php?filter=low_stock" class="text-warning text-decoration-none small">
            <i class="fa fa-triangle-exclamation"></i> <?= $ls['low_stock'] ?> low stock
        </a>
        <?php endif; ?>
        <span class="text-light small"><?= date('d M Y') ?></span>
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

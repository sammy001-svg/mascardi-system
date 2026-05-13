<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= isset($pageTitle) ? e($pageTitle) . ' — ' : '' ?><?= e(getSetting('company_name', 'Mascardi System')) ?> Client Portal</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
<style>
*, body { font-family: 'Inter', sans-serif; }
body { background: #f1f5f9; min-height: 100vh; }
.cp-navbar { background: #1e3a5f; color: #fff; padding: 0 24px; height: 60px; display: flex; align-items: center; justify-content: space-between; box-shadow: 0 2px 8px rgba(0,0,0,.2); position: sticky; top: 0; z-index: 100; }
.cp-brand { font-size: 16px; font-weight: 700; color: #fff; text-decoration: none; display: flex; align-items: center; gap: 10px; }
.cp-brand .logo-dot { width: 32px; height: 32px; background: #2563eb; border-radius: 8px; display: flex; align-items: center; justify-content: center; }
.cp-nav a { color: rgba(255,255,255,.75); text-decoration: none; padding: 6px 12px; border-radius: 6px; font-size: 13px; transition: all .2s; }
.cp-nav a:hover, .cp-nav a.active { color: #fff; background: rgba(255,255,255,.12); }
.cp-main { max-width: 1100px; margin: 32px auto; padding: 0 16px; }
.cp-welcome { background: linear-gradient(135deg, #2563eb 0%, #1e3a5f 100%); color: #fff; border-radius: 16px; padding: 28px 32px; margin-bottom: 28px; }
.stat-card { background: #fff; border-radius: 12px; padding: 20px; display: flex; align-items: center; gap: 16px; box-shadow: 0 1px 4px rgba(0,0,0,.06); }
.stat-icon { width: 48px; height: 48px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 18px; flex-shrink: 0; }
.stat-label { font-size: 12px; color: #64748b; }
.stat-value { font-size: 22px; font-weight: 700; color: #1e293b; }
.card { border-radius: 12px; border: none; box-shadow: 0 1px 4px rgba(0,0,0,.07); }
.card-header { background: #fff; border-bottom: 1px solid #f1f5f9; font-weight: 600; font-size: 13.5px; padding: 14px 20px; border-radius: 12px 12px 0 0 !important; }
.cp-badge-unread { background: #ef4444; color: #fff; border-radius: 99px; font-size: 10px; padding: 1px 6px; font-weight: 700; }
</style>
</head>
<body>
<?php requireClientLogin(); $__cl = clientAuth(); ?>
<nav class="cp-navbar">
    <a href="<?= BASE_URL ?>/client/index.php" class="cp-brand">
        <div class="logo-dot"><i class="fa fa-car-side" style="font-size:14px"></i></div>
        <?= e(getSetting('company_name', 'Mascardi')) ?> <span style="font-weight:400;opacity:.7;margin-left:4px">Portal</span>
    </a>
    <div class="cp-nav d-flex align-items-center gap-1">
        <?php
        $uri = $_SERVER['REQUEST_URI'];
        function cpActive(string $p): string { global $uri; return str_contains($uri, $p) ? 'active' : ''; }
        ?>
        <a href="<?= BASE_URL ?>/client/index.php"       class="<?= cpActive('client/index') ?>"><i class="fa fa-gauge me-1"></i>Dashboard</a>
        <a href="<?= BASE_URL ?>/client/bookings.php"    class="<?= cpActive('bookings') ?>"><i class="fa fa-calendar me-1"></i>Bookings</a>
        <a href="<?= BASE_URL ?>/client/assessments.php" class="<?= cpActive('assessments') ?>"><i class="fa fa-list-check me-1"></i>Assessments</a>
        <a href="<?= BASE_URL ?>/client/invoices.php"    class="<?= cpActive('invoices') ?>"><i class="fa fa-file-invoice me-1"></i>Invoices</a>
        <a href="<?= BASE_URL ?>/client/quotations.php"  class="<?= cpActive('quotations') ?>"><i class="fa fa-file-lines me-1"></i>Quotes</a>
        <a href="<?= BASE_URL ?>/client/notices.php"     class="<?= cpActive('notices') ?>">
            <i class="fa fa-bell me-1"></i>Notices
            <?php
            $unread = 0;
            try { $db=getDB(); $r=$db->prepare("SELECT COUNT(*) FROM client_notices WHERE client_id=? AND is_read=0"); $r->execute([$__cl['id']]); $unread=(int)$r->fetchColumn(); } catch(\Throwable $e){}
            if ($unread): ?><span class="cp-badge-unread"><?= $unread ?></span><?php endif; ?>
        </a>
        <span style="color:rgba(255,255,255,.3);padding:0 4px">|</span>
        <span style="color:rgba(255,255,255,.7);font-size:13px"><?= e($__cl['name']) ?></span>
        <a href="<?= BASE_URL ?>/client/logout.php" style="color:rgba(255,255,255,.6)"><i class="fa fa-right-from-bracket"></i></a>
    </div>
</nav>
<?php $flash = getFlash(); if ($flash): ?>
<div class="container-fluid" style="max-width:1100px;margin:0 auto;padding:12px 16px 0">
    <div class="alert alert-<?= $flash['type']==='error'?'danger':$flash['type'] ?> alert-dismissible py-2">
        <?= e($flash['message']) ?>
        <button type="button" class="btn-close btn-sm" data-bs-dismiss="alert"></button>
    </div>
</div>
<?php endif; ?>
<div class="cp-main">

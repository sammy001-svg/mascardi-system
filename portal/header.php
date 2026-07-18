<?php requirePortalLogin(); $__pc = portalClient(); $__uri = $_SERVER['REQUEST_URI']; ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= isset($pageTitle) ? e($pageTitle) . ' — ' : '' ?><?= e(getSetting('company_name', APP_NAME)) ?> Portal</title>

<!-- Theme — dark by default, applied before first paint -->
<script>
(function () {
    var dark = true;
    try { dark = localStorage.getItem('mascardiTheme') !== 'light'; } catch (e) {}
    if (dark) {
        document.documentElement.setAttribute('data-theme', 'dark');
        document.documentElement.setAttribute('data-bs-theme', 'dark');
    }
}());
</script>

<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
<style>
* { font-family: 'Inter', sans-serif; }
body { background: #f1f5f9; }
.portal-nav { background: #1e293b; box-shadow: 0 2px 8px rgba(0,0,0,.2); }
.portal-nav .navbar-brand { color: #fff; font-weight: 700; font-size: 16px; }
.portal-nav .navbar-brand:hover { color: #e2e8f0; }
.portal-nav .nav-link { color: rgba(255,255,255,.65); font-size: 13.5px; font-weight: 500; padding: .5rem .85rem; border-radius: 6px; }
.portal-nav .nav-link:hover { color: #fff; background: rgba(255,255,255,.08); }
.portal-nav .nav-link.pactive { color: #fff; background: rgba(255,255,255,.12); }
.portal-nav .navbar-toggler { border: none; color: #fff; }
.portal-content { padding: 1.75rem 0 3rem; }
.p-stat { background: #fff; border-radius: 12px; padding: 1.2rem 1.5rem; box-shadow: 0 1px 3px rgba(0,0,0,.07); display: flex; align-items: center; gap: 1rem; height: 100%; }
.p-stat-icon { width: 44px; height: 44px; border-radius: 10px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; font-size: 17px; }
.p-stat-label { font-size: 11.5px; color: #64748b; font-weight: 500; text-transform: uppercase; letter-spacing: .04em; }
.p-stat-value { font-size: 20px; font-weight: 700; line-height: 1.2; margin-top: 2px; }
.p-card { background: #fff; border-radius: 12px; box-shadow: 0 1px 3px rgba(0,0,0,.07); margin-bottom: 1.5rem; overflow: hidden; }
.p-card-header { padding: .85rem 1.25rem; border-bottom: 1px solid #f1f5f9; font-weight: 600; font-size: 14px; display: flex; justify-content: space-between; align-items: center; }
.p-card-body { padding: 1.25rem; }
.badge-status { display: inline-block; padding: .25em .6em; border-radius: 5px; font-size: 11.5px; font-weight: 600; }
.page-hero { background: #1e293b; color: #fff; padding: 1.25rem 0; margin-bottom: 0; }
.page-hero h4 { margin: 0; font-weight: 700; font-size: 18px; }
.page-hero .breadcrumb-item, .page-hero .breadcrumb-item a { color: rgba(255,255,255,.65); font-size: 13px; }
.page-hero .breadcrumb-item.active { color: rgba(255,255,255,.9); }
/* ── Dark mode (default) ─────────────────────────────────── */
[data-theme="dark"] body { background: #0a0f1e; }
[data-theme="dark"] .portal-nav { background: #070b16; border-bottom: 1px solid rgba(59,130,246,.15); }
[data-theme="dark"] .p-stat,
[data-theme="dark"] .p-card {
    background: #101a30;
    border: 1px solid #24365a;
    box-shadow: 0 12px 32px rgba(0,0,0,.5), inset 0 1px 0 rgba(255,255,255,.04);
}
[data-theme="dark"] .p-card-header { border-bottom-color: #24365a; }
[data-theme="dark"] .p-stat-label { color: #9fb0c8; }
[data-theme="dark"] .page-hero { background: #0d1424; }
@media print {
    .portal-nav, .no-print { display: none !important; }
    body { background: #fff !important; }
    [data-theme="dark"] .p-card, [data-theme="dark"] .p-stat { background: #fff; border-color: #e2e8f0; box-shadow: none; }
}
</style>
</head>
<body>

<nav class="navbar navbar-expand-lg portal-nav sticky-top">
    <div class="container">
        <a class="navbar-brand" href="<?= BASE_URL ?>/portal/index.php">
            <i class="fa fa-car-side me-2"></i><?= e(getSetting('company_name', APP_NAME)) ?>
            <span class="badge ms-2" style="background:rgba(255,255,255,.15);font-size:10px;font-weight:500;vertical-align:middle">Client Portal</span>
        </a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#pNav">
            <i class="fa fa-bars"></i>
        </button>
        <div class="collapse navbar-collapse" id="pNav">
            <ul class="navbar-nav ms-auto align-items-lg-center gap-1">
                <li class="nav-item">
                    <a class="nav-link <?= str_ends_with(parse_url($__uri, PHP_URL_PATH), '/portal/index.php') ? 'pactive' : '' ?>"
                       href="<?= BASE_URL ?>/portal/index.php">
                        <i class="fa fa-gauge-high me-1"></i>Dashboard
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= str_contains($__uri, '/portal/my_purchase') ? 'pactive' : '' ?>"
                       href="<?= BASE_URL ?>/portal/my_purchase.php">
                        <i class="fa fa-tag me-1"></i>My Purchase
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= str_contains($__uri, '/portal/booking') ? 'pactive' : '' ?>"
                       href="<?= BASE_URL ?>/portal/bookings.php">
                        <i class="fa fa-calendar-check me-1"></i>Bookings
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= str_contains($__uri, '/portal/service_history') ? 'pactive' : '' ?>"
                       href="<?= BASE_URL ?>/portal/service_history.php">
                        <i class="fa fa-wrench me-1"></i>History
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= str_contains($__uri, '/portal/quotation') ? 'pactive' : '' ?>"
                       href="<?= BASE_URL ?>/portal/quotations.php">
                        <i class="fa fa-file-lines me-1"></i>Quotations
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= str_contains($__uri, '/portal/invoice') ? 'pactive' : '' ?>"
                       href="<?= BASE_URL ?>/portal/invoices.php">
                        <i class="fa fa-file-invoice-dollar me-1"></i>Invoices
                    </a>
                </li>
                <li class="nav-item ms-lg-2">
                    <div class="dropdown">
                        <button class="btn btn-sm dropdown-toggle" style="background:rgba(255,255,255,.12);color:#fff;border:1px solid rgba(255,255,255,.2)" data-bs-toggle="dropdown">
                            <i class="fa fa-user-circle me-1"></i><?= e($__pc['name']) ?>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end shadow-sm border-0" style="min-width:200px">
                            <li><div class="px-3 py-2 border-bottom">
                                <div class="fw-semibold small"><?= e($__pc['name']) ?></div>
                                <div class="text-muted" style="font-size:12px"><?= e($__pc['email']) ?></div>
                            </div></li>
                            <li><a class="dropdown-item small" href="<?= BASE_URL ?>/portal/profile.php">
                                <i class="fa fa-user-pen me-2 text-muted"></i>My Profile
                            </a></li>
                            <li><a class="dropdown-item small" href="<?= BASE_URL ?>/portal/statement.php">
                                <i class="fa fa-file-lines me-2 text-muted"></i>Account Statement
                            </a></li>
                            <li><hr class="dropdown-divider my-1"></li>
                            <li><a class="dropdown-item small text-danger" href="<?= BASE_URL ?>/portal/logout.php">
                                <i class="fa fa-right-from-bracket me-2"></i>Sign Out
                            </a></li>
                        </ul>
                    </div>
                </li>
            </ul>
        </div>
    </div>
</nav>

<?php
$__flash = getFlash();
if ($__flash): ?>
<div class="container mt-3">
    <div class="alert alert-<?= $__flash['type'] === 'success' ? 'success' : 'danger' ?> alert-dismissible py-2 small">
        <i class="fa fa-<?= $__flash['type'] === 'success' ? 'circle-check' : 'circle-exclamation' ?> me-1"></i>
        <?= e($__flash['message']) ?>
        <button type="button" class="btn-close btn-close-sm" data-bs-dismiss="alert"></button>
    </div>
</div>
<?php endif; ?>

<div class="portal-content">
<div class="container">

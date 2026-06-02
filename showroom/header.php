<?php
/**
 * Public showroom header — no authentication required.
 * Loads company info from settings for branding.
 */
require_once __DIR__ . '/../includes/functions.php';

$__companyName  = getSetting('company_name',  'Mascardi Car Yard');
$__companyPhone = getSetting('company_phone', '');
$__companyEmail = getSetting('company_email', '');
$__whatsapp     = getSetting('whatsapp_number', $__companyPhone);
$__logo         = getSetting('company_logo', '');
$__logoSrc      = ($__logo && file_exists(BASE_PATH . '/assets/images/' . $__logo))
                ? BASE_URL . '/assets/images/' . $__logo
                : null;

$__pageTitle = isset($pageTitle) ? $pageTitle . ' — ' . $__companyName : $__companyName . ' — Car Showroom';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<title><?= htmlspecialchars($__pageTitle) ?></title>
<meta name="description" content="<?= isset($metaDesc) ? htmlspecialchars($metaDesc) : 'Browse quality vehicles at ' . htmlspecialchars($__companyName) . '. Finance available.' ?>">
<?php if (isset($ogImage)): ?>
<meta property="og:image" content="<?= htmlspecialchars($ogImage) ?>">
<?php endif; ?>
<meta property="og:title"       content="<?= htmlspecialchars($__pageTitle) ?>">
<meta property="og:type"        content="website">
<meta name="theme-color"        content="#2563eb">

<link rel="icon" type="image/svg+xml" href="<?= BASE_URL ?>/assets/images/icons/icon.svg">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
<style>
:root {
    --brand:      #2563eb;
    --brand-dark: #1d4ed8;
    --text:       #0f172a;
    --text-2:     #475569;
    --text-3:     #94a3b8;
    --border:     #e2e8f0;
    --surface:    #ffffff;
    --bg:         #f1f5f9;
}
*, *::before, *::after { box-sizing: border-box; }
html { scroll-behavior: smooth; }
body {
    font-family: 'Inter', -apple-system, sans-serif;
    font-size: 14px;
    color: var(--text);
    background: var(--bg);
    margin: 0;
    -webkit-font-smoothing: antialiased;
}
a { color: var(--brand); text-decoration: none; }
a:hover { text-decoration: underline; }

/* ── Top bar ── */
.showroom-topbar {
    background: #0f172a;
    color: rgba(255,255,255,.65);
    font-size: 12.5px;
    padding: 8px 0;
}
.showroom-topbar a { color: rgba(255,255,255,.75); }
.showroom-topbar a:hover { color: #fff; text-decoration: none; }

/* ── Nav ── */
.showroom-nav {
    background: #fff;
    border-bottom: 1px solid var(--border);
    padding: 0;
    position: sticky;
    top: 0;
    z-index: 200;
    box-shadow: 0 1px 0 rgba(0,0,0,.04);
}
.showroom-nav-inner {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 14px 24px;
    gap: 16px;
}
.nav-brand {
    display: flex;
    align-items: center;
    gap: 10px;
    text-decoration: none;
}
.nav-brand-logo {
    width: 36px; height: 36px;
    border-radius: 8px;
    background: linear-gradient(135deg, #3b82f6, #1d4ed8);
    display: flex; align-items: center; justify-content: center;
    color: #fff; font-size: 15px;
    flex-shrink: 0;
}
.nav-brand-name {
    font-size: 17px;
    font-weight: 800;
    color: var(--text);
    letter-spacing: -.4px;
}
.nav-brand-sub {
    font-size: 10px;
    color: var(--text-3);
    font-weight: 500;
    margin-top: -2px;
}
.nav-actions { display: flex; align-items: center; gap: 10px; }
.btn-whatsapp {
    background: #25d366;
    color: #fff;
    border: none;
    border-radius: 8px;
    padding: 9px 16px;
    font-size: 13px;
    font-weight: 700;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    text-decoration: none;
    transition: background .15s;
    white-space: nowrap;
}
.btn-whatsapp:hover { background: #128c7e; color: #fff; text-decoration: none; }
.btn-admin-link {
    font-size: 12px;
    color: var(--text-3);
    border: 1px solid var(--border);
    border-radius: 7px;
    padding: 6px 12px;
    display: inline-flex;
    align-items: center;
    gap: 5px;
    transition: all .12s;
}
.btn-admin-link:hover { color: var(--brand); border-color: var(--brand); text-decoration: none; background: #eff6ff; }

/* ── Page wrapper ── */
.showroom-body { padding: 32px 0 64px; }
@media (max-width: 768px) {
    .showroom-topbar { display: none; }
    .showroom-nav-inner { padding: 12px 16px; }
    .showroom-body { padding: 20px 0 48px; }
    .nav-brand-sub { display: none; }
}
</style>
</head>
<body>

<!-- Top info bar -->
<div class="showroom-topbar d-none d-md-block">
    <div class="container-lg d-flex justify-content-between align-items-center">
        <div class="d-flex gap-4">
            <?php if ($__companyPhone): ?>
            <a href="tel:<?= htmlspecialchars($__companyPhone) ?>">
                <i class="fa fa-phone me-1"></i><?= htmlspecialchars($__companyPhone) ?>
            </a>
            <?php endif; ?>
            <?php if ($__companyEmail): ?>
            <a href="mailto:<?= htmlspecialchars($__companyEmail) ?>">
                <i class="fa fa-envelope me-1"></i><?= htmlspecialchars($__companyEmail) ?>
            </a>
            <?php endif; ?>
        </div>
        <div>Mon – Sat: 8:00 AM – 6:00 PM</div>
    </div>
</div>

<!-- Main nav -->
<nav class="showroom-nav">
    <div class="showroom-nav-inner container-lg">
        <a href="<?= BASE_URL ?>/showroom/" class="nav-brand">
            <?php if ($__logoSrc): ?>
            <img src="<?= htmlspecialchars($__logoSrc) ?>" width="36" height="36" style="border-radius:8px;object-fit:contain" alt="logo">
            <?php else: ?>
            <div class="nav-brand-logo"><i class="fa fa-car-side"></i></div>
            <?php endif; ?>
            <div>
                <div class="nav-brand-name"><?= htmlspecialchars($__companyName) ?></div>
                <div class="nav-brand-sub">Car Showroom</div>
            </div>
        </a>

        <div class="nav-actions">
            <?php if ($__whatsapp): ?>
            <a href="https://wa.me/<?= preg_replace('/[^0-9]/', '', $__whatsapp) ?>" target="_blank" rel="noopener" class="btn-whatsapp">
                <i class="fa-brands fa-whatsapp"></i>
                <span class="d-none d-sm-inline">WhatsApp Us</span>
            </a>
            <?php endif; ?>
            <a href="<?= BASE_URL ?>/index.php" class="btn-admin-link">
                <i class="fa fa-lock"></i><span class="d-none d-sm-inline">Staff Login</span>
            </a>
        </div>
    </div>
</nav>

<div class="showroom-body">
<div class="container-lg">

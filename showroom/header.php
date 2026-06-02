<?php
/**
 * Public showroom header — no auth required.
 */
require_once __DIR__ . '/../includes/functions.php';

$__companyName  = getSetting('company_name',    'Mascardi Car Yard');
$__companyPhone = getSetting('company_phone',   '');
$__companyEmail = getSetting('company_email',   '');
$__whatsapp     = getSetting('whatsapp_number', $__companyPhone);
$__address      = getSetting('company_address', '');
$__logo         = getSetting('company_logo',    '');
$__logoSrc      = ($__logo && file_exists(BASE_PATH . '/assets/images/' . $__logo))
                ? BASE_URL . '/assets/images/' . $__logo
                : null;

$__waClean = preg_replace('/[^0-9]/', '', $__whatsapp);
$__pageTitle = isset($pageTitle)
    ? $pageTitle . ' — ' . $__companyName
    : $__companyName . ' — Quality Vehicles';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<title><?= htmlspecialchars($__pageTitle) ?></title>
<meta name="description" content="<?= isset($metaDesc) ? htmlspecialchars($metaDesc) : 'Browse quality imported vehicles at ' . htmlspecialchars($__companyName) . '. Finance available. Visit our showroom today.' ?>">
<?php if (isset($ogImage)): ?>
<meta property="og:image"       content="<?= htmlspecialchars($ogImage) ?>">
<?php endif; ?>
<meta property="og:title"       content="<?= htmlspecialchars($__pageTitle) ?>">
<meta property="og:description" content="Quality vehicles. Transparent pricing. Finance available.">
<meta property="og:type"        content="website">
<meta name="theme-color"        content="#0f172a">

<!-- PWA -->
<link rel="manifest"    href="<?= BASE_URL ?>/manifest.php">
<link rel="icon"        type="image/svg+xml" href="<?= BASE_URL ?>/assets/images/icons/icon.svg">
<link rel="apple-touch-icon"                 href="<?= BASE_URL ?>/assets/images/icons/icon.svg">
<meta name="theme-color"                     content="#0f172a">
<meta name="mobile-web-app-capable"          content="yes">
<meta name="apple-mobile-web-app-capable"    content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title"      content="<?= htmlspecialchars($__companyName) ?>">

<link rel="preconnect"  href="https://fonts.googleapis.com">
<link rel="preconnect"  href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">

<style>
/* ── Design Tokens ─────────────────────────────────────────── */
:root {
    --navy:        #0f172a;
    --navy-2:      #1e293b;
    --navy-3:      #334155;
    --brand:       #2563eb;
    --brand-dark:  #1d4ed8;
    --brand-light: #3b82f6;
    --gold:        #f59e0b;
    --gold-dark:   #d97706;
    --white:       #ffffff;
    --off-white:   #f8fafc;
    --light:       #f1f5f9;
    --border:      #e2e8f0;
    --text:        #0f172a;
    --text-2:      #475569;
    --text-3:      #94a3b8;
    --r:           10px;
    --r-lg:        16px;
    --r-xl:        24px;
    --shadow:      0 4px 24px rgba(0,0,0,.08), 0 1px 4px rgba(0,0,0,.04);
    --shadow-lg:   0 20px 60px rgba(0,0,0,.15), 0 4px 16px rgba(0,0,0,.08);
}

/* ── Base ─────────────────────────────────────────────────── */
*, *::before, *::after { box-sizing: border-box; }
html { scroll-behavior: smooth; }
body {
    font-family: 'Inter', -apple-system, sans-serif;
    font-size: 15px;
    color: var(--text);
    background: var(--white);
    margin: 0;
    -webkit-font-smoothing: antialiased;
    -webkit-tap-highlight-color: transparent;
}
a { color: var(--brand); text-decoration: none; }
a:hover { text-decoration: underline; }
img { max-width: 100%; height: auto; }
h1,h2,h3,h4,h5,h6 { font-weight: 800; letter-spacing: -.4px; }

/* ── Top info strip ───────────────────────────────────────── */
.top-strip {
    background: var(--navy);
    color: rgba(255,255,255,.55);
    font-size: 12.5px;
    padding: 8px 0;
    border-bottom: 1px solid rgba(255,255,255,.05);
}
.top-strip a { color: rgba(255,255,255,.7); transition: color .15s; }
.top-strip a:hover { color: #fff; text-decoration: none; }

/* ── Main navbar ──────────────────────────────────────────── */
.site-nav {
    background: rgba(15,23,42,.97);
    -webkit-backdrop-filter: blur(20px);
    backdrop-filter: blur(20px);
    border-bottom: 1px solid rgba(255,255,255,.06);
    position: sticky;
    top: 0;
    z-index: 1000;
    transition: background .3s;
}
.nav-inner {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 16px 24px;
    gap: 20px;
}
.nav-brand {
    display: flex;
    align-items: center;
    gap: 12px;
    text-decoration: none;
    flex-shrink: 0;
}
.nav-brand-logo {
    width: 42px; height: 42px;
    border-radius: 10px;
    background: linear-gradient(135deg, #3b82f6, #1d4ed8);
    display: flex; align-items: center; justify-content: center;
    color: #fff; font-size: 18px;
    box-shadow: 0 4px 14px rgba(37,99,235,.4);
}
.nav-brand-logo img { width: 100%; height: 100%; object-fit: contain; border-radius: 10px; }
.nav-brand-name { font-size: 18px; font-weight: 800; color: #fff; letter-spacing: -.4px; line-height: 1.2; }
.nav-brand-sub  { font-size: 10.5px; color: rgba(255,255,255,.35); font-weight: 500; }

.nav-links {
    display: flex;
    align-items: center;
    gap: 6px;
    list-style: none;
    margin: 0; padding: 0;
}
.nav-links a {
    color: rgba(255,255,255,.65);
    font-size: 13.5px;
    font-weight: 600;
    padding: 7px 14px;
    border-radius: 8px;
    transition: all .15s;
    text-decoration: none;
    white-space: nowrap;
}
.nav-links a:hover { color: #fff; background: rgba(255,255,255,.08); }
.nav-links a.active { color: #fff; background: rgba(37,99,235,.25); }

.nav-ctas { display: flex; align-items: center; gap: 10px; flex-shrink: 0; }

.btn-wa {
    background: #25d366;
    color: #fff;
    border: none;
    border-radius: 9px;
    padding: 9px 16px;
    font-size: 13px;
    font-weight: 700;
    display: inline-flex;
    align-items: center;
    gap: 7px;
    text-decoration: none;
    transition: background .15s, transform .1s;
    white-space: nowrap;
}
.btn-wa:hover { background: #128c7e; color: #fff; text-decoration: none; transform: translateY(-1px); }

.btn-staff {
    color: rgba(255,255,255,.5);
    font-size: 12px;
    font-weight: 600;
    border: 1px solid rgba(255,255,255,.15);
    border-radius: 8px;
    padding: 7px 14px;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    text-decoration: none;
    transition: all .15s;
    white-space: nowrap;
}
.btn-staff:hover { color: #fff; border-color: rgba(255,255,255,.35); background: rgba(255,255,255,.05); text-decoration: none; }

/* Mobile hamburger */
.nav-toggle {
    display: none;
    flex-direction: column;
    gap: 5px;
    cursor: pointer;
    padding: 6px;
    border: none;
    background: none;
}
.nav-toggle span {
    width: 24px; height: 2px;
    background: rgba(255,255,255,.7);
    border-radius: 2px;
    transition: all .25s;
}

@media (max-width: 991px) {
    .nav-links-wrap { display: none; }
    .nav-links-wrap.open {
        display: flex;
        flex-direction: column;
        position: absolute;
        top: 100%;
        left: 0; right: 0;
        background: var(--navy-2);
        border-bottom: 1px solid rgba(255,255,255,.08);
        padding: 12px 16px 20px;
        z-index: 999;
    }
    .nav-links { flex-direction: column; align-items: flex-start; gap: 2px; width: 100%; }
    .nav-links a { display: block; width: 100%; padding: 10px 14px; }
    .nav-toggle { display: flex; }
    .top-strip { display: none; }
    .nav-brand-sub { display: none; }
}
@media (max-width: 576px) {
    .btn-wa span { display: none; }
    .btn-staff span { display: none; }
}
</style>
</head>
<body>

<!-- Top strip -->
<?php if ($__companyPhone || $__companyEmail || $__address): ?>
<div class="top-strip d-none d-md-block">
    <div class="container-xl d-flex justify-content-between align-items-center">
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
            <?php if ($__address): ?>
            <span><i class="fa fa-location-dot me-1"></i><?= htmlspecialchars($__address) ?></span>
            <?php endif; ?>
        </div>
        <span>Mon – Sat &nbsp;8:00 AM – 6:00 PM</span>
    </div>
</div>
<?php endif; ?>

<!-- Main nav -->
<nav class="site-nav" id="siteNav">
    <div class="container-xl nav-inner">

        <a href="<?= BASE_URL ?>/showroom/" class="nav-brand">
            <?php if ($__logoSrc): ?>
            <div class="nav-brand-logo"><img src="<?= htmlspecialchars($__logoSrc) ?>" alt="logo"></div>
            <?php else: ?>
            <div class="nav-brand-logo"><i class="fa fa-car-side"></i></div>
            <?php endif; ?>
            <div>
                <div class="nav-brand-name"><?= htmlspecialchars($__companyName) ?></div>
                <div class="nav-brand-sub">Official Car Showroom</div>
            </div>
        </a>

        <div class="nav-links-wrap" id="navLinksWrap">
            <ul class="nav-links">
                <li><a href="<?= BASE_URL ?>/showroom/#hero"       class="active">Home</a></li>
                <li><a href="<?= BASE_URL ?>/showroom/#inventory">All Cars</a></li>
                <li><a href="<?= BASE_URL ?>/showroom/#categories">Categories</a></li>
                <li><a href="<?= BASE_URL ?>/showroom/#why-us">About Us</a></li>
                <li><a href="<?= BASE_URL ?>/showroom/#contact">Contact</a></li>
            </ul>
        </div>

        <div class="nav-ctas">
            <?php if ($__waClean): ?>
            <a href="https://wa.me/<?= $__waClean ?>" target="_blank" rel="noopener" class="btn-wa">
                <i class="fa-brands fa-whatsapp"></i>
                <span>WhatsApp</span>
            </a>
            <?php endif; ?>
            <a href="<?= BASE_URL ?>/login.php" class="btn-staff">
                <i class="fa fa-lock"></i>
                <span>Staff Login</span>
            </a>
            <button class="nav-toggle" id="navToggle" aria-label="Menu">
                <span></span><span></span><span></span>
            </button>
        </div>

    </div>
</nav>

<script>
document.getElementById('navToggle').addEventListener('click', function() {
    var wrap = document.getElementById('navLinksWrap');
    wrap.classList.toggle('open');
});
// Highlight nav link based on scroll position
window.addEventListener('scroll', function() {
    var nav = document.getElementById('siteNav');
    if (window.scrollY > 60) {
        nav.style.background = 'rgba(15,23,42,1)';
    } else {
        nav.style.background = 'rgba(15,23,42,.97)';
    }
});
</script>

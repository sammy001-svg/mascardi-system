<?php
/**
 * Public showroom header — no auth required.
 * Design language: minimal luxury (Lucid-inspired) — warm off-white, near-black ink,
 * bronze accent, grotesque sans, uppercase micro-labels, squared corners.
 *
 * Pages may set $navOverlay = true BEFORE including this file to render the nav
 * transparently over a full-bleed hero (turns solid on scroll).
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

$__navOverlay = !empty($navOverlay);

// Vehicle categories for the nav dropdown (cheap, cached per request)
$__navCats = [];
try {
    $__navCats = getDB()->query("
        SELECT body_type, COUNT(*) AS n FROM cars
        WHERE car_type='inventory' AND show_on_website=1
          AND (status IS NULL OR status NOT IN ('delivered','sold'))
          AND body_type IS NOT NULL AND body_type != ''
        GROUP BY body_type ORDER BY n DESC LIMIT 8
    ")->fetchAll(PDO::FETCH_KEY_PAIR);
} catch (\Throwable $_) {}
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
<meta name="theme-color"        content="#0c0c0c">

<!-- PWA -->
<link rel="manifest"    href="<?= BASE_URL ?>/manifest.php">
<link rel="icon"        type="image/svg+xml" href="<?= BASE_URL ?>/assets/images/icons/icon.svg">
<link rel="apple-touch-icon"                 href="<?= BASE_URL ?>/assets/images/icons/icon.svg">
<meta name="mobile-web-app-capable"          content="yes">
<meta name="apple-mobile-web-app-capable"    content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title"      content="<?= htmlspecialchars($__companyName) ?>">

<link rel="preconnect"  href="https://fonts.googleapis.com">
<link rel="preconnect"  href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Archivo:wght@200;300;400;500;600;700&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">

<style>
/* ── Design Tokens — minimal luxury ─────────────────────────── */
:root {
    --paper:    #f8f7f5;   /* warm off-white section bg   */
    --white:    #ffffff;
    --ink:      #191919;   /* primary text                */
    --ink-2:    #575757;   /* secondary text              */
    --ink-3:    #8f8b85;   /* muted text                  */
    --line:     #e5e2dd;   /* hairline borders            */
    --bronze:   #9a7b4f;   /* restrained luxury accent    */
    --black:    #0c0c0c;   /* dark sections / footer      */
    --r:        2px;       /* squared corners throughout  */
    --nav-h:    72px;
    --ease:     cubic-bezier(.25,.46,.45,.94);
}

/* ── Base ───────────────────────────────────────────────────── */
*, *::before, *::after { box-sizing: border-box; }
html { scroll-behavior: smooth; }
img { max-width: 100%; height: auto; content-visibility: auto; }
body {
    font-family: 'Archivo', 'Helvetica Neue', Arial, sans-serif;
    font-size: 15px;
    color: var(--ink);
    background: var(--white);
    margin: 0;
    -webkit-font-smoothing: antialiased;
    -webkit-tap-highlight-color: transparent;
}
a { color: var(--ink); text-decoration: none; }
a:hover { color: var(--bronze); text-decoration: none; }
h1,h2,h3,h4,h5,h6 { font-weight: 400; letter-spacing: -.01em; }

/* Uppercase micro-label used across the site */
.lx-label {
    font-size: 11px; font-weight: 600; text-transform: uppercase;
    letter-spacing: .2em; color: var(--ink-3);
}
/* Section headline */
.lx-h2 {
    font-size: clamp(28px, 4vw, 46px);
    font-weight: 300; letter-spacing: -.01em; color: var(--ink);
    line-height: 1.12; margin: 0;
}
/* Buttons — squared, uppercase, quiet */
.btn-lx, .btn-lx-light, .btn-lx-ghost, .btn-lx-ghost-dark {
    display: inline-flex; align-items: center; justify-content: center; gap: 9px;
    padding: 14px 30px; border-radius: var(--r);
    font-size: 12px; font-weight: 600; letter-spacing: .16em; text-transform: uppercase;
    text-decoration: none; cursor: pointer; transition: all .25s var(--ease);
    border: 1px solid transparent; white-space: nowrap;
}
.btn-lx { background: var(--ink); color: #fff; border-color: var(--ink); }
.btn-lx:hover { background: #000; color: #fff; }
.btn-lx-light { background: #fff; color: var(--ink); border-color: #fff; }
.btn-lx-light:hover { background: rgba(255,255,255,.88); color: #000; }
.btn-lx-ghost { background: transparent; color: #fff; border-color: rgba(255,255,255,.55); }
.btn-lx-ghost:hover { background: rgba(255,255,255,.12); color: #fff; border-color: #fff; }
.btn-lx-ghost-dark { background: transparent; color: var(--ink); border-color: var(--ink); }
.btn-lx-ghost-dark:hover { background: var(--ink); color: #fff; }

/* ── Navbar ─────────────────────────────────────────────────── */
.site-nav {
    position: <?= $__navOverlay ? 'fixed' : 'sticky' ?>;
    top: 0; left: 0; right: 0; z-index: 1000;
    height: var(--nav-h);
    display: flex; align-items: center;
    background: <?= $__navOverlay ? 'transparent' : '#ffffff' ?>;
    border-bottom: 1px solid <?= $__navOverlay ? 'transparent' : 'var(--line)' ?>;
    transition: background .35s var(--ease), border-color .35s var(--ease), box-shadow .35s var(--ease);
}
.site-nav.nav-solid {
    background: rgba(255,255,255,.97);
    -webkit-backdrop-filter: blur(16px); backdrop-filter: blur(16px);
    border-bottom-color: var(--line);
}
.nav-inner {
    width: 100%; max-width: 1320px; margin: 0 auto;
    display: flex; align-items: center; justify-content: space-between;
    padding: 0 28px; gap: 24px;
}
/* Wordmark */
.nav-brand { display: flex; align-items: center; gap: 12px; flex-shrink: 0; text-decoration: none; }
.nav-brand img { height: 34px; width: auto; object-fit: contain; }
.nav-wordmark {
    font-size: 19px; font-weight: 700; letter-spacing: .34em;
    color: var(--ink); text-transform: uppercase; line-height: 1;
    transition: color .35s var(--ease);
}
.nav-overlay-mode:not(.nav-solid) .nav-wordmark { color: #fff; }

/* Links */
.nav-links { display: flex; align-items: center; gap: 4px; list-style: none; margin: 0; padding: 0; }
.nav-links > li { position: relative; }
.nav-links > li > a, .nav-links > li > button {
    display: inline-flex; align-items: center; gap: 7px;
    background: none; border: none; cursor: pointer;
    color: var(--ink); font-family: inherit;
    font-size: 12px; font-weight: 600; letter-spacing: .14em; text-transform: uppercase;
    padding: 10px 16px; transition: color .25s var(--ease);
}
.nav-overlay-mode:not(.nav-solid) .nav-links > li > a,
.nav-overlay-mode:not(.nav-solid) .nav-links > li > button { color: rgba(255,255,255,.92); }
.nav-links > li > a:hover, .nav-links > li > button:hover { color: var(--bronze); }
.nav-links .drop-caret { font-size: 9px; transition: transform .25s var(--ease); }
.nav-item-open .drop-caret { transform: rotate(180deg); }

/* Dropdown panels */
.nav-drop {
    position: absolute; top: calc(100% + 14px); left: 50%; transform: translateX(-50%) translateY(8px);
    background: #fff; border: 1px solid var(--line); border-radius: var(--r);
    box-shadow: 0 24px 64px rgba(0,0,0,.14);
    min-width: 480px; padding: 28px 30px;
    opacity: 0; visibility: hidden; pointer-events: none;
    transition: opacity .25s var(--ease), transform .25s var(--ease), visibility .25s;
}
.nav-item-open .nav-drop {
    opacity: 1; visibility: visible; pointer-events: auto;
    transform: translateX(-50%) translateY(0);
}
.nav-drop-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 8px 36px; }
.nav-drop .drop-head {
    grid-column: 1 / -1; font-size: 10.5px; font-weight: 600; text-transform: uppercase;
    letter-spacing: .2em; color: var(--ink-3); padding-bottom: 10px; margin-bottom: 6px;
    border-bottom: 1px solid var(--line);
}
.nav-drop a.drop-link {
    display: flex; align-items: center; justify-content: space-between; gap: 16px;
    font-size: 13.5px; font-weight: 500; color: var(--ink);
    padding: 8px 0; letter-spacing: .01em;
    border-bottom: 1px solid transparent; transition: color .2s var(--ease);
}
.nav-drop a.drop-link:hover { color: var(--bronze); }
.nav-drop a.drop-link .n { font-size: 11px; color: var(--ink-3); font-weight: 500; }
.nav-drop .drop-cta {
    grid-column: 1 / -1; margin-top: 14px; padding-top: 16px; border-top: 1px solid var(--line);
    display: flex; gap: 12px; flex-wrap: wrap;
}
.nav-drop .drop-cta a { font-size: 11px; letter-spacing: .16em; text-transform: uppercase; font-weight: 600; color: var(--ink); display: inline-flex; align-items: center; gap: 8px; }
.nav-drop .drop-cta a:hover { color: var(--bronze); }

/* Right cluster */
.nav-ctas { display: flex; align-items: center; gap: 14px; flex-shrink: 0; }
.nav-cta-btn {
    display: inline-flex; align-items: center; gap: 8px;
    background: var(--ink); color: #fff !important;
    border: 1px solid var(--ink); border-radius: var(--r);
    padding: 10px 20px; font-size: 11px; font-weight: 600;
    letter-spacing: .16em; text-transform: uppercase; text-decoration: none;
    transition: all .25s var(--ease);
}
.nav-cta-btn:hover { background: #000; }
.nav-overlay-mode:not(.nav-solid) .nav-cta-btn { background: #fff; color: var(--ink) !important; border-color: #fff; }
.nav-overlay-mode:not(.nav-solid) .nav-cta-btn:hover { background: rgba(255,255,255,.88); }
.nav-staff {
    color: var(--ink-3); font-size: 15px; display: inline-flex; padding: 8px;
    transition: color .25s var(--ease);
}
.nav-staff:hover { color: var(--bronze); }
.nav-overlay-mode:not(.nav-solid) .nav-staff { color: rgba(255,255,255,.7); }

/* Hamburger */
.nav-toggle { display: none; flex-direction: column; gap: 5px; cursor: pointer; padding: 8px; border: none; background: none; }
.nav-toggle span { width: 22px; height: 1.5px; background: var(--ink); transition: all .3s var(--ease); }
.nav-overlay-mode:not(.nav-solid) .nav-toggle span { background: #fff; }

/* Mobile panel */
.nav-mobile {
    display: none; position: fixed; top: var(--nav-h); left: 0; right: 0; bottom: 0;
    background: #fff; z-index: 999; overflow-y: auto; padding: 24px 28px 48px;
}
.nav-mobile.open { display: block; }
.nav-mobile .m-group { border-bottom: 1px solid var(--line); padding: 18px 0; }
.nav-mobile .m-head { font-size: 11px; font-weight: 600; letter-spacing: .2em; text-transform: uppercase; color: var(--ink-3); margin-bottom: 12px; }
.nav-mobile a { display: block; font-size: 16px; font-weight: 500; color: var(--ink); padding: 9px 0; }

@media (max-width: 1080px) {
    .nav-links-wrap { display: none; }
    .nav-toggle { display: flex; }
    .nav-cta-btn span { display: none; }
    .nav-cta-btn { padding: 10px 14px; }
}
</style>
</head>
<body>

<!-- ── Navbar ─────────────────────────────────────────────────── -->
<nav class="site-nav <?= $__navOverlay ? 'nav-overlay-mode' : '' ?>" id="siteNav">
    <div class="nav-inner">

        <a href="<?= BASE_URL ?>/showroom/" class="nav-brand" aria-label="<?= htmlspecialchars($__companyName) ?>">
            <span class="nav-wordmark">Mascardi</span>
        </a>

        <div class="nav-links-wrap">
            <ul class="nav-links">
                <li class="nav-has-drop">
                    <button type="button" aria-haspopup="true">Vehicles <i class="fa fa-chevron-down drop-caret"></i></button>
                    <div class="nav-drop">
                        <div class="nav-drop-grid">
                            <div class="drop-head">Browse by Body Type</div>
                            <?php foreach ($__navCats as $__cat => $__n): ?>
                            <a class="drop-link" href="<?= BASE_URL ?>/showroom/?body=<?= urlencode($__cat) ?>#inventory">
                                <?= htmlspecialchars($__cat) ?> <span class="n"><?= (int)$__n ?></span>
                            </a>
                            <?php endforeach; ?>
                            <?php if (!$__navCats): ?>
                            <a class="drop-link" href="<?= BASE_URL ?>/showroom/#inventory">All Vehicles</a>
                            <?php endif; ?>
                            <div class="drop-cta">
                                <a href="<?= BASE_URL ?>/showroom/#inventory">View All Inventory <i class="fa fa-arrow-right" style="font-size:10px"></i></a>
                                <a href="<?= BASE_URL ?>/showroom/?sort=newest#inventory">New Arrivals</a>
                                <a href="<?= BASE_URL ?>/showroom/compare.php">Compare</a>
                            </div>
                        </div>
                    </div>
                </li>
                <li class="nav-has-drop">
                    <button type="button" aria-haspopup="true">Ownership <i class="fa fa-chevron-down drop-caret"></i></button>
                    <div class="nav-drop" style="min-width:380px">
                        <div class="nav-drop-grid">
                            <div class="drop-head">Service &amp; Support</div>
                            <a class="drop-link" href="<?= BASE_URL ?>/showroom/book-service.php">Book a Service</a>
                            <a class="drop-link" href="<?= BASE_URL ?>/showroom/inquiry.php">Vehicle Inquiry</a>
                            <a class="drop-link" href="<?= BASE_URL ?>/client/login.php">Client Portal</a>
                            <a class="drop-link" href="<?= BASE_URL ?>/showroom/contact.php">Contact Us</a>
                            <div class="drop-cta">
                                <?php if ($__waClean): ?>
                                <a href="https://wa.me/<?= $__waClean ?>" target="_blank" rel="noopener"><i class="fa-brands fa-whatsapp"></i> WhatsApp Us</a>
                                <?php endif; ?>
                                <?php if ($__companyPhone): ?>
                                <a href="tel:<?= htmlspecialchars($__companyPhone) ?>"><i class="fa fa-phone" style="font-size:10px"></i> <?= htmlspecialchars($__companyPhone) ?></a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </li>
                <li><a href="<?= BASE_URL ?>/showroom/#story">About</a></li>
                <li><a href="<?= BASE_URL ?>/showroom/contact.php">Contact</a></li>
            </ul>
        </div>

        <div class="nav-ctas">
            <?php if ($__waClean): ?>
            <a href="https://wa.me/<?= $__waClean ?>?text=<?= urlencode('Hi, I\'d like to enquire about a vehicle.') ?>"
               target="_blank" rel="noopener" class="nav-cta-btn">
                <i class="fa-brands fa-whatsapp"></i><span>Enquire</span>
            </a>
            <?php endif; ?>
            <a href="<?= BASE_URL ?>/login.php" class="nav-staff" title="Staff Login" aria-label="Staff Login">
                <i class="fa fa-user"></i>
            </a>
            <button class="nav-toggle" id="navToggle" aria-label="Menu">
                <span></span><span></span><span></span>
            </button>
        </div>
    </div>
</nav>

<!-- Mobile menu -->
<div class="nav-mobile" id="navMobile">
    <div class="m-group">
        <div class="m-head">Vehicles</div>
        <?php foreach ($__navCats as $__cat => $__n): ?>
        <a href="<?= BASE_URL ?>/showroom/?body=<?= urlencode($__cat) ?>#inventory"><?= htmlspecialchars($__cat) ?> <span style="color:var(--ink-3);font-size:12px">(<?= (int)$__n ?>)</span></a>
        <?php endforeach; ?>
        <a href="<?= BASE_URL ?>/showroom/#inventory">View All Inventory</a>
        <a href="<?= BASE_URL ?>/showroom/compare.php">Compare</a>
    </div>
    <div class="m-group">
        <div class="m-head">Ownership</div>
        <a href="<?= BASE_URL ?>/showroom/book-service.php">Book a Service</a>
        <a href="<?= BASE_URL ?>/showroom/inquiry.php">Vehicle Inquiry</a>
        <a href="<?= BASE_URL ?>/client/login.php">Client Portal</a>
        <a href="<?= BASE_URL ?>/showroom/contact.php">Contact Us</a>
    </div>
    <div class="m-group" style="border-bottom:none">
        <a href="<?= BASE_URL ?>/showroom/#story">About</a>
        <a href="<?= BASE_URL ?>/login.php">Staff Login</a>
    </div>
</div>

<script>
(function () {
    var nav = document.getElementById('siteNav');
    var overlayMode = nav.classList.contains('nav-overlay-mode');

    // Solidify on scroll
    function onScroll() {
        nav.classList.toggle('nav-solid', window.scrollY > 40);
    }
    window.addEventListener('scroll', onScroll, { passive: true });
    onScroll();

    // Dropdowns: hover on desktop, click on touch
    document.querySelectorAll('.nav-has-drop').forEach(function (li) {
        var btn = li.querySelector('button');
        li.addEventListener('mouseenter', function () { li.classList.add('nav-item-open'); });
        li.addEventListener('mouseleave', function () { li.classList.remove('nav-item-open'); });
        btn.addEventListener('click', function (e) {
            e.stopPropagation();
            var open = li.classList.contains('nav-item-open');
            document.querySelectorAll('.nav-has-drop').forEach(function (o) { o.classList.remove('nav-item-open'); });
            if (!open) li.classList.add('nav-item-open');
        });
    });
    document.addEventListener('click', function () {
        document.querySelectorAll('.nav-has-drop').forEach(function (o) { o.classList.remove('nav-item-open'); });
    });

    // Mobile menu
    var toggle = document.getElementById('navToggle');
    var mobile = document.getElementById('navMobile');
    toggle.addEventListener('click', function () {
        var open = mobile.classList.toggle('open');
        document.body.style.overflow = open ? 'hidden' : '';
        if (open) nav.classList.add('nav-solid');
        else onScroll();
    });
}());
</script>

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
// $fullTitle (set by a page before including this file) is used verbatim, with no
// " — Company Name" suffix — used for car pages that already craft a full SEO title.
$__pageTitle = isset($fullTitle)
    ? $fullTitle
    : (isset($pageTitle) ? $pageTitle . ' — ' . $__companyName : $__companyName . ' — Quality Vehicles');

$__seoDefaultDesc  = getSetting('seo_default_description', 'Browse quality imported vehicles at ' . $__companyName . '. Finance available. Visit our showroom today.');
$__seoDefaultImage = getSetting('seo_og_image_url', '');
$__metaDescription = isset($metaDesc) && trim((string)$metaDesc) !== '' ? $metaDesc : $__seoDefaultDesc;
$__ogImageFinal    = isset($ogImage) && trim((string)$ogImage) !== '' ? $ogImage : $__seoDefaultImage;
$__ogTypeFinal     = $ogType ?? 'website';
$__canonicalUrl    = isset($canonicalUrl) ? $canonicalUrl : (rtrim(BASE_URL, '/') . strtok($_SERVER['REQUEST_URI'] ?? '/', '?'));

$__allowIndexing = getSetting('seo_allow_indexing', '1') !== '0';
$__forceNoIndex  = !empty($noIndex);
// Tolerant of the DNS form being pasted in: a value saved before the settings
// page started stripping it would otherwise render a meta tag Google rejects.
$__googleVerify  = preg_replace('/^\s*(?:google-site-verification[=:])?\s*/i', '',
                                 getSetting('seo_google_verification', ''));
$__gaId          = getSetting('seo_ga_id', '');

$__navOverlay = !empty($navOverlay);

// Vehicle makes for the nav dropdown (cheap, cached per request)
$__navMakes = [];
try {
    $__navMakes = getDB()->query("
        SELECT make, COUNT(*) AS n FROM cars
        WHERE car_type IN ('inventory','sale_on_behalf') AND show_on_website=1
          AND (status IS NULL OR status NOT IN ('delivered','sold','in_transit'))
          AND make IS NOT NULL AND make != ''
        GROUP BY make ORDER BY n DESC, make ASC LIMIT 10
    ")->fetchAll(PDO::FETCH_KEY_PAIR);
} catch (\Throwable $_) {}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<title><?= htmlspecialchars($__pageTitle) ?></title>
<meta name="description" content="<?= htmlspecialchars($__metaDescription) ?>">
<link rel="canonical" href="<?= htmlspecialchars($__canonicalUrl) ?>">
<?php if (!$__allowIndexing || $__forceNoIndex): ?>
<meta name="robots" content="noindex,nofollow">
<?php else: ?>
<meta name="robots" content="index,follow">
<?php endif; ?>
<?php if ($__googleVerify): ?>
<meta name="google-site-verification" content="<?= htmlspecialchars($__googleVerify) ?>">
<?php endif; ?>

<!-- Open Graph -->
<meta property="og:site_name"   content="<?= htmlspecialchars($__companyName) ?>">
<meta property="og:title"       content="<?= htmlspecialchars($__pageTitle) ?>">
<meta property="og:description" content="<?= htmlspecialchars($__metaDescription) ?>">
<meta property="og:type"        content="<?= htmlspecialchars($__ogTypeFinal) ?>">
<meta property="og:url"         content="<?= htmlspecialchars($__canonicalUrl) ?>">
<?php if ($__ogImageFinal): ?>
<meta property="og:image"       content="<?= htmlspecialchars($__ogImageFinal) ?>">
<?php endif; ?>

<!-- Twitter Card -->
<meta name="twitter:card"        content="<?= $__ogImageFinal ? 'summary_large_image' : 'summary' ?>">
<meta name="twitter:title"       content="<?= htmlspecialchars($__pageTitle) ?>">
<meta name="twitter:description" content="<?= htmlspecialchars($__metaDescription) ?>">
<?php if ($__ogImageFinal): ?>
<meta name="twitter:image"       content="<?= htmlspecialchars($__ogImageFinal) ?>">
<?php endif; ?>

<meta name="theme-color"        content="#0c0c0c">

<?php if (!empty($jsonLd)): ?>
<script type="application/ld+json"><?= $jsonLd ?></script>
<?php endif; ?>

<?php if ($__gaId): ?>
<!-- Google Analytics -->
<script async src="https://www.googletagmanager.com/gtag/js?id=<?= urlencode($__gaId) ?>"></script>
<script>
  window.dataLayer = window.dataLayer || [];
  function gtag(){dataLayer.push(arguments);}
  gtag('js', new Date());
  gtag('config', <?= json_encode($__gaId) ?>);
</script>
<?php endif; ?>
<script>
/* Conversion tracking. Always defined so callers never need to guard, but a
   silent no-op until a GA4 Measurement ID is set in Settings → SEO. Lets the
   pages below mark real conversions (enquiry sent, WhatsApp opened) rather than
   leaving you with pageviews only. */
window.mscTrack = function (name, params) {
    if (typeof gtag === 'function') {
        try { gtag('event', name, params || {}); } catch (e) {}
    }
};
document.addEventListener('click', function (e) {
    var a = e.target.closest && e.target.closest('a[href*="wa.me/"]');
    if (a) window.mscTrack('whatsapp_click', { link_url: a.href, page: location.pathname });
}, true);
</script>

<!-- PWA -->
<link rel="manifest"    href="<?= BASE_URL ?>/manifest.php">
<link rel="icon"        type="image/svg+xml" href="<?= BASE_URL ?>/assets/images/icons/icon.svg">
<?php /* PNG, not the SVG that was here: iOS and iPadOS ignore SVG for
         apple-touch-icon and fall back to a screenshot of the page. */ ?>
<link rel="apple-touch-icon" sizes="192x192"  href="<?= BASE_URL ?>/assets/images/icons/icon-192.png">
<link rel="apple-touch-icon" sizes="512x512"  href="<?= BASE_URL ?>/assets/images/icons/icon-512.png">
<link rel="apple-touch-icon"                  href="<?= BASE_URL ?>/assets/images/icons/icon-192.png">
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
    --paper:    #f4f4f2;   /* neutral light section bg    */
    --white:    #ffffff;
    --ink:      #111111;   /* primary text                */
    --ink-2:    #565656;   /* secondary text              */
    --ink-3:    #8a8a8a;   /* muted text                  */
    --line:     #e4e4e2;   /* hairline borders            */
    --bronze:   #6e6e6e;   /* monochrome hover accent     */
    --black:    #0a0a0a;   /* dark sections / footer      */
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

/* Shared layout + form primitives */
.lx-wrap { max-width: 1320px; margin: 0 auto; padding: 0 28px; }
.lx-flabel { display: block; font-size: 10.5px; font-weight: 600; text-transform: uppercase; letter-spacing: .16em; color: var(--ink-3); margin-bottom: 8px; }
.lx-input {
    width: 100%; border: 1px solid var(--line); border-radius: var(--r);
    padding: 10px 13px; font-size: 13.5px; font-family: inherit; color: var(--ink);
    background: var(--white); outline: none; transition: border-color .25s var(--ease);
}
.lx-input:focus { border-color: var(--ink); }
select.lx-input { cursor: pointer; }
.lx-noimg { width: 100%; height: 100%; display: flex; align-items: center; justify-content: center; font-size: 52px; color: var(--line); background: var(--paper); }

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
/* Invisible bridge across the 14px gap above the panel. Without it the pointer
   crosses dead space that belongs to neither the <li> nor the panel, firing
   mouseleave and snapping the menu shut before it can be reached. Because it is
   a child of .nav-drop it stays inside the <li> subtree, so mouseleave never
   fires while travelling from the trigger to the menu. It also inherits the
   panel's pointer-events:none while closed, so it never blocks clicks. */
.nav-drop::before {
    content: ''; position: absolute;
    left: 0; right: 0; top: -16px; height: 16px;
}
/* :focus-within keeps the panel open for keyboard/screen-reader users tabbing
   through its links — hover alone would drop them out mid-menu. */
.nav-item-open .nav-drop,
.nav-has-drop:focus-within .nav-drop {
    opacity: 1; visibility: visible; pointer-events: auto;
    transform: translateX(-50%) translateY(0);
}
.nav-has-drop:focus-within .drop-caret { transform: rotate(180deg); }
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
                            <div class="drop-head">Browse by Make</div>
                            <?php foreach ($__navMakes as $__mk => $__n): ?>
                            <a class="drop-link" href="<?= BASE_URL ?>/showroom/vehicles.php?make=<?= urlencode($__mk) ?>">
                                <?= htmlspecialchars($__mk) ?> <span class="n"><?= (int)$__n ?></span>
                            </a>
                            <?php endforeach; ?>
                            <?php if (!$__navMakes): ?>
                            <a class="drop-link" href="<?= BASE_URL ?>/showroom/vehicles.php">All Vehicles</a>
                            <?php endif; ?>
                            <div class="drop-cta">
                                <a href="<?= BASE_URL ?>/showroom/vehicles.php">View All Vehicles <i class="fa fa-arrow-right" style="font-size:10px"></i></a>
                                <a href="<?= BASE_URL ?>/showroom/in-shipment.php"><i class="fa fa-ship" style="font-size:10px"></i> In Shipment</a>
                                <a href="<?= BASE_URL ?>/showroom/vehicles.php?sort=newest">New Arrivals</a>
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
        <?php foreach ($__navMakes as $__mk => $__n): ?>
        <a href="<?= BASE_URL ?>/showroom/vehicles.php?make=<?= urlencode($__mk) ?>"><?= htmlspecialchars($__mk) ?> <span style="color:var(--ink-3);font-size:12px">(<?= (int)$__n ?>)</span></a>
        <?php endforeach; ?>
        <a href="<?= BASE_URL ?>/showroom/vehicles.php">View All Vehicles</a>
        <a href="<?= BASE_URL ?>/showroom/in-shipment.php"><i class="fa fa-ship" style="font-size:12px;width:18px"></i> In Shipment</a>
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

    // ── Dropdowns ────────────────────────────────────────────────────────────
    // Hover-intent: opening is instant, but closing is deferred so a brief
    // overshoot, a diagonal move toward a far link, or a wobble on the way down
    // no longer snaps the menu shut mid-selection. Any re-entry cancels the
    // pending close.
    var HOVER_CLOSE_DELAY = 400;   // ms of grace after the pointer leaves
    var dropItems = document.querySelectorAll('.nav-has-drop');

    // Only wire hover on real pointing devices. On touch, mouseenter is
    // synthesised alongside the tap and would fight the click toggle.
    var canHover = !window.matchMedia
                || window.matchMedia('(hover: hover) and (pointer: fine)').matches;

    function closeAll(except) {
        dropItems.forEach(function (o) {
            if (o !== except) {
                o.classList.remove('nav-item-open');
                if (o._closeTimer) { clearTimeout(o._closeTimer); o._closeTimer = null; }
            }
        });
    }

    dropItems.forEach(function (li) {
        var btn = li.querySelector('button');
        li._closeTimer = null;

        function openNow() {
            clearTimeout(li._closeTimer);
            li._closeTimer = null;
            closeAll(li);
            li.classList.add('nav-item-open');
        }
        function closeSoon() {
            clearTimeout(li._closeTimer);
            li._closeTimer = setTimeout(function () {
                li.classList.remove('nav-item-open');
                li._closeTimer = null;
            }, HOVER_CLOSE_DELAY);
        }

        if (canHover) {
            li.addEventListener('mouseenter', openNow);
            li.addEventListener('mouseleave', closeSoon);
            // Re-entering anywhere in the panel cancels a pending close.
            var panel = li.querySelector('.nav-drop');
            if (panel) panel.addEventListener('mouseenter', function () {
                clearTimeout(li._closeTimer);
                li._closeTimer = null;
            });
        }

        // Tap / click still toggles — the only interaction on touch devices.
        btn.addEventListener('click', function (e) {
            e.stopPropagation();
            var isOpen = li.classList.contains('nav-item-open');
            closeAll();
            if (!isOpen) { clearTimeout(li._closeTimer); li.classList.add('nav-item-open'); }
        });

        // Keyboard: Escape closes and returns focus to the trigger.
        li.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && li.classList.contains('nav-item-open')) {
                li.classList.remove('nav-item-open');
                btn.focus();
            }
        });
    });

    document.addEventListener('click', function () { closeAll(); });

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

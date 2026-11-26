<!-- ── Footer ────────────────────────────────────────────── -->
<footer id="contact" style="background:var(--black);color:rgba(255,255,255,.55);padding:84px 0 0">
    <div class="lx-wrap">
        <div class="row g-5 mb-5">

            <!-- Brand column -->
            <div class="col-lg-4">
                <div style="font-size:20px;font-weight:700;letter-spacing:.34em;color:#fff;text-transform:uppercase;margin-bottom:22px">
                    Mascardi
                </div>
                <p style="line-height:1.8;font-size:13.5px;margin:0 0 28px;max-width:340px;color:rgba(255,255,255,.5)">
                    Your trusted destination for quality imported vehicles. Transparent pricing,
                    flexible financing, and an unmatched selection of cars for every lifestyle.
                </p>
                <div style="display:flex;gap:10px">
                    <?php if ($__waClean): ?>
                    <a href="https://wa.me/<?= $__waClean ?>" target="_blank" rel="noopener" class="ft-icon" aria-label="WhatsApp">
                        <i class="fa-brands fa-whatsapp"></i>
                    </a>
                    <?php endif; ?>
                    <?php if ($__companyPhone): ?>
                    <a href="tel:<?= htmlspecialchars($__companyPhone) ?>" class="ft-icon" aria-label="Phone">
                        <i class="fa fa-phone"></i>
                    </a>
                    <?php endif; ?>
                    <?php if ($__companyEmail): ?>
                    <a href="mailto:<?= htmlspecialchars($__companyEmail) ?>" class="ft-icon" aria-label="Email">
                        <i class="fa fa-envelope"></i>
                    </a>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Vehicles -->
            <div class="col-sm-6 col-lg-2">
                <div class="ft-head">Vehicles</div>
                <?php foreach ([
                    ['All Vehicles',   BASE_URL . '/showroom/vehicles.php'],
                    ['In Shipment',    BASE_URL . '/showroom/in-shipment.php'],
                    ['New Arrivals',   BASE_URL . '/showroom/vehicles.php?sort=newest'],
                    ['Current Offers', BASE_URL . '/showroom/vehicles.php?sort=price_asc'],
                    ['Compare',        BASE_URL . '/showroom/compare.php'],
                ] as [$lbl, $url]): ?>
                <a class="ft-link" href="<?= $url ?>"><?= $lbl ?></a>
                <?php endforeach; ?>
            </div>

            <!-- Ownership -->
            <div class="col-sm-6 col-lg-2">
                <div class="ft-head">Ownership</div>
                <?php foreach ([
                    ['Book a Service',  BASE_URL . '/showroom/book-service.php'],
                    ['Vehicle Inquiry', BASE_URL . '/showroom/contact.php'],
                    ['Client Portal',   BASE_URL . '/client/login.php'],
                    ['Contact Us',      BASE_URL . '/showroom/contact.php'],
                    ['Staff Login',     BASE_URL . '/login.php'],
                ] as [$lbl, $url]): ?>
                <a class="ft-link" href="<?= $url ?>"><?= $lbl ?></a>
                <?php endforeach; ?>
            </div>

            <!-- Contact info -->
            <div class="col-sm-6 col-lg-4">
                <div class="ft-head">Visit Us</div>
                <div style="display:flex;flex-direction:column;gap:16px;font-size:13.5px">
                    <?php if ($__address): ?>
                    <div style="line-height:1.7;color:rgba(255,255,255,.7)"><?= htmlspecialchars($__address) ?></div>
                    <?php endif; ?>
                    <div style="color:rgba(255,255,255,.45)">Mon – Sat · 8:00 AM – 6:00 PM</div>
                    <?php if ($__companyPhone): ?>
                    <a href="tel:<?= htmlspecialchars($__companyPhone) ?>" style="color:#fff;font-weight:500"><?= htmlspecialchars($__companyPhone) ?></a>
                    <?php endif; ?>
                    <?php if ($__companyEmail): ?>
                    <a href="mailto:<?= htmlspecialchars($__companyEmail) ?>" style="color:rgba(255,255,255,.7)"><?= htmlspecialchars($__companyEmail) ?></a>
                    <?php endif; ?>
                </div>
            </div>

        </div>
    </div>

    <!-- Bottom bar -->
    <div style="border-top:1px solid rgba(255,255,255,.08);padding:24px 0">
        <div class="lx-wrap d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div style="font-size:12px;color:rgba(255,255,255,.3);letter-spacing:.04em">
                &copy; <?= date('Y') ?> <?= htmlspecialchars($__companyName) ?>. All rights reserved.
            </div>
            <div style="font-size:11px;color:rgba(255,255,255,.2);letter-spacing:.1em;text-transform:uppercase">
                Powered by Mascardi System
            </div>
        </div>
    </div>
</footer>

<style>
.ft-head {
    font-size: 10.5px; font-weight: 600; text-transform: uppercase;
    letter-spacing: .2em; color: rgba(255,255,255,.35); margin-bottom: 18px;
}
.ft-link {
    display: block; color: rgba(255,255,255,.62); font-size: 13.5px; font-weight: 400;
    padding: 5px 0; transition: color .25s var(--ease);
}
.ft-link:hover { color: #fff; }
.ft-icon {
    width: 40px; height: 40px; border: 1px solid rgba(255,255,255,.2); border-radius: var(--r);
    display: flex; align-items: center; justify-content: center;
    color: rgba(255,255,255,.75); font-size: 16px; transition: all .25s var(--ease);
}
.ft-icon:hover { background: #fff; color: var(--ink); border-color: #fff; }
</style>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<?php if (isset($extraJs)) echo $extraJs; ?>

<?php
/* ── The bottom tab bar ──────────────────────────────────────────────────
   Phones only. Four destinations, which is as many as stays legible at this
   width, and all four are places rather than actions -- the buttons that act
   on a vehicle belong on that vehicle's page.

   $__tab marks the current one so the bar says where you are, which is most of
   what makes a tab bar feel like an app rather than a row of links. */
$__here = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?? '';
$__isSaved = !empty($_GET['fav']);
/* A page may name its own tab. A vehicle's address is /make/MSC-1200,
   which carries nothing to recognise it by, so guessing from the path
   put the bar on Home while looking at a car. */
$__tab = $showroomTab ?? 'home';
if ($__tab === 'home') {
if (str_contains($__here, '/vehicles.php') || str_contains($__here, '/in-shipment.php')) {
    $__tab = $__isSaved ? 'saved' : 'cars';
} elseif (str_contains($__here, '/contact.php') || str_contains($__here, '/book-service.php')
          || str_contains($__here, '/inquiry.php')) {
    $__tab = 'contact';
} elseif (str_contains($__here, '/compare.php') || str_contains($__here, '/view.php')) {
    $__tab = 'cars';
}
}
$__vehicles = rtrim(BASE_URL, '/') . '/showroom/vehicles.php';
?>
<nav class="mtab" id="mTabBar" aria-label="Quick navigation">
    <a class="mtab-i<?= $__tab === 'home' ? ' on' : '' ?>" href="<?= rtrim(BASE_URL, '/') ?>/showroom/">
        <i class="fa fa-house"></i><span>Home</span>
    </a>
    <a class="mtab-i<?= $__tab === 'cars' ? ' on' : '' ?>" href="<?= e($__vehicles) ?>">
        <i class="fa fa-car-side"></i><span>Vehicles</span>
    </a>
    <a class="mtab-i<?= $__tab === 'saved' ? ' on' : '' ?>" href="<?= e($__vehicles) ?>" id="mTabSaved">
        <i class="fa fa-heart"></i><span>Saved</span>
        <b class="mtab-n" id="mTabSavedCount" hidden>0</b>
    </a>
    <a class="mtab-i<?= $__tab === 'contact' ? ' on' : '' ?>" href="<?= rtrim(BASE_URL, '/') ?>/showroom/contact.php">
        <i class="fa fa-comment-dots"></i><span>Contact</span>
    </a>
</nav>
<style>
/* The bar reserves its own height on the body, so nothing is hidden behind it
   and the page does not jump when it appears. */
.mtab { display: none; }
@media (max-width: 768px) {
    body { padding-bottom: calc(60px + env(safe-area-inset-bottom, 0px)); }
    .mtab {
        display: grid; grid-template-columns: repeat(4, 1fr);
        position: fixed; left: 0; right: 0; bottom: 0;
        /* Below the mobile menu (999), so opening the menu covers this rather
           than leaving two navigations on screen at once. */
        z-index: 900;
        background: rgba(255,255,255,.96);
        -webkit-backdrop-filter: saturate(180%) blur(12px);
        backdrop-filter: saturate(180%) blur(12px);
        border-top: 1px solid var(--line);
        padding-bottom: env(safe-area-inset-bottom, 0px);
    }
    .mtab-i {
        position: relative;
        display: flex; flex-direction: column; align-items: center; justify-content: center;
        gap: 4px; height: 56px;                 /* past the 44px a thumb needs */
        text-decoration: none; color: var(--ink-3);
        -webkit-tap-highlight-color: transparent;
        transition: color .18s var(--ease);
    }
    .mtab-i i    { font-size: 17px; line-height: 1; }
    .mtab-i span { font-size: 10px; font-weight: 600; letter-spacing: .04em; }
    .mtab-i.on   { color: var(--ink); }
    /* The marker along the top edge, which is what says "you are here" without
       another colour fighting the icon. */
    .mtab-i.on::before {
        content: ''; position: absolute; top: -1px; left: 50%; transform: translateX(-50%);
        width: 26px; height: 2px; background: var(--ink); border-radius: 0 0 2px 2px;
    }
    .mtab-i:active { color: var(--ink); }
    .mtab-n {
        position: absolute; top: 6px; left: 50%; margin-left: 4px;
        min-width: 15px; height: 15px; padding: 0 4px;
        background: var(--ink); color: #fff;
        border-radius: 8px; font-size: 9px; font-weight: 700; line-height: 15px;
        text-align: center;
    }
    @media (prefers-reduced-motion: reduce) { .mtab-i { transition: none; } }

    /* The install banner is pinned to bottom:0 as well, and higher in the
       stack, so it would sit on top of the bar and bury it. It is lifted to
       rest on the bar instead. !important because that banner carries its
       position in a style attribute, which a stylesheet cannot otherwise
       outrank. */
    #pwaInstallBanner { bottom: calc(60px + env(safe-area-inset-bottom, 0px)) !important; }
}
</style>
<script>
(function () {
    /* Saved lives in the visitor's own browser, and the listing filters on
       ?fav=<ids>, so the link cannot be finished on the server. The count goes
       on the tab as well -- a number you can see without opening it is most of
       why the tab is worth having. */
    var tab = document.getElementById('mTabSaved');
    if (!tab) return;
    var ids = [];
    try { ids = JSON.parse(localStorage.getItem('msc_favs') || '[]') || []; } catch (e) { ids = []; }
    if (ids.length) {
        tab.href += (tab.href.indexOf('?') === -1 ? '?' : '&') + 'fav=' + ids.join(',');
        var n = document.getElementById('mTabSavedCount');
        if (n) { n.textContent = ids.length > 99 ? '99+' : ids.length; n.hidden = false; }
    }
}());
</script>

<!-- ── PWA Install Banner ─────────────────────────────────────── -->
<div id="pwaInstallBanner" style="
    display:none;
    position:fixed;
    bottom:0; left:0; right:0;
    z-index:9998;
    padding:0 16px;
    padding-bottom:calc(12px + env(safe-area-inset-bottom,0px));
    transform:translateY(110%);
    transition:transform .4s cubic-bezier(.22,.61,.36,1);
    pointer-events:none;
">
    <div style="
        display:flex; align-items:center; gap:12px;
        background:#fff; border:1px solid var(--line);
        border-radius:2px 2px 0 0;
        box-shadow:0 -8px 32px rgba(0,0,0,.12);
        padding:14px 18px;
        max-width:560px; margin:0 auto;
        pointer-events:all;
    ">
        <img src="<?= BASE_URL ?>/assets/images/icons/icon.svg" width="40" height="40"
             style="border-radius:2px;flex-shrink:0" alt="App icon">
        <div style="flex:1;min-width:0">
            <div style="font-size:13.5px;font-weight:600;color:var(--ink)"><?= htmlspecialchars($__companyName) ?></div>
            <div style="font-size:12px;color:var(--ink-3)">Install for faster access &amp; offline browsing</div>
        </div>
        <button id="pwaInstallBtn" style="
            flex-shrink:0; background:var(--ink); color:#fff; border:none;
            border-radius:2px; padding:10px 18px; font-size:11px; letter-spacing:.12em;
            text-transform:uppercase; font-weight:600; cursor:pointer; font-family:inherit;
        ">
            Install
        </button>
        <button id="pwaInstallDismiss" style="
            flex-shrink:0; width:30px; height:30px; border-radius:2px;
            border:1px solid var(--line); background:#fff; color:var(--ink-3);
            cursor:pointer; display:flex; align-items:center; justify-content:center;
            font-size:14px;
        " aria-label="Dismiss">✕</button>
    </div>
</div>

<script>
(function () {
    // ── Service Worker ────────────────────────────────────────────
    if ('serviceWorker' in navigator) {
        window.addEventListener('load', function () {
            navigator.serviceWorker
                .register('<?= BASE_URL ?>/sw.js')
                .catch(function (e) { console.warn('[SW] Registration failed:', e); });
        });
    }

    // ── Install prompt ────────────────────────────────────────────
    var deferred = null;
    var banner   = document.getElementById('pwaInstallBanner');
    var btnInst  = document.getElementById('pwaInstallBtn');
    var btnDism  = document.getElementById('pwaInstallDismiss');
    var DISMISS_KEY = 'pwa_showroom_dismissed';

    function isDismissed() {
        var ts = localStorage.getItem(DISMISS_KEY);
        return ts && (Date.now() - parseInt(ts, 10)) < 7 * 86400000;
    }
    function showBanner() {
        if (!banner || isDismissed()) return;
        banner.style.display = '';
        setTimeout(function () { banner.style.transform = 'translateY(0)'; }, 50);
    }
    function hideBanner(persist) {
        if (!banner) return;
        banner.style.transform = 'translateY(110%)';
        setTimeout(function () { banner.style.display = 'none'; }, 420);
        if (persist) localStorage.setItem(DISMISS_KEY, String(Date.now()));
    }

    window.addEventListener('beforeinstallprompt', function (e) {
        e.preventDefault();
        deferred = e;
        showBanner();
    });

    btnInst && btnInst.addEventListener('click', function () {
        hideBanner(false);
        if (!deferred) return;
        deferred.prompt();
        deferred.userChoice.then(function (r) { deferred = null; });
    });

    btnDism && btnDism.addEventListener('click', function () { hideBanner(true); });

    window.addEventListener('appinstalled', function () { hideBanner(true); });
}());
</script>

</body>
</html>

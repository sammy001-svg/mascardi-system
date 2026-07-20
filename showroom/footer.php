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
                    ['All Inventory',  BASE_URL . '/showroom/#inventory'],
                    ['New Arrivals',   BASE_URL . '/showroom/?sort=newest#inventory'],
                    ['Current Offers', BASE_URL . '/showroom/?sort=price_asc#inventory'],
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
                    ['Vehicle Inquiry', BASE_URL . '/showroom/inquiry.php'],
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

<!-- ── Floating WhatsApp Button ──────────────────────────────── -->
<?php if ($__waClean): ?>
<style>
.fab-wa {
    position: fixed;
    bottom: 28px;
    right: 28px;
    width: 56px;
    height: 56px;
    background: var(--black);
    color: #fff;
    border: 1px solid rgba(255,255,255,.15);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 26px;
    box-shadow: 0 8px 28px rgba(0,0,0,.35);
    z-index: 9997;
    text-decoration: none;
    transition: transform .25s var(--ease), background .25s var(--ease);
}
.fab-wa:hover {
    transform: scale(1.08);
    background: #25d366;
    color: #fff;
    text-decoration: none;
}
.fab-wa-tooltip {
    position: absolute;
    right: calc(100% + 12px);
    top: 50%;
    background: var(--black);
    color: #fff;
    font-size: 11px;
    font-weight: 600;
    letter-spacing: .1em;
    text-transform: uppercase;
    white-space: nowrap;
    padding: 8px 14px;
    border-radius: var(--r);
    opacity: 0;
    pointer-events: none;
    transition: opacity .25s var(--ease), transform .25s var(--ease);
    transform: translateY(-50%) translateX(6px);
}
.fab-wa:hover .fab-wa-tooltip {
    opacity: 1;
    transform: translateY(-50%) translateX(0);
}
@media (max-width: 576px) {
    .fab-wa { bottom: 20px; right: 20px; width: 52px; height: 52px; font-size: 24px; }
    .fab-wa-tooltip { display: none; }
}
</style>

<a href="https://wa.me/<?= $__waClean ?>?text=<?= urlencode('Hi, I\'d like to enquire about a vehicle from your showroom.') ?>"
   class="fab-wa" target="_blank" rel="noopener" aria-label="Chat on WhatsApp">
    <span class="fab-wa-tooltip">Chat with us</span>
    <i class="fa-brands fa-whatsapp"></i>
</a>
<?php endif; ?>

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

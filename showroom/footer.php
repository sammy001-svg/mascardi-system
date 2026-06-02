<!-- ── Footer ────────────────────────────────────────────── -->
<footer id="contact" style="background:var(--navy);color:rgba(255,255,255,.6);padding:72px 0 0">
    <div class="container-xl">
        <div class="row g-5 mb-5">

            <!-- Brand column -->
            <div class="col-lg-4">
                <div style="display:flex;align-items:center;gap:12px;margin-bottom:20px">
                    <?php if ($__logoSrc): ?>
                    <img src="<?= htmlspecialchars($__logoSrc) ?>" width="44" height="44"
                         style="border-radius:10px;object-fit:contain" alt="">
                    <?php else: ?>
                    <div style="width:44px;height:44px;border-radius:10px;background:linear-gradient(135deg,#3b82f6,#1d4ed8);display:flex;align-items:center;justify-content:center;color:#fff;font-size:20px">
                        <i class="fa fa-car-side"></i>
                    </div>
                    <?php endif; ?>
                    <div>
                        <div style="font-size:18px;font-weight:800;color:#fff;letter-spacing:-.3px"><?= htmlspecialchars($__companyName) ?></div>
                        <div style="font-size:11px;color:rgba(255,255,255,.35);font-weight:500">Official Car Showroom</div>
                    </div>
                </div>
                <p style="line-height:1.75;font-size:14px;margin:0 0 24px">
                    Your trusted destination for quality imported vehicles. We offer transparent pricing, flexible financing, and an unmatched selection of cars for every lifestyle.
                </p>
                <!-- Social / contact icons -->
                <div style="display:flex;gap:10px">
                    <?php if ($__waClean): ?>
                    <a href="https://wa.me/<?= $__waClean ?>" target="_blank" rel="noopener"
                       style="width:40px;height:40px;border-radius:10px;background:#25d366;display:flex;align-items:center;justify-content:center;color:#fff;font-size:18px;text-decoration:none;transition:transform .15s"
                       onmouseover="this.style.transform='scale(1.1)'" onmouseout="this.style.transform='scale(1)'">
                        <i class="fa-brands fa-whatsapp"></i>
                    </a>
                    <?php endif; ?>
                    <?php if ($__companyPhone): ?>
                    <a href="tel:<?= htmlspecialchars($__companyPhone) ?>"
                       style="width:40px;height:40px;border-radius:10px;background:rgba(255,255,255,.08);display:flex;align-items:center;justify-content:center;color:rgba(255,255,255,.7);font-size:16px;text-decoration:none;transition:background .15s"
                       onmouseover="this.style.background='rgba(255,255,255,.15)'" onmouseout="this.style.background='rgba(255,255,255,.08)'">
                        <i class="fa fa-phone"></i>
                    </a>
                    <?php endif; ?>
                    <?php if ($__companyEmail): ?>
                    <a href="mailto:<?= htmlspecialchars($__companyEmail) ?>"
                       style="width:40px;height:40px;border-radius:10px;background:rgba(255,255,255,.08);display:flex;align-items:center;justify-content:center;color:rgba(255,255,255,.7);font-size:16px;text-decoration:none;transition:background .15s"
                       onmouseover="this.style.background='rgba(255,255,255,.15)'" onmouseout="this.style.background='rgba(255,255,255,.08)'">
                        <i class="fa fa-envelope"></i>
                    </a>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Quick links -->
            <div class="col-sm-6 col-lg-2">
                <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:1.5px;color:rgba(255,255,255,.3);margin-bottom:16px">Quick Links</div>
                <?php foreach ([
                    ['Home',        BASE_URL . '/showroom/'],
                    ['All Cars',    BASE_URL . '/showroom/#inventory'],
                    ['Categories',  BASE_URL . '/showroom/#categories'],
                    ['About Us',    BASE_URL . '/showroom/#why-us'],
                    ['Contact',     BASE_URL . '/showroom/#contact'],
                    ['Staff Login', BASE_URL . '/login.php'],
                ] as [$lbl, $url]): ?>
                <div style="margin-bottom:10px">
                    <a href="<?= $url ?>" style="color:rgba(255,255,255,.55);font-size:14px;font-weight:500;text-decoration:none;transition:color .15s"
                       onmouseover="this.style.color='#fff'" onmouseout="this.style.color='rgba(255,255,255,.55)'">
                        <?= $lbl ?>
                    </a>
                </div>
                <?php endforeach; ?>
            </div>

            <!-- Contact info -->
            <div class="col-sm-6 col-lg-3">
                <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:1.5px;color:rgba(255,255,255,.3);margin-bottom:16px">Contact Us</div>
                <div style="display:flex;flex-direction:column;gap:14px;font-size:14px">
                    <?php if ($__companyPhone): ?>
                    <div style="display:flex;gap:12px;align-items:flex-start">
                        <div style="width:32px;height:32px;border-radius:8px;background:rgba(37,99,235,.2);display:flex;align-items:center;justify-content:center;color:#60a5fa;flex-shrink:0">
                            <i class="fa fa-phone" style="font-size:13px"></i>
                        </div>
                        <div>
                            <div style="color:rgba(255,255,255,.35);font-size:11px;margin-bottom:2px">Phone</div>
                            <a href="tel:<?= htmlspecialchars($__companyPhone) ?>" style="color:rgba(255,255,255,.8);font-weight:600;text-decoration:none"><?= htmlspecialchars($__companyPhone) ?></a>
                        </div>
                    </div>
                    <?php endif; ?>
                    <?php if ($__companyEmail): ?>
                    <div style="display:flex;gap:12px;align-items:flex-start">
                        <div style="width:32px;height:32px;border-radius:8px;background:rgba(37,99,235,.2);display:flex;align-items:center;justify-content:center;color:#60a5fa;flex-shrink:0">
                            <i class="fa fa-envelope" style="font-size:13px"></i>
                        </div>
                        <div>
                            <div style="color:rgba(255,255,255,.35);font-size:11px;margin-bottom:2px">Email</div>
                            <a href="mailto:<?= htmlspecialchars($__companyEmail) ?>" style="color:rgba(255,255,255,.8);font-weight:600;text-decoration:none"><?= htmlspecialchars($__companyEmail) ?></a>
                        </div>
                    </div>
                    <?php endif; ?>
                    <?php if ($__address): ?>
                    <div style="display:flex;gap:12px;align-items:flex-start">
                        <div style="width:32px;height:32px;border-radius:8px;background:rgba(37,99,235,.2);display:flex;align-items:center;justify-content:center;color:#60a5fa;flex-shrink:0;margin-top:2px">
                            <i class="fa fa-location-dot" style="font-size:13px"></i>
                        </div>
                        <div>
                            <div style="color:rgba(255,255,255,.35);font-size:11px;margin-bottom:2px">Location</div>
                            <div style="color:rgba(255,255,255,.8);font-weight:500;line-height:1.5"><?= htmlspecialchars($__address) ?></div>
                        </div>
                    </div>
                    <?php endif; ?>
                    <div style="display:flex;gap:12px;align-items:flex-start">
                        <div style="width:32px;height:32px;border-radius:8px;background:rgba(37,99,235,.2);display:flex;align-items:center;justify-content:center;color:#60a5fa;flex-shrink:0">
                            <i class="fa fa-clock" style="font-size:13px"></i>
                        </div>
                        <div>
                            <div style="color:rgba(255,255,255,.35);font-size:11px;margin-bottom:2px">Working Hours</div>
                            <div style="color:rgba(255,255,255,.8);font-weight:500">Mon – Sat: 8:00 AM – 6:00 PM</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- WhatsApp CTA column -->
            <div class="col-lg-3">
                <div style="font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:1.5px;color:rgba(255,255,255,.3);margin-bottom:16px">Get In Touch</div>
                <?php if ($__waClean): ?>
                <a href="https://wa.me/<?= $__waClean ?>" target="_blank" rel="noopener"
                   style="display:flex;align-items:center;gap:12px;background:#25d366;border-radius:14px;padding:18px 20px;text-decoration:none;margin-bottom:12px;transition:transform .15s,box-shadow .15s;box-shadow:0 4px 20px rgba(37,211,102,.25)"
                   onmouseover="this.style.transform='translateY(-2px)';this.style.boxShadow='0 8px 28px rgba(37,211,102,.35)'"
                   onmouseout="this.style.transform='translateY(0)';this.style.boxShadow='0 4px 20px rgba(37,211,102,.25)'">
                    <i class="fa-brands fa-whatsapp" style="font-size:28px;color:#fff"></i>
                    <div>
                        <div style="font-weight:800;color:#fff;font-size:14px">Chat on WhatsApp</div>
                        <div style="color:rgba(255,255,255,.75);font-size:12px">Instant response</div>
                    </div>
                </a>
                <?php endif; ?>
                <?php if ($__companyPhone): ?>
                <a href="tel:<?= htmlspecialchars($__companyPhone) ?>"
                   style="display:flex;align-items:center;gap:12px;background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.1);border-radius:14px;padding:16px 20px;text-decoration:none;transition:background .15s"
                   onmouseover="this.style.background='rgba(255,255,255,.1)'" onmouseout="this.style.background='rgba(255,255,255,.06)'">
                    <i class="fa fa-phone" style="font-size:20px;color:#60a5fa"></i>
                    <div>
                        <div style="font-weight:700;color:#fff;font-size:14px"><?= htmlspecialchars($__companyPhone) ?></div>
                        <div style="color:rgba(255,255,255,.45);font-size:12px">Call us anytime</div>
                    </div>
                </a>
                <?php endif; ?>
            </div>

        </div>
    </div>

    <!-- Bottom bar -->
    <div style="border-top:1px solid rgba(255,255,255,.06);padding:20px 0;margin-top:0">
        <div class="container-xl d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div style="font-size:13px;color:rgba(255,255,255,.3)">
                &copy; <?= date('Y') ?> <?= htmlspecialchars($__companyName) ?>. All rights reserved.
            </div>
            <div style="font-size:12px;color:rgba(255,255,255,.2)">
                Powered by Mascardi System
            </div>
        </div>
    </div>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<?php if (isset($extraJs)) echo $extraJs; ?>

<!-- ── Floating WhatsApp Button ──────────────────────────────── -->
<?php if ($__waClean): ?>
<style>
.fab-wa {
    position: fixed;
    bottom: 28px;
    right: 28px;
    width: 60px;
    height: 60px;
    background: #25d366;
    color: #fff;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 30px;
    box-shadow: 0 4px 20px rgba(37,211,102,.45);
    z-index: 9997;
    text-decoration: none;
    transition: transform .2s, box-shadow .2s;
}
.fab-wa:hover {
    transform: scale(1.12);
    box-shadow: 0 8px 32px rgba(37,211,102,.65);
    color: #fff;
    text-decoration: none;
}
.fab-wa::before {
    content: '';
    position: absolute;
    inset: -6px;
    border-radius: 50%;
    background: rgba(37,211,102,.2);
    animation: waPing 2s ease-out infinite;
}
@keyframes waPing {
    0%   { transform: scale(1);   opacity: .6; }
    70%  { transform: scale(1.5); opacity: 0;  }
    100% { transform: scale(1.5); opacity: 0;  }
}
.fab-wa-tooltip {
    position: absolute;
    right: calc(100% + 12px);
    top: 50%;
    transform: translateY(-50%);
    background: #0f172a;
    color: #fff;
    font-size: 13px;
    font-weight: 700;
    white-space: nowrap;
    padding: 7px 14px;
    border-radius: 8px;
    box-shadow: 0 4px 14px rgba(0,0,0,.25);
    opacity: 0;
    pointer-events: none;
    transition: opacity .2s, transform .2s;
    transform: translateY(-50%) translateX(6px);
    font-family: 'Inter', sans-serif;
}
.fab-wa-tooltip::after {
    content: '';
    position: absolute;
    left: 100%;
    top: 50%;
    transform: translateY(-50%);
    border: 6px solid transparent;
    border-left-color: #0f172a;
}
.fab-wa:hover .fab-wa-tooltip {
    opacity: 1;
    transform: translateY(-50%) translateX(0);
}
@media (max-width: 576px) {
    .fab-wa { bottom: 20px; right: 20px; width: 54px; height: 54px; font-size: 26px; }
    .fab-wa-tooltip { display: none; }
}
</style>

<a href="https://wa.me/<?= $__waClean ?>?text=<?= urlencode('Hi, I\'d like to enquire about a vehicle from your showroom.') ?>"
   class="fab-wa" target="_blank" rel="noopener" aria-label="Chat on WhatsApp">
    <span class="fab-wa-tooltip">Chat with us!</span>
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
        background:#fff; border:1px solid #e2e8f0;
        border-radius:16px 16px 0 0;
        box-shadow:0 -8px 32px rgba(0,0,0,.12);
        padding:14px 18px;
        max-width:560px; margin:0 auto;
        pointer-events:all;
    ">
        <img src="<?= BASE_URL ?>/assets/images/icons/icon.svg" width="40" height="40"
             style="border-radius:10px;flex-shrink:0" alt="App icon">
        <div style="flex:1;min-width:0">
            <div style="font-size:13.5px;font-weight:700;color:#0f172a"><?= htmlspecialchars($__companyName) ?></div>
            <div style="font-size:12px;color:#94a3b8">Install for faster access &amp; offline browsing</div>
        </div>
        <button id="pwaInstallBtn" style="
            flex-shrink:0; background:#2563eb; color:#fff; border:none;
            border-radius:10px; padding:9px 18px; font-size:13px;
            font-weight:700; cursor:pointer; font-family:inherit;
        ">
            <i class="fa fa-download me-1"></i>Install
        </button>
        <button id="pwaInstallDismiss" style="
            flex-shrink:0; width:30px; height:30px; border-radius:50%;
            border:1px solid #e2e8f0; background:#f8fafc; color:#94a3b8;
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

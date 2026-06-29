</div><!-- /page-body -->
</div><!-- /main-wrap -->
</div><!-- /appShell -->

<!-- Toast notification stack -->
<div id="toastStack" class="toast-stack"></div>

<!-- ── Floating WhatsApp ──────────────────────────────────────── -->
<?php
$__wa = preg_replace('/[^0-9]/', '', getSetting('whatsapp_number', getSetting('company_phone', '')));
if ($__wa): ?>
<style>
.fab-wa{position:fixed;bottom:28px;right:28px;width:56px;height:56px;background:#25d366;color:#fff;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:27px;box-shadow:0 4px 20px rgba(37,211,102,.45);z-index:9000;text-decoration:none;transition:transform .2s,box-shadow .2s}
.fab-wa::before{content:'';position:absolute;inset:-6px;border-radius:50%;background:rgba(37,211,102,.2);animation:waPing 2s ease-out infinite}
@keyframes waPing{0%{transform:scale(1);opacity:.6}70%,100%{transform:scale(1.5);opacity:0}}
.fab-wa:hover{transform:scale(1.12);box-shadow:0 8px 32px rgba(37,211,102,.65);color:#fff;text-decoration:none}
.fab-wa-tip{position:absolute;right:calc(100% + 12px);top:50%;transform:translateY(-50%) translateX(6px);background:#0f172a;color:#fff;font-size:12.5px;font-weight:700;white-space:nowrap;padding:6px 12px;border-radius:8px;opacity:0;pointer-events:none;transition:opacity .2s,transform .2s}
.fab-wa-tip::after{content:'';position:absolute;left:100%;top:50%;transform:translateY(-50%);border:5px solid transparent;border-left-color:#0f172a}
.fab-wa:hover .fab-wa-tip{opacity:1;transform:translateY(-50%) translateX(0)}
@media(max-width:576px){.fab-wa{bottom:80px;right:16px;width:50px;height:50px;font-size:24px}.fab-wa-tip{display:none}}
</style>
<a href="https://wa.me/<?= $__wa ?>?text=<?= urlencode('Hi, I have a question about the Mascardi Car Yard system.') ?>"
   class="fab-wa" target="_blank" rel="noopener" aria-label="WhatsApp">
    <span class="fab-wa-tip">WhatsApp Us</span>
    <i class="fa-brands fa-whatsapp"></i>
</a>
<?php endif; ?>

<!-- ── Floating Chat Button ───────────────────────────────────────────── -->
<?php if (canAccess('chat') && !str_contains($_SERVER['REQUEST_URI'], '/modules/chat/')): ?>
<style>
.fab-chat{position:fixed;bottom:90px;right:28px;width:52px;height:52px;background:#128c7e;color:#fff;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:22px;box-shadow:0 4px 18px rgba(18,140,126,.5);z-index:8900;text-decoration:none;transition:transform .2s,box-shadow .2s}
.fab-chat:hover{transform:scale(1.1);box-shadow:0 8px 28px rgba(18,140,126,.7);color:#fff;text-decoration:none}
.fab-chat-badge{position:absolute;top:-4px;right:-4px;background:#ef4444;color:#fff;border-radius:50%;font-size:10px;font-weight:700;min-width:18px;height:18px;line-height:18px;text-align:center;padding:0 3px;border:2px solid #fff;display:none}
@media(max-width:576px){.fab-chat{bottom:140px;right:16px;width:46px;height:46px;font-size:20px}}
</style>
<a href="<?= BASE_URL ?>/modules/chat/index.php" class="fab-chat" id="fabChat" title="Team Chat">
    <i class="fa fa-comments"></i>
    <span class="fab-chat-badge" id="fabChatBadge"></span>
</a>
<script>
(function(){
    var badge = document.getElementById('fabChatBadge');
    if (!badge) return;
    function poll(){
        fetch('<?= BASE_URL ?>/modules/chat/api/unread.php')
            .then(function(r){ return r.json(); })
            .then(function(d){
                var n = d.count || 0;
                if (n > 0) { badge.textContent = n > 99 ? '99+' : n; badge.style.display = ''; }
                else { badge.style.display = 'none'; }
            }).catch(function(){});
    }
    poll();
    setInterval(poll, 20000);
}());
</script>
<?php endif; ?>

<!-- jQuery -->
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<!-- Bootstrap 5 -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<!-- DataTables -->
<script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/dataTables.bootstrap5.min.js"></script>
<!-- Select2 -->
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<!-- Custom -->
<script src="<?= BASE_URL ?>/assets/js/main.js?v=<?= @filemtime(BASE_PATH . '/assets/js/main.js') ?: time() ?>"></script>
<?php if (isset($extraJs)) echo $extraJs; ?>
<script>
// Auto-inject CSRF token into all HTML forms that POST to this site
(function() {
    var token = document.querySelector('meta[name="csrf-token"]');
    if (!token) return;
    var tokenValue = token.getAttribute('content');
    document.querySelectorAll('form[method="post"], form[method="POST"]').forEach(function(form) {
        if (!form.querySelector('input[name="csrf_token"]')) {
            var input = document.createElement('input');
            input.type  = 'hidden';
            input.name  = 'csrf_token';
            input.value = tokenValue;
            form.appendChild(input);
        }
    });
    // Also set header for fetch/XMLHttpRequest
    var origFetch = window.fetch;
    window.fetch = function(url, opts) {
        opts = opts || {};
        if (opts.method && opts.method.toUpperCase() === 'POST') {
            opts.headers = Object.assign({ 'X-CSRF-Token': tokenValue }, opts.headers || {});
        }
        return origFetch(url, opts);
    };
    if (window.XMLHttpRequest) {
        var origOpen = XMLHttpRequest.prototype.open;
        XMLHttpRequest.prototype.open = function(method) {
            this._method = method;
            return origOpen.apply(this, arguments);
        };
        var origSend = XMLHttpRequest.prototype.send;
        XMLHttpRequest.prototype.send = function(body) {
            if (this._method && this._method.toUpperCase() === 'POST') {
                this.setRequestHeader('X-CSRF-Token', tokenValue);
            }
            return origSend.apply(this, arguments);
        };
    }
}());
</script>
<?php if (isset($extraModal)) echo $extraModal; ?>

<!-- ── PWA Install Popup ──────────────────────────────────────────────── -->
<div id="pwaOverlay" class="pwa-overlay" style="display:none" role="dialog" aria-modal="true" aria-labelledby="pwaPopupTitle">
    <div class="pwa-popup-card">
        <button id="pwaPopupClose" class="pwa-popup-close" aria-label="Close">
            <i class="fa fa-xmark"></i>
        </button>

        <div class="pwa-popup-icon">
            <img src="<?= BASE_URL ?>/assets/images/icons/icon.svg" width="56" height="56" alt="">
        </div>
        <h4 id="pwaPopupTitle" class="pwa-popup-title">Install <?= e(getSetting('company_name', APP_NAME)) ?></h4>
        <p class="pwa-popup-subtitle">Add this app to your home screen or desktop for instant, offline-capable access.</p>

        <div class="pwa-popup-benefits">
            <div class="pwa-popup-benefit"><i class="fa fa-bolt text-warning"></i><span>Launches instantly — no browser needed</span></div>
            <div class="pwa-popup-benefit"><i class="fa fa-wifi-slash text-primary"></i><span>Key pages work offline</span></div>
            <div class="pwa-popup-benefit"><i class="fa fa-bell text-success"></i><span>Stay notified of alerts &amp; updates</span></div>
            <div class="pwa-popup-benefit"><i class="fa fa-mobile-screen text-info"></i><span>Available on mobile &amp; desktop</span></div>
        </div>

        <!-- Chrome / Edge / Android: native prompt -->
        <div id="pwaChromActions" class="pwa-popup-actions">
            <button id="pwaPopupInstallBtn" class="btn btn-primary btn-lg w-100 fw-bold">
                <i class="fa fa-download me-2"></i>Install App
            </button>
            <button id="pwaPopupDismissBtn" class="btn btn-link text-muted w-100 mt-1" style="font-size:13px">
                Not now — remind me in 7 days
            </button>
        </div>

        <!-- iOS Safari: manual instructions -->
        <div id="pwaIosInstructions" class="pwa-ios-instructions" style="display:none">
            <p class="pwa-ios-header">How to install on iPhone / iPad</p>
            <div class="pwa-ios-step">
                <div class="pwa-ios-step-num">1</div>
                <span>Tap the <strong>Share</strong> button <i class="fa fa-arrow-up-from-bracket ms-1"></i> in Safari's bottom toolbar</span>
            </div>
            <div class="pwa-ios-step">
                <div class="pwa-ios-step-num">2</div>
                <span>Scroll and tap <strong>"Add to Home Screen"</strong></span>
            </div>
            <div class="pwa-ios-step">
                <div class="pwa-ios-step-num">3</div>
                <span>Tap <strong>"Add"</strong> in the top-right corner</span>
            </div>
            <button id="pwaIosGotIt" class="btn btn-primary w-100 mt-3 fw-bold">Got it!</button>
        </div>
    </div>
</div>

<!-- ── Service Worker + PWA Logic ────────────────────────────────────── -->
<script>
(function () {
    'use strict';

    // ── Service Worker registration ──────────────────────────────────────
    if ('serviceWorker' in navigator) {
        window.addEventListener('load', function () {
            navigator.serviceWorker
                .register('<?= BASE_URL ?>/sw.js')
                .then(function (reg) {
                    reg.addEventListener('updatefound', function () {
                        var newWorker = reg.installing;
                        newWorker && newWorker.addEventListener('statechange', function () {
                            if (this.state === 'installed' && navigator.serviceWorker.controller) {
                                if (typeof window.showToast === 'function') {
                                    window.showToast('info', 'Update Available',
                                        'A new version is ready. <a href="#" onclick="location.reload();return false;">Reload now</a>',
                                        12000);
                                }
                            }
                        });
                    });
                })
                .catch(function (err) { console.warn('[SW] Registration failed:', err); });
        });
    }

    // ── State ────────────────────────────────────────────────────────────
    var deferredPrompt = null;
    var overlay        = document.getElementById('pwaOverlay');
    var topbarBtn      = document.getElementById('pwaInstallTopbarBtn');
    var DISMISS_KEY    = 'pwa_popup_dismissed_v2';
    var DISMISS_DAYS   = 3;

    function isStandalone() {
        return window.matchMedia('(display-mode: standalone)').matches
            || window.navigator.standalone === true;
    }
    function isDismissed() {
        var ts = localStorage.getItem(DISMISS_KEY);
        return ts && (Date.now() - parseInt(ts, 10)) < DISMISS_DAYS * 86400000;
    }
    var ua        = navigator.userAgent;
    var isIOS     = /iphone|ipad|ipod/i.test(ua) && !window.MSStream;
    var isIOSSaf  = isIOS && /^((?!chrome|android).)*safari/i.test(ua);
    var isChrome  = /chrome|chromium|crios/i.test(ua) && !/edg/i.test(ua);
    var isEdge    = /edg/i.test(ua);
    var isFF      = /firefox|fxios/i.test(ua);
    var isSamsung = /samsungbrowser/i.test(ua);

    // Nothing to do if already running as installed app
    if (isStandalone()) return;

    function showPopup() {
        if (!overlay) return;
        overlay.style.display = 'flex';
        setTimeout(function () { overlay.classList.add('pwa-overlay-visible'); }, 20);
    }
    function hidePopup(dismiss) {
        if (!overlay) return;
        overlay.classList.remove('pwa-overlay-visible');
        setTimeout(function () { overlay.style.display = 'none'; }, 320);
        if (dismiss) localStorage.setItem(DISMISS_KEY, String(Date.now()));
    }

    // ── Always show topbar install button for non-standalone users ───────
    if (topbarBtn) topbarBtn.classList.remove('d-none');

    // ── Capture native install prompt if Chrome/Edge provides it ────────
    function onInstallPrompt(e) { e.preventDefault(); deferredPrompt = e; }
    if (window.__pwaBeforeInstall) {
        deferredPrompt = window.__pwaBeforeInstall;
        window.__pwaBeforeInstall = null;
    }
    window.addEventListener('beforeinstallprompt', onInstallPrompt);

    // ── Set popup content based on browser / capability ──────────────────
    function configurePopup() {
        var chrEl      = document.getElementById('pwaChromActions');
        var iosEl      = document.getElementById('pwaIosInstructions');
        var installBtn = document.getElementById('pwaPopupInstallBtn');

        if (isIOSSaf) {
            if (chrEl) chrEl.style.display = 'none';
            if (iosEl) iosEl.style.display = '';

        } else if (deferredPrompt) {
            if (iosEl) iosEl.style.display = 'none';
            if (chrEl) chrEl.style.display = '';
            if (installBtn) installBtn.innerHTML = '<i class="fa fa-download me-2"></i>Install App';

        } else {
            // No native prompt — show browser-specific manual instructions
            if (iosEl) iosEl.style.display = 'none';
            if (chrEl) {
                chrEl.style.display = '';
                var hint = '';
                if (isChrome || isEdge) {
                    hint = 'Tap the <i class="fa fa-download"></i> install icon in the address bar &mdash; or tap &#8942; menu &rarr; <strong>Install App</strong> / <strong>Add to Home Screen</strong>.';
                } else if (isFF) {
                    hint = 'Tap the <i class="fa fa-house"></i> icon in the address bar, then <strong>Add to Home Screen</strong>.';
                } else if (isSamsung) {
                    hint = 'Tap &#8942; menu &rarr; <strong>Add page to</strong> &rarr; <strong>Home screen</strong>.';
                } else if (isIOS) {
                    hint = 'Open this page in <strong>Safari</strong>, tap the Share button, then <strong>Add to Home Screen</strong>.';
                } else {
                    hint = 'Use your browser menu to find <strong>Install App</strong> or <strong>Add to Home Screen</strong>.';
                }
                chrEl.innerHTML =
                    '<div style="background:#eff6ff;border-radius:10px;padding:14px 16px;font-size:13px;color:#1e40af;line-height:1.7">'
                    + hint + '</div>'
                    + '<button id="pwaDismissManual" class="btn btn-link text-muted w-100 mt-2" style="font-size:13px">Close</button>';
                var dm = document.getElementById('pwaDismissManual');
                dm && dm.addEventListener('click', function () { hidePopup(true); });
            }
        }
    }

    // ── Install / open-popup action ──────────────────────────────────────
    function triggerInstall() {
        if (deferredPrompt) {
            hidePopup(false);
            deferredPrompt.prompt();
            deferredPrompt.userChoice.then(function (result) {
                if (result.outcome === 'accepted') {
                    if (topbarBtn) topbarBtn.classList.add('d-none');
                    if (typeof window.showToast === 'function') {
                        window.showToast('success', 'App Installed!',
                            'Launch it from your home screen or taskbar anytime.');
                    }
                }
                deferredPrompt = null;
            });
        } else {
            configurePopup();
            showPopup();
        }
    }

    topbarBtn && topbarBtn.addEventListener('click', triggerInstall);

    // ── Popup buttons ────────────────────────────────────────────────────
    var installBtn = document.getElementById('pwaPopupInstallBtn');
    installBtn && installBtn.addEventListener('click', triggerInstall);

    var dismissBtn = document.getElementById('pwaPopupDismissBtn');
    dismissBtn && dismissBtn.addEventListener('click', function () { hidePopup(true); });

    var closeBtn = document.getElementById('pwaPopupClose');
    closeBtn && closeBtn.addEventListener('click', function () { hidePopup(true); });

    var gotItBtn = document.getElementById('pwaIosGotIt');
    gotItBtn && gotItBtn.addEventListener('click', function () { hidePopup(true); });

    overlay && overlay.addEventListener('click', function (e) {
        if (e.target === overlay) hidePopup(true);
    });

    // ── Auto-show popup after 3 s — works on ALL browsers, no event needed
    if (!isDismissed()) {
        setTimeout(function () {
            configurePopup();
            showPopup();
        }, 3000);
    }

    window.addEventListener('appinstalled', function () {
        hidePopup(true);
        if (topbarBtn) topbarBtn.classList.add('d-none');
        deferredPrompt = null;
        localStorage.removeItem(DISMISS_KEY);
    });

    // ── Network status indicator ─────────────────────────────────────────
    function updateNetworkStatus() {
        var online    = navigator.onLine;
        var indicator = document.getElementById('networkStatusDot');
        if (!indicator) return;
        indicator.title     = online ? 'Online' : 'Offline — some features may be unavailable';
        indicator.className = online ? 'network-dot network-online' : 'network-dot network-offline';
    }
    window.addEventListener('online',  updateNetworkStatus);
    window.addEventListener('offline', function () {
        updateNetworkStatus();
        if (typeof window.showToast === 'function') {
            window.showToast('warning', 'No Connection', 'You are offline. Some features may not work.');
        }
    });
}());
</script>

</body>
</html>

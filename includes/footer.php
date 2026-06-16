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
                    // Prompt user when a new SW is waiting (new version deployed)
                    reg.addEventListener('updatefound', function () {
                        var newWorker = reg.installing;
                        newWorker && newWorker.addEventListener('statechange', function () {
                            if (this.state === 'installed' && navigator.serviceWorker.controller) {
                                // New version available — show a toast
                                if (typeof window.showToast === 'function') {
                                    window.showToast('info', 'Update Available',
                                        'A new version is ready. <a href="#" onclick="location.reload();return false;">Reload now</a>',
                                        12000);
                                }
                            }
                        });
                    });
                })
                .catch(function (err) {
                    console.warn('[SW] Registration failed:', err);
                });
        });
    }

    // ── Install popup ────────────────────────────────────────────────────
    var deferredPrompt  = null;
    var overlay         = document.getElementById('pwaOverlay');
    var DISMISS_KEY     = 'pwa_popup_dismissed';
    var DISMISS_DAYS    = 7;

    function isStandalone() {
        return window.matchMedia('(display-mode: standalone)').matches
            || window.navigator.standalone === true;
    }
    function isDismissed() {
        var ts = localStorage.getItem(DISMISS_KEY);
        return ts && (Date.now() - parseInt(ts, 10)) < DISMISS_DAYS * 86400000;
    }
    function showPopup() {
        if (!overlay || isDismissed() || isStandalone()) return;
        overlay.style.display = 'flex';
        setTimeout(function () { overlay.classList.add('pwa-overlay-visible'); }, 20);
    }
    function hidePopup(dismiss) {
        if (!overlay) return;
        overlay.classList.remove('pwa-overlay-visible');
        setTimeout(function () { overlay.style.display = 'none'; }, 320);
        if (dismiss) localStorage.setItem(DISMISS_KEY, String(Date.now()));
    }

    // Detect iOS Safari (no native beforeinstallprompt support)
    var isIOS    = /iphone|ipad|ipod/i.test(navigator.userAgent) && !window.MSStream;
    var isSafari = /^((?!chrome|android).)*safari/i.test(navigator.userAgent);
    if (isIOS && isSafari && !isStandalone()) {
        document.getElementById('pwaIosInstructions') && (document.getElementById('pwaIosInstructions').style.display = '');
        document.getElementById('pwaChromActions')    && (document.getElementById('pwaChromActions').style.display    = 'none');
        setTimeout(showPopup, 2500);
    }

    // Chrome / Edge / Android: use the event captured early in <head>,
    // or listen for it if it hasn't fired yet.
    // beforeinstallprompt can fire BEFORE footer scripts run (especially on
    // cached/fast pages), so we always capture it in the <head> first.
    function onInstallPrompt(e) {
        e.preventDefault();
        deferredPrompt = e;
        setTimeout(showPopup, 2000);
    }
    if (window.__pwaBeforeInstall) {
        // Already captured early — use it immediately
        deferredPrompt = window.__pwaBeforeInstall;
        window.__pwaBeforeInstall = null;
        setTimeout(showPopup, 2000);
    }
    // Also keep listening in case the event fires after this script runs
    window.addEventListener('beforeinstallprompt', onInstallPrompt);

    // Install button
    var installBtn = document.getElementById('pwaPopupInstallBtn');
    installBtn && installBtn.addEventListener('click', function () {
        if (!deferredPrompt) {
            // Fallback: show iOS-style instructions if no native prompt
            var chromActions = document.getElementById('pwaChromActions');
            var iosInstr     = document.getElementById('pwaIosInstructions');
            if (chromActions) chromActions.style.display = 'none';
            if (iosInstr)     iosInstr.style.display     = '';
            return;
        }
        hidePopup(false);
        deferredPrompt.prompt();
        deferredPrompt.userChoice.then(function (result) {
            if (result.outcome === 'accepted') {
                if (typeof window.showToast === 'function') {
                    window.showToast('success', 'App Installed!', 'Launch it from your home screen or desktop anytime.');
                }
            }
            deferredPrompt = null;
        });
    });

    // "Not now" button
    var dismissBtn = document.getElementById('pwaPopupDismissBtn');
    dismissBtn && dismissBtn.addEventListener('click', function () { hidePopup(true); });

    // Close ×
    var closeBtn = document.getElementById('pwaPopupClose');
    closeBtn && closeBtn.addEventListener('click', function () { hidePopup(true); });

    // iOS "Got it"
    var gotItBtn = document.getElementById('pwaIosGotIt');
    gotItBtn && gotItBtn.addEventListener('click', function () { hidePopup(true); });

    // Click outside card to dismiss
    overlay && overlay.addEventListener('click', function (e) {
        if (e.target === overlay) hidePopup(true);
    });

    // Already installed
    window.addEventListener('appinstalled', function () {
        hidePopup(true);
        deferredPrompt = null;
    });

    // ── Network status indicator ─────────────────────────────────────────
    function updateNetworkStatus() {
        var online = navigator.onLine;
        var indicator = document.getElementById('networkStatusDot');
        if (!indicator) return;
        indicator.title = online ? 'Online' : 'Offline — some features may be unavailable';
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

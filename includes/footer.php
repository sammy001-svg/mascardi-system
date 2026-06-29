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

<!-- ══════════════════════════════════════════════════════════════
     PWA Install Banner
     • Slides up from the bottom of the screen
     • Chrome/Edge/Android: native one-tap install via deferredPrompt
     • iOS Safari: step-by-step Share → Add to Home Screen guide
     • All others: browser-specific address-bar instruction
═══════════════════════════════════════════════════════════════ -->
<div id="pwaBanner" role="dialog" aria-label="Install App" style="
    display:none;position:fixed;bottom:0;left:0;right:0;z-index:99999;
    background:#fff;border-top:1px solid #e2e8f0;
    box-shadow:0 -4px 24px rgba(0,0,0,.12);
    padding:0;font-family:inherit;
    transform:translateY(100%);transition:transform .35s cubic-bezier(.4,0,.2,1)">

    <!-- Main panel: icon + text + buttons -->
    <div id="pwaBannerMain" style="display:flex;align-items:center;gap:14px;padding:14px 16px">
        <img src="<?= BASE_URL ?>/assets/images/icons/icon-192.png"
             width="48" height="48" alt="" style="border-radius:12px;flex-shrink:0">
        <div style="flex:1;min-width:0">
            <div style="font-weight:700;font-size:15px;color:#0f172a;line-height:1.2">
                Install <?= e(getSetting('company_name', APP_NAME)) ?>
            </div>
            <div style="font-size:12px;color:#64748b;margin-top:2px">
                Add to home screen for fast, offline access
            </div>
        </div>
        <button id="pwaBannerInstall" style="
            background:#2563eb;color:#fff;border:none;border-radius:8px;
            padding:9px 18px;font-size:14px;font-weight:600;cursor:pointer;
            white-space:nowrap;flex-shrink:0">
            Install
        </button>
        <button id="pwaBannerClose" aria-label="Dismiss" style="
            background:none;border:none;padding:6px;cursor:pointer;
            color:#94a3b8;font-size:18px;flex-shrink:0;line-height:1">
            &#x2715;
        </button>
    </div>

    <!-- iOS guide panel (hidden until needed) -->
    <div id="pwaBannerIos" style="display:none;padding:12px 16px 16px;border-top:1px solid #f1f5f9">
        <p style="margin:0 0 10px;font-size:13px;font-weight:600;color:#1e293b">
            How to install on iPhone / iPad:
        </p>
        <div style="display:flex;gap:10px;align-items:flex-start;margin-bottom:7px">
            <span style="background:#2563eb;color:#fff;border-radius:50%;width:22px;height:22px;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:700;flex-shrink:0">1</span>
            <span style="font-size:13px;color:#334155">Tap the <strong>Share</strong> <i class="fa fa-arrow-up-from-bracket" style="color:#2563eb"></i> button in Safari's toolbar</span>
        </div>
        <div style="display:flex;gap:10px;align-items:flex-start;margin-bottom:7px">
            <span style="background:#2563eb;color:#fff;border-radius:50%;width:22px;height:22px;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:700;flex-shrink:0">2</span>
            <span style="font-size:13px;color:#334155">Scroll down and tap <strong>"Add to Home Screen"</strong></span>
        </div>
        <div style="display:flex;gap:10px;align-items:flex-start;margin-bottom:14px">
            <span style="background:#2563eb;color:#fff;border-radius:50%;width:22px;height:22px;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:700;flex-shrink:0">3</span>
            <span style="font-size:13px;color:#334155">Tap <strong>"Add"</strong> in the top-right corner</span>
        </div>
        <button id="pwaBannerIosOk" style="
            width:100%;background:#2563eb;color:#fff;border:none;border-radius:8px;
            padding:10px;font-size:14px;font-weight:600;cursor:pointer">
            Got it
        </button>
    </div>

    <!-- Browser hint panel (hidden until needed) -->
    <div id="pwaBannerHint" style="display:none;padding:12px 16px 14px;border-top:1px solid #f1f5f9">
        <p id="pwaBannerHintText" style="margin:0 0 10px;font-size:13px;color:#334155;line-height:1.7"></p>
        <button id="pwaBannerHintOk" style="
            width:100%;background:#2563eb;color:#fff;border:none;border-radius:8px;
            padding:10px;font-size:14px;font-weight:600;cursor:pointer">
            Got it
        </button>
    </div>
</div>

<!-- ── Service Worker + PWA ───────────────────────────────────────────── -->
<script>
(function () {
    'use strict';

    var DISMISS_KEY  = 'pwa_b_v6';   // bump version to clear old dismissals
    var DISMISS_DAYS = 3;
    var ua           = navigator.userAgent;
    var isIOS        = /iphone|ipad|ipod/i.test(ua) && !window.MSStream;
    var isIOSSaf     = isIOS && /^((?!chrome|android).)*safari/i.test(ua);
    var isChrome     = /chrome|chromium|crios/i.test(ua) && !/edg/i.test(ua);
    var isEdge       = /edg/i.test(ua);
    var isFF         = /firefox|fxios/i.test(ua);
    var isSamsung    = /samsungbrowser/i.test(ua);
    var deferredPrompt = null;

    // ── Helpers ───────────────────────────────────────────────────────────
    function isStandalone() {
        return window.matchMedia('(display-mode: standalone)').matches
            || window.navigator.standalone === true;
    }
    function isDismissed() {
        var ts = localStorage.getItem(DISMISS_KEY);
        return ts && (Date.now() - parseInt(ts, 10)) < DISMISS_DAYS * 86400000;
    }
    function dismiss() {
        localStorage.setItem(DISMISS_KEY, String(Date.now()));
        hideBanner();
    }

    // ── Banner show / hide ────────────────────────────────────────────────
    var banner = document.getElementById('pwaBanner');
    function showBanner() {
        if (!banner) return;
        banner.style.display = 'block';
        requestAnimationFrame(function () {
            requestAnimationFrame(function () {
                banner.style.transform = 'translateY(0)';
            });
        });
    }
    function hideBanner() {
        if (!banner) return;
        banner.style.transform = 'translateY(100%)';
        setTimeout(function () { banner.style.display = 'none'; }, 380);
    }

    // ── Panel switching ───────────────────────────────────────────────────
    var panelMain = document.getElementById('pwaBannerMain');
    var panelIos  = document.getElementById('pwaBannerIos');
    var panelHint = document.getElementById('pwaBannerHint');

    function showPanel(which) {
        panelMain && (panelMain.style.display = which === 'main' ? 'flex'  : 'none');
        panelIos  && (panelIos.style.display  = which === 'ios'  ? 'block' : 'none');
        panelHint && (panelHint.style.display  = which === 'hint' ? 'block' : 'none');
    }

    // ── Native install prompt ─────────────────────────────────────────────
    function capturePrompt(e) {
        // Do NOT call e.preventDefault() — Chrome's native install UI must
        // remain active as a guaranteed fallback.
        deferredPrompt = e;
        console.log('[PWA] deferredPrompt set ✓ — custom Install button now active');
    }
    window.addEventListener('beforeinstallprompt', capturePrompt);
    // Pick up event captured early in <head> (fires before footer script runs)
    if (window.__pwaBeforeInstall) {
        deferredPrompt = window.__pwaBeforeInstall;
        window.__pwaBeforeInstall = null;
        console.log('[PWA] deferredPrompt restored from __pwaBeforeInstall ✓');
    }
    console.log('[PWA] init — deferredPrompt:', deferredPrompt ? 'AVAILABLE' : 'null (Chrome native UI handles install)');

    // ── Install action ────────────────────────────────────────────────────
    function doInstall() {
        console.log('[PWA] Install clicked — deferredPrompt:', deferredPrompt ? 'available' : 'null');
        if (deferredPrompt) {
            // Chrome / Edge / Android — native one-tap install dialog
            deferredPrompt.prompt();
            deferredPrompt.userChoice.then(function (r) {
                console.log('[PWA] userChoice:', r.outcome);
                if (r.outcome === 'accepted') {
                    hideBanner();
                    localStorage.removeItem(DISMISS_KEY);
                    if (typeof window.showToast === 'function') {
                        window.showToast('success', 'App Installed',
                            'Launch it from your home screen anytime.');
                    }
                }
                deferredPrompt = null;
            });
        } else if (isIOSSaf) {
            showPanel('ios');
        } else {
            // Chrome / Edge shows a native install icon in the address bar.
            // Point the user directly to it — it works even without our prompt.
            var hint = '';
            if (isChrome || isEdge) {
                hint = '<strong>Chrome has an install button ready for you:</strong><br>'
                     + '&bull; <strong>Desktop:</strong> look for the '
                     + '<svg width="15" height="15" viewBox="0 0 24 24" fill="#2563eb" style="vertical-align:middle;margin:0 2px"><path d="M19 9h-4V3H9v6H5l7 7 7-7zM5 18v2h14v-2H5z"/></svg>'
                     + ' install icon in the <strong>address bar</strong> (right side).<br>'
                     + '&bull; <strong>Android:</strong> tap the menu <strong>&#8942;</strong> &rarr; <strong>"Add to Home Screen"</strong> or <strong>"Install App"</strong>.';
            } else if (isSamsung) {
                hint = 'Tap the menu <strong>&#8942;</strong> and choose <strong>"Add page to"</strong> &rarr; <strong>"Home screen"</strong>.';
            } else if (isFF) {
                hint = 'Tap the <strong>home</strong> icon in the Firefox address bar, then <strong>"Add to Home Screen"</strong>.';
            } else if (isIOS) {
                hint = 'Open this page in <strong>Safari</strong>, tap the <strong>Share</strong> button, then <strong>"Add to Home Screen"</strong>.';
            } else {
                hint = 'Open your browser menu and look for <strong>"Install App"</strong> or <strong>"Add to Home Screen"</strong>.';
            }
            var hintEl = document.getElementById('pwaBannerHintText');
            if (hintEl) hintEl.innerHTML = hint;
            showPanel('hint');
        }
    }

    // ── Wire up buttons ───────────────────────────────────────────────────
    var btnInstall = document.getElementById('pwaBannerInstall');
    var btnClose   = document.getElementById('pwaBannerClose');
    var btnIosOk   = document.getElementById('pwaBannerIosOk');
    var btnHintOk  = document.getElementById('pwaBannerHintOk');

    btnInstall && btnInstall.addEventListener('click', doInstall);
    btnClose   && btnClose.addEventListener('click', dismiss);
    btnIosOk   && btnIosOk.addEventListener('click', dismiss);
    btnHintOk  && btnHintOk.addEventListener('click', dismiss);

    // Topbar shortcut button — show for iOS Safari and when prompt is available
    var topbarBtn = document.getElementById('pwaInstallTopbarBtn');
    if (topbarBtn && (isIOSSaf || deferredPrompt)) {
        topbarBtn.classList.remove('d-none');
    }
    if (topbarBtn) {
        topbarBtn.addEventListener('click', function () {
            showPanel('main');
            showBanner();
        });
        // Also reveal it when the prompt fires later
        window.addEventListener('beforeinstallprompt', function () {
            topbarBtn.classList.remove('d-none');
        });
    }

    // app installed — clean up
    window.addEventListener('appinstalled', function () {
        hideBanner();
        if (topbarBtn) topbarBtn.classList.add('d-none');
        deferredPrompt = null;
        localStorage.removeItem(DISMISS_KEY);
    });

    // ── Auto-show after 2 s (all browsers, all platforms) ─────────────────
    if (!isStandalone() && !isDismissed()) {
        setTimeout(function () { showPanel('main'); showBanner(); }, 2000);
    }

    // ── Service Worker ────────────────────────────────────────────────────
    if ('serviceWorker' in navigator) {
        window.addEventListener('load', function () {
            navigator.serviceWorker.register('<?= BASE_URL ?>/sw.js')
                .then(function (reg) {
                    reg.addEventListener('updatefound', function () {
                        var nw = reg.installing;
                        nw && nw.addEventListener('statechange', function () {
                            if (this.state === 'installed' && navigator.serviceWorker.controller) {
                                if (typeof window.showToast === 'function') {
                                    window.showToast('info', 'Update Available',
                                        'Reload to get the latest version.',
                                        8000);
                                }
                            }
                        });
                    });
                })
                .catch(function (e) { console.warn('[SW]', e); });
        });
    }

    // ── Network status ────────────────────────────────────────────────────
    function setNetworkDot(online) {
        var dot = document.getElementById('networkStatusDot');
        if (!dot) return;
        dot.title     = online ? 'Online' : 'Offline';
        dot.className = 'network-dot network-' + (online ? 'online' : 'offline');
    }
    window.addEventListener('online',  function () { setNetworkDot(true); });
    window.addEventListener('offline', function () {
        setNetworkDot(false);
        if (typeof window.showToast === 'function') {
            window.showToast('warning', 'No Connection',
                'You are offline. Some features may not work.');
        }
    });

}());
</script>

</body>
</html>

</div><!-- /page-body -->
</div><!-- /main-wrap -->
</div><!-- /appShell -->

<!-- Toast notification stack -->
<div id="toastStack" class="toast-stack"></div>

<!-- Image skeleton shimmer — active while lazy images are off-screen -->
<style>
img[loading="lazy"]{background:linear-gradient(90deg,#f0f0f0 25%,#e8e8e8 50%,#f0f0f0 75%);background-size:200% 100%;animation:imgSkel 1.4s ease infinite;min-height:1px}
img[loading="lazy"].img-loaded{background:none;animation:none}
@keyframes imgSkel{0%{background-position:200% 0}100%{background-position:-200% 0}}
@media(prefers-reduced-motion:reduce){img[loading="lazy"]{animation:none;background:#f3f4f6}}
</style>

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
<script src="<?= BASE_URL ?>/assets/js/install-prompt.js?v=9" defer></script>
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
     PWA Install Modal
     • Centered popup on every page visit (not shown when already installed)
     • Chrome/Edge/Android: native one-tap install via deferredPrompt
     • iOS Safari: step-by-step Share → Add to Home Screen guide
     • All others: browser-specific address-bar instruction
═══════════════════════════════════════════════════════════════ -->
<div id="pwaOverlay" role="dialog" aria-modal="true" aria-label="Install App" style="
    display:none;position:fixed;inset:0;z-index:99999;
    background:rgba(15,23,42,0.6);backdrop-filter:blur(4px);-webkit-backdrop-filter:blur(4px);
    align-items:center;justify-content:center;padding:16px;
    opacity:0;transition:opacity .25s ease">

    <div id="pwaModal" style="
        background:#fff;border-radius:20px;width:100%;max-width:380px;
        box-shadow:0 24px 64px rgba(0,0,0,.35);position:relative;
        transform:scale(.9) translateY(24px);
        transition:transform .32s cubic-bezier(.34,1.56,.64,1)">

        <!-- Close button -->
        <button id="pwaClose" aria-label="Close" style="
            position:absolute;top:12px;right:12px;width:32px;height:32px;
            background:#f1f5f9;border:none;border-radius:50%;cursor:pointer;
            color:#64748b;font-size:14px;line-height:32px;text-align:center;z-index:1">
            &#x2715;
        </button>

        <!-- ── Main panel ──────────────────────────────────────────────── -->
        <div id="pwaMain" style="padding:32px 24px 24px;text-align:center">
            <img src="<?= BASE_URL ?>/assets/images/icons/icon-192.png"
                 width="80" height="80" alt=""
                 style="border-radius:20px;margin-bottom:16px;box-shadow:0 4px 16px rgba(37,99,235,.25)">
            <div style="font-size:20px;font-weight:800;color:#0f172a;margin-bottom:6px;line-height:1.2">
                Install <?= e(getSetting('company_name', APP_NAME)) ?>
            </div>
            <div style="font-size:13px;color:#64748b;line-height:1.6;margin-bottom:24px">
                Add to your home screen for instant access —<br>works offline, no app store needed.
            </div>
            <button id="pwaInstallBtn" style="
                display:block;width:100%;background:#2563eb;color:#fff;border:none;
                border-radius:12px;padding:14px;font-size:15px;font-weight:700;
                cursor:pointer;margin-bottom:10px;letter-spacing:.01em;
                box-shadow:0 4px 14px rgba(37,99,235,.4);transition:opacity .15s">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor" style="vertical-align:middle;margin-right:6px;margin-top:-2px"><path d="M19 9h-4V3H9v6H5l7 7 7-7zM5 18v2h14v-2H5z"/></svg>
                Install App
            </button>
            <button id="pwaDismissBtn" style="
                display:block;width:100%;background:none;border:none;
                color:#94a3b8;font-size:13px;cursor:pointer;padding:8px">
                Not now
            </button>
        </div>

        <!-- ── iOS guide panel ─────────────────────────────────────────── -->
        <div id="pwaIos" style="display:none;padding:24px 24px 20px">
            <div style="font-size:16px;font-weight:700;color:#0f172a;margin-bottom:16px">
                How to install on iPhone / iPad:
            </div>
            <div style="display:flex;gap:12px;align-items:flex-start;margin-bottom:12px">
                <span style="background:#2563eb;color:#fff;border-radius:50%;min-width:26px;height:26px;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:700;flex-shrink:0">1</span>
                <span style="font-size:13px;color:#334155;line-height:1.5">Tap the <strong>Share</strong> <i class="fa fa-arrow-up-from-bracket" style="color:#2563eb"></i> button in Safari's toolbar</span>
            </div>
            <div style="display:flex;gap:12px;align-items:flex-start;margin-bottom:12px">
                <span style="background:#2563eb;color:#fff;border-radius:50%;min-width:26px;height:26px;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:700;flex-shrink:0">2</span>
                <span style="font-size:13px;color:#334155;line-height:1.5">Scroll down and tap <strong>"Add to Home Screen"</strong></span>
            </div>
            <div style="display:flex;gap:12px;align-items:flex-start;margin-bottom:20px">
                <span style="background:#2563eb;color:#fff;border-radius:50%;min-width:26px;height:26px;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:700;flex-shrink:0">3</span>
                <span style="font-size:13px;color:#334155;line-height:1.5">Tap <strong>"Add"</strong> in the top-right corner</span>
            </div>
            <button id="pwaIosOk" style="
                display:block;width:100%;background:#2563eb;color:#fff;border:none;
                border-radius:12px;padding:13px;font-size:14px;font-weight:700;cursor:pointer">
                Got it
            </button>
        </div>

        <!-- ── Browser hint panel ──────────────────────────────────────── -->
        <div id="pwaHint" style="display:none;padding:24px 24px 20px">
            <div style="font-size:16px;font-weight:700;color:#0f172a;margin-bottom:12px">
                How to install:
            </div>
            <p id="pwaHintText" style="font-size:13px;color:#334155;line-height:1.75;margin:0 0 20px"></p>
            <button id="pwaHintOk" style="
                display:block;width:100%;background:#2563eb;color:#fff;border:none;
                border-radius:12px;padding:13px;font-size:14px;font-weight:700;cursor:pointer">
                Got it
            </button>
        </div>
    </div>
</div>

<!-- ── Service Worker + PWA ───────────────────────────────────────────── -->
<script>
(function () {
    'use strict';

    var ua        = navigator.userAgent;
    var isIOS     = /iphone|ipad|ipod/i.test(ua) && !window.MSStream;
    var isIOSSaf  = isIOS && /^((?!chrome|android).)*safari/i.test(ua);
    var isChrome  = /chrome|chromium|crios/i.test(ua) && !/edg/i.test(ua);
    var isEdge    = /edg/i.test(ua);
    var isFF      = /firefox|fxios/i.test(ua);
    var isSamsung = /samsungbrowser/i.test(ua);
    var deferredPrompt = null;
    var modalOpen  = false;

    // ── Persistence: remember installs and dismissals across page loads ───
    var INSTALLED_KEY = 'pwa_app_installed';
    var SNOOZED_KEY   = 'pwa_snoozed_until';
    function isInstalled() {
        try { return localStorage.getItem(INSTALLED_KEY) === '1'; } catch(e) { return false; }
    }
    function isSnoozed() {
        try { return Date.now() < parseInt(localStorage.getItem(SNOOZED_KEY) || '0', 10); } catch(e) { return false; }
    }
    function markInstalled() {
        try { localStorage.setItem(INSTALLED_KEY, '1'); localStorage.removeItem(SNOOZED_KEY); } catch(e) {}
    }
    function snooze(days) {
        try { localStorage.setItem(SNOOZED_KEY, String(Date.now() + days * 86400000)); } catch(e) {}
    }

    function isStandalone() {
        return window.matchMedia('(display-mode: standalone)').matches
            || window.navigator.standalone === true;
    }

    // ── Modal open / close ────────────────────────────────────────────────
    var overlay = document.getElementById('pwaOverlay');
    var modal   = document.getElementById('pwaModal');

    function openModal() {
        if (!overlay || isStandalone() || modalOpen) return;
        modalOpen = true;
        overlay.style.display = 'flex';
        requestAnimationFrame(function () {
            requestAnimationFrame(function () {
                overlay.style.opacity = '1';
                if (modal) modal.style.transform = 'scale(1) translateY(0)';
            });
        });
    }

    function closeModal() {
        if (!overlay) return;
        modalOpen = false;
        overlay.style.opacity = '0';
        if (modal) modal.style.transform = 'scale(.9) translateY(24px)';
        setTimeout(function () { overlay.style.display = 'none'; }, 300);
    }

    // ── Panel switching ───────────────────────────────────────────────────
    function showPanel(which) {
        var main = document.getElementById('pwaMain');
        var ios  = document.getElementById('pwaIos');
        var hint = document.getElementById('pwaHint');
        if (main) main.style.display = which === 'main' ? 'block' : 'none';
        if (ios)  ios.style.display  = which === 'ios'  ? 'block' : 'none';
        if (hint) hint.style.display = which === 'hint' ? 'block' : 'none';
    }

    // ── Native install prompt capture ─────────────────────────────────────
    function capturePrompt(e) {
        deferredPrompt = e;
        console.log('[PWA] beforeinstallprompt captured ✓ — Install button will trigger native dialog');
    }
    window.addEventListener('beforeinstallprompt', capturePrompt);
    if (window.__pwaBeforeInstall) {
        deferredPrompt = window.__pwaBeforeInstall;
        window.__pwaBeforeInstall = null;
        console.log('[PWA] deferredPrompt restored from head capture ✓');
    }

    // ── Install action ────────────────────────────────────────────────────
    function doInstall() {
        if (deferredPrompt) {
            // Real one-tap native install dialog (Chrome / Edge / Android)
            deferredPrompt.prompt();
            deferredPrompt.userChoice.then(function (r) {
                if (r.outcome === 'accepted') {
                    markInstalled();
                    closeModal();
                    if (topbarBtn) topbarBtn.classList.add('d-none');
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
            var hint = '';
            if (isChrome || isEdge) {
                hint = '<strong>Chrome is ready to install — just one more step:</strong><br><br>'
                     + '&bull; <strong>Desktop:</strong> click the '
                     + '<svg width="15" height="15" viewBox="0 0 24 24" fill="#2563eb" style="vertical-align:middle;margin:0 2px"><path d="M19 9h-4V3H9v6H5l7 7 7-7zM5 18v2h14v-2H5z"/></svg>'
                     + ' install icon in the <strong>right side of the address bar</strong>.<br>'
                     + '&bull; <strong>Android:</strong> tap the <strong>&#8942;</strong> menu &rarr; <strong>"Add to Home Screen"</strong>.';
            } else if (isSamsung) {
                hint = 'Tap the <strong>&#8942;</strong> menu &rarr; <strong>"Add page to"</strong> &rarr; <strong>"Home screen"</strong>.';
            } else if (isFF) {
                hint = 'Tap the <strong>home</strong> icon in the Firefox address bar, then <strong>"Add to Home Screen"</strong>.';
            } else if (isIOS) {
                hint = 'This page must be opened in <strong>Safari</strong>. Then tap the <strong>Share</strong> button &rarr; <strong>"Add to Home Screen"</strong>.';
            } else {
                hint = 'Use your browser menu and look for <strong>"Install App"</strong> or <strong>"Add to Home Screen"</strong>.';
            }
            document.getElementById('pwaHintText').innerHTML = hint;
            showPanel('hint');
        }
    }

    // ── Wire up buttons ───────────────────────────────────────────────────
    document.getElementById('pwaInstallBtn').addEventListener('click', doInstall);
    document.getElementById('pwaClose').addEventListener('click', function () { snooze(30); closeModal(); });
    document.getElementById('pwaDismissBtn').addEventListener('click', function () { snooze(30); closeModal(); });
    document.getElementById('pwaIosOk').addEventListener('click', function () { snooze(30); closeModal(); });
    document.getElementById('pwaHintOk').addEventListener('click', function () { snooze(30); closeModal(); });

    // Close on backdrop click
    overlay && overlay.addEventListener('click', function (e) {
        if (e.target === overlay) closeModal();
    });

    // Topbar shortcut button re-opens the modal (hide if already installed)
    var topbarBtn = document.getElementById('pwaInstallTopbarBtn');
    if (topbarBtn) {
        if (!isInstalled() && (isIOSSaf || deferredPrompt)) topbarBtn.classList.remove('d-none');
        topbarBtn.addEventListener('click', function () { showPanel('main'); openModal(); });
        window.addEventListener('beforeinstallprompt', function () {
            if (!isInstalled()) topbarBtn.classList.remove('d-none');
        });
    }

    // Hide everything once installed and persist so modal never shows again
    window.addEventListener('appinstalled', function () {
        markInstalled();
        closeModal();
        if (topbarBtn) topbarBtn.classList.add('d-none');
        deferredPrompt = null;
        if (typeof window.showToast === 'function') {
            window.showToast('success', 'App Installed', 'Launch it from your home screen anytime.');
        }
    });

    // ── Show modal only if not installed and not recently dismissed ───────
    if (!isStandalone() && !isInstalled() && !isSnoozed()) {
        setTimeout(function () { showPanel('main'); openModal(); }, 800);
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
                                        'Reload to get the latest version.', 8000);
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
            window.showToast('warning', 'No Connection', 'You are offline. Some features may not work.');
        }
    });

}());
</script>

</body>
</html>
<?php
/* Inject loading="lazy" decoding="async" on every <img> that doesn't already carry
   a loading= attribute. Runs once per request via the ob_start() opened in header.php. */
if (ob_get_level() > 0) {
    $__html = ob_get_clean();
    $__html = preg_replace('/<img\s(?![^>]*loading=)/i', '<img loading="lazy" decoding="async" ', $__html);
    echo $__html;
}
?>

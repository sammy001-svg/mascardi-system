</div><!-- /page-body -->
</div><!-- /main-wrap -->
</div><!-- /appShell -->

<!-- Toast notification stack -->
<div id="toastStack" class="toast-stack"></div>

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

<!-- ── PWA Install Banner ─────────────────────────────────────────────── -->
<div id="pwaInstallBanner" class="pwa-install-banner" style="display:none" role="complementary" aria-label="Install app">
    <div class="pwa-install-inner">
        <div class="pwa-install-icon">
            <img src="<?= BASE_URL ?>/assets/images/icons/icon.svg" width="40" height="40" alt="App icon">
        </div>
        <div class="pwa-install-text">
            <strong><?= e(getSetting('company_name', APP_NAME)) ?></strong>
            <span>Install for faster, offline access</span>
        </div>
        <button id="pwaInstallBtn" class="pwa-install-action" aria-label="Install app">
            <i class="fa fa-download me-1"></i>Install
        </button>
        <button id="pwaInstallDismiss" class="pwa-install-dismiss" aria-label="Dismiss">
            <i class="fa fa-xmark"></i>
        </button>
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

    // ── Install prompt ───────────────────────────────────────────────────
    var deferredPrompt = null;
    var banner  = document.getElementById('pwaInstallBanner');
    var btnInst = document.getElementById('pwaInstallBtn');
    var btnDism = document.getElementById('pwaInstallDismiss');
    var DISMISS_KEY = 'pwa_banner_dismissed';
    var DISMISS_DAYS = 7;

    function isDismissed() {
        var ts = localStorage.getItem(DISMISS_KEY);
        if (!ts) return false;
        return (Date.now() - parseInt(ts, 10)) < DISMISS_DAYS * 86400000;
    }

    function showBanner() {
        if (!banner || isDismissed()) return;
        banner.style.display = '';
        setTimeout(function () { banner.classList.add('pwa-banner-visible'); }, 50);
    }

    function hideBanner(dismiss) {
        if (!banner) return;
        banner.classList.remove('pwa-banner-visible');
        setTimeout(function () { banner.style.display = 'none'; }, 400);
        if (dismiss) localStorage.setItem(DISMISS_KEY, String(Date.now()));
    }

    // Listen for the browser's install eligibility signal
    window.addEventListener('beforeinstallprompt', function (e) {
        e.preventDefault();
        deferredPrompt = e;
        showBanner();
    });

    // Install button click
    btnInst && btnInst.addEventListener('click', function () {
        hideBanner(false);
        if (!deferredPrompt) return;
        deferredPrompt.prompt();
        deferredPrompt.userChoice.then(function (result) {
            if (result.outcome === 'accepted') {
                if (typeof window.showToast === 'function') {
                    window.showToast('success', 'Installed!', 'App added to your home screen.');
                }
            }
            deferredPrompt = null;
        });
    });

    // Dismiss button click
    btnDism && btnDism.addEventListener('click', function () {
        hideBanner(true);
    });

    // Hide banner when app is installed
    window.addEventListener('appinstalled', function () {
        hideBanner(true);
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

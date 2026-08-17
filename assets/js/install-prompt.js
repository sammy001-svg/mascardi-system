/**
 * Mascardi Car Yard — PWA install
 *
 * Two routes, because browsers differ in a way that cannot be papered over:
 *
 *   Chrome / Edge / Samsung (Android phones and tablets, desktop)
 *       Fire `beforeinstallprompt`. We hold the event and hand it to a real
 *       install dialog when asked.
 *
 *   Safari (iPhone and — the reason this was rewritten — iPad)
 *       Never fires that event and exposes no install API at all. Installing is
 *       a manual gesture through the Share menu, so the only thing software can
 *       do is say where it is. Previously nothing appeared on an iPad whatsoever,
 *       which made the app look uninstallable on exactly the device it was
 *       supposed to run on.
 *
 * Both routes are reachable on demand from the "Install App" item in the user
 * menu, so a banner missed once is not gone for weeks.
 *
 * Included at the bottom of includes/footer.php
 */

(function () {
    'use strict';

    var STORE_KEY  = 'pwa_install_dismissed';
    var QUIET_DAYS = 14;            // after "Not now" — was 30, which felt like never
    var QUIET_MS   = QUIET_DAYS * 24 * 60 * 60 * 1000;

    // ── Environment ─────────────────────────────────────────────────────────
    var ua = navigator.userAgent || '';

    // iPadOS 13+ reports itself as a Mac. The touch-point count is what still
    // separates an iPad from a desktop Safari, so both tests are needed.
    var isIOS = /iPad|iPhone|iPod/.test(ua) ||
                (/Macintosh/.test(ua) && typeof navigator.maxTouchPoints === 'number'
                 && navigator.maxTouchPoints > 1);

    // On iOS every browser is WebKit underneath, but only some expose the
    // Add to Home Screen action. Chrome/Firefox/Edge on iOS do not.
    var iosCanInstall = isIOS && !/CriOS|FxiOS|EdgiOS|OPiOS/i.test(ua);

    var installed = window.matchMedia('(display-mode: standalone)').matches ||
                    window.matchMedia('(display-mode: fullscreen)').matches ||
                    window.matchMedia('(display-mode: minimal-ui)').matches ||
                    window.navigator.standalone === true;

    var deferredPrompt = null;

    function quiet() {
        var t = parseInt(localStorage.getItem(STORE_KEY) || '0', 10);
        return t > 0 && (Date.now() - t) < QUIET_MS;
    }
    function silence() {
        try { localStorage.setItem(STORE_KEY, Date.now().toString()); } catch (e) {}
    }

    // ── The menu entry ──────────────────────────────────────────────────────
    // Shown whenever installing is possible, regardless of the quiet period —
    // it is something the user went looking for, not an interruption.
    function revealMenuItem() {
        var li = document.getElementById('pwaInstallMenuItem');
        if (!li || installed) return;
        li.style.display = '';
        var a = document.getElementById('pwaInstallMenuLink');
        if (a && !a.dataset.bound) {
            a.dataset.bound = '1';
            a.addEventListener('click', function (e) {
                e.preventDefault();
                if (deferredPrompt) doInstall();
                else if (iosCanInstall) showIosSheet();
                else showUnsupported();
            });
        }
    }

    // ── Chrome / Edge / Android ──────────────────────────────────────────────
    window.addEventListener('beforeinstallprompt', function (e) {
        e.preventDefault();
        deferredPrompt = e;
        revealMenuItem();
        if (!installed && !quiet()) showBanner(false);
    });

    async function doInstall() {
        if (!deferredPrompt) return;
        var p = deferredPrompt;
        deferredPrompt = null;             // a prompt may only be used once
        hideBanner();
        try {
            p.prompt();
            var res = await p.userChoice;
            if (res && res.outcome === 'accepted') silence();
        } catch (err) { /* dialog dismissed by the browser */ }
    }

    // ── Shared banner ───────────────────────────────────────────────────────
    function showBanner(ios) {
        if (installed || document.getElementById('pwa-install-banner')) return;

        var wrap = document.createElement('div');
        wrap.id = 'pwa-install-banner';
        wrap.innerHTML =
            '<div id="pwa-banner-inner" style="' +
                'position:fixed;bottom:1.25rem;left:50%;transform:translateX(-50%);' +
                'z-index:9999;max-width:420px;width:calc(100% - 2rem);' +
                'background:#1e293b;color:#fff;border-radius:16px;padding:14px 18px;' +
                'box-shadow:0 8px 32px rgba(0,0,0,.35);display:flex;align-items:center;gap:14px;' +
                'animation:pwaSlideUp .35s cubic-bezier(.34,1.56,.64,1) forwards;' +
                'font-family:Inter,system-ui,sans-serif;font-size:14px">' +
                '<div style="font-size:26px;flex-shrink:0">' + (ios ? '📲' : '📱') + '</div>' +
                '<div style="flex:1;min-width:0">' +
                    '<div style="font-weight:700;margin-bottom:2px">Install Mascardi</div>' +
                    '<div style="color:rgba(255,255,255,.66);font-size:12px">' +
                        (ios ? 'Add it to your Home Screen for full-screen access.'
                             : 'Add to your home screen — works offline.') +
                    '</div>' +
                '</div>' +
                '<div style="display:flex;flex-direction:column;gap:6px;flex-shrink:0">' +
                    '<button id="pwa-install-btn" style="' +
                        'background:linear-gradient(135deg,#3b82f6,#1d4ed8);color:#fff;border:0;' +
                        'border-radius:8px;padding:7px 15px;font-size:13px;font-weight:600;' +
                        'cursor:pointer;white-space:nowrap">' + (ios ? 'How' : 'Install') + '</button>' +
                    '<button id="pwa-dismiss-btn" style="' +
                        'background:rgba(255,255,255,.08);color:rgba(255,255,255,.62);' +
                        'border:1px solid rgba(255,255,255,.15);border-radius:8px;' +
                        'padding:5px 11px;font-size:12px;cursor:pointer">Not now</button>' +
                '</div>' +
            '</div>' +
            '<style>' +
            '@keyframes pwaSlideUp{from{opacity:0;transform:translateX(-50%) translateY(20px)}' +
            'to{opacity:1;transform:translateX(-50%) translateY(0)}}' +
            '@keyframes pwaFadeOut{to{opacity:0;transform:translateX(-50%) translateY(16px)}}' +
            '</style>';
        document.body.appendChild(wrap);

        document.getElementById('pwa-install-btn').addEventListener('click', function () {
            if (ios) { hideBanner(); showIosSheet(); } else doInstall();
        });
        document.getElementById('pwa-dismiss-btn').addEventListener('click', function () {
            silence(); hideBanner();
        });

        // Long enough to read and act on; the menu item remains either way.
        setTimeout(hideBanner, 15000);
    }

    function hideBanner() {
        var b = document.getElementById('pwa-install-banner');
        if (!b) return;
        var inner = document.getElementById('pwa-banner-inner');
        if (inner) inner.style.animation = 'pwaFadeOut .25s ease forwards';
        setTimeout(function () { if (b.parentNode) b.remove(); }, 260);
    }

    // ── Safari: show the gesture, since it cannot be triggered ──────────────
    function showIosSheet() {
        if (document.getElementById('pwa-ios-sheet')) return;
        var iPad = /iPad/.test(ua) || (/Macintosh/.test(ua) && navigator.maxTouchPoints > 1);
        // The Share button sits in the top toolbar on iPad and at the bottom on
        // iPhone. Telling someone to look in the wrong place is worse than saying
        // nothing, so the wording follows the device.
        var whereShare = iPad ? 'the top toolbar' : 'the bar at the bottom';

        var el = document.createElement('div');
        el.id = 'pwa-ios-sheet';
        el.innerHTML =
            '<div style="position:fixed;inset:0;z-index:10000;background:rgba(2,6,23,.72);' +
                 'display:flex;align-items:center;justify-content:center;padding:20px" id="pwa-ios-back">' +
              '<div style="background:#0f172a;color:#e8eaed;border:1px solid #24303f;border-radius:16px;' +
                   'max-width:400px;width:100%;padding:26px;font-family:Inter,system-ui,sans-serif" ' +
                   'role="dialog" aria-modal="true" aria-label="Install Mascardi">' +
                '<div style="font-size:17px;font-weight:800;margin-bottom:6px">Install on your ' +
                    (iPad ? 'iPad' : 'iPhone') + '</div>' +
                '<div style="font-size:13px;color:#94a3b8;margin-bottom:20px">' +
                    'Safari installs apps from the Share menu — three quick taps.</div>' +
                '<ol style="margin:0 0 22px;padding-left:22px;font-size:14.5px;line-height:1.85">' +
                  '<li>Tap <strong>Share</strong> ' +
                      '<span style="display:inline-block;transform:translateY(2px)">&#x21E7;</span>' +
                      ' in ' + whereShare + '</li>' +
                  '<li>Scroll and tap <strong>Add to Home Screen</strong></li>' +
                  '<li>Tap <strong>Add</strong></li>' +
                '</ol>' +
                '<button id="pwa-ios-ok" style="width:100%;background:#3b82f6;color:#fff;border:0;' +
                       'border-radius:10px;padding:12px;font-size:14.5px;font-weight:700;cursor:pointer">' +
                    'Got it</button>' +
              '</div>' +
            '</div>';
        document.body.appendChild(el);

        function close() { silence(); if (el.parentNode) el.remove(); }
        document.getElementById('pwa-ios-ok').addEventListener('click', close);
        document.getElementById('pwa-ios-back').addEventListener('click', function (ev) {
            if (ev.target.id === 'pwa-ios-back') close();
        });
    }

    // Reached only from the menu, when no install route exists at all.
    function showUnsupported() {
        alert('To install, open this site in Chrome, Edge or Safari.\n\n'
            + 'On Android: menu ⋮ → Install app.\n'
            + 'On iPhone or iPad: Share ⇧ → Add to Home Screen.');
    }

    // ── Wire up ─────────────────────────────────────────────────────────────
    window.addEventListener('appinstalled', function () {
        deferredPrompt = null;
        installed = true;
        silence();
        hideBanner();
        var li = document.getElementById('pwaInstallMenuItem');
        if (li) li.style.display = 'none';
    });

    if (!installed && iosCanInstall) {
        // No event to wait for on Safari, so offer it directly.
        revealMenuItem();
        if (!quiet()) setTimeout(function () { showBanner(true); }, 1800);
    }

    // Chrome sometimes fires beforeinstallprompt before this script runs; the
    // menu item is also revealed there, so expose it for that case and for any
    // browser where installing is possible but the event is late.
    if (!installed && !isIOS) {
        setTimeout(function () { if (deferredPrompt) revealMenuItem(); }, 2500);
    }

    // Manual entry point, for the menu and for anything else that wants it.
    window.mascardiPWA = {
        install: function () {
            if (deferredPrompt) return doInstall();
            if (iosCanInstall)  return showIosSheet();
            return showUnsupported();
        },
        get canInstall() { return !!deferredPrompt || iosCanInstall; },
        get installed()  { return installed; }
    };
}());

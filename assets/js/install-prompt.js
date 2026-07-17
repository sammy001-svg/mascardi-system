/**
 * Mascardi Car Yard — PWA Install Prompt (Phase 9)
 *
 * Shows a sleek "Add to Home Screen" banner for mobile users who haven't
 * installed the app yet. Dismisses automatically after 10 seconds or on click.
 *
 * Included at the bottom of includes/footer.php
 */

(function () {
    'use strict';

    // Don't show if: already in standalone mode, or user dismissed within 30 days
    const isStandalone = window.matchMedia('(display-mode: standalone)').matches
        || window.navigator.standalone === true;
    const dismissed = parseInt(localStorage.getItem('pwa_install_dismissed') || '0', 10);
    const THIRTY_DAYS = 30 * 24 * 60 * 60 * 1000;

    if (isStandalone || (Date.now() - dismissed) < THIRTY_DAYS) return;

    let deferredPrompt = null;

    // Capture the browser's beforeinstallprompt event
    window.addEventListener('beforeinstallprompt', function (e) {
        e.preventDefault();
        deferredPrompt = e;
        showBanner();
    });

    function showBanner() {
        // Don't inject twice
        if (document.getElementById('pwa-install-banner')) return;

        const banner = document.createElement('div');
        banner.id = 'pwa-install-banner';
        banner.innerHTML = `
            <div style="
                position:fixed; bottom:1.25rem; left:50%; transform:translateX(-50%);
                z-index:9999; max-width:380px; width:calc(100% - 2rem);
                background:#1e293b; color:#fff;
                border-radius:16px; padding:14px 18px;
                box-shadow:0 8px 32px rgba(0,0,0,.35);
                display:flex; align-items:center; gap:14px;
                animation:pwaSlideUp .35s cubic-bezier(.34,1.56,.64,1) forwards;
                font-family:'Inter',sans-serif; font-size:14px;
            " id="pwa-banner-inner">
                <div style="font-size:28px;flex-shrink:0">📱</div>
                <div style="flex:1;min-width:0">
                    <div style="font-weight:700;font-size:14px;margin-bottom:2px">Install Mascardi App</div>
                    <div style="color:rgba(255,255,255,.65);font-size:12px">Add to home screen for quick access — works offline!</div>
                </div>
                <div style="display:flex;flex-direction:column;gap:6px;flex-shrink:0">
                    <button id="pwa-install-btn" style="
                        background:linear-gradient(135deg,#3b82f6,#1d4ed8);
                        color:#fff; border:none; border-radius:8px;
                        padding:6px 14px; font-size:13px; font-weight:600;
                        cursor:pointer; white-space:nowrap;
                    ">Install</button>
                    <button id="pwa-dismiss-btn" style="
                        background:rgba(255,255,255,.08); color:rgba(255,255,255,.6);
                        border:1px solid rgba(255,255,255,.15); border-radius:8px;
                        padding:4px 10px; font-size:12px; cursor:pointer;
                    ">Not now</button>
                </div>
            </div>
            <style>
            @keyframes pwaSlideUp {
                from { opacity:0; transform:translateX(-50%) translateY(20px); }
                to   { opacity:1; transform:translateX(-50%) translateY(0); }
            }
            @keyframes pwaFadeOut {
                to { opacity:0; transform:translateX(-50%) translateY(16px); }
            }
            </style>
        `;
        document.body.appendChild(banner);

        // Install button
        document.getElementById('pwa-install-btn').addEventListener('click', async function () {
            if (!deferredPrompt) return;
            deferredPrompt.prompt();
            const { outcome } = await deferredPrompt.userChoice;
            deferredPrompt = null;
            if (outcome === 'accepted') {
                localStorage.setItem('pwa_install_dismissed', Date.now().toString());
            }
            hideBanner();
        });

        // Dismiss button
        document.getElementById('pwa-dismiss-btn').addEventListener('click', function () {
            localStorage.setItem('pwa_install_dismissed', Date.now().toString());
            hideBanner();
        });

        // Auto-hide after 12 seconds
        setTimeout(function () {
            if (document.getElementById('pwa-install-banner')) hideBanner();
        }, 12000);
    }

    function hideBanner() {
        const banner = document.getElementById('pwa-install-banner');
        if (!banner) return;
        const inner = document.getElementById('pwa-banner-inner');
        if (inner) {
            inner.style.animation = 'pwaFadeOut .25s ease forwards';
        }
        setTimeout(function () { banner.remove(); }, 260);
    }

    // Also handle appinstalled event to clean up
    window.addEventListener('appinstalled', function () {
        deferredPrompt = null;
        localStorage.setItem('pwa_install_dismissed', Date.now().toString());
        hideBanner();
    });
}());

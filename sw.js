/**
 * Mascardi Car Yard — Service Worker
 * Strategy:
 *   - App shell (CSS, JS, fonts):  Cache-first (CDN + local static)
 *   - HTML pages:                  Network-first → cache fallback → offline page
 *   - Images:                      Cache-first with background refresh
 *   - POST / API requests:         Network-only (never cache)
 */

const CACHE_SHELL   = 'mascardi-shell-v3';
const CACHE_PAGES   = 'mascardi-pages-v3';
const CACHE_IMAGES  = 'mascardi-images-v3';
const ALL_CACHES    = [CACHE_SHELL, CACHE_PAGES, CACHE_IMAGES];

// Detect the base path from where this SW is hosted
// e.g.  /mascardi/sw.js  →  BASE = '/mascardi'
const BASE = self.location.pathname.replace(/\/sw\.js$/, '');

const OFFLINE_URL = BASE + '/offline.php';

// Static shell assets to precache on install
const SHELL_URLS = [
    // CDN — Bootstrap, Font Awesome, jQuery, DataTables, Select2
    'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css',
    'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css',
    'https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js',
    'https://code.jquery.com/jquery-3.7.1.min.js',
    'https://cdn.datatables.net/1.13.8/css/dataTables.bootstrap5.min.css',
    'https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js',
    'https://cdn.datatables.net/1.13.8/js/dataTables.bootstrap5.min.js',
    'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css',
    'https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css',
    'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js',

    // App icons
    BASE + '/assets/images/icons/icon.svg',
    BASE + '/assets/images/icons/maskable.svg',
];

// ── Install ────────────────────────────────────────────────────────────────
self.addEventListener('install', event => {
    event.waitUntil(
        (async () => {
            // Cache the offline page unconditionally
            const pagesCache = await caches.open(CACHE_PAGES);
            await pagesCache.add(new Request(OFFLINE_URL, { cache: 'reload' }));

            // Cache shell assets — ignore individual failures (CDN may be offline)
            const shellCache = await caches.open(CACHE_SHELL);
            const results = await Promise.allSettled(
                SHELL_URLS.map(url =>
                    shellCache.add(url).catch(e => {
                        console.warn('[SW] Shell cache miss:', url, e.message);
                    })
                )
            );

            await self.skipWaiting();
        })()
    );
});

// ── Activate ───────────────────────────────────────────────────────────────
self.addEventListener('activate', event => {
    event.waitUntil(
        (async () => {
            // Remove old caches
            const keys = await caches.keys();
            await Promise.all(
                keys
                    .filter(key => !ALL_CACHES.includes(key))
                    .map(key => caches.delete(key))
            );
            await self.clients.claim();
        })()
    );
});

// ── Fetch ──────────────────────────────────────────────────────────────────
self.addEventListener('fetch', event => {
    const req = event.request;
    const url = new URL(req.url);

    // Never intercept non-GET (POST forms, CSRF etc.)
    if (req.method !== 'GET') return;

    // Never intercept login, logout, CSRF-sensitive URLs
    const sensitivePatterns = ['/login', '/logout', '/api/', 'csrf'];
    if (sensitivePatterns.some(p => url.pathname.includes(p))) return;

    // CDN assets → cache-first
    if (isCDNAsset(url)) {
        event.respondWith(cacheFirst(req, CACHE_SHELL));
        return;
    }

    // Local static assets (CSS, JS, fonts, icons) → cache-first
    if (isStaticAsset(url)) {
        event.respondWith(cacheFirst(req, CACHE_SHELL));
        return;
    }

    // Images → cache-first with background refresh
    if (isImage(url)) {
        event.respondWith(staleWhileRevalidate(req, CACHE_IMAGES));
        return;
    }

    // HTML pages (same origin) → network-first with offline fallback
    if (isSameOriginHTML(url)) {
        event.respondWith(networkFirstWithOffline(req));
        return;
    }
});

// ── Strategies ─────────────────────────────────────────────────────────────

async function cacheFirst(req, cacheName) {
    const cache    = await caches.open(cacheName);
    const cached   = await cache.match(req);
    if (cached) return cached;
    try {
        const response = await fetch(req);
        if (response.ok) cache.put(req, response.clone());
        return response;
    } catch {
        return new Response('Offline', { status: 503 });
    }
}

async function staleWhileRevalidate(req, cacheName) {
    const cache  = await caches.open(cacheName);
    const cached = await cache.match(req);
    const networkPromise = fetch(req)
        .then(res => { if (res.ok) cache.put(req, res.clone()); return res; })
        .catch(() => null);
    return cached || await networkPromise || new Response('', { status: 503 });
}

async function networkFirstWithOffline(req) {
    const cache = await caches.open(CACHE_PAGES);
    try {
        const response = await fetch(req);
        if (response.ok) {
            // Only cache same-origin, non-auth pages
            const url = new URL(req.url);
            const skip = ['/login', '/logout', '/portal'];
            if (!skip.some(p => url.pathname.includes(p))) {
                cache.put(req, response.clone());
            }
        }
        return response;
    } catch {
        const cached = await cache.match(req);
        if (cached) return cached;
        const offline = await cache.match(OFFLINE_URL);
        return offline || new Response('<h1>Offline</h1>', {
            headers: { 'Content-Type': 'text/html' },
            status: 503,
        });
    }
}

// ── URL classifiers ─────────────────────────────────────────────────────────

function isCDNAsset(url) {
    const cdnHosts = [
        'cdn.jsdelivr.net',
        'cdnjs.cloudflare.com',
        'code.jquery.com',
        'cdn.datatables.net',
        'fonts.googleapis.com',
        'fonts.gstatic.com',
    ];
    return cdnHosts.includes(url.hostname);
}

function isStaticAsset(url) {
    return url.hostname === self.location.hostname &&
        /\.(css|js|woff2?|ttf|otf|eot)(\?.*)?$/.test(url.pathname);
}

function isImage(url) {
    return /\.(png|jpg|jpeg|gif|webp|svg|ico)(\?.*)?$/.test(url.pathname);
}

function isSameOriginHTML(url) {
    return url.hostname === self.location.hostname &&
        !isStaticAsset(url) &&
        !isImage(url);
}

// ── Background sync / push placeholder ────────────────────────────────────
self.addEventListener('message', event => {
    if (event.data === 'skipWaiting') self.skipWaiting();
});

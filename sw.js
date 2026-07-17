/**
 * Mascardi Car Yard — Service Worker v7
 *
 * Strategy:
 *   HTML pages    → Network-first, cache fallback, offline page last resort
 *   Static assets → Cache-first (CSS, JS, fonts, local images)
 *   CDN origins   → Cache-first by hostname (covers fonts.googleapis.com CSS,
 *                   cdn.jsdelivr.net, cdnjs.cloudflare.com, etc.)
 *   API / POST    → Network-only (never cached)
 */

const VERSION    = 'v8';
const CACHE_NAME = 'mascardi-' + VERSION;
const BASE       = self.location.pathname.replace(/\/sw\.js$/, '');
const OFFLINE    = BASE + '/offline.php';

// Minimal shell to precache — only local files, no CDN (CDN failures break install)
const PRECACHE = [
    BASE + '/assets/images/icons/icon-192.png',
    BASE + '/assets/images/icons/icon-512.png',
    BASE + '/portal/track.php',   // public tracker available offline
];

// ── Install ────────────────────────────────────────────────────────────────
self.addEventListener('install', event => {
    event.waitUntil(
        caches.open(CACHE_NAME).then(async cache => {
            // Try to cache the offline page; silently skip if unavailable
            try {
                const res = await fetch(OFFLINE, { cache: 'no-store' });
                if (res.ok) await cache.put(OFFLINE, res);
            } catch (_) {}

            // Precache static assets — each wrapped so one failure doesn't abort
            await Promise.allSettled(
                PRECACHE.map(url => cache.add(url).catch(() => {}))
            );
        }).then(() => self.skipWaiting())
    );
});

// ── Activate ───────────────────────────────────────────────────────────────
self.addEventListener('activate', event => {
    event.waitUntil(
        caches.keys()
            .then(keys => Promise.all(
                keys.filter(k => k !== CACHE_NAME).map(k => caches.delete(k))
            ))
            .then(() => self.clients.claim())
    );
});

// CDN origins — always use cache-first regardless of path/extension
const CDN_HOSTS = new Set([
    'cdn.jsdelivr.net',
    'cdnjs.cloudflare.com',
    'cdn.datatables.net',
    'code.jquery.com',
    'fonts.googleapis.com',
    'fonts.gstatic.com',
]);

// ── Fetch ──────────────────────────────────────────────────────────────────
self.addEventListener('fetch', event => {
    const { request } = event;
    const url = new URL(request.url);

    // Only handle GET
    if (request.method !== 'GET') return;

    // Never intercept auth-sensitive paths
    if (/\/(login|logout|api\/)/.test(url.pathname)) return;

    // CDN assets — cache-first by hostname (covers fonts CSS, versioned libs, etc.)
    if (CDN_HOSTS.has(url.hostname)) {
        event.respondWith(cacheFirst(request));
        return;
    }

    // Local static files — cache-first by extension
    if (/\.(css|js|woff2?|ttf|png|jpg|jpeg|gif|webp|svg|ico)(\?|$)/.test(url.pathname)) {
        event.respondWith(cacheFirst(request));
        return;
    }

    // Same-origin HTML → network-first with offline fallback
    if (url.hostname === self.location.hostname) {
        event.respondWith(networkFirst(request));
    }
});

// ── Strategies ─────────────────────────────────────────────────────────────
async function cacheFirst(req) {
    const cached = await caches.match(req);
    if (cached) return cached;
    try {
        const res = await fetch(req);
        if (res.ok) {
            const cache = await caches.open(CACHE_NAME);
            cache.put(req, res.clone());
        }
        return res;
    } catch (_) {
        return new Response('', { status: 503 });
    }
}

async function networkFirst(req) {
    try {
        const res = await fetch(req);
        if (res.ok) {
            // Don't cache login/portal pages
            if (!/\/(login|portal)/.test(new URL(req.url).pathname)) {
                const cache = await caches.open(CACHE_NAME);
                cache.put(req, res.clone());
            }
        }
        return res;
    } catch (_) {
        const cached = await caches.match(req);
        if (cached) return cached;
        const offline = await caches.match(OFFLINE);
        return offline || new Response('<h1>Offline</h1>', {
            headers: { 'Content-Type': 'text/html' }, status: 503,
        });
    }
}

self.addEventListener('message', e => {
    if (e.data === 'skipWaiting') self.skipWaiting();
});

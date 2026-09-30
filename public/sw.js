/*
 * Cultiv One service worker.
 *
 * SECURITY POSTURE — this worker is deliberately NOT an offline cache.
 *
 * Every Cultiv One page is tenant-scoped and authenticated: the dashboard, sales,
 * stock, customers, purchasing and billing screens all render private workspace data
 * behind a session cookie. A stale HTML or JSON response served from Cache Storage can
 * therefore leak one workspace's data to another (or to a signed-out user on a shared
 * device), and a cached authenticated page would also pin an expired session. Offline
 * convenience is worth far less than not being able to leak financials, so the default
 * is NETWORK, ALWAYS.
 *
 * Concretely, the worker only handles two things:
 *   1. install/activate lifecycle + cache cleanup (so old caches cannot survive);
 *   2. a cache for PUBLIC, CONTENT-HASHED, UNAUTHENTICATED build assets
 *      (/build/*, icons, manifest).
 *
 * Anything else — HTML documents, /pos/calculate, /pos/checkout, /api/*, any
 * non-GET request — is left completely untouched: no respondWith(), so the browser
 * performs its normal network request. Those requests are never stored, so they can
 * never be replayed from cache.
 *
 * This keeps install/launch working (which is what a PWA must deliver) while making
 * offline data exposure impossible by construction rather than by careful bookkeeping.
 */

const VERSION = 'cultiv-one-v1';
const ASSET_CACHE = `${VERSION}-assets`;

// Same-origin, public, immutable assets only. Everything here is a Vite build output
// whose filename contains a content hash, or a static icon, so a cached response can
// never be stale and never contains tenant data.
const PRECACHE = [
    '/manifest.json',
    '/favicon.svg',
    '/pwa-192.png',
    '/pwa-512.png',
    '/pwa-maskable-512.png',
];

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(ASSET_CACHE)
            // addAll is atomic: one 404 would reject and leave the install incomplete.
            .then((cache) => Promise.allSettled(PRECACHE.map((url) => cache.add(url))))
            .then(() => self.skipWaiting())
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys()
            .then((keys) => Promise.all(
                keys.filter((key) => key !== ASSET_CACHE).map((key) => caches.delete(key))
            ))
            .then(() => self.clients.claim())
    );
});

/** True only for the hashed, public build assets this worker is allowed to cache. */
function isCacheableAsset(url) {
    if (url.origin !== self.location.origin) {
        return false;
    }

    return url.pathname.startsWith('/build/');
}

self.addEventListener('fetch', (event) => {
    const { request } = event;

    // POST/PUT/PATCH/DELETE carry CSRF + writes: never intercept, never cache.
    if (request.method !== 'GET') {
        return;
    }

    let url;
    try {
        url = new URL(request.url);
    } catch (e) {
        return;
    }

    // HTML documents (every page in the app) and any data endpoint go straight to the
    // network with no respondWith, so no page or API response is ever stored.
    if (request.mode === 'navigate' || url.pathname.startsWith('/api/')) {
        return;
    }

    if (!isCacheableAsset(url)) {
        return;
    }

    // Cache-first: the filename contains a content hash, so a hit is always correct,
    // and a miss is filled from the network for the next load.
    event.respondWith(
        caches.match(request).then((cached) => {
            if (cached) {
                return cached;
            }

            return fetch(request).then((response) => {
                // Only durable, successful, same-origin responses are stored.
                if (response.ok && response.type === 'basic') {
                    const copy = response.clone();
                    caches.open(ASSET_CACHE).then((cache) => cache.put(request, copy));
                }

                return response;
            });
        })
    );
});

// Allows the page to tell an old worker to go away, without a reload.
self.addEventListener('message', (event) => {
    if (event.data === 'SKIP_WAITING') {
        self.skipWaiting();
    }
});

/*
 * Kulmis Market service worker — makes repeat visits fast and shows a friendly
 * page when the network is gone. Deliberately small and careful:
 *  - versioned files (/build/…), product photos and icons: from the cache first
 *  - public pages: always from the network; a saved copy is used only when the
 *    network fails (offline), then /offline.html
 *  - never stored: anything signed-in or private (account, admin, cart,
 *    checkout, pay, API, sign-in) and any response that isn't "public"
 *  - phone notifications: the push carries no message; the worker asks the
 *    site (signed in, never cached) for safe lock-screen words like
 *    "New team earnings" — never an amount or a name
 * To switch it off, replace this file with one that calls self.registration.unregister().
 */
const VERSION = 'km-v1';
const STATIC = `${VERSION}-static`;
const PAGES = `${VERSION}-pages`;
const PRIVATE = /^\/(account|admin|api|cart|checkout|pay|login|register|logout|forgot|r)(\/|$)/;

self.addEventListener('install', (e) => {
    e.waitUntil(caches.open(PAGES).then((c) => c.add('/offline.html')).then(() => self.skipWaiting()));
});

self.addEventListener('activate', (e) => {
    e.waitUntil(caches.keys()
        .then((keys) => Promise.all(keys.filter((k) => !k.startsWith(VERSION)).map((k) => caches.delete(k))))
        .then(() => self.clients.claim()));
});

const isStatic = (url) => /^\/(build|storage|brand)\//.test(url.pathname) || /^\/(favicon\.(ico|svg)|apple-touch-icon\.png|og-default\.png)$/.test(url.pathname);

self.addEventListener('fetch', (e) => {
    const req = e.request;
    const url = new URL(req.url);
    if (req.method !== 'GET' || url.origin !== self.location.origin) return;

    if (isStatic(url)) {
        e.respondWith(caches.open(STATIC).then(async (cache) => {
            const hit = await cache.match(req);
            if (hit) return hit;
            const res = await fetch(req);
            if (res.ok && res.type === 'basic') cache.put(req, res.clone());
            return res;
        }));
        return;
    }

    if (req.mode === 'navigate' && !PRIVATE.test(url.pathname) && !url.search) {
        e.respondWith(fetch(req).then((res) => {
            const cc = res.headers.get('Cache-Control') || '';
            if (res.ok && res.type === 'basic' && cc.includes('public') && !cc.includes('no-store')) {
                const copy = res.clone();
                caches.open(PAGES).then((c) => c.put(req, copy));
            }
            return res;
        }).catch(async () => (await caches.match(req)) || caches.match('/offline.html')));
    }
});

// Signing out (or in) clears saved pages, so nothing personal or stale is ever shown offline.
self.addEventListener('message', (e) => {
    if (e.data === 'km-clear-pages') e.waitUntil(caches.delete(PAGES).then(() => caches.open(PAGES)).then((c) => c.add('/offline.html')));
});

self.addEventListener('push', (e) => {
    const fallback = { title: 'Kulmis Market', body: 'You have news. Tap to open.', url: '/account/notifications' };
    e.waitUntil(fetch('/account/notifications/latest', { credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json' } })
        .then((r) => (r.ok ? r.json() : Promise.reject(new Error('signed out'))))
        .then(({ data }) => data || fallback)
        .catch(() => fallback)
        .then((n) => self.registration.showNotification(n.title, { body: n.body, tag: 'km-news', renotify: true,
            icon: '/brand/icon-192.png', badge: '/brand/icon-192.png', data: { url: n.url } })));
});

self.addEventListener('notificationclick', (e) => {
    e.notification.close();
    const url = new URL(e.notification.data?.url || '/account/notifications', self.location.origin);
    if (url.origin !== self.location.origin) return; // only our own pages
    e.waitUntil(self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((list) => {
        const open = list.find((c) => new URL(c.url).origin === url.origin);
        return open ? open.navigate(url.href).then((c) => c?.focus()) : self.clients.openWindow(url.href);
    }));
});

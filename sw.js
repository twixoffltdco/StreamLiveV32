/* StreamLive PWA service worker — кэш оболочки, сеть для API */
const CACHE = 'sl-shell-v1';
const SHELL = [
  '/',
  '/manifest.webmanifest',
  '/assets/icons/icon-192.png',
  '/assets/icons/icon-512.png'
];

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE).then((c) => c.addAll(SHELL).catch(() => {})).then(() => self.skipWaiting())
  );
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((keys) =>
      Promise.all(keys.filter((k) => k !== CACHE).map((k) => caches.delete(k)))
    ).then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', (event) => {
  const req = event.request;
  if (req.method !== 'GET') return;

  const url = new URL(req.url);
  // API / poll / auth — только сеть
  if (
    url.pathname.indexOf('/api') === 0 ||
    url.pathname.indexOf('poll') !== -1 ||
    url.pathname.indexOf('/auth') === 0 ||
    url.pathname.indexOf('/admin') === 0
  ) {
    return;
  }

  // статика: cache-first
  if (/\.(css|js|png|jpg|jpeg|webp|svg|woff2|webmanifest)$/i.test(url.pathname)) {
    event.respondWith(
      caches.match(req).then((hit) => {
        const fetchPromise = fetch(req).then((res) => {
          if (res && res.ok) {
            const copy = res.clone();
            caches.open(CACHE).then((c) => c.put(req, copy));
          }
          return res;
        }).catch(() => hit);
        return hit || fetchPromise;
      })
    );
    return;
  }

  // HTML: network-first, fallback cache
  if (req.headers.get('accept') && req.headers.get('accept').indexOf('text/html') !== -1) {
    event.respondWith(
      fetch(req).then((res) => {
        if (res && res.ok) {
          const copy = res.clone();
          caches.open(CACHE).then((c) => c.put(req, copy));
        }
        return res;
      }).catch(() => caches.match(req).then((h) => h || caches.match('/')))
    );
  }
});

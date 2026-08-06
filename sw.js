/* StreamLive SW — installability + control */
const VERSION = 'sl-pwa-v3';
const SHELL = [
  '/',
  '/manifest.webmanifest',
  '/manifest.json',
  '/assets/icons/icon-192.png',
  '/assets/icons/icon-512.png',
  '/offline.html'
];

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(VERSION).then((c) => c.addAll(SHELL).catch(() => {})).then(() => self.skipWaiting())
  );
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((keys) =>
      Promise.all(keys.filter((k) => k !== VERSION).map((k) => caches.delete(k)))
    ).then(() => self.clients.claim())
  );
});

function isApi(url) {
  const p = url.pathname;
  return (
    p.indexOf('/api') === 0 ||
    p.indexOf('poll') !== -1 ||
    p.indexOf('/auth') === 0 ||
    p.indexOf('/admin') === 0 ||
    p.indexOf('/chat_') === 0 ||
    p.indexOf('/comment') === 0 ||
    p.indexOf('/message') === 0
  );
}

self.addEventListener('fetch', (event) => {
  const req = event.request;
  if (req.method !== 'GET') return;
  let url;
  try { url = new URL(req.url); } catch (e) { return; }
  if (url.origin !== self.location.origin) return;
  if (isApi(url)) return;

  if (/\.(css|js|png|jpg|jpeg|webp|gif|svg|woff2|webmanifest|json|ico)$/i.test(url.pathname)) {
    event.respondWith(
      caches.open(VERSION).then(async (cache) => {
        const cached = await cache.match(req);
        const network = fetch(req).then((res) => {
          if (res && res.ok) cache.put(req, res.clone());
          return res;
        }).catch(() => cached);
        return cached || network;
      })
    );
    return;
  }

  if (req.mode === 'navigate' || (req.headers.get('accept') || '').includes('text/html')) {
    event.respondWith(
      fetch(req).then((res) => {
        if (res && res.ok) {
          const copy = res.clone();
          caches.open(VERSION).then((c) => c.put(req, copy));
        }
        return res;
      }).catch(async () => {
        const cache = await caches.open(VERSION);
        return (await cache.match(req)) || (await cache.match('/offline.html')) || (await cache.match('/'));
      })
    );
  }
});

self.addEventListener('message', (event) => {
  if (event.data === 'SKIP_WAITING') self.skipWaiting();
});

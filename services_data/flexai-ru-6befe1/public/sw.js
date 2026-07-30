// FLEXAI Service Worker — production only.
// Strategy:
//  - Navigation requests: network-first, fall back to last cached HTML (offline shell).
//  - Static assets (js/css/images/fonts): stale-while-revalidate.
//  - API/server-fn POSTs: never cached (must be authoritative).

const VERSION = "flexai-v1";
const SHELL_CACHE = `${VERSION}-shell`;
const ASSET_CACHE = `${VERSION}-assets`;

self.addEventListener("install", (event) => {
  event.waitUntil(
    caches.open(SHELL_CACHE).then((c) => c.addAll(["/", "/manifest.webmanifest"]).catch(() => null))
  );
  self.skipWaiting();
});

self.addEventListener("activate", (event) => {
  event.waitUntil(
    caches.keys().then((keys) =>
      Promise.all(keys.filter((k) => !k.startsWith(VERSION)).map((k) => caches.delete(k)))
    ).then(() => self.clients.claim())
  );
});

const isAsset = (url) => /\.(?:js|css|woff2?|ttf|otf|png|jpg|jpeg|svg|webp|ico)$/i.test(url.pathname);

self.addEventListener("fetch", (event) => {
  const req = event.request;
  if (req.method !== "GET") return; // never cache writes
  const url = new URL(req.url);
  if (url.origin !== self.location.origin) return;
  // skip server-fn RPC endpoints
  if (url.pathname.startsWith("/_serverFn") || url.pathname.startsWith("/api/")) return;

  if (req.mode === "navigate") {
    event.respondWith(
      fetch(req).then((res) => {
        const copy = res.clone();
        caches.open(SHELL_CACHE).then((c) => c.put("/", copy)).catch(() => null);
        return res;
      }).catch(() => caches.match("/").then((r) => r || new Response("offline", { status: 503 })))
    );
    return;
  }

  if (isAsset(url)) {
    event.respondWith(
      caches.open(ASSET_CACHE).then(async (cache) => {
        const cached = await cache.match(req);
        const networkPromise = fetch(req).then((res) => {
          if (res.ok) cache.put(req, res.clone()).catch(() => null);
          return res;
        }).catch(() => cached);
        return cached || networkPromise;
      })
    );
  }
});

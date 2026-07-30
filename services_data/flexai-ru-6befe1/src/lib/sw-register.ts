// Register the service worker in production only. In dev/preview the SW would
// cache stale Vite-transformed modules and break HMR.
export function registerSW() {
  if (typeof window === "undefined") return;
  if (!("serviceWorker" in navigator)) return;
  if (!import.meta.env.PROD) return;
  window.addEventListener("load", () => {
    navigator.serviceWorker.register("/sw.js").catch(() => {/* ignore */});
  });
}

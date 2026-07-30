// Offline cache for the public catalog. Stores last successful list + timestamp
// in localStorage so users can browse when network is unavailable (RU/VPN issues).

const KEY = "flexai:catalog:v1";
const STALE_AFTER_MS = 15 * 60 * 1000; // 15 min

export type CachedCatalog<T> = { items: T[]; savedAt: number };

export function saveCatalog<T>(items: T[]) {
  try {
    localStorage.setItem(KEY, JSON.stringify({ items, savedAt: Date.now() }));
  } catch {/* quota */}
}

export function loadCatalog<T>(): CachedCatalog<T> | null {
  try {
    const raw = localStorage.getItem(KEY);
    if (!raw) return null;
    return JSON.parse(raw) as CachedCatalog<T>;
  } catch { return null; }
}

export function isStale(savedAt: number): boolean {
  return Date.now() - savedAt > STALE_AFTER_MS;
}

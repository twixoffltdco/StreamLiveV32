// Stable idempotency key per intent. Use crypto when available; fall back to time+random.
export function newIdempotencyKey(): string {
  try {
    if (typeof crypto !== "undefined" && "randomUUID" in crypto) {
      return (crypto as Crypto).randomUUID().replace(/-/g, "").slice(0, 32);
    }
  } catch {/* noop */}
  return `${Date.now().toString(36)}${Math.random().toString(36).slice(2, 14)}`;
}

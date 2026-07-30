import { useEffect, useState } from "react";

const KEY = "flexai_splash_shown";

export function Splash() {
  const [show, setShow] = useState(false);
  const [fade, setFade] = useState(false);

  useEffect(() => {
    if (typeof window === "undefined") return;
    if (sessionStorage.getItem(KEY)) return;
    setShow(true);
    sessionStorage.setItem(KEY, "1");
    const t1 = setTimeout(() => setFade(true), 1600);
    const t2 = setTimeout(() => setShow(false), 2200);
    return () => { clearTimeout(t1); clearTimeout(t2); };
  }, []);

  if (!show) return null;

  return (
    <div
      className={`fixed inset-0 z-[100] flex items-center justify-center transition-opacity duration-500 ${fade ? "opacity-0" : "opacity-100"}`}
      style={{ background: "radial-gradient(circle at 50% 50%, oklch(0.22 0.08 280), oklch(0.12 0.02 270) 70%)" }}
    >
      <div aria-hidden className="absolute inset-0 overflow-hidden">
        <div className="absolute top-1/4 left-1/4 w-72 h-72 rounded-full blur-3xl animate-pulse"
          style={{ background: "oklch(0.65 0.22 310 / 0.5)" }} />
        <div className="absolute bottom-1/4 right-1/4 w-72 h-72 rounded-full blur-3xl animate-pulse"
          style={{ background: "oklch(0.72 0.18 190 / 0.5)" }} />
      </div>
      <div className="relative text-center">
        <div className="mx-auto h-24 w-24 rounded-3xl flex items-center justify-center mb-4 animate-in zoom-in duration-700"
          style={{
            background: "var(--gradient-brand)",
            boxShadow: "0 0 60px -5px oklch(0.72 0.18 190 / 0.8), 0 0 120px -20px oklch(0.65 0.22 310 / 0.6)",
          }}>
          <span className="text-5xl">✨</span>
        </div>
        <h1 className="text-5xl font-black tracking-tight"
          style={{
            background: "linear-gradient(135deg, oklch(0.85 0.18 190), oklch(0.75 0.25 310))",
            WebkitBackgroundClip: "text",
            backgroundClip: "text",
            color: "transparent",
            textShadow: "0 0 40px oklch(0.72 0.18 190 / 0.5)",
          }}>
          FLEXAI
        </h1>
        <p className="text-xs uppercase tracking-[0.4em] text-white/60 mt-2">neon · ai · chat</p>
      </div>
    </div>
  );
}

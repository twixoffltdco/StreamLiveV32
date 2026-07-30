import { createFileRoute, Outlet, Link, useLocation, Navigate } from "@tanstack/react-router";
import { useAuth } from "@/lib/auth-context";
import { useServerFn } from "@tanstack/react-start";
import { useQuery } from "@tanstack/react-query";
import { getProfile } from "@/lib/flexai.functions";
import { Home, Plus, MessageSquare } from "lucide-react";
import { useEffect, useRef } from "react";
import { toast } from "sonner";

export const Route = createFileRoute("/_app")({ component: AppLayout });

function AppLayout() {
  const { user, loading } = useAuth();
  const location = useLocation();

  if (loading) return <div className="flex min-h-screen items-center justify-center text-muted-foreground">Загрузка…</div>;
  if (!user) return <Navigate to="/auth" />;

  const isCreate = location.pathname.startsWith("/create");
  const isChats = location.pathname.startsWith("/chats");
  const isHome = location.pathname === "/" || location.pathname.startsWith("/home") || location.pathname.startsWith("/profile");

  return (
    <div className="min-h-screen bg-background flex flex-col relative overflow-hidden">
      <BonusWatcher />
      {/* ambient glow */}
      <div aria-hidden className="pointer-events-none fixed inset-0 -z-0">
        <div className="absolute -top-32 -left-24 w-80 h-80 rounded-full bg-primary/15 blur-3xl" />
        <div className="absolute top-1/3 -right-24 w-80 h-80 rounded-full bg-accent/15 blur-3xl" />
      </div>

      <main className="flex-1 pb-28 relative z-10">
        <Outlet />
      </main>

      <nav className="fixed bottom-0 left-0 right-0 z-20 pb-[env(safe-area-inset-bottom)]">
        <div className="mx-auto max-w-md px-4 pb-3 pt-2">
          <div className="relative rounded-3xl bg-card/85 backdrop-blur-xl border border-border/70 shadow-2xl">
            <div className="grid grid-cols-3 items-center px-2 py-2">
              <Link to="/profile" className={`flex flex-col items-center gap-0.5 py-2 text-[11px] ${isHome ? "text-primary" : "text-muted-foreground"}`}>
                <Home className="h-5 w-5" />
                Главная
              </Link>
              <div className="flex justify-center -mt-8">
                <Link
                  to="/create"
                  className={`relative h-16 w-16 rounded-full flex items-center justify-center ${isCreate ? "scale-95" : ""} transition-transform`}
                  style={{ background: "var(--gradient-brand)", boxShadow: "var(--shadow-glow)" }}
                  aria-label="Создать персонажа"
                >
                  <span aria-hidden className="absolute inset-0 rounded-full ring-4 ring-primary/20 animate-pulse" />
                  <Plus className="h-7 w-7 text-primary-foreground relative" />
                </Link>
              </div>
              <Link to="/chats" className={`flex flex-col items-center gap-0.5 py-2 text-[11px] ${isChats ? "text-primary" : "text-muted-foreground"}`}>
                <MessageSquare className="h-5 w-5" />
                Чаты
              </Link>
            </div>
            <div className="grid grid-cols-3 px-4 pb-1 -mt-1 text-center">
              <span /> 
              <span className="text-[10px] text-muted-foreground">Создать</span>
              <span />
            </div>
          </div>
        </div>
      </nav>
    </div>
  );
}

/** Polls profile every 60s and notifies once when daily bonus becomes claimable. */
function BonusWatcher() {
  const fetchProfile = useServerFn(getProfile);
  const { data } = useQuery({
    queryKey: ["profile"],
    queryFn: () => fetchProfile(),
    refetchInterval: 60_000,
  });
  const notifiedRef = useRef(false);

  useEffect(() => {
    if (typeof window === "undefined") return;
    if (!data) return;
    if (!data.canClaim) { notifiedRef.current = false; return; }
    if (notifiedRef.current) return;
    notifiedRef.current = true;

    toast.success(`🎁 Ежедневный бонус готов! Забери +${data.limits.dailyBonus} монет`, {
      duration: 8000,
      action: { label: "Забрать", onClick: () => { window.location.href = "/profile"; } },
    });

    if ("Notification" in window && Notification.permission === "granted") {
      try {
        new Notification("FLEXAI: бонус готов 🎁", {
          body: `Забери +${data.limits.dailyBonus} монет в профиле`,
          icon: "/icon-192.png",
        });
      } catch { /* noop */ }
    }
  }, [data]);

  // Ask permission once on first visit (silent if denied)
  useEffect(() => {
    if (typeof window === "undefined") return;
    if (!("Notification" in window)) return;
    if (Notification.permission === "default") {
      const t = setTimeout(() => { Notification.requestPermission().catch(() => {}); }, 5000);
      return () => clearTimeout(t);
    }
  }, []);

  return null;
}

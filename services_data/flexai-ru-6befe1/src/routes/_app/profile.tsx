import { createFileRoute, Link, useNavigate } from "@tanstack/react-router";
import { useServerFn } from "@tanstack/react-start";
import { useQuery, useMutation, useQueryClient } from "@tanstack/react-query";
import { getProfile, claimDaily } from "@/lib/flexai.functions";
import { Button } from "@/components/ui/button";
import { Coins, LogOut, Gift, Sparkles, Clock, Download } from "lucide-react";
import { toast } from "sonner";
import { useAuth } from "@/lib/auth-context";
import { useEffect, useState } from "react";

type BIPEvent = Event & { prompt: () => Promise<void>; userChoice: Promise<{ outcome: string }> };

function useInstallPrompt() {
  const [evt, setEvt] = useState<BIPEvent | null>(null);
  const [installed, setInstalled] = useState(false);
  useEffect(() => {
    const handler = (e: Event) => { e.preventDefault(); setEvt(e as BIPEvent); };
    const installedHandler = () => { setInstalled(true); setEvt(null); };
    window.addEventListener("beforeinstallprompt", handler);
    window.addEventListener("appinstalled", installedHandler);
    if (window.matchMedia("(display-mode: standalone)").matches) setInstalled(true);
    return () => {
      window.removeEventListener("beforeinstallprompt", handler);
      window.removeEventListener("appinstalled", installedHandler);
    };
  }, []);
  return { canInstall: !!evt && !installed, installed, install: async () => {
    if (!evt) return;
    await evt.prompt();
    await evt.userChoice;
    setEvt(null);
  }};
}

export const Route = createFileRoute("/_app/profile")({ component: ProfilePage });

function useCountdown(target: string | null | undefined) {
  const [now, setNow] = useState(() => Date.now());
  useEffect(() => {
    if (!target) return;
    const i = setInterval(() => setNow(Date.now()), 1000);
    return () => clearInterval(i);
  }, [target]);
  if (!target) return null;
  const diff = new Date(target).getTime() - now;
  if (diff <= 0) return "00:00:00";
  const h = Math.floor(diff / 3_600_000);
  const m = Math.floor((diff % 3_600_000) / 60_000);
  const s = Math.floor((diff % 60_000) / 1000);
  return `${String(h).padStart(2, "0")}:${String(m).padStart(2, "0")}:${String(s).padStart(2, "0")}`;
}

function ProfilePage() {
  const navigate = useNavigate();
  const qc = useQueryClient();
  const { user, signOut } = useAuth();
  const fetchProfile = useServerFn(getProfile);
  const claim = useServerFn(claimDaily);

  const { data, isLoading, refetch } = useQuery({
    queryKey: ["profile"],
    queryFn: () => fetchProfile(),
    refetchInterval: 60_000,
  });

  const countdown = useCountdown(data?.canClaim ? null : data?.nextClaimAt);

  // Precise refetch+notify at the EXACT bonus-available moment (no minute drift).
  useEffect(() => {
    if (!data?.nextClaimAt || data.canClaim) return;
    const diff = new Date(data.nextClaimAt).getTime() - Date.now();
    if (diff <= 0) { refetch(); return; }
    const t = setTimeout(() => {
      refetch();
      toast.success("Дневной бонус доступен! Забери монеты 🎁");
      if (typeof Notification !== "undefined" && Notification.permission === "granted") {
        try { new Notification("FLEXAI", { body: "Дневной бонус доступен — забери монеты!" }); } catch {/* noop */}
      }
    }, diff + 250);
    return () => clearTimeout(t);
  }, [data?.nextClaimAt, data?.canClaim, refetch]);

  // Ask for notification permission once (when we know there's an upcoming bonus).
  useEffect(() => {
    if (typeof Notification === "undefined") return;
    if (Notification.permission === "default" && data && !data.canClaim) {
      Notification.requestPermission().catch(() => null);
    }
  }, [data]);

  const { canInstall, installed, install } = useInstallPrompt();

  const claimMut = useMutation({
    mutationFn: () => claim(),
    onSuccess: (r) => {
      qc.invalidateQueries({ queryKey: ["profile"] });
      toast.success(`+${r.awarded} монет! 🎉`);
    },
    onError: (e: Error) => toast.error(e.message),
  });

  return (
    <div className="px-4 pt-6 max-w-md mx-auto w-full">
      <div className="flex items-center gap-2 mb-6">
        <div className="h-10 w-10 rounded-2xl flex-gradient flex items-center justify-center flex-glow">
          <Sparkles className="h-5 w-5 text-primary-foreground" />
        </div>
        <h1 className="text-2xl font-bold flex-text-gradient">FLEXAI</h1>
      </div>

      <div className="rounded-3xl p-5 mb-4 flex-glow relative overflow-hidden border border-primary/30"
        style={{ background: "linear-gradient(135deg, oklch(0.25 0.05 270), oklch(0.22 0.07 310))" }}>
        <div className="flex items-center justify-between mb-1">
          <p className="text-xs text-white/70 uppercase tracking-wider">Баланс</p>
          <span className="text-xs text-white/60 truncate max-w-[55%]">{user?.email}</span>
        </div>
        <div className="flex items-baseline gap-2 mb-5">
          <Coins className="h-8 w-8 text-[var(--coin)]" />
          <span className="text-5xl font-bold text-white">{isLoading ? "…" : data?.profile?.coins ?? 0}</span>
        </div>

        <Button
          className="w-full h-12 text-base font-semibold"
          onClick={() => claimMut.mutate()}
          disabled={!data?.canClaim || claimMut.isPending}
        >
          <Gift className="h-5 w-5 mr-2" />
          {data?.canClaim ? `Забрать +${data.limits.dailyBonus} монет` : "Уже получено"}
        </Button>
        {!data?.canClaim && countdown && (
          <div className="flex items-center justify-center gap-1.5 mt-3 text-sm text-white/80">
            <Clock className="h-4 w-4" />
            <span className="font-mono tabular-nums">{countdown}</span>
            <span className="text-xs text-white/60">до следующего бонуса</span>
          </div>
        )}
      </div>

      {data && (
        <div className="bg-card/80 backdrop-blur border border-border rounded-2xl p-5 mb-4 space-y-3 text-sm">
          <p className="font-semibold mb-2">Сегодня (за 24 часа)</p>
          <Stat label="Персонажей создано" value={`${data.charactersToday} / ${data.limits.charactersPerDay}`} />
          <Stat label="Сообщений" value={`${data.messagesToday} / ${data.limits.messagesPerDay}`} />
          <div className="border-t border-border my-2" />
          <Stat label="Стоимость персонажа" value={`${data.limits.costCharacter} монет`} />
          <Stat label="Стоимость сообщения" value={`${data.limits.costMessage} монет`} />
        </div>
      )}

      {canInstall && (
        <Button
          variant="outline"
          className="w-full mb-4 h-12 border-primary/40 text-primary"
          onClick={install}
        >
          <Download className="h-4 w-4 mr-2" /> Установить приложение
        </Button>
      )}
      {installed && (
        <p className="text-xs text-muted-foreground text-center mb-4">✓ Приложение установлено</p>
      )}

      <div className="bg-card/80 backdrop-blur border border-border rounded-2xl p-3 mb-4 text-sm">
        <Link to="/chats" className="block px-2 py-2 rounded-lg hover:bg-muted/50">Мои чаты</Link>
        <Link to="/faq" className="block px-2 py-2 rounded-lg hover:bg-muted/50">FAQ</Link>
        <Link to="/docs" className="block px-2 py-2 rounded-lg hover:bg-muted/50">Документация</Link>
        <Link to="/terms" className="block px-2 py-2 rounded-lg hover:bg-muted/50">Условия использования</Link>
        <Link to="/privacy" className="block px-2 py-2 rounded-lg hover:bg-muted/50">Политика конфиденциальности</Link>
      </div>

      <Button
        variant="outline"
        className="w-full"
        onClick={async () => { await signOut(); navigate({ to: "/auth" }); }}
      >
        <LogOut className="h-4 w-4 mr-1.5" /> Выйти
      </Button>
    </div>
  );
}

function Stat({ label, value }: { label: string; value: string }) {
  return (
    <div className="flex justify-between">
      <span className="text-muted-foreground">{label}</span>
      <span className="font-medium">{value}</span>
    </div>
  );
}

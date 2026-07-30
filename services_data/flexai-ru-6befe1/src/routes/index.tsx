import { createFileRoute, Link, Navigate } from "@tanstack/react-router";
import { useAuth } from "@/lib/auth-context";
import { Sparkles, Coins, Users, Zap, Shield, Smartphone } from "lucide-react";

export const Route = createFileRoute("/")({
  head: () => ({
    meta: [
      { title: "FLEXAI — Возвращение GIGA. Создавай ИИ-персонажей и общайся" },
      { name: "description", content: "FLEXAI — наследник GIGA в России. Создавай ИИ-персонажей, общайся с публичными героями, зарабатывай монетки. Работает без VPN." },
    ],
  }),
  component: Landing,
});

function Landing() {
  const { user, loading } = useAuth();
  if (loading) return <div className="flex min-h-screen items-center justify-center text-muted-foreground">Загрузка…</div>;
  if (user) return <Navigate to="/chats" />;

  return (
    <div className="min-h-screen bg-background relative overflow-hidden">
      {/* glow background */}
      <div aria-hidden className="pointer-events-none fixed inset-0">
        <div className="absolute -top-40 left-1/2 -translate-x-1/2 w-[600px] h-[600px] rounded-full bg-primary/20 blur-[120px]" />
        <div className="absolute bottom-0 right-0 w-[400px] h-[400px] rounded-full bg-accent/20 blur-[100px]" />
      </div>

      <div className="relative z-10 px-5 pt-10 pb-20 max-w-md mx-auto">
        <header className="flex items-center justify-between mb-12">
          <div className="flex items-center gap-2">
            <div className="h-9 w-9 rounded-2xl flex-gradient flex items-center justify-center flex-glow">
              <Sparkles className="h-5 w-5 text-primary-foreground" />
            </div>
            <span className="font-bold text-lg flex-text-gradient">FLEXAI</span>
          </div>
          <Link to="/auth" className="text-sm text-muted-foreground">Войти</Link>
        </header>

        <section className="text-center mb-10">
          <div className="inline-block rounded-full px-3 py-1 bg-primary/10 border border-primary/30 text-xs text-primary mb-4">
            🇷🇺 Вернули GIGA в Россию
          </div>
          <h1 className="text-4xl font-extrabold mb-4 leading-tight">
            Создавай <span className="flex-text-gradient">ИИ-персонажей</span> и общайся
          </h1>
          <p className="text-muted-foreground mb-7">
            Кот, психолог, аниме-герой — оживи кого угодно. Заходи каждый день, забирай монетки,
            общайся с публичными персонажами других пользователей.
          </p>
          <Link
            to="/auth"
            className="inline-block w-full h-14 rounded-2xl text-base font-bold text-primary-foreground flex items-center justify-center"
            style={{ background: "var(--gradient-brand)", boxShadow: "var(--shadow-glow)" }}
          >
            Начать бесплатно
          </Link>
          <p className="text-xs text-muted-foreground mt-3">+50 монет на старте · работает без VPN</p>
        </section>

        <section className="grid grid-cols-2 gap-3 mb-10">
          <Feat icon={<Sparkles className="h-5 w-5" />} title="ИИ Gemini" text="Живые ответы в характере" />
          <Feat icon={<Users className="h-5 w-5" />} title="Публичные герои" text="Каталог персонажей сообщества" />
          <Feat icon={<Coins className="h-5 w-5" />} title="Монетки" text="Бонус каждые 24 часа" />
          <Feat icon={<Zap className="h-5 w-5" />} title="Быстро" text="Российские серверы, без VPN" />
          <Feat icon={<Smartphone className="h-5 w-5" />} title="На рабочий стол" text="Установи как приложение" />
          <Feat icon={<Shield className="h-5 w-5" />} title="Приватно" text="Твои переписки — только твои" />
        </section>

        <section className="rounded-3xl border border-border bg-card/60 backdrop-blur p-5 mb-8">
          <h2 className="font-bold mb-3">Как это работает</h2>
          <ol className="space-y-2.5 text-sm">
            <Step n={1} text="Регистрируешься по email" />
            <Step n={2} text="Получаешь 50 монет приветственного бонуса" />
            <Step n={3} text="Создаёшь персонажа (30 монет) или открываешь публичного" />
            <Step n={4} text="Общаешься (2 монеты за сообщение)" />
            <Step n={5} text="Каждый день забираешь +25 монет в профиле" />
          </ol>
        </section>

        <footer className="text-center text-xs text-muted-foreground space-x-3">
          <Link to="/terms">Условия</Link>
          <Link to="/privacy">Конфиденциальность</Link>
          <Link to="/docs">Документация</Link>
        </footer>
      </div>
    </div>
  );
}

function Feat({ icon, title, text }: { icon: React.ReactNode; title: string; text: string }) {
  return (
    <div className="rounded-2xl border border-border bg-card/60 backdrop-blur p-3">
      <div className="h-9 w-9 rounded-xl flex-gradient flex items-center justify-center text-primary-foreground mb-2">{icon}</div>
      <p className="font-semibold text-sm">{title}</p>
      <p className="text-xs text-muted-foreground">{text}</p>
    </div>
  );
}
function Step({ n, text }: { n: number; text: string }) {
  return (
    <li className="flex gap-3 items-start">
      <span className="shrink-0 h-6 w-6 rounded-full flex-gradient text-primary-foreground text-xs font-bold flex items-center justify-center">{n}</span>
      <span>{text}</span>
    </li>
  );
}

import { createFileRoute } from "@tanstack/react-router";
import { useServerFn } from "@tanstack/react-start";
import { useQuery, useMutation, useQueryClient } from "@tanstack/react-query";
import { getCharacterWithMessages, sendMessage, getProfile } from "@/lib/flexai.functions";
import { useAuth } from "@/lib/auth-context";
import { useEffect, useRef, useState } from "react";
import { Button } from "@/components/ui/button";
import { Send, Coins, Sparkles, ExternalLink } from "lucide-react";
import { toast } from "sonner";
import { supabase } from "@/integrations/supabase/client";
import { newIdempotencyKey } from "@/lib/idempotency";

export const Route = createFileRoute("/embed/$id")({ component: EmbedPage });

function EmbedPage() {
  const { id } = Route.useParams();
  const { user, loading } = useAuth();

  // Auto-resize parent iframe
  useEffect(() => {
    const post = () => {
      try {
        window.parent?.postMessage(
          { flexai: id, height: document.documentElement.scrollHeight },
          "*"
        );
      } catch {/* noop */}
    };
    post();
    const ro = new ResizeObserver(post);
    ro.observe(document.documentElement);
    return () => ro.disconnect();
  }, [id]);

  if (loading) {
    return <div className="min-h-screen flex items-center justify-center bg-background text-sm text-muted-foreground">…</div>;
  }
  if (!user) return <InlineAuth id={id} />;
  return <EmbedChat id={id} />;
}

function InlineAuth({ id }: { id: string }) {
  const [mode, setMode] = useState<"signin" | "signup">("signin");
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [busy, setBusy] = useState(false);

  const submit = async (e: React.FormEvent) => {
    e.preventDefault();
    setBusy(true);
    if (mode === "signin") {
      const { error } = await supabase.auth.signInWithPassword({ email, password });
      setBusy(false);
      if (error) return toast.error(error.message);
      toast.success("Готово!");
    } else {
      const { error } = await supabase.auth.signUp({
        email, password,
        options: { emailRedirectTo: `${window.location.origin}/embed/${id}` },
      });
      setBusy(false);
      if (error) return toast.error(error.message);
      toast.success("Письмо подтверждения отправлено на email");
    }
  };

  return (
    <div className="min-h-screen flex flex-col bg-background p-4">
      <div className="flex-1 flex items-center justify-center">
        <div className="w-full max-w-xs rounded-2xl border border-primary/30 p-5"
          style={{ background: "linear-gradient(135deg, oklch(0.22 0.05 270), oklch(0.2 0.07 310))", boxShadow: "var(--shadow-glow)" }}>
          <div className="flex items-center gap-2 mb-3">
            <div className="h-10 w-10 rounded-xl flex-gradient flex items-center justify-center flex-glow">
              <Sparkles className="h-5 w-5 text-primary-foreground" />
            </div>
            <h1 className="text-lg font-bold flex-text-gradient">FLEXAI</h1>
          </div>
          <p className="text-xs text-white/80 mb-4">
            {mode === "signin" ? "Войди, чтобы общаться с персонажем" : "Регистрация займёт 10 секунд"}
          </p>

          <form onSubmit={submit} className="space-y-2.5">
            <input type="email" required placeholder="Email" value={email}
              onChange={(e) => setEmail(e.target.value)}
              className="w-full h-10 px-3 rounded-lg bg-black/30 text-sm text-white placeholder:text-white/40 border border-white/10" />
            <input type="password" required minLength={6} placeholder="Пароль" value={password}
              onChange={(e) => setPassword(e.target.value)}
              className="w-full h-10 px-3 rounded-lg bg-black/30 text-sm text-white placeholder:text-white/40 border border-white/10" />
            <Button type="submit" className="w-full h-10" disabled={busy}>
              {busy ? "…" : mode === "signin" ? "Войти" : "Создать аккаунт"}
            </Button>
          </form>

          <button onClick={() => setMode(mode === "signin" ? "signup" : "signin")}
            className="w-full text-center text-[11px] text-white/60 mt-3 hover:text-white">
            {mode === "signin" ? "Нет аккаунта? Регистрация" : "Уже есть аккаунт? Войти"}
          </button>
        </div>
      </div>
      <FlexAIBanner />
    </div>
  );
}

function EmbedChat({ id }: { id: string }) {
  const qc = useQueryClient();
  const fetchChat = useServerFn(getCharacterWithMessages);
  const fetchProfile = useServerFn(getProfile);
  const send = useServerFn(sendMessage);
  const [input, setInput] = useState("");
  const scrollRef = useRef<HTMLDivElement>(null);

  const { data } = useQuery({ queryKey: ["chat", id], queryFn: () => fetchChat({ data: { id } }) });
  const { data: pd } = useQuery({ queryKey: ["profile"], queryFn: () => fetchProfile() });

  useEffect(() => {
    scrollRef.current?.scrollTo({ top: scrollRef.current.scrollHeight, behavior: "smooth" });
  }, [data?.messages.length]);

  const sendMut = useMutation({
    mutationFn: (content: string) =>
      send({ data: { characterId: id, content, idempotencyKey: newIdempotencyKey() } }),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ["chat", id] });
      qc.invalidateQueries({ queryKey: ["profile"] });
    },
    onError: (e: Error) => toast.error(e.message),
  });

  const remaining = pd ? pd.limits.messagesPerDay - pd.messagesToday : null;
  const blocked = remaining !== null && remaining <= 0;

  const onSend = () => {
    const t = input.trim();
    if (!t || sendMut.isPending || blocked) return;
    setInput("");
    sendMut.mutate(t);
  };

  if (!data) return <div className="p-4 text-xs text-muted-foreground">Загрузка…</div>;

  return (
    <div className="flex flex-col h-screen bg-background">
      <header className="flex items-center gap-2.5 px-3 py-2.5 border-b border-border bg-card/95">
        <div className="text-xl">{data.character.avatar_emoji}</div>
        <div className="flex-1 min-w-0">
          <p className="font-semibold text-sm truncate">{data.character.name}</p>
          <p className="text-[10px] text-muted-foreground">via FLEXAI</p>
        </div>
        <div className="flex items-center gap-1 text-[10px] text-muted-foreground">
          <Coins className="h-3 w-3 text-[var(--coin)]" /> {pd?.profile?.coins ?? "—"}
        </div>
      </header>

      <div ref={scrollRef} className="flex-1 overflow-y-auto px-3 py-3 space-y-2">
        {data.messages.map((m) => (
          <div key={m.id} className={m.role === "user" ? "flex justify-end" : "flex justify-start"}>
            <div className={`max-w-[85%] rounded-2xl px-3 py-1.5 text-sm whitespace-pre-wrap ${
              m.role === "user" ? "bg-primary text-primary-foreground rounded-br-sm" : "bg-muted"
            }`}>{m.content}</div>
          </div>
        ))}
        {sendMut.isPending && <div className="text-xs text-muted-foreground italic">печатает…</div>}
      </div>

      <div className="border-t border-border bg-card p-2">
        <div className="flex gap-1.5">
          <input
            value={input}
            onChange={(e) => setInput(e.target.value)}
            onKeyDown={(e) => { if (e.key === "Enter") { e.preventDefault(); onSend(); } }}
            disabled={blocked || sendMut.isPending}
            placeholder={blocked ? "Лимит 5/24ч исчерпан" : "Сообщение…"}
            className="flex-1 h-9 px-3 rounded-xl bg-muted text-sm disabled:opacity-50"
          />
          <Button size="icon" className="h-9 w-9" onClick={onSend} disabled={blocked || sendMut.isPending || !input.trim()}>
            <Send className="h-3.5 w-3.5" />
          </Button>
        </div>
        <p className="text-[9px] text-muted-foreground text-center mt-1">
          {remaining !== null
            ? `Осталось ${Math.max(0, remaining)}/${pd?.limits.messagesPerDay} · ${pd?.limits.costMessage} монет`
            : "—"}
        </p>
      </div>
      <FlexAIBanner />
    </div>
  );
}

function FlexAIBanner() {
  return (
    <a href="https://flexai-ru.lovable.app" target="_blank" rel="noopener"
      className="block border-t border-primary/30 px-3 py-2 text-center"
      style={{ background: "linear-gradient(90deg, oklch(0.28 0.12 280), oklch(0.3 0.15 310))" }}>
      <p className="text-[10px] text-white/90 font-medium flex items-center justify-center gap-1.5">
        <Sparkles className="h-3 w-3" />
        Для большего функционала — открой <b>FlexAI</b>
        <ExternalLink className="h-3 w-3" />
      </p>
    </a>
  );
}

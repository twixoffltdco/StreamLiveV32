import { createFileRoute, Link, useNavigate } from "@tanstack/react-router";
import { useServerFn } from "@tanstack/react-start";
import { useQuery, useMutation, useQueryClient } from "@tanstack/react-query";
import { listCharacters, deleteCharacter, getProfile, listPublicCharacters } from "@/lib/flexai.functions";
import { Button } from "@/components/ui/button";
import { Coins, Plus, Trash2, Sparkles, Search, Users, MessageCircle, Code2, WifiOff } from "lucide-react";
import { toast } from "sonner";
import { useState, useMemo, useEffect } from "react";
import { EmbedDialog } from "@/components/EmbedDialog";
import { saveCatalog, loadCatalog, isStale } from "@/lib/catalog-cache";

export const Route = createFileRoute("/_app/chats")({ component: ChatsPage });

const CATEGORIES = ["Все", "Питомец", "Помощник", "Магия", "Аниме", "Семья", "Креатив", "Спорт", "Романтика", "Игры", "Прочее"];
type SortKey = "popular" | "new";
type PublicChar = Awaited<ReturnType<typeof listPublicCharacters>>[number];

function ChatsPage() {
  const navigate = useNavigate();
  const qc = useQueryClient();
  const fetchChars = useServerFn(listCharacters);
  const fetchPublic = useServerFn(listPublicCharacters);
  const fetchProfile = useServerFn(getProfile);
  const delChar = useServerFn(deleteCharacter);
  const [q, setQ] = useState("");
  const [tab, setTab] = useState<"mine" | "public">("public");
  const [cat, setCat] = useState("Все");
  const [sort, setSort] = useState<SortKey>("popular");
  const [embed, setEmbed] = useState<{ id: string; name: string } | null>(null);
  const [offlineCache, setOfflineCache] = useState<{ items: PublicChar[]; savedAt: number } | null>(null);

  const { data: chars = [], isLoading } = useQuery({ queryKey: ["chars"], queryFn: () => fetchChars() });
  const publicQuery = useQuery({
    queryKey: ["public-chars"],
    queryFn: async () => {
      const items = await fetchPublic();
      saveCatalog(items);
      return items;
    },
    retry: 1,
  });
  const { data: profileData } = useQuery({ queryKey: ["profile"], queryFn: () => fetchProfile() });

  // On error, hydrate from offline cache
  useEffect(() => {
    if (publicQuery.isError && !offlineCache) {
      const cached = loadCatalog<PublicChar>();
      if (cached) {
        setOfflineCache(cached);
        toast.warning("Каталог загружен из кэша — нет сети");
      }
    }
  }, [publicQuery.isError, offlineCache]);

  const publicChars: PublicChar[] = publicQuery.data ?? offlineCache?.items ?? [];
  const showStale = publicQuery.isError && offlineCache && isStale(offlineCache.savedAt);

  const delMut = useMutation({
    mutationFn: (id: string) => delChar({ data: { id } }),
    onSuccess: () => { qc.invalidateQueries({ queryKey: ["chars"] }); qc.invalidateQueries({ queryKey: ["public-chars"] }); toast.success("Персонаж удалён"); },
    onError: (e: Error) => toast.error(e.message),
  });

  const baseList = tab === "mine" ? chars : publicChars;
  const filtered = useMemo(() => {
    let list = baseList;
    if (q) list = list.filter((c) => c.name.toLowerCase().includes(q.toLowerCase()));
    if (tab === "public" && cat !== "Все") list = list.filter((c) => "category" in c && c.category === cat);
    if (tab === "public") {
      list = [...list].sort((a, b) => {
        if (sort === "popular") {
          const ac = "chat_count" in a ? a.chat_count : 0;
          const bc = "chat_count" in b ? b.chat_count : 0;
          return bc - ac;
        }
        return new Date(b.created_at).getTime() - new Date(a.created_at).getTime();
      });
    }
    return list;
  }, [baseList, q, cat, sort, tab]);

  const featured = publicChars[0] ?? chars[0];

  return (
    <div className="px-4 pt-5 max-w-md mx-auto w-full">
      <Link to="/profile" className="block mb-4">
        <div className="rounded-2xl px-4 py-3 flex items-center gap-3"
          style={{ background: "linear-gradient(135deg, oklch(0.35 0.13 265), oklch(0.42 0.18 290))" }}>
          <div className="h-10 w-10 rounded-full bg-white/20 flex items-center justify-center text-lg">✨</div>
          <div className="flex-1 min-w-0">
            <p className="text-white font-semibold truncate text-sm">FlexAI пользователь</p>
            <p className="text-white/70 text-xs">Перейти в профиль</p>
          </div>
          <div className="flex items-center gap-1.5 rounded-full bg-black/30 px-3 py-1 text-sm text-white">
            <Coins className="h-4 w-4 text-[var(--coin)]" />
            <span className="font-semibold">{profileData?.profile?.coins ?? "—"}</span>
          </div>
        </div>
      </Link>

      <div className="relative mb-4">
        <Search className="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-muted-foreground" />
        <input value={q} onChange={(e) => setQ(e.target.value)} placeholder="Найти персонажа"
          className="w-full pl-10 pr-12 h-11 rounded-2xl bg-card/80 backdrop-blur border border-border text-sm focus:outline-none focus:border-primary" />
        <button onClick={() => baseList.length && navigate({ to: "/chat/$id", params: { id: baseList[Math.floor(Math.random() * baseList.length)].id } })}
          className="absolute right-1.5 top-1/2 -translate-y-1/2 h-8 w-8 rounded-xl flex items-center justify-center"
          style={{ background: "var(--gradient-brand)" }} aria-label="Случайный">🎲</button>
      </div>

      {publicQuery.isError && offlineCache && (
        <div className="mb-3 rounded-xl border border-amber-500/40 bg-amber-500/10 px-3 py-2 text-xs flex items-center gap-2">
          <WifiOff className="h-3.5 w-3.5 text-amber-400" />
          <span className="flex-1">
            Нет сети — показан кэш{showStale ? " (устарел)" : ""}.
          </span>
          <button onClick={() => publicQuery.refetch()} className="text-amber-300 underline">Обновить</button>
        </div>
      )}

      {featured && (
        <section className="mb-5">
          <div className="flex items-center justify-between mb-2">
            <h2 className="text-xl font-bold">В топе</h2>
            <span className="text-xs text-muted-foreground">Лучшие персонажи</span>
          </div>
          <Link to="/chat/$id" params={{ id: featured.id }}
            className="block relative rounded-3xl overflow-hidden p-6 flex-glow border border-primary/30"
            style={{ background: "radial-gradient(circle at 50% 30%, oklch(0.35 0.18 200 / 0.6), oklch(0.18 0.04 270) 70%)" }}>
            <div className="flex flex-col items-center text-center">
              <div className="h-24 w-24 rounded-full bg-card flex items-center justify-center text-5xl border-4 border-primary/40 mb-3"
                style={{ boxShadow: "var(--shadow-glow)" }}>{featured.avatar_emoji}</div>
              <div className="rounded-full bg-card/90 px-4 py-1.5 text-sm font-medium">{featured.name}</div>
              <p className="text-xs text-white/70 mt-2 line-clamp-2 max-w-[80%]">
                {featured.description || "Готов к диалогу"}
              </p>
            </div>
          </Link>
        </section>
      )}

      {/* Tabs */}
      <div className="flex gap-2 mb-3 p-1 rounded-2xl bg-card/60 border border-border">
        <button onClick={() => setTab("public")}
          className={`flex-1 h-10 rounded-xl text-sm font-medium flex items-center justify-center gap-1.5 transition ${
            tab === "public" ? "bg-primary text-primary-foreground" : "text-muted-foreground"
          }`}>
          <Users className="h-4 w-4" /> Каталог
          <span className="text-[10px] opacity-70">({publicChars.length})</span>
        </button>
        <button onClick={() => setTab("mine")}
          className={`flex-1 h-10 rounded-xl text-sm font-medium flex items-center justify-center gap-1.5 transition ${
            tab === "mine" ? "bg-primary text-primary-foreground" : "text-muted-foreground"
          }`}>
          <MessageCircle className="h-4 w-4" /> Мои
          <span className="text-[10px] opacity-70">({chars.length})</span>
        </button>
      </div>

      {tab === "public" && (
        <>
          {/* Categories */}
          <div className="-mx-4 px-4 mb-2 overflow-x-auto">
            <div className="flex gap-1.5 pb-2">
              {CATEGORIES.map((c) => (
                <button key={c} onClick={() => setCat(c)}
                  className={`shrink-0 px-3 h-8 rounded-full text-xs font-medium border transition ${
                    cat === c ? "bg-primary text-primary-foreground border-primary" : "bg-card border-border text-muted-foreground"
                  }`}>{c}</button>
              ))}
            </div>
          </div>
          {/* Sort */}
          <div className="flex gap-2 mb-3 text-xs">
            <button onClick={() => setSort("popular")}
              className={`px-3 h-7 rounded-full border ${sort === "popular" ? "bg-accent/30 border-accent text-foreground" : "bg-card/50 border-border text-muted-foreground"}`}>
              🔥 По популярности
            </button>
            <button onClick={() => setSort("new")}
              className={`px-3 h-7 rounded-full border ${sort === "new" ? "bg-accent/30 border-accent text-foreground" : "bg-card/50 border-border text-muted-foreground"}`}>
              ✨ Новые
            </button>
          </div>
        </>
      )}

      {isLoading && <p className="text-muted-foreground text-sm">Загрузка…</p>}

      {!isLoading && filtered.length === 0 && tab === "mine" && (
        <div className="text-center py-10">
          <div className="h-20 w-20 mx-auto rounded-3xl flex-gradient flex items-center justify-center flex-glow mb-4">
            <Sparkles className="h-10 w-10 text-primary-foreground" />
          </div>
          <h3 className="text-lg font-semibold mb-1">Создай первого персонажа</h3>
          <p className="text-sm text-muted-foreground mb-6">Кот, психолог, аниме-герой — кто угодно</p>
          <Button onClick={() => navigate({ to: "/create" })}>
            <Plus className="h-4 w-4 mr-1" /> Создать персонажа
          </Button>
        </div>
      )}

      {!isLoading && filtered.length === 0 && tab === "public" && (
        <p className="text-center text-sm text-muted-foreground py-10">
          Ничего не найдено. Попробуй другую категорию.
        </p>
      )}

      <ul className="space-y-2.5">
        {filtered.map((c) => {
          const isMine = "user_id" in c && c.user_id === profileData?.profile?.id;
          const chatCount: number = "chat_count" in c && typeof c.chat_count === "number" ? c.chat_count : 0;
          const isPublic = "is_public" in c ? Boolean(c.is_public) : tab === "public";
          return (
            <li key={c.id} className="bg-card/80 backdrop-blur border border-border rounded-2xl p-3 flex items-center gap-2">
              <Link to="/chat/$id" params={{ id: c.id }} className="flex items-center gap-3 flex-1 min-w-0">
                <div className="h-12 w-12 rounded-2xl flex items-center justify-center text-2xl shrink-0"
                  style={{ background: "var(--gradient-brand)", boxShadow: "var(--shadow-glow)" }}>
                  {c.avatar_emoji}
                </div>
                <div className="min-w-0">
                  <p className="font-semibold truncate">{c.name}</p>
                  <p className="text-xs text-muted-foreground truncate">{c.description || "Без описания"}</p>
                  {tab === "public" && chatCount > 0 && (
                    <p className="text-[10px] text-primary mt-0.5">💬 {chatCount} разговоров</p>
                  )}
                </div>
              </Link>
              {isPublic && (
                <button onClick={() => setEmbed({ id: c.id, name: c.name })}
                  className="text-muted-foreground hover:text-primary p-2" aria-label="Встроить" title="Встроить на сайт">
                  <Code2 className="h-4 w-4" />
                </button>
              )}
              {(tab === "mine" || isMine) && (
                <button onClick={() => { if (confirm(`Удалить ${c.name}?`)) delMut.mutate(c.id); }}
                  className="text-muted-foreground hover:text-destructive p-2" aria-label="Удалить">
                  <Trash2 className="h-4 w-4" />
                </button>
              )}
            </li>
          );
        })}
      </ul>

      {embed && <EmbedDialog characterId={embed.id} characterName={embed.name} onClose={() => setEmbed(null)} />}
    </div>
  );
}

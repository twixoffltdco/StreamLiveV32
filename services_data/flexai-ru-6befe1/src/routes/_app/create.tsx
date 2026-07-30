import { createFileRoute, useNavigate } from "@tanstack/react-router";
import { useState } from "react";
import { useServerFn } from "@tanstack/react-start";
import { useMutation, useQueryClient, useQuery } from "@tanstack/react-query";
import { createCharacter, getProfile } from "@/lib/flexai.functions";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Textarea } from "@/components/ui/textarea";
import { Coins, Globe, Lock } from "lucide-react";
import { toast } from "sonner";
import { newIdempotencyKey } from "@/lib/idempotency";

export const Route = createFileRoute("/_app/create")({ component: CreatePage });

const EMOJIS = [
  // robots/fantasy/mythical
  "🤖","👾","🧙","🧙‍♀️","🧙‍♂️","🧚","🧚‍♀️","🧚‍♂️","🧛","🧛‍♀️","🧛‍♂️","🧜","🧜‍♀️","🧜‍♂️","🧞","🧞‍♀️","🧞‍♂️","🧟","🧟‍♀️","🧟‍♂️",
  "🦸","🦸‍♀️","🦸‍♂️","🦹","🦹‍♀️","🦹‍♂️","🥷","🧝","🧝‍♀️","🧝‍♂️","🧌","👼","👻","💀","☠️","🤡","👽","🛸","🪄","🔮",
  // animals
  "🐱","🐶","🐰","🐻","🐼","🦁","🐯","🐸","🐵","🦝","🦉","🐺","🐲","🐉","🦊","🦄","🐴","🦓","🦒","🐮",
  "🐷","🐗","🐭","🐹","🐨","🐧","🐦","🐤","🦅","🦆","🦢","🦩","🦚","🦜","🐝","🦋","🐛","🐌","🐞","🕷️",
  "🦂","🦞","🦀","🐙","🦑","🦈","🐬","🐳","🐋","🐠","🐟","🐡","🦭","🦦","🦥","🦔","🦡","🐿️","🦨","🦘",
  "🐢","🦎","🐊","🐍","🦖","🦕","🦌","🐃","🐂","🐄","🦬","🐎","🦏","🦛","🐘","🦣","🐫","🐪","🦙","🦃",
  // people / pro
  "👨‍🚀","👩‍🚀","👨‍🎨","👩‍🎨","👨‍🍳","👩‍🍳","👨‍⚕️","👩‍⚕️","👨‍🏫","👩‍🏫","👨‍💻","👩‍💻","👨‍🔬","👩‍🔬","👨‍🎤","👩‍🎤","👨‍🎓","👩‍🎓","👮","🕵️",
  "💂","🧑‍✈️","🧑‍🚒","🧑‍⚖️","🧑‍🌾","🧑‍🔧","🧑‍🏭","🧑‍💼","👸","🤴","🧑‍🎄","🤶","🎅","🧖","🧗","🧘","🏃","🏄","🏊","🚴",
  "👵","👴","🧓","👶","💪","🏋️","⛹️","🤸","🤽","🤾","🤺","🏇","⛷️","🏂","🪂","🏌️","🚣","🤹","🧑‍🤝‍🧑","👯",
  // expressions
  "😺","😸","😹","😻","😼","😽","🙀","😿","😾","😀","😃","😄","😁","😆","😅","🤣","😂","🙂","🙃","😉",
  "😊","😇","🥰","😍","🤩","😘","😗","😚","😙","🥲","😋","😛","😜","🤪","😝","🤑","🤗","🤭","🤫","🤔",
  "🤐","🤨","😐","😑","😶","😏","😒","🙄","😬","🤥","😌","😔","😪","🤤","😴","😷","🤒","🤕","🤢","🤮",
  // magic / symbols / objects
  "🌟","✨","💫","⭐","🌠","☄️","🌈","🔥","💥","💢","💯","💎","👑","💍","🪬","🧿","☯️","🪷","🍀","🌸",
  "🌺","🌻","🌷","🌹","💐","🌵","🌿","🍄","🎃","☃️","⚡","🌙","☀️","🌎","🪐","🚀","🎭","🎨","🎮","🎲",
  "🎰","🃏","🎼","🎷","🎺","🥁","🎸","🪕","🎻","🎤","🎬","📚","✏️","🖋️","🪶","🗝️","🔑","💡","🔭","🔬",
  "⚔️","🛡️","🏹","🎯","🪖","🎒","🧸","🪅","💭","🕊️","🪽","🪩","🧊","🍕","🍔","🍩","🍦","🍫","☕","🍵"
];

const CATEGORIES = ["Питомец","Помощник","Магия","Аниме","Семья","Креатив","Спорт","Романтика","Игры","Прочее"];

type Preset = {
  name: string; emoji: string; description: string; personality: string;
  greeting: string; badge: string; category: string;
};

const PRESETS: Preset[] = [
  { name: "Котик", emoji: "🐱", badge: "Питомец", category: "Питомец",
    description: "Милый говорящий кот",
    personality: "Игривый, ласковый, иногда дерзкий. Часто мурчит, любит рыбку. Использует кошачьи звуки: мур, мяу.",
    greeting: "Мур-мяу! Погладишь меня?" },
  { name: "Пёсик", emoji: "🐶", badge: "Питомец", category: "Питомец",
    description: "Самый верный друг",
    personality: "Добрый, восторженный, всегда рад. Виляет хвостом, любит играть и угощения. Заканчивает фразы на 'гав!'",
    greeting: "Гав-гав! Пойдём играть?" },
  { name: "Психолог", emoji: "🧘", badge: "Помощник", category: "Помощник",
    description: "Помогу найти гармонию",
    personality: "Спокойный, эмпатичный, задаёт открытые вопросы, никого не осуждает. Говорит мягко.",
    greeting: "Здравствуй. Что у тебя на душе сегодня?" },
  { name: "Астролог", emoji: "🔮", badge: "Магия", category: "Магия",
    description: "Звёзды расскажут всё",
    personality: "Мистический, говорит о знаках зодиака, ретроградах, предсказаниях. Любит метафоры.",
    greeting: "Звёзды шепчут... Назови свой знак." },
  { name: "Аниме-герой", emoji: "⚔️", badge: "Аниме", category: "Аниме",
    description: "Дерзкий протагонист",
    personality: "Самоуверенный, использует японские слова (нани?, бака!), любит драматичные речи о дружбе.",
    greeting: "Что? Ты бросаешь мне вызов?!" },
  { name: "Бабушка", emoji: "👵", badge: "Семья", category: "Семья",
    description: "Добрая и мудрая",
    personality: "Заботливая, рассказывает истории из жизни, советует поесть пирожков, переживает за всех.",
    greeting: "Ой, внучек! Ты кушал сегодня?" },
  { name: "Контент-мейкер", emoji: "🎨", badge: "Креатив", category: "Креатив",
    description: "Идеи для контента",
    personality: "Креативный, помогает с идеями для постов, сценариев, дизайна. Энергичный и трендовый.",
    greeting: "Привет! Какой контент будем создавать?" },
  { name: "Тренер", emoji: "💪", badge: "Спорт", category: "Спорт",
    description: "Жми, не сдавайся!",
    personality: "Мотивирующий, требовательный, говорит короткими фразами, любит цифры подходов и калории.",
    greeting: "Готов к тренировке? Поехали!" },
];

function CreatePage() {
  const navigate = useNavigate();
  const qc = useQueryClient();
  const create = useServerFn(createCharacter);
  const fetchProfile = useServerFn(getProfile);
  const { data: pd } = useQuery({ queryKey: ["profile"], queryFn: () => fetchProfile() });

  const [form, setForm] = useState({
    name: "", description: "", personality: "",
    avatar_emoji: "🤖", greeting: "Привет! Рад знакомству.",
    is_public: false, category: "Прочее",
  });

  const mut = useMutation({
    mutationFn: () => create({ data: { ...form, idempotencyKey: newIdempotencyKey() } }),
    onSuccess: (c) => {
      qc.invalidateQueries({ queryKey: ["chars"] });
      qc.invalidateQueries({ queryKey: ["profile"] });
      qc.invalidateQueries({ queryKey: ["public-chars"] });
      toast.success(`Персонаж "${c.name}" создан!`);
      navigate({ to: "/chat/$id", params: { id: c.id } });
    },
    onError: (e: Error) => toast.error(e.message),
  });

  const applyPreset = (p: Preset) => {
    setForm((f) => ({
      ...f,
      name: p.name, description: p.description, personality: p.personality,
      avatar_emoji: p.emoji, greeting: p.greeting, category: p.category,
    }));
    toast.success(`Шаблон "${p.name}" применён`);
  };

  const limitReached = pd ? pd.charactersToday >= pd.limits.charactersPerDay : false;
  const notEnoughCoins = pd ? (pd.profile?.coins ?? 0) < pd.limits.costCharacter : false;

  return (
    <div className="px-4 pt-6 max-w-md mx-auto w-full">
      <div className="flex items-center justify-between mb-5">
        <h1 className="text-2xl font-bold">Новый персонаж</h1>
        <div className="flex items-center gap-1.5 rounded-full bg-card border border-border px-3 py-1.5 text-sm">
          <Coins className="h-4 w-4 text-[var(--coin)]" />
          <span className="font-semibold">{pd?.profile?.coins ?? "—"}</span>
        </div>
      </div>

      <div className="rounded-2xl border border-border bg-card/60 px-3 py-2.5 mb-5 text-xs text-muted-foreground">
        Стоимость: <b className="text-foreground">{pd?.limits.costCharacter ?? 30} монет</b>.
        Лимит: <b className="text-foreground">{pd?.limits.charactersPerDay ?? 1}/24ч</b>
        {pd && <> · сегодня {pd.charactersToday}/{pd.limits.charactersPerDay}</>}
      </div>

      <div className="mb-6">
        <p className="text-sm font-semibold mb-3">Готовые шаблоны</p>
        <div className="grid grid-cols-2 gap-2.5">
          {PRESETS.map((p) => {
            const active = form.name === p.name;
            return (
              <button key={p.name} type="button" onClick={() => applyPreset(p)}
                className={`relative text-left rounded-2xl p-3 border transition ${
                  active ? "border-primary bg-primary/10 flex-glow" : "border-border bg-card/70"
                }`}>
                <div className="flex items-center gap-2 mb-1">
                  <div className="h-10 w-10 rounded-xl flex items-center justify-center text-2xl"
                    style={{ background: active ? "var(--gradient-brand)" : "oklch(0.26 0.03 275)" }}>
                    {p.emoji}
                  </div>
                  <div className="min-w-0">
                    <p className="font-semibold text-sm truncate">{p.name}</p>
                    <p className="text-[10px] text-muted-foreground uppercase">{p.badge}</p>
                  </div>
                </div>
                <p className="text-[11px] text-muted-foreground line-clamp-2">{p.description}</p>
              </button>
            );
          })}
        </div>
      </div>

      <form onSubmit={(e) => { e.preventDefault(); mut.mutate(); }} className="space-y-4">
        <div>
          <Label>Аватар <span className="text-xs text-muted-foreground">({EMOJIS.length} вариантов)</span></Label>
          <div className="grid grid-cols-8 gap-1.5 mt-2 max-h-48 overflow-y-auto p-2 rounded-xl border border-border bg-card/40">
            {EMOJIS.map((e, i) => (
              <button type="button" key={`${e}-${i}`} onClick={() => setForm({ ...form, avatar_emoji: e })}
                className={`text-xl h-10 w-10 rounded-lg border ${form.avatar_emoji === e ? "border-primary bg-primary/10" : "border-transparent"}`}
              >{e}</button>
            ))}
          </div>
        </div>
        <div>
          <Label>Имя *</Label>
          <Input required maxLength={60} value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} placeholder="Например, Леон" />
        </div>
        <div>
          <Label>Категория</Label>
          <div className="flex flex-wrap gap-1.5 mt-2">
            {CATEGORIES.map((c) => (
              <button type="button" key={c} onClick={() => setForm({ ...form, category: c })}
                className={`px-3 h-8 rounded-full text-xs border ${form.category === c ? "bg-primary text-primary-foreground border-primary" : "bg-card border-border text-muted-foreground"}`}
              >{c}</button>
            ))}
          </div>
        </div>
        <div>
          <Label>Краткое описание</Label>
          <Input maxLength={500} value={form.description} onChange={(e) => setForm({ ...form, description: e.target.value })} placeholder="Кто этот персонаж" />
        </div>
        <div>
          <Label>Характер и стиль речи</Label>
          <Textarea maxLength={1000} rows={4} value={form.personality} onChange={(e) => setForm({ ...form, personality: e.target.value })} placeholder="Дерзкий, любит шутить, говорит коротко…" />
        </div>
        <div>
          <Label>Приветственное сообщение</Label>
          <Textarea required maxLength={500} rows={2} value={form.greeting} onChange={(e) => setForm({ ...form, greeting: e.target.value })} />
        </div>

        {/* Public toggle */}
        <button type="button" onClick={() => setForm({ ...form, is_public: !form.is_public })}
          className={`w-full flex items-center gap-3 rounded-2xl p-4 border transition ${
            form.is_public ? "border-primary bg-primary/10" : "border-border bg-card/60"
          }`}>
          <div className={`h-10 w-10 rounded-xl flex items-center justify-center ${form.is_public ? "flex-gradient text-primary-foreground" : "bg-muted"}`}>
            {form.is_public ? <Globe className="h-5 w-5" /> : <Lock className="h-5 w-5" />}
          </div>
          <div className="flex-1 text-left">
            <p className="font-semibold text-sm">{form.is_public ? "Публичный персонаж" : "Только для меня"}</p>
            <p className="text-xs text-muted-foreground">{form.is_public ? "Будет в каталоге, любой пользователь сможет общаться" : "Никто кроме вас не увидит"}</p>
          </div>
          <div className={`w-11 h-6 rounded-full p-0.5 transition ${form.is_public ? "bg-primary" : "bg-muted"}`}>
            <div className={`h-5 w-5 rounded-full bg-white transition-transform ${form.is_public ? "translate-x-5" : ""}`} />
          </div>
        </button>

        <Button type="submit" className="w-full h-12 text-base font-semibold" disabled={mut.isPending || limitReached || notEnoughCoins}>
          {mut.isPending ? "Создаём…"
            : limitReached ? "Лимит на сегодня исчерпан"
            : notEnoughCoins ? `Нужно ${pd?.limits.costCharacter} монет`
            : `Создать за ${pd?.limits.costCharacter ?? 30} монет`}
        </Button>
      </form>
    </div>
  );
}

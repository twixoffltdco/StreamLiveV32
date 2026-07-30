import { createFileRoute, Link } from "@tanstack/react-router";
import { useServerFn } from "@tanstack/react-start";
import { useQuery, useMutation, useQueryClient } from "@tanstack/react-query";
import { getCharacterWithMessages, sendMessage, getProfile } from "@/lib/flexai.functions";
import { useEffect, useRef, useState } from "react";
import { Button } from "@/components/ui/button";
import { Textarea } from "@/components/ui/textarea";
import { ArrowLeft, Coins, Send } from "lucide-react";
import { toast } from "sonner";
import { newIdempotencyKey } from "@/lib/idempotency";

export const Route = createFileRoute("/_app/chat/$id")({ component: ChatPage });

function ChatPage() {
  const { id } = Route.useParams();
  const qc = useQueryClient();
  const fetchChat = useServerFn(getCharacterWithMessages);
  const fetchProfile = useServerFn(getProfile);
  const send = useServerFn(sendMessage);
  const [input, setInput] = useState("");
  const scrollRef = useRef<HTMLDivElement>(null);

  const { data, isLoading } = useQuery({
    queryKey: ["chat", id],
    queryFn: () => fetchChat({ data: { id } }),
  });
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

  if (isLoading) return <div className="p-6 text-muted-foreground">Загрузка…</div>;
  if (!data) return null;

  return (
    <div className="flex flex-col h-screen pb-20">
      <header className="flex items-center gap-3 px-3 py-3 border-b border-border bg-card/95 backdrop-blur sticky top-0 z-10">
        <Link to="/chats" className="p-1.5"><ArrowLeft className="h-5 w-5" /></Link>
        <div className="text-2xl">{data.character.avatar_emoji}</div>
        <div className="flex-1 min-w-0">
          <p className="font-semibold truncate">{data.character.name}</p>
          <p className="text-xs text-muted-foreground truncate">{data.character.description}</p>
        </div>
        <div className="flex items-center gap-1 text-xs">
          <Coins className="h-3.5 w-3.5 text-[var(--coin)]" />
          <span>{pd?.profile?.coins ?? "—"}</span>
        </div>
      </header>

      {blocked && (
        <div className="mx-3 mt-3 rounded-xl border border-destructive/40 bg-destructive/10 px-3 py-2 text-xs text-destructive">
          Лимит {pd?.limits.messagesPerDay} сообщений за 24 часа исчерпан. Возвращайтесь завтра.
        </div>
      )}

      <div ref={scrollRef} className="flex-1 overflow-y-auto px-4 py-4 space-y-3">
        {data.messages.map((m) => (
          <div key={m.id} className={m.role === "user" ? "flex justify-end" : "flex justify-start"}>
            {m.role === "user" ? (
              <div className="max-w-[80%] rounded-2xl rounded-br-sm bg-primary text-primary-foreground px-3.5 py-2 text-sm whitespace-pre-wrap">
                {m.content}
              </div>
            ) : (
              <div className="max-w-[85%] text-sm whitespace-pre-wrap leading-relaxed">
                {m.content}
              </div>
            )}
          </div>
        ))}
        {sendMut.isPending && (
          <div className="text-sm text-muted-foreground italic">{data.character.name} печатает…</div>
        )}
      </div>

      <div className="fixed bottom-16 left-0 right-0 border-t border-border bg-card/95 backdrop-blur px-3 py-2">
        <div className="max-w-md mx-auto flex items-end gap-2">
          <Textarea
            value={input}
            onChange={(e) => setInput(e.target.value)}
            onKeyDown={(e) => { if (e.key === "Enter" && !e.shiftKey) { e.preventDefault(); onSend(); } }}
            placeholder={blocked ? "Лимит исчерпан" : "Сообщение…"}
            rows={1}
            disabled={sendMut.isPending || blocked}
            className="min-h-10 max-h-32 resize-none"
          />
          <Button onClick={onSend} disabled={sendMut.isPending || !input.trim() || blocked} size="icon">
            <Send className="h-4 w-4" />
          </Button>
        </div>
        {remaining !== null && (
          <p className="text-[10px] text-muted-foreground text-center mt-1">
            Осталось: <b className={blocked ? "text-destructive" : ""}>{Math.max(0, remaining)}</b> / {pd?.limits.messagesPerDay} · {pd?.limits.costMessage} монет / сообщение
          </p>
        )}
      </div>
    </div>
  );
}

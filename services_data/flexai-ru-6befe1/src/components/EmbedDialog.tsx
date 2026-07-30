import { useState, useMemo } from "react";
import { Button } from "@/components/ui/button";
import { Copy, X, Check, Sparkles } from "lucide-react";
import { toast } from "sonner";

export function EmbedDialog({ characterId, characterName, onClose }: {
  characterId: string;
  characterName: string;
  onClose: () => void;
}) {
  const [copied, setCopied] = useState<string | null>(null);
  const [width, setWidth] = useState<number>(380);
  const [height, setHeight] = useState<number>(600);
  const [responsive, setResponsive] = useState<boolean>(true);

  const origin = typeof window !== "undefined" ? window.location.origin : "https://flexai-ru.lovable.app";
  const url = `${origin}/embed/${characterId}`;

  const widthCss = responsive ? "100%" : `${width}px`;

  const iframeCode = useMemo(() =>
    `<iframe src="${url}" style="width:${widthCss};max-width:100%;height:${height}px;border:0;border-radius:16px;background:#0f0f1a" allow="clipboard-write"></iframe>`
  , [url, widthCss, height]);

  // JS snippet with auto-resize via postMessage from the embed page.
  const jsCode = useMemo(() => `<div id="flexai-${characterId}"></div>
<script>(function(){
  var host=document.getElementById("flexai-${characterId}");
  var f=document.createElement("iframe");
  f.src=${JSON.stringify(url)};
  f.style.cssText="width:${widthCss};max-width:100%;height:${height}px;border:0;border-radius:16px;background:#0f0f1a";
  f.setAttribute("allow","clipboard-write");
  host.appendChild(f);
  window.addEventListener("message",function(e){
    if(!e.data||e.data.flexai!==${JSON.stringify(characterId)})return;
    if(typeof e.data.height==="number")f.style.height=Math.max(420,e.data.height)+"px";
  });
})();</script>`, [characterId, url, widthCss, height]);

  const copy = (text: string, label: string) => {
    navigator.clipboard.writeText(text);
    setCopied(label);
    toast.success("Скопировано");
    setTimeout(() => setCopied(null), 1500);
  };

  return (
    <div className="fixed inset-0 z-50 bg-black/70 backdrop-blur flex items-end sm:items-center justify-center p-4" onClick={onClose}>
      <div className="bg-card border border-border rounded-3xl w-full max-w-md max-h-[90vh] overflow-y-auto" onClick={(e) => e.stopPropagation()}>
        <div className="sticky top-0 bg-card/95 backdrop-blur border-b border-border px-5 py-3 flex items-center justify-between z-10">
          <div className="flex items-center gap-2 min-w-0">
            <Sparkles className="h-4 w-4 text-primary shrink-0" />
            <h2 className="font-bold truncate">Встроить «{characterName}»</h2>
          </div>
          <button onClick={onClose} className="p-1.5 hover:bg-muted rounded-lg"><X className="h-4 w-4" /></button>
        </div>

        <div className="p-5 space-y-5">
          <p className="text-xs text-muted-foreground">
            Вставь код на свой сайт. Регистрация и общение происходят прямо внутри виджета —
            без переходов на новые вкладки.
          </p>

          {/* Size controls */}
          <div className="rounded-2xl border border-border bg-card/40 p-3 space-y-3">
            <div className="flex items-center justify-between">
              <p className="text-xs font-semibold">Размер</p>
              <button onClick={() => setResponsive((v) => !v)}
                className="text-[10px] px-2 py-1 rounded-md border border-border">
                {responsive ? "✓ Адаптивная ширина" : "Фиксированная ширина"}
              </button>
            </div>
            <div className="grid grid-cols-2 gap-3">
              <label className={`block ${responsive ? "opacity-50" : ""}`}>
                <span className="text-[10px] text-muted-foreground">Ширина (px)</span>
                <input type="number" min={280} max={1200} disabled={responsive}
                  value={width} onChange={(e) => setWidth(Math.max(280, Math.min(1200, Number(e.target.value) || 380)))}
                  className="w-full h-9 px-2 mt-1 rounded-lg bg-muted text-sm" />
              </label>
              <label className="block">
                <span className="text-[10px] text-muted-foreground">Высота (px)</span>
                <input type="number" min={420} max={1200}
                  value={height} onChange={(e) => setHeight(Math.max(420, Math.min(1200, Number(e.target.value) || 600)))}
                  className="w-full h-9 px-2 mt-1 rounded-lg bg-muted text-sm" />
              </label>
            </div>
          </div>

          <Section title="iframe" code={iframeCode} onCopy={() => copy(iframeCode, "iframe")} copied={copied === "iframe"} />
          <Section title="JS-скрипт (с автоподстройкой высоты)" code={jsCode} onCopy={() => copy(jsCode, "js")} copied={copied === "js"} />

          <div>
            <p className="text-xs font-semibold mb-2">Прямая ссылка</p>
            <div className="flex gap-2">
              <input readOnly value={url} className="flex-1 h-10 px-3 rounded-xl bg-muted text-xs font-mono" />
              <Button size="sm" variant="outline" onClick={() => copy(url, "url")}>
                {copied === "url" ? <Check className="h-4 w-4" /> : <Copy className="h-4 w-4" />}
              </Button>
            </div>
          </div>

          <div className="rounded-2xl border border-primary/30 bg-primary/5 p-3">
            <p className="text-xs font-semibold mb-2">Предпросмотр</p>
            <iframe src={url}
              style={{ width: widthCss, maxWidth: "100%", height: `${Math.min(height, 480)}px`, border: 0, borderRadius: 12, background: "#0f0f1a" }}
              allow="clipboard-write" />
          </div>
        </div>
      </div>
    </div>
  );
}

function Section({ title, code, onCopy, copied }: { title: string; code: string; onCopy: () => void; copied: boolean }) {
  return (
    <div>
      <div className="flex items-center justify-between mb-2">
        <p className="text-xs font-semibold">{title}</p>
        <Button size="sm" variant="ghost" onClick={onCopy} className="h-7 text-xs">
          {copied ? <Check className="h-3 w-3 mr-1" /> : <Copy className="h-3 w-3 mr-1" />}
          Копировать
        </Button>
      </div>
      <pre className="bg-muted rounded-xl p-3 text-[10px] font-mono overflow-x-auto whitespace-pre-wrap break-all">{code}</pre>
    </div>
  );
}

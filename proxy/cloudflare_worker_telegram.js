/**
 * Бесплатный прокси Telegram Bot API (Cloudflare Workers).
 * 1. https://dash.cloudflare.com → Workers → Create
 * 2. Вставь этот код → Deploy
 * 3. Скопируй URL вида https://tg-proxy.xxx.workers.dev
 * 4. В админке StreamLive → Диагностика / Боты → «Базовый URL API» =
 *    https://tg-proxy.xxx.workers.dev
 *
 * Запросы:  {WORKER}/botTOKEN/getMe  →  api.telegram.org/botTOKEN/getMe
 */
export default {
  async fetch(request) {
    const url = new URL(request.url);
    // /bot123:AA.../method  or  /bot123:AA.../method?query
    const path = url.pathname + url.search;
    if (!path.startsWith('/bot')) {
      return new Response('StreamLive TG proxy. Use /bot<token>/<method>', { status: 200 });
    }
    const target = 'https://api.telegram.org' + path;
    const init = {
      method: request.method,
      headers: {},
    };
    // не пробрасываем host
    if (request.method !== 'GET' && request.method !== 'HEAD') {
      init.body = await request.arrayBuffer();
      const ct = request.headers.get('content-type');
      if (ct) init.headers['content-type'] = ct;
    }
    try {
      const resp = await fetch(target, init);
      const body = await resp.arrayBuffer();
      return new Response(body, {
        status: resp.status,
        headers: { 'content-type': resp.headers.get('content-type') || 'application/json' },
      });
    } catch (e) {
      return new Response(JSON.stringify({ ok: false, description: 'proxy error: ' + String(e) }), {
        status: 502,
        headers: { 'content-type': 'application/json' },
      });
    }
  },
};

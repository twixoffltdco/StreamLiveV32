(function () {
  document.documentElement.setAttribute('data-sl-extension', '1.2.0');
  window.SL_EXTENSION = { version: '1.2.0', installed: true };
  window.dispatchEvent(new CustomEvent('sl-extension-ready', { detail: { version: '1.2.0' } }));

  // Монетки только если зашли через приложение (?sl_app=1) или всегда при установленном расширении
  const fromApp = /[?&]sl_app=1/.test(location.search) || sessionStorage.getItem('sl_app') === '1';
  if (/[?&]sl_app=1/.test(location.search)) sessionStorage.setItem('sl_app', '1');
  if (!fromApp && !document.documentElement.getAttribute('data-sl-extension')) return;

  const DAILY = 5, NEED = 100;

  function el(tag, css, html) {
    const n = document.createElement(tag);
    if (css) n.style.cssText = css;
    if (html != null) n.innerHTML = html;
    return n;
  }

  async function getState() {
    return new Promise((resolve) => {
      chrome.runtime.sendMessage({ type: 'COINS_GET' }, (r) => resolve(r || { coins: 0, lastClaim: '', promo: '' }));
    });
  }
  function setState(patch) {
    return new Promise((resolve) => {
      chrome.runtime.sendMessage({ type: 'COINS_SET', patch: patch }, () => resolve());
    });
  }

  async function mount() {
    if (document.getElementById('sl-coins-fab')) return;
    const s = await getState();
    const fab = el('div',
      'position:fixed;z-index:2147483000;right:14px;bottom:80px;display:flex;flex-direction:column;align-items:flex-end;gap:8px;font-family:system-ui,sans-serif');
    fab.id = 'sl-coins-fab';

    const panel = el('div',
      'display:none;width:260px;background:rgba(20,20,22,.96);color:#f2f2f7;border:1px solid rgba(255,255,255,.12);border-radius:14px;padding:12px;box-shadow:0 12px 40px rgba(0,0,0,.45);font-size:13px');
    panel.innerHTML =
      '<div style="font-weight:700;margin-bottom:6px">🪙 Монетки приложения</div>' +
      '<div style="font-size:22px;font-weight:800;color:#e50914" id="sl-c-bal">0</div>' +
      '<div style="color:#999;margin:4px 0 8px;font-size:12px" id="sl-c-info"></div>' +
      '<button type="button" id="sl-c-claim" style="width:100%;border:0;border-radius:10px;padding:8px;background:#e50914;color:#fff;font-weight:700;cursor:pointer">Забрать +5 сегодня</button>' +
      '<div style="margin-top:10px;color:#999;font-size:12px">100 монет → промокод (username)</div>' +
      '<input id="sl-c-user" placeholder="username" style="width:100%;margin-top:6px;padding:8px;border-radius:8px;border:1px solid #333;background:#111;color:#fff;box-sizing:border-box">' +
      '<button type="button" id="sl-c-ex" style="width:100%;margin-top:6px;border:0;border-radius:10px;padding:8px;background:#222;color:#fff;border:1px solid #333;font-weight:600;cursor:pointer">Обменять</button>' +
      '<div id="sl-c-out" style="margin-top:8px;color:#aaa;font-size:12px"></div>';

    const btn = el('button',
      'border:0;border-radius:999px;padding:10px 14px;background:#e50914;color:#fff;font-weight:800;cursor:pointer;box-shadow:0 6px 20px rgba(229,9,20,.4)',
      '🪙 <span id="sl-c-mini">0</span>');
    btn.type = 'button';
    btn.id = 'sl-coins-toggle';
    btn.onclick = () => {
      panel.style.display = panel.style.display === 'none' ? 'block' : 'none';
    };

    fab.appendChild(panel);
    fab.appendChild(btn);
    document.body.appendChild(fab);

    async function render() {
      const st = await getState();
      const bal = st.coins || 0;
      const today = new Date().toISOString().slice(0, 10);
      const claimed = st.lastClaim === today;
      document.getElementById('sl-c-bal').textContent = String(bal);
      document.getElementById('sl-c-mini').textContent = String(bal);
      document.getElementById('sl-c-info').textContent = claimed
        ? 'Сегодня уже получено. Завтра снова +' + DAILY
        : 'Можно забрать +' + DAILY + ' · нужно ' + NEED + ' на промокод';
      document.getElementById('sl-c-claim').disabled = claimed;
      if (st.promo) document.getElementById('sl-c-out').textContent = 'Промокод: ' + st.promo;
    }

    document.getElementById('sl-c-claim').onclick = async () => {
      const st = await getState();
      const today = new Date().toISOString().slice(0, 10);
      if (st.lastClaim === today) return;
      await setState({ coins: (st.coins || 0) + DAILY, lastClaim: today });
      render();
    };

    document.getElementById('sl-c-ex').onclick = async () => {
      const st = await getState();
      const user = (document.getElementById('sl-c-user').value || '').trim();
      const out = document.getElementById('sl-c-out');
      if ((st.coins || 0) < NEED) { out.textContent = 'Нужно ' + NEED + ' монет'; return; }
      if (!user) { out.textContent = 'Укажите username'; return; }
      out.textContent = 'Обмен…';
      try {
        const res = await fetch('/api/extension_promo.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ username: user, coins: NEED })
        });
        const data = await res.json().catch(() => ({}));
        if (!data.ok) {
          out.textContent = data.error || 'Ошибка обмена';
          return;
        }
        await setState({ coins: 0, promo: data.code || '' });
        out.textContent = 'Промокод: ' + (data.code || '');
        render();
      } catch (e) {
        out.textContent = 'Нет связи с API';
      }
    };

    render();
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', mount);
  else mount();
})();

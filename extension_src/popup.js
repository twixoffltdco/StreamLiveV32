const DAILY = 5, NEED = 100;

async function defaults() {
  try {
    const d = await chrome.storage.sync.get({ siteUrl: '', brand: 'StreamLive' });
    return d;
  } catch (e) {
    return { siteUrl: '', brand: 'StreamLive' };
  }
}

async function getUrl() {
  const d = await defaults();
  if (d.siteUrl) return d.siteUrl;
  // baked at build time via background DEFAULT - read from manifest homepage optional
  return new Promise((resolve) => {
    chrome.runtime.sendMessage({ type: 'GET_DEFAULT_URL' }, (r) => {
      resolve((r && r.url) || 'https://streamliveru.web1337.net/?source=extension');
    });
  });
}

async function siteOk(url) {
  try {
    const ctrl = new AbortController();
    const t = setTimeout(() => ctrl.abort(), 7000);
    const res = await fetch(url, { method: 'GET', redirect: 'follow', signal: ctrl.signal, credentials: 'omit', cache: 'no-store' });
    clearTimeout(t);
    const text = (await res.text()).slice(0, 6000).toLowerCase();
    const bad = ['domain has been suspended', 'account suspended', 'this account has been suspended',
      'website is not available', 'сайт заблокирован', 'приостановлен', 'has been disabled'];
    for (const b of bad) if (text.includes(b)) return false;
    if (res.status >= 500) return false;
    return true;
  } catch (e) {
    return false;
  }
}

async function state() {
  return chrome.storage.local.get({ coins: 0, lastClaim: '', promo: '' });
}

async function renderCoins() {
  const s = await state();
  document.getElementById('bal').textContent = String(s.coins || 0);
  const today = new Date().toISOString().slice(0, 10);
  const claimed = s.lastClaim === today;
  document.getElementById('claim').disabled = claimed;
  document.getElementById('claimInfo').textContent = claimed
    ? 'Сегодня уже получено. Завтра +' + DAILY
    : 'Можно забрать +' + DAILY + ' · до ' + NEED + ' на промокод';
  if (s.promo) document.getElementById('exOut').textContent = 'Промокод: ' + s.promo;
}

async function boot() {
  const conf = await defaults();
  document.getElementById('brand').textContent = conf.brand || 'StreamLive';
  const url = await getUrl();
  document.getElementById('splashSub').textContent = 'Проверка платформы…';
  const ok = await siteOk(url);
  document.getElementById('splash').classList.add('hide');
  document.getElementById('app').classList.add('show');
  document.getElementById('statusLine').textContent = ok
    ? 'Платформа доступна'
    : 'Платформа недоступна — откроется локальная заглушка';
  window.__siteOk = ok;
  window.__siteUrl = url;
  await renderCoins();
}

document.getElementById('open').onclick = () => {
  chrome.runtime.sendMessage({ type: 'OPEN_APP' });
  window.close();
};
document.getElementById('retry').onclick = () => {
  document.getElementById('splash').classList.remove('hide');
  document.getElementById('app').classList.remove('show');
  document.getElementById('splashSub').textContent = 'Проверка…';
  boot();
};
document.getElementById('claim').onclick = async () => {
  const s = await state();
  const today = new Date().toISOString().slice(0, 10);
  if (s.lastClaim === today) return;
  await chrome.storage.local.set({ coins: (s.coins || 0) + DAILY, lastClaim: today });
  renderCoins();
};
document.getElementById('exchange').onclick = async () => {
  const s = await state();
  const user = (document.getElementById('user').value || '').trim();
  const out = document.getElementById('exOut');
  if ((s.coins || 0) < NEED) { out.textContent = 'Нужно ' + NEED + ' монет'; return; }
  if (!user) { out.textContent = 'Укажите username'; return; }
  out.textContent = 'Обмен…';
  const url = await getUrl();
  const base = String(url).replace(/\/?(\?.*)?$/, '');
  try {
    const res = await fetch(base + '/api/extension_promo.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ username: user, coins: NEED })
    });
    const data = await res.json().catch(() => ({}));
    if (!data.ok) {
      out.textContent = data.error || 'Ошибка / сайт недоступен. Монеты не списаны.';
      return;
    }
    await chrome.storage.local.set({ coins: 0, promo: data.code || '' });
    out.textContent = 'Промокод: ' + (data.code || '');
    renderCoins();
  } catch (e) {
    out.textContent = 'Нет связи с сайтом. Монеты не списаны.';
  }
};

boot();

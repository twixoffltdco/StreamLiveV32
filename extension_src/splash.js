(async function () {
  const DEFAULT = '{{SITE_URL}}';
  let siteUrl = DEFAULT;
  try {
    const d = await chrome.storage.sync.get({ siteUrl: DEFAULT, brand: 'StreamLive' });
    if (d.siteUrl) siteUrl = d.siteUrl;
    if (d.brand) document.getElementById('brand').textContent = d.brand;
  } catch (e) {}

  document.getElementById('msg').textContent = 'Проверка платформы…';

  async function ok(url) {
    try {
      const ctrl = new AbortController();
      const t = setTimeout(() => ctrl.abort(), 8000);
      const res = await fetch(url, { method: 'GET', redirect: 'follow', signal: ctrl.signal, credentials: 'omit', cache: 'no-store' });
      clearTimeout(t);
      const text = (await res.text()).slice(0, 8000).toLowerCase();
      const bad = [
        'domain has been suspended', 'account suspended', 'this account has been suspended',
        'website is not available', 'сайт заблокирован', 'приостановлен', 'has been disabled'
      ];
      for (const b of bad) if (text.includes(b)) return false;
      if (res.status >= 500) return false;
      return true;
    } catch (e) {
      return false;
    }
  }

  const alive = await ok(siteUrl);
  if (alive) {
    document.getElementById('msg').textContent = 'Запуск…';
    // помечаем, что открыто из «приложения»
    const join = siteUrl.indexOf('?') >= 0 ? '&' : '?';
    location.replace(siteUrl + join + 'sl_app=1');
  } else {
    location.replace(chrome.runtime.getURL('unavailable.html'));
  }
})();

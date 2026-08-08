const DAILY = 5;
const NEED = 100;

async function state() {
  const d = await chrome.storage.local.get({ coins: 0, lastClaim: '', promo: '' });
  return d;
}

async function render() {
  const s = await state();
  document.getElementById('bal').textContent = String(s.coins || 0);
  const today = new Date().toISOString().slice(0, 10);
  const claimed = s.lastClaim === today;
  document.getElementById('claim').disabled = claimed;
  document.getElementById('claimInfo').textContent = claimed
    ? 'Сегодня уже получено. Завтра снова +' + DAILY
    : 'Можно забрать +' + DAILY + ' за сегодня';
  if (s.promo) {
    document.getElementById('exOut').innerHTML = 'Ваш промокод: <code>' + s.promo + '</code>';
  }
}

document.getElementById('claim').onclick = async function () {
  const s = await state();
  const today = new Date().toISOString().slice(0, 10);
  if (s.lastClaim === today) return;
  const coins = (s.coins || 0) + DAILY;
  await chrome.storage.local.set({ coins: coins, lastClaim: today });
  render();
};

document.getElementById('exchange').onclick = async function () {
  const s = await state();
  const user = (document.getElementById('user').value || '').trim();
  const out = document.getElementById('exOut');
  if ((s.coins || 0) < NEED) {
    out.textContent = 'Нужно ' + NEED + ' монет (сейчас ' + (s.coins || 0) + ').
    return;
  }
  if (!user) {
    out.textContent = 'Укажите username (нужна регистрация на сайте).';
    return;
  }
  out.textContent = 'Обмен…';
  const conf = await chrome.storage.sync.get({ siteUrl: '' });
  let base = conf.siteUrl || '';
  try {
    if (!base) {
      const m = await fetch(chrome.runtime.getURL('defaults.json')).then(r => r.json()).catch(() => ({}));
      base = m.siteUrl || '';
    }
  } catch (e) {}
  base = String(base).replace(/\/?(\?.*)?$/, '');
  if (!base) {
    out.textContent = 'Нет адреса сайта в настройках расширения.';
    return;
  }
  try {
    const res = await fetch(base + '/api/extension_promo.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ username: user, coins: NEED })
    });
    const data = await res.json().catch(() => ({}));
    if (!data || !data.ok) {
      out.textContent = (data && data.error) ? data.error : 'Сайт недоступен или ошибка обмена. Монеты не списаны.';
      return;
    }
    await chrome.storage.local.set({ coins: 0, promo: data.code || '' });
    out.innerHTML = 'Промокод: <code>' + (data.code || '') + '</code> — активируйте в профиле/студии один раз.';
    render();
  } catch (e) {
    out.textContent = 'Нет связи с платформой. Попробуйте, когда сайт снова будет доступен. Монеты не списаны.';
  }
};

render();

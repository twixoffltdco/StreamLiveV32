const DEFAULT_URL = '{{SITE_URL}}';
const SPLASH = chrome.runtime.getURL('splash.html');

async function openApp() {
  try {
    await chrome.windows.create({
      url: SPLASH,
      type: 'popup',
      state: 'maximized',
      focused: true
    });
  } catch (e) {
    try { await chrome.tabs.create({ url: SPLASH, active: true }); } catch (e2) {}
  }
}

chrome.action.onClicked.addListener(() => { openApp(); });

chrome.runtime.onMessage.addListener((msg, _s, sendResponse) => {
  if (msg && msg.type === 'COINS_GET') {
    chrome.storage.local.get({ coins: 0, lastClaim: '', promo: '' }, (d) => sendResponse(d));
    return true;
  }
  if (msg && msg.type === 'COINS_SET') {
    chrome.storage.local.set(msg.patch || {}, () => sendResponse({ ok: true }));
    return true;
  }
  if (msg && msg.type === 'PING') {
    sendResponse({ ok: true, version: '1.2.0' });
    return true;
  }
  return false;
});

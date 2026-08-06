const DEFAULT_URL = '{{SITE_URL}}';
async function getUrl() {
  try {
    const d = await chrome.storage.sync.get({ siteUrl: DEFAULT_URL });
    return (d.siteUrl && String(d.siteUrl).trim()) || DEFAULT_URL;
  } catch (e) {
    return DEFAULT_URL;
  }
}
async function openApp() {
  const url = await getUrl();
  try {
    await chrome.windows.create({ url: url, type: 'popup', state: 'maximized', focused: true });
  } catch (e) {
    try { await chrome.tabs.create({ url: url, active: true }); } catch (e2) {}
  }
}
chrome.action.onClicked.addListener(() => { openApp(); });

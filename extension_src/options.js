const DEFAULT_URL = '{{SITE_URL}}';
const input = document.getElementById('url');
chrome.storage.sync.get({ siteUrl: DEFAULT_URL }, (d) => { input.value = d.siteUrl || DEFAULT_URL; });
document.getElementById('save').onclick = () => {
  let v = (input.value || '').trim();
  if (v && !/^https?:\/\//i.test(v)) v = 'https://' + v;
  chrome.storage.sync.set({ siteUrl: v || DEFAULT_URL });
};

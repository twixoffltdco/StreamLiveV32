const DEF = '{{SITE_URL}}';
chrome.storage.sync.get({ siteUrl: DEF }, (d) => { document.getElementById('url').value = d.siteUrl || DEF; });
document.getElementById('save').onclick = () => {
  let v = (document.getElementById('url').value || '').trim();
  if (v && !/^https?:\/\//i.test(v)) v = 'https://' + v;
  chrome.storage.sync.set({ siteUrl: v || DEF });
};

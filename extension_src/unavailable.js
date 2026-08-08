document.getElementById('retry').onclick = function () {
  document.getElementById('status').textContent = 'Проверяем…';
  chrome.runtime.sendMessage({ type: 'RETRY_OPEN' }, function () {});
};
document.getElementById('coins').onclick = function () {
  chrome.runtime.sendMessage({ type: 'OPEN_COINS' }, function () {});
};

/* Minimal SW for StreamLive browser notifications */
self.addEventListener('install', function (e) { self.skipWaiting(); });
self.addEventListener('activate', function (e) { e.waitUntil(self.clients.claim()); });
self.addEventListener('notificationclick', function (e) {
  e.notification.close();
  var url = (e.notification.data && e.notification.data.url) || '/';
  e.waitUntil(clients.openWindow(url));
});
self.addEventListener('message', function (e) {
  var d = e.data || {};
  if (d.type === 'notify' && self.registration.showNotification) {
    self.registration.showNotification(d.title || 'StreamLive', {
      body: d.body || '',
      icon: '/assets/img/avatar-placeholder.png',
      data: { url: d.url || '/' }
    });
  }
});

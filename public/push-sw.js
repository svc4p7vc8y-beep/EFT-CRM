self.addEventListener('push', (event) => {
  let message = {};
  try { message = event.data?.json() || {}; } catch { message = {}; }
  const url = typeof message.url === 'string' && /^#(?:tasks|task\/[A-Za-z0-9_-]{1,36}|communications|client\/[A-Za-z0-9%_-]+|chat\/[A-Za-z0-9_-]{1,36})(?:\?(?:event|message)=[A-Za-z0-9_-]{1,64})?$/.test(message.url) ? message.url : '#overview';
  event.waitUntil(self.registration.showNotification(message.title || 'ЭФТ CRM', {
    body: message.body || 'Появилось новое уведомление.',
    icon: './eft-icon-192-v54.png',
    badge: './eft-icon-192-v54.png',
    tag: typeof message.tag === 'string' ? message.tag : undefined,
    data: { url },
  }));
});

self.addEventListener('notificationclick', (event) => {
  event.notification.close();
  const hash = event.notification.data?.url || '#overview';
  const target = new URL(hash, self.registration.scope).href;
  event.waitUntil((async () => {
    const windows = await clients.matchAll({ type: 'window', includeUncontrolled: true });
    for (const windowClient of windows) {
      if (windowClient.url.startsWith(self.registration.scope)) {
        await windowClient.navigate(target);
        return windowClient.focus();
      }
    }
    return clients.openWindow(target);
  })());
});

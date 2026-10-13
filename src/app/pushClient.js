export const pushAvailable = () => window.isSecureContext && 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window;

function decodeBase64Url(value) {
  const base64 = value.replace(/-/g, '+').replace(/_/g, '/');
  const bytes = atob(base64.padEnd(Math.ceil(base64.length / 4) * 4, '='));
  return Uint8Array.from(bytes, (char) => char.charCodeAt(0));
}

async function registration() {
  return navigator.serviceWorker.register(new URL('./push-sw.js', document.baseURI), { scope: './' });
}

export async function enablePush(publicKey, save) {
  if (!pushAvailable()) throw new Error('Этот браузер не поддерживает фоновые уведомления.');
  const permission = await Notification.requestPermission();
  if (permission !== 'granted') throw new Error('Разрешите уведомления в настройках телефона или браузера.');
  const worker = await registration();
  const existing = await worker.pushManager.getSubscription();
  const subscription = existing || await worker.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: decodeBase64Url(publicKey) });
  await save(subscription.toJSON());
  return subscription;
}

export async function syncExistingPush(save) {
  if (!pushAvailable() || Notification.permission !== 'granted') return;
  const worker = await navigator.serviceWorker.getRegistration(new URL('./', document.baseURI));
  const subscription = await worker?.pushManager.getSubscription();
  if (subscription) await save(subscription.toJSON());
}

export async function disablePush(remove) {
  if (!pushAvailable()) return;
  const worker = await navigator.serviceWorker.getRegistration(new URL('./', document.baseURI));
  const subscription = await worker?.pushManager.getSubscription();
  if (!subscription) return;
  try { await remove(subscription.endpoint); }
  finally { await subscription.unsubscribe(); }
}

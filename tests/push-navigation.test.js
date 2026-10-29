import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';

const source = readFileSync(new URL('../public/push-sw.js', import.meta.url), 'utf8');

test('push opens the precise client, task and chat destination', async () => {
  const listeners = {};
  const shown = [];
  const self = {
    addEventListener: (name, callback) => { listeners[name] = callback; },
    registration: {
      scope: 'https://crm.eftsip.ru/',
      showNotification: async (_title, options) => { shown.push(options); },
    },
  };
  runInNewContext(source, { self, URL });
  const destinations = [
    '#client/lead-1?event=event-2',
    '#task/task-1?event=answer-task-1',
    '#communications?message=message-3',
    '#chat/thread-1?message=42',
  ];
  for (const destination of destinations) {
    let pending;
    listeners.push({ data: { json: () => ({ title: 'CRM', url: destination }) }, waitUntil: (promise) => { pending = promise; } });
    await pending;
    assert.equal(shown.at(-1).data.url, destination);
  }
  let navigated = '';
  const clients = { matchAll: async () => [{ url: 'https://crm.eftsip.ru/#overview', navigate: async (url) => { navigated = url; }, focus: async () => {} }] };
  runInNewContext(source, { self, URL, clients });
  let pending;
  listeners.notificationclick({ notification: { data: { url: destinations[0] }, close: () => {} }, waitUntil: (promise) => { pending = promise; } });
  await pending;
  assert.equal(navigated, 'https://crm.eftsip.ru/#client/lead-1?event=event-2');
});

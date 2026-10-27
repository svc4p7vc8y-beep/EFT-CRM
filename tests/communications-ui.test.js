import test from 'node:test';
import assert from 'node:assert/strict';
import React from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { createServer } from 'vite';

test('непривязанный MAX-диалог предлагает ответ и связь с заявкой или заказом', async () => {
  globalThis.localStorage = { getItem: () => null };
  const vite = await createServer({ server: { middlewareMode: true }, appType: 'custom' });
  try {
    const { Communications } = await vite.ssrLoadModule('/src/features/Communications.jsx');
    const { createDemoState } = await vite.ssrLoadModule('/src/app/model.js');
    const state = createDemoState();
    state.activities.unshift({ ...state.activities.find((item) => item.channel === 'email' && !item.siteId), id: 'max-test', channel: 'max', type: 'message', direction: 'incoming', subject: '', text: 'Фото', createdAt: new Date(Date.now() + 86400000).toISOString(), attachments: [{ kind: 'sender', name: 'Клиент MAX' }, { kind: 'max_chat', id: '12345' }, { key: 'm-12345678901234567890123456789012.jpg', name: 'Фото из MAX.jpg', mime: 'image/jpeg', url: '/api/api.php?action=communications.file&key=m-12345678901234567890123456789012.jpg' }] });
    const html = renderToStaticMarkup(React.createElement(Communications, {
      state, search: '', command: () => {}, notify: () => {}, onCreate: () => {}, onCreateLead: () => {}, onLead: () => {}, onNotifications: () => {},
      runtime: { mode: 'server', capabilities: ['*'], assignCommunication: async () => {}, replyUnassigned: async () => {} },
    }));
    assert.match(html, /Связать диалог/);
    assert.match(html, /Новая заявка/);
    assert.match(html, /optgroup label="Заявки"/);
    assert.match(html, /optgroup label="Заказы"/);
    assert.match(html, /Ответить в MAX/);
    assert.match(html, /Ответить на сообщение/);
    assert.match(html, /<img[^>]+alt="Фото из MAX.jpg"/);
    assert.match(html, /Открыть изображение: Фото из MAX.jpg/);
    assert.match(html, /aria-label="Прикрепить фото или файл"/);
    assert.match(html, /aria-label="Открыть изображение: Фото из MAX.jpg"[^>]*><img/);
  } finally {
    await vite.close();
    delete globalThis.localStorage;
  }
});

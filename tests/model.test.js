import test from 'node:test';
import assert from 'node:assert/strict';
import { applyCommand, createDemoState, validateState } from '../src/app/model.js';

test('заказ фиксирует комплектацию и создаёт связанное задание ровно один раз', () => {
  const original = createDemoState();
  assert.throws(() => applyCommand(original, 'order.create', { leadId: 'lead-1', scope: 'СИП' }), /Подтвердите/);
  const next = applyCommand(original, 'order.create', { leadId: 'lead-1', scope: 'СИП 174 мм', approved: true, dueAt: '', assigneeId: 'worker-1' });
  assert.equal(original.orders.length, 4);
  assert.equal(next.orders[0].scope, 'СИП 174 мм');
  assert.equal(next.tasks[0].orderId, next.orders[0].id);
  assert.equal(next.leads[0].status, 'contract');
  assert.throws(() => applyCommand(next, 'order.create', { leadId: 'lead-1', scope: 'Другая', approved: true }), /уже передана/);
});
test('блокировка требует причины, приёмка — завершённого чек-листа', () => {
  let state = createDemoState();
  assert.throws(() => applyCommand(state, 'task.update', { id: 'task-1', status: 'blocked' }), /причину/);
  state = applyCommand(state, 'task.update', { id: 'task-1', status: 'blocked', blockReason: 'Нет материала' });
  assert.equal(state.tasks[0].blockReason, 'Нет материала');
  assert.throws(() => applyCommand(state, 'task.update', { id: 'task-1', status: 'done' }), /чек-листа/);
  for (const item of state.tasks[0].checklist) state = applyCommand(state, 'task.check', { id: 'task-1', checkId: item.id });
  state = applyCommand(state, 'task.update', { id: 'task-1', status: 'done' });
  assert.equal(state.tasks[0].completedQty, 38);
  assert.equal(state.tasks[0].blockReason, '');
});
test('повторная заявка существующего клиента создаёт новый объект без дубля клиента', () => {
  const state = applyCommand(createDemoState(), 'lead.create', { clientId: 'client-1', siteName: 'Второй дом', nextAction: 'Позвонить', dueAt: '' });
  assert.equal(state.clients.length, 5); assert.equal(state.sites.length, 6);
  assert.equal(state.sites.at(-1).clientId, 'client-1');
});
test('импорт отбрасывает повреждённые связи и нечисловой объём', () => {
  const state = createDemoState(); assert.equal(validateState(JSON.parse(JSON.stringify(state))).schemaVersion, 1);
  state.tasks[0].orderId = 'missing'; assert.throws(() => validateState(state), /задание/);
  state.tasks[0].orderId = 'order-1'; state.tasks[0].quantity = null; assert.throws(() => validateState(state), /задание/);
});

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
test('серверная заявка может временно оставаться без назначенного менеджера', () => {
  const state=createDemoState();state.leads[0].ownerId='';assert.doesNotThrow(()=>validateState(state));
});
test('общая задача может быть без исполнителя и срока', () => {
  let state=applyCommand(createDemoState(),'task.create',{title:'Распределить новую задачу',description:'',orderId:'',siteId:'',assigneeId:'',dueAt:'',quantity:1,completedQty:0,unit:'задача',priority:'normal',status:'planned'});
  assert.equal(state.tasks[0].assigneeId,'');assert.equal(state.tasks[0].dueAt,'');assert.doesNotThrow(()=>validateState(state));
  state=applyCommand(state,'task.update',{id:state.tasks[0].id,assigneeId:'manager-1'});
  assert.equal(state.tasks[0].assigneeId,'manager-1');
});
test('сотрудников и бригады можно добавлять, редактировать и сохранять связи', () => {
  let state = applyCommand(createDemoState(), 'employee.save', { name: 'Тестовый электрик', role: 'Электрик', department: 'Монтаж', active: true });
  const person = state.employees.at(-1);
  assert.equal(person.role, 'Электрик');
  state = applyCommand(state, 'crew.save', { name: 'Электромонтаж', specialty: 'Электрика', memberIds: [person.id], leadId: person.id, active: true });
  const crew = state.crews.find((item) => item.name === 'Электромонтаж');
  assert.deepEqual(crew.memberIds, [person.id]);
  assert.throws(() => applyCommand(state, 'crew.save', { name: 'Другая', memberIds: [], leadId: person.id }), /Бригадир/);
  state = applyCommand(state, 'employee.save', { id: person.id, name: 'Тестовый электрик', role: 'Бригадир', active: false });
  assert.equal(state.employees.find((p) => p.id === person.id).active, false);
  assert.equal(state.crews.find((item) => item.name === 'Электромонтаж').leadId, person.id);
});
test('старый формат данных получает справочники персонала без потери заявок', () => {
  const old = createDemoState(); delete old.employees; delete old.crews;
  const restored = validateState(old);
  assert.equal(restored.employees.length, 9);
  assert.equal(restored.crews.length, 0);
  assert.equal(restored.leads.length, 5);
});
test('перенос задачи сохраняет исходный срок и полную историю изменений', () => {
  let state = createDemoState(); const original = state.tasks[0].dueAt;
  state = applyCommand(state, 'task.reschedule', { id: 'task-1', dueAt: '2026-10-01T11:30' });
  state = applyCommand(state, 'task.reschedule', { id: 'task-1', dueAt: '2026-10-03T11:30' });
  const task = state.tasks.find((item) => item.id === 'task-1');
  assert.equal(task.originalDueAt, original); assert.equal(task.dueAt, '2026-10-03T11:30'); assert.equal(task.rescheduleHistory.length, 2);
  assert.equal(task.rescheduleHistory[0].from, '2026-10-01T11:30'); validateState(state);
});
test('типовой план строительства создаёт 18 последовательных этапов', () => {
  const original = createDemoState();
  const state = applyCommand(original, 'construction.plan.initialize', { siteId: 'site-2', startDate: '2026-10-01' });
  const stages = state.constructionStages.filter((stage) => stage.siteId === 'site-2');
  assert.equal(stages.length, 18); assert.equal(stages[0].status, 'ready'); assert.deepEqual(stages[1].dependencyIds, [stages[0].id]);
  assert.equal(state.sites.find((site) => site.id === 'site-2').plannedStart, '2026-10-01');
  assert.throws(() => applyCommand(state, 'construction.plan.initialize', { siteId: 'site-2' }), /уже создан/);
});
test('этап строительства проверяет задержку, прогресс и связи', () => {
  let state = applyCommand(createDemoState(), 'construction.plan.initialize', { siteId: 'site-2', startDate: '2026-10-01' });
  const stage = state.constructionStages.find((item) => item.siteId === 'site-2');
  assert.throws(() => applyCommand(state, 'construction.stage.save', { id: stage.id, status: 'blocked', blockReason: '' }), /Причина задержки/);
  state = applyCommand(state, 'construction.stage.save', { id: stage.id, status: 'doing', progress: 35, assigneeId: 'worker-1' });
  assert.equal(state.constructionStages.find((item) => item.id === stage.id).progress, 35);
  state = applyCommand(state, 'construction.stage.comment', { id: stage.id, text: 'Завезён материал' });
  assert.equal(state.constructionStages.find((item) => item.id === stage.id).comments[0].text, 'Завезён материал');
  assert.throws(() => applyCommand(state, 'construction.stage.save', { id: stage.id, dependencyIds: [stage.id] }), /зависимости/);
});
test('задача связывается с объектом, этапом и бригадой', () => {
  const original = createDemoState(); const stage = original.constructionStages[2];
  const state = applyCommand(original, 'task.create', { title: 'Монтаж этапа', description: '', orderId: '', siteId: stage.siteId, constructionStageId: stage.id, crewId: 'crew-1', assigneeId: '', status: 'planned', priority: 'high', dueAt: '2026-10-10T17:00', quantity: 1, completedQty: 0, unit: 'этап', blockReason: '' });
  assert.equal(state.tasks[0].siteId, stage.siteId); assert.equal(state.tasks[0].constructionStageId, stage.id); assert.equal(state.tasks[0].crewId, 'crew-1');
  assert.throws(() => applyCommand(state, 'task.create', { title: 'Неверная связь', siteId: 'site-2', constructionStageId: stage.id, status: 'planned', priority: 'normal', dueAt: '2026-10-10T17:00', quantity: 1, completedQty: 0, unit: 'этап' }), /этап этого объекта/);
});

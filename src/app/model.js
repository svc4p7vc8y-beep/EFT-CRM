import { extendWorkspace, applyOperation, validateOperations } from './operations.js';
export const STORAGE_KEY = 'eft-crm.workspace.v1';
export const EMPLOYEE_AVATARS = Array.from({ length: 15 }, (_, index) => `./avatars/employee-${String(index + 1).padStart(2, '0')}.jpg`);
export const EMPLOYEES = [
  { id: 'manager-1', name: 'Иван Соколов', role: 'Менеджер', initials: 'ИС' },
  { id: 'estimator-1', name: 'Анна Кузнецова', role: 'Сметчик', initials: 'АК' },
  { id: 'manager-2', name: 'Ольга Смирнова', role: 'Менеджер', initials: 'ОС' },
  { id: 'manager-3', name: 'Дмитрий Волков', role: 'Менеджер', initials: 'ДВ' },
  ...Array.from({ length: 5 }, (_, i) => ({ id: `worker-${i + 1}`, name: `Сотрудник ${String(i + 1).padStart(2, '0')}`, role: 'Цех', initials: `С${i + 1}` })),
];
export const LEAD_STAGES = [
  { id: 'new', label: 'Новая', color: 'blue' }, { id: 'working', label: 'В работе', color: 'blue' },
  { id: 'calculation', label: 'Расчёт', color: 'green' }, { id: 'offer', label: 'Предложение', color: 'blue' },
  { id: 'approval', label: 'Согласование', color: 'amber' }, { id: 'contract', label: 'Договор', color: 'green' },
  { id: 'lost', label: 'Закрыта', color: 'gray' },
];
export const TASK_STAGES = [
  { id: 'planned', label: 'Запланировано', color: 'gray' }, { id: 'doing', label: 'В работе', color: 'blue' },
  { id: 'review', label: 'На проверке', color: 'green' }, { id: 'blocked', label: 'Блокировано', color: 'amber' },
  { id: 'done', label: 'Выполнено', color: 'green' },
];
export const ACTIVITY_TYPES = { note: 'Заметка', call: 'Звонок', meeting: 'Встреча', email: 'Письмо', message: 'Сообщение', system: 'Событие' };
export const CONSTRUCTION_STATUSES = [
  { id: 'planned', label: 'Запланирован', color: 'gray' }, { id: 'ready', label: 'Можно начинать', color: 'blue' },
  { id: 'doing', label: 'В работе', color: 'blue' }, { id: 'blocked', label: 'Задержка', color: 'amber' },
  { id: 'review', label: 'Приёмка', color: 'green' }, { id: 'done', label: 'Завершён', color: 'green' },
];
export const SITE_STATUSES = [
  { id: 'planning', label: 'Подготовка', color: 'gray' }, { id: 'active', label: 'Строится', color: 'blue' },
  { id: 'paused', label: 'Приостановлен', color: 'amber' }, { id: 'complete', label: 'Сдан', color: 'green' },
];
export const CONSTRUCTION_TEMPLATES = [
  ['Подготовка', 'Договор и исходные данные', 3], ['Подготовка', 'Геология и геодезия', 4], ['Проектирование', 'Архитектурный и конструктивный проект', 10],
  ['Участок', 'Подготовка участка', 3], ['Фундамент', 'Устройство фундамента', 10], ['Домокомплект', 'Производство домокомплекта', 12],
  ['Логистика', 'Доставка на объект', 2], ['Монтаж', 'Сборка силового контура', 14], ['Кровля', 'Монтаж кровли', 7],
  ['Контур', 'Окна и входные двери', 5], ['Фасад', 'Наружная отделка', 10], ['Инженерия', 'Электромонтаж', 7],
  ['Инженерия', 'Водоснабжение и канализация', 7], ['Инженерия', 'Отопление', 7], ['Инженерия', 'Вентиляция', 4],
  ['Отделка', 'Внутренняя отделка', 20], ['Участок', 'Благоустройство', 7], ['Сдача', 'Финальная приёмка и сдача дома', 2],
].map(([group, title, duration], index) => ({ id: `construction-${index + 1}`, group, title, duration }));
export const STAFF_ROLES = ['Менеджер', 'Сметчик', 'Руководитель', 'Начальник цеха', 'Цех', 'Бригадир', 'Монтажник', 'Электрик', 'Сантехник', 'Плиточник', 'Снабженец', 'Кладовщик', 'Логист', 'Администратор', 'Другое'];
export const employee = (id, state) => (state?.employees || EMPLOYEES).find((person) => person.id === id);
export const activeStaff = (state) => state.employees.filter((person) => person.active !== false);
export const officeStaff = (state) => activeStaff(state).filter((person) => !['Цех', 'Бригадир', 'Монтажник', 'Электрик', 'Сантехник', 'Плиточник'].includes(person.role));
export const workshopStaff = (state) => activeStaff(state).filter((person) => person.role === 'Цех');
export const initials = (name) => name.split(' ').slice(0, 2).map((part) => part[0]).join('');
export function dateKey(date = new Date()) { const d = new Date(date); return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`; }
export function offsetDate(days = 0) { const date = new Date(); date.setDate(date.getDate() + days); return dateKey(date); }
export const dueAt = (days, time = '16:00') => `${offsetDate(days)}T${time}`;
export function dateLabel(value, withTime = true) {
  if (!value) return 'Без срока';
  const d = new Date(value.length === 10 ? `${value}T12:00` : value);
  const day = dateKey(d) === dateKey() ? 'Сегодня' : dateKey(d) === offsetDate(1) ? 'Завтра' : d.toLocaleDateString('ru-RU', { day: 'numeric', month: 'long' });
  return `${day}${withTime && value.includes('T') ? `, ${d.toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' })}` : ''}`;
}
export const isOverdue = (value) => Boolean(value && new Date(value) < new Date());
export function leadContext(state, lead) { const site = state.sites.find((s) => s.id === lead.siteId); const client = state.clients.find((c) => c.id === site?.clientId); return { site, client }; }
export function taskContext(state, task) { const order = state.orders.find((o) => o.id === task.orderId); const site = state.sites.find((s) => s.id === order?.siteId); return { order, site }; }
const uid = () => crypto.randomUUID();
const text = (v, max = 5000) => String(v ?? '').trim().slice(0, max);
const requireText = (value, label) => { const v = text(value); if (!v) throw new Error(`Заполните поле «${label}»`); return v; };
function validDue(value) { if (value && (typeof value !== 'string' || !/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/.test(value) || Number.isNaN(Date.parse(value)))) throw new Error('Укажите корректную дату и время'); return value || ''; }

export function createDemoState() {
  const stamp = new Date().toISOString();
  const sizes = ['8 × 10', '10 × 12', '9 × 9', '6 × 8', '8 × 8'];
  const clients = sizes.map((_, i) => ({ id: `client-${i + 1}`, name: `Демо-клиент 0${i + 1}`, phone: '', email: `client${i + 1}@example.invalid` }));
  const sites = sizes.map((size, i) => ({ id: `site-${i + 1}`, clientId: clients[i].id, name: `Дом ${size}`, address: ['Учебный объект · Истра', 'Учебный объект · Клин', 'Учебный объект · Дмитров', 'Учебный объект · Руза', 'Учебный объект · Чехов'][i] }));
  const leads = sizes.map((_, i) => ({ id: `lead-${i + 1}`, siteId: sites[i].id, status: ['new', 'calculation', 'approval', 'contract', 'offer'][i], ownerId: ['manager-1', 'estimator-1', 'manager-1', 'manager-2', 'manager-3'][i], nextAction: ['Уточнить комплектацию', 'Подготовить предложение', 'Получить ответ по КП', 'Передать в производство', 'Обсудить сроки'][i], dueAt: dueAt(i < 2 ? 0 : i - 1, i ? '16:00' : '12:00'), source: i % 2 ? 'Рекомендация' : 'Сайт', notes: i === 2 ? 'Интересует комфорт-комплектация. Нужна консультация по фундаменту.' : '', createdAt: stamp }));
  const orders = sites.slice(0, 4).map((site, i) => ({ id: `order-${i + 1}`, number: `ДЕМО-0${i + 1}`, siteId: site.id, leadId: '', scope: `Демонстрационная комплектация: ${site.name}, комплект СИП-панелей`, reference: 'Учебная спецификация', approvedBy: 'manager-1', approvedAt: stamp, createdAt: stamp }));
  const titles = ['Раскрой панелей пола', 'Подготовка комплекта крепежа', 'Сборка стеновых панелей', 'Маркировка панелей', 'Контроль геометрии', 'Комплектация перекрытия'];
  const tasks = titles.map((title, i) => ({ id: `task-${i + 1}`, orderId: orders[[0, 1, 2, 0, 1, 3][i]].id, title, description: i === 5 ? 'Запросить недостающий брус у снабжения.' : 'Выполнить по согласованной спецификации заказа. Результат передать на проверку.', assigneeId: `worker-${Math.min(i + 1, 5)}`, status: ['planned', 'planned', 'doing', 'doing', 'review', 'blocked'][i], priority: i === 5 ? 'high' : 'normal', dueAt: dueAt(i < 2 ? -1 : 0, ['16:00', '16:00', '18:00', '17:00', '15:00', '16:00'][i]), quantity: i === 0 ? 38 : i === 2 ? 24 : 1, completedQty: i === 2 ? 12 : i === 4 ? 1 : 0, unit: i === 0 || i === 2 ? 'панелей' : 'комплект', blockReason: i === 5 ? 'Не хватает бруса' : '', checklist: [{ id: `check-${i}-1`, title: 'Проверить спецификацию', done: i >= 2 }, { id: `check-${i}-2`, title: 'Подготовить результат к приёмке', done: i === 4 }], createdAt: stamp, originalDueAt: '', rescheduleHistory: [] }));
  const activities = [{ id: 'activity-1', siteId: 'site-3', taskId: '', type: 'email', text: 'КП отправлено. Ожидаем ответ по комплектации.', createdAt: dueAt(-1, '10:24'), authorId: 'manager-1' }, { id: 'activity-2', siteId: 'site-3', taskId: '', type: 'call', text: 'Уточнена комплектация. Обсудили изменения в планировке.', createdAt: dueAt(-3, '14:17'), authorId: 'manager-1' }, { id: 'activity-3', siteId: 'site-3', taskId: '', type: 'system', text: 'Новая заявка с сайта', createdAt: dueAt(-5, '09:03'), authorId: 'manager-1' }];
  const employees = EMPLOYEES.map((person, index) => ({ ...person, avatar: EMPLOYEE_AVATARS[index], department: person.role === 'Цех' ? 'Производство' : 'Офис', phone: '', email: '', active: true, notes: '', attendanceMode: 'hours', payRate: 0, advanceAmount: 0 }));
  const constructionStages = CONSTRUCTION_TEMPLATES.slice(0, 8).map((template, index) => ({ id: `stage-${index + 1}`, siteId: 'site-1', title: template.title, group: template.group, status: index < 2 ? 'done' : index === 2 ? 'doing' : index === 3 ? 'ready' : 'planned', progress: index < 2 ? 100 : index === 2 ? 45 : 0, plannedStart: offsetDate(index * 3), plannedFinish: offsetDate(index * 3 + template.duration), actualStart: index < 3 ? offsetDate(index * 3) : '', actualFinish: index < 2 ? offsetDate(index * 3 + template.duration - 1) : '', assigneeId: index === 2 ? 'worker-1' : '', crewId: '', dependencyIds: index ? [`stage-${index}`] : [], notes: '', blockReason: '', comments: [], attachments: [], createdAt: stamp, updatedAt: stamp }));
  return extendWorkspace({ schemaVersion: 1, revision: 0, clients, sites, leads, orders, tasks, activities, employees, crews: [], constructionStages });
}

function log(state, siteId, message, taskId = '', type = 'system', authorId = 'manager-1') { state.activities.unshift({ id: uid(), siteId, taskId, type, text: message, authorId, createdAt: new Date().toISOString() }); }
export function applyCommand(current, action, payload = {}) {
  const state = extendWorkspace(structuredClone(current));
  if (applyOperation(state, action, payload)) { /* Operation committed below with shared validation. */ }
  else if (action === 'lead.create') {
    let client = state.clients.find((c) => c.id === payload.clientId);
    if (!client) { client = { id: uid(), name: requireText(payload.name, 'Клиент'), phone: text(payload.phone, 60), email: text(payload.email, 200) }; state.clients.push(client); }
    const site = { id: uid(), clientId: client.id, name: requireText(payload.siteName, 'Объект'), address: text(payload.address, 500) };
    state.sites.push(site);
    const lead = { id: uid(), siteId: site.id, status: 'new', ownerId: payload.ownerId || 'manager-1', nextAction: requireText(payload.nextAction, 'Следующий шаг'), dueAt: validDue(payload.dueAt), source: text(payload.source, 100) || 'Вручную', notes: text(payload.notes), createdAt: new Date().toISOString() };
    state.leads.unshift(lead); log(state, site.id, 'Создана заявка');
  } else if (action === 'lead.update') {
    const lead = state.leads.find((v) => v.id === payload.id); if (!lead) throw new Error('Заявка не найдена');
    if (payload.status && !LEAD_STAGES.some((s) => s.id === payload.status)) throw new Error('Неизвестный этап заявки');
    const oldStatus = lead.status;
    for (const key of ['status', 'ownerId', 'nextAction', 'notes']) if (payload[key] !== undefined) lead[key] = text(payload[key]);
    if (payload.dueAt !== undefined) lead.dueAt = validDue(payload.dueAt);
    log(state, lead.siteId, oldStatus !== lead.status ? `Этап заявки: ${LEAD_STAGES.find((s) => s.id === lead.status).label}` : 'Обновлены данные заявки');
  } else if (action === 'client.update') {
    const client = state.clients.find((v) => v.id === payload.id); if (!client) throw new Error('Клиент не найден');
    client.name = requireText(payload.name, 'Клиент'); client.phone = text(payload.phone, 60); client.email = text(payload.email, 200);
    const site = state.sites.find((v) => v.id === payload.siteId); if (site) { site.address = text(payload.address, 500); site.name = requireText(payload.siteName, 'Объект'); log(state, site.id, 'Обновлены контакты и данные объекта'); }
  } else if (action === 'activity.create') {
    if (!state.sites.some((v) => v.id === payload.siteId) && !state.tasks.some((t) => t.id === payload.taskId && !t.orderId)) throw new Error('Выберите объект');
    if (!ACTIVITY_TYPES[payload.type] || payload.type === 'system') throw new Error('Выберите вид общения');
    log(state, payload.siteId, requireText(payload.text, 'Результат общения'), payload.taskId || '', payload.type);
  } else if (action === 'order.create') {
    const lead = state.leads.find((v) => v.id === payload.leadId); if (!lead) throw new Error('Заявка не найдена');
    if (state.orders.some((v) => v.leadId === lead.id)) throw new Error('Эта заявка уже передана в производство');
    if (!payload.approved) throw new Error('Подтвердите согласование комплектации');
    const scope = requireText(payload.scope, 'Согласованная комплектация');
    const stamp = new Date().toISOString();
    const order = { id: uid(), number: `ЭФТ-${String(state.orders.length + 1).padStart(4, '0')}`, siteId: lead.siteId, leadId: lead.id, scope, reference: text(payload.reference, 1000), approvedAt: stamp, approvedBy: lead.ownerId, createdAt: stamp };
    state.orders.unshift(order); lead.status = 'contract'; lead.nextAction = 'Контроль производства'; lead.dueAt = validDue(payload.dueAt);
    state.tasks.unshift({ id: uid(), orderId: order.id, title: 'Подготовить заказ к производству', description: scope, assigneeId: payload.assigneeId || '', status: 'planned', priority: 'normal', dueAt: validDue(payload.dueAt), quantity: 1, completedQty: 0, unit: 'комплект', blockReason: '', checklist: [], createdAt: stamp });
    log(state, lead.siteId, `Заказ ${order.number} передан в цех. Комплектация зафиксирована.`);
  } else if (action === 'task.reschedule') {
    const task = state.tasks.find((value) => value.id === payload.id); if (!task) throw new Error('Задание не найдено');
    const from = task.dueAt; const to = validDue(payload.dueAt); if (!to) throw new Error('Укажите новый срок');
    if (from === to) return state;
    task.originalDueAt ||= from; task.rescheduleHistory.unshift({ from, to, at: new Date().toISOString() }); task.rescheduleHistory = task.rescheduleHistory.slice(0, 100); task.dueAt = to;
    log(state, state.orders.find((order) => order.id === task.orderId)?.siteId || '', `Перенесён срок задания: ${task.title}`, task.id);
  } else if (action === 'task.create' || action === 'task.update') {
    const existing = state.tasks.find((v) => v.id === payload.id);
    if (action === 'task.update' && !existing) throw new Error('Задание не найдено');
    const template = state.taskTemplates.find((t) => t.id === payload.templateId);
    const task = existing || { id: uid(), createdAt: new Date().toISOString(), checklist: (template?.checklist || []).map((title) => ({ id: uid(), title, done: false })), status: 'planned', completedQty: 0, blockReason: '', orderId: '', originalDueAt: '', rescheduleHistory: [] };
    const next = { ...task, ...Object.fromEntries(Object.entries(payload).filter(([key]) => ['title', 'description', 'orderId', 'assigneeId', 'status', 'priority', 'dueAt', 'quantity', 'completedQty', 'unit', 'blockReason'].includes(key))) };
    next.title = requireText(next.title, 'Название задания'); next.description = text(next.description); next.unit = requireText(next.unit, 'Единица'); next.blockReason = text(next.blockReason);
    if (next.orderId && !state.orders.some((v) => v.id === next.orderId)) throw new Error('Выберите заказ');
    if (!TASK_STAGES.some((v) => v.id === next.status)) throw new Error('Неизвестный статус задания');
    if (next.status === 'blocked' && !next.blockReason) throw new Error('Укажите причину блокировки');
    if (next.status !== 'blocked') next.blockReason = '';
    next.dueAt = validDue(next.dueAt); next.quantity = Number(next.quantity); next.completedQty = Number(next.completedQty);
    if (!Number.isFinite(next.quantity) || next.quantity <= 0 || !Number.isFinite(next.completedQty) || next.completedQty < 0 || next.completedQty > next.quantity) throw new Error('Готовый объём должен быть от 0 до общего объёма');
    if (next.status === 'done') {
      if (next.checklist.some((v) => !v.done)) throw new Error('Выполните все пункты чек-листа перед приёмкой');
      next.completedQty = next.quantity;
    }
    if (existing) Object.assign(existing, next); else state.tasks.unshift(next);
    log(state, state.orders.find((o) => o.id === next.orderId)?.siteId || '', existing ? `${next.title}: ${TASK_STAGES.find((s) => s.id === next.status).label}` : `Создано задание: ${next.title}`, next.id);
  } else if (action === 'construction.plan.initialize') {
    const site = state.sites.find((value) => value.id === payload.siteId); if (!site) throw new Error('Объект не найден');
    if (state.constructionStages.some((stage) => stage.siteId === site.id)) throw new Error('План по объекту уже создан');
    let cursor = payload.startDate ? new Date(`${payload.startDate}T12:00:00`) : new Date();
    let previousId = '';
    for (const template of CONSTRUCTION_TEMPLATES) {
      const stageId = uid(); const plannedStart = dateKey(cursor); cursor.setDate(cursor.getDate() + template.duration); const plannedFinish = dateKey(cursor);
      state.constructionStages.push({ id: stageId, siteId: site.id, title: template.title, group: template.group, status: previousId ? 'planned' : 'ready', progress: 0, plannedStart, plannedFinish, actualStart: '', actualFinish: '', assigneeId: '', crewId: '', dependencyIds: previousId ? [previousId] : [], notes: '', blockReason: '', comments: [], attachments: [], createdAt: new Date().toISOString(), updatedAt: new Date().toISOString() });
      previousId = stageId; cursor.setDate(cursor.getDate() + 1);
    }
    site.status = 'planning'; site.plannedStart ||= state.constructionStages.find((stage) => stage.siteId === site.id)?.plannedStart || ''; site.plannedFinish = dateKey(cursor);
    log(state, site.id, 'Создан типовой план строительства из 18 этапов');
  } else if (action === 'construction.site.update') {
    const site = state.sites.find((value) => value.id === payload.id); if (!site) throw new Error('Объект не найден');
    if (!SITE_STATUSES.some((value) => value.id === payload.status)) throw new Error('Выберите статус объекта');
    for (const key of ['status','managerId','contractNumber','plannedStart','plannedFinish','actualStart','actualFinish','notes']) if (payload[key] !== undefined) site[key] = text(payload[key], key === 'notes' ? 5000 : 200);
    for (const key of ['plannedStart','plannedFinish','actualStart','actualFinish']) if (site[key] && !/^\d{4}-\d{2}-\d{2}$/.test(site[key])) throw new Error('Проверьте даты объекта');
    log(state, site.id, 'Обновлены параметры строительства');
  } else if (action === 'construction.stage.save') {
    const existing = state.constructionStages.find((value) => value.id === payload.id);
    const siteId = payload.siteId || existing?.siteId; if (!state.sites.some((value) => value.id === siteId)) throw new Error('Объект не найден');
    const status = payload.status || existing?.status || 'planned'; if (!CONSTRUCTION_STATUSES.some((value) => value.id === status)) throw new Error('Выберите статус этапа');
    const progress = status === 'done' ? 100 : Number(payload.progress ?? existing?.progress ?? 0); if (!Number.isFinite(progress) || progress < 0 || progress > 100) throw new Error('Готовность должна быть от 0 до 100%');
    const dependencies = [...new Set(Array.isArray(payload.dependencyIds) ? payload.dependencyIds : existing?.dependencyIds || [])].filter(Boolean);
    if (dependencies.includes(existing?.id) || dependencies.some((id) => !state.constructionStages.some((stage) => stage.id === id && stage.siteId === siteId))) throw new Error('Проверьте зависимости этапа');
    const next = { id: existing?.id || uid(), siteId, title: requireText(payload.title ?? existing?.title, 'Название этапа'), group: requireText(payload.group ?? existing?.group ?? 'Другое', 'Раздел'), status, progress, plannedStart: text(payload.plannedStart ?? existing?.plannedStart, 10), plannedFinish: text(payload.plannedFinish ?? existing?.plannedFinish, 10), actualStart: text(payload.actualStart ?? existing?.actualStart, 10), actualFinish: text(payload.actualFinish ?? existing?.actualFinish, 10), assigneeId: text(payload.assigneeId ?? existing?.assigneeId, 36), crewId: text(payload.crewId ?? existing?.crewId, 36), dependencyIds: dependencies, notes: text(payload.notes ?? existing?.notes), blockReason: status === 'blocked' ? requireText(payload.blockReason ?? existing?.blockReason, 'Причина задержки') : '', comments: existing?.comments || [], attachments: existing?.attachments || [], createdAt: existing?.createdAt || new Date().toISOString(), updatedAt: new Date().toISOString() };
    for (const key of ['plannedStart','plannedFinish','actualStart','actualFinish']) if (next[key] && !/^\d{4}-\d{2}-\d{2}$/.test(next[key])) throw new Error('Проверьте даты этапа');
    if (next.assigneeId && !state.employees.some((person) => person.id === next.assigneeId)) throw new Error('Ответственный сотрудник не найден');
    if (next.crewId && !state.crews.some((crew) => crew.id === next.crewId)) throw new Error('Бригада не найдена');
    if (existing) Object.assign(existing, next); else state.constructionStages.push(next);
    log(state, siteId, `${existing ? 'Обновлён' : 'Добавлен'} этап: ${next.title}`);
  } else if (action === 'construction.stage.comment') {
    const stage = state.constructionStages.find((value) => value.id === payload.id); if (!stage) throw new Error('Этап не найден');
    stage.comments.unshift({ id: uid(), text: requireText(payload.text, 'Комментарий'), authorId: text(payload.authorId || 'manager-1', 36), createdAt: new Date().toISOString() }); stage.updatedAt = new Date().toISOString();
  } else if (action === 'employee.save') {
    const existing = state.employees.find((person) => person.id === payload.id);
    const name = requireText(payload.name, 'Имя сотрудника').slice(0, 200);
    if (!String(payload.role || '').trim() || String(payload.role).length > 120) throw new Error('Укажите должность');
    const avatar = EMPLOYEE_AVATARS.includes(payload.avatar) ? payload.avatar : existing?.avatar || EMPLOYEE_AVATARS[state.employees.length % EMPLOYEE_AVATARS.length];
    const attendanceMode = ['hours', 'days'].includes(payload.attendanceMode) ? payload.attendanceMode : existing?.attendanceMode || 'hours';
    const payRate = payload.payRate === undefined ? existing?.payRate || 0 : Number(payload.payRate);
    if (!Number.isFinite(payRate) || payRate < 0 || payRate > 1e7) throw new Error('Проверьте ставку сотрудника');
    const next = { id: existing?.id || uid(), name, role: payload.role, department: text(payload.department, 100), phone: text(payload.phone, 60), email: text(payload.email, 200), active: payload.active !== false && payload.active !== 'false', notes: text(payload.notes, 2000), initials: initials(name), avatar, attendanceMode, payRate, advanceAmount: existing?.advanceAmount || 0 };
    if (existing) Object.assign(existing, next); else state.employees.push(next);
  } else if (action === 'crew.save') {
    const existing = state.crews.find((crew) => crew.id === payload.id);
    const name = requireText(payload.name, 'Название бригады').slice(0, 200);
    const memberIds = [...new Set(Array.isArray(payload.memberIds) ? payload.memberIds : [])];
    if (memberIds.some((id) => !state.employees.some((person) => person.id === id))) throw new Error('Сотрудник бригады не найден');
    const next = { id: existing?.id || uid(), name, specialty: text(payload.specialty, 100), leadId: payload.leadId || '', memberIds, phone: text(payload.phone, 60), notes: text(payload.notes, 2000), active: payload.active !== false && payload.active !== 'false' };
    if (next.leadId && !memberIds.includes(next.leadId)) throw new Error('Бригадир должен входить в состав бригады');
    if (existing) Object.assign(existing, next); else state.crews.push(next);
  } else if (action === 'task.check') {
    const task = state.tasks.find((v) => v.id === payload.id); if (!task) throw new Error('Задание не найдено');
    if (task.status === 'done') throw new Error('Сначала верните задание в работу');
    if (payload.checkId) { const item = task.checklist.find((v) => v.id === payload.checkId); if (item) item.done = !item.done; }
    else task.checklist.push({ id: uid(), title: requireText(payload.title, 'Пункт чек-листа'), done: false });
    log(state, state.orders.find((v) => v.id === task.orderId)?.siteId || '', `Обновлён чек-лист: ${task.title}`, task.id);
  } else throw new Error('Неизвестное действие');
  state.revision += 1;
  validateState(state);
  return state;
}

export function validateState(state) {
  if (!state || state.schemaVersion !== 1 || !Number.isInteger(state.revision) || state.revision < 0) throw new Error('Неподдерживаемый формат данных');
  if (state.employees === undefined) state.employees = createDemoState().employees;
  if (state.crews === undefined) state.crews = [];
  extendWorkspace(state);
  const fields = { clients: ['id', 'name', 'phone', 'email'], sites: ['id', 'clientId', 'name', 'address', 'status', 'managerId', 'contractNumber', 'plannedStart', 'plannedFinish', 'actualStart', 'actualFinish', 'notes'], leads: ['id', 'siteId', 'status', 'ownerId', 'nextAction', 'dueAt', 'source', 'notes', 'createdAt'], orders: ['id', 'number', 'siteId', 'leadId', 'scope', 'reference', 'approvedBy', 'approvedAt', 'createdAt'], tasks: ['id', 'orderId', 'title', 'description', 'assigneeId', 'status', 'priority', 'dueAt', 'unit', 'blockReason', 'createdAt'], activities: ['id', 'siteId', 'taskId', 'type', 'text', 'createdAt', 'authorId'] };
  for (const [table, columns] of Object.entries(fields)) {
    if (!Array.isArray(state[table]) || state[table].length > 20000) throw new Error(`Неверный раздел данных: ${table}`);
    const ids = new Set();
    for (const row of state[table]) { if (!row || columns.some((c) => typeof row[c] !== 'string' || row[c].length > 10000) || !row.id || ids.has(row.id)) throw new Error(`Некорректная запись: ${table}`); ids.add(row.id); }
  }
  const has = (table, id) => state[table].some((r) => r.id === id);
  if (!Array.isArray(state.employees) || state.employees.length > 20000 || state.employees.some((p) => !p || typeof p.id !== 'string' || !p.id || typeof p.name !== 'string' || !p.name || typeof p.role !== 'string' || !p.role.trim() || p.role.length > 120 || typeof p.phone !== 'string' || typeof p.email !== 'string' || typeof p.department !== 'string' || typeof p.notes !== 'string' || typeof p.active !== 'boolean' || typeof p.avatar !== 'string' || !EMPLOYEE_AVATARS.includes(p.avatar) || !['hours','days'].includes(p.attendanceMode) || !Number.isFinite(p.payRate) || p.payRate < 0 || !Number.isFinite(p.advanceAmount) || p.advanceAmount < 0) || new Set(state.employees.map((p) => p.id)).size !== state.employees.length) throw new Error('Некорректный список сотрудников');
  if (!Array.isArray(state.crews) || state.crews.length > 20000 || state.crews.some((c) => !c || typeof c.id !== 'string' || !c.id || typeof c.name !== 'string' || !c.name || typeof c.specialty !== 'string' || typeof c.phone !== 'string' || typeof c.notes !== 'string' || typeof c.active !== 'boolean' || !Array.isArray(c.memberIds) || c.memberIds.some((id) => !has('employees', id)) || (c.leadId && (!has('employees', c.leadId) || !c.memberIds.includes(c.leadId))))) throw new Error('Некорректный список бригад');
  if (state.sites.some((s) => !has('clients', s.clientId)) || state.leads.some((l) => !has('sites', l.siteId) || !has('employees', l.ownerId) || !LEAD_STAGES.some((v) => v.id === l.status)) || state.orders.some((o) => !has('sites', o.siteId) || (o.leadId && !has('leads', o.leadId)))) throw new Error('Нарушены связи клиентов, заявок и заказов');
  if (!Array.isArray(state.constructionStages) || state.constructionStages.length > 50000 || state.constructionStages.some((stage) => !stage || typeof stage.id !== 'string' || !stage.id || !has('sites', stage.siteId) || typeof stage.title !== 'string' || !stage.title || typeof stage.group !== 'string' || !CONSTRUCTION_STATUSES.some((value) => value.id === stage.status) || !Number.isFinite(stage.progress) || stage.progress < 0 || stage.progress > 100 || !Array.isArray(stage.dependencyIds) || stage.dependencyIds.some((id) => !state.constructionStages.some((value) => value.id === id && value.siteId === stage.siteId)) || !Array.isArray(stage.comments) || !Array.isArray(stage.attachments))) throw new Error('Некорректный план строительства');
  for (const lead of state.leads) validDue(lead.dueAt);
  for (const task of state.tasks) {
    if ((task.orderId && !has('orders', task.orderId)) || (task.assigneeId && !has('employees', task.assigneeId)) || !TASK_STAGES.some((v) => v.id === task.status) || !['normal', 'high'].includes(task.priority) || !Number.isFinite(task.quantity) || task.quantity <= 0 || !Number.isFinite(task.completedQty) || task.completedQty < 0 || task.completedQty > task.quantity || !Array.isArray(task.checklist) || task.checklist.some((v) => typeof v.id !== 'string' || typeof v.title !== 'string' || typeof v.done !== 'boolean') || typeof task.originalDueAt !== 'string' || !Array.isArray(task.rescheduleHistory) || task.rescheduleHistory.some((entry) => !entry || typeof entry.from !== 'string' || typeof entry.to !== 'string' || typeof entry.at !== 'string' || Number.isNaN(Date.parse(entry.at)))) throw new Error('Некорректное производственное задание');
    validDue(task.dueAt);
    if (task.originalDueAt) validDue(task.originalDueAt);
    for (const entry of task.rescheduleHistory) { if (entry.from) validDue(entry.from); validDue(entry.to); }
  }
  if (state.activities.some((a) => (!has('sites', a.siteId) && !(a.taskId && state.tasks.some((t) => t.id === a.taskId && !t.orderId))) || (a.taskId && !has('tasks', a.taskId)) || !ACTIVITY_TYPES[a.type] || Number.isNaN(Date.parse(a.createdAt)))) throw new Error('Некорректная история событий');
  validateOperations(state);
  return state;
}

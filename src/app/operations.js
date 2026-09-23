import catalog from '../data/calculator-catalog.json' with { type: 'json' };

export const RELEASE = 10;
export const PRICE_SOURCE = catalog.source;
export function exportCalculatorPrices(state) {
  return { format: 'eft-price-catalog', appVersion: 144, priceMat: state.materials.map((m) => ({ id: m.id, kind: 'material', cat: m.category, name: m.name, unit: m.unit, price: m.price, ...(m.priceNote ? { priceNote: m.priceNote } : {}) })), priceLab: structuredClone(catalog.priceLab) };
}
export const money = (value) => Number(value || 0).toLocaleString('ru-RU', { maximumFractionDigits: 2 }) + ' ₽';
export const round = (value) => Math.round((value + Number.EPSILON) * 100) / 100;
const id = () => crypto.randomUUID();
const required = (value, label) => { const result = String(value ?? '').trim().slice(0, 2000); if (!result) throw new Error(`Заполните: ${label}`); return result; };
const num = (value, min = 0, max = 1e9) => { const n = Number(value); if (!Number.isFinite(n) || n < min || n > max) throw new Error('Проверьте количество или сумму'); return n; };
export function validDay(value) { if (typeof value !== 'string' || !/^\d{4}-\d{2}-\d{2}$/.test(value) || new Date(value + 'T12:00:00Z').toISOString().slice(0, 10) !== value) throw new Error('Укажите корректную дату'); return value; }
export function extendWorkspace(state) {
  state.materials ??= catalog.priceMat.map((row) => ({ id: row.id, name: row.name, category: row.cat || 'Без категории', unit: row.unit, price: row.price, tracked: false, minStock: 0, source: PRICE_SOURCE, priceNote: row.priceNote || (row.pricePending ? 'Цена требует уточнения' : row.priceEstimated ? 'Предварительная цена' : '') }));
  state.taskTemplates ??= [
    ...['Раскрой панелей', 'Сборка панелей', 'Маркировка и упаковка', 'Контроль качества', 'Подготовка инструмента'].map((title, i) => ({ id: `factory-action-${i}`, title, unit: 'комплект', description: '', checklist: ['Проверить задание', 'Передать результат'], active: true })),
    ...catalog.priceLab.map((row) => ({ id: row.id, title: row.name, unit: row.unit, description: row.cat, checklist: [], active: true })),
  ];
  for (const key of ['stockDocuments', 'purchases', 'suppliers', 'tools', 'toolEvents', 'attendance']) state[key] ??= [];
  return state;
}
export const documentTypes = { receipt: 'Приход', issue: 'Выдача', return: 'Возврат', writeoff: 'Списание', direct: 'Покупка на объект' };
export function stockFor(state, itemId) {
  let quantity = 0, value = 0;
  for (const doc of state.stockDocuments) for (const line of doc.lines) if (line.itemId === itemId && doc.kind !== 'direct') {
    const sign = ['receipt', 'return'].includes(doc.kind) ? 1 : -1;
    quantity = round(quantity + sign * line.quantity); value = quantity === 0 ? 0 : round(value + sign * line.quantity * line.price);
  }
  return { quantity, value: quantity === 0 ? 0 : value, average: quantity > 0 ? round(value / quantity) : 0 };
}
export const receivedQuantity = (state, purchaseId, itemId) => round(state.stockDocuments.filter((d) => d.purchaseId === purchaseId).flatMap((d) => d.lines).filter((l) => l.itemId === itemId).reduce((n, l) => n + l.quantity, 0));
function linesFrom(state, lines) {
  if (!Array.isArray(lines) || !lines.length || lines.length > 200) throw new Error('Добавьте позиции документа');
  const seen = new Set();
  return lines.map((line) => { const item = state.materials.find((m) => m.id === line.itemId); if (!item || seen.has(item.id)) throw new Error('Позиция не найдена или повторяется'); seen.add(item.id); return { itemId: item.id, name: item.name, unit: item.unit, quantity: num(line.quantity, 0.01, 1e6), price: round(num(line.price)) }; });
}
export function applyOperation(state, action, payload) {
  if (action === 'material.save') {
    const previous = state.materials.find((m) => m.id === payload.id);
    if (previous && state.stockDocuments.some((d) => d.lines.some((l) => l.itemId === previous.id)) && previous.unit !== payload.unit) throw new Error('Единицу материала с движениями менять нельзя');
    if (previous?.tracked && !payload.tracked && stockFor(state, previous.id).quantity !== 0) throw new Error('Сначала обнулите складской остаток движениями');
    const price = round(num(payload.price));
    const item = { id: previous?.id || id(), name: required(payload.name, 'материал'), category: required(payload.category, 'категория'), unit: required(payload.unit, 'единица'), price, tracked: Boolean(payload.tracked), minStock: num(payload.minStock), source: previous && previous.price === price ? previous.source : 'Введено в CRM', priceNote: previous && previous.price === price ? previous.priceNote : '' };
    if (previous) Object.assign(previous, item); else state.materials.push(item);
  } else if (action === 'supplier.save') {
    const previous = state.suppliers.find((s) => s.id === payload.id);
    const supplier = { id: previous?.id || id(), name: required(payload.name, 'поставщик'), contact: String(payload.contact || '').slice(0, 500), notes: String(payload.notes || '').slice(0, 2000) };
    if (previous) Object.assign(previous, supplier); else state.suppliers.push(supplier);
  } else if (action === 'purchase.create') {
    if (!state.suppliers.some((s) => s.id === payload.supplierId)) throw new Error('Выберите поставщика');
    state.purchases.push({ id: id(), number: `ЗК-${String(state.purchases.length + 1).padStart(4, '0')}`, date: validDay(payload.date), dueDate: payload.dueDate ? validDay(payload.dueDate) : '', supplierId: payload.supplierId, lines: linesFrom(state, payload.lines), note: String(payload.note || '').slice(0, 2000), createdAt: new Date().toISOString() });
  } else if (action === 'stock.post') {
    if (!documentTypes[payload.kind]) throw new Error('Выберите вид движения');
    const lines = linesFrom(state, payload.lines);
    const purchase = payload.purchaseId ? state.purchases.find((p) => p.id === payload.purchaseId) : null;
    if (payload.purchaseId && (!purchase || !['receipt', 'direct'].includes(payload.kind))) throw new Error('Закупка не найдена');
    if (payload.supplierId && !state.suppliers.some((s) => s.id === payload.supplierId)) throw new Error('Поставщик не найден');
    if (payload.orderId && !state.orders.some((o) => o.id === payload.orderId)) throw new Error('Заказ не найден');
    if (purchase && payload.supplierId !== purchase.supplierId) throw new Error('Поставщик должен совпадать с закупкой');
    const target = required(payload.target, 'поставщик, получатель или основание');
    for (const line of lines) {
      const item = state.materials.find((m) => m.id === line.itemId);
      if (payload.kind !== 'direct' && !item.tracked) throw new Error(`Включите складской учёт: ${item.name}`);
      if (purchase) { const ordered = purchase.lines.find((l) => l.itemId === item.id); if (!ordered || receivedQuantity(state, purchase.id, item.id) + line.quantity > ordered.quantity) throw new Error('Приход превышает остаток закупки'); }
      const stock = stockFor(state, item.id);
      if (['issue', 'writeoff'].includes(payload.kind)) {
        if (stock.quantity < line.quantity) throw new Error(`Недостаточно на складе: ${item.name} (${stock.quantity} ${item.unit})`);
        line.price = stock.average;
      }
      if (payload.updatePrice && ['receipt', 'direct'].includes(payload.kind)) { item.price = line.price; item.priceNote = ''; item.source = `Последнее поступление: ${payload.date}`; }
    }
    state.stockDocuments.push({ id: id(), number: `ДВ-${String(state.stockDocuments.length + 1).padStart(4, '0')}`, date: validDay(payload.date), kind: payload.kind, target, supplierId: payload.supplierId || '', purchaseId: payload.purchaseId || '', orderId: payload.orderId || '', reference: String(payload.reference || '').slice(0, 500), note: String(payload.note || '').slice(0, 2000), lines, total: round(lines.reduce((n, l) => n + l.quantity * l.price, 0)), createdAt: new Date().toISOString() });
  } else if (action === 'tool.save') {
    const previous = state.tools.find((t) => t.id === payload.id);
    if (!['production', 'field'].includes(payload.home)) throw new Error('Выберите назначение инструмента');
    const serial = required(payload.serial, 'инвентарный номер');
    if (state.tools.some((t) => t.id !== previous?.id && t.serial.toLowerCase() === serial.toLowerCase())) throw new Error('Инвентарный номер уже используется');
    const tool = { id: previous?.id || id(), name: required(payload.name, 'инструмент'), serial, home: payload.home, price: round(num(payload.price)), note: String(payload.note || '').slice(0, 2000), holderType: previous?.holderType || '', holderId: previous?.holderId || '', dueDate: previous?.dueDate || '' };
    if (previous) Object.assign(previous, tool); else state.tools.push(tool);
  } else if (action === 'tool.transfer') {
    const tool = state.tools.find((t) => t.id === payload.id); if (!tool) throw new Error('Инструмент не найден');
    if (payload.kind === 'issue') {
      if (tool.holderId) throw new Error('Инструмент уже выдан. Оформите возврат');
      const table = payload.holderType === 'crew' ? state.crews : payload.holderType === 'employee' ? state.employees : [];
      const holder = table.find((p) => p.id === payload.holderId && p.active); if (!holder) throw new Error('Выберите получателя');
      tool.holderType = payload.holderType; tool.holderId = holder.id; tool.dueDate = payload.dueDate ? validDay(payload.dueDate) : '';
    } else if (payload.kind === 'return') { if (!tool.holderId) throw new Error('Инструмент находится на базе'); }
    else throw new Error('Выберите действие с инструментом');
    const holder = (tool.holderType === 'crew' ? state.crews : state.employees).find((p) => p.id === tool.holderId);
    state.toolEvents.push({ id: id(), toolId: tool.id, toolName: tool.name, serial: tool.serial, kind: payload.kind, holder: holder.name, date: validDay(payload.date), dueDate: tool.dueDate, note: String(payload.note || '').slice(0, 2000) });
    if (payload.kind === 'return') { tool.holderType = ''; tool.holderId = ''; tool.dueDate = ''; }
  } else if (action === 'attendance.save') {
    if (!state.employees.some((p) => p.id === payload.employeeId)) throw new Error('Выберите сотрудника');
    const date = validDay(payload.date);
    if (!['work', 'off', 'leave', 'sick'].includes(payload.kind)) throw new Error('Выберите отметку');
    const hours = payload.kind === 'work' ? num(payload.hours, 0.01, 24) : 0;
    const existing = state.attendance.find((a) => a.employeeId === payload.employeeId && a.date === date);
    const row = { id: existing?.id || id(), employeeId: payload.employeeId, date, kind: payload.kind, hours, note: String(payload.note || '').slice(0, 2000), updatedAt: new Date().toISOString() };
    if (existing) Object.assign(existing, row); else state.attendance.push(row);
  } else if (action === 'template.save') {
    const previous = state.taskTemplates.find((t) => t.id === payload.id);
    const template = { id: previous?.id || id(), title: required(payload.title, 'действие'), unit: required(payload.unit, 'единица'), description: String(payload.description || '').slice(0, 2000), checklist: String(payload.checklist || '').split('\n').map((s) => s.trim()).filter(Boolean).slice(0, 30), active: payload.active !== false };
    if (previous) Object.assign(previous, template); else state.taskTemplates.push(template);
  } else return false;
  return true;
}
export function validateOperations(state) {
  for (const table of ['materials', 'suppliers', 'stockDocuments', 'purchases', 'tools', 'toolEvents', 'attendance', 'taskTemplates']) {
    const rows = state[table];
    if (!Array.isArray(rows) || rows.length > 20000 || rows.some((r) => !r || typeof r.id !== 'string' || !r.id) || new Set(rows.map((r) => r.id)).size !== rows.length) throw new Error(`Некорректный раздел: ${table}`);
  }
  const has = (table, key) => state[table].some((r) => r.id === key);
  const str = (v) => typeof v === 'string' && v.length <= 10000;
  for (const item of state.materials) { if (![item.name, item.unit, item.category, item.source, item.priceNote].every(str) || typeof item.tracked !== 'boolean') throw new Error('Некорректная номенклатура'); num(item.price); num(item.minStock); }
  for (const supplier of state.suppliers) if (![supplier.name, supplier.contact, supplier.notes].every(str)) throw new Error('Некорректный поставщик');
  for (const doc of [...state.stockDocuments, ...state.purchases]) {
    validDay(doc.date);
    if (!str(doc.number) || !Array.isArray(doc.lines) || !doc.lines.length || doc.lines.some((l) => !has('materials', l.itemId) || !str(l.name) || !str(l.unit))) throw new Error('Некорректный документ');
    for (const line of doc.lines) { num(line.quantity, 0.01, 1e6); num(line.price); }
    if (doc.supplierId && !has('suppliers', doc.supplierId)) throw new Error('Поставщик документа не найден');
    if (doc.purchaseId && !has('purchases', doc.purchaseId)) throw new Error('Закупка документа не найдена');
    if (doc.orderId && !has('orders', doc.orderId)) throw new Error('Заказ документа не найден');
  }
  for (const doc of state.stockDocuments) if (!documentTypes[doc.kind] || !str(doc.target) || !str(doc.note) || !str(doc.reference)) throw new Error('Некорректное движение');
  for (const item of state.materials) if (stockFor(state, item.id).quantity < 0) throw new Error('Отрицательный остаток');
  for (const row of state.attendance) { validDay(row.date); num(row.hours, 0, 24); if (!has('employees', row.employeeId) || !['work','off','leave','sick'].includes(row.kind) || !str(row.note) || (row.kind !== 'work' && row.hours !== 0)) throw new Error('Некорректный табель'); }
  if (new Set(state.attendance.map((a) => `${a.employeeId}:${a.date}`)).size !== state.attendance.length) throw new Error('Повторная отметка табеля');
  for (const t of state.tools) { if (![t.name,t.serial,t.note].every(str) || !['production','field'].includes(t.home) || (t.holderId && !has(t.holderType === 'crew' ? 'crews' : 'employees', t.holderId))) throw new Error('Некорректный инструмент'); num(t.price); }
  for (const e of state.toolEvents) { validDay(e.date); if (!has('tools',e.toolId) || !['issue','return'].includes(e.kind) || ![e.holder,e.toolName,e.serial,e.note].every(str)) throw new Error('Некорректная выдача инструмента'); }
  for (const t of state.taskTemplates) if (![t.title,t.unit,t.description].every(str) || !Array.isArray(t.checklist) || t.checklist.some((s) => !str(s)) || typeof t.active !== 'boolean') throw new Error('Некорректный шаблон действия');
}

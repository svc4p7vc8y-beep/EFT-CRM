const coreKeys = ['clients','sites','leads','orders','tasks','activities','constructionStages','logistics'];
const inventoryKeys = ['materials','suppliers','supplyNeeds','purchases','stockDocuments','tools','toolEvents'];

export function prepareDeletion(state, type, id) {
  const keys = inventoryKeys.includes(type) ? inventoryKeys : coreKeys;
  if (!keys.includes(type)) throw new Error('Этот тип записи нельзя удалить.');
  const record = state[type].find((item) => item.id === id);
  if (!record) throw new Error('Запись уже удалена. Обновите страницу.');
  const next = Object.fromEntries(keys.map((key) => [key, [...state[key]]]));
  const remove = (key, predicate) => { next[key] = next[key].filter((item) => !predicate(item)); };
  if (type === 'clients' || type === 'sites' || type === 'leads') {
    const siteIds = new Set(type === 'clients' ? state.sites.filter((site) => site.clientId === id).map((site) => site.id) : type === 'sites' ? [id] : []);
    const leadIds = new Set(type === 'leads' ? [id] : state.leads.filter((lead) => siteIds.has(lead.siteId)).map((lead) => lead.id));
    const orderIds = new Set(state.orders.filter((order) => siteIds.has(order.siteId) || leadIds.has(order.leadId)).map((order) => order.id));
    const taskIds = new Set(state.tasks.filter((task) => siteIds.has(task.siteId) || orderIds.has(task.orderId)).map((task) => task.id));
    remove('tasks', (task) => taskIds.has(task.id));
    remove('logistics', (route) => siteIds.has(route.siteId) || orderIds.has(route.orderId));
    remove('orders', (order) => orderIds.has(order.id));
    remove('leads', (lead) => leadIds.has(lead.id));
    remove('constructionStages', (stage) => siteIds.has(stage.siteId));
    remove('activities', (activity) => siteIds.has(activity.siteId) || taskIds.has(activity.taskId));
    remove('sites', (site) => siteIds.has(site.id));
    if (type === 'clients') remove('clients', (client) => client.id === id);
  } else if (type === 'tasks') {
    remove('tasks', (task) => task.id === id);
    remove('activities', (activity) => activity.taskId === id);
  } else if (type === 'materials') {
    if (state.purchases.some((purchase) => purchase.lines.some((line) => line.itemId === id)) || state.stockDocuments.some((document) => document.lines.some((line) => line.itemId === id))) throw new Error('Материал используется в закупках или движениях. Сначала удалите связанные документы.');
    remove(type, (item) => item.id === id);
  } else if (type === 'suppliers') {
    if (state.purchases.some((purchase) => purchase.supplierId === id) || state.stockDocuments.some((document) => document.supplierId === id)) throw new Error('Поставщик используется в документах. Сначала удалите связанные документы.');
    remove(type, (item) => item.id === id);
  } else if (type === 'purchases') {
    if (state.stockDocuments.some((document) => document.purchaseId === id)) throw new Error('У закупки есть приходные документы. Сначала удалите их.');
    remove(type, (item) => item.id === id);
  } else if (type === 'tools') {
    if (record.holderId) throw new Error('Сначала оформите возврат инструмента.');
    remove('toolEvents', (event) => event.toolId === id);
    remove('tools', (tool) => tool.id === id);
  } else remove(type, (item) => item.id === id);
  return { domain: keys === inventoryKeys ? 'inventory' : 'workspace', snapshot: next, counts: Object.fromEntries(keys.map((key) => [key, state[key].length - next[key].length]).filter(([, count]) => count)) };
}

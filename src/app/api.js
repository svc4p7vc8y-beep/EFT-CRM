const API_URL = String(import.meta.env.VITE_CRM_API_URL || '').trim();

export const serverMode = API_URL !== '';

const presetAvatar = (value = '') => /^employee-(0[1-9]|1[0-5])\.jpg$/.test(value);
const uploadedAvatar = (value = '') => /^e-[a-f0-9]{24}\.(jpg|png|webp)$/.test(value);
const avatarKey = (value = '') => presetAvatar(value) || uploadedAvatar(value) ? value : 'employee-01.jpg';
const avatarUrl = (key) => presetAvatar(key) ? `./avatars/${key}` : `/uploads/employees/${encodeURIComponent(key)}`;
export function employeeFromApi(employee) {
  const name = String(employee?.name || '');
  const key = avatarKey(employee?.avatarKey);
  return { ...employee, avatarKey: key, initials: name.split(/\s+/).filter(Boolean).slice(0, 2).map((part) => part[0]).join('').toUpperCase(), avatar: avatarUrl(key), payRate: Number(employee?.payRate || 0), advanceAmount: Number(employee?.advanceAmount || 0) };
}
export function employeeToApi(employee) {
  const { photoFile: _photoFile, ...fields } = employee;
  return { ...fields, avatarKey: avatarKey(employee?.avatarKey || String(employee?.avatar || '').split('/').pop()) };
}

export class ApiError extends Error {
  constructor(message, { code = 'request_failed', status = 0 } = {}) {
    super(message);
    this.name = 'ApiError';
    this.code = code;
    this.status = status;
  }
}

let csrfToken = '';

function actionUrl(action, query = {}) {
  const separator = API_URL.includes('?') ? '&' : '?';
  const parameters = new URLSearchParams({ action, ...Object.fromEntries(Object.entries(query).filter(([, value]) => value !== '' && value !== undefined)) });
  return `${API_URL}${separator}${parameters}`;
}

async function request(action, { method = 'GET', body, signal, query } = {}) {
  const formData = body instanceof FormData;
  let response;
  try {
    response = await fetch(actionUrl(action, query), {
      method,
      credentials: 'same-origin',
      headers: {
        Accept: 'application/json',
        ...(body === undefined || formData ? {} : { 'Content-Type': 'application/json' }),
        ...(csrfToken ? { 'X-CSRF-Token': csrfToken } : {}),
      },
      body: body === undefined ? undefined : formData ? body : JSON.stringify(body),
      signal,
    });
  } catch (error) {
    if (error?.name === 'AbortError') throw error;
    throw new ApiError('Не удалось связаться с сервером CRM. Проверьте соединение и повторите попытку.');
  }

  let payload = null;
  try {
    payload = await response.json();
  } catch {
    throw new ApiError('Сервер CRM вернул некорректный ответ.', { status: response.status });
  }

  if (!response.ok || payload?.ok === false) {
    throw new ApiError(payload?.message || 'Не удалось выполнить запрос.', {
      code: payload?.code,
      status: response.status,
    });
  }
  if (payload?.csrf) csrfToken = payload.csrf;
  return payload;
}

export const crmApi = {
  filesList: (siteId = '') => request('files.list', { query: { siteId } }),
  filesUpload: (file, siteId = '') => { const body = new FormData(); body.append('file', file); if (siteId) body.append('siteId', siteId); return request('files.upload', { method: 'POST', body }); },
  filesMove: (id, siteId = '') => request('files.move', { method: 'POST', body: { id, siteId } }),
  filesDownloadUrl: (id) => actionUrl('files.download', { id }),
  filesPreviewUrl: (id) => actionUrl('files.download', { id, inline: '1' }),
  session: (signal) => request('session', { signal }),
  login: (username, password) => request('login', { method: 'POST', body: { username, password } }),
  logout: () => request('logout', { method: 'POST', body: {} }),
  bootstrap: (signal) => request('bootstrap', { signal }),
  notificationConfig: () => request('notifications.config'),
  notifications: () => request('notifications.list'),
  subscribePush: (subscription) => request('notifications.subscribe', { method: 'POST', body: { subscription } }),
  unsubscribePush: (endpoint) => request('notifications.unsubscribe', { method: 'POST', body: { endpoint } }),
  saveNotificationPreferences: (preferences) => request('notifications.preferences', { method: 'POST', body: preferences }),
  staffNotifications: () => request('notifications.staff'),
  saveStaffNotification: (employeeId, allEvents) => request('notifications.staff', { method: 'POST', body: { employeeId, allEvents } }),
  readNotification: (id) => request('notifications.read', { method: 'POST', body: { id } }),
  readLeadNotifications: (leadId) => request('notifications.read-lead', { method: 'POST', body: { leadId } }),
  testNotification: () => request('notifications.test', { method: 'POST', body: {} }),
  chatList: () => request('chat.list'),
  chatMessages: (threadId, before = '') => request('chat.messages', { query: { threadId, before } }),
  chatCreate: (type, userIds = [], title = '', contextId = '') => request('chat.create', { method: 'POST', body: { type, userIds, title, contextId } }),
  chatSend: (threadId, body, files = []) => {
    if (!files.length) return request('chat.send', { method: 'POST', body: { threadId, body } });
    const form = new FormData(); form.append('threadId', threadId); form.append('body', body);
    files.forEach((file) => form.append('files[]', file));
    return request('chat.send', { method: 'POST', body: form });
  },
  chatFileUrl: (key) => actionUrl('chat.file', { key }),
  chatRead: (threadId, messageId) => request('chat.read', { method: 'POST', body: { threadId, messageId } }),
  answerTask: (taskId, answer) => request('tasks.answer', { method: 'POST', body: { taskId, answer } }),
  commentTask: (taskId, text) => request('tasks.comment', { method: 'POST', body: { taskId, text } }),
  completeTask: (taskId, text) => request('tasks.complete', { method: 'POST', body: { taskId, text } }),
  checkTask: (taskId, checkId = '', title = '') => request('tasks.check', { method: 'POST', body: { taskId, checkId, title } }),
  createTask: (task) => request('tasks.create', { method: 'POST', body: { task } }),
  saveWorkspace: (workspace, baseRevision, initialize = false, deletionPassword = '') => request('workspace.save', { method: 'PUT', body: { workspace, baseRevision, initialize, deletionPassword } }),
  mergeClients: (targetId, sourceId, deletionPassword) => request('clients.merge', { method: 'POST', body: { targetId, sourceId, deletionPassword } }),
  uploadConstructionPhoto: (stageId, file) => { const body = new FormData(); body.append('stageId', stageId); body.append('photo', file); return request('construction.photo', { method: 'POST', body }); },
  saveInventory: (inventory, baseRevision, deletionPassword = '') => request('inventory.save', { method: 'PUT', body: { inventory, baseRevision, deletionPassword } }),
  deletionStatus: () => request('deletion.status'),
  updateDeletionPassword: (currentPassword, newPassword) => request('deletion.password.update', { method: 'POST', body: { currentPassword, newPassword } }),
  saveEmployee: (employee) => request('employees.save', { method: employee.id ? 'PUT' : 'POST', body: employee }),
  uploadEmployeePhoto: (employeeId, file) => {
    const body = new FormData();
    body.append('employeeId', employeeId);
    body.append('photo', file);
    return request('employees.photo', { method: 'POST', body });
  },
  connectorStatuses: (signal) => request('connectors.status', { signal }),
  saveMailSettings: (address, appPassword) => request('connectors.mail.configure', { method: 'POST', body: { address, appPassword } }),
  saveTelegramSettings: (botToken) => request('connectors.telegram.configure', { method: 'POST', body: { botToken } }),
  createTelegramLink: (siteId) => request('connectors.telegram.link', { method: 'POST', body: { siteId } }),
  saveMaxSettings: (botToken) => request('connectors.max.configure', { method: 'POST', body: { botToken } }),
  saveAvitoSettings: (clientId, clientSecret, accountId) => request('connectors.avito.configure', { method: 'POST', body: { clientId, clientSecret, accountId } }),
  syncAvito: () => request('connectors.avito.sync', { method: 'POST', body: {} }),
  loadMaxRecipients: () => request('connectors.max.recipients'),
  saveMaxRecipients: (userIds) => request('connectors.max.recipients', { method: 'POST', body: { userIds } }),
  checkMaxConnection: () => request('connectors.max.check', { method: 'POST', body: {} }),
  createMaxLink: (siteId) => request('connectors.max.link', { method: 'POST', body: { siteId } }),
  syncMail: () => request('communications.mail.sync', { method: 'POST', body: {} }),
  assignCommunication: (messageId, targetType, targetId) => request('communications.assign', { method: 'POST', body: { messageId, targetType, targetId } }),
  replyUnassigned: (messageId, text, subject = '', files = []) => {
    const body = new FormData(); body.append('messageId', messageId); body.append('text', text); body.append('subject', subject);
    files.forEach((file) => body.append('files[]', file));
    return request('communications.reply-unassigned', { method: 'POST', body });
  },
  ignoreCommunication: (messageId, ignored) => request('communications.ignore', { method: 'POST', body: { messageId, ignored } }),
  recoverMaxImages: (messageId) => request('communications.max.recover-images', { method: 'POST', body: { messageId } }),
  batchCommunications: (messageIds, operation, siteId = '') => request('communications.batch', { method: 'POST', body: { messageIds, operation, siteId } }),
  updateCommunicationWorkflow: (messageId, status, followUpAt = '') => request('communications.workflow', { method: 'POST', body: { messageId, status, followUpAt } }),
  communicationTemplates: () => request('communications.templates'),
  saveCommunicationTemplates: (templates) => request('communications.templates', { method: 'POST', body: { templates } }),
  communicationRules: () => request('communications.rules'),
  saveCommunicationRules: (rules) => request('communications.rules', { method: 'POST', body: { rules } }),
  sendCommunication: ({ siteId, channel, subject = '', text, replyTo = '', files = [] }) => {
    const body = new FormData(); body.append('siteId', siteId); body.append('channel', channel); body.append('subject', subject); body.append('text', text); body.append('replyTo', replyTo);
    files.forEach((file) => body.append('files[]', file));
    return request('communications.send', { method: 'POST', body });
  },
  saveCrew: (crew) => request('crews.save', { method: crew.id ? 'PUT' : 'POST', body: crew }),
  saveUser: (user) => request('users.save', { method: user.id ? 'PUT' : 'POST', body: user }),
  unlockFinance: (pin) => request('finance.unlock', { method: 'POST', body: { pin } }),
  lockFinance: () => request('finance.lock', { method: 'POST', body: {} }),
  updateFinancePin: (currentPin, newPin) => request('finance.pin.update', { method: 'POST', body: { currentPin, newPin } }),
  attendance: (month, employeeId = '', signal) => request('attendance.list', { signal, query: { month, employeeId } }),
  saveAttendance: (entry) => request('attendance.save', { method: entry.id ? 'PUT' : 'POST', body: entry }),
  payroll: (month, signal) => request('payroll', { signal, query: { month } }),
  clearSession: () => { csrfToken = ''; },
};

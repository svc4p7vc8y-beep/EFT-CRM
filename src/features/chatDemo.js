const KEY = 'eft-crm-chat-demo-v1';

function read() {
  try {
    const saved = JSON.parse(localStorage.getItem(KEY) || 'null');
    if (saved && Array.isArray(saved.threads) && Array.isArray(saved.messages)) return saved;
  } catch { /* Start a fresh local preview. */ }
  return { threads: [{ id: 'demo-all', type: 'all', title: 'Общий чат ЭФТ', contextId: '', memberIds: [], updatedAt: new Date().toISOString() }], messages: [] };
}

function write(data) { localStorage.setItem(KEY, JSON.stringify(data)); }

export function demoChatApi(employees) {
  const users = [{ id: 'me', name: 'Вы (демо)', employeeId: '' }, ...employees.filter((person) => person.active).map((person) => ({ id: person.id, name: person.name, employeeId: person.id }))];
  const list = async () => {
    const data = read();
    return { users, threads: data.threads.map((thread) => {
      const latest = data.messages.filter((message) => message.threadId === thread.id).at(-1);
      return { ...thread, memberIds: thread.type === 'all' ? users.map((user) => user.id) : thread.memberIds, lastBody: latest?.body || '', lastMessageId: latest?.id || 0, unread: 0 };
    }) };
  };
  return {
    mode: 'demo', user: { id: 'me', displayName: 'Вы (демо)' }, chatList: list,
    chatMessages: async (threadId, before = '') => {
      const messages = read().messages.filter((message) => message.threadId === threadId && (!before || message.id < Number(before)));
      return { messages: messages.slice(-100), hasMore: messages.length > 100 };
    },
    chatCreate: async (type, userIds = [], title = '', contextId = '') => {
      const data = read();
      const existing = data.threads.find((thread) => type === 'all' ? thread.type === 'all' : type === 'direct' ? thread.type === 'direct' && thread.memberIds.includes(userIds[0]) : ['task', 'lead'].includes(type) && thread.type === type && thread.contextId === contextId);
      if (existing) return { threadId: existing.id };
      const thread = { id: crypto.randomUUID(), type, title: title || users.find((user) => user.id === userIds[0])?.name || 'Обсуждение', contextId, memberIds: ['me', ...userIds], updatedAt: new Date().toISOString() };
      data.threads.unshift(thread); write(data); return { threadId: thread.id };
    },
    chatSend: async (threadId, body) => {
      const data = read();
      const message = { id: (data.messages.at(-1)?.id || 0) + 1, threadId, authorId: 'me', authorName: 'Вы (демо)', body, createdAt: new Date().toISOString() };
      data.messages.push(message); const thread = data.threads.find((item) => item.id === threadId); if (thread) thread.updatedAt = message.createdAt;
      write(data); return { message };
    },
    chatRead: async () => ({ ok: true }),
  };
}

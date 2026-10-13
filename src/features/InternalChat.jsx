import { useEffect, useMemo, useRef, useState } from 'react';
import { ArrowLeft, Bell, Check, CheckCheck, FileText, MessageCircle, Paperclip, Plus, Send, Users, X } from 'lucide-react';
import { Dialog, PageHeading, VoiceInput } from '../components/UI.jsx';
import { demoChatApi } from './chatDemo.js';
import './internal-chat.css';

const typeNames = { all: 'Всем сотрудникам', group: 'Группа', direct: 'Личный чат', task: 'Задача', lead: 'Заявка' };
const stamp = (value) => value ? new Date(value.replace(' ', 'T')).toLocaleString('ru-RU', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }) : '';

function MessageReceipt({ messageId, currentUserId, receipts }) {
  const peers = receipts.filter((receipt) => String(receipt.userId) !== currentUserId);
  const delivered = peers.filter((receipt) => receipt.deliveredId >= messageId).length;
  const read = peers.filter((receipt) => receipt.readId >= messageId).length;
  const label = peers.length === 1 ? read ? 'Прочитано' : delivered ? 'Доставлено в CRM' : 'Отправлено'
    : peers.length ? `Доставлено ${delivered}/${peers.length} · прочитано ${read}/${peers.length}` : 'Отправлено';
  return <details className={`chat-receipt ${read ? 'has-read' : ''}`}><summary>{read || delivered ? <CheckCheck size={14} /> : <Check size={14} />}{label}</summary>
    {peers.length ? <div className="chat-receipt-details">{peers.map((receipt) => <span key={receipt.userId}>{receipt.name} — {receipt.readId >= messageId ? 'прочитано' : receipt.deliveredId >= messageId ? 'доставлено в CRM' : 'ожидает входа'}</span>)}</div>
      : <div className="chat-receipt-details">Других активных участников пока нет.</div>}</details>;
}

function MessageAttachment({ file, fileUrl }) {
  const url = file.url || fileUrl?.(file.key);
  if (!url) return null;
  const preview = /^image\/(jpeg|png|webp)$/.test(file.mime || '');
  return <a className={`chat-attachment ${preview ? 'chat-attachment-image' : ''}`} href={url} target="_blank" rel="noopener noreferrer" download={preview ? undefined : file.name}>
    {preview ? <img src={url} alt={file.name} loading="lazy" /> : <FileText size={22} />}
    <span>{file.name}{file.size ? <small>{Math.max(1, Math.round(file.size / 1024))} КБ</small> : null}</span>
  </a>;
}

export function InternalChat({ runtime, employees, requestedThreadId = '', context, onContextConsumed, onSelectThread, onOpenContext }) {
  const demo = useMemo(() => demoChatApi(employees), [employees]);
  const api = runtime.chatList ? runtime : demo;
  const apiRef = useRef(api); apiRef.current = api;
  const [users, setUsers] = useState([]);
  const [threads, setThreads] = useState([]);
  const [selectedId, setSelectedId] = useState(requestedThreadId);
  const [messages, setMessages] = useState([]);
  const [receipts, setReceipts] = useState([]);
  const [hasMore, setHasMore] = useState(false);
  const [draft, setDraft] = useState('');
  const [files, setFiles] = useState([]);
  const [filter, setFilter] = useState('');
  const [create, setCreate] = useState(null);
  const [recipientSearch, setRecipientSearch] = useState('');
  const [sending, setSending] = useState(false);
  const [creating, setCreating] = useState(false);
  const [error, setError] = useState('');
  const [mobileThread, setMobileThread] = useState(Boolean(requestedThreadId));
  const messagesRef = useRef(null);
  const fileInputRef = useRef(null);
  const loadedOlder = useRef(false);
  const lastReadRef = useRef({ threadId: '', messageId: 0 });
  const pendingReadRef = useRef({ threadId: '', messageId: 0 });
  const currentUserId = String(api.user?.id || 'me');
  const selected = threads.find((thread) => thread.id === selectedId);

  function readVisibleMessages() {
    const pending = pendingReadRef.current;
    const list = messagesRef.current;
    if (!list || pending.threadId !== selectedId || !pending.messageId || document.visibilityState === 'hidden') return;
    if (window.matchMedia('(max-width: 760px)').matches && !mobileThread) return;
    if (list.scrollHeight - list.scrollTop - list.clientHeight > 90) return;
    if (lastReadRef.current.threadId === selectedId && lastReadRef.current.messageId >= pending.messageId) return;
    const previous = lastReadRef.current;
    lastReadRef.current = pending;
    apiRef.current.chatRead(selectedId, pending.messageId).catch((failure) => {
      if (lastReadRef.current === pending) lastReadRef.current = previous;
      setError(failure.message);
    });
  }

  async function loadThreads() {
    const result = await apiRef.current.chatList();
    setUsers(result.users || []);
    setThreads(result.threads || []);
    setSelectedId((id) => id || requestedThreadId || result.threads?.find((thread) => thread.type === 'all')?.id || '');
  }
  useEffect(() => {
    let active = true;
    const refresh = () => { if (document.visibilityState !== 'hidden') loadThreads().catch((failure) => { if (active) setError(failure.message); }); };
    refresh(); const timer = window.setInterval(refresh, 10000);
    window.addEventListener('focus', refresh);
    return () => { active = false; window.clearInterval(timer); window.removeEventListener('focus', refresh); };
  }, []);
  useEffect(() => { if (requestedThreadId) { setSelectedId(requestedThreadId); setMobileThread(true); } }, [requestedThreadId]);
  useEffect(() => {
    if (!context?.id) return;
    const suggested = users.find((user) => user.employeeId && user.employeeId === context.suggestedEmployeeId && String(user.id) !== currentUserId);
    setCreate({ type: context.type, title: context.title || '', contextId: context.id, userIds: suggested ? [suggested.id] : [] });
  }, [context?.id, context?.type, users.length]);
  useEffect(() => {
    if (!selectedId) return undefined;
    let active = true;
    setMessages([]); setReceipts([]); setHasMore(false); loadedOlder.current = false;
    const refresh = async () => {
      if (document.visibilityState === 'hidden') return;
      if (window.matchMedia('(max-width: 760px)').matches && !mobileThread) return;
      try {
        const result = await apiRef.current.chatMessages(selectedId);
        if (!active) return;
        const list = messagesRef.current;
        const wasAtBottom = !list || list.scrollHeight - list.scrollTop - list.clientHeight <= 90;
        setReceipts(result.receipts || []);
        setMessages((previous) => {
          const byId = new Map(previous.map((message) => [message.id, message]));
          result.messages.forEach((message) => byId.set(message.id, message));
          return [...byId.values()].sort((a, b) => a.id - b.id);
        });
        if (!loadedOlder.current) setHasMore(Boolean(result.hasMore));
        const latestId = result.messages.at(-1)?.id || 0;
        pendingReadRef.current = { threadId: selectedId, messageId: latestId };
        requestAnimationFrame(() => { if (active && wasAtBottom && messagesRef.current) {
          messagesRef.current.scrollTop = messagesRef.current.scrollHeight;
          readVisibleMessages();
        } });
      } catch (failure) { if (active) setError(failure.message); }
    };
    refresh(); const timer = window.setInterval(refresh, 6000);
    return () => { active = false; window.clearInterval(timer); };
  }, [selectedId, mobileThread]);

  function selectThread(id) {
    setSelectedId(id); setMobileThread(true); setError(''); setDraft(''); setFiles([]);
    onSelectThread(id);
  }
  function queueFiles(selected) {
    const next = [...files, ...Array.from(selected || [])];
    if (next.length > 5 || next.some((file) => file.size < 1 || file.size > 10 * 1024 * 1024) || next.reduce((sum, file) => sum + file.size, 0) > 25 * 1024 * 1024) {
      setError('До пяти файлов: каждый не больше 10 МБ, вместе не больше 25 МБ.'); return;
    }
    setFiles(next); setError('');
  }
  async function loadPrevious() {
    if (!messages.length) return;
    try {
      const result = await apiRef.current.chatMessages(selectedId, messages[0].id);
      loadedOlder.current = true;
      setMessages((previous) => [...result.messages, ...previous]); setHasMore(Boolean(result.hasMore));
    } catch (failure) { setError(failure.message); }
  }
  async function submitMessage(event) {
    event.preventDefault(); const body = draft.trim(); if ((!body && !files.length) || !selectedId || sending) return;
    setSending(true); setError('');
    try {
      const result = await apiRef.current.chatSend(selectedId, body, files);
      setMessages((previous) => previous.some((message) => message.id === result.message.id) ? previous : [...previous, result.message]);
      setDraft(''); setFiles([]); await loadThreads();
      requestAnimationFrame(() => { if (messagesRef.current) messagesRef.current.scrollTop = messagesRef.current.scrollHeight; });
    } catch (failure) { setError(failure.message); }
    finally { setSending(false); }
  }
  function startCreate(type) {
    setRecipientSearch(''); setError('');
    setCreate({ type, title: '', contextId: '', userIds: [] });
  }
  function closeCreate() { setCreate(null); onContextConsumed?.(); }
  async function submitCreate(event) {
    event.preventDefault(); if (!create || creating) return;
    setCreating(true); setError('');
    try {
      const result = await apiRef.current.chatCreate(create.type, create.userIds, create.title.trim(), create.contextId);
      await loadThreads(); selectThread(result.threadId); closeCreate();
    } catch (failure) { setError(failure.message); }
    finally { setCreating(false); }
  }
  const filteredThreads = threads.filter((thread) => `${thread.title} ${thread.lastBody}`.toLocaleLowerCase('ru-RU').includes(filter.toLocaleLowerCase('ru-RU')));
  const recipients = users.filter((user) => String(user.id) !== currentUserId && user.name.toLocaleLowerCase('ru-RU').includes(recipientSearch.toLocaleLowerCase('ru-RU')));
  const memberNames = selected?.memberIds?.filter((id) => String(id) !== currentUserId).map((id) => users.find((user) => String(user.id) === String(id))?.name).filter(Boolean) || [];

  return <section className={`page internal-chat-page ${mobileThread ? 'mobile-thread-page' : ''}`}>
    <PageHeading title="Внутренний чат" description="Личные сообщения, группы и обсуждения задач и заявок"><div className="internal-chat-actions"><button className="button" onClick={() => startCreate('direct')}><MessageCircle size={17} />Личное</button><button className="button primary" onClick={() => startCreate('group')}><Plus size={17} />Группа</button></div></PageHeading>
    {runtime.mode === 'demo' ? <p className="chat-demo-note">Предпросмотр: сообщения сохраняются только в этом браузере. Для доставки сотрудникам откройте рабочую CRM.</p> : null}
    <div className={`internal-chat-layout ${mobileThread ? 'mobile-thread-open' : ''}`}>
      <aside className="chat-thread-list"><label className="chat-filter"><span className="sr-only">Поиск чата</span><input value={filter} onChange={(event) => setFilter(event.target.value)} placeholder="Найти чат…" /></label>
        <div className="chat-thread-items">{filteredThreads.map((thread) => <button key={thread.id} className={`chat-thread-item ${selectedId === thread.id ? 'active' : ''}`} onClick={() => selectThread(thread.id)}><span className="chat-thread-avatar">{thread.type === 'all' || thread.type === 'group' ? <Users size={20} /> : <MessageCircle size={20} />}</span><span className="chat-thread-copy"><strong>{thread.title}</strong><small>{typeNames[thread.type]} · {thread.memberIds?.length || 0} участников</small><span>{thread.lastBody || 'Начните разговор'}</span></span>{thread.unread ? <b className="chat-unread">{thread.unread}</b> : null}</button>)}{!filteredThreads.length ? <p className="chat-empty-list">Чаты не найдены.</p> : null}</div>
      </aside>
      <div className="chat-conversation">{selected ? <><header className="chat-conversation-head"><button className="icon-button chat-back" aria-label="К списку чатов" onClick={() => setMobileThread(false)}><ArrowLeft size={21} /></button><div><strong>{selected.title}</strong><span>{selected.type === 'all' ? `Все пользователи CRM · ${selected.memberIds.length}` : memberNames.length ? memberNames.join(', ') : typeNames[selected.type]}</span></div>{selected.contextId ? <button className="button chat-context-link" onClick={() => onOpenContext(selected.type, selected.contextId)}>Открыть {selected.type === 'task' ? 'задачу' : 'заявку'}</button> : null}</header>
        <div className="chat-message-list" ref={messagesRef} onScroll={readVisibleMessages}>{hasMore ? <button className="button chat-older" onClick={loadPrevious}>Показать предыдущие</button> : null}{messages.length ? messages.map((message) => <article key={message.id} className={`chat-message ${String(message.authorId) === currentUserId ? 'own' : ''}`}><strong>{message.authorName}</strong>{message.body ? <p>{message.body}</p> : null}{message.attachments?.length ? <div className="chat-attachments">{message.attachments.map((file) => <MessageAttachment file={file} fileUrl={api.chatFileUrl} key={file.key} />)}</div> : null}<time>{stamp(message.createdAt)}</time>{String(message.authorId) === currentUserId ? <MessageReceipt messageId={message.id} currentUserId={currentUserId} receipts={receipts} /> : null}</article>) : <div className="chat-empty-conversation"><MessageCircle size={32} /><strong>Пока нет сообщений</strong><span>Напишите первое сообщение участникам.</span></div>}</div>
        {files.length ? <div className="chat-file-queue">{files.map((file, index) => <span key={`${file.name}-${index}`}><Paperclip size={13} />{file.name}<button type="button" aria-label={`Убрать ${file.name}`} onClick={() => setFiles((current) => current.filter((_, position) => position !== index))}><X size={13} /></button></span>)}</div> : null}
        <form className="chat-compose" onSubmit={submitMessage}><input ref={fileInputRef} type="file" multiple hidden accept="image/*,.pdf,.txt,.zip,.docx,.xlsx" onChange={(event) => { queueFiles(event.target.files); event.target.value = ''; }} /><button className="button chat-attach-button" type="button" aria-label="Прикрепить фотографии или файлы" title="Прикрепить фотографии или файлы" onClick={() => fileInputRef.current?.click()}><Paperclip size={19} /></button><VoiceInput><textarea value={draft} onChange={(event) => setDraft(event.target.value)} onPaste={(event) => { if (event.clipboardData.files.length) { event.preventDefault(); queueFiles(event.clipboardData.files); } }} maxLength={5000} rows={2} placeholder="Сообщение или файл…" aria-label="Текст сообщения" onKeyDown={(event) => { if (event.key === 'Enter' && !event.shiftKey) { event.preventDefault(); event.currentTarget.form?.requestSubmit(); } }} /></VoiceInput><button className="button primary" type="submit" disabled={sending || (!draft.trim() && !files.length)} aria-label="Отправить сообщение"><Send size={19} /><span>{sending ? 'Отправляем…' : 'Отправить'}</span></button></form>
        <p className="chat-delivery-hint"><Bell size={13} />Доставка отмечается при получении чатов в CRM, прочтение — при открытии диалога. Push сам по себе не означает прочтение.</p>
      </> : <div className="chat-empty-conversation"><MessageCircle size={32} /><strong>Выберите чат</strong></div>}</div>
    </div>
    {error && !create ? <p className="chat-error" role="alert">{error}</p> : null}
    {create ? <Dialog title={create.type === 'direct' ? 'Личное сообщение' : create.type === 'group' ? 'Новая группа' : `Чат по ${create.type === 'task' ? 'задаче' : 'заявке'}`} onClose={closeCreate}><form className="chat-create-form" onSubmit={submitCreate}>{create.contextId ? <p className="chat-context-caption">{create.title}</p> : null}{create.type === 'group' ? <label className="field"><span>Название группы</span><input required maxLength={180} value={create.title} onChange={(event) => setCreate((current) => ({ ...current, title: event.target.value }))} placeholder="Например, монтажная бригада" /></label> : null}<label className="field"><span>{create.type === 'direct' ? 'Кому написать' : 'Кого пригласить'}</span><input value={recipientSearch} onChange={(event) => setRecipientSearch(event.target.value)} placeholder="Найти сотрудника…" /></label><div className="chat-recipient-list">{recipients.map((user) => { const checked = create.userIds.some((id) => String(id) === String(user.id)); return <label key={user.id}><input type={create.type === 'direct' ? 'radio' : 'checkbox'} name={create.type === 'direct' ? 'recipient' : undefined} checked={checked} onChange={() => setCreate((current) => ({ ...current, userIds: create.type === 'direct' ? [user.id] : checked ? current.userIds.filter((id) => String(id) !== String(user.id)) : [...current.userIds, user.id] }))} /><span>{user.name}</span></label>; })}{!recipients.length ? <p>Сотрудники с доступом к CRM не найдены.</p> : null}</div><small>Уведомления получают только сотрудники с личным входом в CRM.</small>{error ? <p className="chat-error" role="alert">{error}</p> : null}<footer className="dialog-actions"><button className="button" type="button" onClick={closeCreate}>Отмена</button><button className="button primary" disabled={creating || !create.userIds.length || (create.type === 'group' && !create.title.trim())}>{creating ? 'Создаём…' : 'Открыть чат'}</button></footer></form></Dialog> : null}
  </section>;
}

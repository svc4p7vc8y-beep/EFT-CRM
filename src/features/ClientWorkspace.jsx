import { useEffect, useLayoutEffect, useMemo, useRef, useState } from 'react';
import { ArrowLeft, CalendarDays, CheckCircle2, Download, ExternalLink, FileText, Image, Mail, MapPin, MessageSquare, Paperclip, Phone, Plus, Upload, UserRound, X } from 'lucide-react';
import { Badge, Empty, VoiceInput } from '../components/UI.jsx';
import { CallLink } from '../components/CallLink.jsx';
import { LEAD_STAGES, dateLabel, employee, leadContext } from '../app/model.js';
import { briefReadiness, CALCULATOR_URL, downloadCalculatorBrief } from '../app/buildingBrief.js';
import { crmApi } from '../app/api.js';
import { openDocument } from '../components/DocumentViewer.jsx';
import '../components/building-brief.css';
import './lead-discussion.css';
import './communications-dialogs.css';

const formatBytes = (bytes) => bytes < 1024 ? `${bytes} Б` : `${Math.round(bytes / 1024)} КБ`;

export function ClientWorkspace({ state, lead, liveSession = false, currentEmployeeId = '', command, onBack, onEditLead, onEditClient, onTransfer, onOpenTask, onChat, notify, focusEventId = '' }) {
  const { client, site } = leadContext(state, lead);
  const [activityType, setActivityType] = useState('note');
  const [activityText, setActivityText] = useState('');
  const [checkText, setCheckText] = useState('');
  const [recipientIds, setRecipientIds] = useState(lead.ownerId && lead.ownerId !== currentEmployeeId ? [lead.ownerId] : []);
  const [newAction, setNewAction] = useState('');
  const [uploading, setUploading] = useState(false);
  const [serverAttachments, setServerAttachments] = useState([]);
  const fileRef = useRef(null);
  const chatListRef = useRef(null);
  const chatNearBottom = useRef(true);
  const orders = useMemo(() => state.orders.filter((order) => order.siteId === site.id), [state.orders, site.id]);
  const orderIds = useMemo(() => new Set(orders.map((order) => order.id)), [orders]);
  const tasks = useMemo(() => state.tasks.filter((task) => orderIds.has(task.orderId)), [state.tasks, orderIds]);
  const events = useMemo(() => state.activities.filter((event) => event.siteId === site.id && !event.taskId).toSorted((a, b) => new Date(a.createdAt) - new Date(b.createdAt)), [state.activities, site.id]);
  const attachments = useMemo(() => state.attachments.filter((item) => item.siteId === site.id), [state.attachments, site.id]);
  const files = useMemo(() => liveSession ? serverAttachments.map((item) => ({ id: item.id, name: item.name, size: item.size, createdAt: item.createdAt, type: item.mime, url: crmApi.filesPreviewUrl(item.id), downloadUrl: crmApi.filesDownloadUrl(item.id) })) : attachments.map((item) => ({ id: item.id, name: item.name, size: item.size, createdAt: item.createdAt, type: item.type, url: item.dataUrl, downloadUrl: item.dataUrl })), [liveSession, serverAttachments, attachments]);
  const timeline = useMemo(() => [...events.map((item) => ({ kind: 'event', item })), ...files.map((item) => ({ kind: 'file', item }))].sort((a, b) => new Date(a.item.createdAt) - new Date(b.item.createdAt)), [events, files]);
  useEffect(() => { if (!liveSession) return undefined; let active = true; const refresh = () => { if (document.visibilityState === 'hidden') return; crmApi.filesList(site.id).then((result) => { if (active) setServerAttachments(result.files); }).catch(() => {}); }; refresh(); const timer = window.setInterval(refresh, 15000); window.addEventListener('focus', refresh); return () => { active = false; window.clearInterval(timer); window.removeEventListener('focus', refresh); }; }, [liveSession, site.id]);
  useLayoutEffect(() => { if (focusEventId || !chatNearBottom.current || !chatListRef.current) return; chatListRef.current.scrollTop = chatListRef.current.scrollHeight; }, [timeline.length, site.id, focusEventId]);
  useLayoutEffect(() => { if (!focusEventId || !chatListRef.current) return; const entry = [...chatListRef.current.children].find((item) => item.dataset.notificationEvent === focusEventId); entry?.scrollIntoView({ block: 'center' }); }, [focusEventId, timeline]);
  const stageIndex = Math.max(0, LEAD_STAGES.findIndex((stage) => stage.id === lead.status));
  const progress = lead.status === 'lost' ? 0 : Math.round(((stageIndex + 1) / (LEAD_STAGES.length - 1)) * 100);
  const brief = site.buildingBrief || {};
  const missingBrief = briefReadiness(brief);

  function transferToCalculator() {
    downloadCalculatorBrief({ client, site, lead });
    window.open(CALCULATOR_URL, '_blank', 'noopener,noreferrer');
    notify('Анкета скачана. В калькуляторе нажмите «Импортировать заявку клиента» и выберите файл.');
  }

  function saveNextAction(event) {
    event.preventDefault();
    const data = new FormData(event.currentTarget);
    try { command('lead.update', { id: lead.id, nextAction: data.get('nextAction'), dueAt: data.get('dueAt') }); notify('Следующее действие запланировано'); }
    catch (error) { notify(error.message, true); }
  }

  async function addActivity(event) {
    event.preventDefault();
    try { await command('activity.create', { siteId: site.id, taskId: '', type: activityType, text: activityText, recipientIds, authorId: currentEmployeeId }, 'Запись сохранена', liveSession); setActivityText(''); notify('Запись сохранена. Ответственный и подписанные сотрудники получат уведомление.'); }
    catch (error) { notify(error.message, true); }
  }

  function updateCheck(event) {
    event.preventDefault();
    try { command('lead.check', { id: lead.id, title: checkText, authorId: currentEmployeeId }); setCheckText(''); notify('Пункт чек-листа добавлен'); }
    catch (error) { notify(error.message, true); }
  }

  function toggleCheck(checkId) {
    try { command('lead.check', { id: lead.id, checkId, authorId: currentEmployeeId }); }
    catch (error) { notify(error.message, true); }
  }

  function addAction(event) {
    event.preventDefault();
    try { command('client-action.save', { title: newAction, active: true }); command('lead.update', { id: lead.id, nextAction: newAction }); setNewAction(''); notify('Вариант действия добавлен и выбран'); }
    catch (error) { notify(error.message, true); }
  }

  async function uploadFiles(event) {
    const files = [...event.target.files];
    if (!files.length) return;
    setUploading(true);
    let uploaded = 0;
    try {
      for (const file of files) {
        if (liveSession) { await crmApi.filesUpload(file, site.id); uploaded++; continue; }
        if (file.size > 800000) throw new Error(`${file.name}: максимальный размер 800 КБ`);
        const dataUrl = String(await new Promise((resolve, reject) => { const reader = new FileReader(); reader.onload = () => resolve(reader.result); reader.onerror = () => reject(new Error(`Не удалось прочитать ${file.name}`)); reader.readAsDataURL(file); })).replace(/^data:;base64,/, 'data:application/octet-stream;base64,');
        command('attachment.add', { siteId: site.id, name: file.name, type: file.type, size: file.size, dataUrl });
        uploaded++;
      }
      notify(files.length === 1 ? 'Вложение добавлено' : `Добавлено вложений: ${files.length}`);
    } catch (error) { notify(`${error.message}${uploaded ? ` · загружено ${uploaded} из ${files.length}` : ''}`, true); }
    finally { if (liveSession && uploaded) { try { setServerAttachments((await crmApi.filesList(site.id)).files); } catch (error) { notify(error.message, true); } } setUploading(false); event.target.value = ''; }
  }

  return <section className="page client-workspace">
    <button className="client-back" onClick={onBack}><ArrowLeft size={17} />Назад к списку</button>
    <header className="client-header">
      <div><div className="client-title-line"><h1>{client.name}</h1><Badge value={lead.status} stages={LEAD_STAGES} /></div><p>{site.name} · заявка от {dateLabel(lead.createdAt)}</p></div>
      <div className="client-header-actions">{client.phone ? <CallLink phone={client.phone} label="Позвонить" className="button" /> : null}<button className="button" onClick={onEditClient}>Контакты и объект</button><button className="button" onClick={onEditLead}>Редактировать заявку</button><button className="button" onClick={() => onChat?.(lead.id)}><MessageSquare size={16} />Чат по заявке</button></div>
    </header>

    <div className="client-progress" aria-label={`Прогресс заявки ${progress}%`}>
      <div><span>Прогресс работы с клиентом</span><strong>{progress}%</strong></div>
      <div className="client-progress-track"><i style={{ width: `${progress}%` }} /></div>
      <ol>{LEAD_STAGES.filter((stage) => stage.id !== 'lost').map((stage, index) => <li className={index <= stageIndex ? 'complete' : ''} key={stage.id}><span>{index < stageIndex ? <CheckCircle2 size={15} /> : index + 1}</span>{stage.label}</li>)}</ol>
    </div>

    <div className="client-layout">
      <div className="client-main">
        <section className="client-panel client-building-brief"><div className="client-section-title"><div><FileText size={19} /><h2>Параметры постройки</h2></div><button className="button" onClick={onEditLead}>Изменить</button></div>
          <div className="brief-summary"><span><strong>{brief.buildingType || 'Тип не указан'}</strong>Тип постройки</span><span><strong>{brief.length && brief.width ? `${brief.length} × ${brief.width} м` : 'Не указаны'}</strong>Габариты</span><span><strong>{brief.floors || '—'}</strong>Этажей</span><span><strong>{brief.roofShape || '—'}</strong>Кровля</span></div>
          <p>{Array.isArray(brief.scope) && brief.scope.length ? `Для расчёта: ${brief.scope.join(', ')}.` : 'Состав расчёта пока не выбран.'}</p>
          {missingBrief.length ? <p className="brief-warning">Для полноценного черновика уточните: {missingBrief.join(', ')}.</p> : <p className="brief-ready">Основные параметры готовы к переносу в калькулятор.</p>}
          <div className="brief-transfer-actions"><button className="button primary" type="button" onClick={transferToCalculator}><ExternalLink size={16} />Перенести в калькулятор</button><small>Скачается файл анкеты; загрузите его в открывшемся калькуляторе кнопкой «Импортировать заявку клиента».</small></div>
        </section>
        <section className="client-panel next-step-panel"><div className="client-section-title"><div><CalendarDays size={19} /><h2>Следующее действие</h2></div><span>обязательный шаг по клиенту</span></div>
          <form className="next-step-form" onSubmit={saveNextAction}><label><span>Действие</span><select name="nextAction" defaultValue={lead.nextAction} key={`${lead.id}-${lead.nextAction}`}>{state.clientActions.filter((item) => item.active).map((item) => <option key={item.id}>{item.title}</option>)}{lead.nextAction && !state.clientActions.some((item) => item.title === lead.nextAction) ? <option>{lead.nextAction}</option> : null}</select></label><label><span>Дата и время</span><input name="dueAt" type="datetime-local" defaultValue={lead.dueAt} /></label><button className="button primary">Запланировать</button></form>
          <details className="action-maker"><summary><Plus size={14} />Добавить свой вариант действия</summary><form onSubmit={addAction}><VoiceInput><input aria-label="Новый вариант действия" value={newAction} onChange={(event) => setNewAction(event.target.value)} maxLength={200} required placeholder="Например, запросить фото участка"/></VoiceInput><button className="button" type="submit">Добавить</button></form></details>
        </section>

        <section className="client-panel lead-discussion"><div className="client-section-title"><div><MessageSquare size={19} /><h2>Заметки и история общения</h2></div><span>{events.length} записей · {files.length} файлов</span></div>
          {lead.notes ? <p className="lead-initial-note"><strong>Описание заявки</strong>{lead.notes}</p> : null}
          <div className="lead-checklist"><div className="client-section-title"><div><CheckCircle2 size={18} /><h3>Чек-лист</h3></div><span>{lead.checklist?.filter((item) => item.done).length || 0} / {lead.checklist?.length || 0}</span></div>{lead.checklist?.map((item) => <label key={item.id} className="check-row"><input type="checkbox" checked={item.done} onChange={() => toggleCheck(item.id)} /><span>{item.title}</span></label>)}<form onSubmit={updateCheck} className="inline-form"><VoiceInput><input aria-label="Новый пункт чек-листа заявки" value={checkText} onChange={(event) => setCheckText(event.target.value)} required maxLength={500} placeholder="Добавить пункт выполнения…" /></VoiceInput><button className="button"><Plus size={15} />Добавить</button></form></div>
          <ol ref={chatListRef} className="lead-chat-list" onScroll={(event) => { const element = event.currentTarget; chatNearBottom.current = element.scrollHeight - element.scrollTop - element.clientHeight < 90; }}>{timeline.length ? timeline.map(({ kind, item }) => kind === 'file' ? <li key={`file-${item.id}`} data-notification-event={item.id} className={`lead-file-entry ${focusEventId === item.id ? "notification-focus" : ""}`}><div className="lead-chat-bubble"><header><strong>Вложение к заявке</strong><span>Файл</span></header>{item.type?.startsWith('image/') ? <button type="button" className="lead-chat-image" aria-label={`Открыть фотографию ${item.name}`} onClick={() => openDocument({ ...item, mime: item.type, siteId: site.id, sharedFileId: liveSession ? item.id : '' })}><img src={item.url} alt={item.name} loading="lazy" /></button> : null}<a className="lead-chat-file-link" href={item.downloadUrl} onClick={(event) => { event.preventDefault(); openDocument({ ...item, mime: item.type, siteId: site.id, sharedFileId: liveSession ? item.id : '' }); }}><Paperclip size={16}/><span>{item.name}</span><small>{formatBytes(item.size)}</small></a><time>{dateLabel(item.createdAt)}</time></div></li> : <li key={item.id} data-notification-event={item.id} className={`${item.type === 'system' ? 'system' : item.authorId === currentEmployeeId ? 'own' : ''} ${focusEventId === item.id ? 'notification-focus' : ''}`}><div className="lead-chat-bubble"><header><strong>{item.authorName || employee(item.authorId, state)?.name || (item.direction === 'incoming' ? 'Клиент' : 'Система')}</strong><span>{item.subject === 'checklist.done' ? '✓ Выполнен пункт' : item.type === 'note' ? 'Комментарий' : item.type === 'call' ? 'Звонок' : item.type === 'meeting' ? 'Встреча' : item.type === 'email' ? 'Письмо' : item.type === 'system' ? 'Событие' : 'Сообщение'}</span></header><p>{item.text}</p><time>{dateLabel(item.createdAt)}</time></div></li>) : <li className="lead-chat-empty">Обсуждение заявки пока пустое.</li>}</ol>
          <form className="quick-communication lead-chat-compose" onSubmit={addActivity}><select aria-label="Тип записи" value={activityType} onChange={(event) => setActivityType(event.target.value)}><option value="note">Комментарий сотрудникам</option><option value="call">Звонок</option><option value="message">Мессенджер</option><option value="email">Письмо</option><option value="meeting">Встреча</option></select><details className="lead-recipients"><summary>Уведомить дополнительно: {recipientIds.length || 'никого'}</summary><div>{state.employees.filter((person) => person.active && person.id !== currentEmployeeId).map((person) => <label key={person.id}><input type="checkbox" checked={recipientIds.includes(person.id)} onChange={(event) => setRecipientIds((ids) => event.target.checked ? [...ids, person.id] : ids.filter((id) => id !== person.id))} />{person.name}</label>)}</div></details><VoiceInput><textarea aria-label="Сообщение по заявке" value={activityText} onChange={(event) => setActivityText(event.target.value)} required rows={3} maxLength={5000} placeholder="Напишите комментарий или итог общения…"/></VoiceInput><button type="button" className="button lead-chat-attach" disabled={uploading} onClick={() => fileRef.current?.click()}><Paperclip size={17}/>{uploading ? 'Загрузка…' : 'Файл'}</button><button className="button primary">Отправить</button></form>
        </section>

        <section className="client-panel"><div className="client-section-title"><div><Paperclip size={19} /><h2>Файлы, фотографии и переписки</h2></div><button className="button" disabled={uploading} onClick={() => fileRef.current?.click()}><Upload size={15}/>{uploading ? 'Загрузка…' : 'Прикрепить'}</button></div>
          <input ref={fileRef} className="sr-only" type="file" multiple accept="image/*,video/mp4,video/quicktime,video/webm,.pdf,.doc,.docx,.txt,.eml" onChange={uploadFiles}/>
          <p className="client-file-hint">{liveSession ? 'Файлы до 8 МБ сохраняются на сервере и видны в общем списке CRM и калькулятора.' : 'Файл до 800 КБ. В демонстрационной версии он хранится только в этом браузере.'}</p>
          {files.length ? <div className="attachment-grid">{files.map((item) => item.type?.startsWith('image/') ? <button type="button" className="attachment-card" key={item.id} onClick={() => openDocument({ ...item, mime: item.type, siteId: site.id, sharedFileId: liveSession ? item.id : '' })}><Image size={22}/><span><strong>{item.name}</strong><small>{formatBytes(item.size)} · {dateLabel(item.createdAt)}</small></span></button> : <a className="attachment-card" href={item.downloadUrl} onClick={(event) => { event.preventDefault(); openDocument({ ...item, mime: item.type, siteId: site.id, sharedFileId: liveSession ? item.id : '' }); }} key={item.id}><FileText size={22}/><span><strong>{item.name}</strong><small>{formatBytes(item.size)} · {dateLabel(item.createdAt)}</small></span></a>)}</div> : <Empty title="Вложений пока нет">Добавьте фотографии объекта, документы или экспорт переписки.</Empty>}
        </section>
      </div>

      <aside className="client-rail">
        <section className="client-panel client-contacts"><div className="client-section-title"><div><UserRound size={19}/><h2>Клиент и объект</h2></div></div><dl><dt><Phone size={15}/>Телефон</dt><dd><CallLink phone={client.phone} /></dd><dt><Mail size={15}/>Почта</dt><dd>{client.email || 'Не указана'}</dd><dt><MapPin size={15}/>Адрес</dt><dd>{site.address || 'Не указан'}</dd><dt>Источник</dt><dd>{lead.source}</dd><dt>Менеджер</dt><dd>{employee(lead.ownerId, state)?.name}</dd></dl>{lead.notes ? <div className="client-note"><strong>Примечание</strong><p>{lead.notes}</p></div> : null}</section>
        <section className="client-panel"><div className="client-section-title"><div><CheckCircle2 size={19}/><h2>Задачи и производство</h2></div><span>{tasks.length}</span></div>{tasks.length ? tasks.map((task) => <button className="client-task" key={task.id} onClick={() => onOpenTask(task.id)}><strong>{task.title}</strong><span>{employee(task.assigneeId, state)?.name || 'Не назначен'} · {dateLabel(task.dueAt)}</span><progress max="100" value={task.quantity ? task.completedQty / task.quantity * 100 : 0}/></button>) : <p className="muted small">Производственные задачи ещё не созданы.</p>}{orders.map((order) => <details className="order-spec" key={order.id}><summary>{order.number}</summary><p>{order.scope}</p></details>)}{!state.orders.some((order) => order.leadId === lead.id) ? <button className="button primary client-transfer" onClick={onTransfer}>Передать в производство</button> : null}</section>
      </aside>
    </div>

  </section>;
}

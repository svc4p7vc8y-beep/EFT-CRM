import { useMemo, useRef, useState } from 'react';
import { ArrowLeft, CalendarDays, CheckCircle2, FileText, Image, Mail, MapPin, MessageSquare, Paperclip, Phone, Plus, Upload, UserRound } from 'lucide-react';
import { Badge, Empty, Timeline, VoiceInput } from '../components/UI.jsx';
import { LEAD_STAGES, dateLabel, employee, leadContext } from '../app/model.js';

const formatBytes = (bytes) => bytes < 1024 ? `${bytes} Б` : `${Math.round(bytes / 1024)} КБ`;

export function ClientWorkspace({ state, lead, command, onBack, onEditLead, onEditClient, onTransfer, onOpenTask, notify }) {
  const { client, site } = leadContext(state, lead);
  const [activityType, setActivityType] = useState('call');
  const [activityText, setActivityText] = useState('');
  const [newAction, setNewAction] = useState('');
  const [uploading, setUploading] = useState(false);
  const fileRef = useRef(null);
  const orders = useMemo(() => state.orders.filter((order) => order.siteId === site.id), [state.orders, site.id]);
  const orderIds = useMemo(() => new Set(orders.map((order) => order.id)), [orders]);
  const tasks = useMemo(() => state.tasks.filter((task) => orderIds.has(task.orderId)), [state.tasks, orderIds]);
  const events = useMemo(() => state.activities.filter((event) => event.siteId === site.id).toSorted((a, b) => new Date(b.createdAt) - new Date(a.createdAt)), [state.activities, site.id]);
  const attachments = useMemo(() => state.attachments.filter((item) => item.siteId === site.id), [state.attachments, site.id]);
  const stageIndex = Math.max(0, LEAD_STAGES.findIndex((stage) => stage.id === lead.status));
  const progress = lead.status === 'lost' ? 0 : Math.round(((stageIndex + 1) / (LEAD_STAGES.length - 1)) * 100);

  function saveNextAction(event) {
    event.preventDefault();
    const data = new FormData(event.currentTarget);
    try { command('lead.update', { id: lead.id, nextAction: data.get('nextAction'), dueAt: data.get('dueAt') }); notify('Следующее действие запланировано'); }
    catch (error) { notify(error.message, true); }
  }

  function addActivity(event) {
    event.preventDefault();
    try { command('activity.create', { siteId: site.id, taskId: '', type: activityType, text: activityText }); setActivityText(''); notify('Запись добавлена в историю'); }
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
    try {
      for (const file of files) {
        if (file.size > 800000) throw new Error(`${file.name}: максимальный размер 800 КБ`);
        const dataUrl = String(await new Promise((resolve, reject) => { const reader = new FileReader(); reader.onload = () => resolve(reader.result); reader.onerror = () => reject(new Error(`Не удалось прочитать ${file.name}`)); reader.readAsDataURL(file); })).replace(/^data:;base64,/, 'data:application/octet-stream;base64,');
        command('attachment.add', { siteId: site.id, name: file.name, type: file.type, size: file.size, dataUrl });
      }
      notify(files.length === 1 ? 'Вложение добавлено' : `Добавлено вложений: ${files.length}`);
    } catch (error) { notify(error.message, true); }
    finally { setUploading(false); event.target.value = ''; }
  }

  return <section className="page client-workspace">
    <button className="client-back" onClick={onBack}><ArrowLeft size={17} />Назад к списку</button>
    <header className="client-header">
      <div><div className="client-title-line"><h1>{client.name}</h1><Badge value={lead.status} stages={LEAD_STAGES} /></div><p>{site.name} · заявка от {dateLabel(lead.createdAt)}</p></div>
      <div className="client-header-actions"><button className="button" onClick={onEditClient}>Контакты и объект</button><button className="button" onClick={onEditLead}>Редактировать заявку</button></div>
    </header>

    <div className="client-progress" aria-label={`Прогресс заявки ${progress}%`}>
      <div><span>Прогресс работы с клиентом</span><strong>{progress}%</strong></div>
      <div className="client-progress-track"><i style={{ width: `${progress}%` }} /></div>
      <ol>{LEAD_STAGES.filter((stage) => stage.id !== 'lost').map((stage, index) => <li className={index <= stageIndex ? 'complete' : ''} key={stage.id}><span>{index < stageIndex ? <CheckCircle2 size={15} /> : index + 1}</span>{stage.label}</li>)}</ol>
    </div>

    <div className="client-layout">
      <div className="client-main">
        <section className="client-panel next-step-panel"><div className="client-section-title"><div><CalendarDays size={19} /><h2>Следующее действие</h2></div><span>обязательный шаг по клиенту</span></div>
          <form className="next-step-form" onSubmit={saveNextAction}><label><span>Действие</span><select name="nextAction" defaultValue={lead.nextAction} key={`${lead.id}-${lead.nextAction}`}>{state.clientActions.filter((item) => item.active).map((item) => <option key={item.id}>{item.title}</option>)}{lead.nextAction && !state.clientActions.some((item) => item.title === lead.nextAction) ? <option>{lead.nextAction}</option> : null}</select></label><label><span>Дата и время</span><input name="dueAt" type="datetime-local" defaultValue={lead.dueAt} /></label><button className="button primary">Запланировать</button></form>
          <details className="action-maker"><summary><Plus size={14} />Добавить свой вариант действия</summary><form onSubmit={addAction}><VoiceInput><input aria-label="Новый вариант действия" value={newAction} onChange={(event) => setNewAction(event.target.value)} maxLength={200} required placeholder="Например, запросить фото участка"/></VoiceInput><button className="button" type="submit">Добавить</button></form></details>
        </section>

        <section className="client-panel"><div className="client-section-title"><div><MessageSquare size={19} /><h2>История общения</h2></div><span>{events.length} событий</span></div>
          <form className="quick-communication" onSubmit={addActivity}><select aria-label="Канал общения" value={activityType} onChange={(event) => setActivityType(event.target.value)}><option value="call">Звонок</option><option value="message">Мессенджер</option><option value="email">Письмо</option><option value="meeting">Встреча</option><option value="note">Заметка</option></select><VoiceInput><textarea aria-label="Результат общения" value={activityText} onChange={(event) => setActivityText(event.target.value)} required rows={3} maxLength={5000} placeholder="Что обсудили, что решил клиент, что сделать дальше"/></VoiceInput><button className="button primary">Добавить в историю</button></form>
          <Timeline state={state} events={events} />
        </section>

        <section className="client-panel"><div className="client-section-title"><div><Paperclip size={19} /><h2>Файлы, фотографии и переписки</h2></div><button className="button" disabled={uploading} onClick={() => fileRef.current?.click()}><Upload size={15}/>{uploading ? 'Загрузка…' : 'Прикрепить'}</button></div>
          <input ref={fileRef} className="sr-only" type="file" multiple accept="image/*,.pdf,.doc,.docx,.txt,.eml" onChange={uploadFiles}/>
          <p className="client-file-hint">Файл до 800 КБ. В демонстрационной версии он хранится только в этом браузере.</p>
          {attachments.length ? <div className="attachment-grid">{attachments.map((item) => <a className="attachment-card" href={item.dataUrl} download={item.name} key={item.id}>{item.type.startsWith('image/') ? <Image size={22}/> : <FileText size={22}/>}<span><strong>{item.name}</strong><small>{formatBytes(item.size)} · {dateLabel(item.createdAt)}</small></span></a>)}</div> : <Empty title="Вложений пока нет">Добавьте фотографии объекта, документы или экспорт переписки.</Empty>}
        </section>
      </div>

      <aside className="client-rail">
        <section className="client-panel client-contacts"><div className="client-section-title"><div><UserRound size={19}/><h2>Клиент и объект</h2></div></div><dl><dt><Phone size={15}/>Телефон</dt><dd>{client.phone || 'Не указан'}</dd><dt><Mail size={15}/>Почта</dt><dd>{client.email || 'Не указана'}</dd><dt><MapPin size={15}/>Адрес</dt><dd>{site.address || 'Не указан'}</dd><dt>Источник</dt><dd>{lead.source}</dd><dt>Менеджер</dt><dd>{employee(lead.ownerId, state)?.name}</dd></dl>{lead.notes ? <div className="client-note"><strong>Примечание</strong><p>{lead.notes}</p></div> : null}</section>
        <section className="client-panel"><div className="client-section-title"><div><CheckCircle2 size={19}/><h2>Задачи и производство</h2></div><span>{tasks.length}</span></div>{tasks.length ? tasks.map((task) => <button className="client-task" key={task.id} onClick={() => onOpenTask(task.id)}><strong>{task.title}</strong><span>{employee(task.assigneeId, state)?.name || 'Не назначен'} · {dateLabel(task.dueAt)}</span><progress max="100" value={task.quantity ? task.completedQty / task.quantity * 100 : 0}/></button>) : <p className="muted small">Производственные задачи ещё не созданы.</p>}{orders.map((order) => <details className="order-spec" key={order.id}><summary>{order.number}</summary><p>{order.scope}</p></details>)}{!state.orders.some((order) => order.leadId === lead.id) ? <button className="button primary client-transfer" onClick={onTransfer}>Передать в производство</button> : null}</section>
      </aside>
    </div>
  </section>;
}

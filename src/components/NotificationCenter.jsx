import { useEffect, useState } from 'react';
import { Bell, BellOff, Check, RefreshCw } from 'lucide-react';
import { disablePush, enablePush, pushAvailable } from '../app/pushClient.js';
import './notification-center.css';

const kinds = [['task', 'Назначенные задачи'], ['message', 'Сообщения клиентов и внутренний чат'], ['reminder', 'Напоминания о сроках']];

export function NotificationCenter({ runtime, late, blocked, onLead, onTask, onOpen, onCount }) {
  const [items, setItems] = useState([]);
  const [unread, setUnread] = useState(0);
  const [config, setConfig] = useState(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState('');
  const [message, setMessage] = useState('');
  const [enabled, setEnabled] = useState(false);
  const canViewLeads = runtime.capabilities?.includes('*') || runtime.capabilities?.includes('clients.view') || runtime.capabilities?.includes('clients.manage');

  async function refresh() {
    const [list, settings] = await Promise.all([runtime.notifications(), runtime.notificationConfig()]);
    setItems(list.items); setUnread(list.unread); setConfig(settings); onCount(list.unread, list.leadUnread);
    if (pushAvailable()) {
      const registration = await navigator.serviceWorker.getRegistration(new URL('./', document.baseURI));
      setEnabled(Boolean(await registration?.pushManager.getSubscription()));
    }
  }
  useEffect(() => { refresh().catch((failure) => setError(failure.message)); }, []);

  async function run(operation, success) {
    setBusy(true); setError(''); setMessage('');
    try { await operation(); if (success) setMessage(success); await refresh(); }
    catch (failure) { setError(failure.message); }
    finally { setBusy(false); }
  }

  async function markRead(item) {
    if (!item.read) {
      const list = await runtime.readNotification(item.id);
      setItems(list.items); setUnread(list.unread); onCount(list.unread, list.leadUnread);
    }
    onOpen(item.target);
  }

  return <div className="notification-center">
    <div className="notification-intro"><span><Bell size={18} />Личные уведомления <b>{unread ? `${unread} новых` : 'нет новых'}</b></span><button type="button" className="icon-button" title="Обновить" aria-label="Обновить уведомления" onClick={() => run(refresh)}><RefreshCw size={17} /></button></div>
    <div className="notification-items">{items.length ? items.map((item) => <button type="button" key={item.id} className={`notification-item ${item.read ? '' : 'unread'}`} onClick={() => run(() => markRead(item))}><span className="notification-dot" /><span><strong>{item.title}</strong><small>{item.body}</small><time>{item.createdAt}</time></span></button>) : <p className="notification-empty">Личных уведомлений пока нет.</p>}</div>
    {late.length || blocked.length ? <div className="notification-attention"><h3>Также требуют внимания</h3>{late.map((lead) => <button key={lead.id} type="button" onClick={() => onLead(lead.id)}>{lead.nextAction || 'Связаться с клиентом'}</button>)}{blocked.map((task) => <button key={task.id} type="button" onClick={() => onTask(task.id)}>{task.title} · заблокировано</button>)}</div> : null}
    <section className="notification-settings"><h3>Уведомления на телефоне</h3><p>Показываются даже когда CRM закрыта, если телефон разрешил уведомления.</p>
      {!pushAvailable() ? <p className="notification-hint">Для iPhone добавьте CRM на главный экран и откройте её через значок. На Android откройте CRM в современном браузере.</p> : null}
      {config ? <><div className="notification-switches">{kinds.map(([kind, label]) => <label key={kind}><input type="checkbox" checked={Boolean(config.preferences?.[kind])} disabled={busy} onChange={(event) => run(async () => { const preferences = { ...config.preferences, [kind]: event.target.checked }; await runtime.saveNotificationPreferences(preferences); }, 'Настройки сохранены')} />{label}</label>)}{canViewLeads ? <label><input type="checkbox" checked={Boolean(config.preferences?.allLeadMessages)} disabled={busy} onChange={(event) => run(async () => { await runtime.saveNotificationPreferences({ ...config.preferences, allLeadMessages: event.target.checked }); }, 'Настройки сохранены')} />Получать все сообщения по заявкам</label> : null}</div>{config.preferences?.allEvents ? <p className="notification-hint">Администратор включил для вас все уведомления по доступным разделам CRM.</p> : null}<div className="notification-actions">{enabled ? <button type="button" className="button" disabled={busy} onClick={() => run(() => disablePush(runtime.unsubscribePush), 'Уведомления отключены на этом устройстве')}><BellOff size={16} />Отключить на этом телефоне</button> : <button type="button" className="button primary" disabled={busy || !pushAvailable()} onClick={() => run(() => enablePush(config.publicKey, runtime.subscribePush), 'Уведомления включены на этом устройстве')}><Bell size={16} />Включить на телефоне</button>}<button type="button" className="button" disabled={busy || !enabled} onClick={() => run(() => runtime.testNotification(), 'Проверочное уведомление отправлено')}><Check size={16} />Проверить</button></div><small>Подключённых устройств: {config.devices}. Каждому сотруднику нужен собственный вход в CRM.</small></> : null}
    </section>
    {error ? <p className="notification-error" role="alert">{error}</p> : null}{message ? <p className="notification-success" role="status">{message}</p> : null}
  </div>;
}

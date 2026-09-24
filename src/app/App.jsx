import { useEffect, useRef, useState } from 'react';
import { Bell, Search, ChevronDown, Home, ClipboardList, Users, MessageSquare, Factory, CheckSquare, CalendarDays, Package, HardHat, Truck, Database, Menu, X, CircleCheck, AlertCircle, Settings2, LogOut, ShieldCheck } from 'lucide-react';
import { useWorkspace } from './useWorkspace.js';
import { applyCommand, employee, isOverdue, leadContext } from './model.js';
import { Dialog, VoiceInput } from '../components/UI.jsx';
import { ThemeSwitcher } from '../components/ThemeSwitcher.jsx';
import { ActivityForm, ClientForm, DataTools, LeadForm, OrderForm, TaskForm } from '../components/Forms.jsx';
import { Leads } from '../features/Leads.jsx';
import { Production, TaskDetail } from '../features/Production.jsx';
import { Clients, Communications, Overview } from '../features/WorkspacePages.jsx';
import { StaffSettings, EmployeeForm, CrewForm } from '../features/StaffSettings.jsx';
import { Calendar } from '../features/Calendar.jsx';
import { Inventory } from '../features/Inventory.jsx';
import { Attendance } from '../features/Attendance.jsx';
import { ClientWorkspace } from '../features/ClientWorkspace.jsx';
import { MyTasks } from '../features/MyTasks.jsx';
import { RELEASE } from './operations.js';
import { employeeFromApi } from './api.js';
import './app.css';
import './operations.css';

const navigation = [
  { id: 'overview', label: 'Обзор', icon: Home }, { id: 'leads', label: 'Заявки', icon: ClipboardList }, { id: 'clients', label: 'Клиенты и объекты', icon: Users }, { id: 'communications', label: 'Общение', icon: MessageSquare },
  { id: 'production', label: 'Производство', icon: Factory, separator: true }, { id: 'tasks', label: 'Мои задачи', icon: CheckSquare }, { id: 'calendar', label: 'Календарь', icon: CalendarDays }, { id: 'attendance', label: 'Табель', icon: ClipboardList },
  { id: 'supplies', label: 'Закупки и склад', icon: Package, separator: true }, { id: 'crews', label: 'Бригады', icon: HardHat }, { id: 'logistics', label: 'Логистика', icon: Truck, planned: true }, { id: 'settings', label: 'Настройки', icon: Settings2, separator: true },
];
const getRoute = () => { const hash = window.location.hash.slice(1); const match = hash.match(/^client\/(.+)$/); if (match) return { page: 'client', leadId: decodeURIComponent(match[1]) }; return { page: navigation.some((v) => v.id === hash && !v.planned) ? hash : 'leads', leadId: '' }; };
const roleLabels = { owner: 'Владелец', admin: 'Администратор', finance: 'Финансы', manager: 'Менеджер', production: 'Производство', procurement: 'Закупки', foreman: 'Бригадир', employee: 'Сотрудник', viewer: 'Просмотр' };
const initials = (name = '') => name.split(/\s+/).filter(Boolean).slice(0, 2).map((part) => part[0]).join('').toUpperCase() || 'ЭФ';
const serverActions = new Set(['lead.create','lead.update','client.update','activity.create','order.create','task.create','task.update','task.reschedule','task.check']);

export function App({ runtime = { mode: 'demo' } }) {
  const { state: localState, command, storageError, replace } = useWorkspace();
  const liveSession = runtime.mode === 'server'; const sessionUser = runtime.user;
  const canSaveWorkspace = !liveSession || runtime.capabilities?.includes('*') || (runtime.capabilities?.includes('clients.manage') && runtime.capabilities?.includes('tasks.manage'));
  const [serverOverride, setServerOverride] = useState(null);
  const revisionRef = useRef(Number(runtime.serverData?.workspaceRevision || 0));
  const saveQueue = useRef(Promise.resolve());
  const initializing = useRef(false);
  const serverCore = serverOverride || runtime.serverData || {};
  const state = liveSession ? { ...localState, clients: serverCore.clients || [], sites: serverCore.sites || [], leads: serverCore.leads || [], orders: serverCore.orders || [], tasks: serverCore.tasks || [], activities: serverCore.activities || [], employees: (runtime.serverData?.employees || []).map(employeeFromApi), crews: runtime.serverData?.crews || [], attendance: runtime.serverData?.attendance || [] } : localState;
  const personnelState = state;
  const initialRoute = getRoute();
  const themeKey = `eft-crm-theme:${sessionUser?.username || 'local'}`;
  const [page, setPage] = useState(initialRoute.page); const [search, setSearch] = useState(''); const [selectedLead, setSelectedLead] = useState(initialRoute.leadId); const [modal, setModal] = useState(null); const [toast, setToast] = useState(null); const [sidebar, setSidebar] = useState(false); const [now,setNow]=useState(()=>new Date());
  const [theme, setTheme] = useState(() => { const saved = localStorage.getItem(themeKey); return ['light', 'dark', 'brand'].includes(saved) ? saved : 'brand'; });
  const selected = state.leads.find((l) => l.id === selectedLead);
  const late = state.leads.filter((l) => l.status !== 'lost' && isOverdue(l.dueAt)); const blocked = state.tasks.filter((t) => t.status === 'blocked');
  useEffect(() => { const change = () => { const route = getRoute(); setPage(route.page); if (route.leadId) setSelectedLead(route.leadId); setSearch(''); }; window.addEventListener('hashchange', change); return () => window.removeEventListener('hashchange', change); }, []);
  useEffect(() => { window.scrollTo({ top: 0, behavior: 'instant' }); }, [page, selectedLead]);
  useEffect(() => { if (!toast) return; const id = setTimeout(() => setToast(null), 4500); return () => clearTimeout(id); }, [toast]);
  useEffect(() => { const id=setInterval(()=>setNow(new Date()),1000); return ()=>clearInterval(id); }, []);
  useEffect(() => { document.documentElement.dataset.theme = theme; localStorage.setItem(themeKey, theme); const color = theme === 'dark' ? '#101820' : theme === 'brand' ? '#282b27' : '#142b3b'; document.querySelector('meta[name="theme-color"]')?.setAttribute('content', color); }, [theme, themeKey]);
  useEffect(() => { revisionRef.current = Number(runtime.serverData?.workspaceRevision || revisionRef.current); }, [runtime.serverData?.workspaceRevision]);
  useEffect(() => {
    if (!liveSession || !canSaveWorkspace || runtime.serverData?.workspaceInitialized !== false || initializing.current || !runtime.saveWorkspace) return;
    initializing.current = true;
    const initial = { ...localState, employees: localState.employees || [] };
    runtime.saveWorkspace(initial, Number(runtime.serverData?.workspaceRevision || 0), true).then((saved) => {
      revisionRef.current = saved.workspaceRevision; setServerOverride(saved); setToast({ text: 'Данные этого устройства перенесены на сервер', error: false });
    }).catch((error) => setToast({ text: error.message, error: true }));
  }, [canSaveWorkspace, liveSession, localState, runtime]);
  function navigate(id) { const item = navigation.find((v) => v.id === id); if (item?.planned) { setModal({ type: 'planned', id }); return; } setPage(id); setSearch(''); setSidebar(false); window.location.hash = id; }
  function mutate(action, payload, message = 'Сохранено') {
    if (!liveSession) { const next = command(action, payload); setToast({ text: message, error: false }); return next; }
    if (serverActions.has(action) && !canSaveWorkspace) throw new Error('Для этого действия недостаточно прав. Обратитесь к администратору CRM.');
    const next = applyCommand(state, action, payload); replace(next); setToast({ text: message, error: false });
    if (serverActions.has(action) && runtime.saveWorkspace) {
      const snapshot = { clients: next.clients, sites: next.sites, leads: next.leads, orders: next.orders, tasks: next.tasks, activities: next.activities };
      setServerOverride(snapshot);
      saveQueue.current = saveQueue.current.then(() => runtime.saveWorkspace(snapshot, revisionRef.current, false)).then((saved) => {
        revisionRef.current = saved.workspaceRevision; setServerOverride(saved);
      }).catch(async (error) => {
        setToast({ text: error.message, error: true });
        try { const fresh = await runtime.refreshWorkspace?.(); if (fresh) { revisionRef.current = fresh.workspaceRevision; setServerOverride(fresh); } } catch { /* The original error is more useful. */ }
      });
    }
    return next;
  }
  function safeMutate(action, payload, message) { try { mutate(action, payload, message); return true; } catch (e) { setToast({ text: e.message, error: true }); return false; } }
  function openLead(id) { if (!id) return; setSelectedLead(id); setPage('client'); setSearch(''); setSidebar(false); window.location.hash = `client/${encodeURIComponent(id)}`; }
  function moveTask(id, status) { if (status === 'blocked') setModal({ type: 'task-edit', id, status }); else safeMutate('task.update', { id, status }, 'Статус задания обновлён'); }
  const closeModal = () => setModal(null);
  const modalLead = state.leads.find((l) => l.id === modal?.id); const modalTask = state.tasks.find((t) => t.id === modal?.id);
  let dialog = null;
  if (modal?.type === 'lead-new' || (modal?.type === 'lead-edit' && modalLead)) dialog = <Dialog key={`${modal.type}-${modal.id}`} title={modal.type === 'lead-new' ? 'Новая заявка' : 'Изменить заявку'} onClose={closeModal}><LeadForm state={state} lead={modalLead} onClose={closeModal} onSubmit={(p) => { const next = mutate(modal.type === 'lead-new' ? 'lead.create' : 'lead.update', { ...p, id: modal?.id }, modal.type === 'lead-new' ? 'Заявка создана' : 'Заявка обновлена'); if (modal.type === 'lead-new') openLead(next.leads[0].id); closeModal(); }} /></Dialog>;
  if (modal?.type === 'client-edit' && modalLead) { const { client, site } = leadContext(state, modalLead); dialog = <Dialog title="Контакты и объект" onClose={closeModal}><ClientForm client={client} site={site} onClose={closeModal} onSubmit={(p) => { mutate('client.update', { ...p, id: client.id, siteId: site.id }); closeModal(); }} /></Dialog>; }
  if (modal?.type === 'activity') dialog = <Dialog title="Записать общение" onClose={closeModal}><ActivityForm state={state} siteId={modal.siteId} onClose={closeModal} onSubmit={(p) => { mutate('activity.create', p, 'Запись добавлена в историю'); closeModal(); }} /></Dialog>;
  if (modal?.type === 'order' && modalLead) dialog = <Dialog title="Передать заказ в производство" onClose={closeModal}><OrderForm state={state} onClose={closeModal} onSubmit={(p) => { mutate('order.create', { ...p, leadId: modal.id }, 'Созданы заказ и первое задание цеху'); closeModal(); }} /></Dialog>;
  if (modal?.type === 'task-new' || (modal?.type === 'task-edit' && modalTask)) dialog = <Dialog key={`${modal.type}-${modal.id}`} title={modal.type === 'task-new' ? 'Новое задание' : 'Изменить задание'} onClose={closeModal}><TaskForm state={state} task={modalTask ? { ...modalTask, status: modal.status || modalTask.status } : null} orderId={modal.orderId} onClose={closeModal} onSubmit={(p) => { const next = mutate(modal.type === 'task-new' ? 'task.create' : 'task.update', { ...p, id: modal.id }, 'Задание сохранено'); setModal({ type: 'task', id: modal.type === 'task-new' ? next.tasks[0].id : modal.id }); }} /></Dialog>;
  if (modal?.type === 'task' && modalTask) dialog = <Dialog title={modalTask.title} onClose={closeModal} wide><TaskDetail state={state} task={modalTask} onEdit={() => setModal({ type: 'task-edit', id: modalTask.id })} onCheck={(p) => safeMutate('task.check', p, 'Чек-лист обновлён')} onMove={moveTask} onComment={(p) => mutate('activity.create', p, 'Комментарий добавлен')} /></Dialog>;
  if (modal?.type === 'data') dialog = <Dialog title="Данные на этом устройстве" onClose={closeModal}><DataTools state={state} replace={replace} onClose={closeModal} /></Dialog>;
  if (modal?.type === 'profile') dialog = <Dialog title="Рабочий аккаунт" onClose={closeModal}><div className="form-content account-summary"><span className="profile-avatar">{initials(sessionUser?.displayName)}</span><div><strong>{sessionUser?.displayName}</strong><span>{roleLabels[sessionUser?.role] || sessionUser?.role}</span><small>Логин: {sessionUser?.username}</small></div><p><ShieldCheck size={17} />Сессия защищена. Права доступа назначаются администратором CRM.</p></div><footer className="dialog-actions"><button className="button" onClick={closeModal}>Закрыть</button><button className="button logout-button" onClick={runtime.onLogout}><LogOut size={16} />Выйти</button></footer></Dialog>;
  if (modal?.type === 'employee-form') dialog = <Dialog key={`employee-${modal.id || 'new'}`} title={modal.id ? 'Редактировать сотрудника' : 'Новый сотрудник'} onClose={closeModal}><EmployeeForm person={personnelState.employees.find((p) => p.id === modal.id)} serverMode={liveSession} onClose={closeModal} onSubmit={async (p) => { if (liveSession) await runtime.saveEmployee({ ...p, id: modal.id }); else mutate('employee.save', { ...p, id: modal.id }, 'Сотрудник сохранён'); setToast({ text: 'Сотрудник сохранён', error: false }); closeModal(); }} /></Dialog>;
  if (modal?.type === 'crew-form') dialog = <Dialog key={`crew-${modal.id || 'new'}`} title={modal.id ? 'Редактировать бригаду' : 'Новая бригада'} onClose={closeModal}><CrewForm state={personnelState} crew={personnelState.crews.find((c) => c.id === modal.id)} onClose={closeModal} onSubmit={async (p) => { if (liveSession) await runtime.saveCrew({ ...p, id: modal.id }); else mutate('crew.save', { ...p, id: modal.id }, 'Бригада сохранена'); setToast({ text: 'Бригада сохранена', error: false }); closeModal(); }} /></Dialog>;
  if (modal?.type === 'notifications') dialog = <Dialog title="Требуют внимания" onClose={closeModal}><div className="form-content">{!late.length && !blocked.length ? <p>Просроченных контактов и блокировок нет.</p> : null}{late.map((l) => <button className="action-row" key={l.id} onClick={() => { openLead(l.id); closeModal(); }}><CalendarDays size={18} /><div><strong>{leadContext(state, l).client.name}</strong><span>{l.nextAction}</span></div></button>)}{blocked.map((t) => <button className="action-row" key={t.id} onClick={() => setModal({ type: 'task', id: t.id })}><AlertCircle size={18} /><div><strong>{t.title}</strong><span>{t.blockReason}</span></div></button>)}</div></Dialog>;
  if (modal?.type === 'planned') dialog = <Dialog title={navigation.find((n) => n.id === modal.id)?.label} onClose={closeModal}><div className="form-content"><p className="data-explanation">Этот раздел входит в следующие этапы разработки.</p><p>{modal.id === 'supplies' ? 'Потребности по объектам, заказы поставщикам, приёмка, резервы, выдача материалов и учёт инструмента.' : modal.id === 'crews' ? 'Состав бригад, специализации, назначение на строительные этапы и проверка занятости.' : 'Комплектация, рейсы, окна доставки и подтверждение приёмки на объекте.'}</p><p className="muted small">Сейчас доступны заявки, клиенты, история общения, цех, задачи и календарь.</p></div><footer className="dialog-actions"><button className="button primary" onClick={closeModal}>Понятно</button></footer></Dialog>;
  const taskProps = { state, search, onCreate: (orderId) => setModal({ type: 'task-new', orderId }), onOpen: (id) => setModal({ type: 'task', id }), onMove: moveTask };
  return <div className="app-shell">
    {sidebar ? <button className="sidebar-backdrop" aria-label="Закрыть меню" onClick={() => setSidebar(false)} /> : null}
    <aside className={`sidebar ${sidebar ? 'is-open' : ''}`}><a href="#overview" className="brand" onClick={() => navigate('overview')}><img className="brand-logo" src="./eft-logo.jpg" alt="ЭФТ"/><span>Управление<br />строительством</span></a><nav aria-label="Основная навигация">{navigation.map(({ id, icon: Icon, label, separator, planned }) => { const active = page === id || (page === 'client' && id === 'leads'); return <div className={separator ? 'nav-group-start' : ''} key={id}><button className={`nav-item ${active ? 'active' : ''} ${planned ? 'planned' : ''}`} aria-current={active ? 'page' : undefined} onClick={() => navigate(id)}><Icon size={20} /><span>{label}</span>{planned ? <span className="planned-dot" title="Следующий этап" /> : null}</button></div>; })}</nav><div className="sidebar-bottom"><button onClick={() => setModal({ type: 'data' })}><Database size={17} /><span>Данные и резервная копия</span></button><span className={`demo-label ${liveSession ? 'server-online' : ''}`}><span />{liveSession ? 'Серверная сессия активна' : 'Демо · на этом устройстве'}</span><small>Версия {RELEASE} · {__BUILD_REVISION__}</small></div></aside>
    <div className="workspace"><header className="topbar"><span className="release-badge">v{RELEASE}</span><button className="icon-button mobile-menu" aria-label="Открыть меню" onClick={() => setSidebar(true)}><Menu size={22} /></button><div className="topbar-clock" aria-label="Текущие дата и время"><strong>{now.toLocaleTimeString('ru-RU',{hour:'2-digit',minute:'2-digit',second:'2-digit'})}</strong><span>{now.toLocaleDateString('ru-RU',{day:'2-digit',month:'2-digit',year:'numeric'})}</span></div><ThemeSwitcher value={theme} onChange={setTheme}/><label className="global-search"><Search size={19} /><VoiceInput><input aria-label="Общий поиск" placeholder="Поиск по клиентам, объектам, задачам…" value={search} onChange={(e) => { setSearch(e.target.value); if (page === 'overview') { setPage('leads'); window.history.replaceState(null, '', '#leads'); } }} /></VoiceInput>{search ? <button className="icon-button" aria-label="Очистить поиск" onClick={() => setSearch('')}><X size={14} /></button> : null}</label><button className="notification-button icon-button" aria-label={`Требуют внимания: ${late.length + blocked.length}`} onClick={() => setModal({ type: 'notifications' })}><Bell size={21} />{late.length + blocked.length ? <i /> : null}</button><button className="profile-button" onClick={() => setModal({ type: liveSession ? 'profile' : 'data' })}><span className="profile-avatar">{liveSession ? initials(sessionUser?.displayName) : 'ЭФ'}</span><span>{liveSession ? sessionUser?.displayName : 'Рабочее пространство'}<small>{liveSession ? (roleLabels[sessionUser?.role] || sessionUser?.role) : 'Ознакомительная версия'}</small></span><ChevronDown size={15} /></button></header>
    {storageError ? <div className="storage-error" role="alert">{storageError}<button onClick={() => setModal({ type: 'data' })}>Открыть данные</button></div> : null}
    <main className="workspace-body">
      {page === 'leads' ? <Leads state={state} search={search} selectedId="" onSelect={openLead} onCreate={() => setModal({ type: 'lead-new' })} onEdit={(id) => setModal({ type: 'lead-edit', id })} onChangeStage={(id, status) => safeMutate('lead.update', { id, status }, 'Этап обновлён')} /> : null}
      {page === 'client' && selected ? <ClientWorkspace key={selected.id} state={state} lead={selected} command={mutate} onBack={() => navigate('leads')} onEditLead={() => setModal({ type: 'lead-edit', id: selected.id })} onEditClient={() => setModal({ type: 'client-edit', id: selected.id })} onTransfer={() => setModal({ type: 'order', id: selected.id })} onOpenTask={(id) => setModal({ type: 'task', id })} notify={(text, error = false) => setToast({ text, error })} /> : null}
      {page === 'client' && !selected ? <section className="page"><p>Заявка не найдена.</p><button className="button" onClick={() => navigate('leads')}>Вернуться к заявкам</button></section> : null}
      {page === 'production' ? <Production key={page} {...taskProps} /> : null}
      {page === 'tasks' ? <MyTasks state={state} search={search} command={mutate} onCreate={() => setModal({ type: 'task-new' })} onOpen={(id) => setModal({ type: 'task', id })} onMove={moveTask} /> : null}
      {page === 'overview' ? <Overview state={state} navigate={navigate} onLead={openLead} onTask={taskProps.onOpen} /> : null}
      {page === 'clients' ? <Clients state={state} search={search} onLead={openLead} onCreate={() => setModal({ type: 'lead-new' })} /> : null}
      {page === 'communications' ? <Communications state={state} search={search} onCreate={(siteId) => setModal({ type: 'activity', siteId })} onLead={openLead} /> : null}
      {page === 'supplies' ? <Inventory state={state} command={command} search={search}/> : null}
      {page === 'attendance' ? <Attendance state={personnelState} command={command} search={search} runtime={runtime} notify={(text,error=false)=>setToast({text,error})}/> : null}
      {page === 'calendar' ? <Calendar state={state} search={search} onLead={openLead} onTask={taskProps.onOpen} /> : null}
      {page === 'settings' || page === 'crews' ? <StaffSettings key={page} state={personnelState} focus={page === 'crews' ? 'crews' : 'employees'} search={search} onEmployee={(id) => setModal({ type: 'employee-form', id })} onCrew={(id) => setModal({ type: 'crew-form', id })} runtime={runtime} /> : null}
    </main></div>{dialog}{toast ? <div className={`toast ${toast.error ? 'error' : ''}`} role={toast.error ? 'alert' : 'status'}>{toast.error ? <AlertCircle size={19} /> : <CircleCheck size={19} />}<span>{toast.text}</span><button className="icon-button" onClick={() => setToast(null)} aria-label="Закрыть уведомление"><X size={15} /></button></div> : null}
  </div>;
}

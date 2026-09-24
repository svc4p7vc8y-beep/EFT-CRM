import { useMemo, useState } from 'react';
import { AlertCircle, CalendarDays, Check, ChevronLeft, ChevronRight, ClipboardCheck, LayoutGrid, List, Plus, RotateCcw, UserRoundX, Users } from 'lucide-react';
import { Avatar, Badge, Empty, VoiceInput } from '../components/UI.jsx';
import { TASK_STAGES, dateKey, dateLabel, employee, taskContext } from '../app/model.js';
import './my-tasks.css';

const dayOf = (value) => value?.slice(0, 10) || '';
const atNoon = (value) => new Date(`${value}T12:00:00`);
const shiftDay = (value, amount) => { const date = atNoon(value); date.setDate(date.getDate() + amount); return dateKey(date); };
const timeOf = (value) => value?.slice(11, 16) || '10:00';
const mondayOf = (value) => { const date = atNoon(value); const day = date.getDay() || 7; date.setDate(date.getDate() - day + 1); return dateKey(date); };
const monthTitle = (value) => atNoon(value).toLocaleDateString('ru-RU', { month: 'long', year: 'numeric' });
const dayTitle = (value) => atNoon(value).toLocaleDateString('ru-RU', { day: 'numeric', month: 'long', year: 'numeric', weekday: 'short' });

function AssigneePicker({ state, task, onAssign, side = false }) {
  return <label className={`task-person task-assignee-picker${side ? ' side' : ''}`} onClick={(event) => event.stopPropagation()}>
    <Avatar id={task.assigneeId} state={state} />
    <select aria-label={`Ответственный: ${task.title}`} value={task.assigneeId || ''} onChange={(event) => onAssign(task.id, event.target.value)}>
      <option value="">Не назначен</option>
      {state.employees.filter((person) => person.active || person.id === task.assigneeId).map((person) => <option key={person.id} value={person.id}>{person.name}</option>)}
    </select>
  </label>;
}

function TaskRow({ state, task, carried = false, selected, onSelect, onOpen, onMove, onReschedule, onAssign }) {
  const { order, site, stage } = taskContext(state, task);
  function move(days) { onReschedule(task.id, `${shiftDay(dayOf(task.dueAt) || dateKey(), days)}T${timeOf(task.dueAt)}`); }
  return <article className={`my-task-row${selected ? ' selected' : ''}`} onClick={() => onSelect(task.id)}>
    <button className={`task-check ${task.status === 'done' ? 'done' : ''}`} type="button" aria-label={task.status === 'done' ? 'Задача выполнена' : 'Отметить выполненной'} onClick={(event) => { event.stopPropagation(); if (task.status !== 'done') onMove(task.id, 'done'); }}>{task.status === 'done' ? <Check size={15} /> : null}</button>
    <time className={!task.dueAt ? 'no-date' : ''}>{task.dueAt ? timeOf(task.dueAt) : 'Без срока'}</time><button className="task-row-title" type="button" onClick={(event) => { event.stopPropagation(); onOpen(task.id); }}><strong>{task.title}</strong>{carried ? <small>Было: {dateLabel(task.dueAt, false)}</small> : null}</button>
    <span className="task-project">{site?.name || order?.number || 'Без проекта'}{stage ? <small>{stage.title}</small> : null}</span><span className={`priority ${task.priority}`}>{task.priority === 'high' ? 'Высокий' : 'Обычный'}</span>
    <AssigneePicker state={state} task={task} onAssign={onAssign} /><Badge value={task.status} stages={TASK_STAGES} />
    {carried ? <span className="task-shifts"><button type="button" onClick={(event) => { event.stopPropagation(); move(1); }}>+1 день</button><button type="button" onClick={(event) => { event.stopPropagation(); move(2); }}>+2 дня</button><button type="button" onClick={(event) => { event.stopPropagation(); move(3); }}>+3 дня</button><label title="Выбрать дату"><CalendarDays size={15} /><input type="date" aria-label="Перенести на выбранную дату" onClick={(event) => event.stopPropagation()} onChange={(event) => { if (event.target.value) onReschedule(task.id, `${event.target.value}T${timeOf(task.dueAt)}`); }} /></label></span> : null}
  </article>;
}

function TaskDetails({ state, task, onOpen, onEdit, onReschedule, onAssign }) {
  if (!task) return <aside className="task-side empty-side"><ClipboardCheck size={28} /><strong>Выберите задачу</strong><span>Здесь появятся детали, срок и чек-лист.</span></aside>;
  const done = task.checklist.filter((item) => item.done).length; const { site, stage, crew } = taskContext(state, task);
  return <aside className="task-side"><div className="task-side-head"><div><h2>{task.title}</h2><span className={`priority ${task.priority}`}>{task.priority === 'high' ? 'Высокий приоритет' : 'Обычный приоритет'}</span></div><button className="text-link" onClick={() => onOpen(task.id)}>Открыть</button></div>
    <dl><dt>Ответственный</dt><dd><AssigneePicker state={state} task={task} onAssign={onAssign} side /></dd><dt>Объект</dt><dd>{site?.name || 'Не привязан'}</dd>{stage ? <><dt>Этап</dt><dd>{stage.title}</dd></> : null}{crew ? <><dt>Бригада</dt><dd>{crew.name}</dd></> : null}<dt>Срок выполнения</dt><dd>{task.dueAt ? dateLabel(task.dueAt) : 'Не назначен'}</dd><dt>Статус</dt><dd><Badge value={task.status} stages={TASK_STAGES} /></dd></dl>
    <section><h3>Описание</h3><p>{task.description || 'Описание не добавлено.'}</p></section><section><h3>Чек-лист ({done} из {task.checklist.length})</h3>{task.checklist.length ? <ul>{task.checklist.map((item) => <li className={item.done ? 'done' : ''} key={item.id}><span>{item.done ? <Check size={12} /> : null}</span>{item.title}</li>)}</ul> : <p>Чек-лист пока пуст.</p>}</section>
    {task.rescheduleHistory.length ? <section><h3>История переноса</h3><p>{task.rescheduleHistory.length} измен. · первый срок {dateLabel(task.originalDueAt || task.rescheduleHistory.at(-1)?.from)}</p></section> : null}
    <div className="task-side-actions">{task.dueAt ? <button className="button" onClick={() => onReschedule(task.id, `${shiftDay(dayOf(task.dueAt), 1)}T${timeOf(task.dueAt)}`)}><RotateCcw size={15} />Перенести на день</button> : null}<button className="button primary" onClick={() => onEdit(task.id)}>{task.dueAt ? 'Редактировать' : 'Назначить и запланировать'}</button></div>
  </aside>;
}

function WeekView({ state, tasks, start, onSelect }) {
  const days = Array.from({ length: 7 }, (_, index) => shiftDay(start, index));
  return <div className="task-week">{days.map((day) => <section className={day === dateKey() ? 'today' : ''} key={day}><header><strong>{atNoon(day).toLocaleDateString('ru-RU', { weekday: 'short' })}</strong><span>{atNoon(day).getDate()}</span></header>{tasks.filter((task) => dayOf(task.dueAt) === day).map((task) => <button key={task.id} onClick={() => onSelect(task.id)}><time>{timeOf(task.dueAt)}</time><strong>{task.title}</strong><small>{employee(task.assigneeId, state)?.name || 'Не назначен'}</small></button>)}</section>)}</div>;
}

function MonthView({ state, tasks, selectedDate, onDay }) {
  const basis = atNoon(selectedDate); const first = new Date(basis.getFullYear(), basis.getMonth(), 1, 12); const offset = (first.getDay() || 7) - 1; first.setDate(first.getDate() - offset);
  const days = Array.from({ length: 42 }, (_, index) => { const d = new Date(first); d.setDate(first.getDate() + index); return dateKey(d); });
  return <div className="task-month"><div className="month-weekdays">{['Пн','Вт','Ср','Чт','Пт','Сб','Вс'].map((day) => <span key={day}>{day}</span>)}</div><div className="month-cells">{days.map((day) => { const rows = tasks.filter((task) => dayOf(task.dueAt) === day); return <button className={`${atNoon(day).getMonth() === basis.getMonth() ? '' : 'outside'} ${day === dateKey() ? 'today' : ''}`} key={day} onClick={() => onDay(day)}><strong>{atNoon(day).getDate()}</strong>{rows.slice(0, 3).map((task) => <span key={task.id}><i />{task.title}</span>)}{rows.length > 3 ? <small>ещё {rows.length - 3}</small> : null}</button>; })}</div></div>;
}

export function MyTasks({ state, search, command, onCreate, onOpen, onEdit, onMove }) {
  const staff = state.employees.filter((person) => person.active);
  const [period, setPeriod] = useState('all'); const [view, setView] = useState('list'); const [selectedDate, setSelectedDate] = useState(dateKey()); const [staffIds, setStaffIds] = useState([]); const [selectedId, setSelectedId] = useState(''); const [title, setTitle] = useState(''); const [assignee, setAssignee] = useState(''); const [dueMode, setDueMode] = useState('none'); const [due, setDue] = useState(''); const [notice, setNotice] = useState('');
  const tasks = useMemo(() => {
    const query = search.toLocaleLowerCase('ru-RU');
    return state.tasks.filter((task) => {
      const ownerKey = task.assigneeId || '__unassigned__';
      return (!staffIds.length || staffIds.includes(ownerKey)) && `${task.title} ${task.description} ${employee(task.assigneeId, state)?.name || 'не назначен'}`.toLocaleLowerCase('ru-RU').includes(query);
    });
  }, [state, staffIds, search]);
  const selectedTask = state.tasks.find((task) => task.id === selectedId) || tasks.find((task) => period === 'all' || dayOf(task.dueAt) === selectedDate) || tasks[0];
  const openTasks = tasks.filter((task) => task.status !== 'done');
  const carried = tasks.filter((task) => task.status !== 'done' && task.dueAt && dayOf(task.dueAt) < selectedDate);
  const today = tasks.filter((task) => dayOf(task.dueAt) === selectedDate);
  const active = openTasks.filter((task) => ['doing','review','blocked'].includes(task.status));
  const done = tasks.filter((task) => task.status === 'done' && dayOf(task.dueAt) === selectedDate);
  const unassigned = openTasks.filter((task) => !task.assigneeId);
  const unscheduled = openTasks.filter((task) => !task.dueAt);
  const needsPlanning = openTasks.filter((task) => !task.assigneeId || !task.dueAt);
  const plannedOpen = openTasks.filter((task) => task.assigneeId && task.dueAt).toSorted((a, b) => a.dueAt.localeCompare(b.dueAt));
  function reschedule(id, dueAt) { try { command('task.reschedule', { id, dueAt }); setNotice('Новый срок сохранён в истории задачи.'); } catch (error) { setNotice(error.message); } }
  function assign(id, assigneeId) { try { command('task.update', { id, assigneeId }); setNotice(assigneeId ? 'Ответственный назначен.' : 'Задача возвращена в общий список без исполнителя.'); } catch (error) { setNotice(error.message); } }
  function submit(event) {
    event.preventDefault();
    try {
      const template = state.taskTemplates.find((item) => item.title === title);
      const dueAt = dueMode === 'today' ? `${dateKey()}T18:00` : dueMode === 'tomorrow' ? `${shiftDay(dateKey(), 1)}T10:00` : dueMode === 'custom' ? due : '';
      if (dueMode === 'custom' && !dueAt) throw new Error('Выберите дату и время или укажите «Без срока».');
      command('task.create', { title, description: template?.description || '', templateId: template?.id || '', orderId: '', assigneeId: assignee, dueAt, quantity: 1, completedQty: 0, unit: template?.unit || 'задача', priority: 'normal', status: 'planned' });
      setTitle(''); setAssignee(''); setDueMode('none'); setDue(''); setNotice('Задача добавлена в общий список.');
    } catch (error) { setNotice(error.message); }
  }
  function navigate(amount) { if (period === 'month') { const date = atNoon(selectedDate); date.setMonth(date.getMonth() + amount); setSelectedDate(dateKey(date)); } else setSelectedDate(shiftDay(selectedDate, amount * (period === 'week' ? 7 : 1))); }
  const listGroups = staff.map((person) => ({ person, tasks: today.filter((task) => task.assigneeId === person.id) })).filter((group) => group.tasks.length);
  const unassignedToday = today.filter((task) => !task.assigneeId);
  const boardTasks = period === 'all' ? openTasks : period === 'day' ? [...carried, ...today.filter((task) => !carried.includes(task)), ...unscheduled.filter((task) => !today.includes(task))] : tasks;
  const titleForPeriod = period === 'all' ? 'Все открытые задачи' : period === 'month' ? monthTitle(selectedDate) : period === 'week' ? `${dayTitle(mondayOf(selectedDate))} — ${dayTitle(shiftDay(mondayOf(selectedDate), 6))}` : dayTitle(selectedDate);
  return <section className="page my-tasks-page"><header className="my-tasks-title"><div><h1>Мои задачи</h1><p>Общий список, свободные задачи, загрузка сотрудников и сроки выполнения</p></div><button className="button" onClick={onCreate}><Plus size={17} />Расширенная задача</button></header>
    <form className="task-quick-add" onSubmit={submit}><VoiceInput><input list="task-quick-actions" required maxLength={200} value={title} onChange={(event) => setTitle(event.target.value)} placeholder="Добавить задачу голосом или текстом…" aria-label="Текст новой задачи" /></VoiceInput><datalist id="task-quick-actions">{state.taskTemplates.filter((item) => item.active).map((item) => <option value={item.title} key={item.id} />)}</datalist><select value={assignee} onChange={(event) => setAssignee(event.target.value)} aria-label="Ответственный"><option value="">Не назначен</option>{staff.map((person) => <option value={person.id} key={person.id}>{person.name}</option>)}</select><select value={dueMode} onChange={(event) => setDueMode(event.target.value)} aria-label="Когда выполнить"><option value="none">Без срока</option><option value="today">Сегодня</option><option value="tomorrow">Завтра</option><option value="custom">Выбрать дату…</option></select>{dueMode === 'custom' ? <input type="datetime-local" required value={due} onChange={(event) => setDue(event.target.value)} aria-label="Дата и время задачи" /> : null}<button className="button primary" type="submit"><Plus size={17} />Добавить</button></form>
    {notice ? <p className="task-notice" role="status">{notice}</p> : null}<div className="task-controls"><div className="segmented">{[['all','Общий список'],['day','День'],['week','Неделя'],['month','Месяц']].map(([id,label]) => <button className={period === id ? 'active' : ''} onClick={() => setPeriod(id)} key={id}>{label}</button>)}</div>{period !== 'all' ? <><div className="date-navigator"><button className="icon-button" onClick={() => navigate(-1)}><ChevronLeft size={18} /></button><strong>{titleForPeriod}</strong><button className="icon-button" onClick={() => navigate(1)}><ChevronRight size={18} /></button></div><button className="button" onClick={() => setSelectedDate(dateKey())}>Сегодня</button></> : <strong className="all-tasks-caption">{titleForPeriod}</strong>}<details className="staff-filter"><summary><Users size={17} />{staffIds.length ? `Фильтр: ${staffIds.length}` : 'Все сотрудники и свободные'}</summary><div><label><input type="checkbox" checked={staffIds.includes('__unassigned__')} onChange={(event) => setStaffIds((current) => event.target.checked ? [...current, '__unassigned__'] : current.filter((id) => id !== '__unassigned__'))} /><span className="unassigned-avatar"><UserRoundX size={15} /></span>Не назначенные</label>{staff.map((person) => <label key={person.id}><input type="checkbox" checked={staffIds.includes(person.id)} onChange={(event) => setStaffIds((current) => event.target.checked ? [...current, person.id] : current.filter((id) => id !== person.id))} /><Avatar id={person.id} state={state} />{person.name}</label>)}<button className="text-link" type="button" onClick={() => setStaffIds([])}>Показать всех</button></div></details><div className="segmented view-switch"><button className={view === 'list' ? 'active' : ''} onClick={() => setView('list')}><List size={16} />Список</button><button className={view === 'board' ? 'active' : ''} onClick={() => setView('board')}><LayoutGrid size={16} />Доска</button></div></div>
    <div className="task-metrics"><article><ClipboardCheck size={22} /><span>Открыто<strong>{openTasks.length}</strong></span></article><article className="unassigned"><UserRoundX size={22} /><span>Не назначено<strong>{unassigned.length}</strong></span></article><article><CalendarDays size={22} /><span>Без срока<strong>{unscheduled.length}</strong></span></article><article className="late"><AlertCircle size={22} /><span>Просрочено<strong>{tasks.filter((task) => task.status !== 'done' && task.dueAt && dayOf(task.dueAt) < dateKey()).length}</strong></span></article></div>
    <div className="task-content"><div className="task-main">
      {period === 'week' ? <WeekView state={state} tasks={tasks} start={mondayOf(selectedDate)} onSelect={(id) => { setSelectedId(id); setSelectedDate(dayOf(state.tasks.find((task) => task.id === id)?.dueAt)); }} /> : null}
      {period === 'month' ? <MonthView state={state} tasks={tasks} selectedDate={selectedDate} onDay={(day) => { setSelectedDate(day); setPeriod('day'); }} /> : null}
      {['all','day'].includes(period) && view === 'board' ? <div className="task-board">{TASK_STAGES.filter((stage) => stage.id !== 'blocked').map((stage) => <section key={stage.id}><h2>{stage.label}<span>{boardTasks.filter((task) => task.status === stage.id).length}</span></h2>{boardTasks.filter((task) => task.status === stage.id).map((task) => <button key={task.id} onClick={() => setSelectedId(task.id)}><strong>{task.title}</strong><small><Avatar id={task.assigneeId} state={state} />{employee(task.assigneeId, state)?.name || 'Не назначен'} · {task.dueAt ? dateLabel(task.dueAt, false) : 'без срока'}</small></button>)}</section>)}</div> : null}
      {period === 'all' && view === 'list' ? <>{needsPlanning.length ? <section className="assignment-section"><h2><UserRoundX size={18} />Нужно распределить <span>{needsPlanning.length}</span></h2>{needsPlanning.map((task) => <TaskRow key={task.id} state={state} task={task} selected={selectedTask?.id === task.id} onSelect={setSelectedId} onOpen={onOpen} onMove={onMove} onReschedule={reschedule} onAssign={assign} />)}</section> : null}<section className="employee-tasks"><h2><ClipboardCheck size={18} />Запланированные задачи <span>{plannedOpen.length}</span></h2>{plannedOpen.map((task) => <TaskRow key={task.id} state={state} task={task} selected={selectedTask?.id === task.id} onSelect={setSelectedId} onOpen={onOpen} onMove={onMove} onReschedule={reschedule} onAssign={assign} />)}{!plannedOpen.length ? <Empty title="Запланированных задач нет" /> : null}</section></> : null}
      {period === 'day' && view === 'list' ? <>{unscheduled.length ? <section className="assignment-section"><h2><CalendarDays size={18} />Без срока — общий список <span>{unscheduled.length}</span></h2>{unscheduled.map((task) => <TaskRow key={task.id} state={state} task={task} selected={selectedTask?.id === task.id} onSelect={setSelectedId} onOpen={onOpen} onMove={onMove} onReschedule={reschedule} onAssign={assign} />)}</section> : null}{carried.length ? <section className="carry-section"><h2>Перенесено с прошлых дней <span>{carried.length}</span></h2>{carried.map((task) => <TaskRow key={task.id} state={state} task={task} carried selected={selectedTask?.id === task.id} onSelect={setSelectedId} onOpen={onOpen} onMove={onMove} onReschedule={reschedule} onAssign={assign} />)}</section> : null}{unassignedToday.length ? <section className="assignment-section"><h2><UserRoundX size={18} />Не назначены на дату <span>{unassignedToday.length}</span></h2>{unassignedToday.map((task) => <TaskRow key={task.id} state={state} task={task} selected={selectedTask?.id === task.id} onSelect={setSelectedId} onOpen={onOpen} onMove={onMove} onReschedule={reschedule} onAssign={assign} />)}</section> : null}{listGroups.map(({ person, tasks: rows }) => <section className="employee-tasks" key={person.id}><h2><Avatar id={person.id} state={state} />{person.name}<span>{rows.length}</span></h2>{rows.map((task) => <TaskRow key={task.id} state={state} task={task} selected={selectedTask?.id === task.id} onSelect={setSelectedId} onOpen={onOpen} onMove={onMove} onReschedule={reschedule} onAssign={assign} />)}</section>)}{!unscheduled.length && !carried.length && !unassignedToday.length && !listGroups.length ? <Empty title="На выбранную дату задач нет">Добавьте задачу сверху или выберите другую дату.</Empty> : null}</> : null}
    </div><TaskDetails state={state} task={selectedTask} onOpen={onOpen} onEdit={onEdit} onReschedule={reschedule} onAssign={assign} /></div>
  </section>;
}

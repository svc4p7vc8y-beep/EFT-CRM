import { useMemo, useState } from 'react';
import { AlertTriangle, CalendarDays, ChevronLeft, ChevronRight, HardHat, Pencil, Plus, UsersRound } from 'lucide-react';
import { Dialog, Empty, Field } from '../components/UI.jsx';
import { CONSTRUCTION_STATUSES, employee } from '../app/model.js';
import './crew-planning.css';

const dayKey = (date) => date.toISOString().slice(0, 10);
const parseDay = (value) => new Date(`${value}T12:00:00`);
const addDays = (date, amount) => { const next = new Date(date); next.setDate(next.getDate() + amount); return next; };
const monday = (value) => { const date = new Date(value); date.setHours(12, 0, 0, 0); date.setDate(date.getDate() - ((date.getDay() + 6) % 7)); return date; };
const monthStart = (value) => new Date(value.getFullYear(), value.getMonth(), 1, 12);
const dateLabel = (date, options) => date.toLocaleDateString('ru-RU', options);
const overlaps = (stage, start, finish) => Boolean(stage.plannedStart && stage.plannedFinish && stage.plannedStart <= finish && stage.plannedFinish >= start);
const siteFor = (state, stage) => state.sites.find((site) => site.id === stage.siteId);
const clientFor = (state, stage) => state.clients.find((client) => client.id === siteFor(state, stage)?.clientId);

function AssignmentForm({ stage, state, onClose, onSave }) {
  const [crewId, setCrewId] = useState(stage.crewId || '');
  const [start, setStart] = useState(stage.plannedStart || dayKey(new Date()));
  const [finish, setFinish] = useState(stage.plannedFinish || stage.plannedStart || dayKey(new Date()));
  const conflicts = crewId ? state.constructionStages.filter((item) => item.id !== stage.id && item.crewId === crewId && item.status !== 'done' && overlaps(item, start, finish)) : [];
  return <form onSubmit={(event) => { event.preventDefault(); onSave({ id: stage.id, crewId, plannedStart: start, plannedFinish: finish }); }}>
    <div className="form-content"><div className="assignment-context"><HardHat size={20} /><span><strong>{stage.title}</strong><small>{siteFor(state, stage)?.name} · {clientFor(state, stage)?.name}</small></span></div><div className="form-grid">
      <Field label="Бригада" wide><select value={crewId} onChange={(event) => setCrewId(event.target.value)}><option value="">Не назначена</option>{state.crews.filter((crew) => crew.active || crew.id === crewId).map((crew) => <option key={crew.id} value={crew.id}>{crew.name} · {crew.specialty || 'без специализации'}</option>)}</select></Field>
      <Field label="Начало"><input type="date" required value={start} onChange={(event) => setStart(event.target.value)} /></Field>
      <Field label="Окончание"><input type="date" required min={start} value={finish} onChange={(event) => setFinish(event.target.value)} /></Field>
    </div>{finish < start ? <p className="form-error">Дата окончания не может быть раньше начала.</p> : null}{conflicts.length ? <div className="assignment-warning"><AlertTriangle size={18} /><span><strong>Пересечение в графике</strong>{conflicts.map((item) => <small key={item.id}>{item.title} · {siteFor(state, item)?.name}</small>)}</span></div> : null}</div>
    <footer className="dialog-actions"><button className="button" type="button" onClick={onClose}>Отмена</button><button className="button primary" disabled={finish < start}>Сохранить назначение</button></footer>
  </form>;
}

function StagePill({ stage, state, onEdit, compact = false }) {
  const site = siteFor(state, stage);
  return <button className={`crew-stage-pill ${stage.status}`} title={`${stage.title} · ${site?.name || ''}`} onClick={() => onEdit(stage)}><span>{stage.title}</span>{compact ? null : <small>{site?.name || 'Объект не указан'}</small>}</button>;
}

export function CrewPlanning({ state, search, onCrew, command, canManage = true }) {
  const [view, setView] = useState('week');
  const [anchor, setAnchor] = useState(() => new Date());
  const [crewFilter, setCrewFilter] = useState('all');
  const [editing, setEditing] = useState(null);
  const activeCrews = state.crews.filter((crew) => crew.active && (crewFilter === 'all' || crew.id === crewFilter) && `${crew.name} ${crew.specialty}`.toLocaleLowerCase('ru-RU').includes(search.toLocaleLowerCase('ru-RU')));
  const assigned = state.constructionStages.filter((stage) => stage.crewId && stage.status !== 'done');
  const unassigned = state.constructionStages.filter((stage) => !stage.crewId && stage.status !== 'done');
  const period = useMemo(() => {
    if (view === 'month') { const first = monday(monthStart(anchor)); return Array.from({ length: 42 }, (_, index) => addDays(first, index)); }
    const first = monday(anchor); return Array.from({ length: 7 }, (_, index) => addDays(first, index));
  }, [view, anchor]);
  const rangeStart = dayKey(period[0]); const rangeFinish = dayKey(period.at(-1));
  const periodStages = assigned.filter((stage) => overlaps(stage, rangeStart, rangeFinish));
  const conflicts = useMemo(() => assigned.filter((stage, index) => assigned.some((other, otherIndex) => otherIndex !== index && other.crewId === stage.crewId && overlaps(other, stage.plannedStart, stage.plannedFinish))).length, [assigned]);
  const title = view === 'month' ? dateLabel(anchor, { month: 'long', year: 'numeric' }) : `${dateLabel(period[0], { day: 'numeric', month: 'short' })} — ${dateLabel(period.at(-1), { day: 'numeric', month: 'short', year: 'numeric' })}`;
  const move = (direction) => setAnchor((current) => addDays(current, direction * (view === 'month' ? 28 : 7)));
  return <div className="crew-planning">
    <div className="crew-planning-head"><div><h2>Планирование бригад</h2><p>Загрузка по объектам, пересечения и свободные окна</p></div><div className="crew-planning-actions">{canManage ? <button className="button" onClick={() => onCrew()}><Plus size={17} />Добавить бригаду</button> : null}</div></div>
    <div className="crew-metrics"><article><UsersRound size={22} /><span><strong>{state.crews.filter((crew) => crew.active).length}</strong><small>действующих бригад</small></span></article><article><CalendarDays size={22} /><span><strong>{periodStages.length}</strong><small>этапов в периоде</small></span></article><article className={conflicts ? 'danger' : ''}><AlertTriangle size={22} /><span><strong>{conflicts}</strong><small>пересечений графика</small></span></article><article><HardHat size={22} /><span><strong>{unassigned.length}</strong><small>этапов без бригады</small></span></article></div>
    <div className="crew-toolbar"><div className="view-switch"><button className={view === 'week' ? 'active' : ''} onClick={() => setView('week')}>Неделя</button><button className={view === 'month' ? 'active' : ''} onClick={() => setView('month')}>Месяц</button><button className={view === 'load' ? 'active' : ''} onClick={() => setView('load')}>Загрузка</button></div><select value={crewFilter} onChange={(event) => setCrewFilter(event.target.value)}><option value="all">Все бригады</option>{state.crews.filter((crew) => crew.active).map((crew) => <option key={crew.id} value={crew.id}>{crew.name}</option>)}</select>{view !== 'load' ? <div className="period-nav"><button aria-label="Предыдущий период" onClick={() => move(-1)}><ChevronLeft size={18} /></button><strong>{title}</strong><button aria-label="Следующий период" onClick={() => move(1)}><ChevronRight size={18} /></button><button onClick={() => setAnchor(new Date())}>Сегодня</button></div> : null}</div>
    {view === 'load' ? <div className="crew-load-grid">{activeCrews.map((crew) => { const crewStages = assigned.filter((stage) => stage.crewId === crew.id); const late = crewStages.filter((stage) => stage.plannedFinish && stage.plannedFinish < dayKey(new Date())).length; return <article key={crew.id}><header><span className="crew-icon"><UsersRound size={20} /></span><button className="text-link" onClick={() => onCrew(crew.id)}><Pencil size={14} />Изменить</button></header><h3>{crew.name}</h3><p>{crew.specialty || 'Специализация не указана'}</p><dl><dt>Бригадир</dt><dd>{employee(crew.leadId, state)?.name || 'Не назначен'}</dd><dt>Состав</dt><dd>{crew.memberIds.length} чел.</dd><dt>Активных этапов</dt><dd>{crewStages.length}</dd><dt>Просрочено</dt><dd className={late ? 'danger-text' : ''}>{late}</dd></dl><div className="crew-load-list">{crewStages.slice(0, 4).map((stage) => <StagePill key={stage.id} stage={stage} state={state} onEdit={setEditing} />)}{!crewStages.length ? <small>Бригада свободна</small> : null}</div></article>; })}{!activeCrews.length ? <Empty title="Бригады не найдены" /> : null}</div> : <div className={`crew-calendar ${view}`}><div className="crew-calendar-corner">Бригада</div>{period.map((date) => <div key={dayKey(date)} className={`crew-day-head ${dayKey(date) === dayKey(new Date()) ? 'today' : ''}`}><strong>{dateLabel(date, { weekday: 'short' })}</strong><span>{dateLabel(date, { day: '2-digit', month: '2-digit' })}</span></div>)}{activeCrews.flatMap((crew) => [<div className="crew-row-title" key={`${crew.id}-title`}><strong>{crew.name}</strong><span>{crew.specialty || 'Без специализации'}</span><small>{crew.memberIds.length} чел.</small></div>, ...period.map((date) => { const dateString = dayKey(date); const items = assigned.filter((stage) => stage.crewId === crew.id && overlaps(stage, dateString, dateString)); return <div className={`crew-day-cell ${dateString === dayKey(new Date()) ? 'today' : ''}`} key={`${crew.id}-${dateString}`}>{items.map((stage) => <StagePill compact={view === 'month'} key={stage.id} stage={stage} state={state} onEdit={setEditing} />)}</div>; })])}</div>}
    <section className="unassigned-stages"><header><div><h3>Нужно назначить бригаду</h3><p>Этапы строительства без исполнителя</p></div><span>{unassigned.length}</span></header><div>{unassigned.slice(0, 12).map((stage) => <button key={stage.id} onClick={() => setEditing(stage)}><span><strong>{stage.title}</strong><small>{siteFor(state, stage)?.name} · {stage.plannedStart || 'дата не задана'}</small></span><Plus size={17} /></button>)}{!unassigned.length ? <p className="crew-all-assigned">Все созданные этапы распределены по бригадам.</p> : null}</div></section>
    {editing ? <Dialog title="Назначить бригаду и срок" onClose={() => setEditing(null)}><AssignmentForm stage={editing} state={state} onClose={() => setEditing(null)} onSave={(payload) => { command('construction.stage.save', payload, 'График бригады обновлён'); setEditing(null); }} /></Dialog> : null}
  </div>;
}

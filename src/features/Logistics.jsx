import { useMemo, useState } from 'react';
import { AlertTriangle, CalendarClock, MapPin, Pencil, Plus, Route, Truck, UserRound } from 'lucide-react';
import { Badge, Empty, Field, PageHeading, VoiceInput } from '../components/UI.jsx';
import { dateLabel, employee } from '../app/model.js';
import './logistics.css';

export const LOGISTICS_STATUSES = [
  { id: 'planned', label: 'Запланирован', color: 'gray' }, { id: 'loading', label: 'Погрузка', color: 'amber' },
  { id: 'in_transit', label: 'В пути', color: 'blue' }, { id: 'delivered', label: 'Доставлен', color: 'green' },
  { id: 'problem', label: 'Проблема', color: 'amber' }, { id: 'cancelled', label: 'Отменён', color: 'gray' },
];
const initial = { siteId: '', orderId: '', plannedAt: '', deliveryWindow: '', vehicle: '', driverId: '', status: 'planned', carrier: '', loadingAddress: 'Производство ЭФТ', unloadingAddress: '', note: '' };

function RouteForm({ state, item, onSave, onClose }) {
  const [form, setForm] = useState(item || initial); const change = (key) => (event) => setForm((value) => ({ ...value, [key]: event.target.value }));
  const siteOrders = state.orders.filter((order) => !form.siteId || order.siteId === form.siteId);
  return <form onSubmit={(event) => { event.preventDefault(); onSave(form); }}><div className="form-content form-grid">
    <Field label="Объект *" wide><select required value={form.siteId} onChange={(event) => { const siteId = event.target.value; setForm((value) => ({ ...value, siteId, orderId: '', unloadingAddress: state.sites.find((site) => site.id === siteId)?.address || value.unloadingAddress })); }}><option value="">Выберите объект</option>{state.sites.map((site) => <option key={site.id} value={site.id}>{site.name} · {site.address}</option>)}</select></Field>
    <Field label="Заказ"><select value={form.orderId} onChange={change('orderId')}><option value="">Без заказа</option>{siteOrders.map((order) => <option key={order.id} value={order.id}>{order.number}</option>)}</select></Field>
    <Field label="Дата и время *"><input required type="datetime-local" value={form.plannedAt} onChange={change('plannedAt')} /></Field>
    <Field label="Окно доставки"><input value={form.deliveryWindow} onChange={change('deliveryWindow')} placeholder="09:00–12:00" /></Field>
    <Field label="Статус"><select value={form.status} onChange={change('status')}>{LOGISTICS_STATUSES.map((status) => <option key={status.id} value={status.id}>{status.label}</option>)}</select></Field>
    <Field label="Машина *" wide><VoiceInput><input required value={form.vehicle} onChange={change('vehicle')} placeholder="Модель, госномер или тип транспорта" /></VoiceInput></Field>
    <Field label="Водитель"><select value={form.driverId} onChange={change('driverId')}><option value="">Не назначен</option>{state.employees.filter((person) => person.active).map((person) => <option key={person.id} value={person.id}>{person.name}</option>)}</select></Field>
    <Field label="Перевозчик"><VoiceInput><input value={form.carrier} onChange={change('carrier')} placeholder="Свой транспорт или подрядчик" /></VoiceInput></Field>
    <Field label="Откуда" wide><VoiceInput><input value={form.loadingAddress} onChange={change('loadingAddress')} /></VoiceInput></Field>
    <Field label="Куда" wide><VoiceInput><input value={form.unloadingAddress} onChange={change('unloadingAddress')} /></VoiceInput></Field>
    <Field label="Комментарий" wide><VoiceInput><textarea rows="3" value={form.note} onChange={change('note')} placeholder="Состав груза, пропуск, разгрузка, контакт на объекте" /></VoiceInput></Field>
  </div><footer className="dialog-actions"><button type="button" className="button" onClick={onClose}>Отмена</button><button className="button primary">Сохранить рейс</button></footer></form>;
}

export function Logistics({ state, search, command }) {
  const [editing, setEditing] = useState(null); const [status, setStatus] = useState('active');
  const rows = useMemo(() => state.logistics.filter((item) => (status === 'all' || (status === 'active' ? !['delivered','cancelled'].includes(item.status) : item.status === status)) && `${item.number} ${item.vehicle} ${item.carrier} ${state.sites.find((site) => site.id === item.siteId)?.name || ''}`.toLowerCase().includes(search.toLowerCase())).sort((a,b) => a.plannedAt.localeCompare(b.plannedAt)), [state, search, status]);
  const today = new Date().toISOString().slice(0,10); const active = state.logistics.filter((item) => !['delivered','cancelled'].includes(item.status));
  return <section className="page logistics-page"><PageHeading title="Логистика" description="Рейсы, доставка домокомплектов и контроль разгрузки"><button className="button primary" onClick={() => setEditing({})}><Plus size={17}/>Новый рейс</button></PageHeading>
    <div className="logistics-metrics"><article><Truck/><div><strong>{active.length}</strong><span>активных рейсов</span></div></article><article><CalendarClock/><div><strong>{active.filter((item) => item.plannedAt.slice(0,10) === today).length}</strong><span>на сегодня</span></div></article><article><Route/><div><strong>{active.filter((item) => item.status === 'in_transit').length}</strong><span>сейчас в пути</span></div></article><article className="warning"><AlertTriangle/><div><strong>{active.filter((item) => item.status === 'problem').length}</strong><span>требуют решения</span></div></article></div>
    <div className="logistics-filters"><button className={status === 'active' ? 'active' : ''} onClick={() => setStatus('active')}>Активные</button><button className={status === 'planned' ? 'active' : ''} onClick={() => setStatus('planned')}>Запланированные</button><button className={status === 'delivered' ? 'active' : ''} onClick={() => setStatus('delivered')}>Доставленные</button><button className={status === 'all' ? 'active' : ''} onClick={() => setStatus('all')}>Все</button></div>
    <div className="logistics-list">{rows.map((item) => { const site = state.sites.find((value) => value.id === item.siteId); const order = state.orders.find((value) => value.id === item.orderId); return <article className="logistics-card" key={item.id}><div className="route-time"><strong>{new Date(item.plannedAt).toLocaleDateString('ru-RU',{day:'2-digit',month:'short'})}</strong><span>{item.plannedAt.slice(11,16)}</span><small>{item.deliveryWindow}</small></div><div className="route-line"><i/><span/></div><div className="route-main"><header><div><small>{item.number}{order ? ` · ${order.number}` : ''}</small><h2>{site?.name || 'Объект не найден'}</h2></div><Badge value={item.status} stages={LOGISTICS_STATUSES}/></header><div className="route-addresses"><span><MapPin size={15}/><b>{item.loadingAddress || 'Адрес погрузки не указан'}</b></span><span><MapPin size={15}/><b>{item.unloadingAddress || site?.address || 'Адрес доставки не указан'}</b></span></div><footer><span><Truck size={15}/>{item.vehicle}</span><span><UserRound size={15}/>{employee(item.driverId,state)?.name || item.carrier || 'Водитель не назначен'}</span>{item.note ? <span>{item.note}</span> : null}</footer></div><div className="route-actions"><select aria-label="Статус рейса" value={item.status} onChange={(event) => command('logistics.save',{...item,status:event.target.value},'Статус рейса обновлён')}>{LOGISTICS_STATUSES.map((value) => <option key={value.id} value={value.id}>{value.label}</option>)}</select><button className="icon-button" aria-label="Редактировать рейс" onClick={() => setEditing(item)}><Pencil size={17}/></button></div></article>; })}{!rows.length ? <Empty title="Рейсы не найдены" /> : null}</div>
    {editing ? <div className="construction-editor"><div className="construction-editor-card wide"><header><h2>{editing.id ? `Рейс ${editing.number}` : 'Новый рейс'}</h2><button className="icon-button" onClick={() => setEditing(null)}>×</button></header><RouteForm state={state} item={editing.id ? editing : null} onClose={() => setEditing(null)} onSave={(payload) => { command('logistics.save',payload,'Рейс сохранён'); setEditing(null); }}/></div></div> : null}
  </section>;
}

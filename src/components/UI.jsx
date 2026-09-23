import { useEffect, useRef } from 'react';
import { X, Clock3, MessageSquare, Phone, Mail, CalendarDays, CircleDot } from 'lucide-react';
import { ACTIVITY_TYPES, dateLabel, employee } from '../app/model.js';

export function Badge({ value, stages, children, color }) { const stage = stages?.find((s) => s.id === value); return <span className={`badge ${color || stage?.color || 'gray'}`}>{children || stage?.label || value}</span>; }
export function Avatar({ id, state, size = '' }) { const person = employee(id, state); return <span className={`avatar ${size}`} title={person?.name || 'Не назначен'}>{person?.initials || '—'}</span>; }
export function Empty({ title = 'Ничего не найдено', children }) { return <div className="empty-state"><CircleDot size={30} /><h3>{title}</h3><p>{children || 'Попробуйте изменить поиск или фильтры.'}</p></div>; }
export function Field({ label, children, wide }) { return <label className={`field${wide ? ' field-wide' : ''}`}><span>{label}</span>{children}</label>; }
export function Dialog({ title, subtitle, children, onClose, wide = false }) {
  const ref = useRef(null);
  useEffect(() => { const dialog = ref.current; dialog.showModal(); return () => dialog.close(); }, []);
  return <dialog ref={ref} className={`dialog ${wide ? 'dialog-wide' : ''}`} onCancel={onClose} onClick={(event) => { if (event.target === event.currentTarget) { const r = event.currentTarget.getBoundingClientRect(); if (event.clientX < r.left || event.clientX > r.right || event.clientY < r.top || event.clientY > r.bottom) onClose(); } }} aria-labelledby="dialog-title"><header><div><h2 id="dialog-title">{title}</h2>{subtitle ? <p>{subtitle}</p> : null}</div><button className="icon-button" type="button" aria-label="Закрыть окно" onClick={onClose}><X size={20} /></button></header>{children}</dialog>;
}
const activityIcons = { call: Phone, email: Mail, meeting: CalendarDays, message: MessageSquare, note: MessageSquare, system: CircleDot };
export function Timeline({ state, events, compact = false }) {
  if (!events.length) return <p className="muted small">Событий пока нет. Добавьте результат первого общения.</p>;
  return <ol className={`timeline${compact ? ' compact' : ''}`}>{events.map((event) => { const Icon = activityIcons[event.type] || Clock3; return <li key={event.id}><span className="timeline-mark"><Icon size={13} /></span><time>{dateLabel(event.createdAt)}</time><strong>{ACTIVITY_TYPES[event.type]}</strong><p>{event.text}</p><small>{employee(event.authorId, state)?.name || 'ЭФТ'}</small></li>; })}</ol>;
}
export function PageHeading({ title, description, children }) { return <div className="page-heading"><div><h1>{title}</h1><p>{description}</p></div>{children}</div>; }

import { Children, cloneElement, isValidElement, useEffect, useRef, useState } from 'react';
import { X, Clock3, MessageSquare, Phone, Mail, CalendarDays, CircleDot, Mic, Square } from 'lucide-react';
import { ACTIVITY_TYPES, dateLabel, employee } from '../app/model.js';

export function Badge({ value, stages, children, color }) { const stage = stages?.find((s) => s.id === value); return <span className={`badge ${color || stage?.color || 'gray'}`}>{children || stage?.label || value}</span>; }
export function Avatar({ id, state, size = '' }) { const person = employee(id, state); return <span className={`avatar ${size}`} title={person?.name || 'Не назначен'}>{person?.avatar ? <img src={person.avatar} alt="" /> : person?.initials || '—'}</span>; }
export function Empty({ title = 'Ничего не найдено', children }) { return <div className="empty-state"><CircleDot size={30} /><h3>{title}</h3><p>{children || 'Попробуйте изменить поиск или фильтры.'}</p></div>; }

function speakable(child) {
  if (!isValidElement(child) || !['input', 'textarea'].includes(child.type) || child.props['data-no-voice']) return false;
  return child.type === 'textarea' || ['text', 'search', undefined].includes(child.props.type);
}
function VoiceControl({ child }) {
  const inputRef = useRef(null); const recognition = useRef(null); const [listening, setListening] = useState(false);
  const Speech = typeof window === 'undefined' ? null : window.SpeechRecognition || window.webkitSpeechRecognition;
  useEffect(() => () => recognition.current?.abort(), []);
  function record(event) {
    event.preventDefault(); event.stopPropagation();
    if (listening) { recognition.current?.stop(); return; }
    if (!Speech) return;
    const engine = new Speech(); recognition.current = engine; engine.lang = 'ru-RU'; engine.continuous = false; engine.interimResults = false;
    engine.onresult = (result) => {
      const node = inputRef.current; const phrase = result.results[0][0].transcript.trim(); if (!node || !phrase) return;
      const current = node.value.trim(); const value = current ? `${node.value}${/[.!?]$/.test(current) ? ' ' : '. '}${phrase}` : phrase;
      const setter = Object.getOwnPropertyDescriptor(node.constructor.prototype, 'value')?.set; setter?.call(node, value);
      if (child.props.onChange) child.props.onChange({ target: node, currentTarget: node }); else node.dispatchEvent(new Event('input', { bubbles: true }));
    };
    engine.onend = () => setListening(false); engine.onerror = () => setListening(false);
    try { engine.start(); setListening(true); } catch { setListening(false); }
  }
  const originalRef = child.props.ref;
  return <span className="voice-field-control">{cloneElement(child, { ref: (node) => { inputRef.current = node; if (typeof originalRef === 'function') originalRef(node); else if (originalRef) originalRef.current = node; } })}<button type="button" className={`voice-input-button${listening ? ' listening' : ''}`} onClick={record} disabled={!Speech} aria-label={listening ? 'Остановить голосовой ввод' : 'Ввести голосом'} title={Speech ? 'Ввести текст голосом' : 'Голосовой ввод не поддерживается браузером'}>{listening ? <Square size={14} /> : <Mic size={16} />}</button></span>;
}
export function VoiceInput({ children }) { return speakable(children) ? <VoiceControl child={children} /> : children; }
export function Field({ label, children, wide }) {
  let added = false;
  const content = Children.map(children, (child) => { if (!added && speakable(child)) { added = true; return <VoiceControl child={child} />; } return child; });
  return <label className={`field${wide ? ' field-wide' : ''}`}><span>{label}</span>{content}</label>;
}
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

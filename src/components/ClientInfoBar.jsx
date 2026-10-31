import { useMemo } from 'react';
import { CalendarDays, Clock3, MessageSquare, UserRound, Phone, Mail, MapPin, ChevronDown } from 'lucide-react';
import { dateLabel, employee } from '../app/model.js';
import './client-info-bar.css';

export function clientInfo(lead, messages = [], files = []) {
  const ordered = messages.filter((item) => !item.ignored && Number.isFinite(Date.parse(item.createdAt))).toSorted((a,b) => Date.parse(a.createdAt)-Date.parse(b.createdAt));
  const incoming = ordered.filter((item) => item.direction === 'incoming');
  const comment = ordered.filter((item) => item.type === 'note' || item.channel === 'note').at(-1);
  const last = [...ordered, ...files.filter((item) => Number.isFinite(Date.parse(item.createdAt))).map((item) => ({...item,text:`Добавлен файл: ${item.name}`,type:'file'}))].sort((a,b) => Date.parse(a.createdAt)-Date.parse(b.createdAt)).at(-1);
  return { arrivedAt: incoming[0]?.createdAt || lead?.createdAt || '', last, comment, unread: incoming.filter((item)=>!item.read).length };
}
export function ClientInfoBar({state,lead,site,client,messages,files=[],source='',compact=false}) {
  const related = useMemo(()=>messages || state.activities.filter((item)=>item.siteId===site?.id),[messages,state.activities,site?.id]);
  const info=useMemo(()=>clientInfo(lead,related,files),[lead,related,files]);
  const owners=(lead?.ownerIds || [lead?.ownerId || site?.managerId]).filter(Boolean).map((id)=>employee(id,state)?.name).filter(Boolean);
  const origin=lead?.source || source || 'Не указан';
  const date=(value)=>value?dateLabel(value):'Пока нет';
  const body=<><div className="client-info-dates"><article><CalendarDays size={16}/><span>Первое обращение<strong>{date(info.arrivedAt)}</strong></span></article><article><Clock3 size={16}/><span>Последнее действие<strong>{date(info.last?.createdAt)}</strong></span></article><article><MessageSquare size={16}/><span>Последний комментарий<strong>{date(info.comment?.createdAt)}</strong></span></article></div><div className="client-info-contacts"><span><UserRound size={15}/>{owners.join(', ') || 'Ответственные не назначены'}</span><span><Phone size={15}/>{client?.phone || 'Телефон не передан'}</span><span><Mail size={15}/>{client?.email || 'Почта не указана'}</span>{site?.address?<span><MapPin size={15}/>{site.address}</span>:null}</div>{info.last?<p className="client-info-latest"><b>Последнее действие</b><span>{info.last.text || info.last.subject || 'Обновлена информация'}</span></p>:null}{lead?.nextAction?<div className="client-info-next"><span>Следующий шаг: <b>{lead.nextAction}</b></span><span>{lead.dueAt?date(lead.dueAt):'Срок не назначен'}</span></div>:null}</>;
  if(compact)return <details className="client-info-bar compact"><summary><span><b>{origin}</b><span>Первое обращение: {date(info.arrivedAt)}</span><span>Последнее действие: {date(info.last?.createdAt)}</span></span><ChevronDown size={16}/></summary><div className="client-info-body">{body}</div></details>;
  return <section className="client-info-bar" aria-label="Сводка по клиенту"><header><span className="client-info-source">{origin}</span><span>{site?.name || 'Объект пока не уточнён'}</span><span className="client-info-unread">Непрочитано: {info.unread}</span></header><div className="client-info-body">{body}</div></section>;
}
